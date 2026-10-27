@if ($paginator->hasPages())
<nav aria-label="{{ __('platform.pagination') }}" class="flex flex-wrap items-center justify-between gap-3">
    @if ($paginator->previousPageUrl())
        <a class="inline-flex min-h-11 items-center rounded-lg border bg-white px-4" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('platform.previous') }}</a>
    @else
        <span aria-disabled="true" class="inline-flex min-h-11 items-center px-4 text-slate-500">{{ __('platform.previous') }}</span>
    @endif
    <span aria-current="page">{{ __('platform.page', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>
    @if ($paginator->nextPageUrl())
        <a class="inline-flex min-h-11 items-center rounded-lg border bg-white px-4" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('platform.next') }}</a>
    @else
        <span aria-disabled="true" class="inline-flex min-h-11 items-center px-4 text-slate-500">{{ __('platform.next') }}</span>
    @endif
</nav>
@endif
