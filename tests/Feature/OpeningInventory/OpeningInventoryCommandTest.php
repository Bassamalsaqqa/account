<?php

declare(strict_types=1);

namespace Tests\Feature\OpeningInventory;

use App\Actions\Company\CreateCompanyAction;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\OpeningInventoryImport;
use App\Services\Inventory\OpeningInventoryWorkbook;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\OpeningInventoryWorkbookFixture;
use Tests\TestCase;

final class OpeningInventoryCommandTest extends TestCase
{
    public function test_interactive_dry_run_authenticates_without_rehash_or_session_audit_or_inventory_writes(): void
    {
        $schema = DisposableMariaDbSchema::create();
        $file = $planFile = null;
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $actor = User::factory()->create(['name' => 'Synthetic CLI Operator']);
            $company = app(CreateCompanyAction::class)->execute($actor, ['name_ar' => 'شركة اصطناعية', 'base_currency_code' => 'ILS']);
            $context = app(CompanyContext::class);
            $context->setCompany($company, $actor);
            $unit = Unit::where('code', 'piece')->firstOrFail();
            $warehouse = Warehouse::firstOrFail();
            $file = OpeningInventoryWorkbookFixture::create();
            $sha = hash_file('sha256', $file);
            $source = app(OpeningInventoryWorkbook::class)->read($file, $sha);
            $import = app(OpeningInventoryImport::class);
            $plan = ['version' => 1, 'source_sha256' => $sha, 'rows_sha256' => $source['rows_sha256'], 'company_public_id' => $company->public_id,
                'actor_id' => $actor->id, 'owner_id' => $actor->id, 'owner_name' => $actor->name, 'source_provider' => 'Synthetic Counter',
                'baseline_sha256' => $import->baseline($company), 'currency' => 'ILS', 'stock_count_date' => '2026-10-20', 'movement_date' => '2026-10-20',
                'code_kind' => 'barcode', 'uncoded_sku_policy' => 'leave_null', 'identity_resolution' => 'Synthetic serum pair shares one barcode; vial is separate.', 'rows' => []];
            foreach (['owned_stock_confirmed', 'complete_count_confirmed', 'not_previously_posted_confirmed', 'no_payable_confirmed', 'prices_per_base_unit_confirmed'] as $field) {
                $plan[$field] = true;
            }
            foreach ($source['rows'] as $row) {
                $plan['rows'][] = ['source_row' => $row['source_row'], 'exclude' => false, 'exclusion_reason' => null, 'product_key' => $row['source_row'] === 4 ? 'vial' : 'serum',
                    'existing_product_public_id' => null, 'unit_public_id' => $unit->public_id, 'warehouse_public_id' => $warehouse->public_id,
                    'expiry_date' => $row['expiry_date'], 'unknown_expiry_confirmed' => false, 'zero_cost_confirmed' => false];
            }
            $planFile = tempnam(sys_get_temp_dir(), 'opening-cli-plan-');
            $this->assertNotFalse($planFile);
            file_put_contents($planFile, json_encode($plan, JSON_THROW_ON_ERROR));
            $context->clear();
            Auth::forgetUser();
            $beforeUser = DB::table('users')->where('id', $actor->id)->first();
            $beforeAudit = DB::table('audit_events')->count();
            $beforeSessions = DB::table('sessions')->count();
            config(['hashing.bcrypt.rounds' => 5]);
            $arguments = ['workbook' => $file, 'plan' => $planFile, '--company' => $company->public_id, '--source-sha' => $sha,
                '--plan-sha' => $import->hash($plan), '--operator' => $actor->email, '--dry-run' => true];
            $this->artisan('inventory:opening-import', $arguments)->expectsQuestion('Actual operator password', 'password')
                ->expectsOutputToContain('"status":"DRY_RUN"')->assertExitCode(0);
            $this->assertEquals($beforeUser, DB::table('users')->where('id', $actor->id)->first());
            $this->assertSame($beforeAudit, DB::table('audit_events')->count());
            $this->assertSame($beforeSessions, DB::table('sessions')->count());
            $this->assertDatabaseCount('products', 0);
            $this->assertDatabaseCount('stock_movements', 0);
            $this->assertFalse(Auth::check());
            $this->assertFalse($context->hasCompany());
            $this->artisan('inventory:opening-import', $arguments)->expectsQuestion('Actual operator password', 'not-the-password')->assertExitCode(1);
            $this->assertEquals($beforeUser, DB::table('users')->where('id', $actor->id)->first());
            $this->assertDatabaseCount('products', 0);
        } finally {
            foreach ([$file, $planFile] as $path) {
                if (is_string($path) && is_file($path)) {
                    unlink($path);
                }
            }
            $schema->drop();
        }
    }
}
