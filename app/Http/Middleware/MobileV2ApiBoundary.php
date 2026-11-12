<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Mobile\Contract\ContractConstants;
use App\Support\Mobile\Contract\Exceptions\CanonicalSerializationException;
use App\Support\Mobile\Contract\StrictJsonParser;
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

    public const string INTERNAL_SAFE_HEADER = 'X-Mobile-V2-Internal-Safe';

    public const array ALLOWED_VALIDATION_FIELD_KEYS = [
        'company_id',
        'companyId',
        'device_id',
        'device_name',
        'platform',
        'app_version',
        'os_version',
        'push_token',
        'status',
        'page',
        'per_page',
        'limit',
        'cursor',
        'query',
        'search',
        'filter',
        'sort',
        'order',
        'regular_field',
        'field',
        'auth.password',
        'reason',
        'revoked_at',
        'requested_company_id',
    ];

    /**
     * Handle incoming request for Mobile V2 API before routing and web middleware.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isMobileV2($request)) {
            return $next($request);
        }

        if ($request->attributes->has('_mobile_v2_boundary_mounted')) {
            return $next($request);
        }
        $request->attributes->set('_mobile_v2_boundary_mounted', true);

        $guard = auth()->guard();
        $priorHadUser = $guard->hasUser();
        $priorGuardUser = $priorHadUser ? $guard->user() : null;
        $priorUserResolver = $request->getUserResolver();

        try {
            return $this->handleV2($request, $next);
        } finally {
            if ($priorHadUser && $priorGuardUser !== null) {
                $guard->setUser($priorGuardUser);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
            $request->setUserResolver($priorUserResolver);
            $request->attributes->remove('mobile_user');
            $request->attributes->remove('mobile_token');
            $request->attributes->remove('mobile_device');
            $request->attributes->remove('mobile_company');
            $request->attributes->remove('_mobile_v2_boundary_mounted');
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
        if ($maxAttempts <= 0) {
            $maxAttempts = 120;
        }

        if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts)) {
            $retryAfter = max(1, min(86400, RateLimiter::availableIn($rateKey)));

            return self::buildErrorResponse(
                code: 'TOO_MANY_REQUESTS',
                message: "Rate limit exceeded. Please retry after {$retryAfter} seconds.",
                status: 429,
                headers: ['Retry-After' => (string) $retryAfter]
            );
        }

        RateLimiter::hit($rateKey, 60);

        // 5. Reject GET and HEAD requests with any non-empty request body
        $rawBody = (string) $request->getContent();
        if (in_array($request->method(), ['GET', 'HEAD'], true) && $rawBody !== '') {
            return self::buildErrorResponse(
                code: 'BAD_REQUEST',
                message: $request->method().' requests must not contain a request body.',
                status: 400
            );
        }

        // 6. Safe query parameter bounds and recursive control character validation
        $queryString = (string) $request->server->get('QUERY_STRING', '');
        if (strlen($queryString) > self::MAX_QUERY_STRING_LENGTH) {
            return self::buildErrorResponse(
                code: 'BAD_REQUEST',
                message: 'Query string exceeds maximum allowed length of '.self::MAX_QUERY_STRING_LENGTH.' bytes.',
                status: 400
            );
        }

        if (! self::validateQueryParams($request->query(), 0)) {
            return self::buildErrorResponse(
                code: 'BAD_REQUEST',
                message: 'Query parameter contains invalid control characters.',
                status: 400
            );
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
                } catch (CanonicalSerializationException) {
                    return self::buildErrorResponse(
                        code: 'INVALID_JSON',
                        message: 'The request body must be valid, well-formed canonical JSON.',
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

    public const array ALLOWED_INTERNAL_ERROR_CODES = [
        'MOBILE_API_DISABLED',
        'UNAUTHENTICATED',
        'FORBIDDEN',
        'FORBIDDEN_TENANT_MISMATCH',
        'NOT_FOUND',
        'METHOD_NOT_ALLOWED',
        'NOT_ACCEPTABLE',
        'CONFLICT',
        'PAYLOAD_TOO_LARGE',
        'UNSUPPORTED_MEDIA_TYPE',
        'VALIDATION_FAILED',
        'TOO_MANY_REQUESTS',
        'FINANCIAL_AUTHORITY_UNRESOLVED',
        'INTERNAL_SERVER_ERROR',
        'BAD_REQUEST',
        'INVALID_JSON',
    ];

    /**
     * Recursively validate query parameters to reject control characters and enforce bounded depth.
     *
     * @param  array<mixed, mixed>  $params
     */
    public static function validateQueryParams(array $params, int $depth = 0): bool
    {
        if ($depth > 16) {
            return false;
        }

        foreach ($params as $key => $val) {
            if (preg_match('/[\x00-\x1F\x7F]/', (string) $key)) {
                return false;
            }

            if (is_array($val)) {
                if (! self::validateQueryParams($val, $depth + 1)) {
                    return false;
                }
            } elseif (is_string($val)) {
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $val)) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function defaultCodeForStatus(int $status): string
    {
        return match ($status) {
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
    }

    public static function defaultMessageForCode(string $code, int $status): string
    {
        return match ($code) {
            'MOBILE_API_DISABLED' => 'The mobile API is currently disabled.',
            'UNAUTHENTICATED' => 'Authentication required or token expired.',
            'FORBIDDEN_TENANT_MISMATCH' => 'User does not possess active authorized membership in the requested company context.',
            'FORBIDDEN' => 'Action is unauthorized.',
            'FINANCIAL_AUTHORITY_UNRESOLVED' => 'Financial authority is unresolved on Mobile V2.',
            'NOT_FOUND' => 'The requested resource was not found.',
            'METHOD_NOT_ALLOWED' => 'The requested HTTP method is not allowed for this route.',
            'NOT_ACCEPTABLE' => 'The Accept header must include application/json.',
            'CONFLICT' => 'Resource state conflict.',
            'PAYLOAD_TOO_LARGE' => 'Payload exceeds maximum allowed size of '.self::MAX_PAYLOAD_BYTES.' bytes.',
            'UNSUPPORTED_MEDIA_TYPE' => 'The request Content-Type must be application/json.',
            'VALIDATION_FAILED' => 'The given data failed validation.',
            'TOO_MANY_REQUESTS' => 'Rate limit exceeded. Please retry later.',
            'BAD_REQUEST' => 'Bad request.',
            'INVALID_JSON' => 'The request body must be valid, well-formed canonical JSON.',
            default => match ($status) {
                400 => 'Bad request.',
                401 => 'Authentication required or token expired.',
                403 => 'Action is unauthorized.',
                404 => 'The requested resource was not found.',
                405 => 'The requested HTTP method is not allowed for this route.',
                406 => 'The Accept header must include application/json.',
                409 => 'Resource state conflict.',
                413 => 'Payload exceeds maximum allowed size.',
                415 => 'The request Content-Type must be application/json.',
                422 => 'The given data failed validation.',
                429 => 'Rate limit exceeded. Please retry later.',
                default => 'An unexpected server error occurred.',
            },
        };
    }

    /**
     * Standardize any downstream HTTP errors into schema-compliant error response.
     */
    public static function standardizeErrorResponse(Response $response): Response
    {
        $status = $response->getStatusCode();

        if ($response->headers->has(self::INTERNAL_SAFE_HEADER)) {
            $response->headers->remove(self::INTERNAL_SAFE_HEADER);
            self::applySecurityHeaders($response);

            return $response;
        }

        $content = (string) $response->getContent();
        $decoded = json_decode($content, true);

        $code = self::defaultCodeForStatus($status);
        $details = null;

        if (is_array($decoded)) {
            if (isset($decoded['error']) && is_array($decoded['error'])) {
                $candidateCode = $decoded['error']['code'] ?? null;
                if (is_string($candidateCode) && in_array($candidateCode, self::ALLOWED_INTERNAL_ERROR_CODES, true)) {
                    $code = $candidateCode;
                }
                if (isset($decoded['error']['details']) && is_array($decoded['error']['details'])) {
                    $details = self::sanitizeValidationErrors($decoded['error']['details']);
                }
            } elseif (isset($decoded['errors']) && is_array($decoded['errors'])) {
                $details = self::sanitizeValidationErrors($decoded['errors']);
            }
        }

        $message = self::defaultMessageForCode($code, $status);

        $extraHeaders = [];
        if ($response->headers->has('Retry-After')) {
            $rawRetry = (string) $response->headers->get('Retry-After');
            if (ctype_digit($rawRetry)) {
                $retrySec = max(0, min(86400, (int) $rawRetry));
                $extraHeaders['Retry-After'] = (string) $retrySec;
            }
        }

        return self::buildErrorResponse($code, $message, $status, $details, headers: $extraHeaders);
    }

    /**
     * Map caught exceptions to schema-compliant error response.
     */
    public static function renderException(Throwable $e): JsonResponse
    {
        if ($e instanceof HttpResponseException) {
            $resp = self::standardizeErrorResponse($e->getResponse());

            return $resp instanceof JsonResponse ? $resp : response()->json(
                json_decode((string) $resp->getContent(), true),
                $resp->getStatusCode(),
                $resp->headers->all()
            );
        }

        if ($e instanceof AuthenticationException) {
            return self::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: self::defaultMessageForCode('UNAUTHENTICATED', 401),
                status: 401
            );
        }

        if ($e instanceof AuthorizationException) {
            return self::buildErrorResponse(
                code: 'FORBIDDEN',
                message: self::defaultMessageForCode('FORBIDDEN', 403),
                status: 403
            );
        }

        if ($e instanceof NotFoundHttpException || $e instanceof ModelNotFoundException) {
            return self::buildErrorResponse(
                code: 'NOT_FOUND',
                message: self::defaultMessageForCode('NOT_FOUND', 404),
                status: 404
            );
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return self::buildErrorResponse(
                code: 'METHOD_NOT_ALLOWED',
                message: self::defaultMessageForCode('METHOD_NOT_ALLOWED', 405),
                status: 405
            );
        }

        if ($e instanceof ValidationException) {
            return self::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: self::defaultMessageForCode('VALIDATION_FAILED', 422),
                status: 422,
                details: self::sanitizeValidationErrors($e->errors())
            );
        }

        if ($e instanceof ConflictHttpException) {
            return self::buildErrorResponse(
                code: 'CONFLICT',
                message: self::defaultMessageForCode('CONFLICT', 409),
                status: 409
            );
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = self::defaultCodeForStatus($status);
            $message = self::defaultMessageForCode($code, $status);

            $headers = [];
            $headersMap = $e->getHeaders();
            if (isset($headersMap['Retry-After'])) {
                $rawRetry = (string) $headersMap['Retry-After'];
                if (ctype_digit($rawRetry)) {
                    $retrySec = max(0, min(86400, (int) $rawRetry));
                    $headers['Retry-After'] = (string) $retrySec;
                }
            }

            return self::buildErrorResponse(
                code: $code,
                message: $message,
                status: $status,
                headers: $headers
            );
        }

        Log::error('MobileApiV2InternalError: '.get_class($e));

        return self::buildErrorResponse(
            code: 'INTERNAL_SERVER_ERROR',
            message: self::defaultMessageForCode('INTERNAL_SERVER_ERROR', 500),
            status: 500
        );
    }

    /**
     * Sanitize validation error details to ensure no sensitive credentials, secrets, or raw SQL are leaked.
     *
     * @param  array<string, mixed>  $errors
     * @return array<string, mixed>
     */
    public static function sanitizeValidationErrors(array $errors): array
    {
        $sanitized = [];
        $count = 0;
        foreach ($errors as $field => $messages) {
            if (++$count > 20) {
                break;
            }
            $fieldName = (string) $field;
            $safeFieldName = in_array($fieldName, self::ALLOWED_VALIDATION_FIELD_KEYS, true)
                ? $fieldName
                : 'field';

            if (self::isSensitiveField($fieldName)) {
                $sanitized[$safeFieldName] = ['The provided credential or secret field failed validation.'];

                continue;
            }

            $sanitized[$safeFieldName] = ["The {$safeFieldName} field failed validation."];
        }

        return $sanitized;
    }

    public static function isSensitiveField(string $field): bool
    {
        $lower = strtolower($field);
        $sensitiveTokens = [
            'password',
            'secret',
            'token',
            'raw_token',
            'credential',
            'mfa',
            'code',
            'recovery',
            'hash',
            'bearer',
            'private',
            'provider_secret',
            'auth',
        ];

        $parts = explode('.', $lower);
        foreach ($parts as $part) {
            foreach ($sensitiveTokens as $token) {
                if ($part === $token || str_contains($part, $token)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function sanitizeFieldName(string $field): string
    {
        if (strlen($field) > 64 || preg_match('/[^a-zA-Z0-9_\-\.]/', $field)) {
            return 'field';
        }

        return $field;
    }

    public static function sanitizeValidationMessage(string $msg, string $safeField): string
    {
        if (preg_match('/(SELECT|INSERT|UPDATE|DELETE|DROP|FROM|WHERE|TABLE|SQLSTATE|PDOException|#\d+|\.php:)/i', $msg)) {
            return "The {$safeField} field failed validation.";
        }

        if (strlen($msg) > 255) {
            return "The {$safeField} field failed validation.";
        }

        return $msg;
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
            self::INTERNAL_SAFE_HEADER => 'true',
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
