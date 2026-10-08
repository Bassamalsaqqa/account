<?php

declare(strict_types=1);

namespace App\Services\Expenses;

use App\Models\LedgerAccount;
use InvalidArgumentException;

final class ExpenseLedger
{
    public function validate(LedgerAccount $ledger, bool $new = false): void
    {
        $parent = LedgerAccount::where('company_id', $ledger->company_id)->where('system_key', 'operating_expense_parent')->firstOrFail();
        if ($ledger->account_type !== 'expense' || $ledger->normal_balance !== 'debit' || $ledger->is_control
            || (int) $ledger->parent_id !== (int) $parent->id || ($new && (! $ledger->active || ! $parent->active))) {
            throw new InvalidArgumentException('Category requires a debit-normal operating Expense child ledger.');
        }
    }
}
