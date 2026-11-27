<?php

declare(strict_types=1);

namespace Tests\Feature\Phase10;

use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\EnsurePurchasingFoundationAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Livewire\Pages\Customers\CustomerForm;
use App\Livewire\Pages\Customers\CustomerIndex;
use App\Livewire\Pages\Products\ProductForm;
use App\Livewire\Pages\Products\ProductIndex;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Purchasing\PurchaseReturnForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\InvoiceIndex;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

/** Scoped semantic/error regressions, not an overall WCAG or browser certification. */
final class FormAccessibilityTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج اختبار الوصول', 'name_en' => 'Accessible fixture product', 'sku' => 'A11Y-1',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => false,
            'base_unit_id' => Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail()->id,
        ], $this->owner->id);
    }

    /** @return list<array{string}> */
    public static function locales(): array
    {
        return [['ar'], ['en']];
    }

    #[DataProvider('locales')]
    public function test_scoped_list_create_edit_and_repeated_controls_have_unique_accessible_names(string $locale): void
    {
        $this->owner->update(['locale' => $locale]);
        session()->put('locale', $locale);
        app()->setLocale($locale);
        foreach ([ProductIndex::class, CustomerIndex::class, InvoiceIndex::class, ProductForm::class, CustomerForm::class] as $component) {
            $this->assertNamedControls(Livewire::test($component)->html());
        }
        $this->assertNamedControls(Livewire::test(ProductForm::class, ['publicId' => $this->product->public_id])->html());
        $this->assertNamedControls(Livewire::test(CustomerForm::class, ['publicId' => $this->customer->public_id])->html());
        $invoice = Livewire::test(InvoiceForm::class)
            ->call('selectProduct', 0, $this->product->id)
            ->call('addLine')
            ->call('selectProduct', 1, $this->product->id)
            ->call('toggleDiscountControl')
            ->set('discount_type', 'fixed');
        $this->assertNamedControls($invoice->html());
        foreach ([0, 1] as $index) {
            $rowKey = $invoice->get('lines.'.$index.'.row_key');
            $id = 'invoice-line-'.$rowKey.'-quantity';
            $this->assertSame(1, substr_count($invoice->html(), 'id="'.$id.'"'));
            $invoice->assertSee('aria-label="'.__('sales.quantity').' '.($index + 1).'"', false);
        }
        $invoice->assertSee('id="sales-discount-type"', false);
        $invoice->assertSee('id="sales-discount-value"', false);
        $invoice->assertSee(__('sales.document_discount'));
    }

    #[DataProvider('locales')]
    public function test_real_validation_links_errors_and_preserves_entered_values_without_economic_writes(string $locale): void
    {
        $this->owner->update(['locale' => $locale]);
        session()->put('locale', $locale);
        app()->setLocale($locale);
        $before = DB::table('posting_batches')->count();
        $customer = Livewire::test(CustomerForm::class)
            ->set('business_name', 'Retained business / مؤسسة محفوظة')
            ->set('email', 'invalid-email')
            ->call('save')
            ->assertHasErrors(['name_ar', 'email'])
            ->assertSet('business_name', 'Retained business / مؤسسة محفوظة');
        $this->assertError($customer->html(), 'customer-name-ar');
        $this->assertError($customer->html(), 'customer-email');

        $product = Livewire::test(ProductForm::class)
            ->set('sku', 'RETAINED-SKU')
            ->set('name_ar', '')
            ->call('save')
            ->assertHasErrors(['name_ar'])
            ->assertSet('sku', 'RETAINED-SKU');
        $this->assertError($product->html(), 'product-name-ar');

        $invoice = Livewire::test(InvoiceForm::class)
            ->set('lines.0.item_description', 'Retained exact draft / مسودة محفوظة')
            ->set('lines.0.quantity', '0')
            ->set('lines.0.unit_price', '2.500000')
            ->call('save', false)
            ->assertHasErrors(['customer_id', 'lines.0.quantity'])
            ->assertSet('lines.0.item_description', 'Retained exact draft / مسودة محفوظة')
            ->assertSet('lines.0.unit_price', '2.500000');
        $this->assertError($invoice->html(), 'invoice-customer-id');
        $this->assertError($invoice->html(), 'invoice-line-'.$invoice->get('lines.0.row_key').'-quantity');
        $this->assertNamedControls($invoice->html());

        $discountErrorInvoice = Livewire::test(InvoiceForm::class)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.unit_price', '25.00')
            ->call('toggleDiscountControl')
            ->set('discount_type', 'percent')
            ->set('discount_value', '150')
            ->call('recalculate')
            ->assertHasErrors(['discount_value']);
        $this->assertError($discountErrorInvoice->html(), 'sales-discount-value');

        $this->owner->roles->firstOrFail()->revokePermissionTo('sales.invoice.change_discount');
        $this->owner->unsetRelation('roles')->unsetRelation('permissions');
        Livewire::test(InvoiceForm::class)
            ->assertDontSee('id="sales-discount-type"', false)
            ->assertDontSee('id="sales-discount-value"', false)
            ->call('toggleDiscountControl')
            ->assertForbidden();

        $this->assertSame($before, DB::table('posting_batches')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('sales_invoices')->count());
    }

    private function assertNamedControls(string $html): void
    {
        $xpath = $this->xpath($html);
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $element) {
            $id = $element->getAttribute('id');
            $this->assertNotContains($id, $ids, 'Duplicate ID: '.$id);
            $ids[] = $id;
        }
        $count = 0;
        foreach ($xpath->query('//input|//select|//textarea') as $input) {
            $bound = false;
            foreach ($input->attributes as $attribute) {
                if (str_starts_with($attribute->name, 'wire:model') || str_starts_with($attribute->value, 'selectProduct(') || str_starts_with($attribute->value, 'changeUnit(')) {
                    $bound = true;
                }
            }
            if (! $bound) {
                continue;
            }
            $count++;
            $id = $input->getAttribute('id');
            $this->assertNotSame('', $id, 'Bound control must have a unique ID: '.$input->getAttribute('wire:model.live'));
            $labels = $xpath->query('//label[@for="'.$id.'"]');
            $name = trim($input->getAttribute('aria-label'));
            if ($name === '' && $labels->length > 0) {
                $name = trim($labels->item(0)->textContent);
            }
            $this->assertNotSame('', $name, 'Control has no accessible name: '.$id);
            $this->assertFalse(str_starts_with($name, 'sales.') || str_starts_with($name, 'inventory.'), 'Untranslated accessible name: '.$id);
        }
        $this->assertGreaterThan(0, $count);
    }

    private function assertError(string $html, string $id): void
    {
        $xpath = $this->xpath($html);
        $control = $xpath->query('//*[@id="'.$id.'"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $control);
        $this->assertSame('true', $control->getAttribute('aria-invalid'));
        $this->assertSame($id.'-error', $control->getAttribute('aria-describedby'));
        $error = $xpath->query('//*[@id="'.$id.'-error"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $error);
        $this->assertSame('alert', $error->getAttribute('role'));
        $this->assertNotSame('', trim($error->textContent));
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private function ensurePurchasingProvisioned(string $locale): void
    {
        $this->owner->update(['locale' => $locale]);
        session()->put('locale', $locale);
        app()->setLocale($locale);
        $context = app(CompanyContext::class);
        $context->clear();
        app(EnsurePurchasingFoundationAction::class)->execute($this->company);
        $context->setCompany($this->company, $this->owner);
    }

    #[DataProvider('locales')]
    public function test_discount_controls_meet_touch_and_mobile_font_sizing_without_micro_labels(string $locale): void
    {
        $this->ensurePurchasingProvisioned($locale);

        $forms = [
            [InvoiceForm::class, 'sales-discount-type', 'sales-discount-value'],
            [QuotationForm::class, 'quote-discount-type', 'quote-discount-value'],
            [PurchaseForm::class, 'purchase-discount-type', 'purchase-discount-value'],
        ];

        foreach ($forms as [$component, $typeId, $valueId]) {
            $html = Livewire::test($component)
                ->call('toggleDiscountControl')
                ->set('discount_type', 'fixed')
                ->html();

            $this->assertStringNotContainsString('text-[11px]', $html);

            $xpath = $this->xpath($html);
            foreach ([$typeId, $valueId] as $controlId) {
                $label = $xpath->query('//label[@for="'.$controlId.'"]')->item(0);
                $this->assertInstanceOf(DOMElement::class, $label, "Missing label[for={$controlId}] on {$component}");
                $this->assertStringNotContainsString('text-[11px]', $label->getAttribute('class'));

                $node = $xpath->query('//*[@id="'.$controlId.'"]')->item(0);
                $this->assertInstanceOf(DOMElement::class, $node, "Missing control #{$controlId} on {$component}");
                $classes = $node->getAttribute('class');
                $this->assertStringContainsString('min-h-11', $classes);
                $this->assertStringContainsString('lg:min-h-9.5', $classes);
                $this->assertStringContainsString('text-base', $classes);
                $this->assertStringContainsString('md:text-sm', $classes);
                $this->assertDoesNotMatchRegularExpression('/\bh-8\b/', $classes);
            }
        }
    }

    #[DataProvider('locales')]
    public function test_purchase_return_quantity_stepper_binds_label_and_error_id(string $locale): void
    {
        $this->ensurePurchasingProvisioned($locale);

        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد إتاحة',
            'name_en' => 'A11y Vendor',
            'code' => 'VEND-A11Y',
        ]);
        $warehouse = Warehouse::where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
        $unit = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();
        $product = app(ProductCatalogService::class)->createProduct($this->company, [
            'sku' => 'PR-A11Y-1',
            'name_ar' => 'صنف مرتجع',
            'name_en' => 'Returnable Item',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $unit->id,
            'default_purchase_cost_base' => '10.000000',
            'default_sale_price_base' => '15.000000',
        ], $this->owner->id);
        $productUnit = ProductUnit::where('product_id', $product->id)->where('is_base', true)->firstOrFail();

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'purchase_date' => '2026-11-25',
            'lines' => [[
                'product_id' => $product->id,
                'product_unit_id' => $productUnit->id,
                'item_description' => $product->name_en,
                'quantity' => '5',
                'unit_cost' => '10.00',
            ]],
        ]);
        $purchase = app(PostPurchaseAction::class)->execute($draft, $this->owner);

        $component = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id]);
        $this->assertNamedControls($component->html());

        $xpathInitial = $this->xpath($component->html());
        $label = $xpathInitial->query('//label[@for="purchase-return-quantity-0"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $label);

        $invalid = $component
            ->set('lines.0.return_quantity', '99')
            ->call('saveDraft')
            ->assertHasErrors(['lines.0.return_quantity']);

        $this->assertError($invalid->html(), 'purchase-return-quantity-0');
    }
}
