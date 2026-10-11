<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\AI\AssistantAccess;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\SalesAssistant;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

final class SalesAssistantAudioController
{
    public function __invoke(Request $request, CompanyContext $context, SalesAssistant $assistant): JsonResponse
    {
        $company = $context->company();
        $actor = $request->user();
        app(AssistantAccess::class)->authorize($company, $actor, true);
        $file = $request->file('audio');
        $key = $request->input('request_key');
        if ($file === null || is_array($file) || ! $file->isValid() || ! is_string($key) || ! Str::isUuid($key)
            || $file->getSize() > (int) config('ai_assistant.max_audio_bytes')) {
            return response()->json(['message' => __('assistant.audio_invalid')], 422)->header('Cache-Control', 'no-store');
        }
        try {
            // PHP's protected upload temp file only; never move/store the recording.
            $bytes = file_get_contents($file->getRealPath(), false, null, 0, (int) config('ai_assistant.max_audio_bytes') + 1);
            if ($bytes === false) {
                throw new AssistantUnavailable('audio_invalid');
            }
            $result = $assistant->transcribe($company, $actor, $bytes, $key, $request->boolean('consent'));

            return response()->json($result)->header('Cache-Control', 'no-store');
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $reason = $e instanceof AssistantUnavailable ? $e->reason : 'unavailable';
            $known = ['budget', 'consent', 'low_confidence', 'audio_invalid', 'request_processed'];

            return response()->json(['message' => __('assistant.'.(in_array($reason, $known, true) ? $reason : 'unavailable'))], 422)->header('Cache-Control', 'no-store');
        } finally {
            // Upload paths come exclusively from Symfony's verified PHP upload object.
            // Request teardown also removes the temp file on interrupted responses.
            if (is_file($file->getPathname())) {
                @unlink($file->getPathname());
            }
        }
    }
}
