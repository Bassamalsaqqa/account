<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5B;

use App\Actions\Purchasing\DeletePurchaseDraftAction;
use App\Livewire\Pages\Purchasing\PurchaseDetail;
use App\Livewire\Pages\Purchasing\PurchaseIndex;
use App\Models\CompanyUser;
use App\Models\Purchase;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

class PurchaseDraftDeletionTest extends PurchaseDraftTest
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:VHEDqS0eK6A+vPgmWfUYk7lE44lioPBuuJS4nAGdEeE=']);
        }
    }

    public function test_deletion_removes_lines_and_lots_atomically(): void
    {
        $purchase = $this->create();
        app(DeletePurchaseDraftAction::class)->execute($purchase, $this->owner);
        $this->assertDatabaseMissing('purchases', ['id' => $purchase->id]);
        $this->assertDatabaseMissing('purchase_lines', ['purchase_id' => $purchase->id]);
    }

    public function test_detail_action_parameterless_deletes_and_redirects(): void
    {
        $purchase = $this->create();
        Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->call('deleteDraft')
            ->assertRedirect(route('purchases.index'));
        $this->assertDatabaseMissing('purchases', ['id' => $purchase->id]);
        $this->assertDatabaseHas('audit_events', ['event_key' => 'purchase.draft.deleted']);
    }

    public function test_detail_action_with_matching_param_deletes_and_redirects(): void
    {
        $purchase = $this->create();
        Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->call('deleteDraft', $purchase->public_id)
            ->assertRedirect(route('purchases.index'));
        $this->assertDatabaseMissing('purchases', ['id' => $purchase->id]);
        $this->assertDatabaseHas('audit_events', ['event_key' => 'purchase.draft.deleted']);
    }

    public function test_detail_action_rejects_cross_detail_argument(): void
    {
        $purchase = $this->create();
        $otherPurchase = $this->create(['vendor_invoice_number' => 'SUP-OTHER-99']);

        Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->call('deleteDraft', $otherPurchase->public_id)
            ->assertForbidden();

        $this->assertDatabaseHas('purchases', ['id' => $purchase->id]);
        $this->assertDatabaseHas('purchases', ['id' => $otherPurchase->id]);
        $this->assertDatabaseMissing('audit_events', ['event_key' => 'purchase.draft.deleted']);
    }

    public function test_list_delete_resets_page_preserves_filters(): void
    {
        $purchase = $this->create();

        Livewire::test(PurchaseIndex::class)
            ->set('statusFilter', 'draft')
            ->set('search', 'SOAP')
            ->call('deleteDraft', $purchase->public_id)
            ->assertSet('statusFilter', 'draft')
            ->assertSet('search', 'SOAP')
            ->assertSee(__('purchase_draft_delete.deleted'))
            ->assertNoRedirect();

        $this->assertDatabaseMissing('purchases', ['id' => $purchase->id]);
        $this->assertDatabaseHas('audit_events', ['event_key' => 'purchase.draft.deleted']);
    }

    public function test_payment_modal_events_regression_closes_modal_without_deleting(): void
    {
        $draft = $this->create();
        $posted = $this->create(['vendor_invoice_number' => 'INV-POSTED-01']);
        DB::table('purchases')->where('id', $posted->id)->update(['status' => Purchase::STATUS_POSTED]);

        $testable = Livewire::test(PurchaseIndex::class)
            ->call('openPaymentModal', $posted->id);

        $this->assertSame($posted->id, $testable->get('payingPurchaseId'));

        $testable->dispatch('close-payment-modal');
        $this->assertNull($testable->get('payingPurchaseId'));
        $this->assertDatabaseHas('purchases', ['id' => $draft->id]);

        $testable->call('openPaymentModal', $posted->id);
        $this->assertSame($posted->id, $testable->get('payingPurchaseId'));

        $testable->dispatch('vendor-payment-recorded');
        $this->assertNull($testable->get('payingPurchaseId'));
        $this->assertDatabaseHas('purchases', ['id' => $draft->id]);
        $this->assertDatabaseMissing('audit_events', ['event_key' => 'purchase.draft.deleted']);
    }

    public function test_repeat_delete_cannot_create_a_second_audit(): void
    {
        $purchase = $this->create();
        app(DeletePurchaseDraftAction::class)->execute($purchase, $this->owner);
        try {
            app(DeletePurchaseDraftAction::class)->execute($purchase, $this->owner);
            $this->fail('Deleted draft must not be recreated');
        } catch (ModelNotFoundException) {
            $this->assertSame(1, DB::table('audit_events')->where('event_key', 'purchase.draft.deleted')->count());
        }
    }

    public function test_confirmation_receipt_blocks_delete_and_preserves_retry_provenance(): void
    {
        $purchase = $this->create();
        DB::table('purchase_confirmation_receipts')->insert([
            'company_id' => $this->company->id, 'actor_id' => $this->owner->id,
            'purchase_id' => $purchase->id, 'request_key' => (string) Str::uuid(),
            'payload_digest' => str_repeat('a', 64), 'confirmation' => '{}', 'created_at' => now(),
        ]);
        try {
            app(DeletePurchaseDraftAction::class)->execute($purchase, $this->owner);
            $this->fail('Retry provenance must survive');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('deleteDraft', $e->errors());
        }
        $this->assertDatabaseHas('purchase_confirmation_receipts', ['purchase_id' => $purchase->id]);
        $this->assertDatabaseHas('purchase_lines', ['purchase_id' => $purchase->id]);
    }

    public function test_unauthenticated_user_cannot_delete(): void
    {
        $purchase = $this->create();
        auth()->logout();
        $this->expectException(AuthorizationException::class);
        app(DeletePurchaseDraftAction::class)->execute($purchase, $this->owner);
    }

    public function test_revoked_membership_cannot_delete(): void
    {
        $purchase = $this->create();
        CompanyUser::where('company_id', $this->company->id)
            ->where('user_id', $this->owner->id)
            ->update(['status' => 'inactive']);

        $this->expectException(AuthorizationException::class);
        app(DeletePurchaseDraftAction::class)->execute($purchase, $this->owner);
    }

    public function test_cashier_role_cannot_delete(): void
    {
        $purchase = $this->create();
        $cashier = User::factory()->create(['locale' => 'ar']);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $cashier->id,
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->company->id);
        $cashierRole = Role::where('company_id', $this->company->id)->where('name', 'Cashier')->firstOrFail();
        $cashier->assignRole($cashierRole);

        $this->activate($this->company, $cashier);
        $this->expectException(AuthorizationException::class);
        app(DeletePurchaseDraftAction::class)->execute($purchase, $cashier);
    }

    public function test_sales_manager_role_cannot_delete(): void
    {
        $purchase = $this->create();
        $manager = User::factory()->create(['locale' => 'ar']);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $manager->id,
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->company->id);
        $managerRole = Role::where('company_id', $this->company->id)->where('name', 'Manager')->firstOrFail();
        $manager->assignRole($managerRole);

        $this->activate($this->company, $manager);
        $this->expectException(AuthorizationException::class);
        app(DeletePurchaseDraftAction::class)->execute($purchase, $manager);
    }

    public function test_role_without_owner_cannot_delete(): void
    {
        $purchase = $this->create();
        $this->owner->removeRole('Owner');
        $this->expectException(AuthorizationException::class);
        app(DeletePurchaseDraftAction::class)->execute($purchase, $this->owner);
    }

    public function test_owner_without_cost_view_cannot_delete(): void
    {
        $purchase = $this->create();
        setPermissionsTeamId($this->company->id);
        $ownerRole = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();
        $ownerRole->revokePermissionTo('purchasing.cost.view');
        $this->activate($this->company, $this->owner);

        $this->expectException(AuthorizationException::class);
        app(DeletePurchaseDraftAction::class)->execute($purchase, $this->owner);
    }

    public function test_owner_without_edit_draft_cannot_delete(): void
    {
        $purchase = $this->create();
        setPermissionsTeamId($this->company->id);
        $ownerRole = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();
        $ownerRole->revokePermissionTo('purchasing.purchase.edit_draft');
        $this->activate($this->company, $this->owner);

        $this->expectException(AuthorizationException::class);
        app(DeletePurchaseDraftAction::class)->execute($purchase, $this->owner);
    }

    public function test_foreign_company_public_id_cannot_be_deleted(): void
    {
        $foreignCompanyId = (int) DB::table('companies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'name_ar' => 'شركة أجنبية',
            'name_en' => 'Foreign Co',
            'base_currency_code' => 'ILS',
            'status' => 'active',
            'timezone' => 'Asia/Gaza',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreignPurchase = $this->create(['vendor_invoice_number' => 'FOREIGN-INV-01']);
        DB::table('purchases')->where('id', $foreignPurchase->id)->update(['company_id' => $foreignCompanyId]);

        Livewire::test(PurchaseDetail::class, ['publicId' => $foreignPurchase->public_id])
            ->assertNotFound();

        Livewire::test(PurchaseIndex::class)
            ->call('deleteDraft', $foreignPurchase->public_id)
            ->assertNotFound();

        $this->assertDatabaseHas('purchases', ['id' => $foreignPurchase->id, 'company_id' => $foreignCompanyId]);
    }

    public function test_cross_company_context_cannot_delete(): void
    {
        $purchase = $this->create();
        app(CompanyContext::class)->clear();
        $this->expectException(AuthorizationException::class);
        app(DeletePurchaseDraftAction::class)->execute($purchase, $this->owner);
    }

    public function test_late_failure_rolls_back_audit_lines_and_header(): void
    {
        $purchase = $this->create();
        try {
            DB::transaction(function () use ($purchase): void {
                app(DeletePurchaseDraftAction::class)->execute($purchase, $this->owner);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            $this->assertDatabaseHas('purchases', ['id' => $purchase->id]);
            $this->assertDatabaseHas('purchase_lines', ['purchase_id' => $purchase->id]);
            $this->assertDatabaseMissing('audit_events', ['event_key' => 'purchase.draft.deleted']);
        }
    }

    public function test_stale_draft_cannot_delete_now_posted_record(): void
    {
        $purchase = $this->create();
        DB::table('purchases')->where('id', $purchase->id)->update(['status' => Purchase::STATUS_POSTED]);
        try {
            app(DeletePurchaseDraftAction::class)->execute($purchase, $this->owner);
            $this->fail('Posted history must survive');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('deleteDraft', $e->errors());
        }
        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'status' => Purchase::STATUS_POSTED]);
    }
}
