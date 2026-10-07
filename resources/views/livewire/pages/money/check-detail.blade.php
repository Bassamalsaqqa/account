<div class="min-w-0 space-y-6">
@include('livewire.pages.money.nav')
<h1 class="text-2xl font-bold">{{ __('money.'.$check->direction) }} <bdi>{{ $check->check_number }}</bdi>
</h1>
<section class="rounded-card border border-border bg-white p-4 sm:p-6 space-y-3">
<p class="font-semibold">{{ $check->partyDisplayName() }}</p>
<p class="text-2xl font-bold" dir="ltr">{{ $check->amount }} {{ $check->currency_code }}</p>
<p>{{ __('money.'.$check->status) }} · {{ __('money.due_date') }}: {{ $check->due_date->toDateString() }}</p>
<p>{{ $check->bank_name }}</p>
<p class="break-words">{{ $check->notes }}</p>
</section>
<section class="rounded-card border border-border bg-white p-4 sm:p-6 space-y-3">
<h2 class="font-bold">{{ __('money.history') }}</h2>@foreach($events as $event)<article class="flex flex-wrap justify-between gap-3 border-b border-border py-2">
<span>{{ __('money.'.$event->to_status) }}</span>
<span>{{ $event->event_date->toDateString() }}</span>@if($event->fx_gain_loss_base !== null)<span>{{ __('money.realized_fx') }}: <bdi>{{ $event->fx_gain_loss_base }} {{ $check->base_currency_code }}</bdi>
</span>@endif</article>@endforeach</section>
@if($canManage && !in_array($check->status, ['returned', 'cancelled'], true))<section class="rounded-card border border-border bg-white p-4 sm:p-6 space-y-4">@if($errors->any())<div role="alert" class="rounded-control border border-red-200 bg-red-50 p-3 text-sm text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.date') }}</span>
<input type="date" wire:model="eventDate" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" >
</label>@if($check->direction === 'incoming' && $check->status === 'received')<label class="block text-sm">{{ __('money.settlement_bank') }}<select wire:model="bankId" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
<option value="">{{ __('money.select_account') }}</option>@foreach($banks as $bank)<option value="{{ $bank->id }}">{{ $bank->displayName() }}</option>@endforeach</select>
</label>
<button type="button" wire:click="recordTransition('deposit')" wire:loading.attr="disabled" class="inline-flex justify-center items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2">{{ __('money.deposit') }}</button>@endif
@if(in_array($check->status, ['deposited', 'issued'], true))<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.clearing_rate') }}</span>
<input type="text" wire:model="rate" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" inputmode="decimal">
</label>
<button type="button" wire:click="recordTransition('clear')" wire:confirm="{{ __('money.clear_confirm') }}" wire:loading.attr="disabled" class="inline-flex justify-center items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2">{{ __('money.clear') }}</button>@endif
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.reason') }}</span>
<input type="text" wire:model="notes" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" >
</label>@if($canUndo && ($check->status !== 'cleared' || $check->direction === 'incoming'))<div class="flex flex-wrap gap-3">
<button type="button" wire:click="recordTransition('return')" wire:confirm="{{ __('money.return_confirm') }}" wire:loading.attr="disabled" class="inline-flex justify-center items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2">{{ __('money.return') }}</button>@if($check->status !== 'cleared')<button type="button" wire:click="recordTransition('cancel')" wire:confirm="{{ __('money.return_confirm') }}" wire:loading.attr="disabled" class="inline-flex justify-center items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2">{{ __('money.cancel') }}</button>@endif</div>@endif</section>@endif
</div>
