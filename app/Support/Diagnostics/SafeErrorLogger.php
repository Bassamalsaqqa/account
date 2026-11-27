<?php

declare(strict_types=1);

namespace App\Support\Diagnostics;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Writes strictly sanitized, bounded diagnostic log records without raw exception messages,
 * stack frame arguments, SQL, queries, bodies, credentials, or tenant business values.
 * Never allows logger failures to crash or alter an HTTP response.
 */
final class SafeErrorLogger
{
    private static bool $reporting = false;

    private static bool $inSafeError = false;

    /** @var list<string> */
    private const array INTERNAL_PREFIXES = [
        'app/',
        'bootstrap/',
        'config/',
        'database/',
        'resources/views/',
        'routes/',
    ];

    public static function report(Throwable $exception, ?Request $request = null, ?int $statusOverride = null): void
    {
        if (self::$reporting) {
            return;
        }

        self::$reporting = true;

        try {
            $frames = self::boundedFrames($exception);
            $exceptionClass = self::exceptionClass($exception);
            $fingerprint = hash('sha256', $exceptionClass.'|'.implode('|', $frames));

            $requestMeta = self::resolveSafeRequestContext($request);
            $actor = self::resolveSafeActor($request);

            $context = array_filter([
                'request_id' => $requestMeta['request_id'],
                'release_version' => self::releaseVersion(),
                'release_source_sha' => self::releaseHex('release.source_sha', 40),
                'asset_manifest_sha256' => self::releaseHex('release.asset_manifest_sha256', 64),
                'route_name' => $requestMeta['route_name'],
                'route_uri' => $requestMeta['route_uri'],
                'method' => $requestMeta['method'],
                'status' => self::resolveSafeStatus($exception, $statusOverride),
                'exception_class' => $exceptionClass,
                'fingerprint' => $fingerprint,
                'frames' => $frames,
                'actual_user_id' => $actor['actual_user_id'],
                'effective_user_id' => $actor['effective_user_id'],
                'company_id' => $actor['company_id'],
                'is_impersonated' => $actor['is_impersonated'] ?: null,
                'support_correlation_id' => $actor['support_correlation_id'],
            ], static fn (mixed $value): bool => $value !== null && $value !== []);

            self::emitError('Handled application exception', $context);
        } catch (Throwable) {
            // Entire diagnostic context assembly and logging path is isolated; never propagate or recurse.
        } finally {
            self::$reporting = false;
        }
    }

