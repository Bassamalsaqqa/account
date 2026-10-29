<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Money\PostMoneyTransferAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Application\Platform\TenantWriteAccess;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Livewire\Pages\Catalog\CategoryIndex;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\User;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Sales\CustomerCatalogService;
use App\Services\Sales\DocumentSequenceService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class TenantWriteAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(12, 0));
        $this->owner = User::factory()->create();
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Synthetic', 'timezone' => 'UTC']);
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        config(['subscriptions.enforcement_enabled' => true]);
        DB::table('platform_subscriptions')->insert([
            'company_id' => $this->company->id, 'plan' => 'Synthetic', 'fee' => '100', 'currency' => 'ILS',
            'starts_on' => '2026-10-01', 'renews_on' => '2026-10-29', 'grace_days' => 7,
            'reminder_days' => '[14,7,1]', 'enforcement_enabled' => true,
            'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_canonical_master_writer_rejects_expired_company_without_rows_or_audit(): void
    {
        $auditBefore = DB::table('audit_events')->count();
        try {
            app(CustomerCatalogService::class)->save($this->company, $this->owner, ['name_en' => 'Rejected synthetic customer']);
            $this->fail('Expired company admitted a canonical business write.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString(__('subscriptions.tenant_read_only'), $exception->getMessage());
        }
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame($auditBefore, DB::table('audit_events')->count());
    }

    public function test_global_off_and_company_off_each_preserve_existing_workflows(): void
    {
        config(['subscriptions.enforcement_enabled' => false]);
        app(CustomerCatalogService::class)->save($this->company, $this->owner, ['name_en' => 'Global enforcement off']);
        config(['subscriptions.enforcement_enabled' => true]);
        DB::table('platform_subscriptions')->where('company_id', $this->company->id)->update(['enforcement_enabled' => false]);
        app(CustomerCatalogService::class)->save($this->company, $this->owner, ['name_en' => 'Company enforcement off']);
        $this->assertSame(2, Customer::query()->count());
    }

    public function test_unmanaged_company_is_not_locked_out(): void
    {
        DB::table('platform_subscriptions')->where('company_id', $this->company->id)->delete();
        app(CustomerCatalogService::class)->save($this->company, $this->owner, ['name_en' => 'Unmanaged']);
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_expired_company_can_preview_but_cannot_advance_a_document_sequence(): void
    {
        $service = app(DocumentSequenceService::class);
        $before = DB::table('document_sequences')->orderBy('id')->get()->toJson();
        $this->assertNotSame('', $service->peekNextNumber($this->company->id, DocumentSequence::TYPE_SALES_INVOICE));
        try {
            DB::transaction(fn () => $service->generateNextNumber($this->company->id, DocumentSequence::TYPE_SALES_INVOICE));
            $this->fail('Expired company advanced a sequence through the direct service.');
        } catch (AuthorizationException) {
            $this->assertSame($before, DB::table('document_sequences')->orderBy('id')->get()->toJson());
        }
    }

    public function test_expiry_is_company_scoped_and_does_not_authorize_a_foreign_context(): void
    {
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create();
        $other = app(CreateCompanyAction::class)->execute($otherOwner, ['name_ar' => 'Other synthetic company', 'timezone' => 'UTC']);
        $this->actingAs($otherOwner);
        app(CompanyContext::class)->setCompany($other, $otherOwner);
        $customer = app(CustomerCatalogService::class)->save($other, $otherOwner, ['name_en' => 'Unmanaged company customer']);
        $this->assertSame($other->id, $customer->company_id);
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->assertFalse(Customer::query()->whereKey($customer->id)->exists());
        $this->expectException(AuthorizationException::class);
        app(CustomerCatalogService::class)->save($other, $this->owner, ['name_en' => 'Foreign mutation']);
    }

    public function test_grace_allows_writes_but_expiry_during_callback_rolls_everything_back(): void
    {
        $this->travelTo(now()->setTime(0, 0)->subSecond());
        app(CustomerCatalogService::class)->save($this->company, $this->owner, ['name_en' => 'Last grace second']);
        $this->assertSame(1, Customer::query()->count());
        try {
            app(TenantWriteAccess::class)->transaction($this->company->id, function (): void {
                app(CustomerCatalogService::class)->save($this->company, $this->owner, ['name_en' => 'Late result']);
                $this->travel(1)->seconds();
            });
            $this->fail('Clock crossing admitted a late write.');
        } catch (AuthorizationException) {
            $this->assertSame(1, Customer::query()->count());
        }
    }

    public function test_livewire_direct_category_write_is_denied_and_search_still_works(): void
    {
        Livewire::test(CategoryIndex::class)->set('name_ar', 'Rejected category')->call('save')->assertForbidden();
        $this->assertDatabaseCount('product_categories', 0);
        $this->get('/customers')->assertOk();
        $this->get('/products')->assertOk();
        $this->get('/settings')->assertOk();
    }

    public function test_settings_and_report_export_remain_readable_without_creating_defaults(): void
    {
        DB::table('company_purchase_settings')->where('company_id', $this->company->id)->delete();
        $this->get('/settings/purchases')->assertOk();
        $this->assertDatabaseMissing('company_purchase_settings', ['company_id' => $this->company->id]);
        $export = $this->get(route('reports.export', ['reportKey' => 'sales.summary']));
        $export->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertNotSame('', $export->streamedContent());
        $this->get('/profile')->assertOk();
        $this->assertDatabaseCount('posting_batches', 0);
    }

    public function test_sales_purchase_money_and_inventory_entrypoints_cannot_write_after_grace(): void
    {
        $before = [];
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'sales_invoices', 'purchases', 'money_transfers', 'document_sequences'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $attempts = [
            fn () => app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, []),
            fn () => app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, []),
            fn () => app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, [
                'transfer_date' => '2026-11-05', 'from_money_account_id' => 1, 'to_money_account_id' => 2,
                'from_amount' => '1', 'to_amount' => '1', 'from_exchange_rate' => '1', 'to_exchange_rate' => '1',
                'idempotency_key' => 'synthetic-expired-transfer',
            ]),
            fn () => app(InventoryMovementService::class)->transfer(new StockTransferCommand(
                $this->company->id, 1, 2, '2026-11-05',
                [new StockTransferLineCommand(1, Quantity::of('1'))],
                'synthetic-expired-stock', $this->owner->id
            )),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('An expired company bypassed commercial write admission.');
            } catch (AuthorizationException $exception) {
                $this->assertSame(__('subscriptions.tenant_read_only'), $exception->getMessage());
            }
        }
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson(), $table.' changed');
        }
    }

    public function test_already_prepared_invoice_cannot_post_after_grace_and_snapshots_are_preserved(): void
    {
        config(['subscriptions.enforcement_enabled' => false]);
        $customer = app(CustomerCatalogService::class)->save($this->company, $this->owner, ['name_en' => 'Synthetic customer']);
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1', 'issue_date' => '2026-11-05',
            'lines' => [['item_description' => 'Synthetic service', 'quantity' => '1', 'unit_price' => '10']],
        ]);
        $before = $invoice->fresh()->toArray();
        config(['subscriptions.enforcement_enabled' => true]);
        try {
            app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);
            $this->fail('A prepared invoice posted after expiry.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(__('subscriptions.tenant_read_only'), $exception->getMessage());
        }
        $this->assertSame($before, $invoice->fresh()->toArray());
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->get('/invoices/'.$invoice->public_id)->assertOk();
    }

    public function test_tenant_notice_discloses_no_commercial_notes_or_receipts_in_both_locales(): void
    {
        DB::table('platform_subscriptions')->where('company_id', $this->company->id)->update(['notes' => 'PRIVATE-COMMERCIAL-NOTE', 'fee' => '987.654321']);
        foreach (['ar', 'en'] as $locale) {
            $this->withSession(['locale' => $locale])->get('/customers')->assertOk()
                ->assertSee(__('subscriptions.tenant_read_only', [], $locale))
                ->assertDontSee('PRIVATE-COMMERCIAL-NOTE')->assertDontSee('987.654321');
        }
    }
}
