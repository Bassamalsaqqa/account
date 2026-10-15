<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessWorkflow;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Domain\Sales\Queries\CustomerLastPriceQuery;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Models\Customer;
use App\Models\PostingBatch;
use App\Models\StockMovement;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\Feature\Phase8\Phase8TestCase;

final class CustomerLastPriceTest extends Phase8TestCase
{
    public function test_latest_price_uses_posted_document_date_and_historical_unit_not_mutable_master_data(): void
    {
        $this->createAndPostPurchase(['lines' => [['quantity' => '53']]]);
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل الاختبار', 'active' => true]);
        foreach ([['2026-10-10', '17.25'], ['2026-10-08', '15.50']] as [$date, $price]) {
            $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $customer->id, 'warehouse_id' => $this->warehouse->id, 'issue_date' => $date, 'currency_code' => 'ILS', 'exchange_rate' => '1',
                'lines' => [['product_id' => $this->product->id, 'product_unit_id' => $this->unit->id, 'item_description' => 'Price cue fixture', 'quantity' => '1', 'unit_price' => $price]],
            ]);
            app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);
        }
        app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'warehouse_id' => $this->warehouse->id, 'issue_date' => '2026-10-12', 'currency_code' => 'ILS', 'exchange_rate' => '1',
            'lines' => [['product_id' => $this->product->id, 'product_unit_id' => $this->unit->id, 'item_description' => 'Draft fixture', 'quantity' => '1', 'unit_price' => '999']],
        ]);
        $originalUnit = $this->unit->unit->name_ar;
        $this->unit->unit->update(['name_ar' => 'اسم جديد']);
        $before = [PostingBatch::count(), StockMovement::count()];
        $rows = app(CustomerLastPriceQuery::class)->execute($this->company, $this->owner, $customer->id, [$this->product->id]);
        $this->assertSame('17.250000', $rows[$this->product->id]['price']);
        $this->assertSame('2026-10-10', $rows[$this->product->id]['date']);
        $this->assertSame($originalUnit, $rows[$this->product->id]['unit_ar']);
        $this->assertSame($before, [PostingBatch::count(), StockMovement::count()]);
        $this->assertArrayNotHasKey('cost', $rows[$this->product->id]);
        $page = Livewire::test(InvoiceForm::class)->set('customer_id', $customer->id)->call('selectProduct', 0, $this->product->id)
            ->assertSee('17.25')->assertSee(__('trade-cues.last_customer_price'));
        $this->owner->roles()->firstOrFail()->revokePermissionTo('reports.sales.view');
        $page->call('$refresh')->assertViewHas('lastCustomerPrices', fn ($prices) => $prices === [])->assertDontSee('17.25');
    }

    public function test_create_permission_alone_cannot_reveal_historical_prices(): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'Test', 'active' => true]);
        $actor = $this->customActor(['sales.invoice.create']);
        $this->activate($actor);
        $this->expectException(AuthorizationException::class);
        app(CustomerLastPriceQuery::class)->execute($this->company, $actor, $customer->id, [$this->product->id]);
    }
}
