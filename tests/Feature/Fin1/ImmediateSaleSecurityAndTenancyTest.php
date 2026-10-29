<?php

declare(strict_types=1);

namespace Tests\Feature\Fin1;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Application\Commands\ImmediateSaleCommands;
use App\Models\CustomerPayment;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

final class ImmediateSaleSecurityAndTenancyTest extends TestCase
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
            'customer_id' => $this->customer->id,
            'currency_code' => 'USD',
            'exchange_rate' => '3.5',
            'issue_date' => '2026-10-29',
            'document_locale' => 'en',
            'lines' => [['item_description' => 'Security test line', 'quantity' => '1', 'unit_price' => '100']],
        ]);
    }

    /** @return array<string, mixed> */
    private function choice(string $mode = 'full'): array
    {
        return [
            'mode' => $mode,
            'money_account_id' => (int) $this->cash->id,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'payment_date' => '2026-10-29',
            'amount' => '100',
            'exchange_rate' => '3.5',
        ];
    }

    public function test_foreign_actor_cannot_preview_the_active_company_invoice(): void
    {
        app(CompanyContext::class)->clear();
        $foreignUser = User::factory()->create();
        $foreignCompany = app(CreateCompanyAction::class)->execute($foreignUser, [
            'name_ar' => 'شركة أجنبية ثانية',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);

        // Preview with foreign actor
        $this->expectException(AuthorizationException::class);
        $command->preview($invoice, $foreignUser, $this->choice());
    }

    public function test_foreign_money_account_rejected_in_preview(): void
    {
        app(CompanyContext::class)->clear();
        $foreignUser = User::factory()->create();
        $foreignCompany = app(CreateCompanyAction::class)->execute($foreignUser, [
            'name_ar' => 'شركة أخرى للحسابات',
            'base_currency_code' => 'ILS',
        ]);
        $foreignCashId = (int) DB::table('money_accounts')->where('company_id', $foreignCompany->id)->value('id');
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);

        $this->expectException(ModelNotFoundException::class);
        $command->preview($invoice, $this->owner, array_replace($this->choice(), [
            'money_account_id' => $foreignCashId,
        ]));
    }

    public function test_tampered_or_malformed_token_is_strictly_rejected(): void
    {
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, $this->choice());

        // 1. Invalid ciphertext
        try {
            $command->execute($invoice, $this->owner, 'invalid-token-string', 'key-bad-token');
            $this->fail('Malformed ciphertext was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Invalid sale confirmation.', $e->getMessage());
        }

        // 2. Tampered token payload (e.g. changing actor_id)
        $payload = json_decode(Crypt::decryptString($preview['confirmation_token']), true);
        $payload['actor_id'] = 999999;
        $tamperedToken = Crypt::encryptString(json_encode($payload));

        try {
            $command->execute($invoice, $this->owner, $tamperedToken, 'key-tampered');
            $this->fail('Tampered actor payload was accepted.');
        } catch (AuthorizationException $e) {
            $this->assertSame('Sale confirmation does not match the company, actor and invoice.', $e->getMessage());
        }
    }

    public function test_permission_negatives_for_invoice_and_payment_legs(): void
    {
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);

        $customUser = User::factory()->create();
        $this->company->users()->attach($customUser->id, ['is_owner' => false, 'status' => 'active']);
        app(CompanyContext::class)->setCompany($this->company, $customUser);
        $this->actingAs($customUser);

        $role = Role::create(['name' => 'CustomRole_'.Str::random(6), 'guard_name' => 'web', 'company_id' => $this->company->id]);
        $customUser->assignRole($role);

        // 1. No permissions at all
        try {
            $command->preview($invoice, $customUser, $this->choice());
            $this->fail('Preview allowed without permissions.');
        } catch (AuthorizationException) {
            // Expected
        }

        // 2. Has invoice permissions (view, post, change_price), but lacking money.receipt.create
        $role->givePermissionTo('sales.invoice.view');
        $role->givePermissionTo('sales.invoice.post');
        $role->givePermissionTo('sales.invoice.change_price');

        // Unpaid preview works
        $unpaidPreview = $command->preview($invoice, $customUser, ['mode' => 'unpaid']);
        $this->assertSame('unpaid', $unpaidPreview['settlement']['mode']);

        // Paid preview rejected
        try {
            $command->preview($invoice, $customUser, $this->choice());
            $this->fail('Paid preview allowed without money.receipt.create.');
        } catch (AuthorizationException) {
            // Expected
        }

        // 3. Give money.receipt.create but lack money.receipt.allocate
        $role->givePermissionTo('money.receipt.create');
        try {
            $command->preview($invoice, $customUser, $this->choice());
            $this->fail('Paid preview allowed without money.receipt.allocate.');
        } catch (AuthorizationException) {
            // Expected
        }

        // 4. Give money.receipt.allocate -> Now paid preview succeeds
        $role->givePermissionTo('money.receipt.allocate');
        $paidPreview = $command->preview($invoice, $customUser, $this->choice());
        $this->assertSame('0.000000', $paidPreview['settlement']['outstanding_invoice_amount']);
    }

    public function test_deactivated_money_account_between_preview_and_execute_fails_closed(): void
    {
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, $this->choice());

        // Deactivate cash account before execute
        $this->cash->update(['is_active' => false]);

        try {
            $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'key-deactivated');
            $this->fail('Execute succeeded with deactivated payment account.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('Payment context changed; review the sale again.', $e->getMessage());
            $this->assertTrue($invoice->fresh()->isDraft());
            $this->assertDatabaseCount('customer_payments', 0);
        }
    }

    public function test_already_committed_replay_succeeds_even_after_token_expiry(): void
    {
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, $this->choice());

        // First execution succeeds
        $first = $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'fin1-replay-expiry');
        $this->assertTrue($first['invoice']->isPosted());
        $this->assertNotNull($first['payment']);

        // Token expires
        $this->travel(10)->minutes();

        // Replay with the same key returns the committed receipt rather than throwing expired 409
        $replay = $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'fin1-replay-expiry');
        $this->assertSame($first['invoice']->id, $replay['invoice']->id);
        $this->assertSame($first['payment']->id, $replay['payment']->id);
        $this->assertDatabaseCount('customer_payments', 1);
    }

    public function test_api_session_guard_enforced_when_request_has_attribute(): void
    {
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, $this->choice());

        // 1. Invalid API session proof throws AuthenticationException
        $requestInvalid = Request::create('/sales/invoices/'.$invoice->id, 'POST');
        $requestInvalid->attributes->set('phase11_api_session', ['invalid' => true]);
        app()->instance('request', $requestInvalid);

        try {
            $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'fin1-api-session-invalid');
            $this->fail('Invalid API session proof was accepted.');
        } catch (AuthenticationException $exception) {
            $this->assertSame('API session verification proof is missing or invalid.', $exception->getMessage());
        }

        // 2. Valid API session proof with active DB session row succeeds
        $sessionId = 'valid-session-'.Str::random(10);
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $this->owner->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'payload',
            'last_activity' => time(),
        ]);
        session(['phase11_session_started_at' => now()->timestamp]);

        $requestValid = Request::create('/sales/invoices/'.$invoice->id, 'POST');
        $requestValid->attributes->set('phase11_api_session', [
            'authentication_origin' => 'api_v1_session',
            'actor_id' => $this->owner->id,
            'session_id' => $sessionId,
        ]);
        app()->instance('request', $requestValid);

        $result = $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'fin1-api-session-valid');
        $this->assertTrue($result['invoice']->isPosted());
    }

    public function test_subscription_enforcement_expired_company_cannot_execute(): void
    {
        $invoice = $this->draft();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, $this->choice());

        config(['subscriptions.enforcement_enabled' => true]);

        // Create platform subscription in expired state
        DB::table('platform_subscriptions')->insert([
            'company_id' => $this->company->id,
            'plan' => 'Standard',
            'fee' => '100.000000',
            'currency' => 'ILS',
            'starts_on' => '2026-01-01',
            'renews_on' => '2026-02-01',
            'grace_days' => 0,
            'reminder_days' => json_encode([14, 7, 1]),
            'enforcement_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Execute must be denied due to subscription read-only state
        $this->expectException(AuthorizationException::class);
        $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'fin1-sub-expired');
    }
}
