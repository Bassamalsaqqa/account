<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Accounting\PostOpeningBalancesAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Domain\Money\Queries\MoneyBalanceQuery;
use App\Domain\Money\Queries\MoneyMovementQuery;
use App\Models\LedgerAccount;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Posting\AccountingReversalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase5E\Phase5ETestCase;

class MoneyControlPlaneTest extends Phase5ETestCase
{
    public function test_balances_are_ledger_derived_with_exact_native_and_base_values_and_negative_policy(): void
    {
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $this->payment());
        $rows = app(MoneyBalanceQuery::class)->forType($this->company, 'cash');
        $usd = collect($rows)->firstWhere('public_id', $this->usdCashAccount->public_id);
        $this->assertSame('-1.230000', $usd['balance_currency']);
        $this->assertSame('-4.305000', $usd['balance_base']);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
        app(AccountingReversalService::class)->reverse($payment->postingBatch, $this->owner);
        $usd = collect(app(MoneyBalanceQuery::class)->forType($this->company, 'cash'))->firstWhere('public_id', $this->usdCashAccount->public_id);
        $this->assertSame('0.000000', $usd['balance_currency']);
        $this->assertSame('0.000000', $usd['balance_base']);
    }

    public function test_legacy_foreign_opening_does_not_fabricate_native_currency_balance(): void
    {
        app(PostOpeningBalancesAction::class)->execute($this->company, now(), [
            ['account_id' => $this->usdCashAccount->ledger_account_id, 'amount' => '350'],
        ], postedBy: $this->owner);
        $row = collect(app(MoneyBalanceQuery::class)->forType($this->company, 'cash'))->firstWhere('public_id', $this->usdCashAccount->public_id);
        $this->assertNull($row['balance_currency']);
        $this->assertFalse($row['currency_balance_available']);
        $this->assertSame('350.000000', $row['balance_base']);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_movements_keep_running_balances_across_pages_and_include_inverse(): void
    {
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $this->payment());
        app(AccountingReversalService::class)->reverse($payment->postingBatch, $this->owner);
        $query = app(MoneyMovementQuery::class);
        $latest = $query->forAccount($this->usdCashAccount, perPage: 1);
        $this->assertSame(2, $latest->total());
        $this->assertSame('0.000000', $latest->items()[0]->running_base);
        $this->assertSame('reversal', $latest->items()[0]->source_type);
        $prior = $query->forAccount($this->usdCashAccount, page: 2, perPage: 1);
        $this->assertSame('-4.305000', $prior->items()[0]->running_base);
        $this->assertSame('-1.230000', $prior->items()[0]->known_running_currency);
    }

    public function test_money_reconciliation_detects_wrong_parent_and_currency_corruption(): void
    {
        app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $this->payment());
        DB::table('posting_lines')->where('ledger_account_id', $this->usdCashAccount->ledger_account_id)->update(['transaction_currency_code' => 'JOD']);
        DB::table('ledger_accounts')->where('id', $this->usdCashAccount->ledger_account_id)->update([
            'parent_id' => LedgerAccount::where('system_key', 'bank_control')->firstOrFail()->id,
        ]);
        $report = app(MoneyReconciliationService::class)->reconcile($this->company);
        $this->assertFalse($report->isHealthy);
        $this->assertCount(2, $report->violations);
    }

    public function test_money_query_freshly_denies_stale_permission_and_foreign_context(): void
    {
        $reader = $this->customActor(['money.cash.view']);
        $this->activate($reader);
        $this->assertNotEmpty(app(MoneyBalanceQuery::class)->forType($this->company, 'cash'));
        $reader->roles()->firstOrFail()->revokePermissionTo('money.cash.view');
        $this->expectException(AuthorizationException::class);
        app(MoneyBalanceQuery::class)->forType($this->company, 'cash');
    }

    /** @return array<string, mixed> */
    private function payment(): array
    {
        return [
            'vendor_id' => $this->vendor->id, 'money_account_id' => $this->usdCashAccount->id,
            'payment_date' => '2026-10-07', 'payment_method' => 'cash', 'amount' => '1.23',
            'exchange_rate' => '3.5', 'idempotency_key' => 'money-control-test', 'allocations' => [],
        ];
    }
}
