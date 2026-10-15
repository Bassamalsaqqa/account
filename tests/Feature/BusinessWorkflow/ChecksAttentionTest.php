<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessWorkflow;

use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Money\TransitionCheckAction;
use App\Livewire\Pages\Money\CheckIndex;
use App\Models\Check;
use App\Models\CheckEvent;
use Carbon\Carbon;
use Livewire\Livewire;
use Tests\Feature\Phase7\Phase7TestCase;

class ChecksAttentionTest extends Phase7TestCase
{
    public function test_invalid_date_range_shows_recoverable_errors_without_breaking_the_page(): void
    {
        Livewire::test(CheckIndex::class)->set('dateFrom', '2026-10-15')->set('dateTo', '2026-10-14')
            ->assertStatus(200)->assertHasErrors(['dateTo'])->set('dateTo', '2026-10-16')->assertHasNoErrors();
    }

    public function test_attention_filters_and_company_local_today_partitioning(): void
    {
        $this->activate($this->owner);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', $this->company->timezone));

        try {
            $overdue = app(ReceiveCheckAction::class)->execute($this->company, $this->owner, array_replace($this->checkIntent(), [
                'check_number' => 'CHK-OVERDUE-1',
                'date' => '2026-10-01',
                'due_date' => '2026-10-09',
            ]));

            $today = app(ReceiveCheckAction::class)->execute($this->company, $this->owner, array_replace($this->checkIntent(), [
                'check_number' => 'CHK-TODAY-1',
                'date' => '2026-10-01',
                'due_date' => '2026-10-10',
            ]));

            $next7 = app(ReceiveCheckAction::class)->execute($this->company, $this->owner, array_replace($this->checkIntent(), [
                'check_number' => 'CHK-NEXT7-1',
                'date' => '2026-10-01',
                'due_date' => '2026-10-15',
            ]));

            $future = app(ReceiveCheckAction::class)->execute($this->company, $this->owner, array_replace($this->checkIntent(), [
                'check_number' => 'CHK-FUTURE-1',
                'date' => '2026-10-01',
                'due_date' => '2026-10-25',
            ]));

            // 1. Attention: Today
            Livewire::test(CheckIndex::class)
                ->call('setAttention', 'today')
                ->assertSee('CHK-TODAY-1')
                ->assertDontSee('CHK-OVERDUE-1')
                ->assertDontSee('CHK-NEXT7-1')
                ->assertDontSee('CHK-FUTURE-1');

            // 2. Attention: Overdue
            Livewire::test(CheckIndex::class)
                ->call('setAttention', 'overdue')
                ->assertSee('CHK-OVERDUE-1')
                ->assertDontSee('CHK-TODAY-1')
                ->assertDontSee('CHK-NEXT7-1')
                ->assertDontSee('CHK-FUTURE-1');

            // 3. Attention: Next 7 days
            Livewire::test(CheckIndex::class)
                ->call('setAttention', 'next_7_days')
                ->assertSee('CHK-TODAY-1')
                ->assertSee('CHK-NEXT7-1')
                ->assertDontSee('CHK-OVERDUE-1')
                ->assertDontSee('CHK-FUTURE-1');

            // 4. Attention: Open
            Livewire::test(CheckIndex::class)
                ->call('setAttention', 'open')
                ->assertSee('CHK-TODAY-1')
                ->assertSee('CHK-NEXT7-1')
                ->assertSee('CHK-OVERDUE-1')
                ->assertSee('CHK-FUTURE-1');
        } finally {
            $this->travelBack();
        }
    }

