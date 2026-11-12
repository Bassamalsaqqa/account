<?php

declare(strict_types=1);

namespace App\Support\Mobile\Receipt;

use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MobileEventReceipt;
use App\Models\User;
use App\Support\Mobile\Contract\Exceptions\CanonicalSerializationException;
use App\Support\Mobile\Contract\StrictJsonParser;
use App\Support\Mobile\Receipt\Exceptions\MobileEventAuthenticationException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventDuplicatePayloadException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventForeignScopeException;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDOException;
use Throwable;

final class MobileEventReceiptManager
{
    private const int MAX_DEADLOCK_RETRIES = 5;

    public function __construct(
        private readonly ?FinancialExecutionAdapterInterface $defaultExecutor = null
    ) {}

    /**
     * Record a new mobile event receipt atomically with financial operations or return idempotent replay.
     *
     * @param  string  $tokenHash  SHA-256 hash of the bearer token
     * @param  int  $eventVersion  e.g. 1, 2, 3
     * @param  string  $representation  e.g. 'v1_canonical', 'v2_intent', 'v3_offline_event'
     * @param  string  $canonicalBytes  Exact original canonical bytes of economic payload
     * @param  FinancialExecutionAdapterInterface|null  $executor  Optional override for execution
     * @param  list<string>  $requiredPermissions  Canonical permissions required for execution
     * @return array{
     *     outcome: string,
     *     status: string,
     *     sales_invoice_id: int|null,
     *     sales_invoice_public_id: string|null,
     *     customer_payment_id: int|null,
     *     customer_payment_public_id: string|null,
     *     command_receipt_id: int|null,
     *     terminal_canonical_result: array<string, mixed>|null,
     *     receipt_id: int,
     *     receipt: MobileEventReceipt
     * }
     */
    public function recordOrReplay(
        int $companyId,
        string $clientEventId,
        string $tokenHash,
        int $eventVersion,
        string $representation,
        string $canonicalBytes,
        ?FinancialExecutionAdapterInterface $executor = null,
        array $requiredPermissions = []
    ): array {
        $this->validateInputs($clientEventId, $eventVersion, $representation, $canonicalBytes);
        $payloadDigest = hash('sha256', $canonicalBytes);

        $attempts = 0;
        while (true) {
            $attempts++;
            try {
                return DB::transaction(function () use (
                    $companyId,
                    $clientEventId,
                    $tokenHash,
                    $eventVersion,
                    $representation,
                    $canonicalBytes,
                    $payloadDigest,
                    $executor,
                    $requiredPermissions
                ): array {
                    // 1. Root canonical company lock establishes global consistent lock order
                    /** @var Company $company */
                    $company = Company::whereKey($companyId)->lockForUpdate()->firstOrFail();

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

                    $expectedEpoch = 'epoch-'.substr(hash('sha256', (string) $user->password), 0, 16);
                    if (! hash_equals($expectedEpoch, $token->password_epoch)) {
                        $token->update(['revoked_at' => now()]);
                        throw new MobileEventAuthenticationException('User credentials have changed since token establishment.');
                    }

                    // 5. Revalidate active company membership
                    $isMember = DB::table('company_user')
                        ->where('company_id', $companyId)
                        ->where('user_id', $user->id)
                        ->where('status', 'active')
                        ->exists();

                    if (! $isMember) {
                        throw new MobileEventForeignScopeException('User does not possess active authorized membership in the requested company.');
                    }

                    // Activate sessionless CompanyContext for model tenancy
                    $companyContext = app(CompanyContext::class);
                    $companyContext->setCompany($company, $user);

                    // 6. Current grants revalidation under Spatie team ID
                    setPermissionsTeamId($companyId);
                    $user->unsetRelation('roles')->unsetRelation('permissions');
                    foreach ($requiredPermissions as $permission) {
                        if (! $user->hasPermissionTo($permission)) {
                            throw new AuthorizationException("Missing required financial permission [{$permission}].");
                        }
                    }

                    // 7. Check existing receipt row under lock
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
                            'receipt' => $receipt,
                        ];
                    }

                    // 8. If receipt does not exist: execute financial adapter
                    $activeExecutor = $executor ?? $this->defaultExecutor ?? app(FinancialExecutionAdapterInterface::class);
                    $suffix = hash('sha256', $user->id.'|'.$device->device_id.'|'.$clientEventId);

                    $decodedPayload = json_decode($canonicalBytes, true, 512, JSON_THROW_ON_ERROR);
                    if (! is_array($decodedPayload)) {
                        throw new InvalidArgumentException('Economic payload must decode to a JSON object or array.');
                    }

                    $financialResult = $activeExecutor->execute($company, $user, $device, $decodedPayload, $suffix);

                    // 9. Store receipt record in the exact same atomic transaction
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
                        'sales_invoice_id' => $financialResult['sales_invoice_id'] ?? null,
                        'sales_invoice_public_id' => $financialResult['sales_invoice_public_id'] ?? null,
                        'customer_payment_id' => $financialResult['customer_payment_id'] ?? null,
                        'customer_payment_public_id' => $financialResult['customer_payment_public_id'] ?? null,
                        'command_receipt_id' => $financialResult['command_receipt_id'] ?? null,
                        'status' => 'completed',
                        'terminal_canonical_result' => $financialResult['terminal_canonical_result'],
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
                        'receipt' => $created,
                    ];
                });
            } catch (QueryException|PDOException $e) {
                // Catch ONLY specific duplicate constraint error or InnoDB deadlock
                $isDuplicate = self::isDuplicateConstraintError($e, 'mobile_event_receipts_company_client_event_unique');
                $isDeadlock = self::isDeadlockError($e);

                if (($isDuplicate || $isDeadlock) && $attempts < self::MAX_DEADLOCK_RETRIES) {
                    // Backoff with jitter before bounded retry
                    usleep(random_int(10000, 30000));

                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Read-only lookup of an existing receipt enforcing tenant and actor/device scope.
     */
    public function lookup(int $companyId, string $clientEventId, int $actorId, string $deviceId): ?MobileEventReceipt
    {
        /** @var MobileEventReceipt|null $receipt */
        $receipt = MobileEventReceipt::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('client_event_id', $clientEventId)
            ->first();

        if ($receipt === null) {
            return null;
        }

        if ((int) $receipt->actor_id !== $actorId || $receipt->device_id !== $deviceId) {
            throw new MobileEventForeignScopeException('Event scope does not match authorized actor and device context.');
        }

        return $receipt;
    }

    /**
     * Validate raw JSON economic payload: checks UTF-8, duplicate keys, and decimal lexemes without float normalization.
     *
     * @return array<string, mixed>
     */
    public function validateEconomicPayload(string $rawJson): array
    {
        if (strlen($rawJson) > 1048576) {
            throw new InvalidArgumentException('Economic payload exceeds 1 MiB limit.');
        }

        if (! mb_check_encoding($rawJson, 'UTF-8')) {
            throw new InvalidArgumentException('Payload must be valid UTF-8.');
        }

        try {
            // StrictJsonParser enforces duplicate key rejection and validates decimal lexemes
            StrictJsonParser::parse($rawJson);
        } catch (CanonicalSerializationException $e) {
            throw new InvalidArgumentException('Invalid JSON payload structure: '.$e->getMessage(), 0, $e);
        }

        $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Economic payload must be a JSON object or array.');
        }

        return $decoded;
    }

    public static function isDuplicateConstraintError(Throwable $e, string $constraintName): bool
    {
        $sqlState = (string) $e->getCode();
        $message = $e->getMessage();
        $errorCode = ($e instanceof QueryException || $e instanceof PDOException) && isset($e->errorInfo[1])
            ? $e->errorInfo[1]
            : null;

        if ($sqlState === '23000' && ($errorCode === 1062 || str_contains($message, '1062'))) {
            return str_contains($message, $constraintName) || str_contains($message, 'Duplicate entry');
        }

        if ($sqlState === '23000' && str_contains($message, 'UNIQUE constraint failed')) {
            return str_contains($message, $constraintName) || str_contains($message, 'mobile_event_receipts');
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
            ? $e->errorInfo[1]
            : null;

        return $errorCode === 1213 || $sqlState === '40001' || str_contains($e->getMessage(), 'Deadlock found') || str_contains($e->getMessage(), '1213');
    }

    private function validateInputs(string $clientEventId, int $eventVersion, string $representation, string $canonicalBytes): void
    {
        if ($clientEventId === '' || strlen($clientEventId) > 64 || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $clientEventId)) {
            throw new InvalidArgumentException('Client event ID must be a non-empty alphanumeric string up to 64 characters.');
        }

        if ($eventVersion < 1) {
            throw new InvalidArgumentException('Event version must be a strictly positive integer.');
        }

        if ($representation === '' || strlen($representation) > 64) {
            throw new InvalidArgumentException('Representation must be a non-empty string up to 64 characters.');
        }

        if ($canonicalBytes === '' || strlen($canonicalBytes) > 1048576) {
            throw new InvalidArgumentException('Canonical payload bytes must be non-empty and bounded at 1 MiB.');
        }

        if (! mb_check_encoding($canonicalBytes, 'UTF-8')) {
            throw new InvalidArgumentException('Canonical payload bytes must be valid UTF-8.');
        }
    }
}
