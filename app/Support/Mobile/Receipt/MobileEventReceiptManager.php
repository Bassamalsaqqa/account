<?php

declare(strict_types=1);

namespace App\Support\Mobile\Receipt;

use App\Actions\Sales\ExecuteImmediateOnlineCashSaleAction;
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
use App\Support\Mobile\Receipt\Exceptions\IllegalStateException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventAuthenticationException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventDuplicatePayloadException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventForeignScopeException;
use App\Support\Mobile\Receipt\Exceptions\SecurityException;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;
use WeakMap;

final class MobileEventReceiptManager
{
    /** @var WeakMap<NativeFinancialExecutionCapability, array<string, mixed>>|null */
    private static ?WeakMap $capabilityRegistry = null;

    private static ?string $currentInvocationScopeId = null;

    private const int MAX_DEADLOCK_RETRIES = 5;

    private const int MAX_PAYLOAD_BYTES = 1048576; // 1 MiB

    private const int MAX_TERMINAL_RESULT_BYTES = 65536; // 64 KiB

    /** @var list<int> */
    private const array SUPPORTED_EVENT_VERSIONS = [2];

    /** @var list<string> */
    private const array SUPPORTED_REPRESENTATIONS = [
        'v2_intent',
        'v2_online_sale_intent',
        'offline_sale_intake',
        'offline_sale',
    ];

    private const array ALLOWED_TERMINAL_RESULT_KEYS = [
        'status',
        'sales_invoice_id',
        'sales_invoice_public_id',
        'invoice_id',
        'invoice_public_id',
        'invoice_number',
        'customer_payment_id',
        'customer_payment_public_id',
        'payment_id',
        'payment_public_id',
        'payment_receipt_number',
        'command_receipt_id',
        'customer_id',
        'customer_public_id',
        'currency',
        'total_amount',
        'grand_total',
        'paid_amount',
        'amount',
        'amount_paid',
        'change_returned',
        'posted_at',
        'lines_count',
    ];

