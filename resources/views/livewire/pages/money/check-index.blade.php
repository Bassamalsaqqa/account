<div class="min-w-0 space-y-6">
@include('livewire.pages.money.nav')
<div class="flex flex-wrap justify-between gap-3">
<h1 class="text-2xl font-bold">{{ __('money.checks') }}</h1>
<div class="flex flex-wrap gap-2">@if($canIncoming)<a class="inline-flex justify-center items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2" href="{{ route('money.checks.create', 'incoming') }}">{{ __('money.receive_check') }}</a>@endif @if($canOutgoing)<a class="inline-flex justify-center items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2" href="{{ route('money.checks.create', 'outgoing') }}">{{ __('money.issue_check') }}</a>@endif</div>
</div>

<div class="flex flex-wrap items-center gap-2">
    <span class="text-xs font-semibold text-text-secondary">{{ __('money.quick_filters') }}:</span>
    <button type="button" wire:click="setAttention('open')" wire:loading.attr="disabled" aria-pressed="{{ $attention === 'open' ? 'true' : 'false' }}" class="inline-flex items-center gap-1.5 rounded-control px-3 py-1.5 text-xs font-medium border {{ $attention === 'open' ? 'bg-primary text-white border-primary' : 'bg-white text-text border-border hover:bg-surface' }}">
        <span>{{ __('money.open') }}</span>
        @if(isset($attentionCounts['open']))<span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $attention === 'open' ? 'bg-white text-primary font-bold' : 'bg-surface text-text-secondary' }}">{{ $attentionCounts['open'] }}</span>@endif
    </button>
    <button type="button" wire:click="setAttention('today')" wire:loading.attr="disabled" aria-pressed="{{ $attention === 'today' ? 'true' : 'false' }}" class="inline-flex items-center gap-1.5 rounded-control px-3 py-1.5 text-xs font-medium border {{ $attention === 'today' ? 'bg-primary text-white border-primary' : 'bg-white text-text border-border hover:bg-surface' }}">
        <span>{{ __('money.today') }}</span>
        @if(isset($attentionCounts['today']))<span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $attention === 'today' ? 'bg-white text-primary font-bold' : 'bg-surface text-text-secondary' }}">{{ $attentionCounts['today'] }}</span>@endif
    </button>
    <button type="button" wire:click="setAttention('overdue')" wire:loading.attr="disabled" aria-pressed="{{ $attention === 'overdue' ? 'true' : 'false' }}" class="inline-flex items-center gap-1.5 rounded-control px-3 py-1.5 text-xs font-medium border {{ $attention === 'overdue' ? 'bg-primary text-white border-primary' : 'bg-white text-text border-border hover:bg-surface' }}">
        <span>{{ __('money.overdue') }}</span>
        @if(isset($attentionCounts['overdue']))<span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $attention === 'overdue' ? 'bg-white text-primary font-bold' : 'bg-surface text-text-secondary' }}">{{ $attentionCounts['overdue'] }}</span>@endif
    </button>
    <button type="button" wire:click="setAttention('next_7_days')" wire:loading.attr="disabled" aria-pressed="{{ $attention === 'next_7_days' ? 'true' : 'false' }}" class="inline-flex items-center gap-1.5 rounded-control px-3 py-1.5 text-xs font-medium border {{ $attention === 'next_7_days' ? 'bg-primary text-white border-primary' : 'bg-white text-text border-border hover:bg-surface' }}">
        <span>{{ __('money.next_7_days') }}</span>
        @if(isset($attentionCounts['next_7_days']))<span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $attention === 'next_7_days' ? 'bg-white text-primary font-bold' : 'bg-surface text-text-secondary' }}">{{ $attentionCounts['next_7_days'] }}</span>@endif
    </button>
    @if($attention !== '' || $direction !== '' || $status !== '' || $search !== '' || $dateFrom !== '' || $dateTo !== '')
        <button type="button" wire:click="resetFilters" class="rounded-control border border-border bg-surface px-2.5 py-1.5 text-xs text-text-secondary hover:text-text hover:bg-surface-hover focus:ring-2 focus:ring-primary">
            {{ __('money.reset_filters') }}
        </button>
    @endif
</div>

<div class="grid gap-3 sm:grid-cols-5">
<label class="text-sm sm:col-span-2">{{ __('money.search') }}<input type="search" wire:model.live.debounce.300ms="search" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
</label>
<label class="text-sm">{{ __('money.direction') }}<select wire:model.live="direction" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
<option value="">{{ __('money.all') }}</option>
<option value="incoming">{{ __('money.incoming') }}</option>@if($canViewOutgoing)<option value="outgoing">{{ __('money.outgoing') }}</option>@endif</select>
</label>
<label class="text-sm">{{ __('money.status') }}<select wire:model.live="status" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
<option value="">{{ __('money.all') }}</option>@foreach(['due','received','deposited','issued','cleared','returned','cancelled'] as $state)<option value="{{ $state }}">{{ __('money.'.$state) }}</option>@endforeach</select>
</label>
<div class="grid grid-cols-2 gap-2">
<label class="text-xs text-text-secondary">{{ __('money.date_from') }}<input type="date" wire:model.live="dateFrom" class="w-full min-w-0 rounded-control border border-border bg-white px-2 py-1.5 text-xs focus:border-primary focus:ring-primary">
</label>
<label class="text-xs text-text-secondary">{{ __('money.date_to') }}<input type="date" wire:model.live="dateTo" class="w-full min-w-0 rounded-control border border-border bg-white px-2 py-1.5 text-xs focus:border-primary focus:ring-primary">
</label>
</div>
</div>

@error('dateFrom')<p class="text-sm text-danger" role="alert">{{ $message }}</p>@enderror
@error('dateTo')<p class="text-sm text-danger" role="alert">{{ $message }}</p>@enderror

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
