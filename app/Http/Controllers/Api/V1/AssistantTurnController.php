<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\AI\AssistantTurnContext;
use App\Application\AI\AssistantTurnService;
use App\Application\AI\AssistantUnavailable;
use App\Models\Company;
use App\Models\User;
use App\Services\Sales\SalesActorGuard;
use App\Services\Security\CommandSessionGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssistantTurnController extends ApiController
{
    public function __invoke(Request $request, string $companyId, AssistantTurnService $turns): JsonResponse
    {
        $payload = $request->json()->all();
        if (array_diff(array_keys($payload), ['text', 'request_key', 'conversation_token', 'source']) !== []) {
            throw ValidationException::withMessages(['text' => 'Unsupported request fields.']);
        }
        $data = validator($payload, [
            'text' => ['required', 'string', 'max:4000'],
            'request_key' => ['required', 'string', 'uuid'],
            'conversation_token' => ['sometimes', 'string', 'uuid'],
            'source' => ['sometimes', 'string', 'in:typed,voice'],
        ])->validate();

        $company = app(CompanyContext::class)->company();
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }
        // Company/permission/session locks never span provider I/O.
        $company = $this->authorizeSession($company, $actor);
        $unavailable = null;
        $result = null;
        try {
            $result = $turns->run($company, $actor, $data['text'], $data['request_key'],
                $request->session()->getId(), $data['conversation_token'] ?? null);
        } catch (AssistantUnavailable $exception) {
            $unavailable = $exception;
        }
        try {
            $this->authorizeSession($company, $actor, $result['_authorization_scope'] ?? null, $request->session()->getId());
        } catch (AssistantUnavailable $exception) {
            $unavailable = $exception;
        }
        unset($result['_authorization_scope']);

        $traceId = $this->resolveTraceId($request);
        if ($unavailable !== null) {
            $category = match ($unavailable->reason) {
                'consent', 'consent_stale' => 'consent_required',
                'budget', 'budget_configuration' => 'budget_exceeded',
                'proposal_stale', 'request_conflict', 'request_processed', 'request_canceled' => 'context_expired',
                'provider_authentication', 'provider_credit', 'provider_format', 'provider_parameters',
                'provider_rate_limit', 'provider_timeout', 'provider_outage', 'provider_invalid',
                'provider_rejected', 'provider_configuration', 'connection_missing', 'connection_unverified',
                'credential_invalid', 'credential_revoked' => 'provider_unavailable',
                default => 'unavailable',
            };

            return $this->problem('Service Unavailable', 503, 'The assistant is currently unavailable.',
                $traceId, extra: ['category' => $category]);
        }

        return response()->json($result, 200, $this->securityHeaders($traceId));
    }

    private function authorizeSession(Company $company, User $actor, ?string $scope = null, ?string $sessionId = null): Company
    {
        return DB::transaction(function () use ($company, $actor, $scope, $sessionId): Company {
            $fresh = app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'ai.assistant.use');
            app(CommandSessionGuard::class)->authorize($actor);
            if ($scope !== null && ! hash_equals($scope, app(AssistantTurnContext::class)->scope($fresh, $actor, $sessionId ?? ''))) {
                throw new AssistantUnavailable('proposal_stale');
            }

            return $fresh;
        });
    }
}
