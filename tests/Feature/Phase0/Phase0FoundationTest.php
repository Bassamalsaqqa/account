<?php

namespace Tests\Feature\Phase0;

use App\Actions\Company\CreateCompanyAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Tests\TestCase;

class Phase0FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_boots_and_sign_in_page_loads(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('التاجر الصغير');
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->get('/settings')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/profile')->assertRedirect('/login');
    }

    public function test_public_registration_is_disabled_by_default(): void
    {
        $this->assertFalse(config('auth.registration_enabled'));

        $this->get('/register')->assertNotFound();
    }

    public function test_arabic_locale_renders_rtl_direction(): void
    {
        $response = $this->withSession(['locale' => 'ar'])->get('/login');

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('lang="ar"', false);
        $response->assertSee('التاجر الصغير');
        $response->assertSee('البريد الإلكتروني');
    }

    public function test_english_locale_renders_ltr_direction(): void
    {
        $response = $this->withSession(['locale' => 'en'])->get('/login');

        $response->assertOk();
        $response->assertSee('dir="ltr"', false);
        $response->assertSee('lang="en"', false);
        $response->assertSee('Small Trader');
    }

    public function test_locale_switcher_route_updates_session_for_guest(): void
    {
        $response = $this->post(route('locale.switch'), ['locale' => 'en']);

        $response->assertSessionHas('locale', 'en');
        $response->assertRedirect();

        $responseAr = $this->post(route('locale.switch'), ['locale' => 'ar']);
        $responseAr->assertSessionHas('locale', 'ar');
        $responseAr->assertRedirect();
    }

    public function test_locale_switcher_persists_for_authenticated_user(): void
    {
        $user = User::factory()->create(['locale' => 'ar']);

        $response = $this->actingAs($user)->post(route('locale.switch'), ['locale' => 'en']);

        $response->assertSessionHas('locale', 'en');
        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_locale_switcher_rejects_invalid_locale(): void
    {
        $response = $this->withSession(['locale' => 'ar'])
            ->post(route('locale.switch'), ['locale' => 'invalid']);

        $response->assertSessionHasErrors('locale');
        $this->assertSame('ar', session('locale'));
    }

    public function test_locale_get_request_does_not_mutate_state(): void
    {
        $response = $this->withSession(['locale' => 'ar'])->get('/locale');
        $response->assertStatus(405);
        $this->assertSame('ar', session('locale'));

        $responseOld = $this->withSession(['locale' => 'ar'])->get('/locale/en');
        $responseOld->assertNotFound();
        $this->assertSame('ar', session('locale'));
    }

    public function test_user_model_has_fortify_two_factor_authenticatable_trait(): void
    {
        $traits = class_uses_recursive(User::class);

        $this->assertContains(
            TwoFactorAuthenticatable::class,
            $traits,
            'User model must include Laravel\Fortify\TwoFactorAuthenticatable trait'
        );
    }

    public function test_authenticated_user_can_enable_and_disable_two_factor_authentication(): void
    {
        $user = User::factory()->create();

        // Enable 2FA via Fortify endpoint
        $response = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication');
        $response->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);

        // Can access recovery codes endpoint
        $recoveryResponse = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/user/two-factor-recovery-codes');
        $recoveryResponse->assertOk();
        $this->assertIsArray($recoveryResponse->json());

        // Can access QR code SVG endpoint
        $qrResponse = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/user/two-factor-qr-code');
        $qrResponse->assertOk();
        $this->assertArrayHasKey('svg', $qrResponse->json());

        // Confirm 2FA
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());

        // Disable 2FA via Fortify endpoint
        $deleteResponse = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete('/user/two-factor-authentication');
        $deleteResponse->assertRedirect();

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_user_with_two_factor_enabled_is_redirected_to_challenge_on_login(): void
    {
        $user = User::factory()->create([
            'email' => 'twofactor@example.com',
            'password' => bcrypt('password123'),
        ]);

        // Enable and confirm 2FA for user
        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication');

        $user->refresh();
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        auth()->logout();

        $response = $this->post('/login', [
            'email' => 'twofactor@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_two_factor_challenge_view_renders_when_user_is_challenged(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession(['login.id' => $user->id])
            ->get('/two-factor-challenge');

        $response->assertOk();
        $response->assertSee('two-factor-challenge');
    }

    public function test_two_factor_challenge_can_authenticate_with_recovery_code(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication');

        $user->refresh();
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $recoveryCode = $user->recoveryCodes()[0];
        auth()->logout();

        $response = $this->withSession(['login.id' => $user->id])
            ->post('/two-factor-challenge', [
                'recovery_code' => $recoveryCode,
            ]);

        $response->assertRedirect('/settings');
        $this->assertAuthenticatedAs($user);
    }

    public function test_authenticated_user_can_view_settings_visual_proof_screen(): void
    {
        $user = User::factory()->create([
            'name' => 'أحمد محمد',
            'email' => 'owner@example.com',
            'locale' => 'ar',
        ]);

        app(CreateCompanyAction::class)->execute($user, [
            'name_ar' => 'شركة التجارة الحديثة',
            'base_currency_code' => 'ILS',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['locale' => 'ar'])
            ->get('/settings');

        $response->assertOk();
        $response->assertSee('إعدادات النظام');
        $response->assertSee('الهوية والإعدادات العامة');
        $response->assertSee('المبيعات والمشتريات والمخزون');
        $response->assertSee('المستخدمون والأمان');
        $response->assertSee('المستندات والبيانات');
        $response->assertSee('ILS');
    }

    public function test_authenticated_user_can_view_settings_in_english_ltr(): void
    {
        $user = User::factory()->create([
            'name' => 'Ahmed Mohammed',
            'email' => 'owner.en@example.com',
            'locale' => 'en',
        ]);

        app(CreateCompanyAction::class)->execute($user, [
            'name_ar' => 'Modern Trade Co',
            'name_en' => 'Modern Trade Co',
            'base_currency_code' => 'USD',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['locale' => 'en'])
            ->get('/settings');

        $response->assertOk();
        $response->assertSee('dir="ltr"', false);
        $response->assertSee('System Settings');
        $response->assertSee('Identity & General Settings');
        $response->assertSee('Sales, Purchases & Inventory');
        $response->assertSee('Users & Security');
        $response->assertSee('Documents & Data');
    }

    public function test_dev_ui_specimen_is_accessible_in_local(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dev/ui');
        $response->assertOk();
        $response->assertSee('UI Specimen');
    }

    public function test_https_scheme_is_enforced_when_app_url_is_https(): void
    {
        config(['app.url' => 'https://account.palsync.net']);
        URL::forceScheme('https');

        $this->assertStringStartsWith('https://', url('/login'));
    }

    public function test_production_env_example_is_configured_for_hostinger_without_secrets(): void
    {
        $envPath = base_path('.env.production.example');
        $this->assertFileExists($envPath);

        $content = (string) file_get_contents($envPath);

        $this->assertStringContainsString('APP_URL=https://account.palsync.net', $content);
        $this->assertStringContainsString('REGISTRATION_ENABLED=false', $content);
        $this->assertStringContainsString('SESSION_DRIVER=database', $content);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $content);
        $this->assertStringContainsString('DB_HOST=127.0.0.1', $content);
        $this->assertStringContainsString('APP_KEY=', $content);

        // Verify database and secrets are strictly blank placeholders (not invented values)
        $this->assertMatchesRegularExpression('/^APP_KEY=\s*$/m', $content);
        $this->assertMatchesRegularExpression('/^DB_DATABASE=\s*$/m', $content);
        $this->assertMatchesRegularExpression('/^DB_USERNAME=\s*$/m', $content);
        $this->assertMatchesRegularExpression('/^DB_PASSWORD=\s*$/m', $content);

        // Verify mail configuration requires explicit SMTP setup for password resets
        $this->assertStringContainsString('MAIL_MAILER=smtp', $content);
        $this->assertMatchesRegularExpression('/^MAIL_HOST=\s*$/m', $content);
        $this->assertMatchesRegularExpression('/^MAIL_USERNAME=\s*$/m', $content);
        $this->assertMatchesRegularExpression('/^MAIL_PASSWORD=\s*$/m', $content);
    }

    public function test_public_index_supports_shared_hosting_app_path_resolution(): void
    {
        $indexPath = public_path('index.php');
        $this->assertFileExists($indexPath);

        $content = (string) file_get_contents($indexPath);
        $this->assertStringContainsString('LARAVEL_APP_PATH', $content);
        $this->assertStringContainsString('usePublicPath', $content);
        $this->assertStringContainsString('Configuration Error: Laravel application root not found', $content);
    }
}
