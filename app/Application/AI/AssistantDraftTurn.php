<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Reusable natural-transaction command entry service for unsaved drafts.
 * Authorizes active context and delegates to canonical TransactionAssistant.
 * Zero database draft invoice writes; produces unsaved handoff token and URL only.
 */
final class AssistantDraftTurn
{
    public function __construct(
        private readonly TransactionAssistant $transactionAssistant,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Prepare an unsaved transaction draft from natural language or structured commands.
     *
     * Operation is untrusted input; only sales, purchase or auto is accepted below.
     *
     * @return array<string, mixed>
     */
    public function prepare(
        Company $company,
        User $actor,
        string $text,
        string $operation = 'auto',
        ?string $requestUUID = null,
        ?string $formUUID = null,
        ?string $sessionId = null,
    ): array {
        $this->assertContext($company, $actor);

        if (! in_array($operation, ['sales', 'purchase', 'auto'], true)) {
            throw new AssistantUnavailable('input_invalid');
        }

        // Stable caller-owned identities are required: manufacturing a new key
        // here would turn a retry into another reservation or paid request.
        if ($requestUUID === null || $formUUID === null || $sessionId === null
            || ! Str::isUuid($requestUUID) || ! Str::isUuid($formUUID)) {
            throw new AssistantUnavailable('invalid_request');
        }

        if (trim($text) === '' || mb_strlen($text) > (int) config('ai_assistant.max_text_characters', 4000)
            || $sessionId === '' || strlen($sessionId) > 255) {
            throw new AssistantUnavailable('input_invalid');
        }

        $result = $this->transactionAssistant->prepare(
            $company,
            $actor,
            $text,
            $operation,
            $formUUID,
            $requestUUID,
            $sessionId,
        );

        return $this->formatPresentation($company, $actor, $result);
    }

    /**
     * Clarify an existing clarifying transaction request.
     *
     * @param  array<array-key, mixed>  $choices
     * @return array<string, mixed>
     */
    public function clarify(
        Company $company,
        User $actor,
        string $token,
        string $sessionId,
        string $formUUID,
        array $choices,
    ): array {
        $this->assertContext($company, $actor);

        if (! Str::isUuid($formUUID) || ! Str::isUuid($token) || $sessionId === '' || strlen($sessionId) > 255) {
            throw new AssistantUnavailable('invalid_request');
        }

        $result = $this->transactionAssistant->clarify(
            $company,
            $actor,
            $token,
            $sessionId,
            $formUUID,
            $choices,
        );

        return $this->formatPresentation($company, $actor, $result);
    }

    /**
     * Cancel an ongoing or clarifying transaction turn and discard unsaved memory.
     */
    public function cancel(
        Company $company,
        User $actor,
        string $formUUID,
        string $sessionId,
    ): void {
        $this->assertContext($company, $actor);

        if (! Str::isUuid($formUUID) || $sessionId === '' || strlen($sessionId) > 255) {
            throw new AssistantUnavailable('invalid_request');
        }

        $this->transactionAssistant->cancel($company, $actor, $formUUID, $sessionId);
    }

    /**
     * Strict active actor and active company tenant context check.
     */
    private function assertContext(Company $company, User $actor): void
    {
        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id
            || ! $this->companyContext->hasCompany()
            || (int) $this->companyContext->companyId() !== (int) $company->id) {
            throw new AuthorizationException('Active actor and company context required.');
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function formatPresentation(Company $company, User $actor, array $result): array
    {
        $state = $result['state'] ?? null;
        if (! is_string($state)) {
            throw new AssistantUnavailable('provider_invalid');
        }
        if (in_array($state, ['handoff', 'clarifying'], true)) {
            if (! in_array($result['intent'] ?? null, ['sales', 'purchase'], true)
                || ! is_string($result['token'] ?? null) || ! Str::isUuid($result['token'])
                || ! is_int($result['request_id'] ?? null) || $result['request_id'] < 1) {
                throw new AssistantUnavailable('provider_invalid');
            }
            app(AiGovernance::class)->authorizeUse($company, $actor, $result['intent']);
        }

        if ($state === 'handoff') {
            $intent = $result['intent'];
            $route = $intent === 'sales' ? 'invoices.create' : 'purchases.create';
            $url = route($route, ['assistant' => $result['token']]);

            return [
                'state' => 'handoff',
                'token' => $result['token'],
                'url' => $url,
                'handoff_url' => $url,
                'intent' => $intent,
                'request_id' => $result['request_id'],
                'questions' => [],
                'unresolved' => $result['unresolved'] ?? [],
            ];
        }

        if ($state === 'clarifying') {
            $intent = $result['intent'];
            $questions = $result['questions'] ?? [];
            if (! is_array($questions)) {
                throw new AssistantUnavailable('provider_invalid');
            }
            $manualCreateUrl = null;

            foreach ($questions as &$question) {
                if (($question['path'] ?? null) === 'party' && ($question['candidates'] ?? []) === []) {
                    $canManage = $intent === 'sales'
                        ? app(AiGovernance::class)->can($company, $actor, 'customers.manage')
                        : app(AiGovernance::class)->can($company, $actor, 'vendors.manage');

                    if ($canManage) {
                        $createRoute = $intent === 'sales' ? 'customers.create' : 'vendors.create';
                        $manualCreateUrl = route($createRoute);
                        $question['create_url'] = $manualCreateUrl;
                        $question['manual_create_link'] = $manualCreateUrl;
                    }
                }
            }
            unset($question);

            return [
                'state' => 'clarifying',
                'token' => $result['token'],
                'intent' => $intent,
                'request_id' => $result['request_id'],
                'questions' => $questions,
                'unresolved' => $result['unresolved'] ?? [],
                'manual_create_url' => $manualCreateUrl,
                'manual_create_link' => $manualCreateUrl,
            ];
        }

        return [
            'state' => $state,
            'request_id' => $result['request_id'] ?? null,
        ];
    }
}
