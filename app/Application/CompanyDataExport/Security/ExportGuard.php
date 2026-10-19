<?php

declare(strict_types=1);

namespace App\Application\CompanyDataExport\Security;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Session\CookieSessionHandler;
use Illuminate\Session\EncryptedStore;
use Illuminate\Session\Store;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ExportGuard
{
    private ?Request $boundRequest = null;

    public function __construct(
        private readonly CompanyContext $context,
        private readonly RateLimiter $rateLimiter
    ) {}

    public function bindRequest(Request $request): self
    {
        $this->boundRequest = $request;

        return $this;
    }

    /**
     * Authorize and validate fresh authority for the export request.
     * Enforces rate limiting, Owner membership, session persistence, and session password confirmation.
     *
     * @return array{company: Company, user: User, fingerprint: array<string, mixed>}
     *
     * @throws AuthorizationException|HttpException
     */
    public function authorizeRequest(Request $request): array
    {
        abort_unless(config('company_data_export.enabled', true), 403);
        $this->bindRequest($request);
        // Client-only session storage cannot independently observe server-side
        // logout/revocation while preparing a sensitive archive.
        abort_if(config('session.driver') === 'cookie'
            || ($request->hasSession() && $request->session()->getHandler() instanceof CookieSessionHandler),
            403, __('company_data_export.session_revoked'));

        $user = $request->user();
        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        if (! $this->context->hasCompany()) {
            abort(403, 'Active company context is required.');
        }

        $sessionCompany = $this->context->company();

        // 1. Re-query fresh persisted company
        $freshCompany = Company::whereKey($sessionCompany->id)->where('status', 'active')->first();
        if ($freshCompany === null) {
            abort(403, 'Company is inactive or does not exist.');
        }

        // 2. Re-query fresh persisted user
        $freshUser = User::whereKey($user->id)->first();
        if ($freshUser === null) {
            abort(401, 'User does not exist.');
        }

        // 3. Strict Owner membership verification. Admin role is NOT inherently allowed.
        $membership = CompanyUser::where('company_id', $freshCompany->id)
            ->where('user_id', $freshUser->id)
            ->where('status', 'active')
            ->first();

        if ($membership === null || ! (bool) $membership->is_owner) {
            abort(403, __('company_data_export.unauthorized_owner_only'));
        }

        // 4. Rate limiting per company & user (checked before expensive operations)
        $this->ensureRateLimit($freshCompany, $freshUser);

        // 5. Recent password confirmation verification via session
        $confirmedAt = $this->ensurePasswordConfirmed($request);

        // 6. Explicitly persist session to storage handler before initial snapshot begins
        // Ensures database/file/array session handler has the fresh state persisted
        if ($request->hasSession()) {
            $session = $request->session();
            // Never manufacture authentication from a remembered in-memory actor.
            $guardKey = auth()->guard()->getName();
            if ((int) $session->get($guardKey) !== (int) $freshUser->id
                || (int) $session->get('active_company_id') !== (int) $freshCompany->id) {
                throw new AuthorizationException(__('company_data_export.session_revoked'));
            }
            $session->save();
            $session->start();
        }

        // 7. Capture pre-export fingerprint (without logging session secrets)
        $fingerprint = [
            'user_id' => (int) $freshUser->id,
            'company_id' => (int) $freshCompany->id,
            'session_id' => (string) ($request->hasSession() ? $request->session()->getId() : ''),
            'password_confirmed_at' => $confirmedAt,
            'is_owner' => true,
            'membership_status' => 'active',
            'company_status' => 'active',
            'password_hash' => (string) $freshUser->password,
        ];

        return [
            'company' => $freshCompany,
            'user' => $freshUser,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Assert that live authority matches the initial fingerprint, session is intact,
     * password was not changed, and Owner membership remains active.
     * Must be executed outside the REPEATABLE READ transaction to observe fresh state.
     *
     * @param  array<string, mixed>  $preFingerprint
     *
     * @throws AuthorizationException
     */
    public function assertFingerprintValid(
        array $preFingerprint,
        Company $company,
        User $user,
        ?Request $request = null
    ): void {
        // Resolve request from argument, bound instance, or application container
        $request = $request ?? $this->boundRequest ?? (app()->bound('request') ? app('request') : null);

        // Optional Request bypass is prohibited: fail closed if no request context exists
        if ($request === null || ! $request->hasSession()) {
            throw new AuthorizationException(__('company_data_export.session_revoked'));
        }

        $timeout = (int) config('auth.password_timeout', 10800);
        $confirmedAt = (int) ($preFingerprint['password_confirmed_at'] ?? 0);

        if ($confirmedAt <= 0 || (time() - $confirmedAt) >= $timeout) {
            throw new AuthorizationException(__('company_data_export.password_confirmation_required'));
        }

        // Fresh handler read on the persisted session storage (fail-closed)
        $sessionId = (string) ($preFingerprint['session_id'] ?? '');
        if ($sessionId === '') {
            throw new AuthorizationException(__('company_data_export.session_revoked'));
        }

        $session = $request->session();
        if (! $session instanceof Store) {
            throw new AuthorizationException(__('company_data_export.session_revoked'));
        }
        $handler = $session->getHandler();

        try {
            $rawData = $handler->read($sessionId);
            if ($rawData === false || $rawData === '') {
                throw new AuthorizationException(__('company_data_export.session_revoked'));
            }

            // Parse persisted session via separate Illuminate Session Store clone
            // using encryption-compatible instance without direct unsafe unserialize
            $freshStore = $this->createSessionStoreClone($session, $sessionId);
            $freshStore->start();

            // Verify password confirmation timestamp in fresh persisted session
            $persistedConfirmedAt = (int) $freshStore->get('auth.password_confirmed_at', 0);
            if ($persistedConfirmedAt <= 0 || (time() - $persistedConfirmedAt) >= $timeout) {
                throw new AuthorizationException(__('company_data_export.password_confirmation_required'));
            }

            // Verify auth guard session key user match if present
            $guardKey = auth()->guard()->getName();
            $persistedUserId = $freshStore->get($guardKey);
            if ((int) $persistedUserId !== (int) $user->id) {
                throw new AuthorizationException(__('company_data_export.session_revoked'));
            }

            // Verify active company in persisted session if present
            $persistedCompanyId = $freshStore->get('active_company_id');
            if ((int) $persistedCompanyId !== (int) $company->id) {
                throw new AuthorizationException(__('company_data_export.session_revoked'));
            }
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AuthorizationException(__('company_data_export.session_revoked'), 0, $e);
        }

        // Fresh database checks for company, user password hash, and active owner membership
        $freshCompany = Company::whereKey($company->id)->first();
        if ($freshCompany === null || $freshCompany->status !== 'active') {
            throw new AuthorizationException(__('company_data_export.authority_revoked'));
        }

        $freshUser = User::whereKey($user->id)->first();
        if ($freshUser === null || $freshUser->password !== $preFingerprint['password_hash']) {
            throw new AuthorizationException(__('company_data_export.session_revoked'));
        }

        $membership = CompanyUser::where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->first();

        if ($membership === null || ! (bool) $membership->is_owner || $membership->status !== 'active') {
            throw new AuthorizationException(__('company_data_export.authority_revoked'));
        }
    }

    /**
     * Create an encryption-compatible separate Illuminate Session Store clone.
     */
    private function createSessionStoreClone(Store $currentSession, string $sessionId): Store
    {
        $handler = $currentSession->getHandler();
        $name = $currentSession->getName();

        if ($currentSession instanceof EncryptedStore) {
            return new EncryptedStore($name, $handler, $currentSession->getEncrypter(), $sessionId, config('session.serialization', 'php'));
        }

        // Check if encryption is enabled in configuration
        if (config('session.encrypt', false) && app()->bound('encrypter')) {
            /** @var Encrypter $encrypter */
            $encrypter = app('encrypter');

            return new EncryptedStore($name, $handler, $encrypter, $sessionId, config('session.serialization', 'php'));
        }

        return new Store($name, $handler, $sessionId, config('session.serialization', 'php'));
    }

    private function ensurePasswordConfirmed(Request $request): int
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $timeout = (int) config('auth.password_timeout', 10800);

        if ($confirmedAt <= 0 || (time() - $confirmedAt) >= $timeout) {
            abort(403, __('company_data_export.password_confirmation_required'));
        }

        return $confirmedAt;
    }

    private function ensureRateLimit(Company $company, User $user): void
    {
        $maxAttempts = (int) config('company_data_export.rate_limit.max_attempts', 5);
        $decaySeconds = (int) config('company_data_export.rate_limit.decay_seconds', 3600);
        $rateKey = 'company_data_export:'.$company->id.':'.$user->id;

        if ($this->rateLimiter->tooManyAttempts($rateKey, $maxAttempts)) {
            abort(429, __('company_data_export.rate_limited'));
        }

        $this->rateLimiter->hit($rateKey, $decaySeconds);
    }
}
