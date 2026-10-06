<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Exceptions\IdempotencyConflictException;
use App\Models\VendorPayment;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class VendorPaymentLocaleNormalizationRegressionTest extends Phase5ETestCase
{
    private function intent(array $changes = []): array
    {
        return array_replace([
            'vendor_id' => $this->vendor->id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01', 'payment_method' => 'cash', 'amount' => '1.00',
            'exchange_rate' => '1', 'idempotency_key' => 'locale-normalization', 'allocations' => [],
        ], $changes);
    }

    public static function implicitLocales(): array
    {
        return [[null], [''], ['   '], ["\t\n"]];
    }

    #[DataProvider('implicitLocales')]
    public function test_blank_and_null_locale_match_omitted_intent_and_preserve_historical_retry(?string $locale): void
    {
        $this->vendor->update(['preferred_locale' => 'en']);
        $action = app(PostVendorPaymentAction::class);
        $payment = $action->execute($this->company, $this->owner, $this->intent(['document_locale' => $locale]));
        $this->assertSame('en', $payment->document_locale);
        app(VendorPaymentPostedIntegrityValidator::class)->validate($payment);
        $before = $this->snapshot();
        $this->assertSame($payment->id, $action->execute($this->company, $this->owner, $this->intent())->id);
        $this->assertSame($before, $this->snapshot());
        $this->vendor->update(['preferred_locale' => 'ar', 'status' => 'inactive']);
        $this->ilsCashAccount->update(['is_active' => false]);
        foreach ([null, '', '   ', "\t\n"] as $equivalent) {
            $retry = $action->execute($this->company, $this->owner, $this->intent(['document_locale' => $equivalent]));
            $this->assertSame($payment->id, $retry->id);
            $this->assertSame('en', $retry->document_locale);
            $this->assertSame($payment->vendor_snapshot, $retry->vendor_snapshot);
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_explicit_locale_remains_distinct_idempotency_intent(): void
    {
        $action = app(PostVendorPaymentAction::class);
        $payment = $action->execute($this->company, $this->owner, $this->intent(['document_locale' => '']));
        $before = $this->snapshot();
        try {
            $action->execute($this->company, $this->owner, $this->intent(['document_locale' => $payment->document_locale]));
            $this->fail('Explicit locale intent was collapsed into implicit default selection.');
        } catch (IdempotencyConflictException) {
            $this->assertSame($before, $this->snapshot());
        }
        $this->assertDatabaseCount('vendor_payments', 1);
    }

    public function test_nonblank_unsupported_locale_still_fails_with_zero_effects(): void
    {
        $before = $this->snapshot();
        try {
            app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $this->intent(['document_locale' => 'fr']));
            $this->fail('Unsupported explicit locale was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, $this->snapshot());
        }
        $this->assertSame(0, VendorPayment::count());
    }

    private function snapshot(): array
    {
        $state = [];
        foreach (['vendor_payments', 'vendor_payment_allocations', 'posting_batches', 'posting_lines', 'document_sequences', 'audit_events'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $state;
    }
}
