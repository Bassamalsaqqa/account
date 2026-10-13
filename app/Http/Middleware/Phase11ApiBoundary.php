<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class Phase11ApiBoundary
{
    private const DEFAULT_MAX_PAYLOAD_BYTES = 65536; // 64 KiB

    private const MAX_JSON_DEPTH = 32;

    private const FORBIDDEN_KEYS = [
        'actor',
        'user',
        'user_id',
        'company',
        'company_id',
        'cost',
        'unit_cost',
        'total_cost',
        'cogs',
        'cogs_total_base',
        'cogs_unit_base',
    ];

    private const ALLOWED_VALIDATION_PATTERNS = [
        'customer_id',
        'issue_date',
        'due_date',
        'currency_code',
        'exchange_rate',
        'notes',
        'terms',
        'warehouse_id',
        'lines',
        'lines.*',
        'lines.*.line_number',
        'lines.*.product_id',
        'lines.*.product_unit_id',
        'lines.*.item_description',
        'lines.*.quantity',
        'lines.*.unit_price',
        'lines.*.discount_type',
        'lines.*.discount_value',
        'lines.*.tax_rate_id',
        'lines.*.public_id',
        'confirmation_token',
        'confirmed',
        'idempotency_key',
        'search',
        'limit',
        'text',
        'request_key',
        'conversation_token',
        'source',
    ];

    /**
     * Handle incoming request globally before the Laravel web pipeline runs.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Inspect ONLY path api/v1 or api/v1/*; all other requests pass completely unchanged
        if (! $request->is('api/v1') && ! $request->is('api/v1/*')) {
            return $next($request);
        }

        $traceId = self::resolveTraceId($request);
        $request->attributes->set('trace_id', $traceId);

        // 2. Feature Off Gate: MUST return 404 BEFORE session, CSRF, auth, or DB queries
        $assistantTurnPath = preg_match('#^api/v1/companies/[^/]+/assistant/turn$#D', $request->path()) === 1;
        $enabled = $assistantTurnPath
            ? $request->isMethod('POST') && config('ai_assistant.enabled') === true && config('ai_assistant.turn_enabled') === true
            : (bool) config('commands.api_enabled', false);
        if (! $enabled) {
            return self::buildProblem(
                title: 'Not Found',
                status: 404,
                detail: 'The requested API endpoint was not found.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.4'
            );
        }

        // Refuse unsupported session storage before the web pipeline opens a connection.
        $sessionConnection = config('session.connection');
        if (config('session.driver') !== 'database'
            || ($sessionConnection !== null && $sessionConnection !== config('database.default'))) {
            return self::buildProblem(
                title: 'Service Unavailable',
                status: 503,
                detail: 'The configured session storage cannot safely authorize API commands.',
                traceId: $traceId,
                type: 'about:blank'
            );
        }

        // 3. Force JSON Accept header before web pipeline; refuse non-JSON Accept
        $accept = (string) $request->header('Accept');
        if ($accept !== '' && ! str_contains($accept, 'application/json') && ! str_contains($accept, '*/*')) {
            return self::buildProblem(
                title: 'Not Acceptable',
                status: 406,
                detail: 'The Accept header must include application/json.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.6'
            );
        }

        $request->headers->set('Accept', 'application/json');
        $request->server->set('HTTP_ACCEPT', 'application/json');

        // 4. Overall IP Rate Limiter (default 120 per minute per IP)
        $ip = (string) $request->ip();
        $overallKey = "phase11:ip_overall:{$ip}";
        $overallMax = (int) config('commands.rate_limits.ip_overall_per_minute', 120);

        if (RateLimiter::tooManyAttempts($overallKey, $overallMax)) {
            $retryAfter = RateLimiter::availableIn($overallKey);

            return self::buildProblem(
                title: 'Too Many Requests',
                status: 429,
                detail: "Overall IP rate limit exceeded. Please retry after {$retryAfter} seconds.",
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc6585#section-4',
                extraHeaders: ['Retry-After' => (string) $retryAfter]
            );
        }

        RateLimiter::hit($overallKey, 60);

        // 5. TLS Enforcement outside local and testing environments
        if (! app()->environment('local', 'testing') && ! $request->isSecure()) {
            return self::buildProblem(
                title: 'Forbidden',
                status: 403,
                detail: 'HTTPS/TLS connection is required for API access.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.3'
            );
        }

        // 6. Payload Actual Size Check (<= 64 KiB)
        $maxBytes = (int) config('commands.max_payload_bytes', self::DEFAULT_MAX_PAYLOAD_BYTES);
        $contentLength = $request->header('Content-Length');
        if ($contentLength !== null && (int) $contentLength > $maxBytes) {
            return self::buildProblem(
                title: 'Payload Too Large',
                status: 413,
                detail: "Payload exceeds maximum allowed size of {$maxBytes} bytes.",
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.11'
            );
        }

        $rawBody = (string) $request->getContent();
        if (strlen($rawBody) > $maxBytes) {
            return self::buildProblem(
                title: 'Payload Too Large',
                status: 413,
                detail: "Payload exceeds maximum allowed size of {$maxBytes} bytes.",
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.11'
            );
        }

        // 7. Mutation Content-Type and Strict JSON Validation
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            if ($rawBody !== '' || in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
                $contentType = (string) $request->header('Content-Type');
                if (! str_contains($contentType, 'application/json')) {
                    return self::buildProblem(
                        title: 'Unsupported Media Type',
                        status: 415,
                        detail: 'The request Content-Type must be application/json.',
                        traceId: $traceId,
                        type: 'https://tools.ietf.org/html/rfc7231#section-6.5.13'
                    );
                }
            }

            if ($rawBody !== '') {
                $decoded = json_decode($rawBody, true, self::MAX_JSON_DEPTH);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return self::buildProblem(
                        title: 'Unprocessable Content',
                        status: 422,
                        detail: 'Invalid JSON payload format.',
                        traceId: $traceId,
                        type: 'https://tools.ietf.org/html/rfc7231#section-6.5.1'
                    );
                }

                if (! is_array($decoded)) {
                    return self::buildProblem(
                        title: 'Unprocessable Content',
                        status: 422,
                        detail: 'The payload must be a valid JSON object or list.',
                        traceId: $traceId,
                        type: 'https://tools.ietf.org/html/rfc7231#section-6.5.1'
                    );
                }

                // Strict Float Prohibition: reject floating-point numbers recursively
                if ($this->containsFloat($decoded)) {
                    return self::buildProblem(
                        title: 'Unprocessable Content',
                        status: 422,
                        detail: 'Floating-point numbers are prohibited. Quantities, prices, and rates must be supplied as exact decimal strings.',
                        traceId: $traceId,
                        type: 'https://tools.ietf.org/html/rfc7231#section-6.5.1'
                    );
                }

                // Strict Field Containment: no actor/company/cost fields
                $forbiddenKey = $this->findForbiddenKey($decoded);
                if ($forbiddenKey !== null) {
                    return self::buildProblem(
                        title: 'Unprocessable Content',
                        status: 422,
                        detail: "The field [{$forbiddenKey}] is unsupported and cannot be supplied.",
                        traceId: $traceId,
                        type: 'https://tools.ietf.org/html/rfc7231#section-6.5.1'
                    );
                }
            }
        }

        // 8. Execute Next Pipeline with Sanitized Exception Envelopes
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $response = self::renderProblemException($e, $request);
        }

        // 9. Sanitize Actual Rendered Error Responses (Router/Pipeline catches and renders errors)
        if ($response->getStatusCode() >= 400) {
            $response = $this->sanitizeErrorResponse($response, $request, $traceId);
        }

        // 10. Apply Security and No-Store Headers on All Responses
        self::applySecurityHeaders($response, $traceId);

        return $response;
    }

    /**
     * Sanitize downstream error responses (e.g. from router, validation, or exceptions) into safe RFC 7807 problem JSON.
     * Preserves session cookies, bounded Retry-After / Allow headers, and executes guest limiter on 401.
     */
    private function sanitizeErrorResponse(Response $response, Request $request, string $traceId): Response
    {
        $status = $response->getStatusCode();

        // Guest Rate Limiter on Rendered 401
        if ($status === 401) {
            $guestKey = 'phase11:guest:'.(string) $request->ip();
            $guestMax = (int) config('commands.rate_limits.guest_per_minute', 30);

            if (RateLimiter::tooManyAttempts($guestKey, $guestMax)) {
                $retryAfter = RateLimiter::availableIn($guestKey);

                return self::buildProblem(
                    title: 'Too Many Requests',
                    status: 429,
                    detail: "Guest rate limit exceeded. Please retry after {$retryAfter} seconds.",
                    traceId: $traceId,
                    type: 'https://tools.ietf.org/html/rfc6585#section-4',
                    extraHeaders: ['Retry-After' => (string) $retryAfter],
                    preserveResponse: $response
                );
            }

            RateLimiter::hit($guestKey, 60);
        }

        $rawContent = (string) $response->getContent();

        // Only the bounded turn endpoint may preserve an allowlisted availability category.
        if ($status === 503 && $request->isMethod('POST')
            && preg_match('#^api/v1/companies/[^/]+/assistant/turn$#D', $request->path()) === 1) {
            $decoded = json_decode($rawContent, true);
            $category = is_array($decoded) ? ($decoded['category'] ?? null) : null;
            if (in_array($category, ['provider_unavailable', 'consent_required', 'budget_exceeded', 'context_expired', 'unavailable'], true)) {
                return self::buildProblem('Service Unavailable', 503, 'The assistant is currently unavailable.', $traceId,
                    extra: ['category' => $category], preserveResponse: $response);
            }
        }

        // Handle 422 Validation: whitelist actual supported field names, generic fixed messages
        if ($status === 422) {
            $whitelistedErrors = $this->extractWhitelistedValidationErrors($rawContent);

            return self::buildProblem(
                title: 'Unprocessable Content',
                status: 422,
                detail: 'The given data failed validation.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.1',
                extra: ['errors' => $whitelistedErrors],
                preserveResponse: $response
            );
        }

        // Fixed RFC Problem mappings for framework/pipeline rendered errors
        $mapping = match ($status) {
            400 => ['Bad Request', 'The request is malformed or invalid.', 'https://tools.ietf.org/html/rfc7231#section-6.5.1'],
            401 => ['Unauthorized', 'Authentication is required to access this endpoint.', 'https://tools.ietf.org/html/rfc7235#section-3.1'],
            403 => ['Forbidden', 'Action is unauthorized.', 'https://tools.ietf.org/html/rfc7231#section-6.5.3'],
            404 => ['Not Found', 'The requested resource was not found.', 'https://tools.ietf.org/html/rfc7231#section-6.5.4'],
            405 => ['Method Not Allowed', 'The requested HTTP method is not allowed for this route.', 'https://tools.ietf.org/html/rfc7231#section-6.5.5'],
            406 => ['Not Acceptable', 'The Accept header must include application/json.', 'https://tools.ietf.org/html/rfc7231#section-6.5.6'],
            409 => ['Conflict', 'The requested operation conflicts with the current resource state.', 'https://tools.ietf.org/html/rfc7231#section-6.5.8'],
            413 => ['Payload Too Large', 'Payload exceeds maximum allowed size.', 'https://tools.ietf.org/html/rfc7231#section-6.5.11'],
            415 => ['Unsupported Media Type', 'The request Content-Type must be application/json.', 'https://tools.ietf.org/html/rfc7231#section-6.5.13'],
            419 => ['CSRF Token Mismatch', 'CSRF token is missing, invalid, or expired.', 'https://tools.ietf.org/html/rfc7231#section-6.5.1'],
            423 => ['Password Confirmation Required', 'Fresh password confirmation is required for session management.', 'https://tools.ietf.org/html/rfc7231#section-6.5.3'],
            429 => ['Too Many Requests', 'Rate limit exceeded. Please retry later.', 'https://tools.ietf.org/html/rfc6585#section-4'],
            503 => ['Service Unavailable', 'The API requires the database session driver for verifiable revocation.', 'https://tools.ietf.org/html/rfc7231#section-6.6.4'],
            default => ['Internal Server Error', 'An unexpected server error occurred.', 'https://tools.ietf.org/html/rfc7231#section-6.6.1'],
        };

        $extraHeaders = [];
        if ($response->headers->has('Retry-After')) {
            $extraHeaders['Retry-After'] = (string) $response->headers->get('Retry-After');
        }
        if ($response->headers->has('Allow')) {
            $extraHeaders['Allow'] = (string) $response->headers->get('Allow');
        }

        // On unexpected 500, log ONLY class + trace ID; NEVER message or response content
        if ($status >= 500) {
            Log::error('Phase11DownstreamErrorResponse', ['trace_id' => $traceId]);
        }

        return self::buildProblem(
            title: $mapping[0],
            status: $status >= 500 && $status !== 503 ? 500 : $status,
            detail: $mapping[1],
            traceId: $traceId,
            type: $mapping[2],
            extraHeaders: $extraHeaders,
            preserveResponse: $response
        );
    }

    /**
     * Map caught framework and domain exceptions to sanitized Problem JSON envelopes.
     * Public static so it can be utilized directly by Phase11ServiceProvider exception hooks.
     */
    public static function renderProblemException(Throwable $e, Request $request): JsonResponse
    {
        $traceId = self::resolveTraceId($request);

        // 419: CSRF Token Mismatch
        if ($e instanceof TokenMismatchException) {
            return self::buildProblem(
                title: 'CSRF Token Mismatch',
                status: 419,
                detail: 'CSRF token is missing, invalid, or expired.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.1'
            );
        }

        // 401: Authentication Required - execute guest rate limiter before returning
        if ($e instanceof AuthenticationException) {
            return self::buildProblem(
                title: 'Unauthorized',
                status: 401,
                detail: 'Authentication is required to access this endpoint.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7235#section-3.1'
            );
        }

        // 403: Authorization Denied
        if ($e instanceof AuthorizationException) {
            return self::buildProblem(
                title: 'Forbidden',
                status: 403,
                detail: 'Action is unauthorized.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.3'
            );
        }

        // 404: Not Found
        if ($e instanceof NotFoundHttpException || $e instanceof ModelNotFoundException) {
            return self::buildProblem(
                title: 'Not Found',
                status: 404,
                detail: 'The requested resource was not found.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.4'
            );
        }

        // 405: Method Not Allowed
        if ($e instanceof MethodNotAllowedHttpException) {
            $headers = $e->getHeaders();
            $extraHeaders = isset($headers['Allow']) ? ['Allow' => (string) $headers['Allow']] : [];

            return self::buildProblem(
                title: 'Method Not Allowed',
                status: 405,
                detail: 'The requested HTTP method is not allowed for this route.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.5',
                extraHeaders: $extraHeaders
            );
        }

        // 409: Conflict
        if ($e instanceof ConflictHttpException) {
            return self::buildProblem(
                title: 'Conflict',
                status: 409,
                detail: 'The requested operation conflicts with the current resource state.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.8'
            );
        }

        // 422: Validation Exception - whitelisted field errors only
        if ($e instanceof ValidationException) {
            $whitelistedErrors = [];
            foreach ($e->errors() as $field => $messages) {
                if (self::isAllowedValidationField((string) $field)) {
                    $cleanField = preg_replace('/[^a-zA-Z0-9_\.]/', '', (string) $field);
                    if ($cleanField !== '') {
                        $whitelistedErrors[$cleanField] = ['The field value is invalid or required.'];
                    }
                }
            }

            return self::buildProblem(
                title: 'Unprocessable Content',
                status: 422,
                detail: 'The given data failed validation.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.1',
                extra: ['errors' => $whitelistedErrors]
            );
        }

        // Standard HTTP Exceptions
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return self::buildProblem(
                title: 'HTTP Error',
                status: $status,
                detail: 'An HTTP error occurred.',
                traceId: $traceId,
                type: 'about:blank'
            );
        }

        // Unexpected 500: Log ONLY exception class + trace ID; NEVER message or payload
        Log::error(get_class($e), ['trace_id' => $traceId]);

        return self::buildProblem(
            title: 'Internal Server Error',
            status: 500,
            detail: 'An unexpected server error occurred.',
            traceId: $traceId,
            type: 'https://tools.ietf.org/html/rfc7231#section-6.6.1'
        );
    }

    /**
     * Extract and whitelist validation errors from raw response content.
     *
     * @return array<string, list<string>>
     */
    private function extractWhitelistedValidationErrors(string $content): array
    {
        $decoded = json_decode($content, true);
        if (! is_array($decoded) || ! isset($decoded['errors']) || ! is_array($decoded['errors'])) {
            return [];
        }

        $whitelisted = [];
        foreach ($decoded['errors'] as $field => $messages) {
            if (self::isAllowedValidationField((string) $field)) {
                $cleanField = preg_replace('/[^a-zA-Z0-9_\.]/', '', (string) $field);
                if ($cleanField !== '') {
                    $whitelisted[$cleanField] = ['The field value is invalid or required.'];
                }
            }
        }

        return $whitelisted;
    }

    /**
     * Check if a field name matches allowed validation patterns.
     */
    private static function isAllowedValidationField(string $field): bool
    {
        foreach (self::ALLOWED_VALIDATION_PATTERNS as $pattern) {
            if (Str::is($pattern, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve strict bounded UUID trace ID from request headers or generate fresh UUID.
     */
    public static function resolveTraceId(Request $request): string
    {
        $candidate = $request->attributes->get('trace_id') ?? $request->header('X-Trace-Id') ?? $request->header('X-Request-Id');
        if (is_string($candidate) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', trim($candidate)) === 1) {
            return strtolower(trim($candidate));
        }

        return (string) Str::uuid();
    }

    /**
     * Recursively check if array contains float values.
     */
    private function containsFloat(mixed $data): bool
    {
        if (is_float($data)) {
            return true;
        }

        if (is_array($data)) {
            foreach ($data as $item) {
                if ($this->containsFloat($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Recursively check for forbidden keys in payload.
     */
    /** @param array<array-key, mixed> $data */
    private function findForbiddenKey(array $data): ?string
    {
        foreach ($data as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, self::FORBIDDEN_KEYS, true)) {
                return (string) $key;
            }

            if (is_array($value)) {
                $nested = $this->findForbiddenKey($value);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * Apply mandatory security, no-store, and trace headers.
     */
    public static function applySecurityHeaders(Response $response, string $traceId): void
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Trace-Id', $traceId);

        // Disallow wildcard CORS
        if ($response->headers->get('Access-Control-Allow-Origin') === '*') {
            $response->headers->remove('Access-Control-Allow-Origin');
        }
    }

    /**
     * Build RFC 7807 compliant problem JSON response, preserving existing session cookies.
     *
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $extraHeaders
     */
    public static function buildProblem(
        string $title,
        int $status,
        string $detail,
        string $traceId,
        string $type = 'about:blank',
        array $extra = [],
        array $extraHeaders = [],
        ?Response $preserveResponse = null
    ): JsonResponse {
        $headers = array_merge([
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Trace-Id' => $traceId,
        ], $extraHeaders);

        $response = response()->json(array_merge([
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'trace_id' => $traceId,
        ], $extra), $status, $headers);

        // Preserve session cookies from previous response if present
        if ($preserveResponse !== null) {
            foreach ($preserveResponse->headers->getCookies() as $cookie) {
                $response->headers->setCookie($cookie);
            }
        }

        return $response;
    }
}
