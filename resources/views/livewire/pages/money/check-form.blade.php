<div class="min-w-0 space-y-6">
@include('livewire.pages.money.nav')
<h1 class="text-2xl font-bold">{{ __('money.'.($direction === 'incoming' ? 'receive_check' : 'issue_check')) }}</h1>
<form wire:submit="save" class="rounded-card border border-border bg-white p-4 sm:p-6 space-y-5">@if($errors->any())<div role="alert" class="rounded-control border border-red-200 bg-red-50 p-3 text-sm text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif<div class="grid gap-4 sm:grid-cols-2">
<label class="text-sm space-y-1">
<span>{{ __('money.party') }}</span>
<select wire:model.live="partyId" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
<option value="">{{ __('money.select_party') }}</option>@foreach($parties as $party)<option value="{{ $party->id }}">{{ $party->displayName() }}</option>@endforeach</select>
</label>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.check_number') }}</span>
<input type="text" wire:model="number" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" >
</label>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.bank_name') }}</span>
<input type="text" wire:model="bankName" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" >
</label>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.drawer') }}</span>
<input type="text" wire:model="drawer" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" >
</label>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.date') }}</span>
<input type="date" wire:model="date" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" >
</label>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.due_date') }}</span>
<input type="date" wire:model="dueDate" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" >
</label>
<label class="text-sm space-y-1">
<span>{{ __('money.currency') }}</span>
<select wire:model.live="currency" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">@foreach($currencies as $code)<option value="{{ $code }}">{{ $code }}</option>@endforeach</select>
</label>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.amount') }}</span>
<input type="text" wire:model="amount" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" inputmode="decimal">
</label>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.rate') }}</span>
<input type="text" wire:model="rate" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" inputmode="decimal">
</label>@if($direction === 'outgoing')<label class="text-sm space-y-1">
<span>{{ __('money.drawn_bank') }}</span>
<select wire:model="bankId" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
<option value="">{{ __('money.select_account') }}</option>@foreach($banks as $bank)<option value="{{ $bank->id }}">{{ $bank->displayName() }}</option>@endforeach</select>
</label>@endif</div>
<section class="space-y-3">
<h2 class="font-bold">{{ __('money.allocations') }}</h2>
<p class="text-xs text-text-secondary">{{ __('money.allocation_help') }}</p>@foreach($targets as $target)<div class="grid items-end gap-3 border-b border-border py-3 sm:grid-cols-3" wire:key="target-{{ $target['id'] }}">
<div>
<bdi>{{ $target['number'] }}</bdi>
<p class="text-xs">{{ __('money.outstanding') }}: <bdi>{{ $target['outstanding'] }} {{ $target['currency'] }}</bdi>
</p>
</div>
<label class="text-xs space-y-1">
<span>{{ __('money.apply_document') }} ({{ $target['currency'] }})</span>
<input wire:model="allocations.{{ $target['id'] }}.document" inputmode="decimal" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
</label>
<label class="text-xs space-y-1">
<span>{{ __('money.use_payment') }} ({{ $currency }})</span>
<input wire:model="allocations.{{ $target['id'] }}.payment" inputmode="decimal" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
</label>
</div>@endforeach</section>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.notes') }}</span>
<input type="text" wire:model="notes" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" >
</label>
<button type="submit" wire:loading.attr="disabled" class="inline-flex justify-center items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2">{{ __('money.'.($direction === 'incoming' ? 'receive_check' : 'issue_check')) }}</button>
</form>
</div>
