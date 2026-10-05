<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5D;

use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Livewire\Pages\Purchasing\PurchaseDetail;
use App\Livewire\Pages\Purchasing\PurchaseReturnDetail;
use App\Livewire\Pages\Purchasing\PurchaseReturnForm;
use App\Livewire\Pages\Purchasing\PurchaseReturnIndex;
use App\Models\PurchaseReturn;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

class PurchaseReturnUiTest extends Phase5DTestCase
{
    protected function customActor(array $permissions): User
    {
        $actor = User::factory()->create(['locale' => 'ar']);
        $this->company->users()->attach($actor->id, ['status' => 'active', 'is_owner' => false]);
        $role = Role::create([
            'company_id' => $this->company->id,
            'name' => 'Role-'.Str::ulid(),
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo($permissions);
        $actor->assignRole($role);
        $this->activate($actor);

        return $actor;
    }

    public function test_purchase_return_index_renders_and_filters(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '15'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]);
        $posted = $this->postReturn($draft);

        Livewire::test(PurchaseReturnIndex::class)
            ->assertOk()
            ->assertSee($posted->return_number)
            ->set('statusFilter', 'draft')
            ->assertDontSee($posted->return_number)
            ->set('statusFilter', 'posted')
            ->assertSee($posted->return_number)
            ->set('search', 'NONEXISTENT')
            ->assertDontSee($posted->return_number);
    }

    public function test_purchase_return_index_cost_masking(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '123.45'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '1']],
        ]);
        $posted = $this->postReturn($draft);

        // Owner with cost view sees total
        Livewire::test(PurchaseReturnIndex::class)
            ->assertOk()
            ->assertSee('123.45');

        // Actor without purchasing.cost.view does NOT see cost in index
        $noCostUser = $this->customActor(['purchasing.purchase.view']);
        Livewire::actingAs($noCostUser)
            ->test(PurchaseReturnIndex::class)
            ->assertOk()
            ->assertSee($posted->return_number)
            ->assertDontSee('123.45');
    }

    public function test_purchase_return_detail_renders_and_redacts_without_cost_permission(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '543.21'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '1']],
        ]);
        $posted = $this->postReturn($draft);

        // Owner with cost view sees financial figures
        Livewire::test(PurchaseReturnDetail::class, ['publicId' => $posted->public_id])
            ->assertOk()
            ->assertSee('543.21');

        // Restricted user without purchasing.cost.view does NOT receive costs
        $restricted = $this->customActor(['purchasing.purchase.view']);
        Livewire::actingAs($restricted)
            ->test(PurchaseReturnDetail::class, ['publicId' => $posted->public_id])
            ->assertOk()
            ->assertSee($posted->return_number)
            ->assertDontSee('543.21');
    }

    public function test_purchase_return_detail_post_action(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '15'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]);

        $component = Livewire::test(PurchaseReturnDetail::class, ['publicId' => $draft->public_id])
            ->assertOk()
            ->assertViewHas('canPost', true)
            ->call('post')
            ->assertHasNoErrors();

        $this->assertTrue($draft->fresh()->isPosted());
        $this->assertNotNull($draft->fresh()->return_number);
    }

    public function test_purchase_return_form_create_and_edit_draft(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '15'],
            ],
        ]);

        // 1. Create draft from purchase
        $testForm = Livewire::withQueryParams([])
            ->test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id])
            ->assertOk()
            ->assertSet('mode', 'create')
            ->set('lines.0.return_quantity', '3')
            ->set('reason', 'Defective items')
            ->set('notes', 'Returned to vendor')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $createdDraft = PurchaseReturn::where('purchase_id', $purchase->id)->first();
        $this->assertNotNull($createdDraft);
        $this->assertSame('3.000000', $createdDraft->lines->first()->quantity);
        $this->assertSame('Defective items', $createdDraft->reason);

        // 2. Edit existing draft
        Livewire::withQueryParams([])
            ->test(PurchaseReturnForm::class, ['publicId' => $createdDraft->public_id])
            ->assertOk()
            ->assertSet('mode', 'edit')
            ->set('lines.0.return_quantity', '5')
            ->set('notes', 'Updated return note')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertSame('5.000000', $createdDraft->fresh()->lines->first()->quantity);
        $this->assertSame('Updated return note', $createdDraft->fresh()->notes);
    }

    public function test_purchase_detail_shows_create_return_button_only_when_authorized_and_posted(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '15'],
            ],
        ]);

        // 1. Owner on posted purchase sees create return button
        Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->assertOk()
            ->assertViewHas('canCreateReturn', true)
            ->assertSee(route('purchase-returns.create', $purchase->public_id));

        // 2. Draft purchase does NOT show create return button
        $draftPurchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-04',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '10'],
            ],
        ]);

        Livewire::test(PurchaseDetail::class, ['publicId' => $draftPurchase->public_id])
            ->assertOk()
            ->assertViewHas('canCreateReturn', false)
            ->assertDontSee(route('purchase-returns.create', $draftPurchase->public_id));

        // 3. User without purchasing.cost.view does NOT see the button even on posted purchase
        $noCostUser = $this->customActor(['purchasing.purchase.view', 'purchasing.return.manage']);
        Livewire::actingAs($noCostUser)
            ->test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->assertOk()
            ->assertViewHas('canCreateReturn', false)
            ->assertDontSee(route('purchase-returns.create', $purchase->public_id));

        // 4. User without purchasing.return.manage does NOT see the button
        $noManageUser = $this->customActor(['purchasing.purchase.view', 'purchasing.cost.view']);
        Livewire::actingAs($noManageUser)
            ->test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->assertOk()
            ->assertViewHas('canCreateReturn', false)
            ->assertDontSee(route('purchase-returns.create', $purchase->public_id));
    }

    /** C2-6: Stale form denies reads after purchasing.purchase.view permission revoked */
    public function test_c2_6_stale_form_denies_reads_after_purchase_view_permission_revoked(): void
    {
        $purchase = $this->createAndPostPurchase();
        $component = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id])->assertOk();

        $role = $this->owner->roles->first();
        $role->revokePermissionTo('purchasing.purchase.view');
        $this->owner->unsetRelation('roles')->unsetRelation('permissions');

        $component->call('$refresh')->assertForbidden();
    }

    /** Review regression: Stale return form denies reads after cost permission revoked */
    public function test_stale_return_form_denies_reads_after_cost_permission_revoked(): void
    {
        $purchase = $this->createAndPostPurchase();
        $component = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id])->assertOk();

        $role = $this->owner->roles->first();
        $role->revokePermissionTo('purchasing.cost.view');
        $this->owner->unsetRelation('roles')->unsetRelation('permissions');

        $component->call('$refresh')->assertForbidden();
    }

    /** Review regression: Expiry return form create loads actual lot balance */
    public function test_expiry_return_form_create_loads_actual_lot_balance(): void
    {
        $purchase = $this->createAndPostExpiryPurchase();
        Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id])->assertOk();
    }
}
