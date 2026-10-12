<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Livewire\AI\AssistantLauncher;
use App\Models\AiCompanyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class AssistantLauncherMountTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->salesFixtures();
        config(['ai_assistant.enabled' => false, 'ai_assistant.provider_approved' => false,
            'ai_assistant.provider' => 'none', 'ai_assistant.speech_enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_blade_alias_resolves_the_exact_case_sensitive_class_without_prior_class_mount(): void
    {
        $this->assertSame(AssistantLauncher::class, app('livewire.finder')->resolveClassComponentClassName('ai.assistant-launcher'));
        Livewire::test('ai.assistant-launcher')->assertDontSee(__('assistant_v2.open'));
        Http::assertNothingSent();
    }

    public function test_disabled_launcher_does_not_break_authenticated_ar_en_layout_and_both_forms(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $this->withSession(['locale' => $locale]);
            foreach (['/dashboard', '/invoices/create', '/purchases/create'] as $path) {
                $this->get($path)->assertOk()->assertDontSee(__('assistant_v2.open'));
            }
        }
        Http::assertNothingSent();
    }

    public function test_enabled_alias_mount_uses_controller_render_data_and_existing_consent_picker(): void
    {
        config(['ai_assistant.enabled' => true]);
        AiCompanyPolicy::create(['company_id' => $this->company->id, 'enabled' => true]);
        Livewire::test('ai.assistant-launcher')->assertSee(__('ai_product.launcher'))
            ->call('showAssistant')->assertSet('open', true)->assertSet('consentRoutes', [])
            ->assertSet('consent', false);
        Http::assertNothingSent();
    }
}
