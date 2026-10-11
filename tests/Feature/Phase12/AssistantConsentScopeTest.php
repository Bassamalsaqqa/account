<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AiProviderRoutes;
use App\Application\AI\AssistantUnavailable;
use App\Livewire\AI\AssistantLauncher;
use App\Models\AiProviderConnection;
use App\Models\AiUserPreference;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
        AiUserPreference::query()->update(['consents' => []]);
        Http::preventStrayRequests();
    }

    public function test_only_selected_primary_and_approved_fallback_receive_consent_in_both_languages(): void
    {
        $extra = $this->connection();
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
            $this->assertSame([$this->textConnection->public_id, $this->fallbackConnection->public_id], array_column($component->get('consentRoutes'), 'public_id'));
            $this->assertContains($extra->public_id, array_column($component->get('availableConnections'), 'public_id'));
            $component->assertSee(__('assistant_v2.route_primary'))->assertSee(__('assistant_v2.route_fallback'))
                ->set('consent', true)->call('acceptConsent')->assertSet('consent', true);
            $this->assertSame([$this->textConnection->public_id, $this->fallbackConnection->public_id], array_keys($this->consents()));
        }
        Http::assertNothingSent();
    }

    public function test_selection_can_change_before_consent_but_saving_selection_grants_no_dispatch(): void
    {
        $extra = $this->connection();
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant')
            ->set('textConnection', $extra->public_id)->call('selectConnections')->assertSet('consent', false);
        $this->assertSame([], $this->consents());
        $this->assertSame([$extra->public_id, $this->fallbackConnection->public_id], array_column($component->get('consentRoutes'), 'public_id'));
        try {
            app(AiProviderRoutes::class)->select($this->company, $this->owner, 'sales', 'text_structured');
            $this->fail('Choosing a provider must not imply consent.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('consent', $e->reason);
        }
        $component->set('consent', true)->call('acceptConsent');
        $this->assertSame([$extra->public_id, $this->fallbackConnection->public_id], array_keys($this->consents()));
        $this->assertSame($extra->public_id, app(AiProviderRoutes::class)->select($this->company, $this->owner, 'sales', 'text_structured')[0]->public_id);
        Http::assertNothingSent();
    }

    public function test_metadata_disclosure_reads_no_keys_and_does_not_authorize_provider_activation(): void
    {
        config(['ai_assistant.provider_approved' => false]);
        $this->textConnection->update(['secret_ciphertext' => 'synthetic-unusable-ciphertext']);
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant')
            ->assertSet('open', true)->assertDontSee('synthetic-unusable-ciphertext');
        $this->assertSame([$this->textConnection->public_id, $this->fallbackConnection->public_id], array_column($component->get('consentRoutes'), 'public_id'));
        $component->set('consent', true)->call('acceptConsent');
        foreach ([false => 'disabled', true => 'credential_invalid'] as $approved => $reason) {
            config(['ai_assistant.provider_approved' => (bool) $approved]);
            try {
                app(AiGovernance::class)->dispatchConnection($this->company, $this->owner, $this->textConnection->public_id, 'sales', 'text_structured');
                $this->fail('Consent metadata is not a decrypted or activated credential.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function test_mutating_a_selection_without_refreshing_disclosure_cannot_consent_it(): void
    {
        $extra = $this->connection();
        Livewire::test(AssistantLauncher::class)->call('showAssistant')
            ->set('textConnection', $extra->public_id)->set('consent', true)->call('acceptConsent')
            ->assertSet('consent', false)->assertSet('error', __('assistant_v2.error_consent'));
        $this->assertSame([], $this->consents());
        Http::assertNothingSent();
    }

    public function test_key_rotation_after_disclosure_requires_renewed_acceptance_without_partial_records(): void
    {
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
        $this->fallbackConnection->increment('revision');
        $component->set('consent', true)->call('acceptConsent')->assertSet('error', __('assistant_v2.error_consent'));
        $this->assertSame([], $this->consents());
        Http::assertNothingSent();
    }

    public function test_policy_and_fallback_change_after_disclosure_cannot_be_silently_accepted(): void
    {
        $extra = $this->connection();
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
        $settings = $this->aiPolicy->fresh()->settings;
        $settings['fallback']['text_connection'] = $extra->public_id;
        $this->aiPolicy->update(['settings' => $settings, 'revision' => $this->aiPolicy->revision + 1]);
        $component->set('consent', true)->call('acceptConsent')->assertSet('error', __('assistant_v2.error_consent'));
        $this->assertSame([], $this->consents());
        Http::assertNothingSent();
    }

    public function test_company_selection_clears_a_personal_preference_without_reusing_the_old_route(): void
    {
        $extra = $this->connection();
        app(AiProviderRoutes::class)->preference($this->company, $this->owner, 'text_structured', $extra->public_id);
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant')->assertSet('textConnection', $extra->public_id)
            ->set('textConnection', '')->call('selectConnections')->assertSet('textConnection', null);
        $this->assertSame($this->textConnection->public_id, $component->get('consentRoutes')[0]['public_id']);
        $this->assertSame([], $this->consents());
        Http::assertNothingSent();
    }

    public function test_disabled_fallback_and_local_only_speech_do_not_receive_consent(): void
    {
        $speech = $this->connection(true);
        $settings = $this->aiPolicy->fresh()->settings;
        $settings['fallback']['enabled'] = false;
        $settings['speech_connection'] = $speech->public_id;
        $this->aiPolicy->update(['settings' => $settings, 'speech_method' => 'local_only', 'revision' => $this->aiPolicy->revision + 1]);
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
        $this->assertSame([$this->textConnection->public_id], array_column($component->get('consentRoutes'), 'public_id'));
        $component->set('consent', true)->call('acceptConsent');
        $this->assertSame([$this->textConnection->public_id], array_keys($this->consents()));
        Http::assertNothingSent();
    }

    public function test_cloud_speech_has_its_own_selected_route_but_global_speech_disable_removes_it(): void
    {
        $speech = $this->connection(true);
        $settings = $this->aiPolicy->fresh()->settings;
        $settings['speech_connection'] = $speech->public_id;
        $this->aiPolicy->update(['settings' => $settings, 'revision' => $this->aiPolicy->revision + 1]);
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
        $this->assertSame([$this->textConnection->public_id, $this->fallbackConnection->public_id, $speech->public_id], array_column($component->get('consentRoutes'), 'public_id'));
        config(['ai_assistant.speech_enabled' => false]);
        $component->set('consent', true)->call('acceptConsent')->assertSet('error', __('assistant_v2.error_consent'));
        $this->assertSame([], $this->consents());
        $component->call('showAssistant');
        $this->assertNotContains($speech->public_id, array_column($component->get('consentRoutes'), 'public_id'));
        Http::assertNothingSent();
    }

    public function test_multi_route_recording_rolls_back_if_any_fingerprint_is_stale(): void
    {
        $governance = app(AiGovernance::class);
        try {
            $governance->recordConsents($this->company, $this->owner, [
                $this->textConnection->public_id => $governance->consentFingerprint($this->aiPolicy, $this->textConnection),
                $this->fallbackConnection->public_id => str_repeat('0', 64),
            ]);
            $this->fail('Every displayed route must be current.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('consent_stale', $e->reason);
        }
        $this->assertSame([], $this->consents());
        Http::assertNothingSent();
    }

    public function test_revoked_membership_cannot_accept_previously_displayed_routes(): void
    {
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        $component->set('consent', true)->call('acceptConsent')->assertForbidden();
        $this->assertSame([], $this->consents());
        Http::assertNothingSent();
    }

    public function test_foreign_company_and_another_users_personal_key_are_not_disclosed_or_consented(): void
    {
        $extra = $this->connection();
        $other = User::factory()->create();
        app(CompanyContext::class)->clear();
        $foreignCompany = app(CreateCompanyAction::class)->execute($other, ['name_ar' => 'شركة معزولة', 'name_en' => 'Isolated company', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($foreignCompany, $other);
        $foreign = $extra->replicate();
        $foreign->forceFill(['company_id' => $foreignCompany->id, 'public_id' => (string) Str::uuid()])->save();
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $private = $extra->replicate();
        $private->forceFill(['owner_user_id' => $other->id, 'credential_source' => 'personal_byok', 'public_id' => (string) Str::uuid()])->save();
        foreach ([$foreign, $private] as $connection) {
            $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
            $this->assertNotContains($connection->public_id, array_column($component->get('availableConnections'), 'public_id'));
            $component->set('textConnection', $connection->public_id)->set('consent', true)->call('acceptConsent')
                ->assertSet('error', __('assistant_v2.error_consent'));
            $this->assertSame([], $this->consents());
        }
        Http::assertNothingSent();
    }

    public function test_mid_session_role_downgrade_cannot_accept_displayed_routes(): void
    {
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
        $viewer = Role::where('company_id', $this->company->id)->where('name', 'Viewer')->firstOrFail();
        $this->owner->syncRoles([$viewer]);
        $component->set('consent', true)->call('acceptConsent')->assertForbidden();
        $this->assertSame([], $this->consents());
        Http::assertNothingSent();
    }

    public function test_revoked_or_unallowed_connection_cannot_be_selected_for_consent(): void
    {
        $extra = $this->connection();
        $extra->update(['revoked_at' => now()]);
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
        $this->assertNotContains($extra->public_id, array_column($component->get('availableConnections'), 'public_id'));
        $component->set('textConnection', $extra->public_id)->set('consent', true)->call('acceptConsent')
            ->assertSet('error', __('assistant_v2.error_consent'));
        $this->assertSame([], $this->consents());
        Http::assertNothingSent();
    }

    private function connection(bool $speech = false): AiProviderConnection
    {
        $connection = app(AiGovernance::class)->provision($this->company, $this->owner, [
            'provider' => $speech ? 'groq' : 'qwen', 'model' => $speech ? 'whisper-large-v3-turbo' : 'qwen-plus',
            'region' => $speech ? 'global' : 'singapore', 'capability' => $speech ? 'audio_transcription' : 'text_structured',
            'credential_source' => 'company_byok',
        ], 'synthetic-unrelated-key-only', 'synthetic-configuration-only');
        $settings = $this->aiPolicy->fresh()->settings;
        $settings['allowed_connections'][] = $connection->public_id;
        $this->aiPolicy->update(['settings' => $settings, 'revision' => $this->aiPolicy->revision + 1]);

        return $connection;
    }

    private function consents(): array
    {
        return AiUserPreference::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->firstOrFail()->consents ?? [];
    }
}
