<div class="min-w-0 space-y-6">
@include('livewire.pages.money.nav')
<h1 class="text-2xl font-bold">{{ $account->displayName() }}</h1>

<section class="rounded-card border border-border bg-surface p-4 sm:p-6 flex flex-wrap justify-between gap-4">
<div>
<p class="text-xs text-text-secondary">{{ __('money.current_balance') }}</p>
<p class="text-xl font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($balance['balance_currency'] ?? __('money.unavailable')) }} {{ $account->currency_code }}</p>
</div>
<div>
<p class="text-xs text-text-secondary">{{ __('money.base_value') }}</p>
<bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($balance['balance_base']) }} {{ $balance['base_currency_code'] }}</bdi>
</div>
</section>

<x-money-daily-summary :summaries="[$dailySummary]" :selected-date="$selectedDate" :today="$today" />

<section class="rounded-card border border-border bg-surface overflow-hidden">
<div class="px-4 py-3 border-b border-border bg-surface-soft/50">
    <h2 class="font-bold text-sm text-text-primary">{{ __('money.movements') }}</h2>
</div>
<div class="divide-y divide-border">
@forelse($movements as $movement)
    <div wire:key="account-movement-{{ $movement->id }}" x-data="{ expanded: false }" class="px-4 py-2.5">
        <div class="flex items-start justify-between gap-2.5 min-w-0">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-text-primary truncate">{{ $movement->description ?: __('money.movement') }}</p>
                <p class="text-xs text-text-secondary truncate mt-0.5">
                    <bdi dir="ltr" class="font-mono tabular-nums">{{ $movement->posting_date }}</bdi>
                    @if(isset($sourceLinks[$movement->id])) &middot; <a class="touch-action text-primary hover:underline" href="{{ $sourceLinks[$movement->id] }}">{{ __('money.source') }}</a>@endif
                </p>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <div class="text-end">
                    <bdi dir="ltr" class="block text-sm font-mono tabular-nums font-bold text-text-primary">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($movement->transaction_amount ?? '—') }} {{ $movement->transaction_currency_code }}</bdi>
                    <bdi dir="ltr" class="block text-xs font-mono tabular-nums text-text-secondary">{{ $movement->running_base ?? __('money.unavailable') }} {{ $balance['base_currency_code'] }}</bdi>
                </div>
                <x-row-details-toggle />
            </div>
        </div>
        <div x-show="expanded" x-cloak class="mt-2 pt-2 border-t border-border/80 grid grid-cols-2 gap-2 text-xs">
            <div><span class="text-text-secondary block">{{ __('money.in') }}</span><bdi dir="ltr" class="font-mono tabular-nums font-semibold">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($movement->debit_base) }}</bdi></div>
            <div><span class="text-text-secondary block">{{ __('money.out') }}</span><bdi dir="ltr" class="font-mono tabular-nums font-semibold">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($movement->credit_base) }}</bdi></div>
            <div><span class="text-text-secondary block">{{ __('money.running_base') }}</span><bdi dir="ltr" class="font-mono tabular-nums font-semibold">{{ $movement->running_base ?? __('money.unavailable') }} {{ $balance['base_currency_code'] }}</bdi></div>
            <div><span class="text-text-secondary block">{{ __('money.running_currency') }}</span><bdi dir="ltr" class="font-mono tabular-nums font-semibold">{{ $movement->running_base === null ? __('money.unavailable') : ($account->currency_code === $balance['base_currency_code'] ? $movement->running_base : ($movement->unknown_currency_lines == 0 ? $movement->known_running_currency : __('money.unavailable'))) }} {{ $account->currency_code }}</bdi></div>
        </div>
    </div>
@empty
    <p class="p-6 text-center text-sm text-text-secondary">{{ __('money.empty') }}</p>
@endforelse
</div>
@if ($movements->count() > 0 || $movements->currentPage() > 1)
    <div class="p-4 border-t border-border"><x-table-pagination :paginator="$movements" /></div>
@endif
</section>
</div>
