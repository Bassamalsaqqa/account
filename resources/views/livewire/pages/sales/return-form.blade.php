<div class="max-w-4xl mx-auto space-y-6" x-data="{
        saving: false, documentBusy: false, operationFailed: false,
        run(method, args = [], terminal = false) {
            if (this.saving || window.isDocumentBlocked($wire)) return;
            if (terminal &amp;&amp; [...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0)) return;
            if (terminal) this.saving = true;
            this.operationFailed = false;
            window.enqueueDocumentOperation($wire, method, args)
                .catch(() => { this.operationFailed = true; })
                .finally(() => { if (terminal) this.saving = false; });
        }
    }" @document-operation-state.window="if ($event.detail.id === $wire.$id) documentBusy = $event.detail.busy">
    <x-draft-operation-feedback />
    <p x-cloak x-show="operationFailed" role="alert" class="text-sm text-danger">{{ __('camera.network_error') }}</p>
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
           class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
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
                        class="w-full min-h-11 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden font-mono" :disabled="documentBusy || saving">
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
                       class="w-full min-h-11 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"  :disabled="documentBusy || saving"/>
                @error('issue_date') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="md:col-span-3">
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.return_reason') }}
                </label>
                <input type="text"
                       wire:model="reason"
                       placeholder="{{ __('sales.return_reason_hint') }}"
                       class="w-full min-h-11 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"  :disabled="documentBusy || saving"/>
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
                <div class="md:hidden space-y-3 mt-4">
                    @foreach ($lines as $idx => $line)
                        <article class="rounded-card border border-border bg-white p-4 space-y-3" wire:key="mobile-return-{{ $line['sales_invoice_line_id'] }}">
                            <h3 class="font-bold text-sm">{{ $line['item_description'] }}</h3>
                            <p class="text-xs text-text-secondary">{{ $line['unit_name'] }}</p>
                            <dl class="grid grid-cols-3 gap-2 rounded-control bg-surface-soft p-3 text-xs">
                                <div><dt>{{ __('refinement.original_quantity') }}</dt><dd dir="ltr" class="mt-1 font-mono tabular-nums">{{ $line['original_quantity'] }}</dd></div>
                                <div><dt>{{ __('refinement.previously_returned') }}</dt><dd dir="ltr" class="mt-1 font-mono tabular-nums">{{ $line['already_returned_quantity'] }}</dd></div>
                                <div><dt>{{ __('refinement.remaining_quantity') }}</dt><dd dir="ltr" class="mt-1 font-mono tabular-nums font-bold text-primary">{{ $line['max_returnable'] }}</dd></div>
                            </dl>
                            <div class="flex items-center gap-2" dir="ltr">
    <button type="button" @click="run('stepReturnQuantity', [{{ $idx }}, 'down'])" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="min-h-11 min-w-11 rounded-control border border-border bg-white px-3 font-semibold disabled:opacity-50 focus-visible:ring-2 focus-visible:ring-primary" aria-label="{{ __('refinement.decrease_quantity') }}">-</button>
    <input type="text" inputmode="decimal" wire:model="lines.{{ $idx }}.return_quantity" dir="ltr" aria-label="{{ __('refinement.return_quantity') }}" class="min-h-11 min-w-0 w-full px-2 rounded-control border border-border text-center font-mono tabular-nums"  :disabled="documentBusy || saving"/>
    <button type="button" @click="run('stepReturnQuantity', [{{ $idx }}, 'up'])" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="min-h-11 min-w-11 rounded-control border border-border bg-white px-3 font-semibold disabled:opacity-50 focus-visible:ring-2 focus-visible:ring-primary" aria-label="{{ __('refinement.increase_quantity') }}">+</button>
</div>
<button type="button" @click="run('returnFullRemaining', [{{ $idx }}])" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="min-h-11 w-full mt-2 rounded-control border border-primary px-3 py-2 text-primary font-semibold disabled:opacity-50">{{ __('refinement.return_remaining') }}</button>
                            @error("lines.{$idx}.return_quantity") <p role="alert" class="text-sm text-danger">{{ $message }}</p> @enderror
                        </article>
                    @endforeach
                </div>
                <div class="hidden md:block overflow-x-auto mt-4">
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
                                        <div class="flex items-center gap-2" dir="ltr">
    <button type="button" @click="run('stepReturnQuantity', [{{ $idx }}, 'down'])" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="min-h-11 min-w-11 rounded-control border border-border bg-white px-3 font-semibold disabled:opacity-50 focus-visible:ring-2 focus-visible:ring-primary" aria-label="{{ __('refinement.decrease_quantity') }}">-</button>
    <input type="text" inputmode="decimal" wire:model="lines.{{ $idx }}.return_quantity" dir="ltr" aria-label="{{ __('refinement.return_quantity') }}" class="min-h-11 min-w-0 w-full px-2 rounded-control border border-border text-center font-mono tabular-nums"  :disabled="documentBusy || saving"/>
    <button type="button" @click="run('stepReturnQuantity', [{{ $idx }}, 'up'])" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="min-h-11 min-w-11 rounded-control border border-border bg-white px-3 font-semibold disabled:opacity-50 focus-visible:ring-2 focus-visible:ring-primary" aria-label="{{ __('refinement.increase_quantity') }}">+</button>
</div>
<button type="button" @click="run('returnFullRemaining', [{{ $idx }}])" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="min-h-11 w-full mt-2 rounded-control border border-primary px-3 py-2 text-primary font-semibold disabled:opacity-50">{{ __('refinement.return_remaining') }}</button>
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
            <textarea wire:model="notes" rows="2" class="w-full p-2.5 rounded-control border border-border bg-canvas text-xs text-text-primary outline-hidden" :disabled="documentBusy || saving"></textarea>
        </div>

        <!-- Buttons -->
        <p wire:loading role="status" class="text-sm font-semibold text-primary">{{ __('refinement.working') }}</p>
            <div class="sticky bottom-[calc(5rem+env(safe-area-inset-bottom))] z-20 flex flex-wrap items-center justify-end gap-3 border-t border-border bg-white p-3 shadow-sm md:static md:shadow-none">
            <a href="{{ route('returns.index') }}"
               class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary px-4 py-2 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                {{ __('sales.cancel') }}
            </a>
            <button type="button"
                    @click="run('save', [false], true)" :disabled="saving || window.isDocumentBlocked($wire)"
                    class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary px-4 py-2 rounded-control border border-primary text-primary hover:bg-primary-50 text-xs font-bold transition-colors cursor-pointer" wire:loading.attr="disabled">
                {{ __('sales.save_draft') }}
            </button>
            @if ($canPost)
                <button type="button"
                        @click="run('save', [true], true)" :disabled="saving || window.isDocumentBlocked($wire)"
                        class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary px-5 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm cursor-pointer" wire:loading.attr="disabled">
                    ⚡ {{ __('sales.post') }}
                </button>
            @endif
        </div>
    </div>
</div>
