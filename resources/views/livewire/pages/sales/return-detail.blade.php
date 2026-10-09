<div class="space-y-6">
    <!-- Header Card -->
    <div class="bg-white rounded-card border border-border shadow-xs p-6 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-mono font-bold tracking-tight text-text-primary" dir="ltr">
                        {{ $return->return_number ?? __('sales.draft') }}
                    </h1>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold
                        @if ($return->status === 'posted') bg-success-bg text-success
                        @elseif ($return->status === 'void') bg-danger-bg text-danger
                        @else bg-slate-100 text-slate-600 @endif">
                        {{ __('sales.' . $return->status) }}
                    </span>
                </div>
                <div class="text-xs text-text-secondary mt-1">
                    {{ __('sales.customer') }}:
                    <a href="{{ route('customers.show', $return->customer->public_id) }}" class="font-bold text-text-primary hover:text-primary">
                        {{ $return->customer->displayName() }}
                    </a>
                    @if ($return->salesInvoice)
                        | {{ __('sales.original_invoice') }}:
                        <a href="{{ route('invoices.show', $return->salesInvoice->public_id) }}" class="font-mono text-primary hover:underline">
                            {{ $return->salesInvoice->invoice_number }}
                        </a>
                    @endif
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-wrap items-center gap-2">
                @if ($canPost)
                    <button type="button"
                            wire:click="postReturn"
                            wire:confirm="{{ __('sales.confirm_post_return') }}"
                            class="px-3.5 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer">
                        ⚡ {{ __('sales.post') }}
                    </button>
                @endif

                @if ($canVoid)
                    <button type="button"
                            wire:click="$set('showVoidModal', true)"
                            class="px-3 py-1.5 rounded-control bg-danger-bg text-danger border border-danger/30 text-xs font-bold hover:bg-danger hover:text-white transition-colors cursor-pointer">
                        ✕ {{ __('sales.void') }}
                    </button>
                @endif

                <x-document-actions route-name="pdf.return" :parameters="['publicId' => $return->public_id]" :permissions="['sales.document.pdf', 'sales.return.view']" />



                <a href="{{ route('returns.index') }}"
                   class="px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
                    {{ __('sales.back') }}
                </a>
            </div>
        </div>



        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 pt-4 border-t border-border text-xs">
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.date') }}</span>
                <span class="font-bold text-text-primary" dir="ltr">{{ $return->issue_date ? $return->issue_date->toDateString() : '—' }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.original_invoice') }}</span>
                <span class="font-mono font-bold text-text-primary" dir="ltr">{{ $return->salesInvoice?->invoice_number ?? '—' }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.currency') }}</span>
                <span class="font-bold text-text-primary">{{ $return->currency_code }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.grand_total') }}</span>
                <span class="font-mono font-bold text-danger text-sm" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($return->grand_total, $return->currency_code) }} {{ $return->currency_code }}</span>
            </div>
        </div>
    </div>

    <!-- Lines Table -->
    <div class="bg-white rounded-card border border-border shadow-xs overflow-hidden">
        <div class="p-4 border-b border-border font-bold text-sm text-text-primary">
            {{ __('sales.return_details') }}
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                        <th class="py-2.5 px-4 text-start">#</th>
                        <th class="py-2.5 px-4 text-start">{{ __('sales.product') }}</th>
                        <th class="py-2.5 px-4 text-start">{{ __('sales.returned_quantity') }}</th>
                        <th class="py-2.5 px-4 text-start">{{ __('sales.unit_price') }}</th>
                        <th class="py-2.5 px-4 text-start">{{ __('sales.tax') }}</th>
                        <th class="py-2.5 px-4 text-end">{{ __('sales.total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($return->lines as $line)
                        <tr class="hover:bg-surface-soft/40">
                            <td class="py-2.5 px-4 text-text-muted">{{ $line->line_number }}</td>
                            <td class="py-2.5 px-4">
                                <div class="font-bold text-text-primary">{{ $line->item_description }}</div>
                            </td>
                            <td class="py-2.5 px-4 font-mono font-bold text-danger" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line->quantity) }} {{ $line->unit_name_ar ?? '' }}
                            </td>
                            <td class="py-2.5 px-4 font-mono" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line->unit_price, $return->currency_code) }}
                            </td>
                            <td class="py-2.5 px-4 font-mono" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($line->tax_amount) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line->tax_amount, $return->currency_code) : '—' }}
                            </td>
                            <td class="py-2.5 px-4 font-mono font-bold text-end text-danger" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line->line_total, $return->currency_code) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="p-6 bg-surface-soft/40 border-t border-border flex flex-col md:flex-row justify-between gap-6 text-xs">
            <div class="space-y-3 max-w-md">
                @if ($return->reason)
                    <div>
                        <span class="font-bold text-text-primary block">{{ __('sales.return_reason') }}:</span>
                        <p class="text-text-secondary text-[11px]">{{ $return->reason }}</p>
                    </div>
                @endif
                @if ($return->notes)
                    <div>
                        <span class="font-bold text-text-primary block">{{ __('sales.notes') }}:</span>
                        <p class="text-text-secondary text-[11px]">{{ $return->notes }}</p>
                    </div>
                @endif
                @if ($return->void_reason)
                    <div class="p-2.5 bg-danger-bg border border-danger/20 rounded-control text-danger">
                        <span class="font-bold block">{{ __('sales.void_reason') }}:</span>
                        <p class="text-[11px] mt-0.5">{{ $return->void_reason }}</p>
                    </div>
                @endif
            </div>

            <div class="w-full md:w-64 space-y-2">
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.subtotal') }}:</span>
                    <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($return->subtotal, $return->currency_code) }} {{ $return->currency_code }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.tax') }}:</span>
                    <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($return->tax_total, $return->currency_code) }} {{ $return->currency_code }}</span>
                </div>
                <div class="flex justify-between py-2 border-t border-border text-base font-extrabold text-danger">
                    <span>{{ __('sales.grand_total') }}:</span>
                    <span class="font-mono" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($return->grand_total, $return->currency_code) }} {{ $return->currency_code }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Void Modal -->
    @if ($showVoidModal)
        <div class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-card border border-border shadow-xl max-w-md w-full p-6 space-y-4">
                <h3 class="font-bold text-base text-danger">
                    {{ __('sales.confirm_void_invoice') }}
                </h3>
                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block font-bold text-text-primary mb-1">{{ __('sales.void_reason') }} <span class="text-danger">*</span></label>
                        <textarea wire:model="voidReason" rows="3" required class="w-full p-2.5 rounded-control border border-border text-xs"></textarea>
                        @error('voidReason') <span class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-border">
                    <button type="button" wire:click="$set('showVoidModal', false)" class="px-3 py-1.5 rounded-control border border-border text-xs font-bold">
                        {{ __('sales.cancel') }}
                    </button>
                    <button type="button" wire:click="voidReturn" class="px-4 py-1.5 rounded-control bg-danger text-white text-xs font-bold">
                        {{ __('sales.void') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Share Modal -->


    @if($return->isPosted() && collect(['sales.document.share', 'sales.return.view'])->every(fn ($permission) => auth()->user()->can($permission)))
        <livewire:financial-share-manager subject-type="sales_return" :subject-id="$return->id" :key="'financial-share-'.$return->public_id" />
    @endif
</div>
