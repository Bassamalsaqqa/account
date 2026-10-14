<?php

declare(strict_types=1);

namespace App\Livewire\AI;

use App\Application\AI\AiGovernance;
use App\Application\AI\AiProviderRoutes;
use App\Application\AI\AssistantDailyBriefing;
use App\Application\AI\AssistantDeterministicClassifier;
use App\Application\AI\AssistantDraftTurn;
use App\Application\AI\AssistantQuestionContext;
use App\Application\AI\AssistantQuestionPlanner;
use App\Application\AI\AssistantRecordContext;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantReportExecutor;
use App\Application\AI\AssistantReportPlan;
use App\Application\AI\AssistantTurnConstraints;
use App\Application\AI\AssistantTurnContext;
use App\Application\AI\AssistantTurnService;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\DeepSeekQuickConnect;
use App\Models\AiUserPreference;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

final class AssistantLauncher extends Component
{
    #[Locked]
    public string $context = 'auto';

    #[Locked]
    public string $formKey = '';

    #[Locked]
    public string $requestKey = '';

    #[Locked]
    public ?int $conversationCompanyId = null;

    #[Locked]
    public ?int $conversationActorId = null;

    #[Locked]
    public ?string $conversationSessionHash = null;

    #[Locked]
    public ?string $draftInputHash = null;

    #[Locked]
    public ?string $token = null;

    #[Locked]
    public string $mode = 'seamless';

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $questions = [];

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $consentRoutes = [];

    #[Locked]
    public string $consentSelection = '';

    public string $text = '';

    public bool $consent = false;

    public bool $open = false;

    public ?string $error = null;

    /** @var array<int, mixed> */
    public array $answers = [];

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $reportResult = null;

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $turnReports = [];

    /** @var list<array<string,mixed>> */
    #[Locked]
    public array $briefingInsights = [];

    #[Locked]
    public bool $isBriefing = false;

    #[Locked]
    public ?string $turnContextToken = null;

    #[Locked]
    public ?string $turnScope = null;

    #[Locked]
    public ?string $turnClarification = null;

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

    #[Locked]
    public ?string $recordType = null;

    #[Locked]
    public ?string $recordPublicId = null;

    public function mount(string $context = 'auto'): void
    {
        abort_unless(in_array($context, ['auto', 'sales', 'purchase'], true), 422);
        $this->context = $context;
        $this->formKey = (string) Str::uuid();
        $this->requestKey = (string) Str::uuid();
        if (auth()->check() && app(CompanyContext::class)->hasCompany()) {
            $this->conversationCompanyId = (int) app(CompanyContext::class)->companyId();
            $this->conversationActorId = (int) auth()->id();
            $this->conversationSessionHash = hash('sha256', session()->getId());
            $record = AssistantRecordContext::fromRoute();
            $this->recordType = $record['type'] ?? null;
            $this->recordPublicId = $record['public_id'] ?? null;
        }
    }

    private function resolveCurrentRecord(): ?AssistantRecordContext
    {
        if ($this->recordType === null && $this->recordPublicId === null) {
            return null;
        }
        $context = app(CompanyContext::class);
        if ($this->recordType === null || $this->recordPublicId === null || ! auth()->check() || ! $context->hasCompany()
            || $this->conversationCompanyId !== (int) $context->companyId()
            || $this->conversationActorId !== (int) auth()->id()
            || ! hash_equals($this->conversationSessionHash ?? '', hash('sha256', session()->getId()))) {
            throw new AssistantUnavailable('proposal_stale');
        }

        return AssistantRecordContext::resolve(app(CompanyContext::class)->company(), auth()->user(), $this->recordType, $this->recordPublicId);
    }

    private function assertConversationContext(): void
    {
        $context = app(CompanyContext::class);
        abort_unless(auth()->check() && $context->hasCompany()
            && $this->conversationCompanyId === (int) $context->companyId()
            && $this->conversationActorId === (int) auth()->id()
            && hash_equals($this->conversationSessionHash ?? '', hash('sha256', session()->getId())), 403);
    }

