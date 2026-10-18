<div class="min-w-0 space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('expenses.title') }}</h1>
            <p class="text-sm text-text-muted">{{ __('expenses.expenses') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($canManage)
                <a href="{{ route('expenses.categories') }}" class="touch-action border border-border bg-white text-sm font-semibold text-text-secondary hover:bg-surface-soft">
                    {{ __('expenses.categories') }}
                </a>
                <a href="{{ route('expenses.create') }}" class="touch-action bg-primary text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2">
                    {{ __('expenses.create_expense') }}
                </a>
            @endif
        </div>
    </div>

    <!-- Filters -->
    <x-refinement-filters :active-count="(int) filled($categoryId) + (int) filled($classification) + (int) filled($paymentMethod) + (int) filled($status) + (int) filled($fromDate) + (int) filled($toDate)">
        <x-slot:search>
            <input aria-label="{{ __('expenses.search') }}" id="expense-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('expenses.search') }}" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
        </x-slot:search>
        <x-slot:statuses>
        <button type="button" wire:click="$set('status', '')" wire:loading.attr="disabled" wire:target="status" aria-pressed="{{ $status === '' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $status === '' ? 'border-primary bg-primary text-white' : 'border-border bg-white text-text-secondary' }}">{{ __('expenses.all_statuses') }}</button>
        <button type="button" wire:click="$set('status', 'posted')" wire:loading.attr="disabled" wire:target="status" aria-pressed="{{ $status === 'posted' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $status === 'posted' ? 'border-primary bg-primary text-white' : 'border-border bg-white text-text-secondary' }}">{{ __('expenses.posted') }}</button>
        <button type="button" wire:click="$set('status', 'reversed')" wire:loading.attr="disabled" wire:target="status" aria-pressed="{{ $status === 'reversed' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $status === 'reversed' ? 'border-primary bg-primary text-white' : 'border-border bg-white text-text-secondary' }}">{{ __('expenses.reversed') }}</button>
        </x-slot:statuses>
        <select aria-label="{{ __('expenses.all_categories') }}" id="expense-category-filter" wire:model.live="categoryId" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('expenses.all_categories') }}</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ app()->getLocale() === 'en' ? ($cat->name_en ?: $cat->name_ar) : $cat->name_ar }}</option>
                    @endforeach
                </select>
        <select aria-label="{{ __('expenses.all_classifications') }}" id="expense-classification-filter" wire:model.live="classification" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('expenses.all_classifications') }}</option>
                    <option value="operating">{{ __('expenses.operating') }}</option>
                    <option value="landed_cost">{{ __('expenses.landed_cost') }}</option>
                </select>
        <select aria-label="{{ __('expenses.all_methods') }}" id="expense-payment-method-filter" wire:model.live="paymentMethod" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('expenses.all_methods') }}</option>
                    <option value="cash">{{ __('expenses.cash') }}</option>
                    <option value="bank">{{ __('expenses.bank') }}</option>
                    <option value="check">{{ __('expenses.check') }}</option>
                </select>
        <select aria-label="{{ __('expenses.all_statuses') }}" id="expense-status-filter" wire:model.live="status" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('expenses.all_statuses') }}</option>
                    <option value="posted">{{ __('expenses.posted') }}</option>
                    <option value="reversed">{{ __('expenses.reversed') }}</option>
                </select>
        <label class="grid gap-1 text-sm text-text-secondary">{{ __('inventory.date_from') }}<input aria-label="{{ __('inventory.date_from') }}" id="expense-from-date" type="date" wire:model.live="fromDate" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary"></label>
        <label class="grid gap-1 text-sm text-text-secondary">{{ __('inventory.date_to') }}<input aria-label="{{ __('inventory.date_to') }}" id="expense-to-date" type="date" wire:model.live="toDate" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary"></label>
    </x-refinement-filters>

    <!-- Table -->
    <div class="rounded-card border border-border bg-white overflow-hidden shadow-sm">
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-sm text-start">
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
                        <tr class="hover:bg-surface-soft/60 transition-colors">
                            <td class="px-4 py-3 font-semibold text-primary">
                                <a href="{{ route('expenses.show', $exp->public_id) }}" class="touch-action hover:underline">
                                    {{ $exp->expense_number }}
                                </a>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-text-secondary">
                                {{ $exp->expense_date->toDateString() }}
                            </td>
                            <td class="px-4 py-3 text-text-secondary">
                                {{ app()->getLocale() === 'en' ? ($exp->category_snapshot['name_en'] ?: $exp->category_snapshot['name_ar']) : ($exp->category_snapshot['name_ar'] ?: $exp->category_snapshot['name_en']) }}
                            </td>
                            <td class="px-4 py-3 text-text-secondary max-w-xs truncate" title="{{ $exp->description }}">
                                {{ $exp->description }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($exp->classification === 'landed_cost')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">
                                        {{ __('expenses.landed_cost') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-700">
                                        {{ __('expenses.operating') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-text-secondary">
                                {{ __('expenses.'.$exp->payment_method) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap font-medium" dir="ltr">
                                @if($exp->amount === null)
                                    <span class="text-text-muted italic">{{ __('expenses.cost_redacted') }}</span>
                                @else
                                    {{ $exp->amount }} {{ $exp->currency_code }}
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($exp->status === 'posted')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800">
                                        {{ __('expenses.posted') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-rose-100 text-rose-800">
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
<div class="md:hidden divide-y divide-border">
                    @forelse($expenses as $exp)
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('expenses.expense_number') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary"><a href="{{ route('expenses.show', $exp->public_id) }}" class="touch-action hover:underline">
                                    {{ $exp->expense_number }}
                                </a></dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('expenses.expense_date') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ $exp->expense_date->toDateString() }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('expenses.category') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ app()->getLocale() === 'en' ? ($exp->category_snapshot['name_en'] ?: $exp->category_snapshot['name_ar']) : ($exp->category_snapshot['name_ar'] ?: $exp->category_snapshot['name_en']) }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('expenses.description') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ $exp->description }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('expenses.classification') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">@if($exp->classification === 'landed_cost')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">
                                        {{ __('expenses.landed_cost') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-700">
                                        {{ __('expenses.operating') }}
                                    </span>
                                @endif</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('expenses.payment_method') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ __('expenses.'.$exp->payment_method) }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('expenses.amount') }}</dt>
                                <dd dir="ltr" class="break-words text-sm tabular-nums text-text-primary">@if($exp->amount === null)
                                    <span class="text-text-muted italic">{{ __('expenses.cost_redacted') }}</span>
                                @else
                                    {{ $exp->amount }} {{ $exp->currency_code }}
                                @endif</dd>
                            </div>
                            <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('expenses.status') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">@if($exp->status === 'posted')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800">
                                        {{ __('expenses.posted') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-rose-100 text-rose-800">
                                        {{ __('expenses.reversed') }}
                                    </span>
                                @endif</dd>
                            </div>
                        </dl>
                        </article>
                    @empty
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="col-span-2 py-4 text-center text-sm text-text-secondary">
                                {{ __('expenses.empty') }}
                            </div>
                        </dl>
                        </article>
                    @endforelse
                </div>

        @if($expenses->hasPages())
            <div class="p-4 border-t border-border">
                {{ $expenses->links() }}
            </div>
        @endif
    </div>
</div>
