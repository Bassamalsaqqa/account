<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5B;

use App\Actions\Purchasing\DeletePurchaseDraftAction;
use App\Livewire\Pages\Purchasing\PurchaseDetail;
use App\Models\Purchase;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class PurchaseDraftDeletionTest extends PurchaseDraftTest
{
    public function test_deletion_removes_lines_and_lots_atomically(): void
    {
        $purchase = $this->create();
        app(DeletePurchaseDraftAction::class)->execute($purchase, $this->owner);
        $this->assertDatabaseMissing('purchases', ['id' => $purchase->id]);
        $this->assertDatabaseMissing('purchase_lines', ['purchase_id' => $purchase->id]);
    }

    public function test_detail_action_deletes_and_redirects(): void
    {
        $purchase = $this->create();
        Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->call('deleteDraft', $purchase->public_id)
            ->assertRedirect(route('purchases.index'));
        $this->assertDatabaseHas('audit_events', ['event_key' => 'purchase.draft.deleted']);
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

    public function test_role_without_owner_cannot_delete(): void
    {
        $purchase = $this->create();
        $this->owner->removeRole('Owner');
        $this->expectException(AuthorizationException::class);
        app(DeletePurchaseDraftAction::class)->execute($purchase, $this->owner);
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
