<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Actions\Company\CreateCompanyAction;
use App\Application\Platform\PlatformAccess;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
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

    public function test_real_operator_password_starts_read_only_owner_view_and_restores_identity(): void
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

    public function test_all_mutating_livewire_ai_switch_download_and_profile_paths_are_blocked(): void
    {
        $this->start();
        $before = DB::table('users')->where('id', $this->owner->id)->first();
        foreach ([app(HandleRequests::class)->getUpdateUri(), '/assistant/memory/discard', '/company/switch', '/locale', '/settings/company-data/download'] as $url) {
            $this->post($url, ['locale' => 'en', 'public_id' => $this->company->public_id])->assertForbidden();
        }
        $this->get('/profile')->assertForbidden();
        $this->get('/companies/select')->assertForbidden();
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

    public function test_read_only_guard_is_effective_even_when_subscription_enforcement_is_off(): void
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

    public function test_only_signed_product_search_and_pagination_updates_are_allowed(): void
    {
        $this->start();
        $page = $this->get('/products')->assertOk();
        $this->assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $match));
        $snapshot = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $uri = app(HandleRequests::class)->getUpdateUri();
        $component = ['snapshot' => $snapshot, 'updates' => ['search' => 'Synthetic'], 'calls' => [['path' => '', 'method' => '$commit', 'params' => []]]];
        $this->postJson($uri, ['components' => [$component]], ['X-Livewire' => 'true'])->assertOk();
        $component['updates'] = ['pageCompanyId' => 999];
        $this->postJson($uri, ['components' => [$component]], ['X-Livewire' => 'true'])->assertForbidden();
        $component['updates'] = [];
        $component['calls'] = [['path' => '', 'method' => 'save', 'params' => []]];
        $this->postJson($uri, ['components' => [$component]], ['X-Livewire' => 'true'])->assertForbidden();
        $component['calls'] = [['path' => '', 'method' => 'gotoPage', 'params' => [1, 'page']]];
        $this->postJson($uri, ['components' => [$component]], ['X-Livewire' => 'true'])->assertOk();
        $decoded = json_decode($snapshot, true, 32, JSON_THROW_ON_ERROR);
        $decoded['checksum'] = str_repeat('0', 64);
        $component['snapshot'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        $component['calls'] = [];
        $this->postJson($uri, ['components' => [$component]], ['X-Livewire' => 'true'])->assertForbidden();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('posting_batches', 0);
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

    public function test_get_writer_backstop_rejects_raw_business_updates_and_restores_root_actor(): void
    {
        Route::middleware('web')->get('/support-test-write', function () {
            DB::table('companies')->where('id', $this->company->id)->update(['name_en' => 'Unwanted write']);

            return response('bad');
        })->name('products.support-probe');
        $this->start();
        $this->get('/support-test-write')->assertForbidden();
        $this->assertSame('Synthetic Support Company', $this->company->fresh()->name_en);
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
}
