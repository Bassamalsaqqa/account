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
    'hasError' => false,
    'rowKey' => null,
    'resetAfterSelect' => false,
])

@php
    $id = $id ?? 'combobox-' . \Illuminate\Support\Str::random(8);
    $rowKey = is_string($rowKey) && $rowKey !== '' ? $rowKey : null;
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
        context: @js($context),
        wireModel: {{ $wireModel ? "'{$wireModel}'" : 'null' }},
        onSelect: {{ $onSelect ? "'{$onSelect}'" : 'null' }},
        extraArgs: {{ $extraArgsJson }},
        resetAfterSelect: {{ $resetAfterSelect ? 'true' : 'false' }},
        value: '{{ $selectedIdStr }}',
        label: @js($selectedLabel),
        options: {{ $initialOptionsJson }},
        disabled: {{ $disabled ? 'true' : 'false' }},
        required: {{ $required ? 'true' : 'false' }},
        hasError: {{ $hasError ? 'true' : 'false' }},
        placeholder: @js($placeholder),
        ariaLabel: @js($ariaLabel)
    })"
    @if($rowKey) wire:key="cb-{{ $rowKey }}" @endif
    wire:ignore.self
>
    <span
        hidden
        x-ref="syncBridge"
        data-combobox-id="{{ $id }}"
        data-type="{{ $type }}"
        data-context="{{ $context ?? '' }}"
        data-selected-id="{{ $selectedIdStr }}"
        data-selected-label="{{ $selectedLabel }}"
        data-extra-args="{{ $extraArgsJson }}"
        data-reset-after-select="{{ $resetAfterSelect ? '1' : '0' }}"
        data-disabled="{{ $disabled ? '1' : '0' }}"
        data-has-error="{{ $hasError ? '1' : '0' }}"
        data-placeholder="{{ $placeholder }}"
        data-aria-label="{{ $ariaLabel }}"
    ></span>

    <span class="sr-only" role="status" aria-live="polite" x-text="liveAnnouncement"></span>

    @if($wireModel)
        <input type="hidden" name="{{ $wireModel }}" :value="selectedId" />
    @endif

    <div class="relative flex items-center">
        <input
            x-ref="searchInput"
            @if($rowKey) wire:key="cb-input-{{ $rowKey }}" @endif
            id="{{ $id }}"
            type="text"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="open.toString()"
            :aria-controls="listboxId"
            :aria-activedescendant="activeId"
            :aria-label="ariaLabel"
            aria-label="{{ $ariaLabel }}"
            @if($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            :aria-invalid="hasError ? 'true' : null"
            :aria-describedby="hasError ? '{{ $id }}-error' : null"
            x-model="query"
            @input="handleInput()"
            @focus="handleFocus()"
            @click="handleTriggerClick()"
            @keydown="handleKeydown($event)"
            :placeholder="placeholder"
            :readonly="syncing"
            :aria-busy="(pendingDebounce || searching || syncing) ? 'true' : null"
            :disabled="disabled || (typeof saving !== 'undefined' && saving) || (typeof documentBusy !== 'undefined' && documentBusy)"
            autocomplete="off"
            spellcheck="false"
            :class="hasError ? 'border-danger' : 'border-border'"
            class="w-full rounded-control border {{ $hasError ? 'border-danger' : 'border-border' }} bg-canvas px-3 text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden transition-colors {{ $compact ? 'min-h-[44px] md:min-h-8 md:h-8 py-1' : 'min-h-[44px] h-11 py-2' }} ltr:pr-8 rtl:pl-8"
            dir="auto"
        />

        <div class="absolute inset-y-0 ltr:right-0 rtl:left-0 flex items-center ltr:pr-2 rtl:pl-2 gap-1 pointer-events-auto">
            <span x-show="pendingDebounce || searching || syncing" x-cloak class="inline-block w-4 h-4 border-2 border-primary border-t-transparent rounded-full animate-spin text-primary" aria-hidden="true"></span>

            <button
                type="button"
                x-show="isSelected && !pendingDebounce && !searching && !syncing"
                x-cloak
                @click.stop="clear()"
                title="{{ __('search.clear_search') }}"
                aria-label="{{ __('search.clear_search') }}"
                :disabled="disabled || (typeof saving !== 'undefined' && saving) || (typeof documentBusy !== 'undefined' && documentBusy)"
                class="min-h-[44px] min-w-[32px] md:min-h-7 md:min-w-7 flex items-center justify-center text-text-muted hover:text-danger rounded-control transition-colors cursor-pointer"
            >
                <span class="text-base font-bold leading-none">&times;</span>
            </button>
        </div>
    </div>

    <template x-teleport="body">
        <div
            wire:ignore
            x-ref="overlayPanel"
            x-show="open"
            x-cloak
            :data-combobox-overlay="id"
            :data-overlay-mode="overlayMode"
            :role="overlayMode === 'sheet' ? 'dialog' : null"
            :aria-modal="overlayMode === 'sheet' ? 'true' : null"
            :aria-label="overlayMode === 'sheet' ? (ariaLabel || placeholder || @js(__('search.quick_search'))) : null"
            :style="overlayStyle"
            @keydown="handleOverlayKeydown($event)"
            @click.self="overlayMode === 'sheet' ? handleOutsideClick() : null"
            class="fixed z-[70] text-text-primary focus:outline-none"
            :class="overlayMode === 'sheet'
                ? 'flex items-center justify-center bg-black/30 p-2'
                : 'bg-surface border border-border rounded-control shadow-lg overflow-hidden'"
        >
            <div
                class="flex flex-col w-full min-h-0"
                :class="overlayMode === 'sheet'
                    ? 'bg-surface w-full max-w-lg max-h-[min(65dvh,22rem,var(--combobox-fallback-max-h))] rounded-card border border-border shadow-xl overflow-hidden'
                    : ''"
            >
                <div
                    x-show="overlayMode === 'sheet'"
                    class="shrink-0 border-b border-border bg-surface-soft px-3 py-2.5 flex flex-col gap-2"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-bold text-text-primary truncate" x-text="ariaLabel || placeholder || @js(__('search.quick_search'))"></span>
                        <button
                            type="button"
                            x-ref="sheetCloseBtn"
                            @click="closeOverlay({ restoreFocus: true })"
                            aria-label="{{ __('search.close_search') }}"
                            class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-control text-text-muted hover:text-text-primary hover:bg-surface border border-border cursor-pointer shrink-0"
                        >
                            <span aria-hidden="true" class="text-lg font-bold leading-none">&times;</span>
                        </button>
                    </div>

                    <div class="relative flex items-center">
                        <input
                            x-ref="sheetInput"
                            type="text"
                            x-model="query"
                            @input="handleInput()"
                            @keydown="handleKeydown($event)"
                            :placeholder="placeholder"
                            :aria-label="ariaLabel || placeholder"
                            :aria-controls="listboxId"
                            :aria-activedescendant="activeId"
                            :readonly="syncing"
                            :aria-busy="(pendingDebounce || searching || syncing) ? 'true' : null"
                            autocomplete="off"
                            spellcheck="false"
                            dir="auto"
                            class="w-full min-h-[44px] rounded-control border border-border bg-canvas px-3 py-2 text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden ltr:pr-10 rtl:pl-10"
                        />
                        <button
                            type="button"
                            x-show="query && query.length > 0 && !pendingDebounce && !searching && !syncing"
                            x-cloak
                            @click.stop="clear()"
                            aria-label="{{ __('search.clear_search') }}"
                            class="absolute inset-y-0 ltr:right-0 rtl:left-0 min-h-[44px] min-w-[40px] flex items-center justify-center text-text-muted hover:text-danger cursor-pointer"
                        >
                            <span class="text-base font-bold leading-none">&times;</span>
                        </button>
                    </div>
                </div>

                <div
                    x-ref="listbox"
                    :id="listboxId"
                    role="listbox"
                    aria-label="{{ $ariaLabel }}"
                    class="overflow-y-auto focus:outline-none text-sm text-text-primary divide-y divide-border/40 overscroll-contain"
                    :class="overlayMode === 'sheet' ? 'flex-1 min-h-0' : 'max-h-[var(--combobox-max-h,15rem)]'"
                >
                    <template x-if="offline">
                        <div role="status" class="p-3 text-xs text-danger font-semibold bg-danger-bg/50 flex items-center justify-between gap-2">
                            <span>{{ __('search.offline_notice') }}</span>
                            <button
                                type="button"
                                @click.stop="retrySearch()"
                                class="min-h-[36px] px-2.5 py-1 rounded-control border border-danger/30 bg-surface text-danger hover:bg-danger-bg text-xs font-semibold cursor-pointer shrink-0"
                            >{{ __('search.retry') }}</button>
                        </div>
                    </template>

                    <template x-if="searchFailed && !offline">
                        <div role="alert" class="p-3 text-xs text-danger font-semibold bg-danger-bg/50 flex items-center justify-between gap-2">
                            <span>{{ __('search.error_occurred') }}</span>
                            <button
                                type="button"
                                @click.stop="retrySearch()"
                                class="min-h-[36px] px-2.5 py-1 rounded-control border border-danger/30 bg-surface text-danger hover:bg-danger-bg text-xs font-semibold cursor-pointer shrink-0"
                            >{{ __('search.retry') }}</button>
                        </div>
                    </template>

                    <template x-if="(pendingDebounce || searching) && items.length === 0">
                        <div role="status" class="p-3 text-xs text-text-muted flex items-center justify-center gap-2">
                            <span class="inline-block w-3.5 h-3.5 border-2 border-primary border-t-transparent rounded-full animate-spin"></span>
                            <span>{{ __('search.loading') }}</span>
                        </div>
                    </template>

                    <template x-if="!pendingDebounce && !searching && !searchFailed && !offline && items.length === 0">
                        <div role="status" class="p-3 text-xs text-text-muted text-center">
                            {{ __('search.no_results') }}
                        </div>
                    </template>

                    <template x-for="(item, index) in items" :key="item.id">
                        <div
                            :id="optionId(item)"
                            role="option"
                            :aria-selected="(String(item.id) === String(selectedId)).toString()"
                            @click="selectItem(item)"
                            @mouseenter="activeIndex = index; activeId = optionId(item)"
                            :class="{
                                'bg-surface-soft text-primary font-semibold': activeIndex === index,
                                'bg-primary/10 font-bold': String(item.id) === String(selectedId) && activeIndex !== index,
                                'text-text-primary hover:bg-surface-soft': String(item.id) !== String(selectedId) && activeIndex !== index
                            }"
                            class="min-h-[44px] md:min-h-9 px-3 py-2 flex items-center justify-between gap-2 cursor-pointer transition-colors select-none"
                        >
                            <div class="flex-1 min-w-0 flex items-center gap-2">
                                <span x-show="String(item.id) === String(selectedId)" class="text-primary font-bold text-xs shrink-0" aria-hidden="true">&check;</span>
                                <span class="truncate text-sm" x-text="item.label" dir="auto"></span>
                            </div>

                            <template x-if="item.sublabel">
                                <span class="min-w-0 max-w-[45%] flex items-center justify-end gap-1 text-xs text-text-muted overflow-hidden">
                                    <template x-if="item.meta?.business_name">
                                        <span class="min-w-0 w-full flex items-center justify-end gap-1">
                                            <template x-if="item.meta?.code || item.meta?.phone">
                                                <bdi dir="ltr" class="shrink-0 max-w-[50%] truncate font-mono tabular-nums" x-text="item.meta.code || item.meta.phone"></bdi>
                                            </template>
                                            <template x-if="item.meta?.code || item.meta?.phone">
                                                <span aria-hidden="true" class="shrink-0">&middot;</span>
                                            </template>
                                            <span class="min-w-0 truncate" dir="auto" x-text="item.meta.business_name"></span>
                                        </span>
                                    </template>
                                    <template x-if="!item.meta?.business_name">
                                        <bdi dir="ltr" class="block min-w-0 truncate font-mono tabular-nums" x-text="item.sublabel"></bdi>
                                    </template>
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
        </div>
    </template>
</div>
