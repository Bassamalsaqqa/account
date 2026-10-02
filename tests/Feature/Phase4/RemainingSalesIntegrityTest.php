<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateQuotationAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Actions\Sales\VoidSalesReturnAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\Exceptions\InvalidQuantityException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\Exceptions\InvalidMoneyException;
use App\Domain\Sales\Queries\CustomerBalanceQuery;
use App\Domain\Sales\Queries\CustomerCreditLimitQuery;
use App\Exceptions\IdempotencyConflictException;
use App\Livewire\Pages\Customers\CustomerIndex;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\PaymentForm;
use App\Models\CustomerPayment;
use App\Models\InventoryCostState;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\PublicShare;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Sales\PublicShareService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Throwable;

final class RemainingSalesIntegrityTest extends SalesInvoicePostingAndFefoTest
{
    private function draft(array $extra = []): SalesInvoice
    {
        return app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS', 'exchange_rate' => '1',
            'issue_date' => '2026-10-02', 'due_date' => '2026-11-02',
            'lines' => [array_merge(['item_description' => 'Remaining probe service', 'quantity' => '1', 'unit_price' => '100'], $extra)],
        ]);
    }

    private function posted(array $extra = []): SalesInvoice
    {
        return app(PostSalesInvoiceAction::class)->execute($this->draft($extra), $this->user);
    }

    private function payment(array $extra = []): CustomerPayment
    {
        return app(PostCustomerPaymentAction::class)->execute($this->company, $this->user, array_merge([
            'customer_id' => $this->customer->id, 'money_account_id' => MoneyAccount::firstOrFail()->id,
            'payment_date' => '2026-10-02', 'payment_method' => 'cash',
            'amount' => '100', 'exchange_rate' => '1', 'idempotency_key' => 'remaining-receipt-1',
        ], $extra));
    }

    private function inbound(string $qty, string $cost, string $key, string $lot = 'A'): void
    {
        $this->inventoryService->record(new StockMovementCommand(
            companyId: $this->company->id, movementType: 'opening_balance', movementDate: '2026-10-01',
            lines: [new StockMovementLineCommand(productId: $this->product->id, warehouseId: $this->warehouse->id,
                quantity: Quantity::of($qty), unitCostBase: $cost, lotNumber: $lot, expiryDate: '2027-12-31')],
            sourceType: 'probe', sourceId: 1, idempotencyKey: $key, createdBy: $this->user->id,
        ));
    }

    public function test_probe_direct_status_void_is_not_a_canonical_reversal(): void
    {
        $invoice = $this->posted();
        try {
            $invoice->update(['status' => 'void']);
        } catch (Throwable) {
        }
        $this->assertSame('posted', $invoice->fresh()->status, 'Direct status update bypassed stock/accounting reversal');
    }

    public function test_probe_direct_payment_reversal_metadata_is_rejected(): void
    {
        $payment = $this->payment();
        try {
            $payment->update(['is_reversed' => true]);
        } catch (Throwable) {
        }
        $this->assertFalse($payment->fresh()->is_reversed, 'Direct metadata update bypassed accounting reversal');
    }

    public function test_probe_unauthenticated_draft_actor_is_rejected(): void
    {
        auth()->logout();
        $this->expectException(AuthorizationException::class);
        $this->draft();
    }

    public function test_probe_quote_action_requires_permission(): void
    {
        $this->user->syncRoles([]);
        $this->user->unsetRelation('roles')->unsetRelation('permissions');
        $this->expectException(AuthorizationException::class);
        app(CreateQuotationAction::class)->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1',
            'issue_date' => '2026-10-02', 'lines' => [['item_description' => 'Unauthorized quote', 'quantity' => '1', 'unit_price' => '100']],
        ]);
    }

    public function test_probe_foreign_receipt_fractional_base_can_post(): void
    {
        $account = app(CreateMoneyAccountAction::class)->execute($this->company, $this->user, [
            'account_type' => 'cash', 'name_ar' => 'USD probe cash', 'currency_code' => 'USD',
        ]);
        $payment = $this->payment(['money_account_id' => $account->id, 'amount' => '0.01', 'exchange_rate' => '3.55']);
        $this->assertNotNull($payment->posting_batch_id);
    }

    public function test_probe_receipt_form_lost_response_retry_is_idempotent(): void
    {
        $component = Livewire::test(PaymentForm::class)
            ->set('customer_id', $this->customer->id)
            ->set('money_account_id', MoneyAccount::firstOrFail()->id)
            ->set('payment_date', '2026-10-02')->set('amount', '100')->set('exchange_rate', '1');
        $component->call('save')->assertHasNoErrors();
        $component->call('save')->assertHasNoErrors();
        $this->assertSame(1, CustomerPayment::count(), 'Same form state creates a second receipt because no stable request key is passed');
    }

    public function test_ordinary_inventory_operation_rejects_total_value_override(): void
    {
        $this->inbound('3', '1', 'basis');
        $before = StockMovement::count();
        try {
            $this->inventoryService->record(new StockMovementCommand(companyId: $this->company->id,
                movementType: 'adjustment_increase', movementDate: '2026-10-02',
                lines: [new StockMovementLineCommand(productId: $this->product->id, warehouseId: $this->warehouse->id,
                    quantity: Quantity::of('1'), valueDeltaBase: '999')],
                sourceType: 'probe', sourceId: 1, idempotencyKey: 'illegal-total', createdBy: $this->user->id));
            $this->fail('Ordinary value override was accepted.');
        } catch (InvalidInventoryMovementException) {
            $this->assertSame($before, StockMovement::count());
        }
    }

    public function test_probe_warehouse_actor_cannot_override_implicit_adjustment_value(): void
    {
        $this->inbound('1', '1', 'basis');
        $this->user->syncRoles(['Warehouse']);
        $this->user->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertFalse($this->user->hasPermissionTo('inventory.cost.view'));
        $rejected = false;
        try {
            $this->inventoryService->record(new StockMovementCommand(
                companyId: $this->company->id, movementType: 'adjustment_increase', movementDate: '2026-10-02',
                lines: [new StockMovementLineCommand(productId: $this->product->id, warehouseId: $this->warehouse->id,
                    quantity: Quantity::of('1'), unitCostBase: null, lotNumber: 'A', expiryDate: '2027-12-31', valueDeltaBase: '999')],
                sourceType: 'probe', sourceId: 1, idempotencyKey: 'unauthorized-value', createdBy: $this->user->id,
            ));
        } catch (Throwable) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'Stock-adjust-only actor injected an explicit arbitrary valuation');
    }

    public function test_probe_exact_void_remains_replayable(): void
    {
        $this->inbound('1', '1', 'residual-a');
        $this->inbound('2', '0', 'residual-b');
        $invoice = $this->posted(['product_id' => $this->product->id, 'product_unit_id' => $this->product->productUnits()->where('is_base', true)->firstOrFail()->id, 'quantity' => '3']);
        app(VoidSalesInvoiceAction::class)->execute($invoice, $this->user, 'Replay probe void');
        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertSame([], $report->historyCorruptions, implode("\n", $report->historyCorruptions));
    }

    public function test_probe_full_return_restores_exact_original_cogs_residual(): void
    {
        $this->inbound('1', '1', 'return-a');
        $this->inbound('2', '0', 'return-b');
        $invoice = $this->posted(['product_id' => $this->product->id, 'product_unit_id' => $this->product->productUnits()->where('is_base', true)->firstOrFail()->id, 'quantity' => '3']);
        $return = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->user, [
            'sales_invoice_id' => $invoice->id, 'issue_date' => '2026-10-02',
            'lines' => [['sales_invoice_line_id' => $invoice->lines->first()->id, 'quantity' => '3']],
        ]);
        app(PostSalesReturnAction::class)->execute($return, $this->user);
        $this->assertSame('1.000000', InventoryCostState::where('product_id', $this->product->id)->firstOrFail()->inventory_value_base);
    }

    public function test_probe_duplicate_return_lines_respect_one_original_quantity(): void
    {
        $invoice = $this->posted();
        $line = ['sales_invoice_line_id' => $invoice->lines->first()->id, 'quantity' => '1'];
        $rejected = false;
        try {
            $return = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->user, [
                'sales_invoice_id' => $invoice->id, 'issue_date' => '2026-10-02', 'lines' => [$line, $line],
            ]);
            app(PostSalesReturnAction::class)->execute($return, $this->user);
        } catch (Throwable) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'Same original line was returned twice within one document');
    }

    public function test_probe_repeated_returns_consume_original_lot_quantities(): void
    {
        $this->inbound('10', '20', 'three-lot-a', 'A');
        $this->inbound('10', '20', 'three-lot-b', 'B');
        $this->inbound('10', '20', 'three-lot-c', 'C');
        $invoice = $this->posted(['product_id' => $this->product->id, 'product_unit_id' => $this->product->productUnits()->where('is_base', true)->firstOrFail()->id, 'quantity' => '25']);
        foreach (['14', '11'] as $qty) {
            $return = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->user, [
                'sales_invoice_id' => $invoice->id, 'issue_date' => '2026-10-02',
                'lines' => [['sales_invoice_line_id' => $invoice->lines->first()->id, 'quantity' => $qty]],
            ]);
            app(PostSalesReturnAction::class)->execute($return, $this->user);
        }
        $lotB = InventoryLot::where('product_id', $this->product->id)->where('lot_number', 'B')->firstOrFail();
        $this->assertSame('10.000000', InventoryLotBalance::where('lot_id', $lotB->id)->firstOrFail()->quantity_base,
            'Return planning does not restore the original per-lot quantities across repeated split returns');
    }

    public function test_probe_invoice_pdf_uses_posted_customer_identity(): void
    {
        $invoice = $this->posted();
        $originalName = $invoice->customer_snapshot['name_ar'];
        $this->customer->update(['name_ar' => 'Renamed after posting']);
        $html = view('pdf.sales-invoice', ['invoice' => $invoice, 'company' => $this->company,
            'customer' => $this->customer->fresh(), 'lines' => $invoice->lines, 'qrDataUri' => null, 'locale' => 'ar', 'isRtl' => true])->render();
        $this->assertStringContainsString($originalName, $html, 'PDF still renders mutable customer identity');
    }

    public function test_cumulative_foreign_receipts_relieve_exact_final_book_residual(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id, 'currency_code' => 'USD', 'exchange_rate' => '3.3333333333',
            'issue_date' => '2026-10-02', 'due_date' => '2026-11-02',
            'lines' => [['item_description' => 'Small settlement', 'quantity' => '1', 'unit_price' => '0.03']],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($invoice, $this->user);
        $account = app(CreateMoneyAccountAction::class)->execute($this->company, $this->user, ['account_type' => 'cash', 'name_ar' => 'USD', 'currency_code' => 'USD']);
        $book = BigDecimal::zero();
        foreach ([1, 2, 3] as $part) {
            $payment = $this->payment(['idempotency_key' => 'partial-'.$part, 'money_account_id' => $account->id, 'amount' => '0.01', 'exchange_rate' => '3.55',
                'allocations' => [['sales_invoice_id' => $invoice->id, 'allocated_amount' => '0.01']]]);
            $book = $book->plus($payment->allocations->first()->base_amount_applied_to_receivable);
        }
        $this->assertSame('0.100000', (string) $book);
        $this->assertTrue($invoice->calculateOutstanding()->isZero());
        $report = app(SalesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($report->isHealthy, implode("\n", $report->violations));
    }

    public function test_receipt_identity_is_canonical_and_payload_changes_conflict(): void
    {
        $one = $this->posted();
        $two = $this->posted();
        $allocations = [['sales_invoice_id' => $one->id, 'allocated_amount' => '40'], ['sales_invoice_id' => $two->id, 'allocated_amount' => '60']];
        $first = $this->payment(['allocations' => $allocations]);
        $retry = $this->payment(['amount' => '100.000000', 'allocations' => array_reverse($allocations)]);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, CustomerPayment::count());
        $before = PostingBatch::count();
        try {
            $this->payment(['amount' => '101', 'allocations' => $allocations]);
            $this->fail('Changed receipt request was accepted.');
        } catch (IdempotencyConflictException) {
            $this->assertSame($before, PostingBatch::count());
        }
    }

    public function test_direct_post_and_child_append_cannot_forge_financial_history(): void
    {
        $draft = $this->draft();
        try {
            $draft->update(['status' => 'posted']);
            $this->fail('Draft finalized without posting.');
        } catch (ImmutableRecordException) {
            $this->assertSame('draft', $draft->fresh()->status);
            $this->assertSame(0, PostingBatch::count());
        }
        $posted = app(PostSalesInvoiceAction::class)->execute($draft->fresh(), $this->user);
        $attributes = $posted->lines->first()->getAttributes();
        unset($attributes['id'], $attributes['public_id']);
        try {
            SalesInvoiceLine::create($attributes);
            $this->fail('A line was appended to posted history.');
        } catch (ImmutableRecordException) {
            $this->assertSame(1, $posted->lines()->count());
        }
    }

    public function test_reconciliation_checks_actual_gl_and_stock_without_mutating(): void
    {
        $this->inbound('3', '1', 'reconcile-basis');
        $invoice = $this->posted(['product_id' => $this->product->id, 'product_unit_id' => $this->product->productUnits()->where('is_base', true)->firstOrFail()->id, 'quantity' => '2']);
        $audit = app(SalesReconciliationService::class);
        $this->assertTrue($audit->reconcile($this->company)->isHealthy);
        $allocation = $invoice->lotAllocations->first();
        DB::table('stock_movements')->where('id', $allocation->stock_movement_id)->update(['value_delta_base' => '-99']);
        $snapshot = DB::table('stock_movements')->where('id', $allocation->stock_movement_id)->first();
        $this->assertFalse($audit->reconcile($this->company)->isHealthy);
        $this->assertEquals($snapshot, DB::table('stock_movements')->where('id', $allocation->stock_movement_id)->first());
        DB::table('stock_movements')->where('id', $allocation->stock_movement_id)->update(['value_delta_base' => '-2']);
        $line = PostingLine::where('posting_batch_id', $invoice->posting_batch_id)->where('debit_base', '>', 0)->firstOrFail();
        DB::table('posting_lines')->where('id', $line->id)->update(['debit_base' => '999']);
        $this->assertFalse($audit->reconcile($this->company)->isHealthy);
        $this->assertSame('999.000000', PostingLine::findOrFail($line->id)->debit_base);
    }

    public function test_guest_statement_is_explicit_jod_data_and_contains_no_model_or_cost(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id, 'currency_code' => 'JOD', 'exchange_rate' => '5',
            'issue_date' => '2026-10-02', 'due_date' => '2026-11-02',
            'lines' => [['item_description' => 'JOD precision', 'quantity' => '1', 'unit_price' => '0.001']],
        ]);
        app(PostSalesInvoiceAction::class)->execute($invoice, $this->user);
        $service = app(PublicShareService::class);
        $share = $service->createShare($this->company, $this->user, PublicShare::SUBJECT_CUSTOMER_STATEMENT, $this->customer->id);
        app(CompanyContext::class)->clear();
        auth()->logout();
        $result = $service->resolvePublicShare($share['raw_token']);
        $this->assertSame('success', $result['status']);
        $json = json_encode($result['data'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('cogs', $json);
        $this->assertStringNotContainsString('App\\Models', $json);
        $this->assertSame('0.001', $result['data']['statement']['currencies']['JOD']['closing_balance']);
        $this->get('/share/'.$share['raw_token'])->assertOk()->assertSee('0.001');
    }

    public function test_draft_quantity_and_financial_total_tampering_is_rejected_atomically(): void
    {
        $this->inbound('5', '2', 'tamper-basis');
        $draft = $this->draft(['product_id' => $this->product->id, 'product_unit_id' => $this->product->productUnits()->where('is_base', true)->firstOrFail()->id]);
        $draft->lines->first()->update(['quantity_base' => '4']);
        try {
            app(PostSalesInvoiceAction::class)->execute($draft, $this->user);
            $this->fail('Tampered draft was posted.');
        } catch (\InvalidArgumentException) {
            $this->assertSame('draft', $draft->fresh()->status);
            $this->assertSame(1, StockMovement::count());
            $this->assertSame(0, PostingBatch::count());
        }
        $draft->lines->first()->update(['quantity_base' => '1']);
        $draft->update(['grand_total_currency' => '999']);
        try {
            app(PostSalesInvoiceAction::class)->execute($draft, $this->user);
            $this->fail('Forged header total was posted.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, PostingBatch::count());
            $this->assertNull($draft->fresh()->invoice_number);
        }
    }

    public function test_discounted_inclusive_tax_partial_then_full_return_restores_original_credit(): void
    {
        $taxAccount = LedgerAccount::create(['company_id' => $this->company->id, 'code' => '2199',
            'name_ar' => 'Configured output tax', 'account_type' => 'liability', 'normal_balance' => 'credit', 'active' => true, 'is_control' => false]);
        $tax = TaxRate::create(['company_id' => $this->company->id, 'code' => 'INC20', 'name_ar' => 'Tax',
            'rate' => '0.20', 'calculation' => 'inclusive', 'active' => true, 'sales_tax_account_id' => $taxAccount->id]);
        $invoice = $this->posted(['quantity' => '3', 'unit_price' => '40', 'discount_type' => 'fixed', 'discount_value' => '12', 'tax_rate_id' => $tax->id]);
        $this->assertSame('108.000000', $invoice->grand_total_currency);
        $this->assertSame('18.000000', $invoice->tax_total_currency);
        $totals = BigDecimal::zero();
        $taxTotals = BigDecimal::zero();
        foreach (['1', '2'] as $quantity) {
            $return = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->user, [
                'sales_invoice_id' => $invoice->id, 'issue_date' => '2026-10-02',
                'lines' => [['sales_invoice_line_id' => $invoice->lines->first()->id, 'quantity' => $quantity]],
            ]);
            $return = app(PostSalesReturnAction::class)->execute($return, $this->user);
            $totals = $totals->plus($return->grand_total_currency);
            $taxTotals = $taxTotals->plus($return->tax_total_currency);
            $this->assertTrue(PostingLine::where('posting_batch_id', $return->posting_batch_id)
                ->where('ledger_account_id', $taxAccount->id)->where('debit_base', '>', 0)->exists());
            $audit = app(SalesReconciliationService::class)->reconcile($this->company);
            $this->assertTrue($audit->isHealthy, implode("\n", $audit->violations));
        }
        $this->assertSame('108.000000', (string) $totals);
        $this->assertSame('18.000000', (string) $taxTotals);
        $this->assertTrue($invoice->calculateOutstanding()->isZero());
    }

    public function test_return_void_with_changed_average_rejects_without_partial_reversal(): void
    {
        $this->inbound('10', '2', 'original-value');
        $invoice = $this->posted(['product_id' => $this->product->id, 'product_unit_id' => $this->product->productUnits()->where('is_base', true)->firstOrFail()->id, 'quantity' => '2']);
        $return = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->user, [
            'sales_invoice_id' => $invoice->id, 'issue_date' => '2026-10-02',
            'lines' => [['sales_invoice_line_id' => $invoice->lines->first()->id, 'quantity' => '2']],
        ]);
        $return = app(PostSalesReturnAction::class)->execute($return, $this->user);
        $this->inbound('10', '4', 'changed-average', 'B');
        $before = [StockMovement::count(), PostingBatch::count(), InventoryCostState::firstOrFail()->getAttributes()];
        try {
            app(VoidSalesReturnAction::class)->execute($return, $this->user, 'Unsafe after changed valuation');
            $this->fail('Incoherent reversal was accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertSame($before, [StockMovement::count(), PostingBatch::count(), InventoryCostState::firstOrFail()->getAttributes()]);
            $this->assertSame('posted', $return->fresh()->status);
            $this->assertNull($return->fresh()->reversal_posting_batch_id);
        }
    }

    public function test_pdf_permission_is_independent_of_document_view_and_snapshots_use_document_locale(): void
    {
        $invoice = $this->posted();
        $original = $invoice->customer_snapshot['name_ar'];
        $this->customer->update(['name_ar' => 'Renamed customer']);
        $this->company->update(['name_ar' => 'Renamed company']);
        app()->setLocale('en');
        $this->get('/pdf/invoice/'.$invoice->public_id.'?format=print')->assertOk()->assertSee($original)->assertDontSee('Renamed customer')->assertSee('dir="rtl"', false);
        $this->user->syncRoles([]);
        $this->user->givePermissionTo('sales.invoice.view');
        $this->user->unsetRelation('roles')->unsetRelation('permissions');
        $this->get('/pdf/invoice/'.$invoice->public_id)->assertForbidden();
    }

    public function test_actual_return_and_invoice_routes_render_existing_posted_documents(): void
    {
        $invoice = $this->posted();
        $this->get('/invoices')->assertOk()->assertSee($invoice->invoice_number);
        $this->get('/customers/'.$this->customer->public_id.'/statement')->assertOk()->assertSee($invoice->invoice_number);
        $this->get('/returns/create?invoice_id='.$invoice->id)->assertOk()->assertSee('Remaining probe service');
        $this->get('/payments/create?customer_id='.$this->customer->id.'&invoice_id='.$invoice->id)->assertOk()->assertSee($invoice->invoice_number);
    }

    public function test_invalid_exact_sales_inputs_fail_without_persisting_a_draft(): void
    {
        foreach ([['quantity' => '0'], ['quantity' => '-1'], ['quantity' => 1.25], ['unit_price' => 0.01], ['discount_value' => 0.25], ['discount_type' => 'percent', 'discount_value' => '101']] as $line) {
            try {
                $this->draft($line);
                $this->fail('Malformed exact input was persisted.');
            } catch (\TypeError|\InvalidArgumentException|InvalidQuantityException|InvalidMoneyException) {
                $this->assertSame(0, SalesInvoice::count());
                $this->assertSame(0, PostingBatch::count());
                $this->assertSame(0, StockMovement::count());
            }
        }
    }

    public function test_revoked_membership_is_revalidated_for_normal_creation_actions(): void
    {
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->user->id)->update(['status' => 'inactive']);
        $operations = [
            fn () => $this->draft(),
            fn () => app(CreateQuotationAction::class)->execute($this->company, $this->user, [
                'customer_id' => $this->customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1', 'issue_date' => '2026-10-02',
                'lines' => [['item_description' => 'Unauthorized', 'quantity' => '1', 'unit_price' => '1']],
            ]),
            fn () => app(CreateMoneyAccountAction::class)->execute($this->company, $this->user, ['account_type' => 'cash', 'name_ar' => 'Unauthorized', 'currency_code' => 'ILS']),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('Inactive membership performed an operation.');
            } catch (AuthorizationException) {
                $this->assertSame(0, SalesInvoice::count());
                $this->assertSame(0, Quotation::count());
                $this->assertSame(1, MoneyAccount::count());
                $this->assertSame(0, PostingBatch::count());
            }
        }
    }

    public function test_system_bootstrap_rejects_context_and_preserves_custom_role_grants(): void
    {
        $role = Role::where('company_id', $this->company->id)->where('name', 'Sales')->firstOrFail();
        $role->syncPermissions(['customers.view']);
        $this->assertSame(1, Artisan::call('sales:bootstrap', ['--all' => true]));
        app(CompanyContext::class)->clear();
        $this->assertSame(0, Artisan::call('sales:bootstrap', ['--all' => true]));
        $this->assertSame(0, Artisan::call('sales:bootstrap', ['--all' => true]));
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $this->assertSame(['customers.view'], $role->fresh()->permissions->pluck('name')->all());
        $this->assertSame(0, CustomerPayment::count());
        $this->assertSame(0, TaxRate::count());
        $this->assertSame(0, PostingBatch::count());
    }

    public function test_customer_balance_groups_currencies_and_includes_unallocated_receipt_credit(): void
    {
        $this->posted();
        $this->payment(['amount' => '150']);
        $foreign = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id, 'currency_code' => 'USD', 'exchange_rate' => '3.5',
            'issue_date' => '2026-10-02', 'due_date' => '2026-11-02',
            'lines' => [['item_description' => 'Foreign service', 'quantity' => '1', 'unit_price' => '100']],
        ]);
        app(PostSalesInvoiceAction::class)->execute($foreign, $this->user);
        $balances = app(CustomerBalanceQuery::class)->execute([$this->customer->id])[$this->customer->id];
        $this->assertSame('-50.000000', $balances['ILS']['outstanding']);
        $this->assertSame('100.000000', $balances['USD']['outstanding']);
        Livewire::test(CustomerIndex::class)
            ->assertSee('-50.00')->assertSee('100.00')->assertSee('USD');
    }

    public function test_projected_credit_limit_is_a_warning_and_does_not_block_authorized_posting(): void
    {
        $this->customer->update(['credit_limit' => '120']);
        $this->posted();
        Livewire::test(InvoiceForm::class)
            ->set('customer_id', $this->customer->id)
            ->set('lines.0.item_description', 'Warning only service')
            ->set('lines.0.unit_price', '50')
            ->call('recalculate')
            ->assertSee(__('sales.credit_limit_warning'));
        $next = $this->posted(['unit_price' => '50']);
        $this->assertSame('posted', $next->status);
        $this->assertTrue(app(CustomerCreditLimitQuery::class)->exceeds($this->customer, BigDecimal::zero()));
    }
}
