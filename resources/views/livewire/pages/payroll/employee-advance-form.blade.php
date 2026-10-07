<div class="max-w-3xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">{{ __('payroll.create_advance') }}</h1>
            <p class="text-sm text-text-muted">{{ __('payroll.advances') }}</p>
        </div>
        <a href="{{ route('employees.index') }}" class="inline-flex items-center rounded-control border border-border bg-white px-3 py-2 text-sm font-semibold text-text-secondary hover:bg-surface-soft">
            {{ __('payroll.cancel') }}
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
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.employee') }} *</label>
                <select wire:model="employeeId" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                    <option value="">{{ __('payroll.select_employee') }}</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->name }} ({{ $emp->code }})</option>
                    @endforeach
                </select>
                @error('employeeId') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.advance_date') }} *</label>
                <input type="date" wire:model="advanceDate" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('advanceDate') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.amount') }} *</label>
                <input type="text" inputmode="decimal" wire:model="amount" placeholder="0.00" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('amount') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.currency') }} *</label>
                <select wire:model.live="currencyCode" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                    @foreach($currencies as $curr)
                        <option value="{{ $curr->currency_code }}">{{ $curr->currency_code }}</option>
                    @endforeach
                </select>
                @error('currencyCode') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.rate') }} *</label>
                <input type="text" inputmode="decimal" wire:model="exchangeRate" placeholder="1.0000000000" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('exchangeRate') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <!-- Payment Method -->
        <div class="border-t border-border pt-6 space-y-4">
            <h2 class="font-bold text-sm text-text-primary">{{ __('payroll.payment_method') }}</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach(['cash', 'bank', 'check'] as $method)
                    <label class="flex items-center gap-2 p-3 rounded-control border cursor-pointer {{ $paymentMethod === $method ? 'border-primary bg-primary-50' : 'border-border' }}">
                        <input type="radio" wire:model.live="paymentMethod" value="{{ $method }}" class="text-primary focus:ring-primary">
                        <span class="text-sm font-semibold">{{ __('payroll.'.$method) }}</span>
                    </label>
                @endforeach
            </div>

            @if($paymentMethod !== 'check')
                <div>
                    <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.account') }} *</label>
                    <select wire:model="moneyAccountId" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                        <option value="">{{ __('expenses.select_account') }}</option>
                        @foreach($accounts as $acc)
                            <option value="{{ $acc->id }}">{{ $acc->displayName() }}</option>
                        @endforeach
                    </select>
                    @error('moneyAccountId') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                </div>
            @else
                <div class="rounded-control border border-border bg-surface-soft p-4 space-y-4">
                    <h3 class="font-semibold text-xs text-text-primary">{{ __('money.check_details') }}</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('money.check_number') }} *</label>
                            <input type="text" wire:model="checkNumber" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                            @error('checkNumber') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('money.due_date') }} *</label>
                            <input type="date" wire:model="dueDate" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                            @error('dueDate') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('money.drawn_bank') }} *</label>
                            <select wire:model="drawnMoneyAccountId" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                                <option value="">{{ __('money.select_account') }}</option>
                                @foreach($accounts as $acc)
                                    <option value="{{ $acc->id }}">{{ $acc->displayName() }}</option>
                                @endforeach
                            </select>
                            @error('drawnMoneyAccountId') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('money.bank_name') }}</label>
                            <input type="text" wire:model="bankName" placeholder="Bank of Palestine" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div>
            <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.notes') }}</label>
            <textarea wire:model="notes" rows="3" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary"></textarea>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-border">
            <a href="{{ route('employees.index') }}" class="inline-flex justify-center items-center rounded-control border border-border bg-white px-4 py-2 text-sm font-semibold text-text-secondary hover:bg-surface-soft">
                {{ __('payroll.cancel') }}
            </a>
            <button type="submit" class="inline-flex justify-center items-center rounded-control bg-primary px-6 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary">
                {{ __('payroll.save') }}
            </button>
        </div>
    </form>
</div>
