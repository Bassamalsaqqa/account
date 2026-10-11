<?php

declare(strict_types=1);

namespace Tests\Feature\Phase11;

use App\Actions\Company\AddCompanyMemberAction;
use App\Actions\Company\CreateCompanyAction;
use App\Actions\Company\ToggleCompanyMemberStatusAction;
use App\Actions\Company\UpdateCompanyMemberRoleAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Application\Commands\SalesInvoiceCommands;
use App\Models\CommandReceipt;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\Phase11CsrfTestMiddleware;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class ApiCommandParityTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    /** @var array{id: string, token: string} */
    private array $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
        config(['commands.api_enabled' => true, 'session.driver' => 'database', 'session.connection' => null]);
        $this->app->bind(PreventRequestForgery::class, Phase11CsrfTestMiddleware::class);
        $this->app->bind(ValidateCsrfToken::class, Phase11CsrfTestMiddleware::class);
        $this->device = $this->device($this->owner, $this->company);
    }

    /** @return array{id: string, token: string} */
    private function device(User $actor, Company $company): array
    {
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        auth()->forgetGuards();
        $device = ['id' => Str::random(40), 'token' => Str::random(40)];
        DB::table('sessions')->insert([
            'id' => $device['id'], 'user_id' => $actor->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'SyntheticPhase11', 'last_activity' => now()->timestamp,
            'payload' => base64_encode(serialize([
                auth()->guard('web')->getName() => $actor->id,
                'password_hash_web' => $actor->getAuthPassword(),
                '_token' => $device['token'], 'active_company_id' => $company->id,
                'phase11_session_started_at' => now()->timestamp,
            ])),
        ]);

        return $device;
    }

    /** @param array<string, mixed> $data */
    private function api(string $method, string $suffix, array $data = [], ?string $key = null): TestResponse
    {
        auth()->forgetGuards();
        $headers = ['X-CSRF-TOKEN' => $this->device['token']];
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        return $this->withCredentials()->withCookie(config('session.cookie'), $this->device['id'])
            ->json($method, '/api/v1/companies/'.$this->company->id.'/sales/invoices'.$suffix, $data, $headers);
    }

    /** @return array<string, mixed> */
    private function intent(): array
    {
        return [
            'customer_id' => $this->customer->id, 'issue_date' => '2026-10-11',
            'currency_code' => 'ILS', 'exchange_rate' => '1.0000000000',
            'lines' => [['item_description' => 'Service', 'quantity' => '2.000000', 'unit_price' => '50.000000']],
        ];
    }

    /** @return array{string, string} */
    private function draftAndPreview(): array
    {
        $created = $this->api('POST', '/draft', $this->intent(), 'create-'.Str::random(12))->assertCreated();
        $id = $created->json('public_id');
        $preview = $this->api('POST', '/'.$id.'/preview')->assertOk()->assertJsonPath('can_execute', true);

        return [$id, $preview->json('confirmation_token')];
    }

    private function assertNoPosting(string $id): void
    {
        $invoice = SalesInvoice::where('public_id', $id)->firstOrFail();
        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->invoice_number);
        $this->assertNull($invoice->posting_batch_id);
        $this->assertSame(0, DB::table('posting_batches')->where('company_id', $this->company->id)->where('source_type', 'sales_invoice')->count());
        $this->assertSame(0, DB::table('command_receipts')->where('action', 'sales_invoice.execute')->count());
    }

    public function test_real_http_lifecycle_requires_confirmation_and_retries_without_duplicate_posting(): void
    {
        [$id, $token] = $this->draftAndPreview();
        $this->assertNoPosting($id);
        $this->assertSame(0, DB::table('stock_movements')->count());
        foreach ([[], ['confirmed' => false], ['confirmed' => 'true']] as $confirmation) {
            $this->api('POST', '/'.$id.'/execute', $confirmation + ['confirmation_token' => $token], 'deny')->assertStatus(422);
            $this->assertNoPosting($id);
        }
        $confirmed = ['confirmed' => true, 'confirmation_token' => $token];
        $posted = $this->api('POST', '/'.$id.'/execute', $confirmed, 'post-once')->assertOk()->assertJsonPath('status', 'posted');
        $this->assertNotEmpty($posted->json('invoice_number'));
        $this->api('POST', '/'.$id.'/execute', $confirmed, 'post-once')->assertOk()->assertJsonPath('invoice_number', $posted->json('invoice_number'));
        $shown = $this->api('GET', '/'.$id)->assertOk();
        $this->assertSame($posted->json(), $shown->json());
        $this->assertSame('100.000000', $shown->json('totals.grand_total_currency'));
        $this->assertSame(1, DB::table('sales_invoices')->count());
        $this->assertSame(1, DB::table('posting_batches')->count());
        $this->assertSame(1, DB::table('command_receipts')->where('action', 'sales_invoice.execute')->count());
        $this->assertStringNotContainsString('cogs', $shown->getContent());
        foreach (['accounting:reconcile', 'inventory:reconcile', 'sales:reconcile', 'money:reconcile', 'phase7:reconcile'] as $command) {
            $this->assertSame(0, Artisan::call($command, ['companyPublicId' => $this->company->public_id]), Artisan::output());
        }
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $report = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($report->isHealthy, implode('; ', $report->violations));
    }

    public function test_http_exact_tax_discount_and_fx_match_posted_canonical_amounts(): void
    {
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $tax = TaxRate::create(['company_id' => $this->company->id, 'code' => 'VAT16', 'name_ar' => 'VAT', 'rate' => '16.000000', 'calculation' => 'exclusive', 'active' => true]);
        foreach (['USD' => ['3.5000000000', '365.400000'], 'JOD' => ['4.9992000000', '521.916480']] as $currency => [$rate, $base]) {
            $payload = $this->intent();
            $payload['currency_code'] = $currency;
            $payload['exchange_rate'] = $rate;
            $payload['lines'][0] += ['discount_type' => 'percent', 'discount_value' => '10.000000', 'tax_rate_id' => $tax->id];
            $id = $this->api('POST', '/draft', $payload, 'exact-'.$currency)->assertCreated()->json('public_id');
            $preview = $this->api('POST', '/'.$id.'/preview')->assertOk();
            $preview->assertJsonPath('totals.grand_total_currency', '104.400000')->assertJsonPath('totals.grand_total_base', $base);
            $posted = $this->api('POST', '/'.$id.'/execute', ['confirmed' => true, 'confirmation_token' => $preview->json('confirmation_token')], 'exact-post-'.$currency)->assertOk();
            $this->assertSame($preview->json('totals'), $posted->json('totals'));
        }
    }

    public function test_expired_and_edited_preview_refuse_execution_without_any_financial_effect(): void
    {
        [$id, $token] = $this->draftAndPreview();
        $this->travel(301)->seconds();
        $this->api('POST', '/'.$id.'/execute', ['confirmed' => true, 'confirmation_token' => $token], 'expired')->assertStatus(409);
        $this->assertNoPosting($id);
        $this->travelBack();
        $token = $this->api('POST', '/'.$id.'/preview')->assertOk()->json('confirmation_token');
        $payload = $this->intent();
        $payload['lines'][0]['unit_price'] = '60.000000';
        $this->api('PUT', '/'.$id, $payload, 'edit-after-preview')->assertOk();
        $this->api('POST', '/'.$id.'/execute', ['confirmed' => true, 'confirmation_token' => $token], 'stale')->assertStatus(409);
        $this->assertNoPosting($id);
    }

    public function test_foreign_customer_and_invoice_are_refused_without_writes(): void
    {
        app(CompanyContext::class)->clear();
        $other = User::factory()->create();
        $company = app(CreateCompanyAction::class)->execute($other, ['name_ar' => 'Other', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($company, $other);
        $foreign = Customer::create(['company_id' => $company->id, 'name_ar' => 'Foreign', 'created_by' => $other->id]);
        $payload = $this->intent();
        $payload['customer_id'] = $foreign->id;
        $this->api('POST', '/draft', $payload, 'foreign')->assertNotFound();
        $this->api('GET', '/'.Str::ulid())->assertNotFound();
        $this->assertSame(0, DB::table('sales_invoices')->count());
        $this->assertSame(0, DB::table('command_receipts')->count());
    }

    public function test_role_downgrade_and_membership_revocation_deny_confirmation_and_exact_replay(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $membership = app(AddCompanyMemberAction::class)->execute($this->company, ['name' => 'Member', 'email' => 'phase11-member@example.test', 'password' => 'SyntheticPassword123!', 'locale' => 'en'], 'Manager', $this->owner);
        $member = $membership->user;
        $this->device = $this->device($member, $this->company);
        [$id, $token] = $this->draftAndPreview();
        $confirmed = ['confirmed' => true, 'confirmation_token' => $token];
        $this->api('POST', '/'.$id.'/execute', $confirmed, 'member-post')->assertOk();
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        app(UpdateCompanyMemberRoleAction::class)->execute($this->company, $membership, 'Viewer', $this->owner);
        $this->api('POST', '/'.$id.'/execute', $confirmed, 'member-post')->assertForbidden();
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        app(ToggleCompanyMemberStatusAction::class)->execute($this->company, $membership, $this->owner);
        $this->api('GET', '/'.$id)->assertForbidden();
        $this->assertSame(1, DB::table('posting_batches')->count());
        $this->assertSame(1, DB::table('command_receipts')->where('action', 'sales_invoice.execute')->count());
    }

    public function test_session_and_password_revocation_between_middleware_and_command_prevent_writes(): void
    {
        $commands = app(SalesInvoiceCommands::class);
        $mode = '';
        $hook = \Mockery::mock($commands)->makePartial();
        $hook->shouldReceive('create')->twice()->andReturnUsing(function (Company $company, User $actor, array $payload, string $key) use ($commands, &$mode) {
            $this->assertIsArray(request()->attributes->get('phase11_api_session'));
            if ($mode === 'delete-session') {
                DB::table('sessions')->where('id', $this->device['id'])->delete();
            } else {
                DB::table('users')->where('id', $actor->id)->update(['password' => Hash::make('ChangedSyntheticPassword!')]);
            }

            return $commands->create($company, $actor, $payload, $key);
        });
        app()->instance(SalesInvoiceCommands::class, $hook);
        foreach (['delete-session', 'change-password'] as $mode) {
            $this->device = $this->device($this->owner, $this->company);
            $this->api('POST', '/draft', $this->intent(), $mode)->assertUnauthorized();
            $this->assertSame(0, DB::table('sales_invoices')->count());
            $this->assertSame(0, DB::table('command_receipts')->count());
        }
    }

    public function test_owned_device_enumeration_and_revocation_require_fresh_password_confirmation(): void
    {
        DB::table('sessions')->where('id', $this->device['id'])->delete();
        $otherDevice = $this->device($this->owner, $this->company);
        $this->device = $this->device($this->owner, $this->company);
        $this->withCredentials()->withCookie(config('session.cookie'), $this->device['id'])
            ->getJson('/api/v1/sessions')->assertStatus(423);
        foreach ([now()->timestamp + 1 => 423, now()->timestamp => 200] as $confirmedAt => $status) {
            $row = DB::table('sessions')->where('id', $this->device['id'])->first();
            $payload = unserialize(base64_decode($row->payload), ['allowed_classes' => false]);
            $payload['auth.password_confirmed_at'] = $confirmedAt;
            DB::table('sessions')->where('id', $this->device['id'])->update(['payload' => base64_encode(serialize($payload))]);
            app('session')->forgetDrivers();
            app()->forgetInstance('session.store');
            auth()->forgetGuards();
            $listed = $this->withCredentials()->withCookie(config('session.cookie'), $this->device['id'])
                ->getJson('/api/v1/sessions')->assertStatus($status);
        }
        $this->assertCount(2, $listed->json('sessions'));
        $this->assertStringNotContainsString($otherDevice['id'], $listed->getContent());
        $target = collect($listed->json('sessions'))->firstWhere('is_current', false);
        auth()->forgetGuards();
        $this->withCredentials()->withCookie(config('session.cookie'), $this->device['id'])
            ->deleteJson('/api/v1/sessions/'.$target['handle'], [], ['X-CSRF-TOKEN' => $this->device['token']])->assertOk();
        $this->assertFalse(DB::table('sessions')->where('id', $otherDevice['id'])->exists());
        $this->assertTrue(DB::table('sessions')->where('id', $this->device['id'])->exists());
    }

    public function test_late_receipt_failure_rolls_back_numbering_inventory_ledger_and_lifecycle(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $warehouse = Warehouse::where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
        $unit = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();
        $product = Product::create(['company_id' => $this->company->id, 'sku' => 'ROLLBACK', 'name_ar' => 'Rollback', 'base_unit_id' => $unit->id, 'product_type' => 'stock', 'track_stock' => true, 'active' => true, 'created_by' => $this->owner->id]);
        $productUnit = ProductUnit::create(['company_id' => $this->company->id, 'product_id' => $product->id, 'unit_id' => $unit->id, 'conversion_to_base' => '1.000000', 'is_base' => true, 'is_default_sale' => true, 'is_default_purchase' => true, 'active' => true, 'created_by' => $this->owner->id]);
        $vendor = Vendor::create(['company_id' => $this->company->id, 'name_ar' => 'Vendor', 'created_by' => $this->owner->id]);
        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, ['vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id, 'purchase_date' => '2026-10-11', 'currency_code' => 'ILS', 'exchange_rate' => '1', 'lines' => [['product_id' => $product->id, 'product_unit_id' => $productUnit->id, 'quantity' => '10', 'unit_cost' => '20']]]);
        app(PostPurchaseAction::class)->execute($purchase, $this->owner);
        $payload = $this->intent();
        $payload['warehouse_id'] = $warehouse->id;
        $payload['lines'][0] += ['product_id' => $product->id, 'product_unit_id' => $productUnit->id];
        $id = $this->api('POST', '/draft', $payload, 'rollback-draft')->assertCreated()->json('public_id');
        $token = $this->api('POST', '/'.$id.'/preview')->assertOk()->assertJsonPath('can_execute', true)->json('confirmation_token');
        $tables = ['posting_batches', 'posting_lines', 'stock_movements', 'inventory_balances', 'inventory_cost_states', 'sales_invoice_lot_allocations', 'document_sequences'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $witness = false;
        CommandReceipt::creating(function (CommandReceipt $receipt) use (&$witness): void {
            if ($receipt->action === 'sales_invoice.execute') {
                $witness = true;
                $this->assertSame('posted', DB::table('sales_invoices')->where('id', $receipt->sales_invoice_id)->value('status'));
                throw new RuntimeException('Injected late persistence failure');
            }
        });
        $this->api('POST', '/'.$id.'/execute', ['confirmed' => true, 'confirmation_token' => $token], 'rollback-post')->assertStatus(500);
        $this->assertTrue($witness, 'Failure was injected after canonical posting, not before it.');
        $this->assertNoPosting($id);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table.' must roll back completely.');
        }
    }
}
