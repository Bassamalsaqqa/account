<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileAuthChallenge;
use App\Models\MobileDevice;
use App\Models\User;
use App\Support\Mobile\WireResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

final class AuthController extends Controller
{
    /**
     * POST /api/mobile/v1/auth/challenge
     */
    public function challenge(Request $request): JsonResponse
    {
        $payload = $this->validateChallengeRequest($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $ip = (string) $request->ip();
        $rateKey = "mobile_challenge:{$ip}";
        $max = (int) config('mobile.rate_limits.challenges_per_minute', 30);
        if (RateLimiter::tooManyAttempts($rateKey, $max)) {
            $retryAfter = RateLimiter::availableIn($rateKey);

            return MobileApiBoundary::buildErrorResponse(
                code: 'TOO_MANY_REQUESTS',
                message: "Challenge rate limit exceeded. Please retry after {$retryAfter} seconds.",
                status: 429,
                headers: ['Retry-After' => (string) $retryAfter]
            );
        }
        RateLimiter::hit($rateKey, 60);

        $challengeId = (string) Str::uuid();
        $nonce = bin2hex(random_bytes(32)); // 64 hex characters (within 32..128)
        $ttl = (int) config('mobile.challenge_ttl_seconds', 300);
        $expiresAt = now()->addSeconds($ttl);

        MobileAuthChallenge::create([
            'challenge_id' => $challengeId,
            'device_id' => $payload['device_id'],
            'nonce_hash' => hash('sha256', $nonce),
            'app_version' => $payload['app_version'],
            'platform' => $payload['platform'],
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'challenge_id' => $challengeId,
            'nonce' => $nonce,
            'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * POST /api/mobile/v1/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $payload = $this->validateLoginRequest($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $ip = (string) $request->ip();
        $ipKey = "mobile_login_ip:{$ip}";
        $actorKey = 'mobile_login_actor:'.hash('sha256', mb_strtolower(trim($payload['email'])));

        $maxIp = (int) config('mobile.rate_limits.login_attempts_per_minute', 10);
        if (RateLimiter::tooManyAttempts($ipKey, $maxIp) || RateLimiter::tooManyAttempts($actorKey, 5)) {
            $retryAfter = max(RateLimiter::availableIn($ipKey), RateLimiter::availableIn($actorKey));

            return MobileApiBoundary::buildErrorResponse(
                code: 'TOO_MANY_REQUESTS',
                message: "Login rate limit exceeded. Please retry after {$retryAfter} seconds.",
                status: 429,
                headers: ['Retry-After' => (string) $retryAfter]
            );
        }

        // 1. Challenge Verification
        /** @var MobileAuthChallenge|null $challenge */
        $challenge = MobileAuthChallenge::where('challenge_id', $payload['challenge_id'])->first();
        if ($challenge === null
            || $challenge->device_id !== $payload['device_id']
            || $challenge->isExpired()
            || $challenge->isConsumed()
            || ! $challenge->verifyNonce($payload['nonce'])) {
            RateLimiter::hit($ipKey, 60);
            RateLimiter::hit($actorKey, 60);

            return MobileApiBoundary::buildErrorResponse(
                code: 'INVALID_CREDENTIALS',
                message: 'Invalid credentials or challenge expired.',
                status: 401
            );
        }

        // 3. Atomically consume the challenge (single-use)
        if (! $challenge->consume()) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'INVALID_CREDENTIALS',
                message: 'Invalid credentials or challenge expired.',
                status: 401
            );
        }

        return DB::transaction(function () use ($payload, $challenge, $ipKey, $actorKey): JsonResponse {
            // Lock order: user, device, token. Never issue against a stale credential revision.
            // 4. Verify User and Password
            /** @var User|null $user */
            $user = User::where('email', $payload['email'])->lockForUpdate()->first();
            if ($user === null || ! Hash::check($payload['password'], (string) $user->password)) {
                RateLimiter::hit($ipKey, 60);
                RateLimiter::hit($actorKey, 60);

                return MobileApiBoundary::buildErrorResponse(
                    code: 'INVALID_CREDENTIALS',
                    message: 'Invalid credentials or challenge expired.',
                    status: 401
                );
            }

            // Resolve candidate active company IDs for this user
            /** @var list<int> $candidateCompanyIds */
            $candidateCompanyIds = DB::table('company_user')
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->pluck('company_id')
                ->map(fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            // 1. Lock active Company rows in strictly ascending ID order (tenant boundary)
            $activeCompanies = Company::whereIn('id', $candidateCompanyIds)
                ->where('status', 'active')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            // 2. Lock active CompanyUser membership rows in matching company ID order
            if ($activeCompanies->isNotEmpty()) {
                DB::table('company_user')
                    ->where('user_id', $user->id)
                    ->whereIn('company_id', $activeCompanies->pluck('id'))
                    ->where('status', 'active')
                    ->orderBy('company_id', 'asc')
                    ->lockForUpdate()
                    ->get();
            }

            if ($activeCompanies->isEmpty()) {
                RateLimiter::hit($ipKey, 60);
                RateLimiter::hit($actorKey, 60);

                return MobileApiBoundary::buildErrorResponse(
                    code: 'INVALID_CREDENTIALS',
                    message: 'Invalid credentials or challenge expired.',
                    status: 401
                );
            }

            $device = MobileDevice::where('device_id', $payload['device_id'])->lockForUpdate()->first();
            if ($device !== null && ($device->isRevoked() || (int) $device->user_id !== (int) $user->id)) {
                RateLimiter::hit($actorKey, 60);

                return MobileApiBoundary::buildErrorResponse(code: 'INVALID_CREDENTIALS', message: 'Invalid credentials or challenge expired.', status: 401);
            }
            if ($challenge->user_id !== null && (int) $challenge->user_id !== (int) $user->id) {
                return MobileApiBoundary::buildErrorResponse(code: 'INVALID_CREDENTIALS', message: 'Invalid credentials or challenge expired.', status: 401);
            }

            // 5. Check Fortify Two-Factor Authentication
            if ($user->hasEnabledTwoFactorAuthentication()) {
                $mfaCode = $payload['mfa_code'] ?? null;
                $recoveryCode = $payload['recovery_code'] ?? null;

                if ($mfaCode === null && $recoveryCode === null) {
                    $mfaResponse = response()->json([
                        'status' => 'MFA_REQUIRED',
                        'retry' => 'fresh_challenge',
                    ], 200, [
                        'Content-Type' => 'application/json',
                        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                        'Pragma' => 'no-cache',
                    ]);
                    if (! WireResponse::accepts(json_decode((string) $mfaResponse->getContent()), 'auth-login-response')) {
                        throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(code: 'CONTRACT_INCOMPATIBLE', message: 'Existing data cannot be represented by Mobile V1.', status: 409));
                    }

                    return $mfaResponse;
                }

                if ($mfaCode !== null) {
                    $secret = decrypt($user->two_factor_secret);
                    /** @var TwoFactorAuthenticationProvider $provider */
                    $provider = app(TwoFactorAuthenticationProvider::class);
                    if (! $provider->verify($secret, $mfaCode)) {
                        RateLimiter::hit($ipKey, 60);
                        RateLimiter::hit($actorKey, 60);

                        return MobileApiBoundary::buildErrorResponse(
                            code: 'INVALID_CREDENTIALS',
                            message: 'Invalid credentials or challenge expired.',
                            status: 401
                        );
                    }
                } elseif ($recoveryCode !== null) {
                    $codes = $user->recoveryCodes();
                    $matched = collect($codes)->first(fn ($c) => hash_equals((string) $c, $recoveryCode));

                    if (! $matched) {
                        RateLimiter::hit($ipKey, 60);
                        RateLimiter::hit($actorKey, 60);

                        return MobileApiBoundary::buildErrorResponse(
                            code: 'INVALID_CREDENTIALS',
                            message: 'Invalid credentials or challenge expired.',
                            status: 401
                        );
                    }

                    // Atomically replace the used single-use recovery code
                    $user->replaceRecoveryCode($matched);
                }
            }

            // Authentication Successful: clear rate limit counters
            // Success must not erase concurrent failures from other requests.

            $attributes = [
                'device_name' => $payload['device_name'],
                'platform' => $challenge->platform,
                'app_version' => $challenge->app_version,
            ];
            if ($device === null) {
                // A concurrent different actor cannot claim this UUID via an upsert.
                MobileDevice::create($attributes + ['device_id' => $payload['device_id'], 'user_id' => $user->id]);
            } else {
                $device->update($attributes);
            }

            // 7. Generate Token
            $rawToken = 'st_m_'.bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $ttl = (int) config('mobile.token_ttl_seconds', 604800);
            $expiresAt = now()->addSeconds($ttl);
            $passwordEpoch = 'epoch-'.substr(hash('sha256', (string) $user->password), 0, 16);

            /** @var Company|null $defaultCompany */
            $defaultCompany = $user->lastActiveCompany;
            if ($defaultCompany === null || ! $activeCompanies->contains('id', $defaultCompany->id)) {
                $defaultCompany = $activeCompanies->first();
            }

            MobileAccessToken::create([
                'token_hash' => $tokenHash,
                'device_id' => $payload['device_id'],
                'user_id' => $user->id,
                'company_id' => null, // Staff + device token; tenant selected and checked per request.
                'password_epoch' => $passwordEpoch,
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 8. Determine Actor Role
            setPermissionsTeamId($defaultCompany->id);
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $roleName = $user->roles()->where('roles.company_id', $defaultCompany->id)->value('name');

            $role = match (strtolower((string) $roleName)) {
                'owner', 'admin', 'administrator' => 'admin',
                'manager' => 'manager',
                default => 'cashier',
            };

            // 9. Map Companies List
            $companies = $activeCompanies->map(function (Company $c) use ($defaultCompany) {
                return [
                    'id' => $c->public_id,
                    'name' => $c->displayName(),
                    'base_currency' => $c->base_currency_code,
                    'is_default' => (int) $c->id === (int) $defaultCompany->id,
                ];
            })->values()->all();

            // Ensure at least one is default
            if (! collect($companies)->contains('is_default', true) && count($companies) > 0) {
                $companies[0]['is_default'] = true;
            }

            $response = response()->json([
                'token' => $rawToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
                'actor' => [
                    'id' => $user->public_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $role,
                    'password_epoch' => $passwordEpoch,
                ],
                'companies' => $companies,
            ], 200, [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'Pragma' => 'no-cache',
            ]);
            if (! WireResponse::accepts(json_decode((string) $response->getContent()), 'auth-login-response')) {
                throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(code: 'CONTRACT_INCOMPATIBLE', message: 'Existing data cannot be represented by Mobile V1.', status: 409));
            }

            return $response;
        });
    }

    /**
     * POST /api/mobile/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $raw = (string) $request->getContent();
        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['device_id']) || ! is_string($data['device_id'])
            || preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $data['device_id']) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'The given data failed validation.',
                status: 422,
                details: ['device_id' => ['The device_id must be a valid UUID.']]
            );
        }

        // Schema additionalProperties: false
        if (count($data) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Unexpected additional fields in request payload.',
                status: 422
            );
        }

        /** @var MobileAccessToken $token */
        $token = $request->attributes->get('mobile_token');
        if ($token->device_id !== strtolower($data['device_id'])) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'The device ID does not match the authenticated session.',
                status: 401
            );
        }

