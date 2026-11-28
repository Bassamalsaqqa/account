<div class="space-y-6">
    @error('deleteDraft') <p role="alert" class="text-danger text-sm">{{ $message }}</p> @enderror

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">{{ __('purchasing.purchases') }}</h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('purchasing.purchases') }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($purchases->total()) }})
            </p>
        </div>
        @if($canCreate)
            <div class="flex items-center gap-2">
                <a href="{{ route('purchases.create') }}" class="touch-action gap-1.5 bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors shadow-sm">
                    <x-icon name="plus" class="w-4 h-4" />
                    <span>{{ __('purchasing.new_purchase') }}</span>
                </a>
            </div>
        @endif
    </div>
    <x-refinement-filters :active-count="(int) ($statusFilter !== 'all') + (int) filled($vendorFilter)">
        <x-slot:search>
            <input type="search" wire:model.live.debounce.300ms="search" aria-label="{{ __('purchasing.search_purchases') }}" placeholder="{{ __('purchasing.search_purchases') }}" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary" />
        </x-slot:search>
        <x-slot:statuses>
        <button type="button" wire:click="$set('statusFilter', 'all')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'all' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'all' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('purchasing.all_statuses') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'draft')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'draft' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'draft' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('purchasing.draft') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'posted')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'posted' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'posted' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('purchasing.posted') }}</button>
        </x-slot:statuses>
        <select wire:model.live="vendorFilter" aria-label="{{ __('purchasing.vendor') }}" class="min-h-11 h-11 min-w-0 px-3 rounded-control border border-border text-sm"><option value="">{{ __('purchasing.all_vendors') }}</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}">{{ $vendor->displayName() }}</option>@endforeach</select>
        <select wire:model.live="statusFilter" aria-label="{{ __('purchasing.status') }}" class="min-h-11 h-11 px-3 rounded-control border border-border text-sm"><option value="all">{{ __('purchasing.all_statuses') }}</option><option value="draft">{{ __('purchasing.draft') }}</option><option value="posted">{{ __('purchasing.posted') }}</option></select>
    </x-refinement-filters>
    @if(session()->has('success'))<p role="status" class="text-sm text-success font-semibold bg-success-bg p-3 rounded-control border border-success/20">{{ session('success') }}</p>@endif
    @if(session()->has('error'))<p role="alert" class="text-sm text-danger font-semibold bg-danger-bg p-3 rounded-control border border-danger/20">{{ session('error') }}</p>@endif
    @error('postPurchase') <p role="alert" class="text-sm text-danger font-semibold bg-danger-bg p-3 rounded-control border border-danger/20">{{ $message }}</p> @enderror

    <div class="bg-surface rounded-card border border-border shadow-xs overflow-hidden">
        <div class="hidden lg:block overflow-x-auto">
            <table class="data-table w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-xs uppercase tracking-wider">
                        <th class="py-3 px-4 text-start">{{ __('purchasing.purchase_number') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.vendor') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.purchase_date') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.vendor_invoice_number') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.status') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.payment_status') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.currency') }}</th>
                        @if($withCost)<th class="py-3 px-4 text-start">{{ __('purchasing.total') }}</th>@endif
                        <th class="py-3 px-4 text-end">{{ __('sales.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
            @forelse($purchases as $purchase)
                <tr wire:key="purchase-{{ $purchase->public_id }}" class="hover:bg-surface-soft/60 transition-colors">
                    <td class="py-3 px-4 font-mono font-bold" dir="ltr">
                        <a href="{{ route('purchases.show', $purchase->public_id) }}" class="touch-action text-primary hover:underline">
                            {{ $purchase->purchase_number ?? __('purchasing.draft') }}
                        </a>
                    </td>
                    <td class="py-3 px-4">
                        @if($canViewVendor && $purchase->vendor?->public_id)
                            <a class="touch-action font-bold text-text-primary hover:text-primary transition-colors" href="{{ route('vendors.show', $purchase->vendor->public_id) }}">
                                {{ $purchase->vendor->displayName() }}
                            </a>
                        @else
                            <span class="font-bold text-text-primary">{{ $purchase->vendor?->displayName() ?? '—' }}</span>
                        @endif
                    </td>
                    <td class="py-3 px-4" dir="ltr">
                        {{ $purchase->purchase_date->format('Y-m-d') }}
                    </td>
                    <td class="py-3 px-4 font-mono text-text-secondary" dir="ltr">
                        {{ $purchase->vendor_invoice_number ?? '—' }}
                    </td>
                    <td class="py-3 px-4">
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold @if($purchase->status === 'posted') bg-success-bg text-success @elseif($purchase->status === 'void') bg-danger-bg text-danger @else bg-slate-100 text-slate-600 @endif">
                            {{ __('purchasing.'.$purchase->status) }}
                        </span>
                    </td>
                    <td class="py-3 px-4">
                        @if($withCost && $purchase->isPosted() && isset($payablePositions[$purchase->id]))
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-bold {{ $payablePositions[$purchase->id]->status === 'settled' ? 'bg-success-bg text-success' : ($payablePositions[$purchase->id]->status === 'partially_paid' ? 'bg-warning-bg text-warning' : ($payablePositions[$purchase->id]->status === 'credit' ? 'bg-info-bg text-info' : 'bg-surface-soft text-text-secondary')) }}">
                                {{ __('purchasing.'.$payablePositions[$purchase->id]->status) }}
                            </span>
                        @else
                            <span class="text-text-muted">—</span>
                        @endif
                    </td>
                    <td class="py-3 px-4 font-bold text-text-secondary">
                        <bdi>{{ $purchase->currency_code }}</bdi>
                    </td>
                    @if($withCost)
                        <td class="py-3 px-4 font-bold text-text-primary tabular-nums" dir="ltr">
                            <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($purchase->grand_total_currency, $purchase->currency_code, $purchase->resolvedCurrencyMinorUnits()) }}</bdi>
                        </td>
                    @endif
                    <td class="table-actions py-3 px-4 text-end">@include('livewire.partials.purchase-row-actions')</td>
                </tr>
            @empty
                <tr><td colspan="{{ $withCost ? 9 : 8 }}" class="p-8 text-center text-text-secondary">{{ __('purchasing.no_purchases') }}</td></tr>
            @endforelse
            </tbody></table>
        </div>
        <div class="lg:hidden divide-y divide-border">
            @forelse($purchases as $purchase)
                <x-compact-mobile-row wire:key="purchase-mobile-{{ $purchase->public_id }}">
                    <x-slot:primary>
                        <a href="{{ route('purchases.show', $purchase->public_id) }}" data-row-link class="touch-action font-mono font-bold text-primary hover:underline truncate" dir="ltr">
                            {{ $purchase->purchase_number ?? __('purchasing.draft') }}
                        </a>
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold shrink-0 @if($purchase->status === 'posted') bg-success-bg text-success @elseif($purchase->status === 'void') bg-danger-bg text-danger @else bg-slate-100 text-slate-600 @endif">
                            {{ __('purchasing.'.$purchase->status) }}
                        </span>
                    </x-slot:primary>

                    <x-slot:subtitle>
                        @if($canViewVendor && $purchase->vendor?->public_id)
                            <a href="{{ route('vendors.show', $purchase->vendor->public_id) }}" class="touch-action font-semibold text-text-primary hover:text-primary">{{ $purchase->vendor->displayName() }}</a>
                        @else
                            <span class="font-semibold text-text-primary">{{ $purchase->vendor?->displayName() ?? '—' }}</span>
                        @endif
                        &middot; <bdi dir="ltr" class="font-mono">{{ $purchase->purchase_date->format('Y-m-d') }}</bdi>
                    </x-slot:subtitle>

                    <x-slot:trailing>
                        @if($withCost)
                            <bdi dir="ltr" class="block font-mono font-bold text-sm tabular-nums text-text-primary">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($purchase->grand_total_currency, $purchase->currency_code, $purchase->resolvedCurrencyMinorUnits()) }} {{ $purchase->currency_code }}
                            </bdi>
                        @else
                            <bdi class="block font-bold text-xs text-text-secondary">{{ $purchase->currency_code }}</bdi>
                        @endif
                        @if($withCost && $purchase->isPosted() && isset($payablePositions[$purchase->id]))
                            <span class="inline-block px-1.5 py-0.5 rounded-full text-xs font-bold mt-0.5 {{ $payablePositions[$purchase->id]->status === 'settled' ? 'bg-success-bg text-success' : ($payablePositions[$purchase->id]->status === 'partially_paid' ? 'bg-warning-bg text-warning' : ($payablePositions[$purchase->id]->status === 'credit' ? 'bg-info-bg text-info' : 'bg-surface-soft text-text-secondary')) }}">
                                {{ __('purchasing.'.$payablePositions[$purchase->id]->status) }}
                            </span>
                        @endif
                    </x-slot:trailing>

                    <x-slot:details>
                        <dl class="grid grid-cols-2 gap-2">
                            <div>
                                <dt class="text-text-secondary">{{ __('purchasing.vendor_invoice_number') }}</dt>
                                <dd class="mt-0.5 font-mono text-text-primary"><bdi dir="ltr">{{ $purchase->vendor_invoice_number ?? '—' }}</bdi></dd>
                            </div>
                            <div>
                                <dt class="text-text-secondary">{{ __('purchasing.currency') }}</dt>
                                <dd class="mt-0.5 font-semibold text-text-primary"><bdi>{{ $purchase->currency_code }}</bdi></dd>
                            </div>
                        </dl>
                        <div>
                            @include('livewire.partials.purchase-row-actions')
                        </div>
                    </x-slot:details>
                </x-compact-mobile-row>
            @empty
                <p class="p-8 text-center text-text-secondary">{{ __('purchasing.no_purchases') }}</p>
            @endforelse
        </div>
        <div class="p-3 border-t border-border">
            <x-table-pagination :paginator="$purchases" />
        </div>
    </div>

    @if($payingPurchaseId)
        <x-dialog-shell
            :title="__('purchasing.new_vendor_payment')"
            title-id="modal-vendor-payment-title"
            max-width="max-w-2xl"
            close-action="closePaymentModal" save-target="save">
            <livewire:pages.purchasing.payment-form :purchase_id="$payingPurchaseId" :is-modal="true" :key="'modal-vendor-payment-'.$payingPurchaseId" />
        </x-dialog-shell>
    @endif
</div>
