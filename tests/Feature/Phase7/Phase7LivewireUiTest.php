<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Livewire\Pages\Expenses\ExpenseDetail;
use App\Livewire\Pages\Expenses\ExpenseForm;
use App\Livewire\Pages\Expenses\ExpenseIndex;
use App\Livewire\Pages\Payroll\EmployeeAdvanceForm;
use App\Livewire\Pages\Payroll\EmployeeDetail;
use App\Livewire\Pages\Payroll\EmployeeForm;
use App\Livewire\Pages\Payroll\EmployeeIndex;
use App\Livewire\Pages\Payroll\SalaryEntryForm;
use App\Livewire\Pages\Payroll\SalaryPaymentForm;
use App\Livewire\Pages\Purchasing\PurchaseDetail;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class Phase7LivewireUiTest extends Phase7TestCase
{
    public function test_expenses_pages_render_and_operate(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        // 1. Index page
        Livewire::test(ExpenseIndex::class)
            ->assertStatus(200)
            ->assertSee(__('expenses.title'));

        // 2. Create operating expense via form
        Livewire::test(ExpenseForm::class)
            ->set('categoryId', $this->operatingCategory->id)
            ->set('classification', 'operating')
            ->set('description', 'نفقات ضيافة واجتماعات')
            ->set('expenseDate', '2026-10-02')
            ->set('amount', '75.50')
            ->set('currencyCode', 'ILS')
            ->set('exchangeRate', '1.0000000000')
            ->set('paymentMethod', 'cash')
            ->set('moneyAccountId', $this->cashAccount->id)
            ->call('save')
            ->assertHasNoErrors();

        $expense = Expense::where('company_id', $this->company->id)->where('description', 'نفقات ضيافة واجتماعات')->firstOrFail();

        // 3. Detail page
        Livewire::test(ExpenseDetail::class, ['publicId' => $expense->public_id])
            ->assertStatus(200)
            ->assertSee($expense->expense_number)
            ->assertSee('75.50');
    }

    public function test_expense_attachment_upload_and_download(): void
    {
        Storage::fake('local');
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');

        Livewire::test(ExpenseForm::class)
            ->set('categoryId', $this->operatingCategory->id)
            ->set('classification', 'operating')
            ->set('description', 'مصروف مع مرفق')
            ->set('expenseDate', '2026-10-02')
            ->set('amount', '50.00')
            ->set('currencyCode', 'ILS')
            ->set('exchangeRate', '1.0000000000')
            ->set('paymentMethod', 'cash')
            ->set('moneyAccountId', $this->cashAccount->id)
            ->set('attachment', $file)
            ->call('save')
            ->assertHasNoErrors();

        $expense = Expense::where('company_id', $this->company->id)->where('description', 'مصروف مع مرفق')->firstOrFail();
        $this->assertNotNull($expense->attachment_path);

        // Download as owner
        $response = $this->get(route('attachments.expenses.download', $expense->public_id));
        $response->assertStatus(200);
    }

    public function test_employees_and_payroll_pages_render(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        // Employee Index
        Livewire::test(EmployeeIndex::class)
            ->assertStatus(200)
            ->assertSee($this->employee->name);

        // Employee Form Edit
        Livewire::test(EmployeeForm::class, ['publicId' => $this->employee->public_id])
            ->assertStatus(200)
            ->assertSet('name', $this->employee->name)
            ->set('phone', '0599123456')
            ->call('save')
            ->assertHasNoErrors();

        $this->employee->refresh();
        $this->assertEquals('0599123456', $this->employee->phone);

        // Employee Detail
        Livewire::test(EmployeeDetail::class, ['publicId' => $this->employee->public_id])
            ->assertStatus(200)
            ->assertSee($this->employee->name);

        // Advance Form
        Livewire::test(EmployeeAdvanceForm::class, ['employee' => $this->employee->public_id])
            ->assertStatus(200)
            ->assertSet('employeeId', $this->employee->id);

        // Salary Entry Form
        Livewire::test(SalaryEntryForm::class, ['employee' => $this->employee->public_id])
            ->assertStatus(200)
            ->assertSet('employeeId', $this->employee->id);

        // Salary Payment Form
        Livewire::test(SalaryPaymentForm::class, ['employee' => $this->employee->public_id])
            ->assertStatus(200)
            ->assertSet('employeeId', $this->employee->id);
    }

    public function test_purchase_detail_landed_cost_management_ui(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        // 1. Post a landed expense
        $landedExpense = app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->landedCategory->id,
            'expense_date' => '2026-10-02',
            'classification' => Expense::CLASSIFICATION_LANDED_COST,
            'description' => 'شحن وتخليص',
            'currency_code' => 'ILS',
            'amount' => '120.000000',
            'exchange_rate' => '1.0000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id,
            'idempotency_key' => 'ui-landed-'.uniqid(),
        ]);

        // 2. Create draft purchase
        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد سلع',
        ]);
        $pieceUnit = Unit::where('code', 'piece')->firstOrFail();
        $product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صنف سلع',
            'sku' => 'SKU-UI-LC-1',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $pieceUnit->id,
            'default_purchase_cost_base' => '100.000000',
        ], $this->owner->id);
        $warehouse = Warehouse::firstOrFail();

        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'vendor_invoice_number' => 'INV-UI-LC-'.uniqid(),
            'purchase_date' => '2026-10-03',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'unit_id' => $pieceUnit->id,
                    'quantity' => '5.000000',
                    'unit_cost' => '100.000000',
                ],
            ],
        ]);

        // 3. Purchase detail component displays landed cost management
        $component = Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->assertStatus(200)
            ->assertSee(__('purchasing.landed_costs'))
            ->assertSee(__('purchasing.attach_landed_cost'))
            ->set('selectedExpenseId', $landedExpense->id)
            ->set('allocationMethod', 'value')
            ->call('attachLandedCost')
            ->assertHasNoErrors();

        // Verify allocation attached
        $purchase->refresh();
        $this->assertEquals(1, $purchase->landedCostAllocations()->count());

        // 4. Remove allocation
        $component->call('removeLandedCost', $landedExpense->id)
            ->assertHasNoErrors();

        $purchase->refresh();
        $this->assertEquals(0, $purchase->landedCostAllocations()->count());
    }
}
