<?php

declare(strict_types=1);

namespace Tests\Feature\Phase10;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\InventoryCostState;
use App\Models\InventoryLotBalance;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

/** Independent connections require committed fixtures, so intentionally no RefreshDatabase. */
class ConcurrentStockRaceTest extends TestCase
{
    private Company $company;

    private Company $controlCompany;

    private User $owner;

    private Vendor $vendor;

    private Customer $customer;

    private Product $product;

    private ProductUnit $unit;

    private Warehouse $warehouse;

    private string $controlFingerprint;

    protected function setUp(): void
    {
        parent::setUp();
        DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create();
        $creator = app(CreateCompanyAction::class);
        $this->controlCompany = $creator->execute($this->owner, ['name_ar' => 'Stock race control', 'base_currency_code' => 'ILS']);
        $this->activate($this->controlCompany);
        $controlProduct = $this->product('CONTROL');
        $controlUnit = ProductUnit::where('company_id', $this->controlCompany->id)->where('product_id', $controlProduct->id)->firstOrFail();
        $controlVendor = app(VendorCatalogService::class)->save($this->controlCompany, $this->owner,
            ['name_ar' => 'Control vendor', 'default_currency_code' => 'ILS']);
        $controlWarehouse = Warehouse::where('company_id', $this->controlCompany->id)->firstOrFail();
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->controlCompany, $this->owner, [
            'vendor_id' => $controlVendor->id, 'warehouse_id' => $controlWarehouse->id, 'purchase_date' => '2026-10-01',
            'currency_code' => 'ILS', 'exchange_rate' => '1', 'lines' => [['product_id' => $controlProduct->id,
                'quantity' => '2', 'unit_cost' => '7', 'lots' => [['product_unit_id' => $controlUnit->id, 'quantity' => '2', 'lot_number' => 'CONTROL', 'expiry_date' => '2027-06-01']]]],
        ]);
        app(PostPurchaseAction::class)->execute($draft, $this->owner);
        $this->controlFingerprint = $this->fingerprint($this->controlCompany);

