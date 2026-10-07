<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Money\TransitionCheckAction;
use App\Livewire\Pages\Money\CheckDetail;
use App\Models\Check;
use App\Models\PostingBatch;
use App\Services\Money\CheckHistory;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class CheckTerminalReasonValidationTest extends Phase6TestCase
{
    private function snapshot(): array
    {
        $state = [];
        foreach (['checks', 'check_events', 'posting_batches', 'posting_lines', 'customer_payments', 'customer_payment_allocations',
            'customer_payment_application_events', 'vendor_payments', 'vendor_payment_allocations', 'vendor_payment_application_events', 'audit_events', 'document_sequences'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $state;
    }

    public static function terminalTransitions(): array
    {
        return [['incoming', 'return', 'returned'], ['incoming', 'cancel', 'cancelled'],
            ['outgoing', 'return', 'returned'], ['outgoing', 'cancel', 'cancelled']];
    }

    #[DataProvider('terminalTransitions')]
    public function test_500_character_terminal_reason_reverses_payment_exactly(string $direction, string $type, string $status): void
    {
        $check = $this->check($direction);
        $reason = str_repeat('س', 500);
        $batches = PostingBatch::count();
        Livewire::test(CheckDetail::class, ['publicId' => $check->public_id])->set('eventDate', '2026-10-03')->set('notes', $reason)
            ->call('recordTransition', $type)->assertStatus(200)->assertHasNoErrors();

        $payment = $direction === 'incoming' ? $check->customerPayment()->sole() : $check->vendorPayment()->sole();
        $event = $check->events()->where('event_type', $type)->sole();
        $this->assertSame($status, $check->fresh()->status);
        $this->assertTrue($payment->is_reversed);
        $this->assertSame($reason, $payment->reversal_reason);
        $this->assertSame($reason, $event->notes);
        $this->assertNotNull($event->completed_at);
        $this->assertSame($payment->reversal_posting_batch_id, $event->payment_reversal_posting_batch_id);
        $this->assertSame($payment->posting_batch_id, PostingBatch::findOrFail($event->payment_reversal_posting_batch_id)->reversal_of_id);
        $this->assertSame($batches + 1, PostingBatch::count());
        app(CheckHistory::class)->validate($check->fresh());
    }

    #[DataProvider('terminalTransitions')]
    public function test_livewire_rejects_501_character_terminal_reason_without_effects(string $direction, string $type, string $status): void
    {
        $check = $this->check($direction);
        $before = $this->snapshot();
        $page = Livewire::test(CheckDetail::class, ['publicId' => $check->public_id])->set('eventDate', '2026-10-03')->set('notes', str_repeat('x', 501));
        $key = $page->get('requestKey');
        $page->call('recordTransition', $type)->assertStatus(200)->assertHasErrors(['notes' => 'max'])->assertHasNoErrors(['check'])->assertSet('requestKey', $key);
        $this->assertStringContainsString((string) $page->errors()->first('notes'), $page->html());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($direction === 'incoming' ? 'received' : 'issued', $check->fresh()->status);
        $this->assertFalse(($direction === 'incoming' ? $check->customerPayment()->sole() : $check->vendorPayment()->sole())->is_reversed);
    }

    #[DataProvider('terminalTransitions')]
    public function test_domain_rejects_501_character_terminal_reason_without_effects(string $direction, string $type, string $status): void
    {
        $check = $this->check($direction);
        $this->assertRejected($check, $type);
    }

    private function assertRejected(Check $check, string $type): void
    {
        $before = $this->snapshot();
        try {
            app(TransitionCheckAction::class)->execute($check->fresh(), $this->owner, ['event_type' => $type, 'event_date' => '2026-10-04',
                'notes' => str_repeat('x', 501), 'idempotency_key' => 'long-terminal']);
            $this->fail('Oversized terminal reason must fail before any reversal.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Invalid money event text.', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_oversized_return_after_clearance_rejects_before_dependent_reversal(): void
    {
        $check = $this->check();
        $this->event($check, 'deposit');
        $this->event($check->fresh(), 'clear');
        $this->assertRejected($check, 'return');
        $before = $this->snapshot();
        Livewire::test(CheckDetail::class, ['publicId' => $check->public_id])->set('eventDate', '2026-10-04')->set('notes', str_repeat('x', 501))
            ->call('recordTransition', 'return')->assertHasErrors(['notes' => 'max']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('cleared', $check->fresh()->status);
    }

    public function test_deposit_and_clear_retain_2000_character_notes_contract(): void
    {
        $check = $this->check();
        $notes = str_repeat('x', 2000);
        $page = Livewire::test(CheckDetail::class, ['publicId' => $check->public_id])->set('eventDate', '2026-10-03')
            ->set('bankId', $this->usdBankAccount->id)->set('notes', $notes)->call('recordTransition', 'deposit')->assertHasNoErrors();
        $this->assertSame($notes, $check->events()->where('event_type', 'deposit')->sole()->notes);
        $page->set('rate', '3.60')->call('recordTransition', 'clear')->assertHasNoErrors();
        $this->assertSame($notes, $check->events()->where('event_type', 'clear')->sole()->notes);
        $this->assertSame('cleared', $check->fresh()->status);
        app(CheckHistory::class)->validate($check->fresh());
    }
}
