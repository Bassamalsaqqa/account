<div class="min-w-0 space-y-6">
@include('livewire.pages.money.nav')
<div class="flex flex-wrap justify-between gap-3">
<h1 class="text-2xl font-bold">{{ __('money.checks') }}</h1>
<div class="flex flex-wrap gap-2">@if($canIncoming)<a class="touch-action bg-primary text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2" href="{{ route('money.checks.create', 'incoming') }}">{{ __('money.receive_check') }}</a>@endif @if($canOutgoing)<a class="touch-action bg-primary text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2" href="{{ route('money.checks.create', 'outgoing') }}">{{ __('money.issue_check') }}</a>@endif</div>
</div>

<div class="flex items-center gap-2 overflow-x-auto pb-1">
    <span class="text-xs font-semibold text-text-secondary">{{ __('money.quick_filters') }}:</span>
    <button type="button" wire:click="setAttention('open')" wire:loading.attr="disabled" aria-pressed="{{ $attention === 'open' ? 'true' : 'false' }}" class="touch-action shrink-0 gap-1.5 text-sm font-medium border {{ $attention === 'open' ? 'bg-primary text-white border-primary' : 'bg-surface text-text border-border hover:bg-surface' }}">
        <span>{{ __('money.open') }}</span>
        @if(isset($attentionCounts['open']))<span class="rounded-full px-1.5 py-0.5 text-xs {{ $attention === 'open' ? 'bg-surface text-primary font-bold' : 'bg-surface text-text-secondary' }}">{{ $attentionCounts['open'] }}</span>@endif
    </button>
    <button type="button" wire:click="setAttention('today')" wire:loading.attr="disabled" aria-pressed="{{ $attention === 'today' ? 'true' : 'false' }}" class="touch-action shrink-0 gap-1.5 text-sm font-medium border {{ $attention === 'today' ? 'bg-primary text-white border-primary' : 'bg-surface text-text border-border hover:bg-surface' }}">
        <span>{{ __('money.today') }}</span>
        @if(isset($attentionCounts['today']))<span class="rounded-full px-1.5 py-0.5 text-xs {{ $attention === 'today' ? 'bg-surface text-primary font-bold' : 'bg-surface text-text-secondary' }}">{{ $attentionCounts['today'] }}</span>@endif
    </button>
    <button type="button" wire:click="setAttention('overdue')" wire:loading.attr="disabled" aria-pressed="{{ $attention === 'overdue' ? 'true' : 'false' }}" class="touch-action shrink-0 gap-1.5 text-sm font-medium border {{ $attention === 'overdue' ? 'bg-primary text-white border-primary' : 'bg-surface text-text border-border hover:bg-surface' }}">
        <span>{{ __('money.overdue') }}</span>
        @if(isset($attentionCounts['overdue']))<span class="rounded-full px-1.5 py-0.5 text-xs {{ $attention === 'overdue' ? 'bg-surface text-primary font-bold' : 'bg-surface text-text-secondary' }}">{{ $attentionCounts['overdue'] }}</span>@endif
    </button>
    <button type="button" wire:click="setAttention('next_7_days')" wire:loading.attr="disabled" aria-pressed="{{ $attention === 'next_7_days' ? 'true' : 'false' }}" class="touch-action shrink-0 gap-1.5 text-sm font-medium border {{ $attention === 'next_7_days' ? 'bg-primary text-white border-primary' : 'bg-surface text-text border-border hover:bg-surface' }}">
        <span>{{ __('money.next_7_days') }}</span>
        @if(isset($attentionCounts['next_7_days']))<span class="rounded-full px-1.5 py-0.5 text-xs {{ $attention === 'next_7_days' ? 'bg-surface text-primary font-bold' : 'bg-surface text-text-secondary' }}">{{ $attentionCounts['next_7_days'] }}</span>@endif
    </button>
    @if($attention !== '' || $direction !== '' || $status !== '' || $search !== '' || $dateFrom !== '' || $dateTo !== '')
        <button wire:loading.attr="disabled" type="button" wire:click="resetFilters" class="touch-action border border-border bg-surface text-sm text-text-secondary hover:text-text hover:bg-surface-hover focus:ring-2 focus:ring-primary">
            {{ __('money.reset_filters') }}
        </button>
    @endif