    #[On('ai:open-assistant')]
    public function showAssistant(): void
    {
        $this->assertConversationContext();
        $company = app(CompanyContext::class)->company();
        $governance = app(AiGovernance::class);
        $policy = $governance->authorizeLauncher($company, auth()->user(), $this->context);
        $this->mode = $policy->workflow_mode;
        $isReportOnly = $governance->allowedIntents($company, auth()->user()) === [];
        $this->error = null;
        $this->consentRoutes = [];
        try {
            $this->consentRoutes = $this->selectedConsentRoutes();
        } catch (AssistantUnavailable $e) {
            $this->error = __('assistant_v2.error_'.$this->safeReason($e->diagnosticReason()));
        }
        $this->consentSelection = $this->selectionFingerprint($this->consentRoutes);
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
        $this->assertConversationContext();
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
        $this->assertConversationContext();
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
        // The company route is authoritative. Historical per-user routing stays inactive.
        // Missing setup must not block local deterministic reports or manual workflows.
        if (! is_string($policy->settings['text_connection'] ?? null) || $policy->settings['text_connection'] === '') {
            return [];
        }
        foreach (app(AiProviderRoutes::class)->resolve($company, $actor, $resolveIntent, 'text_structured') as $connection) {
            $fingerprint = $governance->consentFingerprint($policy, $connection, $scope);
            $routes[] = ['public_id' => $connection->public_id, 'fingerprint' => $fingerprint,
                'consented' => $governance->hasConsent($policy, $connection, $actor, $scope, $preferences)];
        }

        return $routes;
    }

