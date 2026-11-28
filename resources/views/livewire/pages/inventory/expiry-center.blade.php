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
        <details class="rounded-control border border-border bg-surface p-3">
            <summary class="flex min-h-11 cursor-pointer items-center gap-2 text-sm font-semibold text-text-primary focus-visible:outline-2 focus-visible:outline-primary"><x-icon name="settings" class="h-4 w-4" />{{ __('inventory.warning_window') }}</summary>
        <form wire:submit="saveWarningDays" class="flex flex-col sm:flex-row sm:items-end gap-3 border border-border rounded-control p-4 bg-surface">
            <div class="flex-1">
                <label for="expiry-warning-days" class="block text-sm font-bold text-text-primary">{{ __('inventory.warning_window') }}</label>
                <p id="expiry-warning-hint" class="mt-1 text-sm text-text-secondary">{{ __('inventory.warning_hint') }}</p>
                <div class="mt-3 flex items-center gap-2">
                    <input inputmode="numeric" id="expiry-warning-days" type="number" min="1" max="365" wire:model="warningDays" aria-describedby="expiry-warning-hint @if ($errors->has('warningDays')) expiry-warning-error @endif" aria-invalid="{{ $errors->has('warningDays') ? 'true' : 'false' }}" dir="ltr" class="min-h-11 lg:min-h-9.5 w-24 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm font-mono tabular-nums focus-visible:outline-2 focus-visible:outline-primary" />
                    <span class="text-sm text-text-secondary">{{ __('inventory.days') }}</span>
                </div>
                @error('warningDays') <p role="alert" id="expiry-warning-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="saveWarningDays" class="touch-action min-h-11 lg:min-h-9.5 px-4 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover cursor-pointer">{{ __('inventory.save_warning') }}</button>
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


        <select aria-label="{{ __('inventory.filter_all_lots') }}" id="expiry-timeframe" wire:model.live="timeframeFilter" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-sm bg-surface">
                    <option value="all">{{ __('inventory.filter_all_lots') }}</option>
                    <option value="expired">{{ __('inventory.filter_expired_only') }}</option>
                    <option value="warning">{{ __('inventory.warning_filter', ['days' => $savedWarningDays]) }}</option>
                    <option value="7">{{ __('inventory.within_7_days') }}</option>
                    <option value="30">{{ __('inventory.filter_30_days') }}</option>
                    <option value="60">{{ __('inventory.filter_60_days') }}</option>
                    <option value="90">{{ __('inventory.filter_90_days') }}</option>
                    <option value="custom">{{ __('inventory.custom_range') }}</option>
                </select>
        <select aria-label="{{ __('inventory.all_products') }}" id="expiry-product" wire:model.live="productFilter" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-sm bg-surface">
                    <option value="">{{ __('inventory.all_products') }}</option>
                    @foreach ($products as $p)
                        <option value="{{ $p->id }}">{{ app()->getLocale() === 'en' && $p->name_en ? $p->name_en : $p->name_ar }}</option>
                    @endforeach
                </select>
        <select aria-label="{{ __('inventory.all_warehouses') }}" id="expiry-warehouse" wire:model.live="warehouseFilter" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-sm bg-surface">
                    <option value="">{{ __('inventory.all_warehouses') }}</option>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ app()->getLocale() === 'en' && $w->name_en ? $w->name_en : $w->name_ar }}</option>
                    @endforeach
                </select>
        @if($timeframeFilter === 'custom')<label class="grid gap-1 text-sm text-text-secondary">{{ __('inventory.date_from') }}<input aria-label="{{ __('inventory.date_from') }}" id="expiry-from-date" type="date" wire:model.live="customFromDate" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-sm bg-surface" /></label>@endif
        @if($timeframeFilter === 'custom')<label class="grid gap-1 text-sm text-text-secondary">{{ __('inventory.date_to') }}<input aria-label="{{ __('inventory.date_to') }}" id="expiry-to-date" type="date" wire:model.live="customToDate" class="min-h-11 w-full h-11 px-2 rounded-control border border-border text-sm bg-surface" /></label>@endif
    </x-refinement-filters>

    <!-- Lots Table -->
    <x-card padding="p-0">
        <div class="hidden md:block overflow-x-auto">
            <table class="data-table w-full text-xs text-right rtl:text-right ltr:text-left">
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
                                    {{ $lb->product?->name() }}
                                </a>
                                @if ($lb->product?->sku)
                                    <bdi dir="ltr" class="block text-xs font-mono text-text-muted">{{ $lb->product?->sku }}</bdi>
                                @endif
                            </td>
                            <td class="p-3.5 font-mono font-bold text-text-primary" dir="ltr">
                                {{ $lb->lot?->lot_number ?? __('inventory.lot_prefix') . $lb->lot_id }}
                            </td>
                            <td class="p-3.5 text-text-secondary">
                                {{ app()->getLocale() === 'en' && $lb->warehouse?->name_en ? $lb->warehouse->name_en : $lb->warehouse?->name_ar }}
                            </td>
                            <td class="p-3.5 font-mono text-text-secondary" dir="ltr">
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
                            <td class="p-3.5 text-center font-mono font-bold tabular-nums text-text-primary">
                                <bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($lb->quantity_base) }}</bdi>
                                <span class="text-xs text-text-muted font-arabic">{{ app()->getLocale() === 'en' && $lb->product?->baseUnit?->name_en ? $lb->product->baseUnit->name_en : $lb->product?->baseUnit?->name_ar }}</span>
                            </td>
                            @if ($canViewCost)
                                <td class="p-3.5 text-center font-mono tabular-nums text-text-secondary">
                                    <bdi dir="ltr">{{ $approxVal ?? '—' }}</bdi>
                                    <span class="text-xs text-text-muted">{{ $company->base_currency_code }}</span>
                                </td>
                            @endif
                            @if ($canAdjust)
                                <td class="table-actions p-3.5 text-center">
                                    @if ($isExpired)
                                    <button id="expiry-dispose-desktop-{{ $lb->id }}" wire:loading.attr="disabled" type="button" wire:click="openDisposalModal({{ $lb->id }})"
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
                    $whLabel = app()->getLocale() === 'en' && $lb->warehouse?->name_en ? $lb->warehouse->name_en : $lb->warehouse?->name_ar;
                    $unitLabel = app()->getLocale() === 'en' && $lb->product?->baseUnit?->name_en ? $lb->product->baseUnit->name_en : $lb->product?->baseUnit?->name_ar;
                @endphp
                <x-compact-mobile-row wire:key="expiry-mobile-{{ $lb->id }}">
                    <x-slot:primary>
                        <a href="{{ route('products.show', $lb->product?->public_id) }}" data-row-link class="touch-action font-bold text-text-primary hover:text-primary transition-colors truncate text-sm">
                            {{ $lb->product?->name() }}
                        </a>
                        <span class="shrink-0">
                            @if ($isExpired)
                                <x-badge variant="danger">{{ __('inventory.expired') }}</x-badge>
                            @elseif ($days !== null && $days <= $savedWarningDays)
                                <x-badge variant="warn">{{ $days }} {{ __('inventory.days') }}</x-badge>
                            @elseif ($days !== null)
                                <x-badge variant="success">{{ $days }} {{ __('inventory.days') }}</x-badge>
                            @endif
                        </span>
                    </x-slot:primary>

                    <x-slot:subtitle>
                        <bdi dir="ltr" class="font-mono">{{ $lb->lot?->lot_number ?? __('inventory.lot_prefix') . $lb->lot_id }}</bdi>
                        &middot; {{ $whLabel }}
                        &middot; <bdi dir="ltr" class="font-mono">{{ $lb->lot?->expiry_date?->toDateString() ?? '—' }}</bdi>
                    </x-slot:subtitle>

                    <x-slot:trailing>
                        <bdi dir="ltr" class="block font-mono font-bold text-sm tabular-nums text-text-primary">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($lb->quantity_base) }}</bdi>
                        <span class="block text-xs text-text-muted">{{ $unitLabel }}</span>
                    </x-slot:trailing>

                    <x-slot:details>
                        <dl class="grid grid-cols-2 gap-2">
                            <div>
                                <dt class="text-text-secondary">{{ __('inventory.lot_number') }}</dt>
                                <dd class="mt-0.5 font-mono text-text-primary" dir="ltr">{{ $lb->lot?->lot_number ?? __('inventory.lot_prefix') . $lb->lot_id }}</dd>
                            </div>
                            <div>
                                <dt class="text-text-secondary">{{ __('inventory.warehouse') }}</dt>
                                <dd class="mt-0.5 text-text-primary">{{ $whLabel }}</dd>
                            </div>
                            <div>
                                <dt class="text-text-secondary">{{ __('inventory.expiry_date') }}</dt>
                                <dd class="mt-0.5 font-mono text-text-primary" dir="ltr"><bdi dir="ltr">{{ $lb->lot?->expiry_date?->toDateString() ?? '—' }}</bdi></dd>
                            </div>
                            @if ($canViewCost)
                                <div>
                                    <dt class="text-text-secondary">{{ __('inventory.approximate_valuation') }}</dt>
                                    <dd class="mt-0.5 font-mono text-text-primary" dir="ltr">
                                        {{ $approxVal ?? '—' }} <span class="text-xs text-text-muted">{{ $company->base_currency_code }}</span>
                                    </dd>
                                </div>
                            @endif
                        </dl>
                        @if ($canAdjust && $isExpired)
                            <div class="flex justify-end">
                                <button id="expiry-dispose-mobile-{{ $lb->id }}" wire:loading.attr="disabled" type="button" wire:click="openDisposalModal({{ $lb->id }})"
                                        class="touch-action bg-danger-bg hover:bg-danger/20 text-danger font-bold text-xs transition-colors">
                                    {{ __('inventory.dispose_expired') }}
                                </button>
                            </div>
                        @endif
                    </x-slot:details>
                </x-compact-mobile-row>
            @empty
                <p class="p-8 text-center text-sm text-text-secondary">{{ __('inventory.no_matching_lots') }}</p>
            @endforelse
        </div>

        <div class="p-4"><x-table-pagination :paginator="$lotBalances" /></div>
    </x-card>

    <!-- Quick Disposal Modal -->
    @if ($showDisposalModal && $disposingLotBalance)
        <x-dialog-shell :title="__('inventory.confirm_disposal')" title-id="expiry-disposal-title" close-action="$set('showDisposalModal', false)" save-target="confirmDisposal" max-width="max-w-md">
            <div class="space-y-3 text-sm">
                <div class="p-3 rounded-control bg-surface-soft border border-border space-y-1 text-xs">
                    <div><span class="text-text-muted">{{ __('inventory.product') }}:</span> <span class="font-bold text-text-primary">{{ $disposingLotBalance->product?->name() }}</span></div>
                    <div><span class="text-text-muted">{{ __('inventory.warehouse') }}:</span> <span class="text-text-secondary">{{ app()->getLocale() === 'en' && $disposingLotBalance->warehouse?->name_en ? $disposingLotBalance->warehouse->name_en : $disposingLotBalance->warehouse?->name_ar }}</span></div>
                    <div><span class="text-text-muted">{{ __('inventory.lot_number') }}:</span> <bdi dir="ltr" class="font-mono">{{ $disposingLotBalance->lot?->lot_number }}</bdi></div>
                    <div><span class="text-text-muted">{{ __('inventory.current_stock') }}:</span> <bdi dir="ltr" class="font-mono font-bold">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($disposingLotBalance->quantity_base) }}</bdi> <span class="font-bold">{{ app()->getLocale() === 'en' && $disposingLotBalance->product?->baseUnit?->name_en ? $disposingLotBalance->product->baseUnit->name_en : $disposingLotBalance->product?->baseUnit?->name_ar }}</span></div>
                </div>

                <div>
                    <label for="expiry-disposal-quantity" class="block font-bold text-text-secondary mb-1">{{ __('inventory.disposal_quantity') }} *</label>
                    <input aria-invalid="{{ $errors->has('disposalQuantity') ? 'true' : 'false' }}" @if ($errors->has('disposalQuantity')) aria-describedby="expiry-disposal-quantity-error" @endif id="expiry-disposal-quantity" type="text" inputmode="decimal" wire:model="disposalQuantity" dir="ltr" class="w-full h-11 px-2.5 rounded-control border border-border text-sm font-mono" />
                    @error('disposalQuantity') <span role="alert" id="expiry-disposal-quantity-error" class="text-danger text-xs">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="expiry-disposal-date" class="block font-bold text-text-secondary mb-1">{{ __('inventory.disposal_date') }} *</label>
                    <input aria-invalid="{{ $errors->has('disposalDate') ? 'true' : 'false' }}" @if ($errors->has('disposalDate')) aria-describedby="expiry-disposal-date-error" @endif id="expiry-disposal-date" type="date" wire:model="disposalDate" dir="ltr" class="w-full h-11 px-2.5 rounded-control border border-border text-sm font-mono" />
                    @error('disposalDate') <span role="alert" id="expiry-disposal-date-error" class="text-danger text-xs">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="expiry-disposal-reason" class="block font-bold text-text-secondary mb-1">{{ __('inventory.disposal_reason') }} *</label>
                    <textarea aria-invalid="{{ $errors->has('disposalReason') ? 'true' : 'false' }}" @if ($errors->has('disposalReason')) aria-describedby="expiry-disposal-reason-error" @endif id="expiry-disposal-reason" wire:model="disposalReason" rows="2" class="w-full p-2.5 rounded-control border border-border text-sm focus:outline-none focus:border-primary @error('disposalReason') border-danger @enderror"></textarea>
                    @error('disposalReason') <span role="alert" id="expiry-disposal-reason-error" class="text-danger text-xs">{{ $message }}</span> @enderror
                </div>
            </div>
            <x-slot:footer>
                <x-secondary-button type="button" wire:click="$set('showDisposalModal', false)" wire:loading.attr="disabled">
                    {{ __('common.cancel') }}
                </x-secondary-button>
                <x-danger-button type="button" wire:click="confirmDisposal" wire:loading.attr="disabled" wire:target="confirmDisposal">
                    {{ __('inventory.confirm_disposal_record_loss') }}
                </x-danger-button>
            </x-slot:footer>
        </x-dialog-shell>
    @endif
</div>
