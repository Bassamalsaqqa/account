<div class="max-w-5xl mx-auto space-y-6">


    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <a href="{{ route('employees.index') }}" class="touch-action inline-flex items-center justify-center w-8 h-8 rounded-control border border-border bg-surface text-text-secondary hover:bg-surface-soft">
                &larr;
            </a>
            <div>
                <h1 class="text-2xl font-bold">{{ $employee->name }}</h1>
                <p class="text-sm text-text-muted">{{ $employee->code }} · {{ $employee->job_title ?: __('payroll.employee') }}</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if($detail['has_manage'] && ! $employee->trashed())
                <a href="{{ route('employees.edit', $employee->public_id) }}" class="touch-action inline-flex items-center rounded-control border border-border bg-surface px-3 py-2 text-sm font-semibold text-text-secondary hover:bg-surface-soft">
                    {{ __('payroll.edit_employee') }}
                </a>
            @endif
            @if($detail['has_advance_manage'] && $employee->active && ! $employee->trashed())
                <a href="{{ route('payroll.advances.create', ['employee' => $employee->public_id]) }}" class="touch-action inline-flex items-center rounded-control border border-border bg-surface px-3 py-2 text-sm font-semibold text-text-secondary hover:bg-surface-soft">
                    {{ __('payroll.create_advance') }}
                </a>
            @endif
            @if($canPostSalary && $employee->active && ! $employee->trashed())
                <a href="{{ route('payroll.salary-entries.create', ['employee' => $employee->public_id]) }}" class="touch-action inline-flex items-center rounded-control bg-primary px-3 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                    {{ __('payroll.create_salary_entry') }}
                </a>
            @endif
            @if($canPaySalary)
                <a href="{{ route('payroll.salary-payments.create', ['employee' => $employee->public_id]) }}" class="touch-action inline-flex items-center rounded-control bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                    {{ __('payroll.create_salary_payment') }}
                </a>
            @endif
        </div>
    </div>

    @if(session()->has('success'))
        <div class="rounded-control border border-success/20 bg-success-bg p-4 text-sm text-success">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->has('reversal'))
        <div class="rounded-control border border-danger/20 bg-danger-bg p-4 text-sm text-danger">
            {{ $errors->first('reversal') }}
        </div>
    @endif

    <!-- Tabs Header -->
    <div class="border-b border-border">
        <div role="tablist"
             aria-label="{{ __('payroll.employee') }}"
             @keydown="window.handleTablistKeydown?.($event, $el)"
             class="flex flex-wrap gap-4">
            <button type="button"
                    role="tab"
                    id="tab-employee-overview"
                    aria-controls="panel-employee"
                    aria-selected="{{ $tab === 'overview' ? 'true' : 'false' }}"
                    tabindex="{{ $tab === 'overview' ? '0' : '-1' }}"
                    wire:click="$set('tab', 'overview')"
                    class="pb-3 text-sm font-semibold border-b-2 cursor-pointer {{ $tab === 'overview' ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text-secondary' }}">
                {{ __('payroll.identity') }}
            </button>
            @if($detail['has_advance_manage'] || $detail['has_salary_view'])
                <button type="button"
                        role="tab"
                        id="tab-employee-advances"
                        aria-controls="panel-employee"
                        aria-selected="{{ $tab === 'advances' ? 'true' : 'false' }}"
                        tabindex="{{ $tab === 'advances' ? '0' : '-1' }}"
                        wire:click="$set('tab', 'advances')"
                        class="pb-3 text-sm font-semibold border-b-2 cursor-pointer {{ $tab === 'advances' ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text-secondary' }}">
                    {{ __('payroll.advances') }} ({{ $advanceCount }})
                </button>
            @endif
            @if($detail['has_salary_view'])
                <button type="button"
                        role="tab"
                        id="tab-employee-salaries"
                        aria-controls="panel-employee"
                        aria-selected="{{ $tab === 'salaries' ? 'true' : 'false' }}"
                        tabindex="{{ $tab === 'salaries' ? '0' : '-1' }}"
                        wire:click="$set('tab', 'salaries')"
                        class="pb-3 text-sm font-semibold border-b-2 cursor-pointer {{ $tab === 'salaries' ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text-secondary' }}">
                    {{ __('payroll.salary_entries') }} ({{ $salaryCount }})
                </button>
                <button type="button"
                        role="tab"
                        id="tab-employee-statement"
                        aria-controls="panel-employee"
                        aria-selected="{{ $tab === 'statement' ? 'true' : 'false' }}"
                        tabindex="{{ $tab === 'statement' ? '0' : '-1' }}"
                        wire:click="$set('tab', 'statement')"
                        class="pb-3 text-sm font-semibold border-b-2 cursor-pointer {{ $tab === 'statement' ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text-secondary' }}">
                    {{ __('payroll.statement') }}
                </button>
            @endif
        </div>
    </div>

    <div role="tabpanel"
         id="panel-employee"
         aria-labelledby="tab-employee-{{ $tab }}"
         tabindex="0"
         wire:key="employee-panel-{{ $tab }}"
         wire:loading.class="opacity-70">
    <!-- Tab 1: Overview -->
    @if($tab === 'overview')
        <div class="rounded-card border border-border bg-surface p-6 space-y-6">
            <div class="grid gap-6 sm:grid-cols-2 md:grid-cols-3">
                <div>
                    <span class="block text-sm font-semibold text-text-muted uppercase">{{ __('payroll.code') }}</span>
                    <span class="text-base font-bold text-text-primary">{{ $employee->code }}</span>
                </div>
                <div>
                    <span class="block text-sm font-semibold text-text-muted uppercase">{{ __('payroll.job_title') }}</span>
                    <span class="text-base font-bold text-text-primary">{{ $employee->job_title ?: '-' }}</span>
                </div>
                <div>
                    <span class="block text-sm font-semibold text-text-muted uppercase">{{ __('payroll.status') }}</span>
                    @if($employee->trashed())
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-sm font-semibold bg-danger-bg text-danger">
                            {{ __('payroll.archived') }}
                        </span>
                    @elseif($employee->active)
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-sm font-semibold bg-success-bg text-success">
                            {{ __('payroll.active') }}
                        </span>
                    @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-sm font-semibold bg-surface-soft text-text-secondary">
                            {{ __('payroll.inactive') }}
                        </span>
                    @endif
                </div>
                <div>
                    <span class="block text-sm font-semibold text-text-muted uppercase">{{ __('payroll.phone') }}</span>
                    <span class="text-sm font-medium text-text-primary" dir="ltr">{{ $employee->phone ?: '-' }}</span>
                </div>
                <div>
                    <span class="block text-sm font-semibold text-text-muted uppercase">{{ __('payroll.hire_date') }}</span>
                    <span class="text-sm font-medium text-text-primary">{{ $employee->hire_date ? $employee->hire_date->toDateString() : '-' }}</span>
                </div>
                @if($detail['has_salary_view'])
                    <div>
                        <span class="block text-sm font-semibold text-text-muted uppercase">{{ __('payroll.salary') }}</span>
                        <span class="text-base font-bold text-text-primary" dir="ltr">
                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($detail['employee']->default_salary ?: '-') }} {{ $detail['employee']->salary_currency_code }}
                        </span>
                    </div>
                @endif
            </div>

            @if($employee->notes)
                <div>
                    <span class="block text-sm font-semibold text-text-muted uppercase mb-1">{{ __('payroll.notes') }}</span>
                    <p class="text-sm text-text-secondary bg-surface-soft p-3 rounded-control border border-border">
                        {{ $employee->notes }}
                    </p>
                </div>
            @endif
        </div>
    @endif

    <!-- Tab 2: Advances -->
    @if($tab === 'advances' && ($detail['has_advance_manage'] || $detail['has_salary_view']))
        <div class="rounded-card border border-border bg-surface overflow-hidden shadow-sm">
            <div class="p-4 border-b border-border flex justify-between items-center bg-surface-soft">
                <h2 class="font-bold text-sm text-text-primary">{{ __('payroll.advances') }}</h2>
                @if($detail['has_advance_manage'] && $employee->active)
                    <a href="{{ route('payroll.advances.create', ['employee' => $employee->public_id]) }}" class="touch-action inline-flex items-center rounded-control bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-hover">
                        {{ __('payroll.create_advance') }}
                    </a>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="data-table data-table--responsive w-full text-sm text-start">
                    <thead class="bg-surface-soft border-b border-border text-sm uppercase text-text-muted">
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
                            <tr wire:key="edet-adv-{{ $adv->id }}" x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'" class="hover:bg-surface-soft/60">
                                <td class="td-primary px-4 py-3 font-semibold">
                                    <div class="flex items-center gap-2">
                                        <bdi dir="ltr" class="font-mono truncate">{{ $adv->advance_number }}</bdi>
                                        <span class="lg:hidden inline-flex px-2 py-0.5 rounded text-xs font-semibold shrink-0 {{ $adv->status === 'posted' ? 'bg-success-bg text-success' : 'bg-danger-bg text-danger' }}">
                                            {{ __('expenses.'.$adv->status) }}
                                        </span>
                                    </div>
                                    <div class="lg:hidden font-normal text-xs text-text-secondary mt-0.5">
                                        <bdi dir="ltr">{{ $adv->advance_date->toDateString() }}</bdi> &middot; {{ __('payroll.'.$adv->payment_method) }}
                                    </div>
                                </td>
                                <td class="td-secondary px-4 py-3 text-text-secondary" data-label="{{ __('payroll.advance_date') }}" dir="ltr">{{ $adv->advance_date->toDateString() }}</td>
                                <td class="td-secondary px-4 py-3 text-text-secondary" data-label="{{ __('payroll.payment_method') }}">{{ __('payroll.'.$adv->payment_method) }}</td>
                                <td class="td-secondary px-4 py-3 font-medium" data-label="{{ __('payroll.original_amount') }}" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($pos['original_amount']) }} {{ $pos['currency_code'] }}</td>
                                <td class="td-end px-4 py-3 font-bold {{ $pos['is_available'] ? 'text-success' : 'text-text-muted' }}">
                                    <div class="inline-flex items-center gap-2">
                                        <bdi dir="ltr" class="tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($pos['remaining_amount']) }} {{ $pos['currency_code'] }}</bdi>
                                        <x-row-details-toggle />
                                    </div>
                                </td>
                                <td class="td-secondary px-4 py-3" data-label="{{ __('payroll.status') }}">
                                    <span class="inline-flex px-2 py-0.5 rounded text-sm font-semibold {{ $adv->status === 'posted' ? 'bg-success-bg text-success' : 'bg-danger-bg text-danger' }}">
                                        {{ __('expenses.'.$adv->status) }}
                                    </span>
                                </td>
                                @if($detail['has_advance_manage'])
                                    <td class="table-actions px-4 py-3 text-end">
                                        @if($adv->status === 'posted' && $pos['is_available'] && $adv->payment_method !== 'check')
                                            <button type="button" wire:click="openReversalReview('advance', {{ $adv->id }})" class="text-sm font-semibold text-danger hover:text-danger" wire:loading.attr="disabled" wire:target="reverseAdvance">
                                                {{ __('payroll.reverse_advance') }}
                                            </button>
                                        @elseif($adv->payment_method === 'check' && $adv->check)
                                            <a href="{{ route('money.checks.show', $adv->check->public_id) }}" class="touch-action text-sm font-semibold text-primary hover:underline">
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
                <div class="p-4"><x-table-pagination :paginator="$advancePositions" /></div>
        </div>
    @endif

    <!-- Tab 3: Salary Entries -->
    @if($tab === 'salaries' && $detail['has_salary_view'])
        <div class="rounded-card border border-border bg-surface overflow-hidden shadow-sm">
            <div class="p-4 border-b border-border flex justify-between items-center bg-surface-soft">
                <h2 class="font-bold text-sm text-text-primary">{{ __('payroll.salary_entries') }}</h2>
                <div class="flex gap-2">
                    @if($canPostSalary && $employee->active)
                        <a href="{{ route('payroll.salary-entries.create', ['employee' => $employee->public_id]) }}" class="touch-action inline-flex items-center rounded-control bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-hover">
                            {{ __('payroll.create_salary_entry') }}
                        </a>
                    @endif
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table data-table--responsive w-full text-sm text-start">
                    <thead class="bg-surface-soft border-b border-border text-sm uppercase text-text-muted">
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
                            <tr wire:key="edet-sal-{{ $ent->id }}" x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'" class="hover:bg-surface-soft/60">
                                <td class="td-primary px-4 py-3 font-semibold">
                                    <div class="flex items-center gap-2">
                                        <bdi dir="ltr" class="font-mono truncate">{{ $ent->salary_number }}</bdi>
                                        <span class="lg:hidden inline-flex px-2 py-0.5 rounded text-xs font-semibold shrink-0 {{ $ent->status === 'posted' ? 'bg-success-bg text-success' : 'bg-danger-bg text-danger' }}">
                                            {{ __('expenses.'.$ent->status) }}
                                        </span>
                                    </div>
                                    <div class="lg:hidden font-normal text-xs text-text-secondary mt-0.5" dir="ltr">
                                        {{ $ent->period_start->toDateString() }} &ndash; {{ $ent->period_end->toDateString() }}
                                    </div>
                                </td>
                                <td class="td-secondary px-4 py-3 text-text-secondary" data-label="{{ __('payroll.recognition_date') }}" dir="ltr">{{ $ent->recognition_date->toDateString() }}</td>
                                <td class="td-secondary px-4 py-3 text-text-secondary text-sm" data-label="{{ __('payroll.period') }}" dir="ltr">
                                    {{ $ent->period_start->toDateString() }} – {{ $ent->period_end->toDateString() }}
                                </td>
                                <td class="td-secondary px-4 py-3 font-medium" data-label="{{ __('payroll.earned_salary') }}" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($ent->earned_salary) }} {{ $ent->currency_code }}</td>
                                <td class="td-secondary px-4 py-3 text-warning font-medium" data-label="{{ __('payroll.advances_applied') }}" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($ent->advance_applied) }} {{ $ent->currency_code }}</td>
                                <td class="td-secondary px-4 py-3 font-bold" data-label="{{ __('payroll.net_payable') }}" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($pos['net_payable']) }} {{ $pos['currency_code'] }}</td>
                                <td class="td-end px-4 py-3 font-bold {{ $pos['is_unpaid'] ? 'text-primary' : 'text-success' }}">
                                    <div class="inline-flex items-center gap-2">
                                        <bdi dir="ltr" class="tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($pos['remaining_payable']) }} {{ $pos['currency_code'] }}</bdi>
                                        <x-row-details-toggle />
                                    </div>
                                </td>
                                <td class="td-secondary px-4 py-3" data-label="{{ __('payroll.status') }}">
                                    <span class="inline-flex px-2 py-0.5 rounded text-sm font-semibold {{ $ent->status === 'posted' ? 'bg-success-bg text-success' : 'bg-danger-bg text-danger' }}">
                                        {{ __('expenses.'.$ent->status) }}
                                    </span>
                                </td>
                                @if($canReverseSalary)
                                    <td class="table-actions px-4 py-3 text-end">
                                        @if($ent->status === 'posted')
                                            <button type="button" wire:click="openReversalReview('salary', {{ $ent->id }})" class="text-sm font-semibold text-danger hover:text-danger" wire:loading.attr="disabled" wire:target="reverseSalaryEntry">
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
                <div class="p-4"><x-table-pagination :paginator="$salaryPositions" /></div>
        </div>
    @endif

    <!-- Tab 4: Statement -->
    @if($tab === 'statement' && $detail['has_salary_view'])
        <div class="space-y-6">
            <!-- Balances by Currency -->
            <div class="grid gap-4 sm:grid-cols-2 md:grid-cols-3">
                @foreach($statement['balances_by_currency'] as $curr => $bal)
                    <div class="rounded-card border border-border bg-surface p-4 space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="font-bold text-base text-primary">{{ $curr }}</span>
                            <span class="text-sm text-text-muted uppercase">{{ __('payroll.balances') }}</span>
                        </div>
                        <div class="border-t border-border pt-2 space-y-1 text-sm">
                            <div class="flex justify-between">
                                <span class="text-text-secondary">{{ __('payroll.outstanding_advance') }}:</span>
                                <span class="font-semibold text-warning" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($bal['outstanding_advance']) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-text-secondary">{{ __('payroll.outstanding_payable') }}:</span>
                                <span class="font-semibold text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($bal['outstanding_payable']) }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Statement Table -->
            <div class="rounded-card border border-border bg-surface overflow-hidden shadow-sm">
                <div class="p-4 border-b border-border bg-surface-soft">
                    <h2 class="font-bold text-sm text-text-primary">{{ __('payroll.statement') }}</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="data-table data-table--responsive w-full text-sm text-start">
                        <thead class="bg-surface-soft border-b border-border text-sm uppercase text-text-muted">
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
                            @forelse($statementEvents as $evtIdx => $evt)
                                <tr wire:key="edet-stmt-{{ $evtIdx }}" x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'" class="hover:bg-surface-soft/60">
                                    <td class="td-secondary px-4 py-3 whitespace-nowrap text-text-secondary" data-label="{{ __('payroll.statement_date') }}" dir="ltr">{{ $evt['date'] }}</td>
                                    <td class="td-secondary px-4 py-3 font-semibold" data-label="{{ __('payroll.statement_type') }}">
                                        @if($evt['type'] === 'advance')
                                            <span class="text-warning">{{ __('payroll.advance') }}</span>
                                        @elseif($evt['type'] === 'salary_entry')
                                            <span class="text-primary">{{ __('payroll.salary_entry') }}</span>
                                        @else
                                            <span class="text-success">{{ __('payroll.salary_payment') }}</span>
                                        @endif
                                    </td>
                                    <td class="td-primary px-4 py-3 font-medium">
                                        <div class="flex items-center gap-2">
                                            <bdi dir="ltr" class="font-mono font-semibold truncate">{{ $evt['number'] }}</bdi>
                                            <span class="lg:hidden inline-flex px-2 py-0.5 rounded text-xs font-semibold shrink-0 {{ $evt['status'] === 'posted' ? 'bg-success-bg text-success' : 'bg-danger-bg text-danger' }}">
                                                {{ __('expenses.'.$evt['status']) }}
                                            </span>
                                        </div>
                                        <div class="lg:hidden font-normal text-xs text-text-secondary mt-0.5">
                                            <bdi dir="ltr" class="font-mono">{{ $evt['date'] }}</bdi> &middot; {{ __('payroll.'.$evt['type']) }}
                                        </div>
                                    </td>
                                    <td class="td-secondary px-4 py-3 text-text-secondary text-sm" data-label="{{ __('payroll.statement_details') }}">
                                        @if($evt['type'] === 'salary_entry')
                                            {{ $evt['period'] }} ({{ __('payroll.advance_settlement') }}: {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($evt['advance_applied']) }})
                                        @elseif(isset($evt['method']))
                                            {{ __('payroll.'.$evt['method']) }}
                                        @endif
                                    </td>
                                    <td class="td-end px-4 py-3 font-bold whitespace-nowrap">
                                        <div class="inline-flex items-center gap-2">
                                            <bdi dir="ltr" class="tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($evt['type'] === 'salary_entry' ? $evt['net_payable'] : ($evt['amount'] ?? '')) }} {{ $evt['currency_code'] }}</bdi>
                                            <x-row-details-toggle />
                                        </div>
                                    </td>
                                    <td class="table-actions px-4 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded text-sm font-semibold {{ $evt['status'] === 'posted' ? 'bg-success-bg text-success' : 'bg-danger-bg text-danger' }}">
                                            {{ __('expenses.'.$evt['status']) }}
                                        </span>
                                        @if($evt['type']==='salary_payment' && $evt['status']==='posted' && $canReverseSalary && $evt['method']!=='check')
                                            <button wire:click="openReversalReview('salary_payment', {{ $evt['id'] }})" wire:loading.attr="disabled" class="text-danger">{{ __('payroll.reverse_payment') }}</button>
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
                <div class="p-4"><x-table-pagination :paginator="$statementEvents" /></div>
            </div>
        </div>
    @endif
    </div>
<x-standalone-reversal-review />
</div>
