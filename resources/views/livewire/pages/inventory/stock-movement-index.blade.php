<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-extrabold text-text-primary flex items-center gap-2.5">
                <x-icon name="receipt" class="w-6 h-6 text-primary" />
                <span>{{ __('inventory.stock_movements') }}</span>
            </h1>
            <p class="text-xs text-text-secondary mt-1">{{ __('inventory.stock_movements_subtitle') }}</p>
        </div>
    </div>

    <!-- Filters Bar -->
    <x-refinement-filters :active-count="(int) filled($productFilter) + (int) filled($warehouseFilter) + (int) filled($typeFilter) + (int) filled($fromDate) + (int) filled($toDate)">


        @php
            $selectedProd = $productFilter ? $products->firstWhere('id', (int) $productFilter) : null;
            $prodLabel = $selectedProd ? $selectedProd->name() : '';
            $prodOptions = $products->map(fn($p) => [
                'id' => (string) $p->id,
                'label' => $p->name(),
                'sublabel' => $p->sku,
            ])->values()->all();
        @endphp
        <div class="min-w-[200px] flex-1">
            <x-searchable-combobox
                id="stock-movement-product-filter"
                type="product"
                context="inventory"
                wire-model="productFilter"
                :selected-id="$productFilter"
                :selected-label="$prodLabel"
                :options="$prodOptions"
                :placeholder="__('inventory.all_products')"
                :aria-label="__('inventory.all_products')"
            />
        </div>
        <select aria-label="{{ __('inventory.all_warehouses') }}" wire:model.live="warehouseFilter" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-sm bg-surface">
                    <option value="">{{ __('inventory.all_warehouses') }}</option>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ app()->getLocale() === 'en' ? ($w->name_en ?: $w->name_ar) : $w->name_ar }}</option>
                    @endforeach
                </select>
        <select aria-label="{{ __('inventory.all_movement_types') }}" wire:model.live="typeFilter" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-sm bg-surface">
                    <option value="">{{ __('inventory.all_movement_types') }}</option>
                    @foreach ($movementTypes as $type)
                        <option value="{{ $type }}">{{ __('inventory.type_' . $type) }}</option>
                    @endforeach
                </select>
        <label for="stock-movements-from-date" class="grid gap-1 text-sm text-text-secondary">{{ __('inventory.date_from') }}<input id="stock-movements-from-date" aria-label="{{ __('inventory.date_from') }}" type="date" wire:model.live="fromDate" dir="ltr" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-sm bg-surface font-mono" /></label>
        <label for="stock-movements-to-date" class="grid gap-1 text-sm text-text-secondary">{{ __('inventory.date_to') }}<input id="stock-movements-to-date" aria-label="{{ __('inventory.date_to') }}" type="date" wire:model.live="toDate" dir="ltr" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-sm bg-surface font-mono" /></label>
    </x-refinement-filters>

    <!-- Ledger Table -->
    <x-card padding="p-0">
        <div class="overflow-x-auto">
            <table class="data-table data-table--responsive w-full text-xs text-right rtl:text-right ltr:text-left">
                <thead class="bg-surface-soft border-b border-border text-text-secondary font-bold select-none">
                    <tr>
                        <th class="p-3.5">{{ __('inventory.movement_date') }}</th>
                        <th class="p-3.5">{{ __('inventory.movement_type') }}</th>
                        <th class="p-3.5">{{ __('inventory.product') }}</th>
                        <th class="p-3.5">{{ __('inventory.warehouse') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.lot_number') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.quantity_delta') }}</th>
                        @if ($canViewCost)
                            <th class="p-3.5 text-center">{{ __('inventory.unit_cost') }}</th>
                            <th class="p-3.5 text-center">{{ __('inventory.value_delta') }}</th>
                            <th class="p-3.5 text-center">{{ __('inventory.average_cost_after') }}</th>
                        @endif
                        <th class="p-3.5">{{ __('inventory.reason') }}</th>
                        <th class="p-3.5">{{ __('inventory.actor') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($movements as $m)
                        @php($whName = app()->getLocale() === 'en' ? ($m->warehouse?->name_en ?: $m->warehouse?->name_ar) : $m->warehouse?->name_ar)
                        <tr wire:key="movement-row-{{ $m->id }}" data-row-surface x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'" class="hover:bg-surface-soft/60 transition-colors">
                            <td class="td-secondary p-3.5 font-mono text-text-secondary" data-label="{{ __('inventory.movement_date') }}" dir="ltr">
                                {{ $m->movement_date }}
                            </td>
                            <td class="td-secondary p-3.5" data-label="{{ __('inventory.movement_type') }}">
                                <x-badge variant="{{ $m->isInbound() ? 'success' : 'alert' }}">
                                    {{ __('inventory.type_' . $m->movement_type) }}
                                </x-badge>
                            </td>
                            <td class="td-primary p-3.5 font-bold text-text-primary">
                                <div class="flex items-center gap-2 min-w-0">
                                    @if ($m->product)
                                        <a href="{{ route('products.show', $m->product->public_id) }}" data-row-link class="touch-action hover:text-primary transition-colors truncate">
                                            {{ $m->product->name() }}
                                        </a>
                                    @else
                                        <span class="text-text-muted">—</span>
                                    @endif
                                    <span class="lg:hidden shrink-0">
                                        <x-badge variant="{{ $m->isInbound() ? 'success' : 'alert' }}">
                                            {{ __('inventory.type_' . $m->movement_type) }}
                                        </x-badge>
                                    </span>
                                </div>
                                <div class="lg:hidden font-normal text-xs text-text-secondary truncate mt-0.5">
                                    <bdi dir="ltr">{{ $m->movement_date }}</bdi>@if($whName) &middot; {{ $whName }}@endif
                                </div>
                            </td>
                            <td class="td-secondary p-3.5 text-text-secondary" data-label="{{ __('inventory.warehouse') }}">
                                {{ $whName }}
                            </td>
                            <td class="td-secondary p-3.5 text-center font-mono text-xs text-text-muted" data-label="{{ __('inventory.lot_number') }}" dir="ltr">
                                {{ $m->lot?->lot_number ?? ($m->lot_id ? '#' . $m->lot_id : '—') }}
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
                                <td class="td-secondary p-3.5 text-center font-mono tabular-nums text-text-muted" data-label="{{ __('inventory.average_cost_after') }}" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($m->average_cost_after) }}
                                </td>
                            @endif
                            <td class="td-secondary p-3.5 text-text-secondary lg:max-w-xs lg:truncate" data-label="{{ __('inventory.reason') }}">
                                {{ $m->reason ?? '—' }}
                            </td>
                            <td class="td-secondary p-3.5 text-text-muted text-xs" data-label="{{ __('inventory.actor') }}">
                                {{ $m->user?->name ?? __('inventory.system') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canViewCost ? 11 : 8 }}" class="p-8 text-center text-text-muted">
                                {{ __('inventory.no_movements_recorded') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($movements->count() > 0 || $movements->currentPage() > 1)
            <div class="p-4 border-t border-border">
                <x-table-pagination :paginator="$movements" />
            </div>
        @endif
    </x-card>
</div>
