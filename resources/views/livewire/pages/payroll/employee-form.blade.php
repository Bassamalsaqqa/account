<div class="max-w-3xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">{{ $publicId ? __('payroll.edit_employee') : __('payroll.create_employee') }}</h1>
            <p class="text-sm text-text-muted">{{ __('payroll.title') }}</p>
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
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.name') }} *</label>
                <input type="text" wire:model="name" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('name') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.code') }} *</label>
                <input type="text" wire:model="code" placeholder="EMP-001" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('code') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.job_title') }}</label>
                <input type="text" wire:model="jobTitle" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
            </div>
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.phone') }}</label>
                <input type="text" wire:model="phone" dir="ltr" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
            </div>
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.hire_date') }}</label>
                <input type="date" wire:model="hireDate" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
            </div>
        </div>

        @if($canSalary)
            <div class="border-t border-border pt-6 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.salary') }}</label>
                    <input type="text" inputmode="decimal" wire:model="defaultSalary" placeholder="0.00" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    @error('defaultSalary') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.salary_currency') }}</label>
                    <select wire:model="salaryCurrencyCode" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                        @foreach($currencies as $curr)
                            <option value="{{ $curr->currency_code }}">{{ $curr->currency_code }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endif

        <div class="border-t border-border pt-6 space-y-4">
            <div>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" wire:model="active" class="rounded text-primary focus:ring-primary">
                    <span class="text-sm font-semibold text-text-primary">{{ __('payroll.active') }}</span>
                </label>
            </div>
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('payroll.notes') }}</label>
                <textarea wire:model="notes" rows="3" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary"></textarea>
            </div>
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
