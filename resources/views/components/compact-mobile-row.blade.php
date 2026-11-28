@props([
    'rowId' => null,
    'hasDetails' => true,
])

<div
    data-row-surface
    x-data="{ expanded: false }"
    x-id="['mobile-row-details']"
    {{ $attributes->merge(['class' => 'px-3 py-2.5 transition-colors']) }}
>
    <div class="flex items-start justify-between gap-2.5 min-w-0">
        <div class="min-w-0 flex-1 space-y-0.5">
            <div class="flex items-center justify-between gap-2 min-w-0">
                {{ $primary }}
            </div>
            @isset($subtitle)
                <div class="text-xs text-text-secondary truncate min-w-0">
                    {{ $subtitle }}
                </div>
            @endisset
        </div>

        <div class="flex items-center gap-1.5 shrink-0">
            @isset($trailing)
                <div class="text-end shrink-0">
                    {{ $trailing }}
                </div>
            @endisset

            @if ($hasDetails && isset($details))
                <button
                    type="button"
                    @click.stop="expanded = !expanded"
                    :aria-expanded="expanded.toString()"
                    :aria-controls="{{ $rowId ? '\''.$rowId.'\'' : '$id(\'mobile-row-details\')' }}"
                    :aria-label="expanded ? '{{ __('refinement_lists.hide_details') }}' : '{{ __('refinement_lists.show_details') }}'"
                    class="touch-action touch-manipulation inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control border border-border bg-surface text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors cursor-pointer"
                >
                    <x-icon name="chevron-down" class="h-4 w-4 transition-transform duration-150" ::class="expanded ? 'rotate-180' : ''" aria-hidden="true" />
                </button>
            @endif
        </div>
    </div>

    @if ($hasDetails && isset($details))
        <div
            :id="{{ $rowId ? '\''.$rowId.'\'' : '$id(\'mobile-row-details\')' }}"
            x-show="expanded"
            x-cloak
            class="mt-2.5 pt-2.5 border-t border-border/80 space-y-2.5 text-xs"
        >
            {{ $details }}
        </div>
    @endif
</div>
