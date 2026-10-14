<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\AiCompanyPolicy;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

final class AssistantDailyBriefing
{
    /**
     * Suggested bounded cards for daily briefing.
     *
     * @var list<string>
     */
    public const array CARD_KEYS = [
        'sales.summary',
        'sales.unpaid',
        'purchases.unpaid',
        'money.balances',
        'inventory.low-stock',
        'inventory.expiry',
    ];

    /**
     * Recognized briefing insight kinds.
     *
     * @var list<string>
     */
    public const array INSIGHT_KINDS = [
        'sales_comparison',
        'sales_unpaid',
        'purchases_unpaid',
        'low_stock',
        'expiry',
    ];

    public function isAvailable(Company $company, User $actor): bool
    {
        if (config('ai_assistant.daily_briefing_enabled') !== true
            || config('ai_assistant.enabled') !== true
            || config('ai_assistant.questions.enabled') !== true
            || in_array((string) $company->id, config('ai_assistant.disabled_company_ids', []), true)) {
            return false;
        }

        try {
            $governance = app(AiGovernance::class);
            if (! $governance->can($company, $actor, 'ai.assistant.use')) {
                return false;
            }
            $policy = AiCompanyPolicy::where('company_id', $company->id)->first();
            $questions = $policy?->settings['questions'] ?? [];
            if ($policy === null || ! $policy->enabled || ! is_array($questions) || ($questions['enabled'] ?? false) !== true) {
                return false;
            }
            $permitted = app(AssistantReportCatalog::class)->permittedKeys($company, $actor);

            return array_intersect(self::CARD_KEYS, $permitted) !== [];
        } catch (AuthorizationException) {
            return false;
        }
    }

    /**
     * Deterministic, zero-LLM daily briefing using canonical reports exclusively.
     *
     * @return array{
     *     status: string,
     *     reports: list<array<string, mixed>>,
     *     insights: list<array<string,mixed>>,
     *     model_calls: int,
     *     _authorization_scope: string,
     *     generated_at: string,
     *     timezone: string
     * }
     */
    public function generate(Company $company, User $actor, string $sessionId): array
    {
        if (config('ai_assistant.daily_briefing_enabled') !== true
            || config('ai_assistant.enabled') !== true
            || config('ai_assistant.questions.enabled') !== true
            || in_array((string) $company->id, config('ai_assistant.disabled_company_ids', []), true)) {
            throw new AssistantUnavailable('disabled');
        }

        if (trim($sessionId) === '' || strlen($sessionId) > 255) {
            throw new AssistantUnavailable('invalid_request');
        }

        $this->assertContext($company, $actor);

        $governance = app(AiGovernance::class);
        $governance->authorizeUse($company, $actor, 'question');

        // Capture fresh AssistantTurnContext::scope BEFORE generating cards
        $turnContext = app(AssistantTurnContext::class);
        $initialScope = $turnContext->scope($company, $actor, $sessionId);

        $catalog = app(AssistantReportCatalog::class);
        $executor = app(AssistantReportExecutor::class);
        $permitted = $catalog->permittedKeys($company, $actor);

        $plans = $this->buildPlans();
        $cards = [];
        $dtosByKey = [];

        foreach (self::CARD_KEYS as $key) {
            if (! in_array($key, $permitted, true)) {
                // Omit enumerated unauthorized or disabled cards; never zero them
                continue;
            }

            // The canonical executor independently authorizes each card before and after retrieval.
            // Query or revocation failures invalidate the snapshot; they are never zeroed.
            $plan = $plans[$key] ?? null;
            if ($plan === null) {
                continue;
            }
            $dto = $executor->execute($company, $actor, $plan);
            if ($key !== 'sales.summary') {
                $cards[] = $dto->toArray();
            }
            $dtosByKey[$key] = $dto;
        }

        $insights = [];
        foreach ($dtosByKey as $dto) {
            $insight = $this->buildInsight($dto);
            if ($insight !== null) {
                $insights[] = $insight;
            }
        }
        // Compare fresh scope after all queries; fail proposal_stale on any change.
        $finalScope = $turnContext->scope($company, $actor, $sessionId);
        if (! hash_equals($initialScope, $finalScope)) {
            throw new AssistantUnavailable('proposal_stale');
        }

        // Recheck current actor and company context before delivery
        $this->assertContext($company, $actor);

        $payload = [
            'status' => 'answer',
            'reports' => $cards,
            'insights' => $insights,
            'model_calls' => 0,
            '_authorization_scope' => $finalScope,
            'generated_at' => CarbonImmutable::now($company->timezone)->toIso8601String(),
            'timezone' => (string) $company->timezone,
        ];

        // Enforce aggregate 64KiB response cap
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > AssistantReportExecutor::MAX_OUTPUT_BYTES) {
            throw new AssistantUnavailable('output_limit_exceeded');
        }

