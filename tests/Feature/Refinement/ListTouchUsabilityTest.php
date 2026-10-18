<?php

declare(strict_types=1);

namespace Tests\Feature\Refinement;

use App\Livewire\Pages\Catalog\BrandIndex;
use App\Livewire\Pages\Catalog\CategoryIndex;
use App\Livewire\Pages\Catalog\UnitIndex;
use App\Livewire\Pages\Catalog\WarehouseIndex;
use App\Livewire\Pages\Customers\CustomerIndex;
use App\Livewire\Pages\Expenses\ExpenseCategoryIndex;
use App\Livewire\Pages\Expenses\ExpenseIndex;
use App\Livewire\Pages\Inventory\ExpiryCenter;
use App\Livewire\Pages\Inventory\InventoryOverview;
use App\Livewire\Pages\Inventory\StockMovementIndex;
use App\Livewire\Pages\Money\CheckIndex;
use App\Livewire\Pages\Money\TransferIndex;
use App\Livewire\Pages\Payroll\EmployeeIndex;
use App\Livewire\Pages\Products\ProductIndex;
use App\Livewire\Pages\Purchasing\PaymentIndex as VendorPaymentIndex;
use App\Livewire\Pages\Purchasing\PurchaseIndex;
use App\Livewire\Pages\Purchasing\PurchaseReturnIndex;
use App\Livewire\Pages\Purchasing\VendorIndex;
use App\Livewire\Pages\Sales\InvoiceIndex;
use App\Livewire\Pages\Sales\PaymentIndex;
use App\Livewire\Pages\Sales\QuotationIndex;
use App\Livewire\Pages\Sales\ReturnIndex;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class ListTouchUsabilityTest extends Phase5ETestCase
{
    public function test_bilingual_daily_lists_render_without_economic_mutation(): void
    {
        $this->createAndPostPurchase();
        Employee::create(['company_id' => $this->company->id, 'code' => 'TOUCH-EMP', 'name' => 'Pilot employee',
            'default_salary' => '6789.12', 'salary_currency_code' => 'ILS', 'created_by' => $this->owner->id]);
        $before = $this->economicFingerprint();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ([BrandIndex::class, CategoryIndex::class, UnitIndex::class, WarehouseIndex::class,
                CustomerIndex::class, VendorIndex::class, ProductIndex::class, InvoiceIndex::class,
                QuotationIndex::class, ReturnIndex::class, PurchaseIndex::class, PurchaseReturnIndex::class,
                PaymentIndex::class, VendorPaymentIndex::class, ExpenseIndex::class, ExpenseCategoryIndex::class,
                EmployeeIndex::class, StockMovementIndex::class, ExpiryCenter::class, InventoryOverview::class,
                CheckIndex::class, TransferIndex::class] as $page) {
                Livewire::test($page)->assertSuccessful();
            }
        }

        $this->assertSame($before, $this->economicFingerprint());
    }

    public function test_purchase_payment_shortcut_requires_outstanding_and_both_permissions(): void
    {
        $purchase = $this->createAndPostPurchase();
        $url = route('vendor-payments.create', ['purchase_id' => $purchase->id]);
        Livewire::test(PurchaseIndex::class)->assertSeeHtml('href="'.$url.'"');

        $restricted = $this->customActor(['purchasing.purchase.view', 'money.vendor_payment.create']);
        $this->activate($restricted);
        Livewire::test(PurchaseIndex::class)->assertDontSeeHtml('href="'.$url.'"');

        $this->activate($this->owner);
        $this->createAndPostReturn($purchase, ['lines' => [['quantity' => '10']]]);
        Livewire::test(PurchaseIndex::class)->assertDontSeeHtml('href="'.$url.'"');
    }

    public function test_salary_is_not_serialized_in_restricted_mobile_or_desktop_cards(): void
    {
        Employee::create(['company_id' => $this->company->id, 'code' => 'TOUCH-EMP', 'name' => 'Pilot employee',
            'default_salary' => '6789.12', 'salary_currency_code' => 'ILS', 'created_by' => $this->owner->id]);
        $this->activate($this->customActor(['employees.view']));
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(EmployeeIndex::class)->assertSee('Pilot employee')->assertDontSee('6789.12');
        }
    }

    public function test_filters_keep_search_and_secondary_selection_after_status_changes(): void
    {
        $purchase = $this->createAndPostPurchase();
        Livewire::test(PurchaseIndex::class)
            ->set('search', $purchase->purchase_number)
            ->set('vendorFilter', $this->vendor->id)
            ->set('statusFilter', 'draft')
            ->assertSet('search', $purchase->purchase_number)
            ->assertSet('vendorFilter', $this->vendor->id)
            ->set('statusFilter', 'posted')
            ->assertSee($this->vendor->displayName());
    }

    /** @return array<string, string> */
    private function economicFingerprint(): array
    {
        $result = [];
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'document_sequences'] as $table) {
            $result[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $result;
    }
}
