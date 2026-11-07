<div class="min-w-0 space-y-6">
@include('livewire.pages.money.nav')
@error('transfer')<p role="alert" class="text-sm text-danger">{{ $message }}</p>@enderror
<h1 class="text-2xl font-bold">
<bdi>{{ $transfer->transfer_number }}</bdi>
</h1>
<section class="rounded-card border border-border bg-surface p-4 sm:p-6 space-y-3">
<p>{{ $transfer->transfer_date->toDateString() }} · {{ __('money.'.($transfer->is_reversed ? 'reversed' : 'posted')) }}</p>
<div class="grid gap-4 sm:grid-cols-2">@foreach(['from', 'to'] as $side)<div>
<h2 class="font-bold">{{ __('money.'.$side.'_account') }}</h2>
<p>{{ $transfer->getAttribute($side.'_account_snapshot')['name_'.app()->getLocale()] ?? $transfer->getAttribute($side.'_account_snapshot')['name_ar'] ?? '' }}</p>
<bdi class="text-xl">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($transfer->getAttribute($side.'_amount')) }} {{ $transfer->getAttribute($side.'_currency_code') }}</bdi>
<p class="text-sm">{{ __('money.rate') }}: <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($transfer->getAttribute($side.'_exchange_rate')) }}</bdi>
</p>
</div>@endforeach</div>
<p>{{ __('money.realized_fx') }}: <bdi>{{ $transfer->fx_gain_loss_base }} {{ $transfer->base_currency_code }}</bdi>
</p>
<p class="break-words">{{ $transfer->notes }}</p>
</section>
@if($canReverse && !$transfer->is_reversed)<x-secondary-button wire:click="openReversalReview">{{ __('money.reverse_transfer') }}</x-secondary-button>@endif
<x-standalone-reversal-review />
</div>