        $token->revoke();

        return response()->json([
            'status' => 'logged_out',
            'revoked_at' => now()->format('Y-m-d\TH:i:s\Z'),
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Validate Challenge Request Payload.
     *
     * @return array{device_id: string, app_version: string, platform: string}|JsonResponse
     */
    private function validateChallengeRequest(Request $request): array|JsonResponse
    {
        $raw = (string) $request->getContent();
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'INVALID_JSON',
                message: 'Invalid JSON payload format.',
                status: 422
            );
        }

        $allowedKeys = ['device_id', 'app_version', 'platform'];
        if (array_diff(array_keys($data), $allowedKeys) !== []) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Unexpected additional fields in request payload.',
                status: 422
            );
        }

        $errors = [];
        $deviceId = $data['device_id'] ?? null;
        if (! is_string($deviceId) || preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $deviceId) !== 1) {
            $errors['device_id'] = ['The device_id must be a valid UUID.'];
        }

        $appVersion = $data['app_version'] ?? null;
        if (! is_string($appVersion) || strlen($appVersion) < 1 || strlen($appVersion) > 64) {
            $errors['app_version'] = ['The app_version must be between 1 and 64 characters.'];
        }

        $platform = $data['platform'] ?? null;
        if (! is_string($platform) || ! in_array($platform, ['android', 'ios'], true)) {
            $errors['platform'] = ['The platform must be either android or ios.'];
        }

        if ($errors !== []) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'The given data failed validation.',
                status: 422,
                details: $errors
            );
        }

        return [
            'device_id' => strtolower((string) $deviceId),
            'app_version' => (string) $appVersion,
            'platform' => (string) $platform,
        ];
    }

    /**
     * Validate Login Request Payload.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function validateLoginRequest(Request $request): array|JsonResponse
    {
        $raw = (string) $request->getContent();
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'INVALID_JSON',
                message: 'Invalid JSON payload format.',
                status: 422
            );
        }

        $allowedKeys = [
            'challenge_id',
            'nonce',
            'device_id',
            'device_name',
            'email',
            'password',
            'mfa_code',
            'recovery_code',
        ];
        if (array_diff(array_keys($data), $allowedKeys) !== []) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Unexpected additional fields in request payload.',
                status: 422
            );
        }

        $errors = [];

        // Required fields
        $challengeId = $data['challenge_id'] ?? null;
        if (! is_string($challengeId) || preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $challengeId) !== 1) {
            $errors['challenge_id'] = ['The challenge_id must be a valid UUID.'];
        }

        $nonce = $data['nonce'] ?? null;
        if (! is_string($nonce) || strlen($nonce) < 32 || strlen($nonce) > 128) {
            $errors['nonce'] = ['The nonce must be between 32 and 128 characters.'];
        }

        $deviceId = $data['device_id'] ?? null;
        if (! is_string($deviceId) || preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $deviceId) !== 1) {
            $errors['device_id'] = ['The device_id must be a valid UUID.'];
        }

        $deviceName = $data['device_name'] ?? null;
        if (! is_string($deviceName) || strlen($deviceName) < 1 || strlen($deviceName) > 100) {
            $errors['device_name'] = ['The device_name must be between 1 and 100 characters.'];
        }

        $email = $data['email'] ?? null;
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            $errors['email'] = ['The email must be a valid email address.'];
        }

        $password = $data['password'] ?? null;
        if (! is_string($password) || strlen($password) < 1 || strlen($password) > 256) {
            $errors['password'] = ['The password must be between 1 and 256 characters.'];
        }

        // Optional MFA / Recovery Code mutual exclusion
        $mfaCode = array_key_exists('mfa_code', $data) ? $data['mfa_code'] : null;
        $recoveryCode = array_key_exists('recovery_code', $data) ? $data['recovery_code'] : null;

        if ($mfaCode !== null) {
            if (! is_string($mfaCode) || preg_match('/^[0-9]{6}$/', $mfaCode) !== 1) {
                $errors['mfa_code'] = ['The mfa_code must be a 6-digit numeric string.'];
            }
        }

        if ($recoveryCode !== null) {
            if (! is_string($recoveryCode) || preg_match('/^[a-zA-Z0-9]{8,24}(-[a-zA-Z0-9]{8,24})?$/', $recoveryCode) !== 1) {
                $errors['recovery_code'] = ['The recovery_code format is invalid.'];
            }
        }

        // Mutual exclusion per contract schema
        if (is_string($mfaCode) && is_string($recoveryCode)) {
            $errors['mfa_code'] = ['mfa_code and recovery_code cannot both be supplied.'];
            $errors['recovery_code'] = ['recovery_code and mfa_code cannot both be supplied.'];
        }

        if ($errors !== []) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'The given data failed validation.',
                status: 422,
                details: $errors
            );
        }

        return [
            'challenge_id' => (string) $challengeId,
            'nonce' => (string) $nonce,
            'device_id' => strtolower((string) $deviceId),
            'device_name' => (string) $deviceName,
            'email' => (string) $email,
            'password' => (string) $password,
            'mfa_code' => is_string($mfaCode) ? $mfaCode : null,
            'recovery_code' => is_string($recoveryCode) ? $recoveryCode : null,
        ];
    }
}