    private const array FORBIDDEN_CREDENTIAL_SUBSTRINGS = [
        'password',
        'token_hash',
        'secret',
        'authorization',
        'bearer',
        'private_key',
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
     * Enforces root database transaction level unconditionally; nested transactions are rejected before effects.
     *
     * @param  string  $clientEventId  RFC 4122 UUID v4 client event ID
     * @param  string  $tokenHash  SHA-256 hash of the bearer token
     * @param  int  $eventVersion  Supported event version (2; v1 legacy new admission is unsupported)
     * @param  string  $representation  Supported representation ('v2_intent', 'v2_online_sale_intent', etc.)
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
        string $canonicalBytes
    ): array {
        $context = app(CompanyContext::class);
        $priorContext = clone $context;
        $priorTeamId = getPermissionsTeamId();
        $guard = auth()->guard();
        $priorHadUser = $guard->hasUser();
        $priorGuardUser = $priorHadUser ? $guard->user() : null;

        try {
            $context->clearSessionless();

            if (app(SupportViewContext::class)->active()) {
                throw new MobileEventAuthenticationException('Support impersonation is unauthorized on mobile event receipts.');
            }

            // Cheap bounded structural validation
            $decodedPayload = $this->validateInputs($clientEventId, $eventVersion, $representation, $canonicalBytes);
            $payloadDigest = hash('sha256', $canonicalBytes);

            // Stage 1: Initial unlocked token authentication before company existence query (zero oracle)
            $tentativeToken = MobileAccessToken::where('token_hash', $tokenHash)->first();
            if ($tentativeToken === null || ! $tentativeToken->isValid()) {
                throw new MobileEventAuthenticationException('The Bearer token has been revoked or expired.');
            }

            // Root commit guarantee: reject nested transactions before effects
            if (DB::transactionLevel() > 0) {
                throw new RuntimeException('Mobile event receipt manager requires root database transaction level.');
            }

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
                        $priorGuardUser,
                        $tentativeToken
                    ): array {
                        // Tenant root then membership, principal, device and token; bounded mobile paths only.
                        /** @var Company|null $company */
                        $company = Company::whereKey($companyId)->lockForUpdate()->first();
                        if ($company === null || $company->status !== 'active') {
                            throw new MobileEventForeignScopeException('Target company not found or inactive.');
                        }

                        $membership = DB::table('company_user')
                            ->where('company_id', $companyId)
                            ->where('user_id', $tentativeToken->user_id)
                            ->where('status', 'active')
                            ->lockForUpdate()
                            ->first();

                        if ($membership === null) {
                            throw new MobileEventForeignScopeException('User does not possess active authorized membership in the requested company.');
                        }

                        // 1. Lock & revalidate user (principal)
                        /** @var User|null $user */
                        $user = User::whereKey($tentativeToken->user_id)->lockForUpdate()->first();
                        if ($user === null) {
                            throw new MobileEventAuthenticationException('User not found.');
                        }

                        // 2. Lock & revalidate device status and strict ownership
                        /** @var MobileDevice|null $device */
                        $device = MobileDevice::where('device_id', $tentativeToken->device_id)->lockForUpdate()->first();
                        if ($device === null || $device->isRevoked() || (int) $device->user_id !== (int) $tentativeToken->user_id) {
                            throw new MobileEventAuthenticationException('Device has been revoked or does not match token owner.');
                        }

                        // 3. Lock & revalidate bearer token under lock
                        /** @var MobileAccessToken|null $token */
                        $token = MobileAccessToken::where('token_hash', $tokenHash)->lockForUpdate()->first();
                        if ($token === null || ! $token->isValid()) {
                            throw new MobileEventAuthenticationException('The Bearer token has been revoked or expired.');
                        }
                        if ($token->device_id !== $device->device_id || (int) $token->user_id !== (int) $user->id) {
                            throw new MobileEventAuthenticationException('Device has been revoked or does not match token owner.');
                        }
                        if ($token->company_id !== null && (int) $token->company_id !== $companyId) {
                            throw new MobileEventForeignScopeException('Token is bound to a different company.');
                        }

                        $expectedEpoch = 'epoch-'.substr(hash('sha256', (string) $user->password), 0, 16);
                        if (! hash_equals($expectedEpoch, $token->password_epoch)) {
                            throw new MobileEventAuthenticationException('User credentials have changed since token establishment.');
                        }

                        if ($priorHadUser && $priorGuardUser !== null && (int) $priorGuardUser->getAuthIdentifier() !== (int) $user->id) {
                            throw new MobileEventAuthenticationException('Ambient guard user conflicts with authenticated mobile token user.');
                        }

                        $guard->setUser($user);

                        // Activate sessionless CompanyContext for model tenancy
                        $context->setCompany($company, $user);
                        setPermissionsTeamId($companyId);
                        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
                        $user->unsetRelation('roles')->unsetRelation('permissions');

                        // Cooperative row locks on model assignments serialize against concurrent assignment updates.
                        // In addition, lock assigned roles and role_has_permissions definitions and forget registrar cache
                        // for fresh DB linearity.
                        $this->verifyAndLockFreshRolePermissions($companyId, $user);

                        // 6. Verify payload context binding before admission when known context keys are present
                        $this->verifyPayloadContextBinding($decodedPayload, $company, $user, $device, $clientEventId);

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

                        // 8. If receipt does not exist: authorize and execute financial adapter
                        $activeExecutor = $this->getExecutor();
                        $activeExecutor->authorize($company, $user, $device, 'execute', $decodedPayload);

                        $suffix = hash('sha256', $user->id.'|'.$device->device_id.'|'.$clientEventId);

                        $invocationScopeId = bin2hex(random_bytes(16));
                        self::$currentInvocationScopeId = $invocationScopeId;
                        if (self::$capabilityRegistry === null) {
                            self::$capabilityRegistry = new WeakMap;
                        }

                        $cmu = isset($decodedPayload['currency_minor_units']) && is_int($decodedPayload['currency_minor_units'])
                            ? $decodedPayload['currency_minor_units']
                            : 2;

                        $capability = (Closure::bind(
                            fn () => new NativeFinancialExecutionCapability(
                                companyId: (int) $company->id,
                                companyPublicId: (string) $company->public_id,
                                actorId: (int) $user->id,
                                actorPublicId: (string) $user->public_id,
                                deviceId: (string) $device->device_id,
                                tokenPasswordEpoch: (string) $token->password_epoch,
                                clientEventId: (string) $clientEventId,
                                eventHash: (string) $payloadDigest,
                                currencyMinorUnits: $cmu,
                                connectionName: DB::connection()->getName(),
                                rootTransactionLevel: 1,
                                invocationScopeId: $invocationScopeId
                            ),
                            null,
                            NativeFinancialExecutionCapability::class
                        ))();

                        self::$capabilityRegistry[$capability] = [
                            'company_id' => (int) $company->id,
                            'actor_id' => (int) $user->id,
                            'device_id' => (string) $device->device_id,
                            'token_password_epoch' => (string) $token->password_epoch,
                            'client_event_id' => (string) $clientEventId,
                            'event_hash' => (string) $payloadDigest,
                            'currency_minor_units' => $cmu,
                            'connection_name' => DB::connection()->getName(),
                            'root_transaction_level' => 1,
                            'invocation_scope_id' => $invocationScopeId,
                            'active' => true,
                            'consumed' => false,
                            'composite_completed' => false,
                            'completed_invoice_id' => null,
                            'completed_payment_id' => null,
                        ];

                        try {
                            $financialResult = $activeExecutor->execute($company, $user, $device, $decodedPayload, $suffix, $capability);
                        } finally {
                            if (self::$capabilityRegistry->offsetExists($capability)) {
                                $capRec = self::$capabilityRegistry[$capability];
                                $capRec['active'] = false;
                                self::$capabilityRegistry[$capability] = $capRec;
                            }
                            self::$currentInvocationScopeId = null;
                        }

                        // 9. Financial execution validation: require capability consumption & closed composite completion
                        $capRec = self::getCapabilityRecord($capability);
                        $invId = $financialResult['sales_invoice_id'] ?? null;
                        $payId = $financialResult['customer_payment_id'] ?? null;

                        if ($invId !== null || $payId !== null) {
                            if ($representation !== 'v2_online_sale_intent') {
                                throw new SecurityException("Unsupported financial representation [{$representation}] produced financial entities.");
                            }

                            if ($invId === null || $payId === null) {
                                throw new SecurityException('Partial financial execution (invoice-only or payment-only) is prohibited.');
                            }

                            if (! ($capRec['consumed'] ?? false)) {
                                throw new SecurityException('Financial execution failed to consume required capability.');
                            }

                            if (! ($capRec['composite_completed'] ?? false)) {
                                throw new SecurityException('Financial execution failed to achieve verified closed composite completion.');
                            }

                            if ((int) $invId !== (int) ($capRec['completed_invoice_id'] ?? 0) ||
                                (int) $payId !== (int) ($capRec['completed_payment_id'] ?? 0)) {
                                throw new SecurityException('Financial entity IDs do not match closed composite completion.');
                            }
                        } elseif ($representation === 'v2_online_sale_intent') {
                            throw new SecurityException('Online cash sale execution failed to produce required financial composite.');
                        }

                        // 10. Strict entity ownership & public ID derivation from locked canonical entities
                        $canonicalInvPublicId = null;
                        $invRow = null;
                        if ($invId !== null) {
                            $invRow = DB::table('sales_invoices')
                                ->where('id', $invId)
                                ->where('company_id', $companyId)
                                ->lockForUpdate()
                                ->first();
                            if ($invRow === null) {
                                throw new RuntimeException('Financial executor returned sales invoice not belonging to target company.');
                            }
                            $canonicalInvPublicId = (string) $invRow->public_id;
                            if (isset($financialResult['sales_invoice_public_id'])) {
                                if (! hash_equals($canonicalInvPublicId, (string) $financialResult['sales_invoice_public_id'])) {
                                    throw new RuntimeException('Sales invoice public ID mismatch between executor and database row.');
                                }
                            }
                        }

                        $payId = $financialResult['customer_payment_id'] ?? null;
                        $canonicalPayPublicId = null;
                        $payRow = null;
                        if ($payId !== null) {
                            $payRow = DB::table('customer_payments')
                                ->where('id', $payId)
                                ->where('company_id', $companyId)
                                ->lockForUpdate()
                                ->first();
                            if ($payRow === null) {
                                throw new RuntimeException('Financial executor returned customer payment not belonging to target company.');
                            }
                            $canonicalPayPublicId = (string) $payRow->public_id;
                            if (isset($financialResult['customer_payment_public_id'])) {
                                if (! hash_equals($canonicalPayPublicId, (string) $financialResult['customer_payment_public_id'])) {
                                    throw new RuntimeException('Customer payment public ID mismatch between executor and database row.');
                                }
                            }
                        }

                        // Relationship verification between invoice and customer payment
                        /** @phpstan-ignore notIdentical.alwaysTrue */
                        if ($invRow !== null && $payRow !== null) {
                            if ((int) $invRow->customer_id !== (int) $payRow->customer_id) {
                                throw new RuntimeException('Customer mismatch between sales invoice and customer payment.');
                            }
                            if ($invRow->currency_code !== $payRow->currency_code) {
                                throw new RuntimeException('Currency mismatch between sales invoice and customer payment.');
                            }
                            $hasAllocation = DB::table('customer_payment_allocations')
                                ->where('customer_payment_id', $payId)
                                ->where('sales_invoice_id', $invId)
                                ->exists();
                            if (! $hasAllocation) {
                                throw new RuntimeException('Missing payment allocation between customer payment and sales invoice.');
                            }
                        }

                        $cmdId = $financialResult['command_receipt_id'] ?? null;
                        if ($cmdId !== null) {
                            $cmdRow = DB::table('command_receipts')
                                ->where('id', $cmdId)
                                ->where('company_id', $companyId)
                                ->where('user_id', $user->id)
                                ->lockForUpdate()
                                ->first();
                            if ($cmdRow === null) {
                                throw new RuntimeException('Financial executor returned command receipt not belonging to target company or authenticated actor.');
                            }
                        }

                        // 10. Bounded typed allowlisted terminal result validation
                        $terminalResult = $this->buildTerminalCanonicalResult(
                            $financialResult['terminal_canonical_result'],
                            $invId,
                            $canonicalInvPublicId,
                            $payId,
                            $canonicalPayPublicId,
                            $cmdId
                        );

                        // 11. Store receipt record in the exact same atomic transaction
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
                            'sales_invoice_public_id' => $canonicalInvPublicId,
                            'customer_payment_id' => $payId,
                            'customer_payment_public_id' => $canonicalPayPublicId,
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

                    if (($isDuplicate || $isDeadlock) && $attempts < self::MAX_DEADLOCK_RETRIES) {
                        usleep(random_int(10000, 30000));

                        continue;
                    }

                    throw $e;
                }
            }
        } finally {
            $context->clearSessionless();
            $context->restoreSessionless($priorContext);
            setPermissionsTeamId($priorTeamId);
            app(PermissionRegistrar::class)->setPermissionsTeamId($priorTeamId);

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
        string $tokenHash
    ): ?array {
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException('Mobile event receipt manager requires root database transaction level.');
        }

        $context = app(CompanyContext::class);
        $priorContext = clone $context;
        $priorTeamId = getPermissionsTeamId();
        $guard = auth()->guard();
        $priorHadUser = $guard->hasUser();
        $priorGuardUser = $priorHadUser ? $guard->user() : null;

        try {
            $context->clearSessionless();

            if (! preg_match(self::UUID_REGEX, $clientEventId)) {
                throw new InvalidArgumentException('Client event ID must be a valid UUID string.');
            }

            if (app(SupportViewContext::class)->active()) {
                throw new MobileEventAuthenticationException('Support impersonation is unauthorized on mobile event receipts.');
            }

            // Stage 1: Unlocked token authentication before company existence query (zero oracle)
            $tentativeToken = MobileAccessToken::where('token_hash', $tokenHash)->first();
            if ($tentativeToken === null || ! $tentativeToken->isValid()) {
                throw new MobileEventAuthenticationException('The Bearer token has been revoked or expired.');
            }

            return DB::transaction(function () use (
                $companyId,
                $clientEventId,
                $tokenHash,
                $context,
                $guard,
                $priorHadUser,
                $priorGuardUser,
                $tentativeToken
            ): ?array {
                // Tenant root then membership, principal, device and token; bounded mobile paths only.
                /** @var Company|null $company */
                $company = Company::whereKey($companyId)->lockForUpdate()->first();
                if ($company === null || $company->status !== 'active') {
                    throw new MobileEventForeignScopeException('Target company not found or inactive.');
                }

                $membership = DB::table('company_user')
                    ->where('company_id', $companyId)
                    ->where('user_id', $tentativeToken->user_id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if ($membership === null) {
                    throw new MobileEventForeignScopeException('User does not possess active authorized membership in the requested company.');
                }

                /** @var User|null $user */
                $user = User::whereKey($tentativeToken->user_id)->lockForUpdate()->first();
                if ($user === null) {
                    throw new MobileEventAuthenticationException('User not found.');
                }

                /** @var MobileDevice|null $device */
                $device = MobileDevice::where('device_id', $tentativeToken->device_id)->lockForUpdate()->first();
                if ($device === null || $device->isRevoked() || (int) $device->user_id !== (int) $tentativeToken->user_id) {
                    throw new MobileEventAuthenticationException('Device has been revoked or does not match token owner.');
                }

                /** @var MobileAccessToken|null $token */
                $token = MobileAccessToken::where('token_hash', $tokenHash)->lockForUpdate()->first();
                if ($token === null || ! $token->isValid()) {
                    throw new MobileEventAuthenticationException('The Bearer token has been revoked or expired.');
                }
                if ($token->device_id !== $device->device_id || (int) $token->user_id !== (int) $user->id) {
                    throw new MobileEventAuthenticationException('Device has been revoked or does not match token owner.');
                }
                if ($token->company_id !== null && (int) $token->company_id !== $companyId) {
                    throw new MobileEventForeignScopeException('Token is bound to a different company.');
                }

                $expectedEpoch = 'epoch-'.substr(hash('sha256', (string) $user->password), 0, 16);
                if (! hash_equals($expectedEpoch, $token->password_epoch)) {
                    throw new MobileEventAuthenticationException('User credentials have changed since token establishment.');
                }

                if ($priorHadUser && $priorGuardUser !== null && (int) $priorGuardUser->getAuthIdentifier() !== (int) $user->id) {
                    throw new MobileEventAuthenticationException('Ambient guard user conflicts with authenticated mobile token user.');
                }

                $guard->setUser($user);

                $context->setCompany($company, $user);
                setPermissionsTeamId($companyId);
                app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
                $user->unsetRelation('roles')->unsetRelation('permissions');

                // Enforce fresh role definition & permission concurrency locking on lookup path
                $this->verifyAndLockFreshRolePermissions($companyId, $user);

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
            $context->restoreSessionless($priorContext);
            setPermissionsTeamId($priorTeamId);
            app(PermissionRegistrar::class)->setPermissionsTeamId($priorTeamId);

            if ($priorHadUser && $priorGuardUser !== null) {
                $guard->setUser($priorGuardUser);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }

    /**
     * Validate raw JSON economic payload: checks UTF-8, duplicate keys, object shape, and verifies canonical JCS bytes.
     * Note: Strict JCS verification provides syntax canonicality only; it does not confer financial authority or schema authority.
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
     * Fresh role definition and permission table verification under lock.
     * Enforces serial concurrency against concurrent permission or role definition alterations.
     */
    private function verifyAndLockFreshRolePermissions(int $companyId, User $user): void
    {
        $teamKey = (string) config('permission.column_names.team_foreign_key', 'company_id');
        $rolePivotRows = DB::table('model_has_roles')
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

        $assignedRoleIds = $rolePivotRows->pluck('role_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        if (! empty($assignedRoleIds)) {
            sort($assignedRoleIds);
            DB::table('roles')
                ->whereIn('id', $assignedRoleIds)
                ->where('company_id', $companyId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            DB::table('role_has_permissions')
                ->whereIn('role_id', $assignedRoleIds)
                ->orderBy('role_id')
                ->orderBy('permission_id')
                ->lockForUpdate()
                ->get();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function verifyPayloadContextBinding(
        array $payload,
        Company $company,
        User $user,
        MobileDevice $device,
        string $clientEventId
    ): void {
        $target = isset($payload['event']) && is_array($payload['event']) ? $payload['event'] : $payload;

        if (isset($target['client_event_id']) && strcasecmp((string) $target['client_event_id'], $clientEventId) !== 0) {
            throw new MobileEventForeignScopeException('Payload client_event_id does not match event context.');
        }

        if (isset($target['device_id']) && (string) $target['device_id'] !== $device->device_id) {
            throw new MobileEventForeignScopeException('Payload device_id does not match authenticated device.');
        }

        if (isset($target['actor_id'])) {
            $actorMatch = (string) $target['actor_id'] === (string) $user->id
                || (isset($user->public_id) && (string) $target['actor_id'] === (string) $user->public_id);
            if (! $actorMatch) {
                throw new MobileEventForeignScopeException('Payload actor_id does not match authenticated actor.');
            }
        }

        if (isset($target['company_id'])) {
            $companyMatch = (string) $target['company_id'] === (string) $company->id
                || (isset($company->public_id) && (string) $target['company_id'] === (string) $company->public_id);
            if (! $companyMatch) {
                throw new MobileEventForeignScopeException('Payload company_id does not match authenticated company.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $rawTerminal
     * @return array<string, mixed>
     */
    private function buildTerminalCanonicalResult(
        array $rawTerminal,
        ?int $invId,
        ?string $canonicalInvPublicId,
        ?int $payId,
        ?string $canonicalPayPublicId,
        ?int $cmdId
    ): array {
        $encoded = json_encode($rawTerminal, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_TERMINAL_RESULT_BYTES) {
            throw new RuntimeException('Terminal canonical result exceeds 64 KiB bound.');
        }

        foreach (array_keys($rawTerminal) as $key) {
            if (! in_array($key, self::ALLOWED_TERMINAL_RESULT_KEYS, true)) {
                throw new RuntimeException("Terminal canonical result contains forbidden or unexpected key: {$key}");
            }
        }

        // Verify no sensitive credential substrings in keys or values
        $lowerEncoded = strtolower($encoded);
        foreach (self::FORBIDDEN_CREDENTIAL_SUBSTRINGS as $sensitive) {
            if (str_contains($lowerEncoded, '"'.$sensitive.'"') || str_contains($lowerEncoded, '"'.$sensitive.'_') || str_contains($lowerEncoded, '_'.$sensitive.'"')) {
                throw new RuntimeException("Terminal canonical result contains forbidden sensitive credential: {$sensitive}");
            }
        }

        // Validate public ID consistency with locked canonical entities
        if ($canonicalInvPublicId !== null) {
            if (isset($rawTerminal['sales_invoice_public_id']) && ! hash_equals($canonicalInvPublicId, (string) $rawTerminal['sales_invoice_public_id'])) {
                throw new RuntimeException('Terminal canonical result sales invoice public ID mismatch.');
            }
            if (isset($rawTerminal['invoice_public_id']) && ! hash_equals($canonicalInvPublicId, (string) $rawTerminal['invoice_public_id'])) {
                throw new RuntimeException('Terminal canonical result invoice public ID mismatch.');
            }
            if (isset($rawTerminal['invoice_id']) && ! hash_equals($canonicalInvPublicId, (string) $rawTerminal['invoice_id'])) {
                throw new RuntimeException('Terminal canonical result invoice ID mismatch.');
            }
        }

        if ($canonicalPayPublicId !== null) {
            if (isset($rawTerminal['customer_payment_public_id']) && ! hash_equals($canonicalPayPublicId, (string) $rawTerminal['customer_payment_public_id'])) {
                throw new RuntimeException('Terminal canonical result customer payment public ID mismatch.');
            }
            if (isset($rawTerminal['payment_public_id']) && ! hash_equals($canonicalPayPublicId, (string) $rawTerminal['payment_public_id'])) {
                throw new RuntimeException('Terminal canonical result payment public ID mismatch.');
            }
            if (isset($rawTerminal['payment_id']) && ! hash_equals($canonicalPayPublicId, (string) $rawTerminal['payment_id'])) {
                throw new RuntimeException('Terminal canonical result payment ID mismatch.');
            }
        }

        if ($invId !== null && isset($rawTerminal['sales_invoice_id']) && (int) $rawTerminal['sales_invoice_id'] !== $invId) {
            throw new RuntimeException('Terminal canonical result sales invoice ID mismatch.');
        }

        if ($payId !== null && isset($rawTerminal['customer_payment_id']) && (int) $rawTerminal['customer_payment_id'] !== $payId) {
            throw new RuntimeException('Terminal canonical result customer payment ID mismatch.');
        }

        if ($cmdId !== null && isset($rawTerminal['command_receipt_id']) && (int) $rawTerminal['command_receipt_id'] !== $cmdId) {
            throw new RuntimeException('Terminal canonical result command receipt ID mismatch.');
        }

        return $rawTerminal;
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

        if ($eventVersion === 1 || $representation === 'v1_canonical') {
            throw new InvalidArgumentException('Legacy v1 event admission is unsupported.');
        }

        if (! in_array($eventVersion, self::SUPPORTED_EVENT_VERSIONS, true)) {
            throw new InvalidArgumentException('Unsupported event version.');
        }

        if (! in_array($representation, self::SUPPORTED_REPRESENTATIONS, true)) {
            throw new InvalidArgumentException('Unsupported representation format.');
        }

        return $this->validateEconomicPayload($canonicalBytes);
    }

    /**
     * Checks if a native mobile execution context is currently active.
     */
    public static function isNativeExecutionContextActive(): bool
    {
        return self::$currentInvocationScopeId !== null;
    }

    private static function assertClosedActionCaller(): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
        $callerFound = false;
        foreach ($trace as $frame) {
            if (($frame['class'] ?? null) === ExecuteImmediateOnlineCashSaleAction::class) {
                $callerFound = true;
                break;
            }
        }

        if (! $callerFound) {
            throw new SecurityException('Financial capability operations are restricted strictly to closed canonical action execution.');
        }
    }

    /**
     * Internal private capability consumption, strictly restricted to ExecuteImmediateOnlineCashSaleAction.
     *
     * @phpstan-ignore method.unused
     */
    private static function verifyAndConsumeCapabilityPrivately(
        NativeFinancialExecutionCapability $capability,
        int $companyId,
        int $actorId,
        string $deviceId,
        string $clientEventId,
        string $eventHash
    ): void {
        self::assertClosedActionCaller();

        if (self::$capabilityRegistry === null || ! self::$capabilityRegistry->offsetExists($capability)) {
            throw new SecurityException('Unauthorized financial capability: capability was not minted by an active admission.');
        }

        $record = self::$capabilityRegistry[$capability];

        if ($record['consumed'] ?? false) {
            throw new SecurityException('Native financial capability has already been consumed.');
        }

        if (! ($record['active'] ?? false)) {
            throw new SecurityException('Native financial capability is expired or inactive.');
        }

        if (DB::transactionLevel() !== 1) {
            throw new IllegalStateException('Capability consumption requires root database transaction level (depth 1).');
        }

        if (DB::connection()->getName() !== ($record['connection_name'] ?? '')) {
            throw new SecurityException('Active database connection does not match capability admission connection.');
        }

        if ((int) ($record['company_id'] ?? 0) !== $companyId) {
            throw new SecurityException('Capability company mismatch.');
        }

        if ((int) ($record['actor_id'] ?? 0) !== $actorId) {
            throw new SecurityException('Capability actor mismatch.');
        }

        if ((string) ($record['device_id'] ?? '') !== $deviceId) {
            throw new SecurityException('Capability device mismatch.');
        }

        if ((string) ($record['client_event_id'] ?? '') !== $clientEventId) {
            throw new SecurityException('Capability client_event_id mismatch.');
        }

        if (! hash_equals((string) ($record['event_hash'] ?? ''), $eventHash)) {
            throw new SecurityException('Capability event hash mismatch.');
        }

        if (self::$currentInvocationScopeId === null || ($record['invocation_scope_id'] ?? '') !== self::$currentInvocationScopeId) {
            throw new SecurityException('Capability invocation scope mismatch.');
        }

        $record['consumed'] = true;
        $record['active'] = false;
        self::$capabilityRegistry[$capability] = $record;
    }

    /**
     * Records closed composite completion for an attested capability.
     * Strictly restricted to ExecuteImmediateOnlineCashSaleAction under active composite permit.
     *
     * @phpstan-ignore method.unused
     */
    private static function markCompositeCompletedPrivately(
        NativeFinancialExecutionCapability $capability,
        int $companyId,
        int $actorId,
        int $invoiceId,
        int $paymentId
    ): void {
        self::assertClosedActionCaller();

        if (! CompositeFinancialExecutionScope::isActive($companyId, $actorId)) {
            throw new SecurityException('Composite completion can only be recorded while composite execution permit is actively held.');
        }

        if (self::$capabilityRegistry === null || ! self::$capabilityRegistry->offsetExists($capability)) {
            throw new SecurityException('Unauthorized financial capability: capability was not minted by an active admission.');
        }

        $record = self::$capabilityRegistry[$capability];

        if (! ($record['consumed'] ?? false)) {
            throw new SecurityException('Capability must be consumed before marking composite completion.');
        }

        if ($record['composite_completed'] ?? false) {
            throw new SecurityException('Composite completion has already been recorded for this capability.');
        }

        if ((int) ($record['company_id'] ?? 0) !== $companyId || (int) ($record['actor_id'] ?? 0) !== $actorId) {
            throw new SecurityException('Scope mismatch on composite completion registration.');
        }

        // Freshness & tenant isolation validation: entities must not have pre-existing receipts
        $existingInvoiceReceipt = DB::table('mobile_event_receipts')
            ->where('company_id', $companyId)
            ->where('sales_invoice_id', $invoiceId)
            ->exists();
        if ($existingInvoiceReceipt) {
            throw new SecurityException("Sales invoice [{$invoiceId}] is already associated with an existing event receipt.");
        }

        $existingPaymentReceipt = DB::table('mobile_event_receipts')
            ->where('company_id', $companyId)
            ->where('customer_payment_id', $paymentId)
            ->exists();
        if ($existingPaymentReceipt) {
            throw new SecurityException("Customer payment [{$paymentId}] is already associated with an existing event receipt.");
        }

        // Entity tenant verification
        $invoiceValid = DB::table('sales_invoices')
            ->where('id', $invoiceId)
            ->where('company_id', $companyId)
            ->exists();
        if (! $invoiceValid) {
            throw new SecurityException("Sales invoice [{$invoiceId}] does not belong to company [{$companyId}].");
        }

        $paymentValid = DB::table('customer_payments')
            ->where('id', $paymentId)
            ->where('company_id', $companyId)
            ->exists();
        if (! $paymentValid) {
            throw new SecurityException("Customer payment [{$paymentId}] does not belong to company [{$companyId}].");
        }

        $record['composite_completed'] = true;
        $record['completed_invoice_id'] = $invoiceId;
        $record['completed_payment_id'] = $paymentId;
        self::$capabilityRegistry[$capability] = $record;
    }

    /**
     * @return array<string, mixed>
     */
    private static function getCapabilityRecord(NativeFinancialExecutionCapability $capability): array
    {
        if (self::$capabilityRegistry === null || ! self::$capabilityRegistry->offsetExists($capability)) {
            return [];
        }

        return self::$capabilityRegistry[$capability];
    }
}
