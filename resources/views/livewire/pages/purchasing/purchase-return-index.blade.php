<div class="space-y-5">
    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div><h1 class="text-2xl font-bold">{{ __('purchasing.purchase_returns') }}</h1></div>
    </header>
    <div class="bg-surface border border-border rounded-card p-4 grid sm:grid-cols-3 gap-3">
        <input type="search" wire:model.live.debounce.300ms="search" aria-label="{{ __('purchasing.search_purchase_returns') }}" placeholder="{{ __('purchasing.search_purchase_returns') }}" class="h-11 min-w-0 px-3 rounded-control border border-border text-sm" />
        <select wire:model.live="vendorFilter" aria-label="{{ __('purchasing.vendor') }}" class="h-11 min-w-0 px-3 rounded-control border border-border text-sm">
            <option value="">{{ __('purchasing.all_vendors') }}</option>
            @foreach($vendors as $vendor)
                <option value="{{ $vendor->id }}">{{ $vendor->displayName() }}</option>
            @endforeach
        </select>
        <select wire:model.live="statusFilter" aria-label="{{ __('purchasing.status') }}" class="h-11 px-3 rounded-control border border-border text-sm">
            <option value="all">{{ __('purchasing.all_statuses') }}</option>
            <option value="draft">{{ __('purchasing.draft') }}</option>
            <option value="posted">{{ __('purchasing.posted') }}</option>
        </select>
    </div>
    <section class="bg-surface border border-border rounded-card overflow-hidden">
        <div class="hidden md:block">
            <table class="w-full text-xs">
                <thead class="bg-surface-soft text-text-secondary">
                    <tr>
                        @foreach(['return_date', 'vendor', 'purchase_number', 'status', 'currency'] as $label)
                            <th class="px-4 py-3 text-start">{{ __('purchasing.'.$label) }}</th>
                        @endforeach
                        @if($withCost)
                            <th class="px-4 py-3 text-start">{{ __('purchasing.total') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($returns as $return)
                        <tr wire:key="return-{{ $return->public_id }}">
                            <td class="px-4 py-4"><bdi>{{ $return->return_date->format('Y-m-d') }}</bdi></td>
                            <td class="px-4 py-4">
                                <a class="font-bold text-primary" href="{{ route('purchase-returns.show', $return->public_id) }}">
                                    {{ $return->vendor->displayName() }}
                                </a>
                                <p class="mt-1"><bdi>{{ $return->return_number ?? __('purchasing.draft') }}</bdi></p>
                            </td>
                            <td class="px-4 py-4">
                                @if($return->purchase)
                                    <a class="text-primary hover:underline" href="{{ route('purchases.show', $return->purchase->public_id) }}">
                                        <bdi>{{ $return->purchase->purchase_number ?? '—' }}</bdi>
                                    </a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-4">{{ __('purchasing.'.$return->status) }}</td>
                            <td class="px-4 py-4"><bdi>{{ $return->currency_code }}</bdi></td>
                            @if($withCost)
                                <td class="px-4 py-4">
                                    <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($return->grand_total_currency, $return->currency_code) }}</bdi>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-8 text-center text-text-secondary">{{ __('purchasing.no_purchase_returns') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="md:hidden divide-y divide-border">
            @forelse($returns as $return)
                <article class="p-4 space-y-3" wire:key="return-mobile-{{ $return->public_id }}">
                    <div class="flex justify-between gap-3">
                        <a href="{{ route('purchase-returns.show', $return->public_id) }}" class="font-bold text-primary text-sm">
                            {{ $return->vendor->displayName() }}
                        </a>
                        <span class="text-xs">{{ __('purchasing.'.$return->status) }}</span>
                    </div>
                    <dl class="grid grid-cols-2 gap-3 text-xs">
                        <div>
                            <dt class="text-text-secondary">{{ __('purchasing.purchase_return_number') }}</dt>
                            <dd><bdi>{{ $return->return_number ?? __('purchasing.draft') }}</bdi></dd>
                        </div>
                        <div>
                            <dt class="text-text-secondary">{{ __('purchasing.return_date') }}</dt>
                            <dd><bdi>{{ $return->return_date->format('Y-m-d') }}</bdi></dd>
                        </div>
                        <div>
                            <dt class="text-text-secondary">{{ __('purchasing.purchase_number') }}</dt>
                            <dd>
                                @if($return->purchase)
                                    <a class="text-primary hover:underline" href="{{ route('purchases.show', $return->purchase->public_id) }}">
                                        <bdi>{{ $return->purchase->purchase_number ?? '—' }}</bdi>
                                    </a>
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-text-secondary">{{ __('purchasing.currency') }}</dt>
                            <dd><bdi>{{ $return->currency_code }}</bdi></dd>
                        </div>
                        @if($withCost)
                            <div>
                                <dt class="text-text-secondary">{{ __('purchasing.total') }}</dt>
                                <dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($return->grand_total_currency, $return->currency_code) }}</bdi></dd>
                            </div>
                        @endif
                    </dl>
                </article>
            @empty
                <p class="p-8 text-center text-text-secondary">{{ __('purchasing.no_purchase_returns') }}</p>
            @endforelse
        </div>
    </section>
    {{ $returns->links() }}
</div>
