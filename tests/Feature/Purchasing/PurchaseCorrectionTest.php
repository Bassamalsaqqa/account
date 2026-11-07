<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Livewire\Pages\Purchasing\PurchaseDetail;
use App\Models\MoneyAccount;
use App\Models\Purchase;
use App\Models\User;
use App\Models\VendorPayment;
use App\Services\Corrections\PurchaseCorrection;
use App\Services\Corrections\PurchaseDependencySnapshot;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Phase5D\Phase5DTestCase;

final class PurchaseCorrectionTest extends Phase5DTestCase
{
    public function test_edit_creates_one_replacement_and_retry_returns_it(): void
    {
        $purchase = $this->createAndPostPurchase();
        $service = app(PurchaseCorrection::class);
        $preview = $service->preview($purchase, $this->owner, 'edit', 'Correction', '2026-11-07');
        $replacement = $service->execute($preview['token'], $this->owner);
        $this->assertTrue($replacement->isDraft());
        $this->assertTrue($purchase->fresh()->isVoid());
        $this->assertSame($replacement->id, $service->execute($preview['token'], $this->owner)->id);
        $this->assertSame(1, DB::table('purchase_correction_receipts')->count());
    }

    public function test_delete_retains_original_and_history(): void
    {
        $purchase = $this->createAndPostPurchase();
        $service = app(PurchaseCorrection::class);
        $preview = $service->preview($purchase, $this->owner, 'delete', 'Correction', '2026-11-07');
        $this->assertTrue($service->execute($preview['token'], $this->owner)->isVoid());
        $this->assertSame(1, Purchase::count());
    }

    public function test_changed_dependency_rejects_preview_without_voiding(): void
    {
        $purchase = $this->createAndPostPurchase();
        $service = app(PurchaseCorrection::class);
        $preview = $service->preview($purchase, $this->owner, 'edit', 'Correction', '2026-11-07');
        DB::table('products')->where('id', $this->product->id)->update(['active' => false]);
        try {
            $service->execute($preview['token'], $this->owner);
            $this->fail('Stale preview accepted');
        } catch (ValidationException) {
            $this->assertTrue($purchase->fresh()->isPosted());
            $this->assertSame(1, Purchase::count());
        }
    }

    public function test_failure_after_clone_rolls_back_draft_and_every_inverse(): void
    {
        $purchase = $this->createAndPostPurchase();
        $service = app(PurchaseCorrection::class);
        $preview = $service->preview($purchase, $this->owner, 'edit', 'Correction', '2000-01-01');
        $movements = DB::table('stock_movements')->count();
        try {
            $service->execute($preview['token'], $this->owner);
            $this->fail('Invalid economic date accepted');
        } catch (\InvalidArgumentException) {
            $this->assertSame(1, Purchase::count());
            $this->assertTrue($purchase->fresh()->isPosted());
            $this->assertSame(0, DB::table('purchase_correction_receipts')->count());
            $this->assertSame($movements, DB::table('stock_movements')->count());
        }
    }

    public function test_unauthenticated_preview_never_serializes_cost(): void
    {
        $purchase = $this->createAndPostPurchase();
        auth()->logout();
        $this->expectException(AuthorizationException::class);
        app(PurchaseCorrection::class)->preview($purchase, $this->owner, 'edit', 'Correction', '2026-11-07');
    }

    public function test_tampered_preview_is_rejected(): void
    {
        $purchase = $this->createAndPostPurchase();
        $this->expectException(ValidationException::class);
        app(PurchaseCorrection::class)->execute('tampered', $this->owner);
    }

