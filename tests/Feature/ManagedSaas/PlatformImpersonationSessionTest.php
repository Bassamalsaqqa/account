<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiGovernance;
use App\Application\Platform\PlatformAccess;
use App\Application\Platform\SupportViewContext;
use App\Application\Platform\SupportViewSession;
use App\Application\Platform\TenantWriteAccess;
use App\Http\Middleware\Phase11ApiGuard;
use App\Models\Company;
use App\Models\PlatformOperatorGrant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Security\CommandSessionGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PlatformImpersonationSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database', 'subscriptions.enforcement_enabled' => false]);
        $this->owner = User::factory()->create(['password' => Hash::make('Synthetic-owner-password-not-used')]);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'شركة جلسة اصطناعية', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->clear();
        $this->operator = User::factory()->create();
        $grant = new PlatformOperatorGrant;
        $grant->forceFill(['user_id' => $this->operator->id, 'active' => true, 'permissions' => PlatformAccess::PERMISSIONS, 'revision' => 1])->save();
        $this->assertInstanceOf(DatabaseSessionHandler::class, session()->driver()->getHandler());
        $this->post('/login', ['email' => $this->operator->email, 'password' => 'password'])->assertRedirect();
        $this->post(route('platform.support.start', $this->company->public_id), ['reason' => 'Synthetic database session verification', 'current_password' => 'password'])->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('sessions', ['id' => session()->getId(), 'user_id' => $this->operator->id]);
        // HTTP tests must carry the rotated browser cookie; memory-only requests hide this requirement.
        $this->withCookie(session()->getName(), session()->getId());
    }

    public function test_financial_api_proof_locks_real_admin_session_while_using_effective_owner_permissions(): void
    {
        Route::middleware('web')->post('/delegation-session-probe/{companyId}', function (Request $request) {
            return app(Phase11ApiGuard::class)->handle($request, function () {
                return app(TenantWriteAccess::class)->transaction((int) $this->company->id, function () {
                    app(CommandSessionGuard::class)->authorize(auth()->user());

                    return response()->json(['effective_actor' => auth()->id(), 'session_actor' => request()->attributes->get('phase11_api_session')['session_actor_id']]);
                });
            });
        })->name('tenant.delegation-session-probe');
        $response = $this->withCredentials()->postJson('/delegation-session-probe/'.$this->company->public_id);
        $this->assertSame(200, $response->status(), $response->getContent());
        $response->assertJson(['effective_actor' => $this->owner->id, 'session_actor' => $this->operator->id]);
        $this->assertDatabaseHas('sessions', ['id' => session()->getId(), 'user_id' => $this->operator->id]);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->owner->id)->count());
        $this->assertSame($this->operator->id, auth()->id());
        $this->postJson('/delegation-session-probe/foreign-company')->assertForbidden();
    }

    public function test_previous_read_only_proof_is_not_silently_upgraded_to_write_authority(): void
    {
        $legacy = session(SupportViewSession::KEY);
        unset($legacy['access_mode']);
        session()->put(SupportViewSession::KEY, $legacy);
        session()->save();
        $this->get('/products')->assertRedirect(route('platform.index'));
        $this->assertFalse(session()->has(SupportViewSession::KEY));
        $this->assertDatabaseCount('products', 0);
    }

    public function test_owner_that_also_has_a_platform_grant_can_be_impersonated_without_inheriting_that_grant(): void
    {
        $targetGrant = new PlatformOperatorGrant;
        $targetGrant->forceFill(['user_id' => $this->owner->id, 'active' => true, 'permissions' => PlatformAccess::PERMISSIONS, 'revision' => 1])->save();
        $this->get('/products')->assertOk();
        $this->get('/platform')->assertForbidden();
        $this->assertSame($this->operator->id, auth()->id());
        $this->assertDatabaseHas('sessions', ['id' => session()->getId(), 'user_id' => $this->operator->id]);
    }

    public function test_sensitive_company_confirmation_uses_real_admin_password_and_never_calls_a_provider(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Route::middleware('web')->post('/delegation-password-probe', function (Request $request) {
            app(AiGovernance::class)->confirmOwner(app(CompanyContext::class)->company(), auth()->user(), (string) $request->input('password'));

            return response()->noContent();
        })->name('tenant.delegation-password-probe');
        $this->withCredentials()->postJson('/delegation-password-probe', ['password' => 'Synthetic-owner-password-not-used'])->assertForbidden();
        $this->postJson('/delegation-password-probe', ['password' => 'password'])->assertNoContent();
        $this->assertSame($this->operator->id, auth()->id());
        $this->assertDatabaseCount('ai_company_policies', 0);
        Http::assertNothingSent();
    }

    public function test_fortify_password_confirmation_remains_bound_to_real_administrator(): void
    {
        $this->post(route('password.confirm.store'), ['password' => 'Synthetic-owner-password-not-used'])->assertSessionHasErrors('password');
        $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect();
        $this->assertNotNull(session('auth.password_confirmed_at'));
        $this->assertSame($this->operator->id, auth()->id());
        $this->assertDatabaseHas('sessions', ['id' => session()->getId(), 'user_id' => $this->operator->id]);
    }

    public function test_request_local_context_cannot_rebind_a_real_session_to_another_company(): void
    {
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, ['name_ar' => 'Synthetic foreign fixture', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->clear();
        Route::middleware('web')->post('/delegation-context-probe', function () use ($otherOwner, $otherCompany) {
            $support = app(SupportViewContext::class);
            $details = $support->details();
            $details['company_id'] = (int) $otherCompany->id;
            $details['company_public_id'] = $otherCompany->public_id;
            $details['target_user_id'] = (int) $otherOwner->id;
            auth()->setUser($otherOwner);
            app(CompanyContext::class)->clear();
            app(CompanyContext::class)->setCompany($otherCompany, $otherOwner);
            $support->activate($details);

            return app(TenantWriteAccess::class)->transaction((int) $otherCompany->id, function () use ($otherCompany, $otherOwner) {
                app(ProductCatalogService::class)->createProduct($otherCompany,
                    ['name_en' => 'Must not escape selected company', 'base_unit_id' => Unit::where('code', 'piece')->value('id')], (int) $otherOwner->id);

                return response()->noContent();
            });
        })->name('tenant.delegation-context-probe');
        $this->withCredentials()->postJson('/delegation-context-probe')->assertForbidden();
        $this->assertDatabaseCount('products', 0);
        $this->assertSame($this->operator->id, auth()->id());
    }

    public function test_real_api_middleware_keeps_admin_credentials_and_effective_tenant_identity_separate(): void
    {
        config(['commands.api_enabled' => true]);
        $this->withCredentials()->getJson('/api/v1/session')->assertOk()->assertJsonPath('user.id', $this->owner->public_id)->assertJsonPath('company.id', $this->company->id);
        $stored = session('password_hash_web');
        $this->assertSame(auth()->guard('web')->hashPasswordForCookie($this->operator->getAuthPassword()), $stored);
        $this->getJson('/api/v1/companies/'.$this->company->public_id.'/lookups/products')->assertOk();
        $this->assertSame($stored, session('password_hash_web'));
        $this->assertSame($this->operator->id, auth()->id());
        $this->assertDatabaseHas('sessions', ['id' => session()->getId(), 'user_id' => $this->operator->id]);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->owner->id)->count());
        $this->getJson('/api/v1/sessions')->assertForbidden();
        $this->deleteJson('/api/v1/sessions/not-a-customer-session')->assertForbidden();
    }
}
