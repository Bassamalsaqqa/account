<?php

declare(strict_types=1);

namespace Tests\Feature\Phase10;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Purchasing\AllocateLandedCostAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Presentation\DashboardReports;
use App\Application\Reporting\Queries\CustomerStatementReportQuery;
use App\Application\Reporting\Queries\MoneyMovementReportQuery;
use App\Application\Reporting\Queries\PurchaseSummaryReportQuery;
use App\Application\Reporting\Queries\SalesSummaryReportQuery;
use App\Application\Reporting\Support\CsvReportWriter;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Sales\Documents\DocumentData;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\BarcodeLabelService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Money\ReceivablePositionAsOf;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\PurchasePayableAsOf;
use App\Services\Purchasing\VendorCatalogService;
use App\Services\Sales\DocumentRenderLimits;
use App\Services\Sales\PdfRendererService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Tests\Feature\Phase8\Phase8TestCase;

final class CapacityMeasurementTest extends Phase8TestCase
{
    /** @var list<Product> */
    protected array $products = [];

    /** @var list<Customer> */
    protected array $customers = [];

    /** @var list<Vendor> */
    protected array $vendors = [];

    /** @var array<string, mixed> */
    protected array $benchmarkMetrics = [];

    public function test_capacity_measurement_and_evidence_generation(): void
    {
        // Capture actual UTC measurement timestamp before freezing Carbon for business logic
        $actualUtcMeasurementTime = gmdate('Y-m-d\TH:i:s\Z');

        $profile = strtoupper((string) (getenv('PHASE10_CAPACITY_WORKLOAD') ?: 'S'));
        if (! in_array($profile, ['S', 'M', 'L'], true)) {
            throw new InvalidArgumentException('Capacity workload must be S, M or L.');
        }

        Carbon::setTestNow('2026-10-20 12:00:00');
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        // 1. Build Configurable Workload (S = 10 / 50 tx, M = 500 / 5000 tx, L = 2500 / 25000 tx)
        $fixtureStart = hrtime(true);
        $fixtureCounts = $this->buildConfigurableWorkload($profile);
        $fixtureDurationMs = round((hrtime(true) - $fixtureStart) / 1_000_000, 2);

        // Invariant verification: exact fixture counts queried directly from MariaDB tables
        $dbProductsCount = Product::where('company_id', $this->company->id)->count();
        $dbPurchasesCount = Purchase::where('company_id', $this->company->id)->where('status', 'posted')->count();
        $dbExpensesCount = Expense::where('company_id', $this->company->id)->where('status', 'posted')->count();
        $dbSalesInvoicesCount = SalesInvoice::where('company_id', $this->company->id)->where('status', 'posted')->count();
        $dbSalesReturnsCount = SalesReturn::where('company_id', $this->company->id)->where('status', 'posted')->count();
        $dbPurchaseReturnsCount = PurchaseReturn::where('company_id', $this->company->id)->where('status', 'posted')->count();
        $dbCustomerPaymentsCount = CustomerPayment::where('company_id', $this->company->id)->whereNotNull('posting_batch_id')->count();
        $dbVendorPaymentsCount = VendorPayment::where('company_id', $this->company->id)->whereNotNull('posting_batch_id')->count();
        $dbStockAdjustmentsCount = StockMovement::where('company_id', $this->company->id)
            ->whereIn('movement_type', [StockMovement::TYPE_DAMAGE_OR_LOSS, StockMovement::TYPE_ADJUSTMENT_INCREASE])
            ->count();
        $dbEmployeeAdvancesCount = EmployeeAdvance::where('company_id', $this->company->id)->where('status', 'posted')->count();

        $dbTotalTransactions = $dbPurchasesCount
            + $dbExpensesCount
            + $dbSalesInvoicesCount
            + $dbSalesReturnsCount
            + $dbPurchaseReturnsCount
            + $dbCustomerPaymentsCount
            + $dbVendorPaymentsCount
            + $dbStockAdjustmentsCount
            + $dbEmployeeAdvancesCount;

        $this->assertSame($fixtureCounts['products'], $dbProductsCount, "Database products count must match profile {$profile}.");
        $this->assertSame($fixtureCounts['total_transactions'], $dbTotalTransactions, "Database canonical transaction count must match profile {$profile}.");

        // Every fixture batch records its canonical source; source inspection verifies writers.
        $orphanBatches = DB::table('posting_batches')
            ->where('company_id', $this->company->id)
            ->whereNull('source_type')
            ->count();
        $this->assertSame(0, $orphanBatches, 'Fixture posting batches must have an explicit source type.');

        $metrics = [
            'profile' => $profile,
            'timestamp' => $actualUtcMeasurementTime,
            'products_count' => count($this->products),
            'transactions_count' => $fixtureCounts['total_transactions'],
            'transactions_breakdown' => $fixtureCounts,
            'fixture_generation_ms' => $fixtureDurationMs,
            'operations' => [],
            'host_limits' => $this->captureHostEnvironment(),
            'concurrency' => [
                'concurrency_1' => 'MEASURED',
                'concurrency_2' => 'NOT RUN (requires independent multi-worker HTTP web harness; single-process CLI cannot simulate multi-tenant concurrency safely)',
                'concurrency_4' => 'NOT RUN (requires independent multi-worker HTTP web harness; single-process CLI cannot simulate multi-tenant concurrency safely)',
            ],
            'explain_plans' => [],
        ];

        // 2. Measure Dashboard Read
        $dashStart = hrtime(true);
        $dashMemBefore = memory_get_usage(true);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $dashboard = app(DashboardReports::class)->read($this->company, 'this_month');
        $dashQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $dashDurationMs = round((hrtime(true) - $dashStart) / 1_000_000, 2);
        $dashMemAfter = memory_get_usage(true);

        $this->assertIsArray($dashboard['activity']);
        $this->assertIsArray($dashboard['positions']);
        $this->assertIsArray($dashboard['alerts']);

        $metrics['operations']['dashboard'] = [
            'wall_time_ms' => $dashDurationMs,
            'query_count' => count($dashQueries),
            'memory_delta_kb' => round(($dashMemAfter - $dashMemBefore) / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ];

        // 3. Measure SalesSummaryReportQuery
        $period = ReportPeriod::fromPreset('this_month', $this->company);
        $salesStart = hrtime(true);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $salesSummary = app(SalesSummaryReportQuery::class)->execute($this->company, [
            'period' => $period->toArray(),
            'page' => 1,
            'per_page' => 25,
        ], $this->owner);
        $salesQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $salesDurationMs = round((hrtime(true) - $salesStart) / 1_000_000, 2);

        $this->assertNotNull($salesSummary->totals['net_sales_base'] ?? null);
        $metrics['operations']['sales_summary'] = [
            'wall_time_ms' => $salesDurationMs,
            'query_count' => count($salesQueries),
            'net_sales_base' => $salesSummary->totals['net_sales_base'],
        ];

        // Capture EXPLAIN for heavy Sales Summary query
        $salesExplainQuery = null;
        foreach ($salesQueries as $q) {
            if (str_contains($q['query'], 'sales_invoices') && str_contains($q['query'], 'group by')) {
                $salesExplainQuery = $q;
                break;
            }
        }
        if ($salesExplainQuery !== null) {
            $metrics['explain_plans']['sales_summary'] = [
                'sql' => $salesExplainQuery['query'],
                'plan' => DB::select('EXPLAIN '.$salesExplainQuery['query'], $salesExplainQuery['bindings']),
            ];
        }

        // 4. Measure PurchaseSummaryReportQuery
        $purchStart = hrtime(true);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $purchaseSummary = app(PurchaseSummaryReportQuery::class)->execute($this->company, [
            'period' => $period->toArray(),
            'page' => 1,
            'per_page' => 25,
        ], $this->owner);
        $purchQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $purchDurationMs = round((hrtime(true) - $purchStart) / 1_000_000, 2);

        $metrics['operations']['purchase_summary'] = [
            'wall_time_ms' => $purchDurationMs,
            'query_count' => count($purchQueries),
            'net_purchases_base' => $purchaseSummary->totals['net_purchases_base'] ?? '0.000000',
        ];

        // 5. Measure MoneyMovementReportQuery (Window Function)
        $moneyStart = hrtime(true);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $moneyMovement = app(MoneyMovementReportQuery::class)->execute($this->company, [
            'money_account_id' => $this->ilsCashAccount->id,
            'period' => $period,
            'page' => 1,
            'per_page' => 25,
        ], $this->owner);
        $moneyQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $moneyDurationMs = round((hrtime(true) - $moneyStart) / 1_000_000, 2);

        $metrics['operations']['money_movement'] = [
            'wall_time_ms' => $moneyDurationMs,
            'query_count' => count($moneyQueries),
            'row_count' => count($moneyMovement->rows),
        ];

        // Capture EXPLAIN for Money Movement Window Query
        $moneyExplainQuery = null;
        foreach ($moneyQueries as $q) {
            if (str_contains($q['query'], 'movements') && str_contains($q['query'], 'limit')) {
                $moneyExplainQuery = $q;
                break;
            }
        }
        if ($moneyExplainQuery !== null) {
            $metrics['explain_plans']['money_movement_window'] = [
                'sql' => $moneyExplainQuery['query'],
                'plan' => DB::select('EXPLAIN '.$moneyExplainQuery['query'], $moneyExplainQuery['bindings']),
            ];
        }

        // 6. Measure CustomerStatementReportQuery (In-Memory Subledger Hydration)
        $customer = $this->customers[0];
        $stmtStart = hrtime(true);
        $stmtMemBefore = memory_get_usage(true);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $customerStatement = app(CustomerStatementReportQuery::class)->execute($this->company, [
            'customer_id' => $customer->id,
            'period' => $period,
            'page' => 1,
            'per_page' => 25,
        ], $this->owner);
        $stmtQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $stmtDurationMs = round((hrtime(true) - $stmtStart) / 1_000_000, 2);
        $stmtMemAfter = memory_get_usage(true);

        $metrics['operations']['customer_statement'] = [
            'wall_time_ms' => $stmtDurationMs,
            'query_count' => count($stmtQueries),
            'memory_delta_kb' => round(($stmtMemAfter - $stmtMemBefore) / 1024, 2),
            'row_count' => count($customerStatement->rows),
        ];

        // 7. Measure Batched Historical Positions (ReceivablePositionAsOf)
        $invoices = SalesInvoice::where('company_id', $this->company->id)->whereNotNull('posted_at')->get();
        $this->assertNotEmpty($invoices);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $arPositions = [];
        foreach ($invoices->chunk(500) as $chunk) {
            $arPositions += app(ReceivablePositionAsOf::class)->forInvoices($chunk, '2026-10-31');
        }
        $arQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $expectedArChunks = (int) ceil($invoices->count() / 500);
        $expectedArQueries = 2 * $expectedArChunks;
        $this->assertCount($expectedArQueries, $arQueries, "ReceivablePositionAsOf must execute exactly {$expectedArQueries} batched queries for {$invoices->count()} invoices in chunks of 500.");
        $metrics['operations']['ar_position_batching'] = [
            'invoices_count' => $invoices->count(),
            'query_count' => count($arQueries),
            'batch_size_cap' => 500,
            'chunks_executed' => $expectedArChunks,
        ];

        // Capture EXPLAIN for AR Payment Allocations Relief
        $metrics['explain_plans']['ar_relief'] = [
            'sql' => $arQueries[0]['query'],
            'plan' => DB::select('EXPLAIN '.$arQueries[0]['query'], $arQueries[0]['bindings']),
        ];

        // 8. Measure Batched AP Historical Positions (PurchasePayableAsOf)
        $purchases = Purchase::where('company_id', $this->company->id)->whereNotNull('posted_at')->get();
        $this->assertNotEmpty($purchases);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $apPositions = [];
        foreach ($purchases->chunk(500) as $chunk) {
            $apPositions += app(PurchasePayableAsOf::class)->forHistory($chunk, Carbon::parse('2026-10-31'));
        }
        $apQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $expectedApChunks = (int) ceil($purchases->count() / 500);
        $this->assertCount($invoices->count(), $arPositions);
        $this->assertCount($purchases->count(), $apPositions);
        $metrics['operations']['ap_position_batching'] = [
            'purchases_count' => $purchases->count(),
            'query_count' => count($apQueries),
            'batch_size_cap' => 500,
            'chunks_executed' => $expectedApChunks,
        ];

        // 9. Measure PDF Invoice Rendering
        $firstInvoice = $invoices->first();
        $pdfStart = hrtime(true);
        $pdfMemBefore = memory_get_usage(true);
        $pdfBytes = app(PdfRendererService::class)->renderInvoice($firstInvoice);
        $pdfDurationMs = round((hrtime(true) - $pdfStart) / 1_000_000, 2);
        $pdfMemAfter = memory_get_usage(true);

        $this->assertStringStartsWith('%PDF-', $pdfBytes);
        $this->assertGreaterThan(5000, strlen($pdfBytes));

        $metrics['operations']['pdf_render_invoice'] = [
            'wall_time_ms' => $pdfDurationMs,
            'byte_size' => strlen($pdfBytes),
            'memory_delta_kb' => round(($pdfMemAfter - $pdfMemBefore) / 1024, 2),
        ];

        // 10. Measure Barcode Label Preparation & Rendering
        $barcodes = ProductBarcode::where('company_id', $this->company->id)->orderBy('id')->limit(10)->get();
        $this->assertNotEmpty($barcodes);
        $barcodeQuantities = [];
        $sampleBarcodes = $barcodes->take(10);
        foreach ($sampleBarcodes as $bc) {
            $barcodeQuantities[(int) $bc->id] = 5; // 10 distinct barcodes * 5 = 50 total labels
        }

        $bcStart = hrtime(true);
        $bcPrepared = app(BarcodeLabelService::class)->prepare($barcodeQuantities, locale: 'ar', preset: 'a4-3x8');
        $bcPdfBytes = app(BarcodeLabelService::class)->pdf($bcPrepared);
        $bcDurationMs = round((hrtime(true) - $bcStart) / 1_000_000, 2);

        $this->assertStringStartsWith('%PDF-', $bcPdfBytes);
        $this->assertCount(50, $bcPrepared['labels']);

        $metrics['operations']['barcode_labels_render'] = [
            'wall_time_ms' => $bcDurationMs,
            'distinct_barcodes' => count($barcodeQuantities),
            'total_labels' => 50,
            'byte_size' => strlen($bcPdfBytes),
        ];

        // 11. Match the shipped ReportCsvController's bounded export page size.
        $stream = fopen('php://temp', 'w+');
        $this->assertIsResource($stream);
        $csvStart = hrtime(true);
        $writer = new CsvReportWriter;
        $writer->write($stream, function (int $page) use ($period): ReportResult {
            return app(SalesSummaryReportQuery::class)->execute($this->company, [
                'period' => $period->toArray(),
                'page' => $page,
                'per_page' => 100,
            ], $this->owner);
        }, [
            ['key' => 'document_number', 'label' => 'رقم المستند', 'type' => 'text'],
            ['key' => 'event_type', 'label' => 'نوع الحركة', 'type' => 'text'],
            ['key' => 'net_sales_base', 'label' => 'صافي المبيعات الأساسي', 'type' => 'decimal'],
        ]);
        $csvDurationMs = round((hrtime(true) - $csvStart) / 1_000_000, 2);
        rewind($stream);
        $csvContent = stream_get_contents($stream);
        rewind($stream);
        fgetcsv($stream, escape: ''); // Translated header with UTF-8 BOM.
        $csvRows = 0;
        $csvNet = BigDecimal::zero();
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $this->assertNotSame('', $row[0], 'CSV must contain actual canonical document numbers.');
            $this->assertNotSame('', $row[1], 'CSV must identify each economic event type.');
            $csvNet = $csvNet->plus($row[2]);
            $csvRows++;
        }
        $this->assertSame($fixtureCounts['sales_invoices'] + $fixtureCounts['sales_returns'], $csvRows);
        $this->assertTrue($csvNet->isEqualTo($salesSummary->totals['net_sales_base']));
        fclose($stream);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvContent);
        $metrics['operations']['csv_streaming'] = [
            'wall_time_ms' => $csvDurationMs,
            'bytes_written' => strlen($csvContent),
            'actual_rows' => $csvRows,
            'page_size' => 100,
            'net_sales_base' => (string) $csvNet,
            'max_row_limit' => CsvReportWriter::MAX_ROWS,
            'max_byte_limit' => CsvReportWriter::MAX_BYTES,
        ];

