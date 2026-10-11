<?php

declare(strict_types=1);

namespace Tests\Support\Phase12;

use App\Application\AI\AiGovernance;
use App\Models\AiCompanyPolicy;
use App\Models\AiProviderConnection;
use Illuminate\Support\Facades\Hash;

trait FinalAiFixtures
{
    use AssistantFixtures;

    protected AiProviderConnection $textConnection;

    protected AiProviderConnection $fallbackConnection;

    protected AiCompanyPolicy $aiPolicy;

    protected function finalAiFixtures(): void
    {
        $this->assistantFixtures();
        $this->owner->update(['password' => Hash::make('synthetic-configuration-only')]);
        $governance = app(AiGovernance::class);
        $this->textConnection = $governance->provision($this->company, $this->owner, [
            'provider' => 'deepseek', 'model' => 'deepseek-flash', 'region' => 'global',
            'capability' => 'text_structured', 'credential_source' => 'company_byok',
        ], 'synthetic-primary-key-never-real', 'synthetic-configuration-only');
        $this->fallbackConnection = $governance->provision($this->company, $this->owner, [
            'provider' => 'openai', 'model' => 'gpt-4o-mini', 'region' => 'global',
            'capability' => 'text_structured', 'credential_source' => 'company_byok',
        ], 'synthetic-fallback-key-never-real', 'synthetic-configuration-only');
        $pricing = [];
        foreach ([$this->textConnection, $this->fallbackConnection] as $connection) {
            $pricing[$connection->public_id] = ['reference' => 'https://example.test/synthetic-pricing',
                'checked_at' => '2026-10-11', 'input_micros_per_million' => 1000,
                'output_micros_per_million' => 2000, 'audio_micros_per_minute' => 6000];
        }
        $this->aiPolicy = $governance->updatePolicy($this->company, $this->owner, [
            'enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => true,
            'workflow_mode' => 'seamless', 'speech_method' => 'cloud', 'settings' => [
                'allowed_connections' => [$this->textConnection->public_id, $this->fallbackConnection->public_id],
                'text_connection' => $this->textConnection->public_id, 'speech_connection' => null,
                'fallback' => ['enabled' => true, 'text_connection' => $this->fallbackConnection->public_id, 'speech_connection' => null],
                'privacy' => ['operator' => 'Synthetic fixture', 'retention' => 'No real dispatch',
                    'policy_url' => 'https://example.test/privacy'],
                'pricing' => $pricing, 'limits' => [
                    'request_micros' => 1000, 'daily_company_micros' => 100000, 'monthly_company_micros' => 1000000,
                    'daily_user_micros' => 100000, 'monthly_user_micros' => 1000000,
                    'daily_company_requests' => 100, 'daily_user_requests' => 25,
                ], 'retention_days' => 30,
            ],
        ]);
        foreach ([$this->textConnection, $this->fallbackConnection] as $connection) {
            $governance->recordConsent($this->company, $this->owner, $connection->public_id,
                $governance->consentFingerprint($this->aiPolicy, $connection));
        }
    }
}
