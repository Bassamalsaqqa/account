<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Infrastructure\AI\DirectTextProvider;
use App\Models\AiProviderConnection;
use App\Models\AssistantRequest;
use App\Models\Company;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class TransactionAssistant
{
    /** @return array<string, mixed> */
    public function prepare(Company $company, User $actor, string $text, string $context, string $formKey, string $requestKey, string $sessionId, ?string $connectionId = null, ?string $audioToken = null): array
    {
        if (trim($text) === '' || mb_strlen($text) > (int) config('ai_assistant.max_text_characters') || $sessionId === '' || $audioToken !== null) {
            throw new AssistantUnavailable('input_invalid');
        }
        $governance = app(AiGovernance::class);
        $policy = $governance->authorizeUse($company, $actor, $context);
        $this->requireNotCanceled($company, $actor, $formKey, $sessionId);
        $proposal = app(DeterministicTransactionParser::class)->parse($text, $context);
        if ($proposal !== null && in_array($proposal['intent'], ['sales', 'purchase'], true)) {
            $governance->authorizeUse($company, $actor, $proposal['intent']);
        }
        $routes = $proposal === null ? app(AiProviderRoutes::class)->select($company, $actor, $context, 'text_structured', $connectionId) : [];
        if ($routes !== []) {
            if ($routes[0]->verification_status !== 'verified') {
                throw new AssistantUnavailable('connection_unverified');
            }
            $health = app(DeepSeekQuickConnect::class)->latestHealth($company)['status'] ?? null;
            if (in_array($health, ['no_available_credit', 'provider_credit'], true)) {
                throw new AssistantUnavailable('provider_credit');
            }
        }
        $hash = $this->inputHash(['text' => $text, 'context' => $context, 'form' => $formKey,
            'session' => hash('sha256', $sessionId), 'connection' => $routes[0]->public_id ?? null,
            'policy' => $policy->revision, 'audio_token' => $audioToken]);
        $reservation = app(AiRequestBudget::class)->reserve($company, $actor, $requestKey, $formKey, 'intent', $context, $hash, $routes);
        $request = $reservation['request'];
        if (! $reservation['created']) {
            return $this->replay($company, $actor, $request, $sessionId, $formKey);
        }
        try {
            if ($proposal === null) {
                $allowed = $governance->allowedIntents($company, $actor);
                $modelContext = $context === 'auto' && count($allowed) === 1 ? $allowed[0] : $context;
                $response = $this->textInference($company, $actor, $request, $routes, $text, $modelContext);
                $proposal = $response['proposal'];
            }
            $this->requireNotCanceled($company, $actor, $formKey, $sessionId);
            $fresh = $governance->authorizeUse($company, $actor, $context);
            if ((int) $fresh->revision !== (int) $request->policy_revision) {
                throw new AssistantUnavailable('proposal_stale');
            }
            $proposal = app(TransactionProposalSchema::class)->validate($proposal, $text, $context);
            if (in_array($proposal['intent'], ['unsupported', 'uncertain'], true)) {
                $this->finishRequest($company, $actor, $request, $sessionId, $formKey, ['status' => $proposal['intent']]);

                return ['state' => $proposal['intent'], 'request_id' => (int) $request->id];
            }
            $governance->authorizeUse($company, $actor, $proposal['intent']);
            $resolved = app(TransactionProposalResolver::class)->resolve($company, $actor, $proposal);
            $connections = $this->snapshots($routes);
            foreach ($connections as $snapshot) {
                $governance->dispatchConnection($company, $actor, $snapshot['public_id'], $proposal['intent'], $snapshot['capability'], $snapshot['revision']);
            }
            $data = ['request_id' => (int) $request->id, 'proposal' => $proposal, 'transcript' => $text,
                'resolved' => $resolved, 'connections' => $connections, 'lifecycle' => $resolved['questions'] === [] ? 'handoff' : 'clarifying'];
            $token = $this->finishRequest($company, $actor, $request, $sessionId, $formKey,
                ['intent_kind' => $proposal['intent'], 'status' => $resolved['questions'] === [] ? 'form_ready' : 'clarifying'], $data, $proposal['intent']);

            return $this->presentation($token, $resolved, (int) $request->id);
        } catch (Throwable $e) {
            $this->fail($request, $e);
            throw $e;
        }
    }

    public function cancel(Company $company, User $actor, string $formKey, string $sessionId): void
    {
        if (! Str::isUuid($formKey) || $sessionId === '') {
            throw new AssistantUnavailable('invalid_request');
        }
        DB::transaction(function () use ($company, $actor, $formKey, $sessionId): void {
            app(AiGovernance::class)->authorizeCapability($company, $actor, 'ai.assistant.use');
            Cache::put($this->cancelKey($company, $actor, $formKey, $sessionId), true, (int) config('ai_assistant.proposal_ttl_seconds'));
            $requests = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)
                ->where('form_key', $formKey)->whereIn('status', ['pending', 'clarifying', 'form_ready', 'transcribed'])->lockForUpdate()->get();
            foreach ($requests as $request) {
                $token = Cache::get($this->requestKey($request));
                if (is_string($token)) {
                    app(AiFormState::class)->forget($company, $actor, $token, $sessionId);
                }
                $request->update(['status' => 'canceled']);
                Cache::forget($this->requestKey($request));
            }
        });
    }

    /** Company-first locking serializes cancellation against handoff publication.
     * @param  array<string, mixed>  $updates
     * @param  array<string, mixed>|null  $data
     */
    private function finishRequest(Company $company, User $actor, AssistantRequest $request, string $sessionId, string $formKey, array $updates, ?array $data = null, ?string $intent = null): string
    {
        return DB::transaction(function () use ($company, $actor, $request, $sessionId, $formKey, $updates, $data, $intent): string {
            $policy = app(AiGovernance::class)->authorizeUse($company, $actor, $intent ?? $request->intent_kind);
            $fresh = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->requireNotCanceled($company, $actor, $formKey, $sessionId);
            if ($fresh->status !== 'pending' || (int) $policy->revision !== (int) $fresh->policy_revision) {
                throw new AssistantUnavailable('proposal_stale');
            }
            // Recheck carried text keys and consent before publishing any encrypted handoff.
            foreach ($data['connections'] ?? [] as $connection) {
                app(AiGovernance::class)->dispatchConnection($company, $actor, $connection['public_id'],
                    $intent ?? $fresh->intent_kind, $connection['capability'], $connection['revision']);
            }
            $token = $data === null ? '' : app(AiFormState::class)->create($company, $actor, $formKey, $sessionId, $intent ?? $fresh->intent_kind, $data);
            $fresh->update($updates);
            if ($token !== '') {
                Cache::put($this->requestKey($fresh), $token, (int) config('ai_assistant.proposal_ttl_seconds'));
            }

            return $token;
        });
    }

    private function requireNotCanceled(Company $company, User $actor, string $formKey, string $sessionId): void
    {
        if (Cache::get($this->cancelKey($company, $actor, $formKey, $sessionId)) === true) {
            throw new AssistantUnavailable('request_canceled');
        }
    }

    private function cancelKey(Company $company, User $actor, string $formKey, string $sessionId): string
    {
        return 'ai-form-canceled:'.$company->id.':'.$actor->id.':'.hash('sha256', $sessionId.':'.$formKey);
    }

    /** @param array<array-key, mixed> $choices
     * @return array<string, mixed>
     */
    public function clarify(Company $company, User $actor, string $token, string $sessionId, string $formKey, array $choices): array
    {
        return DB::transaction(function () use ($company, $actor, $token, $sessionId, $formKey, $choices): array {
            app(AiGovernance::class)->authorizeUse($company, $actor, 'auto');
            $this->requireNotCanceled($company, $actor, $formKey, $sessionId);
            $state = app(AiFormState::class)->read($company, $actor, $token, $sessionId, $formKey);
            $data = $state['data'];
            $request = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)->whereKey($data['request_id'])->lockForUpdate()->firstOrFail();
            if ($request->status !== 'clarifying') {
                throw new AssistantUnavailable('proposal_stale');
            }
            if (($data['lifecycle'] ?? null) !== 'clarifying' || count($choices) > 20) {
                throw new AssistantUnavailable('proposal_stale');
            }
            $humanPaths = [];
            foreach ($choices as $path => $id) {
                $question = null;
                foreach ($data['resolved']['questions'] as $candidateQuestion) {
                    if (is_array($candidateQuestion) && ($candidateQuestion['path'] ?? null) === $path) {
                        $question = $candidateQuestion;
                        break;
                    }
                }
                if (! is_string($path) || $question === null) {
                    throw new AssistantUnavailable('choice_invalid');
                }
                if ($question['candidates'] !== []) {
                    if (! is_int($id) || ! in_array($id, array_column($question['candidates'], 'value'), true)) {
                        throw new AssistantUnavailable('choice_invalid');
                    }
                } else {
                    if (! is_string($id)) {
                        throw new AssistantUnavailable('choice_invalid');
                    }
                    $value = app(SalesProposalSchema::class)->normalizeDigits(trim($id));
                    if ($path === 'currency') {
                        $value = strtoupper($value);
                        if (! in_array($value, ['ILS', 'USD', 'JOD'], true)) {
                            throw new AssistantUnavailable('choice_invalid');
                        }
                    } elseif ($path === 'exchange_rate' || preg_match('/\Alines\.\d+\.quantity\z/', $path)) {
                        $scale = $path === 'exchange_rate' ? 10 : 6;
                        if (! preg_match('/\A\d{1,10}(?:\.\d{1,'.$scale.'})?\z/', $value)
                            || ! BigDecimal::of($value)->isPositive()) {
                            throw new AssistantUnavailable('choice_invalid');
                        }
                    } else {
                        throw new AssistantUnavailable('choice_invalid');
                    }
                    data_set($data['proposal'], $path, $value);
                    $humanPaths[] = $path;
                    unset($choices[$path]);
                }
            }
            $data['choices'] = [...($data['choices'] ?? []), ...$choices];
            $resolved = app(TransactionProposalResolver::class)->resolve($company, $actor, $data['proposal'], $data['choices']);
            foreach ($humanPaths as $path) {
                $resolved['origins'][$path === 'currency' ? 'currency_code' : $path] = 'human_edit';
            }
            $data['resolved'] = $resolved;
            $data['lifecycle'] = $resolved['questions'] === [] ? 'handoff' : 'clarifying';
            app(AiFormState::class)->replace($company, $actor, $token, $sessionId, $formKey, $state['revision'], $data);
            AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)->whereKey($data['request_id'])
                ->where('status', 'clarifying')->update(['status' => $resolved['questions'] === [] ? 'form_ready' : 'clarifying']);

            return $this->presentation($token, $resolved, $data['request_id']);
        });
    }

    /** @param list<AiProviderConnection> $routes
     * @return array<string, mixed>
     */
    private function textInference(Company $company, User $actor, AssistantRequest $request, array $routes, string $text, string $context): array
    {
        // One reviewed company route and one attempt. Legacy fallback settings cannot trigger retries.
        if (count($routes) !== 1) {
            throw new AssistantUnavailable('connection_required');
        }
        $connection = $routes[0];
        app(AiRequestBudget::class)->beginAttempt($company, $actor, $request, $connection);
        $grant = app(AiGovernance::class)->dispatchConnection($company, $actor, $connection->public_id, $request->intent_kind,
            'text_structured', (int) $connection->revision);
        $result = app(DirectTextProvider::class)->extract($connection->provider, $connection->model,
            $connection->region, $grant['credential'], $text, $context, app(TransactionProposalSchema::class)->providerSchema());
        app(AiRequestBudget::class)->recordUsage($company, $actor, $request, $result['usage']);

        return $result;
    }

    /** @return array<string, mixed> */
    private function replay(Company $company, User $actor, AssistantRequest $request, string $sessionId, string $formKey): array
    {
        if (in_array($request->status, ['unsupported', 'uncertain'], true)) {
            return ['state' => $request->status, 'request_id' => (int) $request->id];
        }
        $token = Cache::get($this->requestKey($request));
        if (! is_string($token)) {
            throw new AssistantUnavailable('request_processed');
        }
        $state = app(AiFormState::class)->read($company, $actor, $token, $sessionId, $formKey);

        return $this->presentation($token, $state['data']['resolved'], (int) $request->id);
    }

    /** @param array<string, mixed> $resolved
     * @return array<string, mixed>
     */
    private function presentation(string $token, array $resolved, int $requestId): array
    {
        return ['state' => $resolved['questions'] === [] ? 'handoff' : 'clarifying',
            'token' => $token, 'intent' => $resolved['intent'], 'request_id' => $requestId,
            'questions' => $resolved['questions'], 'unresolved' => $resolved['unresolved']];
    }

    /** @param list<AiProviderConnection> $connections
     * @return list<array{public_id: string, revision: int, capability: string}>
     */
    private function snapshots(array $connections): array
    {
        return array_map(fn (AiProviderConnection $connection) => ['public_id' => $connection->public_id,
            'revision' => (int) $connection->revision, 'capability' => $connection->capability], $connections);
    }

    private function fail(AssistantRequest $request, Throwable $error): void
    {
        DB::transaction(function () use ($request, $error): void {
            $fresh = AssistantRequest::where('company_id', $request->company_id)->where('user_id', $request->user_id)
                ->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($fresh->status === 'pending') {
                $fresh->update(['status' => 'failed', 'failure_code' => $error instanceof AssistantUnavailable ? $error->reason : 'unavailable']);
            }
        });
    }

    /** @param array<string, mixed> $input */
    private function inputHash(array $input): string
    {
        return hash_hmac('sha256', json_encode($input, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function requestKey(AssistantRequest $request): string
    {
        return 'ai-request-state:'.$request->company_id.':'.$request->user_id.':'.$request->id;
    }
}
