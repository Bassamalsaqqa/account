<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">{{ __('payroll.create_salary_entry') }}</h1>
            <p class="text-sm text-text-muted">{{ __('payroll.salary_entries') }}</p>
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
        <!-- Employee & Period -->
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
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.recognition_date') }} *</label>
                <input type="date" wire:model="recognitionDate" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('recognitionDate') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.period_start') }} *</label>
                <input type="date" wire:model="periodStart" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('periodStart') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.period_end') }} *</label>
                <input type="date" wire:model="periodEnd" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('periodEnd') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <!-- Currency & Base Components -->
        <div class="border-t border-border pt-6 grid gap-4 sm:grid-cols-2">
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

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.base_salary') }} *</label>
                <input type="text" inputmode="decimal" wire:model.live="baseSalary" placeholder="0.00" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('baseSalary') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.bonus') }}</label>
                <input type="text" inputmode="decimal" wire:model.live="bonus" placeholder="0.00" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                @error('bonus') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.deduction') }}</label>
                <input type="text" inputmode="decimal" wire:model.live="deduction" placeholder="0.00" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                @error('deduction') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <!-- Available Advances Table -->
        <div class="border-t border-border pt-6 space-y-3">
            <h2 class="font-bold text-sm text-text-primary">{{ __('payroll.available_advances') }}</h2>
            <p class="text-xs text-text-muted">{{ __('payroll.advance_allocation_hint') }}</p>

            @if(count($availableAdvances) > 0)
                <div class="rounded-control border border-border overflow-hidden">
                    <table class="w-full text-sm text-start">
                        <thead class="bg-surface-soft border-b border-border text-xs text-text-muted">
                            <tr>
                                <th class="px-3 py-2 text-start">{{ __('payroll.advance_number') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('payroll.advance_date') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('payroll.original_amount') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('payroll.remaining_amount') }}</th>
                                <th class="px-3 py-2 text-start w-48">{{ __('payroll.allocate_advance') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach($availableAdvances as $pos)
                                @php $adv = $pos['advance']; @endphp
                                <tr>
                                    <td class="px-3 py-2 font-semibold">{{ $adv->advance_number }}</td>
                                    <td class="px-3 py-2 text-text-secondary">{{ $adv->advance_date->toDateString() }}</td>
                                    <td class="px-3 py-2 text-text-secondary" dir="ltr">{{ $pos['original_amount'] }}</td>
                                    <td class="px-3 py-2 font-bold text-emerald-700" dir="ltr">{{ $pos['remaining_amount'] }}</td>
                                    <td class="px-3 py-2">
                                        <input type="text" inputmode="decimal" wire:model.live="advanceAllocations.{{ $adv->id }}" placeholder="0.00" class="w-full rounded-control border border-border bg-white px-2 py-1 text-sm focus:border-primary focus:ring-primary text-end">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-text-muted bg-surface-soft p-3 rounded-control border border-border">
                    {{ __('payroll.no_available_advances') }}
                </p>
            @endif
        </div>

        <!-- Real-time calculation summary -->
        <div class="rounded-control border border-border bg-surface-soft p-4 space-y-2">
            <h3 class="font-bold text-xs text-text-muted uppercase">{{ __('payroll.calculation_summary') }}</h3>
            <div class="grid gap-4 sm:grid-cols-3 pt-1">
                <div>
                    <span class="block text-xs text-text-secondary">{{ __('payroll.earned_salary') }}:</span>
                    <span class="text-base font-bold text-text-primary" dir="ltr">{{ $previewEarned }} {{ $currencyCode }}</span>
                </div>
                <div>
                    <span class="block text-xs text-text-secondary">{{ __('payroll.advances_applied') }}:</span>
                    <span class="text-base font-bold text-amber-700" dir="ltr">{{ $previewApplied }} {{ $currencyCode }}</span>
                </div>
                <div>
                    <span class="block text-xs text-text-secondary">{{ __('payroll.net_payable') }}:</span>
                    <span class="text-xl font-bold text-primary" dir="ltr">{{ $previewNetPayable }} {{ $currencyCode }}</span>
                </div>
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.notes') }}</label>
            <textarea wire:model="notes" rows="2" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary"></textarea>
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
