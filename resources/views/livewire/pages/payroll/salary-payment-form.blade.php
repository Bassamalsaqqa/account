<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">{{ __('payroll.create_salary_payment') }}</h1>
            <p class="text-sm text-text-muted">{{ __('payroll.salary_payments') }}</p>
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
        <!-- Employee & Date -->
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.employee') }} *</label>
                <select wire:model.live="employeeId" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                    <option value="">{{ __('payroll.select_employee') }}</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->name }} ({{ $emp->code }})</option>
                    @endforeach
                </select>
                @error('employeeId') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.payment_date') }} *</label>
                <input type="date" wire:model="paymentDate" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('paymentDate') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <!-- Currency & Exchange Rate -->
        <div class="grid gap-4 sm:grid-cols-2">
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

        <!-- Unpaid Entries Allocation Table -->
        <div class="border-t border-border pt-6 space-y-3">
            <h2 class="font-bold text-sm text-text-primary">{{ __('payroll.unpaid_entries') }}</h2>
            <p class="text-xs text-text-muted">{{ __('payroll.payment_allocation_hint') }}</p>

            @if(count($unpaidEntries) > 0)
                <div class="rounded-control border border-border overflow-hidden">
                    <table class="w-full text-sm text-start">
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
                                    <td class="px-3 py-2 font-semibold">{{ $ent->salary_number }}</td>
                                    <td class="px-3 py-2 text-text-secondary">{{ $ent->recognition_date->toDateString() }}</td>
                                    <td class="px-3 py-2 text-text-secondary text-xs">
                                        {{ $ent->period_start->toDateString() }} – {{ $ent->period_end->toDateString() }}
                                    </td>
                                    <td class="px-3 py-2 font-medium" dir="ltr">{{ $pos['net_payable'] }}</td>
                                    <td class="px-3 py-2 font-bold text-primary" dir="ltr">{{ $pos['remaining_payable'] }}</td>
                                    <td class="px-3 py-2">
                                        <input type="text" inputmode="decimal" wire:model.live="entryAllocations.{{ $ent->id }}" placeholder="0.00" class="w-full rounded-control border border-border bg-white px-2 py-1 text-sm focus:border-primary focus:ring-primary text-end">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
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
            <span class="text-2xl font-bold text-emerald-700" dir="ltr">
                {{ $totalAllocated }} {{ $currencyCode }}
            </span>
        </div>

        <div>
            <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.notes') }}</label>
            <textarea wire:model="notes" rows="2" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary"></textarea>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-border">
            <a href="{{ route('employees.index') }}" class="inline-flex justify-center items-center rounded-control border border-border bg-white px-4 py-2 text-sm font-semibold text-text-secondary hover:bg-surface-soft">
                {{ __('payroll.cancel') }}
            </a>
            <button type="submit" class="inline-flex justify-center items-center rounded-control bg-emerald-600 px-6 py-2 text-sm font-semibold text-white hover:bg-emerald-700 focus:ring-2 focus:ring-emerald-500">
                {{ __('payroll.save') }}
            </button>
        </div>
    </form>
</div>
