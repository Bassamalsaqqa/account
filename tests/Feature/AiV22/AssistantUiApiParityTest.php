<?php

declare(strict_types=1);

namespace Tests\Feature\AiV22;

use App\Livewire\AI\AssistantLauncher;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Phase11CsrfTestMiddleware;

/** Uses actual UI and guarded API adapters over the same synthetic canonical history. */
final class AssistantUiApiParityTest extends AccountingTurnAcceptanceTest
{
    /** @return array<string, array{string, string, string}> */
    public static function questions(): array
    {
        return [
            'Arabic named debt' => ['ar', 'قديش على أحمد دين؟', 'balance'],
            'English named debt' => ['en', "What's Ahmed's outstanding balance?", 'balance'],
            'Arabic comparison' => ['ar', 'قارن مبيعات هالشهر بالشهر الماضي.', 'comparison'],
            'English comparison' => ['en', 'Compare sales this month with last month.', 'comparison'],
            'Arabic deterministic stock' => ['ar', 'شو الأصناف اللي قربت تخلص؟', 'stock'],
            'English deterministic stock' => ['en', 'What items are running low?', 'stock'],
        ];
    }

    #[DataProvider('questions')]
    public function test_ui_api_parity_of_canonical_answers_model_calls_and_economic_fingerprints(string $locale, string $text, string $kind): void
    {
        app()->setLocale($locale);
        $this->owner->update(['locale' => $locale]);
        $proposal = match ($kind) {
            'balance' => ['report_key' => 'customers.balances', 'filters' => ['customer_name' => $locale === 'ar' ? 'أحمد' : 'Ahmed']],
            'comparison' => ['report_key' => 'sales.summary', 'filters' => ['preset' => 'this_month', 'compare' => 'previous_period']],
            default => ['report_key' => 'inventory.low-stock', 'filters' => (object) []],
        };
        $mock = $this->mockDeepSeekResponse([['id' => 'parity', 'name' => 'run_report', 'arguments' => [
            'version' => 1, 'type' => 'report_query', ...$proposal, 'display' => 'summary',
        ]]]);
        // Each simulated HTTP exchange owns a fresh stream; the boundary closes
        // the first response instead of allowing it to be reused by the API call.
        Http::fake(fn () => Http::response($mock));
        $before = $this->economicFingerprint();
        $component = Livewire::test(AssistantLauncher::class)->set('text', $text)->call('prepare')->assertNoRedirect();
        $uiReports = $component->get('turnReports');
        self::assertNotEmpty($uiReports);

        config(['session.driver' => 'database', 'commands.api_enabled' => false]);
        $this->withCredentials();
        $this->app->bind(PreventRequestForgery::class, Phase11CsrfTestMiddleware::class);
        $this->app->bind(ValidateCsrfToken::class, Phase11CsrfTestMiddleware::class);
        $session = $this->persistedSession();
        $response = $this->withCookie(config('session.cookie'), $session['id'])->withHeader('X-CSRF-TOKEN', $session['token'])
            ->postJson('/api/v1/companies/'.$this->company->id.'/assistant/turn',
                ['text' => $text, 'request_key' => (string) Str::uuid(), 'source' => 'typed']);
        self::assertSame(200, $response->status(), json_encode([$response->json(),
            DB::table('assistant_requests')->get(['status', 'failure_code', 'attempt_count'])->toArray()], JSON_THROW_ON_ERROR));
        $response->assertJsonPath('status', 'answer')->assertJsonPath('model_calls', $kind === 'stock' ? 0 : 1);
        self::assertSame($uiReports, $response->json('reports'));
        self::assertSame($before, $this->economicFingerprint());
        $response->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
        self::assertStringNotContainsString('_authorization_scope', $response->getContent());
        Http::assertSentCount($kind === 'stock' ? 0 : 2);
    }

    /** @return array{id: string, token: string} */
    private function persistedSession(): array
    {
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        auth()->forgetGuards();
        $id = Str::random(40);
        $token = Str::random(40);
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $this->owner->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'SyntheticParityBrowser', 'last_activity' => now()->timestamp,
            'payload' => base64_encode(serialize(['_token' => $token,
                auth()->guard('web')->getName() => $this->owner->id, 'password_hash_web' => $this->owner->getAuthPassword(),
                'active_company_id' => $this->company->id, 'phase11_session_started_at' => now()->timestamp]))]);

        return ['id' => $id, 'token' => $token];
    }

    /** @return array<string, string> */
    private function economicFingerprint(): array
    {
        $result = [];
        foreach (['sales_invoices', 'sales_invoice_lines', 'posting_batches', 'posting_lines', 'stock_movements',
            'purchases', 'purchase_lines', 'customer_payments', 'vendor_payments', 'customers', 'vendors', 'products'] as $table) {
            $result[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $result;
    }
}
