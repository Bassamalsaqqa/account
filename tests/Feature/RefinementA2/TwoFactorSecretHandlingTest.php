<?php

declare(strict_types=1);

namespace Tests\Feature\RefinementA2;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TwoFactorSecretHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_confirmation_never_flashes_one_time_values(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson(route('two-factor.enable'))->assertSuccessful();

        $response = $this->from('/profile')->post(route('two-factor.confirm'), [
            'code' => 'invalid-totp-value',
            'recovery_code' => 'private-recovery-marker',
        ]);

        $response->assertRedirect('/profile');
        $this->assertNull(session()->getOldInput('code'));
        $this->assertNull(session()->getOldInput('recovery_code'));
        $this->assertStringNotContainsString('private-recovery-marker', json_encode(session()->all(), JSON_THROW_ON_ERROR));
    }

    public function test_malformed_challenge_does_not_flash_an_actual_recovery_code(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson(route('two-factor.enable'))->assertSuccessful();
        $code = $user->fresh()->recoveryCodes()[0];
        auth()->guard('web')->logout();

        $this->withSession(['login.id' => $user->id])->from('/two-factor-challenge')
            ->post(route('two-factor.login.store'), ['code' => ['invalid-type'], 'recovery_code' => $code])
            ->assertRedirect('/two-factor-challenge');

        $this->assertNull(session()->getOldInput('code'));
        $this->assertNull(session()->getOldInput('recovery_code'));
        $this->assertStringNotContainsString($code, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertGuest();
    }

    public function test_secret_endpoints_require_confirmation_and_are_not_cacheable(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->getJson(route('two-factor.qr-code'))->assertStatus(423);
        $this->getJson(route('two-factor.recovery-codes'))->assertStatus(423);
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson(route('two-factor.enable'))->assertSuccessful();

        $qr = $this->getJson(route('two-factor.qr-code'))->assertOk()->assertJsonStructure(['svg']);
        $this->assertStringContainsString('no-store', (string) $qr->headers->get('Cache-Control'));
        $recovery = $this->getJson(route('two-factor.recovery-codes'))->assertOk();
        $this->assertStringContainsString('no-store', (string) $recovery->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('two_factor_secret', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->fresh()->toArray());
    }

    public function test_actual_fortify_challenge_consumes_a_recovery_code_once(): void
    {
        $user = User::factory()->create(['password' => 'fixture-password']);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson(route('two-factor.enable'))->assertSuccessful();
        $user->refresh()->forceFill(['two_factor_confirmed_at' => now()])->save();
        $recoveryCode = $user->recoveryCodes()[0];
        auth()->guard('web')->logout();

        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'fixture-password'])
            ->assertOk()->assertJson(['two_factor' => true]);
        $this->assertGuest();
        $this->postJson(route('two-factor.login.store'), ['recovery_code' => $recoveryCode])->assertSuccessful();
        $this->assertAuthenticatedAs($user);
        $this->assertNotContains($recoveryCode, $user->fresh()->recoveryCodes());

        auth()->guard('web')->logout();
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'fixture-password'])
            ->assertOk()->assertJson(['two_factor' => true]);
        $this->postJson(route('two-factor.login.store'), ['recovery_code' => $recoveryCode])->assertStatus(422);
        $this->assertGuest();
    }
}
