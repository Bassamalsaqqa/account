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
    <x-form-attention />
    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ $draftPublicId ? __('corrections.edit') : __('sales.new_return') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('sales.return_details') }}
            </p>
        </div>

        <a href="{{ route('returns.index') }}"
           class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-sm font-bold transition-colors">
            {{ __('sales.back') }}
        </a>
    </div>

    <!-- Main Form -->
    <div class="bg-surface rounded-card border border-border shadow-xs p-4 sm:p-6 space-y-6">
        <!-- Invoice & Date Selection -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pb-4 border-b border-border">
            <div class="md:col-span-2">
                <label for="sales-return-invoice" class="block text-sm font-bold text-text-primary mb-1">
                    {{ __('sales.original_invoice') }} <span class="text-danger">*</span>
                </label>
                <select id="sales-return-invoice"
                        wire:model.live="sales_invoice_id"
                        @disabled($draftPublicId !== null)
                        @error('sales_invoice_id') aria-invalid="true" aria-describedby="sales-return-invoice-error" @enderror
                        required
                        class="w-full min-h-11 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden font-mono"
                        :disabled="{{ $draftPublicId !== null ? 'true' : 'false' }} || documentBusy || saving">
                    <option value="">-- {{ __('sales.original_invoice') }} --</option>
                    @foreach ($invoices as $inv)
                        <option value="{{ $inv->id }}">
                            {{ $inv->invoice_number }} - {{ $inv->customer->displayName() }} ({{ $inv->issue_date ? $inv->issue_date->toDateString() : '—' }} / {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($inv->grand_total_currency ?? $inv->grand_total, $inv->currency_code) }} {{ $inv->currency_code }})
                        </option>
                    @endforeach
                </select>
                @error('sales_invoice_id') <span id="sales-return-invoice-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label for="sales-return-date" class="block text-sm font-bold text-text-primary mb-1">
                    {{ __('sales.date') }} <span class="text-danger">*</span>
                </label>
                <input id="sales-return-date"
                       type="date"
                       wire:model="issue_date"
                       @error('issue_date') aria-invalid="true" aria-describedby="sales-return-date-error" @enderror
                       required
                       class="w-full min-h-11 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"
                       :disabled="documentBusy || saving"/>
                @error('issue_date') <span id="sales-return-date-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="md:col-span-3">
                <label for="sales-return-reason" class="block text-sm font-bold text-text-primary mb-1">
                    {{ __('sales.return_reason') }}
                </label>
                <input id="sales-return-reason"
                       type="text"
                       wire:model="reason"
                       @error('reason') aria-invalid="true" aria-describedby="sales-return-reason-error" @enderror
                       placeholder="{{ __('sales.return_reason_hint') }}"
                       class="w-full min-h-11 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"
                       :disabled="documentBusy || saving"/>
                @error('reason') <span id="sales-return-reason-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>
        </div>

        <!-- Lines Section -->
        <div>
            <h2 class="text-sm font-bold text-text-primary pb-2 border-b border-border">
                {{ __('sales.return_details') }}
            </h2>

            @error('lines') <span role="alert" aria-live="assertive" class="text-danger text-xs font-bold mt-2 block">{{ $message }}</span> @enderror

            @if (empty($lines))
                <div class="py-8 text-center text-text-muted text-xs">
                    {{ $sales_invoice_id ? __('sales.all_items_returned') : __('sales.select_return_invoice') }}
                </div>
            @else
                <div class="space-y-3 mt-4">
                    @foreach ($lines as $idx => $line)
                        <article class="rounded-card border border-border bg-surface p-3 sm:p-4 space-y-3" wire:key="sales-return-line-{{ $line['sales_invoice_line_id'] }}">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <h3 class="font-bold text-sm text-text-primary break-words">{{ $line['item_description'] }}</h3>
                                    @if (!empty($line['unit_name']))
                                        <p class="text-xs text-text-secondary mt-0.5">{{ $line['unit_name'] }}</p>
                                    @endif
                                </div>
                                <div class="text-xs text-text-secondary shrink-0">
                                    <span>{{ __('sales.unit_price') }}:</span>
                                    <bdi dir="ltr" class="font-mono font-bold tabular-nums text-text-primary">
                                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line['unit_price'], $currencyCode ?: null) }}
                                    </bdi>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-start rounded-control bg-surface-soft p-3">
                                <dl class="grid grid-cols-3 gap-2 text-xs md:col-span-6">
                                    <div class="min-w-0">
                                        <dt class="text-text-secondary">{{ __('refinement.original_quantity') }}</dt>
                                        <dd dir="ltr" class="mt-1 font-mono tabular-nums text-text-primary font-semibold">
                                            <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($line['original_quantity']) }}</bdi>
                                        </dd>
                                    </div>
                                    <div class="min-w-0">
                                        <dt class="text-text-secondary">{{ __('refinement.previously_returned') }}</dt>
                                        <dd dir="ltr" class="mt-1 font-mono tabular-nums text-text-primary font-semibold">
                                            <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($line['already_returned_quantity']) }}</bdi>
                                        </dd>
                                    </div>
                                    <div class="min-w-0">
                                        <dt class="text-text-secondary">{{ __('refinement.remaining_quantity') }}</dt>
                                        <dd dir="ltr" class="mt-1 font-mono tabular-nums font-bold text-primary">
                                            <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($line['max_returnable']) }}</bdi>
                                        </dd>
                                    </div>
                                </dl>

                                <div class="md:col-span-6 space-y-2">
                                    <label for="sales-return-quantity-{{ $idx }}" class="block text-xs font-semibold text-text-secondary">
                                        {{ __('refinement.return_quantity') }}
                                    </label>
                                    <div class="flex flex-wrap items-start gap-2">
                                        <div dir="ltr" class="shrink-0">
                                            <x-touch-quantity-stepper
                                                :index="$idx"
                                                field="return_quantity"
                                                :step="$line['quantity_step'] ?? '1'"
                                                :allows-fraction="($line['quantity_step'] ?? '1') !== '1'"
                                                :max="$line['max_returnable']"
                                                id="sales-return-quantity-{{ $idx }}"
                                                :aria-label="__('refinement.return_quantity') . ' ' . ($idx + 1)"
                                            />
                                        </div>
                                        <button type="button"
                                                @click="run('returnFullRemaining', [{{ $idx }}])"
                                                :disabled="saving || window.isDocumentBlocked($wire)"
                                                wire:loading.attr="disabled"
                                                class="min-h-11 w-full sm:w-auto flex-1 rounded-control border border-primary px-3 py-2 text-sm text-primary font-semibold hover:bg-primary-50 transition-colors disabled:opacity-50 cursor-pointer">
                                            {{ __('refinement.return_remaining') }}
                                        </button>
                                    </div>
                                    @error("lines.{$idx}.return_quantity")
                                        <p id="sales-return-quantity-{{ $idx }}-error" role="alert" class="text-sm text-danger font-medium">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($sales_invoice_id && $preReturnTotal !== null)
            <div class="rounded-card border border-border bg-surface-soft p-4 space-y-3" data-testid="return-totals-summary">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-text-secondary">
                        {{ __('sales.return_summary') }}
                    </h3>
                    @if ($returnCalculationError)
                        <span role="alert" aria-live="polite" class="text-xs font-semibold text-danger flex items-center gap-1" data-testid="return-totals-error">
                            {{ $returnCalculationError }}
                        </span>
                    @endif
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                    <div class="bg-surface rounded-control p-3 border border-border">
                        <span class="text-xs text-text-secondary block">{{ __('sales.pre_return_total') }}</span>
                        <div class="mt-1 font-mono text-base font-bold tabular-nums text-text-primary" dir="ltr">
                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($preReturnTotal, $currencyCode) }}
                            <span class="text-xs font-normal text-text-secondary">{{ $currencyCode }}</span>
                        </div>
                    </div>
                    <div class="bg-surface rounded-control p-3 border border-border">
                        <span class="text-xs text-text-secondary block">{{ __('sales.current_return_total') }}</span>
                        <div class="mt-1 font-mono text-base font-bold tabular-nums text-primary" dir="ltr">
                            @if ($returnCalculationError || $returnTotal === null)
                                <span class="text-text-muted text-sm font-normal">—</span>
                            @else
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($returnTotal, $currencyCode) }}
                                <span class="text-xs font-normal text-text-secondary">{{ $currencyCode }}</span>
                            @endif
                        </div>
                        @if (! $returnCalculationError && \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($returnTax))
                            <span class="text-xs text-text-muted block mt-0.5">
                                {{ __('sales.tax') }}: <span dir="ltr" class="font-mono tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($returnTax, $currencyCode) }}</span> <span class="text-text-secondary">{{ $currencyCode }}</span>
                            </span>
                        @endif
                    </div>
                    <div class="bg-surface rounded-control p-3 border border-border">
                        <span class="text-xs text-text-secondary block">{{ __('sales.post_return_remaining') }}</span>
                        <div class="mt-1 font-mono text-base font-bold tabular-nums text-text-primary" dir="ltr">
                            @if ($returnCalculationError || $postReturnTotal === null)
                                <span class="text-text-muted text-sm font-normal">—</span>
                            @else
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($postReturnTotal, $currencyCode) }}
                                <span class="text-xs font-normal text-text-secondary">{{ $currencyCode }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div>
            <label for="sales-return-notes" class="block text-sm font-bold text-text-primary mb-1">
                {{ __('sales.notes') }}
            </label>
            <textarea id="sales-return-notes"
                      wire:model="notes"
                      rows="2"
                      @error('notes') aria-invalid="true" aria-describedby="sales-return-notes-error" @enderror
                      class="w-full p-2.5 rounded-control border border-border bg-canvas text-sm text-text-primary outline-hidden"
                      :disabled="documentBusy || saving"></textarea>
            @error('notes') <span id="sales-return-notes-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
        </div>

        <!-- Buttons -->
        <p wire:loading role="status" class="text-sm font-semibold text-primary">{{ __('refinement.working') }}</p>
            <div class="sticky bottom-[calc(5rem+env(safe-area-inset-bottom))] z-20 flex flex-wrap items-center justify-end gap-3 border-t border-border bg-surface p-3 shadow-sm md:static md:shadow-none">
            <a href="{{ route('returns.index') }}"
               class="min-h-11 inline-flex items-center justify-center gap-2 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary px-4 py-2 rounded-control border border-border text-sm font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                {{ __('sales.cancel') }}
            </a>
            <button type="button"
                    @click="run('save', [false], true)" :disabled="saving || window.isDocumentBlocked($wire)"
                    class="min-h-11 inline-flex items-center justify-center gap-2 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary px-4 py-2 rounded-control border border-primary text-primary hover:bg-primary-50 text-sm font-bold transition-colors cursor-pointer" wire:loading.attr="disabled">
                {{ __('sales.save_draft') }}
            </button>
            @if ($canPost)
                <button type="button"
                        @click="run('save', [true], true)" :disabled="saving || window.isDocumentBlocked($wire)"
                        class="min-h-11 inline-flex items-center justify-center gap-2 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary px-5 py-2 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors shadow-sm cursor-pointer" wire:loading.attr="disabled">
                    ⚡ {{ __('sales.post') }}
                </button>
            @endif
        </div>
    </div>
</div>
