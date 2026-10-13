<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Application\AI\AiGovernance;
use App\Application\AI\AiProviderRoutes;
use App\Application\AI\AiRequestBudget;
use App\Application\AI\AssistantUnavailable;
use App\Livewire\AI\AiSettings;
use App\Models\AuditEvent;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AiSettingsSecurityTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
    }

    public function test_settings_are_write_only_without_legacy_route_or_price_controls(): void
    {
        Livewire::test(AiSettings::class)->assertSee('DeepSeek AI')->assertSee(__('ai_product.status_verified'))
            ->assertDontSee('synthetic-primary-key-never-real')->assertDontSee('secret_ciphertext')
            ->assertDontSee('wire:model="pricing"', false)->assertDontSee('Provider connections');
        $this->assertStringNotContainsString('synthetic-primary-key', AuditEvent::all()->toJson());
        Http::assertNothingSent();
    }

    public function test_retired_credential_endpoint_does_not_flash_or_persist_secrets(): void
    {
        $secret = 'synthetic-never-flash-only';
        $this->post('/settings/ai/credentials', ['provider_secret' => $secret, 'password' => 'synthetic-configuration-only'])->assertNotFound();
        $this->assertStringNotContainsString($secret, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('synthetic-configuration-only', json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('ai_provider_connections', 1);
        Http::assertNothingSent();
    }

    public function test_delegated_settings_reader_cannot_replace_installation_owner_key(): void
    {
        $custodian = $this->member(['ai.settings.manage', 'ai.credentials.manage']);
        Livewire::actingAs($custodian)->test(AiSettings::class)->assertSee(__('ai_product.owner_managed'))->assertDontSee('DeepSeek API key');
        try {
            app(AiGovernance::class)->provision($this->company, $custodian,
                ['provider' => 'deepseek', 'model' => 'deepseek-flash', 'region' => 'global', 'capability' => 'text_structured', 'credential_source' => 'company_byok'],
                'synthetic-replacement-only', 'synthetic-custodian-only', $this->textConnection->public_id);
            $this->fail('Only Owner can replace the company connection.');
        } catch (AuthorizationException) {
            $this->assertSame(1, $this->textConnection->fresh()->revision);
        }
        Http::assertNothingSent();
    }

    public function test_admin_without_explicit_ai_delegation_cannot_open_settings(): void
    {
        Livewire::actingAs($this->member([]))->test(AiSettings::class)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_string_request_counts_are_normalized_and_enforced_without_resetting_usage(): void
    {
        $settings = $this->aiPolicy->settings;
        $settings['limits']['daily_company_requests'] = '2';
        $settings['limits']['daily_user_requests'] = '2';
        $policy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => true,
            'workflow_mode' => 'seamless', 'speech_method' => 'disabled', 'settings' => $settings,
        ], $this->aiPolicy->revision);
        $this->assertSame(2, $policy->settings['limits']['daily_company_requests']);
        $routes = app(AiProviderRoutes::class)->select($this->company, $this->owner, 'sales', 'text_structured');
        $budget = app(AiRequestBudget::class);
        foreach (range(1, 2) as $number) {
            $this->assertTrue($budget->reserve($this->company, $this->owner, (string) Str::uuid(), (string) Str::uuid(), 'intent', 'sales', hash('sha256', 'count-'.$number), $routes)['created']);
        }
        try {
            $budget->reserve($this->company, $this->owner, (string) Str::uuid(), (string) Str::uuid(), 'intent', 'sales', hash('sha256', 'third'), $routes);
            $this->fail('Saved counts must refuse a third reservation.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('budget', $e->reason);
        }
        $this->assertDatabaseCount('assistant_requests', 2);
        Http::assertNothingSent();
    }

    public function test_stale_revision_cannot_overwrite_company_policy(): void
    {
        $settings = $this->aiPolicy->settings;
        $revision = $this->aiPolicy->revision;
        $this->aiPolicy->increment('revision');
        try {
            app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
                'enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => true,
                'workflow_mode' => 'guided', 'speech_method' => 'disabled', 'settings' => $settings,
            ], $revision);
            $this->fail('A stale Owner cannot overwrite policy.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
            $this->assertSame('seamless', $this->aiPolicy->fresh()->workflow_mode);
        }
        Http::assertNothingSent();
    }

    /** @param list<string> $permissions */
    private function member(array $permissions): User
    {
        $member = User::factory()->create(['password' => Hash::make('synthetic-custodian-only')]);
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $member->id, 'status' => 'active', 'is_owner' => false]);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'AI Test Custodian', 'guard_name' => 'web']);
        $role->syncPermissions($permissions);
        $member->assignRole($role);
        $this->actingAs($member);
        app(CompanyContext::class)->setCompany($this->company, $member);

        return $member;
    }
}
