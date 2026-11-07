<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">{{ __('purchasing.purchase_returns') }}</h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('purchasing.purchase_returns') }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($returns->total()) }})
            </p>
        </div>
    </div>
    <x-refinement-filters :active-count="(int) ($statusFilter !== 'all') + (int) filled($vendorFilter)">
        <x-slot:search>
            <input type="search" wire:model.live.debounce.300ms="search" aria-label="{{ __('purchasing.search_purchase_returns') }}" placeholder="{{ __('purchasing.search_purchase_returns') }}" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary" />
        </x-slot:search>
        <x-slot:statuses>
        <button type="button" wire:click="$set('statusFilter', 'all')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'all' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'all' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('purchasing.all_statuses') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'draft')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'draft' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'draft' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('purchasing.draft') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'posted')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'posted' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'posted' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('purchasing.posted') }}</button>
        </x-slot:statuses>
        <select wire:model.live="vendorFilter" aria-label="{{ __('purchasing.vendor') }}" class="min-h-11 h-11 min-w-0 px-3 rounded-control border border-border text-sm">
            <option value="">{{ __('purchasing.all_vendors') }}</option>
            @foreach($vendors as $vendor)
                <option value="{{ $vendor->id }}">{{ $vendor->displayName() }}</option>

                    @endforeach
        </select>
        <select wire:model.live="statusFilter" aria-label="{{ __('purchasing.status') }}" class="min-h-11 h-11 px-3 rounded-control border border-border text-sm">
            <option value="all">{{ __('purchasing.all_statuses') }}</option>
            <option value="draft">{{ __('purchasing.draft') }}</option>
            <option value="posted">{{ __('purchasing.posted') }}</option>
        </select>
    </x-refinement-filters>
    <div class="bg-surface rounded-card border border-border shadow-xs overflow-hidden">
        <div class="hidden lg:block overflow-x-auto">
            <table class="data-table w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-xs uppercase tracking-wider">
                        <th class="py-3 px-4 text-start">{{ __('purchasing.purchase_return_number') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.vendor') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.purchase_number') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.return_date') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.status') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.currency') }}</th>
                        @if($withCost)
                            <th class="py-3 px-4 text-start">{{ __('purchasing.total') }}</th>
                        @endif
                        <th class="py-3 px-4 text-end">{{ __('sales.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($returns as $return)
                        <tr wire:key="return-{{ $return->public_id }}" class="hover:bg-surface-soft/60 transition-colors">
                            <td class="py-3 px-4 font-mono font-bold" dir="ltr">
                                <a href="{{ route('purchase-returns.show', $return->public_id) }}" class="touch-action text-primary hover:underline">
                                    {{ $return->return_number ?? __('purchasing.draft') }}
                                </a>
                            </td>
                            <td class="py-3 px-4">
                                <a class="touch-action font-bold text-text-primary hover:text-primary transition-colors" href="{{ route('purchase-returns.show', $return->public_id) }}">
                                    {{ $return->vendor->displayName() }}
                                </a>
                            </td>
                            <td class="py-3 px-4 font-mono">
                                @if($return->purchase)
                                    <a class="touch-action text-primary hover:underline" href="{{ route('purchases.show', $return->purchase->public_id) }}">
                                        <bdi>{{ $return->purchase->purchase_number ?? '—' }}</bdi>
                                    </a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-3 px-4" dir="ltr">{{ $return->return_date->format('Y-m-d') }}</td>
                            <td class="py-3 px-4">
                                <span class="px-2 py-0.5 rounded-full text-xs font-bold @if($return->status === 'posted') bg-success-bg text-success @elseif($return->status === 'void') bg-danger-bg text-danger @else bg-slate-100 text-slate-600 @endif">
                                    {{ __('purchasing.'.$return->status) }}
                                </span>
                            </td>
                            <td class="py-3 px-4 font-bold text-text-secondary"><bdi>{{ $return->currency_code }}</bdi></td>
                            @if($withCost)
                                <td class="py-3 px-4 font-bold text-text-primary tabular-nums" dir="ltr">
                                    <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($return->grand_total_currency, $return->currency_code) }}</bdi>
                                </td>
                            @endif
                            <td class="table-actions py-3 px-4 text-end">
                                <div class="row-action-group justify-end" role="group" aria-label="{{ __('sales.actions') }}">
                                    <x-row-action data-row-link :href="route('purchase-returns.show', $return->public_id)" :label="__('workflow.open')" icon="eye" />
                                    <x-correction-shortcuts family="purchase_return" :public-id="$return->public_id" :status="$return->status" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $withCost ? 8 : 7 }}" class="p-8 text-center text-text-secondary">{{ __('purchasing.no_purchase_returns') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="lg:hidden divide-y divide-border">
            @forelse($returns as $return)
                <article class="p-4 space-y-3" wire:key="return-mobile-{{ $return->public_id }}">
                    <div class="flex justify-between gap-3">
                        <a href="{{ route('purchase-returns.show', $return->public_id) }}" class="touch-action font-bold text-primary text-sm">
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
                                    <a class="touch-action text-primary hover:underline" href="{{ route('purchases.show', $return->purchase->public_id) }}">
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
                                <dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($return->grand_total_currency, $return->currency_code) }}</bdi></dd>
                            </div>
                        @endif
                    </dl>
                <div class="row-action-group"><x-correction-shortcuts family="purchase_return" :public-id="$return->public_id" :status="$return->status" /></div>
                </article>
            @empty
                <p class="p-8 text-center text-text-secondary">{{ __('purchasing.no_purchase_returns') }}</p>
            @endforelse
        </div>
        <div class="p-3 border-t border-border">
            <x-table-pagination :paginator="$returns" />
        </div>
    </div>
</div>
