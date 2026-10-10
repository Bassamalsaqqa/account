<?php

declare(strict_types=1);

namespace Tests\Feature\Phase10;

use App\Actions\Company\CreateCompanyAction;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class EnvironmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_debug_disabled_error_response_does_not_disclose_exception_details(): void
    {
        config(['app.debug' => false, 'logging.default' => 'null']);
        Route::middleware('web')->get('/phase10-fixture-error', static function (): never {
            throw new RuntimeException('P10_PRIVATE_EXCEPTION_SENTINEL database query and credential fixture');
        });

        foreach (['ar', 'en'] as $locale) {
            $response = $this->withSession(['locale' => $locale])->get('/phase10-fixture-error');
            $response->assertStatus(500);
            $response->assertDontSee('P10_PRIVATE_EXCEPTION_SENTINEL');
            $response->assertDontSee('EnvironmentSecurityTest.php');
            $response->assertDontSee('Stack trace');
            $this->assertNotSame('', $response->getContent());
        }
    }

    public function test_locale_switch_rejects_missing_token_when_real_csrf_guard_is_enabled(): void
    {
        $this->enableRequestForgeryProtection();
        $this->withSession(['locale' => 'ar', '_token' => 'phase10-local-token'])
            ->post('/locale', ['locale' => 'en'])->assertStatus(419);
        $this->assertSame('ar', session('locale'));
    }

    public function test_locale_switch_accepts_matching_csrf_token(): void
    {
        $this->enableRequestForgeryProtection();
        $this->withSession(['locale' => 'ar', '_token' => 'phase10-local-token'])
            ->post('/locale', ['locale' => 'en', '_token' => 'phase10-local-token'])->assertRedirect();
        $this->assertSame('en', session('locale'));
    }

    public function test_company_switch_without_csrf_token_cannot_change_active_company(): void
    {
        $user = User::factory()->create();
        $first = app(CreateCompanyAction::class)->execute($user, [
            'name_ar' => 'الشركة الأولى', 'name_en' => 'First fixture company', 'base_currency_code' => 'ILS',
        ]);
        $second = app(CreateCompanyAction::class)->execute($user, [
            'name_ar' => 'الشركة الثانية', 'name_en' => 'Second fixture company', 'base_currency_code' => 'ILS',
        ]);
        $user->update(['last_active_company_id' => $first->id]);
        $this->enableRequestForgeryProtection();
        $this->actingAs($user)->withSession(['_token' => 'phase10-local-token', 'active_company_id' => $first->id])
            ->post('/company/switch', ['public_id' => $second->public_id])->assertStatus(419)
            ->assertSessionHas('active_company_id', $first->id);
        $this->assertSame($first->id, $user->fresh()->last_active_company_id);
    }

    private function enableRequestForgeryProtection(): void
    {
        // Laravel bypasses CSRF under PHPUnit; enable the installed guard for these real HTTP routes.
        $this->app->bind(PreventRequestForgery::class, static fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }
}
