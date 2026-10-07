<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('products.index') }}" class="text-xs text-text-muted hover:text-primary transition-colors">&larr; {{ __('inventory.products') }}</a>
                <span class="text-text-muted">/</span>
                <span class="text-xs text-text-secondary">{{ (app()->getLocale() === 'en' && $product->category?->name_en) ? $product->category->name_en : ($product->category?->name_ar ?? __('inventory.general')) }}</span>
            </div>
            <h1 class="text-xl font-extrabold text-text-primary flex items-center gap-3">
                <span>{{ (app()->getLocale() === 'en' && $product->name_en) ? $product->name_en : $product->name_ar }}</span>
                @if ($product->active)
                    <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                @else
                    <x-badge variant="muted">{{ __('inventory.inactive') }}</x-badge>
                @endif
                <x-badge variant="info">{{ __('inventory.type_' . $product->product_type) }}</x-badge>
            </h1>
            @if ($product->name_en || $product->sku)
                <p class="text-xs text-text-muted font-mono dir-ltr mt-1">
                    {{ $product->sku ? 'SKU: ' . $product->sku : '' }}
                    {{ $product->sku && $product->name_en ? ' | ' : '' }}
                    {{ $product->name_en ?? '' }}
                </p>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if ($canManageProduct)
                <a href="{{ route('products.edit', $product->public_id) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-border hover:bg-surface-soft text-text-primary text-xs font-bold rounded-control transition-colors">
                    <x-icon name="pencil" class="w-4 h-4 text-text-secondary" />
                    <span>{{ __('inventory.edit_product') }}</span>
                </a>
            @endif
        </div>
    </div>

    <!-- KPI Summary Row -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <!-- Stock KPI -->
        <x-card padding="p-4" class="space-y-1">
            <div class="text-[11px] font-bold text-text-muted uppercase tracking-wider">{{ __('inventory.total_stock') }}</div>
            <div class="text-xl font-extrabold font-mono tabular-nums dir-ltr text-text-primary">
                {{ $product->stock_quantity }}
                <span class="text-xs font-normal text-text-secondary font-arabic">{{ (app()->getLocale() === 'en' && $product->baseUnit?->name_en) ? $product->baseUnit->name_en : $product->baseUnit?->name_ar }}</span>
            </div>
        </x-card>

        <!-- Suggested Sale Price KPI -->
        <x-card padding="p-4" class="space-y-1">
            <div class="text-[11px] font-bold text-text-muted uppercase tracking-wider">{{ __('inventory.suggested_sale_price') }}</div>
            <div class="text-xl font-extrabold font-mono tabular-nums dir-ltr text-text-primary">
                {{ $product->default_sale_price_base ?? '—' }}
                <span class="text-xs font-normal text-text-secondary font-arabic">{{ $company->base_currency_code }}</span>
            </div>
        </x-card>

        @if ($canViewCost)
            <!-- Average Cost KPI -->
            <x-card padding="p-4" class="space-y-1">
                <div class="text-[11px] font-bold text-text-muted uppercase tracking-wider">{{ __('inventory.moving_average_cost') }}</div>
                <div class="text-xl font-extrabold font-mono tabular-nums dir-ltr text-primary">
                    {{ $costState?->average_cost_base ?? '0.000000' }}
                    <span class="text-xs font-normal text-text-secondary font-arabic">{{ $company->base_currency_code }}</span>
                </div>
            </x-card>

            <!-- Inventory Valuation KPI -->
            <x-card padding="p-4" class="space-y-1">
                <div class="text-[11px] font-bold text-text-muted uppercase tracking-wider">{{ __('inventory.inventory_value') }}</div>
                <div class="text-xl font-extrabold font-mono tabular-nums dir-ltr text-text-primary">
                    {{ $costState?->inventory_value_base ?? '0.000000' }}
                    <span class="text-xs font-normal text-text-secondary font-arabic">{{ $company->base_currency_code }}</span>
                </div>
            </x-card>
        @endif
    </div>

    <!-- Navigation Tabs -->
    <div class="border-b border-border flex flex-wrap items-center gap-6 text-xs font-bold select-none">
        <button type="button" wire:click="setTab('overview')"
                class="pb-3 border-b-2 transition-colors {{ $activeTab === 'overview' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
            {{ __('inventory.overview') }}
        </button>
        <button type="button" wire:click="setTab('warehouses')"
                class="pb-3 border-b-2 transition-colors {{ $activeTab === 'warehouses' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
            {{ __('inventory.stock_by_warehouse') }} ({{ $warehouseBalances->count() }})
        </button>
        @if ($product->track_expiry)
            <button type="button" wire:click="setTab('lots')"
                    class="pb-3 border-b-2 transition-colors {{ $activeTab === 'lots' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
                {{ __('inventory.lots_and_expiry') }} ({{ $lots->count() }})
            </button>
        @endif
        <button type="button" wire:click="setTab('movements')"
                class="pb-3 border-b-2 transition-colors {{ $activeTab === 'movements' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
            {{ __('inventory.stock_movements') }}
        </button>
        <button type="button" wire:click="setTab('units')"
                class="pb-3 border-b-2 transition-colors {{ $activeTab === 'units' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
            {{ __('inventory.units') }} ({{ $product->productUnits->count() }})
        </button>
        <button type="button" wire:click="setTab('barcodes')"
                class="pb-3 border-b-2 transition-colors {{ $activeTab === 'barcodes' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
            {{ __('inventory.barcodes') }} ({{ $product->barcodes->count() }})
        </button>
        <button type="button" wire:click="setTab('images')"
                class="pb-3 border-b-2 transition-colors {{ $activeTab === 'images' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
            {{ __('inventory.images') }} ({{ $product->images->count() }})
        </button>
        @if ($canViewPurchasingCost)
            <button type="button" wire:click="setTab('purchase_history')"
                    class="pb-3 border-b-2 transition-colors {{ $activeTab === 'purchase_history' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
                {{ __('purchasing.purchase_price_history') }}
            </button>
        @endif
    </div>

    <!-- Tab Content -->
    @if ($activeTab === 'overview')
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Details Panel -->
            <x-card padding="p-6" class="md:col-span-2 space-y-4">
                <h3 class="text-sm font-extrabold text-text-primary border-b border-border pb-2.5">{{ __('inventory.product_details') }}</h3>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div>
                        <dt class="text-text-muted">{{ __('inventory.name_ar') }}</dt>
                        <dd class="font-bold text-text-primary mt-0.5">{{ $product->name_ar }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">{{ __('inventory.name_en') }}</dt>
                        <dd class="font-bold text-text-primary mt-0.5">{{ $product->name_en ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">{{ __('inventory.category') }}</dt>
                        <dd class="font-bold text-text-primary mt-0.5">{{ (app()->getLocale() === 'en' && $product->category?->name_en) ? $product->category->name_en : ($product->category?->name_ar ?? '—') }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">{{ __('inventory.brand') }}</dt>
                        <dd class="font-bold text-text-primary mt-0.5">{{ (app()->getLocale() === 'en' && $product->brand?->name_en) ? $product->brand->name_en : ($product->brand?->name_ar ?? '—') }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">{{ __('inventory.base_unit') }}</dt>
                        <dd class="font-bold text-text-primary mt-0.5">{{ (app()->getLocale() === 'en' && $product->baseUnit?->name_en) ? $product->baseUnit->name_en : ($product->baseUnit?->name_ar ?? '—') }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">{{ __('inventory.minimum_stock') }}</dt>
                        <dd class="font-bold font-mono dir-ltr mt-0.5">{{ $product->minimum_stock_base ?? '0' }}</dd>
                    </div>
                </dl>

                @if ($product->description_ar || $product->description_en)
                    <div class="pt-3 border-t border-border/50 text-xs">
                        <span class="text-text-muted block mb-1">{{ __('inventory.description') }}:</span>
                        <p class="text-text-secondary leading-relaxed">{{ (app()->getLocale() === 'en' && $product->description_en) ? $product->description_en : ($product->description_ar ?? '') }}</p>
                    </div>
                @endif
            </x-card>

            <!-- Images & Media Preview Panel -->
            <x-card padding="p-6" class="space-y-4">
                <h3 class="text-sm font-extrabold text-text-primary border-b border-border pb-2.5">{{ __('inventory.images') }}</h3>
                @if ($product->images->isNotEmpty())
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ($product->images as $img)
                            <div class="rounded-control border border-border overflow-hidden bg-surface-soft aspect-square relative">
                                <img src="{{ asset('storage/' . $img->thumbnail_path) }}" alt="{{ $product->name_ar }}" class="w-full h-full object-cover" />
                                @if ($img->is_primary)
                                    <div class="absolute top-1 right-1">
                                        <x-badge variant="success">{{ __('inventory.primary') }}</x-badge>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="p-8 text-center text-text-muted">
                        <x-icon name="box" class="w-8 h-8 mx-auto mb-2 text-text-muted/40" />
                        <p class="text-xs">{{ __('inventory.no_images_for_product') }}</p>
                    </div>
                @endif
            </x-card>
        </div>
    @elseif ($activeTab === 'warehouses')
        <!-- Stock by Warehouse Table -->
        <x-card padding="p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-right rtl:text-right ltr:text-left">
                    <thead class="bg-surface-soft border-b border-border text-text-secondary font-bold">
                        <tr>
                            <th class="p-3.5">{{ __('inventory.warehouse') }}</th>
                            <th class="p-3.5 text-center">{{ __('inventory.current_stock') }}</th>
                            <th class="p-3.5 text-center">{{ __('inventory.unit') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($warehouseBalances as $bal)
                            <tr>
                                <td class="p-3.5 font-bold text-text-primary">
                                    {{ app()->getLocale() === 'en' ? ($bal->warehouse?->name_en ?: $bal->warehouse?->name_ar) : $bal->warehouse?->name_ar }}
                                    @if ($bal->warehouse?->is_default)
                                        <x-badge variant="info">{{ __('inventory.default') }}</x-badge>
                                    @endif
                                </td>
                                <td class="p-3.5 text-center font-mono font-bold text-sm tabular-nums dir-ltr text-text-primary">
                                    {{ $bal->quantity_base }}
                                </td>
                                <td class="p-3.5 text-center text-text-secondary">
                                    {{ app()->getLocale() === 'en' ? ($product->baseUnit?->name_en ?: $product->baseUnit?->name_ar) : $product->baseUnit?->name_ar }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="p-6 text-center text-text-muted">
                                    {{ __('inventory.no_warehouse_stock') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    @elseif ($activeTab === 'lots')
        <!-- Lots Table -->
        <x-card padding="p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-right rtl:text-right ltr:text-left">
                    <thead class="bg-surface-soft border-b border-border text-text-secondary font-bold">
                        <tr>
                            <th class="p-3.5">{{ __('inventory.lot_number') }}</th>
                            <th class="p-3.5">{{ __('inventory.warehouse') }}</th>
                            <th class="p-3.5">{{ __('inventory.expiry_date') }}</th>
                            <th class="p-3.5 text-center">{{ __('inventory.days_remaining') }}</th>
                            <th class="p-3.5 text-center">{{ __('inventory.current_stock') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y border-border">
                        @forelse ($lots as $lb)
                            @php
                                $days = $lb->lot ? $lb->lot->daysUntilExpiry() : null;
                                $isExpired = $lb->lot && $lb->lot->isExpired();
                            @endphp
                            <tr>
                                <td class="p-3.5 font-mono font-bold text-text-primary dir-ltr">
                                    {{ $lb->lot?->lot_number ?? __('inventory.lot_prefix') . $lb->lot_id }}
                                </td>
                                <td class="p-3.5 text-text-secondary">
                                    {{ app()->getLocale() === 'en' ? ($lb->warehouse?->name_en ?: $lb->warehouse?->name_ar) : $lb->warehouse?->name_ar }}
                                </td>
                                <td class="p-3.5 font-mono dir-ltr text-text-secondary">
                                    {{ $lb->lot?->expiry_date ?? '—' }}
                                </td>
                                <td class="p-3.5 text-center">
                                    @if ($isExpired)
                                        <x-badge variant="danger">{{ __('inventory.expired') }}</x-badge>
                                    @elseif ($days !== null && $days <= 30)
                                        <x-badge variant="warn">{{ $days }} {{ __('inventory.days') }}</x-badge>
                                    @elseif ($days !== null)
                                        <span class="font-mono text-text-secondary">{{ $days }} {{ __('inventory.days') }}</span>
                                    @else
                                        <span class="text-text-muted">—</span>
                                    @endif
                                </td>
                                <td class="p-3.5 text-center font-mono font-bold tabular-nums dir-ltr text-text-primary">
                                    {{ $lb->quantity_base }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="p-6 text-center text-text-muted">
                                    {{ __('inventory.no_lots_with_balance') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    @elseif ($activeTab === 'movements')
        <!-- Movements History Table -->
        <x-card padding="p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-right rtl:text-right ltr:text-left">
                    <thead class="bg-surface-soft border-b border-border text-text-secondary font-bold">
                        <tr>
                            <th class="p-3.5">{{ __('inventory.movement_date') }}</th>
                            <th class="p-3.5">{{ __('inventory.movement_type') }}</th>
                            <th class="p-3.5">{{ __('inventory.warehouse') }}</th>
                            <th class="p-3.5 text-center">{{ __('inventory.quantity_delta') }}</th>
                            @if ($canViewCost)
                                <th class="p-3.5 text-center">{{ __('inventory.unit_cost') }}</th>
                                <th class="p-3.5 text-center">{{ __('inventory.value_delta') }}</th>
                            @endif
                            <th class="p-3.5">{{ __('inventory.reason') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($movements as $m)
                            <tr>
                                <td class="p-3.5 font-mono dir-ltr text-text-secondary">
                                    {{ $m->movement_date }}
                                </td>
                                <td class="p-3.5">
                                    <x-badge variant="{{ $m->isInbound() ? 'success' : 'alert' }}">
                                        {{ __('inventory.type_' . $m->movement_type) }}
                                    </x-badge>
                                </td>
                                <td class="p-3.5 text-text-secondary">
                                    {{ app()->getLocale() === 'en' ? ($m->warehouse?->name_en ?: $m->warehouse?->name_ar) : $m->warehouse?->name_ar }}
                                </td>
                                <td class="p-3.5 text-center font-mono font-bold tabular-nums dir-ltr {{ $m->isInbound() ? 'text-success' : 'text-danger' }}">
                                    {{ $m->isInbound() ? '+' : '' }}{{ $m->quantity_delta_base }}
                                </td>
                                @if ($canViewCost)
                                    <td class="p-3.5 text-center font-mono tabular-nums dir-ltr text-text-secondary">
                                        {{ $m->unit_cost_base }}
                                    </td>
                                    <td class="p-3.5 text-center font-mono font-bold tabular-nums dir-ltr text-text-primary">
                                        {{ $m->value_delta_base }}
                                    </td>
                                @endif
                                <td class="p-3.5 text-text-muted truncate max-w-xs">
                                    {{ $m->reason ?? '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canViewCost ? 7 : 5 }}" class="p-6 text-center text-text-muted">
                                    {{ __('inventory.no_movements_recorded') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    @elseif ($activeTab === 'units')
        <!-- Units Tab -->
        <x-card padding="p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-right rtl:text-right ltr:text-left">
                    <thead class="bg-surface-soft border-b border-border text-text-secondary font-bold">
                        <tr>
                            <th class="p-3.5">{{ __('inventory.unit') }}</th>
                            <th class="p-3.5 text-center">{{ __('inventory.conversion_factor_to_base') }}</th>
                            <th class="p-3.5 text-center">{{ __('common.type') }}</th>
                            <th class="p-3.5 text-center">{{ __('inventory.default_sale_unit') }}</th>
                            <th class="p-3.5 text-center">{{ __('inventory.default_purchase_unit') }}</th>
                            <th class="p-3.5 text-center">{{ __('common.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($product->productUnits as $pu)
                            <tr>
                                <td class="p-3.5 font-bold text-text-primary">
                                    {{ app()->getLocale() === 'en' ? ($pu->unit?->name_en ?: $pu->unit?->name_ar) : $pu->unit?->name_ar }} ({{ $pu->unit?->code }})
                                </td>
                                <td class="p-3.5 text-center font-mono font-bold dir-ltr">
                                    {{ $pu->conversion_to_base }}
                                </td>
                                <td class="p-3.5 text-center">
                                    @if ($pu->is_base)
                                        <x-badge variant="info">{{ __('inventory.base_unit') }}</x-badge>
                                    @else
                                        <x-badge variant="muted">{{ __('inventory.sub_unit') }}</x-badge>
                                    @endif
                                </td>
                                <td class="p-3.5 text-center">
                                    @if ($pu->is_default_sale)
                                        <span class="text-success font-bold">&#10003;</span>
                                    @else
                                        <span class="text-text-muted">&times;</span>
                                    @endif
                                </td>
                                <td class="p-3.5 text-center">
                                    @if ($pu->is_default_purchase)
                                        <span class="text-success font-bold">&#10003;</span>
                                    @else
                                        <span class="text-text-muted">&times;</span>
                                    @endif
                                </td>
                                <td class="p-3.5 text-center">
                                    @if ($pu->active)
                                        <x-badge variant="success">{{ __('common.active') }}</x-badge>
                                    @else
                                        <x-badge variant="muted">{{ __('common.inactive') }}</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-6 text-center text-text-muted">
                                    {{ __('inventory.no_units_registered') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    @elseif ($activeTab === 'barcodes')
        <!-- Barcodes Tab -->
        <x-card padding="p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-right rtl:text-right ltr:text-left">
                    <thead class="bg-surface-soft border-b border-border text-text-secondary font-bold">
                        <tr>
                            <th class="p-3.5">{{ __('inventory.barcode') }}</th>
                            <th class="p-3.5">{{ __('common.type') }}</th>
                            <th class="p-3.5">{{ __('inventory.associated_unit') }}</th>
                            <th class="p-3.5 text-center">{{ __('inventory.primary') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($product->barcodes as $bc)
                            <tr>
                                <td class="p-3.5 font-mono font-bold text-text-primary dir-ltr">
                                    {{ $bc->barcode }}
                                </td>
                                <td class="p-3.5 font-mono text-text-secondary dir-ltr">
                                    {{ $bc->type }}
                                </td>
                                <td class="p-3.5 text-text-secondary">
                                    {{ app()->getLocale() === 'en' ? ($bc->unit?->name_en ?: $bc->unit?->name_ar) : $bc->unit?->name_ar ?? '—' }}
                                </td>
                                <td class="p-3.5 text-center">
                                    @if ($bc->is_primary)
                                        <x-badge variant="success">{{ __('inventory.primary') }}</x-badge>
                                    @else
                                        <span class="text-text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="p-6 text-center text-text-muted">
                                    {{ __('inventory.no_barcodes_registered') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    @elseif ($activeTab === 'images')
        <!-- Images Tab -->
        <x-card padding="p-6">
            @if ($product->images->isNotEmpty())
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                    @foreach ($product->images as $img)
                        <div class="rounded-control border border-border overflow-hidden bg-surface-soft space-y-2 p-2">
                            <div class="aspect-square rounded-control overflow-hidden bg-white relative">
                                <img src="{{ asset('storage/' . $img->path) }}" alt="{{ (app()->getLocale() === 'en' ? ($img->alt_en ?: $img->alt_ar) : $img->alt_ar) ?? (app()->getLocale() === 'en' ? ($product->name_en ?: $product->name_ar) : $product->name_ar) }}" class="w-full h-full object-cover" />
                                @if ($img->is_primary)
                                    <div class="absolute top-1.5 right-1.5">
                                        <x-badge variant="success">{{ __('inventory.primary') }}</x-badge>
                                    </div>
                                @endif
                            </div>
                            <div class="text-[11px] text-text-muted truncate">
                                {{ $img->width }}x{{ $img->height }} • {{ round($img->file_size / 1024) }} KB
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="p-12 text-center text-text-muted">
                    <x-icon name="box" class="w-12 h-12 mx-auto mb-3 text-text-muted/40" />
                    <p class="text-xs">{{ __('inventory.no_images_for_product') }}</p>
                </div>
            @endif
        </x-card>
    @elseif ($activeTab === 'purchase_history' && $canViewPurchasingCost)
        <!-- Purchase Price History Tab -->
        <div class="space-y-6">
            <!-- Commercial Comparison Metric Explanatory Banner -->
            <div class="p-3.5 bg-surface-soft border border-border rounded-card text-xs text-text-secondary flex items-start gap-2.5">
                <span class="text-primary font-bold mt-0.5" aria-hidden="true">ℹ</span>
                <div>
                    <span class="font-bold text-text-primary block mb-0.5">{{ __('purchasing.net_commercial_price_per_base_unit') }}</span>
                    <span>{{ __('purchasing.commercial_price_metric_explanation') }}</span>
                </div>
            </div>

            <x-card padding="p-0" class="overflow-hidden">
                <div class="p-4 border-b border-border flex items-center justify-between gap-3">
                    <h3 class="text-sm font-extrabold text-text-primary">{{ __('purchasing.purchase_price_history') }}</h3>
                </div>

                @if ($purchaseHistory->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                                <tr>
                                    <th class="py-2.5 px-4 text-start">{{ __('purchasing.purchase_number') }}</th>
                                    <th class="py-2.5 px-4 text-start">{{ __('purchasing.purchase_date') }}</th>
                                    <th class="py-2.5 px-4 text-start">{{ __('purchasing.vendor') }}</th>
                                    <th class="py-2.5 px-4 text-start">{{ __('purchasing.unit') }}</th>
                                    <th class="py-2.5 px-4 text-end">{{ __('purchasing.quantity') }}</th>
                                    <th class="py-2.5 px-4 text-end">{{ __('purchasing.raw_unit_cost') }}</th>
                                    <th class="py-2.5 px-4 text-end">{{ __('purchasing.discount') }}</th>
                                    <th class="py-2.5 px-4 text-end">{{ __('purchasing.net_commercial_price_per_base_unit') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach ($purchaseHistory as $item)
                                    <tr class="hover:bg-canvas transition-colors">
                                        <td class="py-3 px-4 font-mono font-bold whitespace-nowrap">
                                            @if ($canViewPurchases)
                                                <a href="{{ route('purchases.show', $item->purchase_public_id) }}" class="text-primary hover:underline">
                                                    {{ $item->purchase_number }}
                                                </a>
                                            @else
                                                <span class="text-text-secondary">{{ $item->purchase_number }}</span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4 text-text-secondary whitespace-nowrap font-mono" dir="ltr">
                                            {{ $item->purchase_date }}
                                        </td>
                                        <td class="py-3 px-4 font-bold text-text-primary">
                                            @if ($item->vendor_public_id && $canViewVendors)
                                                <a href="{{ route('vendors.show', $item->vendor_public_id) }}" class="text-primary hover:underline">
                                                    {{ $item->vendor_name }}
                                                </a>
                                            @else
                                                {{ $item->vendor_name }}
                                            @endif
                                            @if ($item->vendor_code)
                                                <span class="block text-[10px] text-text-muted font-mono">{{ $item->vendor_code }}</span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4 text-text-secondary whitespace-nowrap">
                                            {{ $item->unit_name }}
                                        </td>
                                        <td class="py-3 px-4 text-end font-mono" dir="ltr">
                                            {{ strpos($item->quantity, '.') !== false ? rtrim(rtrim($item->quantity, '0'), '.') : $item->quantity }}
                                        </td>
                                        <td class="py-3 px-4 text-end font-mono font-bold whitespace-nowrap" dir="ltr">
                                            {{ strpos($item->unit_cost, '.') !== false ? rtrim(rtrim($item->unit_cost, '0'), '.') : $item->unit_cost }}
                                            <span class="text-[10px] font-normal text-text-muted">{{ $item->currency_code }}</span>
                                        </td>
                                        <td class="py-3 px-4 text-end font-mono whitespace-nowrap" dir="ltr">
                                            @if ($item->discount_type && $item->discount_type !== 'none')
                                                <span class="text-danger">
                                                    {{ strpos($item->discount_value, '.') !== false ? rtrim(rtrim($item->discount_value, '0'), '.') : $item->discount_value }}
                                                    {{ $item->discount_type === 'percent' ? '%' : $item->currency_code }}
                                                </span>
                                            @else
                                                <span class="text-text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4 text-end font-mono font-bold text-primary whitespace-nowrap" dir="ltr">
                                            @if ($item->net_commercial_price_per_base_unit !== null)
                                                {{ $item->net_commercial_price_per_base_unit }}
                                                <span class="text-[10px] font-normal text-text-muted">{{ $item->base_currency_code }}</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="p-8 text-center text-text-muted">
                        <p class="text-xs">{{ __('purchasing.no_price_history') }}</p>
                    </div>
                @endif
            </x-card>
        </div>
    @endif
</div>
