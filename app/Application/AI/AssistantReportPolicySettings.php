<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Platform\TenantWriteAccess;
use App\Models\AiCompanyPolicy;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/** Explicit report access changes never activate AI or grant domain permissions. */
final class AssistantReportPolicySettings
{
    /** @return array{revision: int, allowed_reports: list<string>}|null */
    public function read(Company $company, User $actor): ?array
    {
        return DB::transaction(function () use ($company, $actor): ?array {
            $this->authorizeOwner($company, $actor);
            $policy = AiCompanyPolicy::where('company_id', $company->id)->first();
            if ($policy === null) {
                return null;
            }
            $reports = $policy->settings['questions']['allowed_reports'] ?? [];
            $this->validateReports($reports);

            return ['revision' => (int) $policy->revision, 'allowed_reports' => $reports];
        });
    }

    /** @param list<string> $reports */
    public function update(Company $company, User $actor, array $reports, int $expectedRevision,
        #[SensitiveParameter] string $password = ''): AiCompanyPolicy
    {
        $this->validateReports($reports);

        return DB::transaction(function () use ($company, $actor, $reports, $expectedRevision, $password): AiCompanyPolicy {
            app(TenantWriteAccess::class)->assertWritable((int) $company->id);
            $this->authorizeOwner($company, $actor);
            $policy = AiCompanyPolicy::where('company_id', $company->id)->lockForUpdate()->first();
            if ($policy === null) {
                throw new AssistantUnavailable('policy_invalid');
            }
            if ((int) $policy->revision !== $expectedRevision) {
                throw new AssistantUnavailable('proposal_stale');
            }
            $settings = $policy->settings ?? [];
            $questions = $settings['questions'] ?? ['enabled' => false, 'allowed_reports' => []];
            if (! is_array($questions) || ! is_bool($questions['enabled'] ?? null)) {
                throw new AssistantUnavailable('policy_invalid');
            }
            $previous = $questions['allowed_reports'] ?? [];
            $this->validateReports($previous);
            $previousSet = $previous;
            $requestedSet = $reports;
            sort($previousSet);
            sort($requestedSet);
            if ($requestedSet === $previousSet) {
                return $policy;
            }
            if (array_diff($reports, $previous) !== []) {
                $freshActor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                if (! Hash::check($password, $freshActor->password)) {
                    throw new AuthorizationException('Fresh Owner password confirmation is required to expand report access.');
                }
            }
            $settings['questions'] = [...$questions, 'allowed_reports' => $reports];
            $updated = app(AiGovernance::class)->updatePolicy($company, $actor, [
                'enabled' => $policy->enabled,
                'personal_credentials_enabled' => $policy->personal_credentials_enabled,
                'follow_up_enabled' => $policy->follow_up_enabled,
                'workflow_mode' => $policy->workflow_mode,
                'speech_method' => $policy->speech_method,
                'settings' => $settings,
            ], $expectedRevision);
            // Legacy settings requiring normalization use the existing explicit
            // setup transition. Editing reports must not normalize other fields.
            $otherBefore = $policy->settings ?? [];
            $otherAfter = $updated->settings ?? [];
            unset($otherBefore['questions'], $otherAfter['questions']);
            if ($otherBefore !== $otherAfter) {
                throw new AssistantUnavailable('policy_invalid');
            }
            app(AuditService::class)->log((int) $company->id, 'ai.report_policy.updated',
                'AI report access explicitly updated by the Company Owner.', (int) $actor->id, $updated,
                meta: ['revision' => $updated->revision, 'previous_reports' => $previous, 'allowed_reports' => $reports]);

            return $updated;
        });
    }

    private function authorizeOwner(Company $company, User $actor): void
    {
        app(AiGovernance::class)->authorizeCapability($company, $actor, 'ai.settings.manage');
        if (! CompanyUser::where('company_id', $company->id)->where('user_id', $actor->id)
            ->where('status', 'active')->where('is_owner', true)->lockForUpdate()->exists()) {
            throw new AuthorizationException('Protected company Owner authorization is required.');
        }
    }

    private function validateReports(mixed $reports): void
    {
        if (! is_array($reports) || ! array_is_list($reports) || count($reports) > count(AssistantReportCatalog::KEYS)) {
            throw new AssistantUnavailable('policy_invalid');
        }
        foreach ($reports as $key) {
            if (! is_string($key) || ! in_array($key, AssistantReportCatalog::KEYS, true)) {
                throw new AssistantUnavailable('policy_invalid');
            }
        }
        if (count($reports) !== count(array_unique($reports))) {
            throw new AssistantUnavailable('policy_invalid');
        }
    }
}
