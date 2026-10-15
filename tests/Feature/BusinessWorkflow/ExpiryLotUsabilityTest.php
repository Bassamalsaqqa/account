<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessWorkflow;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Company\UpdateExpiryWarningDaysAction;
use App\Application\Inventory\AvailableLotQuery;
use App\Livewire\Pages\Inventory\ExpiryCenter;
use App\Livewire\Pages\Inventory\StockAdjustmentForm;
use App\Livewire\Pages\Inventory\StockTransferForm;
use App\Models\CompanyInventorySettings;
use App\Models\InventoryLot;
use App\Models\PostingBatch;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Services\Inventory\ProductCatalogService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Feature\Phase8\PositionTestCase;

final class ExpiryLotUsabilityTest extends PositionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_warning_setting_only_changes_warning_days_and_is_audited_without_economic_writes(): void
    {
        $before = CompanyInventorySettings::whereKey($this->company->id)->firstOrFail()->toArray();
        $this->assertZeroEconomicWrites(function (): void {
            Livewire::test(ExpiryCenter::class)->set('warningDays', '14')->call('saveWarningDays')->assertHasNoErrors();
        });
        $after = CompanyInventorySettings::whereKey($this->company->id)->firstOrFail()->toArray();
        $this->assertSame(14, $after['default_expiry_warning_days']);
        unset($before['updated_at'], $after['updated_at'], $before['default_expiry_warning_days'], $after['default_expiry_warning_days']);
        $this->assertSame($before, $after);
        $this->assertDatabaseHas('audit_events', ['company_id' => $this->company->id, 'event_key' => 'company.expiry_warning_updated']);
    }

    public function test_warning_window_is_inclusive_and_does_not_reclassify_expired_lots(): void
    {
        app(UpdateExpiryWarningDaysAction::class)->execute($this->company, 14, $this->owner);
        foreach (['yesterday' => '2026-10-14', 'today' => '2026-10-15', 'boundary' => '2026-10-29', 'outside' => '2026-10-30', 'unknown' => null] as $name => $expiry) {
            $this->postOpeningStock($this->stockProduct, $this->warehouseA, '3', '17.123456', lotNumber: $name, expiryDate: $expiry);
        }
        $this->assertZeroEconomicWrites(function (): void {
            Livewire::test(ExpiryCenter::class)->set('timeframeFilter', 'warning')
                ->assertViewHas('savedWarningDays', 14)
                ->assertViewHas('lotBalances', fn ($lots) => $lots->pluck('lot.lot_number')->all() === ['today', 'boundary']);
            Livewire::test(ExpiryCenter::class)->set('timeframeFilter', 'expired')
                ->assertViewHas('lotBalances', fn ($lots) => $lots->pluck('lot.lot_number')->all() === ['yesterday']);
        });
    }

    public function test_invalid_warning_values_do_not_save_and_readers_cannot_change_the_preference(): void
    {
        foreach (['0', '366', '1.5', 'abc', ''] as $value) {
            Livewire::test(ExpiryCenter::class)->set('warningDays', $value)->call('saveWarningDays')->assertHasErrors('warningDays');
        }
        $this->assertSame(30, CompanyInventorySettings::whereKey($this->company->id)->value('default_expiry_warning_days'));
        $reader = $this->customActor(['inventory.stock.view']);
        $this->activate($reader);
        Livewire::test(ExpiryCenter::class)->assertDontSee(__('inventory.save_warning'))->set('warningDays', '14')->call('saveWarningDays')->assertForbidden();
        $this->assertSame(30, CompanyInventorySettings::whereKey($this->company->id)->value('default_expiry_warning_days'));
    }

    public function test_action_rechecks_revoked_permissions_and_cross_company_context(): void
    {
        $actor = $this->customActor(['inventory.stock.view', 'settings.company.manage']);
        $this->activate($actor);
        $component = Livewire::test(ExpiryCenter::class);
        Role::where('company_id', $this->company->id)->where('name', 'CustomRole_'.$actor->id)->firstOrFail()->revokePermissionTo('settings.company.manage');
        $component->set('warningDays', '10')->call('saveWarningDays')->assertForbidden();
        $this->activate($this->owner);
        $context = app(CompanyContext::class);
        $context->clear();
        $other = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Synthetic other company', 'base_currency_code' => 'ILS']);
        $context->setCompany($this->company, $this->owner);
        try {
            app(UpdateExpiryWarningDaysAction::class)->execute($other, 10, $this->owner);
            $this->fail('A different company must be rejected.');
        } catch (AuthorizationException) {
            $this->assertSame(30, CompanyInventorySettings::withoutGlobalScopes()->whereKey($other->id)->value('default_expiry_warning_days'));
        }
    }

    public function test_lot_picker_has_stable_expiry_order_base_units_status_and_no_costs_in_both_languages(): void
    {
        foreach (['unknown' => null, 'later' => '2026-11-01', 'today' => '2026-10-15', 'expired' => '2026-10-14'] as $name => $expiry) {
            $this->postOpeningStock($this->stockProduct, $this->warehouseA, '3', '17.123456', lotNumber: $name, expiryDate: $expiry);
        }
        $lots = app(AvailableLotQuery::class)->get($this->company->id, $this->stockProduct->id, $this->warehouseA->id);
        $this->assertSame(['expired', 'today', 'later', 'unknown'], $lots->pluck('lot.lot_number')->all());
        $reader = $this->customActor(['inventory.stock.transfer', 'inventory.stock.adjust']);
        $this->activate($reader);
        $this->assertZeroEconomicWrites(function (): void {
            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                Livewire::test(StockTransferForm::class)->set('source_warehouse_id', $this->warehouseA->id)->set('product_id', $this->stockProduct->id)
                    ->assertSee(__('inventory.base_available'))->assertSee($this->baseUnit->name())->assertSee(__('inventory.lot_expired_status'))
                    ->assertSee(__('inventory.lot_today_status'))->assertSee(__('inventory.lot_unknown_expiry'))->assertDontSee('17.123456');
                Livewire::test(StockAdjustmentForm::class)->set('warehouse_id', $this->warehouseA->id)->set('product_id', $this->stockProduct->id)
                    ->set('type', StockMovement::TYPE_ADJUSTMENT_DECREASE)->assertSee(__('inventory.base_available'))->assertDontSee('17.123456');
            }
        });
    }

    public function test_product_warehouse_and_adjustment_direction_changes_clear_lot_and_unit_state(): void
    {
        $movement = $this->postOpeningStock($this->stockProduct, $this->warehouseA, '3', '17', lotNumber: 'reset', expiryDate: '2026-11-01');
        Livewire::test(StockTransferForm::class)->set('product_id', $this->stockProduct->id)->assertSet('unit_id', $this->baseUnit->id)
            ->set('lot_id', $movement->lot_id)->set('source_warehouse_id', $this->warehouseB->id)->assertSet('lot_id', null)
            ->assertSee(__('inventory.no_available_lots'))->set('product_id', null)->assertSet('unit_id', null);
        Livewire::test(StockAdjustmentForm::class)->set('product_id', $this->stockProduct->id)->set('lot_id', $movement->lot_id)
            ->set('warehouse_id', $this->warehouseB->id)->assertSet('lot_id', null)
            ->set('lot_id', $movement->lot_id)->set('type', StockMovement::TYPE_ADJUSTMENT_DECREASE)->assertSet('lot_id', null)
            ->set('product_id', null)->assertSet('unit_id', null);
    }

    public function test_transfer_retry_after_depleting_lot_reuses_the_canonical_result(): void
    {
        $movement = $this->postOpeningStock($this->stockProduct, $this->warehouseA, '3', '17', lotNumber: 'retry', expiryDate: '2026-11-01');
        $form = Livewire::test(StockTransferForm::class)->set('product_id', $this->stockProduct->id)
            ->set('source_warehouse_id', $this->warehouseA->id)->set('destination_warehouse_id', $this->warehouseB->id)
            ->set('quantity', '3')->set('lot_id', $movement->lot_id);
        $key = $form->get('formIdempotencyKey');
        $form->call('save')->assertHasNoErrors()->assertSet('errorMessage', null);
        $before = [StockMovement::count(), PostingBatch::count()];
        $form->set('formIdempotencyKey', $key)->set('quantity', '3')->set('lot_id', $movement->lot_id)->call('save')->assertSet('errorMessage', null);
        $this->assertSame($before, [StockMovement::count(), PostingBatch::count()]);
    }

    public function test_foreign_product_hook_and_company_switch_cannot_expose_lot_or_unit_data(): void
    {
        $form = Livewire::test(StockTransferForm::class)->set('product_id', $this->stockProduct->id);
        $context = app(CompanyContext::class);
        $context->clear();
        $other = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Synthetic other company', 'base_currency_code' => 'ILS']);
        $context->setCompany($other, $this->owner);
        $foreign = app(ProductCatalogService::class)->createProduct($other, [
            'name_ar' => 'Foreign product must not render', 'sku' => 'FOREIGN-LOT', 'product_type' => 'stock',
            'base_unit_id' => Unit::where('company_id', $other->id)->firstOrFail()->id,
            'track_stock' => true, 'track_expiry' => true, 'active' => true,
        ], $this->owner->id);
        $form->set('quantity', '1')->assertForbidden();
        $context->setCompany($this->company, $this->owner);
        Livewire::test(StockAdjustmentForm::class)->set('product_id', $foreign->id)->assertSet('unit_id', null)->assertDontSee('Foreign product must not render');
        $this->assertSame(0, InventoryLot::withoutGlobalScopes()->where('company_id', $other->id)->count());
    }

    public function test_a_stale_lot_cannot_commit_a_transfer_from_the_old_source_warehouse(): void
    {
        $movement = $this->postOpeningStock($this->stockProduct, $this->warehouseA, '3', '17', lotNumber: 'stale', expiryDate: '2026-11-01');
        $form = Livewire::test(StockTransferForm::class)->set('product_id', $this->stockProduct->id)
            ->set('source_warehouse_id', $this->warehouseA->id)->set('destination_warehouse_id', $this->warehouseB->id)
            ->set('quantity', '1')->set('lot_id', $movement->lot_id);
        $this->postStockTransfer($this->warehouseA, $this->warehouseB, $this->stockProduct, '3', lotId: $movement->lot_id);
        $this->assertZeroEconomicWrites(function () use ($form): void {
            $form->call('save');
            $this->assertNotNull($form->get('errorMessage'));
        });
    }

    public function test_expiry_modal_rechecks_adjust_permission_after_the_page_was_opened(): void
    {
        $movement = $this->postOpeningStock($this->stockProduct, $this->warehouseA, '3', '17', lotNumber: 'disposal', expiryDate: '2026-10-14');
        $actor = $this->customActor(['inventory.stock.view', 'inventory.stock.adjust']);
        $this->activate($actor);
        $component = Livewire::test(ExpiryCenter::class);
        Role::where('company_id', $this->company->id)->where('name', 'CustomRole_'.$actor->id)->firstOrFail()->revokePermissionTo('inventory.stock.adjust');
        $balanceId = $movement->lot->balances()->where('warehouse_id', $this->warehouseA->id)->firstOrFail()->id;
        $this->assertZeroEconomicWrites(fn () => $component->call('openDisposalModal', $balanceId)->assertForbidden());
    }
}
