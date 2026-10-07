<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\PostMoneyTransferAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Services\Money\MoneyEventCapability;
use App\Services\Money\MoneyEventScope;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Posting\AccountingPostingService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class MoneyAuthorityAndMixedSettlementTest extends Phase6TestCase
{
    public function test_live_capability_cannot_reenter_cross_company_serialize_or_survive_completion(): void
    {
        $real = app(AccountingPostingService::class);
        $action = app(PostMoneyTransferAction::class);
        $captured = null;
        $this->mock(AccountingPostingService::class)->shouldReceive('post')->once()->andReturnUsing(function ($command) use ($real, $action, &$captured) {
            $scope = app(MoneyEventScope::class);
            $captured = (new \ReflectionProperty($scope, 'active'))->getValue($scope);
            $scope->assert($captured, (int) $this->company->id);
            foreach ([fn () => $scope->assert(new MoneyEventCapability, (int) $this->company->id),
                fn () => $scope->assert($captured, (int) $this->company->id + 1), fn () => serialize($captured),
                fn () => $scope->within($action, (int) $this->company->id, $this->owner, fn () => null)] as $bypass) {
                try {
                    $bypass();
                    $this->fail('Capability bypass');
                } catch (\LogicException $e) {
                    $this->assertNotEmpty($e->getMessage());
                }
            }

            return $real->post($command);
        });
        $action->execute($this->company, $this->owner, ['from_money_account_id' => $this->usdCashAccount->id, 'to_money_account_id' => $this->usdBankAccount->id,
            'transfer_date' => '2026-10-03', 'from_amount' => '1', 'to_amount' => '1', 'from_exchange_rate' => '3.50', 'to_exchange_rate' => '3.50', 'idempotency_key' => 'capability']);
        $this->assertNotNull($captured);
        $this->expectException(\LogicException::class);
        app(MoneyEventScope::class)->assert($captured, (int) $this->company->id);
    }

    public static function domains(): array
    {
        return [['customer'], ['vendor']];
    }

    #[DataProvider('domains')]
    public function test_one_advance_applies_to_mixed_document_currencies_without_second_money_movement(string $domain): void
    {
        $vendor = $domain === 'vendor';
        $usd = $vendor ? $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']) : $this->invoice();
        $jod = $vendor ? $this->createAndPostPurchase(['currency_code' => 'JOD', 'exchange_rate' => '4.80', 'lines' => [['quantity' => '1', 'unit_cost' => '10']]])
            : app(PostSalesInvoiceAction::class)->execute(app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner,
                ['customer_id' => $usd->customer_id, 'currency_code' => 'JOD', 'exchange_rate' => '4.80', 'issue_date' => '2026-10-01',
                    'lines' => [['product_id' => null, 'item_description' => 'Service', 'quantity' => '1', 'unit_price' => '10']]]), $this->owner);
        $payment = app($vendor ? PostVendorPaymentAction::class : PostCustomerPaymentAction::class)->execute($this->company, $this->owner,
            [($vendor ? 'vendor_id' : 'customer_id') => $vendor ? $this->vendor->id : $usd->customer_id, 'money_account_id' => $this->ilsCashAccount->id,
                'payment_method' => 'cash', 'payment_date' => '2026-10-02', 'amount' => '400', 'exchange_rate' => '1', 'idempotency_key' => 'mixed-advance']);
        $before = DB::table('posting_lines')->where('ledger_account_id', $this->ilsCashAccount->ledger_account_id)->count();
        $data = ['application_date' => '2026-10-03', 'idempotency_key' => 'mixed-application', 'allocations' => [
            [($vendor ? 'purchase_id' : 'sales_invoice_id') => $jod->id, 'allocated_amount' => '10', 'payment_currency_amount' => '48'],
            [($vendor ? 'purchase_id' : 'sales_invoice_id') => $usd->id, 'allocated_amount' => '100', 'payment_currency_amount' => '350']]];
        $action = app($vendor ? ApplyVendorPaymentCreditAction::class : ApplyCustomerPaymentCreditAction::class);
        $event = $action->execute($payment, $this->owner, $data);
        $this->assertNull($event->posting_batch_id);
        $this->assertSame('2.000000', $payment->fresh()->unallocated_amount);
        $this->assertSame('2.000000', $payment->fresh()->unallocated_amount_base);
        $this->assertSame($before, DB::table('posting_lines')->where('ledger_account_id', $this->ilsCashAccount->ledger_account_id)->count());
        $this->assertSame($event->id, $action->execute($payment, $this->owner, $data)->id);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    #[DataProvider('domains')]
    public function test_check_return_or_cancellation_reverses_later_application_before_payment(string $domain): void
    {
        $incoming = $domain === 'customer';
        $document = $incoming ? $this->invoice('USD', '3.60') : $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.60']);
        $data = $this->checkIntent($incoming ? 'incoming' : 'outgoing');
        if ($incoming) {
            $data['party_id'] = $document->customer_id;
        }
        $check = app($incoming ? ReceiveCheckAction::class : IssueCheckAction::class)->execute($this->company, $this->owner, $data);
        $payment = $incoming ? $check->customerPayment : $check->vendorPayment;
        $application = app($incoming ? ApplyCustomerPaymentCreditAction::class : ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner,
            ['application_date' => '2026-10-03', 'idempotency_key' => 'check-advance-application',
                'allocations' => [[($incoming ? 'sales_invoice_id' : 'purchase_id') => $document->id, 'allocated_amount' => '100']]]);
        $this->assertNotNull($application->posting_batch_id);
        $clear = null;
        if ($incoming) {
            $this->event($check, 'deposit');
            $clear = $this->event($check->fresh(), 'clear', '3.60');
        }
        $terminal = $this->event($check->fresh(), $incoming ? 'return' : 'cancel', date: '2026-10-04');
        $application = $application->fresh();
        $this->assertNotNull($application->reversed_at);
        $this->assertLessThan($terminal->payment_reversal_posting_batch_id, $application->reversal_posting_batch_id);
        if ($clear !== null) {
            $this->assertLessThan($application->reversal_posting_batch_id, $terminal->reversal_posting_batch_id);
        }
        $this->assertTrue($document->fresh()->calculateOutstanding()->isEqualTo('100'));
        $this->assertSame('0.000000', $payment->fresh()->unallocated_amount);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
    }
}
