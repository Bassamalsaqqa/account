<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Mobile\WireResponse;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class MobileApiBoundary
{
    private const int MAX_PAYLOAD_BYTES = 65536; // 64 KiB

    private const int MAX_JSON_DEPTH = 32;

    /**
     * Handle an incoming request for Mobile V1 API.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(CompanyContext::class);
        $context->clear();
        try {
            return $this->handleMobile($request, $next);
        } finally {
            $context->clear();
        }
    }

    private function handleMobile(Request $request, Closure $next): Response
    {
        // 1. Inspect ONLY path api/mobile/v1 or api/mobile/v1/*; all other paths pass untouched
        if (! $request->is('api/mobile/v1') && ! $request->is('api/mobile/v1/*')) {
            return $next($request);
        }

        // 2. Feature Off Gate: MUST return 404 when MOBILE_API_ENABLED=false
        if (! (bool) config('mobile.enabled', false)) {
            return self::buildErrorResponse(
                code: 'MOBILE_API_DISABLED',
                message: 'The mobile API is currently disabled.',
                status: 404
            );
        }

        // 3. Force JSON Accept header verification
        $accept = (string) $request->header('Accept');
        if ($accept !== '' && ! str_contains($accept, 'application/json') && ! str_contains($accept, '*/*')) {
            return self::buildErrorResponse(
                code: 'NOT_ACCEPTABLE',
                message: 'The Accept header must include application/json.',
                status: 406
            );
        }

        $request->headers->set('Accept', 'application/json');
        $request->server->set('HTTP_ACCEPT', 'application/json');

        // 4. TLS Enforcement outside local and testing environments
        if (! app()->environment('local', 'testing') && ! $request->isSecure()) {
            return self::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'HTTPS/TLS connection is required for Mobile API access.',
                status: 403
            );
        }

        // 5. Overall IP Rate Limiter
        $ip = (string) $request->ip();
        $rateKey = "mobile_v1:ip_overall:{$ip}";
        $maxAttempts = (int) config('mobile.rate_limits.ip_overall_per_minute', 120);

        if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($rateKey);

            return self::buildErrorResponse(
                code: 'TOO_MANY_REQUESTS',
                message: "Rate limit exceeded. Please retry after {$retryAfter} seconds.",
                status: 429,
                headers: ['Retry-After' => (string) $retryAfter]
            );
        }

        RateLimiter::hit($rateKey, 60);

        // 6. Payload size constraint check (<= 64 KiB)
        $contentLength = $request->header('Content-Length');
        if ($contentLength !== null && (int) $contentLength > self::MAX_PAYLOAD_BYTES) {
            return self::buildErrorResponse(
                code: 'PAYLOAD_TOO_LARGE',
                message: 'Payload exceeds maximum allowed size of 65536 bytes.',
                status: 413
            );
        }

        $rawBody = (string) $request->getContent();
        if (strlen($rawBody) > self::MAX_PAYLOAD_BYTES) {
            return self::buildErrorResponse(
                code: 'PAYLOAD_TOO_LARGE',
                message: 'Payload exceeds maximum allowed size of 65536 bytes.',
                status: 413
            );
        }

        // 7. Mutating methods Content-Type and strict JSON validation
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            $contentType = (string) $request->header('Content-Type');
            if (! str_contains($contentType, 'application/json')) {
                return self::buildErrorResponse(
                    code: 'UNSUPPORTED_MEDIA_TYPE',
                    message: 'The request Content-Type must be application/json.',
                    status: 415
                );
            }

            if ($rawBody !== '') {
                $decoded = json_decode($rawBody, true, self::MAX_JSON_DEPTH);
                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                    return self::buildErrorResponse(
                        code: 'INVALID_JSON',
                        message: 'Invalid JSON payload format.',
                        status: 422
                    );
                }
            }
        }

        // 8. Pipeline Execution with caught exception sanitization
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $response = self::renderException($e);
        }

        $schema = match ($request->route()?->getName()) {
            'mobile.v1.auth.challenge' => 'auth-challenge-response',
            'mobile.v1.auth.login' => 'auth-login-response',
            'mobile.v1.auth.logout' => 'auth-logout-response',
            'mobile.v1.devices.revoke' => 'device-revoke-response',
            'mobile.v1.bootstrap' => 'bootstrap-response',
            'mobile.v1.products.index' => 'product-catalog-response',
            'mobile.v1.customers.index' => 'customer-directory-response',
            default => null,
        };
        if ($response->isSuccessful() && $schema !== null && ! WireResponse::accepts(json_decode((string) $response->getContent()), $schema)) {
            $response = self::buildErrorResponse(code: 'CONTRACT_INCOMPATIBLE', message: 'Existing data cannot be represented by the frozen Mobile V1 contract.', status: 409);
        }

        // 9. Standardize downstream error response shapes to error-response.json
        if ($response->getStatusCode() >= 400) {
            $response = self::standardizeErrorResponse($response);
        }

        // 10. Security and No-Store Headers
        self::applySecurityHeaders($response);

        return $response;
    }

    /**
     * Standardize any downstream HTTP errors into schema-compliant error-response.json.
     */
    public static function standardizeErrorResponse(Response $response): Response
    {
        $status = $response->getStatusCode();
        $content = (string) $response->getContent();
        $decoded = json_decode($content, true);

        // If response already has exact required {"error": {"code": ..., "message": ...}} shape, preserve it
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])
            && isset($decoded['error']['code'], $decoded['error']['message'])) {
            return $response;
        }

        $code = match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            406 => 'NOT_ACCEPTABLE',
            409 => 'CONFLICT',
            413 => 'PAYLOAD_TOO_LARGE',
            415 => 'UNSUPPORTED_MEDIA_TYPE',
            422 => 'VALIDATION_FAILED',
            429 => 'TOO_MANY_REQUESTS',
            default => 'INTERNAL_SERVER_ERROR',
        };

        $message = match ($status) {
            400 => 'Bad request.',
            401 => 'Authentication required or token expired.',
            403 => 'Action is unauthorized.',
            404 => 'The requested resource was not found.',
            405 => 'The requested HTTP method is not allowed for this route.',
            406 => 'Not acceptable.',
            409 => 'Resource state conflict.',
            413 => 'Payload exceeds maximum allowed size.',
            415 => 'Unsupported media type.',
            422 => 'The given data failed validation.',
            429 => 'Rate limit exceeded. Please retry later.',
            default => 'An unexpected server error occurred.',
        };

        $details = null;
        if (is_array($decoded)) {
            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                $details = $decoded['errors'];
            } elseif (isset($decoded['message']) && is_string($decoded['message']) && $status !== 500) {
                $message = $decoded['message'];
            }
        }

        $extraHeaders = [];
        if ($response->headers->has('Retry-After')) {
            $extraHeaders['Retry-After'] = (string) $response->headers->get('Retry-After');
        }

        return self::buildErrorResponse($code, $message, $status, $details, headers: $extraHeaders);
    }

    /**
     * Map caught exceptions to schema-compliant error response.
     */
    public static function renderException(Throwable $e): JsonResponse
    {
        if ($e instanceof HttpResponseException && $e->getResponse() instanceof JsonResponse) {
            return $e->getResponse();
        }
        if ($e instanceof AuthenticationException) {
            return self::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required or token expired.',
                status: 401
            );
        }

        if ($e instanceof AuthorizationException) {
            return self::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized.',
                status: 403
            );
        }

        if ($e instanceof NotFoundHttpException || $e instanceof ModelNotFoundException) {
            return self::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested resource was not found.',
                status: 404
            );
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return self::buildErrorResponse(
                code: 'METHOD_NOT_ALLOWED',
                message: 'The requested HTTP method is not allowed for this route.',
                status: 405
            );
        }

        if ($e instanceof ValidationException) {
            return self::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'The given data failed validation.',
                status: 422,
                details: $e->errors()
            );
        }

        if ($e instanceof HttpExceptionInterface) {
            return self::buildErrorResponse(
                code: 'HTTP_ERROR',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'An HTTP error occurred.',
                status: $e->getStatusCode()
            );
        }

        Log::error('MobileApiInternalError: '.get_class($e));

        return self::buildErrorResponse(
            code: 'INTERNAL_SERVER_ERROR',
            message: 'An unexpected server error occurred.',
            status: 500
        );
    }

    /**
     * Build schema-compliant error JSON response.
     *
     * @param  array<string, mixed>|list<mixed>|string|null  $details
     * @param  array<string, mixed>|null  $conflictEvidence
     * @param  array<string, string>  $headers
     */
    public static function buildErrorResponse(
        string $code,
        string $message,
        int $status,
        mixed $details = null,
        ?array $conflictEvidence = null,
        array $headers = []
    ): JsonResponse {
        $errorPayload = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $errorPayload['details'] = $details;
        }

        if ($conflictEvidence !== null) {
            $errorPayload['conflict_evidence'] = $conflictEvidence;
        }

        $allHeaders = array_merge([
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ], $headers);

        return response()->json(['error' => $errorPayload], $status, $allHeaders);
    }

    /**
     * Apply security and no-store headers.
     */
    public static function applySecurityHeaders(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');

        if ($response->headers->get('Access-Control-Allow-Origin') === '*') {
            $response->headers->remove('Access-Control-Allow-Origin');
        }
    }
}
