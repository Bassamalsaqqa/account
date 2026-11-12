<?php

declare(strict_types=1);

namespace App\Support\Mobile\Receipt;

use App\Application\Platform\SupportViewContext;
use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MobileEventReceipt;
use App\Models\User;
use App\Support\Mobile\Contract\CanonicalJsonSerializer;
use App\Support\Mobile\Contract\Exceptions\CanonicalSerializationException;
use App\Support\Mobile\Contract\StrictJsonObject;
use App\Support\Mobile\Contract\StrictJsonParser;
use App\Support\Mobile\Receipt\Exceptions\MobileEventAuthenticationException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventDuplicatePayloadException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventForeignScopeException;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class MobileEventReceiptManager
{
    private const int MAX_DEADLOCK_RETRIES = 5;

    private const int MAX_PAYLOAD_BYTES = 1048576; // 1 MiB

    private const int MAX_TERMINAL_RESULT_BYTES = 65536; // 64 KiB

    /** @var list<int> */
    private const array ALLOWED_VERSIONS = [1, 2, 3];

    /** @var list<string> */
    private const array ALLOWED_REPRESENTATIONS = ['v1_canonical', 'v2_intent', 'v3_offline_event'];

    /** @var array<int, list<string>> */
    private const array VERSION_REPRESENTATIONS = [
        1 => ['v1_canonical'],
        2 => ['v2_intent'],
        3 => ['v3_offline_event'],
    ];

    private const string UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    public function __construct(
        private readonly ?FinancialExecutionAdapterInterface $executor = null
    ) {}

    private function getExecutor(): FinancialExecutionAdapterInterface
    {
        return $this->executor ?? app(FinancialExecutionAdapterInterface::class);
    }

    /**
     * Record a new mobile event receipt atomically with financial operations or return idempotent replay.
     *
     * @param  string  $clientEventId  RFC 4122 UUID v4 client event ID
     * @param  string  $tokenHash  SHA-256 hash of the bearer token
     * @param  int  $eventVersion  Supported event version (1, 2, 3)
     * @param  string  $representation  Supported representation ('v1_canonical', 'v2_intent', 'v3_offline_event')
     * @param  string  $canonicalBytes  Exact original canonical bytes of economic payload
     * @return array{
     *     outcome: string,
     *     status: string,
     *     sales_invoice_id: int|null,
     *     sales_invoice_public_id: string|null,
     *     customer_payment_id: int|null,
     *     customer_payment_public_id: string|null,
     *     command_receipt_id: int|null,
     *     terminal_canonical_result: array<string, mixed>|null,
     *     receipt_id: int
     * }
     */
    public function recordOrReplay(
        int $companyId,
        string $clientEventId,
        string $tokenHash,
        int $eventVersion,
        string $representation,
        string $canonicalBytes,
        bool $requireRootTransaction = false
    ): array {
        $initialLevel = DB::transactionLevel();
        if ($requireRootTransaction && $initialLevel > 0) {
            throw new RuntimeException('Mobile event receipt manager requires root database transaction level.');
        }

        $decodedPayload = $this->validateInputs($clientEventId, $eventVersion, $representation, $canonicalBytes);
        $payloadDigest = hash('sha256', $canonicalBytes);

        $context = app(CompanyContext::class);
        $guard = auth()->guard();
        $priorHadUser = $guard->hasUser();
        $priorGuardUser = $priorHadUser ? $guard->user() : null;

        if (app(SupportViewContext::class)->active()) {
            throw new MobileEventAuthenticationException('Support impersonation is unauthorized on mobile event receipts.');
        }

        $context->clearSessionless();

        try {
            $attempts = 0;
            while (true) {
                $attempts++;
                $context->clearSessionless();

                try {
                    return DB::transaction(function () use (
                        $companyId,
                        $clientEventId,
                        $tokenHash,
                        $eventVersion,
                        $representation,
                        $canonicalBytes,
                        $payloadDigest,
                        $decodedPayload,
                        $context,
                        $guard,
                        $priorHadUser,
                        $priorGuardUser
                    ): array {
                        // 1. Root canonical company lock establishes global consistent lock order
                        /** @var Company|null $company */
                        $company = Company::whereKey($companyId)->lockForUpdate()->first();
                        if ($company === null || $company->status !== 'active') {
                            throw new MobileEventForeignScopeException('Target company not found or inactive.');
                        }

                        // 2. Lock & revalidate bearer token
                        /** @var MobileAccessToken|null $token */
                        $token = MobileAccessToken::where('token_hash', $tokenHash)->lockForUpdate()->first();
                        if ($token === null || ! $token->isValid()) {
                            throw new MobileEventAuthenticationException('The Bearer token has been revoked or expired.');
                        }

                        // 3. Lock & revalidate device status and strict ownership
                        /** @var MobileDevice|null $device */
                        $device = MobileDevice::where('device_id', $token->device_id)->lockForUpdate()->first();
                        if ($device === null || $device->isRevoked() || (int) $device->user_id !== (int) $token->user_id) {
                            throw new MobileEventAuthenticationException('Device has been revoked or does not match token owner.');
                        }

                        // 4. Lock & revalidate user and password revision epoch
                        /** @var User|null $user */
                        $user = User::whereKey($token->user_id)->lockForUpdate()->first();
                        if ($user === null) {
                            throw new MobileEventAuthenticationException('User not found.');
                        }

                        if ($priorHadUser && $priorGuardUser !== null && (int) $priorGuardUser->getAuthIdentifier() !== (int) $user->id) {
                            throw new MobileEventAuthenticationException('Ambient guard user conflicts with authenticated mobile token user.');
                        }

                        $expectedEpoch = 'epoch-'.substr(hash('sha256', (string) $user->password), 0, 16);
                        if (! hash_equals($expectedEpoch, $token->password_epoch)) {
                            $token->update(['revoked_at' => now()]);
                            throw new MobileEventAuthenticationException('User credentials have changed since token establishment.');
                        }

                        // 5. Revalidate active company membership with row lock
                        $membership = DB::table('company_user')
                            ->where('company_id', $companyId)
                            ->where('user_id', $user->id)
                            ->where('status', 'active')
                            ->lockForUpdate()
                            ->first();

                        if ($membership === null) {
                            throw new MobileEventForeignScopeException('User does not possess active authorized membership in the requested company.');
                        }

                        $guard->setUser($user);

                        // Activate sessionless CompanyContext for model tenancy
                        $context->setCompany($company, $user);
                        setPermissionsTeamId($companyId);
                        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
                        $user->unsetRelation('roles')->unsetRelation('permissions');

                        // Row lock role and permission assignments for linearizable security check
                        $teamKey = (string) config('permission.column_names.team_foreign_key', 'company_id');
                        DB::table('model_has_roles')
                            ->where('model_type', User::class)
                            ->where('model_id', $user->id)
                            ->where($teamKey, $companyId)
                            ->lockForUpdate()
                            ->get();
                        DB::table('model_has_permissions')
                            ->where('model_type', User::class)
                            ->where('model_id', $user->id)
                            ->where($teamKey, $companyId)
                            ->lockForUpdate()
                            ->get();

                        // 6. Check existing receipt row under lock
                        /** @var MobileEventReceipt|null $receipt */
                        $receipt = MobileEventReceipt::withoutGlobalScopes()
                            ->where('company_id', $companyId)
                            ->where('client_event_id', $clientEventId)
                            ->lockForUpdate()
                            ->first();

                        if ($receipt !== null) {
                            // SCOPE CHECK FIRST: Actor and device mismatch returns generic error without digest oracle
                            if ((int) $receipt->actor_id !== (int) $user->id || $receipt->device_id !== $device->device_id) {
                                throw new MobileEventForeignScopeException('Event scope does not match authorized actor and device context.');
                            }

                            // Replay authority check (default deny denies replay as well)
                            $this->getExecutor()->authorize($company, $user, $device, 'replay', $decodedPayload);

                            // DIGEST CHECK: Mismatched payload returns 409 Conflict
                            if (! hash_equals($receipt->payload_digest, $payloadDigest)) {
                                throw new MobileEventDuplicatePayloadException('Event retry payload differs from original reviewed request.');
                            }

                            // IMMUTABLE LOSS-ACK REPLAY RETURN: Returns existing terminal canonical result without re-executing
                            return [
                                'outcome' => 'replay',
                                'status' => $receipt->status,
                                'sales_invoice_id' => $receipt->sales_invoice_id,
                                'sales_invoice_public_id' => $receipt->sales_invoice_public_id,
                                'customer_payment_id' => $receipt->customer_payment_id,
                                'customer_payment_public_id' => $receipt->customer_payment_public_id,
                                'command_receipt_id' => $receipt->command_receipt_id,
                                'terminal_canonical_result' => $receipt->terminal_canonical_result,
                                'receipt_id' => $receipt->id,
                            ];
                        }

                        // 7. If receipt does not exist: authorize and execute financial adapter
                        $activeExecutor = $this->getExecutor();
                        $activeExecutor->authorize($company, $user, $device, 'execute', $decodedPayload);

                        $suffix = hash('sha256', $user->id.'|'.$device->device_id.'|'.$clientEventId);
                        $financialResult = $activeExecutor->execute($company, $user, $device, $decodedPayload, $suffix);

                        // 8. Strict entity ownership check: Foreign keys MUST belong to this company
                        $invId = $financialResult['sales_invoice_id'] ?? null;
                        if ($invId !== null && ! DB::table('sales_invoices')->where('id', $invId)->where('company_id', $companyId)->exists()) {
                            throw new RuntimeException('Financial executor returned sales invoice not belonging to target company.');
                        }

                        $payId = $financialResult['customer_payment_id'] ?? null;
                        if ($payId !== null && ! DB::table('customer_payments')->where('id', $payId)->where('company_id', $companyId)->exists()) {
                            throw new RuntimeException('Financial executor returned customer payment not belonging to target company.');
                        }

                        $cmdId = $financialResult['command_receipt_id'] ?? null;
                        if ($cmdId !== null && ! DB::table('command_receipts')->where('id', $cmdId)->where('company_id', $companyId)->exists()) {
                            throw new RuntimeException('Financial executor returned command receipt not belonging to target company.');
                        }

                        // 9. Bounded terminal result validation & credential stripping
                        $terminalResult = $financialResult['terminal_canonical_result'];
                        $encodedTerminal = json_encode($terminalResult, JSON_THROW_ON_ERROR);
                        if (strlen($encodedTerminal) > self::MAX_TERMINAL_RESULT_BYTES) {
                            throw new RuntimeException('Terminal canonical result exceeds 64 KiB bound.');
                        }

                        $lowerTerminal = strtolower($encodedTerminal);
                        foreach (['password', 'token_hash', 'secret', 'authorization', 'bearer'] as $sensitiveKey) {
                            if (str_contains($lowerTerminal, '"'.$sensitiveKey.'"') || str_contains($lowerTerminal, '"'.$sensitiveKey.'_')) {
                                throw new RuntimeException("Terminal canonical result contains forbidden sensitive credential: {$sensitiveKey}");
                            }
                        }

                        // 10. Store receipt record in the exact same atomic transaction
                        /** @var MobileEventReceipt $created */
                        $created = MobileEventReceipt::create([
                            'company_id' => $companyId,
                            'client_event_id' => $clientEventId,
                            'actor_id' => $user->id,
                            'device_id' => $device->device_id,
                            'event_version' => $eventVersion,
                            'representation' => $representation,
                            'payload_digest' => $payloadDigest,
                            'original_canonical_bytes' => $canonicalBytes,
                            'sales_invoice_id' => $invId,
                            'sales_invoice_public_id' => $financialResult['sales_invoice_public_id'] ?? null,
                            'customer_payment_id' => $payId,
                            'customer_payment_public_id' => $financialResult['customer_payment_public_id'] ?? null,
                            'command_receipt_id' => $cmdId,
                            'status' => 'completed',
                            'terminal_canonical_result' => $terminalResult,
                        ]);

                        return [
                            'outcome' => 'created',
                            'status' => $created->status,
                            'sales_invoice_id' => $created->sales_invoice_id,
                            'sales_invoice_public_id' => $created->sales_invoice_public_id,
                            'customer_payment_id' => $created->customer_payment_id,
                            'customer_payment_public_id' => $created->customer_payment_public_id,
                            'command_receipt_id' => $created->command_receipt_id,
                            'terminal_canonical_result' => $created->terminal_canonical_result,
                            'receipt_id' => $created->id,
                        ];
                    });
                } catch (QueryException|PDOException $e) {
                    $isDuplicate = self::isDuplicateConstraintError($e, 'mobile_event_receipts_company_client_event_unique');
                    $isDeadlock = self::isDeadlockError($e);

                    // Nested savepoint retry after deadlock/duplicate is unsafe for outer transaction; fail closed
                    if ($initialLevel > 0) {
                        throw $e;
                    }

                    if (($isDuplicate || $isDeadlock) && $attempts < self::MAX_DEADLOCK_RETRIES) {
                        usleep(random_int(10000, 30000));

                        continue;
                    }

                    throw $e;
                }
            }
        } finally {
            $context->clearSessionless();
            setPermissionsTeamId(null);
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);

            if ($priorHadUser && $priorGuardUser !== null) {
                $guard->setUser($priorGuardUser);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }

    /**
     * Read-only lookup of an existing receipt enforcing token authentication, tenant, and actor/device scope.
     *
     * @return array{
     *     outcome: string,
     *     status: string,
     *     sales_invoice_id: int|null,
     *     sales_invoice_public_id: string|null,
     *     customer_payment_id: int|null,
     *     customer_payment_public_id: string|null,
     *     command_receipt_id: int|null,
     *     terminal_canonical_result: array<string, mixed>|null,
     *     receipt_id: int
     * }|null
     */
    public function lookup(
        int $companyId,
        string $clientEventId,
        string $tokenHash,
        bool $requireRootTransaction = false
    ): ?array {
        if ($requireRootTransaction && DB::transactionLevel() > 0) {
            throw new RuntimeException('Mobile event receipt lookup requires root database transaction level.');
        }

        if (! preg_match(self::UUID_REGEX, $clientEventId)) {
            throw new InvalidArgumentException('Client event ID must be a valid UUID string.');
        }

        $context = app(CompanyContext::class);
        $guard = auth()->guard();
        $priorHadUser = $guard->hasUser();
        $priorGuardUser = $priorHadUser ? $guard->user() : null;

        if (app(SupportViewContext::class)->active()) {
            throw new MobileEventAuthenticationException('Support impersonation is unauthorized on mobile event receipts.');
        }

        $context->clearSessionless();

        try {
            return DB::transaction(function () use (
                $companyId,
                $clientEventId,
                $tokenHash,
                $context,
                $priorHadUser,
                $priorGuardUser
            ): ?array {
                /** @var Company|null $company */
                $company = Company::whereKey($companyId)->lockForUpdate()->first();
                if ($company === null || $company->status !== 'active') {
                    throw new MobileEventForeignScopeException('Target company not found or inactive.');
                }

                /** @var MobileAccessToken|null $token */
                $token = MobileAccessToken::where('token_hash', $tokenHash)->lockForUpdate()->first();
                if ($token === null || ! $token->isValid()) {
                    throw new MobileEventAuthenticationException('The Bearer token has been revoked or expired.');
                }

                /** @var MobileDevice|null $device */
                $device = MobileDevice::where('device_id', $token->device_id)->lockForUpdate()->first();
                if ($device === null || $device->isRevoked() || (int) $device->user_id !== (int) $token->user_id) {
                    throw new MobileEventAuthenticationException('Device has been revoked or does not match token owner.');
                }

                /** @var User|null $user */
                $user = User::whereKey($token->user_id)->lockForUpdate()->first();
                if ($user === null) {
                    throw new MobileEventAuthenticationException('User not found.');
                }

                if ($priorHadUser && $priorGuardUser !== null && (int) $priorGuardUser->getAuthIdentifier() !== (int) $user->id) {
                    throw new MobileEventAuthenticationException('Ambient guard user conflicts with authenticated mobile token user.');
                }

                $expectedEpoch = 'epoch-'.substr(hash('sha256', (string) $user->password), 0, 16);
                if (! hash_equals($expectedEpoch, $token->password_epoch)) {
                    $token->update(['revoked_at' => now()]);
                    throw new MobileEventAuthenticationException('User credentials have changed since token establishment.');
                }

                $membership = DB::table('company_user')
                    ->where('company_id', $companyId)
                    ->where('user_id', $user->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if ($membership === null) {
                    throw new MobileEventForeignScopeException('User does not possess active authorized membership in the requested company.');
                }

                $context->setCompany($company, $user);
                setPermissionsTeamId($companyId);
                app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
                $user->unsetRelation('roles')->unsetRelation('permissions');

                // Check lookup authority through adapter (default deny denies lookup)
                $this->getExecutor()->authorize($company, $user, $device, 'lookup', null);

                /** @var MobileEventReceipt|null $receipt */
                $receipt = MobileEventReceipt::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('client_event_id', $clientEventId)
                    ->first();

                if ($receipt === null) {
                    return null;
                }

                if ((int) $receipt->actor_id !== (int) $user->id || $receipt->device_id !== $device->device_id) {
                    throw new MobileEventForeignScopeException('Event scope does not match authorized actor and device context.');
                }

                return [
                    'outcome' => 'lookup',
                    'status' => $receipt->status,
                    'sales_invoice_id' => $receipt->sales_invoice_id,
                    'sales_invoice_public_id' => $receipt->sales_invoice_public_id,
                    'customer_payment_id' => $receipt->customer_payment_id,
                    'customer_payment_public_id' => $receipt->customer_payment_public_id,
                    'command_receipt_id' => $receipt->command_receipt_id,
                    'terminal_canonical_result' => $receipt->terminal_canonical_result,
                    'receipt_id' => $receipt->id,
                ];
            });
        } finally {
            $context->clearSessionless();
            setPermissionsTeamId(null);
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);

            if ($priorHadUser && $priorGuardUser !== null) {
                $guard->setUser($priorGuardUser);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }

    /**
     * Validate raw JSON economic payload: checks UTF-8, duplicate keys, object shape, and verifies canonical JCS bytes.
     *
     * @return array<string, mixed>
     */
    public function validateEconomicPayload(string $rawJson): array
    {
        if ($rawJson === '' || strlen($rawJson) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('Payload must be non-empty and bounded at 1 MiB.');
        }

        if (! mb_check_encoding($rawJson, 'UTF-8')) {
            throw new InvalidArgumentException('Payload must be valid UTF-8.');
        }

        try {
            // StrictJsonParser enforces duplicate key rejection, no floats, no -0, no surrogates
            $parsed = StrictJsonParser::parse($rawJson);
        } catch (CanonicalSerializationException $e) {
            // Generic parser error: never echo keys/credentials
            throw new InvalidArgumentException('Invalid canonical JSON payload structure.', 0, $e);
        }

        if (! ($parsed instanceof StrictJsonObject)) {
            throw new InvalidArgumentException('Economic payload must be a JSON object.');
        }

        // Enforce byte-for-byte canonical JCS verification before admission
        $canonicalJcs = CanonicalJsonSerializer::serialize($parsed);
        if ($rawJson !== $canonicalJcs) {
            throw new InvalidArgumentException('Payload bytes are not in Small Trader Canonical JSON (RFC 8785) format.');
        }

        $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Economic payload must decode to a JSON object.');
        }

        return $decoded;
    }

    public static function isDuplicateConstraintError(
        Throwable $e,
        string $constraintName = 'mobile_event_receipts_company_client_event_unique'
    ): bool {
        $sqlState = (string) $e->getCode();
        $message = $e->getMessage();
        $errorCode = ($e instanceof QueryException || $e instanceof PDOException) && isset($e->errorInfo[1])
            ? (int) $e->errorInfo[1]
            : null;

        // MariaDB authoritative: SQLSTATE 23000, error code 1062, exact constraint name in message
        if ($sqlState === '23000' && $errorCode === 1062) {
            return str_contains($message, 'mobile_event_receipts_company_client_event_unique')
                || str_contains($message, $constraintName);
        }

        return false;
    }

    public static function isDeadlockError(Throwable $e): bool
    {
        if ($e instanceof DeadlockException) {
            return true;
        }

        $sqlState = (string) $e->getCode();
        $errorCode = ($e instanceof QueryException || $e instanceof PDOException) && isset($e->errorInfo[1])
            ? (int) $e->errorInfo[1]
            : null;

        // MariaDB / InnoDB deadlock: SQLSTATE 40001 or error code 1213
        return $errorCode === 1213 || $sqlState === '40001';
    }

    /**
     * @return array<string, mixed>
     */
    private function validateInputs(
        string $clientEventId,
        int $eventVersion,
        string $representation,
        string $canonicalBytes
    ): array {
        if (! preg_match(self::UUID_REGEX, $clientEventId)) {
            throw new InvalidArgumentException('Client event ID must be a valid UUID string.');
        }

        if (! in_array($eventVersion, self::ALLOWED_VERSIONS, true)) {
            throw new InvalidArgumentException('Unsupported event version.');
        }

        if (! in_array($representation, self::ALLOWED_REPRESENTATIONS, true)) {
            throw new InvalidArgumentException('Unsupported representation format.');
        }

        if (! in_array($representation, self::VERSION_REPRESENTATIONS[$eventVersion], true)) {
            throw new InvalidArgumentException('Representation does not match event version.');
        }

        return $this->validateEconomicPayload($canonicalBytes);
    }
}
