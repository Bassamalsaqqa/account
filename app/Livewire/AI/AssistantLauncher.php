<?php

declare(strict_types=1);

namespace App\Livewire\AI;

use App\Application\AI\AiGovernance;
use App\Application\AI\AiProviderRoutes;
use App\Application\AI\AiSpeechMethods;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\TransactionAssistant;
use App\Models\AiUserPreference;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class AssistantLauncher extends Component
{
    #[Locked]
    public string $context = 'auto';

    #[Locked]
    public string $formKey;

    #[Locked]
    public ?string $token = null;

    #[Locked]
    public ?string $audioToken = null;

    #[Locked]
    public string $mode = 'seamless';

    #[Locked]
    public string $speechMethod = 'cloud';

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $questions = [];

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $consentRoutes = [];

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $availableConnections = [];

    #[Locked]
    public string $consentSelection = '';

    public string $text = '';

    public bool $consent = false;

    public bool $open = false;

    public ?string $error = null;

    /** @var array<int, mixed> */
    public array $answers = [];

    public string $transcriptionChoice = '';

    /** @var list<string> */
    #[Locked]
    public array $allowedSpeechMethods = [];

    public ?string $textConnection = null;

    public ?string $speechConnection = null;

    public function mount(string $context = 'auto'): void
    {
        abort_unless(in_array($context, ['auto', 'sales', 'purchase'], true), 422);
        $this->context = $context;
        $this->formKey = (string) Str::uuid();
    }

    public function showAssistant(): void
    {
        $company = app(CompanyContext::class)->company();
        $governance = app(AiGovernance::class);
        $policy = $governance->authorizeLauncher($company, auth()->user(), $this->context);
        $this->mode = $policy->workflow_mode;
        $this->speechMethod = app(AiSpeechMethods::class)->effective($policy, auth()->user());
        $this->allowedSpeechMethods = app(AiSpeechMethods::class)->allowed($policy);
        $preferences = AiUserPreference::where('company_id', $company->id)->where('user_id', auth()->id())->first();
        $this->textConnection = $preferences?->preferences['text_connection'] ?? null;
        $this->speechConnection = $preferences?->preferences['speech_connection'] ?? null;
        $this->transcriptionChoice = $preferences?->preferences['speech_method'] ?? '';
        if (! in_array($this->transcriptionChoice, $this->allowedSpeechMethods, true)) {
            $this->transcriptionChoice = '';
        }
        $this->availableConnections = $governance->permittedConnections($company, auth()->user(), $this->context);
        $this->error = null;
        $this->consentRoutes = [];
        try {
            $this->consentRoutes = $this->selectedConsentRoutes();
        } catch (AssistantUnavailable) {
            $this->error = __('assistant_v2.error_provider_selection');
        }
        $this->consentSelection = $this->selectionFingerprint();
        $this->consent = $this->consentRoutes !== [] && collect($this->consentRoutes)->every(fn ($row) => $row['consented']);
        $this->open = true;
    }

    public function acceptConsent(): void
    {
        if (! $this->consent) {
            throw new AssistantUnavailable('consent');
        }
        $company = app(CompanyContext::class)->company();
        try {
            DB::transaction(function () use ($company): void {
                app(AiGovernance::class)->authorizeLauncher($company, auth()->user(), $this->context);
                $routes = $this->selectedConsentRoutes();
                if ($routes === [] || ! hash_equals($this->consentSelection, $this->selectionFingerprint())
                    || $this->routeFingerprints($routes) !== $this->routeFingerprints($this->consentRoutes)) {
                    throw new AssistantUnavailable('consent_stale');
                }
                app(AiGovernance::class)->recordConsents($company, auth()->user(), $this->routeFingerprints($routes));
            });
        } catch (AssistantUnavailable) {
            $this->consent = false;
            $this->error = __('assistant_v2.error_consent');

            return;
        }
        $this->showAssistant();
    }

    public function selectConnections(): void
    {
        $company = app(CompanyContext::class)->company();
        try {
            DB::transaction(function () use ($company): void {
                app(AiGovernance::class)->authorizeLauncher($company, auth()->user(), $this->context);
                app(AiSpeechMethods::class)->choose($company, auth()->user(), $this->transcriptionChoice);
                $policy = app(AiGovernance::class)->authorizeLauncher($company, auth()->user(), $this->context);
                $this->speechMethod = app(AiSpeechMethods::class)->effective($policy, auth()->user());
                foreach (['text_structured' => $this->textConnection, 'audio_transcription' => $this->speechConnection] as $capability => $id) {
                    if ($capability !== 'audio_transcription' || config('ai_assistant.speech_enabled') === true
                        && in_array($this->speechMethod, ['cloud', 'local_if_supported'], true)) {
                        app(AiProviderRoutes::class)->preference($company, auth()->user(), $capability, $id);
                    }
                }
            });
        } catch (AssistantUnavailable) {
            $this->showAssistant();
            $this->consent = false;
            $this->error = __('assistant_v2.error_provider_selection');

            return;
        }
        $this->showAssistant();
        $this->consent = false;
    }

    /** Resolve disclosure without reading keys or granting dispatch authority.
     * @return list<array<string, mixed>>
     */
    private function selectedConsentRoutes(): array
    {
        $company = app(CompanyContext::class)->company();
        $actor = auth()->user();
        $governance = app(AiGovernance::class);
        $policy = $governance->authorizeLauncher($company, $actor, $this->context);
        $preferences = AiUserPreference::where('company_id', $company->id)->where('user_id', $actor->id)->first();
        $routes = [];
        foreach (['text_structured' => $this->textConnection, 'audio_transcription' => $this->speechConnection] as $capability => $selected) {
            if ($capability === 'audio_transcription' && (config('ai_assistant.speech_enabled') !== true
                || ! in_array(app(AiSpeechMethods::class)->effective($policy, $actor), ['cloud', 'local_if_supported'], true))) {
                continue;
            }
            $key = $capability === 'audio_transcription' ? 'speech_connection' : 'text_connection';
            // Missing provider configuration must not prevent deterministic typed/manual workflows.
            if (($selected === null || $selected === '') && ! is_string($preferences?->preferences[$key] ?? $policy->settings[$key] ?? null)) {
                continue;
            }
            foreach (app(AiProviderRoutes::class)->resolve($company, $actor, $this->context, $capability, $selected) as $index => $connection) {
                $fingerprint = $governance->consentFingerprint($policy, $connection);
                $routes[] = [...$connection->only(['public_id', 'provider', 'model', 'region', 'capability', 'credential_source']),
                    'role' => $index === 0 ? 'primary' : 'fallback', 'fingerprint' => $fingerprint,
                    'consented' => hash_equals($fingerprint, (string) ($preferences?->consents[$connection->public_id]['fingerprint'] ?? ''))];
            }
        }

        return $routes;
    }

    private function selectionFingerprint(): string
    {
        return hash('sha256', json_encode([$this->textConnection, $this->speechConnection, $this->transcriptionChoice], JSON_THROW_ON_ERROR));
    }

    /** @param list<array<string, mixed>> $routes
     * @return array<string, string>
     */
    private function routeFingerprints(array $routes): array
    {
        $fingerprints = [];
        foreach ($routes as $route) {
            $fingerprints[$route['public_id']] = $route['fingerprint'];
        }

        return $fingerprints;
    }

    public function prepare(?string $transcript = null, ?string $audioToken = null): mixed
    {
        if ($transcript !== null) {
            $this->text = $transcript;
            $this->audioToken = $audioToken;
        }
        $this->error = null;
        try {
            app(AiGovernance::class)->authorizeLauncher(app(CompanyContext::class)->company(), auth()->user(), $this->context);
            $result = app(TransactionAssistant::class)->prepare(app(CompanyContext::class)->company(), auth()->user(),
                $this->text, $this->context, $this->formKey, (string) Str::uuid(), session()->getId(),
                $this->textConnection ?: null, $this->audioToken);

            return $this->present($result);
        } catch (AssistantUnavailable $e) {
            $this->error = __('assistant_v2.error_'.$this->safeReason($e->reason));

            return null;
        }
    }

    public function receiveTranscript(string $transcript, ?string $token): mixed
    {
        $policy = app(AiGovernance::class)->authorizeLauncher(app(CompanyContext::class)->company(), auth()->user(), $this->context);
        $this->text = $transcript;
        $this->audioToken = $token;
        if ($policy->workflow_mode === 'seamless') {
            return $this->prepare();
        }
        $this->mode = 'guided';

        return null;
    }

    public function clarify(): mixed
    {
        if ($this->token === null) {
            abort(409);
        }
        $answers = [];
        foreach ($this->questions as $index => $question) {
            if (! isset($this->answers[$index]) || $this->answers[$index] === '') {
                continue;
            }
            $value = $this->answers[$index];
            if ($question['candidates'] !== [] && is_string($value) && preg_match('/\A[1-9]\d{0,9}\z/', $value)) {
                $value = (int) $value;
            }
            $answers[$question['path']] = $value;
        }
        try {
            app(AiGovernance::class)->authorizeLauncher(app(CompanyContext::class)->company(), auth()->user(), $this->context);

            return $this->present(app(TransactionAssistant::class)->clarify(app(CompanyContext::class)->company(),
                auth()->user(), $this->token, session()->getId(), $this->formKey, $answers));
        } catch (AssistantUnavailable $e) {
            $this->error = __('assistant_v2.error_'.$this->safeReason($e->reason));

            return null;
        }
    }

    public function reviseDescription(): void
    {
        app(TransactionAssistant::class)->cancel(app(CompanyContext::class)->company(), auth()->user(), $this->formKey, session()->getId());
        $this->token = $this->audioToken = null;
        $this->questions = $this->answers = [];
        $this->formKey = (string) Str::uuid();
        $this->error = null;
    }

    public function cancel(): void
    {
        app(TransactionAssistant::class)->cancel(app(CompanyContext::class)->company(), auth()->user(), $this->formKey, session()->getId());
        $this->open = false;
        $this->text = '';
        $this->token = $this->audioToken = null;
        $this->answers = $this->questions = [];
        $this->formKey = (string) Str::uuid();
    }

    /** @param array<string, mixed> $result */
    private function present(array $result): mixed
    {
        if ($result['state'] === 'handoff') {
            return $this->redirectRoute($result['intent'] === 'sales' ? 'invoices.create' : 'purchases.create', ['assistant' => $result['token']]);
        }
        if ($result['state'] === 'clarifying') {
            $this->token = $result['token'];
            $this->questions = $result['questions'];
            $this->answers = [];

            return null;
        }
        $this->error = __('assistant_v2.error_'.($result['state'] === 'unsupported' ? 'unsupported' : 'uncertain'));

        return null;
    }

    private function safeReason(string $reason): string
    {
        return in_array($reason, ['budget', 'consent', 'form_changed', 'proposal_stale', 'provider_outage'], true) ? $reason : 'unavailable';
    }

    public function render(): View
    {
        $context = app(CompanyContext::class);
        $available = false;
        $privacy = [];
        if ($context->hasCompany()) {
            try {
                $policy = app(AiGovernance::class)->authorizeLauncher($context->company(), auth()->user(), $this->context);
                $available = true;
                $privacy = $policy->settings['privacy'] ?? [];
            } catch (AssistantUnavailable|AuthorizationException) {
                // Hidden feature preserves the complete manual workflow.
            }
        }

        return view('livewire.ai.assistant-launcher', ['available' => $available, 'privacy' => $privacy]);
    }
}
