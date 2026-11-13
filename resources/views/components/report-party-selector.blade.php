@props(['field', 'values', 'required' => false])
<div class="space-y-2" wire:key="report-selector-{{ $field }}">
    <input type="search" wire:model.live.debounce.300ms="selectorSearch.{{ $field }}"
        maxlength="100" autocomplete="off"
        aria-label="{{ __('reports.search') }} {{ __('reports.'.$field) }}"
        aria-controls="report-{{ $field }}" placeholder="{{ __('reports.search') }}"
        class="min-h-[44px] sm:min-h-10 w-full rounded-control border border-border bg-surface px-3 text-base sm:text-sm focus:border-primary focus:ring-1 focus:ring-primary" />
    <select id="report-{{ $field }}" wire:model="filters.{{ $field }}" @required($required)
        class="min-h-[44px] sm:min-h-10 w-full rounded-control border border-border bg-surface px-3 text-base sm:text-sm focus:border-primary focus:ring-1 focus:ring-primary">
        <option value="">{{ $required ? __('reports.select_required') : __('reports.all') }}</option>
        @foreach($values as $option)
            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
        @endforeach
    </select>
    <p wire:loading wire:target="selectorSearch.{{ $field }}" role="status" class="text-sm text-text-muted">{{ __('reports.loading') }}</p>
    @if($values === [])
        <p class="text-sm text-text-muted" role="status">{{ __('reports.empty') }}</p>
    @endif
</div>
