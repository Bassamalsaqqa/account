<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Models\VendorPayment;
use App\Services\Posting\AccountingPostingService;
use App\Services\Posting\AccountingReversalService;
use App\Services\Purchasing\VendorPaymentPostingScope;
use App\Services\Purchasing\VendorPaymentReversalScope;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class VendorPaymentAtomicBoundaryTest extends Phase5ETestCase
{
    private function intent(array $changes = []): array
    {
        return array_replace(['vendor_id' => $this->vendor->id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03', 'payment_method' => 'cash', 'amount' => '100.00', 'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'boundary-payment', 'allocations' => []], $changes);
    }

    private function state(): array
    {
        $state = [];
        foreach (['purchases', 'purchase_lines', 'purchase_line_lots', 'purchase_returns', 'document_sequences', 'vendor_payments',
            'vendor_payment_allocations', 'vendor_payment_application_events', 'posting_batches', 'posting_lines', 'audit_events',
            'inventory_operations', 'stock_movements', 'inventory_lots', 'inventory_balances', 'inventory_lot_balances', 'inventory_cost_states'] as $table) {
            $state[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
        }

        return $state;
    }

    private function rejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected runtime authority rejection.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function test_payment_failure_after_number_and_provisional_history_restores_every_table(): void
    {
        $purchase = $this->createAndPostPurchase();
        $before = $this->state();
        $scope = app(VendorPaymentPostingScope::class);
        $captured = null;
        $mock = \Mockery::mock(AccountingPostingService::class);
        $mock->shouldReceive('post')->once()->andReturnUsing(function ($command) use ($scope, &$captured, $before) {
            $captured = (new \ReflectionProperty($scope, 'active'))->getValue($scope);
            $this->assertTrue($scope->isActive($captured));
            $this->assertSame(1, VendorPayment::count());
            $this->assertSame(1, DB::table('vendor_payment_allocations')->count());
            $this->assertNotSame($before['document_sequences'], $this->state()['document_sequences']);
            throw new RuntimeException('Injected accounting failure after provisional history');
        });
        app()->instance(AccountingPostingService::class, $mock);
        try {
            app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $this->intent(['allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '40.00']]]));
            $this->fail('Expected injected failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected accounting failure after provisional history', $exception->getMessage());
        }
        $this->assertSame($before, $this->state());
        $this->assertFalse($scope->isActive($captured));
    }

    public function test_payment_capability_is_bound_to_live_actor_context_connection_and_exact_transaction(): void
    {
        $scope = app(VendorPaymentPostingScope::class);
        $real = new AccountingPostingService;
        $captured = null;
        $action = null;
        $mock = \Mockery::mock(AccountingPostingService::class);
        $mock->shouldReceive('post')->once()->andReturnUsing(function ($command) use ($scope, $real, &$captured, &$action) {
            $captured = (new \ReflectionProperty($scope, 'active'))->getValue($scope);
            $this->assertTrue($scope->isActive($captured));
            $assert = fn ($company, $vendor, $actor) => $scope->assertPosting($captured, $company, $vendor, $actor);
            $assert($this->company->id, $this->vendor->id, $this->owner->id);
            $this->rejected(fn () => $assert($this->company->id + 1, $this->vendor->id, $this->owner->id));
            $this->rejected(fn () => $assert($this->company->id, $this->vendor->id + 1, $this->owner->id));
            $this->rejected(fn () => $assert($this->company->id, $this->vendor->id, $this->owner->id + 1));
            app(CompanyContext::class)->clear();
            $this->rejected(fn () => $assert($this->company->id, $this->vendor->id, $this->owner->id));
            app(CompanyContext::class)->setCompany($this->company, $this->owner);
            auth()->logout();
            $this->rejected(fn () => $assert($this->company->id, $this->vendor->id, $this->owner->id));
            auth()->setUser($this->owner);
            $connection = DB::getDefaultConnection();
            config(['database.connections.capability_other' => config('database.connections.'.$connection)]);
            DB::setDefaultConnection('capability_other');
            $this->rejected(fn () => $assert($this->company->id, $this->vendor->id, $this->owner->id));
            DB::setDefaultConnection($connection);
            DB::beginTransaction();
            $this->rejected(fn () => $assert($this->company->id, $this->vendor->id, $this->owner->id));
            DB::rollBack();
            $this->rejected(fn () => $scope->withinCanonicalPaymentPosting($action, $this->company->id, $this->vendor->id, $this->owner, 'other', [], fn () => null));
            try {
                serialize($captured);
                $this->fail('Capability serialization accepted');
            } catch (\LogicException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }

            return $real->post($command);
        });
        app()->instance(AccountingPostingService::class, $mock);
        $action = app(PostVendorPaymentAction::class);
        $action->execute($this->company, $this->owner, $this->intent());
        $this->assertFalse($scope->isActive($captured));
        $this->rejected(fn () => $scope->assertPosting($captured, $this->company->id, $this->vendor->id, $this->owner->id));
    }

    public function test_dependent_reversal_failure_restores_events_batches_payment_and_payable_position(): void
    {
        $a = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $b = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $this->intent(['money_account_id' => $this->usdCashAccount->id, 'exchange_rate' => '3.60']));
        $apply = app(ApplyVendorPaymentCreditAction::class);
        $eventA = $apply->execute($payment, $this->owner, ['application_date' => '2026-10-03', 'idempotency_key' => 'A', 'allocations' => [['purchase_id' => $a->id, 'allocated_amount' => '30.00']]]);
        $eventB = $apply->execute($payment, $this->owner, ['application_date' => '2026-10-03', 'idempotency_key' => 'B', 'allocations' => [['purchase_id' => $b->id, 'allocated_amount' => '40.00']]]);
        $before = $this->state();
        $real = app(AccountingReversalService::class);
        $scope = app(VendorPaymentReversalScope::class);
        $captured = null;
        $order = [];
        $mock = \Mockery::mock(AccountingReversalService::class);
        $mock->shouldReceive('reverse')->twice()->andReturnUsing(function ($batch, $actor, $reason) use ($real, $scope, &$captured, &$order, $eventA, $eventB) {
            $captured = (new \ReflectionProperty($scope, 'active'))->getValue($scope);
            $this->assertTrue($scope->isActive($captured));
            $order[] = $batch->id;
            if (count($order) === 2) {
                $this->assertNotNull($eventB->fresh()->reversed_at);
                $this->assertNull($eventA->fresh()->reversed_at);
                throw new RuntimeException('Injected dependent reversal failure');
            }
            $this->assertSame($eventB->posting_batch_id, $batch->id);

            return $real->reverse($batch, $actor, $reason);
        });
        app()->instance(AccountingReversalService::class, $mock);
        try {
            app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
            $this->fail('Expected reversal failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected dependent reversal failure', $exception->getMessage());
        }
        $this->assertSame([$eventB->posting_batch_id, $eventA->posting_batch_id], $order);
        $this->assertSame($before,$this->state());
        $this->assertFalse($scope->isActive($captured));
        $this->assertSame('70.000000',(string) $a->fresh()->payablePosition()->outstanding);
        $this->assertSame('60.000000',(string) $b->fresh()->payablePosition()->outstanding);
    }
}
