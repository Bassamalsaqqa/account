<?php

declare(strict_types=1);

namespace Tests\Feature\UnifiedAssistant;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantReportExecutor;
use App\Application\AI\AssistantReportPlan;
use App\Application\AI\AssistantUnavailable;
use App\Models\AiCompanyPolicy;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class QuestionGovernanceUiTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        config([
            'ai_assistant.enabled' => true,
            'ai_assistant.questions.enabled' => true,
            'ai_assistant.provider_approved' => false,
        ]);
        Http::preventStrayRequests();
    }

    public function test_policy_update_accepts_valid_questions_configuration(): void
    {
        $input = $this->validPolicyInput();
        $input['settings']['questions'] = [
            'enabled' => true,
            'allowed_reports' => AssistantReportCatalog::KEYS,
        ];

        $policy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, $input);

        $this->assertTrue($policy->settings['questions']['enabled']);
        $this->assertSame(AssistantReportCatalog::KEYS, $policy->settings['questions']['allowed_reports']);
        $this->assertSame(2, $policy->revision);
    }

    public function test_policy_update_defaults_questions_disabled_when_omitted(): void
    {
        $input = $this->validPolicyInput();
        unset($input['settings']['questions']);

        $policy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, $input);

        $this->assertFalse($policy->settings['questions']['enabled']);
        $this->assertSame([], $policy->settings['questions']['allowed_reports']);
    }

    public function test_policy_update_rejects_non_boolean_questions_enabled(): void
    {
        $input = $this->validPolicyInput();
        $input['settings']['questions'] = [
            'enabled' => 'true',
            'allowed_reports' => ['sales.summary'],
        ];

        $this->expectException(AssistantUnavailable::class);
        try {
            app(AiGovernance::class)->updatePolicy($this->company, $this->owner, $input);
        } catch (AssistantUnavailable $e) {
            $this->assertSame('policy_invalid', $e->reason);
            throw $e;
        }
    }

    public function test_policy_update_rejects_unknown_report_keys(): void
    {
        $input = $this->validPolicyInput();
        $input['settings']['questions'] = [
            'enabled' => true,
            'allowed_reports' => ['sales.summary', 'secret.financials'],
        ];

        $this->expectException(AssistantUnavailable::class);
        try {
            app(AiGovernance::class)->updatePolicy($this->company, $this->owner, $input);
        } catch (AssistantUnavailable $e) {
            $this->assertSame('policy_invalid', $e->reason);
            throw $e;
        }
    }

    public function test_policy_update_rejects_duplicate_report_keys(): void
    {
        $input = $this->validPolicyInput();
        $input['settings']['questions'] = [
            'enabled' => true,
            'allowed_reports' => ['sales.summary', 'sales.summary'],
        ];

        $this->expectException(AssistantUnavailable::class);
        try {
            app(AiGovernance::class)->updatePolicy($this->company, $this->owner, $input);
        } catch (AssistantUnavailable $e) {
            $this->assertSame('policy_invalid', $e->reason);
            throw $e;
        }
    }

    public function test_policy_update_rejects_extra_unrecognized_keys_in_questions(): void
    {
        $input = $this->validPolicyInput();
        $input['settings']['questions'] = [
            'enabled' => true,
            'allowed_reports' => ['sales.summary'],
            'extra_unrecognized_field' => true,
        ];

        $this->expectException(AssistantUnavailable::class);
        try {
            app(AiGovernance::class)->updatePolicy($this->company, $this->owner, $input);
        } catch (AssistantUnavailable $e) {
            $this->assertSame('policy_invalid', $e->reason);
            throw $e;
        }
    }

    public function test_policy_update_rejects_non_list_allowed_reports(): void
    {
        $input = $this->validPolicyInput();
        $input['settings']['questions'] = [
            'enabled' => true,
            'allowed_reports' => ['key1' => 'sales.summary'],
        ];

        $this->expectException(AssistantUnavailable::class);
        try {
            app(AiGovernance::class)->updatePolicy($this->company, $this->owner, $input);
        } catch (AssistantUnavailable $e) {
            $this->assertSame('policy_invalid', $e->reason);
            throw $e;
        }
    }

    public function test_policy_update_enforces_revision_concurrency(): void
    {
        $input = $this->validPolicyInput();

        $this->expectException(AssistantUnavailable::class);
        try {
            app(AiGovernance::class)->updatePolicy($this->company, $this->owner, $input, expectedRevision: 999);
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
            throw $e;
        }
    }

    public function test_non_owner_administrator_cannot_toggle_ai_enablement(): void
    {
        $adminRole = Role::create([
            'company_id' => $this->company->id,
            'name' => 'AI Settings Admin',
            'guard_name' => 'web',
        ]);
        $adminRole->syncPermissions(['ai.settings.manage']);

        $admin = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $admin->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        $admin->assignRole($adminRole);

        $input = $this->validPolicyInput();
        $input['enabled'] = false;

        $this->expectException(AuthorizationException::class);
        app(AiGovernance::class)->updatePolicy($this->company, $admin, $input);
    }

    public function test_report_only_staff_renders_nonblocking_disclosure_and_no_tx_rights(): void
    {
        $this->enableQuestionsPolicy();
        $reader = $this->createReportReader();

        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);

        $component = Livewire::test('ai.assistant-launcher')
            ->assertStatus(200)
            ->call('showAssistant')
            ->assertSet('open', true)
            ->assertSee(__('assistant_v2.privacy_policy'))
            ->assertDontSee(__('assistant_v2.question_consent'))
            ->assertDontSeeHtml('wire:click="acceptQuestionConsent"')
            ->assertSet('context', 'auto');

        $this->assertFalse($reader->hasPermissionTo('sales.invoice.create'));
        $this->assertFalse($reader->hasPermissionTo('purchasing.purchase.create'));
    }

    public function test_report_only_staff_can_ask_deterministic_question(): void
    {
        $this->enableQuestionsPolicy();
        $reader = $this->createReportReader();

        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);

        Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->set('text', 'sales today')
            ->call('prepare')
            ->assertSet('error', null)
            ->assertSet('reportResult.report_key', 'sales.summary')
            ->assertSee(__('assistant_v2.view_full_report'));
    }

    public function test_report_only_staff_asking_transaction_returns_permission_error(): void
    {
        $this->enableQuestionsPolicy();
        $reader = $this->createReportReader();

        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);

        Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->set('text', 'sell to Ahmed 5 carton SOAP-3L at 80 ILS')
            ->call('prepare')
            ->assertSet('error', __('assistant_v2.tx_permission_required'))
            ->assertSet('reportResult', null);
    }

    public function test_launcher_renders_in_arabic_and_english(): void
    {
        $this->enableQuestionsPolicy();
        $reader = $this->createReportReader();

        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);

        app()->setLocale('ar');
        Livewire::test('ai.assistant-launcher')
            ->assertSee(__('ai_product.launcher'))
            ->call('showAssistant')
            ->assertSee(__('ai_product.title'));

        app()->setLocale('en');
        Livewire::test('ai.assistant-launcher')
            ->assertSee(__('ai_product.launcher'))
            ->call('showAssistant')
            ->assertSee(__('ai_product.title'));
    }

    public function test_ambiguous_operation_presents_explicit_user_choice_for_full_user(): void
    {
        $this->enableQuestionsPolicy();

        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->set('text', 'sell to Ahmed and show sales summary today')
            ->call('prepare')
            ->assertSee(__('assistant_v2.operation_ambiguous'))
            ->assertSee(__('assistant_v2.op_draft'))
            ->assertSee(__('assistant_v2.op_report'))
            ->call('chooseOperation', 'report')
            ->assertSet('reportResult', null)
            ->assertSee(__('assistant_v2.error_question_invalid'));
    }

    public function test_membership_revocation_blocks_launcher(): void
    {
        $this->enableQuestionsPolicy();
        $reader = $this->createReportReader();

        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);
        $component = Livewire::test('ai.assistant-launcher')->call('showAssistant');
        CompanyUser::where('company_id', $this->company->id)
            ->where('user_id', $reader->id)
            ->update(['status' => 'inactive']);

        $component->call('$refresh')
            ->assertDontSee(__('assistant_v2.open'));
    }

    public function test_foreign_company_isolation(): void
    {
        $this->enableQuestionsPolicy();
        $reader = $this->createReportReader();

        app(CompanyContext::class)->clear();
        $foreignCompany = app(CreateCompanyAction::class)->execute(
            User::factory()->create(),
            ['name_ar' => 'شركة أجنبية', 'base_currency_code' => 'ILS']
        );

        $this->actingAs($reader);
        $this->expectException(AuthorizationException::class);
        app(CompanyContext::class)->setCompany($foreignCompany, $reader);
    }

    public function test_purchasing_report_denied_without_cost_view_permission(): void
    {
        $this->enableQuestionsPolicy();
        $reader = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $reader->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        $role = Role::create([
            'company_id' => $this->company->id,
            'name' => 'Purchases Viewer No Cost '.$reader->id,
            'guard_name' => 'web',
        ]);
        $role->syncPermissions(['ai.assistant.use', 'reports.purchases.view', 'purchasing.purchase.view']);
        $reader->assignRole($role);

        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);

        $plan = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => 'purchases.summary',
            'filters' => ['preset' => 'today'],
            'display' => 'summary',
        ]);

        $this->expectException(AuthorizationException::class);
        app(AssistantReportExecutor::class)->execute($this->company, $reader, $plan);
    }

    private function enableQuestionsPolicy(array $allowedReports = AssistantReportCatalog::KEYS): AiCompanyPolicy
    {
        $input = $this->validPolicyInput();
        $input['settings']['questions'] = [
            'enabled' => true,
            'allowed_reports' => $allowedReports,
        ];

        return app(AiGovernance::class)->updatePolicy($this->company, $this->owner, $input);
    }

    private function createReportReader(): User
    {
        $reader = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $reader->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        $role = Role::create([
            'company_id' => $this->company->id,
            'name' => 'Report Reader '.$reader->id,
            'guard_name' => 'web',
        ]);
        $role->syncPermissions(['ai.assistant.use', 'reports.sales.view', 'sales.invoice.view']);
        $reader->assignRole($role);

        return $reader;
    }

    private function validPolicyInput(): array
    {
        $pricing = [];
        foreach ([$this->textConnection] as $connection) {
            $pricing[$connection->public_id] = [
                'reference' => 'https://example.test/synthetic-pricing',
                'checked_at' => '2026-10-11',
                'input_micros_per_million' => 1000,
                'output_micros_per_million' => 2000,
                'audio_micros_per_minute' => 6000,
            ];
        }

        return [
            'enabled' => true,
            'personal_credentials_enabled' => false,
            'follow_up_enabled' => true,
            'workflow_mode' => 'seamless',
            'speech_method' => 'disabled',
            'settings' => [
                'allowed_connections' => [$this->textConnection->public_id],
                'text_connection' => $this->textConnection->public_id,
                'speech_connection' => null,
                'fallback' => [
                    'enabled' => false,
                    'text_connection' => null,
                    'speech_connection' => null,
                ],
                'privacy' => [
                    'operator' => 'Synthetic fixture',
                    'retention' => 'No real dispatch',
                    'policy_url' => 'https://example.test/privacy',
                ],
                'pricing' => $pricing,
                'limits' => [
                    'request_micros' => 1000,
                    'daily_company_micros' => 100000,
                    'monthly_company_micros' => 1000000,
                    'daily_user_micros' => 100000,
                    'monthly_user_micros' => 1000000,
                    'daily_company_requests' => 100,
                    'daily_user_requests' => 25,
                ],
                'retention_days' => 30,
            ],
        ];
    }
}
