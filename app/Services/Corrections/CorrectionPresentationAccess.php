<?php

declare(strict_types=1);

namespace App\Services\Corrections;

use App\Models\User;
use App\Services\Money\MoneyActorGuard;
use Illuminate\Auth\Access\AuthorizationException;

/** Bind cached presentation to the read capabilities used to disclose dependent records. */
final class CorrectionPresentationAccess
{
    /** @return list<string> */
    public static function capture(User $actor): array
    {
        return array_values(array_filter([
            'money.expense.view', 'money.check.view', 'inventory.stock.view',
            'purchasing.purchase.view', 'purchasing.cost.view',
            'money.vendor_payment.create', 'money.vendor_payment.allocate',
            'vendors.statement.view', 'money.vendor_payment.reverse',
            'money.receipt.view', 'money.receipt.reverse',
            'sales.return.view', 'sales.return.void', 'purchasing.return.void',
        ], fn (string $permission): bool => $actor->can($permission)));
    }

    /** @param list<string>|null $permissions */
    public static function authorize(int $companyId, ?array $permissions): void
    {
        if ($permissions === null) {
            throw new AuthorizationException('Refresh the cached correction presentation.');
        }
        foreach ($permissions as $permission) {
            app(MoneyActorGuard::class)->authorize($companyId, $permission);
        }
    }
}
