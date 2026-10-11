<div>
    @if ($available)
        <button type="button" wire:click="showAssistant" class="inline-flex min-h-11 items-center gap-2 rounded-control border border-border bg-surface px-3 py-2 text-sm font-semibold text-primary hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
            <x-icon name="plus" class="h-4 w-4" />
            {{ __('assistant_v2.open') }}
        </button>
        @if ($open)
            <section role="dialog" aria-modal="true" aria-labelledby="assistant-title-{{ $this->getId() }}"
                class="fixed inset-0 z-[70] flex items-start justify-center overflow-y-auto bg-slate-900/40 p-3 pt-16 sm:p-6 sm:pt-20"
                x-data="transactionRecorder($wire, @js(['url' => route('assistant.audio'), 'maxSeconds' => app(\App\Application\AI\AiAudioLimits::class)->current()['max_seconds'], 'method' => $speechMethod, 'locale' => app()->getLocale(), 'permissionError' => __('assistant.microphone_error'), 'deliveryError' => __('assistant.unavailable'), 'limitError' => __('assistant_v2.recording_limit')]))"
                x-init="$nextTick(() => $refs.description.focus())"
                x-trap.inert.noscroll="true"
                @keydown.escape.stop.prevent="clear(); $wire.cancel()">
                <div class="w-full max-w-xl rounded-card bg-surface p-4 sm:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 id="assistant-title-{{ $this->getId() }}" class="text-lg font-bold">{{ __('assistant_v2.title') }}</h2>
                            <p class="mt-1 text-sm text-text-secondary">{{ __('assistant_v2.unsaved_help') }}</p>
                        </div>
                        <button type="button" @click="clear(); $wire.cancel()" class="min-h-11 shrink-0 px-2 text-sm text-text-secondary hover:text-text-primary">{{ __('assistant_v2.cancel') }}</button>
                    </div>

                    <details class="mt-4 text-sm">
                        <summary class="cursor-pointer py-2 font-semibold">{{ __('assistant_v2.privacy_connections') }}</summary>
                        <div class="space-y-3 py-3">
                            <p class="break-words text-text-secondary">{{ $privacy['operator'] ?? '' }} — {{ $privacy['retention'] ?? '' }}</p>
                            @if (!empty($privacy['policy_url']))
                                <a href="{{ $privacy['policy_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-block text-primary underline underline-offset-4">{{ __('assistant_v2.privacy_policy') }}</a>
                            @endif
                            <ul class="space-y-2">
                                @foreach ($consentRoutes as $route)
                                    <li class="break-words text-text-secondary"><b>{{ $route['provider'] }} / {{ $route['model'] }}</b> · {{ $route['region'] }} · {{ __('assistant_v2.'.$route['credential_source']) }} · {{ __('assistant_v2.configured_unverified') }}</li>
                                @endforeach
                            </ul>
                            <label class="flex items-start gap-2">
                                <input type="checkbox" wire:model="consent" class="mt-1 rounded border-border" />
                                <span>{{ __('assistant_v2.consent') }}</span>
                            </label>
                            <button type="button" wire:click="acceptConsent" wire:loading.attr="disabled" class="min-h-11 rounded-control border border-border px-3 text-primary disabled:opacity-50">{{ __('assistant_v2.save_consent') }}</button>
                            @foreach (['textConnection' => 'text_structured', 'speechConnection' => 'audio_transcription'] as $field => $capability)
                                <label class="block">{{ __('assistant_v2.'.$capability) }}
                                    <select wire:model="{{ $field }}" class="mt-1 min-h-11 w-full rounded-control border border-border px-2">
                                        <option value="">{{ __('assistant_v2.company_selection') }}</option>
                                        @foreach ($consentRoutes as $route)
                                            @if ($route['capability'] === $capability)
                                                <option value="{{ $route['public_id'] }}">{{ $route['provider'] }} · {{ $route['model'] }} · {{ __('assistant_v2.'.$route['credential_source']) }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                            <button type="button" wire:click="selectConnections" class="min-h-11 px-3 text-primary">{{ __('assistant_v2.save_selection') }}</button>
                        </div>
                    </details>

                    @if ($questions === [])
                        <label for="assistant-description-{{ $this->getId() }}" class="mt-4 block text-sm font-semibold">{{ __('assistant_v2.description') }}</label>
                        <textarea x-ref="description" id="assistant-description-{{ $this->getId() }}" wire:model="text" rows="4" maxlength="4000"
                            class="mt-2 w-full resize-y rounded-control border border-border bg-canvas px-3 py-2 text-base leading-relaxed"
                            placeholder="{{ __('assistant_v2.example') }}" :disabled="recording || busy"></textarea>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="prepare" wire:loading.attr="disabled" :disabled="recording || busy" class="min-h-11 rounded-control bg-primary px-4 text-sm font-bold text-white hover:bg-primary-hover disabled:opacity-50">{{ __('assistant_v2.open_form') }}</button>
                            @if (config('ai_assistant.speech_enabled') === true)
                                <button type="button" x-show="!recording" @click="start()" :disabled="busy" class="min-h-11 rounded-control border border-border px-4 text-sm font-semibold disabled:opacity-50">{{ __('assistant_v2.record') }}</button>
                                <button type="button" x-show="recording" x-cloak @click="stop(true)" class="min-h-11 rounded-control bg-danger px-4 text-sm font-bold text-white">{{ __('assistant_v2.stop_continue') }}</button>
                            @endif
                        </div>
                        @if ($mode === 'guided')
                            <p class="mt-2 text-sm text-text-secondary">{{ __('assistant_v2.guided_help') }}</p>
                        @else
                            <p class="mt-2 text-sm text-text-secondary">{{ __('assistant_v2.seamless_help') }}</p>
                        @endif
                    @else
                        <h3 class="mt-5 font-semibold">{{ __('assistant_v2.clarification') }}</h3>
                        <p class="mt-1 text-sm text-text-secondary">{{ __('assistant_v2.keep_resolved') }}</p>
                        <div class="mt-3 space-y-3">
                            @foreach ($questions as $index => $question)
                                <label class="block text-sm font-semibold">{{ __('assistant_v2.question', ['field' => __('assistant_v2.field_'.preg_replace('/^lines\.\d+\./', '', $question['path']))]) }}
                                    @if ($question['candidates'] !== [])
                                        <select wire:model="answers.{{ $index }}" class="mt-1 min-h-11 w-full rounded-control border border-border px-2">
                                            <option value="">{{ __('assistant_v2.choose') }}</option>
                                            @foreach ($question['candidates'] as $candidate)
                                                <option value="{{ $candidate['value'] }}">{{ $candidate['label'] }}</option>
                                            @endforeach
                                        </select>
                                    @elseif (in_array($question['path'], ['currency', 'exchange_rate'], true) || str_ends_with($question['path'], '.quantity'))
                                        <input wire:model="answers.{{ $index }}" maxlength="32" dir="ltr" class="mt-1 min-h-11 w-full rounded-control border border-border px-3" />
                                    @else
                                        <span class="mt-1 block text-sm font-normal text-text-secondary">{{ __('assistant_v2.revise_description') }}</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                        <button type="button" wire:click="clarify" wire:loading.attr="disabled" class="mt-4 min-h-11 rounded-control bg-primary px-4 text-sm font-bold text-white disabled:opacity-50">{{ __('assistant_v2.continue') }}</button>
                        <button type="button" wire:click="reviseDescription" class="mt-3 min-h-11 px-3 text-sm font-semibold underline">{{ __('assistant_v2.revise_description') }}</button>
                    @endif
                    <p x-show="recording" x-cloak role="status" class="mt-3 text-sm font-semibold text-danger">{{ __('assistant.recording') }} <span x-text="seconds" class="num"></span> / <span class="num">{{ app(\App\Application\AI\AiAudioLimits::class)->current()['max_seconds'] }}</span> {{ __('assistant.seconds') }}</p>
                    <p x-show="busy" x-cloak role="status" class="mt-3 text-sm text-text-secondary">{{ __('assistant.working') }}</p>
                    <p wire:loading wire:target="prepare,clarify,receiveTranscript" role="status" class="mt-3 text-sm text-text-secondary">{{ __('assistant.working') }}</p>
                    <p x-show="error" x-cloak x-text="error" role="alert" class="mt-3 text-sm text-danger"></p>
                    @if ($error)<p role="alert" class="mt-3 text-sm text-danger">{{ $error }}</p>@endif
                </div>
            </section>
        @endif
    @endif
</div>
