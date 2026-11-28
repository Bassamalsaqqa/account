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
    <div class="rounded-card border border-border bg-surface p-4 space-y-3">
        <x-refinement-filters :active-count="(int) filled($status)">
        <x-slot:search>
            <input aria-label="{{ __('payroll.search_employees') }}" id="employee-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('payroll.search_employees') }}" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary">
        </x-slot:search>
        <x-slot:statuses>
        <button type="button" wire:click="$set('status', '')" wire:loading.attr="disabled" wire:target="status" aria-pressed="{{ $status === '' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $status === '' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('expenses.all_statuses') }}</button>
        <button type="button" wire:click="$set('status', 'active')" wire:loading.attr="disabled" wire:target="status" aria-pressed="{{ $status === 'active' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $status === 'active' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('payroll.active') }}</button>
        <button type="button" wire:click="$set('status', 'inactive')" wire:loading.attr="disabled" wire:target="status" aria-pressed="{{ $status === 'inactive' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $status === 'inactive' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('payroll.inactive') }}</button>
        </x-slot:statuses>
        <select aria-label="{{ __('expenses.all_statuses') }}" id="employee-status" wire:model.live="status" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('expenses.all_statuses') }}</option>
                    <option value="active">{{ __('payroll.active') }}</option>
                    <option value="inactive">{{ __('payroll.inactive') }}</option>
                </select>
    </x-refinement-filters>
    </div>

    <!-- Table -->
    <div class="rounded-card border border-border bg-surface overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
            <table class="data-table data-table--responsive w-full text-sm text-start">
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
                        <tr wire:key="employee-row-{{ $emp->id }}" data-row-surface x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'" class="hover:bg-surface-soft/60 transition-colors">
                            <td class="td-secondary px-4 py-3 font-semibold text-text-secondary" data-label="{{ __('payroll.code') }}">
                                <a href="{{ route('employees.show', $emp->public_id) }}" class="touch-action font-mono text-primary hover:underline" dir="ltr">
                                    {{ $emp->code }}
                                </a>
                            </td>
                            <td class="td-primary px-4 py-3 font-medium text-text-primary">
                                <a href="{{ route('employees.show', $emp->public_id) }}" data-row-link class="touch-action font-semibold hover:text-primary hover:underline truncate block">
                                    {{ $emp->name }}
                                </a>
                                <div class="lg:hidden font-normal text-xs text-text-secondary truncate mt-0.5">
                                    <bdi dir="ltr">{{ $emp->code }}</bdi>@if($emp->job_title) &middot; {{ $emp->job_title }}@endif
                                </div>
                            </td>
                            <td class="td-secondary px-4 py-3 text-text-secondary" data-label="{{ __('payroll.job_title') }}">
                                {{ $emp->job_title ?: '-' }}
                            </td>
                            <td class="td-secondary px-4 py-3 text-text-secondary" data-label="{{ __('payroll.phone') }}" dir="ltr">
                                {{ $emp->phone ?: '-' }}
                            </td>
                            @if($canSalary)
                                <td class="td-secondary px-4 py-3 whitespace-nowrap font-medium" data-label="{{ __('payroll.salary') }}" dir="ltr">
                                    @if($emp->default_salary !== null)
                                        <bdi dir="ltr" class="font-mono tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($emp->default_salary) }} {{ $emp->salary_currency_code }}</bdi>
                                    @else
                                        -
                                    @endif
                                </td>
                            @endif
                            <td class="td-end px-4 py-3 whitespace-nowrap">
                                <div class="inline-flex items-center gap-2">
                                    @if($emp->active)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-success-bg text-success">
                                            {{ __('payroll.active') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-slate-100 text-slate-700">
                                            {{ __('payroll.inactive') }}
                                        </span>
                                    @endif
                                    <x-row-details-toggle />
                                </div>
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

        @if ($employees->count() > 0 || $employees->currentPage() > 1)
            <div class="p-4 border-t border-border">
                <x-table-pagination :paginator="$employees" />
            </div>
        @endif
    </div>
</div>
