<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\AI\AiAudioLimits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class Phase12Boundary
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('sales-assistant/audio')) {
            return response()->json(['message' => 'Not found.'], 404)->header('Cache-Control', 'no-store');
        }
        if ($request->is('assistant/audio')) {
            if (config('ai_assistant.enabled') !== true || config('ai_assistant.provider_approved') !== true || config('ai_assistant.provider') !== 'direct' || config('ai_assistant.speech_enabled') !== true) {
                return response()->json(['message' => 'Not found.'], 404)->header('Cache-Control', 'no-store');
            }
            if ((int) $request->server('CONTENT_LENGTH', 0) > app(AiAudioLimits::class)->current()['max_bytes'] + 65536) {
                return response()->json(['message' => 'Request is too large.'], 413)->header('Cache-Control', 'no-store');
            }
        }

        return $next($request);
    }
}