    /** @param list<array<string, mixed>>|null $routes */
    private function selectionFingerprint(?array $routes = null): string
    {
        return hash('sha256', json_encode($this->routeFingerprints($routes ?? $this->selectedConsentRoutes()), JSON_THROW_ON_ERROR));
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

    public function loadDailyBriefing(): void
    {
        $this->assertConversationContext();
        $this->error = null;

        $company = app(CompanyContext::class)->company();
        $actor = auth()->user();

        if ($this->token !== null && Str::isUuid($this->formKey)) {
            app(AssistantDraftTurn::class)->cancel($company, $actor, $this->formKey, session()->getId());
        }
        $this->token = null;
        $this->questions = [];
        $this->answers = [];
        $this->draftInputHash = null;

        app(AssistantQuestionContext::class)->clear($company, $actor, session()->getId());

        $this->reportResult = null;
        $this->clarifyPeriodReport = null;
        $this->clarifyPeriodFilters = [];
        $this->ambiguousChoice = null;
        $this->turnReports = [];
        $this->briefingInsights = [];
        $this->turnClarification = null;
        $this->turnContextToken = null;
        $this->turnScope = null;
        $this->isBriefing = false;
        $this->formKey = (string) Str::uuid();
        $this->requestKey = (string) Str::uuid();

        try {
            $briefingService = app(AssistantDailyBriefing::class);
            $result = $briefingService->generate($company, $actor, session()->getId());

            $this->questionCompanyId = (int) $company->id;
            $this->questionActorId = (int) $actor->id;
            $this->questionSessionHash = hash('sha256', session()->getId());
            $this->turnReports = $result['reports'];
            $this->briefingInsights = $result['insights'];
            $this->turnScope = $result['_authorization_scope'];
            $this->turnContextToken = null;
            $this->turnClarification = null;
            $this->isBriefing = true;
            $this->text = '';
        } catch (AssistantUnavailable $e) {
            $this->error = __('assistant_v2.error_'.$this->safeReason($e->diagnosticReason()));
        } catch (AuthorizationException) {
            $this->error = __('assistant_v2.error_question_disabled');
        }
    }

    public function prepare(bool $questionOnly = false): mixed
    {
        $this->assertConversationContext();
        $this->error = null;
        $this->reportResult = null;
        $this->clarifyPeriodReport = null;
        $this->clarifyPeriodFilters = [];
        $this->ambiguousChoice = null;
        $this->turnReports = [];
        $this->briefingInsights = [];
        $this->turnClarification = null;
        $this->isBriefing = false;

        $company = app(CompanyContext::class)->company();
        $actor = auth()->user();
        $governance = app(AiGovernance::class);

        try {
            $policy = $governance->authorizeLauncher($company, $actor, $this->context);
            $this->questionCompanyId = (int) $company->id;
            $this->questionActorId = (int) $actor->id;
            $this->questionSessionHash = hash('sha256', session()->getId());
            $allowedIntents = $governance->allowedIntents($company, $actor);
            $canDoTransactions = ! $questionOnly && ($this->context === 'auto' ? $allowedIntents !== [] : in_array($this->context, $allowedIntents, true));
            $canDoQuestions = in_array($this->context, ['auto', 'question'], true)
                && (bool) ($policy->settings['questions']['enabled'] ?? false)
                && $governance->can($company, $actor, 'ai.assistant.use')
                && app(AssistantReportCatalog::class)->permittedKeys($company, $actor) !== [];

            if (config('ai_assistant.daily_briefing_enabled') === true
                && AssistantDailyBriefing::isBriefingQuery($this->text)
                && $canDoQuestions) {
                $this->loadDailyBriefing();

                return null;
            }

            if (config('ai_assistant.turn_enabled') === true && $canDoQuestions && $this->context === 'auto') {
                try {
                    $classified = app(AssistantDeterministicClassifier::class)->classify($this->text);
                } catch (AssistantUnavailable) {
                    $classified = null;
                }
                if (($classified['kind'] ?? null) === 'transaction') {
                    if (! app(AssistantTurnConstraints::class)->isReadInvoiceQuestion($this->text)) {
                        if (! $canDoTransactions) {
                            $this->error = __('assistant_v2.tx_permission_required');

                            return null;
                        }

                        return $this->prepareTransaction();
                    }
                } elseif (($classified['kind'] ?? null) === 'ambiguous_operation') {
                    if ($canDoTransactions) {
                        $this->ambiguousChoice = [
                            'report_key' => $classified['report_key'],
                            'text' => $this->text,
                        ];

                        return null;
                    }
                }

                $result = app(AssistantTurnService::class)->run($company, $actor, $this->text,
                    $this->requestKey, session()->getId(), $this->turnContextToken, $this->resolveCurrentRecord());
                $this->turnReports = $result['reports'];
                $this->turnContextToken = $result['conversation_token'];
                $this->turnScope = $result['_authorization_scope'] ?? null;
                $this->turnClarification = $result['status'] === 'clarification' ? ($result['reason'] ?? 'intent') : null;
                if ($result['status'] === 'operation_choice') {
                    $this->ambiguousChoice = ['report_key' => null, 'text' => $this->text];
                }
                $this->formKey = (string) Str::uuid();
                $this->requestKey = (string) Str::uuid();

                return null;
            }

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
            $this->error = __('assistant_v2.error_'.$this->safeReason($e->diagnosticReason()));

            return null;
        }
    }

    private function prepareQuestionProvider(): mixed
    {
        $planned = app(AssistantQuestionPlanner::class)->plan(
            app(CompanyContext::class)->company(), auth()->user(), $this->text,
            $this->formKey, session()->getId()
        );
        if ($planned !== null) {
            return $this->executeReportPlan($planned);
        }
        $this->error = __('assistant_v2.error_question_unsupported');

        return null;
    }

    private function prepareTransaction(): mixed
    {
        $company = app(CompanyContext::class)->company();
        $actor = auth()->user();

        $this->reportResult = null;
        $this->turnReports = [];
        $this->briefingInsights = [];
        $this->turnContextToken = $this->turnScope = $this->turnClarification = null;
        $this->clarifyPeriodReport = null;
        $this->clarifyPeriodFilters = [];
        $this->ambiguousChoice = null;
        $this->isBriefing = false;
        if (app(CompanyContext::class)->hasCompany() && auth()->check()) {
            app(AssistantQuestionContext::class)->clear($company, $actor, session()->getId());
        }

        $inputHash = hash('sha256', json_encode([$this->text, $this->context, $company->id, $actor->id,
            hash('sha256', session()->getId())], JSON_THROW_ON_ERROR));
        if ($this->draftInputHash !== null && ! hash_equals($this->draftInputHash, $inputHash)) {
            // A changed description submitted by the human is a new turn. A retry
            // of the same description retains its key and cannot bill twice.
            app(AssistantDraftTurn::class)->cancel($company, $actor, $this->formKey, session()->getId());
            $this->token = null;
            $this->questions = $this->answers = [];
            $this->formKey = (string) Str::uuid();
            $this->requestKey = (string) Str::uuid();
        }
        $this->draftInputHash = $inputHash;

        $result = app(AssistantDraftTurn::class)->prepare(
            $company,
            $actor,
            $this->text,
            $this->context,
            $this->requestKey,
            $this->formKey,
            session()->getId(),
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
            $this->error = __('assistant_v2.error_'.$this->safeReason($e->diagnosticReason()));

            return null;
        }
    }

    public function selectPeriod(string $preset): mixed
    {
        $this->assertConversationContext();
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
        $this->assertConversationContext();
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

                $this->token = null;
                $this->questions = $this->answers = [];

                if (config('ai_assistant.turn_enabled') === true && $this->context === 'auto') {
                    $result = app(AssistantTurnService::class)->run($company, auth()->user(), $this->text,
                        $this->requestKey, session()->getId(), $this->turnContextToken, $this->resolveCurrentRecord());
                    $this->turnReports = $result['reports'];
                    $this->turnContextToken = $result['conversation_token'];
                    $this->turnScope = $result['_authorization_scope'] ?? null;
                    $this->turnClarification = in_array($result['status'], ['clarification', 'operation_choice'], true)
                        ? ($result['reason'] ?? 'intent') : null;
                    $this->formKey = (string) Str::uuid();
                    $this->requestKey = (string) Str::uuid();

                    return null;
                }

                return $reportKey === null ? $this->prepareQuestionProvider() : $this->prepareReportFromKey($reportKey);
            }
            if ($choice === 'transaction') {
                $this->reportResult = null;
                $this->turnReports = [];
                $this->briefingInsights = [];
                $this->turnContextToken = $this->turnScope = $this->turnClarification = null;
                $this->clarifyPeriodReport = null;
                $this->clarifyPeriodFilters = [];
                app(AssistantQuestionContext::class)->clear($company, auth()->user(), session()->getId());

                return $this->prepareTransaction();
            }
        } catch (AssistantUnavailable $e) {
            $this->error = __('assistant_v2.error_'.$this->safeReason($e->diagnosticReason()));
        }

