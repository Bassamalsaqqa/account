<div class="min-w-0 space-y-6">
@include('livewire.pages.money.nav')
<div class="flex flex-wrap justify-between gap-3">
<h1 class="text-2xl font-bold">{{ __('money.transfers') }}</h1>@if($canCreate)<a class="touch-action bg-primary text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2" href="{{ route('money.transfers.create') }}">{{ __('money.new_transfer') }}</a>@endif</div>
<label class="block text-sm space-y-1">
<span>{{ __('money.search') }}</span>
<input wire:model.live.debounce.300ms="search" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary" type="search">
</label>
<div class="rounded-card border border-border bg-surface overflow-hidden">
    <div class="divide-y divide-border">
        @forelse($transfers as $transfer)
            <a href="{{ route('money.transfers.show', $transfer->public_id) }}" class="flex items-center justify-between gap-3 px-4 py-2.5 min-h-11 hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-primary active:bg-primary-50 transition-colors">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 min-w-0">
                        <bdi dir="ltr" class="font-mono font-bold text-sm text-primary truncate">{{ $transfer->transfer_number }}</bdi>
                        <x-badge variant="{{ $transfer->is_reversed ? 'danger' : 'success' }}">{{ __('money.'.($transfer->is_reversed ? 'reversed' : 'posted')) }}</x-badge>
                    </div>
                    <p class="text-xs text-text-secondary truncate mt-0.5">
                        <bdi dir="ltr" class="font-mono tabular-nums">{{ $transfer->transfer_date->toDateString() }}</bdi>
                    </p>
                </div>
                <div class="text-end shrink-0">
                    <p class="text-sm font-bold text-text-primary tabular-nums">
                        <bdi dir="ltr" class="font-mono">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($transfer->from_amount) }} {{ $transfer->from_currency_code }}</bdi> &rarr; <bdi dir="ltr" class="font-mono">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($transfer->to_amount) }} {{ $transfer->to_currency_code }}</bdi>
                    </p>
                    <span class="hidden sm:inline text-xs text-primary font-semibold">{{ __('refinement_lists.open') }}</span>
                </div>
            </a>
        @empty
            <p class="px-4 py-8 text-center text-sm text-text-muted">{{ __('money.empty') }}</p>
        @endforelse
    </div>
    @if ($transfers->count() > 0 || $transfers->currentPage() > 1)
        <div class="p-4 border-t border-border"><x-table-pagination :paginator="$transfers" /></div>
    @endif
</div>
</div>
