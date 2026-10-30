@if ($canReceive && ! $showPreview)
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
                        <select id="settlement-currency" wire:model="settlement.currency_code" @error('settlement.currency_code') aria-invalid="true" aria-describedby="settlement-currency_code-error" @enderror class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary">
                            <option value="">{{ __('fin1.choose') }}</option>
                            @foreach ($currencies as $currency) <option value="{{ $currency->currency_code }}">{{ $currency->currency_code }}</option> @endforeach
                        </select>
                    </div>
                @else
                    <div>
                        <label for="settlement-account" class="mb-1 block text-sm font-semibold">{{ __('fin1.money_account_id') }}</label>
                        <select id="settlement-account" wire:model="settlement.money_account_id" @error('settlement.money_account_id') aria-invalid="true" aria-describedby="settlement-money_account_id-error" @enderror class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary">
                            <option value="">{{ __('fin1.choose') }}</option>
                            @foreach ($settlementAccounts as $account)
                                <option value="{{ $account->id }}">{{ app()->getLocale() === 'ar' ? ($account->name_ar ?: $account->name_en) : ($account->name_en ?: $account->name_ar) }} — {{ $account->currency_code }}</option>
                            @endforeach
                        </select>
                        @if ($settlementAccounts->isEmpty()) <p class="mt-1 text-sm text-text-secondary">{{ __('fin1.no_accounts') }}</p> @endif
                    </div>
                @endif
                @foreach (['amount', 'exchange_rate', 'payment_date', ...(($settlement['mode'] ?? null) === 'partial' ? ['invoice_amount'] : []), 'payment_currency_amount', ...(($settlement['payment_method'] ?? null) === 'check' ? ['check_number', 'bank_name', 'drawer', 'due_date'] : [])] as $field)
                    <div>
                        <label for="settlement-{{ $field }}" class="mb-1 block text-sm font-semibold">{{ __('fin1.'.$field) }}</label>
                        <input id="settlement-{{ $field }}" type="{{ in_array($field, ['payment_date', 'due_date'], true) ? 'date' : 'text' }}"
                               wire:model="settlement.{{ $field }}"
                               @if (in_array($field, ['amount', 'exchange_rate', 'invoice_amount', 'payment_currency_amount'], true)) inputmode="decimal" dir="ltr" @endif
                               @if ($errors->has('settlement.'.$field)) aria-invalid="true" aria-describedby="settlement-{{ $field }}-error{{ $field === 'payment_date' ? ' settlement-date-help' : '' }}"
                               @elseif ($field === 'payment_date') aria-describedby="settlement-date-help" @endif
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm focus:ring-2 focus:ring-primary" />
                        @error('settlement.'.$field) <p id="settlement-{{ $field }}-error" class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            @foreach (['payment_method', 'money_account_id', 'currency_code'] as $field)
                @error('settlement.'.$field) <p id="settlement-{{ $field }}-error" role="alert" class="text-sm text-danger">{{ $message }}</p> @enderror
            @endforeach
            <p id="settlement-date-help" class="text-sm text-text-secondary">{{ __('fin1.date_help') }}</p>
            <p class="text-sm text-text-secondary">{{ __('fin1.fx_help') }}</p>
        @endif
    </fieldset>
@endif
