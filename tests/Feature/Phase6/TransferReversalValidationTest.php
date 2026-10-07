<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Money\PostMoneyTransferAction;
use App\Actions\Money\ReverseMoneyTransferAction;
use App\Livewire\Pages\Money\TransferDetail;
use App\Models\MoneyTransfer;
use App\Models\PostingBatch;
use App\Services\Money\MoneyTransferHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class TransferReversalValidationTest extends Phase6TestCase
{
    private function transfer(): MoneyTransfer
    {
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00', $this->company->timezone));

        return app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, [
            'from_money_account_id' => $this->usdCashAccount->id, 'to_money_account_id' => $this->usdBankAccount->id,
            'transfer_date' => '2026-10-07', 'from_amount' => '10', 'to_amount' => '10',
            'from_exchange_rate' => '3.5', 'to_exchange_rate' => '3.5', 'idempotency_key' => 'reason-boundary',
        ]);
    }

    private function snapshot(): array
    {
        $state = [];
        foreach (['money_transfers', 'posting_batches', 'posting_lines', 'audit_events', 'document_sequences'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $state;
    }

    public function test_500_character_reason_reverses_and_preserves_exact_text(): void
    {
        $transfer = $this->transfer();
        $reason = str_repeat('س', 500);
        $batches = PostingBatch::count();

        Livewire::test(TransferDetail::class, ['publicId' => $transfer->public_id])->set('reason', $reason)
            ->call('reverse')->assertStatus(200)->assertHasNoErrors();

        $transfer->refresh();
        $this->assertTrue($transfer->is_reversed);
        $this->assertSame($reason, $transfer->reversal_reason);
        $this->assertSame($batches + 1, PostingBatch::count());
        $reversal = PostingBatch::findOrFail($transfer->reversal_posting_batch_id);
        $this->assertSame('reversal', $reversal->source_type);
        $this->assertSame($transfer->posting_batch_id, $reversal->reversal_of_id);
        app(MoneyTransferHistory::class)->validate($transfer);
    }

    public function test_501_character_reason_has_field_error_and_zero_financial_effects(): void
    {
        $transfer = $this->transfer();
        $before = $this->snapshot();
        $page = Livewire::test(TransferDetail::class, ['publicId' => $transfer->public_id])->set('reason', str_repeat('x', 501))
            ->call('reverse')->assertStatus(200)->assertHasErrors(['reason' => 'max'])->assertHasNoErrors(['transfer']);

        $this->assertSame($before, $this->snapshot());
        $this->assertFalse($transfer->fresh()->is_reversed);
        $this->assertNull($transfer->fresh()->reversal_posting_batch_id);
        $this->assertFalse(PostingBatch::where('reversal_of_id', $transfer->posting_batch_id)->exists());
        $this->assertStringContainsString((string) $page->errors()->first('reason'), $page->html());
    }

    public function test_domain_independently_rejects_501_characters_without_mutation(): void
    {
        $transfer = $this->transfer();
        $before = $this->snapshot();
        try {
            app(ReverseMoneyTransferAction::class)->execute($transfer, $this->owner, reason: str_repeat('x', 501));
            $this->fail('Oversized reversal reason must not reach financial reversal.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Invalid money event text.', $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertFalse($transfer->fresh()->is_reversed);
        $this->assertNull($transfer->fresh()->reversal_posting_batch_id);
        $this->assertFalse(PostingBatch::where('reversal_of_id', $transfer->posting_batch_id)->exists());
    }

    public static function locales(): array
    {
        return [['ar'], ['en']];
    }

    #[DataProvider('locales')]
    public function test_transfer_form_renders_limit_and_field_error_in_supported_locales(string $locale): void
    {
        $transfer = $this->transfer();
        app()->setLocale($locale);
        $before = $this->snapshot();
        $page = Livewire::test(TransferDetail::class, ['publicId' => $transfer->public_id])->assertStatus(200)
            ->assertSee('maxlength="500"', false)->assertSee(__('money.reason'))
            ->set('reason', str_repeat('x', 501))->call('reverse')->assertStatus(200)->assertHasErrors(['reason' => 'max']);

        $this->assertStringContainsString((string) $page->errors()->first('reason'), $page->html());
        $this->assertSame($before, $this->snapshot());
    }
}
