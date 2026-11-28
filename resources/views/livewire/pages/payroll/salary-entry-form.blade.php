<div class="max-w-4xl mx-auto space-y-6" data-entry-form>
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">{{ __('payroll.create_salary_entry') }}</h1>
            <p class="text-sm text-text-muted">{{ __('payroll.salary_entries') }}</p>
        </div>
        <a href="{{ route('employees.index') }}" class="min-h-11 lg:min-h-9.5 inline-flex items-center rounded-control border border-border-control bg-surface px-3 text-sm font-semibold text-text-secondary hover:bg-surface-soft">
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
        <!-- Employee & Period -->
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="salary-entry-employee" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.employee') }} *</label>
                <select id="salary-entry-employee"
                        wire:model.live="employeeId"
                        aria-invalid="{{ $errors->has('employeeId') ? 'true' : 'false' }}"
                        @if ($errors->has('employeeId')) aria-describedby="salary-entry-employee-error" @endif
                        class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary"
                        required>
                    <option value="">{{ __('payroll.select_employee') }}</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->name }} ({{ $emp->code }})</option>
                    @endforeach
                </select>
                @error('employeeId') <span id="salary-entry-employee-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
            </div>

            <div>
                <label for="salary-entry-recognition-date" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.recognition_date') }} *</label>
                <input id="salary-entry-recognition-date"
                       type="date"
                       wire:model="recognitionDate"
                       aria-invalid="{{ $errors->has('recognitionDate') ? 'true' : 'false' }}"
                       @if ($errors->has('recognitionDate')) aria-describedby="salary-entry-recognition-date-error" @endif
                       class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary"
                       required>
                @error('recognitionDate') <span id="salary-entry-recognition-date-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="salary-entry-period-start" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.period_start') }} *</label>
                <input id="salary-entry-period-start"
                       type="date"
                       wire:model="periodStart"
                       aria-invalid="{{ $errors->has('periodStart') ? 'true' : 'false' }}"
                       @if ($errors->has('periodStart')) aria-describedby="salary-entry-period-start-error" @endif
                       class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary"
                       required>
                @error('periodStart') <span id="salary-entry-period-start-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
            </div>
            <div>
                <label for="salary-entry-period-end" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.period_end') }} *</label>
                <input id="salary-entry-period-end"
                       type="date"
                       wire:model="periodEnd"
                       aria-invalid="{{ $errors->has('periodEnd') ? 'true' : 'false' }}"
                       @if ($errors->has('periodEnd')) aria-describedby="salary-entry-period-end-error" @endif
                       class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary"
                       required>
                @error('periodEnd') <span id="salary-entry-period-end-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
            </div>
        </div>

        <!-- Currency & Base Components -->
        <div class="border-t border-border pt-6 grid gap-4 sm:grid-cols-2">
            <div>
                <label for="salary-entry-currency" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.currency') }} *</label>
                <select id="salary-entry-currency"
                        wire:model.live="currencyCode"
                        aria-invalid="{{ $errors->has('currencyCode') ? 'true' : 'false' }}"
                        @if ($errors->has('currencyCode')) aria-describedby="salary-entry-currency-error" @endif
                        class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary"
                        required>
                    @foreach($currencies as $curr)
                        <option value="{{ $curr->currency_code }}">{{ $curr->currency_code }}</option>
                    @endforeach
                </select>
                @error('currencyCode') <span id="salary-entry-currency-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
            </div>
            @if ($currencyCode !== $baseCurrency)
                <div>
                    <label for="salary-entry-exchange-rate" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.rate') }} *</label>
                    <input id="salary-entry-exchange-rate"
                           type="text"
                           inputmode="decimal"
                           dir="ltr"
                           wire:model="exchangeRate"
                           aria-invalid="{{ $errors->has('exchangeRate') ? 'true' : 'false' }}"
                           @if ($errors->has('exchangeRate')) aria-describedby="salary-entry-exchange-rate-error" @endif
                           placeholder="1.0000000000"
                           class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm font-mono tabular-nums focus:border-primary focus:ring-primary"
                           required>
                    @error('exchangeRate') <span id="salary-entry-exchange-rate-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
                </div>
            @endif
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="salary-entry-base-salary" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.base_salary') }} *</label>
                <input id="salary-entry-base-salary"
                       type="text"
                       inputmode="decimal"
                       dir="ltr"
                       wire:model.live="baseSalary"
                       aria-invalid="{{ $errors->has('baseSalary') ? 'true' : 'false' }}"
                       @if ($errors->has('baseSalary')) aria-describedby="salary-entry-base-salary-error" @endif
                       placeholder="0.00"
                       class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm font-mono tabular-nums focus:border-primary focus:ring-primary"
                       required>
                @error('baseSalary') <span id="salary-entry-base-salary-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
            </div>
            <div>
                <label for="salary-entry-bonus" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.bonus') }}</label>
                <input id="salary-entry-bonus"
                       type="text"
                       inputmode="decimal"
                       dir="ltr"
                       wire:model.live="bonus"
                       aria-invalid="{{ $errors->has('bonus') ? 'true' : 'false' }}"
                       @if ($errors->has('bonus')) aria-describedby="salary-entry-bonus-error" @endif
                       placeholder="0.00"
                       class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm font-mono tabular-nums focus:border-primary focus:ring-primary">
                @error('bonus') <span id="salary-entry-bonus-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
            </div>
            <div>
                <label for="salary-entry-deduction" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.deduction') }}</label>
                <input id="salary-entry-deduction"
                       type="text"
                       inputmode="decimal"
                       dir="ltr"
                       wire:model.live="deduction"
                       aria-invalid="{{ $errors->has('deduction') ? 'true' : 'false' }}"
                       @if ($errors->has('deduction')) aria-describedby="salary-entry-deduction-error" @endif
                       placeholder="0.00"
                       class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm font-mono tabular-nums focus:border-primary focus:ring-primary">
                @error('deduction') <span id="salary-entry-deduction-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
            </div>
        </div>

        <!-- Available Advances Table -->
        <div class="border-t border-border pt-6 space-y-3">
            <h2 class="font-bold text-sm text-text-primary">{{ __('payroll.available_advances') }}</h2>
            <p class="text-xs text-text-muted">{{ __('payroll.advance_allocation_hint') }}</p>

            @if(count($availableAdvances) > 0)
                <div class="relative rounded-control border border-border overflow-hidden">
                    <div class="relative overflow-x-auto">
                        <table class="data-table w-full text-sm text-start">
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
                                        <td data-label="{{ __('payroll.advance_number') }}" class="px-3 py-2 font-semibold font-mono">{{ $adv->advance_number }}</td>
                                        <td data-label="{{ __('payroll.advance_date') }}" class="px-3 py-2 text-text-secondary font-mono tabular-nums" dir="ltr">{{ $adv->advance_date->toDateString() }}</td>
                                        <td data-label="{{ __('payroll.original_amount') }}" class="px-3 py-2 text-text-secondary font-mono tabular-nums" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($pos['original_amount']) }}</td>
                                        <td data-label="{{ __('payroll.remaining_amount') }}" class="px-3 py-2 font-bold text-success font-mono tabular-nums" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($pos['remaining_amount']) }}</td>
                                        <td data-label="{{ __('payroll.allocate_advance') }}" class="px-3 py-2 lg:min-w-[10rem]">
                                            <label for="salary-entry-advance-{{ $adv->id }}" class="sr-only">{{ __('payroll.allocate_advance') }} — {{ $adv->advance_number }}</label>
                                            <input id="salary-entry-advance-{{ $adv->id }}"
                                                   type="text"
                                                   inputmode="decimal"
                                                   dir="ltr"
                                                   wire:model.live="advanceAllocations.{{ $adv->id }}"
                                                   aria-label="{{ __('payroll.allocate_advance') }} — {{ $adv->advance_number }}"
                                                   aria-invalid="{{ $errors->has('advanceAllocations.'.$adv->id) ? 'true' : 'false' }}"
                                                   @if ($errors->has('advanceAllocations.'.$adv->id)) aria-describedby="salary-entry-advance-{{ $adv->id }}-error" @endif
                                                   placeholder="0.00"
                                                   class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-2.5 text-base lg:text-sm font-mono tabular-nums focus:border-primary focus:ring-primary text-end">
                                            @error('advanceAllocations.'.$adv->id)
                                                <p id="salary-entry-advance-{{ $adv->id }}-error" role="alert" class="mt-1 whitespace-normal text-xs text-danger">{{ $message }}</p>
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
                    <span class="text-base font-bold text-text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($previewEarned) }} {{ $currencyCode }}</span>
                </div>
                <div>
                    <span class="block text-xs text-text-secondary">{{ __('payroll.advances_applied') }}:</span>
                    <span class="text-base font-bold text-warning" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($previewApplied) }} {{ $currencyCode }}</span>
                </div>
                <div>
                    <span class="block text-xs text-text-secondary">{{ __('payroll.net_payable') }}:</span>
                    <span class="text-xl font-bold text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($previewNetPayable) }} {{ $currencyCode }}</span>
                </div>
            </div>
        </div>

        <div>
            <label for="salary-entry-notes" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.notes') }}</label>
            <textarea id="salary-entry-notes"
                      wire:model="notes"
                      rows="2"
                      aria-invalid="{{ $errors->has('notes') ? 'true' : 'false' }}"
                      @if ($errors->has('notes')) aria-describedby="salary-entry-notes-error" @endif
                      class="w-full rounded-control border border-border-control bg-canvas px-3 py-2.5 text-base lg:text-sm focus:border-primary focus:ring-primary"></textarea>
            @error('notes') <span id="salary-entry-notes-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
        </div>

        <div class="flex flex-wrap justify-end gap-3 pt-4 border-t border-border">
            <a href="{{ route('employees.index') }}" class="min-h-11 lg:min-h-9.5 inline-flex justify-center items-center rounded-control border border-border-control bg-surface px-4 text-sm font-semibold text-text-secondary hover:bg-surface-soft">
                {{ __('payroll.cancel') }}
            </a>
            <button type="submit" class="min-h-11 lg:min-h-9.5 inline-flex justify-center items-center rounded-control bg-primary px-6 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary cursor-pointer" wire:loading.attr="disabled" wire:target="save">
                {{ __('payroll.save') }}
            </button>
        </div>
    </form>
</div>
