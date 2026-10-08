<div class="max-w-5xl mx-auto space-y-6">
    @if($detail['has_advance_manage'] || $canReverseSalary)
    <section class="rounded-card border border-border bg-white p-4 space-y-3">
        <label class="block text-sm">{{ __('money.date') }}<input type="date" wire:model="reversalDate" class="w-full rounded-control border-border"></label>
        <label class="block text-sm">{{ __('expenses.reversal_reason') }}<input type="text" wire:model="reversalReason" maxlength="500" class="w-full rounded-control border-border"></label>
        @foreach($errors->all() as $error)<p role="alert" class="text-red-700">{{ $error }}</p>@endforeach
    </section>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <a href="{{ route('employees.index') }}" class="inline-flex items-center justify-center w-8 h-8 rounded-control border border-border bg-white text-text-secondary hover:bg-surface-soft">
                &larr;
            </a>
            <div>
                <h1 class="text-2xl font-bold">{{ $employee->name }}</h1>
                <p class="text-sm text-text-muted">{{ $employee->code }} · {{ $employee->job_title ?: __('payroll.employee') }}</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if($detail['has_manage'] && ! $employee->trashed())
                <a href="{{ route('employees.edit', $employee->public_id) }}" class="inline-flex items-center rounded-control border border-border bg-white px-3 py-2 text-sm font-semibold text-text-secondary hover:bg-surface-soft">
                    {{ __('payroll.edit_employee') }}
                </a>
            @endif
            @if($detail['has_advance_manage'] && $employee->active && ! $employee->trashed())
                <a href="{{ route('payroll.advances.create', ['employee' => $employee->public_id]) }}" class="inline-flex items-center rounded-control border border-border bg-white px-3 py-2 text-sm font-semibold text-text-secondary hover:bg-surface-soft">
                    {{ __('payroll.create_advance') }}
                </a>
            @endif
            @if($canPostSalary && $employee->active && ! $employee->trashed())
                <a href="{{ route('payroll.salary-entries.create', ['employee' => $employee->public_id]) }}" class="inline-flex items-center rounded-control bg-primary px-3 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                    {{ __('payroll.create_salary_entry') }}
                </a>
            @endif
            @if($canPaySalary)
                <a href="{{ route('payroll.salary-payments.create', ['employee' => $employee->public_id]) }}" class="inline-flex items-center rounded-control bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                    {{ __('payroll.create_salary_payment') }}
                </a>
            @endif
        </div>
    </div>

    @if(session()->has('success'))
        <div class="rounded-control border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->has('reversal'))
        <div class="rounded-control border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
            {{ $errors->first('reversal') }}
        </div>
    @endif

    <!-- Tabs Header -->
    <div class="border-b border-border">
        <nav class="flex flex-wrap gap-4">
            <button wire:click="$set('tab', 'overview')" class="pb-3 text-sm font-semibold border-b-2 {{ $tab === 'overview' ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text-secondary' }}">
                {{ __('payroll.identity') }}
            </button>
            @if($detail['has_advance_manage'] || $detail['has_salary_view'])
                <button wire:click="$set('tab', 'advances')" class="pb-3 text-sm font-semibold border-b-2 {{ $tab === 'advances' ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text-secondary' }}">
                    {{ __('payroll.advances') }} ({{ count($advancePositions) }})
                </button>
            @endif
            @if($detail['has_salary_view'])
                <button wire:click="$set('tab', 'salaries')" class="pb-3 text-sm font-semibold border-b-2 {{ $tab === 'salaries' ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text-secondary' }}">
                    {{ __('payroll.salary_entries') }} ({{ count($salaryPositions) }})
                </button>
                <button wire:click="$set('tab', 'statement')" class="pb-3 text-sm font-semibold border-b-2 {{ $tab === 'statement' ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text-secondary' }}">
                    {{ __('payroll.statement') }}
                </button>
            @endif
        </nav>
    </div>

    <!-- Tab 1: Overview -->
    @if($tab === 'overview')
        <div class="rounded-card border border-border bg-white p-6 space-y-6">
            <div class="grid gap-6 sm:grid-cols-2 md:grid-cols-3">
                <div>
                    <span class="block text-xs font-semibold text-text-muted uppercase">{{ __('payroll.code') }}</span>
                    <span class="text-base font-bold text-text-primary">{{ $employee->code }}</span>
                </div>
                <div>
                    <span class="block text-xs font-semibold text-text-muted uppercase">{{ __('payroll.job_title') }}</span>
                    <span class="text-base font-bold text-text-primary">{{ $employee->job_title ?: '-' }}</span>
                </div>
                <div>
                    <span class="block text-xs font-semibold text-text-muted uppercase">{{ __('payroll.status') }}</span>
                    @if($employee->trashed())
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-rose-100 text-rose-800">
                            {{ __('payroll.archived') }}
                        </span>
                    @elseif($employee->active)
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-emerald-100 text-emerald-800">
                            {{ __('payroll.active') }}
                        </span>
                    @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-slate-100 text-slate-700">
                            {{ __('payroll.inactive') }}
                        </span>
                    @endif
                </div>
                <div>
                    <span class="block text-xs font-semibold text-text-muted uppercase">{{ __('payroll.phone') }}</span>
                    <span class="text-sm font-medium text-text-primary" dir="ltr">{{ $employee->phone ?: '-' }}</span>
                </div>
                <div>
                    <span class="block text-xs font-semibold text-text-muted uppercase">{{ __('payroll.hire_date') }}</span>
                    <span class="text-sm font-medium text-text-primary">{{ $employee->hire_date ? $employee->hire_date->toDateString() : '-' }}</span>
                </div>
                @if($detail['has_salary_view'])
                    <div>
                        <span class="block text-xs font-semibold text-text-muted uppercase">{{ __('payroll.salary') }}</span>
                        <span class="text-base font-bold text-text-primary" dir="ltr">
                            {{ $detail['employee']->default_salary ?: '-' }} {{ $detail['employee']->salary_currency_code }}
                        </span>
                    </div>
                @endif
            </div>

            @if($employee->notes)
                <div>
                    <span class="block text-xs font-semibold text-text-muted uppercase mb-1">{{ __('payroll.notes') }}</span>
                    <p class="text-sm text-text-secondary bg-surface-soft p-3 rounded-control border border-border">
                        {{ $employee->notes }}
                    </p>
                </div>
            @endif
        </div>
    @endif

    <!-- Tab 2: Advances -->
    @if($tab === 'advances' && ($detail['has_advance_manage'] || $detail['has_salary_view']))
        <div class="rounded-card border border-border bg-white overflow-hidden shadow-sm">
            <div class="p-4 border-b border-border flex justify-between items-center bg-surface-soft">
                <h2 class="font-bold text-sm text-text-primary">{{ __('payroll.advances') }}</h2>
                @if($detail['has_advance_manage'] && $employee->active)
                    <a href="{{ route('payroll.advances.create', ['employee' => $employee->public_id]) }}" class="inline-flex items-center rounded-control bg-primary px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-hover">
                        {{ __('payroll.create_advance') }}
                    </a>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-start">
                    <thead class="bg-surface-soft border-b border-border text-xs uppercase text-text-muted">
                        <tr>
                            <th class="px-4 py-3 text-start">{{ __('payroll.advance_number') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('payroll.advance_date') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('payroll.payment_method') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('payroll.original_amount') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('payroll.remaining_amount') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('payroll.status') }}</th>
                            @if($detail['has_advance_manage'])
                                <th class="px-4 py-3 text-end">{{ __('payroll.reverse_action') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($advancePositions as $pos)
                            @php $adv = $pos['advance']; @endphp
                            <tr class="hover:bg-surface-soft/60">
                                <td class="px-4 py-3 font-semibold">{{ $adv->advance_number }}</td>
                                <td class="px-4 py-3 text-text-secondary">{{ $adv->advance_date->toDateString() }}</td>
                                <td class="px-4 py-3 text-text-secondary">{{ __('payroll.'.$adv->payment_method) }}</td>
                                <td class="px-4 py-3 font-medium" dir="ltr">{{ $pos['original_amount'] }} {{ $pos['currency_code'] }}</td>
                                <td class="px-4 py-3 font-bold {{ $pos['is_available'] ? 'text-emerald-700' : 'text-text-muted' }}" dir="ltr">
                                    {{ $pos['remaining_amount'] }} {{ $pos['currency_code'] }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold {{ $adv->status === 'posted' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}">
                                        {{ __('expenses.'.$adv->status) }}
                                    </span>
                                </td>
                                @if($detail['has_advance_manage'])
                                    <td class="px-4 py-3 text-end">
                                        @if($adv->status === 'posted' && $pos['is_available'] && $adv->payment_method !== 'check')
                                            <button type="button" wire:click="reverseAdvance({{ $adv->id }})" wire:confirm="{{ __('payroll.reverse_advance_confirm') }}" class="text-xs font-semibold text-rose-600 hover:text-rose-800">
                                                {{ __('payroll.reverse_advance') }}
                                            </button>
                                        @elseif($adv->payment_method === 'check' && $adv->check)
                                            <a href="{{ route('money.checks.show', $adv->check->public_id) }}" class="text-xs font-semibold text-primary hover:underline">
                                                {{ __('payroll.check') }}
                                            </a>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-text-muted">
                                    {{ __('payroll.empty_advances') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Tab 3: Salary Entries -->
    @if($tab === 'salaries' && $detail['has_salary_view'])
        <div class="rounded-card border border-border bg-white overflow-hidden shadow-sm">
            <div class="p-4 border-b border-border flex justify-between items-center bg-surface-soft">
                <h2 class="font-bold text-sm text-text-primary">{{ __('payroll.salary_entries') }}</h2>
                <div class="flex gap-2">
                    @if($canPostSalary && $employee->active)
                        <a href="{{ route('payroll.salary-entries.create', ['employee' => $employee->public_id]) }}" class="inline-flex items-center rounded-control bg-primary px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-hover">
                            {{ __('payroll.create_salary_entry') }}
                        </a>
                    @endif
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-start">
                    <thead class="bg-surface-soft border-b border-border text-xs uppercase text-text-muted">
                        <tr>
                            <th class="px-4 py-3 text-start">{{ __('payroll.entry_number') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('payroll.recognition_date') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('payroll.period') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('payroll.earned_salary') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('payroll.advances_applied') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('payroll.net_payable') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('payroll.remaining_payable') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('payroll.status') }}</th>
                            @if($canReverseSalary)
                                <th class="px-4 py-3 text-end">{{ __('payroll.reverse_action') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($salaryPositions as $pos)
                            @php $ent = $pos['entry']; @endphp
                            <tr class="hover:bg-surface-soft/60">
                                <td class="px-4 py-3 font-semibold">{{ $ent->salary_number }}</td>
                                <td class="px-4 py-3 text-text-secondary">{{ $ent->recognition_date->toDateString() }}</td>
                                <td class="px-4 py-3 text-text-secondary text-xs">
                                    {{ $ent->period_start->toDateString() }} – {{ $ent->period_end->toDateString() }}
                                </td>
                                <td class="px-4 py-3 font-medium" dir="ltr">{{ $ent->earned_salary }} {{ $ent->currency_code }}</td>
                                <td class="px-4 py-3 text-amber-700 font-medium" dir="ltr">{{ $ent->advance_applied }} {{ $ent->currency_code }}</td>
                                <td class="px-4 py-3 font-bold" dir="ltr">{{ $pos['net_payable'] }} {{ $pos['currency_code'] }}</td>
                                <td class="px-4 py-3 font-bold {{ $pos['is_unpaid'] ? 'text-primary' : 'text-emerald-700' }}" dir="ltr">
                                    {{ $pos['remaining_payable'] }} {{ $pos['currency_code'] }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold {{ $ent->status === 'posted' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}">
                                        {{ __('expenses.'.$ent->status) }}
                                    </span>
                                </td>
                                @if($canReverseSalary)
                                    <td class="px-4 py-3 text-end">
                                        @if($ent->status === 'posted')
                                            <button type="button" wire:click="reverseSalaryEntry({{ $ent->id }})" wire:confirm="{{ __('payroll.reverse_salary_entry_confirm') }}" class="text-xs font-semibold text-rose-600 hover:text-rose-800">
                                                {{ __('payroll.reverse_salary_entry') }}
                                            </button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-text-muted">
                                    {{ __('payroll.empty_entries') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Tab 4: Statement -->
    @if($tab === 'statement' && $detail['has_salary_view'])
        <div class="space-y-6">
            <!-- Balances by Currency -->
            <div class="grid gap-4 sm:grid-cols-2 md:grid-cols-3">
                @foreach($statement['balances_by_currency'] as $curr => $bal)
                    <div class="rounded-card border border-border bg-white p-4 space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="font-bold text-base text-primary">{{ $curr }}</span>
                            <span class="text-xs text-text-muted uppercase">{{ __('payroll.balances') }}</span>
                        </div>
                        <div class="border-t border-border pt-2 space-y-1 text-sm">
                            <div class="flex justify-between">
                                <span class="text-text-secondary">{{ __('payroll.outstanding_advance') }}:</span>
                                <span class="font-semibold text-amber-700" dir="ltr">{{ $bal['outstanding_advance'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-text-secondary">{{ __('payroll.outstanding_payable') }}:</span>
                                <span class="font-semibold text-primary" dir="ltr">{{ $bal['outstanding_payable'] }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Statement Table -->
            <div class="rounded-card border border-border bg-white overflow-hidden shadow-sm">
                <div class="p-4 border-b border-border bg-surface-soft">
                    <h2 class="font-bold text-sm text-text-primary">{{ __('payroll.statement') }}</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-start">
                        <thead class="bg-surface-soft border-b border-border text-xs uppercase text-text-muted">
                            <tr>
                                <th class="px-4 py-3 text-start">{{ __('payroll.statement_date') }}</th>
                                <th class="px-4 py-3 text-start">{{ __('payroll.statement_type') }}</th>
                                <th class="px-4 py-3 text-start">{{ __('payroll.statement_ref') }}</th>
                                <th class="px-4 py-3 text-start">{{ __('payroll.statement_details') }}</th>
                                <th class="px-4 py-3 text-start">{{ __('payroll.amount') }}</th>
                                <th class="px-4 py-3 text-start">{{ __('payroll.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse($statement['events'] as $evt)
                                <tr class="hover:bg-surface-soft/60">
                                    <td class="px-4 py-3 whitespace-nowrap text-text-secondary">{{ $evt['date'] }}</td>
                                    <td class="px-4 py-3 font-semibold">
                                        @if($evt['type'] === 'advance')
                                            <span class="text-amber-700">{{ __('payroll.advance') }}</span>
                                        @elseif($evt['type'] === 'salary_entry')
                                            <span class="text-primary">{{ __('payroll.salary_entry') }}</span>
                                        @else
                                            <span class="text-emerald-700">{{ __('payroll.salary_payment') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-medium">{{ $evt['number'] }}</td>
                                    <td class="px-4 py-3 text-text-secondary text-xs">
                                        @if($evt['type'] === 'salary_entry')
                                            {{ $evt['period'] }} ({{ __('payroll.advance_settlement') }}: {{ $evt['advance_applied'] }})
                                        @elseif(isset($evt['method']))
                                            {{ __('payroll.'.$evt['method']) }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-bold whitespace-nowrap" dir="ltr">
                                        {{ $evt['type'] === 'salary_entry' ? $evt['net_payable'] : ($evt['amount'] ?? '') }} {{ $evt['currency_code'] }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold {{ $evt['status'] === 'posted' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}">
                                            {{ __('expenses.'.$evt['status']) }}
                                        </span>
                                        @if($evt['type']==='salary_payment' && $evt['status']==='posted' && $canReverseSalary && $evt['method']!=='check')
                                            <button wire:click="reverseSalaryPayment({{ $evt['id'] }})" wire:loading.attr="disabled" class="text-red-700">{{ __('payroll.reverse_payment') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-text-muted">
                                        {{ __('payroll.empty_movements') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
