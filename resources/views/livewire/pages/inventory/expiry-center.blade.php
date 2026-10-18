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

    @if ($canManageWarning)
        <details class="rounded-control border border-border bg-white p-3">
            <summary class="flex min-h-11 cursor-pointer items-center gap-2 text-sm font-semibold text-text-primary focus-visible:outline-2 focus-visible:outline-primary"><x-icon name="settings" class="h-4 w-4" />{{ __('inventory.warning_window') }}</summary>
        <form wire:submit="saveWarningDays" class="flex flex-col sm:flex-row sm:items-end gap-3 border border-border rounded-control p-4 bg-white">
            <div class="flex-1">
                <label for="expiry-warning-days" class="block text-sm font-bold text-text-primary">{{ __('inventory.warning_window') }}</label>
                <p id="expiry-warning-hint" class="mt-1 text-sm text-text-secondary">{{ __('inventory.warning_hint') }}</p>
                <div class="mt-3 flex items-center gap-2">
                    <input inputmode="numeric" id="expiry-warning-days" type="number" min="1" max="365" wire:model="warningDays" aria-describedby="expiry-warning-hint @if ($errors->has('warningDays')) expiry-warning-error @endif" aria-invalid="{{ $errors->has('warningDays') ? 'true' : 'false' }}" dir="ltr" class="min-h-11 h-11 w-24 px-3 rounded-control border border-border text-sm focus-visible:outline-2 focus-visible:outline-primary" />
                    <span class="text-sm text-text-secondary">{{ __('inventory.days') }}</span>
                </div>
                @error('warningDays') <p role="alert" id="expiry-warning-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="saveWarningDays" class="touch-action h-11 bg-primary text-white text-sm font-bold">{{ __('inventory.save_warning') }}</button>
        </form>
        </details>
    @endif

    <!-- Messages -->
    @if ($successMessage)
        <div role="status" class="p-3.5 rounded-control bg-success-bg border border-success/30 text-success text-xs font-semibold flex items-center justify-between">
            <span>{{ $successMessage }}</span>
            <button wire:loading.attr="disabled" type="button" aria-label="{{ __('inventory.close') }}" wire:click="$set('successMessage', null)" class="touch-action text-success hover:opacity-75">&times;</button>
        </div>
    @endif
    @if ($errorMessage)
        <div role="alert" class="p-3.5 rounded-control bg-danger-bg border border-danger/30 text-danger text-xs font-semibold flex items-center justify-between">
            <span>{{ $errorMessage }}</span>
            <button wire:loading.attr="disabled" type="button" aria-label="{{ __('inventory.close') }}" wire:click="$set('errorMessage', null)" class="touch-action text-danger hover:opacity-75">&times;</button>
        </div>
    @endif

    <!-- Filters Bar -->
    <x-refinement-filters :active-count="(int) ($timeframeFilter !== 'all') + (int) filled($productFilter) + (int) filled($warehouseFilter)">


        <select aria-label="{{ __('inventory.filter_all_lots') }}" id="expiry-timeframe" wire:model.live="timeframeFilter" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-xs bg-white">
                    <option value="all">{{ __('inventory.filter_all_lots') }}</option>
                    <option value="expired">{{ __('inventory.filter_expired_only') }}</option>
                    <option value="warning">{{ __('inventory.warning_filter', ['days' => $savedWarningDays]) }}</option>
                    <option value="7">{{ __('inventory.within_7_days') }}</option>
                    <option value="30">{{ __('inventory.filter_30_days') }}</option>
                    <option value="60">{{ __('inventory.filter_60_days') }}</option>
                    <option value="90">{{ __('inventory.filter_90_days') }}</option>
                    <option value="custom">{{ __('inventory.custom_range') }}</option>
                </select>
        <select aria-label="{{ __('inventory.all_products') }}" id="expiry-product" wire:model.live="productFilter" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-xs bg-white">
                    <option value="">{{ __('inventory.all_products') }}</option>
                    @foreach ($products as $p)
                        <option value="{{ $p->id }}">{{ app()->getLocale() === 'en' && $p->name_en ? $p->name_en : $p->name_ar }}</option>
                    @endforeach
                </select>
        <select aria-label="{{ __('inventory.all_warehouses') }}" id="expiry-warehouse" wire:model.live="warehouseFilter" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-xs bg-white">
                    <option value="">{{ __('inventory.all_warehouses') }}</option>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ app()->getLocale() === 'en' && $w->name_en ? $w->name_en : $w->name_ar }}</option>
                    @endforeach
                </select>
        @if($timeframeFilter === 'custom')<label class="grid gap-1 text-sm text-text-secondary">{{ __('inventory.date_from') }}<input aria-label="{{ __('inventory.date_from') }}" id="expiry-from-date" type="date" wire:model.live="customFromDate" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-xs bg-white" /></label>@endif
        @if($timeframeFilter === 'custom')<label class="grid gap-1 text-sm text-text-secondary">{{ __('inventory.date_to') }}<input aria-label="{{ __('inventory.date_to') }}" id="expiry-to-date" type="date" wire:model.live="customToDate" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-xs bg-white" /></label>@endif
    </x-refinement-filters>

    <!-- Lots Table -->
    <x-card padding="p-0">
        <div class="hidden md:block overflow-x-auto">
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
                            $days = $lb->lot ? $lb->lot->daysUntilExpiry($expiryReferenceDate->copy()) : null;
                            $isExpired = $lb->lot && $lb->lot->isExpired($expiryReferenceDate->copy());
                            $approxVal = $canViewCost ? ($lotValuations[$lb->id] ?? null) : null;
                        @endphp
                        <tr class="hover:bg-surface-soft/60 transition-colors {{ $isExpired ? 'bg-danger-bg/20' : '' }}">
                            <td class="p-3.5 font-bold text-text-primary">
                                <a href="{{ route('products.show', $lb->product?->public_id) }}" class="touch-action hover:text-primary transition-colors">
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
                                <bdi dir="ltr">{{ $lb->lot?->expiry_date?->toDateString() ?? '—' }}</bdi>
                            </td>
                            <td class="p-3.5 text-center">
                                @if ($isExpired)
                                    <x-badge variant="danger">{{ __('inventory.expired') }}</x-badge>
                                @elseif ($days !== null && $days <= $savedWarningDays)
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
                                    @if ($isExpired)
                                    <button wire:loading.attr="disabled" type="button" wire:click="openDisposalModal({{ $lb->id }})"
                                            class="touch-action bg-danger-bg hover:bg-danger/20 text-danger font-bold text-xs transition-colors">
                                        {{ __('inventory.dispose_expired') }}
                                    </button>
                                    @else
                                        <span class="text-text-muted">—</span>
                                    @endif
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
<div class="md:hidden divide-y divide-border">
                    @forelse ($lotBalances as $lb)
                        @php
                            $days = $lb->lot ? $lb->lot->daysUntilExpiry($expiryReferenceDate->copy()) : null;
                            $isExpired = $lb->lot && $lb->lot->isExpired($expiryReferenceDate->copy());
                            $approxVal = $canViewCost ? ($lotValuations[$lb->id] ?? null) : null;
                        @endphp
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.product') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary"><a href="{{ route('products.show', $lb->product?->public_id) }}" class="touch-action hover:text-primary transition-colors">
                                    {{ app()->getLocale() === 'en' && $lb->product?->name_en ? $lb->product->name_en : $lb->product?->name_ar }}
                                </a>
                                @if ($lb->product?->sku)
                                    <span class="block text-[11px] font-mono text-text-muted dir-ltr">{{ $lb->product?->sku }}</span>
                                @endif</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.lot_number') }}</dt>
                                <dd dir="ltr" class="break-words text-sm tabular-nums text-text-primary">{{ $lb->lot?->lot_number ?? __('inventory.lot_prefix') . $lb->lot_id }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.warehouse') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ app()->getLocale() === 'en' && $lb->warehouse?->name_en ? $lb->warehouse->name_en : $lb->warehouse?->name_ar }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.expiry_date') }}</dt>
                                <dd dir="ltr" class="break-words text-sm tabular-nums text-text-primary"><bdi dir="ltr">{{ $lb->lot?->expiry_date?->toDateString() ?? '—' }}</bdi></dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.days_remaining') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">@if ($isExpired)
                                    <x-badge variant="danger">{{ __('inventory.expired') }}</x-badge>
                                @elseif ($days !== null && $days <= $savedWarningDays)
                                    <x-badge variant="warn">{{ $days }} {{ __('inventory.days') }}</x-badge>
                                @elseif ($days !== null)
                                    <x-badge variant="success">{{ $days }} {{ __('inventory.days') }}</x-badge>
                                @else
                                    <span class="text-text-muted">—</span>
                                @endif</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.current_stock') }}</dt>
                                <dd dir="ltr" class="break-words text-sm tabular-nums text-text-primary">{{ $lb->quantity_base }}
                                <span class="text-[10px] text-text-muted font-arabic">{{ app()->getLocale() === 'en' && $lb->product?->baseUnit?->name_en ? $lb->product->baseUnit->name_en : $lb->product?->baseUnit?->name_ar }}</span></dd>
                            </div>
                            @if ($canViewCost)
                                <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.approximate_valuation') }}</dt>
                                <dd dir="ltr" class="break-words text-sm tabular-nums text-text-primary">{{ $approxVal ?? '—' }}
                                    <span class="text-[10px] text-text-muted">{{ $company->base_currency_code }}</span></dd>
                            </div>
                            @endif
                            @if ($canAdjust)
                                <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('refinement_lists.actions') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">@if ($isExpired)
                                    <button wire:loading.attr="disabled" type="button" wire:click="openDisposalModal({{ $lb->id }})"
                                            class="touch-action bg-danger-bg hover:bg-danger/20 text-danger font-bold text-xs transition-colors">
                                        {{ __('inventory.dispose_expired') }}
                                    </button>
                                    @else
                                        <span class="text-text-muted">—</span>
                                    @endif</dd>
                            </div>
                            @endif
                        </dl>
                        </article>
                    @empty
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="col-span-2 py-4 text-center text-sm text-text-secondary">
                                {{ __('inventory.no_matching_lots') }}
                            </div>
                        </dl>
                        </article>
                    @endforelse
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
                    <button wire:loading.attr="disabled" type="button" aria-label="{{ __('inventory.close') }}" wire:click="$set('showDisposalModal', false)" class="touch-action text-text-muted hover:text-text-primary text-base font-bold">&times;</button>
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
                        <input aria-invalid="{{ $errors->has('disposalQuantity') ? 'true' : 'false' }}" @if ($errors->has('disposalQuantity')) aria-describedby="expiry-disposal-quantity-error" @endif id="expiry-disposal-quantity" type="text" inputmode="decimal" wire:model="disposalQuantity" dir="ltr" class="w-full h-11 px-2.5 rounded-control border border-border text-xs font-mono" />
                        @error('disposalQuantity') <span role="alert" id="expiry-disposal-quantity-error" class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="expiry-disposal-date" class="block font-bold text-text-secondary mb-1">{{ __('inventory.disposal_date') }} *</label>
                        <input aria-invalid="{{ $errors->has('disposalDate') ? 'true' : 'false' }}" @if ($errors->has('disposalDate')) aria-describedby="expiry-disposal-date-error" @endif id="expiry-disposal-date" type="date" wire:model="disposalDate" dir="ltr" class="w-full h-11 px-2.5 rounded-control border border-border text-xs font-mono" />
                        @error('disposalDate') <span role="alert" id="expiry-disposal-date-error" class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="expiry-disposal-reason" class="block font-bold text-text-secondary mb-1">{{ __('inventory.disposal_reason') }} *</label>
                        <textarea aria-invalid="{{ $errors->has('disposalReason') ? 'true' : 'false' }}" @if ($errors->has('disposalReason')) aria-describedby="expiry-disposal-reason-error" @endif id="expiry-disposal-reason" wire:model="disposalReason" rows="2" dir="rtl" class="w-full p-2.5 rounded-control border border-border text-xs focus:outline-none focus:border-primary @error('disposalReason') border-danger @enderror"></textarea>
                        @error('disposalReason') <span role="alert" id="expiry-disposal-reason-error" class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <button wire:loading.attr="disabled" type="button" wire:click="$set('showDisposalModal', false)"
                            class="touch-action border border-border text-text-secondary hover:bg-surface-soft text-xs font-semibold">
                        {{ __('common.cancel') }}
                    </button>
                    <button wire:loading.attr="disabled" type="button" wire:click="confirmDisposal"
                            class="touch-action bg-danger text-white hover:bg-danger/90 text-xs font-bold shadow-button">
                        {{ __('inventory.confirm_disposal_record_loss') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
