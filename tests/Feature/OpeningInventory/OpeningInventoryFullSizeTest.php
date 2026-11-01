<?php

declare(strict_types=1);

namespace Tests\Feature\OpeningInventory;

use App\Actions\Company\CreateCompanyAction;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\OpeningInventoryImport;
use App\Services\Inventory\OpeningInventoryWorkbook;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\OpeningInventoryWorkbookFixture;
use Tests\TestCase;
use ZipArchive;

final class OpeningInventoryFullSizeTest extends TestCase
{
    public function test_full_size_synthetic_stocktake_preserves_all_rows_and_other_company_and_renders_both_locales(): void
    {
        $schema = DisposableMariaDbSchema::create();
        $file = null;
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $actor = User::factory()->create(['name' => 'Synthetic Stock Operator']);
            $company = app(CreateCompanyAction::class)->execute($actor, ['name_ar' => 'شركة الجرد الاصطناعي', 'base_currency_code' => 'ILS']);
            $context = app(CompanyContext::class);
            $context->clear();
            $otherActor = User::factory()->create();
            $other = app(CreateCompanyAction::class)->execute($otherActor, ['name_ar' => 'شركة مستقلة اصطناعية', 'base_currency_code' => 'ILS']);
            $import = app(OpeningInventoryImport::class);
            $otherBaseline = $import->baseline($other);
            $this->actingAs($actor);
            $context->setCompany($company, $actor);
            $unit = Unit::where('company_id', $company->id)->where('code', 'piece')->firstOrFail();
            $warehouse = Warehouse::where('company_id', $company->id)->firstOrFail();
            $file = OpeningInventoryWorkbookFixture::create();
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($file));
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertIsString($sheet);
            $this->assertSame(1, preg_match('/<row r="1">.*?<\/row>/s', $sheet, $header));
            $rows = '';
            // Deliberately invented names, codes and distribution; no real customer rows.
            for ($i = 0; $i < 53; $i++) {
                $n = $i + 2;
                $group = $i < 6 ? intdiv($i, 2) : $i - 3;
                $qty = $i === 52 ? '3' : '10';
                $cost = $i === 52 ? '17.25' : ($i === 51 ? '111' : '11');
                $code = sprintf('%014d', $group + 1);
                $expiry = $i >= 48 ? '' : (string) (47000 + $i);
                $rows .= '<row r="'.$n.'"><c r="A'.$n.'" t="inlineStr"><is><t>Synthetic stock '.$group.'</t></is></c><c r="B'.$n.'" t="inlineStr"><is><t>'.$code.'</t></is></c>'
                    .'<c r="C'.$n.'"><v>'.$qty.'</v></c><c r="D'.$n.'"><v>'.$cost.'</v></c><c r="E'.$n.'"><v>1500</v></c><c r="F'.$n.'" s="1"><v>'.$expiry.'</v></c></row>';
            }
            $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$header[0].$rows.'</sheetData></worksheet>');
            $zip->close();
            $sha = hash_file('sha256', $file);
            $source = app(OpeningInventoryWorkbook::class)->read($file, $sha);
            $this->assertCount(53, $source['rows']);
            $this->assertSame('523.000000', $source['quantity']);
            $this->assertSame('6771.750000', $source['value']);
            $plan = ['version' => 1, 'source_sha256' => $sha, 'rows_sha256' => $source['rows_sha256'], 'company_public_id' => $company->public_id,
                'actor_id' => $actor->id, 'owner_id' => $actor->id, 'owner_name' => $actor->name, 'source_provider' => 'Synthetic Counter',
                'baseline_sha256' => $import->baseline($company), 'currency' => 'ILS', 'stock_count_date' => '2026-10-20', 'movement_date' => '2026-10-20',
                'owned_stock_confirmed' => true, 'complete_count_confirmed' => true, 'not_previously_posted_confirmed' => true, 'no_payable_confirmed' => true,
                'prices_per_base_unit_confirmed' => true, 'code_kind' => 'barcode', 'uncoded_sku_policy' => 'leave_null',
                'identity_resolution' => 'Three synthetic same-code product pairs; other rows distinct. Five explicitly unknown expiry rows.', 'rows' => []];
            foreach ($source['rows'] as $row) {
                $plan['rows'][] = ['source_row' => $row['source_row'], 'exclude' => false, 'exclusion_reason' => null, 'product_key' => $row['code'],
                    'existing_product_public_id' => null, 'unit_public_id' => $unit->public_id, 'warehouse_public_id' => $warehouse->public_id,
                    'expiry_date' => $row['expiry_date'], 'unknown_expiry_confirmed' => $row['expiry_date'] === null, 'zero_cost_confirmed' => false];
            }
            $beforeAudit = DB::table('audit_events')->count();
            $dry = $import->run($file, $sha, $plan, $import->hash($plan), $company, $actor);
            $this->assertSame('DRY_RUN', $dry['status']);
            $this->assertCount(53, $dry['result']['rows']);
            $this->assertSame($beforeAudit, DB::table('audit_events')->count());
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });
            $start = hrtime(true);
            $result = $import->run($file, $sha, $plan, $import->hash($plan), $company, $actor, true);
            $elapsed = hrtime(true) - $start;
            fwrite(STDOUT, PHP_EOL.'Synthetic full-size import: '.json_encode(['elapsed_nanoseconds' => $elapsed, 'sql_queries' => $queries, 'rows' => 53, 'products' => 50], JSON_THROW_ON_ERROR).PHP_EOL);
            $this->assertSame('APPLIED', $result['status']);
            $this->assertCount(53, $result['result']['rows']);
            $this->assertSame('6771.750000', $result['result']['value_base']);
            $this->assertDatabaseCount('products', 50);
            $this->assertDatabaseCount('product_units', 50);
            $this->assertDatabaseCount('inventory_lots', 53);
            $this->assertDatabaseCount('stock_movements', 53);
            $this->assertDatabaseCount('posting_batches', 53);
            $this->assertSame(5, DB::table('inventory_lots')->whereNull('expiry_date')->count());
            $this->assertSame($otherBaseline, $import->baseline($other));
            foreach (['en', 'ar'] as $locale) {
                app()->setLocale($locale);
                foreach (Product::all() as $product) {
                    $this->assertStringStartsWith('Synthetic stock ', $product->name());
                }
                $this->withSession(['active_company_id' => $company->id, 'locale' => $locale]);
                $this->get(route('products.index'))->assertOk()->assertSee('Synthetic stock');
                $this->get(route('inventory.overview'))->assertOk();
                $this->get(route('inventory.expiry'))->assertOk();
            }
            $retry = $import->run($file, $sha, $plan, $import->hash($plan), $company, $actor, true);
            $this->assertSame('ALREADY_APPLIED', $retry['status']);
            $this->assertDatabaseCount('stock_movements', 53);
            $this->assertSame($otherBaseline, $import->baseline($other));
        } finally {
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
            $schema->drop();
        }
    }
}
