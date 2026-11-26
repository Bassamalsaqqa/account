<?php

declare(strict_types=1);

namespace Tests\Feature\Refinement;

use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Livewire\Pages\Accounting\OpeningTradeBalanceEntry;
use App\Livewire\Pages\Catalog\BrandIndex;
use App\Livewire\Pages\Catalog\CategoryIndex;
use App\Livewire\Pages\Catalog\UnitIndex;
use App\Livewire\Pages\Catalog\WarehouseIndex;
use App\Livewire\Pages\Customers\CustomerDetail;
use App\Livewire\Pages\Inventory\ExpiryCenter;
use App\Livewire\Pages\Inventory\StockMovementIndex;
use App\Livewire\Pages\Payroll\EmployeeDetail;
use App\Livewire\Pages\Payroll\EmployeeForm;
use App\Livewire\Pages\Payroll\SalaryEntryForm;
use App\Livewire\Pages\Payroll\SalaryPaymentForm;
use App\Livewire\Pages\Products\BarcodeLabels;
use App\Livewire\Pages\Products\ProductDetail;
use App\Livewire\Pages\Products\ProductForm;
use App\Livewire\Pages\Products\ProductIndex;
use App\Livewire\Pages\Purchasing\VendorDetail;
use App\Livewire\Pages\Purchasing\VendorForm;
use App\Livewire\Pages\Reporting\ReportHub;
use App\Livewire\Pages\Sales\ReturnDetail;
use App\Livewire\Pages\Sales\Settings\MoneyAccountSettings;
use App\Livewire\Pages\Sales\Settings\TaxRateSettings;
use App\Models\Brand;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\InventoryLotBalance;
use App\Models\LedgerAccount;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\TaxRate;
use App\Models\User;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Feature\Phase8\PositionTestCase;

