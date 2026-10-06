<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Livewire\Pages\Purchasing\PaymentDetail;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

final class VendorPaymentAllocationStatusRegressionTest extends Phase5ETestCase
{
    public static function locales(): array
    {
        return [['en'], ['ar']];
    }

    private function allocationStatuses(string $html): array
    {
        $document = new DOMDocument;
        $errors = libxml_use_internal_errors(true);
        try {
            $this->assertTrue($document->loadHTML('<?xml encoding="UTF-8">'.$html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }
        $xpath = new DOMXPath($document);
        $rows = $xpath->query('//table/tbody/tr[td[1]/a]');
        $this->assertNotFalse($rows);
        $statuses = [];
        foreach ($rows as $row) {
            $cells = $xpath->query('./td', $row);
            $this->assertSame(5, $cells->length);
            $number = trim($cells->item(0)->textContent);
            $statuses[$number] = trim(preg_replace('/\s+/u', ' ', $cells->item(4)->textContent));
        }

        return $statuses;
    }

    #[DataProvider('locales')]
    public function test_initial_and_later_allocations_show_reversed_history_in_detail(string $locale): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 22:30:00', 'UTC'));
        app()->setLocale($locale);
        $initialPurchase = $this->createAndPostPurchase();
        $laterPurchase = $this->createAndPostPurchase();
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1',
            'idempotency_key' => 'allocation-status',
            'allocations' => [['purchase_id' => $initialPurchase->id, 'allocated_amount' => '40.00']],
        ]);
        app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, [
            'application_date' => '2026-10-03',
            'idempotency_key' => 'allocation-status-application',
            'allocations' => [['purchase_id' => $laterPurchase->id, 'allocated_amount' => '30.00']],
        ]);
        $history = $payment->allocations()->orderBy('id')->get()->toArray();
        $component = Livewire::test(PaymentDetail::class, ['publicId' => $payment->public_id])->assertOk();
        $statuses = $this->allocationStatuses($component->html());
        $this->assertCount(2, $statuses);
        $this->assertSame(__('purchasing.posted'), $statuses[$initialPurchase->purchase_number]);
        $this->assertStringContainsString(__('purchasing.apply_advance'), $statuses[$laterPurchase->purchase_number]);

        $component->call('reversePayment')->assertHasNoErrors();
        $expected = [
            $initialPurchase->purchase_number => __('purchasing.reversed'),
            $laterPurchase->purchase_number => __('purchasing.reversed'),
        ];
        $this->assertSame($expected, $this->allocationStatuses($component->html()));
        $this->assertSame($history, $payment->allocations()->orderBy('id')->get()->toArray());
        $this->assertSame('0.000000', $payment->fresh()->allocated_amount);

        $batches = DB::table('posting_batches')->count();
        $reloaded = Livewire::test(PaymentDetail::class, ['publicId' => $payment->public_id])->assertOk();
        $this->assertSame($expected, $this->allocationStatuses($reloaded->html()));
        $reloaded->call('$refresh')->assertOk();
        $this->assertSame($expected, $this->allocationStatuses($reloaded->html()));
        $this->assertSame($batches, DB::table('posting_batches')->count());
    }
}
