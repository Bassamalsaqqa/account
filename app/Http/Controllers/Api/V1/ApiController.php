<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

abstract class ApiController extends Controller
{
    /**
     * Resolve trace ID from request attributes or headers, falling back to a fresh UUID.
     */
    protected function resolveTraceId(Request $request): string
    {
        $traceId = $request->attributes->get('trace_id');
        if (is_string($traceId) && $traceId !== '') {
            return $traceId;
        }

        $candidate = $request->header('X-Trace-Id') ?? $request->header('X-Request-Id');
        if (is_string($candidate) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', trim($candidate)) === 1) {
            return strtolower(trim($candidate));
        }

        return (string) Str::uuid();
    }

    /**
     * Default security and caching headers applied to all API responses.
     *
     * @param  array<string,string>  $extraHeaders
     * @return array<string, string>
     */
    protected function securityHeaders(string $traceId, array $extraHeaders = []): array
    {
        return array_merge([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Trace-Id' => $traceId,
        ], $extraHeaders);
    }

    /**
     * Return an RFC 7807 compliant problem JSON error response.
     *
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $extraHeaders
     */
    protected function problem(
        string $title,
        int $status,
        string $detail,
        string $traceId,
        string $type = 'about:blank',
        array $extra = [],
        array $extraHeaders = []
    ): JsonResponse {
        $headers = array_merge(
            $this->securityHeaders($traceId, $extraHeaders),
            ['Content-Type' => 'application/problem+json']
        );

        $payload = array_merge([
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'trace_id' => $traceId,
        ], $extra);

        return response()->json($payload, $status, $headers);
    }
}
