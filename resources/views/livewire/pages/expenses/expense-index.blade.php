<div class="min-w-0 space-y-6">
    <x-page-heading :title="__('expenses.title')" icon="money">
        <div class="flex flex-wrap gap-2">
            @if($canManage)
                <a href="{{ route('expenses.categories') }}" class="touch-action border border-border bg-surface text-sm font-semibold text-text-secondary hover:bg-surface-soft">
                    {{ __('expenses.categories') }}
                </a>
                <a href="{{ route('expenses.create') }}" class="touch-action bg-primary text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2">
                    {{ __('expenses.create_expense') }}
                </a>
            @endif
        </div>
    </x-page-heading>

    <!-- Filters -->
    <x-refinement-filters :active-count="(int) filled($categoryId) + (int) filled($classification) + (int) filled($paymentMethod) + (int) filled($status) + (int) filled($fromDate) + (int) filled($toDate)">
        <x-slot:search>
            <input aria-label="{{ __('expenses.search') }}" id="expense-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('expenses.search') }}" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary">
        </x-slot:search>
        <x-slot:statuses>
        <button type="button" wire:click="$set('status', '')" wire:loading.attr="disabled" wire:target="status" aria-pressed="{{ $status === '' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $status === '' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('expenses.all_statuses') }}</button>
        <button type="button" wire:click="$set('status', 'posted')" wire:loading.attr="disabled" wire:target="status" aria-pressed="{{ $status === 'posted' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $status === 'posted' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('expenses.posted') }}</button>
        <button type="button" wire:click="$set('status', 'reversed')" wire:loading.attr="disabled" wire:target="status" aria-pressed="{{ $status === 'reversed' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $status === 'reversed' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('expenses.reversed') }}</button>
        </x-slot:statuses>
        <select aria-label="{{ __('expenses.all_categories') }}" id="expense-category-filter" wire:model.live="categoryId" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('expenses.all_categories') }}</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ app()->getLocale() === 'en' ? ($cat->name_en ?: $cat->name_ar) : $cat->name_ar }}</option>
                    @endforeach
                </select>
        <select aria-label="{{ __('expenses.all_classifications') }}" id="expense-classification-filter" wire:model.live="classification" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('expenses.all_classifications') }}</option>
                    <option value="operating">{{ __('expenses.operating') }}</option>
                    <option value="landed_cost">{{ __('expenses.landed_cost') }}</option>
                </select>
        <select aria-label="{{ __('expenses.all_methods') }}" id="expense-payment-method-filter" wire:model.live="paymentMethod" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('expenses.all_methods') }}</option>
                    <option value="cash">{{ __('expenses.cash') }}</option>
                    <option value="bank">{{ __('expenses.bank') }}</option>
                    <option value="check">{{ __('expenses.check') }}</option>
                </select>
        <select aria-label="{{ __('expenses.all_statuses') }}" id="expense-status-filter" wire:model.live="status" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('expenses.all_statuses') }}</option>
                    <option value="posted">{{ __('expenses.posted') }}</option>
                    <option value="reversed">{{ __('expenses.reversed') }}</option>
                </select>
        <label class="grid gap-1 text-sm text-text-secondary">{{ __('inventory.date_from') }}<input aria-label="{{ __('inventory.date_from') }}" id="expense-from-date" type="date" wire:model.live="fromDate" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary"></label>
        <label class="grid gap-1 text-sm text-text-secondary">{{ __('inventory.date_to') }}<input aria-label="{{ __('inventory.date_to') }}" id="expense-to-date" type="date" wire:model.live="toDate" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary"></label>
    </x-refinement-filters>

    <!-- Table -->
    <div class="rounded-card border border-border bg-surface overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
            <table class="data-table data-table--responsive w-full text-sm text-start">
                <thead class="bg-surface-soft border-b border-border text-xs uppercase text-text-muted">
                    <tr>
                        <th class="px-4 py-3 text-start">{{ __('expenses.expense_number') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('expenses.expense_date') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('expenses.category') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('expenses.description') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('expenses.classification') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('expenses.payment_method') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('expenses.amount') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('expenses.status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($expenses as $exp)
                        @php($catName = app()->getLocale() === 'en' ? ($exp->category_snapshot['name_en'] ?: $exp->category_snapshot['name_ar']) : ($exp->category_snapshot['name_ar'] ?: $exp->category_snapshot['name_en']))
                        <tr wire:key="expense-row-{{ $exp->id }}" data-row-surface x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'" class="hover:bg-surface-soft/60 transition-colors">
                            <td class="td-primary px-4 py-3 font-semibold text-primary">
                                <div class="flex items-center gap-2 min-w-0">
                                    <a href="{{ route('expenses.show', $exp->public_id) }}" data-row-link class="touch-action font-mono hover:underline truncate" dir="ltr">
                                        {{ $exp->expense_number }}
                                    </a>
                                    @if($exp->status === 'posted')
                                        <span class="lg:hidden inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-success-bg text-success shrink-0">
                                            {{ __('expenses.posted') }}
                                        </span>
                                    @else
                                        <span class="lg:hidden inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-danger-bg text-danger shrink-0">
                                            {{ __('expenses.reversed') }}
                                        </span>
                                    @endif
                                </div>
                                <div class="lg:hidden font-normal text-xs text-text-secondary truncate mt-0.5">
                                    {{ $catName }} &middot; <bdi dir="ltr">{{ $exp->expense_date->toDateString() }}</bdi>
                                </div>
                            </td>
                            <td class="td-secondary px-4 py-3 whitespace-nowrap text-text-secondary" data-label="{{ __('expenses.expense_date') }}" dir="ltr">
                                {{ $exp->expense_date->toDateString() }}
                            </td>
                            <td class="td-secondary px-4 py-3 text-text-secondary" data-label="{{ __('expenses.category') }}">
                                {{ $catName }}
                            </td>
                            <td class="td-secondary px-4 py-3 text-text-secondary lg:max-w-xs lg:truncate" data-label="{{ __('expenses.description') }}" title="{{ $exp->description }}">
                                {{ $exp->description }}
                            </td>
                            <td class="td-secondary px-4 py-3 whitespace-nowrap" data-label="{{ __('expenses.classification') }}">
                                @if($exp->classification === 'landed_cost')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-warning-bg text-warning">
                                        {{ __('expenses.landed_cost') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-700">
                                        {{ __('expenses.operating') }}
                                    </span>
                                @endif
                            </td>
                            <td class="td-secondary px-4 py-3 whitespace-nowrap text-text-secondary" data-label="{{ __('expenses.payment_method') }}">
                                {{ __('expenses.'.$exp->payment_method) }}
                            </td>
                            <td class="td-end px-4 py-3 whitespace-nowrap font-medium">
                                <div class="inline-flex items-center gap-2">
                                    <bdi dir="ltr" class="font-mono tabular-nums font-bold">
                                        @if($exp->amount === null)
                                            <span class="font-sans text-text-muted italic font-normal">{{ __('expenses.cost_redacted') }}</span>
                                        @else
                                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($exp->amount) }} {{ $exp->currency_code }}
                                        @endif
                                    </bdi>
                                    <x-row-details-toggle />
                                </div>
                            </td>
                            <td class="td-secondary px-4 py-3 whitespace-nowrap" data-label="{{ __('expenses.status') }}">
                                @if($exp->status === 'posted')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-success-bg text-success">
                                        {{ __('expenses.posted') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-danger-bg text-danger">
                                        {{ __('expenses.reversed') }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-text-muted">
                                {{ __('expenses.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($expenses->count() > 0 || $expenses->currentPage() > 1)
            <div class="p-4 border-t border-border">
                <x-table-pagination :paginator="$expenses" />
            </div>
        @endif
    </div>
</div>
