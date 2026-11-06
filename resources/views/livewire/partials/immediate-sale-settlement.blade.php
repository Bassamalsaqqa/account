@if ($canReceive && ! $showPreview)
    @php
        $paymentCurrency = $this->settlementPaymentCurrency();
        $company = app(\App\Support\Tenancy\CompanyContext::class)->company();
        $isBasePayment = $paymentCurrency === $company->base_currency_code;
        $isSameCurrency = $paymentCurrency === $currency_code;
    @endphp
    <fieldset class="space-y-4 border-t border-border pt-5" aria-describedby="settlement-help">
        <legend class="px-1 text-base font-bold text-text-primary">{{ __('fin1.title') }}</legend>
        <p id="settlement-help" class="text-sm text-text-secondary">{{ __('fin1.help') }}</p>
        <div class="grid gap-2 sm:grid-cols-3">
            @foreach (['unpaid', 'full', 'partial'] as $mode)
                <label for="settlement-mode-{{ $mode }}" class="flex min-h-11 cursor-pointer items-center gap-2 rounded-control border border-border bg-surface px-3 py-2 text-sm focus-within:ring-2 focus-within:ring-primary">
                    <input id="settlement-mode-{{ $mode }}" type="radio" wire:model.live="settlement.mode" value="{{ $mode }}" name="settlement-mode" class="accent-primary" />
                    <span>{{ __('fin1.mode_'.$mode) }}</span>
                </label>
            @endforeach
        </div>
        @error('settlement.mode') <p role="alert" class="text-sm text-danger">{{ $message }}</p> @enderror
        @if (($settlement['mode'] ?? 'unpaid') !== 'unpaid')
            @if ($errors->has('settlement.*'))
                <div role="alert" tabindex="-1" class="text-sm text-danger" x-data x-init="$el.focus()">{{ __('fin1.correct_fields') }}</div>
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="settlement-method" class="mb-1 block text-sm font-semibold">{{ __('fin1.payment_method') }}</label>
                    <select id="settlement-method" wire:model.live="settlement.payment_method" @error('settlement.payment_method') aria-invalid="true" aria-describedby="settlement-payment_method-error" @enderror class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary">
                        <option value="cash">{{ __('fin1.cash') }}</option>
                        <option value="bank_transfer">{{ __('fin1.bank_transfer') }}</option>
                        @if ($canReceiveCheck) <option value="check">{{ __('fin1.check') }}</option> @endif
                    </select>
                </div>
                @if (($settlement['payment_method'] ?? null) === 'check')
                    <div>
                        <label for="settlement-currency" class="mb-1 block text-sm font-semibold">{{ __('fin1.currency_code') }}</label>
                        <select id="settlement-currency" wire:model.live="settlement.currency_code" @error('settlement.currency_code') aria-invalid="true" aria-describedby="settlement-currency_code-error" @enderror class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary">
                            <option value="">{{ __('fin1.choose') }}</option>
                            @foreach ($currencies as $currency) <option value="{{ $currency->currency_code }}">{{ $currency->currency_code }}</option> @endforeach
                        </select>
                    </div>
                @else
                    <div>
                        <label for="settlement-account" class="mb-1 block text-sm font-semibold">{{ __('fin1.money_account_id') }}</label>
                        <select id="settlement-account" wire:model.live="settlement.money_account_id" @error('settlement.money_account_id') aria-invalid="true" aria-describedby="settlement-money_account_id-error" @enderror class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary">
                            <option value="">{{ __('fin1.choose') }}</option>
                            @foreach ($settlementAccounts as $account)
                                <option value="{{ $account->id }}">{{ app()->getLocale() === 'ar' ? ($account->name_ar ?: $account->name_en) : ($account->name_en ?: $account->name_ar) }} — {{ $account->currency_code }}</option>
                            @endforeach
                        </select>
                        @if ($settlementAccounts->isEmpty()) <p class="mt-1 text-sm text-text-secondary">{{ __('fin1.no_accounts') }}</p> @endif
                    </div>
                @endif

                <div>
                    <label for="settlement-payment_date" class="mb-1 block text-sm font-semibold">{{ __('fin1.payment_date') }}</label>
                    <input id="settlement-payment_date" type="date"
                           wire:model="settlement.payment_date"
                           @if ($errors->has('settlement.payment_date')) aria-invalid="true" aria-describedby="settlement-payment_date-error settlement-date-help"
                           @else aria-describedby="settlement-date-help" @endif
                           class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary" />
                    @error('settlement.payment_date') <p id="settlement-payment_date-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="settlement-amount" class="mb-1 block text-sm font-semibold">
                        {{ __('fin1.amount') }} ({{ $paymentCurrency }}) <span class="text-danger">*</span>
                    </label>
                    <input id="settlement-amount" type="text" inputmode="decimal" dir="ltr"
                           wire:model="settlement.amount"
                           @error('settlement.amount') aria-invalid="true" aria-describedby="settlement-amount-error" @enderror
                           class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm font-mono text-start focus:ring-2 focus:ring-primary" />
                    @error('settlement.amount') <p id="settlement-amount-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                @if (($settlement['mode'] ?? null) === 'partial' && ! $isSameCurrency)
                    <div>
                        <label for="settlement-invoice_amount" class="mb-1 block text-sm font-semibold">
                            {{ __('fin1.invoice_amount') }} ({{ $currency_code }}) <span class="text-danger">*</span>
                        </label>
                        <input id="settlement-invoice_amount" type="text" inputmode="decimal" dir="ltr"
                               wire:model="settlement.invoice_amount"
                               @error('settlement.invoice_amount') aria-invalid="true" aria-describedby="settlement-invoice_amount-error" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm font-mono text-start focus:ring-2 focus:ring-primary" />
                        @error('settlement.invoice_amount') <p id="settlement-invoice_amount-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                @endif

                @if (! $isBasePayment)
                    <div>
                        <label for="settlement-exchange_rate" class="mb-1 block text-sm font-semibold">
                            {{ __('fin1.exchange_rate') }} <span class="text-danger">*</span>
                        </label>
                        <input id="settlement-exchange_rate" type="text" inputmode="decimal" dir="ltr"
                               wire:model="settlement.exchange_rate"
                               @error('settlement.exchange_rate') aria-invalid="true" aria-describedby="settlement-exchange_rate-error" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm font-mono text-start focus:ring-2 focus:ring-primary" />
                        @error('settlement.exchange_rate') <p id="settlement-exchange_rate-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                @endif

                @if (! $isSameCurrency)
                    <div>
                        <label for="settlement-payment_currency_amount" class="mb-1 block text-sm font-semibold">
                            {{ __('fin1.payment_currency_amount') }} ({{ $paymentCurrency }}) <span class="text-danger">*</span>
                        </label>
                        <input id="settlement-payment_currency_amount" type="text" inputmode="decimal" dir="ltr"
                               wire:model="settlement.payment_currency_amount"
                               @error('settlement.payment_currency_amount') aria-invalid="true" aria-describedby="settlement-payment_currency_amount-error" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm font-mono text-start focus:ring-2 focus:ring-primary" />
                        @error('settlement.payment_currency_amount') <p id="settlement-payment_currency_amount-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                @endif

                @if (($settlement['payment_method'] ?? null) === 'check')
                    <div>
                        <label for="settlement-check_number" class="mb-1 block text-sm font-semibold">{{ __('fin1.check_number') }} <span class="text-danger">*</span></label>
                        <input id="settlement-check_number" type="text"
                               wire:model="settlement.check_number"
                               @error('settlement.check_number') aria-invalid="true" aria-describedby="settlement-check_number-error" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary" />
                        @error('settlement.check_number') <p id="settlement-check_number-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="settlement-bank_name" class="mb-1 block text-sm font-semibold">{{ __('fin1.bank_name') }} <span class="text-danger">*</span></label>
                        <input id="settlement-bank_name" type="text"
                               wire:model="settlement.bank_name"
                               @error('settlement.bank_name') aria-invalid="true" aria-describedby="settlement-bank_name-error" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary" />
                        @error('settlement.bank_name') <p id="settlement-bank_name-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="settlement-drawer" class="mb-1 block text-sm font-semibold">{{ __('fin1.drawer') }}</label>
                        <input id="settlement-drawer" type="text"
                               wire:model="settlement.drawer"
                               @error('settlement.drawer') aria-invalid="true" aria-describedby="settlement-drawer-error" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary" />
                        @error('settlement.drawer') <p id="settlement-drawer-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="settlement-due_date" class="mb-1 block text-sm font-semibold">{{ __('fin1.due_date') }} <span class="text-danger">*</span></label>
                        <input id="settlement-due_date" type="date"
                               wire:model="settlement.due_date"
                               @error('settlement.due_date') aria-invalid="true" aria-describedby="settlement-due_date-error" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary" />
                        @error('settlement.due_date') <p id="settlement-due_date-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>
            @foreach (['payment_method', 'money_account_id', 'currency_code'] as $field)
                @error('settlement.'.$field) <p id="settlement-{{ $field }}-error" role="alert" class="text-sm text-danger">{{ $message }}</p> @enderror
            @endforeach
            <p id="settlement-date-help" class="text-sm text-text-secondary">{{ __('fin1.date_help') }}</p>
            @if (! $isBasePayment || ! $isSameCurrency)
                <p class="text-sm text-text-secondary">{{ __('fin1.fx_help') }}</p>
            @endif
        @endif
    </fieldset>
@endif
