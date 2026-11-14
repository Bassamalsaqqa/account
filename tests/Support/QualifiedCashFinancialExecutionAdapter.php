<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Sales\ExecuteImmediateOnlineCashSaleAction;
use App\Models\Company;
use App\Models\MobileDevice;
use App\Models\User;
use App\Support\Mobile\Receipt\Exceptions\SecurityException;
use App\Support\Mobile\Receipt\FinancialExecutionAdapterInterface;
use App\Support\Mobile\Receipt\NativeFinancialExecutionCapability;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Test-only adapter used strictly to qualify native financial execution on Mobile V2.
 * Production binding remains DenyFinancialExecutionAdapter.
 */
final class QualifiedCashFinancialExecutionAdapter implements FinancialExecutionAdapterInterface
{
    public function authorize(
        Company $company,
        User $actor,
        MobileDevice $device,
        string $operation,
        ?array $payload = null
    ): void {
        if (! in_array($operation, ['execute', 'replay', 'lookup'], true)) {
            throw new AuthorizationException("Unsupported operation [{$operation}].");
        }

        if (! $actor->belongsToCompany($company->id)) {
            throw new AuthorizationException("Actor does not belong to company [{$company->id}].");
        }

        setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        if ($operation === 'execute') {
            $requiredPermissions = [
                'sales.invoice.create',
                'sales.invoice.post',
                'money.receipt.create',
                'money.receipt.allocate',
            ];
            foreach ($requiredPermissions as $perm) {
                if (! $actor->hasPermissionTo($perm)) {
                    throw new AuthorizationException("Actor missing required permission [{$perm}] for immediate cash sale execution.");
                }
            }
        } elseif (in_array($operation, ['replay', 'lookup'], true)) {
            $requiredViewPermissions = [
                'sales.invoice.view',
                'money.receipt.view',
            ];
            foreach ($requiredViewPermissions as $perm) {
                if (! $actor->hasPermissionTo($perm)) {
                    throw new AuthorizationException("Actor missing required permission [{$perm}] for {$operation}.");
                }
            }
        }
    }

    public function execute(
        Company $company,
        User $actor,
        MobileDevice $device,
        array $payload,
        string $idempotencySuffix,
        ?NativeFinancialExecutionCapability $capability = null
    ): array {
        if ($capability === null) {
            throw new SecurityException('Qualified cash execution requires a valid NativeFinancialExecutionCapability.');
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
