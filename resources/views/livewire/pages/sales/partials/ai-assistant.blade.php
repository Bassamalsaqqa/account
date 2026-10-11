<section id="sales-ai-assistant" aria-labelledby="sales-ai-title"
    class="rounded-card border border-border bg-surface p-4 space-y-3"
    x-data="aiSaleRecorder($wire, @js(['url' => route('sales-assistant.audio'), 'maxSeconds' => config('ai_assistant.max_audio_seconds'), 'permissionError' => __('assistant.microphone_error'), 'deliveryError' => __('assistant.unavailable')]))"
    @ai-sale-discarded.window="clear()"
    @ai-sale-applied.window="clear(); $nextTick(() => document.getElementById('invoice-customer-id')?.focus())">
    <div>
        <h2 id="sales-ai-title" class="text-sm font-bold text-text-primary">{{ __('assistant.title') }}</h2>
        <p id="sales-ai-help" class="text-xs text-text-secondary mt-1 max-w-prose">{{ __('assistant.help') }}</p>
    </div>
    <label for="sales-ai-description" class="block text-xs font-semibold text-text-primary">{{ __('assistant.description') }}</label>
    <textarea id="sales-ai-description" wire:model="aiText" rows="3" maxlength="4000" aria-describedby="sales-ai-help sales-ai-privacy"
        class="w-full rounded-control border border-border bg-canvas px-3 py-2 text-sm text-text-primary resize-y"
        placeholder="{{ __('assistant.example') }}"></textarea>
    <label for="sales-ai-consent" class="flex items-start gap-2 text-xs text-text-secondary max-w-prose">
        <input id="sales-ai-consent" type="checkbox" wire:model.live="aiConsent" class="mt-0.5 rounded border-border" />
        <span id="sales-ai-privacy">{{ __('assistant.privacy') }}</span>
    </label>
    <div class="flex flex-wrap items-center gap-2">
        <button type="button" wire:click="interpretSale" wire:loading.attr="disabled" wire:target="interpretSale,applySaleSuggestion,discardSaleSuggestion"
            @disabled(! $aiConsent) :disabled="recording || busy || !$wire.aiConsent"
            class="rounded-control bg-primary px-3 py-2 text-xs font-semibold text-white hover:bg-primary-hover disabled:opacity-50 disabled:cursor-not-allowed">
            <span wire:loading.remove wire:target="interpretSale">{{ __('assistant.interpret') }}</span>
            <span wire:loading wire:target="interpretSale">{{ __('assistant.working') }}</span>
        </button>
        @if (config('ai_assistant.speech_enabled') === true)
            <button type="button" x-show="!recording" @click="start()" :disabled="!supported || busy || !$wire.aiConsent"
                wire:loading.attr="disabled" wire:target="interpretSale,applySaleSuggestion"
                class="flex items-center gap-1.5 rounded-control border border-border bg-surface-soft px-3 py-2 text-xs font-semibold text-text-primary hover:bg-canvas disabled:opacity-50 disabled:cursor-not-allowed">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="9" y="3" width="6" height="12" rx="3"/><path d="M5 10v2a7 7 0 0 0 14 0v-2M12 19v3M8 22h8"/></svg>
                {{ __('assistant.record') }}
            </button>
            <button type="button" x-show="recording" x-cloak @click="stop()" class="rounded-control border border-danger bg-danger-bg px-3 py-2 text-xs font-semibold text-danger">{{ __('assistant.stop') }}</button>
            <button type="button" x-show="ready" x-cloak @click="transcribe()" :disabled="busy || !$wire.aiConsent" class="rounded-control border border-border px-3 py-2 text-xs font-semibold text-text-primary disabled:opacity-50">{{ __('assistant.transcribe') }}</button>
        @endif
        <button type="button" wire:click="discardSaleSuggestion" @click="clear()" wire:loading.attr="disabled" wire:target="interpretSale,applySaleSuggestion"
            class="rounded-control px-3 py-2 text-xs font-semibold text-text-secondary hover:bg-surface-soft">{{ __('assistant.clear') }}</button>
    </div>
    <p x-show="recording" x-cloak role="status" class="text-xs font-semibold text-danger">{{ __('assistant.recording') }} <span class="num" x-text="seconds"></span> / {{ config('ai_assistant.max_audio_seconds') }} {{ __('assistant.seconds') }}</p>
    <p x-show="busy" x-cloak role="status" class="text-xs text-text-secondary">{{ __('assistant.working') }}</p>
    @if (config('ai_assistant.speech_enabled') === true)
        <p x-show="!supported" x-cloak class="text-xs text-text-secondary">{{ __('assistant.microphone_unavailable') }}</p>
    @endif
    <p x-show="error" x-cloak role="alert" x-text="error" class="text-xs text-danger"></p>
    @if ($aiError)
        <p role="alert" class="text-xs text-danger">{{ $aiError }}</p>
    @endif
    @if ($aiReview)
        @if ($aiReview['questions'])
            <div role="status" class="text-sm text-text-primary space-y-1">
                <p class="font-semibold">{{ __('assistant.clarify') }}</p>
                <ul class="list-disc list-inside">
                    @foreach ($aiReview['questions'] as $question)
                        <li>{{ __('assistant.question_'.$question) }}</li>
                    @endforeach
                </ul>
            </div>
        @else
            <div class="space-y-3 pt-2 border-t border-border" role="status">
                <p class="text-xs text-text-secondary">{{ __('assistant.review') }}</p>
                <ul class="space-y-2 text-sm text-text-primary">
                    @foreach ($aiReview['summary'] as $line)
                        <li class="flex flex-wrap gap-x-2 gap-y-1">
                            <strong>{{ $line['product'] }}</strong>
                            <span><bdi class="num">{{ $line['quantity'] }}</bdi> {{ $line['unit'] }}</span>
                            <span class="text-text-secondary">(<bdi class="num">{{ $line['base_quantity'] }}</bdi> {{ __('assistant.base_units') }})</span>
                            <span>{{ __('assistant.unit_price') }} <bdi class="num">{{ $line['unit_price'] }} {{ $line['currency'] }}</bdi></span>
                            @if ($line['price_source'] === 'default')<span class="text-xs text-text-secondary">{{ __('assistant.suggested') }}</span>@endif
                        </li>
                    @endforeach
                </ul>
                <button type="button" wire:click="applySaleSuggestion" wire:loading.attr="disabled"
                    wire:target="interpretSale,applySaleSuggestion,discardSaleSuggestion"
                    class="rounded-control border border-primary px-3 py-2 text-xs font-semibold text-primary hover:bg-primary-50 disabled:opacity-50">{{ __('assistant.apply') }}</button>
            </div>
        @endif
    @endif
    @if ($aiSourceRequestId)
        <p role="status" class="text-xs text-text-secondary">{{ __('assistant.applied') }}</p>
    @endif
    @if ($aiUsage)
        <details class="text-xs text-text-secondary">
            <summary class="cursor-pointer py-1">{{ __('assistant.usage') }}</summary>
            <p class="mt-1">{{ __('assistant.usage_counts', ['requests' => $aiUsage['daily_requests'], 'daily' => $aiUsage['daily_reserved_micros'], 'monthly' => $aiUsage['monthly_reserved_micros'], 'reported' => $aiUsage['monthly_reported_micros'], 'unknown' => $aiUsage['unreported_requests']]) }}</p>
        </details>
    @endif
    @if ($aiCompanyUsage)
        <details class="text-xs text-text-secondary">
            <summary class="cursor-pointer py-1">{{ __('assistant.company_usage') }}</summary>
            <p class="mt-1">{{ __('assistant.usage_counts', ['requests' => $aiCompanyUsage['daily_requests'], 'daily' => $aiCompanyUsage['daily_reserved_micros'], 'monthly' => $aiCompanyUsage['monthly_reserved_micros'], 'reported' => $aiCompanyUsage['monthly_reported_micros'], 'unknown' => $aiCompanyUsage['unreported_requests']]) }}</p>
        </details>
    @endif
</section>
