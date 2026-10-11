<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Reporting\Presentation\ReportRegistry;
use App\Application\Reporting\Security\ReportingGuard;
use App\Models\AiCompanyPolicy;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** Read-only question capabilities are independent of transaction-create permissions. */
final class AssistantReportCatalog
{
    public const KEYS = [
        'sales.summary', 'customers.balances', 'purchases.summary', 'vendors.balances',
        'inventory.low-stock', 'inventory.expiry', 'money.balances',
    ];

    /** @return array<string, mixed> */
    public function definition(string $key): array
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new AssistantUnavailable('question_unsupported');
        }

        return app(ReportRegistry::class)->definition($key);
    }

    /** Call again after any provider latency and immediately before delivering an answer. */
    public function authorize(Company $company, User $actor, string $key): void
    {
        if (config('ai_assistant.enabled') !== true || config('ai_assistant.questions.enabled') !== true
            || in_array((string) $company->id, config('ai_assistant.disabled_company_ids', []), true)) {
            throw new AssistantUnavailable('disabled');
        }
        // Verify identity and membership before disclosing supported report metadata.
        app(ReportingGuard::class)->authorize($company, $actor, 'ai.assistant.use');
        $policy = AiCompanyPolicy::where('company_id', $company->id)->first();
        if ($policy === null || ! $policy->enabled || ($policy->settings['questions']['enabled'] ?? false) !== true) {
            throw new AssistantUnavailable('disabled');
        }
        $definition = $this->definition($key);
        if (! in_array($key, $policy->settings['questions']['allowed_reports'] ?? [], true)) {
            throw new AuthorizationException('This question capability is not permitted.');
        }
        app(ReportingGuard::class)->authorize($company, $actor, $definition['permissions']);
        if (! app(ReportRegistry::class)->allows($company, $key)) {
            throw new AuthorizationException('Report source access is required.');
        }
    }
}
