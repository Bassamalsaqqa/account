<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Livewire\Pages\Purchasing\PurchaseDetail;
use App\Livewire\Pages\Purchasing\PurchaseIndex;
use App\Models\Purchase;
use App\Services\Purchasing\PurchasePayablePosition;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

final class VendorPaymentCorrection05RegressionTest extends Phase5ETestCase
{
    /** @var list<string> */
    private array $readQueries = [];

    private function partiallyPaidPurchase(): Purchase
    {
        $purchase = $this->createAndPostPurchase(['lines' => [['unit_cost' => '123.45']]]);
        app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02', 'payment_method' => 'cash', 'amount' => '234.56',
            'exchange_rate' => '1', 'idempotency_key' => 'correction05-payment',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '234.56']],
        ]);
        $this->createAndPostReturn($purchase, ['return_date' => '2026-10-03', 'lines' => [['quantity' => '2']]]);

        return $purchase;
    }

    private function trackReadQueries(): void
    {
        $this->readQueries = [];
        DB::listen(function (QueryExecuted $query): void {
            $this->readQueries[] = $query->sql;
        });
    }

    private function assertNoPayableQueries(): void
    {
        foreach ($this->readQueries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/vendor_payment(?:s|_allocations|_application_events)|purchase_returns/i', $sql);
        }
    }

    private function assertRedactedPayload(string $html, array $snapshot): void
    {
        $payload = json_encode($snapshot, JSON_THROW_ON_ERROR);
        foreach (['753.04', '753.040000', '234.56', '234.560000', '246.90', '246.900000'] as $value) {
            $this->assertStringNotContainsString($value, $html);
            $this->assertStringNotContainsString($value, $payload);
        }
        foreach (['payablePosition', 'payablePositions', 'activeAllocatedAmount', 'postedReturnedAmount', 'outstanding', 'partially_paid'] as $key) {
            $this->assertStringNotContainsString($key, $payload);
        }
        $this->assertStringNotContainsString(__('purchasing.partially_paid'), $html);
        $this->assertStringNotContainsString(__('purchasing.pay_vendor'), $html);
    }

    public function test_cost_only_detail_retains_document_costs_but_never_queries_or_exposes_payables(): void
    {
        $purchase = $this->partiallyPaidPurchase();
        $actor = $this->customActor(['purchasing.purchase.view', 'purchasing.cost.view']);
        $this->activate($actor);
        $this->trackReadQueries();
        $page = Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->assertOk()->assertViewHas('withCost', true)->assertSee('1,234.50')->assertSee('123.45')
            ->assertViewHas('document', fn ($data) => $data['grand_total_currency'] === '1234.500000')
            ->assertViewHas('payablePosition', null)->assertViewHas('canPayVendor', false);
        $this->assertNoPayableQueries();
        $this->assertRedactedPayload($page->html(false), $page->snapshot);
    }

    public function test_cost_only_index_retains_document_total_but_never_queries_or_exposes_settlement(): void
    {
        $this->partiallyPaidPurchase();
        $actor = $this->customActor(['purchasing.purchase.view', 'purchasing.cost.view']);
        $this->activate($actor);
        $this->trackReadQueries();
        $page = Livewire::test(PurchaseIndex::class)->assertOk()->assertViewHas('withCost', true)
            ->assertSee('1,234.50')->assertViewHas('payablePositions', []);
        $this->assertNoPayableQueries();
        $this->assertRedactedPayload($page->html(false), $page->snapshot);
    }

    public static function approvedReaders(): array
    {
        return array_map(fn (string $permission) => [$permission], [
            'money.vendor_payment.create', 'money.vendor_payment.allocate',
            'money.vendor_payment.reverse', 'vendors.statement.view',
        ]);
    }

    #[DataProvider('approvedReaders')]
    public function test_each_approved_financial_reader_with_cost_gets_detail_and_index_position(string $permission): void
    {
        $purchase = $this->partiallyPaidPurchase();
        $actor = $this->customActor(['purchasing.purchase.view', 'purchasing.cost.view', $permission]);
        $this->activate($actor);
        Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])->assertOk()
            ->assertSee('753.04')->assertSee('234.56')->assertSee('246.90')
            ->assertViewHas('payablePosition', fn (PurchasePayablePosition $position) => $position->outstanding->isEqualTo('753.04') && $position->status === 'partially_paid')
            ->assertViewHas('canPayVendor', $permission === 'money.vendor_payment.create');
        Livewire::test(PurchaseIndex::class)->assertOk()->assertSee(__('purchasing.partially_paid'))
            ->assertViewHas('payablePositions', fn ($positions) => $positions[$purchase->id]->outstanding->isEqualTo('753.04'));
    }

    public function test_financial_permission_without_cost_never_queries_or_exposes_either_payable_surface(): void
    {
        $purchase = $this->partiallyPaidPurchase();
        $actor = $this->customActor(['purchasing.purchase.view', 'money.vendor_payment.create']);
        $this->activate($actor);
        $this->trackReadQueries();
        $detail = Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])->assertOk()
            ->assertViewHas('withCost', false)->assertViewHas('payablePosition', null)->assertViewHas('canPayVendor', false)
            ->assertDontSee('1,234.50');
        $index = Livewire::test(PurchaseIndex::class)->assertOk()->assertViewHas('withCost', false)
            ->assertViewHas('payablePositions', [])->assertDontSee('1,234.50');
        $this->assertNoPayableQueries();
        $this->assertRedactedPayload($detail->html(false), $detail->snapshot);
        $this->assertRedactedPayload($index->html(false), $index->snapshot);
    }

    public function test_stale_detail_revocation_removes_position_and_cta_before_querying_or_serializing(): void
    {
        $purchase = $this->partiallyPaidPurchase();
        $actor = $this->customActor(['purchasing.purchase.view', 'purchasing.cost.view', 'money.vendor_payment.create']);
        $this->activate($actor);
        $page = Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->assertSee('753.04')->assertViewHas('canPayVendor', true);
        $actor->roles->first()->revokePermissionTo('money.vendor_payment.create');
        $this->trackReadQueries();
        $page->call('$refresh')->assertOk()->assertViewHas('payablePosition', null)
            ->assertViewHas('canPayVendor', false)->assertSee('1,234.50');
        $this->assertNoPayableQueries();
        $this->assertRedactedPayload($page->html(false), $page->snapshot);
    }

    public function test_stale_index_revocation_removes_settlement_before_querying_or_serializing(): void
    {
        $this->partiallyPaidPurchase();
        $actor = $this->customActor(['purchasing.purchase.view', 'purchasing.cost.view', 'vendors.statement.view']);
        $this->activate($actor);
        $page = Livewire::test(PurchaseIndex::class)->assertSee(__('purchasing.partially_paid'));
        $actor->roles->first()->revokePermissionTo('vendors.statement.view');
        $this->trackReadQueries();
        $page->call('$refresh')->assertOk()->assertViewHas('payablePositions', [])->assertSee('1,234.50');
        $this->assertNoPayableQueries();
        $this->assertRedactedPayload($page->html(false), $page->snapshot);
    }

    public function test_owner_retains_payable_details_index_status_and_pay_vendor_cta(): void
    {
        $purchase = $this->partiallyPaidPurchase();
        Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])->assertOk()
            ->assertSee('753.04')->assertViewHas('canPayVendor', true);
        Livewire::test(PurchaseIndex::class)->assertOk()->assertSee(__('purchasing.partially_paid'))
            ->assertViewHas('payablePositions', fn ($positions) => isset($positions[$purchase->id]));
    }
}
