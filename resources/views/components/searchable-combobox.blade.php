@props([
    'id' => null,
    'type' => 'customer',
    'context' => null,
    'wireModel' => null,
    'selectedId' => null,
    'selectedLabel' => '',
    'options' => [],
    'onSelect' => null,
    'extraArgs' => [],
    'placeholder' => null,
    'ariaLabel' => null,
    'disabled' => false,
    'required' => false,
    'compact' => false,
])

@php
    $id = $id ?? 'combobox-' . \Illuminate\Support\Str::random(8);
    $placeholder = $placeholder ?? __('search.search_placeholder');
    $ariaLabel = $ariaLabel ?? $placeholder;
    $initialOptionsJson = json_encode(array_values($options), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    $extraArgsJson = json_encode($extraArgs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    $selectedIdStr = ($selectedId !== null && $selectedId !== '') ? (string) $selectedId : '';
@endphp

<div
    class="relative w-full searchable-combobox"
    x-data="searchableCombobox({
        id: '{{ $id }}',
        type: '{{ $type }}',
        wireModel: {{ $wireModel ? "'{$wireModel}'" : 'null' }},
        onSelect: {{ $onSelect ? "'{$onSelect}'" : 'null' }},
        extraArgs: {{ $extraArgsJson }},
        value: '{{ $selectedIdStr }}',
        label: @js($selectedLabel),
        options: {{ $initialOptionsJson }},
        disabled: {{ $disabled ? 'true' : 'false' }},
        required: {{ $required ? 'true' : 'false' }},
        placeholder: @js($placeholder)
    })"
    @click.outside="handleOutsideClick()"
    wire:ignore.self
>
    @if($wireModel)
        <input type="hidden" name="{{ $wireModel }}" :value="selectedId" />
    @endif

    <div class="relative flex items-center">
        <input
            x-ref="searchInput"
            id="{{ $id }}"
            type="text"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="open.toString()"
            :aria-controls="listboxId"
            :aria-activedescendant="activeId"
            aria-label="{{ $ariaLabel }}"
            x-model="query"
            @input="handleInput()"
            @focus="handleFocus()"
            @keydown="handleKeydown($event)"
            placeholder="{{ $placeholder }}"
            :disabled="{{ $disabled ? 'true' : 'false' }} || (typeof documentBusy !== 'undefined' && documentBusy) || (typeof saving !== 'undefined' && saving)"
            autocomplete="off"
            spellcheck="false"
            class="w-full rounded-control border border-border bg-canvas px-3 text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden transition-colors {{ $compact ? 'min-h-[44px] md:min-h-8 md:h-8 py-1' : 'min-h-[44px] h-11 py-2' }} ltr:pr-8 rtl:pl-8"
            dir="auto"
        />

        <div class="absolute inset-y-0 ltr:right-0 rtl:left-0 flex items-center ltr:pr-2 rtl:pl-2 gap-1 pointer-events-auto">
            <span x-show="searching" x-cloak class="inline-block w-4 h-4 border-2 border-primary border-t-transparent rounded-full animate-spin text-primary" aria-hidden="true"></span>

            <button
                type="button"
                x-show="isSelected && !searching"
                x-cloak
                @click.stop="clear()"
                title="{{ __('search.clear_search') }}"
                aria-label="{{ __('search.clear_search') }}"
                :disabled="(typeof documentBusy !== 'undefined' && documentBusy) || (typeof saving !== 'undefined' && saving)"
                class="min-h-[44px] min-w-[32px] md:min-h-7 md:min-w-7 flex items-center justify-center text-text-muted hover:text-danger rounded-control transition-colors cursor-pointer"
            >
                <span class="text-base font-bold leading-none">&times;</span>
            </button>
        </div>
    </div>

    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        :id="listboxId"
        role="listbox"
        aria-label="{{ $ariaLabel }}"
        class="absolute z-50 w-full mt-1 bg-surface border border-border rounded-control shadow-lg max-h-60 overflow-y-auto focus:outline-none text-sm text-text-primary divide-y divide-border/40"
    >
        <template x-if="offline">
            <div role="status" class="p-3 text-xs text-danger font-semibold bg-danger-bg/50">
                {{ __('search.offline_notice') }}
            </div>
        </template>

        <template x-if="failed && !offline">
            <div role="alert" class="p-3 text-xs text-danger font-semibold bg-danger-bg/50">
                {{ __('search.error_occurred') }}
            </div>
        </template>

        <template x-if="searching && items.length === 0">
            <div role="status" class="p-3 text-xs text-text-muted flex items-center justify-center gap-2">
                <span class="inline-block w-3.5 h-3.5 border-2 border-primary border-t-transparent rounded-full animate-spin"></span>
                <span>{{ __('search.loading') }}</span>
            </div>
        </template>

        <template x-if="!searching && !failed && items.length === 0">
            <div role="status" class="p-3 text-xs text-text-muted text-center">
                {{ __('search.no_results') }}
            </div>
        </template>

        <template x-for="(item, index) in items" :key="item.id">
            <div
                :id="'{{ $id }}-opt-' + item.id"
                role="option"
                :aria-selected="(String(item.id) === String(selectedId)).toString()"
                @click="selectItem(item)"
                @mouseenter="activeIndex = index; activeId = '{{ $id }}-opt-' + item.id"
                :class="{
                    'bg-surface-soft text-primary font-semibold': activeIndex === index,
                    'bg-primary/10 font-bold': String(item.id) === String(selectedId) && activeIndex !== index,
                    'text-text-primary hover:bg-surface-soft': String(item.id) !== String(selectedId) && activeIndex !== index
                }"
                class="min-h-[44px] md:min-h-9 px-3 py-2 flex items-center justify-between gap-2 cursor-pointer transition-colors select-none"
            >
                <div class="flex-1 min-w-0 flex items-center gap-2">
                    <span x-show="String(item.id) === String(selectedId)" class="text-primary font-bold text-xs" aria-hidden="true">&check;</span>
                    <span class="truncate text-sm" x-text="item.label" dir="auto"></span>
                </div>

                <template x-if="item.sublabel">
                    <span class="shrink-0 text-xs text-text-muted font-mono tabular-nums">
                        <bdi dir="ltr" x-text="item.sublabel"></bdi>
                    </span>
                </template>
            </div>
        </template>

        <template x-if="hasMore">
            <div class="p-2 text-center text-xs text-text-muted bg-surface-soft/60 border-t border-border/50">
                <button
                    type="button"
                    @click.stop="loadMore()"
                    :disabled="loadingMore"
                    class="w-full min-h-[44px] py-2 px-3 text-xs font-medium text-primary hover:text-primary-hover hover:bg-surface-soft rounded-control transition-colors flex items-center justify-center gap-2 cursor-pointer focus:outline-none focus:ring-1 focus:ring-primary"
                >
                    <span x-show="loadingMore" class="inline-block w-3.5 h-3.5 border-2 border-primary border-t-transparent rounded-full animate-spin"></span>
                    <span x-text="loadingMore ? '{{ __('search.loading') }}' : '{{ __('search.load_more') }}'"></span>
                </button>
            </div>
        </template>
    </div>
</div>
