<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">{{ __('expenses.create_expense') }}</h1>
            <p class="text-sm text-text-muted">{{ __('expenses.expenses') }}</p>
        </div>
        <a href="{{ route('expenses.index') }}" class="inline-flex items-center rounded-control border border-border bg-white px-3 py-2 text-sm font-semibold text-text-secondary hover:bg-surface-soft">
            {{ __('expenses.cancel') }}
        </a>
    </div>

    @if($errors->any())
        <div role="alert" class="rounded-control border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 space-y-1">
            <p class="font-semibold">{{ __('money.invalid_request') }}</p>
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form wire:submit.prevent="save" class="rounded-card border border-border bg-white p-6 space-y-6">
        <!-- Classification -->
        <div class="border-b border-border pb-6">
            <p id="expense-classification-label" class="block text-sm font-bold text-text-primary mb-2">{{ __('expenses.classification') }}</p>
            <div role="radiogroup" aria-labelledby="expense-classification-label" class="grid gap-3 sm:grid-cols-2">
                <label class="flex items-start gap-3 p-3 rounded-control border cursor-pointer {{ $classification === 'operating' ? 'border-primary bg-primary-50' : 'border-border' }}">
                    <input type="radio" name="expense-classification" wire:model.live="classification" value="operating" class="mt-1 text-primary focus:ring-primary">
                    <div>
                        <span class="block font-semibold text-sm">{{ __('expenses.operating') }}</span>
                        <span class="block text-xs text-text-muted">{{ __('expenses.operating_hint') }}</span>
                    </div>
                </label>
                @if($canLandedCost)
                <label class="flex items-start gap-3 p-3 rounded-control border cursor-pointer {{ $classification === 'landed_cost' ? 'border-primary bg-primary-50' : 'border-border' }}">
                    <input type="radio" name="expense-classification" wire:model.live="classification" value="landed_cost" class="mt-1 text-primary focus:ring-primary">
                    <div>
                        <span class="block font-semibold text-sm">{{ __('expenses.landed_cost') }}</span>
                        <span class="block text-xs text-text-muted">{{ __('expenses.landed_cost_hint') }}</span>
                    </div>
                </label>
                @endif
            </div>
        </div>

        <!-- Main fields -->
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="expense-category" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('expenses.category') }} *</label>
                <select aria-invalid="{{ $errors->has('categoryId') ? 'true' : 'false' }}" @if ($errors->has('categoryId')) aria-describedby="expense-category-error" @endif id="expense-category" wire:model="categoryId" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                    <option value="">{{ __('expenses.select_category') }}</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ app()->getLocale() === 'en' ? ($cat->name_en ?: $cat->name_ar) : $cat->name_ar }}</option>
                    @endforeach
                </select>
                @error('categoryId') <span role="alert" id="expense-category-error" class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>

            <div>
                <label for="expense-date" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('expenses.expense_date') }} *</label>
                <input aria-invalid="{{ $errors->has('expenseDate') ? 'true' : 'false' }}" @if ($errors->has('expenseDate')) aria-describedby="expense-date-error" @endif id="expense-date" type="date" wire:model="expenseDate" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('expenseDate') <span role="alert" id="expense-date-error" class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <div>
            <label for="expense-description" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('expenses.description') }} *</label>
            <input aria-invalid="{{ $errors->has('description') ? 'true' : 'false' }}" @if ($errors->has('description')) aria-describedby="expense-description-error" @endif id="expense-description" type="text" wire:model="description" placeholder="{{ __('expenses.description') }}" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
            @error('description') <span role="alert" id="expense-description-error" class="text-xs text-rose-600">{{ $message }}</span> @enderror
        </div>

        <!-- Financials -->
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="expense-amount" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('expenses.amount') }} *</label>
                <input aria-invalid="{{ $errors->has('amount') ? 'true' : 'false' }}" @if ($errors->has('amount')) aria-describedby="expense-amount-error" @endif id="expense-amount" type="text" inputmode="decimal" wire:model="amount" placeholder="0.00" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('amount') <span role="alert" id="expense-amount-error" class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>

            <div>
                <label for="expense-currency" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('expenses.currency') }} *</label>
                <select aria-invalid="{{ $errors->has('currencyCode') ? 'true' : 'false' }}" @if ($errors->has('currencyCode')) aria-describedby="expense-currency-error" @endif id="expense-currency" wire:model.live="currencyCode" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                    @foreach($currencies as $curr)
                        <option value="{{ $curr->currency_code }}">{{ $curr->currency_code }}</option>
                    @endforeach
                </select>
                @error('currencyCode') <span role="alert" id="expense-currency-error" class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>

            <div>
                <label for="expense-exchange-rate" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('expenses.rate') }} *</label>
                <input aria-invalid="{{ $errors->has('exchangeRate') ? 'true' : 'false' }}" @if ($errors->has('exchangeRate')) aria-describedby="expense-exchange-rate-error" @endif id="expense-exchange-rate" type="text" inputmode="decimal" wire:model="exchangeRate" placeholder="1.0000000000" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('exchangeRate') <span role="alert" id="expense-exchange-rate-error" class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <!-- Payment method -->
        <div class="border-t border-border pt-6 space-y-4">
            <h2 id="expense-payment-method-label" class="font-bold text-sm text-text-primary">{{ __('expenses.payment_method') }}</h2>
            <div role="radiogroup" aria-labelledby="expense-payment-method-label" class="grid gap-3 sm:grid-cols-3">
                @foreach(['cash', 'bank', 'check'] as $method)
                    <label class="flex items-center gap-2 p-3 rounded-control border cursor-pointer {{ $paymentMethod === $method ? 'border-primary bg-primary-50' : 'border-border' }}">
                        <input type="radio" name="expense-payment-method" wire:model.live="paymentMethod" value="{{ $method }}" class="text-primary focus:ring-primary">
                        <span class="text-sm font-semibold">{{ __('expenses.'.$method) }}</span>
                    </label>
                @endforeach
            </div>

            @if($paymentMethod !== 'check')
                <div>
                    <label for="expense-money-account" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('expenses.account') }} *</label>
                    <select aria-invalid="{{ $errors->has('moneyAccountId') ? 'true' : 'false' }}" @if ($errors->has('moneyAccountId')) aria-describedby="expense-money-account-error" @endif id="expense-money-account" wire:model="moneyAccountId" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                        <option value="">{{ __('expenses.select_account') }}</option>
                        @foreach($accounts as $acc)
                            <option value="{{ $acc->id }}">{{ $acc->displayName() }}</option>
                        @endforeach
                    </select>
                    @error('moneyAccountId') <span role="alert" id="expense-money-account-error" class="text-xs text-rose-600">{{ $message }}</span> @enderror
                </div>
            @else
                <div class="rounded-control border border-border bg-surface-soft p-4 space-y-4">
                    <h3 class="font-semibold text-xs text-text-primary">{{ __('money.check_details') }}</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="expense-check-number" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('money.check_number') }} *</label>
                            <input aria-invalid="{{ $errors->has('checkNumber') ? 'true' : 'false' }}" @if ($errors->has('checkNumber')) aria-describedby="expense-check-number-error" @endif id="expense-check-number" type="text" wire:model="checkNumber" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                            @error('checkNumber') <span role="alert" id="expense-check-number-error" class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label for="expense-check-due-date" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('money.due_date') }} *</label>
                            <input aria-invalid="{{ $errors->has('dueDate') ? 'true' : 'false' }}" @if ($errors->has('dueDate')) aria-describedby="expense-check-due-date-error" @endif id="expense-check-due-date" type="date" wire:model="dueDate" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                            @error('dueDate') <span role="alert" id="expense-check-due-date-error" class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label for="expense-drawn-money-account" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('money.drawn_bank') }} *</label>
                            <select aria-invalid="{{ $errors->has('drawnMoneyAccountId') ? 'true' : 'false' }}" @if ($errors->has('drawnMoneyAccountId')) aria-describedby="expense-drawn-money-account-error" @endif id="expense-drawn-money-account" wire:model="drawnMoneyAccountId" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                                <option value="">{{ __('money.select_account') }}</option>
                                @foreach($accounts as $acc)
                                    <option value="{{ $acc->id }}">{{ $acc->displayName() }}</option>
                                @endforeach
                            </select>
                            @error('drawnMoneyAccountId') <span role="alert" id="expense-drawn-money-account-error" class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label for="expense-bank-name" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('money.bank_name') }}</label>
                            <input id="expense-bank-name" type="text" wire:model="bankName" placeholder="Bank of Palestine" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <!-- Optional Payee & Vendor -->
        <div class="border-t border-border pt-6 grid gap-4 sm:grid-cols-2">
            <div>
                <label for="expense-vendor" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('expenses.vendor') }}</label>
                <select id="expense-vendor" wire:model="vendorId" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('expenses.select_vendor') }}</option>
                    @foreach($vendors as $ven)
                        <option value="{{ $ven->id }}">{{ $ven->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="expense-payee-name" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('expenses.payee_name') }}</label>
                <input id="expense-payee-name" type="text" wire:model="payeeName" placeholder="{{ __('expenses.payee_name') }}" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
            </div>
        </div>

        <!-- Attachment & Notes -->
        <div class="border-t border-border pt-6 space-y-4">
            <div>
                <label for="expense-attachment" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('expenses.attachment') }}</label>
                <input aria-invalid="{{ $errors->has('attachment') ? 'true' : 'false' }}" aria-describedby="expense-attachment-hint{{ $errors->has('attachment') ? ' expense-attachment-error' : '' }}" id="expense-attachment" type="file" wire:model="attachment" accept=".pdf,.jpg,.jpeg,.png" class="w-full text-sm text-text-secondary file:mr-4 file:py-2 file:px-4 file:rounded-control file:border-0 file:text-sm file:font-semibold file:bg-primary-50 file:text-primary hover:file:bg-primary-100">
                <span id="expense-attachment-hint" class="block text-xs text-text-muted mt-1">{{ __('expenses.attachment_hint') }}</span>
                @error('attachment') <span role="alert" id="expense-attachment-error" class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>

            <div>
                <label for="expense-notes" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('expenses.notes') }}</label>
                <textarea id="expense-notes" wire:model="notes" rows="3" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary"></textarea>
            </div>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-border">
            <a href="{{ route('expenses.index') }}" class="inline-flex justify-center items-center rounded-control border border-border bg-white px-4 py-2 text-sm font-semibold text-text-secondary hover:bg-surface-soft">
                {{ __('expenses.cancel') }}
            </a>
            <button type="submit" wire:loading.attr="disabled" class="inline-flex justify-center items-center rounded-control bg-primary px-6 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2">
                {{ __('expenses.save') }}
            </button>
        </div>
    </form>
</div>
