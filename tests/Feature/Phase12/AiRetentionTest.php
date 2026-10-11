<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiRetention;
use App\Models\AiCompanyPolicy;
use App\Models\AssistantRequest;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AiRetentionTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
    }

    public function test_own_company_retention_clears_only_expired_content_and_preserves_usage_and_provenance(): void
    {
        $this->aiPolicy->update(['settings' => [...$this->aiPolicy->settings, 'retention_days' => 1]]);
        $expired = $this->record('accepted', 2);
        $recent = $this->record('accepted', 0);
        app(CompanyContext::class)->clear();
        $control = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Synthetic retention control']);
        app(CompanyContext::class)->setCompany($control, $this->owner);
        AiCompanyPolicy::create(['company_id' => $control->id, 'settings' => ['retention_days' => 30]]);
        $longer = $this->record('accepted', 2);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $result = app(AiRetention::class)->prune();
        $this->assertSame(['expired' => 0, 'cleared' => 1], $result);
        $expired->refresh();
        $this->assertNull($expired->approved_command);
        $this->assertSame('accepted', $expired->status);
        $this->assertSame(hash('sha256', 'synthetic approved fields'), $expired->approved_hash);
        $this->assertSame(28, $expired->reserved_cost_micros);
        $this->assertSame(10, $expired->reported_cost_micros);
        $this->assertSame(['notes' => 'Synthetic private human fields'], $recent->fresh()->approved_command);
        app(CompanyContext::class)->setCompany($control, $this->owner);
        $this->assertSame(['notes' => 'Synthetic private human fields'], $longer->fresh()->approved_command);
        $this->assertSame(3, DB::table('assistant_requests')->count());
        $this->assertSame(0, DB::table('posting_batches')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_prune_expires_only_old_open_states_and_removes_their_temporary_locators(): void
    {
        $records = [];
        foreach (['pending', 'form_ready', 'clarifying', 'transcribed', 'form_edited'] as $status) {
            $request = $this->record($status, 0);
            $request->forceFill(['created_at' => now()->subSeconds(601)])->save();
            $locator = 'ai-request-state:'.$request->company_id.':'.$request->user_id.':'.$request->id;
            $token = (string) Str::uuid();
            Cache::put($locator, $token, 1000);
            Cache::put('ai-form-state:'.hash('sha256', $token), 'synthetic-expired-ciphertext', 1000);
            $records[] = [$request, $locator, $token];
        }
        $accepted = $this->record('accepted', 0);
        $recent = $this->record('form_ready', 0);
        $this->artisan('ai-assistant:prune')->assertSuccessful();
        foreach ($records as [$request, $locator, $token]) {
            $this->assertSame('expired', $request->fresh()->status);
            $this->assertSame(28, $request->fresh()->reserved_cost_micros);
            $this->assertFalse(Cache::has($locator));
            $this->assertFalse(Cache::has('ai-form-state:'.hash('sha256', $token)));
        }
        $this->assertSame('accepted', $accepted->fresh()->status);
        $this->assertSame('form_ready', $recent->fresh()->status);
        $this->assertSame(7, AssistantRequest::count());
        $this->assertSame(0, DB::table('sales_invoices')->count());
        $this->assertSame(0, DB::table('purchases')->count());
    }

    private function record(string $status, int $daysAgo): AssistantRequest
    {
        $request = AssistantRequest::create(['company_id' => app(CompanyContext::class)->companyId(),
            'user_id' => $this->owner->id, 'request_key' => (string) Str::uuid(), 'purpose' => 'intent',
            'input_hash' => hash('sha256', (string) Str::uuid()), 'provider' => 'synthetic', 'status' => $status,
            'reserved_cost_micros' => 28, 'reported_cost_micros' => 10,
            'approved_hash' => hash('sha256', 'synthetic approved fields'),
            'approved_command' => ['notes' => 'Synthetic private human fields']]);
        $request->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $request;
    }
}
