<div class="space-y-6">
    <x-page-heading :title="__('inventory.products')" icon="box">
        @if ($canManageProduct)
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('brands.index') }}"
                   class="touch-action gap-1.5 border border-border bg-surface hover:bg-surface-soft text-text-secondary hover:text-text-primary text-sm font-semibold transition-colors">
                    <x-icon name="box" class="w-4 h-4" />
                    <span>{{ __('inventory.brands') }}</span>
                </a>
                <a href="{{ route('products.create') }}"
                   class="touch-action bg-primary hover:bg-primary-hover text-white text-sm font-bold shadow-button transition-colors">
                    <x-icon name="plus" class="w-4 h-4" />
                    <span>{{ __('inventory.add_product') }}</span>
                </a>
            </div>
        @endif
    </x-page-heading>

    <!-- Filters & Search Toolbar -->
    <x-refinement-filters :active-count="(int) filled($categoryFilter) + (int) filled($brandFilter) + (int) ($statusFilter !== 'all') + (int) $lowStockOnly + (int) $expiryOnly + (int) filled($cameraBarcode)">
        <x-slot:search>
            <div class="flex min-w-0 items-center rounded-control border border-border bg-surface focus-within:border-primary focus-within:ring-1 focus-within:ring-primary">
                <input id="products-search" aria-label="{{ __('inventory.search_products_placeholder') }}" type="text"
                       wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('inventory.search_products_placeholder') }}"
                       class="min-h-11 w-full min-w-0 rounded-control border-0 bg-transparent px-3 py-2 text-sm text-text-primary focus:ring-0 outline-hidden" />
                <x-camera-scanner :compact="true" button-class="min-h-11 min-w-11 inline-flex items-center justify-center rounded-control text-primary hover:bg-surface-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" />
            </div>
        </x-slot:search>
        <x-slot:statuses>
        <button type="button" wire:click="$set('statusFilter', 'all')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'all' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'all' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('inventory.filter_all_status') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'active')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'active' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'active' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('inventory.filter_active_only') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'inactive')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'inactive' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'inactive' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('inventory.filter_inactive_only') }}</button>
        </x-slot:statuses>
        <select id="products-category-filter" wire:model.live="categoryFilter"
                        aria-label="{{ __('inventory.category') }}"
                        class="min-h-11 w-full h-11 px-3 rounded-control border border-border bg-surface text-sm text-text-primary focus:outline-none focus:border-primary focus:ring-2 focus:ring-focus-ring">
                    <option value="">{{ __('inventory.filter_category') }}</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name_ar }}</option>
                    @endforeach
                </select>
        <select id="products-brand-filter" wire:model.live="brandFilter"
                        aria-label="{{ __('inventory.brand') }}"
                        class="min-h-11 w-full h-11 px-3 rounded-control border border-border bg-surface text-sm text-text-primary focus:outline-none focus:border-primary focus:ring-2 focus:ring-focus-ring">
                    <option value="">{{ __('inventory.filter_brand') }}</option>
                    @foreach ($brands as $b)
                        <option value="{{ $b->id }}">
                            {{ $b->name_ar }}@if(! $b->active) ({{ __('inventory.inactive') }})@endif
                        </option>
                    @endforeach
                </select>
        <select id="products-status-filter" wire:model.live="statusFilter"
                        aria-label="{{ __('inventory.filter_status') }}"
                        class="min-h-11 w-full h-11 px-3 rounded-control border border-border bg-surface text-sm text-text-primary focus:outline-none focus:border-primary focus:ring-2 focus:ring-focus-ring">
                    <option value="all">{{ __('inventory.filter_all_status') }}</option>
                    <option value="active">{{ __('inventory.filter_active_only') }}</option>
                    <option value="inactive">{{ __('inventory.filter_inactive_only') }}</option>
                </select>
        <label for="products-low-stock-only" class="min-h-11 flex items-center gap-1.5 cursor-pointer text-sm text-text-secondary select-none">
                    <input id="products-low-stock-only" type="checkbox" wire:model.live="lowStockOnly" class="rounded border-border text-primary focus:ring-primary w-4 h-4" />
                    <span>{{ __('inventory.filter_low_stock') }}</span>
                </label>
        <label for="products-expiry-only" class="min-h-11 flex items-center gap-1.5 cursor-pointer text-sm text-text-secondary select-none">
                    <input id="products-expiry-only" type="checkbox" wire:model.live="expiryOnly" class="rounded border-border text-primary focus:ring-primary w-4 h-4" />
                    <span>{{ __('inventory.filter_expiry') }}</span>
                </label>
    </x-refinement-filters>
