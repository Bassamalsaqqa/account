<div class="max-w-4xl mx-auto space-y-6" data-entry-form>
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">{{ __('payroll.create_salary_payment') }}</h1>
            <p class="text-sm text-text-muted">{{ __('payroll.salary_payments') }}</p>
        </div>
        <a href="{{ route('employees.index') }}" class="min-h-11 lg:min-h-9.5 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary rounded-control border border-border-control bg-surface text-sm font-semibold text-text-secondary hover:bg-surface-soft">
            {{ __('payroll.cancel') }}
        </a>
    </div>

    @if($errors->any())
        <div role="alert" class="rounded-control border border-danger/20 bg-danger-bg p-4 text-sm text-danger space-y-1">
            <p class="font-semibold">{{ __('money.invalid_request') }}</p>
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form wire:submit.prevent="save" class="rounded-card border border-border bg-surface p-4 sm:p-6 space-y-6">
        <!-- Employee & Date -->
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="salary-payment-employee" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.employee') }} *</label>
                <select id="salary-payment-employee"
                        wire:model.live="employeeId"
                        aria-invalid="{{ $errors->has('employeeId') ? 'true' : 'false' }}"
                        @if ($errors->has('employeeId')) aria-describedby="salary-payment-employee-error" @endif
                        class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary"
                        required>
                    <option value="">{{ __('payroll.select_employee') }}</option>
                    @foreach($employees as $emp)
                        @php
                            $isRetired = (! $emp->active) || ($emp->deleted_at !== null);
                            $statusSuffix = $isRetired ? ' — ' . ($emp->deleted_at !== null ? __('payroll.archived') : __('payroll.inactive')) : '';
                        @endphp
                        <option value="{{ $emp->id }}">{{ $emp->name }} ({{ $emp->code }}){{ $statusSuffix }}</option>
                    @endforeach
                </select>
                @error('employeeId') <span id="salary-payment-employee-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
            </div>

            <div>
                <label for="salary-payment-date" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.payment_date') }} *</label>
                <input id="salary-payment-date"
                       type="date"
                       wire:model="paymentDate"
                       aria-invalid="{{ $errors->has('paymentDate') ? 'true' : 'false' }}"
                       @if ($errors->has('paymentDate')) aria-describedby="salary-payment-date-error" @endif
                       class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary"
                       required>
                @error('paymentDate') <span id="salary-payment-date-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
            </div>
        </div>

        <!-- Currency & Exchange Rate -->
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="salary-payment-currency" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.currency') }} *</label>
                <select id="salary-payment-currency"
                        wire:model.live="currencyCode"
                        aria-invalid="{{ $errors->has('currencyCode') ? 'true' : 'false' }}"
                        @if ($errors->has('currencyCode')) aria-describedby="salary-payment-currency-error" @endif
                        class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary"
                        required>
                    @foreach($currencies as $curr)
                        <option value="{{ $curr->currency_code }}">{{ $curr->currency_code }}</option>
                    @endforeach
                </select>
                @error('currencyCode') <span id="salary-payment-currency-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
            </div>
            @if ($currencyCode !== $baseCurrency)
                <div>
                    <label for="salary-payment-exchange-rate" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.rate') }} *</label>
                    <input id="salary-payment-exchange-rate"
                           type="text"
                           inputmode="decimal"
                           dir="ltr"
                           wire:model="exchangeRate"
                           aria-invalid="{{ $errors->has('exchangeRate') ? 'true' : 'false' }}"
                           @if ($errors->has('exchangeRate')) aria-describedby="salary-payment-exchange-rate-error" @endif
                           placeholder="1.0000000000"
                           class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm font-mono tabular-nums focus:border-primary focus:ring-primary"
                           required>
                    @error('exchangeRate') <span id="salary-payment-exchange-rate-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
                </div>
            @endif
        </div>

        <!-- Payment Method -->
        <div class="border-t border-border pt-6 space-y-4">
            <h2 class="font-bold text-sm text-text-primary">{{ __('payroll.payment_method') }}</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach(['cash', 'bank', 'check'] as $method)
                    <label for="salary-payment-method-{{ $method }}" class="flex min-h-11 items-center gap-2 p-3 rounded-control border cursor-pointer {{ $paymentMethod === $method ? 'border-primary bg-primary-50' : 'border-border' }}">
                        <input id="salary-payment-method-{{ $method }}" type="radio" wire:model.live="paymentMethod" value="{{ $method }}" class="text-primary focus:ring-primary">
                        <span class="text-sm font-semibold">{{ __('payroll.'.$method) }}</span>
                    </label>
                @endforeach
            </div>

            @if($paymentMethod !== 'check')
                <div>
                    <label for="salary-payment-account" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.account') }} *</label>
                    <select id="salary-payment-account"
                            wire:model="moneyAccountId"
                            aria-invalid="{{ $errors->has('moneyAccountId') ? 'true' : 'false' }}"
                            @if ($errors->has('moneyAccountId')) aria-describedby="salary-payment-account-error" @endif
                            class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary"
                            required>
                        <option value="">{{ __('expenses.select_account') }}</option>
                        @foreach($accounts as $acc)
                            <option value="{{ $acc->id }}">{{ $acc->displayName() }}</option>
                        @endforeach
                    </select>
                    @error('moneyAccountId') <span id="salary-payment-account-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
                </div>
            @else
                <div class="rounded-control border border-border bg-surface-soft p-4 space-y-4">
                    <h3 class="font-semibold text-xs text-text-primary">{{ __('money.check_details') }}</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="salary-payment-check-number" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('money.check_number') }} *</label>
                            <input id="salary-payment-check-number"
                                   type="text"
                                   dir="ltr"
                                   wire:model="checkNumber"
                                   aria-invalid="{{ $errors->has('checkNumber') ? 'true' : 'false' }}"
                                   @if ($errors->has('checkNumber')) aria-describedby="salary-payment-check-number-error" @endif
                                   class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary"
                                   required>
                            @error('checkNumber') <span id="salary-payment-check-number-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label for="salary-payment-due-date" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('money.due_date') }} *</label>
                            <input id="salary-payment-due-date"
                                   type="date"
                                   wire:model="dueDate"
                                   aria-invalid="{{ $errors->has('dueDate') ? 'true' : 'false' }}"
                                   @if ($errors->has('dueDate')) aria-describedby="salary-payment-due-date-error" @endif
                                   class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary"
                                   required>
                            @error('dueDate') <span id="salary-payment-due-date-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label for="salary-payment-drawn-bank" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('money.drawn_bank') }} *</label>
                            <select id="salary-payment-drawn-bank"
                                    wire:model="drawnMoneyAccountId"
                                    aria-invalid="{{ $errors->has('drawnMoneyAccountId') ? 'true' : 'false' }}"
                                    @if ($errors->has('drawnMoneyAccountId')) aria-describedby="salary-payment-drawn-bank-error" @endif
                                    class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary"
                                    required>
                                <option value="">{{ __('money.select_account') }}</option>
                                @foreach($accounts as $acc)
                                    <option value="{{ $acc->id }}">{{ $acc->displayName() }}</option>
                                @endforeach
                            </select>
                            @error('drawnMoneyAccountId') <span id="salary-payment-drawn-bank-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label for="salary-payment-bank-name" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('money.bank_name') }}</label>
                            <input id="salary-payment-bank-name"
                                   type="text"
                                   wire:model="bankName"
                                   aria-invalid="{{ $errors->has('bankName') ? 'true' : 'false' }}"
                                   @if ($errors->has('bankName')) aria-describedby="salary-payment-bank-name-error" @endif
                                   placeholder="Bank of Palestine"
                                   class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary">
                            @error('bankName') <span id="salary-payment-bank-name-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <!-- Unpaid Entries Allocation Table -->
        <div class="border-t border-border pt-6 space-y-3">
            <h2 class="font-bold text-sm text-text-primary">{{ __('payroll.unpaid_entries') }}</h2>
            <p class="text-xs text-text-muted">{{ __('payroll.payment_allocation_hint') }}</p>

            @if(count($unpaidEntries) > 0)
                <div class="relative rounded-control border border-border overflow-hidden">
                    <div class="relative overflow-x-auto">
                        <table class="data-table w-full text-sm text-start">
                            <thead class="bg-surface-soft border-b border-border text-xs text-text-muted">
                                <tr>
                                    <th class="px-3 py-2 text-start">{{ __('payroll.entry_number') }}</th>
                                    <th class="px-3 py-2 text-start">{{ __('payroll.recognition_date') }}</th>
                                    <th class="px-3 py-2 text-start">{{ __('payroll.period') }}</th>
                                    <th class="px-3 py-2 text-start">{{ __('payroll.net_payable') }}</th>
                                    <th class="px-3 py-2 text-start">{{ __('payroll.remaining_payable') }}</th>
                                    <th class="px-3 py-2 text-start w-48">{{ __('payroll.allocate_payment') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach($unpaidEntries as $pos)
                                    @php $ent = $pos['entry']; @endphp
                                    <tr>
                                        <td data-label="{{ __('payroll.entry_number') }}" class="px-3 py-2 font-semibold font-mono">{{ $ent->salary_number }}</td>
                                        <td data-label="{{ __('payroll.recognition_date') }}" class="px-3 py-2 text-text-secondary font-mono tabular-nums" dir="ltr">{{ $ent->recognition_date->toDateString() }}</td>
                                        <td data-label="{{ __('payroll.period') }}" class="px-3 py-2 text-text-secondary text-xs font-mono tabular-nums" dir="ltr">
                                            {{ $ent->period_start->toDateString() }} – {{ $ent->period_end->toDateString() }}
                                        </td>
                                        <td data-label="{{ __('payroll.net_payable') }}" class="px-3 py-2 font-medium font-mono tabular-nums" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($pos['net_payable']) }}</td>
                                        <td data-label="{{ __('payroll.remaining_payable') }}" class="px-3 py-2 font-bold text-primary font-mono tabular-nums" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($pos['remaining_payable']) }}</td>
                                        <td data-label="{{ __('payroll.allocate_payment') }}" class="px-3 py-2 lg:min-w-[10rem]">
                                            <label for="salary-payment-entry-{{ $ent->id }}" class="sr-only">{{ __('payroll.allocate_payment') }} — {{ $ent->salary_number }}</label>
                                            <input id="salary-payment-entry-{{ $ent->id }}"
                                                   type="text"
                                                   inputmode="decimal"
                                                   dir="ltr"
                                                   wire:model.live="entryAllocations.{{ $ent->id }}"
                                                   aria-label="{{ __('payroll.allocate_payment') }} — {{ $ent->salary_number }}"
                                                   aria-invalid="{{ $errors->has('entryAllocations.'.$ent->id) ? 'true' : 'false' }}"
                                                   @if ($errors->has('entryAllocations.'.$ent->id)) aria-describedby="salary-payment-entry-{{ $ent->id }}-error" @endif
                                                   placeholder="0.00"
                                                   class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-2.5 text-base lg:text-sm font-mono tabular-nums focus:border-primary focus:ring-primary text-end">
                                            @error('entryAllocations.'.$ent->id)
                                                <p id="salary-payment-entry-{{ $ent->id }}-error" role="alert" class="mt-1 whitespace-normal text-xs text-danger">{{ $message }}</p>
                                            @enderror
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <p class="text-sm text-text-muted bg-surface-soft p-3 rounded-control border border-border">
                    {{ __('payroll.no_unpaid_entries') }}
                </p>
            @endif
        </div>

        <!-- Summary & Total -->
        <div class="rounded-control border border-border bg-surface-soft p-4 flex justify-between items-center">
            <span class="font-bold text-sm text-text-primary">{{ __('payroll.amount_paid') }}:</span>
            <span class="text-2xl font-bold text-success" dir="ltr">
                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($totalAllocated) }} {{ $currencyCode }}
            </span>
        </div>

        <div>
            <label for="salary-payment-notes" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.notes') }}</label>
            <textarea id="salary-payment-notes"
                      wire:model="notes"
                      rows="2"
                      aria-invalid="{{ $errors->has('notes') ? 'true' : 'false' }}"
                      @if ($errors->has('notes')) aria-describedby="salary-payment-notes-error" @endif
                      class="w-full rounded-control border border-border-control bg-canvas px-3 py-2.5 text-base lg:text-sm focus:border-primary focus:ring-primary"></textarea>
            @error('notes') <span id="salary-payment-notes-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
        </div>

        <div class="flex flex-wrap justify-end gap-3 pt-4 border-t border-border">
            <a href="{{ route('employees.index') }}" class="min-h-11 lg:min-h-9.5 inline-flex items-center justify-center gap-2 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary rounded-control border border-border-control bg-surface px-4 text-sm font-semibold text-text-secondary hover:bg-surface-soft">
                {{ __('payroll.cancel') }}
            </a>
            <button type="submit" class="min-h-11 lg:min-h-9.5 inline-flex items-center justify-center gap-2 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary rounded-control bg-emerald-600 px-6 text-sm font-semibold text-white hover:bg-emerald-700 focus:ring-2 focus:ring-emerald-500 cursor-pointer" wire:loading.attr="disabled" wire:target="save">
                {{ __('payroll.save') }}
            </button>
        </div>
    </form>
</div>
