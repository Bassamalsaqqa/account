<div>
    @if ($available)
        <button type="button" wire:click="showAssistant" class="inline-flex min-h-11 items-center gap-2 rounded-control border border-border bg-surface px-3 py-2 text-sm font-semibold text-primary hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
            <x-icon name="plus" class="h-4 w-4" />
            {{ __($questionsAvailable ? 'assistant_v2.unified_title' : 'assistant_v2.open') }}
        </button>
        @if ($open)
            <section role="dialog" aria-modal="true" aria-labelledby="assistant-title-{{ $this->getId() }}"
                class="fixed inset-0 z-[70] flex items-start justify-center overflow-y-auto bg-slate-900/40 p-3 pt-16 sm:p-6 sm:pt-20"
                x-data="transactionRecorder($wire, @js(['url' => route('assistant.audio'), 'maxSeconds' => app(\App\Application\AI\AiAudioLimits::class)->current()['max_seconds'], 'method' => $speechMethod, 'locale' => app()->getLocale(), 'permissionError' => __('assistant.microphone_error'), 'deliveryError' => __('assistant.unavailable'), 'limitError' => __('assistant_v2.recording_limit')]))"
                x-init="$nextTick(() => $refs.description?.focus())"
                x-trap.inert.noscroll="true"
                @keydown.escape.stop.prevent="clear(); $wire.cancel()">
                <div class="w-full max-w-xl rounded-card bg-surface p-4 sm:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 id="assistant-title-{{ $this->getId() }}" class="text-lg font-bold">{{ __($questionsAvailable ? 'assistant_v2.unified_title' : 'assistant_v2.title') }}</h2>
                            <p class="mt-1 text-sm text-text-secondary">{{ __($questionsAvailable ? 'assistant_v2.unified_help' : 'assistant_v2.unsaved_help') }}</p>
                        </div>
                        <button type="button" @click="clear(); $wire.cancel()" class="min-h-11 shrink-0 px-2 text-sm text-text-secondary hover:text-text-primary">{{ __('assistant_v2.cancel') }}</button>
                    </div>

                    <details class="mt-4 text-sm" @if ($error === __('assistant_v2.error_provider_selection')) open @endif>
                        <summary class="cursor-pointer py-2 font-semibold">{{ __('assistant_v2.privacy_connections') }}</summary>
                        <div class="space-y-3 py-3">
                            <p class="break-words text-text-secondary">{{ $privacy['operator'] ?? '' }} — {{ $privacy['retention'] ?? '' }}</p>
                            @if (!empty($privacy['policy_url']))
                                <a href="{{ $privacy['policy_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-block text-primary underline underline-offset-4">{{ __('assistant_v2.privacy_policy') }}</a>
                            @endif
                            <ul class="space-y-2">
                                @foreach ($consentRoutes as $route)
                                    <li class="break-words text-text-secondary"><b>{{ $route['provider'] }} / {{ $route['model'] }}</b> · {{ $route['region'] }} · {{ __('assistant_v2.'.$route['capability']) }} · {{ __('assistant_v2.route_'.$route['role']) }} · {{ __('assistant_v2.'.$route['credential_source']) }} · {{ __('assistant_v2.configured_unverified') }}</li>
                                @endforeach
                            </ul>
                            <p class="text-text-secondary">{{ __('assistant_v2.selection_consent_help') }}</p>
                            <label class="flex items-start gap-2">
                                <input type="checkbox" wire:model="consent" class="mt-1 rounded border-border" />
                                <span>{{ ! $transactionsAvailable && $questionsAvailable ? __('assistant_v2.question_consent') : __('assistant_v2.consent') }}</span>
                            </label>
                            <button type="button" wire:click="acceptConsent" wire:loading.attr="disabled" class="min-h-11 rounded-control border border-border px-3 text-primary disabled:opacity-50">{{ __('assistant_v2.save_consent') }}</button>
                            @if (!empty($questionConsentRoutes))
                                <div class="mt-4 border-t border-border pt-3">
                                    <h4 class="font-semibold text-text-primary">{{ __('assistant_v2.questions_policy') }}</h4>
                                    <p class="mt-1 text-xs text-text-secondary">{{ __('assistant_v2.selection_consent_help') }}</p>
                                    <ul class="mt-2 space-y-1">
                                        @foreach ($questionConsentRoutes as $qRoute)
                                            <li class="break-words text-xs text-text-secondary"><b>{{ $qRoute['provider'] }} / {{ $qRoute['model'] }}</b> · {{ $qRoute['region'] }} · {{ __('assistant_v2.'.$qRoute['capability']) }}</li>
                                        @endforeach
                                    </ul>
                                    <label class="mt-2 flex items-start gap-2">
                                        <input type="checkbox" wire:model="questionConsent" class="mt-1 rounded border-border" />
                                        <span class="text-xs">{{ __('assistant_v2.question_consent') }}</span>
                                    </label>
                                    <button type="button" wire:click="acceptQuestionConsent" wire:loading.attr="disabled" class="mt-2 min-h-9 rounded-control border border-border px-3 text-xs font-semibold text-primary disabled:opacity-50">{{ __('assistant_v2.save_question_consent') }}</button>
                                </div>
                            @endif
                            @foreach (['textConnection' => 'text_structured', 'speechConnection' => 'audio_transcription'] as $field => $capability)
                                <label class="block">{{ __('assistant_v2.'.$capability) }}
                                    <select wire:model="{{ $field }}" class="mt-1 min-h-11 w-full rounded-control border border-border px-2">
                                        @if ($this->$field && !in_array($this->$field, array_column($availableConnections, 'public_id'), true))
                                            <option value="{{ $this->$field }}" disabled>{{ __('assistant_v2.error_unavailable') }}</option>
                                        @endif
                                        <option value="">{{ __('assistant_v2.company_selection') }}</option>
                                        @foreach ($availableConnections as $route)
                                            @if ($route['capability'] === $capability)
                                                <option value="{{ $route['public_id'] }}">{{ $route['provider'] }} · {{ $route['model'] }} · {{ __('assistant_v2.'.$route['credential_source']) }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                            <label class="block">{{ __('assistant_v2.speech_method') }}
                                <select wire:model="transcriptionChoice" class="mt-1 min-h-11 w-full rounded-control border border-border px-2">
                                    <option value="">{{ __('assistant_v2.company_selection') }}</option>
                                    @foreach ($allowedSpeechMethods as $method)<option value="{{ $method }}">{{ __('assistant_v2.'.$method) }}</option>@endforeach
                                </select>
                            </label>
                            <button type="button" wire:click="selectConnections" @click="clear()" class="min-h-11 px-3 text-primary">{{ __('assistant_v2.save_selection') }}</button>
                        </div>
                    </details>

                    @if ($reportResult !== null)
                        <div class="mt-4 space-y-3">
                            <div class="rounded-card border border-border bg-canvas p-4">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <span class="inline-block rounded bg-primary-50 px-2 py-0.5 text-xs font-semibold text-primary">
                                            {{ __('assistant_v2.report_result') }}
                                        </span>
                                        <h3 class="mt-1 text-base font-bold text-text-primary">{{ $reportResult['title'] }}</h3>
                                        <p class="text-xs text-text-secondary">
                                            @if (!empty($reportResult['is_current']))
                                                {{ __('assistant_v2.as_of') }} <span class="font-mono" dir="ltr">{{ $reportResult['as_of'] ?? $reportResult['generated_at'] }}</span> ({{ $reportResult['timezone'] }})
                                            @else
                                                {{ __('assistant_v2.preset_'.($reportResult['period_label'] ?? '')) }}
                                                @if (!empty($reportResult['period']))
                                                    (<span class="font-mono" dir="ltr">{{ $reportResult['period']['start_date'] }}</span> – <span class="font-mono" dir="ltr">{{ $reportResult['period']['end_date'] }}</span>)
                                                @endif
                                                ({{ $reportResult['timezone'] }})
                                            @endif
                                            @if (!empty($reportResult['scope']))
                                                · <span class="text-text-secondary">{{ __('assistant_v2.account_scope') }}: {{ implode(' / ', array_map(fn ($type) => __('money.'.$type), $reportResult['scope']['account_types'] ?? [])) }}</span>
                                            @endif
                                        </p>
                                        <p class="text-xs text-text-secondary">{{ __('assistant_v2.generated_at') }}: <span dir="ltr">{{ $reportResult['generated_at'] }}</span></p>
                                        <p class="text-xs text-text-secondary">{{ __('assistant_v2.base_currency') }}: <span dir="ltr">{{ $reportResult['currency']['base_currency_code'] }}</span> · {{ __('assistant_v2.currency_filter') }}: <span dir="ltr">{{ $reportResult['currency']['filter_currency_code'] ?? __('assistant_v2.all_currencies') }}</span></p>
                                    </div>
                                    @if (!empty($reportResult['source_url']))
                                        <a href="{{ $reportResult['source_url'] }}" target="_blank" rel="noopener noreferrer"
                                            class="inline-flex min-h-9 items-center gap-1 rounded-control border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-primary hover:bg-primary-50">
                                            {{ __('assistant_v2.view_full_report') }}
                                            <span aria-hidden="true">&nearr;</span>
                                        </a>
                                    @endif
                                </div>

                                @php
                                    $baseCurr = $reportResult['currency']['base_currency_code'] ?? ($reportResult['currency']['base_currency'] ?? ($reportResult['base_currency_code'] ?? ''));
                                    $renderedTotals = !empty($reportTotals)
                                        ? $reportTotals
                                        : (!empty($reportResult['totals']) ? app(\App\Application\Reporting\Presentation\ReportPresenter::class)->totals($reportResult['totals'], $baseCurr) : []);
                                @endphp

                                @if (!empty($renderedTotals))
                                    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                        @foreach ($renderedTotals as $totalItem)
                                            <div class="rounded-control border border-border/60 bg-surface p-2">
                                                <span class="block text-xs text-text-secondary">{{ $totalItem['label'] }}</span>
                                                <span class="block font-mono text-sm font-bold text-text-primary" dir="ltr">
                                                    @if ($totalItem['value'] === null)
                                                        <span class="text-text-secondary">—</span>
                                                    @else
                                                        {{ $totalItem['value'] }}@if (!empty($totalItem['currency'])) <span class="text-xs font-normal text-text-secondary">{{ $totalItem['currency'] }}</span>@endif
                                                    @endif
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                @if (!empty($reportResult['rows']))
                                    <div class="mt-3 max-h-56 overflow-auto rounded border border-border">
                                        <table class="w-full text-start text-xs">
                                            <thead class="sticky top-0 bg-surface border-b border-border text-text-secondary">
                                                <tr>
                                                    @foreach (array_keys($reportResult['rows'][0]) as $col)
                                                        @php
                                                            $columnKey = (string) $col;
                                                            $columnLabel = \Illuminate\Support\Facades\Lang::has('reports.columns.'.$columnKey)
                                                                ? __('reports.columns.'.$columnKey)
                                                                : (\Illuminate\Support\Facades\Lang::has('reports.column_'.$columnKey)
                                                                    ? __('reports.column_'.$columnKey)
                                                                    : (\Illuminate\Support\Facades\Lang::has('assistant_v2.col_'.$columnKey)
                                                                        ? __('assistant_v2.col_'.$columnKey)
                                                                        : ucwords(str_replace('_', ' ', $columnKey))));
                                                            if ((str_contains($columnKey, '_base') || str_starts_with($columnKey, 'base_')) && $baseCurr !== '') {
                                                                $columnLabel .= ' (' . $baseCurr . ')';
                                                            }
                                                        @endphp
                                                        <th class="px-2.5 py-1.5 font-semibold text-start">{{ $columnLabel }}</th>
                                                    @endforeach
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-border/60 bg-surface">
                                                @foreach ($reportResult['rows'] as $row)
                                                    <tr class="hover:bg-primary-50/30">
                                                        @foreach ($row as $val)
                                                            <td class="px-2.5 py-1.5 text-text-primary {{ is_numeric($val) ? 'font-mono' : '' }}" {{ is_numeric($val) ? 'dir=ltr' : '' }}>
                                                                @if (is_array($val))
                                                                    @foreach ($val as $subKey => $subVal)
                                                                        <span class="inline-block font-mono mr-1" dir="ltr">{{ is_scalar($subVal) ? (string) $subVal : '' }} {{ is_string($subKey) ? $subKey : '' }}</span>
                                                                    @endforeach
                                                                @elseif ($val === null)
                                                                    <span class="text-text-secondary">{{ __('assistant_v2.value_unavailable') }}</span>
                                                                @elseif (is_bool($val))
                                                                    {{ __($val ? 'assistant_v2.value_yes' : 'assistant_v2.value_no') }}
                                                                @else
                                                                    {{ (string) $val }}
                                                                @endif
                                                            </td>
                                                        @endforeach
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    @if ($reportResult['total_rows'] > count($reportResult['rows']))
                                        <p class="mt-1.5 text-xs text-text-secondary">
                                            {{ __('assistant_v2.sample_rows_count', ['shown' => count($reportResult['rows']), 'total' => $reportResult['total_rows']]) }}
                                        </p>
                                    @endif
                                @elseif (empty($renderedTotals))
                                    <p class="mt-3 text-sm text-text-secondary">{{ __('assistant_v2.no_data') }}</p>
                                @endif
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="resetQuestion" class="min-h-11 rounded-control border border-border bg-surface px-4 text-sm font-semibold text-text-primary hover:bg-slate-100">
                                    {{ __('assistant_v2.ask_another') }}
                                </button>
                            </div>

                            <div class="pt-2">
                                <label for="assistant-followup-{{ $this->getId() }}" class="block text-xs font-semibold text-text-secondary">{{ __('assistant_v2.unified_description') }}</label>
                                <textarea id="assistant-followup-{{ $this->getId() }}" wire:model="text" rows="2" maxlength="4000"
                                    class="mt-1 w-full resize-y rounded-control border border-border bg-canvas px-3 py-2 text-sm leading-relaxed"
                                    placeholder="{{ __('assistant_v2.question_example') }}"></textarea>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button type="button" wire:click="prepare" wire:loading.attr="disabled" class="min-h-9 rounded-control bg-primary px-3 text-xs font-bold text-white hover:bg-primary-hover disabled:opacity-50">{{ __('assistant_v2.continue') }}</button>
                                </div>
                            </div>
                        </div>
                    @elseif ($clarifyPeriodReport !== null)
                        <div class="mt-4 rounded-card border border-border bg-canvas p-4">
                            <h3 class="text-sm font-bold text-text-primary">{{ __('assistant_v2.clarify_period') }}</h3>
                            <p class="mt-1 text-xs text-text-secondary">{{ __('assistant_v2.report_'.$clarifyPeriodReport) }}</p>
                            <div class="mt-3 grid grid-cols-3 gap-2">
                                @foreach (['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'this_year', 'last_year'] as $preset)
                                    <button type="button" wire:click="selectPeriod('{{ $preset }}')" wire:loading.attr="disabled"
                                        class="min-h-11 rounded-control border border-border bg-surface px-2 py-1.5 text-xs font-semibold text-primary hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-primary">
                                        {{ __('assistant_v2.preset_'.$preset) }}
                                    </button>
                                @endforeach
                            </div>
                            <button type="button" wire:click="resetQuestion" class="mt-3 min-h-9 px-2 text-xs font-semibold text-text-secondary hover:text-text-primary underline">
                                {{ __('assistant_v2.cancel') }}
                            </button>
                        </div>
                    @elseif ($ambiguousChoice !== null)
                        <div class="mt-4 rounded-card border border-border bg-canvas p-4">
                            <h3 class="text-sm font-bold text-text-primary">{{ __('assistant_v2.operation_ambiguous') }}</h3>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" wire:click="chooseOperation('transaction')" wire:loading.attr="disabled"
                                    class="min-h-11 rounded-control bg-primary px-4 text-sm font-bold text-white hover:bg-primary-hover">
                                    {{ __('assistant_v2.op_draft') }}
                                </button>
                                <button type="button" wire:click="chooseOperation('report')" wire:loading.attr="disabled"
                                    class="min-h-11 rounded-control border border-border bg-surface px-4 text-sm font-bold text-primary hover:bg-primary-50">
                                    {{ __('assistant_v2.op_report') }}
                                </button>
                                <button type="button" wire:click="resetQuestion" class="min-h-11 px-3 text-sm text-text-secondary hover:text-text-primary underline">
                                    {{ __('assistant_v2.cancel') }}
                                </button>
                            </div>
                        </div>
                    @elseif ($questions === [])
                        <label for="assistant-description-{{ $this->getId() }}" class="mt-4 block text-sm font-semibold">{{ __($questionsAvailable ? 'assistant_v2.unified_description' : 'assistant_v2.description') }}</label>
                        <textarea x-ref="description" id="assistant-description-{{ $this->getId() }}" wire:model="text" rows="4" maxlength="4000"
                            class="mt-2 w-full resize-y rounded-control border border-border bg-canvas px-3 py-2 text-base leading-relaxed"
                            placeholder="{{ __($questionsAvailable ? 'assistant_v2.question_example' : 'assistant_v2.example') }}" :disabled="recording || busy"></textarea>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="prepare" wire:loading.attr="disabled" :disabled="recording || busy" class="min-h-11 rounded-control bg-primary px-4 text-sm font-bold text-white hover:bg-primary-hover disabled:opacity-50">{{ __($questionsAvailable ? 'assistant_v2.continue' : 'assistant_v2.open_form') }}</button>
                            @if (config('ai_assistant.speech_enabled') === true && $speechMethod !== 'disabled')
                                <button type="button" x-show="!recording" @click="start()" :disabled="busy" class="min-h-11 rounded-control border border-border px-4 text-sm font-semibold disabled:opacity-50">{{ __('assistant_v2.record') }}</button>
                                <button type="button" x-show="recording" x-cloak @click="stop(true)" class="min-h-11 rounded-control bg-danger px-4 text-sm font-bold text-white">{{ __('assistant_v2.stop_continue') }}</button>
                            @endif
                        </div>
                        @if ($context === 'auto' && ($questionsAvailable ?? false) && ($transactionsAvailable ?? false) && config('ai_assistant.speech_enabled') === true && $speechMethod !== 'disabled')
                            <div class="mt-3 flex items-center gap-2">
                                <span class="text-xs font-semibold text-text-secondary">{{ __('assistant_v2.speech_target') }}:</span>
                                <button type="button" wire:click="selectAudioContext('question')"
                                    class="min-h-8 rounded-control px-2.5 py-1 text-xs font-semibold {{ ($audioContext ?? 'question') === 'question' ? 'bg-primary text-white' : 'border border-border bg-surface text-text-secondary hover:bg-slate-100' }}">
                                    {{ __('assistant_v2.speech_target_question') }}
                                </button>
                                <button type="button" wire:click="selectAudioContext('auto')"
                                    class="min-h-8 rounded-control px-2.5 py-1 text-xs font-semibold {{ ($audioContext ?? 'auto') === 'auto' ? 'bg-primary text-white' : 'border border-border bg-surface text-text-secondary hover:bg-slate-100' }}">
                                    {{ __('assistant_v2.speech_target_transaction') }}
                                </button>
                            </div>
                        @endif
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
                    <p wire:loading wire:target="prepare,clarify,receiveTranscript,selectPeriod,chooseOperation,selectAudioContext" role="status" class="mt-3 text-sm text-text-secondary">{{ __('assistant.working') }}</p>
                    <p x-show="error" x-cloak x-text="error" role="alert" class="mt-3 text-sm text-danger"></p>
                    @if ($error)<p role="alert" class="mt-3 text-sm text-danger">{{ $error }}</p>@endif
                </div>
            </section>
        @endif
    @endif
</div>
