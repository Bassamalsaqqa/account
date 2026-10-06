<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\User;
use App\Models\VendorPayment;
use App\Models\VendorPaymentApplicationEvent;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\PurchasePostingCommandBuilder;
use App\Services\Purchasing\PurchaseReturnPostingCommandBuilder;
use App\Services\Purchasing\VendorPaymentApplicationIntegrityValidator;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

final class VendorPaymentSourceProvenanceRegressionTest extends Phase5ETestCase
{
    public static function sourceTypes(): array
    {
        return [['purchase'], ['purchase_return'], ['vendor_payment'], ['vendor_payment_application']];
    }

    #[DataProvider('sourceTypes')]
    public function test_balanced_orphan_batches_in_each_canonical_namespace_are_rejected(string $type): void
    {
        $template = $this->payment();
        $this->assertHealthy();
        $batchId = $this->insertRogueBatch((int) $template->posting_batch_id, $type, 999999999);
        $this->assertProvenanceFailure($batchId, 'no same-company source record');
    }

    #[DataProvider('sourceTypes')]
    public function test_duplicate_batch_does_not_hide_behind_a_coherent_source(string $type): void
    {
        $source = $this->canonicalEvent($type);
        $canonicalBatch = (int) $source->posting_batch_id;
        $this->assertHealthy();
        $batchId = $this->insertRogueBatch($canonicalBatch, $type, (int) $source->id);
        $this->validateSource($source);
        $this->assertSame($canonicalBatch, (int) $source->fresh()->posting_batch_id);
        $this->assertProvenanceFailure($batchId, 'duplicates original batch');
    }

    public function test_zero_gl_application_is_valid_but_cannot_own_a_rogue_batch(): void
    {
        $payment = $this->payment();
        $purchase = $this->createAndPostPurchase();
        $event = $this->apply($payment, $purchase);
        $this->assertNull($event->posting_batch_id);
        $this->assertHealthy();
        $batchId = $this->insertRogueBatch((int) $payment->posting_batch_id, 'vendor_payment_application', (int) $event->id);
        app(VendorPaymentApplicationIntegrityValidator::class)->validate($event);
        $this->assertProvenanceFailure($batchId, 'canonical posting batch is null');
    }

    public function test_zero_value_purchase_return_is_valid_but_cannot_own_a_rogue_batch(): void
    {
        $payment = $this->payment();
        $purchase = $this->createAndPostPurchase(['lines' => [
            ['unit_cost' => '10', 'discount_type' => 'percent', 'discount_value' => '100'],
            ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
        ]]);
        $return = $this->createAndPostReturn($purchase);
        $this->assertNull($return->posting_batch_id);
        $this->assertHealthy();
        $batchId = $this->insertRogueBatch((int) $payment->posting_batch_id, 'purchase_return', (int) $return->id);
        app(PurchaseReturnPostingCommandBuilder::class)->validatePosted($return);
        $this->assertProvenanceFailure($batchId, 'canonical posting batch is null');
    }

    public function test_source_record_from_another_company_cannot_own_the_batch(): void
    {
        $payment = $this->payment();
        app(CompanyContext::class)->clear();
        $foreign = app(CreateCompanyAction::class)->execute(User::factory()->create(), [
            'name_ar' => 'شركة أخرى', 'name_en' => 'Other Company', 'base_currency_code' => 'ILS',
        ]);
        $this->activate($this->owner);
        // Disposable corruption: retain the same source ID/back-link but break tenant ownership.
        DB::table('vendor_payments')->where('id', $payment->id)->update(['company_id' => $foreign->id]);
        $this->assertProvenanceFailure((int) $payment->posting_batch_id, 'no same-company source record');
    }

    public function test_matching_back_link_does_not_make_a_draft_a_financial_source(): void
    {
        $purchase = $this->createAndPostPurchase();
        DB::table('purchases')->where('id', $purchase->id)->update(['status' => Purchase::STATUS_DRAFT]);
        $this->assertProvenanceFailure((int) $purchase->posting_batch_id, 'uncompleted source event');
    }