        return $payload;
    }

    /**
     * @return array<string, AssistantReportPlan>
     */
    public function buildPlans(): array
    {
        $plans = [];
        foreach (self::CARD_KEYS as $key) {
            $filters = match ($key) {
                'money.balances' => [],
                'sales.summary' => ['preset' => 'this_month', 'compare' => 'previous_period'],
                default => ['preset' => 'today'],
            };
            $plans[$key] = AssistantReportPlan::fromArray([
                'version' => 1, 'type' => 'report_query', 'report_key' => $key,
                'filters' => $filters, 'display' => 'summary',
            ]);
        }

        return $plans;
    }

    /** @return array<string,mixed>|null */
    private function buildInsight(AssistantReportDto $dto): ?array
    {
        $kind = match ($dto->reportKey) {
            'sales.summary' => 'sales_comparison',
            'sales.unpaid' => 'sales_unpaid',
            'purchases.unpaid' => 'purchases_unpaid',
            'inventory.low-stock' => 'low_stock',
            'inventory.expiry' => 'expiry',
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        $values = [];
        $amounts = [];
        $currency = null;
        if ($kind === 'sales_comparison') {
            $previousTotals = $dto->comparison['totals'] ?? [];
            $current = $this->number($dto->totals, 'net_sales_base');
            $previous = $this->number($previousTotals, 'net_sales_base');
            $difference = BigDecimal::of($current)->minus($previous);
            $values = [
                'current_net_sales' => $current,
                'previous_net_sales' => $previous,
                'difference' => (string) $difference,
                'current_invoice_count' => $this->number($dto->totals, 'original_invoice_count'),
                'previous_invoice_count' => $this->number($previousTotals, 'original_invoice_count'),
                'trend' => $difference->isPositive() ? 'up' : ($difference->isNegative() ? 'down' : 'same'),
            ];
            $currency = $dto->currency['base_currency_code'];
        } elseif (in_array($kind, ['sales_unpaid', 'purchases_unpaid'], true)) {
            $countKey = $kind === 'sales_unpaid' ? 'unpaid_invoice_count' : 'unpaid_purchase_count';
            $values = ['unpaid_count' => $this->number($dto->totals, $countKey)];
            $byCurrency = $dto->totals['outstanding_by_currency'] ?? null;
            if (! is_array($byCurrency)) {
                throw new AssistantUnavailable('unavailable');
            }
            foreach ($byCurrency as $code => $value) {
                if (! is_string($code) || ! preg_match('/\A[A-Z]{3}\z/D', $code)) {
                    throw new AssistantUnavailable('unavailable');
                }
                $amounts[$code] = $this->number([$code => $value], $code);
            }
            ksort($amounts);
        } else {
            $keys = $kind === 'low_stock' ? ['total_alert_count', 'low_stock_count', 'out_of_stock_count']
                : ['expired_count', 'soon_count', 'valid_count', 'unknown_count', 'total_active_lots'];
            foreach ($keys as $key) {
                $values[$key] = $this->number($dto->totals, $key);
            }
        }

        return [
            'kind' => $kind,
            'label_key' => 'assistant_briefing.insights.'.$kind,
            'values' => $values,
            'amounts' => $amounts,
            'currency' => $currency,
            'source_url' => $dto->sourceUrl,
            'previous_source_url' => $dto->comparison['source_url'] ?? null,
            'period' => $dto->period,
            'comparison_period' => $dto->comparison['period'] ?? null,
        ];
    }

    /** Missing or redacted values are unavailable, never silently invented as zero.
     * @param array<string,mixed> $totals */
    private function number(array $totals, string $key): string
    {
        $value = $totals[$key] ?? null;
        if ((! is_string($value) && ! is_int($value))
            || ! preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/D', (string) $value)) {
            throw new AssistantUnavailable('unavailable');
        }

        return (string) $value;
    }

    public static function isBriefingQuery(string $text): bool
    {
        $normalized = mb_strtolower(preg_replace('/[.?!؟]+\z/u', '', trim($text)) ?? trim($text));

        return in_array($normalized, [
            'daily briefing',
            'briefing',
            'daily summary',
            'accounting briefing',
            'الملخص اليومي',
            'الموجز اليومي',
            'ملخص اليوم',
            'موجز اليوم',
            'الملخص المحاسبي',
            'الموجز المحاسبي',
            'الملخص',
            'الموجز',
            'تقرير اليوم',
        ], true);
    }

    private function assertContext(Company $company, User $actor): void
    {
        $context = app(CompanyContext::class);
        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id || ! $context->hasCompany()
            || (int) $context->companyId() !== (int) $company->id) {
            throw new AuthorizationException('Active actor and company context required.');
        }
    }
}
