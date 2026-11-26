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
        $frames = self::boundedFrames($exception);
        $exceptionClass = self::exceptionClass($exception);
        $fingerprint = hash('sha256', $exceptionClass.'|'.implode('|', $frames));

        $route = $request?->route();
        $routeName = $route instanceof Route ? self::sanitizeRouteName($route->getName()) : null;
        $routeUri = $route instanceof Route ? self::sanitizeRouteUri($route->uri()) : null;
        $method = $request !== null ? self::sanitizeMethod($request->method()) : null;
        $actor = RequestId::actorSnapshot($request);

        $context = array_filter([
            'request_id' => $request !== null ? RequestId::forRequest($request) : null,
            'release_version' => self::releaseVersion(),
            'release_source_sha' => self::releaseHex('release.source_sha', 40),
            'asset_manifest_sha256' => self::releaseHex('release.asset_manifest_sha256', 64),
            'route_name' => $routeName,
            'route_uri' => $routeUri,
            'method' => $method,
            'status' => $statusOverride ?? self::resolveStatus($exception),
            'exception_class' => $exceptionClass,
            'fingerprint' => $fingerprint,
            'frames' => $frames,
            'actual_user_id' => $actor['actual_user_id'],
            'effective_user_id' => $actor['effective_user_id'],
            'company_id' => $actor['company_id'],
            'is_impersonated' => $actor['is_impersonated'] ?: null,
            'support_correlation_id' => $actor['support_correlation_id'],
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        self::safeError('Handled application exception', $context);
    }

    /**
     * Safe API/boundary logger preserving existing message shapes while stripping raw messages
     * and catching logger storage failures so error responses are never lost.
     *
     * @param  array<string, scalar|null>  $extra
     */
    public static function reportBoundaryException(string $label, Throwable $exception, array $extra = []): void
    {
        $frames = self::boundedFrames($exception);
        $exceptionClass = self::exceptionClass($exception);
        $allowedExtra = array_intersect_key($extra, array_flip(['trace_id', 'request_id']));

        $context = array_filter(array_merge($allowedExtra, [
            'exception_class' => $exceptionClass,
            'fingerprint' => hash('sha256', $exceptionClass.'|'.implode('|', $frames)),
            'frames' => $frames,
            'release_version' => self::releaseVersion(),
            'release_source_sha' => self::releaseHex('release.source_sha', 40),
        ]), static fn (mixed $value): bool => $value !== null && $value !== []);

        // Existing boundaries include the class in their label as well.
        $label = str_replace(get_class($exception), $exceptionClass, $label);
        self::safeError($label, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function safeError(string $message, array $context = []): void
    {
        try {
            Log::error($message, $context);
        } catch (Throwable) {
            // Best-effort logging: never fail or recurse if storage/log channel is unavailable.
        }
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
        $base = str_replace('\\', '/', base_path()).'/';
        $frames = [];

        $appendFrame = static function (?string $file, ?int $line) use ($base, &$frames): void {
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
    }

    private static function exceptionClass(Throwable $exception): string
    {
        $class = get_class($exception);
        // PHP embeds an absolute source path in anonymous class names.
        if (str_contains($class, '@anonymous')) {
            $class = get_parent_class($exception) ?: Throwable::class;
        }

        return strlen($class) <= 200 && preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $class) === 1
            ? $class
            : Throwable::class;
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