        app(CompanyContext::class)->clear();
        $this->company = $creator->execute($this->owner, ['name_ar' => 'Stock race trading', 'base_currency_code' => 'ILS']);
        $this->activate($this->company);
        $this->product = $this->product('RACE');
        $this->unit = ProductUnit::where('company_id', $this->company->id)->where('product_id', $this->product->id)->firstOrFail();
        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();
        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner,
            ['name_ar' => 'Stock race vendor', 'default_currency_code' => 'ILS']);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'Stock race customer',
            'active' => true, 'created_by' => $this->owner->id]);
        app(PostPurchaseAction::class)->execute($this->purchase('6', [
            ['quantity' => '3', 'lot_number' => 'EARLY', 'expiry_date' => '2027-01-01'],
            ['quantity' => '3', 'lot_number' => 'LATE', 'expiry_date' => '2027-02-01'],
        ]), $this->owner);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_competing_sales_cannot_oversell_and_loser_rolls_back_sequence_and_all_business_legs(): void
    {
        // Ten units physically exist, but only six are eligible on the sale business date.
        app(PostPurchaseAction::class)->execute($this->purchase('4', [
            ['quantity' => '4', 'lot_number' => 'EXPIRED', 'expiry_date' => '2026-09-30'],
        ]), $this->owner);
        $this->assertBalances('10.000000', '100.000000');
        $first = $this->invoice('4');
        $second = $this->invoice('4');
        $before = [$first->id => $this->draftFingerprint($first), $second->id => $this->draftFingerprint($second)];
        $next = $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE);
        $results = $this->race(['operation' => 'sale', 'document_id' => $first->id], ['operation' => 'sale', 'document_id' => $second->id]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['insufficient_stock', 'ok'], $statuses);
        $winner = $results[0]['status'] === 'ok' ? $first->fresh() : $second->fresh();
        $loser = $results[0]['status'] === 'ok' ? $second->fresh() : $first->fresh();
        $this->assertSame(SalesInvoice::STATUS_POSTED, $winner->status);
        $this->assertSame(SalesInvoice::STATUS_DRAFT, $loser->status);
        $this->assertNull($loser->invoice_number);
        $this->assertNull($loser->posting_batch_id);
        $this->assertNull($loser->posted_at);
        $this->assertSame($before[$loser->id], $this->draftFingerprint($loser));
        foreach (['stock_movements', 'posting_batches'] as $table) {
            $this->assertSame(0, DB::table($table)->where('company_id', $this->company->id)
                ->where('source_type', 'sales_invoice')->where('source_id', $loser->id)->count());
        }
        $this->assertSame(0, DB::table('sales_invoice_lot_allocations')->where('sales_invoice_id', $loser->id)->count());
        $this->assertSame($next + 1, $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE));
        $this->assertSame(1, DB::table('posting_batches')->where('company_id', $this->company->id)->where('source_type', 'sales_invoice')->count());
        $this->assertSame('60.000000', $winner->grand_total_currency);
        $this->assertSame('40.000000', $winner->cogs_total_base);
        $this->assertSaleAndLots($winner, '4', ['EARLY' => '3.000000', 'LATE' => '1.000000']);
        $this->assertBalances('6.000000', '60.000000');
        $this->assertSame('4.000000', InventoryLotBalance::where('inventory_lot_balances.company_id', $this->company->id)
            ->join('inventory_lots', 'inventory_lot_balances.lot_id', '=', 'inventory_lots.id')
            ->where('lot_number', 'EXPIRED')->value('inventory_lot_balances.quantity_base'));
        $this->assertHealthyAndControlUnchanged();
    }

    public function test_simultaneous_same_receipt_key_with_conflicting_economic_payload_has_one_complete_winner(): void
    {
        $invoice = app(PostSalesInvoiceAction::class)->execute($this->invoice('4'), $this->owner);
        $account = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner,
            ['name_ar' => 'Race cash', 'account_type' => MoneyAccount::TYPE_CASH, 'currency_code' => 'ILS']);
        $key = 'phase10-receipt-economic-race';
        $intent = ['operation' => 'receipt', 'document_id' => $invoice->id, 'customer_id' => $this->customer->id,
            'money_account_id' => $account->id, 'key' => $key];
        $beforeBatches = DB::table('posting_batches')->where('company_id', $this->company->id)->count();
        $beforeLines = DB::table('posting_lines')->where('company_id', $this->company->id)->count();
        $next = $this->sequenceNext(DocumentSequence::TYPE_CUSTOMER_PAYMENT);
        $stockBefore = $this->stockFingerprint();
        $results = $this->race($intent + ['amount' => '20'], $intent + ['amount' => '35']);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['idempotency_conflict', 'ok'], $statuses);
        $winnerIndex = $results[0]['status'] === 'ok' ? 0 : 1;
        $amount = $winnerIndex === 0 ? '20.000000' : '35.000000';
        $payment = CustomerPayment::where('company_id', $this->company->id)->where('idempotency_key', $key)->sole();
        $this->assertSame($results[$winnerIndex]['id'], $payment->id);
        $this->assertSame($results[$winnerIndex]['posting_batch_id'], $payment->posting_batch_id);
        $this->assertSame($results[$winnerIndex]['number'], $payment->payment_number);
        $this->assertSame($this->customer->id, $payment->customer_id);
        $this->assertSame($account->id, $payment->money_account_id);
        $this->assertSame($amount, $payment->amount);
        $this->assertSame($amount, $payment->amount_base);
        $this->assertSame('ILS', $payment->currency_code);
        $this->assertNotEmpty($payment->request_hash);
        $this->assertSame(1, DB::table('customer_payments')->where('company_id', $this->company->id)->count());
        $this->assertSame($next + 1, $this->sequenceNext(DocumentSequence::TYPE_CUSTOMER_PAYMENT));
        $this->assertSame($beforeBatches + 1, DB::table('posting_batches')->where('company_id', $this->company->id)->count());
        $this->assertSame($beforeLines + 2, DB::table('posting_lines')->where('company_id', $this->company->id)->count());
        $batch = $payment->postingBatch;
        $this->assertNotNull($batch);
        $this->assertSame('customer_payment', $batch->source_type);
        $this->assertSame($payment->id, $batch->source_id);
        $this->assertSame('2026-10-03', $batch->posting_date->format('Y-m-d'));
        $lines = $batch->lines()->orderBy('line_number')->get();
        $this->assertCount(2, $lines);
        $this->assertSame($account->ledger_account_id, $lines[0]->ledger_account_id);
        $this->assertSame($amount, $lines[0]->debit_base);
        $this->assertSame('0.000000', $lines[0]->credit_base);
        $receivableId = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_receivable')->firstOrFail()->id;
        $this->assertSame($receivableId, $lines[1]->ledger_account_id);
        $this->assertSame('0.000000', $lines[1]->debit_base);
        $this->assertSame($amount, $lines[1]->credit_base);
        $allocations = $payment->allocations()->get();
        $this->assertCount(1, $allocations);
        $allocation = $allocations->sole();
        $this->assertSame($invoice->id, $allocation->sales_invoice_id);
        foreach (['allocated_amount', 'payment_currency_amount', 'base_amount_applied_to_receivable', 'settlement_base_value'] as $field) {
            $this->assertSame($amount, $allocation->getAttribute($field));
        }
        $this->assertSame('0.000000', $allocation->realized_fx_gain_loss_base);
        $this->assertSame((string) BigDecimal::of('60')->minus($amount)->toScale(6),
            (string) $invoice->fresh()->calculateOutstanding()->toScale(6));
        $this->assertSame($stockBefore, $this->stockFingerprint());
        // An exact historical retry still returns the same canonical result after the conflicting attempt.
        $retry = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'money_account_id' => $account->id, 'payment_date' => '2026-10-03',
            'payment_method' => 'cash', 'amount' => $amount, 'exchange_rate' => '1', 'idempotency_key' => $key,
            'allocations' => [['sales_invoice_id' => $invoice->id, 'allocated_amount' => $amount]],
        ]);
        $this->assertSame($payment->id, $retry->id);
        $this->assertSame($beforeBatches + 1, DB::table('posting_batches')->where('company_id', $this->company->id)->count());
        $this->assertSame($beforeLines + 2, DB::table('posting_lines')->where('company_id', $this->company->id)->count());
        $this->assertHealthyAndControlUnchanged();
    }

    public function test_simultaneous_same_invoice_posting_converges_to_one_atomic_canonical_event(): void
    {
        $invoice = $this->invoice('4');
        $next = $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE);
        $intent = ['operation' => 'sale', 'document_id' => $invoice->id];
        [$first, $second] = $this->race($intent, $intent);
        $this->assertSame('ok', $first['status']);
        $this->assertSame($first, $second);
        $this->assertSame($invoice->id, $first['id']);
        $this->assertSame($next + 1, $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE));
        $this->assertSame(1, DB::table('posting_batches')->where('company_id', $this->company->id)
            ->where('source_type', 'sales_invoice')->where('source_id', $invoice->id)->count());
        $this->assertSaleAndLots($invoice->fresh(), '4', ['EARLY' => '3.000000', 'LATE' => '1.000000']);
        $this->assertBalances('2.000000', '20.000000');
        $this->assertHealthyAndControlUnchanged();
    }

    public function test_sale_and_purchase_overlap_preserve_exact_stock_cost_and_fefo_with_adequate_starting_stock(): void
    {
        $invoice = $this->invoice('5');
        $purchase = $this->purchase('4', [['quantity' => '4', 'lot_number' => 'NEW', 'expiry_date' => '2027-03-01']]);
        $salesNext = $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE);
        $purchaseNext = $this->sequenceNext(DocumentSequence::TYPE_PURCHASE);
        [$sale, $receipt] = $this->race(['operation' => 'sale', 'document_id' => $invoice->id],
            ['operation' => 'purchase', 'document_id' => $purchase->id]);
        $this->assertSame('ok', $sale['status']);
        $this->assertSame('ok', $receipt['status']);
        $this->assertSame(Purchase::STATUS_POSTED, $purchase->fresh()->status);
        $this->assertSame($salesNext + 1, $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE));
        $this->assertSame($purchaseNext + 1, $this->sequenceNext(DocumentSequence::TYPE_PURCHASE));
        $this->assertSame(3, DB::table('posting_batches')->where('company_id', $this->company->id)->count());
        $this->assertSame('75.000000', $invoice->fresh()->grand_total_currency);
        $this->assertSame('50.000000', $invoice->fresh()->cogs_total_base);
        $this->assertSaleAndLots($invoice->fresh(), '5', ['EARLY' => '3.000000', 'LATE' => '2.000000']);
        $this->assertBalances('5.000000', '50.000000');
        $this->assertSame('4.000000', InventoryLotBalance::where('inventory_lot_balances.company_id', $this->company->id)
            ->join('inventory_lots', 'inventory_lot_balances.lot_id', '=', 'inventory_lots.id')
            ->where('lot_number', 'NEW')->value('inventory_lot_balances.quantity_base'));
        $this->assertHealthyAndControlUnchanged();
    }

    private function activate(Company $company): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($company, $this->owner);
        setPermissionsTeamId($company->id);
    }

    private function product(string $sku): Product
    {
        $company = app(CompanyContext::class)->company();

        return app(ProductCatalogService::class)->createProduct($company, ['name_ar' => 'Expiry stock '.$sku,
            'sku' => $sku, 'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => true,
            'base_unit_id' => Unit::where('company_id', $company->id)->where('code', 'piece')->firstOrFail()->id], $this->owner->id);
    }

    /** @param list<array{quantity:string,lot_number:string,expiry_date:string}> $lots */
    private function purchase(string $quantity, array $lots): Purchase
    {
        $lots = array_map(fn (array $lot) => $lot + ['product_unit_id' => $this->unit->id], $lots);

        return app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id, 'warehouse_id' => $this->warehouse->id, 'purchase_date' => '2026-10-01',
            'currency_code' => 'ILS', 'exchange_rate' => '1', 'lines' => [['product_id' => $this->product->id,
                'product_unit_id' => $this->unit->id, 'quantity' => $quantity, 'unit_cost' => '10', 'lots' => $lots]],
        ]);
    }

    private function invoice(string $quantity): SalesInvoice
    {
        return app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'issue_date' => '2026-10-02',
            'currency_code' => 'ILS', 'exchange_rate' => '1', 'lines' => [['product_id' => $this->product->id,
                'product_unit_id' => $this->unit->id, 'item_description' => 'Expiry race stock', 'quantity' => $quantity, 'unit_price' => '15']],
        ]);
    }

    private function sequenceNext(string $type): int
    {
        return (int) (DocumentSequence::where('company_id', $this->company->id)->where('document_type', $type)
            ->where('year', 2026)->value('next_number') ?? 1);
    }

    /** @param array<string,string> $expected */
    private function assertSaleAndLots(SalesInvoice $invoice, string $quantity, array $expected): void
    {
        $allocations = DB::table('sales_invoice_lot_allocations')->where('company_id', $this->company->id)
            ->where('sales_invoice_id', $invoice->id)->orderBy('id')->get();
        $this->assertCount(count($expected), $allocations);
        $actual = [];
        foreach ($allocations as $allocation) {
            $actual[$allocation->lot_number] = $allocation->quantity_allocated_base;
            $movement = DB::table('stock_movements')->where('id', $allocation->stock_movement_id)->first();
            $this->assertNotNull($movement);
            $this->assertSame($invoice->id, $movement->source_id);
            $this->assertSame($allocation->inventory_lot_id, $movement->lot_id);
            $this->assertSame((string) BigDecimal::of($allocation->quantity_allocated_base)->negated()->toScale(6), $movement->quantity_delta_base);
        }
        $this->assertSame($expected, $actual);
        $sum = BigDecimal::zero();
        $movements = DB::table('stock_movements')->where('company_id', $this->company->id)
            ->where('source_type', 'sales_invoice')->where('source_id', $invoice->id)->get();
        $this->assertCount(count($expected), $movements);
        foreach ($movements as $movement) {
            $sum = $sum->plus($movement->quantity_delta_base);
        }
        $this->assertTrue($sum->isEqualTo(BigDecimal::of($quantity)->negated()));
    }

    private function assertBalances(string $quantity, string $value): void
    {
        $this->assertSame($quantity, InventoryBalance::where('company_id', $this->company->id)->where('product_id', $this->product->id)->value('quantity_base'));
        $cost = InventoryCostState::where('company_id', $this->company->id)->where('product_id', $this->product->id)->sole();
        $this->assertSame($quantity, $cost->quantity_base);
        $this->assertSame($value, $cost->inventory_value_base);
        $this->assertSame('10.000000', $cost->average_cost_base);
        foreach (InventoryLotBalance::where('company_id', $this->company->id)->get() as $balance) {
            $this->assertFalse(BigDecimal::of($balance->quantity_base)->isNegative());
        }
    }

    private function assertHealthyAndControlUnchanged(): void
    {
        foreach (['accounting:reconcile', 'inventory:reconcile', 'sales:reconcile', 'money:reconcile', 'phase7:reconcile'] as $command) {
            $code = Artisan::call($command, ['companyPublicId' => $this->company->public_id]);
            $this->assertSame(0, $code, $command.': '.Artisan::output());
        }
        $this->activate($this->company);
        $report = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($report->isHealthy, implode('; ', $report->violations));
        $this->assertSame($this->controlFingerprint, $this->fingerprint($this->controlCompany));
        $this->assertSame(0, DB::transactionLevel());
    }

    private function draftFingerprint(SalesInvoice $invoice): string
    {
        return hash('sha256', json_encode([
            'header' => DB::table('sales_invoices')->where('id', $invoice->id)->first(),
            'lines' => DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->id)->orderBy('id')->get()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function fingerprint(Company $company): string
    {
        $rows = [];
        foreach (['purchases', 'purchase_lines', 'purchase_line_lots', 'posting_batches', 'posting_lines', 'stock_movements',
            'inventory_operations', 'inventory_lots', 'inventory_balances', 'inventory_lot_balances', 'inventory_cost_states',
            'sales_invoices', 'sales_invoice_lines', 'sales_invoice_lot_allocations', 'document_sequences', 'audit_events'] as $table) {
            $rows[$table] = DB::table($table)->where('company_id', $company->id)->orderBy('id')->get()->all();
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function stockFingerprint(): string
    {
        $rows = [];
        foreach (['stock_movements', 'inventory_operations', 'inventory_lots', 'inventory_balances',
            'inventory_lot_balances', 'inventory_cost_states', 'sales_invoice_lot_allocations'] as $table) {
            $rows[$table] = DB::table($table)->where('company_id', $this->company->id)->orderBy('id')->get()->all();
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string,mixed>  $first
     * @param  array<string,mixed>  $second
     * @return array{array<string,mixed>,array<string,mixed>}
     */
    private function race(array $first, array $second): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase10-stock-race-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create owned race directory.');
        }
        $release = $directory.DIRECTORY_SEPARATOR.'release';
        $processes = [];
        $files = [];
        $readyFiles = [];
        $attemptFiles = [];
        $environment = ['APP_KEY' => (string) config('app.key'), 'DB_URL' => '', 'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) config('database.connections.mysql.'.$key);
        }
        foreach (['PHASE8_ALLOW_DISPOSABLE_DB', 'PHASE8_TEST_DB_HOST', 'PHASE8_TEST_DB_PORT', 'PHASE8_TEST_DB_USERNAME',
            'PHASE8_TEST_DB_EMPTY_PASSWORD', 'PHASE8_DISPOSABLE_DB_PROOF', 'PHASE8_DISPOSABLE_DB_NONCE', 'APP_CONFIG_CACHE'] as $key) {
            $environment[$key] = getenv($key);
        }
        // Credentials stay in process environment, never payload files or reported results.
        $environment['PHASE8_TEST_DB_PASSWORD'] = getenv('PHASE8_TEST_DB_PASSWORD');
        $locked = false;
        try {
            DB::beginTransaction();
            $locked = true;
            DB::table('companies')->where('id', $this->company->id)->lockForUpdate()->first();
            foreach ([$first, $second] as $index => $intent) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR.'payload-'.$index.'.json';
                $ready = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $attempting = $directory.DIRECTORY_SEPARATOR.'attempting-'.$index;
                $files = array_merge($files, [$payloadPath, $ready, $attempting]);
                $readyFiles[] = $ready;
                $attemptFiles[] = $attempting;
                file_put_contents($payloadPath, json_encode($intent + ['company_id' => $this->company->id,
                    'actor_id' => $this->owner->id, 'ready' => $ready, 'attempting' => $attempting, 'release' => $release], JSON_THROW_ON_ERROR));
                chmod($payloadPath, 0600);
                $process = new Process([PHP_BINARY, base_path('tests/Support/Phase10/stock-race-worker.php'), $payloadPath], base_path(), $environment);
                $process->setTimeout(45)->start();
                $processes[] = $process;
            }
            $this->waitForFiles($readyFiles, $processes);
            file_put_contents($release, 'release');
            $this->waitForFiles($attemptFiles, $processes);
            usleep(200000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Both independent posting attempts must overlap behind the company lock.');
                $this->assertSame('', $process->getOutput());
            }
            DB::commit();
            $locked = false;
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return [$results[0], $results[1]];
        } finally {
            if ($locked) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (array_merge($files, [$release]) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /** @param list<string> $files
     * @param  list<Process>  $processes
     */
    private function waitForFiles(array $files, array $processes): void
    {
        $deadline = microtime(true) + 20;
        do {
            if (count(array_filter($files, 'is_file')) === count($files)) {
                return;
            }
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail('Stock race child exited before the barrier: '.$process->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Independent stock race barrier timed out.');
    }
}
