<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\EnsurePurchasingFoundationAction;
use App\Models\PlatformAuditEvent;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use App\Services\Accounting\AccountingReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PlatformProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): User
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $user->forceFill(['two_factor_secret' => Crypt::encrypt('synthetic-secret'), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => Crypt::encrypt(json_encode(['synthetic-recovery']))])->save();
        $grant = new PlatformOperatorGrant;
        $grant->forceFill(['user_id' => $user->id, 'active' => true, 'permissions' => ['company.read', 'company.provision', 'audit.read'], 'revision' => 1])->save();
        $this->withSession(['login.id' => $user->id])->post('/two-factor-challenge', ['recovery_code' => 'synthetic-recovery'])->assertRedirect();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);

        return $user;
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return ['request_key' => (string) Str::uuid(), 'name_ar' => 'شركة منصة تجريبية', 'name_en' => 'Synthetic Platform Co', 'base_currency_code' => 'ILS', 'default_locale' => 'ar', 'timezone' => 'Asia/Hebron', 'owner_name' => 'Synthetic Customer Owner', 'owner_email' => 'new.owner@example.test', 'password' => 'Synthetic-password-2026!', 'password_confirmation' => 'Synthetic-password-2026!'];
    }

    public function test_ordinary_user_cannot_read_or_provision_companies(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/platform')->assertForbidden();
        $this->post('/platform/companies', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('platform_audit_events', 2);
    }

    public function test_atomic_provision_replay_and_changed_payload_preserve_identity_and_ai_off(): void
    {
        $operator = $this->signIn();
        $payload = $this->payload();
        $first = $this->post('/platform/companies', $payload)->assertRedirect();
        $second = $this->post('/platform/companies', $payload)->assertRedirect();
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('company_user', 1);
        $this->assertDatabaseCount('platform_provisioning_requests', 1);
        $this->assertDatabaseCount('ai_company_policies', 0);
        $this->assertDatabaseCount('ai_provider_connections', 0);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseMissing('company_user', ['user_id' => $operator->id]);
        $this->assertFalse(app(CompanyContext::class)->hasCompany());
        $this->assertNull(getPermissionsTeamId());
        $this->post('/platform/companies', array_replace($payload, ['name_en' => 'Changed']))->assertSessionHasErrors('request_key');
        $this->assertDatabaseCount('companies', 1);
        $stored = json_encode(DB::table('platform_provisioning_requests')->first());
        $this->assertStringNotContainsString($payload['password'], $stored);
        $this->assertStringNotContainsString($payload['password'], json_encode(DB::table('platform_audit_events')->get()));
        Http::assertNothingSent();
    }

    public function test_foundation_failure_leaves_no_orphan_owner_company_or_request(): void
    {
        $operator = $this->signIn();
        $this->app->bind(EnsurePurchasingFoundationAction::class, static fn () => new class
        {
            public function execute(): void
            {
                throw new RuntimeException('Synthetic late foundation failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/platform/companies', $this->payload());
            $this->fail('Expected injected failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic late foundation failure', $e->getMessage());
        }
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('company_user', 0);
        $this->assertDatabaseCount('platform_provisioning_requests', 0);
        $this->assertDatabaseCount('ledger_accounts', 0);
        $this->assertDatabaseCount('money_accounts', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('platform_audit_events', ['event_key' => 'company.provision_failed', 'actor_user_id' => $operator->id]);
        $this->assertFalse(app(CompanyContext::class)->hasCompany());
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_duplicate_existing_identity_is_not_linked_or_overwritten(): void
    {
        $this->signIn();
        $existing = User::factory()->create(['email' => 'new.owner@example.test']);
        $password = $existing->password;
        $this->post('/platform/companies', $this->payload())->assertSessionHasErrors('owner_email');
        $this->assertDatabaseCount('companies', 0);
        $this->assertSame($password, $existing->fresh()->password);
        $this->assertDatabaseCount('company_user', 0);
    }

    public function test_platform_view_redacts_credentials_emails_and_financial_fields_in_both_locales(): void
    {
        $this->signIn();
        $this->post('/platform/companies', $this->payload())->assertRedirect();
        $publicId = DB::table('companies')->value('public_id');
        foreach (['ar', 'en'] as $locale) {
            $this->withSession(['locale' => $locale])->get('/platform')->assertOk()->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);
            $this->get('/platform/companies/'.$publicId)->assertOk()->assertDontSee('new.owner@example.test')->assertDontSee('Synthetic-password-2026!')->assertDontSee('two_factor_secret');
        }
    }

    public function test_existing_tenant_context_is_restored_after_new_company_creation(): void
    {
        $operator = $this->signIn();
        $prior = app(CreateCompanyAction::class)->execute($operator, ['name_ar' => 'شركة المشغل السابقة', 'base_currency_code' => 'USD']);
        app(CompanyContext::class)->setCompany($prior, $operator);
        $this->post('/platform/companies', $this->payload())->assertRedirect();
        $this->assertSame($prior->id, app(CompanyContext::class)->companyId());
        $this->assertSame($prior->id, getPermissionsTeamId());
        $this->assertDatabaseCount('companies', 2);
        $this->assertDatabaseCount('company_user', 2);
    }

    public function test_failed_success_audit_rolls_back_every_provisioning_leg(): void
    {
        $this->signIn();
        PlatformAuditEvent::creating(static function ($event): void {
            if ($event->event_key === 'company.provisioned') {
                throw new RuntimeException('Synthetic audit write failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/platform/companies', $this->payload());
            $this->fail('Expected synthetic audit failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic audit write failure', $e->getMessage());
        }
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('company_user', 0);
        $this->assertDatabaseCount('platform_provisioning_requests', 0);
        $this->assertDatabaseCount('money_accounts', 0);
        $this->assertDatabaseHas('platform_audit_events', ['event_key' => 'company.provision_failed']);
    }

    public function test_altered_input_cannot_assign_operator_or_existing_owner_identity(): void
    {
        $operator = $this->signIn();
        $this->post('/platform/companies', $this->payload() + ['owner_user_id' => $operator->id, 'is_platform_operator' => true, 'status' => 'suspended', 'enabled' => true])->assertRedirect();
        $ownerId = DB::table('company_user')->value('user_id');
        $this->assertNotSame($operator->id, $ownerId);
        $this->assertDatabaseCount('platform_operator_grants', 1);
        $this->assertDatabaseHas('companies', ['status' => 'active']);
        $this->assertDatabaseCount('ai_company_policies', 0);
        $this->get('/platform/companies/create')->assertOk()->assertSee('x-ref="password"', false)->assertDontSee($this->payload()['password']);
    }

    public function test_both_real_owners_and_staff_without_platform_grant_remain_denied(): void
    {
        $owner = User::factory()->create();
        app(CompanyContext::class)->clear();
        $company = app(CreateCompanyAction::class)->execute($owner, ['name_ar' => 'شركة خاصة', 'base_currency_code' => 'ILS']);
        $this->actingAs($owner)->get('/platform')->assertForbidden();
        $this->get('/platform/companies/'.$company->public_id)->assertForbidden();
        $this->get('/platform/audit')->assertForbidden();
    }

    public function test_precomputed_health_is_timestamped_without_financial_details_or_page_time_reconciliation(): void
    {
        $this->signIn();
        $this->post('/platform/companies', $this->payload())->assertRedirect();
        $publicId = DB::table('companies')->value('public_id');
        app(CompanyContext::class)->clear();
        $this->artisan('platform:health-snapshot', ['company' => $publicId])->assertSuccessful();
        $health = DB::table('platform_company_health')->first();
        $this->assertCount(6, json_decode($health->domains, true));
        $this->assertNotContains(false, json_decode($health->domains, true));
        $this->assertStringNotContainsString('balance', $health->domains);
        $this->app->bind(AccountingReconciliationService::class, static fn () => throw new RuntimeException('HTTP must not reconcile'));
        $this->withSession(['locale' => 'en'])->get('/platform')->assertOk()->assertSee('Six domains healthy at recorded check');
        DB::table('platform_company_health')->update(['checked_at' => now()->subDays(2)]);
        $this->get('/platform/companies/'.$publicId)->assertOk()->assertSee('Previous check is over 24 hours old');
    }
}
