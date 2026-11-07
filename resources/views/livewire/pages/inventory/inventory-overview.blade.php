<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-text-primary flex items-center gap-2.5">
                <x-icon name="box" class="w-6 h-6 text-primary" />
                <span>{{ __('inventory.inventory_overview') }}</span>
            </h1>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($canAdjust)
                <a href="{{ route('inventory.opening-stock.create') }}"
                   class="touch-action inline-flex items-center gap-1.5 px-3 py-2 bg-surface border border-border hover:bg-surface-soft text-text-primary text-sm font-bold rounded-control transition-colors">
                    <x-icon name="plus" class="w-4 h-4 text-primary" />
                    <span>{{ __('inventory.opening_stock') }}</span>
                </a>
                <a href="{{ route('inventory.adjustments.create') }}"
                   class="touch-action inline-flex items-center gap-1.5 px-3 py-2 bg-surface border border-border hover:bg-surface-soft text-text-primary text-sm font-bold rounded-control transition-colors">
                    <x-icon name="plus" class="w-4 h-4 text-primary" />
                    <span>{{ __('inventory.adjust_stock') }}</span>
                </a>
            @endif
            @if ($canTransfer)
                <a href="{{ route('inventory.transfers.create') }}"
                   class="touch-action inline-flex items-center gap-1.5 px-3 py-2 bg-surface border border-border hover:bg-surface-soft text-text-primary text-sm font-bold rounded-control transition-colors">
                    <x-icon name="truck" class="w-4 h-4 text-primary" />
                    <span>{{ __('inventory.transfer_stock') }}</span>
                </a>
            @endif
            <a href="{{ route('inventory.movements') }}"
               class="touch-action inline-flex items-center gap-1.5 px-3 py-2 bg-primary hover:bg-primary-hover text-white text-sm font-bold rounded-control shadow-button transition-colors">
                <x-icon name="receipt" class="w-4 h-4" />
                <span>{{ __('inventory.stock_movements') }}</span>
            </a>
        </div>
    </div>

    @if ($canViewCost)
        <p class="text-sm text-text-secondary">{{ __('inventory.inventory_value') }}
            <bdi dir="ltr" class="ms-2 font-semibold text-text-primary">{{ $totalValuation }} {{ $company->base_currency_code }}</bdi>
        </p>
    @endif

    <!-- Stock by Location (Searchable & Filterable Table) -->
    <x-card padding="p-0" class="overflow-hidden">
        <div class="p-4 border-b border-border bg-surface-soft flex flex-col md:flex-row md:items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <x-icon name="box" class="w-4 h-4 text-primary" />
                <h2 class="text-xs font-bold text-text-primary uppercase tracking-wider">{{ __('inventory.stock_by_location') }}</h2>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <input type="search"
                       id="inventory-stock-search"
                       aria-label="{{ __('inventory.search_stock_placeholder') }}"
                       wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('inventory.search_stock_placeholder') }}"
                       class="min-h-9 px-3 rounded-control border border-border bg-surface text-sm text-text-primary focus:border-primary focus:ring-primary w-48" />

                <select wire:model.live="selectedWarehouseId"
                        id="inventory-stock-warehouse" aria-label="{{ __('inventory.warehouse') }}"
                        class="min-h-9 px-2 rounded-control border border-border bg-surface text-sm text-text-primary focus:border-primary focus:ring-primary">
                    <option value="">{{ __('inventory.all_warehouses') }}</option>
                    @foreach($warehouses as $wh)
                        <option value="{{ $wh->id }}">{{ $wh->displayName() }}</option>
                    @endforeach
                </select>

                <select wire:model.live="stockFilter"
                        id="inventory-stock-status" aria-label="{{ __('inventory.stock_quantity') }}"
                        class="min-h-9 px-2 rounded-control border border-border bg-surface text-sm text-text-primary focus:border-primary focus:ring-primary">
                    <option value="all">{{ __('sales.all') }}</option>
                    <option value="in_stock">{{ __('inventory.in_stock') }}</option>
                    <option value="low_stock">{{ __('inventory.low_stock_metric') }}</option>
                    <option value="out_of_stock">{{ __('inventory.out_of_stock') }}</option>
                </select>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="data-table w-full text-xs">
                <thead class="bg-surface-soft text-text-secondary border-b border-border">
                    <tr>
                        <th class="px-4 py-2.5 text-start">{{ __('inventory.product') }}</th>
                        <th class="px-4 py-2.5 text-start">{{ __('inventory.warehouse') }}</th>
                        <th class="px-4 py-2.5 text-end">{{ __('inventory.stock_quantity') }}</th>
                        <th class="px-4 py-2.5 text-start">{{ __('inventory.unit') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($stockBalances as $balance)
                        <tr class="hover:bg-surface-soft/40 transition-colors">
                            <td class="px-4 py-3 font-medium text-text-primary">
                                <a href="{{ route('products.show', $balance->product->public_id) }}" class="touch-action hover:text-primary">
                                    {{ $balance->product->displayName() }}
                                </a>
                                @if($balance->product->sku)
                                    <span class="ms-2 text-xs text-text-muted font-mono" dir="ltr">{{ $balance->product->sku }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-text-secondary">
                                {{ $balance->warehouse->displayName() }}
                            </td>
                            <td class="px-4 py-3 text-end font-mono font-bold" dir="ltr">
                                <bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($balance->quantity_base) }}</bdi>
                                <x-stock-breakdown :parts="$stockBreakdowns[$balance->id] ?? []" :inline="true" />
                            </td>
                            <td class="px-4 py-3 text-text-secondary">
                                {{ $balance->product->baseUnit?->name() ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-text-muted">
                                {{ __('inventory.no_stock_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

            <div class="p-3 border-t border-border">
                <x-table-pagination :paginator="$stockBalances" />
            </div>
    </x-card>

</div>
