<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\AiCompanyPolicy;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
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
        'sales.unpaid',
        'purchases.unpaid',
        'money.balances',
        'inventory.low-stock',
        'inventory.expiry',
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
            $cards[] = $dto->toArray();
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
        return [
            'sales.unpaid' => AssistantReportPlan::fromArray([
                'version' => 1,
                'type' => 'report_query',
                'report_key' => 'sales.unpaid',
                'filters' => ['preset' => 'today'],
                'display' => 'summary',
            ]),
            'purchases.unpaid' => AssistantReportPlan::fromArray([
                'version' => 1,
                'type' => 'report_query',
                'report_key' => 'purchases.unpaid',
                'filters' => ['preset' => 'today'],
                'display' => 'summary',
            ]),
            'money.balances' => AssistantReportPlan::fromArray([
                'version' => 1,
                'type' => 'report_query',
                'report_key' => 'money.balances',
                'filters' => [],
                'display' => 'summary',
            ]),
            'inventory.low-stock' => AssistantReportPlan::fromArray([
                'version' => 1,
                'type' => 'report_query',
                'report_key' => 'inventory.low-stock',
                'filters' => ['preset' => 'today'],
                'display' => 'summary',
            ]),
            'inventory.expiry' => AssistantReportPlan::fromArray([
                'version' => 1,
                'type' => 'report_query',
                'report_key' => 'inventory.expiry',
                'filters' => ['preset' => 'today'],
                'display' => 'summary',
            ]),
        ];
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
