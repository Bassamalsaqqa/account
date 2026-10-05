<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Services\Posting\AccountingPostingService;
use App\Services\Posting\AccountingReversalService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorPaymentApplicationScope;
use App\Services\Purchasing\VendorPaymentReversalScope;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

final class VendorPaymentScopeRegressionTest extends Phase5ETestCase
{
    private function reject(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Authority accepted invalid runtime context');
        } catch (\InvalidArgumentException|\LogicException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    private function exercise($scope, $cap, callable $assert, callable $reenter): void
    {
        $this->assertTrue($scope->isActive($cap));
        $assert();
        app(CompanyContext::class)->clear();
        $this->reject($assert);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        auth()->logout();
        $this->reject($assert);
        auth()->setUser($this->owner);
        $name = DB::getDefaultConnection();
        config(['database.connections.scope_other' => config('database.connections.'.$name)]);
        DB::setDefaultConnection('scope_other');
        $this->reject($assert);
        DB::setDefaultConnection($name);
        $connection = DB::connection();
        $old = $connection->getPdo();
        $other = DB::connection('scope_other')->getPdo();
        (new \ReflectionProperty($connection, 'pdo'))->setValue($connection, $other);
        $this->reject($assert);
        (new \ReflectionProperty($connection, 'pdo'))->setValue($connection, $old);
        DB::beginTransaction();
        $this->reject($assert);
        DB::rollBack();
        $assert();
        $this->reject($reenter);
        $this->reject(fn () => serialize($cap));
    }

    private function payment()
    {
        return app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, ['vendor_id' => $this->vendor->id, 'money_account_id' => $this->usdCashAccount->id, 'payment_date' => '2026-10-03', 'payment_method' => 'cash', 'amount' => '100', 'exchange_rate' => '3.6', 'idempotency_key' => 'scope-payment', 'allocations' => []]);
    }

    public function test_application_capability_is_bound_to_payment_actor_pdo_and_transaction(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.5']);
        $payment = $this->payment();
        $scope = app(VendorPaymentApplicationScope::class);
        $real = new AccountingPostingService;
        $action = null;
        $cap = null;
        $mock = \Mockery::mock(AccountingPostingService::class);
        $mock->shouldReceive('post')->once()->andReturnUsing(function ($command) use ($scope, $real, $payment, &$action, &$cap) {
            $cap = (new \ReflectionProperty($scope, 'active'))->getValue($scope);
            $assert = fn () => $scope->assertApplication($cap, $this->company->id, $payment->id, $this->owner->id);
            foreach ([[$this->company->id + 1, $payment->id, $this->owner->id], [$this->company->id, $payment->id + 1, $this->owner->id], [$this->company->id, $payment->id, $this->owner->id + 1]] as $ids) {
                $this->reject(fn () => $scope->assertApplication($cap, ...$ids));
            }
            $this->exercise($scope, $cap, $assert, fn () => $scope->withinCanonicalApplication($action, $this->company->id, $payment->id, $this->owner, [], fn () => null));

            return $real->post($command);
        });
        app()->instance(AccountingPostingService::class, $mock);
        $action = app(ApplyVendorPaymentCreditAction::class);
        $action->execute($payment, $this->owner, ['application_date' => '2026-10-03', 'idempotency_key' => 'scope-app', 'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '40']]]);
        $this->assertFalse($scope->isActive($cap));
        $this->reject(fn () => $scope->assertApplication($cap, $this->company->id, $payment->id, $this->owner->id));
    }

    public function test_reversal_capability_requires_exact_event_and_dependent_order(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.5']);
        $payment = $this->payment();
        $event = app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, ['application_date' => '2026-10-03', 'idempotency_key' => 'scope-app', 'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '40']]]);
        $scope = app(VendorPaymentReversalScope::class);
        $real = app(AccountingReversalService::class);
        $action = null;
        $cap = null;
        $i = 0;
        $mock = \Mockery::mock(AccountingReversalService::class);
        $mock->shouldReceive('reverse')->twice()->andReturnUsing(function ($batch, $actor, $reason) use ($scope, $real, $payment, $event, &$action, &$cap, &$i) {
            $cap = (new \ReflectionProperty($scope, 'active'))->getValue($scope);
            $eventId = $i++ === 0 ? $event->id : null;
            $assert = fn () => $scope->assertReversal($cap, $this->company->id, $payment->id, $this->owner->id, $eventId);
            foreach ([[$this->company->id + 1, $payment->id, $this->owner->id], [$this->company->id, $payment->id + 1, $this->owner->id], [$this->company->id, $payment->id, $this->owner->id + 1]] as $ids) {
                $this->reject(fn () => $scope->assertReversal($cap, ...[...$ids, $eventId]));
            }
            if ($eventId !== null) {
                $this->reject(fn () => $scope->assertReversal($cap, $this->company->id, $payment->id, $this->owner->id));
            }
            $this->reject(fn () => $scope->assertReversal($cap, $this->company->id, $payment->id, $this->owner->id, $event->id + 1));
            $this->exercise($scope, $cap, $assert, fn () => $scope->withinCanonicalReversal($action, $payment, $this->owner, [], fn () => null));

            return $real->reverse($batch, $actor, $reason);
        });
        app()->instance(AccountingReversalService::class, $mock);
        $action = app(ReverseVendorPaymentAction::class);
        $action->execute($payment, $this->owner);
        $this->assertFalse($scope->isActive($cap));
        $this->assertTrue($payment->fresh()->is_reversed);
    }

    public function test_every_allocation_financial_component_and_boundary_is_audited(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.5']);
        $payment = $this->payment();
        $event = app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, ['application_date' => '2026-10-03', 'idempotency_key' => 'audit-app', 'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '40']]]);
        $row = DB::table('vendor_payment_allocations')->where('application_event_id', $event->id)->first();
        $audit = app(PayablesReconciliationService::class);
        foreach (['allocated_amount' => '41.000000', 'base_amount_applied_to_payable' => '139.000000', 'settlement_base_value' => '143.000000', 'realized_fx_gain_loss_base' => '3.000000', 'purchase_exchange_rate' => '3.6000000000', 'payment_exchange_rate' => '3.7000000000', 'prior_posting_batch_id' => $event->posting_batch_id] as $field => $value) {
            DB::table('vendor_payment_allocations')->where('id', $row->id)->update([$field => $value]);
            $this->assertFalse($audit->reconcile($this->company)->isHealthy, $field);
            DB::table('vendor_payment_allocations')->where('id', $row->id)->update([$field => $row->$field]);
        }
        $this->assertTrue($audit->reconcile($this->company)->isHealthy);
    }

    public function test_runtime_scope_activation_has_one_production_owner_each(): void
    {
        $owners = ['withinCanonicalPaymentPosting' => 'PostVendorPaymentAction.php', 'withinCanonicalApplication' => 'ApplyVendorPaymentCreditAction.php', 'withinCanonicalReversal' => 'ReverseVendorPaymentAction.php'];
        foreach ($owners as $method => $owner) {
            $hits = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php' && str_contains(file_get_contents($file->getPathname()), '->'.$method.'(')) {
                    $hits[] = $file->getFilename();
                }
            }$this->assertSame([$owner], $hits);
        }
    }
}
