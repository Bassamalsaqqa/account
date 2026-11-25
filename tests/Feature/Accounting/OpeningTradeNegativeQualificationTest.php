<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Actions\Accounting\PostOpeningTradeItemAction;
use App\Actions\Accounting\ReverseOpeningTradeItemAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Domain\Posting\Exceptions\IdempotencyConflictException;
use App\Exceptions\CompanyReassignmentException;
use App\Models\Company;
use App\Models\OpeningTradeItem;
use Brick\Math\Exception\MathException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Phase8\TradeTestCase;

final class OpeningTradeNegativeQualificationTest extends TradeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activateUser($this->owner);
    }

    private function postOpening(string $side, array $overrides = []): OpeningTradeItem
    {
        return app(PostOpeningTradeItemAction::class)->execute(...array_replace([
            'company' => $this->company, 'side' => $side,
            'partyId' => $side === 'customer' ? $this->defaultCustomer->id : $this->vendor->id,
            'openingDate' => '2026-01-01', 'amount' => '100', 'currencyCode' => 'ILS',
            'exchangeRate' => '1', 'actor' => $this->owner, 'idempotencyKey' => 'negative-'.$side,
        ], $overrides));
    }

    private function history(): array
    {
        $snapshot = [];
        foreach (['opening_trade_items', 'posting_batches', 'posting_lines', 'audit_events', 'document_sequences',
            'customer_payments', 'vendor_payments', 'customer_payment_allocations', 'vendor_payment_allocations'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    private function rejectsWithoutEffects(callable $operation): void
    {
        $before = $this->history();
        $exception = null;
        try {
            $operation();
        } catch (\InvalidArgumentException|AuthorizationException|MathException|IdempotencyConflictException|CompanyReassignmentException $e) {
            $exception = $e;
        }
        $this->assertNotNull($exception, 'Expected domain rejection');
        $this->assertSame($before, $this->history(), 'Rejected command changed financial history or provenance');
    }

    public function test_actor_permission_and_foreign_party_boundaries(): void
    {
        $otherCompany = Company::factory()->create();
        foreach (['customer', 'vendor'] as $side) {
            $item = $this->postOpening($side);
            $party = $side === 'customer' ? $this->defaultCustomer : $this->vendor;
            DB::table($party->getTable())->where('id', $party->id)->update(['company_id' => $otherCompany->id]);
            $this->rejectsWithoutEffects(fn () => $this->postOpening($side, ['idempotencyKey' => 'foreign-party-'.$side]));
            DB::table($party->getTable())->where('id', $party->id)->update(['company_id' => $this->company->id]);
            $this->rejectsWithoutEffects(fn () => $this->postOpening($side, ['company' => $otherCompany]));
            $actor = $this->customActor([], 'OpeningDenied');
            $this->rejectsWithoutEffects(fn () => $this->postOpening($side, ['actor' => $actor]));
            $this->activateUser($actor);
            $this->rejectsWithoutEffects(fn () => $this->postOpening($side, ['actor' => $actor]));
            $this->rejectsWithoutEffects(fn () => app(ReverseOpeningTradeItemAction::class)->execute($item, $actor, 'Correction'));
            $this->activateUser($this->owner);
        }
    }

    public function test_invalid_economic_inputs_are_atomic_on_both_sides(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            foreach ([
                ['amount' => '0'], ['amount' => '-1'], ['amount' => 'invalid'],
                ['amount' => '0.0000001'], ['amount' => '1.001', 'currencyCode' => 'ILS'],
                ['amount' => '1.001', 'currencyCode' => 'USD', 'exchangeRate' => '3.5000000000'],
                ['currencyCode' => 'ZZZ'],
                ['currencyCode' => 'USD', 'exchangeRate' => null],
                ['currencyCode' => 'USD', 'exchangeRate' => '0'],
                ['currencyCode' => 'USD', 'exchangeRate' => '-1'],
                ['currencyCode' => 'USD', 'exchangeRate' => '1.12345678901'],
                ['exchangeRate' => '2'], ['openingDate' => '2099-01-01'],
                ['openingDate' => '01/01/2026'], ['dueDate' => 'invalid'], ['partyId' => 2147483647],
            ] as $overrides) {
                $this->rejectsWithoutEffects(fn () => $this->postOpening($side, $overrides));
            }
        }
    }

    public function test_inactive_party_and_membership_cannot_post_or_reverse(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            $party = $side === 'customer' ? $this->defaultCustomer : $this->vendor;
            $party->update(['status' => 'inactive']);
            $this->rejectsWithoutEffects(fn () => $this->postOpening($side));
            $party->update(['status' => 'active']);
            $item = $this->postOpening($side);
            DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
            $this->rejectsWithoutEffects(fn () => $this->postOpening($side, ['idempotencyKey' => 'inactive-'.$side]));
            $this->rejectsWithoutEffects(fn () => app(ReverseOpeningTradeItemAction::class)->execute($item, $this->owner, 'Correction'));
            DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'active']);
        }
    }

    public function test_correction_dates_reasons_and_repeated_reversal_are_atomic(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            $item = $this->postOpening($side);
            foreach ([['', '2026-02-01'], [str_repeat('x', 513), '2026-02-01'], ['Correction', '2025-12-31'], ['Correction', '2099-01-01'], ['Correction', 'invalid']] as [$reason, $date]) {
                $this->rejectsWithoutEffects(fn () => app(ReverseOpeningTradeItemAction::class)->execute($item, $this->owner, $reason, $date));
            }
            app(ReverseOpeningTradeItemAction::class)->execute($item, $this->owner, 'Correction', '2026-02-01');
            $this->rejectsWithoutEffects(fn () => app(ReverseOpeningTradeItemAction::class)->execute($item, $this->owner, 'Again', '2026-02-02'));
        }
    }

    public function test_conflicting_replays_do_not_mutate_posted_history(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            $item = $this->postOpening($side);
            $before = $this->history();
            $this->assertSame($item->id, $this->postOpening($side)->id);
            $this->assertSame($before, $this->history());
            foreach ([['amount' => '101'], ['openingDate' => '2026-01-02'], ['dueDate' => '2026-02-01'], ['originalReference' => 'changed']] as $overrides) {
                $this->rejectsWithoutEffects(fn () => $this->postOpening($side, $overrides));
            }
        }
    }

    public function test_wrong_side_reversed_and_predated_settlements_are_atomic(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            $customerSide = $side === 'customer';
            $item = $this->postOpening($side);
            $wrongSide = $this->postOpening($customerSide ? 'vendor' : 'customer', ['idempotencyKey' => 'wrong-side-'.$side]);
            $action = app($customerSide ? PostCustomerPaymentAction::class : PostVendorPaymentAction::class);
            $payload = [
                $customerSide ? 'customer_id' : 'vendor_id' => $customerSide ? $this->defaultCustomer->id : $this->vendor->id,
                'money_account_id' => $this->ilsCashAccount->id, 'payment_date' => '2026-01-02',
                'payment_method' => 'cash', 'currency_code' => 'ILS', 'exchange_rate' => '1',
                'amount' => '10', 'idempotency_key' => 'negative-payment-'.$side,
            ];
            foreach ([[$wrongSide->id, '2026-01-02'], [$item->id, '2025-12-31']] as [$id, $date]) {
                $this->rejectsWithoutEffects(fn () => $action->execute($this->company, $this->owner, array_replace($payload, [
                    'payment_date' => $date, 'allocations' => [['opening_trade_item_id' => $id, 'allocated_amount' => '10']],
                ])));
            }
            foreach ([
                ['opening_trade_item_id' => $item->id, $customerSide ? 'sales_invoice_id' : 'purchase_id' => 1, 'allocated_amount' => '10'],
                ['kind' => 'unknown', 'id' => $item->id, 'allocated_amount' => '10'],
                ['opening_trade_item_id' => $item->id, 'allocated_amount' => '101'],
                ['opening_trade_item_id' => $item->id, 'allocated_amount' => '0'],
            ] as $allocation) {
                $this->rejectsWithoutEffects(fn () => $action->execute($this->company, $this->owner, $payload + ['allocations' => [$allocation]]));
            }
            $partyModel = $customerSide ? $this->defaultCustomer : $this->vendor;
            $otherParty = $partyModel->replicate();
            $otherParty->public_id = (string) Str::ulid();
            $otherParty->code = 'NEG-OTHER-'.$side;
            $otherParty->save();
            $this->rejectsWithoutEffects(fn () => $action->execute($this->company, $this->owner, array_replace($payload, [
                $customerSide ? 'customer_id' : 'vendor_id' => $otherParty->id,
                'allocations' => [['opening_trade_item_id' => $item->id, 'allocated_amount' => '10']],
            ])));
            app(ReverseOpeningTradeItemAction::class)->execute($item, $this->owner, 'Correction', '2026-02-01');
            $this->rejectsWithoutEffects(fn () => $action->execute($this->company, $this->owner, $payload + [
                'allocations' => [['opening_trade_item_id' => $item->id, 'allocated_amount' => '10']],
            ]));
        }
    }
}
