<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Application\Platform\PlatformAccess;
use App\Application\Platform\PlatformSessionProof;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

final class PlatformPasswordAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, PlatformOperatorGrant} */
    private function operator(): array
    {
        $user = User::factory()->create();
        $grant = new PlatformOperatorGrant;
        $grant->forceFill(['user_id' => $user->id, 'active' => true, 'permissions' => PlatformAccess::PERMISSIONS, 'revision' => 1])->save();

        return [$user, $grant];
    }

    public function test_actual_password_login_without_mfa_can_enter_both_locales(): void
    {
        [$user] = $this->operator();
        $this->assertNull($user->two_factor_secret);
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1'])->assertRedirect();
        foreach (['ar', 'en'] as $locale) {
            $this->withSession(['locale' => $locale])->get('/platform')->assertOk()->assertSee(__('platform.companies'));
        }
        $this->assertSame(1, session(PlatformSessionProof::KEY)['grant_revision']);
    }

    public function test_existing_session_after_grant_uses_real_password_confirmation(): void
    {
        [$user] = $this->operator();
        $this->actingAs($user)->get('/platform')->assertRedirect(route('password.confirm'));
        $this->assertSame(url('/platform'), session('url.intended'));
        Volt::test('pages.auth.confirm-password')->set('password', 'wrong-password')->call('confirmPassword')->assertHasErrors('password');
        $this->assertFalse(session()->has(PlatformSessionProof::KEY));
        Volt::test('pages.auth.confirm-password')->set('password', 'password')->call('confirmPassword')->assertHasNoErrors()->assertRedirect('/platform');
        $this->get('/platform')->assertOk();
    }

    public function test_fortify_confirmation_supports_regrant_but_timestamp_alone_does_not(): void
    {
        [$user, $grant] = $this->operator();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $grant->forceFill(['active' => false])->save();
        $this->get('/platform')->assertForbidden();
        $grant->forceFill(['active' => true])->save();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->get('/platform')->assertRedirect(route('password.confirm'));
        $this->postJson('/confirm-password', ['password' => 'wrong-password'])->assertStatus(422);
        $this->get('/platform')->assertRedirect(route('password.confirm'));
        $this->postJson('/confirm-password', ['password' => 'password'])->assertStatus(201);
        $this->get('/platform')->assertOk();
        $this->assertSame(3, session(PlatformSessionProof::KEY)['grant_revision']);
    }

    public function test_password_change_rejects_old_credentials_with_stale_authenticated_model(): void
    {
        [$user] = $this->operator();
        $this->actingAs($user);
        User::query()->whereKey($user->id)->update(['password' => Hash::make('replacement-password')]);
        $this->postJson('/confirm-password', ['password' => 'password'])->assertStatus(422);
        $this->assertFalse(session()->has(PlatformSessionProof::KEY));
        $this->postJson('/confirm-password', ['password' => 'replacement-password'])->assertStatus(201);
        $this->get('/platform')->assertOk();
    }

    public function test_password_confirmation_without_grant_cannot_create_platform_authority(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/confirm-password', ['password' => 'password'])->assertStatus(201);
        $this->get('/platform')->assertForbidden();
        $this->assertFalse(session()->has(PlatformSessionProof::KEY));
        $this->assertDatabaseCount('platform_operator_grants', 0);
    }

    public function test_expired_proof_requires_password_and_does_not_replay_a_post(): void
    {
        [$user] = $this->operator();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->travel(61)->minutes();
        $this->postJson('/platform/companies', [])->assertStatus(423);
        $this->post('/platform/companies', [])->assertRedirect(route('password.confirm'));
        $this->assertSame(route('platform.index'), session('url.intended'));
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('platform_provisioning_requests', 0);
    }

    public function test_remember_cookie_alone_never_creates_platform_proof(): void
    {
        [$user] = $this->operator();
        $user->forceFill(['remember_token' => 'synthetic-remember-token'])->save();
        $guard = Auth::guard('web');
        $cookie = $user->id.'|'.$user->remember_token.'|'.$user->getAuthPassword();
        $this->withCookie($guard->getRecallerName(), $cookie)->get('/platform')->assertRedirect(route('password.confirm'));
        $this->assertAuthenticatedAs($user);
        $this->assertFalse(session()->has(PlatformSessionProof::KEY));
    }

    public function test_platform_entry_is_available_on_setup_only_with_an_explicit_read_grant(): void
    {
        [$user, $grant] = $this->operator();
        $this->actingAs($user)->get('/companies/setup')->assertOk()->assertSee('href="'.route('platform.index').'"', false);
        $grant->forceFill(['active' => false])->save();
        $this->get('/companies/setup')->assertOk()->assertDontSee('href="'.route('platform.index').'"', false);
    }

    public function test_host_grant_requires_explicit_confirmation_but_not_mfa_enrollment(): void
    {
        $user = User::factory()->create();
        $this->artisan('platform:operator', ['email' => $user->email])
            ->expectsConfirmation('Change platform authority for this exact existing account?', 'no')->assertFailed();
        $this->assertDatabaseCount('platform_operator_grants', 0);
        $this->artisan('platform:operator', ['email' => $user->email])
            ->expectsConfirmation('Change platform authority for this exact existing account?', 'yes')->assertSuccessful();
        $this->assertDatabaseHas('platform_operator_grants', ['user_id' => $user->id, 'active' => true, 'revision' => 1]);
        $this->assertDatabaseHas('platform_audit_events', ['event_key' => 'operator.granted', 'actor_user_id' => null]);
        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertDatabaseCount('companies', 0);
    }
}
