<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Application\Platform\ManageSubscription;
use App\Application\Platform\PlatformAccess;
use App\Models\Company;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class PlatformSubscriptionRecordsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private PlatformOperatorGrant $grant;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Route::middleware('web')->post('/__test/subscriptions/{company}/{operation}', function (string $company, string $operation) {
            return app(ManageSubscription::class)->execute($company, $operation, request()->all());
        });
        $this->company = Company::factory()->create();
        $user = User::factory()->create();
        $this->grant = new PlatformOperatorGrant;
        $this->grant->forceFill(['user_id' => $user->id, 'active' => true, 'permissions' => PlatformAccess::PERMISSIONS, 'revision' => 1])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->postJson('/confirm-password', ['password' => 'password'])->assertStatus(201);
    }

    private function endpoint(string $operation): string
    {
        return '/__test/subscriptions/'.$this->company->public_id.'/'.$operation;
    }

    private function configPayload(): array
    {
        return [
            'request_key' => (string) Str::uuid(), 'expected_revision' => 0,
            'plan' => 'Managed business', 'fee' => '100', 'currency' => 'ILS',
            'starts_on' => '2026-01-01', 'renews_on' => '2026-02-01',
            'grace_days' => 7, 'reminder_days' => [14, 7, 1],
            'enforcement_enabled' => false, 'notes' => 'Synthetic commercial terms',
        ];
    }

    private function configure(): int
    {
        return (int) $this->postJson($this->endpoint('configure'), $this->configPayload())->assertOk()->json('charge_id');
    }

    private function paymentPayload(int $chargeId, int $revision = 1, string $amount = '40'): array
    {
        return ['request_key' => (string) Str::uuid(), 'expected_revision' => $revision, 'charge_id' => $chargeId,
            'amount' => $amount, 'currency' => 'ILS', 'received_on' => '2026-01-10', 'reference' => 'Synthetic receipt'];
    }

    public function test_configuration_and_replay_never_post_to_a_tenant_ledger(): void
    {
        $input = $this->configPayload();
        $first = $this->postJson($this->endpoint('configure'), $input)->assertOk()->json();
        $this->postJson($this->endpoint('configure'), $input)->assertOk()->assertExactJson($first);
        $this->assertDatabaseCount('platform_subscriptions', 1);
        $this->assertDatabaseCount('platform_subscription_charges', 1);
        $this->assertDatabaseCount('platform_billing_requests', 1);
        $this->assertDatabaseHas('platform_subscriptions', ['fee' => '100.000000', 'enforcement_enabled' => false, 'revision' => 1]);
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'customer_payments', 'vendor_payments', 'checks'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNothingSent();
    }

    public function test_changed_payload_under_same_request_is_rejected(): void
    {
        $data = $this->configPayload();
        $this->postJson($this->endpoint('configure'), $data)->assertOk();
        $this->postJson($this->endpoint('configure'), array_replace($data, ['fee' => '101']))->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->assertDatabaseCount('platform_subscription_charges', 1);
    }

    public function test_partial_and_full_receipts_preserve_renewal_and_previous_snapshots(): void
    {
        $charge = $this->configure();
        $payment = $this->paymentPayload($charge);
        $first = $this->postJson($this->endpoint('payment'), $payment)->assertOk()->json();
        $this->postJson($this->endpoint('payment'), $payment)->assertOk()->assertExactJson($first);
        $this->postJson($this->endpoint('payment'), $this->paymentPayload($charge, 2, '60'))->assertOk();
        $this->assertDatabaseCount('platform_subscription_payments', 2);
        $this->assertSame('100.000000', DB::table('platform_subscription_payments')->sum('amount'));
        $this->assertDatabaseHas('platform_subscriptions', ['renews_on' => '2026-02-01', 'revision' => 3]);
        $this->assertDatabaseHas('platform_subscription_charges', ['id' => $charge, 'amount' => '100.000000', 'ends_before' => '2026-02-01']);
    }

    public function test_overpayment_currency_mismatch_and_foreign_charge_fail_without_side_effects(): void
    {
        $charge = $this->configure();
        $data = $this->paymentPayload($charge);
        $this->postJson($this->endpoint('payment'), array_replace($data, ['amount' => '100.000001']))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson($this->endpoint('payment'), array_replace($data, ['currency' => 'USD']))->assertUnprocessable()->assertJsonValidationErrors('charge_id');
        $foreign = Company::factory()->create();
        DB::table('platform_subscription_charges')->where('id', $charge)->update(['company_id' => $foreign->id]);
        $this->postJson($this->endpoint('payment'), $data)->assertUnprocessable()->assertJsonValidationErrors('charge_id');
        $this->assertDatabaseCount('platform_subscription_payments', 0);
        $this->assertDatabaseCount('platform_billing_requests', 1);
    }

    public function test_zero_negative_float_and_excess_precision_are_rejected(): void
    {
        $charge = $this->configure();
        foreach (['0', '-1', '0.0000001', 1.5] as $amount) {
            $this->postJson($this->endpoint('payment'), array_replace($this->paymentPayload($charge), ['amount' => $amount]))->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        $this->assertDatabaseCount('platform_subscription_payments', 0);
    }

    public function test_renewal_requires_fresh_revision_and_preserves_prior_charge(): void
    {
        $chargeId = $this->configure();
        $prior = (array) DB::table('platform_subscription_charges')->where('id', $chargeId)->first();
        $renew = ['request_key' => (string) Str::uuid(), 'expected_revision' => 1, 'renews_on' => '2026-03-01'];
        $this->postJson($this->endpoint('renew'), array_replace($renew, ['expected_revision' => 0]))->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->postJson($this->endpoint('renew'), $renew)->assertOk();
        $this->postJson($this->endpoint('renew'), $renew)->assertOk();
        $this->assertSame($prior, (array) DB::table('platform_subscription_charges')->where('id', $chargeId)->first());
        $this->assertDatabaseCount('platform_subscription_charges', 2);
        $this->assertDatabaseHas('platform_subscriptions', ['revision' => 2, 'renews_on' => '2026-03-01']);
    }

    public function test_revocation_missing_permission_and_expired_password_fail_closed(): void
    {
        $this->grant->forceFill(['permissions' => ['company.read']])->save();
        $this->postJson($this->endpoint('configure'), $this->configPayload())->assertForbidden();
        $this->grant->forceFill(['permissions' => PlatformAccess::PERMISSIONS])->save();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->postJson($this->endpoint('configure'), $this->configPayload())->assertForbidden();
        $this->assertDatabaseCount('platform_subscriptions', 0);
    }

    public function test_populated_commercial_schema_rollback_is_refused_before_any_drop(): void
    {
        $this->configure();
        $migration = require database_path('migrations/2026_10_29_100000_create_platform_subscription_records.php');
        try {
            $migration->down();
            $this->fail('Populated history was dropped.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Retain populated commercial history', $e->getMessage());
        }
        $this->assertDatabaseCount('platform_subscriptions', 1);
        $this->assertDatabaseCount('platform_subscription_charges', 1);
        $this->assertDatabaseCount('platform_billing_requests', 1);
    }
}
