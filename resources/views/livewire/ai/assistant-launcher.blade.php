<div class="ai-product" x-data @ai-open-assistant.window="$wire.showAssistant()">
    @if ($available)
        <button id="ai-launcher-{{ $this->getId() }}" type="button" wire:click="showAssistant" wire:loading.attr="disabled" wire:target="showAssistant"
            class="ai-launcher" aria-haspopup="dialog" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="ai-dialog-{{ $this->getId() }}">
            <x-icon name="sparkles" aria-hidden="true" />
            <span>{{ __('ai_product.launcher') }}</span>
            <x-icon name="microphone" class="ai-launcher-mic" aria-hidden="true" />
        </button>
        @if ($open)
            <section id="ai-dialog-{{ $this->getId() }}" role="dialog" aria-modal="true" aria-labelledby="assistant-title-{{ $this->getId() }}" class="ai-shell"
                x-data="accountingAssistant($wire, @js(['locale' => app()->getLocale(), 'maxSeconds' => 60, 'messages' => ['unsupported' => __('ai_product.voice_unsupported'), 'permission' => __('ai_product.voice_permission'), 'unavailable' => __('ai_product.voice_unavailable'), 'noSpeech' => __('ai_product.voice_no_speech')]]))"
                x-init="$nextTick(() => $refs.description?.focus())" x-trap.inert.noscroll="true"
                @keydown.escape.window.stop.prevent="clear(); $wire.cancel()">
                <div class="ai-backdrop" aria-hidden="true" @click="clear(); $wire.cancel()"></div>
                <div class="ai-sheet">
                    <header class="ai-header">
                        <div class="flex items-center gap-3">
                            <span class="ai-mark"><x-icon name="sparkles" aria-hidden="true" /></span>
                            <div>
                                <h2 id="assistant-title-{{ $this->getId() }}" class="text-base font-bold">{{ __('ai_product.title') }}</h2>
                                <p class="mt-0.5 text-xs text-text-secondary">{{ __('ai_product.unsaved_note') }}</p>
                            </div>
                        </div>
                        <button type="button" @click="clear(); $wire.cancel()" class="ai-icon-button shrink-0" aria-label="{{ __('ai_product.close') }}"><x-icon name="close" /></button>
                    </header>
                    <div class="ai-thread" aria-live="polite" aria-relevant="additions text">
                        <div x-show="sentText" x-cloak class="ai-user-turn" x-text="sentText"></div>
                        @if ($reportResult === null && $clarifyPeriodReport === null && $ambiguousChoice === null && $questions === [])
                            <div x-show="!sentText" class="ai-welcome">
                                <h3>{{ __('ai_product.welcome') }}</h3>
                                <p class="mt-2 text-sm leading-relaxed text-text-secondary">{{ __('ai_product.welcome_help') }}</p>
                                <div class="ai-suggestions">
                                    @if ($questionsAvailable)
                                        @foreach (['sales_question' => 'chart', 'purchases_question' => 'cart', 'inventory_question' => 'box'] as $suggestion => $icon)
                                            <button type="button" class="ai-suggestion" @click="suggest(@js(__('ai_product.'.$suggestion)))"><x-icon :name="$icon" /><span>{{ __('ai_product.'.$suggestion) }}</span></button>
                                        @endforeach
                                    @endif
                                    @if ($transactionsAvailable)
                                        <button type="button" class="ai-suggestion" @click="suggest(@js(__('ai_product.invoice_question')))"><x-icon name="receipt" /><span>{{ __('ai_product.invoice_question') }}</span></button>
                                    @endif
                                </div>
                            </div>
                        @endif
                    <details class="mt-4 text-sm" @if (! $consent || (! $questionConsent && $questionsAvailable) || $error === __('assistant_v2.error_provider_selection')) open @endif>
                        <summary class="cursor-pointer py-2 font-semibold">{{ __('ai_product.privacy') }}</summary>
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
                            @if ($error === __('assistant_v2.error_provider_selection'))
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
                            @endif
                        </div>
                    </details>

                    @if ($reportResult !== null)
                        <div class="mt-4 space-y-3">
                            <div class="rounded-card border border-border bg-canvas p-4">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h3 class="mt-1 text-base font-bold text-text-primary">{{ $reportResult['title'] }}</h3>
                                        <p class="text-xs text-text-secondary">
                                            @if (!empty($reportResult['is_current']))
                                                {{ __('assistant_v2.as_of') }} <span class="num" dir="ltr">{{ $reportResult['as_of'] ?? $reportResult['generated_at'] }}</span> ({{ $reportResult['timezone'] }})
                                            @else
                                                {{ __('assistant_v2.preset_'.($reportResult['period_label'] ?? '')) }}
                                                @if (!empty($reportResult['period']))
                                                    (<span class="num" dir="ltr">{{ $reportResult['period']['start_date'] }}</span> – <span class="num" dir="ltr">{{ $reportResult['period']['end_date'] }}</span>)
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
                                            <x-icon name="share" class="h-3.5 w-3.5" aria-hidden="true" />
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
                                                <span class="block num text-sm font-bold text-text-primary" dir="ltr">
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
                                                            <td class="px-2.5 py-1.5 text-text-primary {{ is_numeric($val) ? 'num' : '' }}" {{ is_numeric($val) ? 'dir=ltr' : '' }}>
                                                                @if (is_array($val))
                                                                    @foreach ($val as $subKey => $subVal)
                                                                        <span class="inline-block num mr-1" dir="ltr">{{ is_scalar($subVal) ? (string) $subVal : '' }} {{ is_string($subKey) ? $subKey : '' }}</span>
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
                                <button type="button" wire:click="resetQuestion" @click="clear()" class="min-h-11 rounded-control border border-border bg-surface px-4 text-sm font-semibold text-text-primary hover:bg-slate-100">
                                    {{ __('assistant_v2.ask_another') }}
                                </button>
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
                            <button type="button" wire:click="resetQuestion" @click="clear()" class="mt-3 min-h-9 px-2 text-xs font-semibold text-text-secondary hover:text-text-primary underline">
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
                                <button type="button" wire:click="resetQuestion" @click="clear()" class="min-h-11 px-3 text-sm text-text-secondary hover:text-text-primary underline">
                                    {{ __('assistant_v2.cancel') }}
                                </button>
                            </div>
                        </div>
                    @elseif ($questions === [])
                        {{-- The shared composer stays anchored below the conversation. --}}
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
                        <div wire:loading wire:target="prepare,clarify,selectPeriod,chooseOperation" role="status" class="mt-4 flex items-center gap-2 text-sm text-text-secondary">
                            <x-icon name="sparkles" class="h-4 w-4" />{{ __('ai_product.thinking') }}
                        </div>
                        @if ($error)<p role="alert" class="mt-4 rounded-control bg-danger-bg p-3 text-sm leading-relaxed text-danger">{{ $error }}</p>@endif
                    </div>
                    @if ($questions === [] && $clarifyPeriodReport === null && $ambiguousChoice === null)
                        @include('livewire.ai.partials.composer')
                    @endif
                </div>
            </section>
        @endif
    @elseif ($setupAvailable ?? false)
        <a href="{{ route('settings.ai') }}" class="ai-launcher">
            <x-icon name="sparkles" aria-hidden="true" /><span>{{ __('ai_product.setup') }}</span><x-icon name="microphone" class="ai-launcher-mic" aria-hidden="true" />
        </a>
    @endif
</div>
