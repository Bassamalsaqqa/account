<div class="min-w-0 space-y-6">
@include('livewire.pages.money.nav')
<h1 class="text-2xl font-bold">{{ __('money.new_transfer') }}</h1>
<p class="text-sm text-text-secondary">{{ __('money.transfer_help') }}</p>
<form wire:submit="save" class="rounded-card border border-border bg-white p-4 sm:p-6 space-y-5">@if($errors->any())<div role="alert" class="rounded-control border border-red-200 bg-red-50 p-3 text-sm text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.date') }}</span>
<input type="date" wire:model="date" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" >
</label>
<div class="grid gap-5 sm:grid-cols-2">
<section class="space-y-4">
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.from_account') }}</span>
<select wire:model.live="fromId" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
<option value="">{{ __('money.select_account') }}</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->displayName() }} · {{ $account->currency_code }}</option>@endforeach</select>
</label>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.from_amount') }}</span>
<input type="text" wire:model="fromAmount" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" inputmode="decimal">
</label>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.rate') }}</span>
<input type="text" wire:model="fromRate" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" inputmode="decimal">
</label>
</section>
<section class="space-y-4">
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.to_account') }}</span>
<select wire:model.live="toId" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
<option value="">{{ __('money.select_account') }}</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->displayName() }} · {{ $account->currency_code }}</option>@endforeach</select>
</label>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.to_amount') }}</span>
<input type="text" wire:model="toAmount" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" inputmode="decimal">
</label>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.rate') }}</span>
<input type="text" wire:model="toRate" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" inputmode="decimal">
</label>
</section>
</div>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.notes') }}</span>
<input type="text" wire:model="notes" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" >
</label>
<p class="text-xs text-text-secondary">{{ __('money.rate_help', ['currency' => $baseCurrency]) }}</p>
<button type="submit" wire:loading.attr="disabled" class="inline-flex justify-center items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2">{{ __('money.post_transfer') }}</button>
</form>
</div>
