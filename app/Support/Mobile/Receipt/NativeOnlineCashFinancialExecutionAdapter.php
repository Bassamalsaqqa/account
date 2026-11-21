<?php

declare(strict_types=1);

namespace App\Support\Mobile\Receipt;

use App\Actions\Sales\ExecuteImmediateOnlineCashSaleAction;
use App\Models\Company;
use App\Models\MobileDevice;
use App\Models\User;
use App\Support\Mobile\Receipt\Exceptions\FinancialExecutionDeniedException;
use App\Support\Mobile\Receipt\Exceptions\SecurityException;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Production bounded typed adapter for Native Online Cash Sales on Mobile V2.
 *
 * Enforces:
 * - Dual-gate feature activation (mobile.enabled, mobile.merchant_api_enabled, mobile.v2_enabled, mobile.v2_cash_slice_enabled).
 *   Fails closed with FinancialExecutionDeniedException when any flag is disabled.
 * - Strict tenant, actor membership, active device ownership, and fresh permissions verification.
 * - Restricts execution to valid NativeFinancialExecutionCapability within root transaction.
 * - Delegates execution to ExecuteImmediateOnlineCashSaleAction.
 */
final class NativeOnlineCashFinancialExecutionAdapter implements FinancialExecutionAdapterInterface
{
    /**
     * Authorize operation ('execute', 'replay', 'lookup') for the authenticated actor and device.
     *
     * @param  string  $operation  'execute', 'replay', or 'lookup'
     * @param  array<string, mixed>|null  $payload  Decoded economic payload if available
     *
     * @throws FinancialExecutionDeniedException If the cash slice or mobile API is disabled
     * @throws AuthorizationException If the actor/device lacks required permissions or scope
     */
    public function authorize(
        Company $company,
        User $actor,
        MobileDevice $device,
        string $operation,
        ?array $payload = null
    ): void {
        // 1. Production feature gate enforcement: default OFF cash slice + merchant / V2 activation
        if (! (bool) config('mobile.enabled', false)
            || ! (bool) config('mobile.v2_enabled', false)
            || ! (bool) config('mobile.v2_cash_slice_enabled', false)
            || ! (bool) config('mobile.merchant_api_enabled', false)) {
            throw new FinancialExecutionDeniedException(
                'Financial authority is unresolved on Mobile V2. Mobile online cash sale execution is disabled.'
            );
        }

        // 2. Bounded operation validation
        if (! in_array($operation, ['execute', 'replay', 'lookup'], true)) {
            throw new AuthorizationException("Unsupported operation [{$operation}].");
        }

        // 3. Strict tenant membership verification
        if (! $actor->belongsToCompany($company->id)) {
            throw new AuthorizationException("Actor does not belong to company [{$company->id}].");
        }

        if ($company->status !== 'active') {
            throw new AuthorizationException('Target company is inactive.');
        }

        // 4. Strict device ownership and revocation check
        if ((int) $device->user_id !== (int) $actor->id || $device->isRevoked()) {
            throw new AuthorizationException('Device has been revoked or does not match authenticated actor.');
        }

        // 5. Fresh role definition & permission verification
        setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        $settlementMode = is_array($payload)
            ? ($payload['payment']['settlement_mode'] ?? $payload['settlement_mode'] ?? null)
            : null;

        if ($operation === 'execute') {
            $requiredPermissions = [
                'sales.invoice.create',
                'sales.invoice.post',
            ];
            if ($settlementMode !== 'unpaid') {
                $requiredPermissions[] = 'money.receipt.create';
                $requiredPermissions[] = 'money.receipt.allocate';
            }
            foreach ($requiredPermissions as $perm) {
                if (! $actor->hasPermissionTo($perm)) {
                    throw new AuthorizationException("Actor missing required permission [{$perm}] for immediate cash sale execution.");
                }
            }
        } elseif (in_array($operation, ['replay', 'lookup'], true)) {
            $requiredViewPermissions = [
                'sales.invoice.view',
            ];
            if ($settlementMode !== 'unpaid') {
                $requiredViewPermissions[] = 'money.receipt.view';
            }
            foreach ($requiredViewPermissions as $perm) {
                if (! $actor->hasPermissionTo($perm)) {
                    throw new AuthorizationException("Actor missing required permission [{$perm}] for {$operation}.");
                }
            }
        }
    }

    /**
     * Execute canonical business operations inside the atomic write transaction under company locks.
     *
     * @param  Company  $company  Locked company model
     * @param  User  $actor  Authenticated staff user
     * @param  MobileDevice  $device  Authenticated mobile device
     * @param  array<string, mixed>  $payload  Decoded economic payload
     * @param  string  $idempotencySuffix  Deterministic idempotency suffix
     * @param  NativeFinancialExecutionCapability|null  $capability  Validated single-use native capability
     * @return array{
     *     sales_invoice_id: int|null,
     *     sales_invoice_public_id: string|null,
     *     customer_payment_id: int|null,
     *     customer_payment_public_id: string|null,
     *     command_receipt_id: int|null,
     *     terminal_canonical_result: array<string, mixed>
     * }
     */
    public function execute(
        Company $company,
        User $actor,
        MobileDevice $device,
        array $payload,
        string $idempotencySuffix,
        ?NativeFinancialExecutionCapability $capability = null
    ): array {
        $this->authorize($company, $actor, $device, 'execute', $payload);

        if ($capability === null) {
            throw new SecurityException('Native cash execution requires a valid NativeFinancialExecutionCapability.');
        }

        return app(ExecuteImmediateOnlineCashSaleAction::class)->execute(
            $capability,
            $company,
            $actor,
            $device,
            $payload,
            $idempotencySuffix
        );
    }
}
