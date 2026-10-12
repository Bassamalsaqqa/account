<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class Phase12Boundary
{
    public function handle(Request $request, Closure $next): Response
    {
        // Retired commercial audio and credential endpoints stay closed before CSRF or authentication.
        if ($request->is('sales-assistant/audio', 'assistant/audio', 'settings/ai/credentials')) {
            return response()->json(['message' => 'Not found.'], 404)->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
