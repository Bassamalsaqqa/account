<?php

declare(strict_types=1);

namespace App\Support\Mobile\Receipt;

use App\Application\Platform\TenantWriteAccess;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\NativeCommandReceipt;
use App\Models\User;
use App\Support\Mobile\NativeAdmissionGuard;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use InvalidArgumentException;

final class NativeCommandReceiptManager
{
    /** Fail-only selector for isolated rollback tests; never executes caller-supplied code. */
    public static bool $failBeforeReceiptInsertForTesting = false;

    public function __construct(
        private readonly TenantWriteAccess $tenantWriteAccess,
        private readonly NativeAdmissionGuard $admissionGuard,
    ) {}

    /**
     * Execute a native command with deadlock-ordered admission, locking, and durable idempotency.
     *
     * @param  list<string>  $requiredPermissions
     * @param  array<string, mixed>  $canonicalPayload
     * @param  Closure(Company, User, MobileDevice, MobileAccessToken): array{snapshot: array<string, mixed>, entity_type?: string|null, entity_id?: int|null, entity_public_id?: string|null}  $executor
     * @return array{outcome: 'created'|'replay', status: int, snapshot: array<string, mixed>, receipt: NativeCommandReceipt}
     */
    public function execute(
        int $companyId,
        User $actor,
        ?MobileAccessToken $token,
        ?MobileDevice $device,
        string $commandType,
        string $idempotencyKey,
        array $canonicalPayload,
        array $requiredPermissions,
        Closure $executor
    ): array {
        if ($token === null || $device === null) {
            throw new AuthenticationException('Native bearer token and mobile device are mandatory.');
        }

        $trimmedKey = trim($idempotencyKey);
        if ($trimmedKey === '') {
            throw new InvalidArgumentException('Idempotency key cannot be empty.');
        }

        if (strlen($trimmedKey) > 128) {
            throw new InvalidArgumentException('Idempotency key exceeds maximum length of 128 characters.');
        }

        $requestHash = hash('sha256', json_encode($canonicalPayload, JSON_THROW_ON_ERROR));

        return $this->tenantWriteAccess->transaction($companyId, function () use (
            $companyId,
            $actor,
            $token,
            $device,
            $commandType,
            $trimmedKey,
            $canonicalPayload,
            $requestHash,
            $requiredPermissions,
            $executor
        ): array {
            // 1. Mandatory NativeAdmissionGuard: Company -> Membership -> User -> Device -> Token + Fresh Permissions
            $admission = $this->admissionGuard->lockAndAuthorize(
                $companyId,
                $actor,
                $token,
                $device,
                $requiredPermissions
            );

            $lockedCompany = $admission['company'];
            $lockedActor = $admission['user'];
            $lockedDevice = $admission['device'];
            $lockedToken = $admission['token'];

            auth()->setUser($lockedActor);

            // 2. Check existing receipt row under company lock
            /** @var NativeCommandReceipt|null $existing */
            $existing = NativeCommandReceipt::where('company_id', $lockedCompany->id)
                ->where('idempotency_key', $trimmedKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->handleExistingReceipt($existing, $commandType, $lockedActor, $lockedDevice, $requestHash, $trimmedKey);
            }

            // 3. Fresh execution: invoke domain executor closure under active lock and transaction
            $executionResult = $executor($lockedCompany, $lockedActor, $lockedDevice, $lockedToken);

            if (app()->environment('testing') && self::$failBeforeReceiptInsertForTesting) {
                throw new \RuntimeException('Injected failure before native receipt persistence.');
            }

            // 4. Persist durable receipt
            $receipt = NativeCommandReceipt::create([
                'company_id' => $lockedCompany->id,
                'command_type' => $commandType,
                'idempotency_key' => $trimmedKey,
                'actor_id' => $lockedActor->id,
                'device_id' => $lockedDevice->device_id,
                'request_hash' => $requestHash,
                'payload' => $canonicalPayload,
                'response_snapshot' => $executionResult['snapshot'],
                'entity_type' => $executionResult['entity_type'] ?? null,
                'entity_id' => $executionResult['entity_id'] ?? null,
                'entity_public_id' => $executionResult['entity_public_id'] ?? null,
                'status' => 'completed',
            ]);

            return [
                'outcome' => 'created',
                'status' => 201,
                'snapshot' => $executionResult['snapshot'],
                'receipt' => $receipt,
            ];

        });
    }

