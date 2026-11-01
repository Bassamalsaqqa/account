<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Actions\Company\CreateCompanyAction;
use App\Application\Platform\PlatformAccess;
use App\Application\Platform\PlatformSessionProof;
use App\Application\Platform\SupportViewContext;
use App\Application\Platform\SupportViewSession;
use App\Application\Platform\TenantWriteAccess;
use App\Models\Company;
use App\Models\PlatformAuditEvent;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Mockery\MockInterface;
use Tests\TestCase;

final class PlatformSupportViewTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $owner;

    private Company $company;

    private PlatformOperatorGrant $grant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['name' => 'Synthetic Support Owner']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'شركة الدعم الاصطناعي', 'name_en' => 'Synthetic Support Company', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->clear();
        $this->operator = User::factory()->create(['name' => 'Synthetic Support Operator']);
        $this->grant = new PlatformOperatorGrant;
        $this->grant->forceFill(['user_id' => $this->operator->id, 'active' => true, 'permissions' => PlatformAccess::PERMISSIONS, 'revision' => 1])->save();
        $this->post('/login', ['email' => $this->operator->email, 'password' => 'password'])->assertRedirect();
    }

    private function start(): void
    {
        $this->post(route('platform.support.start', $this->company->public_id), ['reason' => 'Synthetic troubleshooting ticket', 'current_password' => 'password'])->assertRedirect(route('dashboard'));
    }

    public function test_real_operator_password_starts_owner_impersonation_and_restores_identity(): void
    {
        $ownerBefore = DB::table('users')->where('id', $this->owner->id)->first();
        $this->start();
        $this->assertSame($this->operator->id, auth()->id());
        $this->assertSame($this->owner->id, session(SupportViewSession::KEY)['target_user_id']);
        $this->assertSame(1, DB::table('platform_audit_events')->where('event_key', 'support.started')->count());
        foreach (['en', 'ar'] as $locale) {
            $response = $this->withSession(['locale' => $locale])->get(route('products.index'))->assertOk()->assertSee(__('support.title'))->assertSee('Synthetic Support Operator')->assertSee('Synthetic Support Owner');
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            $this->assertTrue($response->headers->hasCacheControlDirective('private'));
            $this->assertSame($this->operator->id, auth()->id());
            $this->assertFalse(app(SupportViewContext::class)->active());
        }
        $this->assertEquals($ownerBefore, DB::table('users')->where('id', $this->owner->id)->first());
        $this->post(route('platform.support.end'))->assertRedirect(route('platform.index'));
        $this->assertFalse(session()->has(SupportViewSession::KEY));
        $this->assertDatabaseHas('platform_audit_events', ['event_key' => 'support.ended', 'actor_user_id' => $this->operator->id, 'company_id' => $this->company->id]);
        $this->assertNull($this->owner->fresh()->two_factor_secret);
    }

    public function test_missing_permission_or_wrong_password_cannot_start_and_password_is_not_flashed(): void
    {
        $this->from(route('platform.companies.show', $this->company->public_id))->post(route('platform.support.start', $this->company->public_id), ['reason' => 'Synthetic issue', 'current_password' => 'wrong-password'])->assertSessionHasErrors('current_password');
        $this->assertFalse(session()->has(SupportViewSession::KEY));
        $this->assertNull(session('_old_input.current_password'));
        $this->grant->forceFill(['permissions' => ['company.read']])->save();
        $this->post('/confirm-password', ['password' => 'password']);
        $this->post(route('platform.support.start', $this->company->public_id), ['reason' => 'Synthetic issue', 'current_password' => 'password'])->assertForbidden();
        $this->assertSame(0, DB::table('platform_audit_events')->where('event_key', 'support.started')->count());
    }

    public function test_support_dashboard_renders_without_hidden_writes(): void
    {
        $this->withoutExceptionHandling();
        $this->start();
        $this->get('/dashboard')->assertOk()->assertSee(__('support.title'));
    }

    public function test_tenant_owner_is_not_a_platform_support_operator(): void
    {
        $this->actingAs($this->owner)->post(route('platform.support.start', $this->company->public_id), ['reason' => 'Synthetic issue', 'current_password' => 'password'])->assertForbidden();
        $this->assertFalse(session()->has(SupportViewSession::KEY));
    }

    public function test_personal_credentials_platform_and_company_switching_remain_outside_tenant_impersonation(): void
    {
        $this->start();
        $before = DB::table('users')->where('id', $this->owner->id)->first();
        foreach (['/company/switch', '/locale', '/user/two-factor-authentication'] as $url) {
            $this->post($url, ['locale' => 'en', 'public_id' => $this->company->public_id])->assertForbidden();
        }
        $this->put('/user/password', ['password' => 'must-not-change'])->assertForbidden();
        $this->get('/profile')->assertForbidden();
        $this->get('/companies/select')->assertForbidden();
        $this->get('/platform')->assertForbidden();
        $this->assertEquals($before, DB::table('users')->where('id', $this->owner->id)->first());
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('posting_batches', 0);
    }

    public function test_absolute_expiry_revocation_and_regrant_end_the_old_support_session(): void
    {
        $this->start();
        $this->travel(16)->minutes();
        $this->get('/products')->assertRedirect(route('platform.index'));
        $this->assertFalse(session()->has(SupportViewSession::KEY));
        $this->travelBack();
        $this->start();
        $this->grant->forceFill(['active' => false])->save();
        $this->grant->forceFill(['active' => true])->save();
        $this->get('/products')->assertRedirect(route('platform.index'));
        $this->assertFalse(session()->has(SupportViewSession::KEY));
        $this->assertSame($this->operator->id, auth()->id());
    }

    public function test_revoked_target_membership_ends_support_without_cross_company_fallback(): void
    {
        $this->start();
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        $this->get('/products')->assertRedirect(route('platform.index'));
        $this->assertFalse(session()->has(SupportViewSession::KEY));
    }

    public function test_forged_incomplete_delegation_is_denied_even_when_subscription_enforcement_is_off(): void
    {
        config(['subscriptions.enforcement_enabled' => false]);
        app(SupportViewContext::class)->activate(['operator_id' => $this->operator->id]);
        $this->expectException(AuthorizationException::class);
        try {
            app(TenantWriteAccess::class)->transaction((int) $this->company->id, function (): void {
                $this->fail('Support mode admitted a business writer');
            });
        } finally {
            app(SupportViewContext::class)->clear();
        }
    }

    public function test_signed_livewire_search_and_pagination_preserve_the_effective_owner_and_company(): void
    {
        $this->start();
        $page = $this->get('/products')->assertOk();
        $snapshot = $this->componentSnapshot($page->getContent(), 'pages.products.product-index');
        $component = ['snapshot' => $snapshot, 'updates' => ['search' => 'Synthetic'], 'calls' => [['path' => '', 'method' => '$commit', 'params' => []]]];
        $uri = app(HandleRequests::class)->getUpdateUri();
        $this->postJson($uri, ['components' => [$component]], ['X-Livewire' => 'true'])->assertOk();
        $component['calls'] = [['path' => '', 'method' => 'gotoPage', 'params' => [1, 'page']]];
        $this->postJson($uri, ['components' => [$component]], ['X-Livewire' => 'true'])->assertOk();
        $this->assertSame($this->operator->id, auth()->id());
        $this->assertFalse(app(SupportViewContext::class)->active());
        $this->assertDatabaseCount('products', 0);
    }

    public function test_start_audit_failure_creates_no_support_authority_and_nested_start_is_denied(): void
    {
        $this->start();
        $before = session(SupportViewSession::KEY);
        $this->post(route('platform.support.start', $this->company->public_id), ['reason' => 'Nested synthetic issue', 'current_password' => 'password'])->assertForbidden();
        $this->assertSame($before, session(SupportViewSession::KEY));
        $this->post(route('platform.support.end'))->assertRedirect();
        PlatformAuditEvent::creating(static function (PlatformAuditEvent $event): void {
            if ($event->event_key === 'support.started') {
                throw new \RuntimeException('Synthetic start audit failure');
            }
        });
        $this->post(route('platform.support.start', $this->company->public_id), ['reason' => 'Synthetic failing audit', 'current_password' => 'password'])->assertStatus(500);
        $this->assertFalse(session()->has(SupportViewSession::KEY));
        $this->assertSame($this->operator->id, auth()->id());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function componentSnapshot(string $html, string $name): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (json_decode($snapshot, true, 64, JSON_THROW_ON_ERROR)['memo']['name'] === $name) {
                return $snapshot;
            }
        }
        $this->fail('The expected tenant component was not rendered: '.$name);
    }

    public function test_canonical_product_write_uses_owner_permissions_and_audits_the_real_operator(): void
    {
        $this->start();
        $page = $this->get('/products/create')->assertOk();
        $snapshot = $this->componentSnapshot($page->getContent(), 'pages.products.product-form');
        $component = ['snapshot' => $snapshot, 'updates' => ['name_en' => 'Synthetic delegated product'],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]]];
        $this->postJson(app(HandleRequests::class)->getUpdateUri(), ['components' => [$component]], ['X-Livewire' => 'true'])->assertOk();
        $this->assertDatabaseHas('products', ['company_id' => $this->company->id, 'name_en' => 'Synthetic delegated product', 'created_by' => $this->owner->id]);
        $this->assertDatabaseHas('platform_audit_events', ['company_id' => $this->company->id, 'actor_user_id' => $this->operator->id, 'event_key' => 'impersonation.action']);
        $this->assertSame(0, DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->operator->id)->count());
        $this->assertFalse(app(SupportViewContext::class)->active());
        $this->assertSame($this->operator->id, auth()->id());
    }

    public function test_locale_is_session_only_and_leaving_is_available_after_revocation_or_audit_failure(): void
    {
        $this->start();
        $before = $this->owner->fresh()->locale;
        $this->post(route('platform.support.locale'), ['locale' => 'en'])->assertRedirect(route('dashboard'));
        $this->assertSame($before, $this->owner->fresh()->locale);
        $this->grant->forceFill(['active' => false])->save();
        PlatformAuditEvent::creating(static function (PlatformAuditEvent $event): void {
            if ($event->event_key === 'support.ended') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $this->post(route('platform.support.end'))->assertRedirect(route('platform.index'));
        $this->assertFalse(session()->has(SupportViewSession::KEY));
        $this->assertSame($this->operator->id, auth()->id());
    }

    public function test_expired_support_locale_change_returns_to_operator_without_changing_tenant(): void
    {
        $this->start();
        $before = $this->owner->fresh()->locale;
        $this->travel(16)->minutes();
        $this->post(route('platform.support.locale'), ['locale' => 'en'])->assertRedirect(route('platform.index'));
        $this->assertFalse(session()->has(SupportViewSession::KEY));
        $this->assertSame($before, $this->owner->fresh()->locale);
        $this->assertSame($this->operator->id, auth()->id());
    }

    public function test_context_setup_failure_still_restores_real_operator_identity(): void
    {
        $this->start();
        $this->partialMock(CompanyContext::class, function (MockInterface $mock): void {
            $mock->shouldReceive('setCompany')->once()->andThrow(new AuthorizationException('Synthetic context failure'));
        });
        $this->get('/products')->assertForbidden();
        $this->assertSame($this->operator->id, auth()->id());
        $this->assertFalse(app(SupportViewContext::class)->active());
    }

    public function test_exited_support_proof_cannot_be_revived_by_a_late_session_response(): void
    {
        $this->start();
        $stale = session(SupportViewSession::KEY);
        $this->post(route('platform.support.end'))->assertRedirect(route('platform.index'));
        Cache::flush(); // Durable audit revocation must survive cache eviction.
        $this->withSession([SupportViewSession::KEY => $stale])->get('/products')->assertRedirect(route('platform.index'));
        $this->assertSame(1, PlatformAuditEvent::where('event_key', 'support.ended')->count());
        $this->assertFalse(session()->has(SupportViewSession::KEY));
        $this->assertSame($this->operator->id, auth()->id());
    }

    public function test_exit_revocation_survives_audit_failure_and_stale_session_replay(): void
    {
        $this->start();
        $stale = session(SupportViewSession::KEY);
        PlatformAuditEvent::creating(static function (PlatformAuditEvent $event): void {
            if ($event->event_key === 'support.ended') {
                throw new \RuntimeException('Synthetic exit audit failure');
            }
        });
        $this->post(route('platform.support.end'))->assertRedirect(route('platform.index'));
        $this->withSession([SupportViewSession::KEY => $stale])->get('/products')->assertRedirect(route('platform.index'));
        $this->assertFalse(session()->has(SupportViewSession::KEY));
        $this->assertSame($this->operator->id, auth()->id());
    }

    public function test_cache_revocation_failure_uses_durable_audit_and_double_failure_requires_reauthentication(): void
    {
        $this->start();
        $stale = session(SupportViewSession::KEY);
        $cache = Cache::getFacadeRoot();
        Cache::shouldReceive('put')->once()->andReturn(false);
        $this->post(route('platform.support.end'))->assertRedirect(route('platform.index'));
        Cache::swap($cache);
        $this->withSession([SupportViewSession::KEY => $stale])->get('/products')->assertRedirect(route('platform.index'));
        $this->start();
        PlatformAuditEvent::creating(static function (PlatformAuditEvent $event): void {
            if ($event->event_key === 'support.ended') {
                throw new \RuntimeException('Synthetic combined storage failure');
            }
        });
        Cache::shouldReceive('put')->once()->andReturn(false);
        $this->post(route('platform.support.end'))->assertRedirect(route('platform.index'));
        Cache::swap($cache);
        $this->assertFalse(session()->has(SupportViewSession::KEY));
        $this->assertFalse(session()->has(PlatformSessionProof::KEY));
        $this->assertSame($this->operator->id, auth()->id());
    }

    public function test_prior_company_livewire_form_cannot_write_after_switching_impersonation_target(): void
    {
        $this->start();
        $snapshot = $this->componentSnapshot($this->get('/products/create')->assertOk()->getContent(), 'pages.products.product-form');
        $this->post(route('platform.support.end'))->assertRedirect();
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, ['name_en' => 'Synthetic Other Company', 'name_ar' => 'Synthetic fixture', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->clear();
        $this->post(route('platform.support.start', $otherCompany->public_id), ['reason' => 'Synthetic second company', 'current_password' => 'password'])->assertRedirect(route('dashboard'));
        $component = ['snapshot' => $snapshot, 'updates' => ['name_en' => 'Must never cross companies'], 'calls' => [['path' => '', 'method' => 'save', 'params' => []]]];
        $this->postJson(app(HandleRequests::class)->getUpdateUri(), ['components' => [$component]], ['X-Livewire' => 'true'])->assertForbidden();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame($this->operator->id, auth()->id());
    }

    public function test_legacy_support_permission_does_not_display_or_authorize_full_impersonation(): void
    {
        $this->grant->forceFill(['permissions' => ['company.read', 'company.support']])->save();
        $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect();
        $this->get(route('platform.companies.show', $this->company->public_id))->assertOk()->assertDontSee('id="support-reason"', false);
        $this->post(route('platform.support.start', $this->company->public_id), ['reason' => 'Synthetic legacy permission', 'current_password' => 'password'])->assertForbidden();
        $this->assertFalse(session()->has(SupportViewSession::KEY));
    }
}
