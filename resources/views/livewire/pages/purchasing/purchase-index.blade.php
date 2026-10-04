<div class="space-y-5">
    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div><h1 class="text-2xl font-bold">{{ __('purchasing.purchases') }}</h1></div>
        @if($canCreate)
            <a href="{{ route('purchases.create') }}" class="inline-flex h-11 items-center justify-center gap-2 px-4 rounded-control bg-primary text-white text-sm font-bold"><x-icon name="plus" class="w-4 h-4" />{{ __('purchasing.new_purchase') }}</a>
        @endif
    </header>
    <div class="bg-surface border border-border rounded-card p-4 grid sm:grid-cols-3 gap-3">
        <input type="search" wire:model.live.debounce.300ms="search" aria-label="{{ __('purchasing.search_purchases') }}" placeholder="{{ __('purchasing.search_purchases') }}" class="h-11 min-w-0 px-3 rounded-control border border-border text-sm" />
        <select wire:model.live="vendorFilter" aria-label="{{ __('purchasing.vendor') }}" class="h-11 min-w-0 px-3 rounded-control border border-border text-sm"><option value="">{{ __('purchasing.all_vendors') }}</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}">{{ $vendor->displayName() }}</option>@endforeach</select>
        <select wire:model.live="statusFilter" aria-label="{{ __('purchasing.status') }}" class="h-11 px-3 rounded-control border border-border text-sm"><option value="all">{{ __('purchasing.all_statuses') }}</option><option value="draft">{{ __('purchasing.draft') }}</option><option value="posted">{{ __('purchasing.posted') }}</option></select>
    </div>
    <section class="bg-surface border border-border rounded-card overflow-hidden">
        <div class="hidden md:block">
            <table class="w-full text-xs"><thead class="bg-surface-soft text-text-secondary"><tr>
                @foreach(['purchase_date', 'vendor', 'vendor_invoice_number', 'status', 'currency'] as $label)<th class="px-4 py-3 text-start">{{ __('purchasing.'.$label) }}</th>@endforeach
                @if($withCost)<th class="px-4 py-3 text-start">{{ __('purchasing.total') }}</th>@endif
            </tr></thead><tbody class="divide-y divide-border">
            @forelse($purchases as $purchase)
                <tr wire:key="purchase-{{ $purchase->public_id }}"><td class="px-4 py-4"><bdi>{{ $purchase->purchase_date->format('Y-m-d') }}</bdi></td>
                    <td class="px-4 py-4"><a class="font-bold text-primary" href="{{ route('purchases.show', $purchase->public_id) }}">{{ $purchase->vendor->displayName() }}</a><p class="mt-1"><bdi>{{ $purchase->purchase_number ?? __('purchasing.draft') }}</bdi></p></td>
                    <td class="px-4 py-4"><bdi>{{ $purchase->vendor_invoice_number ?? '—' }}</bdi></td><td class="px-4 py-4">{{ __('purchasing.'.$purchase->status) }}</td><td class="px-4 py-4"><bdi>{{ $purchase->currency_code }}</bdi></td>
                    @if($withCost)<td class="px-4 py-4"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($purchase->grand_total_currency, $purchase->currency_code) }}</bdi></td>@endif
                </tr>
            @empty<tr><td colspan="6" class="p-8 text-center text-text-secondary">{{ __('purchasing.no_purchases') }}</td></tr>@endforelse
            </tbody></table>
        </div>
        <div class="md:hidden divide-y divide-border">
            @forelse($purchases as $purchase)
                <article class="p-4 space-y-3" wire:key="purchase-mobile-{{ $purchase->public_id }}">
                    <div class="flex justify-between gap-3"><a href="{{ route('purchases.show', $purchase->public_id) }}" class="font-bold text-primary text-sm">{{ $purchase->vendor->displayName() }}</a><span class="text-xs">{{ __('purchasing.'.$purchase->status) }}</span></div>
                    <dl class="grid grid-cols-2 gap-3 text-xs">
                        <div><dt class="text-text-secondary">{{ __('purchasing.purchase_number') }}</dt><dd><bdi>{{ $purchase->purchase_number ?? __('purchasing.draft') }}</bdi></dd></div>
                        <div><dt class="text-text-secondary">{{ __('purchasing.purchase_date') }}</dt><dd><bdi>{{ $purchase->purchase_date->format('Y-m-d') }}</bdi></dd></div>
                        <div><dt class="text-text-secondary">{{ __('purchasing.vendor_invoice_number') }}</dt><dd><bdi>{{ $purchase->vendor_invoice_number ?? '—' }}</bdi></dd></div>
                        <div><dt class="text-text-secondary">{{ __('purchasing.currency') }}</dt><dd><bdi>{{ $purchase->currency_code }}</bdi></dd></div>
                        @if($withCost)<div><dt class="text-text-secondary">{{ __('purchasing.total') }}</dt><dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($purchase->grand_total_currency, $purchase->currency_code) }}</bdi></dd></div>@endif
                    </dl>
                </article>
            @empty<p class="p-8 text-center text-text-secondary">{{ __('purchasing.no_purchases') }}</p>@endforelse
        </div>
    </section>
    {{ $purchases->links() }}
</div>
