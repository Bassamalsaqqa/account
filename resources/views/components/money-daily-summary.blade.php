@props(['summaries', 'selectedDate', 'today'])
<section class="rounded-card border border-border bg-white overflow-hidden" aria-labelledby="daily-summary-title">
    <div class="p-4 sm:p-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 id="daily-summary-title" class="text-lg font-bold text-text-primary">{{ __('money.daily_summary') }}</h2>
            <p class="mt-1 text-sm text-text-secondary">{{ __('money.as_of_date') }} <bdi dir="ltr">{{ $selectedDate }}</bdi></p>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <label for="daily-summary-date" class="text-sm text-text-secondary">
                <span class="block mb-1">{{ __('money.selected_date') }}</span>
                <input id="daily-summary-date" type="date" value="{{ $selectedDate }}" wire:model.live="date" class="min-h-11 rounded-control border border-border bg-white px-3 py-2 text-sm focus:ring-2 focus:ring-primary">
            </label>
            <button type="button" wire:click="$set('date', '{{ $today }}')" wire:loading.attr="disabled" class="min-h-11 px-3 border border-border rounded-control text-sm hover:bg-surface-soft focus:ring-2 focus:ring-primary disabled:opacity-50">{{ __('money.today') }}</button>
            <button type="button" wire:click="$set('date', '{{ \Carbon\CarbonImmutable::parse($today)->subDay()->format('Y-m-d') }}')" wire:loading.attr="disabled" class="min-h-11 px-3 border border-border rounded-control text-sm hover:bg-surface-soft focus:ring-2 focus:ring-primary disabled:opacity-50">{{ __('money.yesterday') }}</button>
        </div>
    </div>
    @error('date')<p class="px-4 sm:px-6 pb-3 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
    <div class="sm:hidden divide-y divide-border border-t border-border" wire:loading.class="opacity-50" wire:target="date">
        @foreach($summaries as $summary)
            <article class="p-4">
                <a href="{{ route('money.accounts.show', $summary['public_id']) }}" class="font-semibold text-primary hover:underline focus:ring-2 focus:ring-primary">{{ $summary['name'] }}</a>
                <bdi dir="ltr" class="text-xs text-text-secondary ms-2">{{ $summary['currency_code'] }}</bdi>
                @if(!$summary['is_available'])
                    <p class="mt-3 text-sm text-text-secondary">{{ __('money.daily_activity_restricted') }}</p>
                @else
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-5 mt-4">
                        @foreach(['opening' => 'opening_balance', 'inflow' => 'daily_inflows', 'outflow' => 'daily_outflows', 'closing' => 'closing_balance'] as $field => $label)
                            <div class="min-w-0">
                                <dt class="text-xs text-text-secondary">{{ __('money.'.$label) }}</dt>
                                <dd class="mt-1 tabular-nums {{ $field === 'closing' ? 'font-bold text-lg' : 'font-semibold' }}">
                                    <bdi dir="ltr">{{ $summary[$field.'_currency'] === null ? __('money.unavailable') : \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($summary[$field.'_currency'], $summary['currency_code']) }}</bdi>
                                    @if($summary['currency_code'] !== $summary['base_currency_code'])
                                        <span class="block mt-1 text-xs text-text-secondary font-normal"><bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($summary[$field.'_base'], $summary['base_currency_code']) }} {{ $summary['base_currency_code'] }}</bdi></span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                    @if(!$summary['currency_available'])<p class="mt-3 text-xs text-amber-800">{{ __('money.native_unavailable') }}</p>@endif
                @endif
            </article>
        @endforeach
    </div>
    <div class="hidden sm:block overflow-x-auto" tabindex="0" role="region" aria-label="{{ __('money.daily_summary') }}">
        <table class="w-full min-w-[640px] text-sm text-start">
            <thead class="bg-surface-soft border-y border-border text-text-secondary">
                <tr>
                    <th scope="col" class="px-4 py-3 text-start">{{ __('money.account') }}</th>
                    @foreach(['opening_balance', 'daily_inflows', 'daily_outflows', 'closing_balance'] as $label)
                        <th scope="col" class="px-4 py-3 text-end">{{ __('money.'.$label) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-border" wire:loading.class="opacity-50" wire:target="date">
                @foreach($summaries as $summary)
                    <tr>
                        <th scope="row" class="px-4 py-4 text-start font-medium">
                            <a href="{{ route('money.accounts.show', $summary['public_id']) }}" class="text-primary hover:underline focus:ring-2 focus:ring-primary">{{ $summary['name'] }}</a>
                            <span class="block mt-1 text-xs text-text-secondary">{{ $summary['currency_code'] }}</span>
                        </th>
                        @if(!$summary['is_available'])
                            <td colspan="4" class="px-4 py-4 text-text-secondary">{{ __('money.daily_activity_restricted') }}</td>
                        @else
                            @foreach(['opening', 'inflow', 'outflow', 'closing'] as $field)
                                <td class="px-4 py-4 text-end tabular-nums {{ $field === 'closing' ? 'font-bold' : '' }}">
                                    <bdi dir="ltr">{{ $summary[$field.'_currency'] === null ? __('money.unavailable') : \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($summary[$field.'_currency'], $summary['currency_code']) }}</bdi>
                                    @if($summary['currency_code'] !== $summary['base_currency_code'])
                                        <span class="block mt-1 text-xs text-text-secondary"><bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($summary[$field.'_base'], $summary['base_currency_code']) }} {{ $summary['base_currency_code'] }}</bdi></span>
                                    @endif
                                </td>
                            @endforeach
                        @endif
                    </tr>
                    @if($summary['is_available'] && !$summary['currency_available'])
                        <tr><td colspan="5" class="px-4 pb-3 text-xs text-amber-800">{{ __('money.native_unavailable') }}</td></tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
</section>
