<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Reporting\Presentation\ReportRegistry;
use App\Application\Reporting\Security\ReportingGuard;
use App\Exceptions\NoActiveCompanyException;
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
        'sales.by-period', 'sales.by-customer', 'sales.by-product', 'sales.unpaid',
        'customers.top', 'customers.aging',
        'purchases.by-period', 'purchases.by-vendor', 'purchases.by-product', 'purchases.unpaid',
        'vendors.aging',
        'inventory.stock', 'inventory.movements',
        'expenses.summary', 'expenses.by-period', 'expenses.by-category',
        'sales.last-invoice',
    ];

    /** @return array<string, mixed> */
    public function definition(string $key): array
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new AssistantUnavailable('question_unsupported');
        }

        if ($key === 'sales.last-invoice') {
            return [
                'key' => $key, 'group' => 'sales',
                'name_en' => 'Latest issued sales invoice', 'name_ar' => 'آخر فاتورة مبيعات صادرة',
                'permissions' => ['sales.invoice.view', 'customers.view'],
                'filters' => ['customer_id'], 'required' => ['customer_id'],
                'defaults' => [], 'options' => [], 'columns' => [], 'current' => true,
            ];
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
        app(AiGovernance::class)->authorizeCapability($company, $actor, 'ai.assistant.use');
        $policy = AiCompanyPolicy::where('company_id', $company->id)->first();
        $questions = $policy?->settings['questions'] ?? [];
        if ($policy === null || ! $policy->enabled || ! is_array($questions) || ($questions['enabled'] ?? false) !== true) {
            throw new AssistantUnavailable('question_disabled');
        }
        $definition = $this->definition($key);
        $allowed = $questions['allowed_reports'] ?? [];
        if (! $this->validAllowlist($allowed) || ! in_array($key, $allowed, true)) {
            throw new AssistantUnavailable('question_disabled');
        }
        app(ReportingGuard::class)->authorize($company, $actor, $definition['permissions']);
        if ((str_starts_with($key, 'purchases.') || str_starts_with($key, 'vendors.'))
            && ! app(ReportingGuard::class)->canViewPurchasingCost($actor)) {
            throw new AuthorizationException('Purchasing cost read permission is required.');
        }
        if (! $this->sourceAllows($company, $actor, $key)) {
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
        try {
            // One fresh company/membership/permission snapshot for this enumeration only.
            // Execution and delivery still authorize independently after any provider latency.
            $freshActor = $guard->authorize($company, $actor, 'ai.assistant.use');
            $permissions = $freshActor->getAllPermissions()->where('guard_name', 'web')->pluck('name')->all();
        } catch (AuthorizationException|NoActiveCompanyException) {
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
        $permitted = [];
        foreach (self::KEYS as $key) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }
            $definition = $this->definition($key);
            if (array_diff($definition['permissions'], $permissions) !== []) {
                continue;
            }
            if ((str_starts_with($key, 'purchases.') || str_starts_with($key, 'vendors.'))
                && ! in_array('purchasing.cost.view', $permissions, true)) {
                continue;
            }
            // These are the only extra ReportRegistry source gates in this curated catalog.
            // Payroll and vendor-payment sources are not registered assistant capabilities.
            if ($key === 'money.balances' && ! in_array('money.cash.view', $permissions, true)
                && ! in_array('money.bank.view', $permissions, true)) {
                continue;
            }
            $permitted[] = $key;
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

    private function sourceAllows(Company $company, User $actor, string $key): bool
    {
        if ($key === 'sales.last-invoice') {
            // Independently fail closed on both persisted company activation and
            // source authority; registering code never activates this capability.
            $policy = AiCompanyPolicy::where('company_id', $company->id)->first();
            $questions = $policy?->settings['questions'] ?? [];
            $allowed = is_array($questions) ? ($questions['allowed_reports'] ?? []) : [];

            return config('ai_assistant.enabled') === true
                && config('ai_assistant.questions.enabled') === true
                && ! in_array((string) $company->id, config('ai_assistant.disabled_company_ids', []), true)
                && $policy !== null && $policy->enabled
                && is_array($questions) && ($questions['enabled'] ?? false) === true
                && $this->validAllowlist($allowed) && in_array($key, $allowed, true)
                && app(ReportingGuard::class)->allows($company, ['ai.assistant.use', 'sales.invoice.view', 'customers.view'], $actor);
        }

        return app(ReportRegistry::class)->allows($company, $key);
    }
}
