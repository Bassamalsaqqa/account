<?php

declare(strict_types=1);

namespace Tests\Feature\Refinement;

use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Purchasing\Queries\VendorProductPriceHistoryQuery;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Services\Audit\AuditService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\PurchasePostingCommandBuilder;
use App\Services\Purchasing\PurchaseReceiptCapability;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase5E\Phase5ETestCase;

final class ZeroCostPurchaseWorkflowTest extends Phase5ETestCase
{
    private function draft(): Purchase
    {
        return app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id, 'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-01', 'currency_code' => 'ILS', 'exchange_rate' => '1',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '3', 'unit_cost' => '0']],
        ]);
    }

    private function reconcile(): void
    {
        foreach (['accounting:reconcile', 'inventory:reconcile', 'sales:reconcile', 'money:reconcile', 'phase7:reconcile'] as $command) {
            $this->assertSame(0, Artisan::call($command, ['companyPublicId' => $this->company->public_id]), Artisan::output());
        }
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_free_receipt_is_numbered_audited_retry_safe_and_included_in_reports_and_price_history(): void
    {
        $draft = $this->draft();
        $posted = app(PostPurchaseAction::class)->execute($draft, $this->owner);
        $this->assertTrue($posted->isPosted());
        $this->assertNotEmpty($posted->purchase_number);
        $this->assertNull($posted->posting_batch_id);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('posting_lines', 0);
        $this->assertSame('3.000000', StockMovement::sole()->quantity_delta_base);
        $this->assertSame('0.000000', StockMovement::sole()->value_delta_base);
        $this->assertSame('0.000000', (string) $posted->payablePosition()->outstanding);
        $this->assertSame($posted->id, app(PostPurchaseAction::class)->execute($draft, $this->owner)->id);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('audit_events', ['company_id' => $this->company->id, 'event_key' => 'purchase.posted']);
        $report = app(ReportRegistry::class)->execute($this->company, 'purchases.summary', ['period' => [
            'preset' => 'custom', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'timezone' => $this->company->timezone,
        ]]);
        $this->assertSame(1, $report->totals['purchase_count']);
        $this->assertSame('0.000000', $report->totals['net_purchases_base']);
        $history = app(VendorProductPriceHistoryQuery::class)->execute($this->vendor, $this->product, (int) $this->company->id);
        $this->assertCount(1, $history);
        $this->reconcile();
    }

    public function test_free_stock_can_be_returned_without_creating_a_payable_or_fictitious_journal(): void
    {
        $purchase = app(PostPurchaseAction::class)->execute($this->draft(), $this->owner);
        $return = $this->createAndPostReturn($purchase, ['lines' => [['quantity' => '1']]]);
        $this->assertTrue($return->isPosted());
        $this->assertNull($return->posting_batch_id);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertSame('2.000000', (string) $this->product->fresh()->stock_quantity);
        $this->reconcile();
    }

    public function test_failure_after_stock_and_numbering_rolls_back_every_effect(): void
    {
        $draft = $this->draft();
        $before = DB::table('document_sequences')->orderBy('id')->get()->toJson();
        $this->partialMock(AuditService::class, fn ($mock) => $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('Synthetic late audit failure')));
        try {
            app(PostPurchaseAction::class)->execute($draft, $this->owner);
            $this->fail('Late failure was ignored.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic late audit failure', $exception->getMessage());
        }
        $this->assertTrue($draft->fresh()->isDraft());
        $this->assertNull($draft->fresh()->purchase_number);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertSame($before, DB::table('document_sequences')->orderBy('id')->get()->toJson());
    }

    public function test_a_forged_capability_cannot_finalize_a_free_draft(): void
    {
        $draft = $this->draft();
        try {
            DB::transaction(fn () => $draft->completeCanonicalPost(null, 'FORGED', $this->owner, new PurchaseReceiptCapability));
            $this->fail('Forged completion was accepted.');
        } catch (ImmutableRecordException) {
            $this->assertTrue($draft->fresh()->isDraft());
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_a_nonzero_receipt_cannot_masquerade_as_a_nonmonetary_document(): void
    {
        $purchase = $this->createAndPostPurchase();
        DB::table('purchases')->where('id', $purchase->id)->update(['posting_batch_id' => null, 'grand_total_base' => '0', 'grand_total_currency' => '0']);
        $this->expectException(\InvalidArgumentException::class);
        app(PurchasePostingCommandBuilder::class)->validatePosted($purchase->fresh());
    }
}
