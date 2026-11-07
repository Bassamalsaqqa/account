<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Purchasing\PurchaseFormConfirmation;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Phase5E\Phase5ETestCase;

final class PurchaseFormSnapshotReplayTest extends Phase5ETestCase
{
    public static function modes(): array
    {
        return ['new unpaid' => [false, false], 'existing unpaid' => [true, false], 'new paid' => [false, true], 'existing paid' => [true, true]];
    }

    private function form(bool $existing, bool $paid): Testable
    {
        $form = Livewire::test(PurchaseForm::class)->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '2')->set('lines.0.unit_cost', '25.125');
        if ($existing) {
            $form->call('saveDraft')->assertHasNoErrors();
            $form = Livewire::test(PurchaseForm::class, ['publicId' => $form->get('savedDraftPublicId')]);
        }
        if ($paid) {
            $form->set('settlement.mode', 'full')->set('settlement.money_account_id', $this->ilsCashAccount->id);
        }

        return $form;
    }

    private function effects(): array
    {
        return collect(['purchases', 'purchase_lines', 'purchase_line_lots', 'vendor_payments', 'vendor_payment_allocations', 'posting_batches', 'posting_lines', 'stock_movements', 'command_receipts', 'purchase_confirmation_receipts'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    #[DataProvider('modes')]
    public function test_original_pre_success_http_snapshot_replay_resolves_the_same_canonical_result(bool $existing, bool $paid): void
    {
        $form = $this->form($existing, $paid);
        $snapshot = $form->snapshot;
        $this->assertNull($form->get('completedConfirmation'));
        $this->assertNotSame('', $form->get('confirmationRequestIdentity'));
        $form->call('saveAndConfirm')->assertHasNoErrors();
        $purchase = Purchase::sole();
        $form->assertRedirect(route('purchases.show', $purchase->public_id));
        $this->assertTrue($purchase->isPosted());
        $this->assertSame('2.000000', $purchase->lines()->sole()->quantity);
        $this->assertSame('50.250000', $purchase->grand_total_currency);
        $this->assertNotNull($purchase->purchase_number);
        $this->assertSame(1, DB::table('purchases')->whereNotNull('purchase_number')->count());
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('posting_batches', $paid ? 2 : 1);
        $this->assertDatabaseCount('command_receipts', 1);
        $this->assertDatabaseCount('purchase_confirmation_receipts', 1);
        $this->assertDatabaseCount('vendor_payments', $paid ? 1 : 0);
        $effects = $this->effects();
        $form->snapshot = $snapshot;
        $form->call('saveAndConfirm')->assertHasNoErrors()->assertRedirect(route('purchases.show', $purchase->public_id));
        $this->assertSame($effects, $this->effects());
    }

    public static function paidModes(): array
    {
        return ['new paid' => [false, true], 'existing paid' => [true, true]];
    }

    #[DataProvider('paidModes')]
    public function test_confirm_post_caught_failure_rolls_back_payment_and_posting_atomically(bool $existing, bool $paid): void
    {
        $form = $this->form($existing, true);
        $this->mock(PostVendorPaymentAction::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andReturnUsing(function (): never {
                throw new \RuntimeException('Synthetic vendor payment failure');
            });
        });
        $form->call('saveAndConfirm')->assertHasNoErrors();
        $this->assertNotNull($form->get('previewError'));
        $this->assertDatabaseCount('vendor_payments', 0);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('command_receipts', 0);
        $this->assertDatabaseCount('purchase_confirmation_receipts', 0);
        $this->assertSame(0, Purchase::where('status', 'posted')->count());
        if ($existing) {
            $this->assertSame('draft', Purchase::sole()->status);
            $this->assertNull(Purchase::sole()->posting_batch_id);
        }
    }

    #[DataProvider('modes')]
    public function test_outer_receipt_insert_failure_causes_complete_atomic_rollback(bool $existing, bool $paid): void
    {
        $form = $this->form($existing, $paid);
        $injected = false;
        DB::listen(function ($query) use (&$injected): void {
            if (! $injected && str_starts_with(strtolower(trim($query->sql)), 'insert into `purchase_confirmation_receipts`')) {
                $injected = true;
                throw new \RuntimeException('Synthetic receipt insert failure');
            }
        });
        try {
            $form->call('saveAndConfirm');
            $this->fail('Expected receipt insert failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic receipt insert failure', $exception->getMessage());
        }
        $this->assertTrue($injected, 'The receipt insert was not reached.');
        $this->assertDatabaseCount('purchase_confirmation_receipts', 0);
        $this->assertDatabaseCount('command_receipts', 0);
        $this->assertDatabaseCount('vendor_payments', 0);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(0, Purchase::where('status', 'posted')->count());
        if (! $existing) {
            $this->assertDatabaseCount('purchases', 0);
        } else {
            $this->assertSame('draft', Purchase::sole()->status);
            $this->assertNull(Purchase::sole()->posting_batch_id);
        }
    }

    #[DataProvider('modes')]
    public function test_original_snapshot_with_changed_economics_or_settlement_is_rejected_without_writes(bool $existing, bool $paid): void
    {
        $form = $this->form($existing, $paid);
        $snapshot = $form->snapshot;
        $form->call('saveAndConfirm')->assertHasNoErrors();
        $effects = $this->effects();
        foreach ([['lines.0.quantity' => '3'], ['settlement.notes' => 'Changed retry']] as $updates) {
            $form->snapshot = $snapshot;
            $form->update(calls: [['method' => 'saveAndConfirm', 'params' => []]], updates: $updates)
                ->assertSet('previewError', __('commands.form_changed_repreview_required'));
            $this->assertSame($effects, $this->effects());
        }
    }

    public static function revokedModes(): array
    {
        $cases = [];
        foreach (self::modes() as $name => [$existing, $paid]) {
            foreach ([$existing ? 'purchasing.purchase.edit_draft' : 'purchasing.purchase.create', 'purchasing.cost.view', 'purchasing.purchase.post', ...($paid ? ['money.vendor_payment.create'] : [])] as $permission) {
                $cases[$name.' '.$permission] = [$existing, $paid, $permission];
            }
        }

        return $cases;
    }

    #[DataProvider('revokedModes')]
    public function test_original_snapshot_replay_rechecks_revoked_permissions(bool $existing, bool $paid, string $permission): void
    {
        $form = $this->form($existing, $paid);
        $snapshot = $form->snapshot;
        $form->call('saveAndConfirm')->assertHasNoErrors();
        $effects = $this->effects();
        Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail()->revokePermissionTo($permission);
        $form->snapshot = $snapshot;
        $form->call('saveAndConfirm')->assertForbidden();
        $this->assertSame($effects, $this->effects());
    }

    public function test_identity_is_bound_to_actor_company_and_existing_source_before_execution(): void
    {
        $service = app(PurchaseFormConfirmation::class);
        $identity = $service->identity((int) $this->company->id, $this->owner, null);
        $other = User::factory()->create();
        foreach ([[(int) $this->company->id, $other, null], [(int) $this->company->id + 1, $this->owner, null], [(int) $this->company->id, $this->owner, 'different-source']] as [$companyId, $actor, $source]) {
            try {
                $service->execute($companyId, $actor, $source, $identity, str_repeat('a', 64), fn () => $this->fail('A mismatched identity must not execute.'));
                $this->fail('Expected identity rejection.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseCount('purchase_confirmation_receipts', 0);
    }
}
