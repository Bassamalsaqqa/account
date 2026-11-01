<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\PlatformAuditEvent;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final class SupportViewSession
{
    public const string KEY = 'platform_support_view';

    public function start(Request $request, string $companyPublicId, string $reason, #[SensitiveParameter] string $password): void
    {
        if ($request->session()->has(self::KEY)) {
            throw ValidationException::withMessages(['reason' => __('support.already_active')]);
        }
        $operator = $request->user();
        if (! $operator instanceof User) {
            throw new AuthorizationException;
        }
        $state = DB::transaction(fn (): array => $this->createState($operator, $companyPublicId, $reason, $password));
        // No target login, remember token, membership, password or user preference is modified.
        $request->session()->regenerate(true);
        $request->session()->put(self::KEY, $state);
    }

    /** @return array<string, mixed> */
    private function createState(User $operator, string $companyPublicId, string $reason, #[SensitiveParameter] string $password): array
    {
        $company = Company::where('public_id', $companyPublicId)->where('status', 'active')->lockForUpdate()->firstOrFail();
        $grant = app(PlatformAccess::class)->authorize('company.impersonate', true);
        if (! app(PlatformSessionProof::class)->confirmPassword($operator, $password)) {
            throw ValidationException::withMessages(['current_password' => __('auth.password')]);
        }
        $members = CompanyUser::where('company_id', $company->id)->where('status', 'active')->where('is_owner', true)->lockForUpdate()->get();
        if ($members->count() !== 1) {
            throw ValidationException::withMessages(['reason' => __('support.owner_unavailable')]);
        }
        $target = User::whereKey($members->first()->user_id)->firstOrFail();
        $startedAt = now()->timestamp;
        $state = ['operator_id' => (int) $operator->id, 'company_id' => (int) $company->id, 'company_public_id' => $company->public_id,
            'target_user_id' => (int) $target->id, 'access_mode' => 'owner', 'grant_revision' => (int) $grant->revision, 'started_at' => $startedAt,
            'expires_at' => $startedAt + 900, 'correlation_id' => (string) Str::uuid()];
        app(PlatformAudit::class)->record('support.started', (int) $operator->id, (int) $company->id,
            ['owner_user_id' => (int) $target->id, 'correlation_id' => $state['correlation_id'], 'grant_revision' => (int) $grant->revision, 'reason' => $reason]);

        return $state;
    }

    /** @return array{company: Company, target: User, details: array<string, mixed>} */
    public function resolve(Request $request, bool $lock = false): array
    {
        $state = $request->session()->get(self::KEY);
        $active = app(SupportViewContext::class);
        $operator = $active->active() ? User::find($active->details()['operator_id']) : $request->user();
        if (! is_array($state) || ($state['access_mode'] ?? null) !== 'owner' || ! $operator instanceof User || ($state['operator_id'] ?? null) !== (int) $operator->id
            || ! is_int($state['expires_at'] ?? null) || $state['expires_at'] <= now()->timestamp || ! is_int($state['started_at'] ?? null) || $state['started_at'] > now()->timestamp
            || ($state['expires_at'] - $state['started_at']) !== 900 || ! is_string($state['correlation_id'] ?? null) || ! Str::isUuid($state['correlation_id'])) {
            throw new AuthorizationException;
        }
        // Rechecking a valid administrator is insufficient if request-local state was rebound.
        // Every delegated identity/correlation must still be the exact server-side session grant.
        if ($active->active()) {
            $details = $active->details();
            foreach (['operator_id', 'company_id', 'company_public_id', 'target_user_id', 'grant_revision', 'correlation_id', 'access_mode', 'started_at', 'expires_at'] as $field) {
                if (($details[$field] ?? null) !== ($state[$field] ?? null)) {
                    throw new AuthorizationException('Administrative delegation binding changed.');
                }
            }
        }
        // Company-first lock order matches canonical tenant writers.
        $companyQuery = Company::whereKey($state['company_id'] ?? 0)->where('public_id', $state['company_public_id'] ?? '')->where('status', 'active');
        $company = ($lock ? $companyQuery->lockForUpdate() : $companyQuery)->firstOrFail();
        $grant = app(PlatformAccess::class)->authorizeOperator($request, $operator, 'company.impersonate', $lock);
        if (($state['grant_revision'] ?? null) !== (int) $grant->revision) {
            throw new AuthorizationException;
        }
        $ended = PlatformAuditEvent::where('actor_user_id', $operator->id)->where('company_id', $company->id)
            ->where('event_key', 'support.ended')->where('created_at', '>=', now()->subMinutes(16))
            ->where('metadata->correlation_id', $state['correlation_id']);
        if (Cache::get('platform-support-revoked:'.$state['correlation_id']) === true
            || ($lock ? $ended->lockForUpdate() : $ended)->first(['id']) !== null) {
            throw new AuthorizationException;
        }
        if ($lock && DB::connection(config('session.connection')) !== DB::connection()) {
            throw new AuthorizationException('Delegated writes require the canonical session connection.');
        }
        if (config('session.driver') === 'database') {
            $session = DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('id', $request->session()->getId())->where('user_id', $operator->id);
            $row = ($lock ? $session->lockForUpdate() : $session)->first();
            if ($row === null || (int) $row->last_activity < now()->timestamp - (int) config('session.lifetime') * 60) {
                throw new AuthorizationException('The real operator session was revoked or expired.');
            }
        }
        $members = CompanyUser::where('company_id', $company->id)->where('is_owner', true)->where('status', 'active');
        $members = ($lock ? $members->lockForUpdate() : $members)->get();
        $member = $members->count() === 1 ? $members->first() : null;
        if ($member !== null && (int) $member->user_id !== ($state['target_user_id'] ?? 0)) {
            throw new AuthorizationException;
        }
        if ($member === null) {
            throw new AuthorizationException;
        }
        $target = User::findOrFail($member->user_id);

        return ['company' => $company, 'target' => $target, 'details' => $state + ['operator_name' => $operator->name, 'target_name' => $target->name, 'company_name' => $company->displayName()]];
    }

    /**
     * Explicit trusted-host delegation, analogous to platform:operator. No target password/login.
     * The Artisan caller supplies interactive exact-plan confirmation; never reachable over HTTP.
     *
     * @template T
     *
     * @param  Closure(User): T  $callback
     * @return T
     */
    public function runFromHost(User $operator, Company $company, string $reason, bool $apply, Closure $callback): mixed
    {
        if (PHP_SAPI !== 'cli' || ! app()->runningInConsole() || app(CompanyContext::class)->hasCompany() || Auth::check()) {
            throw new AuthorizationException('Trusted host execution requires a fresh CLI context.');
        }
        $grant = PlatformOperatorGrant::where('user_id', $operator->id)->where('active', true)->first();
        if ($grant === null || ! in_array('company.impersonate', $grant->permissions ?? [], true)) {
            throw new AuthorizationException('Explicit platform impersonation authority is required.');
        }
        $members = CompanyUser::where('company_id', $company->id)->where('is_owner', true)->where('status', 'active')->get();
        if ($company->fresh()->status !== 'active' || $members->count() !== 1) {
            throw new AuthorizationException('Exactly one active company Owner is required.');
        }
        $owner = User::findOrFail($members->first()->user_id);
        $state = ['operator_id' => (int) $operator->id, 'target_user_id' => (int) $owner->id, 'company_id' => (int) $company->id,
            'company_public_id' => $company->public_id, 'access_mode' => 'owner', 'authority' => 'trusted_host',
            'grant_revision' => (int) $grant->revision, 'started_at' => now()->timestamp, 'expires_at' => now()->timestamp + 900,
            'correlation_id' => (string) Str::uuid()];
        $support = app(SupportViewContext::class);
        try {
            Auth::setUser($owner);
            app(CompanyContext::class)->setCompany($company, $owner);
            $support->activateFromHost($state);
            $execute = function () use ($apply, $operator, $company, $owner, $reason, $state, $callback) {
                if ($apply) {
                    app(SupportViewContext::class)->assertWritable((int) $company->id);
                    app(PlatformAudit::class)->record('impersonation.started', (int) $operator->id, (int) $company->id,
                        ['owner_user_id' => (int) $owner->id, 'correlation_id' => $state['correlation_id'], 'grant_revision' => $state['grant_revision'], 'reason' => $reason, 'authority' => 'trusted_host']);
                }
                $result = $callback($owner);
                if ($apply) {
                    app(PlatformAudit::class)->record('impersonation.ended', (int) $operator->id, (int) $company->id,
                        ['owner_user_id' => (int) $owner->id, 'correlation_id' => $state['correlation_id'], 'authority' => 'trusted_host']);
                }

                return $result;
            };

            return $apply ? DB::transaction($execute) : $execute();
        } finally {
            $support->clear();
            app(CompanyContext::class)->clear();
            Auth::forgetUser();
        }
    }

    public function end(Request $request, string $exitReason = 'operator_exit'): void
    {
        $state = $request->session()->pull(self::KEY);
        if (! is_array($state)) {
            return;
        }
        $request->session()->regenerate(true);
        $revoked = false;
        try {
            if (is_string($state['correlation_id'] ?? null) && Str::isUuid($state['correlation_id'])) {
                $revoked = Cache::put('platform-support-revoked:'.$state['correlation_id'], true, 1200);
            }
        } catch (\Throwable $e) {
            Log::warning('Support revocation cache unavailable.', ['exception_class' => get_class($e)]);
        }
        // Current authority is already removed. Do not duplicate durable revocations on replay.
        try {
            $ended = PlatformAuditEvent::where('actor_user_id', $request->user()?->id)->where('company_id', $state['company_id'] ?? 0)
                ->where('event_key', 'support.ended')->where('created_at', '>=', now()->subMinutes(16))
                ->where('metadata->correlation_id', $state['correlation_id'] ?? null)->exists();
            if (! $ended) {
                app(PlatformAudit::class)->record('support.ended', $request->user()?->id,
                    is_int($state['company_id'] ?? null) ? $state['company_id'] : null,
                    ['owner_user_id' => is_int($state['target_user_id'] ?? null) ? $state['target_user_id'] : null,
                        'correlation_id' => is_string($state['correlation_id'] ?? null) ? $state['correlation_id'] : null, 'exit_reason' => $exitReason]);
            }
            $revoked = true;
        } catch (\Throwable $e) {
            Log::warning('Support view ended; audit persistence failed.', ['exception_class' => get_class($e)]);
        }
        if (! $revoked) {
            // No storage success is fabricated. Current authority is cleared and reauthentication required.
            $request->session()->forget(PlatformSessionProof::KEY);
            Log::error('Support revocation storage failed; current platform proof removed.');
        }
    }
}
