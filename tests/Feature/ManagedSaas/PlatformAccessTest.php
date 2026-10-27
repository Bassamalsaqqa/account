<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Application\Platform\PlatformAccess;
use App\Application\Platform\PlatformMfaProof;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->get('/__test/platform-read', function () {
            app(PlatformAccess::class)->authorize('company.read');

            return response('authorized');
        });
    }

    /** @return array{User, PlatformOperatorGrant} */
    private function operator(): array
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => Crypt::encrypt('synthetic-secret'),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => Crypt::encrypt(json_encode(['synthetic-recovery-code'])),
        ])->save();
        $grant = new PlatformOperatorGrant;
        $grant->forceFill(['user_id' => $user->id, 'active' => true, 'permissions' => ['company.read'], 'revision' => 1])->save();

        return [$user, $grant];
    }

    private function challenge(User $user): void
    {
        $this->withSession(['login.id' => $user->id])->post('/two-factor-challenge', [
            'recovery_code' => 'synthetic-recovery-code',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_plain_login_and_mfa_enrollment_do_not_grant_platform_access(): void
    {
        [$user] = $this->operator();
        $this->actingAs($user)->get('/__test/platform-read')->assertForbidden();
        $this->assertFalse(session()->has(PlatformMfaProof::KEY));
    }

    public function test_actual_fortify_challenge_produces_session_proof_after_session_regeneration(): void
    {
        [$user] = $this->operator();
        $this->challenge($user);
        $this->get('/__test/platform-read')->assertOk();
        $this->assertTrue(session()->has(PlatformMfaProof::KEY));
        $this->assertNotContains('synthetic-recovery-code', $user->fresh()->recoveryCodes());
    }

    public function test_mfa_without_explicit_operator_grant_cannot_enter_platform(): void
    {
        [$user, $grant] = $this->operator();
        $grant->forceFill(['active' => false])->save();
        $this->challenge($user);
        $this->get('/__test/platform-read')->assertForbidden();
        $this->assertFalse(session()->has(PlatformMfaProof::KEY));
    }

    public function test_revocation_is_checked_again_without_reusing_a_stale_grant(): void
    {
        [$user, $grant] = $this->operator();
        $this->challenge($user);
        $this->get('/__test/platform-read')->assertOk();
        $grant->forceFill(['active' => false, 'revision' => 2])->save();
        $this->get('/__test/platform-read')->assertForbidden();
    }

    public function test_regrant_cannot_reuse_a_previously_challenged_session(): void
    {
        [$user, $grant] = $this->operator();
        $this->challenge($user);
        $grant->forceFill(['revision' => 2])->save();
        $this->get('/__test/platform-read')->assertForbidden();
    }

    public function test_changed_password_invalidates_proof_even_with_stale_authenticated_model(): void
    {
        [$user] = $this->operator();
        $this->challenge($user);
        $this->get('/__test/platform-read')->assertOk();
        User::query()->whereKey($user->id)->update(['password' => bcrypt('changed-synthetic-password')]);
        $this->get('/__test/platform-read')->assertForbidden();
    }

    public function test_expired_proof_and_disabled_mfa_cannot_authorize(): void
    {
        [$user] = $this->operator();
        $this->challenge($user);
        $this->travel(61)->minutes();
        $this->get('/__test/platform-read')->assertForbidden();
        $this->travelBack();
        User::query()->whereKey($user->id)->update(['two_factor_secret' => null]);
        $this->get('/__test/platform-read')->assertForbidden();
    }

    public function test_logout_and_remember_login_cannot_retain_challenge_proof(): void
    {
        [$user] = $this->operator();
        $this->challenge($user);
        auth()->guard('web')->logout();
        $this->assertFalse(session()->has(PlatformMfaProof::KEY));
        auth()->guard('web')->login($user, true);
        $this->get('/__test/platform-read')->assertForbidden();
    }

    public function test_permission_is_checked_in_addition_to_mfa(): void
    {
        [$user, $grant] = $this->operator();
        $this->challenge($user);
        $grant->forceFill(['permissions' => ['audit.read']])->save();
        $this->get('/__test/platform-read')->assertForbidden();
    }

    public function test_future_or_other_user_session_proof_is_rejected(): void
    {
        [$user] = $this->operator();
        $this->challenge($user);
        $proof = session(PlatformMfaProof::KEY);
        $proof['at'] = now()->addMinutes(5)->timestamp;
        $this->withSession([PlatformMfaProof::KEY => $proof])->get('/__test/platform-read')->assertForbidden();
        $proof['at'] = now()->timestamp;
        $proof['user_id']++;
        $this->withSession([PlatformMfaProof::KEY => $proof])->get('/__test/platform-read')->assertForbidden();
    }

    public function test_recovery_code_regeneration_invalidates_existing_operator_session(): void
    {
        [$user] = $this->operator();
        $this->challenge($user);
        $this->get('/__test/platform-read')->assertOk();
        User::query()->whereKey($user->id)->update(['two_factor_recovery_codes' => Crypt::encrypt(json_encode(['new-code']))]);
        $this->get('/__test/platform-read')->assertForbidden();
    }

    public function test_challenge_view_loads_its_alpine_dependency_and_localized_recovery_controls(): void
    {
        [$user] = $this->operator();
        foreach (['ar' => 'استخدام رمز الاسترداد', 'en' => 'Use a recovery code'] as $locale => $label) {
            // Separate HTTP requests have a fresh asset-render lifecycle in the browser.
            Livewire::flushState();
            $this->withSession(['login.id' => $user->id, 'locale' => $locale])->get('/two-factor-challenge')
                ->assertOk()->assertSee($label)->assertSee('livewire.min.js', false)->assertDontSee('auth.use_recovery_code');
        }
    }
}
