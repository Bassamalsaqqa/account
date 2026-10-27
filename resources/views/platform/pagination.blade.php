@if ($paginator->hasPages())
<nav aria-label="{{ __('platform.pagination') }}" class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-border">
    @if ($paginator->previousPageUrl())
        <a class="inline-flex min-h-11 items-center justify-center rounded-control border border-border-strong bg-white px-4 text-xs sm:text-sm font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('platform.previous') }}</a>
    @else
        <span aria-disabled="true" class="inline-flex min-h-11 items-center justify-center rounded-control border border-border/60 bg-surface-soft/60 px-4 text-xs sm:text-sm font-semibold text-text-muted/60 cursor-not-allowed">{{ __('platform.previous') }}</span>
    @endif

    <span aria-current="page" class="text-xs sm:text-sm font-semibold text-text-muted">{{ __('platform.page', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>

    @if ($paginator->nextPageUrl())
        <a class="inline-flex min-h-11 items-center justify-center rounded-control border border-border-strong bg-white px-4 text-xs sm:text-sm font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('platform.next') }}</a>
    @else
        <span aria-disabled="true" class="inline-flex min-h-11 items-center justify-center rounded-control border border-border/60 bg-surface-soft/60 px-4 text-xs sm:text-sm font-semibold text-text-muted/60 cursor-not-allowed">{{ __('platform.next') }}</span>
    @endif
</nav>
@endif
