<?php

declare(strict_types=1);

namespace Tests\Feature\Refinement;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Livewire\Pages\Sales\ReturnForm;
use App\Models\Customer;
use App\Models\Unit;
use App\Support\DocumentIdentity;
use App\Support\QuantityStep;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class QuantityBatchTest extends Phase5ETestCase
{
    private const IDENTITY = DocumentIdentity::FIELDS;

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function identity(array $line): array
    {
        /** @var array<string, mixed> */
        return array_combine(self::IDENTITY, array_map(fn (string $field) => $line[$field] ?? null, self::IDENTITY));
    }

    public function test_twenty_taps_apply_exactly_once_for_all_document_forms_without_economic_writes(): void
    {
        $before = $this->counts();
        foreach ([InvoiceForm::class, QuotationForm::class, PurchaseForm::class] as $type) {
            $form = Livewire::test($type)->call('selectProduct', 0, $this->product->id);
            $line = $form->get('lines.0');
            $identity = $this->identity($line);
            $form->call('batchStepQuantity', 0, '1', $identity, array_fill(0, 20, 'up'))
                ->assertReturned(['ok' => true, 'quantity' => '21', 'minimumReached' => false])
                ->assertSet('lines.0.quantity', '21');
            // A duplicate payload cannot increment the already-confirmed form a second time.
            $form->call('batchStepQuantity', 0, '1', $identity, array_fill(0, 20, 'up'))
                ->assertReturned(['ok' => false, 'message' => __('mobile.quantity_stale')])
                ->assertSet('lines.0.quantity', '21');
            $field = $type === PurchaseForm::class ? 'unit_cost' : 'unit_price';
            $this->assertSame($line[$field], $form->get('lines.0.'.$field));
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_fractional_quantum_and_directional_minimum_are_exact(): void
    {
        Unit::findOrFail($this->unit->unit_id)->update(['allows_fraction' => true, 'decimal_places' => 6]);
        $this->assertSame('0.000001', QuantityStep::forUnit(Unit::findOrFail($this->unit->unit_id)));
        foreach ([InvoiceForm::class, QuotationForm::class, PurchaseForm::class] as $type) {
            $form = Livewire::test($type)->call('selectProduct', 0, $this->product->id)
                ->set('lines.0.quantity', '0.000001');
            $form->assertSeeHtml("'0.000001'");
            $form->call('batchStepQuantity', 0, '0.000001', $this->identity($form->get('lines.0')), ['down', 'down', 'up', 'up'])
                ->assertReturned(['ok' => true, 'quantity' => '0.000003', 'minimumReached' => true])
                ->assertSet('lines.0.quantity', '0.000003')->assertHasErrors('lines.0.quantity');
            $form->call('batchStepQuantity', 0, '0.000003', $this->identity($form->get('lines.0')), ['up'])
                ->assertSet('lines.0.quantity', '0.000004')->assertHasNoErrors('lines.0.quantity');
        }
    }

    public function test_stale_economic_row_and_unit_never_change_a_different_allocation(): void
    {
        $form = Livewire::test(PurchaseForm::class)->call('selectProduct', 0, $this->product->id);
        $identity = $this->identity($form->get('lines.0'));
        $form->set('lines.0.lots', [['lot_code' => 'DIFFERENT-LOT', 'quantity' => '1']])
            ->call('batchStepQuantity', 0, '1', $identity, ['up'])
            ->assertReturned(['ok' => false, 'message' => __('mobile.quantity_stale')])
            ->assertSet('lines.0.quantity', '1');
        $form->set('lines.0.product_unit_id', 999999)
            ->call('batchStepQuantity', 0, '1', $this->identity($form->get('lines.0')), ['up'])
            ->assertSet('lines.0.quantity', '1')->assertHasErrors('lines.0.quantity');
    }

    public function test_invalid_bounds_floats_and_overflow_are_rejected_without_partial_batch_application(): void
    {
        $form = Livewire::test(InvoiceForm::class)->call('selectProduct', 0, $this->product->id);
        $identity = $this->identity($form->get('lines.0'));
        foreach ([[], array_fill(0, 101, 'up'), ['diagonal'], [1], [2 => 'up']] as $directions) {
            $form->call('batchStepQuantity', 0, '1', $identity, $directions)
                ->assertSet('lines.0.quantity', '1')->assertHasErrors('lines.0.quantity');
        }
        $form->call('batchStepQuantity', 0, 1.0, $identity, ['up'])
            ->assertSet('lines.0.quantity', '1')->assertHasErrors('lines.0.quantity');
        $form->set('lines.0.quantity', '999999999999')
            ->call('batchStepQuantity', 0, '999999999999', $identity, ['down', 'up', 'up'])
            ->assertSet('lines.0.quantity', '999999999999')->assertHasErrors('lines.0.quantity');
        $form->call('batchStepQuantity', 99, '1', $identity, ['up'])->assertHasErrors('lines.99.quantity');
    }

    public function test_batch_uses_fresh_membership_and_company_guards(): void
    {
        $form = Livewire::test(PurchaseForm::class)->call('selectProduct', 0, $this->product->id);
        $identity = $this->identity($form->get('lines.0'));
        $this->company->users()->updateExistingPivot($this->owner->id, ['status' => 'inactive']);
        $form->call('batchStepQuantity', 0, '1', $identity, ['up'])->assertForbidden();
    }

    public function test_batch_with_invalid_other_line_retains_added_quantity_and_unavailable_totals(): void
    {
        foreach ([InvoiceForm::class, QuotationForm::class, PurchaseForm::class] as $type) {
            $form = Livewire::test($type)->call('selectProduct', 0, $this->product->id)->call('addLine')
                ->set('lines.1.quantity', 'invalid');
            $form->call('batchStepQuantity', 0, '1', $this->identity($form->get('lines.0')), ['up', 'up'])
                ->assertSet('lines.0.quantity', '3')->assertSet('documentTotalsValid', false)
                ->assertSee(__('camera.totals_unavailable'));
        }
    }

    public function test_actual_js_shaped_16_key_identity_batch_step(): void
    {
        $form = Livewire::test(InvoiceForm::class)->call('selectProduct', 0, $this->product->id);
        $rawLine = $form->get('lines.0');

        // The actual JS client builds identity using:
        // const identityFields = ['id', 'product_id', 'product_unit_id', 'item_description', 'unit_price', 'unit_cost', 'discount_type', 'discount_value', 'tax_rate_id', 'tax_inclusive', 'lot_id', 'lots', 'lot_allocations', 'warehouse_id', 'purchase_line_id', 'sales_invoice_line_id'];
        // const identityFor = line => Object.fromEntries(identityFields.map(key => [key, line?.[key] ?? null]));
        $jsShapedIdentity = [];
        foreach (DocumentIdentity::FIELDS as $field) {
            $jsShapedIdentity[$field] = $rawLine[$field] ?? null;
        }

        $this->assertCount(16, $jsShapedIdentity);
        $this->assertArrayHasKey('purchase_line_id', $jsShapedIdentity);
        $this->assertArrayHasKey('sales_invoice_line_id', $jsShapedIdentity);

        $form->call('batchStepQuantity', 0, '1', $jsShapedIdentity, ['up', 'up'])
            ->assertReturned(['ok' => true, 'quantity' => '3', 'minimumReached' => false])
            ->assertSet('lines.0.quantity', '3');
    }

    public function test_return_batch_step_quantity_stale_row_and_field_rejection(): void
    {
        $this->createAndPostPurchase();

        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل مرتجع',
            'preferred_locale' => 'ar',
            'default_currency_code' => 'ILS',
            'created_by' => $this->owner->id,
            'status' => 'active',
        ]);

        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'item_description' => 'Food Item',
                'quantity' => '10',
                'unit_price' => '50.00',
            ]],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);

        $returnForm = Livewire::test(ReturnForm::class, ['invoice_id' => $invoice->id]);
        $returnLine = $returnForm->get('lines.0');
        $identity = $this->identity($returnLine);

        // 1. Wrong field is rejected
        $returnForm->call('batchStepQuantity', 0, '0', $identity, ['up'], 'quantity')
            ->assertReturned(['ok' => false, 'message' => __('mobile.quantity_stale')])
            ->assertSet('lines.0.return_quantity', '0');

        // 2. Swapped/stale expected quantity is rejected (including floats/scientific notation)
        $returnForm->call('batchStepQuantity', 0, '5', $identity, ['up'], 'return_quantity')
            ->assertReturned(['ok' => false, 'message' => __('mobile.quantity_stale')])
            ->assertSet('lines.0.return_quantity', '0');
        $returnForm->call('batchStepQuantity', 0, '1e1', $identity, ['up'], 'return_quantity')
            ->assertReturned(['ok' => false, 'message' => __('mobile.quantity_stale')])
            ->assertSet('lines.0.return_quantity', '0');
        $returnForm->call('batchStepQuantity', 0, 10, $identity, ['up'], 'return_quantity')
            ->assertReturned(['ok' => false, 'message' => __('mobile.quantity_stale')])
            ->assertSet('lines.0.return_quantity', '0');

        // 3. Tampered identity (e.g. wrong product_id or sales_invoice_line_id) is rejected
        $tamperedIdentity = $identity;
        $tamperedIdentity['product_id'] = 999999;
        $returnForm->call('batchStepQuantity', 0, '0', $tamperedIdentity, ['up'], 'return_quantity')
            ->assertReturned(['ok' => false, 'message' => __('mobile.quantity_stale')])
            ->assertSet('lines.0.return_quantity', '0');

        // 4. Valid batch step succeeds and clamps to returnable cap
        $returnForm->call('batchStepQuantity', 0, '0', $identity, ['up', 'up'], 'return_quantity')
            ->assertReturned(['ok' => true, 'quantity' => '2', 'minimumReached' => false])
            ->assertSet('lines.0.return_quantity', '2');
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $counts = [];
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'sales_invoices', 'purchases'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
