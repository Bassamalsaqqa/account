<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
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
        $request->session()->regenerate();
        $request->session()->put(self::KEY, $state);
    }

    /** @return array<string, mixed> */
    private function createState(User $operator, string $companyPublicId, string $reason, #[SensitiveParameter] string $password): array
    {
        $grant = app(PlatformAccess::class)->authorize('company.support', true);
        if (! app(PlatformSessionProof::class)->confirmPassword($operator, $password)) {
            throw ValidationException::withMessages(['current_password' => __('auth.password')]);
        }
        $company = Company::where('public_id', $companyPublicId)->where('status', 'active')->lockForUpdate()->firstOrFail();
        $members = CompanyUser::where('company_id', $company->id)->where('status', 'active')->where('is_owner', true)->lockForUpdate()->get();
        if ($members->count() !== 1) {
            throw ValidationException::withMessages(['reason' => __('support.owner_unavailable')]);
        }
        $target = User::whereKey($members->first()->user_id)->firstOrFail();
        if ((int) $target->id === (int) $operator->id || PlatformOperatorGrant::where('user_id', $target->id)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['reason' => __('support.owner_unavailable')]);
        }
        $startedAt = now()->timestamp;
        $state = ['operator_id' => (int) $operator->id, 'company_id' => (int) $company->id, 'company_public_id' => $company->public_id,
            'target_user_id' => (int) $target->id, 'grant_revision' => (int) $grant->revision, 'started_at' => $startedAt,
            'expires_at' => $startedAt + 900, 'correlation_id' => (string) Str::uuid()];
        app(PlatformAudit::class)->record('support.started', (int) $operator->id, (int) $company->id,
            ['owner_user_id' => (int) $target->id, 'correlation_id' => $state['correlation_id'], 'grant_revision' => (int) $grant->revision, 'reason' => $reason]);

        return $state;
    }

    /** @return array{company: Company, target: User, details: array<string, mixed>} */
    public function resolve(Request $request): array
    {
        $state = $request->session()->get(self::KEY);
        $operator = $request->user();
        if (! is_array($state) || ! $operator instanceof User || ($state['operator_id'] ?? null) !== (int) $operator->id
            || ! is_int($state['expires_at'] ?? null) || $state['expires_at'] <= now()->timestamp || ! is_int($state['started_at'] ?? null) || $state['started_at'] > now()->timestamp
            || ($state['expires_at'] - $state['started_at']) !== 900) {
            throw new AuthorizationException;
        }
        $grant = app(PlatformAccess::class)->authorize('company.support');
        if (($state['grant_revision'] ?? null) !== (int) $grant->revision) {
            throw new AuthorizationException;
        }
        $company = Company::whereKey($state['company_id'] ?? 0)->where('public_id', $state['company_public_id'] ?? '')->where('status', 'active')->firstOrFail();
        $member = CompanyUser::where('company_id', $company->id)->where('user_id', $state['target_user_id'] ?? 0)->where('is_owner', true)->where('status', 'active')->first();
        if ($member === null) {
            throw new AuthorizationException;
        }
        $target = User::findOrFail($member->user_id);
        if ((int) $target->id === (int) $operator->id || PlatformOperatorGrant::where('user_id', $target->id)->where('active', true)->exists()) {
            throw new AuthorizationException;
        }

        return ['company' => $company, 'target' => $target, 'details' => $state + ['operator_name' => $operator->name, 'target_name' => $target->name, 'company_name' => $company->displayName()]];
    }

    public function end(Request $request, string $exitReason = 'operator_exit'): void
    {
        $state = $request->session()->pull(self::KEY);
        if (! is_array($state)) {
            return;
        }
        $request->session()->regenerate();
        // Exiting must always remove authority even if audit storage is unavailable.
        try {
            app(PlatformAudit::class)->record('support.ended', $request->user()?->id,
                is_int($state['company_id'] ?? null) ? $state['company_id'] : null,
                ['owner_user_id' => is_int($state['target_user_id'] ?? null) ? $state['target_user_id'] : null,
                    'correlation_id' => is_string($state['correlation_id'] ?? null) ? $state['correlation_id'] : null, 'exit_reason' => $exitReason]);
        } catch (\Throwable $e) {
            Log::warning('Support view ended; audit persistence failed.', ['exception_class' => get_class($e)]);
        }
    }
}
