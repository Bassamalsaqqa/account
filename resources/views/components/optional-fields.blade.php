@props(['id', 'open' => false, 'label' => null])
<div x-data="{ optionalOpen: @js((bool) $open) }" class="space-y-3">
    <button type="button" role="switch" :aria-checked="optionalOpen.toString()"
            aria-controls="{{ $id }}" @click="optionalOpen = !optionalOpen"
            class="touch-action min-h-11 inline-flex items-center gap-2 rounded-control border border-border px-3 text-sm font-semibold hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-50"
            :disabled="documentBusy || saving">
        <span aria-hidden="true" class="h-5 w-9 rounded-full p-0.5" :class="optionalOpen ? 'bg-primary' : 'bg-border-strong'">
            <span class="block h-4 w-4 rounded-full bg-surface" :class="optionalOpen ? 'ms-auto' : ''"></span>
        </span>
        {{ $label ?? __('workflow.more_options') }}
    </button>
    <div id="{{ $id }}" x-show="optionalOpen" x-cloak class="space-y-4">{{ $slot }}</div>
</div>
