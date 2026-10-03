<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5B;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\EnsurePurchasingFoundationAction;
use App\Actions\Purchasing\UpdatePurchaseDraftAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Inventory\Exceptions\InvalidQuantityException;
use App\Livewire\Pages\Purchasing\PurchaseDetail;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Purchasing\PurchaseIndex;
use App\Models\Company;
use App\Models\CompanyPurchaseSetting;
use App\Models\LedgerAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\DuplicateVendorInvoice;
use App\Services\Purchasing\PurchaseDocumentRules;
use App\Services\Purchasing\PurchaseDraftBuilder;
use App\Services\Purchasing\PurchaseProductSelection;
use App\Services\Purchasing\PurchaseReadModel;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseDraftTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Vendor $vendor;

    protected Product $product;

    protected ProductUnit $carton;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'شركة المسودات', 'name_en' => 'Draft Company']);
        $this->activate();
        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, ['name_ar' => 'مورد الاختبار', 'name_en' => 'Test Vendor']);
        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صابون', 'name_en' => 'Soap', 'sku' => 'SOAP', 'product_type' => Product::TYPE_STOCK,
            'track_stock' => true, 'track_expiry' => true, 'base_unit_id' => Unit::where('code', 'piece')->firstOrFail()->id,
            'default_purchase_cost_base' => '2.000000',
        ], $this->owner->id);
        $this->carton = app(ProductCatalogService::class)->addOrUpdateAlternateUnit($this->product,
            Unit::where('code', 'carton')->firstOrFail()->id, '12', false, true);
    }

    protected function activate(?Company $company = null, ?User $actor = null): void
    {
        $company ??= $this->company;
        $actor ??= $this->owner;
        app(CompanyContext::class)->setCompany($company, $actor);
        $this->actingAs($actor);
        setPermissionsTeamId($company->id);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
    }

    protected function payload(array $changes = []): array
    {
        return array_replace([
            'vendor_id' => $this->vendor->id, 'warehouse_id' => Warehouse::firstOrFail()->id,
            'vendor_invoice_number' => ' SUP-001 ', 'purchase_date' => '2026-10-03',
            'currency_code' => 'ILS', 'exchange_rate' => '1', 'document_locale' => 'ar',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '2', 'unit_cost' => '24', 'lots' => []]],
        ], $changes);
    }

    protected function create(array $changes = []): Purchase
    {
        return app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $this->payload($changes));
    }

    protected function effects(): array
    {
        $tables = ['posting_batches', 'posting_lines', 'stock_movements', 'inventory_operations', 'inventory_lots', 'inventory_balances', 'inventory_lot_balances', 'inventory_cost_states', 'document_sequences'];
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->orderBy($table === 'document_sequences' ? 'document_type' : 'id')->get()->toJson();
        }

        return $snapshot;
    }

    public function test_drafts_snapshot_exact_lines_and_have_zero_financial_or_stock_effects(): void
    {
        $before = $this->effects();
        $purchase = $this->create();
        $this->assertSame('draft', $purchase->status);
        foreach (Purchase::RESERVED_FIELDS as $field) {
            $this->assertNull($purchase->{$field});
        }
        $this->assertNull($purchase->due_date);
        $this->assertSame('SUP-001', $purchase->vendor_invoice_number);
        $this->assertSame('48.000000', $purchase->grand_total_currency);
        $line = $purchase->lines->sole();
        $this->assertSame($this->carton->id, $line->product_unit_id);
        $this->assertSame('24.000000', $line->quantity_base);
        $this->assertNull($line->stock_movement_id);
        $this->assertNull($line->inventory_unit_cost_base);
        $this->assertSame('مورد الاختبار', $purchase->vendor_snapshot['name_ar']);
        $purchase = app(UpdatePurchaseDraftAction::class)->execute($purchase, $this->owner, ['exchange_rate' => '3.5', 'currency_code' => 'USD']);
        $this->assertSame('48.000000', $purchase->grand_total_currency);
        $this->assertSame('168.000000', $purchase->grand_total_base);
        $this->assertSame($before, $this->effects());
        $this->assertDatabaseHas('audit_events', ['event_key' => 'purchase.draft.created']);
        $this->assertDatabaseHas('audit_events', ['event_key' => 'purchase.draft.updated']);
    }

    public static function terms(): array
    {
        return [[null, null], [0, '2026-10-03'], [30, '2026-11-02']];
    }

    #[DataProvider('terms')]
    public function test_due_defaults_and_saved_null_are_preserved(?int $days, ?string $expected): void
    {
        CompanyPurchaseSetting::where('company_id', $this->company->id)->update(['default_payment_terms_days' => $days]);
        $purchase = $this->create();
        $this->assertSame($expected, $purchase->due_date?->format('Y-m-d'));
        $purchase = app(UpdatePurchaseDraftAction::class)->execute($purchase, $this->owner, ['due_date' => null]);
        $purchase = app(UpdatePurchaseDraftAction::class)->execute($purchase, $this->owner, ['notes' => 'Saved null']);
        $this->assertNull($purchase->due_date);
    }

    public static function taxes(): array
    {
        return [['exclusive', '100', '16.000000', '116.000000'], ['inclusive', '116', '16.000000', '116.000000']];
    }

    #[DataProvider('taxes')]
    public function test_percentage_points_tax_snapshots_and_input_account_does_not_change_document(string $mode, string $cost, string $taxExpected, string $total): void
    {
        $tax = TaxRate::create(['company_id' => $this->company->id, 'code' => 'VAT16', 'name_ar' => 'ضريبة', 'rate' => '16.000000', 'calculation' => $mode, 'active' => true]);
        $line = ['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => $cost, 'tax_rate_id' => $tax->id];
        $purchase = $this->create(['lines' => [$line]]);
        $this->assertSame($total, $purchase->grand_total_currency);
        $this->assertSame($taxExpected, $purchase->tax_total_currency);
        $this->assertSame('16.000000', $purchase->lines->sole()->tax_rate_snapshot);
        $this->assertSame($mode === 'inclusive', $purchase->lines->sole()->tax_inclusive);
        $tax->update(['purchase_tax_account_id' => LedgerAccount::where('system_key', 'tax_input')->firstOrFail()->id]);
        $second = $this->create(['lines' => [$line]]);
        $this->assertSame($purchase->grand_total_currency, $second->grand_total_currency);
        $this->assertDatabaseCount('posting_batches', 0);
    }

    public function test_lot_intent_partial_exact_conversion_and_repeated_external_numbers(): void
    {
        $before = $this->effects();
        $data = $this->payload();
        $data['lines'][0]['lots'] = [['product_unit_id' => $this->carton->id, 'quantity' => '1', 'lot_number' => 'SAME', 'expiry_date' => '2020-01-01']];
        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $data);
        $lot = $purchase->lines->sole()->lots->sole();
        $this->assertSame('12.000000', $lot->quantity_base);
        $this->assertNull($lot->created_inventory_lot_id);
        $this->assertNull($lot->stock_movement_id);
        $data['lines'][0]['lots'][] = $data['lines'][0]['lots'][0];
        $purchase = app(UpdatePurchaseDraftAction::class)->execute($purchase, $this->owner, $data);
        $this->assertCount(2, $purchase->lines->sole()->lots);
        $this->assertSame($before, $this->effects());
    }

    public function test_suggestion_uses_purchase_unit_and_actual_cost_never_changes_defaults(): void
    {
        $service = app(PurchaseProductSelection::class);
        $suggested = $service->select($this->product->id, null, 'ILS', '1');
        $this->assertSame($this->carton->id, $suggested['product_unit_id']);
        $this->assertSame('24.00', $suggested['unit_cost']);
        $this->carton->update(['default_purchase_price_base' => '30']);
        $this->assertSame('10.00', $service->select($this->product->id, null, 'USD', '3')['unit_cost']);
        $this->assertSame('4.286', $service->select($this->product->id, null, 'JOD', '7')['unit_cost']);
        $purchase = $this->create(['lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '29.123456']]]);
        $this->assertSame('29.123456', $purchase->lines->sole()->unit_cost);
        $this->assertSame('30.000000', $this->carton->fresh()->default_purchase_price_base);
        $this->assertSame('2.000000', $this->product->fresh()->default_purchase_cost_base);
        $this->carton->update(['default_purchase_price_base' => null]);
        $this->product->update(['default_purchase_cost_base' => null]);
        $this->assertSame('0.00', $service->select($this->product->id, null, 'ILS', '1')['unit_cost']);
    }

    public static function invalidDrafts(): array
    {
        return array_map(fn ($case) => [$case], [
            'foreign vendor', 'inactive vendor', 'deleted vendor', 'foreign warehouse', 'inactive warehouse',
            'foreign product', 'inactive product', 'service', 'non stock', 'no stock tracking',
            'foreign unit', 'wrong product unit', 'inactive unit', 'inactive underlying unit', 'missing default', 'multiple defaults',
            'disabled currency', 'unknown currency', 'base rate', 'negative rate', 'float rate', 'over precise rate',
            'disabled locale', 'foreign tax', 'inactive tax', 'quantity zero', 'quantity float', 'quantity precision',
            'cost float', 'negative cost', 'cost precision', 'negative discount', 'percent above 100', 'excess fixed discount',
            'invalid purchase date', 'invalid due date', 'early due date', 'lot exceeds line', 'lot invalid date',
            'lot incompatible unit', 'non expiry lots', 'posting intent', 'movement intent',
        ]);
    }

    #[DataProvider('invalidDrafts')]
    public function test_forged_or_invalid_input_fails_atomically_without_effects(string $case): void
    {
        $data = $this->payload();
        if (str_starts_with($case, 'foreign')) {
            $other = $this->foreignFixtures();
            match ($case) {
                'foreign vendor' => $data['vendor_id'] = $other['vendor'],
                'foreign warehouse' => $data['warehouse_id'] = $other['warehouse'],
                'foreign product' => $data['lines'][0]['product_id'] = $other['product'],
                'foreign unit' => $data['lines'][0]['product_unit_id'] = $other['unit'],
                'foreign tax' => $data['lines'][0]['tax_rate_id'] = $other['tax'],
            };
        } else {
            switch ($case) {
                case 'inactive vendor': $this->vendor->update(['status' => 'inactive']);
                    break;
                case 'deleted vendor': $this->vendor->delete();
                    break;
                case 'inactive warehouse': Warehouse::firstOrFail()->update(['active' => false]);
                    break;
                case 'inactive product': $this->product->update(['active' => false]);
                    break;
                case 'service': $this->product->update(['product_type' => Product::TYPE_SERVICE]);
                    break;
                case 'non stock': $this->product->update(['product_type' => Product::TYPE_NON_STOCK]);
                    break;
                case 'no stock tracking': $this->product->update(['track_stock' => false]);
                    break;
                case 'wrong product unit':
                    $second = app(ProductCatalogService::class)->createProduct($this->company, [
                        'name_ar' => 'آخر', 'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'base_unit_id' => $this->product->base_unit_id,
                    ], $this->owner->id);
                    $data['lines'][0]['product_unit_id'] = $second->productUnits->sole()->id;
                    break;
                case 'inactive unit':
                    $this->carton->update(['active' => false]);
                    $data['lines'][0]['product_unit_id'] = $this->carton->id;
                    break;
                case 'inactive underlying unit': $this->carton->unit->update(['active' => false]);
                    break;
                case 'missing default': $this->carton->update(['is_default_purchase' => false]);
                    break;
                case 'multiple defaults': $this->product->productUnits()->where('is_base', true)->update(['is_default_purchase' => true]);
                    break;
                case 'disabled currency': $this->company->currencies()->where('currency_code', 'USD')->update(['enabled' => false]);
                    $data['currency_code'] = 'USD';
                    break;
                case 'unknown currency': $data['currency_code'] = 'EUR';
                    break;
                case 'base rate': $data['exchange_rate'] = '2';
                    break;
                case 'negative rate': $data['exchange_rate'] = '-1';
                    break;
                case 'float rate': $data['exchange_rate'] = 1.0;
                    break;
                case 'over precise rate': $data['exchange_rate'] = '1.00000000001';
                    break;
                case 'disabled locale': $this->company->languages()->where('locale', 'en')->update(['enabled' => false]);
                    $data['document_locale'] = 'en';
                    break;
                case 'inactive tax': $data['lines'][0]['tax_rate_id'] = TaxRate::create(['company_id' => $this->company->id, 'code' => 'OFF', 'name_ar' => 'غير نشط', 'rate' => '16', 'calculation' => 'exclusive', 'active' => false])->id;
                    break;
                case 'quantity zero': $data['lines'][0]['quantity'] = '0';
                    break;
                case 'quantity float': $data['lines'][0]['quantity'] = 2.0;
                    break;
                case 'quantity precision': $data['lines'][0]['quantity'] = '2.0000001';
                    break;
                case 'cost float': $data['lines'][0]['unit_cost'] = 24.0;
                    break;
                case 'negative cost': $data['lines'][0]['unit_cost'] = '-1';
                    break;
                case 'cost precision': $data['lines'][0]['unit_cost'] = '24.0000001';
                    break;
                case 'negative discount': $data['lines'][0]['discount_type'] = 'fixed';
                    $data['lines'][0]['discount_value'] = '-1';
                    break;
                case 'percent above 100': $data['lines'][0]['discount_type'] = 'percent';
                    $data['lines'][0]['discount_value'] = '101';
                    break;
                case 'excess fixed discount': $data['lines'][0]['discount_type'] = 'fixed';
                    $data['lines'][0]['discount_value'] = '49';
                    break;
                case 'invalid purchase date': $data['purchase_date'] = '2026-02-30';
                    break;
                case 'invalid due date': $data['due_date'] = '2026-11-31';
                    break;
                case 'early due date': $data['due_date'] = '2026-10-02';
                    break;
                case 'lot exceeds line': $data['lines'][0]['lots'] = [['product_unit_id' => $this->carton->id, 'quantity' => '3']];
                    break;
                case 'lot invalid date': $data['lines'][0]['lots'] = [['product_unit_id' => $this->carton->id, 'quantity' => '1', 'expiry_date' => '2026-02-30']];
                    break;
                case 'lot incompatible unit': $data['lines'][0]['lots'] = [['product_unit_id' => $this->product->productUnits()->where('is_base', true)->firstOrFail()->id, 'quantity' => '1']];
                    break;
                case 'non expiry lots': $this->product->update(['track_expiry' => false]);
                    $data['lines'][0]['lots'] = [['product_unit_id' => $this->carton->id, 'quantity' => '1']];
                    break;
                case 'posting intent': $data['posting_batch_id'] = 1;
                    break;
                case 'movement intent': $data['lines'][0]['stock_movement_id'] = 1;
                    break;
            }
        }
        $before = $this->effects();
        try {
            app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $data);
            $this->fail('Invalid Purchase input was accepted: '.$case);
        } catch (\Exception $exception) {
            $this->assertInstanceOf(\Exception::class, $exception);
        }
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('purchase_lines', 0);
        $this->assertDatabaseCount('purchase_line_lots', 0);
        $this->assertSame($before, $this->effects());
    }

    protected function foreignFixtures(): array
    {
        app(CompanyContext::class)->clear();
        $actor = User::factory()->create();
        $company = app(CreateCompanyAction::class)->execute($actor, ['name_ar' => 'شركة أخرى']);
        $this->activate($company, $actor);
        $vendor = app(VendorCatalogService::class)->save($company, $actor, ['name_ar' => 'مورد آخر']);
        $product = app(ProductCatalogService::class)->createProduct($company, ['name_ar' => 'أجنبي', 'product_type' => Product::TYPE_STOCK,
            'track_stock' => true, 'base_unit_id' => Unit::where('company_id', $company->id)->where('code', 'piece')->firstOrFail()->id], $actor->id);
        $tax = TaxRate::create(['company_id' => $company->id, 'code' => 'OTHER', 'name_ar' => 'ضريبة', 'rate' => '16', 'calculation' => 'exclusive', 'active' => true]);
        $result = ['company' => $company, 'actor' => $actor, 'vendor' => $vendor->id, 'product' => $product->id,
            'unit' => $product->productUnits->sole()->id, 'warehouse' => Warehouse::where('company_id', $company->id)->firstOrFail()->id, 'tax' => $tax->id];
        $this->activate();

        return $result;
    }

    public function test_invalid_update_rolls_back_header_lines_and_lots_and_rejects_stale_lot_provenance(): void
    {
        $data = $this->payload();
        $data['lines'][0]['lots'] = [['product_unit_id' => $this->carton->id, 'quantity' => '1', 'expiry_date' => '2027-01-01']];
        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $data);
        $original = $purchase->fresh(['lines.lots'])->toJson();
        $edited = app(PurchaseDraftBuilder::class)->editableData($purchase);
        $edited['notes'] = 'Should rollback';
        $edited['lines'][0]['product_unit_id'] = $this->product->productUnits()->where('is_base', true)->firstOrFail()->id;
        $edited['lines'][0]['lots'][0]['product_unit_id'] = $edited['lines'][0]['product_unit_id'];
        try {
            app(UpdatePurchaseDraftAction::class)->execute($purchase, $this->owner, $edited);
            $this->fail('Old lot provenance survived unit change.');
        } catch (ValidationException) {
            $this->assertSame($original, $purchase->fresh(['lines.lots'])->toJson());
        }
        $edited['lines'][0]['lots'] = [];
        $result = app(UpdatePurchaseDraftAction::class)->execute($purchase, $this->owner, $edited);
        $this->assertCount(0, $result->lines->sole()->lots);
        $this->assertSame('2.000000', $result->lines->sole()->quantity_base);
    }

    public function test_duplicate_vendor_invoice_warning_is_trimmed_scoped_optional_and_non_blocking(): void
    {
        $first = $this->create();
        $detector = app(DuplicateVendorInvoice::class);
        $this->assertFalse($detector->exists($this->company->id, $this->vendor->id, ' SUP-001 ', $first->id));
        $second = $this->create();
        $this->assertTrue($detector->exists($this->company->id, $this->vendor->id, ' SUP-001 ', $second->id));
        CompanyPurchaseSetting::where('company_id', $this->company->id)->update(['warn_duplicate_vendor_invoice' => false]);
        $this->assertFalse($detector->exists($this->company->id, $this->vendor->id, 'SUP-001'));
        $this->assertDatabaseCount('purchases', 2);
    }

    public static function lifecycleAttempts(): array
    {
        return [['status', 'posted'], ['status', 'void'], ['purchase_number', 'PUR-1'], ['posted_at', '2026-10-03 00:00:00'], ['void_reason', 'bypass']];
    }

    #[DataProvider('lifecycleAttempts')]
    public function test_models_reject_direct_lifecycle_bypass_on_creation_and_update(string $field, string $value): void
    {
        $purchase = $this->create();
        try {
            $purchase->replicate()->fill([$field => $value])->save();
            $this->fail('Non-draft creation accepted.');
        } catch (ImmutableRecordException) {
            $this->assertDatabaseCount('purchases', 1);
        }
        try {
            $purchase->update([$field => $value]);
            $this->fail('Direct lifecycle update accepted.');
        } catch (ImmutableRecordException) {
            $this->assertSame('draft', $purchase->fresh()->status);
        }
    }

    public function test_future_posted_history_rejects_stale_header_line_and_lot_mutations(): void
    {
        $data = $this->payload();
        $data['lines'][0]['lots'] = [['product_unit_id' => $this->carton->id, 'quantity' => '1']];
        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $data);
        $line = $purchase->lines->sole();
        $lot = $line->lots->sole();
        // Simulate future history in the disposable fixture; Phase 5B exposes no posting path.
        DB::table('purchases')->where('id', $purchase->id)->update(['status' => 'posted']);
        foreach ([$purchase, $line, $lot] as $model) {
            foreach (['save', 'delete'] as $operation) {
                try {
                    $model->{$operation}();
                    $this->fail('Future posted history was mutable.');
                } catch (ImmutableRecordException) {
                    $this->assertTrue($model->exists);
                }
            }
        }
    }

    protected function customActor(array $permissions): User
    {
        $actor = User::factory()->create(['locale' => 'ar']);
        $this->company->users()->attach($actor->id, ['status' => 'active', 'is_owner' => false]);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'Custom '.Str::ulid(), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $actor->assignRole($role);
        $this->activate(null, $actor);

        return $actor;
    }

    public function test_foreign_purchase_ids_and_action_actor_mismatches_are_denied(): void
    {
        $purchase = $this->create();
        $other = $this->foreignFixtures();
        $this->activate($other['company'], $other['actor']);
        $this->get('/purchases/'.$purchase->public_id)->assertNotFound();
        $this->get('/purchases/'.$purchase->public_id.'/edit')->assertNotFound();
        $this->assertNull(Purchase::find($purchase->id));
        try {
            app(UpdatePurchaseDraftAction::class)->execute($purchase, $other['actor'], ['notes' => 'Wrong company']);
            $this->fail('Foreign action accepted.');
        } catch (AuthorizationException) {
            $this->assertNull(DB::table('purchases')->where('id', $purchase->id)->value('notes'));
        }
        $this->activate();
        auth()->logout();
        $this->expectException(AuthorizationException::class);
        app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $this->payload());
    }

    public function test_read_only_cost_redaction_covers_livewire_html_state_and_dto(): void
    {
        $purchase = $this->create(['lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '8765.43']]]);
        $this->customActor(['purchasing.purchase.view']);
        $detail = Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id]);
        $detail->assertSee('صابون')->assertDontSee('8,765.43')->assertDontSee('8765.430000')->assertDontSee('unit_cost')->assertDontSee('grand_total_currency');
        foreach (['unit_cost', 'grand_total_currency', 'lines', 'document', 'purchase'] as $field) {
            $this->assertArrayNotHasKey($field, $detail->instance()->all());
        }
        Livewire::test(PurchaseIndex::class)
            ->assertSee('مورد الاختبار')->assertDontSee('8,765.43')->assertDontSee('8765.430000');
        $dto = app(PurchaseReadModel::class)->detail($purchase, false);
        $this->assertArrayNotHasKey('grand_total_currency', $dto);
        $this->assertArrayNotHasKey('unit_cost', $dto['lines'][0]);
        $this->get('/purchases/create')->assertForbidden();
        $this->get('/purchases/'.$purchase->public_id.'/edit')->assertForbidden();
    }

    public static function stalePages(): array
    {
        $cases = [];
        foreach (['index', 'detail', 'create', 'edit'] as $page) {
            foreach (['permission', 'membership', 'company'] as $change) {
                $cases[$page.' '.$change] = [$page, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('stalePages')]
    public function test_stale_purchase_pages_fail_closed_after_authority_changes(string $page, string $change): void
    {
        $purchase = $this->create();
        $permissions = ['purchasing.purchase.view', 'purchasing.purchase.create', 'purchasing.purchase.edit_draft', 'purchasing.cost.view'];
        $actor = $this->customActor($permissions);
        $class = match ($page) {
            'index' => PurchaseIndex::class,
            'detail' => PurchaseDetail::class,
            default => PurchaseForm::class,
        };
        $component = Livewire::test($class, in_array($page, ['edit', 'detail'], true) ? ['publicId' => $purchase->public_id] : []);
        if ($change === 'permission') {
            $actor->roles()->firstOrFail()->revokePermissionTo(in_array($page, ['index', 'detail'], true) ? 'purchasing.purchase.view' : 'purchasing.cost.view');
        } elseif ($change === 'membership') {
            DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $actor->id)->update(['status' => 'inactive']);
        } else {
            $other = $this->foreignFixtures();
            $other['company']->users()->attach($actor->id, ['status' => 'active', 'is_owner' => false]);
            $this->activate($other['company'], $actor);
        }
        $component->call(in_array($page, ['edit', 'create'], true) ? 'save' : '$refresh')->assertForbidden();
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public static function locales(): array
    {
        return [['ar', 'مسودة مشتريات جديدة', 'رقم فاتورة المورد', 'التشغيلات المخطط استلامها'],
            ['en', 'New Purchase Draft', 'Supplier Invoice Number', 'Planned Receiving Lots']];
    }

    #[DataProvider('locales')]
    public function test_localized_form_detail_index_and_safe_unit_change(string $locale, string $title, string $supplierLabel, string $lotLabel): void
    {
        app()->setLocale($locale);
        $this->owner->update(['locale' => $locale]);
        $before = $this->effects();
        $form = Livewire::test(PurchaseForm::class)
            ->assertSee($title)->assertSee($supplierLabel)->assertSet('due_date', null)
            ->call('selectProduct', 0, $this->product->id)->assertSet('lines.0.product_unit_id', $this->carton->id)
            ->assertSee($lotLabel)->call('addLot', 0);
        $this->assertCount(1, $form->get('lines.0.lots'));
        $form->call('changeUnit', 0, $this->product->productUnits()->where('is_base', true)->firstOrFail()->id)->assertSet('lines.0.lots', []);
        $form->set('vendor_id', $this->vendor->id)->set('vendor_invoice_number', 'LOCAL')->call('save')->assertHasNoErrors();
        $purchase = Purchase::firstOrFail();
        Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])->assertSee($supplierLabel);
        Livewire::test(PurchaseForm::class, ['publicId' => $purchase->public_id])->assertSet('due_date', null);
        Livewire::test(PurchaseIndex::class)->set('search', 'LOCAL')->assertSee('LOCAL');
        $this->assertSame($before, $this->effects());
    }

    public function test_vendor_locale_defaults_fallback_and_saved_exchange_rate_are_independent_of_current_settings(): void
    {
        $this->vendor->update(['preferred_locale' => 'en']);
        $data = $this->payload(['currency_code' => 'USD', 'exchange_rate' => '3.5']);
        unset($data['document_locale']);
        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $data);
        $this->assertSame('en', $purchase->document_locale);
        $this->assertSame('3.5000000000', $purchase->exchange_rate);
        $purchase = app(UpdatePurchaseDraftAction::class)->execute($purchase, $this->owner, ['notes' => 'Keep snapshot']);
        $this->assertSame('3.5000000000', $purchase->exchange_rate);
        $this->company->languages()->where('locale', 'en')->update(['enabled' => false]);
        $this->assertSame('ar', app(PurchaseDocumentRules::class)->defaultLocale($this->company, $this->vendor));
    }

    public function test_permission_catalog_and_existing_role_upgrade_preserve_customization(): void
    {
        $purchasing = Role::where('company_id', $this->company->id)->where('name', 'Purchasing')->firstOrFail();
        $this->assertTrue($purchasing->hasPermissionTo('purchasing.cost.view'));
        $this->assertTrue($purchasing->hasPermissionTo('purchasing.purchase.edit_draft'));
        $this->assertFalse($purchasing->hasPermissionTo('inventory.cost.view'));
        $purchasing->revokePermissionTo('purchasing.cost.view', 'purchasing.purchase.edit_draft');
        $before = $purchasing->permissions()->pluck('name')->sort()->values()->all();
        app(CompanyContext::class)->clear();
        app(EnsurePurchasingFoundationAction::class)->execute($this->company);
        $this->activate();
        $this->assertSame($before, $purchasing->fresh()->permissions()->pluck('name')->sort()->values()->all());
        $this->assertTrue($this->owner->hasPermissionTo('purchasing.cost.view'));
    }

    public function test_numeric_form_errors_and_empty_tax_selection_are_safe(): void
    {
        $form = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.unit_cost', '')->assertHasErrors('calculation')->call('save')->assertHasErrors('draft');
        $this->assertDatabaseCount('purchases', 0);
        $form->set('lines.0.unit_cost', '24')->set('lines.0.tax_rate_id', '')->call('save')->assertHasNoErrors();
        $this->assertNull(Purchase::firstOrFail()->lines->sole()->tax_rate_id);
    }

    public function test_cost_permission_loss_redacts_stale_reads_and_cannot_be_overridden_by_dto_flag(): void
    {
        $purchase = $this->create(['lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '8765.43']]]);
        $actor = $this->customActor(['purchasing.purchase.view', 'purchasing.cost.view']);
        $detail = Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])->assertSee('8,765.43');
        $actor->roles()->firstOrFail()->revokePermissionTo('purchasing.cost.view');
        $detail->call('$refresh')->assertDontSee('8,765.43')->assertDontSee('8765.430000');
        $dto = app(PurchaseReadModel::class)->detail($purchase, true);
        $this->assertArrayNotHasKey('grand_total_currency', $dto);
        $this->assertArrayNotHasKey('unit_cost', $dto['lines'][0]);
    }

    public function test_rounded_document_totals_are_exact_sums_of_stored_lines(): void
    {
        $purchase = $this->create(['currency_code' => 'JOD', 'exchange_rate' => '5.1234567890', 'lines' => [
            ['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '0.0005'],
            ['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '0.0005'],
        ]]);
        $this->assertSame('0.002000', $purchase->grand_total_currency);
        $this->assertSame('0.010246', $purchase->grand_total_base);
        $this->assertSame($purchase->grand_total_base, (string) BigDecimal::of($purchase->lines[0]->line_total_base)->plus($purchase->lines[1]->line_total_base));
    }

    public function test_receiving_warehouse_defaults_fail_closed_and_do_not_change_stock(): void
    {
        $before = $this->effects();
        $rules = app(PurchaseDocumentRules::class);
        $default = Warehouse::firstOrFail();
        $other = Warehouse::create(['company_id' => $this->company->id, 'code' => 'OTHER', 'name_ar' => 'بديل', 'active' => true]);
        CompanyPurchaseSetting::where('company_id', $this->company->id)->update(['default_receiving_warehouse_id' => $other->id]);
        $this->assertSame($other->id, $rules->warehouse($this->company, null)->id);
        $other->update(['active' => false]);
        $this->assertSame($default->id, $rules->warehouse($this->company, null)->id);
        $default->update(['active' => false]);
        try {
            $rules->warehouse($this->company, null);
            $this->fail('Inactive defaults were accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertSame($before, $this->effects());
        }
    }

    public function test_direct_line_and_lot_provenance_and_ownership_changes_are_rejected(): void
    {
        $data = $this->payload();
        $data['lines'][0]['lots'] = [['product_unit_id' => $this->carton->id, 'quantity' => '1']];
        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $data);
        foreach ([[$purchase, 'public_id', (string) Str::ulid()],
            [$purchase->lines->sole(), 'stock_movement_id', 1],
            [$purchase->lines->sole()->lots->sole(), 'created_inventory_lot_id', 1]] as [$model, $field, $value]) {
            try {
                $model->update([$field => $value]);
                $this->fail('Reserved provenance was accepted.');
            } catch (ImmutableRecordException) {
                $this->assertNotEquals($value, $model->fresh()->getAttribute($field));
            }
        }
    }

    public function test_direct_model_decimal_inputs_reject_floats_before_persistence(): void
    {
        $line = $this->create()->lines->sole();
        foreach (['unit_conversion_ratio' => 12.0, 'tax_rate_snapshot' => 16.0] as $field => $value) {
            try {
                $line->fresh()->update([$field => $value]);
                $this->fail('Float persisted in Purchase line decimal field.');
            } catch (InvalidQuantityException|\InvalidArgumentException) {
                $this->assertSame($line->getRawOriginal($field), $line->fresh()->getAttribute($field));
            }
        }
    }
}
