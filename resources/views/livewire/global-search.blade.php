<div x-data="globalSearchComponent()"
     @keydown.window="handleWindowKeydown($event)"
     @open-global-search.window="openModal()"
     class="relative">
    <span x-cloak x-show="failed && !clientOpen" role="alert" class="text-xs text-danger">{{ __('search.error_occurred') }}</span>

    <!-- Mobile Trigger Button (min 44px hit target) -->
    <button type="button"
            @click="openModal()"
            aria-label="{{ __('search.open_search') }}"
            title="{{ __('search.open_search') }} ({{ __('search.shortcut') }})"
            class="lg:hidden min-w-[44px] min-h-[44px] rounded-control border border-border bg-white text-text-secondary hover:bg-surface-soft hover:text-text-primary flex items-center justify-center transition-colors cursor-pointer focus:outline-hidden focus:ring-2 focus:ring-primary/40">
        <x-icon name="search" class="w-5 h-5" />
    </button>

    <!-- Desktop Omnibox Trigger (responsive, max-width: min(18rem, 100%)) -->
    <button type="button"
            @click="openModal()"
            aria-label="{{ __('search.open_search') }}"
            class="hidden lg:flex items-center justify-between w-64 xl:w-72 max-w-[min(18rem,100%)] h-10 px-3 rounded-control border border-border bg-surface-soft/60 hover:bg-surface-soft text-text-muted hover:text-text-secondary transition-all cursor-pointer focus:outline-hidden focus:ring-2 focus:ring-primary/40">
        <div class="flex items-center gap-2 min-w-0">
            <x-icon name="search" class="w-4 h-4 text-text-muted shrink-0" />
            <span class="text-xs truncate font-medium">{{ __('search.search_placeholder') }}</span>
        </div>
        <kbd class="hidden sm:inline-flex shrink-0 whitespace-nowrap items-center px-1.5 py-0.5 text-[10px] font-semibold text-text-muted bg-white border border-border rounded-xs shadow-2xs font-mono">
            {{ __('search.shortcut') }}
        </kbd>
    </button>

    <!-- Teleported Accessible Dialog / Modal (z-index >= 80 to escape fixed-header stacking) -->
    <template x-teleport="body">
        <dialog x-ref="dialog"
             wire:ignore.self
             id="global-search-dialog"
             role="dialog"
             aria-modal="true"
             aria-labelledby="global-search-title"
             @cancel.prevent="closeModal()"
             @close="if (!$refs.dialog.open && clientOpen) closeModal()"
             class="fixed inset-0 z-[85] m-0 h-full max-h-none w-full max-w-none border-0 bg-transparent overflow-y-auto p-4 sm:p-6 md:p-12 open:flex items-start justify-center backdrop:bg-transparent">

            <!-- Backdrop -->
            <div x-show="isModalVisible()"
                 x-transition:enter="transition-opacity ease-out duration-150"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity ease-in duration-100"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 @click="closeModal()"
                 class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs transition-opacity"
                 aria-hidden="true"></div>

            <!-- Dialog Box -->
            <div x-show="isModalVisible()"
                 x-transition:enter="transition-all ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition-all ease-in duration-150"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 class="relative mx-auto max-w-2xl w-full bg-white rounded-card shadow-2xl border border-border overflow-hidden flex flex-col max-h-[85vh] mt-4 sm:mt-10">

                <h2 id="global-search-title" class="sr-only">{{ __('search.title') }}</h2>

                <!-- Search Input Header -->
                <div class="relative flex items-center border-b border-border px-4 py-3 bg-surface-soft/40">
                    <x-icon name="search" class="w-5 h-5 text-text-muted shrink-0 {{ $isRtl ? 'ml-3' : 'mr-3' }}" />

                    <input type="text"
                           x-ref="searchInput"
                           x-model="term" @input="queryChanged()" @input.debounce.250ms="search()"
                           maxlength="100"
                           @keydown.arrow-down.prevent="navigate(1)"
                           @keydown.arrow-up.prevent="navigate(-1)"
                           @keydown.enter.prevent="selectCurrent()"
                           placeholder="{{ __('search.search_placeholder') }}"
                           autocomplete="off"
                           spellcheck="false"
                           aria-autocomplete="list"
                           role="combobox"
                           aria-label="{{ __('search.title') }}"
                           :aria-expanded="isModalVisible() && !dirty && !searching"
                           aria-controls="global-search-results-list"
                           :aria-activedescendant="activeId"
                           class="w-full bg-transparent text-sm md:text-base text-text-primary placeholder:text-text-muted focus:outline-hidden border-0 p-0 font-medium" />

                    <!-- Action / Loading indicator -->
                    <div class="flex items-center gap-2 shrink-0 {{ $isRtl ? 'mr-2' : 'ml-2' }}">
                        <div wire:loading wire:target="query" class="flex items-center">
                            <svg class="animate-spin h-4 w-4 text-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </div>

                        @if ($query !== '')
                            <button type="button"
                                    @click="term = ''; queryChanged(); search(); $refs.searchInput.focus()"
                                    aria-label="{{ __('search.clear_search') }}"
                                    class="p-1 rounded-control text-text-muted hover:text-text-primary hover:bg-surface-soft transition-colors cursor-pointer">
                                <x-icon name="close" class="w-4 h-4" />
                            </button>
                        @endif

                        <button type="button"
                                @click="closeModal()"
                                class="px-2 py-1 rounded-control text-xs font-semibold border border-border bg-white text-text-muted hover:text-text-primary hover:bg-surface-soft transition-colors cursor-pointer">
                            {{ __('search.hints.close') }}
                        </button>
                    </div>
                </div>

                @if ($searchFailed)
                    <p role="alert" class="px-4 py-3 text-sm text-danger">{{ __('search.error_occurred') }}</p>
                @endif

                <!-- Offline Notice -->
                <div wire:offline class="px-4 py-2 bg-amber-50 border-b border-amber-200 text-amber-800 text-xs flex items-center gap-2">
                    <span class="inline-block w-2 h-2 rounded-full bg-amber-500"></span>
                    <span>{{ __('search.offline_notice') }}</span>
                </div>

                <!-- Slow loading notice -->
                <div wire:loading.delay.longer wire:target="query" class="px-4 py-1.5 bg-blue-50 border-b border-blue-200 text-blue-700 text-xs">
                    {{ __('search.slow_loading') }}
                </div>

                <!-- Live Status Announcement for Screen Readers -->
                <p x-cloak x-show="failed" role="alert" class="px-4 py-3 text-sm text-danger">{{ __('search.error_occurred') }}</p>
                <p x-cloak x-show="offline" role="status" class="px-4 py-3 text-sm text-text-secondary">{{ __('search.offline_notice') }}</p>
                <div class="sr-only" role="status" aria-live="polite">
                    @if ($hasSearched)
                        {{ trans_choice('search.results_count', $totalResults, ['count' => $totalResults]) }}
                    @endif
                </div>

                <!-- Results or Empty / Help Container -->
                <div id="global-search-results-list"
                     x-show="!dirty && !searching && !failed && !offline"
                     role="listbox"
                     class="overflow-y-auto divide-y divide-border/60 p-2 sm:p-3 space-y-3 flex-1 min-h-[160px]">

                    @if (! $hasSearched)
                        <!-- Initial Prompt State -->
                        <div class="py-10 text-center px-4">
                            <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-primary-50 text-primary flex items-center justify-center">
                                <x-icon name="search" class="w-6 h-6" />
                            </div>
                            <p class="text-sm font-semibold text-text-secondary">{{ __('search.type_to_search') }}</p>
                            <p class="text-xs text-text-muted mt-1 max-w-sm mx-auto">
                                {{ __('search.search_placeholder') }}
                            </p>
                        </div>

                    @elseif ($hasSearched && $totalResults === 0 && ! $searchFailed)
                        <!-- No Results State -->
                        <div class="py-10 text-center px-4" wire:loading.remove wire:target="query">
                            <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-surface-soft text-text-muted flex items-center justify-center">
                                <x-icon name="close" class="w-6 h-6" />
                            </div>
                            <p class="text-sm font-semibold text-text-primary">{{ __('search.no_results') }}</p>
                            <p class="text-xs text-text-muted mt-1">{{ __('search.no_results_desc') }}</p>
                        </div>

                    @else
                        <!-- Results by Category -->
                        @php $itemGlobalIndex = 0; @endphp

                        @foreach ($results as $categoryKey => $items)
                            @if (! empty($items))
                                <div class="pt-1">
                                    <!-- Category Header -->
                                    <div class="px-3 py-1.5 text-[11px] font-bold tracking-wider text-text-muted uppercase flex items-center justify-between">
                                        <span class="flex items-center gap-1.5">
                                            @if ($categoryKey === 'customer')
                                                <x-icon name="users" class="w-3.5 h-3.5 text-blue-600" />
                                            @elseif ($categoryKey === 'vendor')
                                                <x-icon name="truck" class="w-3.5 h-3.5 text-indigo-600" />
                                            @elseif ($categoryKey === 'product')
                                                <x-icon name="box" class="w-3.5 h-3.5 text-emerald-600" />
                                            @elseif ($categoryKey === 'invoice')
                                                <x-icon name="receipt" class="w-3.5 h-3.5 text-amber-600" />
                                            @elseif ($categoryKey === 'quotation')
                                                <x-icon name="quote" class="w-3.5 h-3.5 text-purple-600" />
                                            @elseif ($categoryKey === 'purchase')
                                                <x-icon name="cart" class="w-3.5 h-3.5 text-teal-600" />
                                            @elseif ($categoryKey === 'check')
                                                <x-icon name="check" class="w-3.5 h-3.5 text-sky-600" />
                                            @endif
                                            <span>{{ __('search.categories.'.$categoryKey) ?? $categoryKey }}</span>
                                        </span>
                                        <span class="text-[10px] font-medium text-text-muted">{{ count($items) }}</span>
                                    </div>

                                    <!-- Items in Category -->
                                    <div class="space-y-1 mt-1">
                                        @foreach ($items as $item)
                                            @php
                                                $currentIndex = $itemGlobalIndex++;
                                                $elementId = 'search-item-'.$currentIndex;
                                            @endphp
                                            <a href="{{ $item['url'] }}"
                                               id="{{ $elementId }}"
                                               role="option"
                                               data-index="{{ $currentIndex }}"
                                               data-url="{{ $item['url'] }}"
                                               :aria-selected="activeIndex === {{ $currentIndex }}"
                                               @mouseenter="activeIndex = {{ $currentIndex }}"
                                               class="group flex items-center justify-between px-3 py-2 rounded-control text-sm transition-colors cursor-pointer select-none"
                                               :class="activeIndex === {{ $currentIndex }} ? 'bg-primary-50 text-primary' : 'text-text-primary hover:bg-surface-soft'">

                                                <div class="flex items-center gap-2.5 min-w-0 flex-1">
                                                    <div class="min-w-0 flex-1">
                                                        <div class="font-semibold truncate text-xs sm:text-sm"
                                                             :class="activeIndex === {{ $currentIndex }} ? 'text-primary' : 'text-text-primary'">
                                                            {{ $item['title'] }}
                                                        </div>
                                                        @if (! empty($item['subtitle']))
                                                            <div class="text-[11px] text-text-muted truncate mt-0.5">
                                                                {{ $item['subtitle'] }}
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>

                                                <div class="flex items-center gap-2 shrink-0 {{ $isRtl ? 'mr-3' : 'ml-3' }}">
                                                    @if (! empty($item['status']))
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium uppercase
                                                            @if (in_array($item['status'], ['active', 'posted', 'cleared', 'accepted'], true))
                                                                bg-emerald-50 text-emerald-700 border border-emerald-200
                                                            @elseif (in_array($item['status'], ['draft', 'received', 'deposited', 'issued', 'sent'], true))
                                                                bg-blue-50 text-blue-700 border border-blue-200
                                                            @elseif (in_array($item['status'], ['inactive', 'void', 'cancelled', 'returned', 'rejected', 'expired'], true))
                                                                bg-rose-50 text-rose-700 border border-rose-200
                                                            @else
                                                                bg-slate-100 text-slate-700 border border-slate-200
                                                            @endif">
                                                            {{ __('search.statuses.'.$item['status']) ?? $item['status'] }}
                                                        </span>
                                                    @endif
                                                    <x-icon name="chevron" class="w-3.5 h-3.5 text-text-muted group-hover:text-primary transition-transform {{ $isRtl ? 'rotate-180' : '' }}" />
                                                </div>
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    @endif
                </div>

                <!-- Footer Key Hints -->
                <div class="px-4 py-2 border-t border-border bg-surface-soft/60 flex items-center justify-between text-[11px] text-text-muted">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center gap-1">
                            <kbd class="px-1.5 py-0.5 text-[10px] bg-white border border-border rounded-xs shadow-2xs">↑</kbd>
                            <kbd class="px-1.5 py-0.5 text-[10px] bg-white border border-border rounded-xs shadow-2xs">↓</kbd>
                            <span>{{ __('search.hints.navigate') }}</span>
                        </span>
                        <span class="inline-flex items-center gap-1">
                            <kbd class="px-1.5 py-0.5 text-[10px] bg-white border border-border rounded-xs shadow-2xs">↵</kbd>
                            <span>{{ __('search.hints.select') }}</span>
                        </span>
                    </div>
                    <div>
                        <span class="inline-flex items-center gap-1">
                            <kbd class="px-1.5 py-0.5 text-[10px] bg-white border border-border rounded-xs shadow-2xs">Esc</kbd>
                            <span>{{ __('search.hints.close') }}</span>
                        </span>
                    </div>
                </div>

            </div>
        </dialog>
    </template>
</div>
