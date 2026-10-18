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


        <select aria-label="{{ __('inventory.all_products') }}" wire:model.live="productFilter" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-xs bg-white">
                    <option value="">{{ __('inventory.all_products') }}</option>
                    @foreach ($products as $p)
                        <option value="{{ $p->id }}">{{ app()->getLocale() === 'en' ? ($p->name_en ?: $p->name_ar) : $p->name_ar }}</option>
                    @endforeach
                </select>
        <select aria-label="{{ __('inventory.all_warehouses') }}" wire:model.live="warehouseFilter" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-xs bg-white">
                    <option value="">{{ __('inventory.all_warehouses') }}</option>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ app()->getLocale() === 'en' ? ($w->name_en ?: $w->name_ar) : $w->name_ar }}</option>
                    @endforeach
                </select>
        <select aria-label="{{ __('inventory.all_movement_types') }}" wire:model.live="typeFilter" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-xs bg-white">
                    <option value="">{{ __('inventory.all_movement_types') }}</option>
                    @foreach ($movementTypes as $type)
                        <option value="{{ $type }}">{{ __('inventory.type_' . $type) }}</option>
                    @endforeach
                </select>
        <label class="grid gap-1 text-sm text-text-secondary">{{ __('inventory.date_from') }}<input aria-label="{{ __('inventory.date_from') }}" type="date" wire:model.live="fromDate" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-xs bg-white font-mono dir-ltr" /></label>
        <label class="grid gap-1 text-sm text-text-secondary">{{ __('inventory.date_to') }}<input aria-label="{{ __('inventory.date_to') }}" type="date" wire:model.live="toDate" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-xs bg-white font-mono dir-ltr" /></label>
    </x-refinement-filters>

    <!-- Ledger Table -->
    <x-card padding="p-0">
        <div class="hidden md:block overflow-x-auto">
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
                                <a href="{{ route('products.show', $m->product?->public_id) }}" class="touch-action hover:text-primary transition-colors">
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
<div class="md:hidden divide-y divide-border">
                    @forelse ($movements as $m)
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.movement_date') }}</dt>
                                <dd dir="ltr" class="break-words text-sm tabular-nums text-text-primary">{{ $m->movement_date }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.movement_type') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary"><x-badge variant="{{ $m->isInbound() ? 'success' : 'alert' }}">
                                    {{ __('inventory.type_' . $m->movement_type) }}
                                </x-badge></dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.product') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary"><a href="{{ route('products.show', $m->product?->public_id) }}" class="touch-action hover:text-primary transition-colors">
                                    {{ app()->getLocale() === 'en' ? ($m->product?->name_en ?: $m->product?->name_ar) : $m->product?->name_ar }}
                                </a></dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.warehouse') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ app()->getLocale() === 'en' ? ($m->warehouse?->name_en ?: $m->warehouse?->name_ar) : $m->warehouse?->name_ar }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.lot_number') }}</dt>
                                <dd dir="ltr" class="break-words text-sm tabular-nums text-text-primary">{{ $m->lot?->lot_number ?? ($m->lot_id ? '#' . $m->lot_id : '—') }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.quantity_delta') }}</dt>
                                <dd dir="ltr" class="break-words text-sm tabular-nums text-text-primary">{{ $m->isInbound() ? '+' : '' }}{{ $m->quantity_delta_base }}</dd>
                            </div>
                            @if ($canViewCost)
                                <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.unit_cost') }}</dt>
                                <dd dir="ltr" class="break-words text-sm tabular-nums text-text-primary">{{ $m->unit_cost_base }}</dd>
                            </div>
                                <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.value_delta') }}</dt>
                                <dd dir="ltr" class="break-words text-sm tabular-nums text-text-primary">{{ $m->value_delta_base }}</dd>
                            </div>
                                <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.average_cost_after') }}</dt>
                                <dd dir="ltr" class="break-words text-sm tabular-nums text-text-primary">{{ $m->average_cost_after }}</dd>
                            </div>
                            @endif
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.reason') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ $m->reason ?? '—' }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.actor') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ $m->user?->name ?? __('inventory.system') }}</dd>
                            </div>
                        </dl>
                        </article>
                    @empty
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="col-span-2 py-4 text-center text-sm text-text-secondary">
                                {{ __('inventory.no_movements_recorded') }}
                            </div>
                        </dl>
                        </article>
                    @endforelse
                </div>


        @if ($movements->hasPages())
            <div class="p-4 border-t border-border">
                {{ $movements->links() }}
            </div>
        @endif
    </x-card>
</div>
