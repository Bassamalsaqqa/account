<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Mobile\Contract\ContractConstants;
use App\Support\Mobile\Contract\Exceptions\CanonicalSerializationException;
use App\Support\Mobile\Contract\StrictJsonParser;
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
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class MobileV2ApiBoundary
{
    public const int MAX_PAYLOAD_BYTES = ContractConstants::MAX_PAYLOAD_BYTES; // 1048576 (1 MiB)

    public const int MAX_QUERY_STRING_LENGTH = 2048;

    public const int MAX_JSON_DEPTH = ContractConstants::MAX_NESTING_DEPTH; // 32

    /**
     * Handle incoming request for Mobile V2 API before routing and web middleware.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isMobileV2($request)) {
            return $next($request);
        }

        $context = app(CompanyContext::class);
        $context->clear();

        try {
            return $this->handleV2($request, $next);
        } finally {
            $context->clear();
        }
    }

    private function isMobileV2(Request $request): bool
    {
        $path = trim($request->path(), '/');

        return $path === 'api/mobile/v2' || str_starts_with($path, 'api/mobile/v2/');
    }

    private function handleV2(Request $request, Closure $next): Response
    {
        // 1. Dual-gate Master Activation: Both mobile.enabled AND mobile.v2_enabled must be true
        if (! (bool) config('mobile.enabled', false) || ! (bool) config('mobile.v2_enabled', false)) {
            return self::buildErrorResponse(
                code: 'MOBILE_API_DISABLED',
                message: 'The mobile API is currently disabled.',
                status: 404
            );
        }

        // 2. TLS Enforcement outside local and testing environments
        if (! app()->environment('local', 'testing') && ! $request->isSecure()) {
            return self::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'HTTPS/TLS connection is required for Mobile API access.',
                status: 403
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

        // 4. Overall IP Rate Limiter
        $ip = (string) $request->ip();
        $rateKey = "mobile_v2:ip_overall:{$ip}";
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

        // 5. Reject GET requests with a request body
        $rawBody = (string) $request->getContent();
        if ($request->isMethod('GET') && $rawBody !== '' && trim($rawBody) !== '[]') {
            return self::buildErrorResponse(
                code: 'BAD_REQUEST',
                message: 'GET requests must not contain a request body.',
                status: 400
            );
        }

        // 6. Safe query parameter bounds
        $queryString = (string) $request->server->get('QUERY_STRING', '');
        if (strlen($queryString) > self::MAX_QUERY_STRING_LENGTH) {
            return self::buildErrorResponse(
                code: 'BAD_REQUEST',
                message: 'Query string exceeds maximum allowed length of '.self::MAX_QUERY_STRING_LENGTH.' bytes.',
                status: 400
            );
        }

        foreach ($request->query() as $key => $val) {
            if (preg_match('/[\x00-\x1F\x7F]/', (string) $key)) {
                return self::buildErrorResponse(
                    code: 'BAD_REQUEST',
                    message: 'Query parameter contains invalid control characters.',
                    status: 400
                );
            }
            if (is_string($val) && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $val)) {
                return self::buildErrorResponse(
                    code: 'BAD_REQUEST',
                    message: 'Query parameter contains invalid control characters.',
                    status: 400
                );
            }
        }

        // 7. Payload size constraint check (<= 1 MiB)
        $contentLength = $request->header('Content-Length');
        if ($contentLength !== null && (int) $contentLength > self::MAX_PAYLOAD_BYTES) {
            return self::buildErrorResponse(
                code: 'PAYLOAD_TOO_LARGE',
                message: 'Payload exceeds maximum allowed size of '.self::MAX_PAYLOAD_BYTES.' bytes.',
                status: 413
            );
        }

        if (strlen($rawBody) > self::MAX_PAYLOAD_BYTES) {
            return self::buildErrorResponse(
                code: 'PAYLOAD_TOO_LARGE',
                message: 'Payload exceeds maximum allowed size of '.self::MAX_PAYLOAD_BYTES.' bytes.',
                status: 413
            );
        }

        // 8. Mutating methods Content-Type and strict JSON validation
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $contentType = (string) $request->header('Content-Type');
            if (! str_contains($contentType, 'application/json')) {
                return self::buildErrorResponse(
                    code: 'UNSUPPORTED_MEDIA_TYPE',
                    message: 'The request Content-Type must be application/json.',
                    status: 415
                );
            }

            if ($rawBody !== '') {
                try {
                    StrictJsonParser::parse($rawBody);
                } catch (CanonicalSerializationException $e) {
                    return self::buildErrorResponse(
                        code: 'INVALID_JSON',
                        message: 'Invalid JSON payload: '.$e->getMessage(),
                        status: 422
                    );
                }
            }
        }

        // 9. Downstream execution with exception sanitization
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $response = self::renderException($e);
        }

        // 10. Standardize error responses to schema-compliant format
        if ($response->getStatusCode() >= 400) {
            $response = self::standardizeErrorResponse($response);
        }

        // 11. Security and No-Store Headers
        self::applySecurityHeaders($response);

        return $response;
    }

    /**
     * Standardize any downstream HTTP errors into schema-compliant error response.
     */
    public static function standardizeErrorResponse(Response $response): Response
    {
        $status = $response->getStatusCode();
        $content = (string) $response->getContent();
        $decoded = json_decode($content, true);

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
                $details = self::sanitizeValidationErrors($decoded['errors']);
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
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Action is unauthorized.',
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
                details: self::sanitizeValidationErrors($e->errors())
            );
        }

        if ($e instanceof ConflictHttpException) {
            return self::buildErrorResponse(
                code: 'CONFLICT',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Resource state conflict.',
                status: 409
            );
        }

        if ($e instanceof HttpExceptionInterface) {
            return self::buildErrorResponse(
                code: 'HTTP_ERROR',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'An HTTP error occurred.',
                status: $e->getStatusCode()
            );
        }

        Log::error('MobileApiV2InternalError: '.get_class($e));

        return self::buildErrorResponse(
            code: 'INTERNAL_SERVER_ERROR',
            message: 'An unexpected server error occurred.',
            status: 500
        );
    }

    /**
     * Sanitize validation error details to ensure no sensitive credentials or raw tokens are leaked.
     *
     * @param  array<string, mixed>  $errors
     * @return array<string, mixed>
     */
    public static function sanitizeValidationErrors(array $errors): array
    {
        $sensitiveKeys = ['password', 'password_confirmation', 'current_password', 'token', 'raw_token', 'mfa_code', 'code', 'recovery_code', 'secret', 'provider_secret'];

        $sanitized = [];
        foreach ($errors as $key => $messages) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $sanitized[$key] = ['The provided credential field failed validation.'];
            } else {
                $sanitized[$key] = $messages;
            }
        }

        return $sanitized;
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
