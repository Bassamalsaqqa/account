<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Application\AI\AiFormCorrections;
use App\Application\AI\AiFormEditing;
use App\Application\AI\AiFormState;
use App\Application\AI\AiGovernance;
use App\Application\AI\AiTranscriptRevisions;
use App\Application\AI\AssistantUnavailable;
use App\Models\AssistantRequest;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

trait AiUnsavedForm
{
    #[Locked]
    public ?string $aiFormToken = null;

    #[Locked]
    public ?string $aiFormKey = null;

    #[Locked]
    public int $aiFormRevision = 0;

    /** @var array<string, string> */
    #[Locked]
    public array $aiFieldOrigins = [];

    /** @var list<string> */
    #[Locked]
    public array $aiUnresolvedFields = [];

    #[Locked]
    public ?int $aiDraftSourceId = null;

    public string $aiCorrectionText = '';

    public ?string $aiCorrectionError = null;

    #[Locked]
    public ?string $aiCorrectionAudioToken = null;

    #[Locked]
    public bool $aiCanUndo = false;

    /** @var list<string> */
    #[Locked]
    public array $aiChangedFields = [];

    public bool $aiTranscriptOpen = false;

    public string $aiTranscriptText = '';

    public bool $aiTranscriptReplaceConfirmed = false;

    #[Locked]
    public ?string $aiTranscriptReplacementToken = null;

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $aiTranscriptQuestions = [];

    /** @var array<array-key, mixed> */
    public array $aiTranscriptAnswers = [];

    public function editAiTranscript(): void
    {
        if ($this->aiFormToken === null || $this->aiFormKey === null) {
            abort(409);
        }
        $state = app(AiFormState::class)->read(app(CompanyContext::class)->company(), auth()->user(),
            $this->aiFormToken, session()->getId(), $this->aiFormKey);
        $this->aiTranscriptText = $state['data']['transcript'];
        $this->aiTranscriptOpen = true;
        $this->aiTranscriptReplaceConfirmed = false;
    }

    public function prepareAiTranscript(): ?string
    {
        if ($this->aiFormToken === null || $this->aiFormKey === null || ! $this->aiTranscriptReplaceConfirmed) {
            abort(409);
        }
        try {
            $this->aiFormRevision = app(AiFormEditing::class)->synchronize(app(CompanyContext::class)->company(), auth()->user(),
                $this->aiFormToken, session()->getId(), $this->aiFormKey, $this->aiFormRevision, $this->aiEditablePayload());

            return app(AiTranscriptRevisions::class)->prepare(app(CompanyContext::class)->company(), auth()->user(),
                $this->aiFormToken, session()->getId(), $this->aiFormKey, $this->aiFormRevision,
                $this->aiEditablePayload(), $this->aiTranscriptText);
        } catch (AssistantUnavailable) {
            $this->aiCorrectionError = __('assistant_v2.correction_refused');

            return null;
        }
    }

    public function applyPreparedAiTranscript(string $prepared): mixed
    {
        if ($this->aiFormToken === null || $this->aiFormKey === null || ! $this->aiTranscriptReplaceConfirmed) {
            abort(409);
        }
        try {
            return $this->presentAiTranscript(app(AiTranscriptRevisions::class)->acknowledge(
                app(CompanyContext::class)->company(), auth()->user(), $this->aiFormToken,
                session()->getId(), $this->aiFormKey, $prepared, $this->aiEditablePayload()));
        } catch (AssistantUnavailable) {
            $this->aiCorrectionError = __('assistant_v2.error_form_changed');

            return null;
        }
    }

    public function prepareAiTranscriptClarification(): ?string
    {
        if ($this->aiFormToken === null || $this->aiFormKey === null || $this->aiTranscriptReplacementToken === null
            || ! $this->aiTranscriptReplaceConfirmed) {
            abort(409);
        }
        $answers = [];
        foreach ($this->aiTranscriptQuestions as $index => $question) {
            $value = $this->aiTranscriptAnswers[$index] ?? '';
            if ($value === '') {
                continue;
            }
            if ($question['candidates'] !== [] && is_string($value) && preg_match('/\A[1-9]\d{0,9}\z/', $value)) {
                $value = (int) $value;
            }
            $answers[$question['path']] = $value;
        }
        try {
            return app(AiTranscriptRevisions::class)->clarify(
                app(CompanyContext::class)->company(), auth()->user(), $this->aiFormToken,
                session()->getId(), $this->aiFormKey, $this->aiTranscriptReplacementToken,
                $this->aiEditablePayload(), $answers);
        } catch (AssistantUnavailable) {
            $this->aiCorrectionError = __('assistant_v2.error_form_changed');

            return null;
        }
    }

