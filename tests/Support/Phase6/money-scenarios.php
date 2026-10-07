<?php

declare(strict_types=1);

// Standalone MariaDB integration harness. Refuses any database outside the disposable Phase 6 namespace.
use App\Actions\Company\CreateCompanyAction;
use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\PostMoneyTransferAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Money\TransitionCheckAction;
use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Domain\Inventory\DTO\InventoryReconciliationReport;
use App\Models\Check;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use App\Models\VendorPayment;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorCatalogService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || ! str_starts_with((string) config('database.connections.mysql.database'), 'accounting_phase6_')) {
    throw new RuntimeException('Only an explicitly disposable Phase 6 testing database is allowed.');
}
$mode = $argv[1] ?? 'audit';
$file = $argv[2] ?? throw new RuntimeException('Fixture manifest path is required.');

if (in_array($mode, ['fixture', 'fixture-legacy'], true)) {
    $legacy = $mode === 'fixture-legacy';
    if (DB::table('companies')->exists()) {
        throw new RuntimeException('Fixture requires a new empty disposable database.');
    }
    $owner = User::factory()->create(['email' => 'phase6@example.test', 'password' => 'phase6-local-only', 'locale' => 'ar']);
    $company = app(CreateCompanyAction::class)->execute($owner, ['name_ar' => 'شركة اختبار المال', 'name_en' => 'Money QA', 'base_currency_code' => 'ILS']);
    app(CompanyContext::class)->setCompany($company, $owner);
    auth()->login($owner);
    setPermissionsTeamId($company->id);
    $account = function (string $type, string $currency) use ($company, $owner) {
        return app(CreateMoneyAccountAction::class)->execute($company, $owner, ['account_type' => $type, 'currency_code' => $currency, 'name_ar' => $type.' '.$currency, 'name_en' => $type.' '.$currency]);
    };
    $cash = $account('cash', 'USD');
    $bank = $account('bank', 'USD');
    $ils = $account('cash', 'ILS');
    $jod = $account('bank', 'JOD');
    $customer = Customer::create(['company_id' => $company->id, 'name_ar' => 'عميل الاختبار', 'name_en' => 'QA Customer', 'status' => 'active', 'created_by' => $owner->id]);
    $vendor = app(VendorCatalogService::class)->save($company, $owner, ['name_ar' => 'مورد الاختبار', 'name_en' => 'QA Vendor', 'default_currency_code' => 'USD']);
    $product = app(ProductCatalogService::class)->createProduct($company, ['name_ar' => 'منتج الاختبار', 'name_en' => 'QA Product', 'sku' => 'QA-MONEY',
        'product_type' => 'stock', 'track_stock' => true, 'track_expiry' => false, 'base_unit_id' => Unit::where('code', 'piece')->sole()->id], $owner->id);
    $invoice = app(PostSalesInvoiceAction::class)->execute(app(CreateSalesInvoiceDraftAction::class)->execute($company, $owner,
        ['customer_id' => $customer->id, 'currency_code' => 'USD', 'exchange_rate' => '3.50', 'issue_date' => '2026-10-01',
            'lines' => [['product_id' => null, 'item_description' => 'Service', 'quantity' => '1', 'unit_price' => '100']]]), $owner);
    $purchases = [];
    for ($i = 0; $i < 3; $i++) {
        $purchases[] = app(PostPurchaseAction::class)->execute(app(CreatePurchaseDraftAction::class)->execute($company, $owner,
            ['vendor_id' => $vendor->id, 'warehouse_id' => Warehouse::where('company_id', $company->id)->sole()->id,
                'currency_code' => 'USD', 'exchange_rate' => '3.50', 'purchase_date' => '2026-10-01',
                'lines' => [['product_id' => $product->id, 'quantity' => '10', 'unit_cost' => '10']]]), $owner);
    }
    $checkData = ['party_id' => $customer->id, 'check_number' => 'QA-IN', 'bank_name' => 'QA Bank', 'date' => '2026-10-02', 'due_date' => '2026-10-03',
        'currency_code' => 'USD', 'amount' => '100', 'exchange_rate' => '3.50', 'idempotency_key' => 'initial-check'];
    $deposited = [];
    for ($i = 0; $i < ($legacy ? 0 : 2); $i++) {
        $check = app(ReceiveCheckAction::class)->execute($company, $owner, array_replace($checkData, ['idempotency_key' => 'deposit-check-'.$i, 'check_number' => 'QA-DEPOSIT-'.$i]));
        app(TransitionCheckAction::class)->execute($check, $owner, ['event_type' => 'deposit', 'event_date' => '2026-10-03', 'money_account_id' => $bank->id, 'idempotency_key' => 'deposit-'.$i]);
        $deposited[] = $check->id;
    }
    $advances = [];
    for ($i = 0; $i < 2; $i++) {
        $advances[] = app(PostVendorPaymentAction::class)->execute($company, $owner, ['vendor_id' => $vendor->id, 'money_account_id' => $ils->id,
            'payment_method' => 'cash', 'payment_date' => '2026-10-02', 'amount' => '400', 'exchange_rate' => '1', 'idempotency_key' => 'advance-'.$i])->id;
    }
    if ($legacy) {
        app(PostCustomerPaymentAction::class)->execute($company, $owner, ['customer_id' => $customer->id, 'money_account_id' => $cash->id,
            'payment_method' => 'cash', 'payment_date' => '2026-10-02', 'amount' => '100', 'exchange_rate' => '3.50', 'idempotency_key' => 'legacy-receipt',
            'allocations' => [['sales_invoice_id' => $invoice->id, 'allocated_amount' => '100']]]);
        app(PostVendorPaymentAction::class)->execute($company, $owner, ['vendor_id' => $vendor->id, 'money_account_id' => $cash->id,
            'payment_method' => 'cash', 'payment_date' => '2026-10-02', 'amount' => '100', 'exchange_rate' => '3.50', 'idempotency_key' => 'legacy-payment',
            'allocations' => [['purchase_id' => $purchases[0]->id, 'allocated_amount' => '100']]]);
        $return = app(CreatePurchaseReturnDraftAction::class)->execute($company, $owner,
            ['purchase_id' => $purchases[0]->id, 'return_date' => '2026-10-03', 'reason' => 'QA return',
                'lines' => [['purchase_line_id' => $purchases[0]->lines()->sole()->id, 'quantity' => '1']]]);
        app(PostPurchaseReturnAction::class)->execute($return, $owner);
    }
    $fixture = ['company' => $company->id, 'owner' => $owner->id, 'customer' => $customer->id, 'vendor' => $vendor->id,
        'cash' => $cash->id, 'bank' => $bank->id, 'ils' => $ils->id, 'jod' => $jod->id, 'invoice' => $invoice->id,
        'purchases' => array_map(fn ($p) => $p->id, $purchases), 'deposited' => $deposited, 'advances' => $advances,
        'urls' => ['account' => $bank->public_id, 'check' => $legacy ? null : $check->public_id, 'customer' => $customer->id, 'vendor' => $vendor->id]];
    file_put_contents($file, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($fixture);
    exit;
}

$f = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
$company = Company::findOrFail($f['company']);
$owner = User::findOrFail($f['owner']);
app(CompanyContext::class)->setCompany($company, $owner);
auth()->login($owner);
setPermissionsTeamId($company->id);
if ($mode === 'run') {
    $case = $argv[3];
    $worker = (int) $argv[4];
    $barrier = $argv[5];
    file_put_contents($barrier.'.'.$worker, 'ready');
    $deadline = microtime(true) + 15;
    while (! is_file($barrier) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($barrier)) {
        throw new RuntimeException('Race barrier timed out.');
    }
    try {
        $result = match ($case) {
            'transfer' => app(PostMoneyTransferAction::class)->execute($company, $owner, ['from_money_account_id' => $f['cash'], 'to_money_account_id' => $f['bank'],
                'transfer_date' => '2026-10-03', 'from_amount' => '10', 'to_amount' => '10', 'from_exchange_rate' => '3.5', 'to_exchange_rate' => '3.5', 'idempotency_key' => 'race-transfer']),
            'incoming', 'outgoing' => app($case === 'incoming' ? ReceiveCheckAction::class : IssueCheckAction::class)->execute($company, $owner,
                ['party_id' => $f[$case === 'incoming' ? 'customer' : 'vendor'], 'money_account_id' => $case === 'outgoing' ? $f['bank'] : null,
                    'check_number' => 'RACE-'.$case, 'bank_name' => 'QA Bank', 'date' => '2026-10-02', 'due_date' => '2026-10-03',
                    'currency_code' => 'USD', 'amount' => '10', 'exchange_rate' => '3.5', 'idempotency_key' => 'race-'.$case]),
            'clear', 'clear-return' => app(TransitionCheckAction::class)->execute(Check::findOrFail($f['deposited'][$case === 'clear' ? 0 : 1]), $owner,
                ['event_type' => $case === 'clear-return' && $worker === 1 ? 'return' : 'clear', 'event_date' => '2026-10-03', 'exchange_rate' => '3.6', 'idempotency_key' => 'race-'.$case.'-'.$worker]),
            'cross-payment' => app(PostCustomerPaymentAction::class)->execute($company, $owner, ['customer_id' => $f['customer'], 'money_account_id' => $f['ils'],
                'payment_method' => 'cash', 'payment_date' => '2026-10-03', 'amount' => '330', 'exchange_rate' => '1', 'idempotency_key' => 'race-cross',
                'allocations' => [['sales_invoice_id' => $f['invoice'], 'allocated_amount' => '100', 'payment_currency_amount' => '330']]]),
            'apply-same', 'apply-different' => app(ApplyVendorPaymentCreditAction::class)->execute(VendorPayment::findOrFail($f['advances'][$case === 'apply-same' ? 0 : 1]), $owner,
                ['application_date' => '2026-10-03', 'idempotency_key' => 'race-'.$case.'-'.$worker, 'allocations' => [
                    ['purchase_id' => $f['purchases'][$case === 'apply-same' ? 0 : 1 + $worker], 'allocated_amount' => '70', 'payment_currency_amount' => '252']]]),
            default => throw new RuntimeException('Unknown race.'),
        };
        echo json_encode(['ok' => true, 'id' => $result->id]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e::class, 'message' => $e->getMessage()]);
    }
    exit;
}
$reports = [];
foreach ([AccountingReconciliationService::class, InventoryReconciliationService::class,
    SalesReconciliationService::class, PayablesReconciliationService::class, MoneyReconciliationService::class] as $service) {
    $report = $service === InventoryReconciliationService::class ? app($service)->auditCompany($company) : app($service)->reconcile($company);
    $reports[$service] = ['healthy' => $report->isHealthy, 'violations' => $report instanceof InventoryReconciliationReport ? $report->discrepancies : $report->violations];
}
$counts = [];
foreach (['money_transfers', 'checks', 'check_events', 'customer_payments', 'vendor_payments', 'vendor_payment_allocations', 'posting_batches'] as $table) {
    $counts[$table] = DB::table($table)->count();
}
$residuals = VendorPayment::whereIn('id', $f['advances'])->get()->map(fn ($p) => ['id' => $p->id, 'unallocated' => $p->unallocated_amount])->all();
echo json_encode(['reconciliations' => $reports, 'counts' => $counts, 'residuals' => $residuals], JSON_THROW_ON_ERROR);
