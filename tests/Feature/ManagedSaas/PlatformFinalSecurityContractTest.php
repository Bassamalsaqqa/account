<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Actions\Company\CreateCompanyAction;
use App\Application\Platform\PlatformAccess;
use App\Application\Platform\PlatformSessionProof;
use App\Models\PlatformAuditEvent;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

final class PlatformFinalSecurityContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * @param  list<string>  $recoveryCodes
     * @return array{User, PlatformOperatorGrant}
     */
    private function createOperator(array $recoveryCodes = ['synthetic-recovery-code-1', 'synthetic-recovery-code-2']): array
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => Crypt::encrypt('synthetic-secret'),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => Crypt::encrypt(json_encode($recoveryCodes)),
        ])->save();

        $grant = new PlatformOperatorGrant;
        $grant->forceFill([
            'user_id' => $user->id,
            'active' => true,
            'permissions' => PlatformAccess::PERMISSIONS,
            'revision' => 1,
        ])->save();

        return [$user, $grant];
    }

    private function performFortifyChallenge(User $user, string $recoveryCode): void
    {
        $this->withSession(['login.id' => $user->id])
            ->post('/two-factor-challenge', ['recovery_code' => $recoveryCode])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    }

    /**
     * Contract 1: Administrator-set verification alone does not confer platform authority.
     */
    public function test_provisioned_owner_with_email_verified_at_cannot_access_platform_without_grant_and_mfa(): void
    {
        [$operator] = $this->createOperator(['op-rec-1']);
        $this->performFortifyChallenge($operator, 'op-rec-1');

        $payload = [
            'request_key' => (string) Str::uuid(),
            'name_ar' => 'شركة المالك الموثق',
            'name_en' => 'Verified Owner Co',
            'base_currency_code' => 'ILS',
            'default_locale' => 'ar',
            'timezone' => 'Asia/Hebron',
            'owner_name' => 'Owner Person',
            'owner_email' => 'provisioned.owner@example.test',
            'password' => 'SecurePass123!@#',
            'password_confirmation' => 'SecurePass123!@#',
        ];

        $this->post('/platform/companies', $payload)->assertRedirect();

        $owner = User::query()->where('email', 'provisioned.owner@example.test')->firstOrFail();
        $this->assertNotNull($owner->email_verified_at);
        $this->assertFalse(PlatformOperatorGrant::query()->where('user_id', $owner->id)->exists());

        auth()->logout();
        $this->flushSession();

        // Acting as the provisioned owner with verified_at set in a fresh session
        $this->actingAs($owner);
        $this->get('/platform')->assertForbidden();
        $this->get('/platform/companies/create')->assertForbidden();
        $this->post('/platform/companies', $payload)->assertForbidden();
        $this->get('/platform/audit')->assertForbidden();

        $this->assertFalse(session()->has(PlatformSessionProof::KEY));
    }

    /**
     * Contract 2A: EnsurePlatformAccess records denial audit and throttle clamps denial abuse.
     */
    public function test_platform_denial_records_audit_event_and_clamps_rate_limit_bucket(): void
    {
        $user = User::factory()->create();
        RateLimiter::clear('platform:'.$user->id);

        $this->actingAs($user);

        // First denied request records an audit event
        $this->get('/platform')->assertForbidden();
        $this->assertDatabaseHas('platform_audit_events', [
            'event_key' => 'access.denied',
            'actor_user_id' => $user->id,
        ]);

        // Requests up to the 30/min limit are recorded
        for ($i = 2; $i <= 30; $i++) {
            $this->get('/platform')->assertForbidden();
        }

        $this->assertSame(30, PlatformAuditEvent::query()->where('event_key', 'access.denied')->where('actor_user_id', $user->id)->count());

        // The 31st request is throttled by 'throttle:platform' before EnsurePlatformAccess
        $this->get('/platform')->assertStatus(429);

        // Audit count remains 30, proving throttle clamps unbounded audit storage abuse
        $this->assertSame(30, PlatformAuditEvent::query()->where('event_key', 'access.denied')->where('actor_user_id', $user->id)->count());
    }

    /**
     * Contract 2B: Denied-audit storage failure fails closed without leaking sensitive data.
     */
    public function test_denied_audit_storage_failure_fails_closed_without_leaking_sensitive_data(): void
    {
        $user = User::factory()->create();
        RateLimiter::clear('platform:'.$user->id);

        $dispatcher = PlatformAuditEvent::getEventDispatcher();
        PlatformAuditEvent::setEventDispatcher(clone $dispatcher);
        try {
            PlatformAuditEvent::creating(static function (PlatformAuditEvent $event): void {
                if ($event->event_key === 'access.denied') {
                    throw new RuntimeException('Synthetic audit database write failure');
                }
            });

            $response = $this->actingAs($user)->get('/platform');

            // Fails closed (500 internal server error), NEVER 200 OK or unauthorized disclosure
            $response->assertStatus(500);
            $content = (string) $response->getContent();
            $this->assertStringNotContainsString('password', strtolower($content));
            $this->assertStringNotContainsString('Synthetic audit database write failure', $content);
        } finally {
            PlatformAuditEvent::setEventDispatcher($dispatcher);
        }
    }

    /**
     * Contract 3: Revoke then regrant changes revision and invalidates earlier Fortify MFA session proof.
     */
    public function test_operator_revoke_then_regrant_cycle_invalidates_prior_fortify_mfa_proof(): void
    {
        [$operator, $grant] = $this->createOperator(['recovery-step-1', 'recovery-step-2']);

        // Step 1: Initial Fortify MFA challenge creates session proof at revision 1
        $this->performFortifyChallenge($operator, 'recovery-step-1');
        $this->get('/platform')->assertOk();
        $this->assertSame(1, session(PlatformSessionProof::KEY)['grant_revision'] ?? null);

        // Step 2: Revoke grant increments revision to 2 and sets active false
        $grant->forceFill([
            'active' => false,
            'permissions' => [],
        ])->save();

        $this->assertSame(2, $grant->fresh()->revision);
        $this->get('/platform')->assertForbidden();

        // Step 3: Regrant increments revision to 3 and sets active true
        $grant->forceFill([
            'active' => true,
            'permissions' => PlatformAccess::PERMISSIONS,
        ])->save();

        $this->assertSame(3, $grant->fresh()->revision);

        // Step 4: Earlier proof cannot authorize the regrant; require real reauthentication.
        $this->get('/platform')->assertRedirect(route('password.confirm'));

        // Step 5: Operator logs out of old session and completes a fresh Fortify MFA challenge
        auth()->logout();
        $this->flushSession();

        $this->performFortifyChallenge($operator->fresh(), 'recovery-step-2');
        $this->get('/platform')->assertOk();
        $this->assertSame(3, session(PlatformSessionProof::KEY)['grant_revision'] ?? null);
    }

    /**
     * Contract 4: Company Owner role alone cannot access platform; platform projections do not leak tenant financial data.
     */
    public function test_company_owner_role_alone_cannot_gain_platform_access_or_leak_financial_data(): void
    {
        $owner = User::factory()->create();
        $company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'شركة مستقلة',
            'name_en' => 'Independent Tenant',
            'base_currency_code' => 'ILS',
        ]);

        // Tenant owner cannot access platform control plane
        $this->actingAs($owner);
        $this->get('/platform')->assertForbidden();
        $this->get('/platform/companies/create')->assertForbidden();
        $this->post('/platform/companies', [])->assertForbidden();
        $this->get('/platform/audit')->assertForbidden();

        auth()->logout();
        $this->flushSession();

        // Authorized platform operator inspects company view
        [$operator] = $this->createOperator(['op-view-code']);
        $this->performFortifyChallenge($operator, 'op-view-code');

        $response = $this->get('/platform/companies/'.$company->public_id)->assertOk();

        // Platform projection contains only tenant identity, not accounting/financial/credential records
        $response->assertSee('شركة مستقلة');
        $response->assertSee($company->public_id);
        $response->assertDontSee('posting_batches');
        $response->assertDontSee('stock_movements');
        $response->assertDontSee('bank_accounts');
        $response->assertDontSee('two_factor_secret');
        $response->assertDontSee('two_factor_recovery_codes');
        $response->assertDontSee($owner->email);
        $response->assertDontSee($owner->getAuthPassword());

        // Platform read authority does not create tenant membership or permit a forged switch.
        $this->post('/company/switch', ['public_id' => $company->public_id])->assertForbidden();
        $this->assertFalse($operator->activeCompanies()->where('companies.id', $company->id)->exists());
        $this->get('/dashboard')->assertRedirect(route('companies.setup'));
    }

    public function test_platform_pagination_has_localized_accessible_links_and_preserves_search(): void
    {
        $paginator = (new LengthAwarePaginator(range(1, 20), 23, 20, 1))
            ->withPath('/platform')->appends(['q' => 'Synthetic']);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $html = $paginator->links('platform.pagination')->toHtml();
            $this->assertStringContainsString(__('platform.pagination'), $html);
            $this->assertStringContainsString(__('platform.next'), $html);
            $this->assertStringContainsString('rel="next"', $html);
            $this->assertStringContainsString('q=Synthetic', $html);
            $this->assertStringContainsString('page=2', $html);
            $this->assertStringNotContainsString('sm:hidden', $html);
            $this->assertStringNotContainsString('hidden sm:', $html);
        }
    }

    public function test_company_creation_validation_renders_accessible_error_summary_and_descriptors_in_both_locales(): void
    {
        [$operator] = $this->createOperator(['op-val-1', 'op-val-2']);
        $this->performFortifyChallenge($operator, 'op-val-1');

        foreach (['ar', 'en'] as $locale) {
            $this->withSession(['locale' => $locale]);
            $this->post('/platform/companies', [])->assertSessionHasErrors(['name_ar', 'owner_name', 'owner_email', 'password']);

            $view = $this->get('/platform/companies/create')->assertOk();
            $view->assertSee('role="alert"', false);
            $view->assertSee('tabindex="-1"', false);
            $view->assertSee('autofocus', false);
            $view->assertSee('aria-invalid="true"', false);
            $view->assertSee('aria-describedby="name_ar-error"', false);
            $view->assertSee('id="name_ar-error"', false);
        }
    }

    public function test_invalid_recovery_code_keeps_the_error_visible_and_linked_to_the_field(): void
    {
        [$operator] = $this->createOperator();
        foreach (['ar', 'en'] as $locale) {
            Livewire::flushState();
            $this->withSession(['locale' => $locale, 'login.id' => $operator->id])
                ->post('/two-factor-challenge', ['recovery_code' => 'invalid-synthetic-code'])
                ->assertRedirect(route('two-factor.login'))->assertSessionHasErrors('recovery_code');
            $view = $this->get('/two-factor-challenge')->assertOk();
            $view->assertSee('recovery: true', false)
                ->assertSee('aria-invalid="true"', false)
                ->assertSee('aria-describedby="recovery-code-error"', false)
                ->assertSee('id="recovery-code-error"', false)
                ->assertSee('role="alert"', false)
                ->assertDontSee('value="invalid-synthetic-code"', false);
            $view->assertSee(__('The provided two factor recovery code was invalid.'));
            if ($locale === 'ar') {
                $this->assertNotSame('The provided two factor recovery code was invalid.', __('The provided two factor recovery code was invalid.'));
                $this->assertNotSame('The provided two factor authentication code was invalid.', __('The provided two factor authentication code was invalid.'));
            }
            $this->assertGuest();
            $this->assertFalse(session()->has(PlatformSessionProof::KEY));
        }
    }
}
