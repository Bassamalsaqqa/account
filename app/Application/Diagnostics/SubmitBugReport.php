<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

use App\Application\Platform\SupportViewContext;
use App\Models\BugReport;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use App\Support\Diagnostics\RequestId;
use App\Support\Diagnostics\SafeErrorLogger;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class SubmitBugReport
{
    public const int MAX_BODY_BYTES = 16384;

    public const int MAX_NOTE_LENGTH = 1000;

    public const int MIN_NOTE_LENGTH = 5;

    /** @var list<string> */
    private const array ALLOWED_TOP_KEYS = [
        'idempotency_key',
        'category',
        'note',
        'include_diagnostics',
        'screen',
    ];

    /** @var list<string> */
    private const array ALLOWED_SCREEN_KEYS = [
        'route_name',
        'failed_request_id',
        'failed_status',
        'page_request_id',
        'viewport',
        'livewire_failed',
    ];

    /** @var list<string> */
    private const array ALLOWED_VIEWPORTS = ['mobile', 'tablet', 'desktop'];

    /**
     * Validate, rate-limit, and atomically store an authenticated bug report.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array{report: BugReport, replay: bool}
     */
    public function execute(Request $request, array $payload): array
    {
        $effectiveUser = $request->user();
        if (! $effectiveUser instanceof User) {
            throw new AuthorizationException('Authentication is required.');
        }

        // Capture server-derived actor/impersonation/company state inside the request boundary.
        $support = app(SupportViewContext::class);
        $isImpersonated = $support->active();
        $actualUserId = $isImpersonated
            ? $support->actualActorId((int) $effectiveUser->id)
            : (int) $effectiveUser->id;
        $effectiveUserId = (int) $effectiveUser->id;

        $companyContext = app(CompanyContext::class);
        $companyId = $companyContext->hasCompany() ? $companyContext->companyId() : null;

        $this->enforceRateLimits($request, $actualUserId, $companyId);

        $normalized = $this->validateAndNormalize($payload);
        $screen = $normalized['screen'];
        $includeDiagnostics = $normalized['include_diagnostics'];

        $routeName = null;
        $screenPath = null;
        $requestId = null;
        $failedRequestId = null;
        $sanitizedContext = null;

        $hasPlatformGrant = PlatformOperatorGrant::query()
            ->where('user_id', $actualUserId)
            ->where('active', true)
            ->exists();

        if ($includeDiagnostics) {
            $registeredRoute = RouteSourceMap::resolveRegisteredRoute($screen['route_name']);
            $routeName = $registeredRoute !== null ? $screen['route_name'] : null;
            $screenPath = RouteSourceMap::sanitizeRouteTemplate($registeredRoute);
            $requestId = $screen['page_request_id'];
            $failedRequestId = $screen['failed_request_id'];

            $sanitizedContext = [
                'server' => array_filter([
                    'app_env' => (string) config('app.env', 'production'),
                    'has_company_context' => $companyId !== null,
                    'has_platform_grant' => $hasPlatformGrant,
                    'intake_request_id' => RequestId::forRequest($request),
                    'support_correlation_id' => $isImpersonated && is_string($support->details()['correlation_id'] ?? null)
                        ? (string) $support->details()['correlation_id']
                        : null,
                ], static fn (mixed $v): bool => $v !== null),
                'client_hint_unverified' => array_filter([
                    'claimed_route_name' => $screen['route_name'],
                    'route_known_to_router' => $screen['route_name'] !== null ? ($registeredRoute !== null) : null,
                    'viewport' => $screen['viewport'],
                    'failed_status' => $screen['failed_status'],
                    'livewire_failed' => $screen['livewire_failed'],
                ], static fn (mixed $v): bool => $v !== null),
            ];
        }

        $locale = in_array(app()->getLocale(), ['ar', 'en'], true) ? app()->getLocale() : 'ar';
        $releaseVersion = $includeDiagnostics ? SafeErrorLogger::releaseVersion() : null;
        $releaseSourceSha = $includeDiagnostics ? SafeErrorLogger::releaseHex('release.source_sha', 40) : null;
        $assetManifestSha = $includeDiagnostics ? SafeErrorLogger::releaseHex('release.asset_manifest_sha256', 64) : null;

        $canonical = [
            'category' => $normalized['category'],
            'company_id' => $companyId,
            'effective_user_id' => $effectiveUserId,
            'failed_request_id' => $failedRequestId,
            'failed_status' => $includeDiagnostics ? $screen['failed_status'] : null,
            'include_diagnostics' => $includeDiagnostics,
            'is_impersonated' => $isImpersonated,
            'livewire_failed' => $includeDiagnostics ? $screen['livewire_failed'] : null,
            'note' => $normalized['note'],
            'request_id' => $requestId,
            'route_name' => $routeName,
            'screen_path' => $screenPath,
            'viewport' => $includeDiagnostics ? $screen['viewport'] : null,
        ];
        ksort($canonical);
        $appKey = (string) config('app.key');
        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY is required for bug report idempotency.');
        }
        $fingerprint = hash_hmac(
            'sha256',
            json_encode($canonical, JSON_THROW_ON_ERROR),
            $appKey,
        );

        $idempotencyKey = $normalized['idempotency_key'];

        // Fast-path replay/conflict check for sequential retries without gap locks.
        $existing = BugReport::query()
            ->where('reporter_user_id', $actualUserId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            return $this->resolveExistingIdempotent($existing, $fingerprint);
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                // Insert directly inside a short transaction so InnoDB relies on the unique key
                // instead of gap-locking missing rows (which can deadlock under concurrent retries).
                return DB::transaction(static function () use (
                    $actualUserId,
                    $effectiveUserId,
                    $companyId,
                    $isImpersonated,
                    $normalized,
                    $locale,
                    $routeName,
                    $screenPath,
                    $requestId,
                    $failedRequestId,
                    $releaseVersion,
                    $releaseSourceSha,
                    $assetManifestSha,
                    $includeDiagnostics,
                    $sanitizedContext,
                    $idempotencyKey,
                    $fingerprint,
                ): array {
                    $report = new BugReport;
                    $report->forceFill([
                        'public_ref' => (string) Str::ulid(),
                        'reporter_user_id' => $actualUserId,
                        'effective_user_id' => $effectiveUserId,
                        'company_id' => $companyId,
                        'is_impersonated' => $isImpersonated,
                        'source' => 'web',
                        'category' => $normalized['category'],
                        'locale' => $locale,
                        'route_name' => $routeName,
                        'screen_path' => $screenPath,
                        'request_id' => $requestId,
                        'failed_request_id' => $failedRequestId,
                        'release_version' => $releaseVersion,
                        'release_source_sha' => $releaseSourceSha,
                        'asset_manifest_sha256' => $assetManifestSha,
                        'include_diagnostics' => $includeDiagnostics,
                        'note' => $normalized['note'],
                        'sanitized_context' => $sanitizedContext,
                        'idempotency_key' => $idempotencyKey,
                        'payload_fingerprint' => $fingerprint,
                        'status' => BugReport::STATUS_NEW,
                    ])->save();

                    return ['report' => $report, 'replay' => false];
                });
            } catch (QueryException $e) {
                if ($this->isDuplicateKeyException($e)) {
                    $winner = $this->findExistingIdempotent($actualUserId, $idempotencyKey);
                    if ($winner !== null) {
                        return $this->resolveExistingIdempotent($winner, $fingerprint);
                    }
                }

                if ($this->isDeadlockException($e) && $attempt === 0) {
                    continue;
                }

                throw $e;
            }
        }

        throw new \RuntimeException('Bug report submission failed after idempotency retry.');
    }

    private function findExistingIdempotent(int $actualUserId, string $idempotencyKey): ?BugReport
    {
        $query = BugReport::query()
            ->where('reporter_user_id', $actualUserId)
            ->where('idempotency_key', $idempotencyKey);

        // Inside an outer REPEATABLE READ transaction, a locking read observes committed winners.
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function isDuplicateKeyException(QueryException $e): bool
    {
        $sqlState = (string) $e->getCode();
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        if ($driverCode === 1062) {
            return true;
        }

        return $sqlState === '23000'
            && (str_contains($e->getMessage(), 'bug_reports_submitter_idempotency_unique')
                || str_contains($e->getMessage(), 'Duplicate entry'));
    }

    private function isDeadlockException(QueryException $e): bool
    {
        return (string) $e->getCode() === '40001'
            || (int) ($e->errorInfo[1] ?? 0) === 1213;
    }

    /** @return array{report: BugReport, replay: bool} */
    private function resolveExistingIdempotent(BugReport $existing, string $fingerprint): array
    {
        if (! hash_equals($existing->payload_fingerprint, $fingerprint)) {
            throw new ConflictHttpException(__('diagnostics.idempotency_conflict'));
        }

        return ['report' => $existing, 'replay' => true];
    }

    private function enforceRateLimits(Request $request, int $actualUserId, ?int $companyId): void
    {
        $buckets = [
            ['key' => 'bug_report:ip:'.(string) $request->ip(), 'max' => 20, 'decay' => 600],
            ['key' => 'bug_report:actor:'.$actualUserId, 'max' => 10, 'decay' => 600],
        ];
        if ($companyId !== null) {
            $buckets[] = ['key' => 'bug_report:company:'.$companyId, 'max' => 25, 'decay' => 600];
        }

        foreach ($buckets as $bucket) {
            if (RateLimiter::tooManyAttempts($bucket['key'], $bucket['max'])) {
                $retryAfter = max(1, RateLimiter::availableIn($bucket['key']));

                throw new TooManyRequestsHttpException($retryAfter, __('diagnostics.rate_limited'));
            }
        }

        foreach ($buckets as $bucket) {
            RateLimiter::hit($bucket['key'], $bucket['decay']);
        }
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{
     *     idempotency_key: string,
     *     category: string,
     *     note: string,
     *     include_diagnostics: bool,
     *     screen: array{
     *         route_name: ?string,
     *         failed_request_id: ?string,
     *         failed_status: ?int,
     *         page_request_id: ?string,
     *         viewport: ?string,
     *         livewire_failed: ?bool
     *     }
     * }
     */
    private function validateAndNormalize(array $payload): array
    {
        if ($payload === [] || array_is_list($payload)) {
            throw ValidationException::withMessages([
                'payload' => [__('diagnostics.invalid_payload')],
            ]);
        }

        $unknownTop = array_diff(array_map('strval', array_keys($payload)), self::ALLOWED_TOP_KEYS);
        if ($unknownTop !== []) {
            throw ValidationException::withMessages([
                'payload' => [__('diagnostics.unsupported_fields')],
            ]);
        }

        $idempotencyKey = $payload['idempotency_key'] ?? null;
        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages([
                'idempotency_key' => [__('diagnostics.invalid_idempotency_key')],
            ]);
        }
        $idempotencyKey = strtolower(trim($idempotencyKey));

        $category = $payload['category'] ?? null;
        if (! is_string($category) || ! in_array($category, BugReport::CATEGORIES, true)) {
            throw ValidationException::withMessages([
                'category' => [__('diagnostics.invalid_category')],
            ]);
        }

        $includeDiagnostics = $payload['include_diagnostics'] ?? null;
        if (! is_bool($includeDiagnostics)) {
            throw ValidationException::withMessages([
                'include_diagnostics' => [__('diagnostics.invalid_diagnostics_flag')],
            ]);
        }

        $rawNote = $payload['note'] ?? null;
        if (! is_string($rawNote)) {
            throw ValidationException::withMessages([
                'note' => [__('diagnostics.note_required')],
            ]);
        }

        $note = str_replace(["\r\n", "\r"], "\n", trim($rawNote));
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note) === 1) {
            throw ValidationException::withMessages([
                'note' => [__('diagnostics.note_invalid_characters')],
            ]);
        }

        $noteLen = mb_strlen($note, 'UTF-8');
        if ($noteLen < self::MIN_NOTE_LENGTH || $noteLen > self::MAX_NOTE_LENGTH) {
            throw ValidationException::withMessages([
                'note' => [__('diagnostics.note_length')],
            ]);
        }

        $rawScreen = $payload['screen'] ?? null;
        if ($rawScreen !== null) {
            if (! is_array($rawScreen) || ($rawScreen !== [] && array_is_list($rawScreen))) {
                throw ValidationException::withMessages([
                    'screen' => [__('diagnostics.invalid_screen_payload')],
                ]);
            }

            $unknownScreen = array_diff(array_map('strval', array_keys($rawScreen)), self::ALLOWED_SCREEN_KEYS);
            if ($unknownScreen !== []) {
                throw ValidationException::withMessages([
                    'screen' => [__('diagnostics.unsupported_fields')],
                ]);
            }

            foreach ($rawScreen as $val) {
                if (is_array($val) || is_object($val)) {
                    throw ValidationException::withMessages([
                        'screen' => [__('diagnostics.invalid_screen_payload')],
                    ]);
                }
            }
        } else {
            $rawScreen = [];
        }

        $routeName = $rawScreen['route_name'] ?? null;
        if ($routeName !== null) {
            if (! is_string($routeName)) {
                throw ValidationException::withMessages(['screen.route_name' => [__('diagnostics.invalid_screen_payload')]]);
            }
            $routeName = trim($routeName);
            if ($routeName === '') {
                $routeName = null;
            } elseif (strlen($routeName) > 120 || preg_match('/^[a-zA-Z0-9._-]+$/D', $routeName) !== 1) {
                throw ValidationException::withMessages(['screen.route_name' => [__('diagnostics.invalid_screen_payload')]]);
            }
        }

        $pageRequestId = $this->validateOptionalRequestId($rawScreen['page_request_id'] ?? null, 'screen.page_request_id');
        $failedRequestId = $this->validateOptionalRequestId($rawScreen['failed_request_id'] ?? null, 'screen.failed_request_id');

        $failedStatus = $rawScreen['failed_status'] ?? null;
        if ($failedStatus !== null) {
            if (! is_int($failedStatus) || $failedStatus < 400 || $failedStatus > 599) {
                throw ValidationException::withMessages(['screen.failed_status' => [__('diagnostics.invalid_screen_payload')]]);
            }
        }

        $viewport = $rawScreen['viewport'] ?? null;
        if ($viewport !== null) {
            if (! is_string($viewport) || ! in_array($viewport, self::ALLOWED_VIEWPORTS, true)) {
                throw ValidationException::withMessages(['screen.viewport' => [__('diagnostics.invalid_screen_payload')]]);
            }
        }

        $livewireFailed = $rawScreen['livewire_failed'] ?? null;
        if ($livewireFailed !== null && ! is_bool($livewireFailed)) {
            throw ValidationException::withMessages(['screen.livewire_failed' => [__('diagnostics.invalid_screen_payload')]]);
        }

        return [
            'idempotency_key' => $idempotencyKey,
            'category' => $category,
            'note' => $note,
            'include_diagnostics' => $includeDiagnostics,
            'screen' => [
                'route_name' => $routeName,
                'failed_request_id' => $failedRequestId,
                'failed_status' => $failedStatus,
                'page_request_id' => $pageRequestId,
                'viewport' => $viewport,
                'livewire_failed' => $livewireFailed,
            ],
        ];
    }

    private function validateOptionalRequestId(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([$field => [__('diagnostics.invalid_request_id')]]);
        }

        $trimmed = trim($value);
        if (! RequestId::isValid($trimmed)) {
            throw ValidationException::withMessages([$field => [__('diagnostics.invalid_request_id')]]);
        }

        return $trimmed;
    }
}
