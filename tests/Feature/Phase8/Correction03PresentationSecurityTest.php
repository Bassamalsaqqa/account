<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Reporting\EnsureReportingFoundationAction;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Presentation\ReportPresentationPolicy;
use App\Application\Reporting\Presentation\ReportPresenter;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Application\Reporting\Queries\SalesSummaryReportQuery;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\CsvReportDelivery;
use App\Http\Controllers\ReportCsvController;
use App\Livewire\Pages\Reporting\ReportView;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\DisposableMariaDbSchema;

final class Correction03PresentationSecurityTest extends Phase8TestCase
{
    private function reader(array $permissions): array
    {
        $reader = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $reader->id, 'status' => 'active', 'is_owner' => false]);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'C03-'.bin2hex(random_bytes(4)), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $reader->assignRole($role);
        $this->activateUser($reader);

        return [$reader, $role];
    }

    public function test_all_expense_aliases_and_sales_options_are_capability_aware_in_both_locales(): void
    {
        [$reader, $role] = $this->reader(['reports.sales.view', 'sales.invoice.view', 'reports.expenses.view', 'money.expense.view']);
        $registry = app(ReportRegistry::class);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ($registry->all() as $key => $definition) {
                if ($definition['group'] !== 'expenses' && ! in_array($key, ['sales.by-product', 'sales.by-category'], true)) {
                    continue;
                }
                $component = Livewire::test(ReportView::class, ['reportKey' => $key]);
                $safe = $component->viewData('definition');
                $this->assertNotContains('profit_desc', $safe['options']['sort'] ?? [], $key);
                $this->assertNotContains('landed_cost', $safe['options']['status'] ?? [], $key);
                $component->assertDontSeeHtml('value="profit_desc"')->assertDontSeeHtml('value="landed_cost"');
            }
        }
        $role->givePermissionTo(['reports.cost.view', 'reports.profit.view', 'inventory.cost.view', 'purchasing.cost.view']);
        $page = Livewire::test(ReportView::class, ['reportKey' => 'sales.by-product'])->assertSeeHtml('value="profit_desc"');
        $role->revokePermissionTo('reports.profit.view');
        $page->call('$refresh')->assertDontSeeHtml('value="profit_desc"');
        $page->set('filters.sort', 'profit_desc')->call('applyFilters')->assertHasErrors('filters');
        $role->revokePermissionTo('purchasing.cost.view');
        Livewire::test(ReportView::class, ['reportKey' => 'expenses.summary'])->set('filters.status', 'landed_cost')->assertForbidden();
    }

    public function test_columns_are_authorized_for_empty_populated_and_out_of_range_ui_and_csv(): void
    {
        $this->invoice('ILS', '1', '100');
        [$reader, $role] = $this->reader(['reports.sales.view', 'sales.invoice.view']);
        $registry = app(ReportRegistry::class);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach (['sales.by-product', 'sales.by-category'] as $key) {
                foreach ([['from' => '2020-01-01', 'to' => '2020-01-31'], ['from' => '2026-10-01', 'to' => '2026-10-31'], ['from' => '2026-10-01', 'to' => '2026-10-31', 'page' => 20]] as $filters) {
                    $page = Livewire::withQueryParams(['filters' => $filters])->test(ReportView::class, ['reportKey' => $key]);
                    foreach (['columns' => $page->viewData('columns'), 'definition' => $page->viewData('definition')['columns']] as $columns) {
                        foreach (['cogs_base', 'gross_profit_base', 'gross_margin'] as $field) {
                            $this->assertNotContains($field, array_column($columns, 'key'));
                        }
                    }
                    $csv = $this->get(route('reports.export', ['reportKey' => $key, 'filters' => $filters]))->assertOk()->streamedContent();
                    foreach (['cogs_base', 'gross_profit_base', 'gross_margin'] as $field) {
                        $this->assertStringNotContainsString(__('reports.columns.'.$field), $csv);
                    }
                }
            }
        }
        $role->givePermissionTo(['reports.cost.view', 'reports.profit.view', 'inventory.cost.view']);
        $empty = new ReportResult('sales.by_product', [], [], [], ['base_currency_code' => 'ILS']);
        $columns = app(ReportPresenter::class)->columns($registry->definition('sales.by-product')['columns'], $empty);
        $this->assertContains('cogs_base', array_column($columns, 'key'));
        $csv = $this->get(route('reports.export', ['reportKey' => 'sales.by-product', 'filters' => ['from' => '2020-01-01', 'to' => '2020-01-31']]))->assertOk()->streamedContent();
        $this->assertStringContainsString(__('reports.columns.cogs_base'), $csv);
    }

    public function test_inventory_financial_columns_are_permission_based_even_without_rows(): void
    {
        [$reader, $role] = $this->reader(['reports.inventory.view', 'inventory.stock.view', 'purchasing.purchase.view']);
        $registry = app(ReportRegistry::class);
        foreach (['inventory.stock', 'inventory.by-warehouse', 'inventory.movements', 'inventory.transfers', 'inventory.adjustments', 'inventory.vendor-products'] as $key) {
            $definition = $registry->definition($key);
            $result = new ReportResult($key, [], [], [], ['base_currency_code' => 'ILS']);
            $columns = app(ReportPresenter::class)->columns($definition['columns'], $result);
            foreach (['value_base', 'average_unit_cost_base', 'unit_cost_base', 'value_delta_base', 'total_spend_base'] as $field) {
                $this->assertNotContains($field, array_column($columns, 'key'));
            }
            $safe = app(ReportPresentationPolicy::class)->definition($this->company, $definition);
            $this->assertSame(array_column($columns, 'key'), array_column($safe['columns'], 'key'));
        }
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $page = Livewire::withQueryParams(['filters' => ['from' => '2020-01-01', 'to' => '2020-01-31']])->test(ReportView::class, ['reportKey' => 'inventory.vendor-products']);
            $this->assertNotContains('total_spend_base', array_column($page->viewData('columns'), 'key'));
            $csv = $this->get(route('reports.export', ['reportKey' => 'inventory.vendor-products', 'filters' => ['from' => '2020-01-01', 'to' => '2020-01-31']]))->assertOk()->streamedContent();
            $this->assertStringNotContainsString(__('reports.columns.total_spend_base'), $csv);
        }
        $role->givePermissionTo(['inventory.cost.view', 'reports.cost.view']);
        $columns = app(ReportPresenter::class)->columns($registry->definition('inventory.stock')['columns'], new ReportResult('inventory.stock', [], [], [], ['base_currency_code' => 'ILS']));
        $this->assertContains('value_base', array_column($columns, 'key'));
        $supplier = $registry->definition('inventory.vendor-products')['columns'];
        $emptySupplier = new ReportResult('inventory.vendor_products', [], [], [], ['base_currency_code' => 'ILS']);
        $this->assertNotContains('total_spend_base', array_column(app(ReportPresenter::class)->columns($supplier, $emptySupplier), 'key'));
        $role->givePermissionTo('purchasing.cost.view');
        $this->assertContains('total_spend_base', array_column(app(ReportPresenter::class)->columns($supplier, $emptySupplier), 'key'));
        $role->revokePermissionTo('reports.cost.view');
        $columns = app(ReportPresenter::class)->columns($registry->definition('inventory.stock')['columns'], new ReportResult('inventory.stock', [], [], [], ['base_currency_code' => 'ILS']));
        $this->assertNotContains('value_base', array_column($columns, 'key'));
    }

    public function test_extreme_livewire_page_is_a_controlled_error(): void
    {
        Livewire::test(ReportView::class, ['reportKey' => 'sales.unpaid'])->call('goToPage', PHP_INT_MAX)->assertHasErrors('filters');
        $this->get(route('reports.show', ['reportKey' => 'sales.unpaid', 'filters' => ['page' => PHP_INT_MAX]]))->assertOk();
        $this->get(route('reports.export', ['reportKey' => 'sales.unpaid', 'filters' => ['page' => PHP_INT_MAX]]))->assertStatus(422); // malformed/deep requests fail before export preparation
    }

    public function test_hub_does_not_evict_global_database_permission_cache_and_live_role_changes_are_seen(): void
    {
        [$reader, $role] = $this->reader(['reports.sales.view', 'sales.invoice.view', 'reports.expenses.view', 'money.expense.view']);
        config(['permission.cache.store' => 'database']);
        $registrar = app(PermissionRegistrar::class);
        $registrar->initializeCache();
        $registrar->forgetCachedPermissions();
        $reader->hasPermissionTo('reports.sales.view'); // deliberate cold-catalogue warmup, outside measured guard path
        DB::enableQueryLog();
        $visible = app(ReportRegistry::class)->visible($this->company);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertArrayHasKey('sales.summary', $visible);
        $this->assertCount(69, app(ReportRegistry::class)->keys());
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/(?:delete from|insert into|update) [`"]?cache\b/i', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/select \* from [`"]?permissions[`"]?\s*(?:$|order)/i', $query['query']);
        }
        $this->assertLessThan(1000, count($queries));
        // Raw assignment revocation intentionally leaves the global catalogue stale.
        DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', DB::table('permissions')->where('name', 'reports.sales.view')->value('id'))->delete();
        $this->assertFalse(app(ReportingGuard::class)->allows($this->company, 'reports.sales.view'));
        $this->assertArrayNotHasKey('sales.summary', app(ReportRegistry::class)->visible($this->company));
        $this->activateUser($this->owner);
        $this->assertTrue(app(ReportingGuard::class)->allows($this->company, 'reports.sales.view'));
    }

    public function test_cold_database_catalogue_is_warmed_before_source_guards_enter_read_only_csv_snapshot(): void
    {
        $schema = DisposableMariaDbSchema::createFromSource(config('database.connections.mysql.database'));
        $previousStore = config('permission.cache.store');
        $schema->switchLaravelConnection();
        try {
            app(CompanyContext::class)->clear();
            $owner = User::factory()->create();
            $company = app(CreateCompanyAction::class)->execute($owner, ['name_ar' => 'Cold cache export', 'base_currency_code' => 'ILS']);
            app(EnsureReportingFoundationAction::class)->execute($company);
            app(CompanyContext::class)->setCompany($company, $owner);
            $this->actingAs($owner);
            config(['permission.cache.store' => 'database']);
            $registrar = app(PermissionRegistrar::class);
            $registrar->initializeCache();
            $registrar->forgetCachedPermissions();
            DB::listen(function ($q): void {
                if ($q->connection->transactionLevel() > 0) {
                    $this->assertDoesNotMatchRegularExpression('/(?:delete from|insert into|update) [`"]?cache\b/i', $q->sql);
                }
            });
            $response = app(ReportCsvController::class)(Request::create('/reports/export', 'GET', ['filters' => ['from' => '2026-10-01', 'to' => '2026-10-31']]), 'money.checks');
            ob_start();
            try {
                $response->sendContent();
                $csv = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            $schema->drop();
            config(['permission.cache.store' => $previousStore]);
            app(PermissionRegistrar::class)->initializeCache();
            app(PermissionRegistrar::class)->clearPermissionsCollection();
        }
    }

    public function test_permissions_are_isolated_across_company_teams(): void
    {
        [$reader] = $this->reader(['reports.sales.view', 'sales.invoice.view']);
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create();
        $other = app(CreateCompanyAction::class)->execute($otherOwner, ['name_ar' => 'Ø´Ø±ÙƒØ© Ø£Ø®Ø±Ù‰', 'base_currency_code' => 'ILS']);
        app(EnsureReportingFoundationAction::class)->execute($other);
        $other->memberships()->create(['user_id' => $reader->id, 'status' => 'active', 'is_owner' => false]);
        app(CompanyContext::class)->setCompany($other, $reader);
        $this->actingAs($reader);
        $this->assertFalse(app(ReportingGuard::class)->allows($other, 'reports.sales.view'));
        $this->activateUser($reader);
        $this->assertTrue(app(ReportingGuard::class)->allows($this->company, 'reports.sales.view'));
    }

    public function test_delivery_checks_each_chunk_and_closes_spool_after_live_revocation(): void
    {
        foreach (['membership', 'permission', 'before'] as $mode) {
            $schema = DisposableMariaDbSchema::create();
            try {
                $pdo = $schema->createSeparatePdo();
                $pdo->exec('CREATE TABLE authority (id INT PRIMARY KEY, membership INT, permission INT)');
                $pdo->exec('INSERT INTO authority VALUES (1,1,1)');
                $second = $schema->createSeparatePdo();
                $spool = tmpfile();
                fwrite($spool, str_repeat('A', CsvReportDelivery::CHUNK_BYTES * 3));
                rewind($spool);
                $emitted = '';
                $checks = 0;
                if ($mode === 'before') {
                    $second->exec('UPDATE authority SET membership=0');
                }
                app(CsvReportDelivery::class)->send($spool, function () use ($pdo, &$checks): bool {
                    $checks++;
                    $row = $pdo->query('SELECT membership,permission FROM authority WHERE id=1')->fetch(\PDO::FETCH_ASSOC);

                    return (int) $row['membership'] === 1 && (int) $row['permission'] === 1;
                }, function (string $chunk) use ($second, $mode, &$emitted): void {
                    $emitted .= $chunk;
                    $second->exec('UPDATE authority SET '.$mode.'=0');
                });
                $this->assertSame($mode === 'before' ? 0 : CsvReportDelivery::CHUNK_BYTES, strlen($emitted));
                $this->assertSame($mode === 'before' ? 1 : 2, $checks);
                $this->assertFalse(is_resource($spool));
            } finally {
                $schema->drop();
            }
        }
        $spool = tmpfile();
        fwrite($spool, 'secret');
        rewind($spool);
        try {
            app(CsvReportDelivery::class)->send($spool, static function (): bool {
                throw new \RuntimeException('Failed authority read');
            });
            $this->fail('Unexpected error suppressed');
        } catch (\RuntimeException $e) {
            $this->assertSame('Failed authority read', $e->getMessage());
        }
        $this->assertFalse(is_resource($spool));
    }

    public function test_actual_csv_response_stops_at_next_chunk_without_claiming_a_late_403(): void
    {
        foreach (['before', 'membership', 'permission'] as $mode) {
            $schema = DisposableMariaDbSchema::createFromSource(config('database.connections.mysql.database'));
            $schema->switchLaravelConnection();
            try {
                app(CompanyContext::class)->clear();
                $owner = User::factory()->create();
                $company = app(CreateCompanyAction::class)->execute($owner, ['name_ar' => 'Delivery test', 'base_currency_code' => 'ILS']);
                app(EnsureReportingFoundationAction::class)->execute($company);
                $reader = User::factory()->create();
                $company->memberships()->create(['user_id' => $reader->id, 'status' => 'active', 'is_owner' => false]);
                setPermissionsTeamId($company->id);
                $role = Role::create(['company_id' => $company->id, 'name' => 'Deliver', 'guard_name' => 'web']);
                $role->givePermissionTo(['reports.sales.view', 'sales.invoice.view', 'reports.cost.view']);
                $reader->assignRole($role);
                app(CompanyContext::class)->setCompany($company, $reader);
                $this->actingAs($reader);
                app()->instance(SalesSummaryReportQuery::class, new class
                {
                    public function execute(Company $company, array $input): ReportResult
                    {
                        app(ReportingGuard::class)->authorize($company, null, ['reports.sales.view', 'sales.invoice.view']);

                        return new ReportResult('sales.summary', $input, [], array_fill(0, 100, ['document_number' => str_repeat('x', 1800), 'business_date' => '2026-10-01', 'currency_code' => 'ILS', 'gross_sales_base' => '1', 'net_sales_base' => '1', 'returns_base' => '0', 'tax_base' => '0']), ['base_currency_code' => 'ILS']);
                    }
                });
                $response = app(ReportCsvController::class)(Request::create('/reports/export', 'GET', ['filters' => ['from' => '2026-10-01', 'to' => '2026-10-31']]), 'sales.summary');
                $callback = (new \ReflectionFunction($response->getCallback()))->getStaticVariables()['callback'];
                $spool = (new \ReflectionFunction($callback))->getStaticVariables()['spool'];
                $pdo = $schema->createSeparatePdo();
                $revoke = static function () use ($pdo, $company, $reader, $role, $mode): void {
                    if ($mode === 'permission') {
                        $pdo->exec("DELETE FROM role_has_permissions WHERE role_id = {$role->id} AND permission_id = (SELECT id FROM permissions WHERE name = 'reports.cost.view')");
                    } else {
                        $pdo->exec("UPDATE company_user SET status='suspended' WHERE company_id={$company->id} AND user_id={$reader->id}");
                    }
                };
                if ($mode === 'before') {
                    $revoke();
                }
                $emitted = '';
                ob_start(static function (string $chunk) use (&$emitted, $revoke): string {
                    $emitted .= $chunk;
                    if ($chunk !== '') {
                        $revoke();
                    }

                    return '';
                }, CsvReportDelivery::CHUNK_BYTES);
                try {
                    $response->sendContent();
                } finally {
                    ob_end_clean();
                }
                $this->assertSame($mode === 'before' ? 0 : CsvReportDelivery::CHUNK_BYTES, strlen($emitted));
                $this->assertSame(200, $response->getStatusCode());
                $this->assertFalse(is_resource($spool));
                $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            } finally {
                $schema->drop();
            }
        }
    }

    public function test_disposable_auxiliary_preserves_parent_transaction_and_real_foreign_keys(): void
    {
        $parent = DB::connection();
        $pdo = $parent->getPdo();
        $level = $parent->transactionLevel();
        $originalCompany = $this->company->id;
        $schema = DisposableMariaDbSchema::createFromSource($parent->getDatabaseName());
        try {
            $schema->switchLaravelConnection();
            $this->assertNotSame($pdo, DB::connection()->getPdo());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertGreaterThan(0, (int) DB::scalar('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=?', [$schema->schemaName()]));
        } finally {
            $schema->drop();
        }
        $this->assertSame($pdo, DB::connection()->getPdo());
        $this->assertSame($level, DB::transactionLevel());
        $this->assertTrue(DB::table('companies')->where('id', $originalCompany)->exists());
        $this->assertSame($originalCompany, app(CompanyContext::class)->companyId());
        $this->assertSame($this->owner->id, auth()->id());
    }
}
