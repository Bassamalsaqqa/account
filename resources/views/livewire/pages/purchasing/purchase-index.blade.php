<div class="space-y-5">
    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div><h1 class="text-2xl font-bold">{{ __('purchasing.purchases') }}</h1></div>
        @if($canCreate)
            <a href="{{ route('purchases.create') }}" class="touch-action h-11 bg-primary text-white text-sm font-bold"><x-icon name="plus" class="w-4 h-4" />{{ __('purchasing.new_purchase') }}</a>
        @endif
    </header>
    <x-refinement-filters :active-count="(int) ($statusFilter !== 'all') + (int) filled($vendorFilter)">
        <x-slot:search>
            <input type="search" wire:model.live.debounce.300ms="search" aria-label="{{ __('purchasing.search_purchases') }}" placeholder="{{ __('purchasing.search_purchases') }}" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" />
        </x-slot:search>
        <x-slot:statuses>
        <button type="button" wire:click="$set('statusFilter', 'all')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'all' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'all' ? 'border-primary bg-primary text-white' : 'border-border bg-white text-text-secondary' }}">{{ __('purchasing.all_statuses') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'draft')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'draft' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'draft' ? 'border-primary bg-primary text-white' : 'border-border bg-white text-text-secondary' }}">{{ __('purchasing.draft') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'posted')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'posted' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'posted' ? 'border-primary bg-primary text-white' : 'border-border bg-white text-text-secondary' }}">{{ __('purchasing.posted') }}</button>
        </x-slot:statuses>
        <select wire:model.live="vendorFilter" aria-label="{{ __('purchasing.vendor') }}" class="min-h-11 h-11 min-w-0 px-3 rounded-control border border-border text-sm"><option value="">{{ __('purchasing.all_vendors') }}</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}">{{ $vendor->displayName() }}</option>@endforeach</select>
        <select wire:model.live="statusFilter" aria-label="{{ __('purchasing.status') }}" class="min-h-11 h-11 px-3 rounded-control border border-border text-sm"><option value="all">{{ __('purchasing.all_statuses') }}</option><option value="draft">{{ __('purchasing.draft') }}</option><option value="posted">{{ __('purchasing.posted') }}</option></select>
    </x-refinement-filters>
    <section class="bg-surface border border-border rounded-card overflow-hidden">
        <div class="hidden md:block">
            <table class="w-full text-xs"><thead class="bg-surface-soft text-text-secondary"><tr>
                @foreach(['purchase_date', 'vendor', 'vendor_invoice_number', 'status', 'currency'] as $label)<th class="px-4 py-3 text-start">{{ __('purchasing.'.$label) }}</th>@endforeach
                @if($withCost)<th class="px-4 py-3 text-start">{{ __('purchasing.total') }}</th>@endif
            </tr></thead><tbody class="divide-y divide-border">
            @forelse($purchases as $purchase)
                <tr wire:key="purchase-{{ $purchase->public_id }}"><td class="px-4 py-4"><bdi>{{ $purchase->purchase_date->format('Y-m-d') }}</bdi></td>
                    <td class="px-4 py-4"><a class="touch-action font-bold text-primary" href="{{ route('purchases.show', $purchase->public_id) }}">{{ $purchase->vendor->displayName() }}</a><p class="mt-1"><bdi>{{ $purchase->purchase_number ?? __('purchasing.draft') }}</bdi></p></td>
                    <td class="px-4 py-4"><bdi>{{ $purchase->vendor_invoice_number ?? '—' }}</bdi></td>
                    <td class="px-4 py-4">
                        <div>{{ __('purchasing.'.$purchase->status) }}</div>
                        @if($withCost && $purchase->isPosted() && isset($payablePositions[$purchase->id]))
                            <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[10px] font-bold {{ $payablePositions[$purchase->id]->status === 'settled' ? 'bg-success-bg text-success' : ($payablePositions[$purchase->id]->status === 'partially_paid' ? 'bg-warning-bg text-warning' : ($payablePositions[$purchase->id]->status === 'credit' ? 'bg-info-bg text-info' : 'bg-surface-soft text-text-secondary')) }}">
                                {{ __('purchasing.'.$payablePositions[$purchase->id]->status) }}
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-4"><bdi>{{ $purchase->currency_code }}</bdi></td>
                    @if($withCost)<td class="px-4 py-4"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($purchase->grand_total_currency, $purchase->currency_code) }}</bdi></td>@endif
                </tr>
            @empty<tr><td colspan="6" class="p-8 text-center text-text-secondary">{{ __('purchasing.no_purchases') }}</td></tr>@endforelse
            </tbody></table>
        </div>
        <div class="md:hidden divide-y divide-border">
            @forelse($purchases as $purchase)
                <article class="p-4 space-y-3" wire:key="purchase-mobile-{{ $purchase->public_id }}">
                    <div class="flex justify-between gap-3">
                        <a href="{{ route('purchases.show', $purchase->public_id) }}" class="touch-action font-bold text-primary text-sm">{{ $purchase->vendor->displayName() }}</a>
                        <div class="text-end">
                            <span class="text-xs">{{ __('purchasing.'.$purchase->status) }}</span>
                            @if($withCost && $purchase->isPosted() && isset($payablePositions[$purchase->id]))
                                <span class="inline-block px-1.5 py-0.5 rounded-full text-[10px] font-bold {{ $payablePositions[$purchase->id]->status === 'settled' ? 'bg-success-bg text-success' : ($payablePositions[$purchase->id]->status === 'partially_paid' ? 'bg-warning-bg text-warning' : ($payablePositions[$purchase->id]->status === 'credit' ? 'bg-info-bg text-info' : 'bg-surface-soft text-text-secondary')) }}">
                                    {{ __('purchasing.'.$payablePositions[$purchase->id]->status) }}
                                </span>
                            @endif
                        </div>
                    </div>
                    <dl class="grid grid-cols-2 gap-3 text-xs">
                        <div><dt class="text-text-secondary">{{ __('purchasing.purchase_number') }}</dt>
                                <dd><bdi>{{ $purchase->purchase_number ?? __('purchasing.draft') }}</bdi></dd>
                            </div>
                        <div><dt class="text-text-secondary">{{ __('purchasing.purchase_date') }}</dt>
                                <dd><bdi>{{ $purchase->purchase_date->format('Y-m-d') }}</bdi></dd>
                            </div>
                        <div><dt class="text-text-secondary">{{ __('purchasing.vendor_invoice_number') }}</dt>
                                <dd><bdi>{{ $purchase->vendor_invoice_number ?? '—' }}</bdi></dd>
                            </div>
                        <div><dt class="text-text-secondary">{{ __('purchasing.currency') }}</dt>
                                <dd><bdi>{{ $purchase->currency_code }}</bdi></dd>
                            </div>
                        @if($withCost)<div><dt class="text-text-secondary">{{ __('purchasing.total') }}</dt>
                                <dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($purchase->grand_total_currency, $purchase->currency_code) }}</bdi></dd>
                            </div>@endif
                    </dl>
                    <div class="flex flex-wrap items-center gap-2 border-t border-border pt-3">
                        <a href="{{ route('purchases.show', $purchase->public_id) }}" class="touch-action bg-surface-soft text-sm font-semibold"><x-icon name="receipt" class="h-4 w-4" />{{ __('refinement_lists.open') }}</a>
                        @if($withCost && $purchase->isPosted() && isset($payablePositions[$purchase->id]) && \Brick\Math\BigDecimal::of($payablePositions[$purchase->id]->outstanding)->isPositive() && auth()->user()->can('money.vendor_payment.create'))
                            <a href="{{ route('vendor-payments.create', ['purchase_id' => $purchase->id]) }}" class="touch-action bg-primary-50 text-primary text-sm font-semibold">{{ __('purchasing.pay_vendor') }}</a>
                        @endif
                        @can('purchasing.document.pdf')
                            <a href="{{ route('pdf.purchase', ['publicId' => $purchase->public_id, 'locale' => app()->getLocale()]) }}" target="_blank" rel="noopener noreferrer" class="touch-action bg-white text-primary text-sm font-semibold">{{ __('refinement_lists.pdf') }}</a>
                        @endcan
                    </div>
                </article>
            @empty<p class="p-8 text-center text-text-secondary">{{ __('purchasing.no_purchases') }}</p>@endforelse
        </div>
    </section>
    {{ $purchases->links() }}
</div>
