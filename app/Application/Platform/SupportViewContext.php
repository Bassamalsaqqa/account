<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Effective tenant authority and real administrator provenance remain separate. */
final class SupportViewContext
{
    /** @var array<string, mixed>|null */
    private ?array $details = null;

    private bool $trustedHost = false;

    /** @param array<string, mixed> $details */
    public function activate(array $details): void
    {
        $this->details = $details;
        $this->trustedHost = false;
    }

    /** @param array<string, mixed> $details */
    public function activateFromHost(array $details): void
    {
        if (PHP_SAPI !== 'cli' || ! app()->runningInConsole()) {
            throw new AuthorizationException;
        }
        $this->activate($details);
        $this->trustedHost = true;
    }

    public function clear(): void
    {
        $this->details = null;
        $this->trustedHost = false;
    }

    public function active(): bool
    {
        return $this->details !== null;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details ?? [];
    }

    public function actualActorId(int $effectiveId): int
    {
        return $this->active() ? (int) $this->details['operator_id'] : $effectiveId;
    }

    public function sessionActor(User $effective): User
    {
        if (! $this->active()) {
            return $effective;
        }
        $this->assertBinding((int) $this->details['company_id']);
        if ($this->trustedHost) {
            throw new AuthorizationException('Host delegation is not a browser session.');
        }
        app(SupportViewSession::class)->resolve(request());

        return User::findOrFail($this->details['operator_id']);
    }

    public function assertWritable(int $companyId): void
    {
        if (! $this->active()) {
            return;
        }
        $this->assertBinding($companyId);
        if (DB::transactionLevel() < 1) {
            throw new AuthorizationException('Delegated writes require their canonical transaction.');
        }
        if (! $this->trustedHost) {
            app(SupportViewSession::class)->resolve(request(), true);

            return;
        }
        $company = Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
        $grant = PlatformOperatorGrant::where('user_id', $this->details['operator_id'])->lockForUpdate()->first();
        $operator = User::whereKey($this->details['operator_id'])->lockForUpdate()->first();
        $owners = CompanyUser::where('company_id', $companyId)->where('status', 'active')->where('is_owner', true)->lockForUpdate()->get();
        if ($company->status !== 'active' || $operator === null || $grant === null || ! $grant->active
            || ! in_array('company.impersonate', $grant->permissions ?? [], true) || (int) $grant->revision !== $this->details['grant_revision']
            || $owners->count() !== 1 || (int) $owners->first()->user_id !== $this->details['target_user_id']) {
            throw new AuthorizationException('Administrative delegation changed.');
        }
    }

    private function assertBinding(int $companyId): void
    {
        $state = $this->details;
        $context = app(CompanyContext::class);
        if ($state === null || ($state['access_mode'] ?? null) !== 'owner' || ($state['company_id'] ?? null) !== $companyId
            || ! $context->hasCompany() || $context->companyId() !== $companyId
            || (int) auth()->id() !== ($state['target_user_id'] ?? null)
            || ! is_int($state['expires_at'] ?? null) || $state['expires_at'] <= now()->timestamp) {
            throw new AuthorizationException('Administrative delegation is absent, expired or bound to another company.');
        }
    }

    /** Canonical writers call this in the same transaction as the immutable effect. */
    public function recordEffect(Model $subject, string $action): void
    {
        if (! $this->active()) {
            return;
        }
        $this->assertWritable((int) $subject->getAttribute('company_id'));
        app(PlatformAudit::class)->record('impersonation.action', (int) $this->details['operator_id'], (int) $this->details['company_id'], [
            'owner_user_id' => (int) $this->details['target_user_id'], 'correlation_id' => $this->details['correlation_id'],
            'subject_type' => get_class($subject), 'subject_id' => (int) $subject->getKey(), 'action' => $action,
            'authority' => $this->trustedHost ? 'trusted_host' : 'operator_session',
        ]);
    }
}
