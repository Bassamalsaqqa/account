<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ __('sales.new_return') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('sales.return_details') }}
            </p>
        </div>

        <a href="{{ route('returns.index') }}"
           class="px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
            {{ __('sales.back') }}
        </a>
    </div>

    <!-- Main Form -->
    <div class="bg-white rounded-card border border-border shadow-xs p-6 space-y-6">
        <!-- Invoice & Date Selection -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pb-4 border-b border-border">
            <div class="md:col-span-2">
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.original_invoice') }} <span class="text-danger">*</span>
                </label>
                <select wire:model.live="sales_invoice_id"
                        required
                        class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden font-mono">
                    <option value="">-- {{ __('sales.original_invoice') }} --</option>
                    @foreach ($invoices as $inv)
                        <option value="{{ $inv->id }}">
                            {{ $inv->invoice_number }} - {{ $inv->customer->displayName() }} ({{ $inv->issue_date ? $inv->issue_date->toDateString() : '—' }} / {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($inv->grand_total_currency ?? $inv->grand_total, $inv->currency_code) }} {{ $inv->currency_code }})
                        </option>
                    @endforeach
                </select>
                @error('sales_invoice_id') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.date') }} <span class="text-danger">*</span>
                </label>
                <input type="date"
                       wire:model="issue_date"
                       required
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                @error('issue_date') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="md:col-span-3">
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.return_reason') }}
                </label>
                <input type="text"
                       wire:model="reason"
                       placeholder="{{ __('sales.return_reason_hint') }}"
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
            </div>
        </div>

        <!-- Lines Section -->
        <div>
            <h2 class="text-sm font-bold text-text-primary pb-2 border-b border-border">
                {{ __('sales.return_details') }}
            </h2>

            @error('lines') <span class="text-danger text-xs font-bold mt-2 block">{{ $message }}</span> @enderror

            @if (empty($lines))
                <div class="py-8 text-center text-text-muted text-xs">
                    {{ $sales_invoice_id ? __('sales.all_items_returned') : __('sales.select_return_invoice') }}
                </div>
            @else
                <div class="overflow-x-auto mt-4">
                    <table class="w-full text-start text-xs border-collapse">
                        <thead>
                            <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                                <th class="py-2.5 px-3 text-start">{{ __('sales.product') }}</th>
                                <th class="py-2.5 px-3 text-start">{{ __('sales.quantity') }}</th>
                                <th class="py-2.5 px-3 text-start">{{ __('sales.max_returnable') }}</th>
                                <th class="py-2.5 px-3 text-start">{{ __('sales.unit_price') }}</th>
                                <th class="py-2.5 px-3 text-end w-36">{{ __('sales.returned_quantity') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($lines as $idx => $line)
                                <tr>
                                    <td class="py-2.5 px-3 font-bold text-text-primary">
                                        {{ $line['item_description'] }}
                                    </td>
                                    <td class="py-2.5 px-3 font-mono" dir="ltr">
                                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['original_quantity']) }} {{ $line['unit_name'] }}
                                    </td>
                                    <td class="py-2.5 px-3 font-mono font-bold text-primary" dir="ltr">
                                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['max_returnable']) }}
                                    </td>
                                    <td class="py-2.5 px-3 font-mono" dir="ltr">
                                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line['unit_price']) }}
                                    </td>
                                    <td class="py-2.5 px-3 text-end">
                                        <input type="number"
                                               step="any"
                                               wire:model="lines.{{ $idx }}.return_quantity"
                                               min="0"
                                               max="{{ $line['max_returnable'] }}"
                                               dir="ltr"
                                               class="w-28 h-8 px-2 rounded-control border border-border bg-canvas text-xs font-mono text-end font-bold text-danger" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div>
            <label class="block text-xs font-bold text-text-primary mb-1">
                {{ __('sales.notes') }}
            </label>
            <textarea wire:model="notes" rows="2" class="w-full p-2.5 rounded-control border border-border bg-canvas text-xs text-text-primary outline-hidden"></textarea>
        </div>

        <!-- Buttons -->
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
            <a href="{{ route('returns.index') }}"
               class="px-4 py-2 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                {{ __('sales.cancel') }}
            </a>
            <button type="button"
                    wire:click="save(false)"
                    class="px-4 py-2 rounded-control border border-primary text-primary hover:bg-primary-50 text-xs font-bold transition-colors cursor-pointer">
                {{ __('sales.save_draft') }}
            </button>
            @if ($canPost)
                <button type="button"
                        wire:click="save(true)"
                        class="px-5 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm cursor-pointer">
                    ⚡ {{ __('sales.post') }}
                </button>
            @endif
        </div>
    </div>
</div>
