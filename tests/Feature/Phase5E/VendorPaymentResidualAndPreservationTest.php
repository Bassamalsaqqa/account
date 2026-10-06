<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Models\LedgerAccount;
use App\Services\Posting\AccountingPostingService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorPaymentApplicationScope;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

final class VendorPaymentResidualAndPreservationTest extends Phase5ETestCase
{
    private function intent(array $change = []): array
    {
        return array_replace(['vendor_id' => $this->vendor->id, 'money_account_id' => $this->usdCashAccount->id, 'payment_date' => '2026-10-03', 'payment_method' => 'cash', 'amount' => '1.01', 'exchange_rate' => '3.3333333333', 'idempotency_key' => 'residual-first', 'allocations' => []], $change);
    }

    private function hashes(): array
    {
        $result = [];
        foreach (['vendor_payments', 'vendor_payment_allocations', 'vendor_payment_application_events', 'posting_batches', 'posting_lines', 'document_sequences', 'audit_events'] as $t) {
            $result[$t] = hash('sha256', json_encode(DB::table($t)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
        }

        return $result;
    }

    public function test_negative_ap_rounding_residual_is_not_fx_and_retries_exactly(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.3333333333', 'lines' => [['product_id' => $this->product->id, 'quantity' => '3', 'unit_cost' => '1.01']]]);
        $post = app(PostVendorPaymentAction::class);
        $intent = $this->intent(['allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '1.01']]]);
        $post->execute($this->company, $this->owner, $intent);
        $intent['idempotency_key'] = 'residual-second';
        $payment = $post->execute($this->company, $this->owner, $intent);
        $this->assertSame('3.366666', $payment->allocations->sole()->base_amount_applied_to_payable);
        $ap = LedgerAccount::where('system_key', 'accounts_payable')->firstOrFail();
        $residual = $payment->postingBatch->lines->filter(fn ($l) => $l->ledger_account_id === $ap->id && $l->transaction_currency_code === null)->sole();
        $this->assertSame('0.000001', $residual->credit_base);
        app(VendorPaymentPostedIntegrityValidator::class)->validate($payment);
        $this->assertSame($payment->id, $post->execute($this->company, $this->owner, $intent)->id);
    }

    public function test_application_failure_after_provisional_event_expires_capability_and_rolls_back(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.5']);
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $this->intent(['amount' => '100', 'exchange_rate' => '3.6']));
        $before = $this->hashes();
        $scope = app(VendorPaymentApplicationScope::class);
        $cap = null;
        $mock = \Mockery::mock(AccountingPostingService::class);
        $mock->shouldReceive('post')->once()->andReturnUsing(function () use ($scope, &$cap) {
            $cap = (new \ReflectionProperty($scope, 'active'))->getValue($scope);
            $this->assertTrue($scope->isActive($cap));
            $this->assertSame(1, DB::table('vendor_payment_application_events')->count());
            $this->assertSame(1, DB::table('vendor_payment_allocations')->count());
            throw new \RuntimeException('Application accounting failure');
        });
        app()->instance(AccountingPostingService::class, $mock);
        try {
            app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, ['application_date' => '2026-10-03', 'idempotency_key' => 'failure', 'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '40']]]);
            $this->fail('Accounting failure expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('Application accounting failure', $e->getMessage());
        }
        $this->assertSame($before, $this->hashes());
        $this->assertFalse($scope->isActive($cap));
        $this->assertSame('100.000000', $payment->fresh()->unallocated_amount);
    }

    public function test_bootstrap_preserves_customized_non_owner_grants_and_every_sequence_twice(): void
    {
        $role = Role::where('company_id', $this->company->id)->where('name', 'Purchasing')->firstOrFail();
        $role->syncPermissions(['vendors.view', 'purchasing.purchase.view']);
        $before = DB::table('role_has_permissions')->where('role_id', $role->id)->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all();
        $sequences = DB::table('document_sequences')->where('company_id', $this->company->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $state = $this->hashes();
        for ($i = 0; $i < 2; $i++) {
            Artisan::call('purchasing:bootstrap', ['--all' => true]);
            $this->assertSame($before, DB::table('role_has_permissions')->where('role_id', $role->id)->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all());
            $this->assertSame($sequences, DB::table('document_sequences')->where('company_id', $this->company->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        }
        $this->assertSame($state, $this->hashes());
        $owner = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();
        $this->assertTrue($owner->hasPermissionTo('money.vendor_payment.allocate'));
        $this->assertTrue($owner->hasPermissionTo('money.vendor_payment.reverse'));
    }

    public function test_new_payment_rejects_money_account_type_drift_without_effects(): void
    {
        DB::table('money_accounts')->where('id', $this->usdBankAccount->id)->update(['account_type' => 'card']);
        $before = $this->hashes();
        try {
            app(PostVendorPaymentAction::class)->execute($this->company, $this->owner,
                $this->intent(['money_account_id' => $this->usdBankAccount->id, 'payment_method' => 'bank_transfer']));
            $this->fail('Invalid account type must fail closed.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('money account type', $e->getMessage());
        }
        $this->assertSame($before, $this->hashes());
    }

    public function test_payables_audit_rejects_corrupt_purchase_before_any_payment_exists(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'ILS']);
        $audit = app(PayablesReconciliationService::class);
        $this->assertTrue($audit->reconcile($this->company)->isHealthy);
        DB::table('purchases')->where('id', $purchase->id)->update(['posting_batch_id' => null]);
        $this->assertFalse($audit->reconcile($this->company)->isHealthy);
        $this->assertDatabaseCount('vendor_payments', 0);
    }

    public function test_audit_detects_standalone_zero_fx_event_reversal_and_stray_payment_reversal_actor(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'ILS']);
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner,
            $this->intent(['money_account_id' => $this->ilsCashAccount->id, 'amount' => '100', 'exchange_rate' => '1']));
        $event = app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner,
            ['application_date' => '2026-10-03', 'idempotency_key' => 'zero-lifecycle', 'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '40']]]);
        $audit = app(PayablesReconciliationService::class);
        $this->assertTrue($audit->reconcile($this->company)->isHealthy);
        DB::table('vendor_payment_application_events')->where('id', $event->id)->update(['reversed_at' => now(), 'reversed_by' => $this->owner->id]);
        $this->assertFalse($audit->reconcile($this->company)->isHealthy);
        DB::table('vendor_payment_application_events')->where('id', $event->id)->update(['reversed_at' => null, 'reversed_by' => null]);
        DB::table('vendor_payments')->where('id', $payment->id)->update(['reversed_by' => $this->owner->id]);
        $this->assertFalse($audit->reconcile($this->company)->isHealthy);
        DB::table('vendor_payments')->where('id', $payment->id)->update(['reversed_by' => null]);
        $this->assertTrue($audit->reconcile($this->company)->isHealthy);
    }
}
