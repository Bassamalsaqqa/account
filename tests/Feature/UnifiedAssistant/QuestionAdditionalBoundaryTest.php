<?php

declare(strict_types=1);

namespace Tests\Feature\UnifiedAssistant;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantUnavailable;
use App\Livewire\AI\AiSettings;
use App\Models\AiProviderConnection;
use App\Models\AiUserPreference;
use App\Models\AssistantRequest;
use App\Models\CompanyUser;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

    public function test_ai_settings_save_in_arabic_and_english_persists_questions_policy(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        // Arabic locale test
        app()->setLocale('ar');
        Livewire::test(AiSettings::class)
            ->set('questionsEnabled', true)
            ->set('allowedReports', AssistantReportCatalog::KEYS)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', __('assistant_v2.policy_saved'));

        $policyAr = $this->aiPolicy->fresh();
        $this->assertTrue((bool) ($policyAr->settings['questions']['enabled'] ?? false));
        $this->assertSame(AssistantReportCatalog::KEYS, $policyAr->settings['questions']['allowed_reports']);

        // English locale test
        app()->setLocale('en');
        Livewire::test(AiSettings::class)
            ->set('questionsEnabled', true)
            ->set('allowedReports', AssistantReportCatalog::KEYS)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', __('assistant_v2.policy_saved'));

        $policyEn = $this->aiPolicy->fresh();
        $this->assertTrue((bool) ($policyEn->settings['questions']['enabled'] ?? false));
        $this->assertSame(AssistantReportCatalog::KEYS, $policyEn->settings['questions']['allowed_reports']);
    }

    public function test_ai_settings_save_rejects_unknown_duplicate_and_associative_reports(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        // Unknown report key
        Livewire::test(AiSettings::class)
            ->set('questionsEnabled', true)
            ->set('allowedReports', ['unknown.unregistered.report'])
            ->call('save')
            ->assertHasErrors(['policy'])
            ->assertSet('message', null);

        // Duplicate report keys
        Livewire::test(AiSettings::class)
            ->set('questionsEnabled', true)
            ->set('allowedReports', ['sales.summary', 'sales.summary'])
            ->call('save')
            ->assertHasErrors(['policy'])
            ->assertSet('message', null);

        // Associative array rejection through AiSettings save
        Livewire::test(AiSettings::class)
            ->set('questionsEnabled', true)
            ->set('allowedReports', ['first' => 'sales.summary', 'second' => 'purchases.summary'])
            ->call('save')
            ->assertHasErrors(['policy'])
            ->assertSet('message', null);
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
                    'speech_method' => 'cloud',
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
            'speech_method' => 'cloud',
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

    public function test_accept_consent_for_transactions_does_not_create_question_consent_entries(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->set('consent', true)
            ->call('acceptConsent');

        $preferences = AiUserPreference::where('company_id', $this->company->id)
            ->where('user_id', $this->owner->id)
            ->firstOrFail();

        $consents = $preferences->consents ?? [];
        $this->assertNotEmpty($consents);

        $questionConsents = collect(array_keys($consents))
            ->filter(fn (string $key): bool => str_starts_with($key, 'question:'));

        $this->assertEmpty($questionConsents);
        Http::assertNothingSent();
    }

    public function test_accept_question_consent_scopes_only_question_routes_and_revocation_prevents_implicit_grant(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $component = Livewire::test('ai.assistant-launcher')
            ->call('showAssistant');

        // Assert selected question routes include both primary and approved fallback
        $routes = $component->get('questionConsentRoutes');
        $this->assertCount(2, $routes);
        $this->assertSame($this->textConnection->public_id, $routes[0]['public_id']);
        $this->assertSame('primary', $routes[0]['role']);
        $this->assertSame($this->fallbackConnection->public_id, $routes[1]['public_id']);
        $this->assertSame('fallback', $routes[1]['role']);

        $component->set('questionConsent', true)
            ->call('acceptQuestionConsent');

        $preferences = AiUserPreference::where('company_id', $this->company->id)
            ->where('user_id', $this->owner->id)
            ->firstOrFail();

        $consents = $preferences->consents ?? [];
        $this->assertArrayHasKey('question:'.$this->textConnection->public_id, $consents);
        $this->assertArrayHasKey('question:'.$this->fallbackConnection->public_id, $consents);

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

    public function test_report_only_speech_refuses_transcript_and_cleans_upload_on_role_demotion_during_fake(): void
    {
        $speech = $this->speechConnection();
        $readerData = $this->reader();
        $reader = $readerData['user'];
        $role = $readerData['role'];

        app(AiGovernance::class)->recordConsent($this->company, $reader, $speech->public_id,
            app(AiGovernance::class)->consentFingerprint($this->aiPolicy, $speech, 'question'), 'question');

        $pref = AiUserPreference::firstOrNew(['company_id' => $this->company->id, 'user_id' => $reader->id]);
        $pref->preferences = array_merge($pref->preferences ?? [], ['speech_connection' => $speech->public_id]);
        $pref->save();

        Http::fake(function () use ($role) {
            $role->syncPermissions([]); // Demote role during provider request

            return Http::response(['text' => 'sales summary this month']);
        });

        $file = UploadedFile::fake()->createWithContent('question.wav', $this->wav());
        $this->postJson(route('assistant.audio'), [
            'audio' => $file,
            'context' => 'question',
            'form_key' => (string) Str::uuid(),
            'request_key' => (string) Str::uuid(),
            'locale' => 'en',
            'connection_id' => $speech->public_id,
        ])->assertForbidden()->assertJsonMissingPath('text');

        $this->assertFileDoesNotExist($file->getPathname());
        $this->assertSame('failed', AssistantRequest::sole()->status);
        Http::assertSentCount(1);
        $this->assertNoEconomicWrites();
    }

    public function test_report_only_speech_refuses_transcript_and_cleans_upload_on_membership_revocation_during_fake(): void
    {
        $speech = $this->speechConnection();
        $readerData = $this->reader();
        $reader = $readerData['user'];

        app(AiGovernance::class)->recordConsent($this->company, $reader, $speech->public_id,
            app(AiGovernance::class)->consentFingerprint($this->aiPolicy, $speech, 'question'), 'question');

        $pref = AiUserPreference::firstOrNew(['company_id' => $this->company->id, 'user_id' => $reader->id]);
        $pref->preferences = array_merge($pref->preferences ?? [], ['speech_connection' => $speech->public_id]);
        $pref->save();

        Http::fake(function () use ($reader) {
            CompanyUser::where('company_id', $this->company->id)->where('user_id', $reader->id)->update(['status' => 'inactive']);

            return Http::response(['text' => 'sales summary this month']);
        });

        $file = UploadedFile::fake()->createWithContent('question.wav', $this->wav());
        $this->postJson(route('assistant.audio'), [
            'audio' => $file,
            'context' => 'question',
            'form_key' => (string) Str::uuid(),
            'request_key' => (string) Str::uuid(),
            'locale' => 'en',
            'connection_id' => $speech->public_id,
        ])->assertForbidden()->assertJsonMissingPath('text');

        $this->assertFileDoesNotExist($file->getPathname());
        $this->assertSame('failed', AssistantRequest::sole()->status);
        Http::assertSentCount(1);
        $this->assertNoEconomicWrites();
    }

    // =========================================================================
    // 5. Transaction Sales/Purchase Explicit Launcher Contexts with Questions Enabled
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
            ->assertSet('audioContext', 'question')
            ->call('receiveTranscript', 'sales this month USD', null)
            ->assertSet('reportResult.report_key', 'sales.summary');
        foreach (['report_key', 'totals', 'rows', 'period', 'currency', 'source_url'] as $field) {
            $this->assertSame($typed->get('reportResult.'.$field), $spoken->get('reportResult.'.$field));
        }
        Http::assertNothingSent();
        $this->assertNoEconomicWrites();
    }

    public function test_question_audio_selection_does_not_silently_dispatch_a_transaction_transcript(): void
    {
        $component = Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->call('selectAudioContext', 'question')
            ->call('receiveTranscript', 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS', null)
            ->assertSet('error', __('assistant_v2.tx_permission_required'))
            ->assertSet('reportResult', null);
        $this->assertArrayNotHasKey('redirect', $component->effects);
        Http::assertNothingSent();
        $this->assertNoEconomicWrites();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function questionPolicy(?array $settings = null): void
    {
        $settings ??= $this->aiPolicy->fresh()->settings;
        $settings['questions'] = ['enabled' => true, 'allowed_reports' => AssistantReportCatalog::KEYS];
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => true,
            'personal_credentials_enabled' => false,
            'follow_up_enabled' => true,
            'workflow_mode' => 'seamless',
            'speech_method' => 'cloud',
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

    private function speechConnection(): AiProviderConnection
    {
        $speech = app(AiGovernance::class)->provision($this->company, $this->owner, [
            'provider' => 'groq',
            'model' => 'whisper-large-v3-turbo',
            'region' => 'global',
            'capability' => 'audio_transcription',
            'credential_source' => 'company_byok',
        ], 'synthetic-question-speech-key', 'synthetic-configuration-only');

        $settings = $this->aiPolicy->fresh()->settings;
        $settings['allowed_connections'][] = $speech->public_id;
        $settings['speech_connection'] = $speech->public_id;
        $settings['pricing'][$speech->public_id] = [
            'reference' => 'https://example.test/pricing',
            'checked_at' => '2026-10-11',
            'input_micros_per_million' => 0,
            'output_micros_per_million' => 0,
            'audio_micros_per_minute' => 6000,
        ];
        $this->questionPolicy($settings);
        $this->withCookie(config('session.cookie'), session()->getId());

        return $speech;
    }

    private function assertNoEconomicWrites(): void
    {
        foreach (['sales_invoices', 'purchases', 'posting_batches', 'stock_movements'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }
}
