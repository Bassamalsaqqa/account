<?php

declare(strict_types=1);

namespace Tests\Feature\Fin1;

use App\Actions\Money\TransitionCheckAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Application\Commands\ImmediateSaleCommands;
use App\Models\Check;
use App\Models\SalesInvoice;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

final class ImmediateSaleCheckTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    private function draft(): SalesInvoice
    {
        return app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'currency_code' => 'USD', 'exchange_rate' => '3.5',
            'issue_date' => '2026-10-29', 'document_locale' => 'en',
            'lines' => [['item_description' => 'Check settlement service', 'quantity' => '1', 'unit_price' => '100']],
        ]);
    }

    private function choice(): array
    {
        return ['mode' => 'full', 'payment_method' => 'check', 'payment_date' => '2026-10-29',
            'currency_code' => 'USD', 'amount' => '100', 'exchange_rate' => '3.5',
            'check_number' => 'SYNTHETIC-100', 'bank_name' => 'Synthetic Bank', 'drawer' => 'Synthetic Customer',
            'due_date' => '2026-11-01'];
    }

    private function assertReconciliations(): void
    {
        foreach (['accounting:reconcile', 'inventory:reconcile', 'sales:reconcile', 'money:reconcile', 'phase7:reconcile'] as $command) {
            $this->assertSame(0, Artisan::call($command, ['companyPublicId' => $this->company->public_id]), Artisan::output());
        }
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_check_settlement_uses_canonical_instrument_control_and_durable_replay(): void
    {
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, $this->choice());
        $this->assertNull($preview['settlement']['money_account_id']);
        $this->assertDatabaseCount('checks', 0);
        $this->assertDatabaseCount('posting_batches', 0);
        $first = $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'check-sale');
        $replay = $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'check-sale');
        $this->assertTrue($first['invoice']->isPosted());
        $this->assertSame($first['payment']->id, $replay['payment']->id);
        $this->assertNull($first['payment']->money_account_id);
        $this->assertSame('check', $first['payment']->payment_method);
        $check = Check::findOrFail($first['payment']->check_id);
        $this->assertSame('received', $check->status);
        $this->assertSame('SYNTHETIC-100', $check->check_number);
        $this->assertDatabaseCount('checks', 1);
        $this->assertDatabaseCount('check_events', 1);
        $this->assertDatabaseCount('customer_payments', 1);
        $ledger = DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', 'checks_in_hand')->value('id');
        $this->assertDatabaseHas('posting_lines', ['posting_batch_id' => $first['payment']->posting_batch_id, 'ledger_account_id' => $ledger]);
        $this->assertReconciliations();
    }

    public function test_check_and_invoice_roll_back_if_canonical_underlying_receipt_fails(): void
    {
        $invoice = $this->draft();
        $preview = app(ImmediateSaleCommands::class)->preview($invoice, $this->owner, $this->choice());
        $sequences = DB::table('document_sequences')->orderBy('id')->get()->toJson();
        $this->mock(PostCustomerPaymentAction::class, function ($mock) use ($invoice): void {
            $mock->shouldReceive('execute')->once()->andReturnUsing(function () use ($invoice): never {
                $this->assertTrue($invoice->fresh()->isPosted());
                $this->assertDatabaseCount('checks', 1);
                throw new RuntimeException('Synthetic canonical check receipt failure');
            });
        });
        try {
            app(ImmediateSaleCommands::class)->execute($invoice, $this->owner, $preview['confirmation_token'], 'failed-check-sale');
            $this->fail('Incomplete check sale committed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic canonical check receipt failure', $exception->getMessage());
        }
        $this->assertTrue($invoice->fresh()->isDraft());
        foreach (['checks', 'check_events', 'customer_payments', 'customer_payment_allocations', 'posting_batches', 'command_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($sequences, DB::table('document_sequences')->orderBy('id')->get()->toJson());
        $this->assertReconciliations();
    }

    public function test_partial_cross_currency_check_allocation_is_explicit_and_reconciles(): void
    {
        $invoice = $this->draft();
        $choice = array_replace($this->choice(), ['mode' => 'partial', 'currency_code' => 'ILS',
            'amount' => '140', 'exchange_rate' => '1', 'invoice_amount' => '40', 'payment_currency_amount' => '140']);
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, $choice);
        $this->assertSame('60.000000', $preview['settlement']['outstanding_invoice_amount']);
        $result = $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'partial-fx-check');
        $this->assertSame('ILS', $result['payment']->currency_code);
        $this->assertDatabaseHas('customer_payment_allocations', ['customer_payment_id' => $result['payment']->id,
            'allocated_amount' => '40.000000', 'payment_currency_amount' => '140.000000']);
        $this->assertReconciliations();
    }

    public function test_even_received_check_requires_canonical_transition_for_reversal(): void
    {
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, $this->choice());
        $result = $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'cancel-check');
        try {
            app(ReverseCustomerPaymentAction::class)->execute($result['payment'], $this->owner, 'Synthetic cancellation', '2026-10-29');
            $this->fail('Direct check payment reversal bypassed the instrument lifecycle.');
        } catch (LogicException) {
            $this->assertFalse($result['payment']->fresh()->is_reversed);
        }
        app(TransitionCheckAction::class)->execute(Check::findOrFail($result['payment']->check_id), $this->owner, [
            'event_type' => 'cancel', 'event_date' => '2026-10-29', 'notes' => 'Synthetic cancellation', 'idempotency_key' => 'canonical-cancel',
        ]);
        $this->assertTrue($result['payment']->fresh()->is_reversed);
        $this->assertReconciliations();
    }

    public function test_check_metadata_and_money_account_cannot_be_mixed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ImmediateSaleCommands::class)->preview($this->draft(), $this->owner, [
            ...$this->choice(), 'money_account_id' => (int) $this->cash->id,
        ]);
    }
}
