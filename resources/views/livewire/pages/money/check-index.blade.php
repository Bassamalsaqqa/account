<div class="min-w-0 space-y-6">
@include('livewire.pages.money.nav')
<div class="flex flex-wrap justify-between gap-3">
<h1 class="text-2xl font-bold">{{ __('money.checks') }}</h1>
<div class="flex flex-wrap gap-2">@if($canIncoming)<a class="inline-flex justify-center items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2" href="{{ route('money.checks.create', 'incoming') }}">{{ __('money.receive_check') }}</a>@endif @if($canOutgoing)<a class="inline-flex justify-center items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2" href="{{ route('money.checks.create', 'outgoing') }}">{{ __('money.issue_check') }}</a>@endif</div>
</div>
<div class="grid gap-3 sm:grid-cols-3">
<label class="text-sm">{{ __('money.search') }}<input type="search" wire:model.live.debounce.300ms="search" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
</label>
<label class="text-sm">{{ __('money.direction') }}<select wire:model.live="direction" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
<option value="">{{ __('money.all') }}</option>
<option value="incoming">{{ __('money.incoming') }}</option>@if($canViewOutgoing)<option value="outgoing">{{ __('money.outgoing') }}</option>@endif</select>
</label>
<label class="text-sm">{{ __('money.status') }}<select wire:model.live="status" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
<option value="">{{ __('money.all') }}</option>@foreach(['due','received','deposited','issued','cleared','returned','cancelled'] as $state)<option value="{{ $state }}">{{ __('money.'.$state) }}</option>@endforeach</select>
</label>
</div>
<div class="rounded-card border border-border bg-white p-4 sm:p-6">@forelse($checks as $check)<a href="{{ route('money.checks.show', $check->public_id) }}" class="grid gap-2 border-b border-border py-3 sm:grid-cols-4">
<div>
<bdi class="font-semibold">{{ $check->check_number }}</bdi>
<p class="text-xs">{{ __('money.'.$check->direction) }}</p>
</div>
<p class="text-sm">{{ $check->partyDisplayName() }}</p>
<div>
<bdi>{{ $check->amount }} {{ $check->currency_code }}</bdi>
<p class="text-xs">{{ __('money.due_date') }}: {{ $check->due_date->toDateString() }}</p>
</div>
<span class="text-sm">{{ __('money.'.$check->status) }}</span>
</a>@empty<p>{{ __('money.empty') }}</p>
@endforelse{{ $checks->links() }}</div>
</div>
