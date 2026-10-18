<div class="min-w-0 space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('payroll.employees') }}</h1>
            <p class="text-sm text-text-muted">{{ __('payroll.title') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($canManage)
                <a href="{{ route('employees.create') }}" class="touch-action bg-primary text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary focus:ring-offset-2">
                    {{ __('payroll.create_employee') }}
                </a>
            @endif
        </div>
    </div>

    <!-- Filters -->
    <div class="rounded-card border border-border bg-white p-4 space-y-3">
        <x-refinement-filters :active-count="(int) filled($status)">
        <x-slot:search>
            <input aria-label="{{ __('payroll.search_employees') }}" id="employee-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('payroll.search_employees') }}" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
        </x-slot:search>
        <x-slot:statuses>
        <button type="button" wire:click="$set('status', '')" wire:loading.attr="disabled" wire:target="status" aria-pressed="{{ $status === '' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $status === '' ? 'border-primary bg-primary text-white' : 'border-border bg-white text-text-secondary' }}">{{ __('expenses.all_statuses') }}</button>
        <button type="button" wire:click="$set('status', 'active')" wire:loading.attr="disabled" wire:target="status" aria-pressed="{{ $status === 'active' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $status === 'active' ? 'border-primary bg-primary text-white' : 'border-border bg-white text-text-secondary' }}">{{ __('payroll.active') }}</button>
        <button type="button" wire:click="$set('status', 'inactive')" wire:loading.attr="disabled" wire:target="status" aria-pressed="{{ $status === 'inactive' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $status === 'inactive' ? 'border-primary bg-primary text-white' : 'border-border bg-white text-text-secondary' }}">{{ __('payroll.inactive') }}</button>
        </x-slot:statuses>
        <select aria-label="{{ __('expenses.all_statuses') }}" id="employee-status" wire:model.live="status" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('expenses.all_statuses') }}</option>
                    <option value="active">{{ __('payroll.active') }}</option>
                    <option value="inactive">{{ __('payroll.inactive') }}</option>
                </select>
    </x-refinement-filters>
    </div>

    <!-- Table -->
    <div class="rounded-card border border-border bg-white overflow-hidden shadow-sm">
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-sm text-start">
                <thead class="bg-surface-soft border-b border-border text-xs uppercase text-text-muted">
                    <tr>
                        <th class="px-4 py-3 text-start">{{ __('payroll.code') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('payroll.name') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('payroll.job_title') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('payroll.phone') }}</th>
                        @if($canSalary)
                            <th class="px-4 py-3 text-start">{{ __('payroll.salary') }}</th>
                        @endif
                        <th class="px-4 py-3 text-start">{{ __('payroll.status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($employees as $emp)
                        <tr class="hover:bg-surface-soft/60 transition-colors">
                            <td class="px-4 py-3 font-semibold text-text-secondary">
                                <a href="{{ route('employees.show', $emp->public_id) }}" class="touch-action text-primary hover:underline">
                                    {{ $emp->code }}
                                </a>
                            </td>
                            <td class="px-4 py-3 font-medium text-text-primary">
                                <a href="{{ route('employees.show', $emp->public_id) }}" class="touch-action hover:underline">
                                    {{ $emp->name }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-text-secondary">
                                {{ $emp->job_title ?: '-' }}
                            </td>
                            <td class="px-4 py-3 text-text-secondary" dir="ltr">
                                {{ $emp->phone ?: '-' }}
                            </td>
                            @if($canSalary)
                                <td class="px-4 py-3 whitespace-nowrap font-medium" dir="ltr">
                                    @if($emp->default_salary !== null)
                                        {{ $emp->default_salary }} {{ $emp->salary_currency_code }}
                                    @else
                                        -
                                    @endif
                                </td>
                            @endif
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($emp->active)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-emerald-100 text-emerald-800">
                                        {{ __('payroll.active') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-slate-100 text-slate-700">
                                        {{ __('payroll.inactive') }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canSalary ? 6 : 5 }}" class="px-4 py-8 text-center text-text-muted">
                                {{ __('payroll.empty_employees') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
<div class="md:hidden divide-y divide-border">
                    @forelse($employees as $emp)
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('payroll.code') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary"><a href="{{ route('employees.show', $emp->public_id) }}" class="touch-action text-primary hover:underline">
                                    {{ $emp->code }}
                                </a></dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('payroll.name') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary"><a href="{{ route('employees.show', $emp->public_id) }}" class="touch-action hover:underline">
                                    {{ $emp->name }}
                                </a></dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('payroll.job_title') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ $emp->job_title ?: '-' }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('payroll.phone') }}</dt>
                                <dd dir="ltr" class="break-words text-sm tabular-nums text-text-primary">{{ $emp->phone ?: '-' }}</dd>
                            </div>
                            @if($canSalary)
                                <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('payroll.salary') }}</dt>
                                <dd dir="ltr" class="break-words text-sm tabular-nums text-text-primary">@if($emp->default_salary !== null)
                                        {{ $emp->default_salary }} {{ $emp->salary_currency_code }}
                                    @else
                                        -
                                    @endif</dd>
                            </div>
                            @endif
                            <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('payroll.status') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">@if($emp->active)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-emerald-100 text-emerald-800">
                                        {{ __('payroll.active') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-slate-100 text-slate-700">
                                        {{ __('payroll.inactive') }}
                                    </span>
                                @endif</dd>
                            </div>
                        </dl>
                        </article>
                    @empty
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="col-span-2 py-4 text-center text-sm text-text-secondary">
                                {{ __('payroll.empty_employees') }}
                            </div>
                        </dl>
                        </article>
                    @endforelse
                </div>

        @if($employees->hasPages())
            <div class="p-4 border-t border-border">
                {{ $employees->links() }}
            </div>
        @endif
    </div>
</div>
