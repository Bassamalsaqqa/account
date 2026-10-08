<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <a href="{{ route('expenses.index') }}" class="inline-flex items-center justify-center w-8 h-8 rounded-control border border-border bg-white text-text-secondary hover:bg-surface-soft">
                &larr;
            </a>
            <div>
                <h1 class="text-2xl font-bold">{{ $expense->expense_number }}</h1>
                <p class="text-sm text-text-muted">{{ __('expenses.expense') }} · {{ $expense->expense_date->toDateString() }}</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            @if($expense->status === 'posted')
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800">
                    {{ __('expenses.posted') }}
                </span>
            @else
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-rose-100 text-rose-800">
                    {{ __('expenses.reversed') }}
                </span>
            @endif
        </div>
    </div>

    @if(session()->has('success'))
        <div class="rounded-control border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->has('reversal'))
        <div class="rounded-control border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
            {{ $errors->first('reversal') }}
        </div>
    @endif

    <!-- Main overview card -->
    <div class="rounded-card border border-border bg-white p-6 space-y-6">
        <div class="grid gap-6 sm:grid-cols-2 md:grid-cols-3">
            <div>
                <span class="block text-xs font-semibold text-text-muted uppercase">{{ __('expenses.category') }}</span>
                <span class="text-base font-bold text-text-primary">
                    {{ app()->getLocale() === 'en' ? ($expense->category_snapshot['name_en'] ?: $expense->category_snapshot['name_ar']) : ($expense->category_snapshot['name_ar'] ?: $expense->category_snapshot['name_en']) }}
                </span>
            </div>
            <div>
                <span class="block text-xs font-semibold text-text-muted uppercase">{{ __('expenses.classification') }}</span>
                @if($expense->classification === 'landed_cost')
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-amber-100 text-amber-800">
                        {{ __('expenses.landed_cost') }}
                    </span>
                @else
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-slate-100 text-slate-700">
                        {{ __('expenses.operating') }}
                    </span>
                @endif
            </div>
            <div>
                <span class="block text-xs font-semibold text-text-muted uppercase">{{ __('expenses.amount') }}</span>
                <span class="text-xl font-bold text-text-primary" dir="ltr">
                    @if($detail['redact_cost'])
                        <span class="text-text-muted italic">{{ __('expenses.cost_redacted') }}</span>
                    @else
                        {{ $expense->amount }} {{ $expense->currency_code }}
                    @endif
                </span>
            </div>
            <div>
                <span class="block text-xs font-semibold text-text-muted uppercase">{{ __('expenses.payment_method') }}</span>
                <span class="text-sm font-semibold text-text-primary">
                    {{ __('expenses.'.$expense->payment_method) }}
                </span>
            </div>
            <div>
                <span class="block text-xs font-semibold text-text-muted uppercase">{{ __('expenses.account') }}</span>
                <span class="text-sm font-medium text-text-primary">
                    @if($expense->payment_method === 'check' && $detail['check'])
                        <a href="{{ route('money.checks.show', $detail['check']->public_id) }}" class="text-primary hover:underline font-semibold">
                            {{ $detail['check']->check_number }} ({{ $detail['check']->bank_name }})
                        </a>
                    @elseif($detail['moneyAccount'])
                        {{ $detail['moneyAccount']->displayName() }}
                    @else
                        -
                    @endif
                </span>
            </div>
            <div>
                <span class="block text-xs font-semibold text-text-muted uppercase">{{ __('expenses.payee') }}</span>
                <span class="text-sm font-medium text-text-primary">
                    @if($expense->vendor_snapshot)
                        @if($canViewVendor && $detail['vendor'] && !$detail['vendor']->trashed())<a href="{{ route('vendors.show', $detail['vendor']->public_id) }}" class="text-primary hover:underline">@endif
                            {{ app()->getLocale()==='en' ? ($expense->vendor_snapshot['name_en'] ?: $expense->vendor_snapshot['name_ar']) : ($expense->vendor_snapshot['name_ar'] ?: $expense->vendor_snapshot['name_en']) }}
                        @if($canViewVendor && $detail['vendor'] && !$detail['vendor']->trashed())</a>@endif
                    @elseif($expense->payee_name)
                        {{ $expense->payee_name }}
                    @else
                        -
                    @endif
                </span>
            </div>
        </div>

        <div>
            <span class="block text-xs font-semibold text-text-muted uppercase mb-1">{{ __('expenses.description') }}</span>
            <p class="text-sm text-text-secondary bg-surface-soft p-3 rounded-control border border-border">
                {{ $expense->description }}
            </p>
        </div>

        @if($expense->notes)
            <div>
                <span class="block text-xs font-semibold text-text-muted uppercase mb-1">{{ __('expenses.notes') }}</span>
                <p class="text-sm text-text-secondary bg-surface-soft p-3 rounded-control border border-border">
                    {{ $expense->notes }}
                </p>
            </div>
        @endif

        @if($expense->attachment_path)
            <div class="border-t border-border pt-4">
                <span class="block text-xs font-semibold text-text-muted uppercase mb-2">{{ __('expenses.attachment') }}</span>
                <a href="{{ route('attachments.expenses.download', $expense->public_id) }}" class="inline-flex items-center gap-2 rounded-control border border-border bg-white px-3 py-2 text-sm font-semibold text-primary hover:bg-surface-soft">
                    <x-icon name="document" class="w-4 h-4" />
                    <span>{{ $expense->attachment_name ?: __('expenses.download_attachment') }}</span>
                </a>
            </div>
        @endif
    </div>

    <!-- Landed Cost Allocations (if applicable) -->
    @if($expense->classification === 'landed_cost' && $detail['has_cost_view'])
        <div class="rounded-card border border-border bg-white p-6 space-y-4">
            <h2 class="font-bold text-base text-text-primary">{{ __('expenses.allocations') }}</h2>
            @if($detail['is_capitalized'])
                <div class="rounded-control bg-amber-50 border border-amber-200 p-3 text-xs text-amber-900">
                    {{ __('expenses.capitalized_notice') }}
                </div>
            @endif

            @if($detail['allocations']->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-start">
                        <thead class="bg-surface-soft border-b border-border text-xs text-text-muted">
                            <tr>
                                <th class="px-3 py-2 text-start">{{ __('expenses.purchase') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('purchasing.product') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('expenses.allocated_base') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('expenses.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach($detail['allocations'] as $alloc)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-primary">
                                        @if($canViewPurchase)<a href="{{ route('purchases.show', $alloc->purchase->public_id) }}" class="hover:underline">@endif
                                            {{ $alloc->purchase->purchase_number }}
                                        @if($canViewPurchase)</a>@endif
                                    </td>
                                    <td class="px-3 py-2 text-text-secondary">
                                        {{ app()->getLocale()==='en' ? ($alloc->purchaseLine->product_name_en ?: $alloc->purchaseLine->product_name_ar ?: $alloc->purchaseLine->item_description) : ($alloc->purchaseLine->product_name_ar ?: $alloc->purchaseLine->product_name_en ?: $alloc->purchaseLine->item_description) }}
                                    </td>
                                    <td class="px-3 py-2 font-semibold" dir="ltr">
                                        {{ $alloc->allocated_base }} {{ $baseCurrencyCode }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="text-xs font-semibold px-2 py-0.5 rounded {{ $alloc->status === 'locked' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                                            {{ __('expenses.allocation_'.$alloc->status) }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-text-muted">{{ __('expenses.empty') }}</p>
            @endif
        </div>
    @endif

    <!-- Reversal Section -->
    @if($canReverse)
        <div class="rounded-card border border-rose-200 bg-rose-50/40 p-6 space-y-4">
            <h2 class="font-bold text-base text-rose-900">{{ __('expenses.reverse') }}</h2>
            <p class="text-xs text-rose-700">{{ __('expenses.reverse_confirm') }}</p>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-semibold text-rose-900 mb-1">{{ __('money.date') }} *</label>
                    <input type="date" wire:model="reversalDate" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-rose-900 mb-1">{{ __('expenses.reversal_reason') }} *</label>
                    <input type="text" wire:model="reversalReason" placeholder="{{ __('expenses.reversal_reason') }}" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                </div>
            </div>

            <button type="button" wire:click="reverse" wire:confirm="{{ __('expenses.reverse_confirm') }}" class="inline-flex justify-center items-center rounded-control bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 focus:ring-2 focus:ring-rose-500">
                {{ __('expenses.reverse') }}
            </button>
        </div>
    @elseif($expense->status === 'reversed')
        <div class="rounded-card border border-border bg-surface-soft p-4 space-y-2">
            <span class="block text-xs font-semibold text-text-muted uppercase">{{ __('expenses.reversal_reason') }}</span>
            <p class="text-sm font-medium text-text-secondary">{{ $expense->reversal_reason }}</p>
            <span class="block text-xs text-text-muted">{{ $expense->reversed_at?->toDateTimeString() }}</span>
        </div>
    @endif
</div>
