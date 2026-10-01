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
    <x-card padding="p-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3">
            <!-- Product Filter -->
            <div>
                <label class="block text-[11px] font-bold text-text-secondary mb-1">{{ __('inventory.product') }}</label>
                <select wire:model.live="productFilter" class="w-full h-8 px-2 rounded-control border border-border text-xs bg-white">
                    <option value="">{{ __('inventory.all_products') }}</option>
                    @foreach ($products as $p)
                        <option value="{{ $p->id }}">{{ app()->getLocale() === 'en' ? ($p->name_en ?: $p->name_ar) : $p->name_ar }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Warehouse Filter -->
            <div>
                <label class="block text-[11px] font-bold text-text-secondary mb-1">{{ __('inventory.warehouse') }}</label>
                <select wire:model.live="warehouseFilter" class="w-full h-8 px-2 rounded-control border border-border text-xs bg-white">
                    <option value="">{{ __('inventory.all_warehouses') }}</option>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ app()->getLocale() === 'en' ? ($w->name_en ?: $w->name_ar) : $w->name_ar }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Movement Type Filter -->
            <div>
                <label class="block text-[11px] font-bold text-text-secondary mb-1">{{ __('inventory.movement_type') }}</label>
                <select wire:model.live="typeFilter" class="w-full h-8 px-2 rounded-control border border-border text-xs bg-white">
                    <option value="">{{ __('inventory.all_movement_types') }}</option>
                    @foreach ($movementTypes as $type)
                        <option value="{{ $type }}">{{ __('inventory.type_' . $type) }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Date Range -->
            <div>
                <label class="block text-[11px] font-bold text-text-secondary mb-1">{{ __('inventory.date_from') }}</label>
                <input type="date" wire:model.live="fromDate" class="w-full h-8 px-2 rounded-control border border-border text-xs bg-white font-mono dir-ltr" />
            </div>
            <div>
                <label class="block text-[11px] font-bold text-text-secondary mb-1">{{ __('inventory.date_to') }}</label>
                <input type="date" wire:model.live="toDate" class="w-full h-8 px-2 rounded-control border border-border text-xs bg-white font-mono dir-ltr" />
            </div>
        </div>
    </x-card>

    <!-- Ledger Table -->
    <x-card padding="p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-xs text-right rtl:text-right ltr:text-left">
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
                        <tr class="hover:bg-surface-soft/60 transition-colors">
                            <td class="p-3.5 font-mono dir-ltr text-text-secondary">
                                {{ $m->movement_date }}
                            </td>
                            <td class="p-3.5">
                                <x-badge variant="{{ $m->isInbound() ? 'success' : 'alert' }}">
                                    {{ __('inventory.type_' . $m->movement_type) }}
                                </x-badge>
                            </td>
                            <td class="p-3.5 font-bold text-text-primary">
                                <a href="{{ route('products.show', $m->product?->public_id) }}" class="hover:text-primary transition-colors">
                                    {{ app()->getLocale() === 'en' ? ($m->product?->name_en ?: $m->product?->name_ar) : $m->product?->name_ar }}
                                </a>
                            </td>
                            <td class="p-3.5 text-text-secondary">
                                {{ app()->getLocale() === 'en' ? ($m->warehouse?->name_en ?: $m->warehouse?->name_ar) : $m->warehouse?->name_ar }}
                            </td>
                            <td class="p-3.5 text-center font-mono text-[11px] dir-ltr text-text-muted">
                                {{ $m->lot?->lot_number ?? ($m->lot_id ? '#' . $m->lot_id : '—') }}
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
                                <td class="p-3.5 text-center font-mono tabular-nums dir-ltr text-text-muted">
                                    {{ $m->average_cost_after }}
                                </td>
                            @endif
                            <td class="p-3.5 text-text-secondary max-w-xs truncate">
                                {{ $m->reason ?? '—' }}
                            </td>
                            <td class="p-3.5 text-text-muted text-[11px]">
                                {{ $m->user?->name ?? __('inventory.system') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canViewCost ? 10 : 7 }}" class="p-8 text-center text-text-muted">
                                {{ __('inventory.no_movements_recorded') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($movements->hasPages())
            <div class="p-4 border-t border-border">
                {{ $movements->links() }}
            </div>
        @endif
    </x-card>
</div>
