<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessWorkflow;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Exceptions\IdempotencyConflictException;
use App\Livewire\Pages\Products\ProductDetail;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Services\Audit\AuditService;
use App\Services\Inventory\QuickProductCreation;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Feature\Phase5E\Phase5ETestCase;

final class QuickProductReceiveTest extends Phase5ETestCase
{
    /** @param array<string, mixed> $changes
     * @return array<string, mixed> */
    private function payload(array $changes = []): array
    {
        return array_replace(['name_ar' => 'Quick item', 'name_en' => 'New item', 'sku' => 'QUICK-NEW',
            'base_unit_id' => $this->product->base_unit_id, 'track_expiry' => false], $changes);
    }

    /** @param array<string, mixed> $changes */
    private function create(array $changes = [], ?string $key = null): Product
    {
        return app(QuickProductCreation::class)->execute($this->company, $this->owner, $key ?? (string) Str::uuid(), $this->payload($changes), 'sales.invoice.create');
    }

    public function test_replay_uses_normalized_complete_payload_and_creates_only_master_records(): void
    {
        $before = [PostingBatch::count(), StockMovement::count(), Purchase::count()];
        $key = (string) Str::uuid();
        $first = $this->create(['name_ar' => ' Quick item '], $key);
        cache()->flush();
        $again = $this->create([], $key);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Product::where('sku', 'QUICK-NEW')->count());
        $this->assertSame(1, ProductUnit::where('product_id', $first->id)->count());
        $base = ProductUnit::where('product_id', $first->id)->firstOrFail();
        $this->assertTrue($base->is_base);
        $this->assertSame('1.000000', (string) $base->conversion_to_base);
        $this->assertNull($first->default_purchase_cost_base);
        $this->assertNull($first->default_sale_price_base);
        $this->assertSame(1, DB::table('product_creation_requests')->where('request_key', $key)->count());
        $this->assertSame($before, [PostingBatch::count(), StockMovement::count(), Purchase::count()]);
    }

    public function test_retry_key_cannot_change_expiry_or_other_payload(): void
    {
        $key = (string) Str::uuid();
        $this->create([], $key);
        $this->expectException(IdempotencyConflictException::class);
        $this->create(['track_expiry' => true], $key);
    }

    public function test_final_audit_failure_rolls_back_product_base_unit_and_receipt(): void
    {
        $before = [Product::count(), ProductUnit::count(), DB::table('product_creation_requests')->count()];
        $audit = \Mockery::mock(AuditService::class);
        $audit->shouldReceive('log')->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        $this->app->instance(AuditService::class, $audit);
        try {
            $this->create();
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
            $this->assertSame($before, [Product::count(), ProductUnit::count(), DB::table('product_creation_requests')->count()]);
        }
    }

    public function test_duplicate_soft_deleted_sku_is_validation_error(): void
    {
        $this->product->delete();
        $this->expectException(ValidationException::class);
        $this->create(['sku' => $this->product->sku]);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function rejectedPayloads(): array
    {
        return ['fractional unit' => [['base_unit_id' => '1.9']], 'boolean text' => [['track_expiry' => 'no']],
            'name array' => [['name_en' => ['invalid']]], 'price injection' => [['default_purchase_cost_base' => '12.34']],
            'initial stock injection' => [['quantity' => '100']]];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('rejectedPayloads')]
    public function test_invalid_or_excess_payload_is_rejected_without_creating_product(array $changes): void
    {
        $before = Product::count();
        try {
            $this->create($changes);
            $this->fail('Invalid payload accepted.');
        } catch (ValidationException) {
            $this->assertSame($before, Product::count());
            $this->assertSame(0, DB::table('product_creation_requests')->count());
        }
    }

    public function test_inactive_and_other_company_units_are_rejected(): void
    {
        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'inactive', 'name_ar' => 'Inactive', 'active' => false]);
        foreach ([$unit->id, 999999] as $unitId) {
            try {
                $this->create(['base_unit_id' => $unitId]);
                $this->fail('Invalid unit accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('base_unit_id', $exception->errors());
            }
        }
        $this->assertSame(0, DB::table('product_creation_requests')->count());
    }

    public function test_replay_checks_fresh_permissions_and_form_access(): void
    {
        $key = (string) Str::uuid();
        $this->create([], $key);
        Role::findById($this->owner->roles()->firstOrFail()->getKey())->revokePermissionTo('inventory.product.manage');
        $this->expectException(AuthorizationException::class);
        $this->create([], $key);
    }

    public function test_product_manage_alone_does_not_authorize_trade_entry(): void
    {
        $actor = $this->customActor(['inventory.product.manage']);
        $this->activate($actor);
        $this->expectException(AuthorizationException::class);
        app(QuickProductCreation::class)->execute($this->company, $actor, (string) Str::uuid(), $this->payload(), 'sales.invoice.create');
    }

    /** @return array<string, array{class-string, string}> */
    public static function forms(): array
    {
        return ['invoice' => [InvoiceForm::class, 'unit_price'], 'quote' => [QuotationForm::class, 'unit_price'], 'purchase' => [PurchaseForm::class, 'unit_cost']];
    }

    #[DataProvider('forms')]
    public function test_quick_product_preserves_edited_rows_currency_and_does_not_save_document(string $component, string $amount): void
    {
        $before = [PostingBatch::count(), StockMovement::count(), Purchase::count()];
        $page = Livewire::test($component)->call('selectProduct', 0, $this->product->id)
            ->set('currency_code', 'USD')->set('exchange_rate', '3.5')->set('lines.0.quantity', '3')
            ->set('lines.0.'.$amount, '12.34')->call('openQuickProductModal')
            ->set('quick_product_name_ar', 'Inline new')->set('quick_product_sku', 'INLINE')
            ->set('quick_product_track_expiry', true)->call('saveQuickProduct')
            ->assertHasNoErrors()->assertSet('showQuickProductModal', false)
            ->assertSet('currency_code', 'USD')->assertSet('exchange_rate', '3.5')
            ->assertSet('lines.0.quantity', '3')->assertSet('lines.0.'.$amount, '12.34');
        $product = Product::where('sku', 'INLINE')->firstOrFail();
        $this->assertTrue($product->track_expiry);
        $this->assertSame($product->id, $page->get('lines.1.product_id'));
        $this->assertSame($before, [PostingBatch::count(), StockMovement::count(), Purchase::count()]);
    }

    public function test_first_empty_row_is_reused_and_cancel_leaves_form_unchanged(): void
    {
        $page = Livewire::test(PurchaseForm::class);
        $before = $page->get('lines');
        $page->call('openQuickProductModal')->set('quick_product_name_ar', 'Unsaved')->call('closeQuickProductModal');
        $this->assertSame($before, $page->get('lines'));
        $page->call('openQuickProductModal')->set('quick_product_name_ar', 'Reuse')->set('quick_product_sku', 'REUSE')->call('saveQuickProduct')->assertHasNoErrors();
        $this->assertCount(1, $page->get('lines'));
        $this->assertSame('REUSE', Product::findOrFail($page->get('lines.0.product_id'))->sku);
    }

    public function test_receive_opens_unsaved_canonical_purchase_then_posts_atomically(): void
    {
        $before = [PostingBatch::count(), StockMovement::count(), Purchase::count()];
        $page = Livewire::test(PurchaseForm::class, ['receiveProduct' => $this->product->public_id])
            ->assertSet('receivingStock', true)->assertSet('lines.0.product_id', $this->product->id);
        $this->assertSame($before, [PostingBatch::count(), StockMovement::count(), Purchase::count()]);
        $page->set('vendor_id', $this->vendor->id)->set('lines.0.quantity', '2')->set('lines.0.unit_cost', '12.34')
            ->call('saveDraft')->assertHasNoErrors();
        $purchase = Purchase::where('public_id', $page->get('savedDraftPublicId'))->firstOrFail();
        $this->assertSame('draft', $purchase->status);
        $this->assertSame($before[0], PostingBatch::count());
        $this->assertSame($before[1], StockMovement::count());
        $posted = app(PostPurchaseAction::class)->execute($purchase, $this->owner);
        $this->assertSame('posted', $posted->status);
        $this->assertSame($before[0] + 1, PostingBatch::count());
        $this->assertSame($before[1] + 1, StockMovement::count());
        $this->assertSame('24.680000', (string) DB::table('inventory_cost_states')->where('product_id', $this->product->id)->value('inventory_value_base'));
    }

    public function test_receive_query_cannot_overwrite_existing_draft(): void
    {
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id, 'warehouse_id' => $this->warehouse->id, 'purchase_date' => '2026-10-15',
            'currency_code' => 'ILS', 'exchange_rate' => '1', 'document_locale' => 'ar',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '7', 'unit_cost' => '9.75']],
        ]);
        Livewire::test(PurchaseForm::class, ['publicId' => $draft->public_id, 'receiveProduct' => 'foreign-or-missing'])
            ->assertSet('receivingStock', false)->assertSet('lines.0.quantity', '7.000000')->assertSet('lines.0.unit_cost', '9.750000');
    }

    public function test_receive_rejects_missing_inactive_product_and_cost_restricted_actor(): void
    {
        Livewire::test(PurchaseForm::class, ['receiveProduct' => 'not-here'])->assertNotFound();
        $this->product->update(['active' => false]);
        Livewire::test(PurchaseForm::class, ['receiveProduct' => $this->product->public_id])->assertNotFound();
        $actor = $this->customActor(['purchasing.purchase.create', 'inventory.stock.view']);
        $this->activate($actor);
        Livewire::test(PurchaseForm::class, ['receiveProduct' => $this->product->public_id])->assertForbidden();
        Livewire::test(ProductDetail::class, ['publicId' => $this->product->public_id])->assertDontSee(__('quick_product.receive_stock'));
    }

    public function test_company_switch_cannot_replay_product_or_receive_foreign_product(): void
    {
        $key = (string) Str::uuid();
        $this->create([], $key);
        app(CompanyContext::class)->clear();
        $other = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Other company', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($other, $this->owner);
        setPermissionsTeamId($other->id);
        Livewire::test(PurchaseForm::class, ['receiveProduct' => $this->product->public_id])->assertNotFound();
        try {
            $this->create([], $key);
            $this->fail('Cross-company replay accepted.');
        } catch (AuthorizationException) {
            $this->assertSame(1, DB::table('product_creation_requests')->count());
        }
    }
}
