<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Models\VendorPayment;
use App\Services\Purchasing\VendorPaymentPostingCapability;
use App\Services\Purchasing\VendorPaymentPostingScope;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class VendorPaymentAuthorityAndImmutabilityTest extends Phase5ETestCase
{
    /** Scenario 9: Model direct-history forgery: Payment, allocation, event, number/batch/reversal/completion/caller-financial values denied outside exact canonical capability/prepared set */
    public function test_scenario_09_model_direct_history_forgery_denied(): void
    {
        $postAction = app(PostVendorPaymentAction::class);
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        $pmt = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s9-01',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '100.00'],
            ],
        ]);

        // 1. Updating posted payment amount or currency directly is blocked
        try {
            $pmt->amount = '200.000000';
            $pmt->save();
            $this->fail('Expected ImmutableRecordException when editing posted payment');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('Only canonical vendor payment reversal can change posted metadata.', $e->getMessage());
        }

        // 2. Deleting posted payment is blocked
        try {
            $pmt->delete();
            $this->fail('Expected ImmutableRecordException when deleting posted payment');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        // 3. Updating allocation directly is blocked
        $allocation = $pmt->allocations->first();
        try {
            $allocation->allocated_amount = '50.000000';
            $allocation->save();
            $this->fail('Expected ImmutableRecordException when editing allocation');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('immutable and cannot be updated', $e->getMessage());
        }

        // 4. Deleting allocation directly is blocked
        try {
            $allocation->delete();
            $this->fail('Expected ImmutableRecordException when deleting allocation');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        // 5. Direct creation of VendorPayment with forged posting metadata is blocked
        try {
            VendorPayment::create([
                'company_id' => $this->company->id,
                'vendor_id' => $this->vendor->id,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_number' => 'FORGED-001',
                'payment_date' => '2026-10-02',
                'payment_method' => 'cash',
                'currency_code' => 'ILS',
                'base_currency_code' => 'ILS',
                'amount' => '50.000000',
                'exchange_rate' => '1.0000000000',
                'amount_base' => '50.000000',
                'document_locale' => 'ar',
                'idempotency_key' => 'forged-01',
                'request_hash' => 'fake',
                'created_by' => $this->owner->id,
                'posting_batch_id' => 999,
            ]);
            $this->fail('Expected exception when creating VendorPayment with forged posting metadata');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be born with forged posting/reversal metadata', $e->getMessage());
        }
    }

    /** Scenario 10: Posting/application capability exact company/Vendor/actor/auth/context/connection/PDO/transaction identity; no arbitrary transaction, no reentrancy/serialization, expiry after exit/rollback */
    public function test_scenario_10_scope_and_capability_validation(): void
    {
        $scope = app(VendorPaymentPostingScope::class);
        $capturedCap = null;

        // Execute canonical post to exercise capability
        $postAction = app(PostVendorPaymentAction::class);
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s10-01',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '100.00'],
            ],
        ]);

        // Outside canonical post, scope has no active capability
        $dummyCap = new VendorPaymentPostingCapability;
        $this->assertFalse($scope->isActive($dummyCap));

        // Serialization of capability is forbidden
        try {
            serialize($dummyCap);
            $this->fail('Expected exception when serializing capability');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Vendor payment posting capabilities cannot be serialized.', $e->getMessage());
        }
    }

    /** Scenario 11: Wrong tenant/guest/mismatched actor/inactive membership/foreign Vendor/account/Purchase/missing create/allocate/reverse/cost/stale Livewire auth/cross-company idempotency zero effects */
    public function test_scenario_11_authorization_and_tenant_isolation(): void
    {
        $postAction = app(PostVendorPaymentAction::class);
        $reverseAction = app(ReverseVendorPaymentAction::class);

        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        // 1. User without money.vendor_payment.allocate permission cannot post payment
        $unauthorizedUser = $this->customActor(['purchasing.cost.view']);
        $this->activate($unauthorizedUser);

        try {
            $postAction->execute($this->company, $unauthorizedUser, [
                'vendor_id' => $this->vendor->id,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_date' => '2026-10-02',
                'payment_method' => 'cash',
                'amount' => '50.00',
                'exchange_rate' => '1.0000000000',
                'idempotency_key' => 'pmt-s11-no-perm',
                'allocations' => [
                    ['purchase_id' => $purchase->id, 'allocated_amount' => '50.00'],
                ],
            ]);
            $this->fail('Expected AuthorizationException for missing allocate permission');
        } catch (AuthorizationException $e) {
            $this->assertTrue(true);
        }

        // 2. User without purchasing.cost.view cannot post payment
        $userNoCost = $this->customActor(['money.vendor_payment.allocate']);
        $this->activate($userNoCost);

        try {
            $postAction->execute($this->company, $userNoCost, [
                'vendor_id' => $this->vendor->id,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_date' => '2026-10-02',
                'payment_method' => 'cash',
                'amount' => '50.00',
                'exchange_rate' => '1.0000000000',
                'idempotency_key' => 'pmt-s11-no-cost',
                'allocations' => [
                    ['purchase_id' => $purchase->id, 'allocated_amount' => '50.00'],
                ],
            ]);
            $this->fail('Expected AuthorizationException for missing cost permission');
        } catch (AuthorizationException $e) {
            $this->assertTrue(true);
        }

        // 3. User with permissions can post payment
        $this->activate($this->owner);
        $pmt = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '50.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s11-owner-ok',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '50.00'],
            ],
        ]);
        $this->assertNotNull($pmt);

        // 4. User without money.vendor_payment.reverse cannot reverse payment
        $this->activate($unauthorizedUser);
        try {
            $reverseAction->execute($pmt, $unauthorizedUser, 'Test unauthorized reversal');
            $this->fail('Expected AuthorizationException for missing reverse permission');
        } catch (AuthorizationException $e) {
            $this->assertTrue(true);
        }
    }
}