    public function discardPreparedAiTranscript(string $prepared): void
    {
        if ($this->aiFormKey === null) {
            return;
        }
        try {
            app(AiTranscriptRevisions::class)->discard(app(CompanyContext::class)->company(), auth()->user(),
                $prepared, session()->getId(), $this->aiFormKey);
        } catch (AssistantUnavailable) {
            // Expired/revoked memory remains inaccessible and expires at its fixed deadline.
        }
        if ($this->aiTranscriptReplacementToken === $prepared) {
            $this->aiTranscriptReplacementToken = null;
            $this->aiTranscriptQuestions = $this->aiTranscriptAnswers = [];
        }
    }

    public function closeAiTranscript(): void
    {
        if ($this->aiTranscriptReplacementToken !== null) {
            $this->discardPreparedAiTranscript($this->aiTranscriptReplacementToken);
        }
        $this->aiTranscriptOpen = $this->aiTranscriptReplaceConfirmed = false;
        $this->aiTranscriptText = '';
    }

    /** @param array<string, mixed> $result */
    protected function presentAiTranscript(array $result): mixed
    {
        if ($result['state'] === 'clarifying') {
            $this->aiTranscriptReplacementToken = $result['token'];
            $this->aiTranscriptQuestions = $result['questions'];
            $this->aiTranscriptAnswers = [];

            return null;
        }
        $this->clearAiInput();

        return $this->redirectRoute($result['intent'] === 'sales' ? 'invoices.create' : 'purchases.create',
            ['assistant' => $result['token']]);
    }

    public function correctAiInput(?string $audioToken = null): ?string
    {
        if ($this->aiFormToken === null || $this->aiFormKey === null) {
            abort(409);
        }
        try {
            // Deferred wire:model values arrive with this call; retain their synchronized revision for dehydration.
            $this->aiFormRevision = app(AiFormEditing::class)->synchronize(app(CompanyContext::class)->company(), auth()->user(),
                $this->aiFormToken, session()->getId(), $this->aiFormKey, $this->aiFormRevision, $this->aiEditablePayload());
            $prepared = app(AiFormCorrections::class)->prepare(app(CompanyContext::class)->company(), auth()->user(),
                $this->aiFormToken, session()->getId(), $this->aiFormKey, $this->aiFormRevision,
                $this->aiEditablePayload(), $this->aiCorrectionText, (string) Str::uuid(), audioToken: $audioToken ?? $this->aiCorrectionAudioToken);
            $this->aiCorrectionText = '';
            $this->aiCorrectionAudioToken = null;
            $this->aiCorrectionError = null;

            return $prepared;
        } catch (AssistantUnavailable) {
            $this->aiCorrectionError = __('assistant_v2.correction_refused');

            return null;
        }
    }

    public function receiveAiCorrection(string $text, ?string $audioToken, int $recordedRevision): ?string
    {
        if ($this->aiFormToken === null || $this->aiFormKey === null) {
            abort(409);
        }
        $state = app(AiFormState::class)->read(app(CompanyContext::class)->company(), auth()->user(),
            $this->aiFormToken, session()->getId(), $this->aiFormKey);
        if ($this->aiFormRevision !== $recordedRevision || $state['revision'] !== $recordedRevision
            || ! hash_equals($state['data']['payload_hash'], app(AiFormEditing::class)->hash($this->aiEditablePayload()))) {
            $this->aiCorrectionError = __('assistant_v2.error_form_changed');

            return null;
        }
        $this->aiCorrectionText = $text;
        $this->aiCorrectionAudioToken = $audioToken;
        $policy = app(AiGovernance::class)->authorizeUse(app(CompanyContext::class)->company(), auth()->user(), $state['intent']);
        if ($policy->workflow_mode === 'seamless') {
            return $this->correctAiInput($audioToken);
        }

        return null;
    }

