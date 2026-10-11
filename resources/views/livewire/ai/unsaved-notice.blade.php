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
        <button type="button" wire:click="discardAiInput" class="min-h-11 px-2 font-semibold underline underline-offset-4">{{ __('assistant_v2.discard_memory') }}</button>
        @php($aiPolicy = app(\App\Application\AI\AiGovernance::class)->authorizeUse(app(\App\Support\Tenancy\CompanyContext::class)->company(), auth()->user(), property_exists($this, 'purchase_date') ? 'purchase' : 'sales'))
        @if ($aiPolicy->follow_up_enabled)
            <div class="space-y-2 border-t border-info/20 pt-3"
                x-data="transactionRecorder($wire, @js(['url' => route('assistant.audio'), 'maxSeconds' => app(\App\Application\AI\AiAudioLimits::class)->current()['max_seconds'], 'method' => app(\App\Application\AI\AiSpeechMethods::class)->effective($aiPolicy, auth()->user()), 'locale' => app()->getLocale(), 'correction' => true, 'changedError' => __('assistant_v2.error_form_changed'), 'consent' => true, 'formKey' => $aiFormKey, 'context' => property_exists($this, 'purchase_date') ? 'purchase' : 'sales', 'permissionError' => __('assistant.microphone_error'), 'deliveryError' => __('assistant.unavailable'), 'limitError' => __('assistant_v2.recording_limit')]))">
                <label for="ai-correction-{{ $this->getId() }}" class="block font-semibold">{{ __('assistant_v2.correct_current_form') }}</label>
                <input id="ai-correction-{{ $this->getId() }}" wire:model="aiCorrectionText" maxlength="4000"
                    placeholder="{{ __('assistant_v2.correction_example') }}" class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-base" :disabled="recording || busy" />
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="correctAiInput" wire:loading.attr="disabled" :disabled="recording || busy" class="min-h-11 rounded-control border border-border bg-surface px-3 font-semibold disabled:opacity-50">{{ __('assistant_v2.apply_correction') }}</button>
                    @if (config('ai_assistant.speech_enabled') === true && $aiPolicy->speech_method !== 'disabled')
                        <button type="button" x-show="!recording" @click="start()" :disabled="busy" class="min-h-11 rounded-control border border-border px-3 font-semibold">{{ __('assistant_v2.record_correction') }}</button>
                        <button type="button" x-show="recording" x-cloak @click="stop(true)" class="min-h-11 rounded-control bg-danger px-3 font-semibold text-white">{{ __('assistant_v2.stop_continue') }}</button>
                        <button type="button" x-show="recording || busy" x-cloak @click="clear(); $wire.cancelAiCorrection()" class="min-h-11 px-3 underline">{{ __('assistant_v2.cancel') }}</button>
                    @endif
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