    public function test_terminal_checks_strictly_excluded_from_all_attention_filters(): void
    {
        $this->activate($this->owner);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', $this->company->timezone));

        try {
            // Open check due today
            $openToday = app(ReceiveCheckAction::class)->execute($this->company, $this->owner, array_replace($this->checkIntent(), [
                'check_number' => 'CHK-OPEN-TODAY',
                'due_date' => '2026-10-10',
            ]));

            // Cancelled check due today
            $cancelledToday = app(ReceiveCheckAction::class)->execute($this->company, $this->owner, array_replace($this->checkIntent(), [
                'check_number' => 'CHK-CANCELLED-TODAY',
                'due_date' => '2026-10-10',
            ]));
            app(TransitionCheckAction::class)->execute($cancelledToday, $this->owner, [
                'event_type' => 'cancel',
                'event_date' => '2026-10-10',
                'idempotency_key' => 'cancel-evt-today',
            ]);

            // Cleared check due today
            $clearedToday = app(ReceiveCheckAction::class)->execute($this->company, $this->owner, array_replace($this->checkIntent(), [
                'check_number' => 'CHK-CLEARED-TODAY',
                'due_date' => '2026-10-10',
            ]));
            app(TransitionCheckAction::class)->execute($clearedToday, $this->owner, [
                'event_type' => 'deposit',
                'event_date' => '2026-10-10',
                'money_account_id' => $this->usdBankAccount->id,
                'idempotency_key' => 'dep-evt-today',
            ]);
            app(TransitionCheckAction::class)->execute($clearedToday->fresh(), $this->owner, [
                'event_type' => 'clear',
                'event_date' => '2026-10-10',
                'exchange_rate' => '3.5',
                'idempotency_key' => 'clr-evt-today',
            ]);

            // Returned check overdue
            $returnedOverdue = app(ReceiveCheckAction::class)->execute($this->company, $this->owner, array_replace($this->checkIntent(), [
                'check_number' => 'CHK-RETURNED-OVERDUE',
                'due_date' => '2026-10-05',
            ]));
            app(TransitionCheckAction::class)->execute($returnedOverdue, $this->owner, [
                'event_type' => 'deposit',
                'event_date' => '2026-10-06',
                'money_account_id' => $this->usdBankAccount->id,
                'idempotency_key' => 'dep-evt-ret',
            ]);
            app(TransitionCheckAction::class)->execute($returnedOverdue->fresh(), $this->owner, [
                'event_type' => 'return',
                'event_date' => '2026-10-07',
                'idempotency_key' => 'ret-evt-overdue',
            ]);

            // Assert attention=today excludes cleared and cancelled
            Livewire::test(CheckIndex::class)
                ->call('setAttention', 'today')
                ->assertSee('CHK-OPEN-TODAY')
                ->assertDontSee('CHK-CANCELLED-TODAY')
                ->assertDontSee('CHK-CLEARED-TODAY');

            // Assert attention=overdue excludes returned
            Livewire::test(CheckIndex::class)
                ->call('setAttention', 'overdue')
                ->assertDontSee('CHK-RETURNED-OVERDUE');

            // Assert attention=open excludes all terminal
            Livewire::test(CheckIndex::class)
                ->call('setAttention', 'open')
                ->assertSee('CHK-OPEN-TODAY')
                ->assertDontSee('CHK-CANCELLED-TODAY')
                ->assertDontSee('CHK-CLEARED-TODAY')
                ->assertDontSee('CHK-RETURNED-OVERDUE');
        } finally {
            $this->travelBack();
        }
    }

    public function test_clear_reset_restores_all_filters(): void
    {
        $this->activate($this->owner);

        $component = Livewire::test(CheckIndex::class)
            ->call('setAttention', 'today')
            ->set('direction', 'incoming')
            ->set('status', 'received')
            ->set('search', 'TEST-CHK')
            ->set('dateFrom', '2026-10-01')
            ->set('dateTo', '2026-10-31');

        $this->assertSame('today', $component->get('attention'));
        $this->assertSame('incoming', $component->get('direction'));
        $this->assertSame('received', $component->get('status'));
        $this->assertSame('TEST-CHK', $component->get('search'));
        $this->assertSame('2026-10-01', $component->get('dateFrom'));
        $this->assertSame('2026-10-31', $component->get('dateTo'));

        $component->call('resetFilters');

        $this->assertSame('', $component->get('attention'));
        $this->assertSame('', $component->get('direction'));
        $this->assertSame('', $component->get('status'));
        $this->assertSame('', $component->get('search'));
        $this->assertSame('', $component->get('dateFrom'));
        $this->assertSame('', $component->get('dateTo'));
    }

