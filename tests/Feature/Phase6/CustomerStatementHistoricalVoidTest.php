<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Actions\Sales\VoidSalesReturnAction;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class CustomerStatementHistoricalVoidTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 12:00:00 UTC');
        $this->salesFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function postedInvoice(): SalesInvoice
    {
        return $this->invoice('100', '3.50', 'USD', ['issue_date' => '2026-10-01', 'due_date' => '2026-10-03']);
    }

    private function postedReturn(SalesInvoice $invoice): SalesReturn
    {
        Carbon::setTestNow('2026-10-05 12:00:00 UTC');
        $draft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id, 'issue_date' => '2026-10-05',
            'lines' => [['sales_invoice_line_id' => $invoice->lines->sole()->id, 'quantity' => '0.30']],
        ]);

        return app(PostSalesReturnAction::class)->execute($draft, $this->owner);
    }

    private function voidDocument(SalesInvoice|SalesReturn $document): SalesInvoice|SalesReturn
    {
        Carbon::setTestNow('2026-10-20 12:00:00 UTC');
        $void = $document instanceof SalesInvoice
            ? app(VoidSalesInvoiceAction::class)->execute($document, $this->owner, 'Historical statement test')
            : app(VoidSalesReturnAction::class)->execute($document, $this->owner, 'Historical statement test');
        $this->assertSame('2026-10-20', $void->voidPostingBatch->posting_date->toDateString());

        return $void;
    }

    private function statement(?string $cutoff, ?string $from = null): array
    {
        return app(CustomerStatementQuery::class)->execute($this->customer, $from, $cutoff);
    }

    public function test_invoice_void_after_cutoff_preserves_rows_balance_and_historical_aging(): void
    {
        $invoice = $this->voidDocument($this->postedInvoice());
        $usd = $this->statement('2026-10-10')['currencies']['USD'];
        $this->assertSame([$invoice->invoice_number], array_column($usd['entries'], 'number'));
        $this->assertSame('100.00', $usd['closing_balance']);
        $this->assertSame('100.00', $usd['aging']['days_1_30']);
        $this->assertSame('0.00', $usd['aging']['current']);
        $this->assertSame('100.00', $usd['aging']['total']);

        // A from-date window preserves the invoice in the opening and closing positions.
        $window = $this->statement('2026-10-10', '2026-10-06')['currencies']['USD'];
        $this->assertSame([], $window['entries']);
        $this->assertSame('100.00', $window['opening_balance']);
        $this->assertSame('100.00', $window['closing_balance']);
        $this->assertSame('100.00', $window['aging']['total']);
    }

    public static function voidCutoffs(): array
    {
        return [['2026-10-20'], ['2026-10-21']];
    }

    #[DataProvider('voidCutoffs')]
    public function test_invoice_void_on_or_before_cutoff_no_longer_contributes(string $cutoff): void
    {
        $this->voidDocument($this->postedInvoice());
        // Preserve the existing absence of an otherwise empty currency block.
        $this->assertSame([], $this->statement($cutoff)['currencies']);
        $this->assertSame([], $this->statement(null)['currencies']);
    }

    public function test_return_void_after_cutoff_preserves_historical_credit_and_outstanding(): void
    {
        $invoice = $this->postedInvoice();
        $return = $this->voidDocument($this->postedReturn($invoice));
        $usd = $this->statement('2026-10-10')['currencies']['USD'];
        $this->assertSame(['invoice', 'return'], array_column($usd['entries'], 'type'));
        $this->assertSame($return->return_number, $usd['entries'][1]['number']);
        $this->assertSame('30.00', $usd['entries'][1]['credit']);
        $this->assertSame('70.00', $usd['closing_balance']);
        $this->assertSame('70.00', $usd['aging']['days_1_30']);
        $this->assertSame('70.00', $usd['aging']['total']);
    }

    #[DataProvider('voidCutoffs')]
    public function test_return_void_on_or_before_cutoff_no_longer_reduces_ar(string $cutoff): void
    {
        $this->voidDocument($this->postedReturn($this->postedInvoice()));
        $usd = $this->statement($cutoff)['currencies']['USD'];
        $this->assertSame(['invoice'], array_column($usd['entries'], 'type'));
        $this->assertSame('100.00', $usd['closing_balance']);
        $this->assertSame('100.00', $usd['aging']['total']);
        $this->assertSame('100.00', $this->statement(null)['currencies']['USD']['closing_balance']);
    }

    public static function documents(): array
    {
        return [['invoice'], ['return']];
    }

    #[DataProvider('documents')]
    public function test_void_business_date_is_authoritative_even_when_runtime_timestamp_differs(string $type): void
    {
        $invoice = $this->postedInvoice();
        $document = $this->voidDocument($type === 'invoice' ? $invoice : $this->postedReturn($invoice));
        // Disposable corruption of completion time deliberately disagrees with financial chronology.
        DB::table($document->getTable())->where('id', $document->id)->update(['voided_at' => '2026-10-07 12:00:00']);
        $usd = $this->statement('2026-10-10')['currencies']['USD'];
        $this->assertSame($type === 'invoice' ? '100.00' : '70.00', $usd['closing_balance']);
        $this->assertSame($usd['closing_balance'], $usd['aging']['total']);
    }

    public static function crossCurrencyModes(): array
    {
        return [['direct'], ['advance']];
    }

    #[DataProvider('crossCurrencyModes')]
    public function test_later_invoice_void_preserves_cross_currency_legs_and_application_reversal_history(string $mode): void
    {
        $invoice = $this->postedInvoice();
        $cash = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, ['account_type' => 'cash', 'currency_code' => 'ILS', 'name_ar' => 'ILS']);
        $allocation = [['sales_invoice_id' => $invoice->id, 'allocated_amount' => '100', 'payment_currency_amount' => '330']];
        Carbon::setTestNow('2026-10-03 12:00:00 UTC');
        $payment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'money_account_id' => $cash->id,
            'payment_date' => '2026-10-03', 'payment_method' => 'cash', 'amount' => '330', 'exchange_rate' => '1',
            'idempotency_key' => 'historical-cross', 'allocations' => $mode === 'direct' ? $allocation : [],
        ]);
        if ($mode === 'advance') {
            Carbon::setTestNow('2026-10-04 12:00:00 UTC');
            app(ApplyCustomerPaymentCreditAction::class)->execute($payment, $this->owner, [
                'application_date' => '2026-10-04', 'idempotency_key' => 'historical-apply', 'allocations' => $allocation,
            ]);
        }
        Carbon::setTestNow('2026-10-15 12:00:00 UTC');
        app(ReverseCustomerPaymentAction::class)->execute($payment, $this->owner, 'Test', '2026-10-15');
        $this->voidDocument($invoice->fresh());

        $past = $this->statement('2026-10-10')['currencies'];
        foreach (['USD', 'ILS'] as $currency) {
            $this->assertSame('0.00', $past[$currency]['closing_balance']);
            $this->assertSame('0.00', $past[$currency]['aging']['total']);
            $this->assertCount(1, array_filter($past[$currency]['entries'], fn ($row) => $row['type'] === 'currency_allocation'));
        }
        $this->assertSame('100.00', $this->statement('2026-10-16')['currencies']['USD']['aging']['total']);
        $after = $this->statement('2026-10-20')['currencies'];
        $this->assertSame('0.00', $after['USD']['closing_balance']);
        $this->assertSame('0.00', $after['USD']['aging']['total']);
        $this->assertSame('0.00', $after['ILS']['closing_balance']);
        $this->assertCount(2, array_filter($after['USD']['entries'], fn ($row) => $row['type'] === 'currency_allocation'));
    }

    public static function corruptVoids(): array
    {
        $cases = [];
        foreach (['invoice', 'return'] as $type) {
            foreach (['missing_inverse', 'foreign_company', 'wrong_original', 'wrong_source', 'wrong_document', 'missing_backlink'] as $corruption) {
                $cases[] = [$type, $corruption];
            }
        }

        return $cases;
    }

    #[DataProvider('corruptVoids')]
    public function test_incoherent_void_provenance_fails_closed(string $type, string $corruption): void
    {
        $invoice = $this->postedInvoice();
        $document = $this->voidDocument($type === 'invoice' ? $invoice : $this->postedReturn($invoice));
        $inverseId = $document->void_posting_batch_id;
        if ($corruption === 'missing_inverse') {
            DB::table($document->getTable())->where('id', $document->id)->update(['void_posting_batch_id' => null]);
        } elseif ($corruption === 'foreign_company') {
            app(CompanyContext::class)->clear();
            $other = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Other', 'base_currency_code' => 'ILS']);
            app(CompanyContext::class)->setCompany($this->company, $this->owner);
            DB::table('posting_batches')->where('id', $inverseId)->update(['company_id' => $other->id]);
        } elseif ($corruption === 'wrong_original') {
            DB::table('posting_batches')->where('id', $inverseId)->update(['reversal_of_id' => $inverseId]);
        } elseif ($corruption === 'wrong_source') {
            DB::table('posting_batches')->where('id', $inverseId)->update(['source_type' => 'opening_balance']);
        } elseif ($corruption === 'wrong_document') {
            DB::table('posting_batches')->where('id', $document->posting_batch_id)->update(['source_id' => 999999]);
        } else {
            DB::table('posting_batches')->where('id', $document->posting_batch_id)->update(['reversed_by_batch_id' => null]);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('coherent canonical void provenance');
        $this->statement('2026-10-10');
    }
}