    public function test_reversed_originals_and_their_inverse_batches_remain_healthy(): void
    {
        $event = $this->canonicalEvent('vendor_payment_application');
        $payment = VendorPayment::findOrFail($event->vendor_payment_id);
        app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
        $this->assertSame('vendor_payment', DB::table('posting_batches')->where('id', $payment->posting_batch_id)->value('source_type'));
        $this->assertSame('vendor_payment_application', DB::table('posting_batches')->where('id', $event->posting_batch_id)->value('source_type'));
        $this->assertSame(2, DB::table('posting_batches')->where('source_type', 'reversal')->count());
        $this->assertHealthy();
    }

    private function payment(array $changes = []): VendorPayment
    {
        return app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, array_replace([
            'vendor_id' => $this->vendor->id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01', 'payment_method' => 'cash', 'amount' => '100.00',
            'exchange_rate' => '1', 'idempotency_key' => (string) Str::ulid(), 'allocations' => [],
        ], $changes));
    }

    private function apply(VendorPayment $payment, Purchase $purchase): VendorPaymentApplicationEvent
    {
        return app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, [
            'application_date' => '2026-10-02', 'idempotency_key' => (string) Str::ulid(),
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '100.00']],
        ]);
    }

    private function canonicalEvent(string $type): Purchase|PurchaseReturn|VendorPayment|VendorPaymentApplicationEvent
    {
        if ($type === 'vendor_payment') {
            return $this->payment();
        }
        if ($type === 'vendor_payment_application') {
            $payment = $this->payment(['money_account_id' => $this->usdCashAccount->id, 'exchange_rate' => '3.60']);
            $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);

            return $this->apply($payment, $purchase);
        }
        $purchase = $this->createAndPostPurchase();

        return $type === 'purchase_return' ? $this->createAndPostReturn($purchase) : $purchase;
    }

    private function validateSource(Purchase|PurchaseReturn|VendorPayment|VendorPaymentApplicationEvent $source): void
    {
        match (true) {
            $source instanceof Purchase => app(PurchasePostingCommandBuilder::class)->validatePosted($source),
            $source instanceof PurchaseReturn => app(PurchaseReturnPostingCommandBuilder::class)->validatePosted($source),
            $source instanceof VendorPayment => app(VendorPaymentPostedIntegrityValidator::class)->validate($source),
            $source instanceof VendorPaymentApplicationEvent => app(VendorPaymentApplicationIntegrityValidator::class)->validate($source),
        };
    }

    /** Insert corruption only in the disposable DB; clone valid balanced lines/metadata. */
    private function insertRogueBatch(int $templateId, string $type, int $sourceId): int
    {
        $batch = (array) DB::table('posting_batches')->where('id', $templateId)->first();
        unset($batch['id']);
        $batch['public_id'] = (string) Str::ulid();
        $batch['batch_number'] = null;
        $batch['idempotency_key'] = 'rogue-'.Str::ulid();
        $batch['source_type'] = $type;
        $batch['source_id'] = $sourceId;
        $batchId = (int) DB::table('posting_batches')->insertGetId($batch);
        foreach (DB::table('posting_lines')->where('posting_batch_id', $templateId)->get() as $original) {
            $line = (array) $original;
            unset($line['id']);
            $line['posting_batch_id'] = $batchId;
            DB::table('posting_lines')->insert($line);
        }

        return $batchId;
    }

    private function assertHealthy(): void
    {
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy);
        $report = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($report->isHealthy, implode("\n", $report->violations));
    }

    private function assertProvenanceFailure(int $batchId, string $reason): void
    {
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy,
            'Corruption fixture must remain balanced with valid accounting metadata.');
        $before = $this->historyHashes();
        $report = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertFalse($report->isHealthy);
        $this->assertTrue(collect($report->violations)->contains(fn (string $v): bool => str_contains($v, "Canonical source provenance failure: batch [{$batchId}]") && str_contains($v, $reason)),
            implode("\n", $report->violations));
        $this->assertSame($before, $this->historyHashes(), 'Reconciliation must never repair immutable history.');
    }

    private function historyHashes(): array
    {
        $hashes = [];
        foreach (['posting_batches', 'posting_lines', 'purchases', 'purchase_returns', 'vendor_payments', 'vendor_payment_application_events'] as $table) {
            $hashes[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $hashes;
    }
}
