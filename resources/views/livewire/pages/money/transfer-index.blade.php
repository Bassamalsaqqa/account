<div class="min-w-0 space-y-6">
@include('livewire.pages.money.nav')
<div class="flex flex-wrap justify-between gap-3">
<h1 class="text-2xl font-bold">{{ __('money.transfers') }}</h1>@if($canCreate)<a class="touch-action bg-primary text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2" href="{{ route('money.transfers.create') }}">{{ __('money.new_transfer') }}</a>@endif</div>
<label class="block text-sm space-y-1">
<span>{{ __('money.search') }}</span>
<input wire:model.live.debounce.300ms="search" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" type="search">
</label>
<div class="rounded-card border border-border bg-white p-4 sm:p-6 space-y-3">@forelse($transfers as $transfer)<a href="{{ route('money.transfers.show', $transfer->public_id) }}" class="grid w-full gap-3 rounded-control border border-border p-3 my-3 focus-visible:outline-2 focus-visible:outline-primary active:bg-primary-50 sm:grid-cols-3">
<div>
<bdi class="font-semibold">{{ $transfer->transfer_number }}</bdi>
<p class="text-xs">{{ $transfer->transfer_date->toDateString() }}</p>
</div>
<p class="text-sm">
<bdi>{{ $transfer->from_amount }} {{ $transfer->from_currency_code }}</bdi> → <bdi>{{ $transfer->to_amount }} {{ $transfer->to_currency_code }}</bdi>
</p>
<div class="space-y-2"><span class="block text-sm">{{ __('money.'.($transfer->is_reversed ? 'reversed' : 'posted')) }}</span><span class="touch-action bg-surface-soft text-sm font-semibold">{{ __('refinement_lists.open') }}</span></div>
</a>@empty<p>{{ __('money.empty') }}</p>
@endforelse{{ $transfers->links() }}</div>
</div>
