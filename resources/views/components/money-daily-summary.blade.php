@props(['summaries', 'selectedDate', 'today'])
<section class="rounded-card border border-border bg-surface overflow-hidden" aria-labelledby="daily-summary-title">
    <div class="p-4 sm:p-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 id="daily-summary-title" class="text-lg font-bold text-text-primary">{{ __('money.daily_summary') }}</h2>
            <p class="mt-1 text-sm text-text-secondary">{{ __('money.as_of_date') }} <bdi dir="ltr">{{ $selectedDate }}</bdi></p>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <label for="daily-summary-date" class="text-sm text-text-secondary">
                <span class="block mb-1">{{ __('money.selected_date') }}</span>
                <input id="daily-summary-date" type="date" value="{{ $selectedDate }}" wire:model.live="date" class="min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-surface px-3 text-base lg:text-sm focus:ring-2 focus:ring-primary">
            </label>
            <button type="button" wire:click="$set('date', '{{ $today }}')" wire:loading.attr="disabled" class="min-h-11 lg:min-h-9.5 px-3 border border-border-control rounded-control text-sm font-semibold hover:bg-surface-soft focus:ring-2 focus:ring-primary disabled:opacity-50">{{ __('money.today') }}</button>
            <button type="button" wire:click="$set('date', '{{ \Carbon\CarbonImmutable::parse($today)->subDay()->format('Y-m-d') }}')" wire:loading.attr="disabled" class="min-h-11 lg:min-h-9.5 px-3 border border-border-control rounded-control text-sm font-semibold hover:bg-surface-soft focus:ring-2 focus:ring-primary disabled:opacity-50">{{ __('money.yesterday') }}</button>
        </div>
    </div>
    @error('date')<p class="px-4 sm:px-6 pb-3 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
    <div class="overflow-x-auto border-t border-border" tabindex="0" role="region" aria-label="{{ __('money.daily_summary') }}">
        <table class="data-table data-table--responsive w-full text-sm text-start">
            <thead class="bg-surface-soft border-b border-border text-text-secondary">
                <tr>
                    <th scope="col" class="px-4 py-3 text-start">{{ __('money.account') }}</th>
                    @foreach(['opening_balance', 'daily_inflows', 'daily_outflows', 'closing_balance'] as $label)
                        <th scope="col" class="px-4 py-3 text-end">{{ __('money.'.$label) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-border" wire:loading.class="opacity-50" wire:target="date">
                @foreach($summaries as $summaryIdx => $summary)
                    <tr wire:key="daily-sum-{{ $summary['public_id'] ?? $summaryIdx }}" data-row-surface x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'">
                        <td class="td-primary px-4 py-3 text-start font-medium">
                            <a href="{{ route('money.accounts.show', $summary['public_id']) }}" data-row-link class="touch-action font-semibold text-primary hover:underline focus:ring-2 focus:ring-primary truncate block">{{ $summary['name'] }}</a>
                            <span class="block mt-0.5 text-xs text-text-secondary"><bdi dir="ltr" class="font-mono">{{ $summary['currency_code'] }}</bdi></span>
                        </td>
                        @if(!$summary['is_available'])
                            <td colspan="4" class="px-4 py-3 text-text-secondary">{{ __('money.daily_activity_restricted') }}</td>
                        @else
                            @foreach(['opening' => 'opening_balance', 'inflow' => 'daily_inflows', 'outflow' => 'daily_outflows'] as $field => $label)
                                <td class="td-secondary px-4 py-3 text-end tabular-nums" data-label="{{ __('money.'.$label) }}">
                                    <bdi dir="ltr">{{ $summary[$field.'_currency'] === null ? __('money.unavailable') : \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($summary[$field.'_currency'], $summary['currency_code']) }}</bdi>
                                    @if($summary['currency_code'] !== $summary['base_currency_code'])
                                        <span class="block mt-1 text-xs text-text-secondary"><bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($summary[$field.'_base'], $summary['base_currency_code']) }} {{ $summary['base_currency_code'] }}</bdi></span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="td-end px-4 py-3 text-end tabular-nums font-bold">
                                <div class="inline-flex items-center gap-2">
                                    <div class="text-end">
                                        <bdi dir="ltr">{{ $summary['closing_currency'] === null ? __('money.unavailable') : \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($summary['closing_currency'], $summary['currency_code']) }}</bdi>
                                        @if($summary['currency_code'] !== $summary['base_currency_code'])
                                            <span class="block mt-0.5 text-xs font-normal text-text-secondary"><bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($summary['closing_base'], $summary['base_currency_code']) }} {{ $summary['base_currency_code'] }}</bdi></span>
                                        @endif
                                    </div>
                                    <x-row-details-toggle />
                                </div>
                            </td>
                        @endif
                    </tr>
                    @if($summary['is_available'] && !$summary['currency_available'])
                        <tr><td colspan="5" class="px-4 pb-3 text-xs text-warning">{{ __('money.native_unavailable') }}</td></tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
</section>
