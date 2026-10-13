<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AiProviderRoutes;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantUnavailable;
use App\Livewire\AI\AssistantLauncher;
use App\Models\AiUserPreference;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AssistantConsentScopeTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        config(['ai_assistant.questions.enabled' => true]);
        $settings = $this->aiPolicy->settings;
        $settings['questions'] = ['enabled' => true, 'allowed_reports' => AssistantReportCatalog::KEYS];
        $this->aiPolicy->update(['settings' => $settings]);
        AiUserPreference::query()->update(['consents' => []]);
        Http::preventStrayRequests();
    }

    private function consents(): array
    {
        return AiUserPreference::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->firstOrFail()->consents ?? [];
    }

    private function consent(string $scope = 'transaction'): void
    {
        $governance = app(AiGovernance::class);
        $governance->recordConsent($this->company, $this->owner, $this->textConnection->public_id,
            $governance->consentFingerprint($this->aiPolicy->fresh(), $this->textConnection->fresh(), $scope), $scope);
    }

    public function test_single_company_route_has_separate_explicit_transaction_and_question_consent_in_ar_and_en(): void
    {
        foreach (['ar', 'en'] as $locale) {
            AiUserPreference::query()->update(['consents' => []]);
            app()->setLocale($locale);
            $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
            $this->assertSame([$this->textConnection->public_id], array_column($component->get('consentRoutes'), 'public_id'));
            $component->set('consent', true)->call('acceptConsent')->assertSet('consent', true)->assertSet('questionConsent', false);
            $this->assertSame([$this->textConnection->public_id], array_keys($this->consents()));
            $component->set('questionConsent', true)->call('acceptQuestionConsent')->assertSet('questionConsent', true);
            $this->assertSame([$this->textConnection->public_id, 'question:'.$this->textConnection->public_id], array_keys($this->consents()));
        }
        Http::assertNothingSent();
    }

    public function test_question_consent_never_authorizes_transaction_or_connection_test_purposes(): void
    {
        $this->consent('question');
        $governance = app(AiGovernance::class);
        $governance->requireConsent($this->aiPolicy->fresh(), $this->textConnection, $this->owner, 'question');
        foreach (['transaction', 'connection_test'] as $scope) {
            try {
                $governance->requireConsent($this->aiPolicy->fresh(), $this->textConnection, $this->owner, $scope);
                $this->fail('Consent cannot cross purposes.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('consent', $e->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function test_unrelated_policy_revision_limits_retention_and_workflow_changes_preserve_consent(): void
    {
        $this->consent();
        $this->consent('question');
        $before = $this->consents();
        $settings = $this->aiPolicy->settings;
        $settings['limits']['daily_user_micros'] = 100;
        $settings['retention_days'] = 14;
        $this->aiPolicy->update(['revision' => $this->aiPolicy->revision + 1, 'settings' => $settings,
            'workflow_mode' => 'guided', 'follow_up_enabled' => false]);
        Livewire::test(AssistantLauncher::class)->call('showAssistant')->assertSet('consent', true)->assertSet('questionConsent', true);
        $this->assertSame($before, $this->consents());
        Http::assertNothingSent();
    }

    public function test_unrelated_change_after_display_still_allows_explicit_acceptance(): void
    {
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
        $settings = $this->aiPolicy->settings;
        $settings['limits']['daily_company_micros'] = 100;
        $this->aiPolicy->update(['revision' => $this->aiPolicy->revision + 1, 'settings' => $settings]);
        $component->set('consent', true)->call('acceptConsent')->assertSet('consent', true)->assertSet('error', null);
        $this->assertArrayHasKey($this->textConnection->public_id, $this->consents());
        Http::assertNothingSent();
    }

    public function test_key_revision_change_after_disclosure_refuses_stale_consent_atomically(): void
    {
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
        $this->textConnection->increment('revision');
        $component->set('consent', true)->call('acceptConsent')->assertSet('consent', false)
            ->assertSet('error', __('assistant_v2.error_consent'));
        $this->assertSame([], $this->consents());
        Http::assertNothingSent();
    }

    public function test_material_privacy_change_invalidates_both_existing_purpose_consents(): void
    {
        $this->consent();
        $this->consent('question');
        $settings = $this->aiPolicy->settings;
        $settings['privacy']['retention'] = 'Changed synthetic disclosure';
        $this->aiPolicy->update(['settings' => $settings, 'revision' => $this->aiPolicy->revision + 1]);
        Livewire::test(AssistantLauncher::class)->call('showAssistant')->assertSet('consent', false)->assertSet('questionConsent', false);
        $this->assertCount(2, $this->consents());
        Http::assertNothingSent();
    }

    public function test_transaction_scope_changes_affect_its_consent_but_report_permissions_are_independent(): void
    {
        $this->consent();
        $this->consent('question');
        $settings = $this->aiPolicy->settings;
        $settings['allowed_intents'] = ['sales'];
        $this->aiPolicy->update(['settings' => $settings]);
        Livewire::test(AssistantLauncher::class)->call('showAssistant')->assertSet('consent', false)->assertSet('questionConsent', true);
        $this->consent();
        $settings['questions']['allowed_reports'] = ['sales.summary'];
        $this->aiPolicy->update(['settings' => $settings]);
        Livewire::test(AssistantLauncher::class)->call('showAssistant')->assertSet('consent', true)->assertSet('questionConsent', true);
        Http::assertNothingSent();
    }

    public function test_metadata_disclosure_and_consent_do_not_decrypt_or_activate_credentials(): void
    {
        config(['ai_assistant.provider_approved' => false]);
        $this->textConnection->update(['secret_ciphertext' => 'synthetic-unusable-ciphertext']);
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant')->assertSet('open', true)
            ->assertDontSee('synthetic-unusable-ciphertext');
        $component->set('consent', true)->call('acceptConsent')->assertSet('consent', true);
        foreach ([false => 'disabled', true => 'credential_invalid'] as $approved => $reason) {
            config(['ai_assistant.provider_approved' => (bool) $approved]);
            try {
                app(AiGovernance::class)->dispatchConnection($this->company, $this->owner, $this->textConnection->public_id, 'sales', 'text_structured');
                $this->fail('Consent is not a valid decrypted or activated key.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function test_revoked_selected_connection_is_an_actionable_safe_error_without_fallback(): void
    {
        $this->textConnection->update(['revoked_at' => now()]);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(AssistantLauncher::class)->call('showAssistant')->assertSet('open', true)
                ->assertSet('consentRoutes', [])->assertSet('consent', false)
                ->assertSet('error', __('assistant_v2.error_credential_revoked'))
                ->assertDontSee('synthetic-primary-key-never-real');
        }
        $this->assertSame([], $this->consents());
        Http::assertNothingSent();
    }

    public function test_historical_user_route_and_fallback_preferences_cannot_change_active_disclosure(): void
    {
        $preferences = ['text_connection' => 'historical-retired-selection', 'speech_connection' => 'historical-speech'];
        AiUserPreference::query()->update(['preferences' => $preferences]);
        $settings = $this->aiPolicy->settings;
        $settings['fallback'] = ['enabled' => true, 'text_connection' => 'historical-fallback', 'speech_connection' => null];
        $this->aiPolicy->update(['settings' => $settings]);
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
        $this->assertSame([$this->textConnection->public_id], array_column($component->get('consentRoutes'), 'public_id'));
        $component->set('consent', true)->call('acceptConsent');
        $this->assertSame([$this->textConnection->public_id], array_keys($this->consents()));
        $this->assertSame($preferences, AiUserPreference::firstOrFail()->preferences);
        Http::assertNothingSent();
    }

    public function test_revoked_membership_and_role_downgrade_cannot_accept_displayed_consent(): void
    {
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        $component->set('consent', true)->call('acceptConsent')->assertForbidden();
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'active']);
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
        $viewer = Role::where('company_id', $this->company->id)->where('name', 'Viewer')->firstOrFail();
        $this->owner->syncRoles([$viewer]);
        $component->set('consent', true)->call('acceptConsent')->assertForbidden();
        $this->assertSame([], $this->consents());
        Http::assertNothingSent();
    }

    public function test_foreign_company_connection_cannot_be_disclosed_or_consented(): void
    {
        $other = User::factory()->create();
        app(CompanyContext::class)->clear();
        $foreignCompany = app(CreateCompanyAction::class)->execute($other, ['name_ar' => 'Isolated', 'name_en' => 'Isolated', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($foreignCompany, $other);
        $foreignPolicy = $this->aiPolicy->replicate();
        $foreignPolicy->forceFill(['company_id' => $foreignCompany->id])->save();
        $this->actingAs($other);
        Livewire::test(AssistantLauncher::class)->call('showAssistant')->assertSet('consentRoutes', [])
            ->assertSet('error', __('assistant_v2.error_credential_revoked'));
        $governance = app(AiGovernance::class);
        try {
            $governance->recordConsent($foreignCompany, $other, $this->textConnection->public_id,
                $governance->consentFingerprint($this->aiPolicy, $this->textConnection));
            $this->fail('A foreign company cannot grant this credential consent.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, AiUserPreference::where('company_id', $foreignCompany->id)->count());
        }
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);
        $this->assertSame([], $this->consents());
        $this->assertSame($this->textConnection->public_id, app(AiProviderRoutes::class)->resolve($this->company, $this->owner, 'sales', 'text_structured')[0]->public_id);
        Http::assertNothingSent();
    }
}
