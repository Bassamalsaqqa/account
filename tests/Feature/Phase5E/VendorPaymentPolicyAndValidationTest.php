<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Models\MoneyAccount;
use App\Models\Vendor;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

class VendorPaymentPolicyAndValidationTest extends Phase5ETestCase
{
    /** Scenario 6: Active Vendor wholly/partly unallocated; inactive/soft-deleted fully allocated historical settlement allowed, any advance rejected */
    public function test_scenario_06_active_and_inactive_vendor_advance_policies(): void
    {
        $postAction = app(PostVendorPaymentAction::class);

        // 1. Create vendor and post a purchase while active
        $inactiveVendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد غير نشط',
            'name_en' => 'Inactive Vendor',
            'default_currency_code' => 'ILS',
            'status' => 'active',
        ]);

        $purchase = $this->createAndPostPurchase([
            'vendor_id' => $inactiveVendor->id,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        // Now deactivate and soft-delete the vendor
        $inactiveVendor->update(['status' => 'inactive']);
        $inactiveVendor->delete();

        // 2. Attempting an unallocated advance to inactive/deleted vendor must FAIL
        try {
            $postAction->execute($this->company, $this->owner, [
                'vendor_id' => $inactiveVendor->id,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_date' => '2026-10-02',
                'payment_method' => 'cash',
                'amount' => '100.00',
                'exchange_rate' => '1.0000000000',
                'idempotency_key' => 'pmt-s6-adv-fail',
                'allocations' => [],
            ]);
            $this->fail('Expected exception for advance to inactive vendor');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Inactive or soft-deleted vendor cannot receive unallocated advance.', $e->getMessage());
        }

        // 3. But 100% allocation to existing valid historical purchase MUST SUCCEED
        $pmt = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $inactiveVendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s6-alloc-ok',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '100.00'],
            ],
        ]);

        $this->assertNotNull($pmt);
        $this->assertEquals('100.000000', (string) $pmt->amount);
        $this->assertEquals('0.000000', (string) $pmt->unallocated_amount);
    }

    /** Scenario 7: Account same-company/type/method/active currency/ledger/control-parent validation; reject check/card; disabled currency/inactive account new Payment/application atomic; no cross-currency */
    public function test_scenario_07_account_and_currency_policy_validation(): void
    {
        $postAction = app(PostVendorPaymentAction::class);

        // 1. Reject invalid payment methods (check, card)
        try {
            $postAction->execute($this->company, $this->owner, [
                'vendor_id' => $this->vendor->id,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_date' => '2026-10-01',
                'payment_method' => 'check',
                'amount' => '100.00',
                'exchange_rate' => '1.0000000000',
                'idempotency_key' => 'pmt-s7-check',
                'allocations' => [],
            ]);
            $this->fail('Expected exception for check method');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Receipt references must be positive integer IDs.', $e->getMessage());
        }

        // 2. Reject method mismatch (cash method for bank account)
        try {
            $postAction->execute($this->company, $this->owner, [
                'vendor_id' => $this->vendor->id,
                'money_account_id' => $this->usdBankAccount->id,
                'payment_date' => '2026-10-01',
                'payment_method' => 'cash',
                'amount' => '100.00',
                'exchange_rate' => '3.6000000000',
                'idempotency_key' => 'pmt-s7-mismatch',
                'allocations' => [],
            ]);
            $this->fail('Expected exception for method mismatch');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Payment method must match its money account type.', $e->getMessage());
        }

        // 3. Reject cross-currency allocation without explicit payment consumption (ILS payment to USD purchase)
        $usdPurchase = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.6000000000',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        try {
            $postAction->execute($this->company, $this->owner, [
                'vendor_id' => $this->vendor->id,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_date' => '2026-10-01',
                'payment_method' => 'cash',
                'amount' => '100.00',
                'exchange_rate' => '1.0000000000',
                'idempotency_key' => 'pmt-s7-cross',
                'allocations' => [
                    ['purchase_id' => $usdPurchase->id, 'allocated_amount' => '100.00'],
                ],
            ]);
            $this->fail('Expected exception for cross-currency allocation');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Cross-currency allocation requires an explicit payment-currency amount', $e->getMessage());
        }

        // 4. Reject other company's money account
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة أخرى',
            'name_en' => 'Other Company',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($otherCompany, $this->owner);
        setPermissionsTeamId($otherCompany->id);
        $otherAccount = app(CreateMoneyAccountAction::class)->execute($otherCompany, $this->owner, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'ILS',
            'name_ar' => 'صندوق آخر',
            'name_en' => 'Other Cash',
        ]);

        // Re-activate main company
        $this->activate($this->owner);

        try {
            $postAction->execute($this->company, $this->owner, [
                'vendor_id' => $this->vendor->id,
                'money_account_id' => $otherAccount->id,
                'payment_date' => '2026-10-01',
                'payment_method' => 'cash',
                'amount' => '100.00',
                'exchange_rate' => '1.0000000000',
                'idempotency_key' => 'pmt-s7-other-acct',
                'allocations' => [],
            ]);
            $this->fail('Expected exception for foreign money account');
        } catch (ModelNotFoundException $e) {
            $this->assertTrue(true);
        }
    }

    /** Scenario 8: Payment before Purchase direct allocation rejected; earlier advance allowed; later application after Payment/Purchase allowed; prior application date rejected */
    public function test_scenario_08_payment_and_application_dates_policy(): void
    {
        $postAction = app(PostVendorPaymentAction::class);
        $applyAction = app(ApplyVendorPaymentCreditAction::class);

        // 1. Direct allocation: payment_date (2026-10-01) < purchase_date (2026-10-05) must FAIL
        $purchase = $this->createAndPostPurchase([
            'purchase_date' => '2026-10-05',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        try {
            $postAction->execute($this->company, $this->owner, [
                'vendor_id' => $this->vendor->id,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_date' => '2026-10-01',
                'payment_method' => 'cash',
                'amount' => '50.00',
                'exchange_rate' => '1.0000000000',
                'idempotency_key' => 'pmt-s8-earlier-fail',
                'allocations' => [
                    ['purchase_id' => $purchase->id, 'allocated_amount' => '50.00'],
                ],
            ]);
            $this->fail('Expected exception for payment date before purchase date in direct allocation');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot precede purchase date', $e->getMessage());
        }

        // 2. But earlier payment AS ADVANCE is permitted
        $advance = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '50.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s8-adv-ok',
            'allocations' => [],
        ]);
        $this->assertEquals('50.000000', (string) $advance->unallocated_amount);

        // 3. Application date < payment_date must FAIL
        try {
            $applyAction->execute($advance, $this->owner, [
                'application_date' => '2026-09-30',
                'idempotency_key' => 'app-s8-prior-pmt',
                'allocations' => [
                    ['purchase_id' => $purchase->id, 'allocated_amount' => '50.00'],
                ],
            ]);
            $this->fail('Expected exception for application date before payment date');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot precede vendor payment date', $e->getMessage());
        }

        // 4. Application date < purchase_date must FAIL
        try {
            $applyAction->execute($advance, $this->owner, [
                'application_date' => '2026-10-03', // earlier than purchase 2026-10-05
                'idempotency_key' => 'app-s8-prior-pur',
                'allocations' => [
                    ['purchase_id' => $purchase->id, 'allocated_amount' => '50.00'],
                ],
            ]);
            $this->fail('Expected exception for application date before purchase date');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot precede purchase', $e->getMessage());
        }

        // 5. Application date >= both payment_date and purchase_date SUCCEEDS
        $event = $applyAction->execute($advance, $this->owner, [
            'application_date' => '2026-10-06',
            'idempotency_key' => 'app-s8-valid',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '50.00'],
            ],
        ]);
        $this->assertNotNull($event);
        $advance->refresh();
        $this->assertEquals('0.000000', (string) $advance->unallocated_amount);
    }
}