        return null;
    }

    public function resetQuestion(): void
    {
        $this->assertConversationContext();
        $company = app(CompanyContext::class)->hasCompany() ? app(CompanyContext::class)->company() : null;
        if ($company !== null && auth()->check()) {
            if ($this->token !== null) {
                app(AssistantDraftTurn::class)->cancel($company, auth()->user(), $this->formKey, session()->getId());
            }
            app(AssistantQuestionContext::class)->clear($company, auth()->user(), session()->getId());
        }
        $this->reportResult = null;
        $this->turnReports = [];
        $this->briefingInsights = [];
        $this->turnClarification = null;
        $this->isBriefing = false;
        // Preserve the bounded A2 report context for an explicitly requested follow-up.
        $this->token = null;
        $this->questions = $this->answers = [];
        $this->draftInputHash = null;
        $this->formKey = (string) Str::uuid();
        $this->requestKey = (string) Str::uuid();
        $this->clarifyPeriodReport = null;
        $this->clarifyPeriodFilters = [];
        $this->ambiguousChoice = null;
        $this->text = '';
        $this->error = null;
    }

    public function receiveTranscript(string $transcript, ?string $token = null): void
    {
        $this->assertConversationContext();
        app(AiGovernance::class)->authorizeLauncher(app(CompanyContext::class)->company(), auth()->user(), $this->context);
        if (mb_strlen($transcript) > 4000 || ! mb_check_encoding($transcript, 'UTF-8')) {
            $this->error = __('assistant_v2.error_input_invalid');

            return;
        }
        // Recognition is an editing action only. An explicit Send starts interpretation.
        // Legacy commercial transcription tokens never grant dispatch authority here.
        $this->text = $transcript;
        $this->error = null;
    }

    public function clarify(): mixed
    {
        $this->assertConversationContext();
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
            $company = app(CompanyContext::class)->company();
            $actor = auth()->user();
            app(AiGovernance::class)->authorizeLauncher($company, $actor, $this->context);

            return $this->present(app(AssistantDraftTurn::class)->clarify(
                $company,
                $actor,
                $this->token,
                session()->getId(),
                $this->formKey,
                $answers
            ));
        } catch (AssistantUnavailable $e) {
            $this->error = __('assistant_v2.error_'.$this->safeReason($e->diagnosticReason()));

            return null;
        }
    }

    public function reviseDescription(): void
    {
        $this->assertConversationContext();
        $this->draftInputHash = null;
        $company = app(CompanyContext::class)->hasCompany() ? app(CompanyContext::class)->company() : null;
        if ($company !== null && auth()->check()) {
            app(AssistantDraftTurn::class)->cancel($company, auth()->user(), $this->formKey, session()->getId());
        }
        $this->token = null;
        $this->questions = $this->answers = [];
        $this->reportResult = null;
        $this->turnReports = [];
        $this->briefingInsights = [];
        $this->turnContextToken = $this->turnScope = $this->turnClarification = null;
        $this->isBriefing = false;
        $this->clarifyPeriodReport = null;
        $this->clarifyPeriodFilters = [];
        $this->ambiguousChoice = null;
        $this->formKey = (string) Str::uuid();
        $this->requestKey = (string) Str::uuid();
        $this->error = null;
    }

    public function cancel(): void
    {
        $this->assertConversationContext();
        $this->draftInputHash = null;
        $company = app(CompanyContext::class)->hasCompany() ? app(CompanyContext::class)->company() : null;
        if ($company !== null && auth()->check()) {
            app(AssistantDraftTurn::class)->cancel($company, auth()->user(), $this->formKey, session()->getId());
            app(AssistantQuestionContext::class)->clear($company, auth()->user(), session()->getId());
        }
        $this->open = false;
        $this->turnReports = [];
        $this->briefingInsights = [];
        $this->turnContextToken = $this->turnScope = $this->turnClarification = null;
        $this->isBriefing = false;
        $this->text = '';
        $this->token = null;
        $this->answers = $this->questions = [];
        $this->reportResult = null;
        $this->clarifyPeriodReport = null;
        $this->clarifyPeriodFilters = [];
        $this->ambiguousChoice = null;
        $this->error = null;
        $this->formKey = (string) Str::uuid();
        $this->requestKey = (string) Str::uuid();
    }

    /** @param array<string, mixed> $result */
    private function present(array $result): mixed
    {
        if ($result['state'] === 'handoff') {
            $intent = $result['intent'];
            $token = $result['token'];
            $this->token = null;
            $this->questions = $this->answers = [];
            $this->text = '';

            return $this->redirectRoute($intent === 'sales' ? 'invoices.create' : 'purchases.create', ['assistant' => $token]);
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
        return match ($reason) {
            'budget', 'consent', 'consent_stale', 'form_changed', 'proposal_stale',
            'provider_authentication', 'provider_credit', 'provider_format', 'provider_parameters',
            'provider_rate_limit', 'provider_timeout', 'provider_outage', 'provider_invalid',
            'credential_invalid', 'credential_revoked', 'provider_configuration', 'connection_missing', 'connection_unverified',
            'disabled', 'input_invalid', 'proposal_invalid', 'question_unsupported', 'question_invalid', 'question_disabled' => $reason,
            'invalid_request', 'choice_invalid' => 'input_invalid',
            'policy_invalid', 'budget_configuration', 'transition_required', 'fallback_invalid', 'personal_credentials_disabled' => 'provider_configuration',
            'connection_required' => 'connection_missing',
            'evidence_invalid' => 'proposal_invalid',
            'output_limit_exceeded' => 'question_invalid',
            'intent_unsupported' => 'unsupported',
            'intent_uncertain', 'intent_mismatch' => 'uncertain',
            'request_conflict', 'request_processed', 'request_canceled' => 'proposal_stale',
            'provider_rejected' => 'provider_invalid',
            default => 'unavailable',
        };
    }

    public function render(): View
    {
        $context = app(CompanyContext::class);
        if ($this->questionCompanyId !== null && (! $context->hasCompany()
            || $this->questionCompanyId !== (int) $context->company()->id
            || $this->questionActorId !== (int) auth()->id()
            || ! hash_equals($this->questionSessionHash ?? '', hash('sha256', session()->getId())))) {
            $this->reportResult = null;
            $this->turnReports = [];
            $this->briefingInsights = [];
            $this->turnContextToken = $this->turnScope = $this->turnClarification = null;
            $this->clarifyPeriodReport = null;
            $this->clarifyPeriodFilters = [];
            $this->ambiguousChoice = null;
            $this->isBriefing = false;
        }
        $available = false;
        $setupAvailable = false;
        $privacy = [];
        $questionsAvailable = $transactionsAvailable = false;
        $briefingAvailable = false;
        $recordLabel = null;
        if ($context->hasCompany()) {
            $setupAvailable = app(DeepSeekQuickConnect::class)->isOwner($context->company(), auth()->user());
            try {
                $recordContext = $this->resolveCurrentRecord();
                $recordLabel = $recordContext?->name;
                $policy = app(AiGovernance::class)->authorizeLauncher($context->company(), auth()->user(), $this->context);
                $available = true;
                $privacy = $policy->settings['privacy'] ?? [];
                $transactionsAvailable = app(AiGovernance::class)->allowedIntents($context->company(), auth()->user()) !== [];
                $questionKeys = $this->context === 'auto' ? app(AssistantReportCatalog::class)->permittedKeys($context->company(), auth()->user()) : [];
                $questionsAvailable = $questionKeys !== [];
                $briefingAvailable = config('ai_assistant.daily_briefing_enabled') === true
                    && array_intersect(AssistantDailyBriefing::CARD_KEYS, $questionKeys) !== [];
                if ($this->isBriefing && config('ai_assistant.daily_briefing_enabled') !== true) {
                    $this->turnReports = [];
                    $this->briefingInsights = [];
                    $this->isBriefing = false;
                }
                if (($this->turnReports !== [] || $this->briefingInsights !== []) && $this->turnScope !== app(AssistantTurnContext::class)->scope($context->company(), auth()->user(), session()->getId(), $this->isBriefing ? null : $recordContext)) {
                    $this->turnReports = [];
                    $this->briefingInsights = [];
                    $this->turnContextToken = $this->turnScope = null;
                    $this->isBriefing = false;
                }
                if ($this->reportResult !== null) {
                    app(AssistantReportCatalog::class)->authorize($context->company(), auth()->user(), $this->reportResult['report_key']);
                }
            } catch (AssistantUnavailable|AuthorizationException) {
                $available = false;
                $briefingAvailable = false;
                $this->reportResult = null;
                $this->turnReports = [];
                $this->briefingInsights = [];
                $this->turnContextToken = $this->turnScope = $this->turnClarification = null;
                $this->clarifyPeriodReport = null;
                $this->clarifyPeriodFilters = [];
                $this->ambiguousChoice = null;
                $this->isBriefing = false;
                // Hidden feature preserves the complete manual workflow.
            }
        }

        return view('livewire.ai.assistant-launcher', compact('available', 'setupAvailable', 'privacy', 'questionsAvailable', 'transactionsAvailable', 'briefingAvailable', 'recordLabel'));
    }
}
