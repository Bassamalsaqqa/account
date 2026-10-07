<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\TransitionCheckAction;
use App\Actions\Payroll\DeleteEmployeeAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Payroll\PostSalaryPaymentAction;
use App\Actions\Purchasing\AllocateLandedCostAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\UpdatePurchaseDraftAction;
use App\Livewire\Pages\Payroll\EmployeeDetail;
use App\Livewire\Pages\Payroll\SalaryPaymentForm;
use App\Livewire\Pages\Purchasing\PurchaseDetail;
use App\Models\Check;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\LandedCostAllocation;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SalaryEntry;
use App\Models\SalaryPayment;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Money\CheckFinancialSourceResolver;
use App\Services\Money\CheckHistory;
use App\Services\Payroll\PayrollReadService;
use App\Services\Phase7\Phase7History;
use App\Services\Purchasing\PurchaseReadModel;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;

final class Phase7Correction01Test extends Phase7TestCase
{
    protected Vendor $vendor;

    protected Product $stockProduct;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد التصحيح',
            'name_en' => 'Correction Vendor',
        ]);

        $pieceUnit = Unit::where('code', 'piece')->firstOrFail();

        $this->stockProduct = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'سلعة تصحيح',
            'name_en' => 'Correction Product',
            'sku' => 'SKU-CORR-01',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $pieceUnit->id,
            'default_purchase_cost_base' => '100.000000',
        ], $this->owner->id);

        $this->warehouse = Warehouse::firstOrFail();
    }

    private function postSalaryEntry(Employee $employee, string $amount = '500.000000', string $rate = '3.5000000000', string $date = '2026-09-30', string $start = '2026-09-01', string $end = '2026-09-30'): SalaryEntry
    {
        return app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $employee->id,
            'recognition_date' => $date,
            'period_start' => $start,
            'period_end' => $end,
            'currency_code' => 'USD',
            'exchange_rate' => $rate,
            'base_salary' => $amount,
            'idempotency_key' => 'sal-entry-'.uniqid(),
        ]);
    }

    private function postLandedExpense(string $amount = '100.000000', string $date = '2026-10-02'): Expense
    {
        return app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->landedCategory->id,
            'expense_date' => $date,
            'classification' => Expense::CLASSIFICATION_LANDED_COST,
            'description' => 'شحن وتخليص تصحيح',
            'currency_code' => 'ILS',
            'amount' => $amount,
            'exchange_rate' => '1.0000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id,
            'idempotency_key' => 'landed-exp-'.uniqid(),
        ]);
    }

    private function createPurchaseDraft(string $date = '2026-10-05', string $currency = 'ILS', string $rate = '1.0000000000', string $unitCost = '100.000000'): Purchase
    {
        return app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'vendor_invoice_number' => 'INV-'.uniqid(),
            'purchase_date' => $date,
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $this->stockProduct->id,
                    'quantity' => '5.000000',
                    'unit_cost' => $unitCost,
                    'lots' => [],
                ],
            ],
        ]);
    }

    // =========================================================================
    // Finding A: Settle recognized salary after Employee retirement
    // =========================================================================

    public function test_soft_deleted_employee_cash_salary_settlement_payable_relief_and_reconciliation(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        // 1. Post recognized salary entry
        $entry = $this->postSalaryEntry($this->employee, '500.000000', '3.5000000000');
        $this->assertSame('500.000000', (string) $entry->getRemainingPayableAmount());

        // Snapshot original employee attributes
        $originalName = $this->employee->name;
        $originalCode = $this->employee->code;
        $originalSalary = $this->employee->default_salary;

        // 2. Soft-delete employee using real DeleteEmployeeAction
        app(DeleteEmployeeAction::class)->execute($this->employee, $this->owner);

        $this->employee->refresh();
        $this->assertTrue($this->employee->trashed());
        $this->assertFalse($this->employee->active);
        $this->assertSame($originalName, $this->employee->name);
        $this->assertSame($originalCode, $this->employee->code);
        $this->assertSame($originalSalary, $this->employee->default_salary);

        // 3. Settle salary for the retired employee via Cash USD
        $payment = app(PostSalaryPaymentAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'payment_date' => '2026-10-05',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'amount' => '500.000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id,
            'allocations' => [
                ['salary_entry_id' => $entry->id, 'allocated_amount' => '500.000000'],
            ],
            'notes' => 'تسوية راتب موظف متقاعد/مؤرشف',
            'idempotency_key' => 'sal-pay-retired-cash',
        ]);

        $this->assertInstanceOf(SalaryPayment::class, $payment);
        $this->assertSame('posted', $payment->status);
        $this->assertSame('500.000000', $payment->amount);
        $this->assertSame('1750.000000', $payment->base_amount);
        $this->assertSame('0.000000', (string) $entry->fresh()->getRemainingPayableAmount());

        // 4. Assert employee attributes remain completely unchanged
        $this->employee->refresh();
        $this->assertTrue($this->employee->trashed());
        $this->assertSame($originalName, $this->employee->name);
        $this->assertSame($originalCode, $this->employee->code);
        $this->assertSame($originalSalary, $this->employee->default_salary);

        // 5. Assert Phase7History is valid
        app(Phase7History::class)->validate($payment);

        // 6. Assert healthy Phase7 reconciliation
        $report = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($report->isHealthy);
        $this->assertEmpty($report->violations);
    }

    public function test_inactive_employee_bank_salary_settlement_with_exact_gl(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        // 1. Post recognized salary entry: 500 USD @ 3.50 = 1750 ILS
        $entry = $this->postSalaryEntry($this->employee, '500.000000', '3.5000000000');

        // 2. Mark employee inactive (not deleted)
        $this->employee->update(['active' => false]);
        $this->assertFalse($this->employee->fresh()->active);
        $this->assertNull($this->employee->fresh()->deleted_at);

        // 3. Settle salary via Bank USD at rate 3.60: 500 USD @ 3.60 = 1800 ILS
        // Book relief = 1750 ILS, FX loss = 50 ILS
        $payment = app(PostSalaryPaymentAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'payment_date' => '2026-10-05',
            'currency_code' => 'USD',
            'exchange_rate' => '3.6000000000',
            'amount' => '500.000000',
            'payment_method' => 'bank',
            'money_account_id' => $this->usdBankAccount->id,
            'allocations' => [
                ['salary_entry_id' => $entry->id, 'allocated_amount' => '500.000000'],
            ],
            'notes' => 'تسوية راتب موظف غير نشط بالبنك',
            'idempotency_key' => 'sal-pay-inactive-bank',
        ]);

        $this->assertSame('posted', $payment->status);
        $this->assertSame('1800.000000', $payment->base_amount);
        $this->assertSame('1750.000000', $payment->salary_book_relief_base);
        $this->assertSame('50.000000', $payment->realized_fx_gain_loss_base);

        // Verify GL batch lines
        $batch = PostingBatch::findOrFail($payment->posting_batch_id);
        $lines = $batch->lines()->orderBy('line_number')->get();

        $salaryPayableAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'salary_payable')->firstOrFail();
        $fxAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'fx_loss')->firstOrFail();

        // Line 1: Debit Salary Payable: 1750
        $this->assertSame($salaryPayableAccount->id, $lines[0]->ledger_account_id);
        $this->assertSame('1750.000000', $lines[0]->debit_base);
        $this->assertSame('0.000000', $lines[0]->credit_base);

        // Line 2: Credit Bank Account: 1800
        $this->assertSame($this->usdBankAccount->ledgerAccount->id, $lines[1]->ledger_account_id);
        $this->assertSame('0.000000', $lines[1]->debit_base);
        $this->assertSame('1800.000000', $lines[1]->credit_base);

        // Line 3: Debit Realized FX Loss: 50
        $this->assertSame($fxAccount->id, $lines[2]->ledger_account_id);
        $this->assertSame('50.000000', $lines[2]->debit_base);
        $this->assertSame('0.000000', $lines[2]->credit_base);
    }

    public function test_retired_employee_outgoing_salary_payment_check_clearance_and_return_reversal(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        // 1. Post recognized salary entries
        $entry1 = $this->postSalaryEntry($this->employee, '300.000000', '3.5000000000', '2026-08-31', '2026-08-01', '2026-08-31');
        $entry2 = $this->postSalaryEntry($this->employee, '200.000000', '3.5000000000', '2026-09-30', '2026-09-01', '2026-09-30');

        // 2. Soft delete employee
        app(DeleteEmployeeAction::class)->execute($this->employee, $this->owner);
        $this->assertTrue($this->employee->fresh()->trashed());

        // 3. Test Clearance flow: Issue Salary Payment Check 1 for entry 1
        $check1 = app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'salary_payment',
            'employee_id' => $this->employee->id,
            'payee_name' => $this->employee->name,
            'date' => '2026-10-02',
            'due_date' => '2026-10-03',
            'currency_code' => 'USD',
            'amount' => '300.000000',
            'exchange_rate' => '3.5000000000',
            'money_account_id' => $this->usdBankAccount->id,
            'check_number' => 'CHK-RETIRED-CLEAR',
            'bank_name' => 'QA Bank',
            'idempotency_key' => 'chk-sal-ret-clear',
            'allocations' => [
                ['salary_entry_id' => $entry1->id, 'allocated_amount' => '300.000000'],
            ],
        ]);

        $this->assertInstanceOf(Check::class, $check1);
        $this->assertSame('issued', $check1->status);
        $source1 = app(CheckFinancialSourceResolver::class)->resolve($check1)->sourceModel();
        $this->assertInstanceOf(SalaryPayment::class, $source1);
        $this->assertSame($this->employee->id, $source1->employee_id);
        $this->assertSame($this->employee->name, $source1->employee_snapshot['name']);
        app(Phase7History::class)->validate($source1);
        $this->assertSame('0.000000', (string) $entry1->fresh()->getRemainingPayableAmount());

        // Clear check 1
        app(TransitionCheckAction::class)->execute($check1, $this->owner, [
            'event_type' => 'clear',
            'event_date' => '2026-10-04',
            'exchange_rate' => '3.5000000000',
            'idempotency_key' => 'clear-chk-ret-01',
        ]);
        $this->assertSame('cleared', $check1->fresh()->status);
        app(CheckHistory::class)->validate($check1->fresh());

        // 4. Test Cancellation/Return provenance flow: Issue Salary Payment Check 2 for entry 2
        $check2 = app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'salary_payment',
            'employee_id' => $this->employee->id,
            'payee_name' => $this->employee->name,
            'date' => '2026-10-02',
            'due_date' => '2026-10-03',
            'currency_code' => 'USD',
            'amount' => '200.000000',
            'exchange_rate' => '3.5000000000',
            'money_account_id' => $this->usdBankAccount->id,
            'check_number' => 'CHK-RETIRED-CANCEL',
            'bank_name' => 'QA Bank',
            'idempotency_key' => 'chk-sal-ret-cancel',
            'allocations' => [
                ['salary_entry_id' => $entry2->id, 'allocated_amount' => '200.000000'],
            ],
        ]);

        $sourcePayment2 = SalaryPayment::where('check_id', $check2->id)->firstOrFail();
        $this->assertSame('issued', $check2->status);
        $this->assertSame('posted', $sourcePayment2->status);
        $this->assertSame('0.000000', (string) $entry2->fresh()->getRemainingPayableAmount());

        // Cancel/return check 2
        app(TransitionCheckAction::class)->execute($check2, $this->owner, [
            'event_type' => 'cancel',
            'event_date' => '2026-10-04',
            'notes' => 'إلغاء شيك الموظف المتقاعد',
            'idempotency_key' => 'cancel-chk-ret-02',
        ]);

        $this->assertSame('cancelled', $check2->fresh()->status);
        $this->assertSame('reversed', $sourcePayment2->fresh()->status);
        // Entry 2 payable is restored after check cancellation
        $this->assertSame('200.000000', (string) $entry2->fresh()->getRemainingPayableAmount());
        app(CheckHistory::class)->validate($check2->fresh());
    }

    public function test_inactive_and_deleted_employee_rejects_new_advance_and_salary_entry(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        // Case A: Inactive employee
        $inactiveEmp = Employee::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => (int) $this->company->id,
            'code' => 'EMP-INACT',
            'name' => 'موظف غير نشط',
            'hire_date' => '2026-01-01',
            'default_salary' => '1000.000000',
            'salary_currency_code' => 'USD',
            'active' => false,
            'created_by' => (int) $this->owner->id,
        ]);

        $beforeBatches = DB::table('posting_batches')->count();
        $beforeAdvances = DB::table('employee_advances')->count();
        $beforeEntries = DB::table('salary_entries')->count();
        $beforeSequences = DB::table('document_sequences')->orderBy('id')->get()->toJson();

        // Advance rejected
        try {
            app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, [
                'employee_id' => $inactiveEmp->id,
                'advance_date' => '2026-10-01',
                'currency_code' => 'USD',
                'amount' => '100.000000',
                'exchange_rate' => '3.5000000000',
                'payment_method' => 'cash',
                'money_account_id' => $this->usdCashAccount->id,
                'idempotency_key' => 'adv-inact-fail',
            ]);
            $this->fail('Expected advance on inactive employee to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('active', $e->getMessage());
        }

        // Salary entry rejected
        try {
            app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
                'employee_id' => $inactiveEmp->id,
                'recognition_date' => '2026-10-01',
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-30',
                'currency_code' => 'USD',
                'exchange_rate' => '3.5000000000',
                'base_salary' => '1000.000000',
                'idempotency_key' => 'entry-inact-fail',
            ]);
            $this->fail('Expected salary entry on inactive employee to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('active', $e->getMessage());
        }

        // Case B: Soft-deleted employee
        $deletedEmp = Employee::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => (int) $this->company->id,
            'code' => 'EMP-DEL',
            'name' => 'موظف محذوف',
            'hire_date' => '2026-01-01',
            'default_salary' => '1000.000000',
            'salary_currency_code' => 'USD',
            'active' => true,
            'created_by' => (int) $this->owner->id,
        ]);
        $deletedEmp->delete(); // Soft delete directly (no history)

        try {
            app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, [
                'employee_id' => $deletedEmp->id,
                'advance_date' => '2026-10-01',
                'currency_code' => 'USD',
                'amount' => '100.000000',
                'exchange_rate' => '3.5000000000',
                'payment_method' => 'cash',
                'money_account_id' => $this->usdCashAccount->id,
                'idempotency_key' => 'adv-del-fail',
            ]);
            $this->fail('Expected advance on deleted employee to be rejected.');
        } catch (\Throwable $e) {
            $this->assertTrue($e instanceof InvalidArgumentException || $e instanceof ModelNotFoundException);
        }

        try {
            app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
                'employee_id' => $deletedEmp->id,
                'recognition_date' => '2026-10-01',
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-30',
                'currency_code' => 'USD',
                'exchange_rate' => '3.5000000000',
                'base_salary' => '1000.000000',
                'idempotency_key' => 'entry-del-fail',
            ]);
            $this->fail('Expected salary entry on deleted employee to be rejected.');
        } catch (\Throwable $e) {
            $this->assertTrue($e instanceof InvalidArgumentException || $e instanceof ModelNotFoundException);
        }

        // Assert DB counts unchanged
        $this->assertSame($beforeBatches, DB::table('posting_batches')->count());
        $this->assertSame($beforeAdvances, DB::table('employee_advances')->count());
        $this->assertSame($beforeEntries, DB::table('salary_entries')->count());
        $this->assertSame($beforeSequences, DB::table('document_sequences')->orderBy('id')->get()->toJson());
    }

    public function test_salary_payment_form_selector_includes_outstanding_retired_and_excludes_settled(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        // Active employee is in selector
        $component = Livewire::test(SalaryPaymentForm::class);
        $component->assertSee($this->employee->name);

        // Create a retired employee with outstanding payable
        $retiredEmp = Employee::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => (int) $this->company->id,
            'code' => 'EMP-RET-SEL',
            'name' => 'متقاعد مستحق',
            'hire_date' => '2026-01-01',
            'default_salary' => '1000.000000',
            'salary_currency_code' => 'USD',
            'active' => true,
            'created_by' => (int) $this->owner->id,
        ]);
        $entry = $this->postSalaryEntry($retiredEmp, '500.000000', '3.5000000000');
        app(DeleteEmployeeAction::class)->execute($retiredEmp, $this->owner);

        // Retired employee with outstanding balance is included with archived status suffix
        $component = Livewire::test(SalaryPaymentForm::class);
        $component->assertSee($retiredEmp->name);
        $component->assertSee(__('payroll.archived'));

        // Preselection via URL works
        $component = Livewire::test(SalaryPaymentForm::class, ['employee' => $retiredEmp->public_id]);
        $component->assertSet('employeeId', (string) $retiredEmp->id);

        // Submit through Livewire to prove historical preselection and redirect remain valid.
        $component->set('paymentDate', '2026-10-05')
            ->set('exchangeRate', '3.5000000000')
            ->set('moneyAccountId', $this->usdCashAccount->id)
            ->set('entryAllocations', [$entry->id => '500.000000'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('employees.show', $retiredEmp->public_id));
        $this->assertSame('0.000000', (string) $entry->fresh()->getRemainingPayableAmount());
        $this->assertTrue($retiredEmp->fresh()->trashed());

        // After full settlement, retired employee is EXCLUDED from the payment selector
        $component = Livewire::test(SalaryPaymentForm::class);
        $component->assertDontSee($retiredEmp->name);
    }

    public function test_archived_employee_detail_auditable_reversals_reachable_new_obligations_absent(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        // 1. Post salary entry
        $entry = $this->postSalaryEntry($this->employee, '500.000000', '3.5000000000');

        // 2. Soft-delete employee
        app(DeleteEmployeeAction::class)->execute($this->employee, $this->owner);

        // 3. EmployeeDetail renders with archived badge
        $component = Livewire::test(EmployeeDetail::class, ['publicId' => $this->employee->public_id]);
        $component->assertStatus(200);
        $component->assertSee(__('payroll.archived'));

        // 4. Assert new obligation links/buttons are absent
        $component->assertDontSee(__('payroll.create_salary_entry'));
        $component->assertDontSee(__('payroll.create_advance'));

        // 5. Assert create salary payment link IS present
        $component->assertSee(__('payroll.create_salary_payment'));

        // 6. Authorized reversal action is reachable and succeeds
        $component->set('reversalReason', 'تصحيح خطأ إداري')
            ->set('reversalDate', '2026-10-05')
            ->call('reverseSalaryEntry', $entry->id)
            ->assertHasNoErrors();
        $this->assertSame('reversed', $entry->fresh()->status);

        // 7. Soft-deleted employee WITHOUT history returns 404
        $emptyEmp = Employee::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => (int) $this->company->id,
            'code' => 'EMP-EMPTY-DEL',
            'name' => 'فارغ محذوف',
            'hire_date' => '2026-01-01',
            'default_salary' => '1000.000000',
            'salary_currency_code' => 'USD',
            'active' => true,
            'created_by' => (int) $this->owner->id,
        ]);
        $emptyEmp->delete();

        Livewire::test(EmployeeDetail::class, ['publicId' => $emptyEmp->public_id])
            ->assertStatus(404);
    }

    public function test_identity_only_and_stale_permission_readback_retain_redaction(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        $entry = $this->postSalaryEntry($this->employee, '500.000000', '3.5000000000');
        app(DeleteEmployeeAction::class)->execute($this->employee, $this->owner);

        // Reader with identity-only permission (employees.view, no payroll.salary.view)
        $reader = $this->customActor(['employees.view']);
        $this->activate($reader);

        $detail = app(PayrollReadService::class)->employeeDetail($this->employee, $reader);

        // Financial data must be redacted
        $this->assertFalse($detail['has_salary_view']);
        $this->assertNull($detail['employee']->default_salary);
        $this->assertNull($detail['employee']->salary_currency_code);

        // View and Livewire payload redaction
        $component = Livewire::actingAs($reader)->test(EmployeeDetail::class, ['publicId' => $this->employee->public_id]);
        $component->assertStatus(200);
        $this->assertStringNotContainsString('500.00', json_encode($component->snapshot));

        // Revoke financial authority on an already mounted historical page.
        $financialReader = $this->customActor(['employees.view', 'payroll.salary.view']);
        $this->activate($financialReader);
        $stale = Livewire::actingAs($financialReader)->test(EmployeeDetail::class, ['publicId' => $this->employee->public_id]);
        $stale->assertViewHas('salaryPositions', fn ($positions) => count($positions) === 1);
        $financialReader->roles()->firstOrFail()->revokePermissionTo('payroll.salary.view');
        $stale->call('$refresh')->assertStatus(200)
            ->assertViewHas('salaryPositions', fn ($positions) => $positions === []);
        $this->assertStringNotContainsString('500.00', json_encode($stale->snapshot));
        $this->activate($reader);

        // Foreign employee public ID must fail closed
        $otherCompany = Company::create([
            'name_ar' => 'شركة أخرى للاختبار',
            'base_currency_code' => 'USD',
            'status' => 'active',
        ]);
        $foreignEmp = CompanyScope::executeWithoutScope(fn () => Employee::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $otherCompany->id,
            'code' => 'FOREIGN-EMP',
            'name' => 'Foreign Employee',
            'hire_date' => '2026-01-01',
            'default_salary' => '1000.000000',
            'salary_currency_code' => 'USD',
            'active' => true,
            'created_by' => (int) $this->owner->id,
        ]));

        Livewire::actingAs($reader)->test(EmployeeDetail::class, ['publicId' => $foreignEmp->public_id])
            ->assertStatus(404);
    }

    // =========================================================================
    // Finding B: Label base acquisition values with the stored base currency
    // =========================================================================

    public function test_purchase_detail_labels_base_acquisition_values_with_base_currency_code(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        // 1. Landed expense in ILS: 100 ILS
        $expense = $this->postLandedExpense('100.000000', '2026-10-02');

        // 2. Purchase in USD: 5 pieces @ 20 USD = 100 USD (@ 3.50 = 350 ILS base)
        $purchase = $this->createPurchaseDraft('2026-10-05', 'USD', '3.5000000000', '20.000000');

        // 3. Allocate landed cost
        app(AllocateLandedCostAction::class)->execute($purchase, $expense, LandedCostAllocation::METHOD_VALUE, $this->owner);

        // 4. Post purchase
        $postedPurchase = app(PostPurchaseAction::class)->execute($purchase, $this->owner);
        $this->assertSame('posted', $postedPurchase->status);

        // 5. Test English view
        app()->setLocale('en');
        $readModel = app(PurchaseReadModel::class)->detail($postedPurchase, true);
        $this->assertSame('ILS', $readModel['base_currency_code']);
        $this->assertSame('USD', $readModel['currency_code']);

        $componentEn = Livewire::test(PurchaseDetail::class, ['publicId' => $postedPurchase->public_id]);
        $componentEn->assertStatus(200);

        // Commercial totals should use USD
        $htmlEn = $componentEn->html();
        $this->assertStringContainsString('USD', $htmlEn);

        // Landed cost and base acquisition should be labeled with ILS
        $this->assertGreaterThanOrEqual(4, substr_count($htmlEn, '100.00 ILS'));
        $this->assertStringContainsString('90.00 ILS', $htmlEn);
        $this->assertStringContainsString('100.00 USD', $htmlEn);
        $this->assertStringNotContainsString('90.00 USD', $htmlEn);

        // 6. Test Arabic view
        app()->setLocale('ar');
        $componentAr = Livewire::test(PurchaseDetail::class, ['publicId' => $postedPurchase->public_id]);
        $componentAr->assertStatus(200);
        $htmlAr = $componentAr->html();
        $this->assertGreaterThanOrEqual(4, substr_count($htmlAr, '100.00 ILS'));
        $this->assertStringContainsString('90.00 ILS', $htmlAr);
        $this->assertStringContainsString('100.00 USD', $htmlAr);
        $this->assertStringNotContainsString('90.00 USD', $htmlAr);

        // 7. Restricted user without purchasing.cost.view has cost fields redacted
        $restrictedActor = $this->customActor(['purchasing.purchase.view']);
        $this->activate($restrictedActor);

        $redactedData = app(PurchaseReadModel::class)->detail($postedPurchase, false);
        $this->assertArrayNotHasKey('base_currency_code', $redactedData);
        $this->assertArrayNotHasKey('total_landed_cost_base', $redactedData);
    }

    // =========================================================================
    // Finding C: Reject post-dated landed costs before allocation mutation
    // =========================================================================

    public function test_direct_allocation_post_dated_expense_rejects_with_no_mutations(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        // Purchase on Oct 5
        $purchase = $this->createPurchaseDraft('2026-10-05');

        // Landed expense on Oct 6 (post-dated)
        $expense = $this->postLandedExpense('100.000000', '2026-10-06');

        $beforeAllocations = LandedCostAllocation::count();
        $beforeBatches = PostingBatch::count();
        $beforeMovements = StockMovement::count();

        // Attempt direct allocation
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expense date [2026-10-06] cannot be after purchase date [2026-10-05].');

        try {
            app(AllocateLandedCostAction::class)->execute($purchase, $expense, LandedCostAllocation::METHOD_VALUE, $this->owner);
        } finally {
            $this->assertSame($beforeAllocations, LandedCostAllocation::count());
            $this->assertSame($beforeBatches, PostingBatch::count());
            $this->assertSame($beforeMovements, StockMovement::count());
        }
    }

    public function test_invalid_attempted_replacement_preserves_prior_valid_plan_exactly(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        // Purchase on Oct 5
        $purchase = $this->createPurchaseDraft('2026-10-05');

        // Valid expense on Oct 4
        $validExpense = $this->postLandedExpense('100.000000', '2026-10-04');
        $allocations = app(AllocateLandedCostAction::class)->execute($purchase, $validExpense, LandedCostAllocation::METHOD_VALUE, $this->owner);
        $this->assertCount(1, $allocations);
        $originalAllocId = $allocations->first()->id;
        $originalAmount = $allocations->first()->allocated_base;

        // Invalid expense on Oct 6
        $invalidExpense = $this->postLandedExpense('50.000000', '2026-10-06');

        try {
            app(AllocateLandedCostAction::class)->execute($purchase, $invalidExpense, LandedCostAllocation::METHOD_VALUE, $this->owner);
            $this->fail('Expected rejection of post-dated expense allocation.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be after purchase date', $e->getMessage());
        }

        // Assert prior valid allocation is preserved exactly
        $existing = LandedCostAllocation::where('purchase_id', $purchase->id)->get();
        $this->assertCount(1, $existing);
        $this->assertSame($originalAllocId, $existing->first()->id);
        $this->assertSame($originalAmount, $existing->first()->allocated_base);
        $this->assertSame(LandedCostAllocation::STATUS_DRAFT, $existing->first()->status);
    }

    public function test_update_purchase_date_to_precede_attached_expense_rolls_back_entirely(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        // Purchase on Oct 6 with attached Expense on Oct 5
        $purchase = $this->createPurchaseDraft('2026-10-06');
        $expense = $this->postLandedExpense('100.000000', '2026-10-05');
        $allocations = app(AllocateLandedCostAction::class)->execute($purchase, $expense, LandedCostAllocation::METHOD_VALUE, $this->owner);

        $originalAllocId = $allocations->first()->id;
        $originalLineId = $purchase->lines()->first()->id;
        $originalDate = $purchase->purchase_date->toDateString();

        // Attempt to update purchase draft to Oct 4 (before Oct 5 expense)
        try {
            app(UpdatePurchaseDraftAction::class)->execute($purchase, $this->owner, [
                'vendor_id' => $this->vendor->id,
                'warehouse_id' => $this->warehouse->id,
                'vendor_invoice_number' => $purchase->vendor_invoice_number,
                'purchase_date' => '2026-10-04',
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'document_locale' => 'ar',
                'lines' => [
                    [
                        'product_id' => $this->stockProduct->id,
                        'quantity' => '10.000000',
                        'unit_cost' => '100.000000',
                    ],
                ],
            ]);
            $this->fail('Expected rejection when purchase date updated to precede expense date.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be after purchase date', $e->getMessage());
        }

        // Verify entire operation was rolled back
        $purchase->refresh();
        $this->assertSame('2026-10-06', $purchase->purchase_date->toDateString());
        $this->assertSame($originalLineId, $purchase->lines()->first()->id);
        $this->assertSame('5.000000', $purchase->lines()->first()->quantity);

        $currentAllocations = LandedCostAllocation::where('purchase_id', $purchase->id)->get();
        $this->assertCount(1, $currentAllocations);
        $this->assertSame($originalAllocId, $currentAllocations->first()->id);
    }

    public function test_purchase_detail_selector_excludes_post_dated_expense_and_offers_same_day_or_earlier(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        // Purchase on Oct 5
        $purchase = $this->createPurchaseDraft('2026-10-05');

        // Expense 1: Oct 4 (earlier)
        $expenseEarlier = $this->postLandedExpense('50.000000', '2026-10-04');

        // Expense 2: Oct 5 (same day)
        $expenseSameDay = $this->postLandedExpense('60.000000', '2026-10-05');

        // Expense 3: Oct 6 (post-dated)
        $expensePostDated = $this->postLandedExpense('70.000000', '2026-10-06');

        $component = Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id]);
        $component->assertStatus(200);

        /** @var Collection $available */
        $available = $component->viewData('availableExpenses');

        $availableIds = $available->pluck('id')->all();
        $this->assertContains($expenseEarlier->id, $availableIds);
        $this->assertContains($expenseSameDay->id, $availableIds);
        $this->assertNotContains($expensePostDated->id, $availableIds);
    }

    public function test_canonical_posting_fails_closed_if_invalid_chronology_bypassed(): void
    {
        $this->actingAs($this->owner);
        session(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->setCompany($this->company);

        // Purchase on Oct 5
        $purchase = $this->createPurchaseDraft('2026-10-05');
        $line = $purchase->lines()->first();

        // Landed expense on Oct 6
        $expense = $this->postLandedExpense('100.000000', '2026-10-06');

        // Simulate database corruption / direct bypass creating invalid chronology allocation
        LandedCostAllocation::create([
            'company_id' => $this->company->id,
            'purchase_id' => $purchase->id,
            'purchase_line_id' => $line->id,
            'expense_id' => $expense->id,
            'allocation_method' => LandedCostAllocation::METHOD_VALUE,
            'allocated_base' => '100.000000',
            'status' => LandedCostAllocation::STATUS_DRAFT,
        ]);

        $beforeBatches = PostingBatch::count();
        $beforeMovements = StockMovement::count();

        // Canonical post must fail closed
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('chronology');

        try {
            app(PostPurchaseAction::class)->execute($purchase, $this->owner);
        } finally {
            $this->assertSame(Purchase::STATUS_DRAFT, $purchase->fresh()->status);
            $this->assertSame($beforeBatches, PostingBatch::count());
            $this->assertSame($beforeMovements, StockMovement::count());
        }
    }
}
