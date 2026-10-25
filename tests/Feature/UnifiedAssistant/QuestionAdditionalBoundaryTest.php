<?php

declare(strict_types=1);

namespace Tests\Feature\UnifiedAssistant;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantUnavailable;
use App\Livewire\AI\AiSettings;
use App\Models\AiUserPreference;
use App\Models\CompanyUser;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

final class QuestionAdditionalBoundaryTest extends TestCase
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
            'ai_assistant.provider_approved' => true,
            'ai_assistant.provider' => 'direct',
            'ai_assistant.speech_enabled' => true,
        ]);
        $this->questionPolicy();
        Http::preventStrayRequests();
    }

    // =========================================================================
    // 1. Existing AiSettings save AR/EN & Question Policy Validation
    // =========================================================================

    public function test_simple_settings_preserves_report_scopes_in_arabic_and_english(): void
    {
        $before = $this->aiPolicy->fresh()->settings['questions'];
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(AiSettings::class)->assertSee('DeepSeek AI')->assertDontSee('wire:model="allowedReports"', false);
            $this->assertSame($before, $this->aiPolicy->fresh()->settings['questions']);
        }
        Http::assertNothingSent();
    }

    public function test_ai_settings_management_authority_does_not_grant_report_reading(): void
    {
        $adminRole = Role::create([
            'company_id' => $this->company->id,
            'name' => 'AI Settings Admin Only',
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

        $this->actingAs($admin);
        app(CompanyContext::class)->setCompany($this->company, $admin);

        $this->expectException(AuthorizationException::class);
        app(AssistantReportCatalog::class)->authorize($this->company, $admin, 'sales.summary');
    }

    public function test_update_policy_rejects_associative_array_without_array_values_weakening(): void
    {
        $settings = $this->aiPolicy->fresh()->settings;
        $settings['questions'] = [
            'enabled' => true,
            'allowed_reports' => [
                'first' => 'sales.summary',
                'second' => 'purchases.summary',
            ],
        ];

        $current = $this->aiPolicy->fresh();

        $this->expectException(AssistantUnavailable::class);
        try {
            app(AiGovernance::class)->updatePolicy(
                $this->company,
                $this->owner,
                [
                    'enabled' => true,
                    'personal_credentials_enabled' => false,
                    'follow_up_enabled' => true,
                    'workflow_mode' => 'seamless',
                    'speech_method' => 'disabled',
                    'settings' => $settings,
                ],
                (int) $current->revision
            );
        } catch (AssistantUnavailable $e) {
            $this->assertSame('policy_invalid', $e->reason);
            throw $e;
        }
    }

    // =========================================================================
    // 2. Existing Actual Livewire Alias Report Result Refresh & State Boundary
    // =========================================================================

    public function test_livewire_report_result_cleared_on_role_downgrade_before_refresh(): void
    {
        $readerData = $this->reader();
        $role = $readerData['role'];

        $component = Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->set('text', 'sales today')
            ->call('prepare');

        $component->assertSet('reportResult.report_key', 'sales.summary');

        // Downgrade role mid-session before refresh: remove report viewing permission
        $role->syncPermissions(['ai.assistant.use']);

        $component->call('$refresh')
            ->assertSet('reportResult', null);

        Http::assertNothingSent();
    }

    public function test_livewire_report_result_cleared_on_membership_revocation_before_refresh(): void
    {
        $readerData = $this->reader();
        $reader = $readerData['user'];

        $component = Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->set('text', 'sales today')
            ->call('prepare');

        $component->assertSet('reportResult.report_key', 'sales.summary');

        // Revoke company membership before refresh
        CompanyUser::where('company_id', $this->company->id)
            ->where('user_id', $reader->id)
            ->update(['status' => 'inactive']);

        $component->call('$refresh')
            ->assertSet('reportResult', null)
            ->assertSet('available', false);

        Http::assertNothingSent();
    }

    public function test_livewire_report_result_cleared_on_company_switch_before_refresh(): void
    {
        // 1. Clear context and create second company with its own owner
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create();
        $companyB = app(CreateCompanyAction::class)->execute(
            $otherOwner,
            ['name_ar' => 'شركة ثانية', 'name_en' => 'Second Company', 'base_currency_code' => 'ILS']
        );
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        // 2. Setup reader with active memberships in both companies
        $readerData = $this->reader();
        $reader = $readerData['user'];
        CompanyUser::create([
            'company_id' => $companyB->id,
            'user_id' => $reader->id,
            'status' => 'active',
            'is_owner' => false,
        ]);

        // 3. Authenticate and set context to first company
        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);

        $component = Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->set('text', 'sales today')
            ->call('prepare');

        $component->assertSet('reportResult.report_key', 'sales.summary');

        // 4. Legitimate company switch via CompanyContext
        app(CompanyContext::class)->setCompany($companyB, $reader);

        // 5. Refresh clears old company's report result
        $component->call('$refresh')
            ->assertSet('reportResult', null);

        Http::assertNothingSent();
    }

    public function test_livewire_report_result_cleared_when_questions_disabled_in_policy(): void
    {
        $readerData = $this->reader();
        $reader = $readerData['user'];

        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);

        $component = Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->set('text', 'sales today')
            ->call('prepare');

        $component->assertSet('reportResult.report_key', 'sales.summary');

        // Authenticate Owner first to legitimately update policy
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $settings = $this->aiPolicy->fresh()->settings;
        $settings['questions'] = ['enabled' => false, 'allowed_reports' => []];
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => true,
            'personal_credentials_enabled' => false,
            'follow_up_enabled' => true,
            'workflow_mode' => 'seamless',
            'speech_method' => 'disabled',
            'settings' => $settings,
        ], (int) $this->aiPolicy->fresh()->revision);

        // Reauthenticate reader and refresh retained component
        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);

        $component->call('$refresh')
            ->assertSet('reportResult', null);

        Http::assertNothingSent();
    }

    // =========================================================================
    // 3. Consent Scoping & Route Change / Revocation
    // =========================================================================

    public function test_legacy_transaction_accept_action_does_not_fabricate_acceptance(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $before = AiUserPreference::query()->get()->map->getRawOriginal()->all();
        Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->set('consent', true)
            ->call('acceptConsent');

        $this->assertSame($before, AiUserPreference::query()->get()->map->getRawOriginal()->all());
        Http::assertNothingSent();
    }

    public function test_legacy_question_accept_action_does_not_record_acceptance_and_revocation_still_blocks_dispatch(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $before = AiUserPreference::query()->get()->map->getRawOriginal()->all();
        $component = Livewire::test('ai.assistant-launcher')
            ->call('showAssistant');

        $this->assertSame([], $component->get('questionConsentRoutes'));
        $component->set('questionConsent', true)->call('acceptQuestionConsent');
        $this->assertSame($before, AiUserPreference::query()->get()->map->getRawOriginal()->all());

        // Revoking selected route cannot implicitly grant dispatch
        app(AiGovernance::class)->revoke($this->company, $this->owner, $this->textConnection->public_id, 'synthetic-configuration-only');

        $this->expectException(AssistantUnavailable::class);
        app(AiGovernance::class)->dispatchConnection(
            $this->company,
            $this->owner,
            $this->textConnection->public_id,
            'question',
            'text_structured'
        );
    }

    // =========================================================================
    // 4. Report-Only Speech HTTP Fake & Role Demotion / Revocation
    // =========================================================================

    public function test_explicit_sales_launcher_context_preserves_existing_flow_when_questions_are_enabled(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        Livewire::test('ai.assistant-launcher', ['context' => 'sales'])
            ->call('showAssistant')
            ->set('text', 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS')
            ->call('prepare')
            ->assertRedirect();

        $this->assertNoEconomicWrites();
    }

    public function test_explicit_purchase_launcher_context_preserves_existing_flow_when_questions_are_enabled(): void
    {
        $this->aiProduct->update(['product_type' => 'stock', 'track_stock' => true, 'track_expiry' => false]);
        $this->aiCarton->update(['is_default_purchase' => true]);
        Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'المورد',
            'name_en' => 'Vendor',
            'code' => 'V-VENDOR',
            'created_by' => $this->owner->id,
        ]);

        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        Livewire::test('ai.assistant-launcher', ['context' => 'purchase'])
            ->call('showAssistant')
            ->set('text', 'buy from "Vendor": 10 "carton" "SOAP-3L" at 50 ILS')
            ->call('prepare')
            ->assertRedirect();

        $this->assertNoEconomicWrites();
    }

    public function test_purchases_report_authorization_requires_actual_purchasing_permissions(): void
    {
        $unauthorizedUser = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $unauthorizedUser->id,
            'status' => 'active',
            'is_owner' => false,
        ]);

        $this->actingAs($unauthorizedUser);
        app(CompanyContext::class)->setCompany($this->company, $unauthorizedUser);

        $this->expectException(AuthorizationException::class);
        app(AssistantReportCatalog::class)->authorize($this->company, $unauthorizedUser, 'purchases.summary');
    }

    public function test_report_only_transcript_and_typed_question_deliver_the_same_canonical_result(): void
    {
        $this->reader();
        config(['ai_assistant.provider_approved' => false]);
        $typed = Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->set('text', 'sales this month USD')
            ->call('prepare')
            ->assertSet('reportResult.report_key', 'sales.summary');
        $spoken = Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->call('receiveTranscript', 'sales this month USD', null)->call('prepare')
            ->assertSet('reportResult.report_key', 'sales.summary');
        foreach (['report_key', 'totals', 'rows', 'period', 'currency', 'source_url'] as $field) {
            $this->assertSame($typed->get('reportResult.'.$field), $spoken->get('reportResult.'.$field));
        }
        Http::assertNothingSent();
        $this->assertNoEconomicWrites();
    }

    public function test_transcript_is_editable_and_does_not_dispatch_until_explicit_send(): void
    {
        $text = 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';
        $component = Livewire::test('ai.assistant-launcher')->call('showAssistant')
            ->call('receiveTranscript', $text, null)->assertSet('text', $text)->assertNoRedirect();
        $this->assertDatabaseCount('assistant_requests', 0);
        $component->call('prepare')->assertRedirect();
        Http::assertNothingSent();
        $this->assertNoEconomicWrites();
    }

    private function questionPolicy(?array $settings = null): void
    {
        $settings ??= $this->aiPolicy->fresh()->settings;
        $settings['questions'] = ['enabled' => true, 'allowed_reports' => AssistantReportCatalog::KEYS];
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => true,
            'personal_credentials_enabled' => false,
            'follow_up_enabled' => true,
            'workflow_mode' => 'seamless',
            'speech_method' => 'disabled',
            'settings' => $settings,
        ], (int) $this->aiPolicy->fresh()->revision);
    }

    /**
     * @return array{user: User, role: Role}
     */
    private function reader(): array
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
            'name' => 'Question-only test reader '.$reader->id,
            'guard_name' => 'web',
        ]);
        $role->syncPermissions(['ai.assistant.use', 'reports.sales.view', 'sales.invoice.view']);
        $reader->assignRole($role);
        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);
        $this->assertFalse($reader->can('sales.invoice.create'));

        return ['user' => $reader, 'role' => $role];
    }

    private function assertNoEconomicWrites(): void
    {
        foreach (['sales_invoices', 'purchases', 'posting_batches', 'stock_movements'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }
}