    public function applyPreparedAiInput(string $prepared): void
    {
        if ($this->aiFormToken === null || $this->aiFormKey === null) {
            abort(409);
        }
        try {
            $result = app(AiFormCorrections::class)->commit(app(CompanyContext::class)->company(), auth()->user(),
                $this->aiFormToken, session()->getId(), $this->aiFormKey, $prepared, $this->aiEditablePayload());
            $this->applyAiEditResult($result);
            $this->aiCorrectionError = null;
        } catch (AssistantUnavailable) {
            $this->aiCorrectionError = __('assistant_v2.error_form_changed');
        }
    }

    public function discardPreparedAiInput(string $prepared): void
    {
        if ($this->aiFormKey === null) {
            return;
        }
        try {
            app(AiFormCorrections::class)->discardPrepared(app(CompanyContext::class)->company(), auth()->user(),
                $prepared, session()->getId(), $this->aiFormKey);
        } catch (AssistantUnavailable) {
            // A revoked/expired capability remains inaccessible and reaches its fixed TTL.
        }
    }

    public function cancelAiCorrection(): void
    {
        if ($this->aiFormToken === null || $this->aiFormKey === null) {
            return;
        }
        $company = app(CompanyContext::class)->company();
        $state = app(AiFormState::class)->read($company, auth()->user(), $this->aiFormToken, session()->getId(), $this->aiFormKey);
        $data = $state['data'];
        unset($data['undo']);
        $data['changed_fields'] = [];
        $this->aiFormRevision = app(AiFormState::class)->replace($company, auth()->user(), $this->aiFormToken,
            session()->getId(), $this->aiFormKey, $state['revision'], $data);
        AssistantRequest::where('company_id', $company->id)->where('user_id', auth()->id())
            ->where('form_key', $this->aiFormKey)->where('purpose', 'patch')->where('status', 'pending')->update(['status' => 'canceled']);
        $this->aiCanUndo = false;
        $this->aiChangedFields = [];
        $this->aiCorrectionAudioToken = null;
    }

    public function undoAiInput(): void
    {
        if ($this->aiFormToken === null || $this->aiFormKey === null) {
            abort(409);
        }
        try {
            $result = app(AiFormCorrections::class)->undo(app(CompanyContext::class)->company(), auth()->user(),
                $this->aiFormToken, session()->getId(), $this->aiFormKey, $this->aiFormRevision, $this->aiEditablePayload());
            $this->applyAiEditResult($result);
            $this->aiCorrectionError = null;
        } catch (AssistantUnavailable) {
            $this->aiCorrectionError = __('assistant_v2.error_form_changed');
        }
    }

    /** @param array<string, mixed> $result */
    protected function applyAiEditResult(array $result): void
    {
        $this->aiFormRevision = $result['revision'];
        $this->aiChangedFields = $result['changed_fields'];
        $this->aiCanUndo = $result['can_undo'];
        foreach ($result['payload'] as $field => $value) {
            if (in_array($field, $this->aiHeaderFields(), true)) {
                $this->{$field} = $value ?? (in_array($field, ['due_date', 'exchange_rate'], true) && ! property_exists($this, 'purchase_date') ? '' : null);
            }
        }
        $this->lines = $result['payload']['lines'];
        $this->recalculate();
    }

    public function mountAiInput(string $intent): void
    {
        $token = request()->query('assistant');
        if ($token === null) {
            return;
        }
        if (! is_string($token)) {
            abort(422);
        }
        try {
            $adopted = app(AiFormEditing::class)->adopt(app(CompanyContext::class)->company(), auth()->user(), $token, session()->getId(), $intent);
        } catch (AssistantUnavailable) {
            abort(409, __('assistant.proposal_changed'));
        }
        $this->aiFormToken = $token;
        $this->aiFormKey = $adopted['form_key'];
        $this->aiFormRevision = $adopted['revision'];
        $this->aiDraftSourceId = $adopted['data']['request_id'];
        $this->aiFieldOrigins = $adopted['data']['resolved']['origins'];
        $this->aiUnresolvedFields = $adopted['data']['resolved']['unresolved'];
        foreach ($adopted['data']['payload'] as $field => $value) {
            if (in_array($field, $this->aiHeaderFields(), true)) {
                $this->{$field} = $value ?? (in_array($field, ['due_date', 'exchange_rate'], true) && $intent === 'sales' ? '' : null);
            }
        }
        $this->lines = $adopted['data']['payload']['lines'];
        foreach ($this->lines as &$line) {
            $line['discount_type'] ??= 'none';
            if ($intent === 'sales') {
                $line += ['subtotal' => '0', 'discount_amount' => '0', 'tax_amount' => '0', 'line_total' => '0'];
            }
        }
        unset($line);
    }

