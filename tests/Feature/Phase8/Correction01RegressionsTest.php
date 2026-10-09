<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Presentation\DashboardReports;
use App\Application\Reporting\Presentation\ReportPresenter;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Application\Reporting\Queries\InventoryValuationReportQuery;
use App\Application\Reporting\Queries\MoneyBalanceReportQuery;
use App\Application\Reporting\Queries\SalesByCategoryReportQuery;
use App\Application\Reporting\Queries\SalesByProductReportQuery;
use App\Application\Reporting\Queries\SalesGrossProfitReportQuery;
use App\Application\Reporting\Queries\SalesSummaryReportQuery;
use App\Application\Reporting\Support\TradeEventActivity;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Livewire\Pages\Reporting\ReportView;
use App\Models\Check;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\ProductCatalogService;
use Carbon\Carbon;
use Livewire\Livewire;

final class Correction01RegressionsTest extends TradeTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Finding A: Sales inverse lines must respect category_id filter */
    public function test_finding_a_sales_inverse_category_correctness_and_period_scoping(): void
    {
        $this->activateUser($this->owner);

        $unitId = (int) $this->unit->unit_id;

        $cat1 = ProductCategory::create([
            'company_id' => $this->company->id,
            'name_ar' => 'فئة أولى',
            'name_en' => 'Category One',
            'code' => 'CAT-01',
        ]);
        $cat2 = ProductCategory::create([
            'company_id' => $this->company->id,
            'name_ar' => 'فئة ثانية',
            'name_en' => 'Category Two',
            'code' => 'CAT-02',
        ]);

        $prod1 = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج فئة 1',
            'name_en' => 'Product Cat 1',
            'sku' => 'P-CAT-1',
            'base_unit_id' => $unitId,
            'category_id' => $cat1->id,
            'default_price' => '100.000000',
            'track_stock' => false,
        ], (int) $this->owner->id);

        $prod2 = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج فئة 2 مخزني',
            'name_en' => 'Product Cat 2 Stock',
            'sku' => 'P-CAT-2',
            'base_unit_id' => $unitId,
            'category_id' => $cat2->id,
            'default_price' => '200.000000',
            'track_stock' => true,
        ], (int) $this->owner->id);

        // Put opening stock for prod2: 10 units @ 50 ILS (total cost 500 ILS)
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $prod2,
            warehouse: $this->warehouse,
            quantity: Quantity::of('10.000000'),
            unitCostBase: '50.000000',
            user: $this->owner,
            idempotencyKey: 'cat2-stock-opening',
            movementDate: '2026-10-01',
        );

        $taxAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'tax_output')->first()
            ?? $this->ilsCashAccount->ledgerAccount;
        $taxRate = TaxRate::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'VAT16'],
            [
                'name_ar' => 'ضريبة القيمة المضافة',
                'name_en' => 'VAT 16%',
                'rate' => '16.0000',
                'calculation' => 'exclusive',
                'active' => true,
                'sales_tax_account_id' => $taxAccount->id,
            ]
        );

        // Invoice 1 in Cat 1 posted on 2026-10-02 (kept active)
        $inv1 = $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-02',
            'lines' => [
                [
                    'product_id' => $prod1->id,
                    'item_description' => 'بند فئة 1',
                    'quantity' => '3.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);

        // Invoice 2 in Cat 2 posted on 2026-10-05 with stock, discount, tax, and COGS
        // 2 units @ 200 = 400 gross, 20 discount = 380 net, 16% tax on 380 = 60.80, COGS = 2 * 50 = 100, profit = 280
        $inv2 = $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-05',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                [
                    'product_id' => $prod2->id,
                    'item_description' => 'بند فئة 2 مخزني',
                    'quantity' => '2.000000',
                    'unit_price' => '200.000000',
                    'discount_type' => 'fixed',
                    'discount_value' => '20.000000',
                    'tax_rate_id' => $taxRate->id,
                ],
            ],
        ]);

        Carbon::setTestNow('2026-10-15 10:00:00');
        app(VoidSalesInvoiceAction::class)->execute($inv2, $this->owner, 'Reversal test', '2026-10-15');

        // Test 1: Query for Cat 1 during the reversal period (2026-10-10 to 2026-10-20)
        // Cat 2 reversal occurred on 2026-10-15. It must NOT leak into Cat 1 results!
        $periodReversalOnly = ReportPeriod::custom('2026-10-10', '2026-10-20', $this->company);
        $filtersCat1 = new ReportFilters(period: $periodReversalOnly, categoryId: $cat1->id);
        $cat1Result = app(SalesByCategoryReportQuery::class)->execute($this->company, $filtersCat1, $this->owner);
        $this->assertEmpty($cat1Result->rows, 'Void reversal of Cat 2 must NOT appear when filtered by Cat 1.');
        $this->assertSame('0.000000', $cat1Result->totals['sales_revenue_base']);
        $this->assertSame('0.000000', $cat1Result->totals['quantity_base']);

        // Test 2: Query for Cat 2 during the reversal period (2026-10-10 to 2026-10-20)
        // Original invoice was on 2026-10-05 (outside period), reversal was on 2026-10-15 (inside period)
        $filtersCat2 = new ReportFilters(period: $periodReversalOnly, categoryId: $cat2->id);
        $cat2Result = app(SalesByCategoryReportQuery::class)->execute($this->company, $filtersCat2, $this->owner);
        $this->assertCount(1, $cat2Result->rows);
        $this->assertSame('-380.000000', $cat2Result->totals['sales_revenue_base']);
        $this->assertSame('-2.000000', $cat2Result->rows[0]['quantity_base']);
        $this->assertSame('-100.000000', $cat2Result->totals['cogs_base']);
        $this->assertSame('-280.000000', $cat2Result->totals['gross_profit_base']);

        // Assert signed line metrics in underlying salesLineActivityQuery for Cat 2
        $activityLines = TradeEventActivity::salesLineActivityQuery($this->company, $filtersCat2, true)->get();
        $this->assertCount(1, $activityLines);
        $actLine = (array) $activityLines[0];
        $this->assertSame('-2.000000', (string) $actLine['quantity_base']);
        $this->assertSame('-380.000000', (string) $actLine['line_revenue_base']);
        $this->assertSame('-20.000000', (string) $actLine['line_discount_base']);
        $this->assertSame('-60.800000', (string) $actLine['line_tax_base']);
        $this->assertSame('-100.000000', (string) $actLine['cogs_total_base']);

        // Assert SalesGrossProfitReportQuery for Prod 2 during reversal period
        $filtersProd2 = new ReportFilters(period: $periodReversalOnly, productId: $prod2->id);
        $profitResult = app(SalesGrossProfitReportQuery::class)->execute($this->company, $filtersProd2, $this->owner);
        $this->assertSame('-380.000000', $profitResult->totals['total_revenue_base']);
        $this->assertSame('-100.000000', $profitResult->totals['total_cogs_base']);
        $this->assertSame('-280.000000', $profitResult->totals['total_gross_profit_base']);

        // Test 3: Query for Cat 2 over the full month (2026-10-01 to 2026-10-31)
        // Original invoice (+380 rev, +2 qty, +100 cogs, +280 profit) and reversal (-380, -2, -100, -280) cancel to 0!
        $periodFullMonth = ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company);
        $filtersCat2Full = new ReportFilters(period: $periodFullMonth, categoryId: $cat2->id);
        $cat2FullResult = app(SalesByCategoryReportQuery::class)->execute($this->company, $filtersCat2Full, $this->owner);
        $this->assertSame('0.000000', $cat2FullResult->totals['sales_revenue_base']);
        $this->assertSame('0.000000', $cat2FullResult->totals['quantity_base']);
        $this->assertSame('0.000000', $cat2FullResult->totals['cogs_base']);
        $this->assertSame('0.000000', $cat2FullResult->totals['gross_profit_base']);

        // Test 4: Query by product for Prod 1 (Cat 1) over full month
        $prod1Result = app(SalesByProductReportQuery::class)->execute($this->company, new ReportFilters(period: $periodFullMonth, categoryId: $cat1->id), $this->owner);
        $this->assertCount(1, $prod1Result->rows);
        $this->assertSame('300.000000', $prod1Result->totals['sales_revenue_base']);
        $this->assertSame('3.000000', $prod1Result->rows[0]['quantity_base']);

        // Test 5: SalesSummary across all sales events during reversal-only period vs full month
        // Reversal-only period captures only the voided invoice: negative revenue, discounts, tax
        $summaryReversal = app(SalesSummaryReportQuery::class)->execute($this->company, ['period' => $periodReversalOnly->toArray()], $this->owner);
        $this->assertSame('-380.000000', $summaryReversal->totals['revenue_base']);
        $this->assertSame('-20.000000', $summaryReversal->totals['discounts_base']);
        $this->assertSame('-60.800000', $summaryReversal->totals['tax_base']);

        // Full month captures Prod 1 (+300 rev, 0 disc, 0 tax) while Prod 2 cancels to 0
        $summaryFull = app(SalesSummaryReportQuery::class)->execute($this->company, ['period' => $periodFullMonth->toArray()], $this->owner);
        $this->assertSame('300.000000', $summaryFull->totals['revenue_base']);
        $this->assertSame('0.000000', $summaryFull->totals['discounts_base']);
        $this->assertSame('0.000000', $summaryFull->totals['tax_base']);
    }

    /** Finding B: Money current balances default to Company-local today */
    public function test_finding_b_money_today_cutoff_uses_company_timezone_and_excludes_future_postings(): void
    {
        // Set company timezone to Asia/Gaza (UTC+3)
        $this->company->timezone = 'Asia/Gaza';
        $this->company->save();

        // Freeze time at 2026-10-15 22:30:00 UTC
        // In Asia/Gaza (+03:00), local time is 2026-10-16 01:30:00
        // Company-local date is 2026-10-16, whereas UTC date is 2026-10-15
        Carbon::setTestNow('2026-10-15 22:30:00');
        $this->activateUser($this->owner);

        // Event 1 posted on 2026-10-16 (Company-local TODAY)
        app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->defaultCustomer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-16',
            'payment_method' => 'cash',
            'amount' => '500.000000',
            'exchange_rate' => '1.000000',
            'idempotency_key' => 'b-today-event',
        ]);

        // Event 2 posted on 2026-10-25 (FUTURE date in same month)
        app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->defaultCustomer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-25',
            'payment_method' => 'cash',
            'amount' => '250.000000',
            'exchange_rate' => '1.000000',
            'idempotency_key' => 'b-future-event',
        ]);

        // Query Money balances with NO filters provided (default current state)
        $result = app(MoneyBalanceReportQuery::class)->execute($this->company, [], $this->owner);

        // 1. Meta as_of_date must be Company-local TODAY (2026-10-16), not UTC (2026-10-15) and not month-end (2026-10-31)
        $this->assertSame('2026-10-16', $result->meta['as_of_date']);

        // 2. Balance must be 500.000000, excluding the future posting of 250.000000
        $ilsRow = collect($result->rows)->firstWhere('money_account_id', $this->ilsCashAccount->id);
        $this->assertNotNull($ilsRow);
        $this->assertSame('500.000000', $ilsRow['balance_base']);

        // 3. Registry alias execution without filters must also resolve to today
        $registryResult = app(ReportRegistry::class)->execute($this->company, 'money.balances', []);
        $this->assertSame('2026-10-16', $registryResult->meta['as_of_date']);

        // 4. Dashboard positions must report today as cutoff
        $dashboard = app(DashboardReports::class)->read($this->company, 'this_month');
        $this->assertSame('2026-10-16', $dashboard['today']);

        // 5. If user EXPLICITLY asks for future date (2026-10-31), future postings are included
        $futurePeriod = ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company);
        $futureResult = app(MoneyBalanceReportQuery::class)->execute($this->company, [
            'money_account_id' => $this->ilsCashAccount->id,
            'period' => $futurePeriod->toArray(),
        ]);
        $this->assertSame('2026-10-31', $futureResult->meta['as_of_date']);
        $this->assertSame('750.000000', $futureResult->rows[0]['balance_base']);
    }

    /** Finding C: Check party names are text and survive real HTTP CSV streaming */
    public function test_finding_c_check_party_names_are_text_and_survive_csv_stream(): void
    {
        $this->activateUser($this->owner);

        // 1. Incoming check with explicit Arabic customer
        $arabicCustomer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'شركة النور للتجارة',
            'name_en' => 'Al Noor Trading Co',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
        $checkData = array_merge($this->checkIntent('incoming'), [
            'party_id' => $arabicCustomer->id,
            'check_number' => 'CHK-AR-CUST-1',
            'idempotency_key' => 'chk-ar-cust-1',
        ]);
        app(ReceiveCheckAction::class)->execute($this->company, $this->owner, $checkData);

        // 2. Outgoing check
        $this->check('outgoing');

        // 3. Returned check
        $returnedCheck = $this->check('incoming');
        $this->event($returnedCheck, 'deposit', '3.50', '2026-10-03');
        $this->event($returnedCheck->fresh(), 'return', '3.50', '2026-10-04');

        // 4. Incoming check with formula name to test text-cell sanitization
        $formulaCustomer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => '=SUM(A1:A10)',
            'name_en' => '=SUM(A1:A10)',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
        $formulaData = array_merge($this->checkIntent('incoming'), [
            'party_id' => $formulaCustomer->id,
            'check_number' => 'CHK-FORMULA-99',
            'idempotency_key' => 'chk-formula-intent-99',
        ]);
        app(ReceiveCheckAction::class)->execute($this->company, $this->owner, $formulaData);

        // 5. Outgoing expense check with free-text payee
        app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'expense',
            'category_id' => $this->operatingCategory->id,
            'description' => 'شيك صادر للمصروفات الحرة',
            'date' => '2026-10-02',
            'due_date' => '2026-10-25',
            'amount' => '75.000000',
            'currency_code' => 'USD',
            'exchange_rate' => '3.500000',
            'money_account_id' => $this->usdBankAccount->id,
            'check_number' => 'CHK-EXP-FREE-1',
            'bank_name' => 'بنك فلسطين',
            'payee_name' => 'شركة المقاولات العامة الحرة',
            'idempotency_key' => 'exp-chk-free-1',
        ]);

        $checkRoutes = [
            'money.checks',
            'money.incoming-checks',
            'money.outgoing-checks',
            'money.due-checks',
            'money.returned-checks',
        ];

        foreach ($checkRoutes as $routeKey) {
            $response = $this->get(route('reports.export', ['reportKey' => $routeKey]));
            $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
            $csv = $response->streamedContent();

            $this->assertNotEmpty($csv, "CSV for {$routeKey} must not be empty.");
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

            // Parse CSV lines properly using str_getcsv
            $cleanCsv = preg_replace('/^\xEF\xBB\xBF/', '', trim($csv));
            $lines = explode("\n", (string) $cleanCsv);
            $parsedRows = array_map(fn (string $line): array => str_getcsv(trim($line)), $lines);
            $header = $parsedRows[0] ?? [];
            $partyColIdx = array_search(__('reports.columns.party_name'), $header, true);
            if ($partyColIdx === false) {
                $partyColIdx = 4; // fallback party column index
            }

            if ($routeKey === 'money.incoming-checks') {
                // Assert that formula-injection protection prepended a single quote
                $foundFormula = false;
                $foundArabic = false;
                foreach (array_slice($parsedRows, 1) as $row) {
                    if (isset($row[$partyColIdx]) && $row[$partyColIdx] === "'=SUM(A1:A10)") {
                        $foundFormula = true;
                    }
                    if (isset($row[$partyColIdx]) && $row[$partyColIdx] === 'شركة النور للتجارة') {
                        $foundArabic = true;
                    }
                }
                $this->assertTrue($foundFormula, 'Parsed CSV party cell must contain single-quote escaped formula: \' =SUM(A1:A10)');
                $this->assertTrue($foundArabic, 'Parsed CSV party cell must contain Arabic customer name.');
            }

            if ($routeKey === 'money.outgoing-checks') {
                // Assert that free-text payee is present in parsed CSV rows
                $foundFreeText = false;
                foreach (array_slice($parsedRows, 1) as $row) {
                    if (isset($row[$partyColIdx]) && $row[$partyColIdx] === 'شركة المقاولات العامة الحرة') {
                        $foundFreeText = true;
                        break;
                    }
                }
                $this->assertTrue($foundFreeText, 'Parsed CSV party cell must contain exact free-text payee name.');
            }
        }
    }

    /** Finding D: Every Expense alias matches its grouping row shape */
    public function test_finding_d_expense_aliases_match_emitted_row_shapes_ui_and_csv(): void
    {
        $this->activateUser($this->owner);

        // Provision multi-month, multi-currency, multi-category expenses using existing seeded categories
        $fuelCategory = ExpenseCategory::where('company_id', $this->company->id)->where('code', 'fuel')->firstOrFail();
        $deliveryCategory = ExpenseCategory::where('company_id', $this->company->id)->where('code', 'delivery')->firstOrFail();
        $transportCategory = ExpenseCategory::where('company_id', $this->company->id)->where('code', 'transport')->firstOrFail();

        // Sept expense (ILS)
        app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $fuelCategory->id,
            'description' => 'وقود شهر 9',
            'expense_date' => '2026-09-15',
            'currency_code' => 'ILS',
            'amount' => '100.000000',
            'exchange_rate' => '1.000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->ilsCashAccount->id,
            'idempotency_key' => 'd-exp-sep-ils',
        ]);

        // Oct expense 1 (ILS, Fuel)
        app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $fuelCategory->id,
            'description' => 'وقود شهر 10',
            'expense_date' => '2026-10-05',
            'currency_code' => 'ILS',
            'amount' => '150.000000',
            'exchange_rate' => '1.000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->ilsCashAccount->id,
            'idempotency_key' => 'd-exp-oct-ils',
        ]);

        // Oct expense 2 (USD, Delivery)
        app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $deliveryCategory->id,
            'description' => 'توصيل شحنة بالدولار',
            'expense_date' => '2026-10-08',
            'currency_code' => 'USD',
            'amount' => '50.000000',
            'exchange_rate' => '3.500000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id,
            'idempotency_key' => 'd-exp-oct-usd',
        ]);

        // Oct expense 3 (ILS, Transport)
        app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $transportCategory->id,
            'description' => 'نقل بري',
            'expense_date' => '2026-10-10',
            'currency_code' => 'ILS',
            'amount' => '75.000000',
            'exchange_rate' => '1.000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->ilsCashAccount->id,
            'idempotency_key' => 'd-exp-oct-transport',
        ]);

        $registry = app(ReportRegistry::class);
        $presenter = app(ReportPresenter::class);
        $period = ReportPeriod::custom('2026-09-01', '2026-10-31', $this->company);

        $aliases = [
            'expenses.by-period' => ['period', 'total_base', 'transaction_count'],
            'expenses.trend' => ['period', 'total_base', 'transaction_count'],
            'expenses.by-currency' => ['currency_code', 'total_amount'],
            'expenses.by-category' => ['category_name', 'category_code', 'total_base', 'transaction_count'],
            'expenses.fuel' => ['category_name', 'category_code', 'total_base', 'transaction_count'],
            'expenses.delivery' => ['category_name', 'category_code', 'total_base', 'transaction_count'],
            'expenses.transport' => ['category_name', 'category_code', 'total_base', 'transaction_count'],
            'expenses.detail' => ['expense_number', 'date', 'category_name', 'vendor_name', 'description', 'classification', 'payment_method', 'currency_code', 'amount', 'base_amount'],
        ];

        foreach ($aliases as $aliasKey => $expectedColumns) {
            $result = $registry->execute($this->company, $aliasKey, [
                'period' => $period->toArray(),
            ]);

            $this->assertNotEmpty($result->rows, "Rows for {$aliasKey} must not be empty.");

            // Verify presenter columns include all expected columns (none dropped silently)
            $def = $registry->definition($aliasKey);
            $presenterColumns = $presenter->columns($def['columns'], $result);
            $presenterKeys = array_column($presenterColumns, 'key');
            $this->assertSame($expectedColumns, $presenterKeys, "Presenter columns for {$aliasKey} must match expected columns.");

            // Verify real HTTP CSV export
            $response = $this->get(route('reports.export', [
                'reportKey' => $aliasKey,
                'filters' => ['period' => $period->toArray()],
            ]));
            $response->assertOk();
            $csv = $response->streamedContent();
            $this->assertNotEmpty($csv);
        }

        // Livewire UI rendering assertions for representative aliases
        $compCat = Livewire::withQueryParams(['filters' => ['period' => $period->toArray()]])
            ->test(ReportView::class, ['reportKey' => 'expenses.by-category']);
        $compCat->assertStatus(200)->assertSee('وقود ومحروقات')->assertSee('خدمات التوصيل والشحن');

        $compCur = Livewire::withQueryParams(['filters' => ['period' => $period->toArray()]])
            ->test(ReportView::class, ['reportKey' => 'expenses.by-currency']);
        $compCur->assertStatus(200)->assertSee('ILS')->assertSee('USD');

        $compPer = Livewire::withQueryParams(['filters' => ['period' => $period->toArray()]])
            ->test(ReportView::class, ['reportKey' => 'expenses.by-period']);
        $compPer->assertStatus(200)->assertSee('2026-09')->assertSee('2026-10');
    }

    /** Finding E: Expiry lot identifiers remain text */
    public function test_finding_e_expiry_lot_identifiers_remain_text(): void
    {
        $this->activateUser($this->owner);

        // Create product with expiry and lot tracking
        $lotProduct = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'دواء تجريبي',
            'name_en' => 'Trial Medicine',
            'sku' => 'MED-001',
            'base_unit_id' => (int) $this->unit->unit_id,
            'track_stock' => true,
            'track_expiry' => true,
            'default_price' => '20.000000',
        ], (int) $this->owner->id);

        // Opening stock with alphanumeric lot identifier: LOT-2026-001, leading zero: 000123, and Arabic/Unicode: وجبة-٢٠٢٦-أ
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $lotProduct,
            warehouse: $this->warehouse,
            quantity: Quantity::of('10.000000'),
            unitCostBase: '15.000000',
            user: $this->owner,
            idempotencyKey: 'e-lot-1',
            lotNumber: 'LOT-2026-001',
            expiryDate: '2026-12-31',
            movementDate: '2026-10-01',
        );

        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $lotProduct,
            warehouse: $this->warehouse,
            quantity: Quantity::of('5.000000'),
            unitCostBase: '15.000000',
            user: $this->owner,
            idempotencyKey: 'e-lot-2',
            lotNumber: '000123',
            expiryDate: '2026-11-30',
            movementDate: '2026-10-01',
        );

        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $lotProduct,
            warehouse: $this->warehouse,
            quantity: Quantity::of('7.000000'),
            unitCostBase: '15.000000',
            user: $this->owner,
            idempotencyKey: 'e-lot-3',
            lotNumber: 'وجبة-٢٠٢٦-أ',
            expiryDate: '2026-10-31',
            movementDate: '2026-10-01',
        );

        $result = app(ReportRegistry::class)->execute($this->company, 'inventory.expiry', []);
        $this->assertNotEmpty($result->rows);

        $lotNumbers = array_column($result->rows, 'lot_number');
        $this->assertContains('LOT-2026-001', $lotNumbers);
        $this->assertContains('000123', $lotNumbers);
        $this->assertContains('وجبة-٢٠٢٦-أ', $lotNumbers);

        // Verify CSV export streams without numeric validation failure and parses exact text strings
        $response = $this->get(route('reports.export', ['reportKey' => 'inventory.expiry']));
        $response->assertOk();
        $csv = $response->streamedContent();
        $cleanCsv = preg_replace('/^\xEF\xBB\xBF/', '', trim($csv));
        $lines = explode("\n", (string) $cleanCsv);
        $parsedRows = array_map(fn (string $line): array => str_getcsv(trim($line)), $lines);
        $header = $parsedRows[0] ?? [];
        $lotColIdx = array_search(__('reports.columns.lot_number'), $header, true);
        if ($lotColIdx === false) {
            $lotColIdx = 2; // fallback lot column index
        }
        $csvLots = [];
        foreach (array_slice($parsedRows, 1) as $row) {
            if (isset($row[$lotColIdx])) {
                $csvLots[] = $row[$lotColIdx];
            }
        }
        $this->assertContains('LOT-2026-001', $csvLots);
        $this->assertContains('000123', $csvLots, 'Leading zeroes must be preserved in parsed CSV text cell.');
        $this->assertContains('وجبة-٢٠٢٦-أ', $csvLots, 'Arabic Unicode lot identifiers must be preserved in parsed CSV text cell.');
    }

    /** Finding F: Stock Transfer fields match query output */
    public function test_finding_f_stock_transfer_fields_match_query(): void
    {
        $this->activateUser($this->owner);

        $destWarehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مستودع فرعي',
            'name_en' => 'Branch Warehouse',
            'code' => 'BR-01',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        // Put stock in source warehouse first
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of('100.000000'),
            unitCostBase: '25.000000',
            user: $this->owner,
            idempotencyKey: 'f-opening',
            movementDate: '2026-10-01',
        );

        // Perform stock transfer of 15 units
        app(InventoryMovementService::class)->transfer(new StockTransferCommand(
            companyId: (int) $this->company->id,
            sourceWarehouseId: (int) $this->warehouse->id,
            destinationWarehouseId: (int) $destWarehouse->id,
            movementDate: '2026-10-08',
            lines: [new StockTransferLineCommand((int) $this->product->id, Quantity::of('15.000000'))],
            idempotencyKey: 'f-transfer-command',
            createdBy: (int) $this->owner->id,
        ));

        $registry = app(ReportRegistry::class);
        $presenter = app(ReportPresenter::class);
        $result = $registry->execute($this->company, 'inventory.transfers', [
            'period' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
        ]);

        $this->assertCount(1, $result->rows);
        $row = $result->rows[0];

        $this->assertSame('2026-10-08', $row['transfer_date']);
        $this->assertSame('15.000000', $row['quantity_transferred']);
        $this->assertSame('25.000000', $row['unit_cost_base']);

        $def = $registry->definition('inventory.transfers');
        $columns = $presenter->columns($def['columns'], $result);
        $columnKeys = array_column($columns, 'key');

        $this->assertContains('transfer_date', $columnKeys);
        $this->assertContains('quantity_transferred', $columnKeys);
        $this->assertContains('unit_cost_base', $columnKeys);
        $this->assertNotContains('movement_date', $columnKeys);
        $this->assertNotContains('quantity_base', $columnKeys);

        // Livewire UI rendering assertion
        $comp = Livewire::withQueryParams(['filters' => ['period' => ['from' => '2026-10-01', 'to' => '2026-10-31']]])
            ->test(ReportView::class, ['reportKey' => 'inventory.transfers']);
        $comp->assertStatus(200)
            ->assertSee('2026-10-08')
            ->assertSee('15.000000')
            ->assertSee('25.000000')
            ->assertSee($this->warehouse->name_ar)
            ->assertSee($destWarehouse->name_ar);

        // Real HTTP CSV stream test parsed properly
        $response = $this->get(route('reports.export', [
            'reportKey' => 'inventory.transfers',
            'filters' => ['period' => ['from' => '2026-10-01', 'to' => '2026-10-31']],
        ]));
        $response->assertOk();
        $csv = $response->streamedContent();
        $cleanCsv = preg_replace('/^\xEF\xBB\xBF/', '', trim($csv));
        $lines = explode("\n", (string) $cleanCsv);
        $parsedRows = array_map(fn (string $line): array => str_getcsv(trim($line)), $lines);
        $this->assertGreaterThanOrEqual(2, count($parsedRows));
        $this->assertContains('2026-10-08', $parsedRows[1]);
        $this->assertContains('15.000000', $parsedRows[1]);
        $this->assertContains('25.000000', $parsedRows[1]);
    }

    /** Finding G: Inventory by warehouse separates warehouses with default grouping */
    public function test_finding_g_inventory_by_warehouse_separates_warehouses_with_default_grouping(): void
    {
        $this->activateUser($this->owner);

        $warehouse2 = Warehouse::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مستودع الساحل',
            'name_en' => 'Coast Warehouse',
            'code' => 'COAST',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        // Stock in warehouse 1: 40 units
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of('40.000000'),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'g-opening-1',
            movementDate: '2026-10-01',
        );

        // Stock in warehouse 2: 60 units
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $warehouse2,
            quantity: Quantity::of('60.000000'),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'g-opening-2',
            movementDate: '2026-10-01',
        );

        // Invoke alias with its defaults (no grouping override)
        $registry = app(ReportRegistry::class);
        $result = $registry->execute($this->company, 'inventory.by-warehouse', []);

        $this->assertCount(2, $result->rows, 'Must produce distinct rows for each warehouse.');

        $warehouseNames = array_column($result->rows, 'warehouse_name');
        $this->assertContains($this->warehouse->name_ar, $warehouseNames);
        $this->assertContains($warehouse2->name_ar, $warehouseNames);

        $quantities = array_column($result->rows, 'quantity_on_hand');
        $this->assertContains('40.000000', $quantities);
        $this->assertContains('60.000000', $quantities);

        $this->assertSame('100.000000', $result->totals['total_quantity']);
        $this->assertSame(1, $result->totals['products_count']);
        $this->assertSame(1, $result->totals['in_stock_count']);
        $valuation = app(InventoryValuationReportQuery::class)->execute($this->company, ['grouping' => 'warehouse'], $this->owner);
        $this->assertCount(2, $valuation->rows);
        $this->assertSame(1, $valuation->totals['products_count']);
        $this->assertSame(1, $valuation->totals['in_stock_count']);

        // Livewire UI rendering assertion
        $comp = Livewire::test(ReportView::class, ['reportKey' => 'inventory.by-warehouse']);
        $comp->assertStatus(200)
            ->assertSee($this->warehouse->name_ar)
            ->assertSee($warehouse2->name_ar)
            ->assertSee('40.000000')
            ->assertSee('60.000000');

        // CSV export retains warehouse names and quantities
        $response = $this->get(route('reports.export', ['reportKey' => 'inventory.by-warehouse']));
        $response->assertOk();
        $csv = $response->streamedContent();
        $cleanCsv = preg_replace('/^\xEF\xBB\xBF/', '', trim($csv));
        $lines = explode("\n", (string) $cleanCsv);
        $parsedRows = array_map(fn (string $line): array => str_getcsv(trim($line)), $lines);
        $this->assertGreaterThanOrEqual(3, count($parsedRows));
        $this->assertStringContainsString('40.000000', $csv);
        $this->assertStringContainsString('60.000000', $csv);
    }
}
