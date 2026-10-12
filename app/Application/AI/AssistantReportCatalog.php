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
        $questions = $policy?->settings['questions'] ?? [];
        if ($policy === null || ! $policy->enabled || ! is_array($questions) || ($questions['enabled'] ?? false) !== true) {
            throw new AssistantUnavailable('disabled');
        }
        $definition = $this->definition($key);
        $allowed = $questions['allowed_reports'] ?? [];
        if (! $this->validAllowlist($allowed) || ! in_array($key, $allowed, true)) {
            throw new AuthorizationException('This question capability is not permitted.');
        }
        app(ReportingGuard::class)->authorize($company, $actor, $definition['permissions']);
        if (! app(ReportRegistry::class)->allows($company, $key)) {
            throw new AuthorizationException('Report source access is required.');
        }
    }

    /** @return list<string> */
    public function permittedKeys(Company $company, User $actor): array
    {
        if (config('ai_assistant.enabled') !== true || config('ai_assistant.questions.enabled') !== true
            || in_array((string) $company->id, config('ai_assistant.disabled_company_ids', []), true)) {
            return [];
        }
        $guard = app(ReportingGuard::class);
        if (! $guard->allows($company, 'ai.assistant.use', $actor)) {
            return [];
        }
        $policy = AiCompanyPolicy::where('company_id', $company->id)->first();
        $questions = $policy?->settings['questions'] ?? [];
        if ($policy === null || ! $policy->enabled || ! is_array($questions) || ($questions['enabled'] ?? false) !== true) {
            return [];
        }
        $allowed = $questions['allowed_reports'] ?? [];
        if (! $this->validAllowlist($allowed)) {
            return [];
        }
        $registry = app(ReportRegistry::class);
        $permitted = [];
        foreach (self::KEYS as $key) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }
            try {
                $definition = $registry->definition($key);
                if ($guard->allows($company, $definition['permissions'], $actor) && $registry->allows($company, $key)) {
                    $permitted[] = $key;
                }
            } catch (\Throwable) {
                // Ignore unregistered or restricted definition errors
            }
        }

        return $permitted;
    }

    private function validAllowlist(mixed $allowed): bool
    {
        return is_array($allowed) && array_is_list($allowed)
            && count($allowed) <= count(self::KEYS)
            && count(array_filter($allowed, fn ($key) => is_string($key) && in_array($key, self::KEYS, true))) === count($allowed)
            && count(array_unique($allowed)) === count($allowed);
    }
}
