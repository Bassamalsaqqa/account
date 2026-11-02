@props(['paginator', 'livewire' => true])
@php
    $pageSizeId = 'table-page-size-'.$paginator->getPageName();
    $selectedSize = \App\Support\TablePageSize::resolve($paginator->perPage());
@endphp
<div class="table-pagination flex flex-wrap items-center justify-between gap-3">
    @if (! $livewire)
        <form method="GET" action="{{ url()->current() }}" class="flex flex-wrap items-center gap-2">
            @foreach (request()->query() as $key => $value)
                @if (is_scalar($value) && ! in_array($key, ['page', 'receipts_page', 'per_page'], true))
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach
    @else
        <div class="flex items-center gap-2">
    @endif
        <label for="{{ $pageSizeId }}" class="whitespace-nowrap text-sm font-medium text-text-secondary">{{ __('pagination.rows_per_page') }}</label>
        <select id="{{ $pageSizeId }}" @if($livewire) wire:model.live="perPage" wire:loading.attr="disabled" @else name="per_page" onchange="this.form.requestSubmit()" @endif class="min-h-11 rounded-control border border-border bg-surface px-3 py-2 text-sm text-text-primary focus:border-primary focus:ring-primary">
            @foreach (\App\Support\TablePageSize::OPTIONS as $size)
                <option value="{{ $size }}" @selected($selectedSize === $size)>{{ $size }}</option>
            @endforeach
        </select>
        @if (! $livewire)
            <button type="submit" class="touch-action border border-border text-sm">{{ __('pagination.apply') }}</button>
        @endif
    @if (! $livewire)</form>@else</div>@endif
    <div class="min-w-0 flex-1">{{ $livewire ? $paginator->links('components.table-page-links') : $paginator->withQueryString()->links('platform.pagination') }}</div>
</div>
