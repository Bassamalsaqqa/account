<?php

declare(strict_types=1);

namespace App\Livewire\AI;

use App\Application\AI\AiGovernance;
use App\Application\AI\AiProviderRoutes;
use App\Application\AI\AiSpeechMethods;
use App\Application\AI\AssistantDeterministicClassifier;
use App\Application\AI\AssistantQuestionContext;
use App\Application\AI\AssistantQuestionPlanner;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantReportExecutor;
use App\Application\AI\AssistantReportPlan;
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
    public string $audioContext = 'auto';

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

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $reportResult = null;

    #[Locked]
    public ?string $clarifyPeriodReport = null;

    /** @var array<string, string> */
    #[Locked]
    public array $clarifyPeriodFilters = [];

    /** @var array{report_key: ?string, text: string}|null */
    #[Locked]
    public ?array $ambiguousChoice = null;

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $questionConsentRoutes = [];

    public bool $questionConsent = false;

    #[Locked]
    public ?int $questionCompanyId = null;

    #[Locked]
    public ?int $questionActorId = null;

    #[Locked]
    public ?string $questionSessionHash = null;

    public function mount(string $context = 'auto'): void
    {
        abort_unless(in_array($context, ['auto', 'sales', 'purchase'], true), 422);
        $this->context = $context;
        $this->audioContext = $context;
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
        $isReportOnly = $governance->allowedIntents($company, auth()->user()) === [];
        if ($this->context === 'auto' && $isReportOnly) {
            $this->audioContext = 'question';
        }
        $effectiveScope = ($this->context === 'auto' && $isReportOnly) ? 'question' : $this->context;
        $this->availableConnections = $governance->permittedConnections($company, auth()->user(), $effectiveScope);
        $this->error = null;
        $this->consentRoutes = [];
        try {
            $this->consentRoutes = $this->selectedConsentRoutes();
        } catch (AssistantUnavailable) {
            $this->error = __('assistant_v2.error_provider_selection');
        }
        $this->consentSelection = $this->selectionFingerprint();
        $this->consent = $this->consentRoutes !== [] && collect($this->consentRoutes)->every(fn ($row) => $row['consented']);

        $this->questionConsentRoutes = [];
        $this->questionConsent = $isReportOnly && $this->consent;
        if (! $isReportOnly && (bool) ($policy->settings['questions']['enabled'] ?? false) && app(AssistantReportCatalog::class)->permittedKeys($company, auth()->user()) !== []) {
            try {
                $this->questionConsentRoutes = $this->selectedConsentRoutes('question');
                $this->questionConsent = $this->questionConsentRoutes !== [] && collect($this->questionConsentRoutes)->every(fn ($row) => $row['consented']);
            } catch (AssistantUnavailable) {
                // If question routes unavailable, keep disabled without blocking launcher
            }
        }
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
                $governance = app(AiGovernance::class);
                $actor = auth()->user();
                $policy = $governance->authorizeLauncher($company, $actor, $this->context);
                $routes = $this->selectedConsentRoutes();
                if ($routes === [] || ! hash_equals($this->consentSelection, $this->selectionFingerprint())
                    || $this->routeFingerprints($routes) !== $this->routeFingerprints($this->consentRoutes)) {
                    throw new AssistantUnavailable('consent_stale');
                }
                $isReportOnly = $governance->allowedIntents($company, $actor) === [];
                $scope = $isReportOnly ? 'question' : 'transaction';
                $governance->recordConsents($company, $actor, $this->routeFingerprints($routes), $scope);
            });
        } catch (AssistantUnavailable) {
            $this->consent = false;
            $this->error = __('assistant_v2.error_consent');

            return;
        }
        $this->showAssistant();
    }

    public function acceptQuestionConsent(): void
    {
        if (! $this->questionConsent) {
            throw new AssistantUnavailable('consent');
        }
        $company = app(CompanyContext::class)->company();
        try {
            DB::transaction(function () use ($company): void {
                $governance = app(AiGovernance::class);
                $actor = auth()->user();
                $policy = $governance->authorizeLauncher($company, $actor, $this->context);
                $routes = $this->selectedConsentRoutes('question');
                if ($routes === [] || $this->routeFingerprints($routes) !== $this->routeFingerprints($this->questionConsentRoutes)) {
                    throw new AssistantUnavailable('consent_stale');
                }
                $governance->recordConsents($company, $actor, $this->routeFingerprints($routes), 'question');
            });
        } catch (AssistantUnavailable) {
            $this->questionConsent = false;
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
                $actor = auth()->user();
                $governance = app(AiGovernance::class);
                $isReportOnly = $governance->allowedIntents($company, $actor) === [];
                $scope = ($this->context === 'auto' && $isReportOnly) ? 'question' : $this->context;
                $policy = $governance->authorizeLauncher($company, $actor, $this->context);
                app(AiSpeechMethods::class)->choose($company, $actor, $this->transcriptionChoice, $scope);
                $this->speechMethod = app(AiSpeechMethods::class)->effective($policy, $actor);
                foreach (['text_structured' => $this->textConnection, 'audio_transcription' => $this->speechConnection] as $capability => $id) {
                    if ($capability !== 'audio_transcription' || config('ai_assistant.speech_enabled') === true
                        && in_array($this->speechMethod, ['cloud', 'local_if_supported'], true)) {
                        app(AiProviderRoutes::class)->preference($company, $actor, $capability, $id, $scope);
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
    private function selectedConsentRoutes(?string $overrideScope = null): array
    {
        $company = app(CompanyContext::class)->company();
        $actor = auth()->user();
        $governance = app(AiGovernance::class);
        $policy = $governance->authorizeLauncher($company, $actor, $this->context);
        $preferences = AiUserPreference::where('company_id', $company->id)->where('user_id', $actor->id)->first();
        $routes = [];
        $isReportOnly = $governance->allowedIntents($company, $actor) === [];
        $scope = $overrideScope ?? ($isReportOnly ? 'question' : 'transaction');
        $resolveIntent = $overrideScope === 'question' ? 'question' : (($this->context === 'auto' && $isReportOnly) ? 'question' : $this->context);
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
            foreach (app(AiProviderRoutes::class)->resolve($company, $actor, $resolveIntent, $capability, $selected) as $index => $connection) {
                $fingerprint = $governance->consentFingerprint($policy, $connection, $scope);
                $consentKey = $scope === 'question' ? 'question:'.$connection->public_id : $connection->public_id;
                $routes[] = [...$connection->only(['public_id', 'provider', 'model', 'region', 'capability', 'credential_source']),
                    'role' => $index === 0 ? 'primary' : 'fallback', 'fingerprint' => $fingerprint,
                    'consented' => hash_equals($fingerprint, (string) ($preferences?->consents[$consentKey]['fingerprint'] ?? ''))];
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

    public function prepare(?string $transcript = null, ?string $audioToken = null, bool $questionOnly = false): mixed
    {
        if ($transcript !== null) {
            $this->text = $transcript;
            $this->audioToken = $audioToken;
        }
        $this->error = null;
        $this->reportResult = null;
        $this->clarifyPeriodReport = null;
        $this->clarifyPeriodFilters = [];
        $this->ambiguousChoice = null;

        $company = app(CompanyContext::class)->company();
        $actor = auth()->user();
        $governance = app(AiGovernance::class);

        try {
            $policy = $governance->authorizeLauncher($company, $actor, $this->context);
            $this->questionCompanyId = (int) $company->id;
            $this->questionActorId = (int) $actor->id;
            $this->questionSessionHash = hash('sha256', session()->getId());
            $allowedIntents = $governance->allowedIntents($company, $actor);
            $questionOnly = $questionOnly || $this->audioContext === 'question';
            $canDoTransactions = ! $questionOnly && ($this->context === 'auto' ? $allowedIntents !== [] : in_array($this->context, $allowedIntents, true));
            $canDoQuestions = in_array($this->context, ['auto', 'question'], true)
                && (bool) ($policy->settings['questions']['enabled'] ?? false)
                && $governance->can($company, $actor, 'ai.assistant.use')
                && app(AssistantReportCatalog::class)->permittedKeys($company, $actor) !== [];

            // Context explicit sales or purchase is always routed to transaction assistant
            if ($this->context !== 'auto' || ($canDoTransactions && ! $canDoQuestions)) {
                return $this->prepareTransaction();
            }

            // Resolve transaction intent before applying report-only constraints to follow-ups.
            $classification = app(AssistantDeterministicClassifier::class)->classify($this->text);
            if ($classification === null && $canDoQuestions) {
                $cachedCtx = app(AssistantQuestionContext::class)->read($company, $actor, session()->getId());
                $classifier = app(AssistantDeterministicClassifier::class);
                if ($cachedCtx !== null && ($classifier->extractPresetFromText($this->text) !== null
                    || $classifier->extractCurrencyFromText($this->text) !== null)) {
                    $followupPlan = app(AssistantDeterministicClassifier::class)->classifyFollowup(
                        $this->text,
                        $cachedCtx['report_key'],
                        $cachedCtx['filters']
                    );
                    if ($followupPlan !== null) {
                        return $this->executeReportPlan($followupPlan);
                    }
                }
            }

            if ($classification !== null) {
                if ($classification['kind'] === 'transaction') {
                    if (! $canDoTransactions) {
                        $this->error = __('assistant_v2.tx_permission_required');

                        return null;
                    }

                    return $this->prepareTransaction();
                }

                if ($classification['kind'] === 'ambiguous_operation') {
                    $this->ambiguousChoice = [
                        'report_key' => $classification['report_key'],
                        'text' => $this->text,
                    ];

                    return null;
                }

                if ($classification['kind'] === 'clarify_period') {
                    if (! $canDoQuestions) {
                        $this->error = __('assistant_v2.error_question_unsupported');

                        return null;
                    }

                    $this->clarifyPeriodReport = $classification['report_key'];
                    $this->clarifyPeriodFilters = $classification['filters'];

                    return null;
                }

                if (! $canDoQuestions) {
                    $this->error = __('assistant_v2.error_question_unsupported');

                    return null;
                }

                return $this->executeReportPlan($classification['plan']);
            }

            // An unknown instruction has no inferred disclosure scope. Ask before any dispatch.
            if ($canDoTransactions) {
                $this->ambiguousChoice = ['report_key' => null, 'text' => $this->text];

                return null;
            }
            if ($canDoQuestions) {
                return $this->prepareQuestionProvider();
            }

            // Report-only user with unclassifiable query
            $this->error = __('assistant_v2.error_question_unsupported');

            return null;
        } catch (AssistantUnavailable $e) {
            $this->error = __('assistant_v2.error_'.$this->safeReason($e->reason));

            return null;
        }
    }

    private function prepareQuestionProvider(): mixed
    {
        $planned = app(AssistantQuestionPlanner::class)->plan(
            app(CompanyContext::class)->company(), auth()->user(), $this->text,
            $this->formKey, session()->getId(), $this->textConnection ?: null
        );
        if ($planned !== null) {
            return $this->executeReportPlan($planned);
        }
        $this->error = __('assistant_v2.error_question_unsupported');

        return null;
    }

    private function prepareTransaction(): mixed
    {
        $result = app(TransactionAssistant::class)->prepare(
            app(CompanyContext::class)->company(),
            auth()->user(),
            $this->text,
            $this->context,
            $this->formKey,
            (string) Str::uuid(),
            session()->getId(),
            $this->textConnection ?: null,
            $this->audioToken
        );

        return $this->present($result);
    }

    private function prepareReportFromKey(string $reportKey): mixed
    {
        $definition = app(AssistantReportCatalog::class)->definition($reportKey);
        $classifier = app(AssistantDeterministicClassifier::class);
        if ($classifier->hasUnsupportedQualifiers($this->text)) {
            throw new AssistantUnavailable('question_invalid');
        }
        $classifier->assertQuestionInput($this->text);
        $preset = $classifier->extractPresetFromText($this->text);
        $currency = $classifier->extractCurrencyFromText($this->text);
        if ($currency !== null && ! in_array('currency_code', $definition['filters'], true)) {
            throw new AssistantUnavailable('question_invalid');
        }
        $filters = [];
        if ($currency !== null && in_array('currency_code', $definition['filters'], true)) {
            $filters['currency_code'] = $currency;
        }

        if ($definition['current']) {
            if ($preset !== null) {
                throw new AssistantUnavailable('question_invalid');
            }
            $plan = AssistantReportPlan::fromArray([
                'version' => 1,
                'type' => 'report_query',
                'report_key' => $reportKey,
                'filters' => $filters,
                'display' => 'summary',
            ]);

            return $this->executeReportPlan($plan);
        }

        if ($preset === null) {
            $this->clarifyPeriodReport = $reportKey;
            $this->clarifyPeriodFilters = $filters;

            return null;
        }

        $filters['preset'] = $preset;
        $plan = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => $reportKey,
            'filters' => $filters,
            'display' => 'summary',
        ]);

        return $this->executeReportPlan($plan);
    }

    private function executeReportPlan(AssistantReportPlan $plan): mixed
    {
        $company = app(CompanyContext::class)->company();
        $actor = auth()->user();
        try {
            $dto = app(AssistantReportExecutor::class)->execute($company, $actor, $plan);
            app(AssistantQuestionContext::class)->store($company, $actor, session()->getId(), $plan->reportKey, $plan->filters);
            app(AssistantReportCatalog::class)->authorize($company, $actor, $plan->reportKey);
            $this->questionCompanyId = (int) $company->id;
            $this->questionActorId = (int) $actor->id;
            $this->questionSessionHash = hash('sha256', session()->getId());
            $this->reportResult = $dto->toArray();
            $this->clarifyPeriodReport = null;
            $this->clarifyPeriodFilters = [];
            $this->ambiguousChoice = null;
            $this->text = '';
            $this->error = null;

            return null;
        } catch (AssistantUnavailable $e) {
            $this->error = __('assistant_v2.error_'.$this->safeReason($e->reason));

            return null;
        }
    }

    public function selectPeriod(string $preset): mixed
    {
        if ($this->clarifyPeriodReport === null) {
            return null;
        }
        $allowed = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'this_year', 'last_year'];
        if (! in_array($preset, $allowed, true)) {
            return null;
        }
        $reportKey = $this->clarifyPeriodReport;
        $filters = $this->clarifyPeriodFilters;
        $filters['preset'] = $preset;

        $plan = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => $reportKey,
            'filters' => $filters,
            'display' => 'summary',
        ]);

        return $this->executeReportPlan($plan);
    }

    public function chooseOperation(string $choice): mixed
    {
        if ($this->ambiguousChoice === null) {
            return null;
        }
        $company = app(CompanyContext::class)->company();
        abort_unless($this->questionCompanyId === (int) $company->id
            && $this->questionActorId === (int) auth()->id()
            && hash_equals($this->questionSessionHash ?? '', hash('sha256', session()->getId())), 403);
        app(AiGovernance::class)->authorizeLauncher($company, auth()->user(), $this->context);
        $reportKey = $this->ambiguousChoice['report_key'];
        $this->text = $this->ambiguousChoice['text'];
        $this->ambiguousChoice = null;
        try {
            if ($choice === 'report') {
                app(AiGovernance::class)->authorizeUse($company, auth()->user(), 'question');

                return $reportKey === null ? $this->prepareQuestionProvider() : $this->prepareReportFromKey($reportKey);
            }
            if ($choice === 'transaction') {
                app(AssistantQuestionContext::class)->clear($company, auth()->user(), session()->getId());

                return $this->prepareTransaction();
            }
        } catch (AssistantUnavailable $e) {
            $this->error = __('assistant_v2.error_'.$this->safeReason($e->reason));
        }

        return null;
    }

    public function resetQuestion(): void
    {
        if (app(CompanyContext::class)->hasCompany() && auth()->check()) {
            app(AssistantQuestionContext::class)->clear(app(CompanyContext::class)->company(), auth()->user(), session()->getId());
        }
        $this->reportResult = null;
        $this->clarifyPeriodReport = null;
        $this->clarifyPeriodFilters = [];
        $this->ambiguousChoice = null;
        $this->text = '';
        $this->error = null;
    }

    public function selectAudioContext(string $context): void
    {
        $company = app(CompanyContext::class)->company();
        $actor = auth()->user();
        abort_unless($this->context === 'auto' && in_array($context, ['auto', 'question'], true), 422);
        app(AiGovernance::class)->authorizeUse($company, $actor, $context);
        $this->audioContext = $context;
        $this->showAssistant();
    }

    public function receiveTranscript(string $transcript, ?string $token): mixed
    {
        $policy = app(AiGovernance::class)->authorizeLauncher(app(CompanyContext::class)->company(), auth()->user(), $this->context);
        $this->text = $transcript;
        $this->audioToken = $token;
        if ($policy->workflow_mode === 'seamless') {
            return $this->prepare(questionOnly: $this->audioContext === 'question');
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
        $company = app(CompanyContext::class)->hasCompany() ? app(CompanyContext::class)->company() : null;
        if ($company !== null && auth()->check()) {
            app(TransactionAssistant::class)->cancel($company, auth()->user(), $this->formKey, session()->getId());
        }
        $this->token = $this->audioToken = null;
        $this->questions = $this->answers = [];
        $this->reportResult = null;
        $this->clarifyPeriodReport = null;
        $this->clarifyPeriodFilters = [];
        $this->ambiguousChoice = null;
        $this->formKey = (string) Str::uuid();
        $this->error = null;
    }

    public function cancel(): void
    {
        $company = app(CompanyContext::class)->hasCompany() ? app(CompanyContext::class)->company() : null;
        if ($company !== null && auth()->check()) {
            app(TransactionAssistant::class)->cancel($company, auth()->user(), $this->formKey, session()->getId());
            app(AssistantQuestionContext::class)->clear($company, auth()->user(), session()->getId());
        }
        $this->open = false;
        $this->text = '';
        $this->token = $this->audioToken = null;
        $this->answers = $this->questions = [];
        $this->reportResult = null;
        $this->clarifyPeriodReport = null;
        $this->clarifyPeriodFilters = [];
        $this->ambiguousChoice = null;
        $this->error = null;
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
        return in_array($reason, ['budget', 'consent', 'form_changed', 'proposal_stale', 'provider_outage', 'question_unsupported', 'question_invalid'], true) ? $reason : 'unavailable';
    }

    public function render(): View
    {
        $context = app(CompanyContext::class);
        if ($this->questionCompanyId !== null && (! $context->hasCompany()
            || $this->questionCompanyId !== (int) $context->company()->id
            || $this->questionActorId !== (int) auth()->id()
            || ! hash_equals($this->questionSessionHash ?? '', hash('sha256', session()->getId())))) {
            $this->reportResult = null;
            $this->clarifyPeriodReport = null;
            $this->clarifyPeriodFilters = [];
            $this->ambiguousChoice = null;
        }
        $available = false;
        $privacy = [];
        $questionsAvailable = $transactionsAvailable = false;
        if ($context->hasCompany()) {
            try {
                $policy = app(AiGovernance::class)->authorizeLauncher($context->company(), auth()->user(), $this->context);
                $available = true;
                $privacy = $policy->settings['privacy'] ?? [];
                $transactionsAvailable = app(AiGovernance::class)->allowedIntents($context->company(), auth()->user()) !== [];
                $questionsAvailable = $this->context === 'auto' && app(AssistantReportCatalog::class)->permittedKeys($context->company(), auth()->user()) !== [];
                if ($this->reportResult !== null) {
                    app(AssistantReportCatalog::class)->authorize($context->company(), auth()->user(), $this->reportResult['report_key']);
                }
            } catch (AssistantUnavailable|AuthorizationException) {
                $available = false;
                $this->reportResult = null;
                $this->clarifyPeriodReport = null;
                $this->clarifyPeriodFilters = [];
                $this->ambiguousChoice = null;
                // Hidden feature preserves the complete manual workflow.
            }
        }

        return view('livewire.ai.assistant-launcher', compact('available', 'privacy', 'questionsAvailable', 'transactionsAvailable'));
    }
}
