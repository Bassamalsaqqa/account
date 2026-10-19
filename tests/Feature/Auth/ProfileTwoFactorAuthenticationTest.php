<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Fortify;
use Livewire\Volt\Volt;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class ProfileTwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:sO7p2V7+eL5ZgVqfR6E2K6J8oXp+q8v4b2y1N5c4m9w=']);
    }

    public function test_profile_page_displays_two_factor_section_for_regular_user_without_settings_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile');

        $response
            ->assertOk()
            ->assertSee(__('profile_security.title'))
            ->assertSee(__('profile_security.description'))
            ->assertSee(__('profile_security.enable_action'))
            ->assertSeeVolt('profile.update-profile-information-form')
            ->assertSeeVolt('profile.update-password-form')
            ->assertSeeVolt('profile.delete-user-form');

        // Verify no raw secrets or recovery codes are rendered in the response HTML
        $response->assertDontSee('two_factor_secret');
        $response->assertDontSee('two_factor_recovery_codes');
    }

    public function test_two_factor_endpoints_require_password_confirmation_when_timeout_expired(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret-password-123'),
        ]);

        $this->actingAs($user);

        // Session has NO password confirmation (or expired)
        // 1. Enable endpoint
        $this->postJson('/user/two-factor-authentication')
            ->assertStatus(423)
            ->assertJson(['message' => 'Password confirmation required.']);

        // 2. Disable endpoint
        $this->deleteJson('/user/two-factor-authentication')
            ->assertStatus(423)
            ->assertJson(['message' => 'Password confirmation required.']);

        // 3. QR code endpoint
        $this->getJson('/user/two-factor-qr-code')
            ->assertStatus(423)
            ->assertJson(['message' => 'Password confirmation required.']);

        // 4. Secret key endpoint
        $this->getJson('/user/two-factor-secret-key')
            ->assertStatus(423)
            ->assertJson(['message' => 'Password confirmation required.']);

        // 5. Recovery codes endpoint
        $this->getJson('/user/two-factor-recovery-codes')
            ->assertStatus(423)
            ->assertJson(['message' => 'Password confirmation required.']);

        // 6. Regenerate recovery codes endpoint
        $this->postJson('/user/two-factor-recovery-codes')
            ->assertStatus(423)
            ->assertJson(['message' => 'Password confirmation required.']);

        // 7. Confirm two-factor endpoint
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => '123456'])
            ->assertStatus(423)
            ->assertJson(['message' => 'Password confirmation required.']);
    }

    public function test_password_can_be_confirmed_via_fortify_endpoint(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('my-strong-password'),
        ]);

        $this->actingAs($user);

        // Incorrect password fails with 422
        $failResponse = $this->postJson('/confirm-password', [
            'password' => 'wrong-pass',
        ]);
        $failResponse->assertStatus(422)
            ->assertJsonValidationErrors('password');

        // Status endpoint indicates unconfirmed
        $this->getJson('/user/confirmed-password-status')
            ->assertOk()
            ->assertJson(['confirmed' => false]);

        // Correct password succeeds with 201
        $successResponse = $this->postJson('/confirm-password', [
            'password' => 'my-strong-password',
        ]);
        $successResponse->assertStatus(201);

        // Status endpoint now indicates confirmed
        $this->getJson('/user/confirmed-password-status')
            ->assertOk()
            ->assertJson(['confirmed' => true]);
    }

    public function test_two_factor_enrollment_lifecycle_pending_state_distinguished_from_confirmed(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()]);

        // 1. Initial state: not enabled, no secret
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());

        // 2. Enable two-factor authentication
        $enableResponse = $this->postJson('/user/two-factor-authentication');
        $enableResponse->assertStatus(200);

        $user->refresh();

        // Secret and recovery codes are now populated
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);

        // BUT confirmation timestamp is NULL -> Pending State
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());

        // 3. User can fetch QR code SVG and URL via Fortify
        $qrResponse = $this->getJson('/user/two-factor-qr-code');
        $qrResponse->assertOk()
            ->assertJsonStructure(['svg', 'url']);

        // 4. User can fetch secret key
        $secretResponse = $this->getJson('/user/two-factor-secret-key');
        $secretResponse->assertOk()
            ->assertJsonStructure(['secretKey']);

        // 5. While in pending state, logging out and logging in does NOT trigger 2FA challenge
        // because the user has not confirmed enrollment yet (not locked out!)
        auth()->logout();
        $loginResponse = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);
        $loginResponse->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_two_factor_confirmation_rejects_invalid_totp_code(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()]);

        // Initiate enrollment
        $this->postJson('/user/two-factor-authentication');
        $user->refresh();

        // Submit invalid 6-digit code
        $confirmResponse = $this->postJson('/user/confirmed-two-factor-authentication', [
            'code' => '000000',
        ]);

        $confirmResponse->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $user->refresh();
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_two_factor_confirmation_succeeds_with_valid_totp_code(): void
    {
        Event::fake([TwoFactorAuthenticationConfirmed::class]);

        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()]);

        // 1. Enable
        $this->postJson('/user/two-factor-authentication');
        $user->refresh();

        // 2. Generate valid TOTP code
        $google2fa = app(Google2FA::class);
        $decryptedSecret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $validCode = $google2fa->getCurrentOtp($decryptedSecret);

        // 3. Confirm with valid code
        $confirmResponse = $this->postJson('/user/confirmed-two-factor-authentication', [
            'code' => $validCode,
        ]);

        $confirmResponse->assertStatus(200);

        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());

        Event::assertDispatched(TwoFactorAuthenticationConfirmed::class, function ($event) use ($user) {
            return $event->user->id === $user->id;
        });
    }

    public function test_recovery_codes_retrieval_and_regeneration(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()]);

        $this->postJson('/user/two-factor-authentication');
        $user->refresh();
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        // 1. Retrieve initial recovery codes
        $getCodesResponse = $this->getJson('/user/two-factor-recovery-codes');
        $getCodesResponse->assertOk();
        $initialCodes = $getCodesResponse->json();
        $this->assertIsArray($initialCodes);
        $this->assertCount(8, $initialCodes);

        // 2. Regenerate recovery codes
        $regenResponse = $this->postJson('/user/two-factor-recovery-codes');
        $regenResponse->assertStatus(200);

        $user->refresh();
        $getNewCodesResponse = $this->getJson('/user/two-factor-recovery-codes');
        $newCodes = $getNewCodesResponse->json();

        $this->assertIsArray($newCodes);
        $this->assertCount(8, $newCodes);
        $this->assertNotSame($initialCodes, $newCodes);
    }

    public function test_two_factor_authentication_can_be_disabled(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()]);

        $this->postJson('/user/two-factor-authentication');
        $user->refresh();
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());

        // Disable 2FA
        $disableResponse = $this->deleteJson('/user/two-factor-authentication');
        $disableResponse->assertStatus(200);

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_pending_two_factor_enrollment_can_be_cancelled(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()]);

        // Start enrollment
        $this->postJson('/user/two-factor-authentication');
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);

        // Cancel pending enrollment
        $cancelResponse = $this->deleteJson('/user/two-factor-authentication');
        $cancelResponse->assertStatus(200);

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_raw_secrets_and_recovery_codes_are_never_in_livewire_or_public_snapshots(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()]);

        $this->postJson('/user/two-factor-authentication');
        $user->refresh();
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $secret = $user->two_factor_secret;
        $recoveryCodes = $user->two_factor_recovery_codes;

        $this->assertNotEmpty($secret);
        $this->assertNotEmpty($recoveryCodes);

        // 1. Model array / JSON serialization MUST hide secrets
        $userArray = $user->toArray();
        $this->assertArrayNotHasKey('two_factor_secret', $userArray);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $userArray);

        // 2. Profile Volt components do not store secrets in public state
        $profileInfo = Volt::test('profile.update-profile-information-form');
        $this->assertStringNotContainsString($secret, json_encode($profileInfo->snapshot, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($recoveryCodes, json_encode($profileInfo->snapshot, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($secret, $profileInfo->html());
        $this->assertStringNotContainsString($recoveryCodes, $profileInfo->html());

        $passwordForm = Volt::test('profile.update-password-form');
        $this->assertStringNotContainsString($secret, json_encode($passwordForm->snapshot, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($recoveryCodes, json_encode($passwordForm->snapshot, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($secret, $passwordForm->html());
        $this->assertStringNotContainsString($recoveryCodes, $passwordForm->html());

        // 3. Rendered profile HTML does not leak decrypted or encrypted secrets
        $profileHtml = $this->get('/profile')->getContent();
        $this->assertStringNotContainsString($secret, $profileHtml);
        $this->assertStringNotContainsString($recoveryCodes, $profileHtml);
    }

    public function test_existing_confirmed_users_are_challenged_on_login(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()]);

        $this->postJson('/user/two-factor-authentication');
        $user->refresh();
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());

        auth()->logout();

        // Login attempt with confirmed 2FA redirects to two-factor challenge
        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->assertSame($user->id, session('login.id'));
    }
}
