<div class="min-w-0 space-y-6">
@include('livewire.pages.money.nav')
@error('transfer')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
<h1 class="text-2xl font-bold">
<bdi>{{ $transfer->transfer_number }}</bdi>
</h1>
<section class="rounded-card border border-border bg-white p-4 sm:p-6 space-y-3">
<p>{{ $transfer->transfer_date->toDateString() }} · {{ __('money.'.($transfer->is_reversed ? 'reversed' : 'posted')) }}</p>
<div class="grid gap-4 sm:grid-cols-2">@foreach(['from', 'to'] as $side)<div>
<h2 class="font-bold">{{ __('money.'.$side.'_account') }}</h2>
<p>{{ $transfer->getAttribute($side.'_account_snapshot')['name_'.app()->getLocale()] ?? $transfer->getAttribute($side.'_account_snapshot')['name_ar'] ?? '' }}</p>
<bdi class="text-xl">{{ $transfer->getAttribute($side.'_amount') }} {{ $transfer->getAttribute($side.'_currency_code') }}</bdi>
<p class="text-xs">{{ __('money.rate') }}: <bdi>{{ $transfer->getAttribute($side.'_exchange_rate') }}</bdi>
</p>
</div>@endforeach</div>
<p>{{ __('money.realized_fx') }}: <bdi>{{ $transfer->fx_gain_loss_base }} {{ $transfer->base_currency_code }}</bdi>
</p>
<p class="break-words">{{ $transfer->notes }}</p>
</section>
@if($canReverse && !$transfer->is_reversed)<form wire:submit="reverse" wire:confirm="{{ __('money.reverse_transfer_confirm') }}" class="rounded-card border border-border bg-white p-4 sm:p-6 space-y-3">
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.reason') }}</span>
<input type="text" wire:model="reason" maxlength="500" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" >
@error('reason')<span role="alert" class="block text-sm text-red-700">{{ $message }}</span>@enderror
</label>
<button class="inline-flex justify-center items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2" type="submit" wire:loading.attr="disabled">{{ __('money.reverse_transfer') }}</button>
</form>@endif
</div>