</div>

<x-refinement-filters :active-count="(int) filled($direction) + (int) filled($status) + (int) filled($dateFrom) + (int) filled($dateTo) + (int) filled($attention)">
        <x-slot:search>
            <input aria-label="{{ __('money.search') }}" type="search" wire:model.live.debounce.300ms="search" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary">
        </x-slot:search>

        <select aria-label="{{ __('money.direction') }}" wire:model.live="direction" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary">
<option value="">{{ __('money.all') }}</option>
<option value="incoming">{{ __('money.incoming') }}</option>@if($canViewOutgoing)<option value="outgoing">{{ __('money.outgoing') }}</option>@endif</select>
        <select aria-label="{{ __('money.status') }}" wire:model.live="status" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary">
<option value="">{{ __('money.all') }}</option>@foreach(['due','received','deposited','issued','cleared','returned','cancelled'] as $state)<option value="{{ $state }}">{{ __('money.'.$state) }}</option>@endforeach</select>
        <label class="grid gap-1 text-sm text-text-secondary">{{ __('inventory.date_from') }}<input aria-label="{{ __('inventory.date_from') }}" type="date" wire:model.live="dateFrom" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-2 py-1.5 text-sm focus:border-primary focus:ring-primary"></label>
        <label class="grid gap-1 text-sm text-text-secondary">{{ __('inventory.date_to') }}<input aria-label="{{ __('inventory.date_to') }}" type="date" wire:model.live="dateTo" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-2 py-1.5 text-sm focus:border-primary focus:ring-primary"></label>
    </x-refinement-filters>

@error('dateFrom')<p class="text-sm text-danger" role="alert">{{ $message }}</p>@enderror
@error('dateTo')<p class="text-sm text-danger" role="alert">{{ $message }}</p>@enderror

<div class="rounded-card border border-border bg-surface overflow-hidden">
    <div class="divide-y divide-border">
        @forelse($checks as $check)
            <a href="{{ route('money.checks.show', $check->public_id) }}" class="flex items-center justify-between gap-3 px-4 py-2.5 min-h-11 hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-primary active:bg-primary-50 transition-colors">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 min-w-0">
                        <bdi dir="ltr" class="font-mono font-bold text-sm text-primary truncate">{{ $check->check_number }}</bdi>
                        <x-badge variant="{{ in_array($check->status, ['cleared', 'deposited'], true) ? 'success' : ($check->status === 'returned' || $check->status === 'cancelled' ? 'danger' : 'neutral') }}">{{ __('money.'.$check->status) }}</x-badge>
                    </div>
                    <p class="text-xs text-text-secondary truncate mt-0.5">
                        {{ $check->partyDisplayName() }} &middot; {{ __('money.'.$check->direction) }} &middot; {{ __('money.due_date') }}: <bdi dir="ltr" class="font-mono tabular-nums">{{ $check->due_date->toDateString() }}</bdi>
                    </p>
                </div>
                <div class="text-end shrink-0">
                    <bdi dir="ltr" class="block font-mono tabular-nums font-bold text-sm text-text-primary">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($check->amount) }} {{ $check->currency_code }}</bdi>
                    <span class="hidden sm:inline text-xs text-primary font-semibold">{{ __('refinement_lists.open') }}</span>
                </div>
            </a>
        @empty
            <p class="px-4 py-8 text-center text-sm text-text-muted">{{ __('money.empty') }}</p>
        @endforelse
    </div>
    @if ($checks->count() > 0 || $checks->currentPage() > 1)
        <div class="p-4 border-t border-border"><x-table-pagination :paginator="$checks" /></div>
    @endif
</div>
</div>
