<?php

declare(strict_types=1);

namespace Tests\Feature\Fin1;

use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\UpdateSalesInvoiceDraftAction;
use App\Application\Commands\ImmediateSaleCommands;
use App\Models\CustomerPayment;
use App\Models\MoneyAccount;
use App\Models\SalesInvoice;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

final class ImmediateSaleCommandsTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    private function draft(string $amount = '100'): SalesInvoice
    {
        return app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'currency_code' => 'USD', 'exchange_rate' => '3.5',
            'issue_date' => '2026-10-29', 'document_locale' => 'en',
            'lines' => [['item_description' => 'Synthetic service', 'quantity' => '1', 'unit_price' => $amount]],
        ]);
    }

    /** @return array<string, mixed> */
    private function choice(string $mode = 'full', string $amount = '100'): array
    {
        return ['mode' => $mode, 'money_account_id' => (int) $this->cash->id,
            'payment_method' => CustomerPayment::METHOD_CASH, 'payment_date' => '2026-10-29',
            'amount' => $amount, 'exchange_rate' => '3.5', ...($mode === 'partial' ? ['invoice_amount' => $amount] : [])];
    }

    public function test_full_sale_previews_without_writes_and_retries_converge_to_one_receipt(): void
    {
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, $this->choice());
        $this->assertTrue($preview['can_execute']);
        $this->assertSame('0.000000', $preview['settlement']['outstanding_invoice_amount']);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('customer_payments', 0);
        $first = $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'synthetic-full');
        $retry = $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'synthetic-full');
        $this->assertTrue($first['invoice']->isPosted());
        $this->assertSame($first['payment']->id, $retry['payment']->id);
        $this->assertDatabaseCount('customer_payments', 1);
        $this->assertDatabaseCount('customer_payment_allocations', 1);
        $this->assertDatabaseCount('posting_batches', 2);
        $this->assertSame('100.000000', (string) DB::table('customer_payment_allocations')->value('allocated_amount'));
        foreach (['accounting:reconcile', 'inventory:reconcile', 'sales:reconcile', 'money:reconcile', 'phase7:reconcile'] as $reconcile) {
            $this->assertSame(0, Artisan::call($reconcile, ['companyPublicId' => $this->company->public_id]), Artisan::output());
        }
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_partial_and_explicit_unpaid_choices_preserve_distinct_accounting_outcomes(): void
    {
        $command = app(ImmediateSaleCommands::class);
        $partial = $this->draft();
        $preview = $command->preview($partial, $this->owner, $this->choice('partial', '40'));
        $this->assertSame('60.000000', $preview['settlement']['outstanding_invoice_amount']);
        $result = $command->execute($partial, $this->owner, $preview['confirmation_token'], 'synthetic-partial');
        $this->assertSame('40.000000', (string) $result['payment']->amount);
        $unpaid = $this->draft();
        $preview = $command->preview($unpaid, $this->owner, ['mode' => 'unpaid']);
        $result = $command->execute($unpaid, $this->owner, $preview['confirmation_token'], 'synthetic-unpaid');
        $this->assertNull($result['payment']);
        $this->assertDatabaseCount('customer_payments', 1);
        $this->assertDatabaseCount('posting_batches', 3);
    }

    public function test_receipt_failure_after_invoice_post_rolls_back_every_effect_and_number(): void
    {
        $invoice = $this->draft();
        $preview = app(ImmediateSaleCommands::class)->preview($invoice, $this->owner, $this->choice());
        $beforeInvoice = $invoice->fresh()->toArray();
        $beforeNumbers = DB::table('document_sequences')->orderBy('id')->get()->toJson();
        $this->mock(PostCustomerPaymentAction::class, function ($mock) use ($invoice): void {
            $mock->shouldReceive('execute')->once()->andReturnUsing(function () use ($invoice): never {
                $this->assertTrue($invoice->fresh()->isPosted());
                throw new RuntimeException('Synthetic receipt failure');
            });
        });
        try {
            app(ImmediateSaleCommands::class)->execute($invoice, $this->owner, $preview['confirmation_token'], 'synthetic-rollback');
            $this->fail('A failed receipt committed part of the reviewed sale.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic receipt failure', $exception->getMessage());
        }
        $this->assertSame($beforeInvoice, $invoice->fresh()->toArray());
        $this->assertSame($beforeNumbers, DB::table('document_sequences')->orderBy('id')->get()->toJson());
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'customer_payments', 'customer_payment_allocations', 'command_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_reused_key_cannot_substitute_a_different_reviewed_payment(): void
    {
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);
        $one = $command->preview($invoice, $this->owner, $this->choice('partial', '40'));
        $two = $command->preview($invoice, $this->owner, $this->choice('partial', '50'));
        $command->execute($invoice, $this->owner, $one['confirmation_token'], 'synthetic-conflict');
        try {
            $command->execute($invoice, $this->owner, $two['confirmation_token'], 'synthetic-conflict');
            $this->fail('Changed payment intent replayed.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertDatabaseCount('customer_payments', 1);
        }
    }

    public function test_permission_revocation_denies_even_an_exact_successful_replay(): void
    {
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, $this->choice());
        $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'synthetic-revocation');
        Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail()->revokePermissionTo('money.receipt.allocate');
        $this->expectException(AuthorizationException::class);
        $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'synthetic-revocation');
    }

    public function test_zero_invoice_uses_unpaid_and_rejects_a_fake_zero_receipt(): void
    {
        $invoice = $this->draft('0');
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, ['mode' => 'unpaid']);
        $this->assertSame('0.000000', $preview['settlement']['outstanding_invoice_amount']);
        $this->expectException(InvalidArgumentException::class);
        $command->preview($invoice, $this->owner, $this->choice('full', '0'));
    }

    public function test_cross_currency_settlement_requires_both_amounts_and_reuses_canonical_fx_posting(): void
    {
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);
        $baseCash = MoneyAccount::where('company_id', $this->company->id)->where('currency_code', 'ILS')->firstOrFail();
        $choice = array_replace($this->choice(), ['money_account_id' => (int) $baseCash->id,
            'amount' => '350', 'exchange_rate' => '1', 'payment_currency_amount' => '350']);
        $preview = $command->preview($invoice, $this->owner, $choice);
        $this->assertSame('100.000000', $preview['settlement']['invoice_amount']);
        $this->assertSame('350.000000', $preview['settlement']['payment_currency_amount']);
        $result = $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'synthetic-fx');
        $this->assertSame('ILS', $result['payment']->currency_code);
        $this->assertDatabaseHas('customer_payment_allocations', ['sales_invoice_id' => $invoice->id,
            'allocated_amount' => '100.000000', 'payment_currency_amount' => '350.000000']);
    }

    public function test_bank_method_uses_the_existing_bank_transfer_contract(): void
    {
        $bank = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, [
            'account_type' => 'bank', 'name_ar' => 'Synthetic bank', 'currency_code' => 'USD',
        ]);
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, array_replace($this->choice('partial', '25'), [
            'money_account_id' => (int) $bank->id, 'payment_method' => CustomerPayment::METHOD_BANK,
        ]));
        $result = $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'synthetic-bank');
        $this->assertSame($bank->id, $result['payment']->money_account_id);
        $this->assertSame(CustomerPayment::METHOD_BANK, $result['payment']->payment_method);
    }

    public function test_changed_draft_requires_repreview_without_any_partial_payment(): void
    {
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, $this->choice());
        app(UpdateSalesInvoiceDraftAction::class)->execute($invoice, $this->owner, [
            'customer_id' => $this->customer->id, 'currency_code' => 'USD', 'exchange_rate' => '3.5',
            'issue_date' => '2026-10-29', 'document_locale' => 'en',
            'lines' => [['item_description' => 'Changed service', 'quantity' => '1', 'unit_price' => '120']],
        ]);
        try {
            $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'synthetic-stale');
            $this->fail('Changed draft posted without reviewing its new totals.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertTrue($invoice->fresh()->isDraft());
            $this->assertDatabaseCount('customer_payments', 0);
            $this->assertDatabaseCount('posting_batches', 0);
        }
    }

    public function test_expired_unused_confirmation_cannot_post_either_leg(): void
    {
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, $this->choice());
        $this->travel(6)->minutes();
        try {
            $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'synthetic-expired');
            $this->fail('Expired sale confirmation posted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertTrue($invoice->fresh()->isDraft());
            $this->assertDatabaseCount('posting_batches', 0);
        }
    }

    public function test_explicit_excess_receipt_is_previewed_as_unallocated_not_silently_lost(): void
    {
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, $this->choice('full', '120'));
        $this->assertSame('20.000000', $preview['settlement']['unallocated_payment_amount']);
        $result = $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'synthetic-excess');
        $this->assertSame('120.000000', (string) $result['payment']->amount);
        $this->assertSame('100.000000', (string) DB::table('customer_payment_allocations')->value('allocated_amount'));
    }

    public function test_check_cannot_bypass_its_canonical_receive_event_in_this_foundation(): void
    {
        $invoice = $this->draft();
        $this->expectException(InvalidArgumentException::class);
        app(ImmediateSaleCommands::class)->preview($invoice, $this->owner, array_replace($this->choice(), ['payment_method' => 'check']));
    }
}
