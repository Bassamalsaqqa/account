<div class="space-y-6">
    @if(session()->has('error'))
        <p role="alert" class="text-sm text-danger font-semibold bg-danger-bg p-3 rounded-control border border-danger/20">{{ session('error') }}</p>
    @endif
    @if(session()->has('success'))
        <p role="status" class="text-sm text-success font-semibold bg-success-bg p-3 rounded-control border border-success/20">{{ session('success') }}</p>
    @endif
    @foreach($this->correctionHistory as $link)
        <a href="{{ $link['url'] }}" class="touch-action text-sm underline">{{ $link['label'] }}</a>
    @endforeach
    @if($correctionOpen)
        <x-correction-preview :correction-mode="$correctionMode" :correction-preview="$correctionPreview" />
    @endif

    <!-- Header Card -->
    <div class="bg-surface rounded-card border border-border shadow-xs p-6 space-y-4">
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
                    <a href="{{ route('customers.show', $return->customer->public_id) }}" class="touch-action font-bold text-text-primary hover:text-primary">
                        {{ $return->customer->displayName() }}
                    </a>
                    @if ($return->salesInvoice)
                        | {{ __('sales.original_invoice') }}:
                        <a href="{{ route('invoices.show', $return->salesInvoice->public_id) }}" class="touch-action font-mono text-primary hover:underline">
                            {{ $return->salesInvoice->invoice_number }}
                        </a>
                    @endif
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-wrap items-center gap-2">
                <x-correction-shortcuts family="sales_return" :public-id="$return->public_id" :status="$return->status" :detail="true" />
                @if($return->isDraft() && auth()->user()->can('sales.return.manage'))
                    @can('sales.return.create')
                        <x-row-action :href="route('returns.edit', $return->public_id)" :label="__('corrections.edit')" icon="edit" />
                    @endcan
                    <x-row-action wire:click="deleteReturnDraft" wire:confirm="{{ __('sales.delete_draft_confirm') }}" wire:loading.attr="disabled" :label="__('corrections.delete')" icon="trash" tone="danger" />
                @endif

                @if ($canPost)
                    <button type="button"
                            wire:click="postReturn"
                            wire:confirm="{{ __('sales.confirm_post_return') }}"
                            class="px-3.5 py-1.5 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer" wire:loading.attr="disabled" wire:target="postReturn">
                        ⚡ {{ __('sales.post') }}
                    </button>
                @endif

                <x-document-actions route-name="pdf.return" :parameters="['publicId' => $return->public_id]" :permissions="['sales.document.pdf', 'sales.return.view']" />



                <a href="{{ route('returns.index') }}"
                   class="touch-action px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-sm font-bold transition-colors">
                    {{ __('sales.back') }}
                </a>
            </div>
        </div>



        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 pt-4 border-t border-border text-xs">
            <div>
                <span class="text-text-muted block text-xs">{{ __('sales.date') }}</span>
                <span class="font-bold text-text-primary" dir="ltr">{{ $return->issue_date ? $return->issue_date->toDateString() : '—' }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-xs">{{ __('sales.original_invoice') }}</span>
                <span class="font-mono font-bold text-text-primary" dir="ltr">{{ $return->salesInvoice?->invoice_number ?? '—' }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-xs">{{ __('sales.currency') }}</span>
                <span class="font-bold text-text-primary">{{ $return->currency_code }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-xs">{{ __('sales.grand_total') }}</span>
                <span class="font-mono font-bold text-danger text-sm" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($return->grand_total, $return->currency_code) }} {{ $return->currency_code }}</span>
            </div>
        </div>
    </div>

    <!-- Lines Table -->
    <div class="bg-surface rounded-card border border-border shadow-xs overflow-hidden">
        <div class="p-4 border-b border-border font-bold text-sm text-text-primary">
            {{ __('sales.return_details') }}
        </div>
        <div class="overflow-x-auto">
            <table class="data-table w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-xs uppercase">
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
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line->unit_price, $return->currency_code) }}
                            </td>
                            <td class="py-2.5 px-4 font-mono" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($line->tax_amount) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line->tax_amount, $return->currency_code) : '—' }}
                            </td>
                            <td class="py-2.5 px-4 font-mono font-bold text-end text-danger" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line->line_total, $return->currency_code) }}
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
                        <p class="text-text-secondary text-xs">{{ $return->reason }}</p>
                    </div>
                @endif
                @if ($return->notes)
                    <div>
                        <span class="font-bold text-text-primary block">{{ __('sales.notes') }}:</span>
                        <p class="text-text-secondary text-xs">{{ $return->notes }}</p>
                    </div>
                @endif
                @if ($return->void_reason)
                    <div class="p-2.5 bg-danger-bg border border-danger/20 rounded-control text-danger">
                        <span class="font-bold block">{{ __('sales.void_reason') }}:</span>
                        <p class="text-xs mt-0.5">{{ $return->void_reason }}</p>
                    </div>
                @endif
            </div>

            <div class="w-full md:w-64 space-y-2">
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.subtotal') }}:</span>
                    <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($return->subtotal, $return->currency_code) }} {{ $return->currency_code }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.tax') }}:</span>
                    <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($return->tax_total, $return->currency_code) }} {{ $return->currency_code }}</span>
                </div>
                <div class="flex justify-between py-2 border-t border-border text-base font-extrabold text-danger">
                    <span>{{ __('sales.grand_total') }}:</span>
                    <span class="font-mono" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($return->grand_total, $return->currency_code) }} {{ $return->currency_code }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Void Modal -->
    @if ($showVoidModal)
        <x-dialog-shell :title="__('sales.confirm_void_invoice')" title-id="return-void-modal-title" close-action="$set('showVoidModal', false)" save-target="voidReturn" max-width="max-w-md">
            <div class="space-y-3 text-sm">
                <div>
                    <label for="return-void-reason" class="block font-bold text-text-primary mb-1">{{ __('sales.void_reason') }} <span class="text-danger">*</span></label>
                    <textarea id="return-void-reason"
                              wire:model="voidReason"
                              rows="3"
                              required
                              aria-invalid="{{ $errors->has('voidReason') ? 'true' : 'false' }}"
                              @if ($errors->has('voidReason')) aria-describedby="return-void-reason-error" @endif
                              class="w-full p-2.5 rounded-control border border-border text-sm"></textarea>
                    @error('voidReason') <span id="return-void-reason-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                </div>
            </div>
            <x-slot:footer>
                <x-secondary-button type="button" wire:click="$set('showVoidModal', false)" wire:loading.attr="disabled">
                    {{ __('sales.cancel') }}
                </x-secondary-button>
                <x-danger-button type="button" wire:click="voidReturn" wire:loading.attr="disabled" wire:target="voidReturn">
                    {{ __('sales.void') }}
                </x-danger-button>
            </x-slot:footer>
        </x-dialog-shell>
    @endif

    <!-- Share Modal -->


    @if($return->isPosted() && collect(['sales.document.share', 'sales.return.view'])->every(fn ($permission) => auth()->user()->can($permission)))
        <livewire:financial-share-manager subject-type="sales_return" :subject-id="$return->id" :key="'financial-share-'.$return->public_id" />
    @endif
</div>
