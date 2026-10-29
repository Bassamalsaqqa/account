<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Application\Platform\PlatformAccess;
use App\Http\Middleware\RequirePlatformBillingPassword;
use App\Models\Company;
use App\Models\PlatformAuditEvent;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class PlatformSubscriptionHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $operatorUser;

    private PlatformOperatorGrant $operatorGrant;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->company = Company::factory()->create([
            'base_currency_code' => 'ILS',
            'timezone' => 'Asia/Hebron',
        ]);

        $this->operatorUser = User::factory()->create();
        $this->operatorGrant = new PlatformOperatorGrant;
        $this->operatorGrant->forceFill([
            'user_id' => $this->operatorUser->id,
            'active' => true,
            'permissions' => PlatformAccess::PERMISSIONS,
            'revision' => 1,
        ])->save();

        $this->post('/login', ['email' => $this->operatorUser->email, 'password' => 'password'])->assertRedirect();
        $this->postJson('/confirm-password', ['password' => 'password'])->assertStatus(201);
    }

    private function showEndpoint(): string
    {
        return '/platform/companies/'.$this->company->public_id.'/subscription';
    }

    private function actionEndpoint(string $operation): string
    {
        return '/platform/companies/'.$this->company->public_id.'/subscription/'.$operation;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function configPayload(array $overrides = []): array
    {
        return array_merge([
            'request_key' => (string) Str::uuid(),
            'expected_revision' => 0,
            'plan' => 'Managed business',
            'fee' => '150.000000',
            'currency' => 'ILS',
            'starts_on' => '2026-01-01',
            'renews_on' => '2026-02-01',
            'grace_days' => 7,
            'reminder_days' => '14, 7, 1',
            'enforcement_enabled' => 0,
            'notes' => 'Synthetic commercial terms for testing',
        ], $overrides);
    }

    public function test_authorized_operator_can_view_subscription_page_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $response = $this->withSession(['locale' => $locale])->get($this->showEndpoint());
            $response->assertOk();
            $response->assertSee($this->company->public_id);
            $response->assertSee(__('subscriptions.title', [], $locale));
        }
    }

    public function test_operator_without_subscription_manage_permission_is_forbidden(): void
    {
        $this->operatorGrant->forceFill(['permissions' => ['company.read']])->save();

        $this->get($this->showEndpoint())->assertForbidden();
        $this->post($this->actionEndpoint('configure'), $this->configPayload())->assertForbidden();
    }

    public function test_tenant_owner_cannot_access_platform_subscription(): void
    {
        $tenantOwner = User::factory()->create();
        DB::table('company_user')->insert([
            'company_id' => $this->company->id,
            'user_id' => $tenantOwner->id,
            'status' => 'active',
            'is_owner' => true,
        ]);

        $this->actingAs($tenantOwner);
        $this->get($this->showEndpoint())->assertForbidden();
    }

    public function test_expired_session_proof_redirects_to_password_confirm(): void
    {
        $this->travel(61)->minutes();

        $this->get($this->showEndpoint())->assertRedirect(route('password.confirm'));

        $this->travelBack();
    }

    public function test_no_hidden_fee_serialization_for_unauthorized_grants(): void
    {
        // First configure subscription with a distinct unique fee.
        $this->post($this->actionEndpoint('configure'), $this->configPayload(['fee' => '987.654321']))->assertRedirect();

        // Grant only company.read to an operator, confirming password for the new grant revision.
        $this->operatorGrant->forceFill(['permissions' => ['company.read']])->save();
        $this->postJson('/confirm-password', ['password' => 'password'])->assertStatus(201);

        // Check directory index: fee must never be serialized.
        $indexResponse = $this->get('/platform');
        $indexResponse->assertOk();
        $indexResponse->assertDontSee('987.654321');

        // Check company show: fee and manage link must never appear.
        $showResponse = $this->get('/platform/companies/'.$this->company->public_id);
        $showResponse->assertOk();
        $showResponse->assertDontSee('987.654321');
        $showResponse->assertDontSee($this->showEndpoint());
    }

    public function test_configure_saves_terms_creates_first_charge_and_redirects(): void
    {
        $input = $this->configPayload();
        $response = $this->post($this->actionEndpoint('configure'), $input);

        $response->assertRedirect($this->showEndpoint());
        $response->assertSessionHas('platform_success');

        $this->assertDatabaseHas('platform_subscriptions', [
            'company_id' => $this->company->id,
            'plan' => 'Managed business',
            'fee' => '150.000000',
            'currency' => 'ILS',
            'revision' => 1,
            'enforcement_enabled' => false,
        ]);

        $this->assertDatabaseHas('platform_subscription_charges', [
            'company_id' => $this->company->id,
            'subscription_revision' => 1,
            'amount' => '150.000000',
            'currency' => 'ILS',
            'starts_on' => '2026-01-01',
            'ends_before' => '2026-02-01',
        ]);

        $this->assertDatabaseCount('platform_billing_requests', 1);
        $this->assertDatabaseHas('platform_audit_events', [
            'event_key' => 'subscription.configured',
            'company_id' => $this->company->id,
            'actor_user_id' => $this->operatorUser->id,
        ]);
    }

    public function test_configure_retry_with_same_request_key_is_idempotent(): void
    {
        $input = $this->configPayload();
        $first = $this->post($this->actionEndpoint('configure'), $input);
        $first->assertRedirect($this->showEndpoint());

        $second = $this->post($this->actionEndpoint('configure'), $input);
        $second->assertRedirect($this->showEndpoint());

        $this->assertDatabaseCount('platform_subscriptions', 1);
        $this->assertDatabaseCount('platform_subscription_charges', 1);
        $this->assertDatabaseCount('platform_billing_requests', 1);
    }

    public function test_configure_with_changed_payload_under_same_key_returns_validation_error(): void
    {
        $input = $this->configPayload();
        $this->post($this->actionEndpoint('configure'), $input)->assertRedirect($this->showEndpoint());

        $changed = array_merge($input, ['fee' => '200.000000']);
        $response = $this->post($this->actionEndpoint('configure'), $changed);

        $response->assertSessionHasErrors('request_key');
        $this->assertDatabaseHas('platform_subscriptions', ['fee' => '150.000000']);
    }

    public function test_configure_with_stale_expected_revision_fails(): void
    {
        $input = $this->configPayload(['expected_revision' => 5]);
        $response = $this->post($this->actionEndpoint('configure'), $input);

        $response->assertSessionHasErrors('expected_revision');
        $this->assertDatabaseCount('platform_subscriptions', 0);
    }

    public function test_configure_validation_rejects_malformed_inputs(): void
    {
        // Blank plan
        $this->post($this->actionEndpoint('configure'), $this->configPayload(['plan' => '']))
            ->assertSessionHasErrors('plan');

        // Malformed fee
        foreach (['abc', '-10', '10.1234567', ''] as $badFee) {
            $this->post($this->actionEndpoint('configure'), $this->configPayload(['fee' => $badFee]))
                ->assertSessionHasErrors('fee');
        }

        // Unsupported currency
        $this->post($this->actionEndpoint('configure'), $this->configPayload(['currency' => 'EUR']))
            ->assertSessionHasErrors('currency');

        // Renews on before starts on
        $this->post($this->actionEndpoint('configure'), $this->configPayload(['starts_on' => '2026-05-01', 'renews_on' => '2026-04-01']))
            ->assertSessionHasErrors('renews_on');

        // Grace days out of bounds
        $this->post($this->actionEndpoint('configure'), $this->configPayload(['grace_days' => 500]))
            ->assertSessionHasErrors('grace_days');

        $this->assertDatabaseCount('platform_subscriptions', 0);
    }

    public function test_malformed_reminder_tokens_are_rejected_instead_of_coerced(): void
    {
        foreach (['14oops,7,1', '14,0,1', '14,-7,1', '14,7.5,1', '14,,1'] as $days) {
            $this->post($this->actionEndpoint('configure'), $this->configPayload(['reminder_days' => $days]))
                ->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('platform_subscriptions', 0);
    }

    public function test_validation_recovers_only_the_submitted_form_with_accessible_errors(): void
    {
        $this->post($this->actionEndpoint('configure'), $this->configPayload(['fee' => 'not-an-amount']))->assertSessionHasErrors('fee');
        $this->get($this->showEndpoint())->assertOk()->assertSee('value="not-an-amount"', false)
            ->assertSee('aria-invalid="true" aria-describedby="term_fee-error"', false)
            ->assertSee('id="term_fee-error"', false);
    }

    public function test_expired_password_confirmation_preserves_a_bound_draft_without_replaying_a_write(): void
    {
        $input = $this->configPayload(['plan' => 'Unsaved synthetic plan', 'password' => 'must-never-be-retained']);
        $this->travel(16)->minutes();
        $this->post($this->actionEndpoint('configure'), $input)->assertRedirect(route('password.confirm'));
        $pending = session(RequirePlatformBillingPassword::PENDING);
        $this->assertSame($this->company->public_id, $pending['company']);
        $this->assertSame($input['request_key'], $pending['input']['request_key']);
        $this->assertArrayNotHasKey('password', $pending['input']);
        $this->assertDatabaseCount('platform_subscriptions', 0);
        $this->postJson('/confirm-password', ['password' => 'password'])->assertStatus(201);
        $this->get($this->showEndpoint())->assertOk()->assertSee('Unsaved synthetic plan')->assertSee($input['request_key']);
        $this->assertFalse(session()->has(RequirePlatformBillingPassword::PENDING));
        $this->assertDatabaseCount('platform_subscriptions', 0);
        // A separate, explicit reviewed submission is still required.
        $this->post($this->actionEndpoint('configure'), $input)->assertRedirect($this->showEndpoint());
        $this->assertDatabaseCount('platform_subscriptions', 1);
    }

    public function test_expired_platform_proof_and_json_require_reconfirmation_without_authority_or_writes(): void
    {
        $this->travel(61)->minutes();
        $this->postJson($this->actionEndpoint('configure'), $this->configPayload())->assertStatus(423);
        $this->assertFalse(session()->has(RequirePlatformBillingPassword::PENDING));
        $this->post($this->actionEndpoint('configure'), $this->configPayload())->assertRedirect(route('password.confirm'));
        $this->assertSame($this->showEndpoint(), parse_url(session('url.intended'), PHP_URL_PATH));
        $this->assertDatabaseCount('platform_subscriptions', 0);
    }

    public function test_validation_values_from_one_company_are_not_restored_into_another_company(): void
    {
        $this->post($this->actionEndpoint('configure'), $this->configPayload(['plan' => 'COMPANY-A-ONLY', 'fee' => 'invalid']))->assertSessionHasErrors('fee');
        $other = Company::factory()->create();
        $this->get('/platform/companies/'.$other->public_id.'/subscription')->assertOk()->assertDontSee('COMPANY-A-ONLY');
    }

    public function test_renewal_creates_new_period_charge_without_mutating_prior_charge(): void
    {
        $this->post($this->actionEndpoint('configure'), $this->configPayload())->assertRedirect();
        $priorCharge = (array) DB::table('platform_subscription_charges')->first();

        $renewPayload = [
            'request_key' => (string) Str::uuid(),
            'expected_revision' => 1,
            'renews_on' => '2026-03-01',
            'notes' => 'Renewed for March',
        ];

        $response = $this->post($this->actionEndpoint('renew'), $renewPayload);
        $response->assertRedirect($this->showEndpoint());
        $response->assertSessionHas('platform_success');

        $this->assertDatabaseCount('platform_subscription_charges', 2);
        $currentPrior = (array) DB::table('platform_subscription_charges')->where('id', $priorCharge['id'])->first();
        $this->assertSame($priorCharge, $currentPrior);

        $this->assertDatabaseHas('platform_subscriptions', [
            'company_id' => $this->company->id,
            'renews_on' => '2026-03-01',
            'revision' => 2,
        ]);
        $this->assertDatabaseHas('platform_audit_events', [
            'event_key' => 'subscription.renewed',
            'company_id' => $this->company->id,
        ]);
    }

    public function test_renewal_rejects_earlier_or_equal_renewal_date(): void
    {
        $this->post($this->actionEndpoint('configure'), $this->configPayload())->assertRedirect();

        foreach (['2026-02-01', '2026-01-15'] as $badDate) {
            $this->post($this->actionEndpoint('renew'), [
                'request_key' => (string) Str::uuid(),
                'expected_revision' => 1,
                'renews_on' => $badDate,
            ])->assertSessionHasErrors('renews_on');
        }

        $this->assertDatabaseCount('platform_subscription_charges', 1);
    }

    public function test_payment_partial_and_full_settlement_without_period_mutation(): void
    {
        $this->post($this->actionEndpoint('configure'), $this->configPayload())->assertRedirect();
        $charge = DB::table('platform_subscription_charges')->first();
        $this->assertNotNull($charge);

        // 1. Partial payment of 50 ILS
        $pay1 = [
            'request_key' => (string) Str::uuid(),
            'expected_revision' => 1,
            'charge_id' => $charge->id,
            'amount' => '50.000000',
            'currency' => 'ILS',
            'received_on' => '2026-01-10',
            'reference' => 'Partial receipt #1',
        ];
        $this->post($this->actionEndpoint('payment'), $pay1)->assertRedirect($this->showEndpoint());

        $this->assertDatabaseCount('platform_subscription_payments', 1);
        $this->assertDatabaseHas('platform_subscriptions', [
            'company_id' => $this->company->id,
            'renews_on' => '2026-02-01', // Renewal date MUST NOT be mutated!
            'revision' => 2,
        ]);

        // 2. Second payment of 100 ILS settles charge
        $pay2 = [
            'request_key' => (string) Str::uuid(),
            'expected_revision' => 2,
            'charge_id' => $charge->id,
            'amount' => '100.000000',
            'currency' => 'ILS',
            'received_on' => '2026-01-15',
            'reference' => 'Final receipt #2',
        ];
        $this->post($this->actionEndpoint('payment'), $pay2)->assertRedirect($this->showEndpoint());

        $this->assertDatabaseCount('platform_subscription_payments', 2);
        $this->assertSame('150.000000', DB::table('platform_subscription_payments')->sum('amount'));
        $this->assertDatabaseHas('platform_subscriptions', [
            'company_id' => $this->company->id,
            'renews_on' => '2026-02-01', // Still original renewal date
            'revision' => 3,
        ]);
    }

    public function test_payment_rejects_overpayment_zero_future_and_mismatched_currency(): void
    {
        $this->post($this->actionEndpoint('configure'), $this->configPayload())->assertRedirect();
        $charge = DB::table('platform_subscription_charges')->first();
        $this->assertNotNull($charge);

        $base = [
            'request_key' => (string) Str::uuid(),
            'expected_revision' => 1,
            'charge_id' => $charge->id,
            'amount' => '150.000000',
            'currency' => 'ILS',
            'received_on' => '2026-01-10',
        ];

        // Overpayment (150.000001 exceeds 150)
        $this->post($this->actionEndpoint('payment'), array_merge($base, ['amount' => '150.000001']))
            ->assertSessionHasErrors('amount');

        // Zero amount
        $this->post($this->actionEndpoint('payment'), array_merge($base, ['amount' => '0']))
            ->assertSessionHasErrors('amount');

        // Future received_on date relative to company timezone
        $futureDate = now()->setTimezone($this->company->timezone)->addDays(5)->toDateString();
        $this->post($this->actionEndpoint('payment'), array_merge($base, ['received_on' => $futureDate]))
            ->assertSessionHasErrors('received_on');

        // Currency mismatch (USD for ILS charge)
        $this->post($this->actionEndpoint('payment'), array_merge($base, ['currency' => 'USD']))
            ->assertSessionHasErrors('charge_id');

        // Foreign charge
        $foreignCompany = Company::factory()->create();
        $foreignChargeId = DB::table('platform_subscription_charges')->insertGetId([
            'company_id' => $foreignCompany->id,
            'subscription_revision' => 1,
            'plan' => 'Foreign plan',
            'amount' => '100.000000',
            'currency' => 'ILS',
            'starts_on' => '2026-01-01',
            'ends_before' => '2026-02-01',
            'created_at' => now(),
        ]);
        $this->post($this->actionEndpoint('payment'), array_merge($base, ['charge_id' => $foreignChargeId]))
            ->assertSessionHasErrors('charge_id');

        $this->assertDatabaseCount('platform_subscription_payments', 0);
    }

    public function test_audit_write_failure_rolls_back_all_billing_rows(): void
    {
        PlatformAuditEvent::saving(function () {
            throw new RuntimeException('Synthetic audit failure.');
        });

        try {
            $this->post($this->actionEndpoint('configure'), $this->configPayload());
        } catch (Exception $e) {
            // Unhandled exception causes 500 or rollback
        } finally {
            PlatformAuditEvent::flushEventListeners();
        }

        // Entire transaction must roll back
        $this->assertDatabaseCount('platform_subscriptions', 0);
        $this->assertDatabaseCount('platform_subscription_charges', 0);
        $this->assertDatabaseCount('platform_subscription_payments', 0);
        $this->assertDatabaseCount('platform_billing_requests', 0);
    }

    public function test_unpopulated_commercial_schema_rollback_succeeds(): void
    {
        $migration = require database_path('migrations/2026_10_29_100000_create_platform_subscription_records.php');

        // When unpopulated, down() succeeds
        $migration->down();

        $tables = ['platform_billing_requests', 'platform_subscription_payments', 'platform_subscription_charges', 'platform_subscriptions'];
        foreach ($tables as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        // Restore schema for subsequent test cycles
        $migration->up();
        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }

    public function test_payment_selection_and_empty_state_are_explicitly_scoped_to_the_history_page(): void
    {
        $this->post($this->actionEndpoint('configure'), $this->configPayload())->assertRedirect();
        $oldest = DB::table('platform_subscription_charges')->first();
        for ($period = 2; $period <= 16; $period++) {
            // Later zero-fee periods are settled; the oldest period still has a balance.
            DB::table('platform_subscription_charges')->insert([
                'company_id' => $this->company->id, 'subscription_revision' => $period, 'plan' => 'Synthetic later period',
                'amount' => '0', 'currency' => 'ILS', 'starts_on' => '2026-01-01', 'ends_before' => '2026-02-01', 'created_at' => now(),
            ]);
        }
        foreach (['en', 'ar'] as $locale) {
            $this->withSession(['locale' => $locale])->get($this->showEndpoint())->assertOk()
                ->assertSee(__('subscriptions.no_outstanding_charges', [], $locale))
                ->assertSee(__('subscriptions.charges_page_help', [], $locale));
            $this->get($this->showEndpoint().'?page=2')->assertOk()
                ->assertSee('value="'.$oldest->id.'"', false)
                ->assertDontSee(__('subscriptions.no_outstanding_charges', [], $locale));
        }
    }
}
