<div class="max-w-3xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">{{ $publicId ? __('payroll.edit_employee') : __('payroll.create_employee') }}</h1>
            <p class="text-sm text-text-muted">{{ __('payroll.title') }}</p>
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
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="employee-name" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.name') }} *</label>
                <input aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" @if ($errors->has('name')) aria-describedby="employee-name-error" @endif id="employee-name" type="text" wire:model="name" class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary" required>
                @error('name') <span role="alert" id="employee-name-error" class="text-xs text-danger">{{ $message }}</span> @enderror
            </div>
            <div>
                <label for="employee-code" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.code') }} *</label>
                <input aria-invalid="{{ $errors->has('code') ? 'true' : 'false' }}" @if ($errors->has('code')) aria-describedby="employee-code-error" @endif id="employee-code" type="text" wire:model="code" placeholder="EMP-001" class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary" required>
                @error('code') <span role="alert" id="employee-code-error" class="text-xs text-danger">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="employee-job-title" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.job_title') }}</label>
                <input id="employee-job-title" type="text" wire:model="jobTitle" class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary">
            </div>
            <div>
                <label for="employee-phone" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.phone') }}</label>
                <input id="employee-phone" type="text" wire:model="phone" dir="ltr" class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary">
            </div>
            <div>
                <label for="employee-hire-date" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.hire_date') }}</label>
                <input id="employee-hire-date" type="date" wire:model="hireDate" class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary">
            </div>
        </div>

        @if($canSalary)
            <div class="border-t border-border pt-6 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="employee-default-salary" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.salary') }}</label>
                    <input aria-invalid="{{ $errors->has('defaultSalary') ? 'true' : 'false' }}" @if ($errors->has('defaultSalary')) aria-describedby="employee-default-salary-error" @endif id="employee-default-salary" type="text" inputmode="decimal" dir="ltr" wire:model="defaultSalary" placeholder="0.00" class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm font-mono tabular-nums focus:border-primary focus:ring-primary">
                    @error('defaultSalary') <span role="alert" id="employee-default-salary-error" class="text-xs text-danger">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="employee-salary-currency" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.salary_currency') }}</label>
                    <select id="employee-salary-currency" wire:model="salaryCurrencyCode" class="w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-canvas px-3 text-base lg:text-sm focus:border-primary focus:ring-primary">
                        @foreach($currencies as $curr)
                            <option value="{{ $curr->currency_code }}">{{ $curr->currency_code }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endif

        <div class="border-t border-border pt-6 space-y-4">
            <div>
                <label for="employee-active" class="inline-flex min-h-11 min-w-11 items-center gap-2 cursor-pointer">
                    <input id="employee-active"
                           type="checkbox"
                           wire:model="active"
                           aria-invalid="{{ $errors->has('active') ? 'true' : 'false' }}"
                           @if ($errors->has('active')) aria-describedby="employee-active-error" @endif
                           class="size-5 rounded text-primary accent-primary focus:ring-primary">
                    <span class="text-sm font-semibold text-text-primary">{{ __('payroll.active') }}</span>
                </label>
                @error('active')<p id="employee-active-error" role="alert" class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="employee-notes" class="block text-sm font-semibold text-text-secondary mb-1">{{ __('payroll.notes') }}</label>
                <textarea id="employee-notes" wire:model="notes" rows="3" class="w-full rounded-control border border-border-control bg-canvas px-3 py-2.5 text-base lg:text-sm focus:border-primary focus:ring-primary"></textarea>
            </div>
        </div>

        <div class="flex flex-wrap justify-end gap-3 pt-4 border-t border-border">
            <a href="{{ route('employees.index') }}" class="min-h-11 lg:min-h-9.5 inline-flex justify-center items-center rounded-control border border-border-control bg-surface px-4 text-sm font-semibold text-text-secondary hover:bg-surface-soft">
                {{ __('payroll.cancel') }}
            </a>
            <button type="submit" class="min-h-11 lg:min-h-9.5 inline-flex justify-center items-center rounded-control bg-primary px-6 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary cursor-pointer">
                {{ __('payroll.save') }}
            </button>
        </div>
    </form>
</div>