    /**
     * Safe API/boundary logger preserving existing message shapes while stripping raw messages
     * and catching context or logger failures so error responses are never lost.
     *
     * @param  array<string, scalar|null>  $extra
     */
    public static function reportBoundaryException(string $label, Throwable $exception, array $extra = []): void
    {
        if (self::$reporting) {
            return;
        }

        self::$reporting = true;

        try {
            $frames = self::boundedFrames($exception);
            $exceptionClass = self::exceptionClass($exception);
            $allowedExtra = self::sanitizeExtraIdentifiers($extra);

            $context = array_filter(array_merge($allowedExtra, [
                'exception_class' => $exceptionClass,
                'fingerprint' => hash('sha256', $exceptionClass.'|'.implode('|', $frames)),
                'frames' => $frames,
                'release_version' => self::releaseVersion(),
                'release_source_sha' => self::releaseHex('release.source_sha', 40),
            ]), static fn (mixed $value): bool => $value !== null && $value !== []);

            try {
                $rawClass = get_class($exception);
                $label = str_replace($rawClass, $exceptionClass, $label);
            } catch (Throwable) {
                $label = $exceptionClass;
            }

            $cleanLabel = preg_replace('/[\x00-\x1F\x7F]/', '', $label);
            $safeLabel = is_string($cleanLabel) && $cleanLabel !== '' ? substr($cleanLabel, 0, 200) : $exceptionClass;

            self::emitError($safeLabel, $context);
        } catch (Throwable) {
            // Best-effort boundary logging: never throw or recurse during failure paths.
        } finally {
            self::$reporting = false;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function safeError(string $message, array $context = []): void
    {
        if (self::$reporting || self::$inSafeError) {
            return;
        }

        self::emitError($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function emitError(string $message, array $context = []): void
    {
        if (self::$inSafeError) {
            return;
        }

        self::$inSafeError = true;

        try {
            Log::error($message, $context);
        } catch (Throwable) {
            // Best-effort logging: never fail or recurse if storage/log channel is unavailable.
        } finally {
            self::$inSafeError = false;
        }
    }

    /**
     * @return array{request_id: ?string, route_name: ?string, route_uri: ?string, method: ?string}
     */
    private static function resolveSafeRequestContext(?Request $request): array
    {
        $result = [
            'request_id' => null,
            'route_name' => null,
            'route_uri' => null,
            'method' => null,
        ];

        if ($request === null) {
            return $result;
        }

        try {
            $result['request_id'] = RequestId::forRequest($request);
        } catch (Throwable) {
        }

        try {
            $route = $request->route();
            if ($route instanceof Route) {
                $result['route_name'] = self::sanitizeRouteName($route->getName());
                $result['route_uri'] = self::sanitizeRouteUri($route->uri());
            }
        } catch (Throwable) {
        }

        try {
            $result['method'] = self::sanitizeMethod($request->method());
        } catch (Throwable) {
        }

        return $result;
    }

    /**
     * @return array{actual_user_id: ?int, effective_user_id: ?int, company_id: ?int, is_impersonated: bool, support_correlation_id: ?string}
     */
    private static function resolveSafeActor(?Request $request): array
    {
        try {
            return RequestId::actorSnapshot($request);
        } catch (Throwable) {
            return [
                'actual_user_id' => null,
                'effective_user_id' => null,
                'company_id' => null,
                'is_impersonated' => false,
                'support_correlation_id' => null,
            ];
        }
    }

    private static function resolveSafeStatus(Throwable $exception, ?int $statusOverride): int
    {
        if ($statusOverride !== null) {
            return $statusOverride;
        }

        try {
            return self::resolveStatus($exception);
        } catch (Throwable) {
            return 500;
        }
    }

    /**
     * @param  array<string, scalar|null>  $extra
     * @return array<string, string>
     */
    private static function sanitizeExtraIdentifiers(array $extra): array
    {
        $clean = [];
        foreach (['trace_id', 'request_id'] as $key) {
            if (! array_key_exists($key, $extra) || ! is_string($extra[$key])) {
                continue;
            }
            $value = preg_replace('/[\x00-\x1F\x7F]/', '', $extra[$key]);
            if (is_string($value) && $value !== '') {
                $clean[$key] = substr($value, 0, 128);
            }
        }

        return $clean;
    }

    public static function releaseVersion(): ?string
    {
        try {
            $value = config('release.version');
        } catch (Throwable) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return ($trimmed !== '' && strlen($trimmed) <= 64 && preg_match('/^[A-Za-z0-9._+\-]+$/D', $trimmed) === 1)
            ? $trimmed
            : null;
    }

    public static function releaseHex(string $configKey, int $expectedLength): ?string
    {
        try {
            $value = config($configKey);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return preg_match('/^[0-9a-f]{'.$expectedLength.'}$/D', $normalized) === 1
            ? $normalized
            : null;
    }

    /**
     * Extract up to 8 internal relative file:line locations without arguments or absolute host paths.
     *
     * @return list<string>
     */
    public static function boundedFrames(Throwable $exception): array
    {
        try {
            $base = str_replace('\\', '/', base_path()).'/';
            $frames = [];

            $appendFrame = static function (mixed $file, mixed $line) use ($base, &$frames): void {
                if (! is_string($file) || $file === '' || ! is_int($line) || $line < 1) {
                    return;
                }
                $normalized = str_replace('\\', '/', $file);
                if (str_starts_with($normalized, $base)) {
                    $relative = substr($normalized, strlen($base));
                } else {
                    return;
                }
                foreach (self::INTERNAL_PREFIXES as $prefix) {
                    if (str_starts_with($relative, $prefix)) {
                        $entry = $relative.':'.$line;
                        if (! in_array($entry, $frames, true)) {
                            $frames[] = $entry;
                        }

                        return;
                    }
                }
            };

            $appendFrame($exception->getFile(), $exception->getLine());

            foreach ($exception->getTrace() as $frame) {
                if (count($frames) >= 8) {
                    break;
                }
                $appendFrame($frame['file'] ?? null, $frame['line'] ?? null);
            }

            return array_slice($frames, 0, 8);
        } catch (Throwable) {
            return [];
        }
    }

    private static function exceptionClass(Throwable $exception): string
    {
        try {
            $class = get_class($exception);
            // PHP embeds an absolute source path in anonymous class names.
            if (str_contains($class, '@anonymous')) {
                $class = get_parent_class($exception) ?: Throwable::class;
            }

            return strlen($class) <= 200 && preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $class) === 1
                ? $class
                : Throwable::class;
        } catch (Throwable) {
            return Throwable::class;
        }
    }

    private static function resolveStatus(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof TokenMismatchException => 419,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof ValidationException => 422,
            default => 500,
        };
    }

    private static function sanitizeRouteName(?string $name): ?string
    {
        if (! is_string($name) || $name === '' || strlen($name) > 120) {
            return null;
        }

        return preg_match('/^[a-zA-Z0-9._-]+$/D', $name) === 1 ? $name : null;
    }

    private static function sanitizeRouteUri(?string $uri): ?string
    {
        if (! is_string($uri) || $uri === '') {
            return null;
        }

        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $uri);
        if (! is_string($clean) || $clean === '') {
            return null;
        }

        return substr($clean, 0, 160);
    }

    private static function sanitizeMethod(string $method): ?string
    {
        $upper = strtoupper($method);

        return in_array($upper, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)
            ? $upper
            : null;
    }
}
