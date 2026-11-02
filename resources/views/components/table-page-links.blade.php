@if ($paginator->hasPages())
<nav aria-label="{{ __('platform.pagination') }}" class="flex flex-wrap items-center justify-end gap-3">
    <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" @disabled($paginator->onFirstPage()) class="touch-action border border-border bg-surface px-3 text-sm disabled:cursor-not-allowed disabled:opacity-40">{{ __('pagination.previous') }}</button>
    <span aria-current="page" class="text-sm text-text-secondary tabular-nums">
        @if ($paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
            {{ __('platform.page', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}
        @else
            {{ $paginator->currentPage() }}
        @endif
    </span>
    <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" @disabled(! $paginator->hasMorePages()) class="touch-action border border-border bg-surface px-3 text-sm disabled:cursor-not-allowed disabled:opacity-40">{{ __('pagination.next') }}</button>
</nav>
@endif
