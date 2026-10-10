<?php

namespace Tests\Feature\Auth;

use App\Actions\Company\CreateCompanyAction;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Phase10IdentityRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:sO7p2V7+eL5ZgVqfR6E2K6J8oXp+q8v4b2y1N5c4m9w=']);
    }

    public function test_valid_password_reset_changes_password_hash_and_remember_token_and_invalidates_old_password(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'password' => Hash::make('InitialPassword123!'),
            'remember_token' => 'initial-remember-token-'.Str::random(10),
        ]);

        $initialHash = $user->password;
        $initialRememberToken = $user->remember_token;

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink');

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user, $initialHash, $initialRememberToken) {
            $token = $notification->token;

            $component = Volt::test('pages.auth.reset-password', ['token' => $token])
                ->set('email', $user->email)
                ->set('password', 'NewSecurePassword456!')
                ->set('password_confirmation', 'NewSecurePassword456!');

            $component->call('resetPassword');

            $component
                ->assertRedirect('/login')
                ->assertHasNoErrors();

            $user->refresh();

            $this->assertNotSame($initialHash, $user->password);
            $this->assertNotSame($initialRememberToken, $user->remember_token);
            $this->assertNotEmpty($user->remember_token);
            $this->assertTrue(Hash::check('NewSecurePassword456!', $user->password));
            $this->assertFalse(Hash::check('InitialPassword123!', $user->password));

            return true;
        });
    }

    public function test_password_reset_token_cannot_be_bound_to_different_account(): void
    {
        Notification::fake();

        $userA = User::factory()->create([
            'email' => 'victim-a@example.com',
            'password' => Hash::make('VictimSecretPassA1!'),
        ]);

        $userB = User::factory()->create([
            'email' => 'attacker-b@example.com',
            'password' => Hash::make('AttackerSecretPassB1!'),
        ]);

        $initialHashA = $userA->password;
        $initialHashB = $userB->password;

        Volt::test('pages.auth.forgot-password')
            ->set('email', $userA->email)
            ->call('sendPasswordResetLink');

        Notification::assertSentTo($userA, ResetPassword::class, function ($notification) use ($userA, $userB, $initialHashA, $initialHashB) {
            $tokenForUserA = $notification->token;

            // Attempt to bind token issued for User A to User B's email
            $component = Volt::test('pages.auth.reset-password', ['token' => $tokenForUserA])
                ->set('email', $userB->email)
                ->set('password', 'HijackedPassword789!')
                ->set('password_confirmation', 'HijackedPassword789!');

            $component->call('resetPassword');

            $component->assertHasErrors(['email']);

            $userA->refresh();
            $userB->refresh();

            $this->assertSame($initialHashA, $userA->password);
            $this->assertSame($initialHashB, $userB->password);
            $this->assertFalse(Hash::check('HijackedPassword789!', $userA->password));
            $this->assertFalse(Hash::check('HijackedPassword789!', $userB->password));

            return true;
        });
    }

    public function test_password_reset_rejects_expired_token(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'password' => Hash::make('InitialPassword123!'),
        ]);
        $initialHash = $user->password;

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink');

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user, $initialHash) {
            $token = $notification->token;

            // Manually age the token record past the configured expiration window (60 minutes)
            DB::table('password_reset_tokens')
                ->where('email', $user->email)
                ->update(['created_at' => now()->subMinutes(61)]);

            $component = Volt::test('pages.auth.reset-password', ['token' => $token])
                ->set('email', $user->email)
                ->set('password', 'AttemptAfterExpiry999!')
                ->set('password_confirmation', 'AttemptAfterExpiry999!');

            $component->call('resetPassword');

            $component->assertHasErrors(['email']);

            $user->refresh();
            $this->assertSame($initialHash, $user->password);
            $this->assertFalse(Hash::check('AttemptAfterExpiry999!', $user->password));

            return true;
        });
    }

    public function test_password_reset_token_cannot_be_replayed_after_successful_reset(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'password' => Hash::make('InitialPassword123!'),
        ]);

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink');

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $token = $notification->token;

            // First legitimate reset
            $firstAttempt = Volt::test('pages.auth.reset-password', ['token' => $token])
                ->set('email', $user->email)
                ->set('password', 'LegitPasswordOne1!')
                ->set('password_confirmation', 'LegitPasswordOne1!');

            $firstAttempt->call('resetPassword');
            $firstAttempt->assertRedirect('/login')->assertHasNoErrors();

            $user->refresh();
            $this->assertTrue(Hash::check('LegitPasswordOne1!', $user->password));

            // Token must be consumed/removed from token repository
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

            // Replay attempt with same token
            $replayAttempt = Volt::test('pages.auth.reset-password', ['token' => $token])
                ->set('email', $user->email)
                ->set('password', 'ReplayedPasswordTwo2!')
                ->set('password_confirmation', 'ReplayedPasswordTwo2!');

            $replayAttempt->call('resetPassword');
            $replayAttempt->assertHasErrors(['email']);

            $user->refresh();
            $this->assertTrue(Hash::check('LegitPasswordOne1!', $user->password));
            $this->assertFalse(Hash::check('ReplayedPasswordTwo2!', $user->password));

            return true;
        });
    }

    public function test_password_broker_throttles_rapid_reset_link_requests(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        // 1. Initial valid request succeeds
        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink')
            ->assertHasNoErrors();

        Notification::assertSentToTimes($user, ResetPassword::class, 1);

        // 2. Immediate second request within 60-second throttle window is rejected
        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink')
            ->assertHasErrors(['email']);

        // Notification must NOT be dispatched again
        Notification::assertSentToTimes($user, ResetPassword::class, 1);

        // 3. Advancing time past the 60-second throttle window permits sending again
        $this->travel(61)->seconds();

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink')
            ->assertHasNoErrors();

        Notification::assertSentToTimes($user, ResetPassword::class, 2);
    }

    public function test_login_throttling_locks_out_after_maximum_failed_attempts(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('ValidAccountPassword1!'),
        ]);

        $ip = '198.51.100.42';
        $throttleKey = Str::transliterate(Str::lower($user->email).'|'.$ip);
        RateLimiter::clear($throttleKey);

        // First 5 attempts fail with credentials error
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('/login', [
                    'email' => $user->email,
                    'password' => 'wrong-pass-attempt-'.$i,
                ]);

            $response->assertSessionHasErrors('email');
            $this->assertGuest();
        }

        $this->assertTrue(RateLimiter::tooManyAttempts($throttleKey, 5));

        // 6th web attempt triggers route RateLimiter lockout returning HTTP 429
        $sixthResponse = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-pass-attempt-6',
            ]);

        $sixthResponse->assertStatus(429);
        $this->assertGuest();

        // JSON/API request to login also receives HTTP 429 Too Many Requests
        $jsonResponse = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/login', [
                'email' => $user->email,
                'password' => 'wrong-pass-attempt-7',
            ]);

        $jsonResponse->assertStatus(429);
        $this->assertGuest();

        RateLimiter::clear($throttleKey);
    }

    public function test_logout_session_invalidation_and_unauthenticated_state(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $this->assertAuthenticatedAs($user);

        // Start session and register dummy session state
        session()->start();
        $sessionId = session()->getId();
        session()->put('tenant_security_marker', 'marker-token-123');

        $response = $this->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();

        // Session must be invalidated and regenerated
        $newSessionId = session()->getId();
        $this->assertNotSame($sessionId, $newSessionId);
        $this->assertNull(session('tenant_security_marker'));

        // Subsequent access to protected endpoint is denied and redirected to login
        $protectedResponse = $this->get('/settings');
        $protectedResponse->assertRedirect('/login');
    }

    public function test_two_factor_challenge_rejects_invalid_recovery_code(): void
    {
        Event::fake([TwoFactorAuthenticationFailed::class]);

        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication');

        $user->refresh();
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        auth()->logout();

        $response = $this->withSession(['login.id' => $user->id])
            ->post('/two-factor-challenge', [
                'recovery_code' => 'invalid-recovery-code-xyz',
            ]);

        $response->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
        Event::assertDispatched(TwoFactorAuthenticationFailed::class);
    }

    public function test_two_factor_challenge_rejects_replayed_recovery_code(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication');

        $user->refresh();
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $recoveryCode = $user->recoveryCodes()[0];
        auth()->logout();

        // 1. First legitimate consumption of the recovery code
        $firstResponse = $this->withSession(['login.id' => $user->id])
            ->post('/two-factor-challenge', [
                'recovery_code' => $recoveryCode,
            ]);

        $firstResponse->assertRedirect('/settings');
        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $this->assertNotContains($recoveryCode, $user->recoveryCodes());

        // Logout
        auth()->logout();
        $this->assertGuest();

        // 2. Replay attempt using the consumed recovery code
        $replayResponse = $this->withSession(['login.id' => $user->id])
            ->post('/two-factor-challenge', [
                'recovery_code' => $recoveryCode,
            ]);

        $replayResponse->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_csrf_middleware_enforcement_and_unauthorized_company_switch(): void
    {
        // 1. Verify CSRF middleware behaves faithfully when not in unit-test bypass mode
        $encrypter = app('encrypter');
        $csrfMiddleware = new class(app(), $encrypter) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };

        // Simulated real POST request without CSRF token
        $forgedRequest = Request::create('/company/switch', 'POST', ['public_id' => 'comp_test123']);
        $session = app('session.store');
        $session->start();
        $session->put('_token', 'valid-server-token-999');
        $forgedRequest->setLaravelSession($session);

        $this->expectException(TokenMismatchException::class);
        $csrfMiddleware->handle($forgedRequest, fn () => response('ok'));
    }

    public function test_unauthorized_company_switch_aborts_with_forbidden_status(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $companyAction = app(CreateCompanyAction::class);
        $companyA = $companyAction->execute($userA, [
            'name_ar' => 'شركة أ',
            'name_en' => 'Company A',
            'base_currency_code' => 'ILS',
        ]);
        $companyB = $companyAction->execute($userB, [
            'name_ar' => 'شركة ب',
            'name_en' => 'Company B',
            'base_currency_code' => 'ILS',
        ]);

        // User A belongs to Company A, but NOT to Company B
        $this->actingAs($userA);

        $response = $this->post(route('company.switch'), [
            'public_id' => $companyB->public_id,
        ]);

        $response->assertForbidden();
    }
}
