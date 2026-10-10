<?php

declare(strict_types=1);

namespace Tests\Feature\Phase10;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErrorPresentationTest extends TestCase
{
    private const EXCEPTION_CANARY = 'P10_PRIVATE_DENIAL_SENTINEL tenant credential fixture';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false, 'logging.default' => 'null']);
        Route::middleware('web')->get('/phase10-fixture-denied', static function (): never {
            abort(403, self::EXCEPTION_CANARY);
        });
        Route::middleware('web')->get('/phase10-fixture-denied-unsupported-locale', static function (): never {
            app()->setLocale('fr');
            abort(403, self::EXCEPTION_CANARY);
        });
    }

    #[DataProvider('supportedLocales')]
    public function test_denied_html_is_localized_accessible_and_does_not_disclose_exception_details(
        string $locale,
        string $direction,
        string $title,
        string $home,
        string $message
    ): void {
        $response = $this->withSession(['locale' => $locale])->get('/phase10-fixture-denied');

        $response->assertForbidden()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('lang="'.$locale.'"', false)
            ->assertSee('dir="'.$direction.'"', false)
            ->assertSee('<main aria-labelledby="error-title">', false)
            ->assertSee('<h1 id="error-title">'.$title.'</h1>', false)
            ->assertSee($message)
            ->assertSee('<a href="/">'.$home.'</a>', false)
            ->assertDontSee(self::EXCEPTION_CANARY)
            ->assertDontSee('Stack trace')
            ->assertDontSee('<script', false);
    }

    #[DataProvider('jsonLocales')]
    public function test_denied_json_remains_json_without_the_html_error_page(string $locale): void
    {
        $response = $this->withSession(['locale' => $locale])->getJson('/phase10-fixture-denied');

        $response->assertForbidden()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message'])
            ->assertDontSee('<html', false)
            ->assertDontSee('<main', false);
    }

    public function test_unsupported_active_locale_uses_safe_arabic_markup_and_copy(): void
    {
        $response = $this->get('/phase10-fixture-denied-unsupported-locale');

        $response->assertForbidden()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('غير مسموح بالوصول')
            ->assertSee('العودة إلى الرئيسية')
            ->assertDontSee(self::EXCEPTION_CANARY);
    }

    /** @return array<string, array{string}> */
    public static function jsonLocales(): array
    {
        return ['Arabic' => ['ar'], 'English' => ['en']];
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function supportedLocales(): array
    {
        return [
            'Arabic' => [
                'ar', 'rtl', 'غير مسموح بالوصول', 'العودة إلى الرئيسية',
                'ليس لديك صلاحية للوصول إلى هذه الصفحة. عُد إلى الرئيسية أو تواصل مع مسؤول شركتك إذا كنت تحتاج إلى الوصول.',
            ],
            'English' => [
                'en', 'ltr', 'Access denied', 'Return to home',
                'You do not have permission to access this page. Return to home or contact your company administrator if you need access.',
            ],
        ];
    }
}
