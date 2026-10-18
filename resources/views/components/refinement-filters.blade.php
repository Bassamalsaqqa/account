@props(['activeCount' => 0])
@php($hasSecondaryFilters = trim((string) $slot) !== '')

<section x-data="{ filtersOpen: false, wide: window.matchMedia('(min-width: 768px)').matches }"
         @resize.window="wide = window.matchMedia('(min-width: 768px)').matches"
         x-id="['filter-panel']"
         {{ $attributes->class(['rounded-card border border-border bg-white p-3 md:p-4 space-y-3']) }}>
    <div class="flex items-end gap-2">
        @isset($search)
            <div class="min-w-0 flex-1">{{ $search }}</div>
        @endisset
        @if($hasSecondaryFilters)
        <button type="button" @click="filtersOpen = !filtersOpen"
                :aria-expanded="filtersOpen.toString()" :aria-controls="$id('filter-panel')"
                class="min-h-11 inline-flex shrink-0 items-center justify-center gap-2 rounded-control border border-border bg-surface-soft px-3 py-2 text-sm font-semibold text-text-primary active:bg-primary-50 focus-visible:outline-2 focus-visible:outline-primary md:hidden">
            <x-icon name="settings" class="h-4 w-4" />
            {{ __('refinement_lists.filters') }}
            @if($activeCount > 0)
                <span class="rounded-full bg-primary px-2 py-0.5 text-xs tabular-nums text-white" aria-label="{{ __('refinement_lists.active_filters') }}">{{ $activeCount }}</span>
            @endif
        </button>
        @endif
    </div>
    @isset($statuses)
        <div class="flex items-center gap-2 overflow-x-auto pb-1" aria-label="{{ __('refinement_lists.status_filters') }}">{{ $statuses }}</div>
    @endisset
    @if($hasSecondaryFilters)
    <div x-cloak x-show="filtersOpen || wide" :id="$id('filter-panel')" class="grid grid-cols-1 gap-3 border-t border-border pt-3 md:grid-cols-2 lg:grid-cols-3 [&_input]:text-base [&_select]:text-base md:[&_input]:text-sm md:[&_select]:text-sm">
        {{ $slot }}
    </div>
    @endif
</section>
