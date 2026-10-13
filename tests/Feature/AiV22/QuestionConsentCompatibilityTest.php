<?php

declare(strict_types=1);

namespace Tests\Feature\AiV22;

use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantReportPolicySettings;
use App\Livewire\AI\AssistantLauncher;
use App\Models\AiUserPreference;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

final class QuestionConsentCompatibilityTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
        config(['ai_assistant.questions.enabled' => true]);
        $settings = $this->aiPolicy->settings;
        $settings['questions'] = ['enabled' => true, 'allowed_reports' => ['sales.summary']];
        $this->updateSettings($settings);
    }

    public function test_new_consent_survives_expansion_reduction_and_order_without_new_acknowledgement(): void
    {
        $governance = app(AiGovernance::class);
        $fingerprint = $governance->consentFingerprint($this->aiPolicy, $this->textConnection, 'question');
        $governance->recordConsent($this->company, $this->owner, $this->textConnection->public_id, $fingerprint, 'question');
        $before = $this->preference()->getAttributes();
        foreach ([['sales.summary', 'sales.last-invoice'], ['sales.last-invoice', 'sales.summary'], ['sales.summary']] as $reports) {
            $this->aiPolicy = app(AssistantReportPolicySettings::class)->update($this->company, $this->owner,
                $reports, $this->aiPolicy->revision, 'synthetic-configuration-only');
            self::assertSame($fingerprint, $governance->consentFingerprint($this->aiPolicy, $this->textConnection, 'question'));
            self::assertTrue($governance->hasConsent($this->aiPolicy, $this->textConnection, $this->owner, 'question'));
            self::assertSame($before, $this->preference()->getAttributes());
        }
        Http::assertNothingSent();
    }

    public function test_valid_legacy_consent_is_accepted_by_both_dispatch_and_launcher_without_writing_history(): void
    {
        $this->storeLegacyConsent();
        $before = $this->preference()->getAttributes();
        app(AiGovernance::class)->requireConsent($this->aiPolicy, $this->textConnection, $this->owner, 'question');
        Livewire::test(AssistantLauncher::class)->call('showAssistant')->assertSet('questionConsent', true);
        self::assertSame($before, $this->preference()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_valid_legacy_proof_survives_owner_list_edit_before_first_use_with_original_hash_and_date(): void
    {
        $this->storeLegacyConsent();
        $old = $this->preference()->consents;
        $this->aiPolicy = app(AssistantReportPolicySettings::class)->update($this->company, $this->owner,
            ['sales.summary', 'sales.last-invoice'], $this->aiPolicy->revision, 'synthetic-configuration-only');
        $entry = $this->preference()->consents['question:'.$this->textConnection->public_id];
        self::assertSame($old['question:'.$this->textConnection->public_id]['fingerprint'], $entry['fingerprint']);
        self::assertSame($old['question:'.$this->textConnection->public_id]['recorded_at'], $entry['recorded_at']);
        self::assertSame($old[$this->textConnection->public_id], $this->preference()->consents[$this->textConnection->public_id]);
        self::assertSame(app(AiGovernance::class)->consentFingerprint($this->aiPolicy, $this->textConnection, 'question'), $entry['compatible_fingerprint']);
        self::assertTrue(app(AiGovernance::class)->hasConsent($this->aiPolicy, $this->textConnection, $this->owner, 'question'));
        $audit = AuditEvent::where('event_key', 'ai.consent.compatibility_recognized')->firstOrFail();
        self::assertSame($this->owner->id, $audit->actor_user_id);
        self::assertSame(1, $audit->getAttribute('meta_json')['recognized_count']);
        $this->aiPolicy = app(AssistantReportPolicySettings::class)->update($this->company, $this->owner,
            ['sales.summary'], $this->aiPolicy->revision);
        self::assertSame(1, AuditEvent::where('event_key', 'ai.consent.compatibility_recognized')->count());
        self::assertTrue(app(AiGovernance::class)->hasConsent($this->aiPolicy, $this->textConnection, $this->owner, 'question'));
        Http::assertNothingSent();
    }

    public function test_simultaneous_allowlist_and_privacy_change_cannot_acknowledge_new_terms(): void
    {
        $this->storeLegacyConsent();
        $settings = $this->aiPolicy->settings;
        $settings['questions']['allowed_reports'][] = 'sales.last-invoice';
        $settings['privacy']['retention'] = 'Materially different synthetic retention';
        $this->updateSettings($settings);
        self::assertFalse(app(AiGovernance::class)->hasConsent($this->aiPolicy, $this->textConnection, $this->owner, 'question'));
    }

    /** @return array<string, array{string}> */
    public static function materialChanges(): array
    {
        return ['privacy' => ['privacy'], 'revision' => ['revision'], 'connection' => ['connection'],
            'provider' => ['provider'], 'region' => ['region'], 'model' => ['model'], 'capability' => ['capability']];
    }

    #[DataProvider('materialChanges')]
    public function test_compatible_legacy_consent_still_rejects_material_changes(string $change): void
    {
        $this->storeLegacyConsent();
        $this->aiPolicy = app(AssistantReportPolicySettings::class)->update($this->company, $this->owner,
            ['sales.summary', 'sales.last-invoice'], $this->aiPolicy->revision, 'synthetic-configuration-only');
        if ($change === 'privacy') {
            $settings = $this->aiPolicy->settings;
            $settings['privacy']['policy_url'] = 'https://example.test/new-privacy';
            $this->updateSettings($settings);
        } else {
            $connection = clone $this->textConnection;
            $field = $change === 'connection' ? 'public_id' : $change;
            $connection->$field = $change === 'revision' ? $connection->revision + 1 : 'different-synthetic-value';
            $this->textConnection = $connection;
        }
        self::assertFalse(app(AiGovernance::class)->hasConsent($this->aiPolicy, $this->textConnection, $this->owner, 'question'));
    }

    public function test_missing_revoked_or_already_invalid_legacy_proof_is_not_upgraded(): void
    {
        $this->storeLegacyConsent();
        $stored = $this->preference()->consents;
        $stored['question:'.$this->textConnection->public_id]['fingerprint'] = str_repeat('a', 64);
        $this->preference()->update(['consents' => $stored]);
        $this->aiPolicy = app(AssistantReportPolicySettings::class)->update($this->company, $this->owner,
            ['sales.summary', 'sales.last-invoice'], $this->aiPolicy->revision, 'synthetic-configuration-only');
        self::assertSame($stored, $this->preference()->consents);
        self::assertFalse(app(AiGovernance::class)->hasConsent($this->aiPolicy, $this->textConnection, $this->owner, 'question'));
        self::assertSame(0, AuditEvent::where('event_key', 'ai.consent.compatibility_recognized')->count());
        $this->preference()->update(['consents' => []]);
        $this->aiPolicy = app(AssistantReportPolicySettings::class)->update($this->company, $this->owner,
            ['sales.summary'], $this->aiPolicy->revision);
        self::assertSame([], $this->preference()->consents);
        self::assertFalse(app(AiGovernance::class)->hasConsent($this->aiPolicy, $this->textConnection, $this->owner, 'question'));
    }

    public function test_removed_connection_and_user_scope_cannot_reuse_a_compatible_proof(): void
    {
        $this->storeLegacyConsent();
        $this->aiPolicy = app(AssistantReportPolicySettings::class)->update($this->company, $this->owner,
            ['sales.summary', 'sales.last-invoice'], $this->aiPolicy->revision, 'synthetic-configuration-only');
        $governance = app(AiGovernance::class);
        self::assertFalse($governance->hasConsent($this->aiPolicy, $this->textConnection, User::factory()->create(), 'question', $this->preference()));
        $foreign = clone $this->textConnection;
        $foreign->company_id = $this->company->id + 1;
        self::assertFalse($governance->hasConsent($this->aiPolicy, $foreign, $this->owner, 'question', $this->preference()));
        self::assertFalse($governance->hasConsent($this->aiPolicy, $this->textConnection, $this->owner, 'connection_test'));
        $this->textConnection->update(['revoked_at' => now()]);
        self::assertFalse($governance->hasConsent($this->aiPolicy, $this->textConnection, $this->owner, 'question'));
    }

    public function test_consent_does_not_grant_removed_report_permission(): void
    {
        $this->storeLegacyConsent();
        Role::findOrFail($this->owner->roles()->value('roles.id'))->revokePermissionTo('reports.sales.view');
        self::assertTrue(app(AiGovernance::class)->hasConsent($this->aiPolicy, $this->textConnection, $this->owner, 'question'));
        $this->expectException(AuthorizationException::class);
        app(AssistantReportCatalog::class)->authorize($this->company, $this->owner, 'sales.summary');
    }

    public function test_clearing_original_acknowledgement_cannot_reuse_leftover_compatibility_metadata(): void
    {
        $this->storeLegacyConsent();
        $this->aiPolicy = app(AssistantReportPolicySettings::class)->update($this->company, $this->owner,
            ['sales.summary', 'sales.last-invoice'], $this->aiPolicy->revision, 'synthetic-configuration-only');
        $stored = $this->preference()->consents;
        $stored['question:'.$this->textConnection->public_id]['fingerprint'] = '';
        $this->preference()->update(['consents' => $stored]);
        self::assertFalse(app(AiGovernance::class)->hasConsent($this->aiPolicy, $this->textConnection, $this->owner, 'question'));
    }

    /** Exact persisted contract-2 formula, not the new implementation under test. */
    private function storeLegacyConsent(): void
    {
        $reports = $this->aiPolicy->settings['questions']['allowed_reports'];
        sort($reports);
        $connection = $this->textConnection;
        $old = ['consent_contract' => 2, 'company' => $this->company->id,
            'connection' => $connection->public_id, 'connection_revision' => $connection->revision,
            'provider' => $connection->provider, 'model' => $connection->model, 'region' => $connection->region,
            'source' => $connection->credential_source, 'capability' => $connection->capability,
            'scope' => 'question', 'authorized_scope' => $reports, 'scope_enabled' => true,
            'privacy' => $this->aiPolicy->settings['privacy']];
        $preference = $this->preference();
        $preference->update(['consents' => [...$preference->consents,
            'question:'.$connection->public_id => ['fingerprint' => hash('sha256', json_encode($old, JSON_THROW_ON_ERROR)),
                'recorded_at' => '2026-10-11T08:00:00+00:00']]]);
    }

    private function preference(): AiUserPreference
    {
        return AiUserPreference::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->firstOrFail();
    }

    /** @param array<string, mixed> $settings */
    private function updateSettings(array $settings): void
    {
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner,
            ['enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => true,
                'workflow_mode' => 'seamless', 'speech_method' => 'disabled', 'settings' => $settings], $this->aiPolicy->revision);
    }
}
