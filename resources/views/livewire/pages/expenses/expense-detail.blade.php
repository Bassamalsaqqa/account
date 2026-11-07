<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <a href="{{ route('expenses.index') }}" class="touch-action inline-flex items-center justify-center w-8 h-8 rounded-control border border-border bg-surface text-text-secondary hover:bg-surface-soft">
                &larr;
            </a>
            <div>
                <h1 class="text-2xl font-bold">{{ $expense->expense_number }}</h1>
                <p class="text-sm text-text-muted">{{ __('expenses.expense') }} · {{ $expense->expense_date->toDateString() }}</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            @if($expense->status === 'posted')
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-sm font-semibold bg-success-bg text-success">
                    {{ __('expenses.posted') }}
                </span>
            @else
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-sm font-semibold bg-danger-bg text-danger">
                    {{ __('expenses.reversed') }}
                </span>
            @endif
        </div>
    </div>

    @if(session()->has('success'))
        <div class="rounded-control border border-success/20 bg-success-bg p-4 text-sm text-success">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->has('reversal'))
        <div class="rounded-control border border-danger/20 bg-danger-bg p-4 text-sm text-danger">
            {{ $errors->first('reversal') }}
        </div>
    @endif

    <!-- Main overview card -->
    <div class="rounded-card border border-border bg-surface p-6 space-y-6">
        <div class="grid gap-6 sm:grid-cols-2 md:grid-cols-3">
            <div>
                <span class="block text-sm font-semibold text-text-muted uppercase">{{ __('expenses.category') }}</span>
                <span class="text-base font-bold text-text-primary">
                    {{ app()->getLocale() === 'en' ? ($expense->category_snapshot['name_en'] ?: $expense->category_snapshot['name_ar']) : ($expense->category_snapshot['name_ar'] ?: $expense->category_snapshot['name_en']) }}
                </span>
            </div>
            <div>
                <span class="block text-sm font-semibold text-text-muted uppercase">{{ __('expenses.classification') }}</span>
                @if($expense->classification === 'landed_cost')
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-sm font-semibold bg-warning-bg text-warning">
                        {{ __('expenses.landed_cost') }}
                    </span>
                @else
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-sm font-semibold bg-surface-soft text-text-secondary">
                        {{ __('expenses.operating') }}
                    </span>
                @endif
            </div>
            <div>
                <span class="block text-sm font-semibold text-text-muted uppercase">{{ __('expenses.amount') }}</span>
                <span class="text-xl font-bold text-text-primary" dir="ltr">
                    @if($detail['redact_cost'])
                        <span class="text-text-muted italic">{{ __('expenses.cost_redacted') }}</span>
                    @else
                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($expense->amount) }} {{ $expense->currency_code }}
                    @endif
                </span>
            </div>
            <div>
                <span class="block text-sm font-semibold text-text-muted uppercase">{{ __('expenses.payment_method') }}</span>
                <span class="text-sm font-semibold text-text-primary">
                    {{ __('expenses.'.$expense->payment_method) }}
                </span>
            </div>
            <div>
                <span class="block text-sm font-semibold text-text-muted uppercase">{{ __('expenses.account') }}</span>
                <span class="text-sm font-medium text-text-primary">
                    @if($expense->payment_method === 'check' && $detail['check'])
                        <a href="{{ route('money.checks.show', $detail['check']->public_id) }}" class="touch-action text-primary hover:underline font-semibold">
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
                <span class="block text-sm font-semibold text-text-muted uppercase">{{ __('expenses.payee') }}</span>
                <span class="text-sm font-medium text-text-primary">
                    @if($expense->vendor_snapshot)
                        @if($canViewVendor && $detail['vendor'] && !$detail['vendor']->trashed())<a href="{{ route('vendors.show', $detail['vendor']->public_id) }}" class="touch-action text-primary hover:underline">@endif
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
            <span class="block text-sm font-semibold text-text-muted uppercase mb-1">{{ __('expenses.description') }}</span>
            <p class="text-sm text-text-secondary bg-surface-soft p-3 rounded-control border border-border">
                {{ $expense->description }}
            </p>
        </div>

        @if($expense->notes)
            <div>
                <span class="block text-sm font-semibold text-text-muted uppercase mb-1">{{ __('expenses.notes') }}</span>
                <p class="text-sm text-text-secondary bg-surface-soft p-3 rounded-control border border-border">
                    {{ $expense->notes }}
                </p>
            </div>
        @endif

        @if($expense->attachment_path)
            <div class="border-t border-border pt-4">
                <span class="block text-sm font-semibold text-text-muted uppercase mb-2">{{ __('expenses.attachment') }}</span>
                <a href="{{ route('attachments.expenses.download', $expense->public_id) }}" class="touch-action inline-flex items-center gap-2 rounded-control border border-border bg-surface px-3 py-2 text-sm font-semibold text-primary hover:bg-surface-soft">
                    <x-icon name="document" class="w-4 h-4" />
                    <span>{{ $expense->attachment_name ?: __('expenses.download_attachment') }}</span>
                </a>
            </div>
        @endif
    </div>

    <!-- Landed Cost Allocations (if applicable) -->
    @if($expense->classification === 'landed_cost' && $detail['has_cost_view'])
        <div class="rounded-card border border-border bg-surface p-6 space-y-4">
            <h2 class="font-bold text-base text-text-primary">{{ __('expenses.allocations') }}</h2>
            @if($detail['is_capitalized'])
                <div class="rounded-control bg-warning-bg border border-warning/20 p-3 text-sm text-warning">
                    {{ __('expenses.capitalized_notice') }}
                </div>
            @endif

            @if($detail['allocations']->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="data-table w-full text-sm text-start">
                        <thead class="bg-surface-soft border-b border-border text-sm text-text-muted">
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
                                        @if($canViewPurchase)<a href="{{ route('purchases.show', $alloc->purchase->public_id) }}" class="touch-action hover:underline">@endif
                                            {{ $alloc->purchase->purchase_number }}
                                        @if($canViewPurchase)</a>@endif
                                    </td>
                                    <td class="px-3 py-2 text-text-secondary">
                                        {{ app()->getLocale()==='en' ? ($alloc->purchaseLine->product_name_en ?: $alloc->purchaseLine->product_name_ar ?: $alloc->purchaseLine->item_description) : ($alloc->purchaseLine->product_name_ar ?: $alloc->purchaseLine->product_name_en ?: $alloc->purchaseLine->item_description) }}
                                    </td>
                                    <td class="px-3 py-2 font-semibold" dir="ltr">
                                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($alloc->allocated_base) }} {{ $baseCurrencyCode }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="text-sm font-semibold px-2 py-0.5 rounded {{ $alloc->status === 'locked' ? 'bg-success-bg text-success' : 'bg-surface-soft text-text-secondary' }}">
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
        <x-secondary-button wire:click="openReversalReview">{{ __('expenses.reverse') }}</x-secondary-button>
    @elseif($expense->status === 'reversed')
        <div class="rounded-card border border-border bg-surface-soft p-4 space-y-2">
            <span class="block text-sm font-semibold text-text-muted uppercase">{{ __('expenses.reversal_reason') }}</span>
            <p class="text-sm font-medium text-text-secondary">{{ $expense->reversal_reason }}</p>
            <span class="block text-sm text-text-muted">{{ $expense->reversed_at?->toDateTimeString() }}</span>
        </div>
    @endif
<x-standalone-reversal-review />
</div>