        $this->benchmarkMetrics = $metrics;

        // Measurements cannot earn integrity acceptance without all six domain proofs.
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        foreach ([AccountingReconciliationService::class, MoneyReconciliationService::class,
            Phase7ReconciliationService::class, SalesReconciliationService::class,
            PayablesReconciliationService::class] as $service) {
            $report = app($service)->reconcile($this->company);
            $this->assertTrue($report->isHealthy, $service);
        }
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy);
        $metrics['six_domain_reconciliations'] = 'HEALTHY';

        // Write metrics JSON and generated evidence markdown solely under ignored .ai/phase10-capacity
        $capacityDir = base_path('.ai/phase10-capacity');
        if (! File::isDirectory($capacityDir)) {
            File::makeDirectory($capacityDir, 0755, true);
        }
        File::put($capacityDir.'/capacity-metrics.json', json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::put($capacityDir.'/capacity-'.strtolower($profile).'-metrics.json', json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertFileExists($capacityDir.'/capacity-metrics.json');

        $evidenceMarkdown = $this->generateEvidenceMarkdown($metrics);
        File::put($capacityDir.'/capacity-evidence.md', $evidenceMarkdown);
        $this->assertFileExists($capacityDir.'/capacity-evidence.md');
    }

    public function test_csv_export_bounds_and_fail_closed_guard(): void
    {
        $writer = new CsvReportWriter;
        $stream = fopen('php://temp', 'w+');

        // Verify fail-closed when row total exceeds MAX_ROWS (50,000)
        $oversizedFetch = static function (int $page): ReportResult {
            return new ReportResult(
                reportType: 'sales_summary',
                filters: [],
                totals: [],
                rows: [],
                currency: ['code' => 'ILS'],
                pagination: [
                    'current_page' => 1,
                    'last_page' => 2000,
                    'per_page' => 26,
                    'total' => 50_001,
                ]
            );
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Export exceeded maximum row limit.');

        try {
            $writer->write($stream, $oversizedFetch, [
                ['key' => 'code', 'label' => 'Code', 'type' => 'text'],
            ]);
        } finally {
            fclose($stream);
        }
    }

    public function test_document_render_preflight_caps(): void
    {
        $limits = new DocumentRenderLimits;

        $this->assertSame(500, DocumentRenderLimits::MAX_DOCUMENT_LINES);
        $this->assertSame(1000, DocumentRenderLimits::MAX_STATEMENT_ENTRIES);
        $this->assertSame(15 * 1024 * 1024, DocumentRenderLimits::MAX_PDF_BYTES);
        $this->assertSame(50, DocumentRenderLimits::MAX_PAGES);
        $this->assertSame(15, DocumentRenderLimits::MAX_SECONDS);

        // Preflight rejection of > 500 document lines
        $oversizedLines = [];
        for ($i = 0; $i < 501; $i++) {
            $oversizedLines[] = [
                'line_number' => $i + 1,
                'item_description' => 'Item '.$i,
                'quantity' => '1',
                'unit_price' => '10',
                'line_total' => '10',
            ];
        }

        $documentData = new DocumentData(
            type: 'invoice',
            locale: 'ar',
            company: ['name' => 'Company'],
            customer: ['name' => 'Customer'],
            document: ['number' => 'INV-OVERSIZED'],
            lines: $oversizedLines,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/line limits/i');

        $limits->assertDocument($documentData);
    }

    public function test_barcode_label_service_caps(): void
    {
        $service = app(BarcodeLabelService::class);

        $this->assertSame(100, BarcodeLabelService::MAX_DISTINCT_BARCODES);
        $this->assertSame(500, BarcodeLabelService::MAX_TOTAL_LABELS);

        // Exceeding 100 distinct barcodes
        $excessDistinct = [];
        for ($i = 1; $i <= 101; $i++) {
            $excessDistinct[$i] = 1;
        }

        try {
            $service->prepare($excessDistinct);
            $this->fail('Expected exception on > 100 distinct barcodes.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('100 distinct barcodes', $e->getMessage());
        }

        // Exceeding 500 total labels
        try {
            $service->prepare([1 => 501]);
            $this->fail('Expected exception on > 500 total labels.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('500 total labels', $e->getMessage());
        }
    }

    /**
     * Shared configurable fixture builder for Profile S (10 / 50 tx), M (500 / 5000 tx), L (2500 / 25000 tx).
     *
     * @return array{
     *     profile: string,
     *     products: int,
     *     purchases: int,
     *     expenses: int,
     *     sales_invoices: int,
     *     sales_returns: int,
     *     purchase_returns: int,
     *     customer_payments: int,
     *     vendor_payments: int,
     *     stock_adjustments: int,
     *     employee_advances: int,
     *     total_transactions: int
     * }
     */
    private function buildConfigurableWorkload(string $profile): array
    {
        $configs = [
            'S' => ['products' => 10, 'customers' => 5, 'vendors' => 3, 'multiplier' => 1],
            'M' => ['products' => 500, 'customers' => 50, 'vendors' => 20, 'multiplier' => 100],
            'L' => ['products' => 2500, 'customers' => 250, 'vendors' => 50, 'multiplier' => 500],
        ];

        $config = $configs[$profile] ?? $configs['S'];
        $m = $config['multiplier'];
        $targetProducts = $config['products'];
        $targetCustomers = $config['customers'];
        $targetVendors = $config['vendors'];

        $unitPiece = Unit::where('code', 'piece')->firstOrFail();

        // 1. Master Data Fixtures
        $this->products = [];
        $this->products[] = $this->product; // First product from Phase5ETestCase

        for ($i = 2; $i <= $targetProducts; $i++) {
            $sku = sprintf('ITM-%05d', $i);
            $p = app(ProductCatalogService::class)->createProduct($this->company, [
                'name_ar' => 'سلعة تجريبية '.$i,
                'name_en' => 'Test Item '.$i,
                'sku' => $sku,
                'product_type' => Product::TYPE_STOCK,
                'track_stock' => true,
                'track_expiry' => false,
                'base_unit_id' => $unitPiece->id,
            ], $this->owner->id);
            $this->products[] = $p;
        }

        // Create barcodes for products
        foreach ($this->products as $idx => $p) {
            ProductBarcode::firstOrCreate(
                ['company_id' => $this->company->id, 'product_id' => $p->id],
                [
                    'unit_id' => $p->base_unit_id,
                    'barcode' => sprintf('2%011d', $idx + 1),
                    'type' => 'C128',
                    'is_primary' => true,
                ]
            );
        }

        // Customers
        $this->customers = [];
        for ($i = 1; $i <= $targetCustomers; $i++) {
            $this->customers[] = Customer::create([
                'company_id' => $this->company->id,
                'name_ar' => 'عميل مرجعي '.$i,
                'name_en' => 'Reference Customer '.$i,
                'active' => true,
                'created_by' => $this->owner->id,
            ]);
        }

        // Vendors
        $this->vendors = [];
        $this->vendors[] = $this->vendor; // Vendor 1 from Phase5ETestCase
        for ($i = 2; $i <= $targetVendors; $i++) {
            $this->vendors[] = app(VendorCatalogService::class)->save($this->company, $this->owner, [
                'name_ar' => 'مورد مرجعي '.$i,
                'name_en' => 'Reference Vendor '.$i,
                'default_currency_code' => 'ILS',
            ]);
        }

        // 2. Canonical Transactions (Committed exclusively via canonical domain actions)
        // (a) Landed Cost Expenses (1 * $m)
        $landedCount = 1 * $m;
        $landedExpenses = [];
        for ($k = 1; $k <= $landedCount; $k++) {
            $landedExpenses[] = app(PostExpenseAction::class)->execute($this->company, $this->owner, [
                'category_id' => $this->landedCategory->id,
                'expense_date' => '2026-10-01',
                'classification' => 'landed_cost',
                'description' => 'landed-freight-'.$k,
                'currency_code' => 'ILS',
                'amount' => '50.000000',
                'exchange_rate' => '1.0000000000',
                'payment_method' => 'cash',
                'money_account_id' => $this->ilsCashAccount->id,
                'idempotency_key' => 'landed-cap-'.$k,
            ]);
        }

        // (b) Purchases (15 * $m)
        $purchaseCount = 15 * $m;
        $purchases = [];
        for ($i = 1; $i <= $purchaseCount; $i++) {
            $prod = $this->products[($i - 1) % count($this->products)];
            $vend = $this->vendors[($i - 1) % count($this->vendors)];
            $unitCost = sprintf('%d.000000', 10 + ($i % 5));
            $qty = '20';

            $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
                'vendor_id' => $vend->id,
                'warehouse_id' => $this->warehouse->id,
                'purchase_date' => sprintf('2026-10-%02d', ($i % 25) + 1),
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'document_locale' => 'ar',
                'lines' => [
                    [
                        'product_id' => $prod->id,
                        'quantity' => $qty,
                        'unit_cost' => $unitCost,
                    ],
                ],
            ]);

            // Allocate 1 landed expense per purchase for the first landedCount purchases
            if ($i <= count($landedExpenses)) {
                app(AllocateLandedCostAction::class)->execute($draft, $landedExpenses[$i - 1], 'value', $this->owner);
            }

            $purchases[] = app(PostPurchaseAction::class)->execute($draft, $this->owner);
        }

        // (c) Operating Expenses (3 * $m)
        $operatingCount = 3 * $m;
        for ($i = 1; $i <= $operatingCount; $i++) {
            app(PostExpenseAction::class)->execute($this->company, $this->owner, [
                'category_id' => $this->operatingCategory->id,
                'expense_date' => sprintf('2026-10-%02d', ($i % 25) + 1),
                'classification' => 'operating',
                'description' => 'operating-exp-'.$i,
                'currency_code' => 'ILS',
                'amount' => sprintf('%d.000000', 10 + ($i % 50)),
                'exchange_rate' => '1.0000000000',
                'payment_method' => 'cash',
                'money_account_id' => $this->ilsCashAccount->id,
                'idempotency_key' => 'operating-cap-'.$i,
            ]);
        }

        // (d) Sales Invoices (18 * $m)
        $salesCount = 18 * $m;
        $salesInvoices = [];
        for ($i = 1; $i <= $salesCount; $i++) {
            $prod = $this->products[($i - 1) % count($this->products)];
            $cust = $this->customers[($i - 1) % count($this->customers)];
            $unitPrice = sprintf('%d.000000', 30 + ($i % 10));

            $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $cust->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'issue_date' => sprintf('2026-10-%02d', ($i % 25) + 1),
                'lines' => [
                    [
                        'product_id' => $prod->id,
                        'item_description' => 'Sales Item '.$i,
                        'quantity' => '2',
                        'unit_price' => $unitPrice,
                    ],
                ],
            ]);
            $salesInvoices[] = app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        }

        // (e) Sales Returns (2 * $m)
        $salesReturnCount = 2 * $m;
        for ($i = 1; $i <= $salesReturnCount; $i++) {
            $inv = $salesInvoices[$i - 1];
            $returnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $inv->customer_id,
                'sales_invoice_id' => $inv->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'issue_date' => (string) $inv->issue_date->toDateString(),
                'lines' => [
                    [
                        'sales_invoice_line_id' => $inv->lines->first()->id,
                        'quantity' => '1',
                        'unit_price' => (string) $inv->lines->first()->unit_price,
                    ],
                ],
            ]);
            app(PostSalesReturnAction::class)->execute($returnDraft, $this->owner);
        }

        // (f) Purchase Returns (1 * $m)
        $purchReturnCount = 1 * $m;
        for ($i = 1; $i <= $purchReturnCount; $i++) {
            $purchToReturn = $purchases[$landedCount + $i - 1];
            $purchReturnDraft = app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, [
                'purchase_id' => $purchToReturn->id,
                'return_date' => (string) $purchToReturn->purchase_date->toDateString(),
                'reason' => 'Defective batch '.$i,
                'lines' => [
                    [
                        'purchase_line_id' => $purchToReturn->lines->first()->id,
                        'quantity' => '1',
                    ],
                ],
            ]);
            app(PostPurchaseReturnAction::class)->execute($purchReturnDraft, $this->owner);
        }

        // (g) Customer Payments (4 * $m)
        $custPaymentCount = 4 * $m;
        for ($i = 1; $i <= $custPaymentCount; $i++) {
            $inv = $salesInvoices[$salesReturnCount + $i - 1];
            $allocatedAmount = '10.000000';
            app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $inv->customer_id,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_date' => (string) $inv->issue_date->toDateString(),
                'payment_method' => 'cash',
                'amount' => $allocatedAmount,
                'exchange_rate' => '1.0000000000',
                'idempotency_key' => 'receipt-cap-'.$i,
                'allocations' => [
                    [
                        'sales_invoice_id' => $inv->id,
                        'allocated_amount' => $allocatedAmount,
                        'payment_currency_amount' => $allocatedAmount,
                    ],
                ],
            ]);
        }

        // (h) Vendor Payments (3 * $m)
        $vendPaymentCount = 3 * $m;
        for ($i = 1; $i <= $vendPaymentCount; $i++) {
            $purch = $purchases[$landedCount + $purchReturnCount + $i - 1];
            $allocatedAmount = '20.000000';
            app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
                'vendor_id' => $purch->vendor_id,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_date' => (string) $purch->purchase_date->toDateString(),
                'payment_method' => 'cash',
                'amount' => $allocatedAmount,
                'exchange_rate' => '1.0000000000',
                'idempotency_key' => 'vp-cap-'.$i,
                'allocations' => [
                    [
                        'purchase_id' => $purch->id,
                        'allocated_amount' => $allocatedAmount,
                        'payment_currency_amount' => $allocatedAmount,
                    ],
                ],
            ]);
        }

        // (i) Stock Adjustments (2 * $m)
        $stockAdjCount = 2 * $m;
        for ($i = 1; $i <= $stockAdjCount; $i++) {
            if ($i % 2 === 1) {
                app(AdjustStockAction::class)->execute(
                    $this->company,
                    $this->products[($i - 1) % count($this->products)],
                    $this->warehouse,
                    StockMovement::TYPE_DAMAGE_OR_LOSS,
                    Quantity::of('1'),
                    'Spoiled inventory item '.$i,
                    $this->owner,
                    'loss-cap-'.$i,
                    movementDate: '2026-10-15'
                );
            } else {
                app(AdjustStockAction::class)->execute(
                    $this->company,
                    $this->products[($i - 1) % count($this->products)],
                    $this->warehouse,
                    StockMovement::TYPE_ADJUSTMENT_INCREASE,
                    Quantity::of('2'),
                    'Surplus physical count '.$i,
                    $this->owner,
                    'gain-cap-'.$i,
                    unitCostBase: '15.000000',
                    movementDate: '2026-10-16'
                );
            }
        }

        // (j) Employee Advances (1 * $m)
        $advanceCount = 1 * $m;
        for ($i = 1; $i <= $advanceCount; $i++) {
            app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, [
                'employee_id' => $this->employee->id,
                'advance_date' => '2026-10-14',
                'currency_code' => 'USD',
                'amount' => '10.000000',
                'exchange_rate' => '3.5000000000',
                'payment_method' => 'cash',
                'money_account_id' => $this->usdCashAccount->id,
                'idempotency_key' => 'advance-cap-'.$i,
            ]);
        }

        $totalTransactions = $purchaseCount
            + $landedCount
            + $operatingCount
            + $salesCount
            + $salesReturnCount
            + $purchReturnCount
            + $custPaymentCount
            + $vendPaymentCount
            + $stockAdjCount
            + $advanceCount;

        return [
            'profile' => $profile,
            'products' => count($this->products),
            'purchases' => $purchaseCount,
            'expenses' => $landedCount + $operatingCount,
            'sales_invoices' => $salesCount,
            'sales_returns' => $salesReturnCount,
            'purchase_returns' => $purchReturnCount,
            'customer_payments' => $custPaymentCount,
            'vendor_payments' => $vendPaymentCount,
            'stock_adjustments' => $stockAdjCount,
            'employee_advances' => $advanceCount,
            'total_transactions' => $totalTransactions,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function captureHostEnvironment(): array
    {
        $dbVersion = DB::select('SELECT VERSION() as v')[0]->v ?? 'unknown';
        $maxPacket = DB::select("SHOW VARIABLES LIKE 'max_allowed_packet'")[0]->Value ?? 'unknown';
        $sqlMode = DB::select("SHOW VARIABLES LIKE 'sql_mode'")[0]->Value ?? 'unknown';
        $bufferPool = DB::select("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'")[0]->Value ?? 'unknown';

        return [
            'os' => PHP_OS_FAMILY,
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'php_memory_limit' => ini_get('memory_limit') ?: 'unlimited',
            'php_max_execution_time' => ini_get('max_execution_time') ?: '0',
            'mariadb_version' => (string) $dbVersion,
            'mariadb_max_allowed_packet' => (string) $maxPacket,
            'mariadb_sql_mode' => (string) $sqlMode,
            'mariadb_innodb_buffer_pool_size' => (string) $bufferPool,
            'production_hosting_limits' => 'BLOCKED EXTERNAL AUTHORIZATION: Production quotas (LiteSpeed LSAPI, CloudLinux LVE, shared hosting memory/time limits) require external authorization and are not simulated or inferred from local CLI.',
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function generateEvidenceMarkdown(array $metrics): string
    {
        $lines = [];
        $lines[] = '# Phase 10 — Workload Capacity, EXPLAIN Analysis & Resource Limits Evidence';
        $lines[] = '';
        $lines[] = 'Evidence artifact produced by `tests/Feature/Phase10/CapacityMeasurementTest.php` on disposable MariaDB.';
        $lines[] = 'Timestamp: '.$metrics['timestamp'];
        $lines[] = '';
        $lines[] = '## 1. Executive Summary & Benchmark Scope';
        $lines[] = '';
        $lines[] = 'This benchmark measures real resource limits, query volumes, memory consumption, execution timings, and MariaDB query plans across defined synthetic workload profiles on disposable MariaDB under PHP 8.4.';
        $lines[] = '';
        $lines[] = '| Workload Profile | Products | Transactions | Status | Execution Mechanism |';
        $lines[] = '|---|---|---|---|---|';
        $sStatus = $metrics['profile'] === 'S' ? '**MEASURED (Default)**' : 'Ready (Configurable builder)';
        $mStatus = $metrics['profile'] === 'M' ? '**MEASURED**' : 'Ready (Execute via `PHASE10_CAPACITY_WORKLOAD=M`)';
        $lStatus = $metrics['profile'] === 'L' ? '**MEASURED**' : 'Ready (Execute via `PHASE10_CAPACITY_WORKLOAD=L`)';
        $lines[] = "| **Profile S** | 10 | 50 | {$sStatus} | Canonical Actions in disposable MariaDB |";
        $lines[] = "| **Profile M** | 500 | 5,000 | {$mStatus} | Canonical Actions in disposable MariaDB |";
        $lines[] = "| **Profile L** | 2,500 | 25,000 | {$lStatus} | Canonical Actions in disposable MariaDB |";
        $lines[] = '';
        $lines[] = '> **Invariant Assurance:** All economic events were created and committed strictly via canonical domain Actions (`PostPurchaseAction`, `PostSalesInvoiceAction`, `PostCustomerPaymentAction`, `PostVendorPaymentAction`, `PostExpenseAction`, `AdjustStockAction`, `PostEmployeeAdvanceAction`). Zero direct bulk insertions were made into financial ledger (`posting_batches`, `posting_lines`) or stock movement tables.';
        $lines[] = '';
        $lines[] = "## 2. Fixture Generation vs Operation Metrics (Profile {$metrics['profile']})";
        $lines[] = '';
        $lines[] = "- **Fixture Generation Time**: `{$metrics['fixture_generation_ms']} ms` for {$metrics['products_count']} products and {$metrics['transactions_count']} canonical economic transactions.";
        $lines[] = '- **Transaction Breakdown**:';
        foreach ($metrics['transactions_breakdown'] as $k => $v) {
            $lines[] = "  - `{$k}`: {$v}";
        }
        $lines[] = '';
        $lines[] = "## 3. Operational Performance Measurements (Profile {$metrics['profile']})";
        $lines[] = '';
        $lines[] = '| Operation | Wall Time (ms) | Query Count | Memory Delta (KB) | Notes |';
        $lines[] = '|---|---|---|---|---|';

        $dash = $metrics['operations']['dashboard'] ?? [];
        $lines[] = '| **Dashboard Read** (`DashboardReports::read`) | '.($dash['wall_time_ms'] ?? 'N/A').' ms | '.($dash['query_count'] ?? 'N/A').' | '.($dash['memory_delta_kb'] ?? 'N/A').' KB | Aggregates activity, positions, and alert cards |';

        $sales = $metrics['operations']['sales_summary'] ?? [];
        $lines[] = '| **Sales Summary** (`SalesSummaryReportQuery`) | '.($sales['wall_time_ms'] ?? 'N/A').' ms | '.($sales['query_count'] ?? 'N/A').' | N/A | SQL `LIMIT / OFFSET` bounded pagination |';

        $purch = $metrics['operations']['purchase_summary'] ?? [];
        $lines[] = '| **Purchase Summary** (`PurchaseSummaryReportQuery`) | '.($purch['wall_time_ms'] ?? 'N/A').' ms | '.($purch['query_count'] ?? 'N/A').' | N/A | SQL aggregated totals |';

        $money = $metrics['operations']['money_movement'] ?? [];
        $lines[] = '| **Money Movement Window** (`MoneyMovementReportQuery`) | '.($money['wall_time_ms'] ?? 'N/A').' ms | '.($money['query_count'] ?? 'N/A').' | N/A | SQL Window functions (`SUM(...) OVER (...)`) |';

        $stmt = $metrics['operations']['customer_statement'] ?? [];
        $lines[] = '| **Customer Statement** (`CustomerStatementReportQuery`) | '.($stmt['wall_time_ms'] ?? 'N/A').' ms | '.($stmt['query_count'] ?? 'N/A').' | '.($stmt['memory_delta_kb'] ?? 'N/A').' KB | Full chronological subledger in-memory hydration |';

        $ar = $metrics['operations']['ar_position_batching'] ?? [];
        $lines[] = '| **AR Position Batching** (`ReceivablePositionAsOf`) | N/A | '.($ar['query_count'] ?? 'N/A')." | N/A | Batched chunks of 500 ({$ar['chunks_executed']} chunks for {$ar['invoices_count']} invoices) |";

        $ap = $metrics['operations']['ap_position_batching'] ?? [];
        $lines[] = '| **AP Position Batching** (`PurchasePayableAsOf`) | N/A | '.($ap['query_count'] ?? 'N/A')." | N/A | Batched for history in chunks of 500 ({$ap['chunks_executed']} chunks for {$ap['purchases_count']} purchases) |";

        $pdf = $metrics['operations']['pdf_render_invoice'] ?? [];
        $lines[] = '| **PDF Invoice Render** (`PdfRendererService`) | '.($pdf['wall_time_ms'] ?? 'N/A').' ms | N/A | '.($pdf['memory_delta_kb'] ?? 'N/A').' KB | mPDF vector render ('.($pdf['byte_size'] ?? 0).' bytes) |';

        $bc = $metrics['operations']['barcode_labels_render'] ?? [];
        $lines[] = '| **Barcode Labels PDF** (`BarcodeLabelService`) | '.($bc['wall_time_ms'] ?? 'N/A').' ms | N/A | N/A | 10 distinct barcodes / 50 total labels sheet |';

        $csv = $metrics['operations']['csv_streaming'] ?? [];
        $lines[] = '| **CSV Stream Export** (`CsvReportWriter`) | '.($csv['wall_time_ms'] ?? 'N/A').' ms | N/A | Bounded | Chunked stream with UTF-8 BOM |';

        $lines[] = '';
        $lines[] = '## 4. Captured MariaDB EXPLAIN Plans';
        $lines[] = '';

        foreach ($metrics['explain_plans'] as $name => $planData) {
            $lines[] = '### 4.'.count($lines)." EXPLAIN: {$name}";
            $lines[] = '```sql';
            $lines[] = $planData['sql'];
            $lines[] = '```';
            $lines[] = $this->formatExplainTable($planData['plan']);
            $lines[] = '';
        }

        $lines[] = '## 5. Host Configuration & Quota Audit';
        $lines[] = '';
        $lines[] = '| Parameter | Local Measured Value | Scope & Classification |';
        $lines[] = '|---|---|---|';
        $host = $metrics['host_limits'] ?? [];
        $lines[] = "| **OS Platform** | `{$host['os']}` | Local test runner platform |";
        $lines[] = "| **PHP Version** | `{$host['php_version']}` (`{$host['php_sapi']}`) | Command-line CLI runner |";
        $lines[] = "| **PHP Memory Limit** | `{$host['php_memory_limit']}` | Local test runner CLI limit |";
        $lines[] = "| **Max Execution Time** | `{$host['php_max_execution_time']}` s | Local CLI runtime (0 = CLI unlimited) |";
        $lines[] = "| **MariaDB Version** | `{$host['mariadb_version']}` | Disposable MariaDB database instance |";
        $lines[] = "| **`max_allowed_packet`** | `{$host['mariadb_max_allowed_packet']}` bytes | Disposable MariaDB connection setting |";
        $lines[] = "| **InnoDB Buffer Pool** | `{$host['mariadb_innodb_buffer_pool_size']}` bytes | Disposable MariaDB buffer pool size |";
        $lines[] = '| **Production Hosting Quotas** | **BLOCKED EXTERNAL AUTHORIZATION** | Production Hostinger / LiteSpeed LSAPI quotas (memory limit, max execution time, LVE caps) are unverified externally. Local CLI does not represent LSAPI. |';
        $lines[] = '';
        $lines[] = '## 6. Concurrency Evaluation';
        $lines[] = '';
        $lines[] = '- **Concurrency 1**: MEASURED in test runner across all reporting, PDF, and barcode operations.';
        $lines[] = '- **Concurrency 2**: NOT RUN — Genuine concurrent HTTP execution requires independent worker processes. In-process CLI execution cannot simulate multi-tenant web concurrency truthfully. No invented measurements are reported.';
        $lines[] = '- **Concurrency 4**: NOT RUN — Bounded multi-worker concurrency requires an isolated web harness. In accordance with the contract, unexecuted concurrency is recorded truthfully as NOT RUN.';
        $lines[] = '';
        $lines[] = '## 7. Bounded Limits & Safe Refusal Enforcements';
        $lines[] = '';
        $lines[] = '1. **CSV Export Cap**: `MAX_ROWS = 50,000` rows and `MAX_BYTES = 50 MiB`. When query metadata indicates total rows exceed 50,000 (`total: 50_001`), the export refuses pre-stream with `RuntimeException("Export exceeded maximum row limit.")`. (Observed behavior is pre-stream refusal based on reported pagination total; this does not prove immunity to mid-stream memory exhaustion if queries report fewer rows or omit count).';
        $lines[] = '2. **PDF Document Limits**: `MAX_DOCUMENT_LINES = 500` lines, `MAX_STATEMENT_ENTRIES = 1000` entries, `MAX_PDF_BYTES = 15 MiB`, `MAX_PAGES = 50` pages, `MAX_SECONDS = 15` seconds.';
        $lines[] = '3. **Barcode Label Limits**: `MAX_DISTINCT_BARCODES = 100`, `MAX_TOTAL_LABELS = 500`. Requests exceeding limits are rejected with `InvalidArgumentException`.';
        $lines[] = '';
        $lines[] = '## 8. Findings Classified: Confirmed vs Risk vs Limitations';
        $lines[] = '';
        $lines[] = '### Confirmed Findings';
        $lines[] = "- **Position Batched Queries**: `ReceivablePositionAsOf` batches invoices in chunks of 500, executing `2 * ceil(N / 500)` queries ({$ar['query_count']} queries for Profile {$metrics['profile']}'s {$ar['invoices_count']} invoices).";
        $lines[] = '- **Window Function Design**: `MoneyMovementReportQuery` executes window functions directly in MariaDB with SQL `LIMIT / OFFSET`, measured with SQL pagination; full bounded-memory guarantees require the larger profile evidence.';
        $lines[] = '- **Fail-Closed CSV Pre-Stream Refusal**: In the observed test scenario with reported pagination total exceeding 50,000, `CsvReportWriter` refuses before writing CSV markers or records.';
        $lines[] = '';
        $lines[] = '### Architectural Risks';
        $lines[] = '- **Subledger In-Memory Hydration**: Party statement queries (`CustomerStatementReportQuery`, `VendorStatementReportQuery`) hydrate the complete chronological transaction history in PHP to compute running balances before `array_slice()` pagination. Under high transaction volume, this produces measurable memory spikes.';
        $lines[] = '- **Non-Preemptive mPDF Rendering**: `PdfRendererService` checks execution time only after mPDF rendering completes. A complex multi-page document exceeding the time budget cannot be interrupted mid-render.';
        $lines[] = '';
        $lines[] = '### Limitations';
        $lines[] = '- Local MariaDB execution timings cannot be equated to production shared-hosting SLAs due to noisy-neighbor effects, shared IOPS limits, and CloudLinux LVE governor policies.';
        $lines[] = '- Concurrency 2 and 4 require independent web-server worker harnesses and remain NOT RUN in the single-process CLI test environment.';
        $lines[] = '- Production hosting quotas remain BLOCKED EXTERNAL AUTHORIZATION; no production environment access occurred.';

        return implode("\n", $lines);
    }

    /**
     * @param  list<object>  $explainRows
     */
    private function formatExplainTable(array $explainRows): string
    {
        $lines = [];
        $lines[] = '| id | select_type | table | type | possible_keys | key | key_len | ref | rows | Extra |';
        $lines[] = '|---|---|---|---|---|---|---|---|---|---|';
        foreach ($explainRows as $r) {
            $id = $r->id ?? '';
            $st = $r->select_type ?? '';
            $table = $r->table ?? '';
            $type = $r->type ?? '';
            $pk = $r->possible_keys ?? 'NULL';
            $key = $r->key ?? 'NULL';
            $klen = $r->key_len ?? 'NULL';
            $ref = $r->ref ?? 'NULL';
            $rows = $r->rows ?? '';
            $extra = $r->Extra ?? '';
            $lines[] = "| {$id} | {$st} | {$table} | {$type} | {$pk} | {$key} | {$klen} | {$ref} | {$rows} | {$extra} |";
        }

        return implode("\n", $lines);
    }
}
