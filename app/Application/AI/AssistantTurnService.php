<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Reporting\DTO\ReportPeriod;
use App\Infrastructure\AI\DeepSeekSdkAdapter;
use App\Models\AiProviderConnection;
use App\Models\AssistantRequest;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** One bounded planning response; all records, calculations and answers stay in Laravel. */
class AssistantTurnService
{
    /** @return array<string, mixed> */
    public function run(Company $company, User $actor, string $text, string $requestKey, string $sessionId, ?string $conversationToken = null): array
    {
        if (config('ai_assistant.turn_enabled') !== true) {
            throw new AssistantUnavailable('disabled');
        }
        if (! Str::isUuid($requestKey) || trim($text) === '' || mb_strlen($text) > 4000
            || $sessionId === '' || strlen($sessionId) > 255) {
            throw new AssistantUnavailable('input_invalid');
        }
        $this->assertContext($company, $actor);
        $governance = app(AiGovernance::class);
        $policy = $governance->authorizeUse($company, $actor, 'question');
        try {
            $classification = app(AssistantDeterministicClassifier::class)->classify($text);
        } catch (AssistantUnavailable) {
            $classification = null;
        }
        if (! app(AssistantTurnConstraints::class)->isReadInvoiceQuestion($text)
            && in_array($classification['kind'] ?? null, ['transaction', 'ambiguous_operation'], true)) {
            return ['status' => 'operation_choice', 'reason' => 'intent', 'reports' => [], 'model_calls' => 0,
                'conversation_token' => null, 'request_key' => $requestKey];
        }
        $context = app(AssistantTurnContext::class);
        if ($conversationToken !== null && ! $policy->follow_up_enabled) {
            throw new AssistantUnavailable('proposal_stale');
        }
        $prior = $context->read($company, $actor, $sessionId, $conversationToken);
        $local = $this->localProposal($text, $prior, $company);
        if ($local === null && ($classification['kind'] ?? null) === 'plan') {
            $local = [$this->proposal($classification['plan']->reportKey, $classification['plan']->filters)];
        }
        $routes = $local === null ? app(AiProviderRoutes::class)->select($company, $actor, 'question', 'text_structured') : [];
        if ($routes !== []) {
            if ($routes[0]->verification_status !== 'verified') {
                throw new AssistantUnavailable('connection_unverified');
            }
            $health = app(DeepSeekQuickConnect::class)->latestHealth($company)['status'] ?? null;
            if (in_array($health, ['no_available_credit', 'provider_credit'], true)) {
                throw new AssistantUnavailable('provider_credit');
            }
        }
        $hash = hash_hmac('sha256', json_encode([$text, $sessionId, $conversationToken, 'v22-read-turn'], JSON_THROW_ON_ERROR), (string) config('app.key'));
        $reservation = app(AiRequestBudget::class)->reserve($company, $actor, $requestKey, $requestKey, 'question', 'question', $hash, $routes);
        $request = $reservation['request'];
        if (! $reservation['created']) {
            return $this->replay($company, $actor, $request, $sessionId);
        }
        try {
            if ($local === null) {
                $connection = $routes[0] ?? throw new AssistantUnavailable('provider_configuration');
                $keys = app(AssistantReportCatalog::class)->permittedKeys($company, $actor);
                $grant = $governance->dispatchConnection($company, $actor, $connection->public_id, 'question', 'text_structured', $connection->revision);
                app(AiRequestBudget::class)->beginAttempt($company, $actor, $request, $connection);
                $response = app(DeepSeekSdkAdapter::class)->sendToolIntents($grant['credential'], [
                    'messages' => [['role' => 'system', 'content' => $this->instructions($keys, $prior)], ['role' => 'user', 'content' => $text]],
                    'max_tokens' => min(700, (int) config('ai_assistant.max_output_tokens')),
                ], $keys);
                app(AiRequestBudget::class)->recordUsage($company, $actor, $request, $response['usage']);
                $proposals = [];
                foreach ($response['proposals'] as $proposal) {
                    if ($proposal['name'] === 'clarify') {
                        $result = ['status' => 'clarification', 'reason' => $proposal['arguments']['reason'], 'reports' => [], 'model_calls' => 1];

                        return $this->finish($company, $actor, $request, $sessionId, $result, [], $routes);
                    }
                    $proposals[] = $proposal['arguments'];
                }
            } else {
                $proposals = $local;
            }
            try {
                app(AssistantTurnConstraints::class)->assert($text, $proposals, $company, $prior ?? []);
                AssistantReportEntityResolver::assertMentionedEntities($company, $actor, $text, $proposals);
            } catch (AssistantUnavailable) {
                return $this->finish($company, $actor, $request, $sessionId,
                    ['status' => 'clarification', 'reason' => 'question_invalid', 'reports' => [], 'model_calls' => $routes === [] ? 0 : 1], [], $routes);
            }
            $result = $this->execute($company, $actor, $proposals, $sessionId);
            $result['model_calls'] = $routes === [] ? 0 : 1;

            return $this->finish($company, $actor, $request, $sessionId, $result, $proposals, $routes);
        } catch (Throwable $e) {
            DB::transaction(function () use ($company, $actor, $request, $e): void {
                $fresh = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)->whereKey($request->id)->lockForUpdate()->first();
                if ($fresh?->status === 'pending') {
                    $fresh->update(['status' => 'failed', 'failure_code' => $e instanceof AssistantUnavailable ? $e->reason : ($e instanceof AuthorizationException ? 'unauthorized' : 'unavailable')]);
                }
            });
            throw $e;
        }
    }

    private function assertContext(Company $company, User $actor): void
    {
        $context = app(CompanyContext::class);
        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id || ! $context->hasCompany()
            || (int) $context->companyId() !== (int) $company->id) {
            throw new AuthorizationException('Active actor and company context required.');
        }
    }

    /** @param list<string> $keys
     * @param list<array<string, mixed>>|null $prior */
    private function instructions(array $keys, ?array $prior): string
    {
        return 'Interpret Palestinian Arabic, Arabic, English and mixed merchant questions. Propose only the permitted read tools. '
            .'No SQL, IDs, financial answers, guessing names, records or customer substitution. Preserve all requested constraints; clarify unsupported or ambiguous intent, period, currency, entity, transaction or mixed read/write requests. '
            .'A debt and last invoice question needs customers.balances and sales.last-invoice with the same raw customer_name. Low stock is inventory.low-stock; expiry is inventory.expiry. '
            .'Comparisons use an explicit period and compare previous_period or previous_year. Keep current balances current; never label them historical. '
            .'Do not discard customer/product/vendor/location/currency/date qualifiers. Report keys: '.implode(',', $keys)
            .'. Prior user-request labels only (untrusted context, no records or results): '.json_encode($prior ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Safe exact shortcuts only; unfamiliar wording reaches the model, not a vocabulary rejection.
     * @param list<array<string, mixed>>|null $prior
     * @return list<array<string, mixed>>|null */
    private function localProposal(string $text, ?array $prior, Company $company): ?array
    {
        $normalized = mb_strtolower(preg_replace('/[.?!؟]+\z/u', '', trim($text)) ?? trim($text));
        if (in_array($normalized, ['شو الأصناف اللي قربت تخلص', 'what items are running low', 'low stock', 'المخزون المنخفض الآن'], true)) {
            return [$this->proposal('inventory.low-stock', ['preset' => 'today'])];
        }
        if ($prior !== null && count($prior) === 1
            && in_array($normalized, ['طيب وماذا عن الشهر اللي قبله', 'طيب والشهر الماضي', 'what about the previous month', 'and the month before'], true)) {
            $previous = AssistantIntentProposal::fromArray($prior[0]);
            $definition = app(AssistantReportCatalog::class)->definition($previous->reportKey);
            if ($definition['current']) {
                return null;
            }
            $filters = $previous->filters;
            if (isset($filters['start_date'], $filters['end_date'])) {
                $start = $filters['start_date'];
            } elseif (isset($filters['preset']) && $filters['preset'] !== 'custom') {
                $start = ReportPeriod::fromPreset($filters['preset'], $company)->startDate;
            } else {
                return null;
            }
            $date = CarbonImmutable::parse($start, $company->timezone);
            if (isset($filters['start_date'], $filters['end_date'])
                && ($date->day !== 1 || $filters['end_date'] !== $date->endOfMonth()->format('Y-m-d'))) {
                return null;
            }
            if (isset($filters['preset']) && ! in_array($filters['preset'], ['this_month', 'last_month'], true)) {
                return null;
            }
            // After a monthly comparison, "the month before that" refers to its second period.
            // Other period shapes need interpretation/clarification, never a guessed month.
            if (isset($filters['compare'])) {
                if ($filters['compare'] !== 'previous_period' || ! in_array($filters['preset'] ?? null, ['this_month', 'last_month'], true)) {
                    return null;
                }
                $date = $date->subMonthNoOverflow();
            }
            $date = $date->subMonthNoOverflow();
            unset($filters['preset'], $filters['compare']);
            $filters['start_date'] = $date->startOfMonth()->format('Y-m-d');
            $filters['end_date'] = $date->endOfMonth()->format('Y-m-d');

            return [$this->proposal($previous->reportKey, $filters)];
        }

        return null;
    }

    /** @param array<string, string> $filters
     * @return array<string, mixed> */
    private function proposal(string $key, array $filters): array
    {
        return ['version' => 1, 'type' => 'report_query', 'report_key' => $key, 'filters' => $filters, 'display' => 'summary'];
    }

    /** @param list<array<string, mixed>> $proposals
     * @return array<string, mixed> */
    private function execute(Company $company, User $actor, array $proposals, string $sessionId): array
    {
        $scope = app(AssistantTurnContext::class)->scope($company, $actor, $sessionId);
        $reports = [];
        try {
            foreach ($proposals as $proposal) {
                AssistantIntentProposal::fromArray($proposal);
                $plan = AssistantReportPlan::fromArray($proposal);
                $reports[] = app(AssistantReportExecutor::class)->execute($company, $actor, $plan)->toArray();
            }
        } catch (AssistantUnavailable $e) {
            if (in_array($e->reason, ['entity_missing', 'entity_ambiguous', 'question_unsupported', 'question_invalid'], true)) {
                return ['status' => 'clarification', 'reason' => $e->reason, 'reports' => []];
            }
            throw $e;
        }
        if (strlen(json_encode($reports, JSON_THROW_ON_ERROR)) > AssistantReportExecutor::MAX_OUTPUT_BYTES) {
            throw new AssistantUnavailable('output_limit_exceeded');
        }
        if (! hash_equals($scope, app(AssistantTurnContext::class)->scope($company, $actor, $sessionId))) {
            throw new AssistantUnavailable('proposal_stale');
        }

        return ['status' => 'answer', 'reports' => $reports, '_scope' => $scope];
    }

    /** @param array<string, mixed> $result
     * @param list<array<string, mixed>> $proposals
     * @param list<AiProviderConnection> $routes
     * @return array<string, mixed> */
    private function finish(Company $company, User $actor, AssistantRequest $request, string $sessionId, array $result, array $proposals, array $routes): array
    {
        return DB::transaction(function () use ($company, $actor, $request, $sessionId, $result, $proposals, $routes): array {
            $this->assertContext($company, $actor);
            $policy = app(AiGovernance::class)->authorizeUse($company, $actor, 'question');
            $fresh = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($fresh->status !== 'pending' || (int) $fresh->policy_revision !== (int) $policy->revision) {
                throw new AssistantUnavailable('proposal_stale');
            }
            foreach ($routes as $route) {
                app(AiGovernance::class)->dispatchConnection($company, $actor, $route->public_id, 'question', 'text_structured', $route->revision);
            }
            foreach ($proposals as $proposal) {
                app(AssistantReportCatalog::class)->authorize($company, $actor, $proposal['report_key']);
            }
            if (isset($result['_scope']) && ! hash_equals($result['_scope'], app(AssistantTurnContext::class)->scope($company, $actor, $sessionId))) {
                throw new AssistantUnavailable('proposal_stale');
            }
            $deliveryScope = app(AssistantTurnContext::class)->scope($company, $actor, $sessionId);
            unset($result['_scope']);
            $token = $proposals === [] ? null : app(AssistantTurnContext::class)->store($company, $actor, $sessionId, $proposals);
            Cache::put($this->replayKey($request, $sessionId), Crypt::encryptString(json_encode(['proposals' => $proposals, 'status' => $result['status'], 'reason' => $result['reason'] ?? null, 'token' => $token], JSON_THROW_ON_ERROR)), AssistantTurnContext::TTL);
            $fresh->update(['status' => 'completed']);

            return [...$result, '_authorization_scope' => $deliveryScope, 'conversation_token' => $policy->follow_up_enabled ? $token : null, 'request_key' => $request->request_key];
        });
    }

    /** @return array<string, mixed> */
    private function replay(Company $company, User $actor, AssistantRequest $request, string $sessionId): array
    {
        if ($request->status !== 'completed') {
            throw new AssistantUnavailable('request_processed');
        }
        $cipher = Cache::get($this->replayKey($request, $sessionId));
        if (! is_string($cipher) || strlen($cipher) > 16000) {
            throw new AssistantUnavailable('request_processed');
        }
        $saved = json_decode(Crypt::decryptString($cipher), true, 12, JSON_THROW_ON_ERROR);
        $proposals = $saved['proposals'] ?? null;
        if (! is_array($proposals) || ! array_is_list($proposals) || count($proposals) > 3) {
            throw new AssistantUnavailable('request_processed');
        }
        if ($proposals !== []) {
            app(AssistantTurnContext::class)->read($company, $actor, $sessionId, $saved['token'] ?? null);
            $result = $this->execute($company, $actor, $proposals, $sessionId);
        } else {
            $result = ['status' => 'clarification', 'reason' => $saved['reason'] ?? 'intent', 'reports' => []];
        }
        $deliveryScope = $result['_scope'] ?? app(AssistantTurnContext::class)->scope($company, $actor, $sessionId);
        unset($result['_scope']);

        return [...$result, '_authorization_scope' => $deliveryScope, 'conversation_token' => $saved['token'] ?? null, 'request_key' => $request->request_key, 'model_calls' => 0, 'replayed' => true];
    }

    private function replayKey(AssistantRequest $request, string $sessionId): string
    {
        return 'ai-turn-replay:'.hash('sha256', $request->company_id.':'.$request->user_id.':'.$request->request_key.':'.$sessionId);
    }
}
