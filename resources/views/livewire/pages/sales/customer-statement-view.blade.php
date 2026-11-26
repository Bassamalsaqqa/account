@php
    $statementScope = 'customer-statement-'.$this->getId();
@endphp
<div class="space-y-6">
    <!-- Header Card -->
    <div class="bg-surface rounded-card border border-border shadow-xs p-4 sm:p-6 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-xl md:text-2xl font-bold tracking-tight text-text-primary">
                    {{ __('sales.customer_statement') }}
                </h1>
                <p class="text-xs text-text-secondary mt-1">
                    {{ $customer->displayName() }} (<bdi dir="ltr" class="font-mono">{{ $customer->code ?? '—' }}</bdi>)
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <x-document-actions route-name="pdf.statement" :parameters="['publicId' => $customer->public_id, 'from' => $fromDate, 'to' => $toDate]" :permissions="['sales.document.pdf', 'sales.statement.view', 'customers.statement.view']" />
                <a href="{{ route('customers.show', $customer->public_id) }}"
                   class="touch-action inline-flex min-h-11 lg:min-h-9.5 items-center justify-center px-3 py-1.5 rounded-control border border-border-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
                    {{ __('sales.back') }}
                </a>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="grid grid-cols-1 sm:flex sm:flex-wrap sm:items-start gap-4 pt-4 border-t border-border text-xs">
            <div class="flex flex-col sm:flex-row sm:items-center gap-1.5 sm:gap-2 min-w-0">
                <label for="{{ $statementScope }}-from" class="font-bold text-text-primary shrink-0">{{ __('sales.from_date') }}:</label>
                <div class="min-w-0 space-y-1">
                    <input id="{{ $statementScope }}-from"
                           type="date"
                           dir="ltr"
                           wire:model.live="fromDate"
                           aria-invalid="{{ $errors->has('fromDate') ? 'true' : 'false' }}"
                           @error('fromDate') aria-describedby="{{ $statementScope }}-from-error" @enderror
                           class="min-h-11 lg:min-h-9.5 w-full sm:w-auto px-2.5 rounded-control border border-border-control bg-surface text-sm text-text-primary aria-invalid:border-danger" />
                    @error('fromDate')<p id="{{ $statementScope }}-from-error" class="text-xs font-semibold text-danger" role="alert">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="flex flex-col sm:flex-row sm:items-center gap-1.5 sm:gap-2 min-w-0">
                <label for="{{ $statementScope }}-to" class="font-bold text-text-primary shrink-0">{{ __('sales.to_date') }}:</label>
                <div class="min-w-0 space-y-1">
                    <input id="{{ $statementScope }}-to"
                           type="date"
                           dir="ltr"
                           wire:model.live="toDate"
                           aria-invalid="{{ $errors->has('toDate') ? 'true' : 'false' }}"
                           @error('toDate') aria-describedby="{{ $statementScope }}-to-error" @enderror
                           class="min-h-11 lg:min-h-9.5 w-full sm:w-auto px-2.5 rounded-control border border-border-control bg-surface text-sm text-text-primary aria-invalid:border-danger" />
                    @error('toDate')<p id="{{ $statementScope }}-to-error" class="text-xs font-semibold text-danger" role="alert">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>
    </div>

    <!-- Statement Blocks per Currency -->
    @forelse ($statements as $curr => $currencyStatement)
        <div class="bg-surface rounded-card border border-border shadow-xs p-4 sm:p-6 space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-border">
                <h2 class="text-lg font-bold text-text-primary">
                    {{ __('sales.currency') }}: {{ $curr }}
                </h2>
                <div class="text-xs flex flex-wrap items-center gap-1.5">
                    <span class="text-text-muted">{{ __('sales.closing_balance') }}:</span>
                    <bdi dir="ltr" class="tabular-nums font-bold text-base {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive((string) $currencyStatement['closing_balance']) ? 'text-danger' : 'text-text-primary' }}">
                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney((string) $currencyStatement['closing_balance'], $curr) }}
                    </bdi>
                </div>
            </div>

            <!-- Aging Analysis Breakdown -->
            <div>
                <div class="text-xs font-bold text-text-muted uppercase tracking-wider mb-2">
                    {{ __('sales.aging_analysis') }}
                </div><div class="p-3 text-xs">{{ __('sales.aging_unspecified') }}: <span dir="ltr">{{ $currencyStatement['aging']['unspecified'] }}</span></div>
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-center text-xs">
                    <div class="p-3 rounded-control bg-canvas border border-border">
                        <div class="text-xs text-text-muted font-bold">{{ __('sales.aging_current') }}</div>
                        <div class="font-bold text-text-primary mt-1" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney((string) $currencyStatement['aging']['current'], $curr) }}</div>
                    </div>
                    <div class="p-3 rounded-control bg-canvas border border-border">
                        <div class="text-xs text-text-muted font-bold">{{ __('sales.aging_1_30') }}</div>
                        <div class="font-bold text-text-primary mt-1" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney((string) $currencyStatement['aging']['days_1_30'], $curr) }}</div>
                    </div>
                    <div class="p-3 rounded-control bg-canvas border border-border">
                        <div class="text-xs text-text-muted font-bold">{{ __('sales.aging_31_60') }}</div>
                        <div class="font-bold text-text-primary mt-1" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney((string) $currencyStatement['aging']['days_31_60'], $curr) }}</div>
                    </div>
                    <div class="p-3 rounded-control bg-canvas border border-border">
                        <div class="text-xs text-text-muted font-bold">{{ __('sales.aging_61_90') }}</div>
                        <div class="font-bold text-text-primary mt-1" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney((string) $currencyStatement['aging']['days_61_90'], $curr) }}</div>
                    </div>
                    <div class="p-3 rounded-control bg-canvas border border-border">
                        <div class="text-xs text-text-muted font-bold">{{ __('sales.aging_90_plus') }}</div>
                        <div class="font-bold text-danger mt-1" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney((string) $currencyStatement['aging']['days_90_plus'], $curr) }}</div>
                    </div>
                </div>
            </div>

            <!-- Ledger Entries Table -->
            <div class="overflow-x-auto border border-border rounded-control">
                <table class="data-table w-full text-start text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-xs uppercase">
                            <th class="py-2.5 px-3 text-start">{{ __('sales.date') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.actions') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.debit') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.credit_col') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.running_balance') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr class="bg-surface-soft/40 italic font-bold">
                            <td class="py-2.5 px-3" colspan="4">{{ __('sales.opening_balance') }}</td>
                            <td class="py-2.5 px-3 font-mono font-bold" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney((string) $currencyStatement['opening_balance'], $curr) }}
                            </td>
                        </tr>
                        @forelse ($currencyStatement['entries'] as $entry)
                            <tr class="hover:bg-surface-soft/40">
                                <td class="py-2.5 px-3" dir="ltr">{{ $entry['date'] }}</td>
                                <td class="py-2.5 px-3">
                                    <span class="font-bold font-mono">{{ $entry['number'] }}</span>
                                    <span class="text-text-muted text-xs">({{ __('sales.statement_type_'.$entry['type']) }})</span>
                                </td>
                                <td class="py-2.5 px-3 font-mono" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive((string) $entry['debit']) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney((string) $entry['debit'], $curr) : '—' }}
                                </td>
                                <td class="py-2.5 px-3 font-mono" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive((string) $entry['credit']) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney((string) $entry['credit'], $curr) : '—' }}
                                </td>
                                <td class="py-2.5 px-3 font-mono font-bold" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney((string) $entry['balance'], $curr) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-4 text-center text-text-muted">
                                    {{ __('sales.no_transactions') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="bg-surface rounded-card border border-border shadow-xs p-8 text-center text-text-muted text-xs">
            {{ __('sales.no_statement_data') }}
        </div>
    @endforelse

    @if($canShare)
        <livewire:financial-share-manager
            subject-type="customer_statement"
            :subject-id="$customer->id"
            :initial-from="$fromDate"
            :initial-to="$toDate"
            :key="'financial-share-'.$customer->public_id" />
    @endif
</div>