    public function test_receipt_migration_refuses_history_loss(): void
    {
        $purchase = $this->createAndPostPurchase();
        $service = app(PurchaseCorrection::class);
        $preview = $service->preview($purchase, $this->owner, 'delete', 'Correction', '2026-11-07');
        $service->execute($preview['token'], $this->owner);
        $migration = require database_path('migrations/2026_11_07_000003_create_purchase_correction_receipts.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_detail_dialog_reaches_canonical_execution(): void
    {
        $purchase = $this->createAndPostPurchase();
        Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->call('openCorrection', 'edit')
            ->set('correctionReason', 'Correction')
            ->set('correctionDate', '2026-11-07')
            ->call('previewCorrection')
            ->assertHasNoErrors()
            ->call('executeCorrection')
            ->assertHasNoErrors();
        $this->assertTrue($purchase->fresh()->isVoid());
        $this->assertSame(1, Purchase::where('status', 'draft')->count());
    }

    public function test_retry_with_changed_economics_is_rejected(): void
    {
        $purchase = $this->createAndPostPurchase();
        $service = app(PurchaseCorrection::class);
        $preview = $service->preview($purchase, $this->owner, 'edit', 'Correction', '2026-11-07');
        $replacement = $service->execute($preview['token'], $this->owner);
        $payload = json_decode(Crypt::decryptString($preview['token']), true);
        $payload['reason'] = 'Different correction';
        try {
            $service->execute(Crypt::encryptString(json_encode($payload)), $this->owner);
            $this->fail('Economic mismatch accepted');
        } catch (ValidationException) {
            $this->assertSame(1, Purchase::where('status', 'draft')->count());
            $this->assertTrue($replacement->fresh()->isDraft());
        }
    }

    public function test_dedicated_payment_is_disclosed_and_reversed_atomically(): void
    {
        $purchase = $this->createAndPostPurchase();
        $account = MoneyAccount::where('company_id', $this->company->id)->where('account_type', 'cash')->firstOrFail();
        app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id, 'money_account_id' => $account->id,
            'payment_date' => '2026-11-06', 'payment_method' => 'cash', 'currency_code' => 'ILS',
            'exchange_rate' => '1', 'amount' => '1', 'idempotency_key' => 'correction-blocker',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '1']],
        ]);
        $service = app(PurchaseCorrection::class);
        $preview = $service->preview($purchase, $this->owner, 'edit', 'Correction', '2026-11-07');
        $this->assertEmpty($preview['blockers']);
        $this->assertCount(1, $preview['payments']);
        $this->assertCount(1, $preview['links']);
        $result = $service->execute($preview['token'], $this->owner);
        $this->assertTrue($purchase->fresh()->isVoid());
        $this->assertTrue($result->isDraft());
        $this->assertTrue((bool) DB::table('vendor_payments')->value('is_reversed'));
    }

    public function test_revoked_cost_permission_blocks_preview_server_side(): void
    {
        $purchase = $this->createAndPostPurchase();
        $this->owner->roles()->firstOrFail()->revokePermissionTo('purchasing.cost.view');
        $this->expectException(AuthorizationException::class);
        app(PurchaseCorrection::class)->preview($purchase, $this->owner, 'delete', 'Correction', '2026-11-07');
    }

    public function test_current_conversion_cannot_silently_change_replacement_intent(): void
    {
        $purchase = $this->createAndPostPurchase();
        DB::table('product_units')->where('company_id', $this->company->id)->where('product_id', $this->product->id)->update(['conversion_to_base' => '5']);
        $this->expectException(ValidationException::class);
        app(PurchaseCorrection::class)->preview($purchase, $this->owner, 'edit', 'Correction', '2026-11-07');
    }

    public function test_unrelated_tenant_rows_do_not_change_snapshot_or_workload(): void
    {
        $purchase = $this->createAndPostPurchase();
        $snapshot = app(PurchaseDependencySnapshot::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $before = $snapshot->capture($purchase, true);
        $queries = count(DB::getQueryLog());
        $preview = app(PurchaseCorrection::class)->preview($purchase, $this->owner, 'edit', 'Correction', '2026-11-07');
        $attributes = $this->expiryProduct->getAttributes();
        unset($attributes['id']);
        $rows = [];
        for ($i = 0; $i < 250; $i++) {
            $rows[] = array_replace($attributes, ['public_id' => (string) Str::ulid(), 'sku' => 'UNRELATED-'.$i]);
        }
        DB::table('products')->insert($rows);
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create();
        $this->actingAs($otherOwner);
        app(CreateCompanyAction::class)->execute($otherOwner, ['name_ar' => 'شركة ثانية']);
        $this->activate($this->owner);
        DB::table('products')->where('id', $this->expiryProduct->id)->update(['active' => false]);
        DB::flushQueryLog();
        $after = $snapshot->capture($purchase, true);
        $this->assertEquals($before, $after);
        $this->assertSame($queries, count(DB::getQueryLog()));
        $this->assertSame(array_sum(array_map('count', $before)), array_sum(array_map('count', $after)));
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('select *', strtolower($query['query']));
            $this->assertStringNotContainsString('for update', strtolower($query['query']));
        }
        DB::disableQueryLog();
        $this->assertTrue(app(PurchaseCorrection::class)->execute($preview['token'], $this->owner)->isDraft());
    }

    public function test_added_payment_invalidates_review(): void
    {
        $purchase = $this->createAndPostPurchase();
        $service = app(PurchaseCorrection::class);
        $preview = $service->preview($purchase, $this->owner, 'delete', 'Correction', '2026-11-07');
        $this->paymentFor($purchase);
        try {
            $service->execute($preview['token'], $this->owner);
            $this->fail('Added payment was not detected');
        } catch (ValidationException) {
            $this->assertTrue($purchase->fresh()->isPosted());
            $this->assertFalse((bool) DB::table('vendor_payments')->value('is_reversed'));
        }
    }

    private function paymentFor(Purchase $purchase, string $amount = '1', string $allocated = '1'): VendorPayment
    {
        $account = MoneyAccount::where('company_id', $this->company->id)->where('account_type', 'cash')->firstOrFail();

        return app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id, 'money_account_id' => $account->id, 'payment_date' => '2026-11-06',
            'payment_method' => 'cash', 'currency_code' => 'ILS', 'exchange_rate' => '1', 'amount' => $amount,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => $allocated]],
        ]);
    }

    public function test_shared_initial_credit_remains_intact(): void
    {
        $purchase = $this->createAndPostPurchase();
        $payment = $this->paymentFor($purchase, '2', '1');
        $service = app(PurchaseCorrection::class);
        $preview = $service->preview($purchase, $this->owner, 'delete', 'Correction', '2026-11-07');
        $this->assertEmpty($preview['payments']);
        $this->assertNotEmpty($preview['blockers']);
        try {
            $service->execute($preview['token'], $this->owner);
            $this->fail('Shared payment reversed');
        } catch (ValidationException) {
            $this->assertFalse($payment->fresh()->is_reversed);
            $this->assertTrue($purchase->fresh()->isPosted());
        }
    }

    public function test_purchase_child_return_is_reversed_before_parent(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draft = app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, [
            'purchase_id' => $purchase->id, 'return_date' => '2026-11-01',
            'lines' => [['purchase_line_id' => $purchase->lines()->firstOrFail()->id, 'quantity' => '2']],
        ]);
        $child = app(PostPurchaseReturnAction::class)->execute($draft, $this->owner);
        $service = app(PurchaseCorrection::class);
        $preview = $service->preview($purchase, $this->owner, 'delete', 'Correction', '2026-11-07');
        $this->assertCount(1, $preview['returns']);
        $this->assertEmpty($preview['blockers']);
        $service->execute($preview['token'], $this->owner);
        $this->assertTrue($child->fresh()->isVoid());
        $this->assertTrue($purchase->fresh()->isVoid());
        $payables = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($payables->isHealthy, json_encode($payables->violations));
    }

    public function test_receipt_failure_rolls_back_payment_stock_gl_clone_and_source(): void
    {
        $purchase = $this->createAndPostPurchase();
        $payment = $this->paymentFor($purchase);
        $service = app(PurchaseCorrection::class);
        $preview = $service->preview($purchase, $this->owner, 'edit', 'Correction', '2026-11-07');
        $before = [DB::table('stock_movements')->count(), DB::table('posting_batches')->count(), DB::table('posting_lines')->count()];
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_starts_with($query->sql, 'insert into `purchase_correction_receipts`')) {
                $armed = false;
                throw new \RuntimeException('Injected receipt failure after all legs');
            }
        });
        try {
            $service->execute($preview['token'], $this->owner);
            $this->fail('Injected failure ignored');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected receipt failure after all legs', $e->getMessage());
            $this->assertFalse($payment->fresh()->is_reversed);
            $this->assertTrue($purchase->fresh()->isPosted());
            $this->assertSame(1, Purchase::count());
            $this->assertSame(0, DB::table('purchase_correction_receipts')->count());
            $this->assertSame($before, [DB::table('stock_movements')->count(), DB::table('posting_batches')->count(), DB::table('posting_lines')->count()]);
        }
    }

    public function test_revoked_cost_permission_clears_previously_authorized_dialog_payload(): void
    {
        $purchase = $this->createAndPostPurchase();
        $component = Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->call('openCorrection', 'edit')->set('correctionReason', 'Correction')->call('previewCorrection')->assertHasNoErrors();
        $this->owner->roles()->firstOrFail()->revokePermissionTo('purchasing.cost.view');
        $component->call('$refresh')->assertSet('correctionPreview', null)->assertSet('correctionOpen', false);
        $this->assertTrue($purchase->fresh()->isPosted());
    }

    public function test_optional_recovery_read_revocation_clears_cached_preview(): void
    {
        $source = $this->createAndPostPurchase();
        $role = $this->owner->roles()->firstOrFail();
        foreach (['money.expense.view', 'money.check.view', 'inventory.stock.view'] as $permission) {
            $page = Livewire::test(PurchaseDetail::class, ['publicId' => $source->public_id])
                ->call('openCorrection', 'delete')->set('correctionReason', 'Correction')->call('previewCorrection')->assertHasNoErrors();
            $this->assertContains($permission, $page->get('correctionPreview')['presentation_permissions']);
            $role->revokePermissionTo($permission);
            $page->call('$refresh')->assertSet('correctionPreview', null)->assertSet('correctionOpen', false);
            $role->givePermissionTo($permission);
        }
        $this->assertSame('posted', $source->fresh()->status);
    }

    public function test_execution_locks_company_before_source(): void
    {
        $purchase = $this->createAndPostPurchase();
        $service = app(PurchaseCorrection::class);
        $preview = $service->preview($purchase, $this->owner, 'delete', 'Correction', now($this->company->timezone)->toDateString());
        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $locks[] = strtolower($query->sql);
            }
        });
        $service->execute($preview['token'], $this->owner);
        $this->assertNotEmpty($locks);
        $this->assertStringContainsString('`companies`', $locks[0]);
        $this->assertNotEmpty(array_filter($locks, fn ($sql) => str_contains($sql, '`purchases`')));
        $this->assertSame('void', $purchase->fresh()->status);
    }
}
