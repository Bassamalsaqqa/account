<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\AI\AiAudioLimits;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\TransactionAssistant;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class AssistantAudioController
{
    public function __invoke(Request $request, CompanyContext $context, TransactionAssistant $assistant): JsonResponse
    {
        $company = $context->company();
        $actor = $request->user();
        $values = $request->validate(['form_key' => ['required', 'uuid'], 'request_key' => ['required', 'uuid'],
            'context' => ['required', 'in:auto,sales,purchase'], 'locale' => ['required', 'in:ar,en,mixed'],
            'connection_id' => ['nullable', 'uuid']]);
        $policy = app(AiGovernance::class)->authorizeUse($company, $actor, $values['context'], true);
        if ($policy->speech_method === 'local_only') {
            abort(403);
        }
        $file = $request->file('audio');
        $limit = app(AiAudioLimits::class)->current()['max_bytes'];
        if ($file === null || is_array($file) || ! $file->isValid() || $file->getSize() > $limit) {
            return $this->reply(['message' => __('assistant.audio_invalid')], 422);
        }
        try {
            $bytes = file_get_contents($file->getRealPath(), false, null, 0, $limit + 1);
            if ($bytes === false || strlen($bytes) > $limit) {
                throw new AssistantUnavailable('audio_invalid');
            }

            return $this->reply($assistant->transcribe($company, $actor, $bytes, $values['context'], $values['form_key'],
                $values['request_key'], $request->session()->getId(), $values['locale'], $values['connection_id'] ?? null));
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $reason = $e instanceof AssistantUnavailable && in_array($e->reason, ['budget', 'consent', 'provider_outage'], true) ? $e->reason : 'unavailable';

            return $this->reply(['message' => __('assistant_v2.error_'.$reason)], 422);
        } finally {
            if (is_file($file->getPathname())) {
                @unlink($file->getPathname());
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function reply(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
