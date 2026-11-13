@props(['field', 'values', 'selected' => '', 'required' => false])
<div class="relative" wire:key="report-selector-{{ $field }}-{{ hash('sha256', json_encode([$selected, $values], JSON_THROW_ON_ERROR)) }}"
    x-data="reportPartyCombobox({lookup: term => $wire.lookupParty(@js($field), term), commit: id => $wire.$set('filters.{{ $field }}', id, false), emptyLabel: @js($required ? __('reports.select_required') : __('reports.all')), initialSelected: @js((string) $selected), initialValues: @js($values), watch: listener => $wire.$watch('filters.{{ $field }}', listener)})"
    x-id="['party-list', 'party-status']"
    @click.outside="dismiss()" @focusout="if (!$el.contains($event.relatedTarget)) dismiss()">
    <input id="report-{{ $field }}" type="text" role="combobox" aria-autocomplete="list" aria-haspopup="listbox"
        :aria-expanded="open" :aria-controls="$id('party-list')" :aria-describedby="$id('party-status')"
        :aria-activedescendant="open && active >= 0 ? $id('party-list') + '-' + active : null"
        aria-required="{{ $required ? 'true' : 'false' }}" :aria-busy="busy"
        :value="term" @input="input($event.target.value)" @click="search()" @keydown="key($event)"
        maxlength="100" autocomplete="off" dir="auto"
        placeholder="{{ $required ? __('reports.select_required') : __('reports.all') }}"
        class="min-h-[44px] sm:min-h-10 w-full rounded-control border border-border bg-surface px-3 text-base sm:text-sm focus:border-primary focus:ring-1 focus:ring-primary" />
    <div x-cloak x-show="open" class="absolute z-30 top-full mt-1 w-full rounded-control border border-border bg-surface shadow-lg">
        <ul x-ref="list" :id="$id('party-list')" role="listbox" aria-label="{{ __('reports.'.$field) }}" class="max-h-64 overflow-y-auto">
            <template x-for="(option, index) in options" :key="option.id">
                <li role="option" :id="$id('party-list') + '-' + index" :aria-selected="selected === String(option.id)"
                    @pointerdown.prevent="choose(index)" @pointermove="active = index"
                    :class="active === index ? 'bg-surface-soft' : ''"
                    class="min-h-[44px] sm:min-h-10 px-3 py-2 text-base sm:text-sm cursor-pointer hover:bg-surface-soft">
                    <bdi dir="auto" x-text="option.label"></bdi>
                </li>
            </template>
        </ul>
        <p :id="$id('party-status')" role="status" class="px-3 text-sm text-text-muted">
            <span x-show="busy">{{ __('reports.loading') }}</span>
            <span x-show="failed">{{ __('reports.error') }}</span>
            <span x-show="!busy && !failed && options.length === 1">{{ __('reports.empty') }}</span>
        </p>
    </div>
</div>
