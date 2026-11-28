@props(['detailsId' => null])

<button
    data-row-details-toggle
    type="button"
    @click.stop="expanded = !expanded"
    :aria-expanded="expanded.toString()"
    @if ($detailsId) aria-controls="{{ $detailsId }}" @endif
    :aria-label="expanded ? '{{ __('refinement_lists.hide_details') }}' : '{{ __('refinement_lists.show_details') }}'"
    title="{{ __('refinement_lists.show_details') }}"
    {{ $attributes->merge(['class' => 'lg:hidden touch-action touch-manipulation inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control border border-border bg-surface text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors cursor-pointer']) }}
>
    <x-icon name="chevron-down" class="h-4 w-4 transition-transform duration-150" ::class="expanded ? 'rotate-180' : ''" aria-hidden="true" />
</button>
