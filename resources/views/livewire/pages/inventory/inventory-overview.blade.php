<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-text-primary flex items-center gap-2.5">
                <x-icon name="box" class="w-6 h-6 text-primary" />
                <span>{{ __('inventory.inventory_overview') }}</span>
            </h1>
            <p class="text-xs text-text-secondary mt-1">{{ __('inventory.inventory_overview_subtitle') }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($canAdjust)
                <a href="{{ route('inventory.opening-stock.create') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-border hover:bg-surface-soft text-text-primary text-xs font-bold rounded-control transition-colors">
                    <x-icon name="plus" class="w-4 h-4 text-primary" />
                    <span>{{ __('inventory.opening_stock') }}</span>
                </a>
                <a href="{{ route('inventory.adjustments.create') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-border hover:bg-surface-soft text-text-primary text-xs font-bold rounded-control transition-colors">
                    <x-icon name="plus" class="w-4 h-4 text-primary" />
                    <span>{{ __('inventory.adjust_stock') }}</span>
                </a>
            @endif
            @if ($canTransfer)
                <a href="{{ route('inventory.transfers.create') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-border hover:bg-surface-soft text-text-primary text-xs font-bold rounded-control transition-colors">
                    <x-icon name="truck" class="w-4 h-4 text-primary" />
                    <span>{{ __('inventory.transfer_stock') }}</span>
                </a>
            @endif
            <a href="{{ route('inventory.movements') }}"
               class="inline-flex items-center gap-1.5 px-3 py-2 bg-primary hover:bg-primary-hover text-white text-xs font-bold rounded-control shadow-button transition-colors">
                <x-icon name="receipt" class="w-4 h-4" />
                <span>{{ __('inventory.stock_movements') }}</span>
            </a>
        </div>
    </div>

    <!-- KPIs Row -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Products Count -->
        <x-card padding="p-4" class="space-y-1">
            <div class="text-[11px] font-bold text-text-muted uppercase tracking-wider">{{ __('inventory.active_products_metric') }}</div>
            <div class="text-2xl font-extrabold font-mono tabular-nums text-text-primary">
                {{ $productsCount }}
            </div>
            <a href="{{ route('products.index') }}" class="text-[11px] text-primary hover:underline font-bold block pt-1">
                {{ __('inventory.view_catalog_link') }} &larr;
            </a>
        </x-card>

        <!-- Warehouses Count -->
        <x-card padding="p-4" class="space-y-1">
            <div class="text-[11px] font-bold text-text-muted uppercase tracking-wider">{{ __('inventory.warehouses') }}</div>
            <div class="text-2xl font-extrabold font-mono tabular-nums text-text-primary">
                {{ $warehousesCount }}
            </div>
            <a href="{{ route('warehouses.index') }}" class="text-[11px] text-primary hover:underline font-bold block pt-1">
                {{ __('inventory.manage_warehouses_link') }} &larr;
            </a>
        </x-card>

        <!-- Expiring Soon Lots -->
        <x-card padding="p-4" class="space-y-1">
            <div class="text-[11px] font-bold text-text-muted uppercase tracking-wider">{{ __('inventory.expiring_lots_metric') }}</div>
            <div class="text-2xl font-extrabold font-mono tabular-nums {{ $expiringLotsCount > 0 ? 'text-danger' : 'text-text-primary' }}">
                {{ $expiringLotsCount }}
            </div>
            <a href="{{ route('inventory.expiry') }}" class="text-[11px] text-primary hover:underline font-bold block pt-1">
                {{ __('inventory.expiry_center_link') }} &larr;
            </a>
        </x-card>

        <!-- Valuation KPI (Gated) -->
        @if ($canViewCost)
            <x-card padding="p-4" class="space-y-1">
                <div class="text-[11px] font-bold text-text-muted uppercase tracking-wider">{{ __('inventory.inventory_value') }}</div>
                <div class="text-2xl font-extrabold font-mono tabular-nums dir-ltr text-primary">
                    {{ $totalValuation }}
                    <span class="text-xs font-normal text-text-secondary font-arabic">{{ $company->base_currency_code }}</span>
                </div>
                <div class="text-[11px] text-text-muted pt-1">{{ __('inventory.total_inventory_valuation') }}</div>
            </x-card>
        @else
            <x-card padding="p-4" class="space-y-1">
                <div class="text-[11px] font-bold text-text-muted uppercase tracking-wider">{{ __('inventory.low_stock_metric') }}</div>
                <div class="text-2xl font-extrabold font-mono tabular-nums {{ $lowStockProducts->count() > 0 ? 'text-warning' : 'text-text-primary' }}">
                    {{ $lowStockProducts->count() }}
                </div>
                <div class="text-[11px] text-text-muted pt-1">{{ __('inventory.exceeded_reorder_point') }}</div>
            </x-card>
        @endif
    </div>

    <!-- Low Stock Alerts & Recent Movements Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Low Stock Items Panel -->
        <x-card padding="p-0" class="overflow-hidden">
            <div class="p-4 border-b border-border bg-surface-soft flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <x-icon name="alert" class="w-4 h-4 text-warning" />
                    <h2 class="text-xs font-bold text-text-primary uppercase tracking-wider">{{ __('inventory.low_stock_alerts') }}</h2>
                </div>
                <span class="text-[11px] font-mono text-text-muted">{{ $lowStockProducts->count() }} {{ __('inventory.items_count_suffix') }}</span>
            </div>

            <div class="divide-y divide-border">
                @forelse ($lowStockProducts as $prod)
                    <div class="p-3.5 flex items-center justify-between text-xs hover:bg-surface-soft/50 transition-colors">
                        <div>
                            <a href="{{ route('products.show', $prod->public_id) }}" class="font-bold text-text-primary hover:text-primary">
                                {{ $prod->name_ar }}
                            </a>
                            <div class="text-[11px] text-text-muted font-mono dir-ltr mt-0.5">
                                {{ $prod->sku ?? '—' }}
                            </div>
                        </div>
                        <div class="text-left rtl:text-left ltr:text-right">
                            <div class="font-mono font-bold text-danger text-sm tabular-nums dir-ltr">
                                {{ $prod->stock_quantity }} / {{ $prod->minimum_stock }}
                            </div>
                            <div class="text-[10px] text-text-muted">{{ $prod->baseUnit?->name_ar }}</div>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-text-muted text-xs">
                        {{ __('inventory.no_low_stock_items') }}
                    </div>
                @endforelse
            </div>
        </x-card>

        <!-- Recent Stock Movements Panel -->
        <x-card padding="p-0" class="overflow-hidden">
            <div class="p-4 border-b border-border bg-surface-soft flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <x-icon name="receipt" class="w-4 h-4 text-primary" />
                    <h2 class="text-xs font-bold text-text-primary uppercase tracking-wider">{{ __('inventory.latest_stock_movements') }}</h2>
                </div>
                <a href="{{ route('inventory.movements') }}" class="text-[11px] text-primary hover:underline font-bold">
                    {{ __('inventory.view_full_ledger') }} &larr;
                </a>
            </div>

            <div class="divide-y divide-border">
                @forelse ($recentMovements as $mov)
                    <div class="p-3.5 flex items-center justify-between text-xs hover:bg-surface-soft/50 transition-colors">
                        <div class="space-y-0.5">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-text-primary">{{ $mov->product?->name_ar }}</span>
                                <x-badge variant="{{ $mov->isInbound() ? 'success' : 'alert' }}">
                                    {{ __('inventory.type_' . $mov->movement_type) }}
                                </x-badge>
                            </div>
                            <div class="text-[11px] text-text-muted flex items-center gap-2">
                                <span>{{ $mov->warehouse?->name_ar }}</span>
                                <span>•</span>
                                <span class="font-mono dir-ltr">{{ $mov->movement_date }}</span>
                            </div>
                        </div>
                        <div class="text-left rtl:text-left ltr:text-right">
                            <span class="font-mono font-bold text-sm tabular-nums dir-ltr {{ $mov->isInbound() ? 'text-success' : 'text-danger' }}">
                                {{ $mov->quantity_delta_base }}
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-text-muted text-xs">
                        {{ __('inventory.no_movements_yet') }}
                    </div>
                @endforelse
            </div>
        </x-card>
    </div>
</div>
