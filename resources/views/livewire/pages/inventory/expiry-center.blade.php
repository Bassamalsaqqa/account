<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-extrabold text-text-primary flex items-center gap-2.5">
                <x-icon name="expiry" class="w-6 h-6 text-primary" />
                <span>{{ __('inventory.expiry_center') }}</span>
            </h1>
            <p class="text-xs text-text-secondary mt-1">{{ __('inventory.expiry_subtitle') }}</p>
        </div>
    </div>

    <!-- Messages -->
    @if ($successMessage)
        <div role="status" class="p-3.5 rounded-control bg-success-bg border border-success/30 text-success text-xs font-semibold flex items-center justify-between">
            <span>{{ $successMessage }}</span>
            <button type="button" aria-label="{{ __('inventory.close') }}" wire:click="$set('successMessage', null)" class="text-success hover:opacity-75">&times;</button>
        </div>
    @endif
    @if ($errorMessage)
        <div role="alert" class="p-3.5 rounded-control bg-danger-bg border border-danger/30 text-danger text-xs font-semibold flex items-center justify-between">
            <span>{{ $errorMessage }}</span>
            <button type="button" aria-label="{{ __('inventory.close') }}" wire:click="$set('errorMessage', null)" class="text-danger hover:opacity-75">&times;</button>
        </div>
    @endif

    <!-- Filters Bar -->
    <x-card padding="p-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
            <!-- Timeframe Filter -->
            <div>
                <label for="expiry-timeframe" class="block text-[11px] font-bold text-text-secondary mb-1">{{ __('inventory.filter_timeframe') }}</label>
                <select id="expiry-timeframe" wire:model.live="timeframeFilter" class="w-full h-8 px-2 rounded-control border border-border text-xs bg-white">
                    <option value="all">{{ __('inventory.filter_all_lots') }}</option>
                    <option value="expired">{{ __('inventory.filter_expired_only') }}</option>
                    <option value="7">{{ __('inventory.within_7_days') }}</option>
                    <option value="30">{{ __('inventory.filter_30_days') }}</option>
                    <option value="60">{{ __('inventory.filter_60_days') }}</option>
                    <option value="90">{{ __('inventory.filter_90_days') }}</option>
                    <option value="custom">{{ __('inventory.custom_range') }}</option>
                </select>
            </div>

            @if ($timeframeFilter === 'custom')
                <div>
                    <label for="expiry-from-date" class="block text-[11px] font-bold text-text-secondary mb-1">{{ __('inventory.from_date') }}</label>
                    <input id="expiry-from-date" type="date" wire:model.live="customFromDate" class="w-full h-8 px-2 rounded-control border border-border text-xs bg-white" />
                </div>
                <div>
                    <label for="expiry-to-date" class="block text-[11px] font-bold text-text-secondary mb-1">{{ __('inventory.to_date') }}</label>
                    <input id="expiry-to-date" type="date" wire:model.live="customToDate" class="w-full h-8 px-2 rounded-control border border-border text-xs bg-white" />
                </div>
            @endif

            <!-- Product Filter -->
            <div>
                <label for="expiry-product" class="block text-[11px] font-bold text-text-secondary mb-1">{{ __('inventory.product') }}</label>
                <select id="expiry-product" wire:model.live="productFilter" class="w-full h-8 px-2 rounded-control border border-border text-xs bg-white">
                    <option value="">{{ __('inventory.all_products') }}</option>
                    @foreach ($products as $p)
                        <option value="{{ $p->id }}">{{ app()->getLocale() === 'en' && $p->name_en ? $p->name_en : $p->name_ar }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Warehouse Filter -->
            <div>
                <label for="expiry-warehouse" class="block text-[11px] font-bold text-text-secondary mb-1">{{ __('inventory.warehouse') }}</label>
                <select id="expiry-warehouse" wire:model.live="warehouseFilter" class="w-full h-8 px-2 rounded-control border border-border text-xs bg-white">
                    <option value="">{{ __('inventory.all_warehouses') }}</option>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ app()->getLocale() === 'en' && $w->name_en ? $w->name_en : $w->name_ar }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </x-card>

    <!-- Lots Table -->
    <x-card padding="p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-xs text-right rtl:text-right ltr:text-left">
                <thead class="bg-surface-soft border-b border-border text-text-secondary font-bold select-none">
                    <tr>
                        <th class="p-3.5">{{ __('inventory.product') }}</th>
                        <th class="p-3.5">{{ __('inventory.lot_number') }}</th>
                        <th class="p-3.5">{{ __('inventory.warehouse') }}</th>
                        <th class="p-3.5">{{ __('inventory.expiry_date') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.days_remaining') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.current_stock') }}</th>
                        @if ($canViewCost)
                            <th class="p-3.5 text-center">{{ __('inventory.approximate_valuation') }}</th>
                        @endif
                        @if ($canAdjust)
                            <th class="p-3.5 text-center w-24"></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($lotBalances as $lb)
                        @php
                            $days = $lb->lot ? $lb->lot->daysUntilExpiry() : null;
                            $isExpired = $lb->lot && $lb->lot->isExpired();
                            $approxVal = $canViewCost ? ($lotValuations[$lb->id] ?? null) : null;
                        @endphp
                        <tr class="hover:bg-surface-soft/60 transition-colors {{ $isExpired ? 'bg-danger-bg/20' : '' }}">
                            <td class="p-3.5 font-bold text-text-primary">
                                <a href="{{ route('products.show', $lb->product?->public_id) }}" class="hover:text-primary transition-colors">
                                    {{ app()->getLocale() === 'en' && $lb->product?->name_en ? $lb->product->name_en : $lb->product?->name_ar }}
                                </a>
                                @if ($lb->product?->sku)
                                    <span class="block text-[11px] font-mono text-text-muted dir-ltr">{{ $lb->product?->sku }}</span>
                                @endif
                            </td>
                            <td class="p-3.5 font-mono dir-ltr font-bold text-text-primary">
                                {{ $lb->lot?->lot_number ?? __('inventory.lot_prefix') . $lb->lot_id }}
                            </td>
                            <td class="p-3.5 text-text-secondary">
                                {{ app()->getLocale() === 'en' && $lb->warehouse?->name_en ? $lb->warehouse->name_en : $lb->warehouse?->name_ar }}
                            </td>
                            <td class="p-3.5 font-mono dir-ltr text-text-secondary">
                                {{ $lb->lot?->expiry_date ?? '—' }}
                            </td>
                            <td class="p-3.5 text-center">
                                @if ($isExpired)
                                    <x-badge variant="danger">{{ __('inventory.expired') }}</x-badge>
                                @elseif ($days !== null && $days <= 7)
                                    <x-badge variant="danger">{{ $days }} {{ __('inventory.days') }}</x-badge>
                                @elseif ($days !== null && $days <= 30)
                                    <x-badge variant="alert">{{ $days }} {{ __('inventory.days') }}</x-badge>
                                @elseif ($days !== null && $days <= 90)
                                    <x-badge variant="warn">{{ $days }} {{ __('inventory.days') }}</x-badge>
                                @elseif ($days !== null)
                                    <x-badge variant="success">{{ $days }} {{ __('inventory.days') }}</x-badge>
                                @else
                                    <span class="text-text-muted">—</span>
                                @endif
                            </td>
                            <td class="p-3.5 text-center font-mono font-bold tabular-nums dir-ltr text-text-primary">
                                {{ $lb->quantity_base }}
                                <span class="text-[10px] text-text-muted font-arabic">{{ app()->getLocale() === 'en' && $lb->product?->baseUnit?->name_en ? $lb->product->baseUnit->name_en : $lb->product?->baseUnit?->name_ar }}</span>
                            </td>
                            @if ($canViewCost)
                                <td class="p-3.5 text-center font-mono tabular-nums dir-ltr text-text-secondary">
                                    {{ $approxVal ?? '—' }}
                                    <span class="text-[10px] text-text-muted">{{ $company->base_currency_code }}</span>
                                </td>
                            @endif
                            @if ($canAdjust)
                                <td class="p-3.5 text-center">
                                    <button type="button" wire:click="openDisposalModal({{ $lb->id }})"
                                            class="px-2.5 py-1 rounded-control bg-danger-bg hover:bg-danger/20 text-danger font-bold text-xs transition-colors">
                                        {{ __('inventory.dispose_expired') }}
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canViewCost ? 8 : 7 }}" class="p-8 text-center text-text-muted">
                                {{ __('inventory.no_matching_lots') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <!-- Quick Disposal Modal -->
    @if ($showDisposalModal && $disposingLotBalance)
        <div x-data x-trap.inert.noscroll="true" @keydown.escape.window="$wire.set('showDisposalModal', false)" role="dialog" aria-modal="true" aria-labelledby="expiry-disposal-title" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-xs p-4">
            <div class="bg-white rounded-card border border-border shadow-xl w-full max-w-md p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <h3 id="expiry-disposal-title" class="font-extrabold text-sm text-text-primary flex items-center gap-2">
                        <x-icon name="alert" class="w-4 h-4 text-danger" />
                        <span>{{ __('inventory.confirm_disposal') }}</span>
                    </h3>
                    <button type="button" aria-label="{{ __('inventory.close') }}" wire:click="$set('showDisposalModal', false)" class="text-text-muted hover:text-text-primary text-base font-bold">&times;</button>
                </div>

                <div class="space-y-3 text-xs">
                    <div class="p-3 rounded-control bg-surface-soft border border-border space-y-1">
                        <div><span class="text-text-muted">{{ __('inventory.product') }}:</span> <span class="font-bold text-text-primary">{{ app()->getLocale() === 'en' && $disposingLotBalance->product?->name_en ? $disposingLotBalance->product->name_en : $disposingLotBalance->product?->name_ar }}</span></div>
                        <div><span class="text-text-muted">{{ __('inventory.warehouse') }}:</span> <span class="text-text-secondary">{{ app()->getLocale() === 'en' && $disposingLotBalance->warehouse?->name_en ? $disposingLotBalance->warehouse->name_en : $disposingLotBalance->warehouse?->name_ar }}</span></div>
                        <div><span class="text-text-muted">{{ __('inventory.lot_number') }}:</span> <span class="font-mono dir-ltr">{{ $disposingLotBalance->lot?->lot_number }}</span></div>
                        <div><span class="text-text-muted">{{ __('inventory.current_stock') }}:</span> <span class="font-mono font-bold">{{ $disposingLotBalance->quantity_base }} {{ app()->getLocale() === 'en' && $disposingLotBalance->product?->baseUnit?->name_en ? $disposingLotBalance->product->baseUnit->name_en : $disposingLotBalance->product?->baseUnit?->name_ar }}</span></div>
                    </div>

                    <div>
                        <label for="expiry-disposal-quantity" class="block font-bold text-text-secondary mb-1">{{ __('inventory.disposal_quantity') }} *</label>
                        <input aria-invalid="{{ $errors->has('disposalQuantity') ? 'true' : 'false' }}" @if ($errors->has('disposalQuantity')) aria-describedby="expiry-disposal-quantity-error" @endif id="expiry-disposal-quantity" type="text" wire:model="disposalQuantity" dir="ltr" class="w-full h-8 px-2.5 rounded-control border border-border text-xs font-mono" />
                        @error('disposalQuantity') <span role="alert" id="expiry-disposal-quantity-error" class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="expiry-disposal-date" class="block font-bold text-text-secondary mb-1">{{ __('inventory.disposal_date') }} *</label>
                        <input aria-invalid="{{ $errors->has('disposalDate') ? 'true' : 'false' }}" @if ($errors->has('disposalDate')) aria-describedby="expiry-disposal-date-error" @endif id="expiry-disposal-date" type="date" wire:model="disposalDate" dir="ltr" class="w-full h-8 px-2.5 rounded-control border border-border text-xs font-mono" />
                        @error('disposalDate') <span role="alert" id="expiry-disposal-date-error" class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="expiry-disposal-reason" class="block font-bold text-text-secondary mb-1">{{ __('inventory.disposal_reason') }} *</label>
                        <textarea aria-invalid="{{ $errors->has('disposalReason') ? 'true' : 'false' }}" @if ($errors->has('disposalReason')) aria-describedby="expiry-disposal-reason-error" @endif id="expiry-disposal-reason" wire:model="disposalReason" rows="2" dir="rtl" class="w-full p-2.5 rounded-control border border-border text-xs focus:outline-none focus:border-primary @error('disposalReason') border-danger @enderror"></textarea>
                        @error('disposalReason') <span role="alert" id="expiry-disposal-reason-error" class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <button type="button" wire:click="$set('showDisposalModal', false)"
                            class="px-3.5 py-1.5 rounded-control border border-border text-text-secondary hover:bg-surface-soft text-xs font-semibold">
                        {{ __('common.cancel') }}
                    </button>
                    <button type="button" wire:click="confirmDisposal"
                            class="px-4 py-1.5 rounded-control bg-danger text-white hover:bg-danger/90 text-xs font-bold shadow-button">
                        {{ __('inventory.confirm_disposal_record_loss') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
