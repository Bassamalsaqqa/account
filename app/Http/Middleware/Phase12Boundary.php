<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\AI\AssistantAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class Phase12Boundary
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('sales-assistant/audio')) {
            if (! app(AssistantAccess::class)->available() || config('ai_assistant.speech_enabled') !== true) {
                return response()->json(['message' => 'Not found.'], 404)->header('Cache-Control', 'no-store');
            }
            if ((int) $request->server('CONTENT_LENGTH', 0) > (int) config('ai_assistant.max_audio_bytes') + 65536) {
                return response()->json(['message' => 'Request is too large.'], 413)->header('Cache-Control', 'no-store');
            }
        }

        return $next($request);
    }
}
