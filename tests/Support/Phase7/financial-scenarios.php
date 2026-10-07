<?php

declare(strict_types=1);
use App\Actions\Accounting\EnsurePhase7FoundationAction;
use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Expenses\ReverseExpenseAction;
use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\TransitionCheckAction;
use App\Actions\Payroll\CreateEmployeeAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Payroll\PostSalaryPaymentAction;
use App\Actions\Payroll\ReverseSalaryPaymentAction;
use App\Actions\Purchasing\AllocateLandedCostAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Models\Check;
use App\Models\Company;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Purchase;
use App\Models\SalaryEntry;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || ! str_starts_with(DB::connection()->getDatabaseName(), 'accounting_phase7_')) {
    throw new RuntimeException('Disposable Phase 7 DB only.');
}
$mode = $argv[1];
$file = $argv[2];
$f = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
$company = Company::findOrFail($f['company']);
$owner = User::findOrFail($f['owner']);
auth()->login($owner);
app(CompanyContext::class)->setCompany($company, $owner);
setPermissionsTeamId($company->id);
$expenseData = fn (string $key, string $classification = 'operating') => ['category_id' => ExpenseCategory::where('company_id', $company->id)->where('code', 'delivery')->sole()->id, 'expense_date' => '2026-10-02', 'classification' => $classification, 'description' => 'QA freight', 'currency_code' => 'ILS', 'amount' => '10', 'exchange_rate' => '1', 'payment_method' => 'cash', 'money_account_id' => $f['ils'], 'idempotency_key' => $key];
$advanceData = function (string $key) use (&$f): array {
    return ['employee_id' => $f['employee'], 'advance_date' => '2026-10-02', 'currency_code' => 'ILS', 'amount' => '50', 'exchange_rate' => '1', 'payment_method' => 'cash', 'money_account_id' => $f['ils'], 'idempotency_key' => $key];
};
if ($mode === 'prepare') {
    app(EnsurePhase7FoundationAction::class)->execute($company);
    $owner->unsetRelation('roles')->unsetRelation('permissions');
    $employee = app(CreateEmployeeAction::class)->execute($company, $owner, ['name' => 'Phase7 QA Employee', 'code' => 'EMP-QA', 'default_salary' => '50', 'salary_currency_code' => 'ILS', 'active' => true]);
    $f['employee'] = $employee->id;
    $f['reverse_expense'] = app(PostExpenseAction::class)->execute($company, $owner, $expenseData('reverse-expense'))->id;
    $f['competing_advance'] = app(PostEmployeeAdvanceAction::class)->execute($company, $owner, $advanceData('competing-advance'))->id;
    $f['landed_expense'] = app(PostExpenseAction::class)->execute($company, $owner, $expenseData('race-landed', 'landed_cost'))->id;
    $product = DB::table('products')->where('company_id', $company->id)->first();
    $draft = app(CreatePurchaseDraftAction::class)->execute($company, $owner, ['vendor_id' => $f['vendor'], 'warehouse_id' => Warehouse::where('company_id', $company->id)->sole()->id, 'purchase_date' => '2026-10-03', 'currency_code' => 'ILS', 'exchange_rate' => '1', 'lines' => [['product_id' => $product->id, 'quantity' => '2', 'unit_cost' => '20']]]);
    app(AllocateLandedCostAction::class)->execute($draft, Expense::findOrFail($f['landed_expense']), 'quantity', $owner);
    $f['landed_purchase'] = $draft->id;
    $check = app(IssueCheckAction::class)->execute($company, $owner, ['source_type' => 'expense', 'category_id' => ExpenseCategory::where('company_id', $company->id)->where('code', 'electricity')->sole()->id, 'description' => 'CHECK_SECRET_EXPENSE', 'classification' => 'operating', 'date' => '2026-10-02', 'due_date' => '2026-10-03', 'check_number' => 'PH7-RACE', 'bank_name' => 'QA Bank', 'money_account_id' => $f['bank'], 'currency_code' => 'USD', 'amount' => '10', 'exchange_rate' => '3.5', 'idempotency_key' => 'phase7-check']);
    $f['check'] = $check->id;
    $f['sequences_before'] = DB::table('document_sequences')->where('company_id', $company->id)->pluck('next_number', 'document_type')->all();
    file_put_contents($file, json_encode($f, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($f);
    exit;
}
if ($mode === 'run') {
    $case = $argv[3];
    $worker = (int) $argv[4];
    $barrier = $argv[5];
    file_put_contents($barrier.'.'.$worker, 'ready');
    $deadline = microtime(true) + 20;
    while (! is_file($barrier) && microtime(true) < $deadline) {
        usleep(10000);
    }if (! is_file($barrier)) {
        throw new RuntimeException('Barrier timeout.');
    }
    try {
        $result = match ($case) {
            'expense' => app(PostExpenseAction::class)->execute($company, $owner, $expenseData('same-expense')),
            'expense-reverse' => app(ReverseExpenseAction::class)->execute(Expense::findOrFail($f['reverse_expense']), $owner, 'Same reversal', '2026-10-03'),
            'advance' => app(PostEmployeeAdvanceAction::class)->execute($company, $owner, $advanceData('same-advance')),
            'salary-entry' => app(PostSalaryEntryAction::class)->execute($company, $owner, ['employee_id' => $f['employee'], 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'recognition_date' => '2026-10-03', 'currency_code' => 'ILS', 'exchange_rate' => '1', 'base_salary' => '100', 'advances' => [['advance_id' => $f['competing_advance'], 'allocated_amount' => '50']], 'idempotency_key' => 'salary-compete-'.$worker]),
            'salary-payment' => app(PostSalaryPaymentAction::class)->execute($company, $owner, ['employee_id' => $f['employee'], 'payment_date' => '2026-10-04', 'currency_code' => 'ILS', 'exchange_rate' => '1', 'amount' => '50', 'payment_method' => 'cash', 'money_account_id' => $f['ils'], 'allocations' => [['salary_entry_id' => SalaryEntry::where('company_id', $company->id)->sole()->id, 'allocated_amount' => '50']], 'idempotency_key' => 'salary-pay-'.$worker]),
            'payment-reverse' => app(ReverseSalaryPaymentAction::class)->execute(SalaryPayment::where('company_id', $company->id)->sole(), $owner, 'Same reverse', '2026-10-05'),
            'landed' => $worker === 0 ? app(PostPurchaseAction::class)->execute(Purchase::findOrFail($f['landed_purchase']), $owner) : app(ReverseExpenseAction::class)->execute(Expense::findOrFail($f['landed_expense']), $owner, 'Freight cancelled', '2026-10-03'),
            'check' => app(TransitionCheckAction::class)->execute(Check::findOrFail($f['check']), $owner, ['event_type' => $worker === 0 ? 'clear' : 'return', 'event_date' => '2026-10-03', 'exchange_rate' => '3.6', 'notes' => 'QA terminal', 'idempotency_key' => 'check-race-'.$worker]),
            default => throw new RuntimeException('Unknown case')
        };
        echo json_encode(['ok' => true, 'id' => $result->id]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e::class, 'message' => $e->getMessage()]);
    }exit;
}
$reports = [];
foreach ([AccountingReconciliationService::class, InventoryReconciliationService::class, SalesReconciliationService::class, PayablesReconciliationService::class, MoneyReconciliationService::class, Phase7ReconciliationService::class] as $service) {
    $r = $service === InventoryReconciliationService::class ? app($service)->auditCompany($company) : app($service)->reconcile($company);
    $reports[$service] = ['healthy' => $r->isHealthy, 'violations' => $service === InventoryReconciliationService::class ? $r->discrepancies : $r->violations];
}
$counts = [];
foreach (['expenses', 'employee_advances', 'salary_entries', 'salary_payments', 'salary_advance_allocations', 'salary_payment_allocations', 'landed_cost_allocations', 'posting_batches', 'posting_lines', 'checks', 'check_events'] as $t) {
    $counts[$t] = DB::table($t)->count();
}
$entry = SalaryEntry::where('company_id', $company->id)->sole();
$advance = EmployeeAdvance::findOrFail($f['competing_advance']);
$purchase = Purchase::findOrFail($f['landed_purchase']);
$landed = Expense::findOrFail($f['landed_expense']);
$gl = DB::table('posting_lines')->join('posting_batches', 'posting_batches.id', '=', 'posting_lines.posting_batch_id')->select('posting_batches.source_type', 'posting_batches.source_id', 'posting_batches.posting_date', 'posting_lines.ledger_account_id', 'posting_lines.debit_base', 'posting_lines.credit_base', 'posting_lines.transaction_currency_code', 'posting_lines.transaction_amount')->orderBy('posting_lines.id')->get();
echo json_encode(['reconciliations' => $reports, 'counts' => $counts, 'advance_remaining' => (string) $advance->getRemainingAmount(), 'salary_remaining' => (string) $entry->getRemainingPayableAmount(), 'payment_status' => SalaryPayment::sole()->status, 'expense_reverse_status' => Expense::findOrFail($f['reverse_expense'])->status, 'purchase_status' => $purchase->status, 'landed_expense_status' => $landed->status, 'check_status' => Check::findOrFail($f['check'])->status, 'sequences' => DB::table('document_sequences')->where('company_id', $company->id)->pluck('next_number', 'document_type')->all(), 'gl' => $gl], JSON_THROW_ON_ERROR);
