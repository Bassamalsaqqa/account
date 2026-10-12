<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\AI\AiAudioLimits;
use App\Application\AI\AiGovernance;
use App\Application\AI\AiProviderRoutes;
use App\Application\AI\AiRequestBudget;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\PcmWave;
use App\Application\AI\TransactionAssistant;
use App\Infrastructure\AI\DirectSpeechProvider;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AssistantAudioController
{
    public function __invoke(Request $request, CompanyContext $context, TransactionAssistant $assistant): JsonResponse
    {
        $file = $request->file('audio');
        try {
            $company = $context->company();
            $actor = $request->user();
            $values = $request->validate([
                'form_key' => ['required', 'uuid'],
                'request_key' => ['required', 'uuid'],
                'context' => ['required', 'in:auto,sales,purchase,question'],
                'locale' => ['required', 'in:ar,en,mixed'],
                'connection_id' => ['nullable', 'uuid'],
            ]);

            $governance = app(AiGovernance::class);
            $isQuestion = $values['context'] === 'question';

            if ($isQuestion) {
                try {
                    $policy = $governance->authorizeUse($company, $actor, 'question', true);
                } catch (AssistantUnavailable) {
                    abort(403);
                }
            } else {
                try {
                    $policy = $governance->authorizeUse($company, $actor, $values['context'], true);
                } catch (AssistantUnavailable) {
                    abort(403);
                }
            }

            $limit = app(AiAudioLimits::class)->current()['max_bytes'];
            if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() > $limit) {
                return $this->reply(['message' => __('assistant.audio_invalid')], 422);
            }

            try {
                $bytes = file_get_contents($file->getRealPath(), false, null, 0, $limit + 1);
                if ($bytes === false || strlen($bytes) > $limit) {
                    throw new AssistantUnavailable('audio_invalid');
                }

                if ($isQuestion) {
                    return $this->reply($this->transcribeQuestion(
                        $company,
                        $actor,
                        $bytes,
                        $values['form_key'],
                        $values['request_key'],
                        $request->session()->getId(),
                        $values['locale'],
                        $values['connection_id'] ?? null
                    ));
                }

                return $this->reply($assistant->transcribe(
                    $company,
                    $actor,
                    $bytes,
                    $values['context'],
                    $values['form_key'],
                    $values['request_key'],
                    $request->session()->getId(),
                    $values['locale'],
                    $values['connection_id'] ?? null
                ));
            } catch (AuthorizationException $e) {
                throw $e;
            } catch (Throwable $e) {
                $reason = $e instanceof AssistantUnavailable && in_array($e->reason, ['budget', 'consent', 'provider_outage'], true) ? $e->reason : 'unavailable';

                return $this->reply(['message' => __('assistant_v2.error_'.$reason)], 422);
            }
        } finally {
            // Cleanup also covers validation, authorization and malformed multi-file refusal.
            foreach (is_array($file) ? Arr::flatten($file) : [$file] as $upload) {
                if ($upload instanceof UploadedFile && is_file($upload->getPathname())) {
                    @unlink($upload->getPathname());
                }
            }
        }
    }

    /**
     * Report-only speech path: no AiFormState created, no candidate data disclosed.
     *
     * @return array<string, mixed>
     */
    private function transcribeQuestion(
        Company $company,
        User $actor,
        string $wav,
        string $formKey,
        string $requestKey,
        string $sessionId,
        string $locale,
        ?string $connectionId = null
    ): array {
        if ($sessionId === '') {
            throw new AssistantUnavailable('invalid_request');
        }

        $seconds = app(PcmWave::class)->seconds($wav);
        $routes = app(AiProviderRoutes::class)->select($company, $actor, 'question', 'audio_transcription', $connectionId);
        $inputHash = hash_hmac('sha256', json_encode([
            'audio' => hash('sha256', $wav),
            'context' => 'question',
            'form' => $formKey,
            'session' => hash('sha256', $sessionId),
            'locale' => $locale,
            'connection' => $routes[0]->public_id,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));

        $budget = app(AiRequestBudget::class);
        $reservation = $budget->reserve($company, $actor, $requestKey, $formKey, 'transcription', 'question', $inputHash, $routes, $seconds * 1000);
        $budgetRequest = $reservation['request'];
        if (! $reservation['created']) {
            throw new AssistantUnavailable('request_processed');
        }

        try {
            $result = null;
            foreach ($routes as $index => $connection) {
                $budget->beginAttempt($company, $actor, $budgetRequest, $connection);
                $grant = app(AiGovernance::class)->dispatchConnection($company, $actor, $connection->public_id, 'question', 'audio_transcription', (int) $connection->revision);
                try {
                    $result = app(DirectSpeechProvider::class)->transcribe(
                        $connection->provider,
                        $connection->model,
                        $connection->region,
                        $grant['credential'],
                        $wav,
                        $locale
                    );
                    break;
                } catch (AssistantUnavailable $e) {
                    if ($e->reason !== 'provider_outage' || $index !== 0 || count($routes) !== 2) {
                        throw $e;
                    }
                }
            }

            if ($result === null) {
                throw new AssistantUnavailable('provider_outage');
            }

            $policy = DB::transaction(function () use ($company, $actor, $budgetRequest, $routes, $seconds) {
                $governance = app(AiGovernance::class);
                $policy = $governance->authorizeUse($company, $actor, 'question', true);
                $fresh = $budgetRequest->newQuery()->where('company_id', $company->id)->where('user_id', $actor->id)->whereKey($budgetRequest->id)->lockForUpdate()->firstOrFail();
                if ($fresh->status !== 'pending' || (int) $policy->revision !== (int) $fresh->policy_revision) {
                    throw new AssistantUnavailable('proposal_stale');
                }
                foreach ($routes as $connection) {
                    $governance->dispatchConnection($company, $actor, $connection->public_id, 'question', 'audio_transcription', (int) $connection->revision);
                }
                $fresh->update(['status' => 'completed', 'audio_seconds' => $seconds]);

                return $policy;
            });

            return [
                'state' => 'transcribed',
                'text' => $result['text'],
                'audio_token' => null,
                'mode' => $policy->workflow_mode,
            ];
        } catch (AssistantUnavailable $e) {
            $budgetRequest->update([
                'status' => 'failed',
                'failure_code' => $e->reason,
            ]);
            throw $e;
        } catch (Throwable $e) {
            $budgetRequest->update([
                'status' => 'failed',
                'failure_code' => 'provider_error',
            ]);
            throw $e;
        }
    }

    /** @param array<string, mixed> $data */
    private function reply(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
