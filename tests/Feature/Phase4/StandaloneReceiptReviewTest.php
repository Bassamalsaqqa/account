<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Livewire\Pages\Sales\PaymentDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class StandaloneReceiptReviewTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    public function test_open_review_redacts_document_rows_after_view_permission_revocation(): void
    {
        $invoice = $this->invoice('30');
        $payment = $this->receipt('100', '3.50', [['sales_invoice_id' => $invoice->id, 'allocated_amount' => '30']]);
        $page = Livewire::test(PaymentDetail::class, ['publicId' => $payment->public_id])
            ->call('openReversalReview')->assertSee($invoice->invoice_number);
        $this->owner->roles()->firstOrFail()->revokePermissionTo('sales.invoice.view');
        $page->call('$refresh');
        $rows = $page->get('reversalReview')['rows'];
        $this->assertSame([], array_values(array_filter($rows, fn ($row) => isset($row['invoice_number']))));
        $this->assertFalse($payment->fresh()->is_reversed);
    }

    public function test_locale_change_preserves_economic_review(): void
    {
        $payment = $this->receipt();
        $page = Livewire::test(PaymentDetail::class, ['publicId' => $payment->public_id])->call('openReversalReview');
        app()->setLocale('ar');
        $page->set('reversalReason', 'Correct receipt')->call('confirmReversalReview')->assertHasNoErrors();
        $this->assertTrue($payment->fresh()->is_reversed);
    }

    public function test_added_credit_application_requires_refreshed_review_of_every_invoice(): void
    {
        $first = $this->invoice('30');
        $second = $this->invoice('40');
        $payment = $this->receipt('100', '3.50', [['sales_invoice_id' => $first->id, 'allocated_amount' => '30']]);
        $page = Livewire::test(PaymentDetail::class, ['publicId' => $payment->public_id])
            ->call('openReversalReview')->assertSee($first->invoice_number)->assertDontSee('wire:confirm=', false);
        app(ApplyCustomerPaymentCreditAction::class)->execute($payment, $this->owner, [
            'application_date' => '2026-10-02', 'idempotency_key' => 'review-added-credit',
            'allocations' => [['sales_invoice_id' => $second->id, 'allocated_amount' => '40']],
        ]);
        $page->set('reversalReason', 'Correct full receipt')->call('confirmReversalReview')
            ->assertHasErrors('review')->assertSee($second->invoice_number)->assertSee($first->invoice_number);
        $this->assertFalse($payment->fresh()->is_reversed);
        $page->call('confirmReversalReview')->assertHasNoErrors();
        $this->assertTrue($payment->fresh()->is_reversed);
        $this->assertSame('30.000000', (string) $first->calculateOutstanding()->toScale(6));
        $this->assertSame('40.000000', (string) $second->calculateOutstanding()->toScale(6));
    }
}
