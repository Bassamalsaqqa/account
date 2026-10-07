<div class="min-w-0 space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('expenses.title') }}</h1>
            <p class="text-sm text-text-muted">{{ __('expenses.expenses') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($canManage)
                <a href="{{ route('expenses.categories') }}" class="inline-flex justify-center items-center rounded-control border border-border bg-white px-3 py-2 text-sm font-semibold text-text-secondary hover:bg-surface-soft">
                    {{ __('expenses.categories') }}
                </a>
                <a href="{{ route('expenses.create') }}" class="inline-flex justify-center items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2">
                    {{ __('expenses.create_expense') }}
                </a>
            @endif
        </div>
    </div>

    <!-- Filters -->
    <div class="rounded-card border border-border bg-white p-4 space-y-3">
        <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
            <div>
                <label class="block text-xs font-medium text-text-secondary mb-1">{{ __('expenses.search') }}</label>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('expenses.search') }}" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
            </div>
            <div>
                <label class="block text-xs font-medium text-text-secondary mb-1">{{ __('expenses.category') }}</label>
                <select wire:model.live="categoryId" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('expenses.all_categories') }}</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ app()->getLocale() === 'en' ? ($cat->name_en ?: $cat->name_ar) : $cat->name_ar }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-text-secondary mb-1">{{ __('expenses.classification') }}</label>
                <select wire:model.live="classification" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('expenses.all_classifications') }}</option>
                    <option value="operating">{{ __('expenses.operating') }}</option>
                    <option value="landed_cost">{{ __('expenses.landed_cost') }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-text-secondary mb-1">{{ __('expenses.payment_method') }}</label>
                <select wire:model.live="paymentMethod" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('expenses.all_methods') }}</option>
                    <option value="cash">{{ __('expenses.cash') }}</option>
                    <option value="bank">{{ __('expenses.bank') }}</option>
                    <option value="check">{{ __('expenses.check') }}</option>
                </select>
            </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label class="block text-xs font-medium text-text-secondary mb-1">{{ __('expenses.status') }}</label>
                <select wire:model.live="status" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('expenses.all_statuses') }}</option>
                    <option value="posted">{{ __('expenses.posted') }}</option>
                    <option value="reversed">{{ __('expenses.reversed') }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-text-secondary mb-1">{{ __('expenses.from_date') }}</label>
                <input type="date" wire:model.live="fromDate" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
            </div>
            <div>
                <label class="block text-xs font-medium text-text-secondary mb-1">{{ __('expenses.to_date') }}</label>
                <input type="date" wire:model.live="toDate" class="w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="rounded-card border border-border bg-white overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
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
                                <a href="{{ route('expenses.show', $exp->public_id) }}" class="hover:underline">
                                    {{ $exp->expense_number }}
                                </a>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-text-secondary">
                                {{ $exp->expense_date->toDateString() }}
                            </td>
                            <td class="px-4 py-3 text-text-secondary">
                                {{ $exp->category ? (app()->getLocale() === 'en' ? ($exp->category->name_en ?: $exp->category->name_ar) : $exp->category->name_ar) : '-' }}
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
        @if($expenses->hasPages())
            <div class="p-4 border-t border-border">
                {{ $expenses->links() }}
            </div>
        @endif
    </div>
</div>
