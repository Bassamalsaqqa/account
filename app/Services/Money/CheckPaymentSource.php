<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Models\Check;
use App\Models\LedgerAccount;
use App\Models\User;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class CheckPaymentSource
{
    /** @param array<string, mixed> $data */
    public function instrument(int $companyId, User $actor, array $data, string $direction): Check
    {
        $check = app(MoneyEventScope::class)->checkForPayment((int) $data['check_id'], $companyId, $actor, $direction);
        $partyKey = $direction === 'incoming' ? 'customer_id' : 'vendor_id';
        if ((int) $check->getAttribute($partyKey) !== (int) $data[$partyKey]
            || $check->received_issued_date->toDateString() !== $data['payment_date']
            || ! BigDecimal::of($check->amount)->isEqualTo($data['amount'])
            || ! BigDecimal::of($check->exchange_rate)->isEqualTo($data['exchange_rate'])) {
            throw new InvalidArgumentException('Check settlement does not match its immutable instrument.');
        }

        return $check;
    }

    /** @param array<string, mixed> $data */
    public function instrumentForExpense(int $companyId, User $actor, array $data): Check
    {
        $check = app(MoneyEventScope::class)->checkForPayment((int) $data['check_id'], $companyId, $actor, 'outgoing');
        $vendorId = ! empty($data['vendor_id']) ? (int) $data['vendor_id'] : null;
        if (($vendorId !== null && (int) $check->vendor_id !== $vendorId)
            || ($vendorId === null && $check->vendor_id !== null)
            || $check->customer_id !== null
            || $check->received_issued_date->toDateString() !== $data['expense_date']
            || ! BigDecimal::of($check->amount)->isEqualTo($data['amount'])
            || ! BigDecimal::of($check->exchange_rate)->isEqualTo($data['exchange_rate'])) {
            throw new InvalidArgumentException('Check settlement does not match its immutable instrument.');
        }

        return $check;
    }

    /** @param array<string, mixed> $data */
    public function instrumentForAdvance(int $companyId, User $actor, array $data): Check
    {
        $check = app(MoneyEventScope::class)->checkForPayment((int) $data['check_id'], $companyId, $actor, 'outgoing');
        if ($check->customer_id !== null || $check->vendor_id !== null
            || $check->received_issued_date->toDateString() !== $data['advance_date']
            || ! BigDecimal::of($check->amount)->isEqualTo($data['amount'])
            || ! BigDecimal::of($check->exchange_rate)->isEqualTo($data['exchange_rate'])) {
            throw new InvalidArgumentException('Check settlement does not match its immutable instrument.');
        }

        return $check;
    }

    /** @param array<string, mixed> $data */
    public function instrumentForSalaryPayment(int $companyId, User $actor, array $data): Check
    {
        $check = app(MoneyEventScope::class)->checkForPayment((int) $data['check_id'], $companyId, $actor, 'outgoing');
        if ($check->customer_id !== null || $check->vendor_id !== null
            || $check->received_issued_date->toDateString() !== $data['payment_date']
            || ! BigDecimal::of($check->amount)->isEqualTo($data['amount'])
            || ! BigDecimal::of($check->exchange_rate)->isEqualTo($data['exchange_rate'])) {
            throw new InvalidArgumentException('Check settlement does not match its immutable instrument.');
        }

        return $check;
    }

    public function ledger(Check $check, bool $active = true): LedgerAccount
    {
        $key = $check->direction === 'incoming' ? 'checks_in_hand' : 'checks_issued';
        $account = LedgerAccount::where('company_id', $check->company_id)->where('system_key', $key)->firstOrFail();
        if (! $account->is_control || ($active && ! $account->active)
            || $account->account_type !== ($check->direction === 'incoming' ? 'asset' : 'liability')
            || $account->normal_balance !== ($check->direction === 'incoming' ? 'debit' : 'credit')) {
            throw new InvalidArgumentException('Check control account configuration is invalid.');
        }

        return $account;
    }
}