    public function test_source_sensitive_checks_visibility_with_attention_filters(): void
    {
        $this->activate($this->owner);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', $this->company->timezone));

        try {
            // Expense check (allowed for money.expense.view)
            app(IssueCheckAction::class)->execute($this->company, $this->owner, [
                'source_type' => 'expense',
                'category_id' => $this->operatingCategory->id,
                'amount' => '100.000000',
                'currency_code' => 'USD',
                'exchange_rate' => '3.5000000000',
                'date' => '2026-10-10',
                'due_date' => '2026-10-10',
                'check_number' => 'CHK-EXP-TODAY-1',
                'bank_name' => 'Bank of Palestine',
                'money_account_id' => $this->usdBankAccount->id,
                'classification' => 'operating',
                'description' => 'Office cleaning',
                'idempotency_key' => 'chk-exp-att-01',
            ]);

            // Vendor check (requires purchasing.cost.view)
            app(IssueCheckAction::class)->execute($this->company, $this->owner, [
                'source_type' => 'vendor_payment',
                'vendor_id' => $this->vendor->id,
                'amount' => '200.000000',
                'currency_code' => 'USD',
                'exchange_rate' => '3.5000000000',
                'date' => '2026-10-10',
                'due_date' => '2026-10-10',
                'check_number' => 'CHK-VND-TODAY-2',
                'bank_name' => 'Bank of Palestine',
                'money_account_id' => $this->usdBankAccount->id,
                'idempotency_key' => 'chk-vnd-att-02',
                'allocations' => [],
            ]);

            // Operator with check and expense view (NO vendor/cost view)
            $expenseClerk = $this->customActor(['money.check.view', 'money.expense.view']);
            $this->activate($expenseClerk);

            $page = Livewire::test(CheckIndex::class)
                ->call('setAttention', 'today')
                ->assertSee('CHK-EXP-TODAY-1')
                ->assertDontSee('CHK-VND-TODAY-2');

            $counts = $page->viewData('attentionCounts');
            $this->assertSame(1, $counts['today']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_composition_of_attention_with_direction_and_date_range_filters(): void
    {
        $this->activate($this->owner);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', $this->company->timezone));

        try {
            app(ReceiveCheckAction::class)->execute($this->company, $this->owner, array_replace($this->checkIntent(), [
                'check_number' => 'CHK-INC-TODAY',
                'due_date' => '2026-10-10',
            ]));

            app(IssueCheckAction::class)->execute($this->company, $this->owner, array_replace($this->checkIntent('outgoing'), [
                'check_number' => 'CHK-OUT-TODAY',
                'due_date' => '2026-10-10',
            ]));

            // Filter attention=today AND direction=incoming
            Livewire::test(CheckIndex::class)
                ->call('setAttention', 'today')
                ->set('direction', 'incoming')
                ->assertSee('CHK-INC-TODAY')
                ->assertDontSee('CHK-OUT-TODAY');

            // Filter attention=today AND direction=outgoing
            Livewire::test(CheckIndex::class)
                ->call('setAttention', 'today')
                ->set('direction', 'outgoing')
                ->assertSee('CHK-OUT-TODAY')
                ->assertDontSee('CHK-INC-TODAY');
        } finally {
            $this->travelBack();
        }
    }

    public function test_no_writes_and_no_endorsement_state_changes(): void
    {
        $this->activate($this->owner);

        $check = $this->check();
        $checkCountBefore = Check::count();
        $eventCountBefore = CheckEvent::count();
        $statusBefore = $check->status;

        $component = Livewire::test(CheckIndex::class);
        $component->call('setAttention', 'open');
        $component->call('setAttention', 'today');
        $component->call('setAttention', 'overdue');
        $component->call('setAttention', 'next_7_days');
        $component->call('resetFilters');

        $this->assertSame($checkCountBefore, Check::count());
        $this->assertSame($eventCountBefore, CheckEvent::count());
        $this->assertSame($statusBefore, $check->fresh()->status);
    }
}
