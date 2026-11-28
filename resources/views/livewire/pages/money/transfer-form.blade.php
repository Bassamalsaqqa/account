<div class="min-w-0 space-y-6">
@include('livewire.pages.money.nav')
<h1 class="text-2xl font-bold">{{ __('money.new_transfer') }}</h1>
<p class="text-sm text-text-secondary">{{ __('money.transfer_help') }}</p>
<form wire:submit="save" class="rounded-card border border-border bg-surface p-4 sm:p-6 space-y-5">@if($errors->any())<div role="alert" class="rounded-control border border-danger/20 bg-danger-bg p-3 text-sm text-danger">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.date') }}</span>
<input type="date" wire:model="date" class="w-full min-w-0 min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary" >
</label>
<div class="grid gap-5 sm:grid-cols-2">
<section class="space-y-4">
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.from_account') }}</span>
<select wire:model.live="fromId" class="w-full min-w-0 min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary">
<option value="">{{ __('money.select_account') }}</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->displayName() }} · {{ $account->currency_code }}</option>@endforeach</select>
</label>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.from_amount') }}</span>
<input type="text" wire:model="fromAmount" class="w-full min-w-0 min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm font-mono tabular-nums focus:border-primary focus:ring-primary" inputmode="decimal" dir="ltr">
</label>
@if ($accounts->firstWhere('id', $fromId)?->currency_code !== $baseCurrency)
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.rate') }}</span>
<input type="text" wire:model="fromRate" class="w-full min-w-0 min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm font-mono tabular-nums focus:border-primary focus:ring-primary" inputmode="decimal" dir="ltr">
</label>
@endif
</section>
<section class="space-y-4">
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.to_account') }}</span>
<select wire:model.live="toId" class="w-full min-w-0 min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary">
<option value="">{{ __('money.select_account') }}</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->displayName() }} · {{ $account->currency_code }}</option>@endforeach</select>
</label>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.to_amount') }}</span>
<input type="text" wire:model="toAmount" class="w-full min-w-0 min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm font-mono tabular-nums focus:border-primary focus:ring-primary" inputmode="decimal" dir="ltr">
</label>
@if ($accounts->firstWhere('id', $toId)?->currency_code !== $baseCurrency)
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.rate') }}</span>
<input type="text" wire:model="toRate" class="w-full min-w-0 min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm font-mono tabular-nums focus:border-primary focus:ring-primary" inputmode="decimal" dir="ltr">
</label>
@endif
</section>
</div>
<label class="block min-w-0 text-sm space-y-1">
<span>{{ __('money.notes') }}</span>
<input type="text" wire:model="notes" class="w-full min-w-0 min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary" >
</label>
<p class="text-xs text-text-secondary">{{ __('money.rate_help', ['currency' => $baseCurrency]) }}</p>
<div class="flex justify-end pt-2 border-t border-border">
<button type="submit" wire:loading.attr="disabled" class="min-h-11 lg:min-h-9.5 inline-flex justify-center items-center rounded-control bg-primary px-5 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2 cursor-pointer">{{ __('money.post_transfer') }}</button>
</div>
</form>
</div>
