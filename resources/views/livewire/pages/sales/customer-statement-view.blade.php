<div class="space-y-6">
    <!-- Header Card -->
    <div class="bg-white rounded-card border border-border shadow-xs p-6 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                    {{ __('sales.customer_statement') }}
                </h1>
                <p class="text-xs text-text-secondary mt-1">
                    {{ $customer->displayName() }} ({{ $customer->code ?? '—' }})
                </p>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('pdf.statement', ['publicId' => $customer->public_id, 'from' => $fromDate, 'to' => $toDate]) }}"
                   target="_blank"
                   class="px-3.5 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs flex items-center gap-1.5">
                    📄 {{ __('sales.download_pdf') }}
                </a>
                <a href="{{ route('customers.show', $customer->public_id) }}"
                   class="px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
                    {{ __('sales.back') }}
                </a>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="flex flex-wrap items-center gap-4 pt-4 border-t border-border text-xs">
            <div class="flex items-center gap-2">
                <label class="font-bold text-text-primary">{{ __('sales.from_date') }}:</label>
                <input type="date" wire:model.live="fromDate" class="h-8 px-2.5 rounded-control border border-border bg-canvas text-xs text-text-primary" />
            </div>
            <div class="flex items-center gap-2">
                <label class="font-bold text-text-primary">{{ __('sales.to_date') }}:</label>
                <input type="date" wire:model.live="toDate" class="h-8 px-2.5 rounded-control border border-border bg-canvas text-xs text-text-primary" />
            </div>
        </div>
    </div>

    @can('sales.document.share')
        <div class="bg-white rounded-card border border-border p-4 flex flex-wrap gap-3 items-center text-xs">
            <label>{{ __('sales.share_expires_in') }} <input type="number" wire:model="shareExpiryDays" min="0" max="3650" class="w-20 rounded-control border-border"></label>
            <label>{{ __('sales.password_protected') }} <input type="password" wire:model="sharePassword" class="rounded-control border-border"></label>
            <button type="button" wire:click="createShareLink" class="bg-primary text-white rounded-control px-3 py-2">{{ __('sales.create_share') }}</button>
            @if($shareUrl)
                <a href="{{ $shareUrl }}" target="_blank" class="text-primary">{{ __('sales.share_link') }}</a>
                <button type="button" wire:click="revokeShareLink" class="text-danger">{{ __('sales.revoke_share') }}</button>
            @endif
        </div>
    @endcan

    <!-- Statement Blocks per Currency -->
    @forelse ($statements as $curr => $currencyStatement)
        <div class="bg-white rounded-card border border-border shadow-xs p-6 space-y-6">
            <div class="flex items-center justify-between pb-3 border-b border-border">
                <h2 class="text-lg font-bold text-text-primary">
                    {{ __('sales.currency') }}: {{ $curr }}
                </h2>
                <div class="text-xs" dir="ltr">
                    <span class="text-text-muted">{{ __('sales.closing_balance') }}:</span>
                    <span class="font-bold text-base {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive((string) $currencyStatement['closing_balance']) ? 'text-danger' : 'text-text-primary' }}">
                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency((string) $currencyStatement['closing_balance'], $curr) }}
                    </span>
                </div>
            </div>

            <!-- Aging Analysis Breakdown -->
            <div>
                <div class="text-[11px] font-bold text-text-muted uppercase tracking-wider mb-2">
                    {{ __('sales.aging_analysis') }}
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-center text-xs">
                    <div class="p-3 rounded-control bg-canvas border border-border">
                        <div class="text-[10px] text-text-muted font-bold">{{ __('sales.aging_current') }}</div>
                        <div class="font-bold text-text-primary mt-1" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format((string) $currencyStatement['aging']['current'], $curr) }}</div>
                    </div>
                    <div class="p-3 rounded-control bg-canvas border border-border">
                        <div class="text-[10px] text-text-muted font-bold">{{ __('sales.aging_1_30') }}</div>
                        <div class="font-bold text-text-primary mt-1" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format((string) $currencyStatement['aging']['days_1_30'], $curr) }}</div>
                    </div>
                    <div class="p-3 rounded-control bg-canvas border border-border">
                        <div class="text-[10px] text-text-muted font-bold">{{ __('sales.aging_31_60') }}</div>
                        <div class="font-bold text-text-primary mt-1" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format((string) $currencyStatement['aging']['days_31_60'], $curr) }}</div>
                    </div>
                    <div class="p-3 rounded-control bg-canvas border border-border">
                        <div class="text-[10px] text-text-muted font-bold">{{ __('sales.aging_61_90') }}</div>
                        <div class="font-bold text-text-primary mt-1" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format((string) $currencyStatement['aging']['days_61_90'], $curr) }}</div>
                    </div>
                    <div class="p-3 rounded-control bg-canvas border border-border">
                        <div class="text-[10px] text-text-muted font-bold">{{ __('sales.aging_90_plus') }}</div>
                        <div class="font-bold text-danger mt-1" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format((string) $currencyStatement['aging']['days_90_plus'], $curr) }}</div>
                    </div>
                </div>
            </div>

            <!-- Ledger Entries Table -->
            <div class="overflow-x-auto border border-border rounded-control">
                <table class="w-full text-start text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
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
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format((string) $currencyStatement['opening_balance'], $curr) }}
                            </td>
                        </tr>
                        @forelse ($currencyStatement['entries'] as $entry)
                            <tr class="hover:bg-surface-soft/40">
                                <td class="py-2.5 px-3" dir="ltr">{{ $entry['date'] }}</td>
                                <td class="py-2.5 px-3">
                                    <span class="font-bold font-mono">{{ $entry['number'] }}</span>
                                    <span class="text-text-muted text-[11px]">({{ __('sales.statement_type_'.$entry['type']) }})</span>
                                </td>
                                <td class="py-2.5 px-3 font-mono" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive((string) $entry['debit']) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::format((string) $entry['debit'], $curr) : '—' }}
                                </td>
                                <td class="py-2.5 px-3 font-mono" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive((string) $entry['credit']) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::format((string) $entry['credit'], $curr) : '—' }}
                                </td>
                                <td class="py-2.5 px-3 font-mono font-bold" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format((string) $entry['balance'], $curr) }}
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
        <div class="bg-white rounded-card border border-border shadow-xs p-8 text-center text-text-muted text-xs">
            {{ __('sales.no_statement_data') }}
        </div>
    @endforelse
</div>
