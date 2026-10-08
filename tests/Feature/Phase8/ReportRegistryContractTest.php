<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Expenses\ReverseExpenseAction;
use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Money\PostMoneyTransferAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Money\ReverseMoneyTransferAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Payroll\PostSalaryPaymentAction;
use App\Actions\Purchasing\AllocateLandedCostAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Presentation\ReportPresenter;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Application\Reporting\Support\CsvCellFormatter;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\ProductCatalogService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Depends;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class ReportRegistryContractTest extends TradeTestCase
{
    /** @var array<string, array<string, mixed>> */
    private static array $recordedContractResults = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$recordedContractResults = [];
    }

    protected Warehouse $branchWarehouse;

    protected Product $expiryProduct;

    protected Product $lowStockProduct;

    protected ProductCategory $mainCategory;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_registry_contains_exactly_69_canonical_definitions(): void
    {
        $registry = app(ReportRegistry::class);
        $all = $registry->all();
        $this->assertCount(69, $all, 'Registry must contain exactly 69 definitions.');

        foreach ($all as $key => $def) {
            $this->assertNotEmpty($def['key'], "Definition {$key} must have key.");
            $this->assertTrue(class_exists($def['query']), "Query class {$def['query']} for {$key} must exist.");
            $this->assertNotEmpty($def['columns'], "Definition {$key} must declare columns.");
            $this->assertNotEmpty($def['permissions'], "Definition {$key} must declare permissions.");
            $this->assertIsArray($def['filters'], "Definition {$key} must declare filters array.");
            foreach ($def['columns'] as $column) {
                if (preg_match('/(?:name|sku|code|number|description|reference|period|date)$/D', $column['key'])) {
                    $this->assertSame('text', $column['type'], "Identifier {$key}.{$column['key']} must be text.");
                }
            }
        }
    }

    public function test_sales_family_contract(): void
    {
        $this->seedComprehensiveHistory();
        $keys = [
            'sales.summary',
            'sales.by-period',
            'sales.by-customer',
            'sales.by-product',
            'sales.by-category',
            'sales.gross-profit',
            'sales.discounts',
            'sales.returns',
            'sales.unpaid',
            'sales.price-history',
        ];

        foreach ($keys as $key) {
            $this->assertVariantSatisfiesContract($key);
        }
    }

    public function test_customers_family_contract(): void
    {
        $this->seedComprehensiveHistory();
        $keys = [
            'customers.balances' => [],
            'customers.statement' => ['customer_id' => $this->defaultCustomer->id],
            'customers.aging' => [],
            'customers.overdue' => [],
            'customers.top' => [],
            'customers.buying-history' => ['customer_id' => $this->defaultCustomer->id],
            'customers.product-history' => ['customer_id' => $this->defaultCustomer->id],
        ];

        foreach ($keys as $key => $filters) {
            $this->assertVariantSatisfiesContract($key, $filters);
        }
    }

    public function test_purchases_family_contract(): void
    {
        $this->seedComprehensiveHistory();
        $keys = [
            'purchases.summary',
            'purchases.by-period',
            'purchases.by-vendor',
            'purchases.by-product',
            'purchases.returns',
            'purchases.unpaid',
            'purchases.price-history',
        ];

        foreach ($keys as $key) {
            $this->assertVariantSatisfiesContract($key);
        }
    }

    public function test_vendors_family_contract(): void
    {
        $this->seedComprehensiveHistory();
        $keys = [
            'vendors.balances' => [],
            'vendors.statement' => ['vendor_id' => $this->vendor->id],
            'vendors.aging' => [],
            'vendors.purchase-history' => ['vendor_id' => $this->vendor->id],
            'vendors.product-history' => ['vendor_id' => $this->vendor->id],
            'vendors.price-history' => ['vendor_id' => $this->vendor->id],
        ];

        foreach ($keys as $key => $filters) {
            $this->assertVariantSatisfiesContract($key, $filters);
        }
    }

    public function test_inventory_family_contract(): void
    {
        $this->seedComprehensiveHistory();
        $keys = [
            'inventory.stock',
            'inventory.by-warehouse',
            'inventory.movements',
            'inventory.valuation',
            'inventory.low-stock',
            'inventory.adjustments',
            'inventory.cost-history',
            'inventory.vendor-products',
            'inventory.transfers',
            'inventory.expiry',
        ];

        foreach ($keys as $key) {
            $this->assertVariantSatisfiesContract($key);
        }
    }

    public function test_money_family_contract(): void
    {
        $this->seedComprehensiveHistory();
        $keys = [
            'money.balances' => [],
            'money.movements' => ['money_account_id' => $this->ilsCashAccount->id],
            'money.receipts' => [],
            'money.vendor-payments' => [],
            'money.transfers' => [],
            'money.checks' => [],
            'money.cash' => [],
            'money.bank' => [],
            'money.incoming-checks' => [],
            'money.outgoing-checks' => [],
            'money.due-checks' => [],
            'money.returned-checks' => [],
        ];

        foreach ($keys as $key => $filters) {
            $this->assertVariantSatisfiesContract($key, $filters);
        }
    }

    public function test_expenses_family_contract(): void
    {
        $this->seedComprehensiveHistory();
        $keys = [
            'expenses.summary',
            'expenses.detail',
            'expenses.by-period',
            'expenses.by-category',
            'expenses.by-currency',
            'expenses.trend',
            'expenses.fuel',
            'expenses.delivery',
            'expenses.transport',
        ];

        foreach ($keys as $key) {
            $this->assertVariantSatisfiesContract($key);
        }
    }

    public function test_payroll_family_contract(): void
    {
        $this->seedComprehensiveHistory();
        $keys = [
            'payroll.summary' => [],
            'payroll.payments' => [],
            'payroll.advances' => [],
            'payroll.statement' => ['employee_id' => $this->employee->id],
            'payroll.unpaid' => [],
            'payroll.outstanding-advances' => [],
            'payroll.salary-history' => ['employee_id' => $this->employee->id],
        ];

        foreach ($keys as $key => $filters) {
            $this->assertVariantSatisfiesContract($key, $filters);
        }
    }

    public function test_profit_family_contract(): void
    {
        $this->seedComprehensiveHistory();
        $this->assertVariantSatisfiesContract('profit');
    }

    #[Depends('test_sales_family_contract')]
    #[Depends('test_customers_family_contract')]
    #[Depends('test_purchases_family_contract')]
    #[Depends('test_vendors_family_contract')]
    #[Depends('test_inventory_family_contract')]
    #[Depends('test_money_family_contract')]
    #[Depends('test_expenses_family_contract')]
    #[Depends('test_payroll_family_contract')]
    #[Depends('test_profit_family_contract')]
    public function test_all_69_variants_covered_and_artifact_generated(): void
    {
        $registry = app(ReportRegistry::class);
        $expectedKeys = array_keys($registry->all());
        $recordedKeys = array_keys(self::$recordedContractResults);

        sort($expectedKeys);
        sort($recordedKeys);

        $this->assertCount(69, $recordedKeys, 'Contract suite must cover all 69 variants in the current run.');
        $this->assertSame($expectedKeys, $recordedKeys, 'Contract suite must cover every one of the 69 variants with zero omissions.');

        $targetDir = base_path('.ai/delegations/phase8-architect-correction01');
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $filePath = $targetDir.'/registry-contract-results.json';
        $payload = [
            'total_variants' => 69,
            'passed_variants' => count(self::$recordedContractResults),
            'failed_variants' => 0,
            'verified_at' => Carbon::now()->toIso8601String(),
            'covered_keys' => $recordedKeys,
            'variants' => self::$recordedContractResults,
        ];
        file_put_contents($filePath, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function test_representative_family_lower_privilege_and_cost_redaction(): void
    {
        $this->seedComprehensiveHistory();
        $registry = app(ReportRegistry::class);

        $viewer = $this->createMemberWithPermissions([
            'reports.inventory.view',
            'inventory.stock.view',
            'reports.expenses.view',
            'money.expense.view',
        ]);
        $this->activateUser($viewer);

        // 1. Inventory Transfers: unit_cost_base must be null and cost_redacted true
        $transResult = $registry->execute($this->company, 'inventory.transfers', [], $viewer);
        $this->assertTrue($transResult->meta['cost_redacted'] ?? false, 'Transfers meta must indicate cost_redacted.');
        foreach ($transResult->rows as $row) {
            $this->assertNull($row['unit_cost_base'], 'unit_cost_base must be null for restricted viewer.');
        }

        // 2. Expenses Summary: landed cost clearing base must not be exposed in totals
        $expResult = $registry->execute($this->company, 'expenses.summary', [], $viewer);
        $this->assertTrue($expResult->meta['cost_redacted'] ?? false, 'Expenses meta must indicate cost_redacted.');
        $this->assertArrayNotHasKey('landed_cost_clearing_base', $expResult->totals, 'Landed cost clearing must be omitted from totals.');
    }

    public function test_representative_family_permission_denial_and_fresh_revocation(): void
    {
        $this->seedComprehensiveHistory();
        $registry = app(ReportRegistry::class);

        $viewer = $this->createMemberWithPermissions(['reports.sales.view', 'sales.invoice.view']);
        $this->activateUser($viewer);

        // Allowed initially
        $res = $registry->execute($this->company, 'sales.summary', [], $viewer);
        $this->assertNotEmpty($res->rows);

        // Revoke reports.sales.view
        /** @var Role $role */
        $role = $viewer->roles->first();
        $role->revokePermissionTo('reports.sales.view');
        $viewer->unsetRelation('permissions')->unsetRelation('roles');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(AuthorizationException::class);
        $registry->execute($this->company, 'sales.summary', [], $viewer);
    }

    public function test_cross_company_denial(): void
    {
        $this->seedComprehensiveHistory();
        $registry = app(ReportRegistry::class);

        $otherOwner = User::factory()->create();
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة أجنبية',
            'base_currency_code' => 'USD',
        ]);

        $this->actingAs($otherOwner);
        app(CompanyContext::class)->setCompany($otherCompany, $otherOwner);

        $this->expectException(AuthorizationException::class);
        $registry->execute($this->company, 'sales.summary', [], $otherOwner);
    }

    public function test_representative_localized_headers_and_labels_in_ar_and_en(): void
    {
        $this->seedComprehensiveHistory();
        $this->company->languages()->updateOrCreate(['locale' => 'en'], ['enabled' => true]);
        $registry = app(ReportRegistry::class);

        $sampleKeys = [
            'sales.summary',
            'customers.balances',
            'purchases.summary',
            'inventory.valuation',
            'money.balances',
            'expenses.summary',
            'payroll.summary',
            'profit',
        ];
        $originalLocale = app()->getLocale();
        try {
            foreach ($sampleKeys as $key) {
                app()->setLocale('ar');
                $titleAr = $registry->title($key);
                app()->setLocale('en');
                $titleEn = $registry->title($key);
                $this->assertNotEmpty($titleAr, "Arabic title for {$key} must not be empty.");
                $this->assertNotEmpty($titleEn, "English title for {$key} must not be empty.");
                $this->assertNotSame($titleAr, $titleEn, "Arabic and English titles for {$key} must be distinct.");
                foreach (['ar', 'en'] as $locale) {
                    $this->withSession(['locale' => $locale]);
                    app()->setLocale($locale);
                    $this->assertVariantSatisfiesContract($key);
                    $response = $this->get(route('reports.show', ['reportKey' => $key]));
                    $response->assertOk()->assertSee($registry->title($key));
                    $this->assertSame($locale, app()->getLocale());
                }
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_canonical_receipt_csv_exports_every_page_without_loss_or_duplication(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->activateUser($this->owner);
        for ($i = 1; $i <= 101; $i++) {
            app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $this->defaultCustomer->id,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_date' => '2026-10-20',
                'payment_method' => 'cash',
                'amount' => '0.01',
                'exchange_rate' => '1',
                'idempotency_key' => 'c01-csv-page-'.$i,
            ]);
        }
        $registry = app(ReportRegistry::class);
        $presenter = app(ReportPresenter::class);
        $first = $registry->execute($this->company, 'money.receipts', ['per_page' => 100]);
        $second = $registry->execute($this->company, 'money.receipts', ['per_page' => 100, 'page' => 2]);
        $this->assertCount(100, $first->rows);
        $this->assertCount(1, $second->rows);
        $columns = $presenter->columns($registry->definition('money.receipts')['columns'], $first);
        $csv = $this->get(route('reports.export', ['reportKey' => 'money.receipts']))->assertOk()->streamedContent();
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $parsed = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $parsed[] = $row;
        }
        fclose($stream);
        $this->assertCount(102, $parsed);
        $this->assertSame(array_column($columns, 'label'), array_shift($parsed));
        foreach (array_merge($first->rows, $second->rows) as $index => $row) {
            $expected = [];
            foreach ($columns as $column) {
                $value = $presenter->value($row, $column['key']);
                $expected[] = $column['type'] === 'decimal' ? CsvCellFormatter::decimal($value) : CsvCellFormatter::text($value);
            }
            $this->assertSame($expected, $parsed[$index]);
        }
        $this->assertCount(101, array_unique(array_column($parsed, 0)));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function assertVariantSatisfiesContract(string $key, array $filters = []): void
    {
        $registry = app(ReportRegistry::class);
        $presenter = app(ReportPresenter::class);
        $def = $registry->definition($key);

        $this->activateUser($this->owner);

        // 1. Direct query execution via registry
        $result = $registry->execute($this->company, $key, $filters);
        $this->assertInstanceOf(
            ReportResult::class,
            $result,
            "Execution for {$key} must return ReportResult."
        );

        $this->assertNotEmpty($result->rows, "Variant {$key} must return non-empty rows for canonical fixtures.");

        // 2. Validate columns and data types across ALL rows
        $checkedColumns = [];
        $columnTypes = [];
        // Nullable fields are explicit semantic contracts, not permission-independent exemptions.
        $nullableColumnsByReport = [
            'sales.gross-profit' => ['gross_margin'],
            'sales.by-product' => ['gross_margin'],
            'sales.by-category' => ['gross_margin'],
            'sales.by-customer' => ['gross_margin'],
            'purchases.returns' => ['reason'],
            'sales.returns' => ['reason'],
            'inventory.movements' => ['reason'],
            'inventory.cost-history' => ['reason'],
            'inventory.stock' => ['warehouse_name'],
            'inventory.valuation' => ['warehouse_name'],
            'money.balances' => ['balance_currency'],
            'money.cash' => ['balance_currency'],
            'money.bank' => ['balance_currency'],
        ];
        $nullableColumns = $nullableColumnsByReport[$key] ?? [];

        foreach ($def['columns'] as $colDef) {
            $colKey = $colDef['key'];
            $colType = $colDef['type'];
            $checkedColumns[] = $colKey;
            $columnTypes[$colKey] = $colType;

            foreach ($result->rows as $rowIndex => $row) {
                $this->assertTrue(
                    $presenter->isColumnPresentInRow($row, $colKey),
                    "Declared column {$colKey} must exist in emitted row #{$rowIndex} for {$key}."
                );

                $val = $row[$colKey] ?? null;
                $presentedVal = $presenter->value($row, $colKey);

                if ($colType === 'text') {
                    if ($presentedVal !== null) {
                        $this->assertIsString($presentedVal, "Column {$colKey} in row #{$rowIndex} for {$key} must be string when presented.");
                    } else {
                        $this->assertTrue(
                            in_array($colKey, $nullableColumns, true),
                            "Column {$colKey} in row #{$rowIndex} for {$key} cannot be null without being whitelisted."
                        );
                    }
                } elseif ($colType === 'decimal') {
                    $checkVal = $val ?? $presentedVal;
                    if ($checkVal !== null) {
                        $this->assertFalse(
                            is_float($checkVal),
                            "Decimal column {$colKey} in row #{$rowIndex} for {$key} must never be a PHP float."
                        );
                        $this->assertTrue(
                            is_string($checkVal) || is_int($checkVal),
                            "Decimal column {$colKey} in row #{$rowIndex} for {$key} must be numeric string or int [got: ".gettype($checkVal).'].'
                        );
                        $this->assertMatchesRegularExpression(
                            '/^-?\d+(\.\d+)?$/',
                            (string) $checkVal,
                            "Decimal column {$colKey} in row #{$rowIndex} for {$key} must be valid exact decimal string [got: {$checkVal}]."
                        );
                    } else {
                        $this->assertTrue(
                            in_array($colKey, $nullableColumns, true),
                            "Decimal column {$colKey} in row #{$rowIndex} for {$key} cannot be null without being whitelisted."
                        );
                    }
                } elseif ($colType === 'integer') {
                    $this->assertTrue(
                        is_int($val) || (is_string($val) && ctype_digit((string) $val)),
                        "Integer column {$colKey} in row #{$rowIndex} for {$key} must be integer."
                    );
                }
            }
        }

        // 3. Presenter column projection verification
        $presenterCols = $presenter->columns($def['columns'], $result);
        $this->assertNotEmpty($presenterCols, "Presenter columns for {$key} must not be empty.");
        $presenterKeys = array_column($presenterCols, 'key');
        $expectedColKeys = array_column($def['columns'], 'key');
        $this->assertSame(
            $expectedColKeys,
            $presenterKeys,
            "Presenter columns for {$key} must match registered definition columns."
        );

        // 4. Real HTTP CSV stream export
        $response = $this->get(route('reports.export', [
            'reportKey' => $key,
            'filters' => $filters,
        ]));
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertNotEmpty($csv, "CSV stream for {$key} must not be empty.");
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, "CSV stream for {$key} must start with UTF-8 BOM.");

        // Parse CSV stream with proper parser
        $csvBody = substr($csv, 3);
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, $csvBody);
        rewind($stream);

        $parsedRows = [];
        while (($csvRow = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $parsedRows[] = $csvRow;
        }
        fclose($stream);

        $this->assertNotEmpty($parsedRows, "Parsed CSV for {$key} must have at least header row.");
        $headerRow = $parsedRows[0];
        $this->assertCount(
            count($presenterCols),
            $headerRow,
            "CSV header column count for {$key} must match presenter columns count."
        );
        $this->assertSame(array_column($presenterCols, 'label'), $headerRow, "Exact CSV headers for {$key}.");
        $dataRows = array_slice($parsedRows, 1);
        $this->assertSame(
            count($result->rows),
            count($dataRows),
            "CSV data rows count for {$key} must match result rows count."
        );

        foreach ($result->rows as $rowIndex => $row) {
            foreach ($presenterCols as $columnIndex => $column) {
                $value = $presenter->value($row, $column['key']);
                $expected = $column['type'] === 'decimal'
                    ? CsvCellFormatter::decimal($value)
                    : CsvCellFormatter::text($value);
                $this->assertSame($expected, $dataRows[$rowIndex][$columnIndex], "Exact CSV cell {$key}.{$column['key']} row {$rowIndex}.");
            }
        }

        // Record verified result in static memory
        self::$recordedContractResults[$key] = [
            'key' => $key,
            'group' => $def['group'] ?? 'unknown',
            'query_class' => $def['query'],
            'rows_count' => count($result->rows),
            'checked_columns' => $checkedColumns,
            'column_types' => $columnTypes,
            'defaults' => $def['defaults'] ?? [],
            'csv_status' => 200,
            'csv_bytes' => strlen($csv),
            'status' => 'PASS',
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createMemberWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

        $roleName = 'TestRole_'.Str::random(8);
        $role = Role::create([
            'company_id' => $this->company->id,
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->activateUser($user);

        return $user;
    }

    private function seedComprehensiveHistory(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->activateUser($this->owner);

        $unitId = (int) $this->unit->unit_id;

        // 1. Categories
        $this->mainCategory = ProductCategory::create([
            'company_id' => $this->company->id,
            'name_ar' => 'فئة رئيسية',
            'name_en' => 'Main Category',
        ]);

        $this->product->category_id = $this->mainCategory->id;
        $this->product->default_sale_price_base = '100.000000';
        $this->product->save();

        // 2. Dest Warehouse
        $this->branchWarehouse = Warehouse::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'BR-WH'],
            ['name_ar' => 'مستودع فرعي', 'name_en' => 'Branch Warehouse', 'active' => true, 'created_by' => $this->owner->id]
        );

        // 3. Products
        $this->expiryProduct = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صنف ذو صلاحية',
            'name_en' => 'Expiry Product',
            'sku' => 'SKU-EXP',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => true,
            'base_unit_id' => $unitId,
            'category_id' => $this->mainCategory->id,
            'default_sale_price_base' => '25.000000',
        ], (int) $this->owner->id);

        $this->lowStockProduct = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صنف منخفض الرصيد',
            'name_en' => 'Low Stock Product',
            'sku' => 'SKU-LOW',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'minimum_stock_base' => '50.000000',
            'base_unit_id' => $unitId,
            'category_id' => $this->mainCategory->id,
            'default_sale_price_base' => '30.000000',
        ], (int) $this->owner->id);

        // 4. Stock & Movements
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of('100.000000'),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'hist-open-wh1',
            movementDate: '2026-10-01',
        );

        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->branchWarehouse,
            quantity: Quantity::of('40.000000'),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'hist-open-wh2',
            movementDate: '2026-10-01',
        );

        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->expiryProduct,
            warehouse: $this->warehouse,
            quantity: Quantity::of('20.000000'),
            unitCostBase: '15.000000',
            user: $this->owner,
            idempotencyKey: 'hist-open-exp',
            lotNumber: 'LOT-2026-001',
            expiryDate: '2026-12-31',
            movementDate: '2026-10-01',
        );

        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->lowStockProduct,
            warehouse: $this->warehouse,
            quantity: Quantity::of('5.000000'),
            unitCostBase: '20.000000',
            user: $this->owner,
            idempotencyKey: 'hist-open-low',
            movementDate: '2026-10-01',
        );

        app(InventoryMovementService::class)->transfer(new StockTransferCommand(
            companyId: (int) $this->company->id,
            sourceWarehouseId: (int) $this->warehouse->id,
            destinationWarehouseId: (int) $this->branchWarehouse->id,
            movementDate: '2026-10-08',
            lines: [new StockTransferLineCommand((int) $this->product->id, Quantity::of('15.000000'))],
            idempotencyKey: 'hist-transfer',
            createdBy: (int) $this->owner->id,
        ));

        app(AdjustStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            type: StockMovement::TYPE_DAMAGE_OR_LOSS,
            quantity: Quantity::of('1.000000'),
            reason: 'Damaged item',
            user: $this->owner,
            idempotencyKey: 'hist-loss',
            movementDate: '2026-10-10',
        );

        // 5. Sales & Invoices
        $inv1 = $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-02',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'بند عادي',
                    'quantity' => '5.000000',
                    'unit_price' => '100.000000',
                ],
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'بند مخفض',
                    'quantity' => '2.000000',
                    'unit_price' => '100.000000',
                    'discount_type' => 'fixed',
                    'discount_value' => '10.000000',
                ],
            ],
        ]);

        $returnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->defaultCustomer->id,
            'sales_invoice_id' => $inv1->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-10-04',
            'lines' => [
                [
                    'sales_invoice_line_id' => $inv1->lines->first()->id,
                    'quantity' => '1.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);
        app(PostSalesReturnAction::class)->execute($returnDraft, $this->owner);

        app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->defaultCustomer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-06',
            'payment_method' => 'cash',
            'amount' => '300.000000',
            'exchange_rate' => '1.000000',
            'idempotency_key' => 'hist-cust-pay',
            'allocations' => [
                [
                    'sales_invoice_id' => $inv1->id,
                    'allocated_amount' => '300.000000',
                    'payment_currency_amount' => '300.000000',
                ],
            ],
        ]);

        // Overdue unpaid invoice
        $this->createAndPostSalesInvoice([
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-15',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'بند متأخر السداد',
                    'quantity' => '3.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);

        // Voided invoice
        $invVoid = $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-05',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'بند ملغى',
                    'quantity' => '1.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);
        app(VoidSalesInvoiceAction::class)->execute($invVoid, $this->owner, 'Reversal test', '2026-10-15');

        // 6. Purchasing
        $landed = app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->landedCategory->id,
            'expense_date' => '2026-10-01',
            'classification' => 'landed_cost',
            'description' => 'تكاليف شحن إضافية',
            'currency_code' => 'ILS',
            'amount' => '50.000000',
            'exchange_rate' => '1.000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->ilsCashAccount->id,
            'idempotency_key' => 'hist-landed',
        ]);

        $purDraft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-02',
            'due_date' => '2026-10-30',
            'currency_code' => 'USD',
            'exchange_rate' => '3.500000',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '20.000000',
                    'unit_cost' => '10.000000',
                ],
            ],
        ]);
        app(AllocateLandedCostAction::class)->execute($purDraft, $landed, 'value', $this->owner);
        $purchase = app(PostPurchaseAction::class)->execute($purDraft, $this->owner);

        $purReturnDraft = app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, [
            'purchase_id' => $purchase->id,
            'return_date' => '2026-10-05',
            'currency_code' => 'USD',
            'exchange_rate' => '3.500000',
            'lines' => [
                [
                    'purchase_line_id' => $purchase->lines->first()->id,
                    'quantity' => '2.000000',
                ],
            ],
        ]);
        app(PostPurchaseReturnAction::class)->execute($purReturnDraft, $this->owner);

        app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-06',
            'payment_method' => 'cash',
            'amount' => '70.000000',
            'exchange_rate' => '1.000000',
            'idempotency_key' => 'hist-vnd-pay',
            'allocations' => [
                [
                    'purchase_id' => $purchase->id,
                    'allocated_amount' => '20.000000',
                    'payment_currency_amount' => '70.000000',
                ],
            ],
        ]);

        // Overdue purchase
        $overduePurDraft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-09-01',
            'due_date' => '2026-09-15',
            'currency_code' => 'USD',
            'exchange_rate' => '3.500000',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10.000000',
                    'unit_cost' => '10.000000',
                ],
            ],
        ]);
        app(PostPurchaseAction::class)->execute($overduePurDraft, $this->owner);

        // 7. Money Transfers & Checks
        app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, [
            'from_money_account_id' => $this->usdCashAccount->id,
            'to_money_account_id' => $this->usdBankAccount->id,
            'transfer_date' => '2026-10-07',
            'from_amount' => '20.000000',
            'to_amount' => '20.000000',
            'from_exchange_rate' => '3.500000',
            'to_exchange_rate' => '3.500000',
            'idempotency_key' => 'hist-transfer-usd',
        ]);

        $cross = app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, [
            'from_money_account_id' => $this->usdCashAccount->id,
            'to_money_account_id' => $this->ilsCashAccount->id,
            'transfer_date' => '2026-10-07',
            'from_amount' => '10.00',
            'to_amount' => '34.00',
            'from_exchange_rate' => '3.50',
            'to_exchange_rate' => '1',
            'idempotency_key' => 'c01-cross-transfer',
        ]);
        app(ReverseMoneyTransferAction::class)->execute($cross, $this->owner, '2026-10-08', 'Contract reversal');

        // Incoming Check 1: Deposited and Cleared
        $chk1 = $this->check('incoming');
        $this->event($chk1, 'deposit', '3.50', '2026-10-03');
        $this->event($chk1->fresh(), 'clear', '3.50', '2026-10-04');

        // Incoming Check 2: Returned
        $chk2 = $this->check('incoming');
        $this->event($chk2, 'deposit', '3.50', '2026-10-03');
        $this->event($chk2->fresh(), 'return', '3.50', '2026-10-05');

        // Incoming Check 3: Due in safe
        $chk3Data = array_merge($this->checkIntent('incoming'), [
            'check_number' => 'CHK-DUE-2026',
            'due_date' => '2026-10-25',
            'idempotency_key' => 'chk-due-intent',
        ]);
        app(ReceiveCheckAction::class)->execute($this->company, $this->owner, $chk3Data);

        // Outgoing Check: Cleared
        $chkOut = $this->check('outgoing');
        $this->event($chkOut, 'clear', '3.50', '2026-10-04');

        // 8. Expenses
        $fuelCat = ExpenseCategory::where('company_id', $this->company->id)->where('code', 'fuel')->firstOrFail();
        $deliveryCat = ExpenseCategory::where('company_id', $this->company->id)->where('code', 'delivery')->firstOrFail();
        $transportCat = ExpenseCategory::where('company_id', $this->company->id)->where('code', 'transport')->firstOrFail();

        app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->operatingCategory->id,
            'expense_date' => '2026-10-01',
            'description' => 'مصروف كهرباء تشغيلي',
            'currency_code' => 'ILS',
            'amount' => '100.000000',
            'exchange_rate' => '1.000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->ilsCashAccount->id,
            'idempotency_key' => 'hist-op-exp',
        ]);

        app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $fuelCat->id,
            'expense_date' => '2026-10-05',
            'description' => 'وقود شاحنة',
            'currency_code' => 'ILS',
            'amount' => '150.000000',
            'exchange_rate' => '1.000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->ilsCashAccount->id,
            'idempotency_key' => 'hist-fuel-exp',
        ]);

        app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $deliveryCat->id,
            'expense_date' => '2026-10-12',
            'description' => 'رسوم توصيل',
            'currency_code' => 'USD',
            'amount' => '50.000000',
            'exchange_rate' => '3.500000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id,
            'idempotency_key' => 'hist-del-exp',
        ]);

        app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $transportCat->id,
            'expense_date' => '2026-10-08',
            'description' => 'نقل بضائع',
            'currency_code' => 'ILS',
            'amount' => '200.000000',
            'exchange_rate' => '1.000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->ilsCashAccount->id,
            'idempotency_key' => 'hist-trans-exp',
        ]);

        $revExpense = app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->operatingCategory->id,
            'expense_date' => '2026-10-02',
            'description' => 'مصروف سيتم إلغاؤه',
            'currency_code' => 'ILS',
            'amount' => '40.000000',
            'exchange_rate' => '1.000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->ilsCashAccount->id,
            'idempotency_key' => 'hist-rev-exp',
        ]);
        app(ReverseExpenseAction::class)->execute($revExpense, $this->owner, 'Reversal test', '2026-10-10');

        // 9. Payroll
        $advance = app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'advance_date' => '2026-10-01',
            'currency_code' => 'USD',
            'amount' => '50.000000',
            'exchange_rate' => '3.500000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id,
            'idempotency_key' => 'hist-emp-adv',
        ]);

        $salary = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'recognition_date' => '2026-10-08',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency_code' => 'USD',
            'exchange_rate' => '3.500000',
            'base_salary' => '200.000000',
            'bonus' => '0.000000',
            'deduction' => '0.000000',
            'advances' => [
                [
                    'advance_id' => $advance->id,
                    'allocated_amount' => '20.000000',
                ],
            ],
            'idempotency_key' => 'hist-emp-sal',
        ]);

        app(PostSalaryPaymentAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'payment_date' => '2026-10-09',
            'currency_code' => 'USD',
            'amount' => '100.000000',
            'exchange_rate' => '3.500000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id,
            'allocations' => [
                [
                    'salary_entry_id' => $salary->id,
                    'allocated_amount' => '100.000000',
                ],
            ],
            'idempotency_key' => 'hist-sal-pay',
        ]);

        $emp2 = Employee::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => (int) $this->company->id,
            'code' => 'EMP-002',
            'name' => 'خالد الموظف',
            'job_title' => 'مساعد',
            'hire_date' => '2026-01-01',
            'default_salary' => '800.000000',
            'salary_currency_code' => 'USD',
            'active' => true,
            'created_by' => (int) $this->owner->id,
        ]);

        // Unpaid salary entry for payroll.unpaid
        app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $emp2->id,
            'recognition_date' => '2026-10-12',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency_code' => 'USD',
            'exchange_rate' => '3.500000',
            'base_salary' => '300.000000',
            'bonus' => '0.000000',
            'deduction' => '0.000000',
            'advances' => [],
            'idempotency_key' => 'hist-sal-unpaid',
        ]);
    }
}
