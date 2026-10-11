@if ($aiFormToken !== null)
    <section x-data x-effect="const form = $el.closest('form') || $el.parentElement; const fields = [...$wire.aiChangedFields, ...$wire.aiUnresolvedFields]; for (const input of form.querySelectorAll('input, select, textarea')) { const name = Array.from(input.attributes).find(a => a.name.startsWith('wire:model'))?.value; input.classList.toggle('ring-2', fields.includes(name)); input.classList.toggle('ring-warning', fields.includes(name)); }" aria-label="{{ __('assistant_v2.unsaved') }}" class="space-y-2 rounded-control bg-info-bg p-3 text-sm text-info">
        <p class="font-semibold">{{ __('assistant_v2.unsaved') }}</p>
        <p>{{ __('assistant_v2.review_before_save') }}</p>
        @if ($aiUnresolvedFields !== [])
            <p>{{ __('assistant_v2.complete_missing') }}</p>
            <ul class="list-disc ps-5">
                @foreach ($aiUnresolvedFields as $field)
                    <li>{{ __('assistant_v2.field_'.preg_replace('/^lines\.\d+\./', '', $field)) }}</li>
                @endforeach
            </ul>
        @endif
        <details>
            <summary class="cursor-pointer py-1 font-semibold">{{ __('assistant_v2.sources') }}</summary>
            <dl class="mt-2 grid grid-cols-1 gap-1 sm:grid-cols-2">
                @foreach ($aiFieldOrigins as $field => $origin)
                    <div><dt class="inline font-semibold">{{ __('assistant_v2.field_'.preg_replace('/^lines\.\d+\./', '', $field)) }}:</dt> <dd class="inline">{{ __('assistant_v2.'.$origin) }}</dd></div>
                @endforeach
            </dl>
        </details>

        <div class="space-y-2" x-data="transactionRecorder($wire, @js(['correction' => true, 'method' => 'disabled', 'changedError' => __('assistant_v2.error_form_changed'), 'deliveryError' => __('assistant.unavailable')]))">
            @if (! $aiTranscriptOpen)
                <button type="button" wire:click="editAiTranscript" class="min-h-11 px-2 font-semibold underline underline-offset-4">{{ __('assistant_v2.edit_transcript') }}</button>
            @else
                <label for="ai-transcript-{{ $this->getId() }}" class="block font-semibold">{{ __('assistant_v2.edit_transcript') }}</label>
                <textarea id="ai-transcript-{{ $this->getId() }}" wire:model="aiTranscriptText" rows="3" maxlength="4000" :disabled="busy"
                    class="w-full resize-y rounded-control border border-border bg-surface px-3 py-2 text-base text-text-primary"></textarea>
                <p>{{ __('assistant_v2.transcript_usage') }}</p>
                <label class="flex items-start gap-2">
                    <input type="checkbox" wire:model="aiTranscriptReplaceConfirmed" :disabled="busy" class="mt-1 h-5 w-5 shrink-0" />
                    <span>{{ __('assistant_v2.transcript_replace_warning') }}</span>
                </label>
                @if ($aiTranscriptQuestions === [])
                    <button type="button" @click="applyTyped(true)" :disabled="busy" class="min-h-11 rounded-control border border-border bg-surface px-3 font-semibold">{{ __('assistant_v2.reprocess_transcript') }}</button>
                @else
                    <p class="font-semibold">{{ __('assistant_v2.clarification') }}</p>
                    @foreach ($aiTranscriptQuestions as $index => $question)
                        <label class="block font-semibold">{{ __('assistant_v2.question', ['field' => __('assistant_v2.field_'.preg_replace('/^lines\.\d+\./', '', $question['path']))]) }}
                            @if ($question['candidates'] !== [])
                                <select wire:model="aiTranscriptAnswers.{{ $index }}" :disabled="busy" class="mt-1 min-h-11 w-full rounded-control border border-border bg-surface px-2 text-text-primary">
                                    <option value="">{{ __('assistant_v2.choose') }}</option>
                                    @foreach ($question['candidates'] as $candidate)
                                        <option value="{{ $candidate['value'] }}">{{ $candidate['label'] }}</option>
                                    @endforeach
                                </select>
                            @elseif ($question['path'] === 'currency')
                                <select wire:model="aiTranscriptAnswers.{{ $index }}" :disabled="busy" class="mt-1 min-h-11 w-full rounded-control border border-border bg-surface px-2 text-text-primary">
                                    <option value="">{{ __('assistant_v2.choose') }}</option>
                                    @foreach (['ILS', 'USD', 'JOD'] as $currency)<option value="{{ $currency }}">{{ $currency }}</option>@endforeach
                                </select>
                            @elseif ($question['path'] === 'exchange_rate' || preg_match('/\Alines\.\d+\.quantity\z/', $question['path']))
                                <input type="text" inputmode="decimal" wire:model="aiTranscriptAnswers.{{ $index }}" :disabled="busy" class="mt-1 min-h-11 w-full rounded-control border border-border bg-surface px-2 text-text-primary" />
                            @else
                                <span class="block font-normal">{{ __('assistant_v2.revise_description') }}</span>
                            @endif
                        </label>
                    @endforeach
                    <button type="button" @click="applyTyped(true, true)" :disabled="busy" class="min-h-11 rounded-control border border-border bg-surface px-3 font-semibold">{{ __('assistant_v2.continue') }}</button>
                @endif
                <button type="button" @click="clear(); $wire.closeAiTranscript()" class="min-h-11 px-2 font-semibold underline">{{ __('assistant_v2.cancel') }}</button>
                <p x-show="busy" x-cloak role="status">{{ __('assistant.working') }}</p>
                <p x-show="error" x-cloak x-text="error" role="alert" class="text-danger"></p>
                @if ($aiCorrectionError)<p role="alert" class="text-danger">{{ $aiCorrectionError }}</p>@endif
            @endif
        </div>
        <button type="button" wire:click="discardAiInput" class="min-h-11 px-2 font-semibold underline underline-offset-4">{{ __('assistant_v2.discard_memory') }}</button>
        @php($aiPolicy = app(\App\Application\AI\AiGovernance::class)->authorizeUse(app(\App\Support\Tenancy\CompanyContext::class)->company(), auth()->user(), property_exists($this, 'purchase_date') ? 'purchase' : 'sales'))
        @if ($aiPolicy->follow_up_enabled)
            <div class="space-y-2 border-t border-info/20 pt-3"
                x-data="transactionRecorder($wire, @js(['url' => route('assistant.audio'), 'maxSeconds' => app(\App\Application\AI\AiAudioLimits::class)->current()['max_seconds'], 'method' => app(\App\Application\AI\AiSpeechMethods::class)->effective($aiPolicy, auth()->user()), 'locale' => app()->getLocale(), 'correction' => true, 'changedError' => __('assistant_v2.error_form_changed'), 'consent' => true, 'formKey' => $aiFormKey, 'context' => property_exists($this, 'purchase_date') ? 'purchase' : 'sales', 'permissionError' => __('assistant.microphone_error'), 'deliveryError' => __('assistant.unavailable'), 'limitError' => __('assistant_v2.recording_limit')]))">
                <label for="ai-correction-{{ $this->getId() }}" class="block font-semibold">{{ __('assistant_v2.correct_current_form') }}</label>
                <input id="ai-correction-{{ $this->getId() }}" wire:model="aiCorrectionText" maxlength="4000"
                    placeholder="{{ __('assistant_v2.correction_example') }}" class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-base" :disabled="recording || busy" />
                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="applyTyped()" :disabled="recording || busy" class="min-h-11 rounded-control border border-border bg-surface px-3 font-semibold disabled:opacity-50">{{ __('assistant_v2.apply_correction') }}</button>
                    @if (config('ai_assistant.speech_enabled') === true && $aiPolicy->speech_method !== 'disabled')
                        <button type="button" x-show="!recording" @click="start()" :disabled="busy" class="min-h-11 rounded-control border border-border px-3 font-semibold">{{ __('assistant_v2.record_correction') }}</button>
                        <button type="button" x-show="recording" x-cloak @click="stop(true)" class="min-h-11 rounded-control bg-danger px-3 font-semibold text-white">{{ __('assistant_v2.stop_continue') }}</button>
                    @endif
                        <button type="button" x-show="recording || busy" x-cloak @click="clear(); $wire.cancelAiCorrection()" class="min-h-11 px-3 underline">{{ __('assistant_v2.cancel') }}</button>
                    @if ($aiCanUndo)
                        <button type="button" wire:click="undoAiInput" wire:loading.attr="disabled" class="min-h-11 rounded-control border border-border bg-surface px-3 font-semibold">{{ __('assistant_v2.undo') }}</button>
                    @endif
                </div>
                <p class="text-text-secondary">{{ __('assistant_v2.correction_help') }}</p>
                @if ($aiChangedFields !== [])
                    <p role="status" class="font-semibold">{{ __('assistant_v2.fields_changed') }}</p>
                    <ul class="list-disc ps-5">
                        @foreach ($aiChangedFields as $field)
                            <li>{{ __('assistant_v2.field_'.preg_replace('/^lines\.\d+\./', '', $field)) }}</li>
                        @endforeach
                    </ul>
                @endif
                <p x-show="busy" x-cloak role="status">{{ __('assistant.working') }}</p>
                <p x-show="error" x-cloak x-text="error" role="alert" class="text-danger"></p>
                @if ($aiCorrectionError)<p role="alert" class="text-danger">{{ $aiCorrectionError }}</p>@endif
            </div>
        @endif
    </section>
@endif
