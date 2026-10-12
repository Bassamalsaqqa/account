<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Infrastructure\AI\DirectTextProvider;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class AssistantQuestionPlanner
{
    public function plan(
        Company $company,
        User $actor,
        string $text,
        string $formKey,
        string $sessionId,
        ?string $connectionId = null
    ): ?AssistantReportPlan {
        if ($sessionId === '' || ! Str::isUuid($formKey) || trim($text) === ''
            || mb_strlen($text) > (int) config('ai_assistant.max_text_characters')
            || app(AssistantDeterministicClassifier::class)->hasUnsupportedQualifiers($text)) {
            throw new AssistantUnavailable('question_invalid');
        }
        if (config('ai_assistant.provider_approved') !== true || config('ai_assistant.provider') !== 'direct') {
            return null;
        }

        $governance = app(AiGovernance::class);
        $policy = $governance->authorizeUse($company, $actor, 'question');
        $catalog = app(AssistantReportCatalog::class);
        $permittedKeys = $catalog->permittedKeys($company, $actor);
        if ($permittedKeys === []) {
            return null;
        }

        $routes = app(AiProviderRoutes::class)->select($company, $actor, 'question', 'text_structured', $connectionId);
        if ($routes === []) {
            return null;
        }

        $requestKey = (string) Str::uuid();
        $inputHash = hash_hmac('sha256', json_encode([
            'text' => $text,
            'form' => $formKey,
            'session' => hash('sha256', $sessionId),
            'intent' => 'question',
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));

        $budget = app(AiRequestBudget::class);

        // Budget reservation with question purpose and intent
        $reservation = $budget->reserve($company, $actor, $requestKey, $formKey, 'question', 'question', $inputHash, $routes);
        $request = $reservation['request'];

        $schema = $this->planSchema($permittedKeys);

        try {
            $response = null;
            foreach ($routes as $index => $connection) {
                $budget->beginAttempt($company, $actor, $request, $connection);
                $grant = $governance->dispatchConnection($company, $actor, $connection->public_id, 'question', 'text_structured', (int) $connection->revision);
                try {
                    $response = app(DirectTextProvider::class)->planQuestion(
                        $grant['connection']->provider,
                        $grant['connection']->model,
                        $grant['connection']->region,
                        $grant['credential'],
                        $text,
                        $schema
                    );
                    break;
                } catch (AssistantUnavailable $e) {
                    if ($e->reason !== 'provider_outage' || $index !== 0 || count($routes) !== 2) {
                        throw $e;
                    }
                }
            }

            if ($response === null) {
                throw new AssistantUnavailable('provider_outage');
            }

            if (! empty($response['usage'])) {
                $budget->recordUsage($company, $actor, $request, $response['usage']);
            }

            $rawPlan = $response['plan'];
            if (array_diff(array_keys($rawPlan), ['version', 'type', 'report_key', 'filters', 'display']) !== []
                || count($rawPlan) !== 5 || ($rawPlan['version'] ?? null) !== 1
                || ($rawPlan['type'] ?? null) !== 'report_query' || ($rawPlan['display'] ?? null) !== 'summary'
                || ! is_string($rawPlan['report_key'] ?? null) || ! is_array($rawPlan['filters'] ?? null)
                || array_diff(array_keys($rawPlan['filters']), ['preset', 'currency_code']) !== []) {
                throw new AssistantUnavailable('question_invalid');
            }
            $reportKey = $rawPlan['report_key'];
            if (! in_array($reportKey, $permittedKeys, true)) {
                throw new AssistantUnavailable('question_invalid');
            }

            // Fresh permissions recheck before delivery
            $catalog->authorize($company, $actor, $reportKey);

            $filters = [];
            foreach ($rawPlan['filters'] as $key => $value) {
                if ($value !== null) {
                    if (! is_string($value)) {
                        throw new AssistantUnavailable('question_invalid');
                    }
                    $filters[$key] = $value;
                }
            }

            $plan = AssistantReportPlan::fromArray([
                'version' => 1,
                'type' => 'report_query',
                'report_key' => $reportKey,
                'filters' => $filters,
                'display' => 'summary',
            ]);

            DB::transaction(function () use ($company, $actor, $request, $governance, $routes, $catalog, $reportKey): void {
                $policy = $governance->authorizeUse($company, $actor, 'question');
                $fresh = $request->newQuery()->where('company_id', $company->id)->where('user_id', $actor->id)->whereKey($request->id)->lockForUpdate()->firstOrFail();
                if ($fresh->status !== 'pending' || (int) $fresh->policy_revision !== (int) $policy->revision) {
                    throw new AssistantUnavailable('proposal_stale');
                }
                foreach ($routes as $connection) {
                    $governance->dispatchConnection($company, $actor, $connection->public_id, 'question', 'text_structured', (int) $connection->revision);
                }
                $catalog->authorize($company, $actor, $reportKey);
                $fresh->update(['status' => 'completed']);
            });

            return $plan;
        } catch (AssistantUnavailable|AuthorizationException $e) {
            $request->update(['status' => 'failed', 'failure_code' => $e instanceof AssistantUnavailable ? $e->reason : 'unauthorized']);
            throw $e;
        } catch (Throwable $e) {
            $request->update(['status' => 'failed', 'failure_code' => 'provider_error']);

            throw new AssistantUnavailable('provider_invalid');
        }
    }

    /**
     * @param  list<string>  $permittedKeys
     * @return array<string, mixed>
     */
    private function planSchema(array $permittedKeys): array
    {
        return [
            'type' => 'object',
            'required' => ['version', 'type', 'report_key', 'filters', 'display'],
            'additionalProperties' => false,
            'properties' => [
                'version' => ['type' => 'integer', 'enum' => [1]],
                'type' => ['type' => 'string', 'enum' => ['report_query']],
                'report_key' => ['type' => 'string', 'enum' => $permittedKeys],
                'display' => ['type' => 'string', 'enum' => ['summary']],
                'filters' => [
                    'type' => 'object',
                    'required' => ['preset', 'currency_code'],
                    'additionalProperties' => false,
                    'properties' => [
                        'preset' => [
                            'type' => ['string', 'null'],
                            'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'this_year', 'last_year', null],
                        ],
                        'currency_code' => [
                            'type' => ['string', 'null'],
                            'pattern' => '^[A-Z]{3}$',
                        ],
                    ],
                ],
            ],
        ];
    }
}
