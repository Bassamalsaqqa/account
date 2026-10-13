<div class="mt-4 space-y-4">
    @if ($isBriefing ?? false)
        <div class="rounded-card border border-primary/20 bg-primary/5 p-4 space-y-1">
            <div class="flex items-center justify-between">
                <h3 class="font-bold text-base text-primary">{{ __('assistant_briefing.title') }}</h3>
                <span class="text-xs px-2 py-0.5 rounded bg-primary/10 text-primary font-medium">{{ __('assistant_briefing.badge') }}</span>
            </div>
            <p class="text-xs text-text-secondary leading-relaxed">{{ __('assistant_briefing.truthful_notice') }}</p>
        </div>
        @if ($turnReports === [])
            <p role="status" class="rounded-card border border-border bg-canvas p-4 text-sm leading-relaxed text-text-secondary">
                {{ __('assistant_briefing.no_cards') }}
            </p>
        @endif
    @endif
    @if ($turnClarification !== null)
        <p role="status" class="rounded-card border border-border bg-canvas p-4 text-sm leading-relaxed">
            {{ __('assistant_v22.clarify_'.$turnClarification) }}
        </p>
    @endif
    @foreach ($turnReports as $answer)
        <article class="rounded-card border border-border bg-canvas p-4 space-y-3">
            <header>
                <h3 class="font-bold">{{ $answer['title'] }}</h3>
                <p class="text-xs text-text-secondary mt-1">
                    @if ($answer['period'])
                        <bdi>{{ $answer['period']['start_date'] }}</bdi> — <bdi>{{ $answer['period']['end_date'] }}</bdi>
                    @else
                        {{ __('assistant_v2.as_of') }} <bdi>{{ $answer['as_of'] ?? $answer['generated_at'] }}</bdi>
                    @endif
                    · {{ $answer['timezone'] }}
                </p>
                <p class="text-xs text-text-secondary">{{ __('assistant_v2.base_currency') }}: <bdi>{{ $answer['currency']['base_currency_code'] }}</bdi>
                    · {{ __('assistant_v2.currency_filter') }}: <bdi>{{ $answer['currency']['filter_currency_code'] ?? __('assistant_v2.all_currencies') }}</bdi></p>
            </header>
            @include('livewire.ai.partials.turn-values', ['values' => $answer['totals']])
            @if ($answer['rows'] !== [])
                <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ $answer['title'] }}">
                    <table class="w-full text-sm text-start">
                        <thead><tr>@foreach (array_keys($answer['rows'][0]) as $column)<th scope="col" class="p-2 text-start">{{ \Illuminate\Support\Facades\Lang::has('assistant_briefing.columns.'.$column) ? __('assistant_briefing.columns.'.$column) : (\Illuminate\Support\Facades\Lang::has('assistant_v22.fields.'.$column) ? __('assistant_v22.fields.'.$column) : (\Illuminate\Support\Facades\Lang::has('reports.columns.'.$column) ? __('reports.columns.'.$column) : str_replace('_', ' ', $column))) }}</th>@endforeach</tr></thead>
                        <tbody>@foreach ($answer['rows'] as $row)<tr class="border-t border-border">@foreach ($row as $column => $value)<td class="p-2"><bdi>{{ is_bool($value) ? __('assistant_v2.value_'.($value ? 'yes' : 'no')) : ($column === 'stock_status' && is_string($value) && \Illuminate\Support\Facades\Lang::has('reports.options.'.$value) ? __('reports.options.'.$value) : ($column === 'status' && $value === 'posted' ? __('sales.posted') : ($value ?? __('assistant_v2.value_unavailable')))) }}</bdi></td>@endforeach</tr>@endforeach</tbody>
                    </table>
                </div>
                <p class="text-xs text-text-secondary">{{ __('assistant_v2.sample_rows_count', ['shown' => count($answer['rows']), 'total' => $answer['total_rows']]) }}</p>
            @else
                <p class="text-sm text-text-secondary">{{ __('assistant_v2.no_data') }}</p>
            @endif
            @if (!empty($answer['comparison']))
                <section class="rounded-control bg-surface p-3 space-y-2">
                    <h4 class="text-sm font-semibold">{{ __('assistant_v22.comparison') }}</h4>
                    @if (!empty($answer['comparison']['period']))<p class="text-xs"><bdi>{{ $answer['comparison']['period']['start_date'] }}</bdi> — <bdi>{{ $answer['comparison']['period']['end_date'] }}</bdi></p>@endif
                    @include('livewire.ai.partials.turn-values', ['values' => $answer['comparison']['totals'] ?? []])
                </section>
            @endif
            <a class="inline-flex min-h-11 items-center text-primary underline" href="{{ $answer['source_url'] }}" target="_blank" rel="noopener noreferrer">{{ __('assistant_v2.view_full_report') }}</a>
        </article>
    @endforeach
    <button type="button" wire:click="resetQuestion" @click="clear()" class="min-h-11 text-primary underline">{{ ($isBriefing ?? false) ? __('assistant_briefing.dismiss') : __('assistant_v22.followup') }}</button>
</div>
