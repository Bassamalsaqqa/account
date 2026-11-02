<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Sales\ConvertQuotationToInvoiceAction;
use App\Actions\Sales\CreateQuotationAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\DeleteSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Livewire\Pages\Sales\InvoiceDetail;
use App\Livewire\Pages\Sales\InvoiceIndex;
use App\Livewire\Pages\Sales\QuotationDetail;
use App\Models\AuditEvent;
use App\Models\MoneyAccount;
use App\Models\PostingLine;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Services\Audit\AuditService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery;

final class SimpleDraftAndListTest extends SalesInvoicePostingAndFefoTest
{
    private function draft(): SalesInvoice
    {
        return app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1',
            'issue_date' => now()->toDateString(),
            'lines' => [['item_description' => 'Synthetic service', 'quantity' => '1', 'unit_price' => '100.123456']],
        ]);
    }

    public function test_owner_discards_only_the_draft_and_retains_an_audit_without_economic_effects(): void
    {
        $draft = $this->draft();
        $other = $this->draft();
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(InvoiceDetail::class, ['publicId' => $draft->public_id])->assertSee(__('sales.delete_draft'));
        }
        Livewire::test(InvoiceDetail::class, ['publicId' => $draft->public_id])->call('deleteDraft')->assertRedirect(route('invoices.index'));
        $this->assertDatabaseMissing('sales_invoices', ['id' => $draft->id]);
        $this->assertDatabaseMissing('sales_invoice_lines', ['sales_invoice_id' => $draft->id]);
        $this->assertNotNull($other->fresh());
        $event = AuditEvent::where('event_key', 'sales_invoice.draft_deleted')->sole();
        $this->assertSame($this->company->id, $event->company_id);
        $this->assertSame(0, PostingLine::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_stale_draft_reference_cannot_delete_a_concurrently_posted_invoice(): void
    {
        $stale = $this->draft();
        app(PostSalesInvoiceAction::class)->execute($stale->fresh(), $this->user);
        $before = PostingLine::count();
        try {
            app(DeleteSalesInvoiceDraftAction::class)->execute($stale, $this->user);
            $this->fail('Posted invoice deleted');
        } catch (\InvalidArgumentException) {
            $this->assertTrue($stale->fresh()->isPosted());
            $this->assertSame($before, PostingLine::count());
        }
    }

    public function test_audit_failure_rolls_back_the_whole_draft_delete(): void
    {
        $draft = $this->draft();
        $mock = Mockery::mock(AuditService::class);
        $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        $this->app->instance(AuditService::class, $mock);
        try {
            app(DeleteSalesInvoiceDraftAction::class)->execute($draft, $this->user);
            $this->fail('Audit failure accepted');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
            $this->assertNotNull($draft->fresh());
            $this->assertSame(1, $draft->lines()->count());
        }
    }

    public function test_missing_company_context_cannot_delete_anything(): void
    {
        $draft = $this->draft();
        app(CompanyContext::class)->clear();
        $this->expectException(AuthorizationException::class);
        app(DeleteSalesInvoiceDraftAction::class)->execute($draft, $this->user);
    }

    public function test_quotation_conversion_history_is_preserved(): void
    {
        $quote = app(CreateQuotationAction::class)->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1',
            'issue_date' => now()->toDateString(),
            'lines' => [['item_description' => 'Synthetic quotation', 'quantity' => '1', 'unit_price' => '10']],
        ]);
        Livewire::test(QuotationDetail::class, ['publicId' => $quote->public_id])->call('markAsSent')->call('markAsAccepted');
        $draft = app(ConvertQuotationToInvoiceAction::class)->execute($quote->fresh(), $this->user);
        try {
            app(DeleteSalesInvoiceDraftAction::class)->execute($draft, $this->user);
            $this->fail('Linked quotation history deleted');
        } catch (\InvalidArgumentException) {
            $this->assertNotNull($draft->fresh());
            $this->assertSame($draft->id, $quote->fresh()->converted_to_invoice_id);
        }
    }

    public function test_forged_foreign_company_cannot_delete_the_original_draft(): void
    {
        $draft = $this->draft();
        $draft->company_id = $this->company->id + 100000;
        try {
            app(DeleteSalesInvoiceDraftAction::class)->execute($draft, $this->user);
            $this->fail('Foreign company accepted');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('sales_invoices', ['id' => $draft->id, 'company_id' => $this->company->id]);
        }
    }

    public function test_staff_with_edit_permission_still_cannot_delete_an_owner_draft(): void
    {
        $draft = $this->draft();
        $this->user->syncRoles([]);
        $this->user->givePermissionTo('sales.invoice.edit_draft');
        $this->expectException(AuthorizationException::class);
        app(DeleteSalesInvoiceDraftAction::class)->execute($draft, $this->user);
    }

    public function test_invoice_list_batches_balances_and_matches_canonical_results_without_rounding(): void
    {
        $invoices = collect();
        for ($i = 0; $i < 6; $i++) {
            $invoices->push(app(PostSalesInvoiceAction::class)->execute($this->draft(), $this->user));
        }
        app(PostCustomerPaymentAction::class)->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id, 'money_account_id' => MoneyAccount::firstOrFail()->id,
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash', 'amount' => '25.000000', 'exchange_rate' => '1',
            'idempotency_key' => 'synthetic-simple-list-receipt',
            'allocations' => [['sales_invoice_id' => $invoices->first()->id, 'allocated_amount' => '25.000000']],
        ]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ($invoices as $invoice) {
            $invoice->derivedPaymentStatus();
            $invoice->calculateOutstanding();
            $invoice->derivedPaymentStatus();
            $invoice->calculateOutstanding();
        }
        $legacy = count(DB::getQueryLog());
        DB::flushQueryLog();
        $component = Livewire::test(InvoiceIndex::class);
        $balanceQueries = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/from `(customer_payment_allocations|sales_returns)`/', $q['query']))->count();
        DB::disableQueryLog();
        $this->assertSame(48, $legacy);
        $this->assertSame(2, $balanceQueries);
        $summaries = $component->viewData('summaries');
        foreach ($invoices as $invoice) {
            $this->assertSame((string) $invoice->calculateOutstanding(), (string) $summaries[$invoice->id]['outstanding']);
            $this->assertSame($invoice->derivedPaymentStatus(), $summaries[$invoice->id]['status']);
        }
        fwrite(STDOUT, "\nSYNTHETIC_BALANCE_QUERIES: six invoices, 48 legacy -> 2 batched; exact canonical balances unchanged.\n");
    }
}