    public function hydrateAiUnsavedForm(): void
    {
        if ($this->aiFormToken !== null) {
            $state = app(AiFormState::class)->read(app(CompanyContext::class)->company(), auth()->user(),
                $this->aiFormToken, session()->getId(), $this->aiFormKey);
            abort_unless($state['revision'] === $this->aiFormRevision, 409, __('assistant_v2.error_form_changed'));
        }
    }

    public function dehydrateAiUnsavedForm(): void
    {
        if ($this->aiFormToken !== null && $this->aiFormKey !== null) {
            $this->aiFormRevision = app(AiFormEditing::class)->synchronize(app(CompanyContext::class)->company(),
                auth()->user(), $this->aiFormToken, session()->getId(), $this->aiFormKey, $this->aiFormRevision, $this->aiEditablePayload());
            $state = app(AiFormState::class)->read(app(CompanyContext::class)->company(), auth()->user(), $this->aiFormToken, session()->getId(), $this->aiFormKey);
            $this->aiFieldOrigins = $state['data']['resolved']['origins'];
            $this->aiUnresolvedFields = $state['data']['resolved']['unresolved'];
            $this->aiCanUndo = isset($state['data']['undo']);
            $this->aiChangedFields = $state['data']['changed_fields'] ?? [];
        }
    }

    /** Discard only assistant memory; editable manual fields remain available. */
    public function discardAiInput(): void
    {
        $this->closeAiTranscript();
        if ($this->aiFormToken !== null) {
            try {
                app(AiFormState::class)->forget(app(CompanyContext::class)->company(), auth()->user(), $this->aiFormToken, session()->getId());
            } catch (AssistantUnavailable) {
                // Expired state is already inaccessible; manual form fields remain intact.
            }
        }
        $this->clearAiInput();
    }

    protected function clearAiInput(): void
    {
        $this->aiFormToken = $this->aiFormKey = null;
        $this->aiDraftSourceId = null;
        $this->aiFormRevision = 0;
        $this->aiFieldOrigins = $this->aiUnresolvedFields = [];
        $this->aiChangedFields = [];
        $this->aiCanUndo = false;
        $this->aiCorrectionText = '';
        $this->aiCorrectionAudioToken = null;
        $this->aiCorrectionError = null;
        $this->aiTranscriptOpen = $this->aiTranscriptReplaceConfirmed = false;
        $this->aiTranscriptText = '';
        $this->aiTranscriptReplacementToken = null;
        $this->aiTranscriptQuestions = $this->aiTranscriptAnswers = [];
    }

    /** @return list<string> */
    protected function aiHeaderFields(): array
    {
        return property_exists($this, 'purchase_date')
            ? ['vendor_id', 'warehouse_id', 'vendor_invoice_number', 'purchase_date', 'due_date', 'currency_code', 'exchange_rate', 'document_locale', 'notes']
            : ['customer_id', 'warehouse_id', 'issue_date', 'due_date', 'currency_code', 'exchange_rate', 'document_locale', 'notes', 'terms'];
    }

    /** @return array<string, mixed> */
    protected function aiEditablePayload(): array
    {
        $payload = [];
        foreach ($this->aiHeaderFields() as $field) {
            $payload[$field] = $this->{$field};
        }
        $purchase = property_exists($this, 'purchase_date');
        $fields = ['product_id', 'product_unit_id', 'item_description', 'quantity',
            $purchase ? 'unit_cost' : 'unit_price', 'discount_type', 'discount_value', 'tax_rate_id'];
        if ($purchase) {
            $fields[] = 'lots';
        }
        $payload['lines'] = array_map(fn ($line) => array_intersect_key($line, array_flip($fields)), $this->lines);

        return $payload;
    }
}
