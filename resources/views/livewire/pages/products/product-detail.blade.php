<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('products.index') }}" class="touch-action text-sm text-text-muted hover:text-primary transition-colors">&larr; {{ __('inventory.products') }}</a>
                <span class="text-text-muted">/</span>
                <span class="text-xs text-text-secondary">{{ (app()->getLocale() === 'en' && $product->category?->name_en) ? $product->category->name_en : ($product->category?->name_ar ?? __('inventory.general')) }}</span>
            </div>
            <h1 class="text-xl font-extrabold text-text-primary flex items-center gap-3">
                <span>{{ $product->name() }}</span>
                @if ($product->active)
                    <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                @else
                    <x-badge variant="muted">{{ __('inventory.inactive') }}</x-badge>
                @endif
                <x-badge variant="info">{{ __('inventory.type_' . $product->product_type) }}</x-badge>
            </h1>
            @if ($product->name_en || $product->sku)
                <p class="text-xs text-text-muted font-mono mt-1" dir="ltr">
                    {{ $product->sku ? 'SKU: ' . $product->sku : '' }}
                    {{ $product->sku && $product->name_en ? ' | ' : '' }}
                    {{ $product->name_en ?? '' }}
                </p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($canReceiveStock)
                <a href="{{ route('purchases.create', ['receiveProduct' => $product->public_id]) }}" class="touch-action inline-flex items-center gap-2 px-3 py-2 bg-primary text-white hover:bg-primary-hover text-sm font-bold rounded-control focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    <x-icon name="plus" class="w-4 h-4" />
                    {{ __('quick_product.receive_stock') }}
                </a>
            @endif
            @if ($canManageProduct)
                <a href="{{ route('products.edit', $product->public_id) }}"
                   class="touch-action inline-flex items-center gap-1.5 px-3 py-2 bg-surface border border-border hover:bg-surface-soft text-text-primary text-xs font-bold rounded-control transition-colors">
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
            <div class="text-xs font-bold text-text-muted uppercase tracking-wider">{{ __('inventory.total_stock') }}</div>
            <div class="text-xl font-extrabold font-mono tabular-nums text-text-primary">
                <bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($stockTotal) }}</bdi>
                <span class="text-xs font-normal text-text-secondary font-arabic">{{ (app()->getLocale() === 'en' && $product->baseUnit?->name_en) ? $product->baseUnit->name_en : $product->baseUnit?->name_ar }}</span>
            </div>
            <x-stock-breakdown :parts="$stockBreakdown" />
        </x-card>

        <!-- Suggested Sale Price KPI -->
        <x-card padding="p-4" class="space-y-1">
            <div class="text-xs font-bold text-text-muted uppercase tracking-wider">{{ __('inventory.suggested_sale_price') }}</div>
            <div class="text-xl font-extrabold font-mono tabular-nums text-text-primary">
                <bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($product->default_sale_price_base ?? '—') }}</bdi>
                <span class="text-xs font-normal text-text-secondary font-arabic">{{ $company->base_currency_code }}</span>
            </div>
        </x-card>

        @if ($canViewCost)
            <!-- Average Cost KPI -->
            <x-card padding="p-4" class="space-y-1">
                <div class="text-xs font-bold text-text-muted uppercase tracking-wider">{{ __('inventory.moving_average_cost') }}</div>
                <div class="text-xl font-extrabold font-mono tabular-nums text-primary">
                    <bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($costState?->average_cost_base ?? '0.000000') }}</bdi>
                    <span class="text-xs font-normal text-text-secondary font-arabic">{{ $company->base_currency_code }}</span>
                </div>
            </x-card>

            <!-- Inventory Valuation KPI -->
            <x-card padding="p-4" class="space-y-1">
                <div class="text-xs font-bold text-text-muted uppercase tracking-wider">{{ __('inventory.inventory_value') }}</div>
                <div class="text-xl font-extrabold font-mono tabular-nums text-text-primary">
                    <bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($costState?->inventory_value_base ?? '0.000000') }}</bdi>
                    <span class="text-xs font-normal text-text-secondary font-arabic">{{ $company->base_currency_code }}</span>
                </div>
            </x-card>
        @endif
    </div>

    <!-- Navigation Tabs -->
    <div role="tablist"
         aria-label="{{ __('inventory.product_details') }}"
         @keydown="window.handleTablistKeydown?.($event, $el)"
         class="border-b border-border flex flex-wrap items-center gap-6 text-xs font-bold select-none">
        <button type="button" role="tab" id="tab-overview" aria-controls="panel-product" aria-selected="{{ $activeTab === 'overview' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'overview' ? '0' : '-1' }}" wire:click="setTab('overview')"
                class="pb-3 border-b-2 cursor-pointer transition-colors {{ $activeTab === 'overview' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
            {{ __('inventory.overview') }}
        </button>
        <button type="button" role="tab" id="tab-warehouses" aria-controls="panel-product" aria-selected="{{ $activeTab === 'warehouses' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'warehouses' ? '0' : '-1' }}" wire:click="setTab('warehouses')"
                class="pb-3 border-b-2 cursor-pointer transition-colors {{ $activeTab === 'warehouses' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
            {{ __('inventory.stock_by_warehouse') }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($warehouseBalances->count()) }})
        </button>
        @if ($product->track_expiry)
            <button type="button" role="tab" id="tab-lots" aria-controls="panel-product" aria-selected="{{ $activeTab === 'lots' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'lots' ? '0' : '-1' }}" wire:click="setTab('lots')"
                    class="pb-3 border-b-2 cursor-pointer transition-colors {{ $activeTab === 'lots' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
                {{ __('inventory.lots_and_expiry') }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity(($lots instanceof \Illuminate\Pagination\LengthAwarePaginator ? $lots->total() : $lots->count())) }})
            </button>
        @endif
        <button type="button" role="tab" id="tab-movements" aria-controls="panel-product" aria-selected="{{ $activeTab === 'movements' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'movements' ? '0' : '-1' }}" wire:click="setTab('movements')"
                class="pb-3 border-b-2 cursor-pointer transition-colors {{ $activeTab === 'movements' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
            {{ __('inventory.stock_movements') }}
        </button>
        <button type="button" role="tab" id="tab-units" aria-controls="panel-product" aria-selected="{{ $activeTab === 'units' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'units' ? '0' : '-1' }}" wire:click="setTab('units')"
                class="pb-3 border-b-2 cursor-pointer transition-colors {{ $activeTab === 'units' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
            {{ __('inventory.units') }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($product->productUnits->count()) }})
        </button>
        <button type="button" role="tab" id="tab-barcodes" aria-controls="panel-product" aria-selected="{{ $activeTab === 'barcodes' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'barcodes' ? '0' : '-1' }}" wire:click="setTab('barcodes')"
                class="pb-3 border-b-2 cursor-pointer transition-colors {{ $activeTab === 'barcodes' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
            {{ __('inventory.barcodes') }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($product->barcodes->count()) }})
        </button>
        <button type="button" role="tab" id="tab-images" aria-controls="panel-product" aria-selected="{{ $activeTab === 'images' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'images' ? '0' : '-1' }}" wire:click="setTab('images')"
                class="pb-3 border-b-2 cursor-pointer transition-colors {{ $activeTab === 'images' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
            {{ __('inventory.images') }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($product->images->count()) }})
        </button>
        @if ($canViewPurchasingCost)
            <button type="button" role="tab" id="tab-purchase_history" aria-controls="panel-product" aria-selected="{{ $activeTab === 'purchase_history' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'purchase_history' ? '0' : '-1' }}" wire:click="setTab('purchase_history')"
                    class="pb-3 border-b-2 cursor-pointer transition-colors {{ $activeTab === 'purchase_history' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
                {{ __('purchasing.purchase_price_history') }}
            </button>
        @endif
    </div>

    <!-- Tab Content -->
    @if ($activeTab === 'overview')
        <div role="tabpanel" id="panel-product" aria-labelledby="tab-overview" tabindex="0">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Details Panel -->
            <x-card padding="p-6" class="md:col-span-2 space-y-4">
                <h3 class="text-sm font-extrabold text-text-primary border-b border-border pb-2.5">{{ __('inventory.product_details') }}</h3>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div>
                        <dt class="text-text-muted">{{ __('inventory.name_ar') }}</dt>
                        <dd class="font-bold text-text-primary mt-0.5">{{ $product->name_ar ?? '—' }}</dd>
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
                        <dd class="font-bold font-mono mt-0.5" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($product->minimum_stock_base ?? '0') }}</dd>
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
                                <img src="{{ asset('storage/' . $img->thumbnail_path) }}" alt="{{ $product->name() }}" class="w-full h-full object-cover" />
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
        </div>
    @elseif ($activeTab === 'warehouses')
        <!-- Stock by Warehouse Table -->
        <div role="tabpanel" id="panel-product" aria-labelledby="tab-warehouses" tabindex="0">
        <x-card padding="p-0">
            <div class="overflow-x-auto">
                <table class="data-table data-table--responsive w-full text-xs text-right rtl:text-right ltr:text-left">
                    <thead class="bg-surface-soft border-b border-border text-text-secondary font-bold">
                        <tr>
                            <th class="p-3.5">{{ __('inventory.warehouse') }}</th>
                            <th class="p-3.5 text-center">{{ __('inventory.current_stock') }}</th>
                            <th class="p-3.5 text-center">{{ __('inventory.unit') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($warehouseBalances as $bal)
                            <tr wire:key="pdet-wh-{{ $bal->id }}" x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'">
                                <td class="td-primary p-3.5 font-bold text-text-primary">
                                    <div class="flex items-center gap-2">
                                        <span class="truncate">{{ app()->getLocale() === 'en' ? ($bal->warehouse?->name_en ?: $bal->warehouse?->name_ar) : $bal->warehouse?->name_ar }}</span>
                                        @if ($bal->warehouse?->is_default)
                                            <x-badge variant="info">{{ __('inventory.default') }}</x-badge>
                                        @endif
                                    </div>
                                    <div class="lg:hidden font-normal text-xs text-text-secondary mt-0.5">
                                        {{ app()->getLocale() === 'en' ? ($product->baseUnit?->name_en ?: $product->baseUnit?->name_ar) : $product->baseUnit?->name_ar }}
                                    </div>
                                </td>
                                <td class="td-end p-3.5 text-center font-mono font-bold text-sm tabular-nums text-text-primary">
                                    <div class="inline-flex items-center gap-2">
                                        <span dir="ltr">
                                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($bal->quantity_base) }}
                                            <x-stock-breakdown :parts="$warehouseBreakdowns[$bal->id]" />
                                        </span>
                                        <x-row-details-toggle />
                                    </div>
                                </td>
                                <td class="td-secondary p-3.5 text-center text-text-secondary" data-label="{{ __('inventory.unit') }}">
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
                <div class="p-4"><x-table-pagination :paginator="$warehouseBalances" /></div>
        </x-card>
        </div>
    @elseif ($activeTab === 'lots')
        <!-- Lots Table -->
        <div role="tabpanel" id="panel-product" aria-labelledby="tab-lots" tabindex="0">
        <x-card padding="p-0">
            <div class="overflow-x-auto">
                <table class="data-table data-table--responsive w-full text-xs text-right rtl:text-right ltr:text-left">
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
                                $whTitle = app()->getLocale() === 'en' ? ($lb->warehouse?->name_en ?: $lb->warehouse?->name_ar) : $lb->warehouse?->name_ar;
                            @endphp
                            <tr wire:key="pdet-lot-{{ $lb->id }}" x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'">
                                <td class="td-primary p-3.5 font-mono font-bold text-text-primary">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <bdi dir="ltr" class="truncate">{{ $lb->lot?->lot_number ?? __('inventory.lot_prefix') . $lb->lot_id }}</bdi>
                                        <span class="lg:hidden font-sans shrink-0">
                                            @if ($isExpired)<x-badge variant="danger">{{ __('inventory.expired') }}</x-badge>
                                            @elseif ($days !== null && $days <= 30)<x-badge variant="warn">{{ $days }} {{ __('inventory.days') }}</x-badge>@endif
                                        </span>
                                    </div>
                                    <div class="lg:hidden font-sans font-normal text-xs text-text-secondary truncate mt-0.5">
                                        {{ $whTitle }} &middot; <bdi dir="ltr" class="font-mono">{{ $lb->lot?->expiry_date ?? '—' }}</bdi>
                                    </div>
                                </td>
                                <td class="td-secondary p-3.5 text-text-secondary" data-label="{{ __('inventory.warehouse') }}">
                                    {{ $whTitle }}
                                </td>
                                <td class="td-secondary p-3.5 font-mono text-text-secondary" data-label="{{ __('inventory.expiry_date') }}" dir="ltr">
                                    {{ $lb->lot?->expiry_date ?? '—' }}
                                </td>
                                <td class="td-secondary p-3.5 text-center" data-label="{{ __('inventory.days_remaining') }}">
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
                                <td class="td-end p-3.5 text-center font-mono font-bold tabular-nums text-text-primary">
                                    <div class="inline-flex items-center gap-2">
                                        <bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($lb->quantity_base) }}</bdi>
                                        <x-row-details-toggle />
                                    </div>
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
                <div class="p-4"><x-table-pagination :paginator="$lots" /></div>
        </x-card>
        </div>
    @elseif ($activeTab === 'movements')
        <!-- Movements History Table -->
        <div role="tabpanel" id="panel-product" aria-labelledby="tab-movements" tabindex="0">
        <x-card padding="p-0">
            <div class="overflow-x-auto">
                <table class="data-table data-table--responsive w-full text-xs text-right rtl:text-right ltr:text-left">
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
                            @php($whName = app()->getLocale() === 'en' ? ($m->warehouse?->name_en ?: $m->warehouse?->name_ar) : $m->warehouse?->name_ar)
                            <tr wire:key="pdet-mov-{{ $m->id }}" x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'">
                                <td class="td-secondary p-3.5 font-mono text-text-secondary" data-label="{{ __('inventory.movement_date') }}" dir="ltr">
                                    {{ $m->movement_date }}
                                </td>
                                <td class="td-primary p-3.5">
                                    <div>
                                        <x-badge variant="{{ $m->isInbound() ? 'success' : 'alert' }}">
                                            {{ __('inventory.type_' . $m->movement_type) }}
                                        </x-badge>
                                    </div>
                                    <div class="lg:hidden font-normal text-xs text-text-secondary truncate mt-0.5">
                                        <bdi dir="ltr" class="font-mono">{{ $m->movement_date }}</bdi>@if($whName) &middot; {{ $whName }}@endif
                                    </div>
                                </td>
                                <td class="td-secondary p-3.5 text-text-secondary" data-label="{{ __('inventory.warehouse') }}">
                                    {{ $whName }}
                                </td>
                                <td class="td-end p-3.5 text-center font-mono font-bold tabular-nums {{ $m->isInbound() ? 'text-success' : 'text-danger' }}">
                                    <div class="inline-flex items-center gap-2">
                                        <bdi dir="ltr">{{ $m->isInbound() ? '+' : '' }}{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($m->quantity_delta_base) }}</bdi>
                                        <x-row-details-toggle />
                                    </div>
                                </td>
                                @if ($canViewCost)
                                    <td class="td-secondary p-3.5 text-center font-mono tabular-nums text-text-secondary" data-label="{{ __('inventory.unit_cost') }}" dir="ltr">
                                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($m->unit_cost_base) }}
                                    </td>
                                    <td class="td-secondary p-3.5 text-center font-mono font-bold tabular-nums text-text-primary" data-label="{{ __('inventory.value_delta') }}" dir="ltr">
                                        {{ $m->value_delta_base }}
                                    </td>
                                @endif
                                <td class="td-secondary p-3.5 text-text-muted lg:truncate lg:max-w-xs" data-label="{{ __('inventory.reason') }}">
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
                <div class="p-4"><x-table-pagination :paginator="$movements" /></div>
        </x-card>
        </div>
    @elseif ($activeTab === 'units')
        <!-- Units Tab -->
        <div role="tabpanel" id="panel-product" aria-labelledby="tab-units" tabindex="0">
        <x-card padding="p-0">
            <div class="overflow-x-auto">
                <table class="data-table data-table--responsive w-full text-xs text-right rtl:text-right ltr:text-left">
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
                            <tr wire:key="pdet-unit-{{ $pu->id }}" x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'">
                                <td class="td-primary p-3.5 font-bold text-text-primary">
                                    <div class="flex items-center gap-2">
                                        <span class="truncate">{{ app()->getLocale() === 'en' ? ($pu->unit?->name_en ?: $pu->unit?->name_ar) : $pu->unit?->name_ar }} ({{ $pu->unit?->code }})</span>
                                        <span class="lg:hidden shrink-0">
                                            @if ($pu->is_base)<x-badge variant="info">{{ __('inventory.base_unit') }}</x-badge>@endif
                                        </span>
                                    </div>
                                    <div class="lg:hidden font-normal text-xs text-text-secondary mt-0.5">
                                        &times;<bdi dir="ltr" class="font-mono">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($pu->conversion_to_base) }}</bdi>
                                    </div>
                                </td>
                                <td class="td-secondary p-3.5 text-center font-mono font-bold" data-label="{{ __('inventory.conversion_factor_to_base') }}" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($pu->conversion_to_base) }}
                                </td>
                                <td class="td-secondary p-3.5 text-center" data-label="{{ __('common.type') }}">
                                    @if ($pu->is_base)
                                        <x-badge variant="info">{{ __('inventory.base_unit') }}</x-badge>
                                    @else
                                        <x-badge variant="muted">{{ __('inventory.sub_unit') }}</x-badge>
                                    @endif
                                </td>
                                <td class="td-secondary p-3.5 text-center" data-label="{{ __('inventory.default_sale_unit') }}">
                                    @if ($pu->is_default_sale)
                                        <span class="text-success font-bold">&#10003;</span>
                                    @else
                                        <span class="text-text-muted">&times;</span>
                                    @endif
                                </td>
                                <td class="td-secondary p-3.5 text-center" data-label="{{ __('inventory.default_purchase_unit') }}">
                                    @if ($pu->is_default_purchase)
                                        <span class="text-success font-bold">&#10003;</span>
                                    @else
                                        <span class="text-text-muted">&times;</span>
                                    @endif
                                </td>
                                <td class="td-end p-3.5 text-center">
                                    <div class="inline-flex items-center gap-2">
                                        @if ($pu->active)
                                            <x-badge variant="success">{{ __('common.active') }}</x-badge>
                                        @else
                                            <x-badge variant="muted">{{ __('common.inactive') }}</x-badge>
                                        @endif
                                        <x-row-details-toggle />
                                    </div>
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
        </div>
    @elseif ($activeTab === 'barcodes')
        <!-- Barcodes Tab -->
        <div role="tabpanel" id="panel-product" aria-labelledby="tab-barcodes" tabindex="0">
        <x-card padding="p-0">
            @if ($product->barcodes->isNotEmpty() && auth()->user()?->hasPermissionTo('inventory.barcode_labels.print') && auth()->user()?->hasAnyPermission(['inventory.stock.view', 'inventory.product.manage']))
                <div class="flex items-center justify-between p-3.5 border-b border-border bg-surface-soft">
                    <span class="text-xs font-bold text-text-secondary">{{ __('inventory.barcodes') }}</span>
                    <a href="{{ route('labels.index', ['search' => $product->sku ?? ($product->barcodes->first()?->barcode ?? '')]) }}"
                       class="touch-action inline-flex items-center gap-1.5 px-3 py-1.5 rounded-control border border-border bg-surface text-xs font-bold text-text-secondary hover:text-text-primary hover:bg-surface transition-colors focus-visible:ring-2 focus-visible:ring-primary">
                        <x-icon name="printer" class="w-3.5 h-3.5 text-primary" />
                        <span>{{ __('release_b1_workflow.print_barcode_labels') }}</span>
                    </a>
                </div>
            @endif
            <div class="overflow-x-auto">
                <table class="data-table data-table--responsive w-full text-xs text-right rtl:text-right ltr:text-left">
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
                            <tr wire:key="pdet-bc-{{ $bc->id }}" x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'">
                                <td class="td-primary p-3.5 font-mono font-bold text-text-primary">
                                    <div class="truncate" dir="ltr">{{ $bc->barcode }}</div>
                                    <div class="lg:hidden font-sans font-normal text-xs text-text-secondary mt-0.5">
                                        {{ $bc->type }} &middot; {{ app()->getLocale() === 'en' ? ($bc->unit?->name_en ?: $bc->unit?->name_ar) : $bc->unit?->name_ar ?? '—' }}
                                    </div>
                                </td>
                                <td class="td-secondary p-3.5 font-mono text-text-secondary" data-label="{{ __('common.type') }}" dir="ltr">
                                    {{ $bc->type }}
                                </td>
                                <td class="td-secondary p-3.5 text-text-secondary" data-label="{{ __('inventory.associated_unit') }}">
                                    {{ app()->getLocale() === 'en' ? ($bc->unit?->name_en ?: $bc->unit?->name_ar) : $bc->unit?->name_ar ?? '—' }}
                                </td>
                                <td class="td-end p-3.5 text-center">
                                    <div class="inline-flex items-center gap-2">
                                        @if ($bc->is_primary)
                                            <x-badge variant="success">{{ __('inventory.primary') }}</x-badge>
                                        @else
                                            <span class="text-text-muted">—</span>
                                        @endif
                                        <x-row-details-toggle />
                                    </div>
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
        </div>
    @elseif ($activeTab === 'images')
        <!-- Images Tab -->
        <div role="tabpanel" id="panel-product" aria-labelledby="tab-images" tabindex="0">
        <x-card padding="p-6">
            @if ($product->images->isNotEmpty())
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                    @foreach ($product->images as $img)
                        <div class="rounded-control border border-border overflow-hidden bg-surface-soft space-y-2 p-2">
                            <div class="aspect-square rounded-control overflow-hidden bg-surface relative">
                                <img src="{{ asset('storage/' . $img->path) }}" alt="{{ (app()->getLocale() === 'en' ? ($img->alt_en ?: $img->alt_ar) : $img->alt_ar) ?? $product->name() }}" class="w-full h-full object-cover" />
                                @if ($img->is_primary)
                                    <div class="absolute top-1.5 right-1.5">
                                        <x-badge variant="success">{{ __('inventory.primary') }}</x-badge>
                                    </div>
                                @endif
                            </div>
                            <div class="text-xs text-text-muted truncate">
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
        </div>
    @elseif ($activeTab === 'purchase_history' && $canViewPurchasingCost)
        <!-- Purchase Price History Tab -->
        <div role="tabpanel" id="panel-product" aria-labelledby="tab-purchase_history" tabindex="0">
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
                        <table class="data-table data-table--responsive w-full text-xs">
                            <thead class="bg-surface-soft text-text-muted font-bold text-xs uppercase">
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
                                @foreach ($purchaseHistory as $histIdx => $item)
                                    <tr wire:key="pdet-hist-{{ $histIdx }}" x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'" class="hover:bg-canvas transition-colors">
                                        <td class="td-secondary py-3 px-4 font-mono font-bold whitespace-nowrap" data-label="{{ __('purchasing.purchase_number') }}">
                                            @if ($canViewPurchases)
                                                <a href="{{ route('purchases.show', $item->purchase_public_id) }}" class="touch-action text-primary hover:underline">
                                                    {{ $item->purchase_number }}
                                                </a>
                                            @else
                                                <span class="text-text-secondary">{{ $item->purchase_number }}</span>
                                            @endif
                                        </td>
                                        <td class="td-secondary py-3 px-4 text-text-secondary whitespace-nowrap font-mono" data-label="{{ __('purchasing.purchase_date') }}" dir="ltr">
                                            {{ $item->purchase_date }}
                                        </td>
                                        <td class="td-primary py-3 px-4 font-bold text-text-primary">
                                            <div class="truncate">
                                                @if ($item->vendor_public_id && $canViewVendors)
                                                    <a href="{{ route('vendors.show', $item->vendor_public_id) }}" class="touch-action text-primary hover:underline">
                                                        {{ $item->vendor_name }}
                                                    </a>
                                                @else
                                                    {{ $item->vendor_name }}
                                                @endif
                                            </div>
                                            @if ($item->vendor_code)
                                                <span class="hidden lg:block text-xs text-text-muted font-mono">{{ $item->vendor_code }}</span>
                                            @endif
                                            <div class="lg:hidden font-normal text-xs text-text-secondary truncate mt-0.5">
                                                <bdi dir="ltr" class="font-mono">{{ $item->purchase_number }}</bdi> &middot; <bdi dir="ltr" class="font-mono">{{ $item->purchase_date }}</bdi> &middot; {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity(strpos($item->quantity, '.') !== false ? rtrim(rtrim($item->quantity, '0'), '.') : $item->quantity) }} {{ $item->unit_name }}
                                            </div>
                                        </td>
                                        <td class="td-secondary py-3 px-4 text-text-secondary whitespace-nowrap" data-label="{{ __('purchasing.unit') }}">
                                            {{ $item->unit_name }}
                                        </td>
                                        <td class="td-secondary py-3 px-4 text-end font-mono" data-label="{{ __('purchasing.quantity') }}" dir="ltr">
                                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity(strpos($item->quantity, '.') !== false ? rtrim(rtrim($item->quantity, '0'), '.') : $item->quantity) }}
                                        </td>
                                        <td class="td-end py-3 px-4 text-end font-mono font-bold whitespace-nowrap">
                                            <div class="inline-flex items-center gap-2">
                                                <bdi dir="ltr" class="tabular-nums">
                                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney(strpos($item->unit_cost, '.') !== false ? rtrim(rtrim($item->unit_cost, '0'), '.') : $item->unit_cost) }}
                                                    <span class="text-xs font-normal text-text-muted">{{ $item->currency_code }}</span>
                                                </bdi>
                                                <x-row-details-toggle />
                                            </div>
                                        </td>
                                        <td class="td-secondary py-3 px-4 text-end font-mono whitespace-nowrap" data-label="{{ __('purchasing.discount') }}" dir="ltr">
                                            @if ($item->discount_type && $item->discount_type !== 'none')
                                                <span class="text-danger">
                                                    {{ $item->discount_type === 'percent' ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($item->discount_value) : \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($item->discount_value) }}
                                                    {{ $item->discount_type === 'percent' ? '%' : $item->currency_code }}
                                                </span>
                                            @else
                                                <span class="text-text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="td-secondary py-3 px-4 text-end font-mono font-bold text-primary whitespace-nowrap" data-label="{{ __('purchasing.net_commercial_price_per_base_unit') }}" dir="ltr">
                                            @if ($item->net_commercial_price_per_base_unit !== null)
                                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($item->net_commercial_price_per_base_unit) }}
                                                <span class="text-xs font-normal text-text-muted">{{ $item->base_currency_code }}</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="p-4"><x-table-pagination :paginator="$purchaseHistory" /></div>
                @else
                    <div class="p-8 text-center text-text-muted">
                        <p class="text-xs">{{ __('purchasing.no_price_history') }}</p>
                    </div>
                @endif
            </x-card>
        </div>
        </div>
    @endif
</div>