final class RemainingAccessibilitySliceTest extends PositionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array{string}> */
    public static function locales(): array
    {
        return [['ar'], ['en']];
    }

    #[DataProvider('locales')]
    public function test_tablist_and_tabpanel_semantics_are_complete_and_unique_across_views(string $locale): void
    {
        $this->setLocaleForOwner($locale);

        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل التبويبات',
            'name_en' => 'Tab Customer',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        // 1. ProductDetail: duplicate role/aria-label removed; shared helper wired; active panel linked
        $productDetail = Livewire::test(ProductDetail::class, ['publicId' => $this->stockProduct->public_id]);
        $productHtml = $productDetail->html(false);
        $this->assertTablistStructure($productHtml, 'tab-overview', 'panel-overview', 'inventory.product_tabs');
        $this->assertStringContainsString('window.handleTablistKeydown', $productHtml);
        $this->assertSame(1, substr_count($productHtml, 'role="tablist"'));
        $productDetail->call('setTab', 'barcodes');
        $this->assertTablistStructure($productDetail->html(false), 'tab-barcodes', 'panel-barcodes', 'inventory.product_tabs');

        // 2. CustomerDetail: uses shared helper and keeps full ARIA tab/panel link
        $customerDetail = Livewire::test(CustomerDetail::class, ['publicId' => $customer->public_id]);
        $customerHtml = $customerDetail->html(false);
        $this->assertTablistStructure($customerHtml, 'tab-customer-overview', 'panel-customer-overview');
        $this->assertStringContainsString('window.handleTablistKeydown', $customerHtml);

        // 3. VendorDetail: full ARIA tab/panel link + no dangling tabpanel when tabs hidden for restricted user
        $vendorDetail = Livewire::test(VendorDetail::class, ['publicId' => $this->vendor->public_id]);
        $vendorHtml = $vendorDetail->html(false);
        $this->assertTablistStructure($vendorHtml, 'tab-vendor-overview', 'panel-vendor-overview');
        $this->assertStringContainsString('window.handleTablistKeydown', $vendorHtml);
        $vendorDetail->set('activeTab', 'purchases');
        $this->assertTablistStructure($vendorDetail->html(false), 'tab-vendor-purchases', 'panel-vendor-purchases');

        // Restricted user without purchasing.cost.view gets no tablist and no dangling role="tabpanel"
        $restricted = User::factory()->create(['locale' => $locale]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $restricted->id,
            'role' => 'Staff',
            'status' => 'active',
        ]);
        $restrictedRole = Role::create(['name' => 'VendorViewerOnly_'.$locale, 'company_id' => $this->company->id, 'guard_name' => 'web']);
        $restrictedRole->syncPermissions(['vendors.view']);
        setPermissionsTeamId($this->company->id);
        $restricted->assignRole($restrictedRole);
        $this->activateUser($restricted);
        $restrictedVendorHtml = Livewire::test(VendorDetail::class, ['publicId' => $this->vendor->public_id])->html(false);
        $this->assertSame(0, substr_count($restrictedVendorHtml, 'role="tablist"'));
        $this->assertSame(0, substr_count($restrictedVendorHtml, 'role="tabpanel"'));

        // Restore owner for remainder
        $this->setLocaleForOwner($locale);

        // 4. EmployeeDetail: <nav> replaced by ARIA tablist + linked tabpanel + reversal modal outside tabpanel
        $employeeDetail = Livewire::test(EmployeeDetail::class, ['publicId' => $this->employee->public_id]);
        $employeeHtml = $employeeDetail->html(false);
        $this->assertTablistStructure($employeeHtml, 'tab-employee-overview', 'panel-employee-overview');
        $this->assertStringContainsString('window.handleTablistKeydown', $employeeHtml);
        $employeeDetail->set('tab', 'salaries');
        $this->assertTablistStructure($employeeDetail->html(false), 'tab-employee-salaries', 'panel-employee-salaries');

        // 5. OpeningTradeBalanceEntry: pseudo-tabs upgraded with roving tabindex + panel link
        $opening = Livewire::test(OpeningTradeBalanceEntry::class);
        $this->assertTablistStructure($opening->html(false), 'tab-opening-customer', 'panel-opening-customer');
        $this->assertStringContainsString('window.handleTablistKeydown', $opening->html(false));
        $opening->set('side', 'vendor');
        $this->assertTablistStructure($opening->html(false), 'tab-opening-vendor', 'panel-opening-vendor');

        // 6. ReportingHub: group filter tabs upgraded with IDs, panel link, helper, and non-sales label
        $hubHtml = Livewire::test(ReportHub::class)->html(false);
        $xpath = $this->xpath($hubHtml);
        $tablist = $xpath->query('//*[@role="tablist"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $tablist);
        $this->assertSame(__('reports.title'), $tablist->getAttribute('aria-label'));
        $this->assertNotSame(__('reports.groups.sales'), $tablist->getAttribute('aria-label'));
        $this->assertStringContainsString('window.handleTablistKeydown', $hubHtml);
        $this->assertNotNull($xpath->query('//*[@id="tab-report-group-all" and @role="tab" and @aria-controls="panel-report-groups"]')->item(0));
        $this->assertNotNull($xpath->query('//*[@id="panel-report-groups" and @role="tabpanel"]')->item(0));
    }

    #[DataProvider('locales')]
    public function test_payroll_allocation_tables_row_errors_and_party_checkboxes(string $locale): void
    {
        $this->setLocaleForOwner($locale);

        $advance = app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'advance_date' => '2026-10-02',
            'currency_code' => 'ILS',
            'amount' => '150.00',
            'exchange_rate' => '1',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id,
            'idempotency_key' => 'a11y-adv-'.$locale,
        ]);

        $salaryEntry = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'recognition_date' => '2026-10-05',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'base_salary' => '500.00',
            'bonus' => '0',
            'deduction' => '0',
            'advances' => [],
            'idempotency_key' => 'a11y-sal-'.$locale,
        ]);

        // SalaryEntryForm: USD FX fields + ILS allocation overflow-x-auto + named controls + dynamic advance allocation row error
        $entryForm = Livewire::test(SalaryEntryForm::class, ['employee' => $this->employee->public_id]);
        $this->assertNamedControls($entryForm->html(false));
        $entryForm->set('currencyCode', 'ILS');
        $entryHtml = $entryForm->html(false);
        $this->assertNamedControls($entryHtml);
        $this->assertTableHasOverflowXAuto($entryHtml);
        $this->assertStringContainsString('id="salary-entry-advance-'.$advance->id.'"', $entryHtml);
        $this->assertStringContainsString('for="salary-entry-advance-'.$advance->id.'"', $entryHtml);

        $beforeBatches = DB::table('posting_batches')->count();
        $entryForm->set('baseSalary', '100')
            ->set('advanceAllocations.'.$advance->id, '-5')
            ->call('save')
            ->assertHasErrors(['advanceAllocations.'.$advance->id]);
        $this->assertError($entryForm->html(false), 'salary-entry-advance-'.$advance->id);

        // SalaryPaymentForm: overflow-x-auto + cash & check named controls + dynamic entry allocation row error
        $paymentForm = Livewire::test(SalaryPaymentForm::class, ['employee' => $this->employee->public_id]);
        $this->assertNamedControls($paymentForm->html(false));
        $paymentForm->set('currencyCode', 'ILS');
        $paymentHtml = $paymentForm->html(false);
        $this->assertNamedControls($paymentHtml);
        $this->assertTableHasOverflowXAuto($paymentHtml);
        $this->assertStringContainsString('id="salary-payment-entry-'.$salaryEntry->id.'"', $paymentHtml);
        $this->assertStringContainsString('for="salary-payment-entry-'.$salaryEntry->id.'"', $paymentHtml);

        $paymentForm->set('paymentMethod', 'check');
        $this->assertNamedControls($paymentForm->html(false));
        $paymentForm->set('entryAllocations.'.$salaryEntry->id, '-5')
            ->set('checkNumber', 'CHK-100')
            ->set('dueDate', '2026-11-01')
            ->set('drawnMoneyAccountId', $this->usdBankAccount->id)
            ->call('save')
            ->assertHasErrors(['entryAllocations.'.$salaryEntry->id]);
        $this->assertError($paymentForm->html(false), 'salary-payment-entry-'.$salaryEntry->id);
        $this->assertSame($beforeBatches, DB::table('posting_batches')->count());

        // EmployeeForm & VendorForm checkbox labels carry 44px floor + accessible for/id
        $empHtml = Livewire::test(EmployeeForm::class)->html(false);
        $this->assertCheckbox44pxLabel($empHtml, 'employee-active');

        $vendorHtml = Livewire::test(VendorForm::class)->html(false);
        $this->assertCheckbox44pxLabel($vendorHtml, 'vendor-active');
    }

    #[DataProvider('locales')]
    public function test_all_ten_migrated_dialogs_use_dialog_shell_and_stable_openers(string $locale): void
    {
        $this->setLocaleForOwner($locale);

        // 1. SalesReturnDetail void modal
        $invoice = $this->invoice('ILS', '1', '50');
        $draftReturn = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => '2026-10-03',
            'lines' => [[
                'sales_invoice_line_id' => $invoice->lines->firstOrFail()->id,
                'quantity' => '1.000000',
            ]],
        ]);
        $postedReturn = app(PostSalesReturnAction::class)->execute($draftReturn, $this->owner);

        $returnDetail = Livewire::test(ReturnDetail::class, ['publicId' => $postedReturn->public_id]);
        $this->assertStringContainsString('id="correction-sales_return-'.$postedReturn->public_id.'-delete"', $returnDetail->html(false));
        $returnDetail->set('showVoidModal', true);
        $this->assertDialogShellModal($returnDetail->html(false), 'return-void-modal-title', '$set(\'showVoidModal\', false)');
        $returnDetail->set('voidReason', '')->call('voidReturn')->assertHasErrors(['voidReason']);
        $this->assertError($returnDetail->html(false), 'return-void-reason');

        // 2. ProductForm inline brand modal
        $productForm = Livewire::test(ProductForm::class);
        $this->assertStringContainsString('id="product-add-brand-trigger"', $productForm->html(false));
        $productForm->call('openCreateBrandModal');
        $this->assertDialogShellModal($productForm->html(false), 'inline-brand-modal-title', 'closeCreateBrandModal');
        $productForm->set('new_brand_name_ar', '')->call('saveInlineBrand')->assertHasErrors(['new_brand_name_ar']);
        $this->assertError($productForm->html(false), 'inline-brand-name-ar');

        // 3. ExpiryCenter disposal modal
        $this->postOpeningStock($this->stockProduct, $this->warehouseA, '2', '10', lotNumber: 'EXP-LOT-1', expiryDate: '2026-10-14');
        $lb = InventoryLotBalance::where('company_id', $this->company->id)->firstOrFail();
        $expiry = Livewire::test(ExpiryCenter::class)->set('timeframeFilter', 'expired');
        $expiryIndexHtml = $expiry->html(false);
        $this->assertStringContainsString('id="expiry-dispose-desktop-'.$lb->id.'"', $expiryIndexHtml);
        $this->assertStringContainsString('id="expiry-dispose-mobile-'.$lb->id.'"', $expiryIndexHtml);
        $expiry->call('openDisposalModal', $lb->id);
        $this->assertDialogShellModal($expiry->html(false), 'expiry-disposal-title', '$set(\'showDisposalModal\', false)');
        $expiry->set('disposalReason', '')->call('confirmDisposal')->assertHasErrors(['disposalReason']);
        $this->assertError($expiry->html(false), 'expiry-disposal-reason');

        // 4. BarcodeLabels preview modal
        $barcode = ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->stockProduct->id,
            'unit_id' => $this->baseUnit->id,
            'barcode' => 'PIECE-SKU-001',
            'type' => 'C128',
            'is_primary' => true,
        ]);
        $labels = Livewire::test(BarcodeLabels::class)->call('selectBarcode', $barcode->id);
        $this->assertStringContainsString('id="barcode-labels-preview-trigger"', $labels->html(false));
        $labels->call('preview');
        $this->assertDialogShellModal($labels->html(false), 'preview-title', 'closePreview');

        // 5. TaxRateSettings modal
        $taxAccount = LedgerAccount::where('company_id', $this->company->id)->firstOrFail();
        $taxRate = TaxRate::where('company_id', $this->company->id)->first() ?? TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT16',
            'name_ar' => 'ضريبة 16%',
            'name_en' => 'VAT 16%',
            'rate' => '16.000000',
            'calculation' => 'exclusive',
            'sales_tax_account_id' => $taxAccount->id,
            'active' => true,
        ]);
        $taxSettings = Livewire::test(TaxRateSettings::class);
        $this->assertStringContainsString('id="tax-rate-add-trigger"', $taxSettings->html(false));
        $this->assertStringContainsString('id="tax-rate-edit-trigger-'.$taxRate->id.'"', $taxSettings->html(false));
        $taxSettings->call('newTaxRate');
        $taxModalHtml = $taxSettings->html(false);
        $this->assertDialogShellModal($taxModalHtml, 'tax-rate-modal-title', '$set(\'showFormModal\', false)');
        $this->assertNamedControls($taxModalHtml);
        $taxSettings->set('code', '')->set('name_ar', '')->call('save')->assertHasErrors(['code', 'name_ar']);
        $this->assertError($taxSettings->html(false), 'tax-code');
        $this->assertError($taxSettings->html(false), 'tax-name-ar');

        // 6. MoneyAccountSettings modal
        $moneySettings = Livewire::test(MoneyAccountSettings::class);
        $this->assertStringContainsString('id="money-account-add-trigger"', $moneySettings->html(false));
        $this->assertStringContainsString('id="money-account-edit-trigger-'.$this->cashAccount->id.'"', $moneySettings->html(false));
        $moneySettings->call('newAccount')->set('account_type', 'bank');
        $moneyModalHtml = $moneySettings->html(false);
        $this->assertDialogShellModal($moneyModalHtml, 'money-account-modal-title', '$set(\'showFormModal\', false)');
        $this->assertNamedControls($moneyModalHtml);
        $moneySettings->set('name_ar', '')->call('save')->assertHasErrors(['name_ar']);
        $this->assertError($moneySettings->html(false), 'money-account-name-ar');

        // 7-10. Catalog Brand / Category / Unit / Warehouse CRUD modals
        $brand = Brand::create([
            'company_id' => $this->company->id,
            'name_ar' => 'علامة تجارية',
            'name_en' => 'Brand EN',
            'active' => true,
        ]);
        $brandIndex = Livewire::test(BrandIndex::class);
        $this->assertStringContainsString('id="brand-add-trigger"', $brandIndex->html(false));
        $this->assertStringContainsString('id="brand-edit-desktop-'.$brand->id.'"', $brandIndex->html(false));
        $this->assertStringContainsString('id="brand-edit-mobile-'.$brand->id.'"', $brandIndex->html(false));
        $brandIndex->call('openCreateModal');
        $this->assertDialogShellModal($brandIndex->html(false), 'brand-modal-title', 'closeModal');
        $this->assertCheckbox44pxLabel($brandIndex->html(false), 'brand-active');

        $category = ProductCategory::create([
            'company_id' => $this->company->id,
            'name_ar' => 'تصنيف',
            'name_en' => 'Category EN',
            'sort_order' => 1,
            'active' => true,
        ]);
        $categoryIndex = Livewire::test(CategoryIndex::class);
        $this->assertStringContainsString('id="category-add-trigger"', $categoryIndex->html(false));
        $this->assertStringContainsString('id="category-edit-desktop-'.$category->id.'"', $categoryIndex->html(false));
        $this->assertStringContainsString('id="category-edit-mobile-'.$category->id.'"', $categoryIndex->html(false));
        $categoryIndex->call('openCreateModal');
        $this->assertDialogShellModal($categoryIndex->html(false), 'category-modal-title', '$set(\'showModal\', false)');
        $this->assertNamedControls($categoryIndex->html(false));
        $this->assertCheckbox44pxLabel($categoryIndex->html(false), 'category-active');

        $unitIndex = Livewire::test(UnitIndex::class);
        $this->assertStringContainsString('id="unit-add-trigger"', $unitIndex->html(false));
        $this->assertStringContainsString('id="unit-edit-desktop-'.$this->baseUnit->id.'"', $unitIndex->html(false));
        $this->assertStringContainsString('id="unit-edit-mobile-'.$this->baseUnit->id.'"', $unitIndex->html(false));
        $unitIndex->call('openCreateModal');
        $this->assertDialogShellModal($unitIndex->html(false), 'unit-modal-title', '$set(\'showModal\', false)');
        $this->assertNamedControls($unitIndex->html(false));
        $this->assertCheckbox44pxLabel($unitIndex->html(false), 'unit-active');

        $warehouseIndex = Livewire::test(WarehouseIndex::class);
        $this->assertStringContainsString('id="warehouse-add-trigger"', $warehouseIndex->html(false));
        $this->assertStringContainsString('id="warehouse-edit-desktop-'.$this->warehouseA->id.'"', $warehouseIndex->html(false));
        $this->assertStringContainsString('id="warehouse-edit-mobile-'.$this->warehouseA->id.'"', $warehouseIndex->html(false));
        $warehouseIndex->call('openCreateModal');
        $this->assertDialogShellModal($warehouseIndex->html(false), 'warehouse-modal-title', '$set(\'showModal\', false)');
        $this->assertNamedControls($warehouseIndex->html(false));
        $this->assertCheckbox44pxLabel($warehouseIndex->html(false), 'warehouse-active');
    }

    public function test_barcode_labels_workspace_decouples_direction_and_no_undefined_dir_ltr_classes_render(): void
    {
        $barcode = ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->stockProduct->id,
            'unit_id' => $this->baseUnit->id,
            'barcode' => '9780201379624',
            'type' => 'EAN13',
            'is_primary' => true,
        ]);

        // EN workspace UI with AR print locale -> outer workspace div has NO dir="rtl", print preview preserves dir="rtl"
        $this->setLocaleForOwner('en');
        $labelsEn = Livewire::test(BarcodeLabels::class)
            ->set('locale', 'ar')
            ->call('selectBarcode', $barcode->id)
            ->call('preview');
        $rootNodeEn = $this->xpath($labelsEn->html(false))->query('/*/*[1]')->item(0);
        if ($rootNodeEn instanceof DOMElement) {
            $this->assertSame('', $rootNodeEn->getAttribute('dir'));
        }
        $this->assertStringContainsString('dir="rtl"', (string) $labelsEn->get('previewHtml'));

        // AR workspace UI with EN print locale -> outer workspace div has NO dir="ltr", print preview preserves dir="ltr"
        $this->setLocaleForOwner('ar');
        $labelsAr = Livewire::test(BarcodeLabels::class)
            ->set('locale', 'en')
            ->call('selectBarcode', $barcode->id)
            ->call('preview');
        $this->assertStringNotContainsString('<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6" dir="ltr"', $labelsAr->html(false));
        $this->assertStringContainsString('dir="ltr"', (string) $labelsAr->get('previewHtml'));

        // Undefined dir-ltr class is absent from every previously-affected view
        $this->postOpeningStock($this->stockProduct, $this->warehouseA, '2', '10', lotNumber: 'DIR-LOT', expiryDate: '2026-10-14');
        Brand::create(['company_id' => $this->company->id, 'name_ar' => 'ع', 'name_en' => 'BrandX', 'active' => true]);
        ProductCategory::create(['company_id' => $this->company->id, 'name_ar' => 'ق', 'name_en' => 'CatX', 'sort_order' => 1, 'active' => true]);

        foreach ([
            Livewire::test(ProductIndex::class)->html(false),
            Livewire::test(ProductDetail::class, ['publicId' => $this->stockProduct->public_id])->html(false),
            Livewire::test(ProductForm::class, ['publicId' => $this->stockProduct->public_id])->html(false),
            Livewire::test(StockMovementIndex::class)->html(false),
            Livewire::test(ExpiryCenter::class)->html(false),
            Livewire::test(BrandIndex::class)->html(false),
            Livewire::test(CategoryIndex::class)->html(false),
            Livewire::test(UnitIndex::class)->html(false),
            Livewire::test(WarehouseIndex::class)->html(false),
            Livewire::test(ReportHub::class)->html(false),
        ] as $html) {
            $this->assertStringNotContainsString('dir-ltr', $html);
        }
    }

    private function setLocaleForOwner(string $locale): void
    {
        app()->setLocale($locale);
        session()->put('locale', $locale);
        $this->owner->update(['locale' => $locale]);
        $this->activateUser($this->owner->fresh());
    }

    private function assertTablistStructure(string $html, string $activeTabId, string $activePanelId, ?string $forbiddenLabelKey = null): void
    {
        $xpath = $this->xpath($html);
        $tablists = $xpath->query('//*[@role="tablist"]');
        $this->assertSame(1, $tablists->length, 'Expected exactly one role="tablist".');
        $tablist = $tablists->item(0);
        $this->assertInstanceOf(DOMElement::class, $tablist);
        $this->assertNotSame('', trim($tablist->getAttribute('aria-label')));
        if ($forbiddenLabelKey !== null) {
            $this->assertNotSame(__($forbiddenLabelKey), $tablist->getAttribute('aria-label'));
        }

        $tabs = $xpath->query('.//*[@role="tab"]', $tablist);
        $this->assertGreaterThan(1, $tabs->length);

        foreach ($tabs as $tab) {
            $this->assertInstanceOf(DOMElement::class, $tab);
            $tabId = $tab->getAttribute('id');
            $this->assertNotSame('', $tabId);
            $this->assertNotSame('', $tab->getAttribute('aria-controls'));
            $isActive = $tabId === $activeTabId;
            $this->assertSame($isActive ? 'true' : 'false', $tab->getAttribute('aria-selected'));
            $this->assertSame($isActive ? '0' : '-1', $tab->getAttribute('tabindex'));
        }

        $panel = $xpath->query('//*[@role="tabpanel" and @id="'.$activePanelId.'"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $panel, 'Missing active tabpanel: '.$activePanelId);
        $this->assertSame($activeTabId, $panel->getAttribute('aria-labelledby'));
        $this->assertSame('0', $panel->getAttribute('tabindex'));
    }

    private function assertTableHasOverflowXAuto(string $html): void
    {
        $xpath = $this->xpath($html);
        $tables = $xpath->query('//table');
        $this->assertGreaterThan(0, $tables->length);
        foreach ($tables as $table) {
            $scrollParent = $xpath->query('ancestor::*[contains(@class, "overflow-x-auto")]', $table);
            $this->assertGreaterThan(0, $scrollParent->length, 'Allocation table must sit inside overflow-x-auto.');
        }
    }

    private function assertCheckbox44pxLabel(string $html, string $inputId): void
    {
        $xpath = $this->xpath($html);
        $input = $xpath->query('//*[@id="'.$inputId.'"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $input, 'Missing checkbox #'.$inputId);
        $label = $xpath->query('//label[@for="'.$inputId.'"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $label, 'Missing label[for="'.$inputId.'"]');
        $this->assertStringContainsString('min-h-11', $label->getAttribute('class'));
    }

    private function assertDialogShellModal(string $html, string $titleId, string $closeAction): void
    {
        $xpath = $this->xpath($html);
        $dialogs = $xpath->query('//*[@role="dialog" and @aria-modal="true" and @aria-labelledby="'.$titleId.'"]');
        $this->assertSame(1, $dialogs->length, 'Expected single dialog-shell for '.$titleId);
        $dialog = $dialogs->item(0);
        $this->assertInstanceOf(DOMElement::class, $dialog);
        $this->assertSame('true', $dialog->getAttribute('x-trap.inert.noscroll'));
        $this->assertNotNull($xpath->query('//*[@id="'.$titleId.'"]')->item(0));
        $this->assertStringContainsString('captureDialogReturnTarget', $html);
        $this->assertStringContainsString('restoreDialogReturnTarget', $html);
        $this->assertStringContainsString($closeAction, $html);
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
                if (str_starts_with($attribute->name, 'wire:model')) {
                    $bound = true;
                }
            }
            if (! $bound) {
                continue;
            }
            $count++;
            $id = $input->getAttribute('id');
            $this->assertNotSame('', $id, 'Bound control must have a unique ID');
            $labels = $xpath->query('//label[@for="'.$id.'"]');
            $name = trim($input->getAttribute('aria-label'));
            if ($name === '' && $labels->length > 0) {
                $name = trim($labels->item(0)->textContent);
            }
            $this->assertNotSame('', $name, 'Control has no accessible name: '.$id);
        }
        $this->assertGreaterThan(0, $count);
    }

    private function assertError(string $html, string $id): void
    {
        $xpath = $this->xpath($html);
        $control = $xpath->query('//*[@id="'.$id.'"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $control, 'Missing control #'.$id);
        $this->assertSame('true', $control->getAttribute('aria-invalid'));
        $this->assertSame($id.'-error', $control->getAttribute('aria-describedby'));
        $error = $xpath->query('//*[@id="'.$id.'-error"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $error, 'Missing error #'.$id.'-error');
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
}