    /**
     * @return array{outcome: 'replay', status: int, snapshot: array<string, mixed>, receipt: NativeCommandReceipt}
     */
    private function handleExistingReceipt(
        NativeCommandReceipt $existing,
        string $commandType,
        User $lockedActor,
        MobileDevice $lockedDevice,
        string $requestHash,
        string $trimmedKey
    ): array {
        if ($existing->command_type !== $commandType) {
            throw new IdempotencyConflictException("Idempotency key [{$trimmedKey}] was previously committed for a different command type.");
        }

        // Cross-actor or cross-device replay => 403 Forbidden
        if ((int) $existing->actor_id !== (int) $lockedActor->id || (string) $existing->device_id !== (string) $lockedDevice->device_id) {
            throw new AuthorizationException('Idempotency key replay across different actors or devices is forbidden.');
        }

        // Payload mismatch => 409 Conflict
        if (! hash_equals((string) $existing->request_hash, $requestHash)) {
            throw new IdempotencyConflictException("Idempotency key [{$trimmedKey}] was previously committed with different parameters.");
        }

        // Exact replay => 200 OK with frozen original response
        return [
            'outcome' => 'replay',
            'status' => 200,
            'snapshot' => $existing->response_snapshot,
            'receipt' => $existing,
        ];
    }

    /**
     * Read-only status lookup of an existing receipt scoped to original actor and device.
     *
     * @return array{idempotency_key: string, command_type: string, status: string, request_hash: string, entity_type: string|null, entity_public_id: string|null, created_at: string, result: array<string, mixed>}
     */
    public function lookup(
        Company $company,
        User $actor,
        MobileDevice $device,
        string $idempotencyKey
    ): array {
        $trimmedKey = trim($idempotencyKey);
        /** @var NativeCommandReceipt|null $receipt */
        $receipt = NativeCommandReceipt::where('company_id', $company->id)
            ->where('idempotency_key', $trimmedKey)
            ->first();

        if ($receipt === null) {
            throw new InvalidArgumentException("Receipt [{$trimmedKey}] not found.");
        }

        if ((int) $receipt->actor_id !== (int) $actor->id || (string) $receipt->device_id !== (string) $device->device_id) {
            throw new AuthorizationException('Event scope does not match authorized actor and device context.');
        }

        setPermissionsTeamId($company->id);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $permissions = match ($receipt->command_type) {
            'customer_payment' => ['money.receipt.view'],
            'supplier_payment' => ['purchasing.purchase.view', 'purchasing.cost.view'],
            'expense' => ['money.expense.view'],
            'inventory.adjustment' => ['inventory.stock.view'],
            'purchase_return' => ['purchasing.return.view', 'purchasing.cost.view'],
            'sales_return' => ['sales.return.view'],
            default => throw new AuthorizationException('Receipt command is not available for status lookup.'),
        };
        foreach ($permissions as $permission) {
            if (! $actor->hasPermissionTo($permission)) {
                throw new AuthorizationException('Current permission does not allow this receipt to be read.');
            }
        }

        return [
            'idempotency_key' => $receipt->idempotency_key,
            'command_type' => $receipt->command_type,
            'status' => $receipt->status,
            'request_hash' => $receipt->request_hash,
            'entity_type' => $receipt->entity_type,
            'entity_public_id' => $receipt->entity_public_id,
            'created_at' => $receipt->created_at->format('Y-m-d\TH:i:s\Z'),
            'result' => $receipt->response_snapshot,
        ];
    }
}
