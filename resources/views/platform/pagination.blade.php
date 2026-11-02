@if ($paginator->count() > 0)
<nav aria-label="{{ __('platform.pagination') }}" class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-slate-200">
    @if ($paginator->previousPageUrl())
        <a class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-200 bg-white px-4 text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-50 hover:text-slate-900 transition-colors" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('platform.previous') }}</a>
    @else
        <span aria-disabled="true" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-4 text-xs sm:text-sm font-semibold text-slate-400 cursor-not-allowed">{{ __('platform.previous') }}</span>
    @endif

    <span aria-current="page" class="text-xs sm:text-sm font-medium text-slate-500">{{ __('platform.page', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>

    @if ($paginator->nextPageUrl())
        <a class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-200 bg-white px-4 text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-50 hover:text-slate-900 transition-colors" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('platform.next') }}</a>
    @else
        <span aria-disabled="true" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-4 text-xs sm:text-sm font-semibold text-slate-400 cursor-not-allowed">{{ __('platform.next') }}</span>
    @endif
</nav>
@endif