@if (! empty($cameraBarcode))
            <div class="mt-3 pt-3 border-t border-border flex items-center justify-between gap-2">
                <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-primary/10 text-primary text-xs font-semibold rounded-control">
                    <span>{{ __('camera.filtered_by_barcode') }} <strong dir="ltr" class="font-mono">{{ $cameraBarcode }}</strong></span>
                </div>
                <button wire:loading.attr="disabled" type="button" wire:click="clearCameraBarcode" class="touch-action min-h-[44px] min-w-[44px] text-sm font-semibold text-danger hover:underline">
                    {{ __('camera.clear_filter') }}
                </button>
            </div>
        @endif

    <!-- Products Table / Cards -->
    <x-card padding="p-0">
        <!-- Desktop Table View -->
        <div class="hidden lg:block overflow-x-auto">
            <table class="data-table table-products w-full text-xs text-right rtl:text-right ltr:text-left">
                <thead class="bg-surface-soft border-b border-border text-text-secondary font-bold select-none">
                    <tr>
                        <th class="p-3.5">{{ __('inventory.product') }}</th>
                        <th class="p-3.5">{{ __('inventory.category') }}</th>
                        <th class="p-3.5">{{ __('inventory.base_unit') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.total_stock') }}</th>
                        @if ($canViewCost)
                            <th class="p-3.5 text-center"><abbr title="{{ __('inventory.moving_average_cost') }}" class="no-underline">{{ __('inventory.average_cost_short') }}</abbr></th>
                            <th class="p-3.5 text-center">{{ __('inventory.stock_value_short') }}</th>
                        @endif
                        <th class="p-3.5 text-center">{{ __('inventory.filter_status') }}</th>
                        <th class="p-3.5 text-center w-24"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($products as $product)
                        <tr class="hover:bg-surface-soft/60 transition-colors">
                            <!-- Product Name & Thumbnail -->
                            <td class="table-primary p-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-control border border-border bg-surface-soft grid place-items-center shrink-0 overflow-hidden">
                                        @if ($product->primaryImage)
                                            <img loading="lazy" decoding="async" width="40" height="40" src="{{ asset('storage/' . $product->primaryImage->thumbnail_path) }}"
                                                 alt="{{ $product->name() }}"
                                                 class="w-full h-full object-cover" />
                                        @else
                                            <x-icon name="box" class="w-5 h-5 text-text-muted" />
                                        @endif
                                    </div>
                                    <div class="min-w-0 flex items-center gap-2">
                                        <a href="{{ route('products.show', $product->public_id) }}"
                                           title="{{ $product->name() }}" class="touch-action min-w-0 font-bold text-text-primary hover:text-primary transition-colors truncate">
                                            {{ $product->name() }}
                                        </a>
                                        <div class="table-cell-secondary text-xs text-text-muted">
                                            @if ($product->sku)
                                                <span class="font-mono dir-ltr">{{ $product->sku }}</span>
                                            @endif
                                            @if ($product->name_ar && $product->name_en)
                                                <span class="truncate">/ {{ app()->getLocale() === 'en' ? $product->name_ar : $product->name_en }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Category -->
                            <td class="p-3.5 text-text-secondary">
                                {{ $product->category?->name() ?? '—' }}
                            </td>

                            <!-- Base Unit -->
                            <td class="p-3.5 text-text-secondary">
                                {{ $product->baseUnit?->name() ?? '—' }}
                            </td>

                            <!-- Stock -->
                            <td class="p-3.5 text-center">
                                @if ($product->track_stock)
                                    <span class="font-mono font-bold text-sm tabular-nums dir-ltr {{ $product->minimum_stock_base !== null && \Brick\Math\BigDecimal::of((string) $product->stock_quantity)->isLessThanOrEqualTo(\Brick\Math\BigDecimal::of((string) $product->minimum_stock_base)) ? 'text-danger' : 'text-text-primary' }}">
                                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($product->stock_quantity) }}
                                    </span>
                                @else
                                    <span class="text-text-muted">—</span>
                                @endif
                            </td>

                            <!-- Cost & Valuation (Gated server-side) -->
                            @if ($canViewCost)
                                <td class="p-3.5 text-center font-mono tabular-nums dir-ltr text-text-secondary">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($product->costState?->average_cost_base ?? '0.000000') }}
                                    <span class="text-xs text-text-muted">{{ $company->base_currency_code }}</span>
                                </td>
                                <td class="p-3.5 text-center font-mono font-bold tabular-nums dir-ltr text-text-primary">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($product->costState?->inventory_value_base ?? '0.000000') }}
                                    <span class="text-xs text-text-muted">{{ $company->base_currency_code }}</span>
                                </td>
                            @endif

                            <!-- Status & Badges -->
                            <td class="p-3.5 text-center">
                                <div class="table-status">
                                    @if ($product->active)
                                        <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                                    @else
                                        <x-badge>{{ __('inventory.inactive') }}</x-badge>
                                    @endif
                                    @if ($product->track_expiry)
                                        <x-badge variant="muted">{{ __('inventory.expiry_date') }}</x-badge>
                                    @endif
                                </div>
                            </td>

                            <!-- Actions -->
                            <td class="table-actions p-3.5 text-center">
                                <div class="flex flex-nowrap items-center justify-center gap-2">
                                    <a href="{{ route('products.show', $product->public_id) }}"
                                       title="{{ __('inventory.product_details') }}"
                                       class="touch-action p-1.5 text-text-secondary hover:bg-surface-soft hover:text-primary transition-colors">
                                        <x-icon name="dashboard" class="w-4 h-4" /><span>{{ __('refinement_lists.open') }}</span>
                                    </a>
                                    @if ($canManageProduct)
                                        <a href="{{ route('products.edit', $product->public_id) }}"
                                           title="{{ __('inventory.edit_product') }}"
                                           class="touch-action p-1.5 text-text-secondary hover:bg-surface-soft hover:text-primary transition-colors">
                                            <x-icon name="settings" class="w-4 h-4" /><span>{{ __('inventory.edit') }}</span>
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canViewCost ? 7 : 5 }}" class="p-8 text-center text-text-muted">
                                <x-icon name="box" class="w-10 h-10 mx-auto mb-2 text-text-muted/40" />
                                <p>{{ __('inventory.no_products_found') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Mobile Cards View -->
        <div class="lg:hidden divide-y divide-border">
            @forelse ($products as $product)
                <div class="p-4 space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <div class="w-12 h-12 rounded-control border border-border bg-surface-soft grid place-items-center shrink-0 overflow-hidden">
                                @if ($product->primaryImage)
                                    <img loading="lazy" decoding="async" width="40" height="40" src="{{ asset('storage/' . $product->primaryImage->thumbnail_path) }}"
                                         alt="{{ $product->name() }}"
                                         class="w-full h-full object-cover" />
                                @else
                                    <x-icon name="box" class="w-6 h-6 text-text-muted" />
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('products.show', $product->public_id) }}"
                                   title="{{ $product->name() }}" class="touch-action min-w-0 truncate font-bold text-text-primary text-sm hover:text-primary transition-colors block">
                                    {{ $product->name() }}
                                </a>
                                @if ($product->sku)
                                    <span class="block truncate text-xs font-mono text-text-muted" title="{{ $product->sku }}"><bdi dir="ltr">{{ $product->sku }}</bdi></span>
                                @endif
                                @if ($product->name_ar && $product->name_en)
                                    <span class="block text-xs text-text-muted truncate">/ {{ app()->getLocale() === 'en' ? $product->name_ar : $product->name_en }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            @if ($product->active)
                                <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                            @else
                                <x-badge>{{ __('inventory.inactive') }}</x-badge>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-xs pt-1 border-t border-border/40">
                        <div>
                            <span class="text-text-muted">{{ __('inventory.category') }}:</span>
                            <span class="font-semibold text-text-secondary">{{ $product->category?->name() ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="text-text-muted">{{ __('inventory.total_stock') }}:</span>
                            <span class="font-mono font-bold tabular-nums dir-ltr text-text-primary">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($product->stock_quantity) }}
                                {{ $product->baseUnit?->name() }}
                            </span>
                        </div>
                        @if ($canViewCost)
                            <div>
                                <span class="text-text-muted">{{ __('inventory.moving_average_cost') }}:</span>
                                <span class="font-mono tabular-nums dir-ltr text-text-secondary">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($product->costState?->average_cost_base ?? '0.000000') }}
                                </span>
                            </div>
                            <div>
                                <span class="text-text-muted">{{ __('inventory.inventory_value') }}:</span>
                                <span class="font-mono font-bold tabular-nums dir-ltr text-text-primary">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($product->costState?->inventory_value_base ?? '0.000000') }}
                                </span>
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-2 pt-2 border-t border-border/40">
                        <a href="{{ route('products.show', $product->public_id) }}"
                           class="touch-action bg-surface-soft hover:bg-border/60 text-xs font-semibold text-text-secondary transition-colors">
                            {{ __('inventory.product_details') }}
                        </a>
                        @can('inventory.barcode_labels.print')
                            <a href="{{ route('labels.index') }}" class="touch-action bg-surface text-primary text-sm font-semibold"><x-icon name="barcode" class="h-4 w-4" />{{ __('refinement_lists.barcode_labels') }}</a>
                        @endcan
                        @if ($canManageProduct)
                            <a href="{{ route('products.edit', $product->public_id) }}"
                               class="touch-action bg-primary text-white text-xs font-semibold hover:bg-primary-hover transition-colors">
                                {{ __('inventory.edit_product') }}
                            </a>
                        @endif
                    </div>
                </div>
            @empty
                <div class="p-8 text-center text-text-muted">
                    <x-icon name="box" class="w-10 h-10 mx-auto mb-2 text-text-muted/40" />
                    <p>{{ __('inventory.no_products_found') }}</p>
                </div>
            @endforelse
        </div>

        @if ($products->count() > 0 || $products->currentPage() > 1)
            <div class="p-4 border-t border-border">
                <x-table-pagination :paginator="$products" />
            </div>
        @endif
    </x-card>
</div>
