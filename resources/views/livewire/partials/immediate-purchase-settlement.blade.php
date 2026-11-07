@if ($canPay)
    @php
        $paymentCurrency = $this->settlementPaymentCurrency();
        $isBasePayment = $paymentCurrency === $baseCurrencyCode;
        $isSameCurrency = $paymentCurrency === $currency_code;
    @endphp

    <x-inline-panel tag="fieldset" aria-describedby="settlement-help" :legend="__('purchase_settlement.title')">
        <p id="settlement-help" class="text-sm text-text-secondary">{{ __('purchase_settlement.help') }}</p>

        <div class="space-y-2">
            <span class="block text-xs font-bold text-text-secondary">{{ __('purchase_settlement.mode') }}</span>
            <div class="flex flex-wrap gap-4 text-sm">
                @foreach (['unpaid', 'full', 'partial'] as $mode)
                    <label for="purchase-settlement-mode-{{ $mode }}" class="inline-flex min-h-11 items-center gap-2 cursor-pointer">
                        <input id="purchase-settlement-mode-{{ $mode }}"
                               type="radio"
                               name="purchase_settlement_mode"
                               value="{{ $mode }}"
                               wire:model.live="settlement.mode"
                               class="text-primary focus:ring-primary"
                               :disabled="documentBusy || saving" />
                        <span>{{ __('purchase_settlement.mode_'.$mode) }}</span>
                    </label>
                @endforeach
            </div>
            @error('settlement.mode') <p id="purchase-settlement-mode-error" role="alert" class="text-sm text-danger">{{ $message }}</p> @enderror
        </div>

        @if (($settlement['mode'] ?? 'unpaid') !== 'unpaid')
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label for="purchase-settlement-payment_method" class="mb-1 block text-sm font-semibold">
                        {{ __('purchase_settlement.payment_method') }} <span class="text-danger">*</span>
                    </label>
                    <select id="purchase-settlement-payment_method"
                            wire:model.live="settlement.payment_method"
                            @error('settlement.payment_method') aria-invalid="true" aria-describedby="purchase-settlement-payment_method-error" @enderror
                            class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary"
                            :disabled="documentBusy || saving">
                        <option value="cash">{{ __('purchase_settlement.cash') }}</option>
                        <option value="bank_transfer">{{ __('purchase_settlement.bank_transfer') }}</option>
                        @if ($canPayCheck)
                            <option value="check">{{ __('purchase_settlement.check') }}</option>
                        @endif
                    </select>
                    @error('settlement.payment_method') <p id="purchase-settlement-payment_method-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="purchase-settlement-money_account_id" class="mb-1 block text-sm font-semibold">
                        {{ __('purchase_settlement.money_account_id') }} <span class="text-danger">*</span>
                    </label>
                    <select id="purchase-settlement-money_account_id"
                            wire:model.live="settlement.money_account_id"
                            @error('settlement.money_account_id') aria-invalid="true" aria-describedby="purchase-settlement-money_account_id-error" @enderror
                            class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary"
                            :disabled="documentBusy || saving">
                        <option value="">{{ __('purchase_settlement.choose') }}</option>
                        @foreach ($settlementAccounts as $account)
                            <option value="{{ $account->id }}">
                                {{ app()->getLocale() === 'ar' ? ($account->name_ar ?: $account->name_en) : ($account->name_en ?: $account->name_ar) }} ({{ $account->currency_code }})
                            </option>
                        @endforeach
                    </select>
                    @error('settlement.money_account_id') <p id="purchase-settlement-money_account_id-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="purchase-settlement-payment_date" class="mb-1 block text-sm font-semibold">
                        {{ __('purchase_settlement.payment_date') }} <span class="text-danger">*</span>
                    </label>
                    <input id="purchase-settlement-payment_date"
                           type="date"
                           wire:model="settlement.payment_date"
                           @error('settlement.payment_date') aria-invalid="true" aria-describedby="purchase-settlement-payment_date-error" @enderror
                           class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary"
                           :disabled="documentBusy || saving" />
                    @error('settlement.payment_date') <p id="purchase-settlement-payment_date-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="purchase-settlement-amount" class="mb-1 block text-sm font-semibold">
                        {{ __('purchase_settlement.amount') }} ({{ $paymentCurrency }}) <span class="text-danger">*</span>
                    </label>
                    <input id="purchase-settlement-amount"
                           type="text"
                           inputmode="decimal"
                           dir="ltr"
                           wire:model="settlement.amount"
                           @error('settlement.amount') aria-invalid="true" aria-describedby="purchase-settlement-amount-error" @enderror
                           class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm font-mono text-start focus:ring-2 focus:ring-primary"
                           :disabled="documentBusy || saving" />
                    @error('settlement.amount') <p id="purchase-settlement-amount-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                @if (($settlement['mode'] ?? null) === 'partial' && ! $isSameCurrency)
                    <div>
                        <label for="purchase-settlement-purchase_amount" class="mb-1 block text-sm font-semibold">
                            {{ __('purchase_settlement.purchase_amount') }} ({{ $currency_code }}) <span class="text-danger">*</span>
                        </label>
                        <input id="purchase-settlement-purchase_amount"
                               type="text"
                               inputmode="decimal"
                               dir="ltr"
                               wire:model="settlement.purchase_amount"
                               @error('settlement.purchase_amount') aria-invalid="true" aria-describedby="purchase-settlement-purchase_amount-error" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm font-mono text-start focus:ring-2 focus:ring-primary"
                               :disabled="documentBusy || saving" />
                        @error('settlement.purchase_amount') <p id="purchase-settlement-purchase_amount-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                @endif

                @if (! $isBasePayment)
                    <div>
                        <label for="purchase-settlement-exchange_rate" class="mb-1 block text-sm font-semibold">
                            {{ __('purchase_settlement.exchange_rate') }} <span class="text-danger">*</span>
                        </label>
                        <input id="purchase-settlement-exchange_rate"
                               type="text"
                               inputmode="decimal"
                               dir="ltr"
                               wire:model="settlement.exchange_rate"
                               @error('settlement.exchange_rate') aria-invalid="true" aria-describedby="purchase-settlement-exchange_rate-error" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm font-mono text-start focus:ring-2 focus:ring-primary"
                               :disabled="documentBusy || saving" />
                        @error('settlement.exchange_rate') <p id="purchase-settlement-exchange_rate-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                @endif

                @if (! $isSameCurrency)
                    <div>
                        <label for="purchase-settlement-payment_currency_amount" class="mb-1 block text-sm font-semibold">
                            {{ __('purchase_settlement.payment_currency_amount') }} ({{ $paymentCurrency }}) <span class="text-danger">*</span>
                        </label>
                        <input id="purchase-settlement-payment_currency_amount"
                               type="text"
                               inputmode="decimal"
                               dir="ltr"
                               wire:model="settlement.payment_currency_amount"
                               @error('settlement.payment_currency_amount') aria-invalid="true" aria-describedby="purchase-settlement-payment_currency_amount-error" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm font-mono text-start focus:ring-2 focus:ring-primary"
                               :disabled="documentBusy || saving" />
                        @error('settlement.payment_currency_amount') <p id="purchase-settlement-payment_currency_amount-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                @endif

                @if (($settlement['payment_method'] ?? null) === 'check')
                    <div>
                        <label for="purchase-settlement-check_number" class="mb-1 block text-sm font-semibold">
                            {{ __('purchase_settlement.check_number') }} <span class="text-danger">*</span>
                        </label>
                        <input id="purchase-settlement-check_number"
                               type="text"
                               wire:model="settlement.check_number"
                               @error('settlement.check_number') aria-invalid="true" aria-describedby="purchase-settlement-check_number-error" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary"
                               :disabled="documentBusy || saving" />
                        @error('settlement.check_number') <p id="purchase-settlement-check_number-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="purchase-settlement-bank_name" class="mb-1 block text-sm font-semibold">
                            {{ __('purchase_settlement.bank_name') }} <span class="text-danger">*</span>
                        </label>
                        <input id="purchase-settlement-bank_name"
                               type="text"
                               wire:model="settlement.bank_name"
                               @error('settlement.bank_name') aria-invalid="true" aria-describedby="purchase-settlement-bank_name-error" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary"
                               :disabled="documentBusy || saving" />
                        @error('settlement.bank_name') <p id="purchase-settlement-bank_name-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="purchase-settlement-drawer" class="mb-1 block text-sm font-semibold">
                            {{ __('purchase_settlement.drawer') }}
                        </label>
                        <input id="purchase-settlement-drawer"
                               type="text"
                               wire:model="settlement.drawer"
                               @error('settlement.drawer') aria-invalid="true" aria-describedby="purchase-settlement-drawer-error" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary"
                               :disabled="documentBusy || saving" />
                        @error('settlement.drawer') <p id="purchase-settlement-drawer-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="purchase-settlement-due_date" class="mb-1 block text-sm font-semibold">
                            {{ __('purchase_settlement.due_date') }} <span class="text-danger">*</span>
                        </label>
                        <input id="purchase-settlement-due_date"
                               type="date"
                               wire:model="settlement.due_date"
                               @error('settlement.due_date') aria-invalid="true" aria-describedby="purchase-settlement-due_date-error" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary"
                               :disabled="documentBusy || saving" />
                        @error('settlement.due_date') <p id="purchase-settlement-due_date-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>

            @foreach (['payment_method', 'money_account_id', 'currency_code'] as $field)
                @error('settlement.'.$field) <p id="purchase-settlement-{{ $field }}-error" role="alert" class="text-sm text-danger">{{ $message }}</p> @enderror
            @endforeach

            <p id="settlement-date-help" class="text-sm text-text-secondary">{{ __('purchase_settlement.date_help') }}</p>
            @if (! $isBasePayment || ! $isSameCurrency)
                <p class="text-sm text-text-secondary">{{ __('purchase_settlement.fx_help') }}</p>
            @endif
        @endif
    </x-inline-panel>
@endif
