@props([
    'prefix' => 'invoice',
    'context' => 'sales',
    'currencyCode' => 'ILS',
    'searchResults' => [],
])

<div x-data="{
    open: false,
    activeIndex: 0,
    init() {
        this.$watch('$wire.searchResults', (val) => {
            if (val && val.length > 0) {
                this.open = true;
                this.activeIndex = 0;
            }
        });
    },
    selectNext() {
        const count = ($wire.searchResults || []).length;
        if (count > 0) {
            this.activeIndex = (this.activeIndex + 1) % count;
            this.scrollToActive();
        }
    },
    selectPrev() {
        const count = ($wire.searchResults || []).length;
        if (count > 0) {
            this.activeIndex = (this.activeIndex - 1 + count) % count;
            this.scrollToActive();
        }
    },
    selectCurrent() {
        const results = $wire.searchResults || [];
        if (results.length > 0 && results[this.activeIndex]) {
            const item = results[this.activeIndex];
            $wire.addProduct(item.id, item.default_unit_id);
            this.open = false;
        } else {
            $wire.addSelectedProduct();
            this.open = false;
        }
    },
    scrollToActive() {
        this.$nextTick(() => {
            const el = this.$refs.resultsList?.querySelector(`[data-index='${this.activeIndex}']`);
            if (el) {
                el.scrollIntoView({ block: 'nearest' });
            }
        });
    }
}" @click.outside="open = false" @product-added.window="open = false" class="relative">
    <label for="{{ $prefix }}-product-search" class="block text-sm font-bold text-text-primary mb-1">
        {{ $context === 'purchasing' ? __('purchasing.search_products') : __('sales.search_products') }}
    </label>

    <div class="flex gap-2 items-center">
        <div class="relative flex min-w-0 flex-1 items-center rounded-control border border-border bg-canvas focus-within:border-primary focus-within:ring-1 focus-within:ring-primary">
            <input id="{{ $prefix }}-product-search"
                   type="search"
                   maxlength="160"
                   autocomplete="off"
                   wire:model.live.debounce.250ms="productSearch"
                   x-on:focus="if (($wire.searchResults || []).length > 0) open = true"
                   x-on:keydown.down.prevent="selectNext()"
                   x-on:keydown.up.prevent="selectPrev()"
                   x-on:keydown.enter.prevent="selectCurrent()"
                   x-on:keydown.escape="open = false"
                   placeholder="{{ $context === 'purchasing' ? __('purchasing.search_products') : __('sales.search_products_hint') }}"
                   class="min-w-0 w-full h-11 px-3 rounded-control border-0 bg-transparent text-sm text-text-primary focus:ring-0 outline-hidden"
                   :disabled="documentBusy || saving" />

            <button type="button"
                    x-show="$wire.productSearch && $wire.productSearch.length > 0"
                    x-on:click="$wire.set('productSearch', ''); open = false;"
                    class="p-1 text-text-muted hover:text-text-primary focus:outline-none cursor-pointer"
                    aria-label="{{ __('common.clear') ?? 'Clear' }}">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

            <x-camera-scanner :compact="true"
                              button-class="min-h-11 min-w-11 inline-flex items-center justify-center rounded-control text-primary hover:bg-surface-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary cursor-pointer" />
        </div>

        @if (auth()->user()->hasPermissionTo('inventory.product.manage'))
            <button type="button"
                    wire:click="openQuickProductModal"
                    aria-haspopup="dialog"
                    class="shrink-0 min-h-11 px-3 py-2 rounded-control border border-border text-sm font-bold text-primary hover:bg-surface-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary cursor-pointer transition-colors"
                    wire:loading.attr="disabled"
                    wire:target="openQuickProductModal"
                    :disabled="documentBusy || saving">
                + {{ __('quick_product.create') }}
            </button>
        @endif
    </div>

    <div wire:loading wire:target="productSearch" role="status" class="mt-1 text-xs text-text-secondary flex items-center gap-1.5">
        <svg class="animate-spin h-3.5 w-3.5 text-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
        </svg>
        <span>{{ $context === 'purchasing' ? __('purchasing.products_searching') : __('sales.products_searching') }}</span>
    </div>

    @error('productSearch')
        <p role="alert" class="text-danger text-xs mt-1">{{ $message }}</p>
    @enderror

    <!-- Bounded Search Results Dropdown -->
    <div x-show="open && (($wire.searchResults && $wire.searchResults.length > 0) || ($wire.productSearch && $wire.productSearch.trim().length > 0))"
         x-cloak
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-75"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="absolute z-40 left-0 right-0 mt-1.5 bg-surface rounded-card border border-border shadow-lg overflow-hidden max-h-80 flex flex-col">

        @if(!empty($searchResults))
            <ul x-ref="resultsList" role="listbox" class="overflow-y-auto divide-y divide-border p-0 m-0">
                @foreach ($searchResults as $idx => $candidate)
                    <li role="option"
                        aria-selected="{{ $idx === 0 ? 'true' : 'false' }}"
                        data-index="{{ $idx }}">
                        <button type="button"
                                wire:click="addProduct({{ $candidate['id'] }}, {{ $candidate['default_unit_id'] ?? 'null' }})"
                                x-on:click="open = false"
                                x-on:mouseenter="activeIndex = {{ $idx }}"
                                :class="{'bg-primary/10 border-s-4 border-primary': activeIndex === {{ $idx }}}"
                                class="w-full text-start p-2.5 min-h-[44px] hover:bg-surface-soft flex items-center justify-between gap-3 cursor-pointer transition-colors outline-hidden">
                            <div class="flex-1 min-w-0 space-y-0.5">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-bold text-text-primary leading-tight">
                                        {{ $candidate['name_ar'] ?: $candidate['name_en'] }}
                                    </span>
                                    @if(!empty($candidate['sku']))
                                        <span class="inline-flex items-center px-1.5 py-0.2 rounded text-xs font-mono font-medium bg-canvas border border-border text-text-secondary dir-ltr">
                                            <bdi>{{ $candidate['sku'] }}</bdi>
                                        </span>
                                    @endif
                                    @if(!empty($candidate['matched_barcode']))
                                        <span class="inline-flex items-center px-1.5 py-0.2 rounded text-xs font-mono font-medium bg-primary/15 text-primary dir-ltr">
                                            <bdi>{{ $candidate['matched_barcode'] }}</bdi>
                                        </span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 text-xs text-text-secondary">
                                    @if(!empty($candidate['name_en']) && !empty($candidate['name_ar']) && $candidate['name_en'] !== $candidate['name_ar'])
                                        <span class="dir-ltr text-text-muted">{{ $candidate['name_en'] }}</span>
                                        <span>•</span>
                                    @endif
                                    <span>{{ $candidate['base_unit_name'] }}</span>
                                </div>
                            </div>

                            <div class="shrink-0 text-end space-y-0.5">
                                @if($context === 'sales' && $candidate['price'] !== null)
                                    <div class="text-sm font-bold text-primary font-mono tabular-nums dir-ltr">
                                        <bdi>{{ $candidate['price'] }}</bdi> {{ $currencyCode }}
                                    </div>
                                    @if($candidate['stock_status'] === 'out_of_stock')
                                        <span class="inline-block text-xs font-semibold text-danger bg-danger/10 px-1.5 py-0.5 rounded">
                                            {{ __('inventory.out_of_stock') }}
                                        </span>
                                    @elseif($candidate['stock_status'] === 'low_stock')
                                        <span class="inline-block text-xs font-semibold text-warning bg-warning/10 px-1.5 py-0.5 rounded">
                                            {{ __('inventory.low_stock') ?? 'Low Stock' }}
                                        </span>
                                    @endif
                                @elseif($context === 'purchasing' && $candidate['cost'] !== null)
                                    <div class="text-xs text-text-secondary">
                                        {{ __('purchasing.unit_cost') }}
                                    </div>
                                    <div class="text-sm font-bold text-text-primary font-mono tabular-nums dir-ltr">
                                        <bdi>{{ $candidate['cost'] }}</bdi> {{ $currencyCode }}
                                    </div>
                                @endif
                            </div>
                        </button>
                    </li>
                @endforeach
            </ul>
        @elseif(!empty(trim($productSearch)))
            <div role="status" class="p-4 text-center text-sm text-text-secondary">
                {{ $context === 'purchasing' ? __('purchasing.no_matching_products') : __('sales.no_matching_products') }}
            </div>
        @endif
    </div>
</div>
