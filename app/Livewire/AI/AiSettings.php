<?php

declare(strict_types=1);

namespace App\Livewire\AI;

use App\Application\AI\AiGovernance;
use App\Application\AI\AiUsageSummary;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCapabilityRegistry;
use App\Models\AiCompanyPolicy;
use App\Models\CompanyUser;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
final class AiSettings extends Component
{
    public bool $enabled = false;

    public bool $personalEnabled = false;

    public bool $followUpEnabled = false;

    public string $mode = 'seamless';

    public string $speechMethod = 'cloud';

    public ?string $textConnection = null;

    public ?string $speechConnection = null;

    public bool $fallbackEnabled = false;

    public ?string $fallbackText = null;

    public ?string $fallbackSpeech = null;

    public string $privacyOperator = '';

    public string $privacyRetention = '';

    public string $privacyUrl = '';

    public int $retentionDays = 30;

    /** @var list<string> */
    public array $allowedConnections = [];

    /** @var list<string> */
    public array $personalRoutes = [];

    /** @var array<string, mixed> */
    public array $limits = [
        'request_micros' => '0', 'daily_company_micros' => '0', 'monthly_company_micros' => '0',
        'daily_user_micros' => '0', 'monthly_user_micros' => '0', 'daily_company_requests' => 100, 'daily_user_requests' => 25,
    ];

    /** @var array<string, array<string, mixed>> */
    public array $pricing = [];

    #[Locked]
    public int $policyRevision = 0;

    public ?string $message = null;

    public function mount(): void
    {
        $company = app(CompanyContext::class)->company();
        app(AiGovernance::class)->managedConnections($company, auth()->user());
        $policy = AiCompanyPolicy::where('company_id', $company->id)->first();
        if ($policy === null) {
            return;
        }
        $this->policyRevision = (int) $policy->revision;
        $this->enabled = $policy->enabled;
        $this->personalEnabled = $policy->personal_credentials_enabled;
        $this->followUpEnabled = $policy->follow_up_enabled;
        $this->mode = $policy->workflow_mode;
        $this->speechMethod = $policy->speech_method;
        $settings = $policy->settings;
        $this->allowedConnections = $settings['allowed_connections'] ?? [];
        $this->personalRoutes = $settings['allowed_personal_routes'] ?? [];
        $this->textConnection = $settings['text_connection'] ?? null;
        $this->speechConnection = $settings['speech_connection'] ?? null;
        $this->fallbackEnabled = $settings['fallback']['enabled'] ?? false;
        $this->fallbackText = $settings['fallback']['text_connection'] ?? null;
        $this->fallbackSpeech = $settings['fallback']['speech_connection'] ?? null;
        $this->privacyOperator = $settings['privacy']['operator'] ?? '';
        $this->privacyRetention = $settings['privacy']['retention'] ?? '';
        $this->privacyUrl = $settings['privacy']['policy_url'] ?? '';
        $this->retentionDays = $settings['retention_days'] ?? 30;
        foreach ($settings['limits'] ?? [] as $key => $value) {
            $this->limits[$key] = str_ends_with($key, '_micros') ? (string) BigDecimal::of($value)->dividedBy('1000000', 6) : $value;
        }
        foreach ($settings['pricing'] ?? [] as $key => $price) {
            foreach (['input_micros_per_million', 'output_micros_per_million', 'audio_micros_per_minute'] as $field) {
                $price[$field] = (string) BigDecimal::of($price[$field])->dividedBy('1000000', 6);
            }
            $this->pricing[$key] = $price;
        }
    }

    public function save(): void
    {
        $company = app(CompanyContext::class)->company();
        app(AiGovernance::class)->authorizeCapability($company, auth()->user(), 'ai.settings.manage');
        $limits = $this->limits;
        foreach ($limits as $key => $value) {
            if (str_ends_with($key, '_micros')) {
                $limits[$key] = $this->micros($value);
            }
        }
        $prices = [];
        foreach ($this->pricing as $key => $price) {
            if (! in_array($key, [...$this->allowedConnections, ...$this->personalRoutes], true)) {
                continue;
            }
            foreach (['input_micros_per_million', 'output_micros_per_million', 'audio_micros_per_minute'] as $field) {
                $price[$field] = $this->micros($price[$field] ?? '0');
            }
            $prices[$key] = $price;
        }
        try {
            $policy = app(AiGovernance::class)->updatePolicy($company, auth()->user(), [
                'enabled' => $this->enabled, 'personal_credentials_enabled' => $this->personalEnabled,
                'follow_up_enabled' => $this->followUpEnabled, 'workflow_mode' => $this->mode, 'speech_method' => $this->speechMethod,
                'settings' => [
                    'allowed_connections' => $this->allowedConnections, 'allowed_personal_routes' => $this->personalRoutes,
                    'text_connection' => $this->textConnection ?: null, 'speech_connection' => $this->speechConnection ?: null,
                    'fallback' => ['enabled' => $this->fallbackEnabled, 'text_connection' => $this->fallbackText ?: null, 'speech_connection' => $this->fallbackSpeech ?: null],
                    'privacy' => ['operator' => $this->privacyOperator, 'retention' => $this->privacyRetention, 'policy_url' => $this->privacyUrl],
                    'limits' => $limits, 'pricing' => $prices, 'retention_days' => $this->retentionDays,
                ],
            ], $this->policyRevision);
            $this->policyRevision = (int) $policy->revision;
            $this->message = __('assistant_v2.policy_saved');
        } catch (AssistantUnavailable) {
            $this->addError('policy', __('assistant_v2.error_proposal_stale'));
        }
    }

    private function micros(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/\A\d{1,4}(?:\.\d{1,6})?\z/', $value)) {
            throw ValidationException::withMessages(['limits' => __('assistant_v2.invalid_limit')]);
        }

        return BigDecimal::of($value)->multipliedBy('1000000')->toInt();
    }

    public function render(): View
    {
        $company = app(CompanyContext::class)->company();
        $actor = auth()->user();
        $governance = app(AiGovernance::class);
        $connections = $governance->managedConnections($company, $actor);
        $permissions = [];
        foreach (['ai.settings.manage', 'ai.credentials.manage', 'ai.personal_credentials.manage'] as $permission) {
            try {
                $governance->authorizeCapability($company, $actor, $permission);
                $permissions[$permission] = true;
            } catch (AuthorizationException) {
                $permissions[$permission] = false;
            }
        }
        $routes = [];
        foreach (app(ProviderCapabilityRegistry::class)->catalog() as $provider => $entry) {
            foreach ($entry['models'] as $model => $capabilities) {
                foreach ($entry['regions'] as $region => $url) {
                    foreach ($capabilities as $capability) {
                        $routes[implode('|', [$provider, $model, $region, $capability])] = compact('provider', 'model', 'region', 'capability');
                    }
                }
            }
        }
        $isOwner = CompanyUser::where('company_id', $company->id)->where('user_id', $actor->id)
            ->where('status', 'active')->where('is_owner', true)->exists();

        $usage = $permissions['ai.settings.manage'] ? app(AiUsageSummary::class)->read($company, $actor, true) : [];

        return view('livewire.ai.settings', compact('connections', 'permissions', 'routes', 'isOwner', 'usage'));
    }
}
