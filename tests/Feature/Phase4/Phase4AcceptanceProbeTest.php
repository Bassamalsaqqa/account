<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Livewire\Pages\Customers\CustomerDetail;
use App\Models\CustomerPayment;
use App\Models\InventoryCostState;
use App\Models\MoneyAccount;
use App\Models\SalesInvoice;
use App\Models\TaxRate;
use App\Services\Sales\PublicShareService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Throwable;

final class Phase4AcceptanceProbeTest extends SalesInvoicePostingAndFefoTest
{
    private function draft(string $price = '100', string $currency = 'ILS', string $rate = '1', array $extra = []): SalesInvoice
    {
        return app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'issue_date' => '2026-10-02',
            'due_date' => '2026-11-02',
            'lines' => [array_merge(['item_description' => 'Probe service', 'quantity' => '1', 'unit_price' => $price], $extra)],
        ]);
    }

    private function posted(string $price = '100', array $extra = []): SalesInvoice
    {
        return app(PostSalesInvoiceAction::class)->execute($this->draft($price, 'ILS', '1', $extra), $this->user);
    }

    private function receipt(array $extra = []): CustomerPayment
    {
        return app(PostCustomerPaymentAction::class)->execute($this->company, $this->user, array_merge([
            'customer_id' => $this->customer->id,
            'money_account_id' => MoneyAccount::firstOrFail()->id,
            'payment_date' => '2026-10-02',
            'idempotency_key' => 'receipt-'.__FUNCTION__,
            'payment_method' => 'cash',
            'amount' => '100',
            'exchange_rate' => '1',
            'idempotency_key' => 'codex-receipt-request-1',
        ], $extra));
    }

    public function test_probe_posted_invoice_rejects_direct_mutation(): void
    {
        $invoice = $this->posted();
        try {
            $invoice->update(['grand_total_currency' => '1.000000']);
        } catch (Throwable) {
        }
        $this->assertSame('100.000000', $invoice->fresh()->grand_total_currency, 'Posted invoice must be immutable');
    }

    public function test_probe_receipt_retry_returns_original_result(): void
    {
        $a = $this->receipt();
        $b = $this->receipt();
        $this->assertSame($a->id, $b->id, 'Same caller request must not create a second receipt');
    }

    public function test_probe_duplicate_invoice_allocations_cannot_overpay(): void
    {
        $inv = $this->posted();
        try {
            $this->receipt(['amount' => '120', 'allocations' => [
                ['sales_invoice_id' => $inv->id, 'allocated_amount' => '60'],
                ['sales_invoice_id' => $inv->id, 'allocated_amount' => '60'],
            ]]);
        } catch (Throwable) {
        }
        $this->assertTrue($inv->fresh()->calculateOutstanding()->isGreaterThanOrEqualTo(0), 'Duplicate allocation entries overpaid invoice');
    }

    public function test_probe_foreign_minor_amount_can_post_with_exact_metadata(): void
    {
        $invoice = app(PostSalesInvoiceAction::class)->execute($this->draft('0.01', 'USD', '3.55'), $this->user);
        $this->assertTrue($invoice->isPosted());
    }

    public function test_probe_inclusive_tax_posts_balanced_revenue(): void
    {
        $tax = TaxRate::create(['company_id' => $this->company->id, 'code' => 'INC20', 'name_ar' => 'Tax', 'rate' => '20.000000', 'calculation' => 'inclusive', 'active' => true]);
        $invoice = $this->posted('120', ['tax_rate_id' => $tax->id]);
        $this->assertTrue($invoice->isPosted());
    }

    public function test_probe_guest_share_contains_its_document_lines(): void
    {
        $invoice = $this->posted();
        $service = app(PublicShareService::class);
        $link = $service->createShare($this->company, $this->user, 'sales_invoice', $invoice->id);
        app(CompanyContext::class)->clear();
        auth()->logout();
        $result = $service->resolvePublicShare($link['raw_token']);
        $this->assertCount(1, $result['data']['lines'], 'Public document lost lines without tenant context');
    }

    public function test_probe_public_document_preserves_posted_identity(): void
    {
        $invoice = $this->posted();
        $oldName = $invoice->customer_snapshot['name_ar'];
        $link = app(PublicShareService::class)->createShare($this->company, $this->user, 'sales_invoice', $invoice->id);
        $this->customer->update(['name_ar' => 'Renamed after posting']);
        $data = app(PublicShareService::class)->buildWhitelistedData($link['share']);
        $this->assertSame($oldName, $data['customer']['name'], 'Share must use historical customer identity');
    }

    public function test_probe_return_preserves_original_discount(): void
    {
        $invoice = $this->posted('100', ['discount_type' => 'fixed', 'discount_value' => '20']);
        $return = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->user, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => '2026-10-02',
            'lines' => [['sales_invoice_line_id' => $invoice->lines->first()->id, 'quantity' => '1']],
        ]);
        $this->assertSame('80.000000', $return->grand_total_currency, 'Full return must not credit undiscounted price');
    }

    public function test_probe_reversed_payment_statement_net_is_zero(): void
    {
        $p = $this->receipt();
        app(ReverseCustomerPaymentAction::class)->execute($p, $this->user, 'Probe reversal');
        $statement = app(CustomerStatementQuery::class)->execute($this->customer);
        $this->assertSame('0.00', $statement['currencies']['ILS']['closing_balance'], 'Receipt plus reversal must cancel exactly');
    }

    public function test_probe_nonstock_return_rechecks_quantity_at_posting(): void
    {
        $invoice = $this->posted();
        $payload = ['sales_invoice_id' => $invoice->id, 'issue_date' => '2026-10-02', 'lines' => [['sales_invoice_line_id' => $invoice->lines->first()->id, 'quantity' => '1']]];
        $a = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->user, $payload);
        $b = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->user, $payload);
        app(PostSalesReturnAction::class)->execute($a, $this->user);
        try {
            app(PostSalesReturnAction::class)->execute($b, $this->user);
        } catch (Throwable) {
        }
        $this->assertSame('draft', $b->fresh()->status, 'Second service return over-credits original invoice');
    }

    public function test_probe_draft_action_requires_create_permission(): void
    {
        $this->user->syncRoles([]);
        $this->user->unsetRelation('roles')->unsetRelation('permissions');
        $rejected = false;
        try {
            $this->draft();
        } catch (AuthorizationException) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'Direct draft creation bypasses domain authorization');
    }

    public function test_probe_customer_invoice_tab_uses_real_schema(): void
    {
        $this->posted();
        Livewire::test(CustomerDetail::class, ['publicId' => $this->customer->public_id])->set('activeTab', 'invoices')->assertStatus(200);
    }

    private function inbound(string $qty, string $cost, string $key, string $lot = 'PROBE-LOT'): void
    {
        $this->inventoryService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: 'opening_balance',
            movementDate: '2026-10-01',
            lines: [new StockMovementLineCommand(
                productId: $this->product->id,
                warehouseId: $this->warehouse->id,
                quantity: Quantity::of($qty),
                unitCostBase: $cost,
                lotNumber: $lot,
                expiryDate: '2027-12-31'
            )],
            sourceType: 'probe',
            sourceId: 1,
            idempotencyKey: $key,
            createdBy: $this->user->id,
        ));
    }

    private function stockInvoice(string $qty): SalesInvoice
    {
        return $this->posted('50', ['product_id' => $this->product->id, 'product_unit_id' => $this->productUnit->id, 'quantity' => $qty]);
    }

    public function test_probe_full_depletion_void_restores_exact_residual_value(): void
    {
        $this->inbound('1', '1', 'probe-stock-a');
        $this->inbound('2', '0', 'probe-stock-b');
        $inv = $this->stockInvoice('3');
        app(VoidSalesInvoiceAction::class)->execute($inv, $this->user, 'Probe void');
        $this->assertSame('1.000000', InventoryCostState::where('product_id', $this->product->id)->firstOrFail()->inventory_value_base, 'Void must restore exact original value including residual');
    }

    public function test_probe_split_return_line_cogs_equals_all_movements(): void
    {
        $this->inbound('10', '20', 'probe-lot-a', 'A');
        $this->inbound('10', '20', 'probe-lot-b', 'B');
        $invoice = $this->stockInvoice('15');
        foreach (['6', '6'] as $qty) {
            $ret = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->user, [
                'sales_invoice_id' => $invoice->id,
                'issue_date' => '2026-10-02',
                'lines' => [['sales_invoice_line_id' => $invoice->lines->first()->id, 'quantity' => $qty]],
            ]);
            $ret = app(PostSalesReturnAction::class)->execute($ret, $this->user);
        }
        $this->assertSame($ret->cogs_total_base, $ret->lines->first()->cogs_total_base, 'FEFO split overwrote rather than accumulated return-line COGS');
    }
}
