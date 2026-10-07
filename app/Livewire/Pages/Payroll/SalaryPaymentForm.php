<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Payroll;

use App\Actions\Money\IssueCheckAction;
use App\Actions\Payroll\PostSalaryPaymentAction;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\CompanyCurrency;
use App\Models\Employee;
use App\Services\Payroll\PayrollReadService;
use App\Services\Phase7\Phase7SettlementAccounts;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class SalaryPaymentForm extends Component
{
    use AuthorizesMoneyPages;

    #[Locked]
    public string $requestKey;

    #[Url(as: 'employee')]
    public ?string $employeePublicId = null;

    public ?int $employeeId = null;

    public string $paymentDate = '';

    public string $currencyCode = '';

    public string $exchangeRate = '1';

    public string $paymentMethod = 'cash';

    public ?int $moneyAccountId = null;

    // Check fields
    public string $checkNumber = '';

    public string $bankName = '';

    public string $dueDate = '';

    public ?int $drawnMoneyAccountId = null;

    public string $notes = '';

    /** @var array<int, string> */
    public array $entryAllocations = [];

    public function mount(?string $employee = null): void
    {
        $this->authorizeMoney('payroll.salary.pay');

        if ($employee !== null) {
            $this->employeePublicId = $employee;
        }

        $company = app(CompanyContext::class)->company();
        $this->currencyCode = $company->base_currency_code;
        $now = Carbon::now($company->timezone);
        $this->paymentDate = $now->toDateString();
        $this->dueDate = $now->toDateString();
        $this->requestKey = (string) Str::uuid();

        if ($this->employeePublicId !== null) {
            $emp = Employee::withTrashed()
                ->where('company_id', $this->pageCompanyId)
                ->when(! $this->canMoney('payroll.salary.view'), fn ($q) => $q->select(app(PayrollReadService::class)->identityColumns()))
                ->where('public_id', $this->employeePublicId)
                ->first();
            if ($emp !== null) {
                $this->employeeId = $emp->id;
                if ($this->canMoney('payroll.salary.view') && $emp->salary_currency_code) {
                    $this->currencyCode = $emp->salary_currency_code;
                }
            }
        }

        $this->updatedPaymentMethod();
    }

    public function updatedEmployeeId(): void
    {
        $emp = Employee::withTrashed()
            ->where('company_id', $this->pageCompanyId)
            ->when(! $this->canMoney('payroll.salary.view'), fn ($q) => $q->select(app(PayrollReadService::class)->identityColumns()))
            ->find($this->employeeId);
        if ($emp !== null && $this->canMoney('payroll.salary.view') && $emp->salary_currency_code) {
            $this->currencyCode = $emp->salary_currency_code;
        }
        $this->entryAllocations = [];
        $this->updatedPaymentMethod();
    }

    public function updatedPaymentMethod(): void
    {
        if ($this->paymentMethod === 'check') {
            $this->authorizeMoney('money.check.outgoing.manage');
            $firstBank = app(Phase7SettlementAccounts::class)->query($this->pageCompanyId, 'bank')->where('currency_code', $this->currencyCode)
                ->first();
            $this->drawnMoneyAccountId = $firstBank?->id;
            $this->moneyAccountId = null;
        } else {
            $firstAcc = app(Phase7SettlementAccounts::class)->query($this->pageCompanyId, $this->paymentMethod)->where('currency_code', $this->currencyCode)
                ->first();
            $this->moneyAccountId = $firstAcc?->id;
            $this->drawnMoneyAccountId = null;
        }
    }

    public function updatedCurrencyCode(): void
    {
        $company = app(CompanyContext::class)->company();
        if ($this->currencyCode === $company->base_currency_code) {
            $this->exchangeRate = '1';
        } else {
            $this->exchangeRate = '';
        }
        $this->entryAllocations = [];
        $this->updatedPaymentMethod();
    }

    public function save(): void
    {
        $this->authorizeMoney('payroll.salary.pay');
        if ($this->paymentMethod === 'check') {
            $this->authorizeMoney('money.check.outgoing.manage');
        }

        $rules = [
            'employeeId' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $this->pageCompanyId)],
            'paymentDate' => 'required|date_format:Y-m-d',
            'currencyCode' => 'required|string|size:3',
            'exchangeRate' => 'required|numeric|gt:0',
            'paymentMethod' => 'required|in:cash,bank,check',
            'notes' => 'nullable|string|max:2000',
            'entryAllocations' => 'array',
            'entryAllocations.*' => 'nullable|numeric|gte:0',
        ];

        if ($this->paymentMethod === 'check') {
            $rules['checkNumber'] = 'required|string|max:64';
            $rules['dueDate'] = 'required|date_format:Y-m-d';
            $rules['drawnMoneyAccountId'] = 'required|integer|exists:money_accounts,id';
        } else {
            $rules['moneyAccountId'] = 'required|integer|exists:money_accounts,id';
        }

        $this->validate($rules);

        $allocations = [];
        $totalAmount = BigDecimal::zero();

        foreach ($this->entryAllocations as $entryId => $allocatedAmt) {
            if (trim((string) $allocatedAmt) !== '' && BigDecimal::of($allocatedAmt)->isPositive()) {
                $allocations[] = [
                    'salary_entry_id' => (int) $entryId,
                    'allocated_amount' => (string) $allocatedAmt,
                ];
                $totalAmount = $totalAmount->plus(BigDecimal::of((string) $allocatedAmt));
            }
        }

        if (count($allocations) === 0 || $totalAmount->isZero()) {
            $this->addError('payment', __('payroll.allocation_required'));

            return;
        }

        $company = app(CompanyContext::class)->company();
        $user = auth()->user();

        try {
            if ($this->paymentMethod === 'check') {
                $check = app(IssueCheckAction::class)->execute($company, $user, [
                    'source_type' => 'salary_payment',
                    'employee_id' => $this->employeeId,
                    'amount' => (string) $totalAmount,
                    'currency_code' => $this->currencyCode,
                    'exchange_rate' => $this->exchangeRate,
                    'date' => $this->paymentDate,
                    'due_date' => $this->dueDate,
                    'check_number' => $this->checkNumber,
                    'bank_name' => $this->bankName ?: 'Bank',
                    'money_account_id' => $this->drawnMoneyAccountId,
                    'allocations' => $allocations,
                    'notes' => $this->notes,
                    'idempotency_key' => $this->requestKey,
                ]);

                session()->flash('success', __('payroll.payment_check_success'));
                $this->redirect(route('money.checks.show', $check->public_id), navigate: true);

                return;
            }

            app(PostSalaryPaymentAction::class)->execute($company, $user, [
                'employee_id' => $this->employeeId,
                'payment_date' => $this->paymentDate,
                'amount' => (string) $totalAmount,
                'currency_code' => $this->currencyCode,
                'exchange_rate' => $this->exchangeRate,
                'payment_method' => $this->paymentMethod,
                'money_account_id' => $this->moneyAccountId,
                'allocations' => $allocations,
                'notes' => $this->notes,
                'idempotency_key' => $this->requestKey,
            ]);

            $emp = Employee::withTrashed()->where('company_id', $this->pageCompanyId)->select(['id', 'public_id'])->findOrFail($this->employeeId);
            session()->flash('success', __('payroll.payment_success'));
            $this->redirect(route('employees.show', $emp->public_id), navigate: true);
        } catch (\InvalidArgumentException|MathException|ModelNotFoundException $e) {
            $this->addError('payment', __('money.invalid_request'));
        }
    }

    public function render(): View
    {
        $this->authorizeMoney('payroll.salary.pay');

        $companyId = $this->pageCompanyId;

        $employees = Employee::withTrashed()
            ->where('company_id', $companyId)
            ->when(! $this->canMoney('payroll.salary.view'), fn ($q) => $q->select(app(PayrollReadService::class)->identityColumns()))
            ->where(function ($q) use ($companyId) {
                $q->where(function ($activeQ) {
                    $activeQ->where('active', true)
                        ->whereNull('deleted_at');
                })->orWhere(function ($retiredQ) use ($companyId) {
                    $retiredQ->where(function ($sub) {
                        $sub->where('active', false)
                            ->orWhereNotNull('deleted_at');
                    })->whereHas('salaryEntries', function ($entryQ) use ($companyId) {
                        $entryQ->where('company_id', $companyId)
                            ->where('status', 'posted')
                            ->whereRaw('(salary_entries.net_payable - (SELECT COALESCE(SUM(spa.allocated_amount), 0) FROM salary_payment_allocations spa WHERE spa.salary_entry_id = salary_entries.id AND spa.company_id = '.(int) $companyId.' AND spa.status = "active")) > 0');
                    });
                });
            })
            ->orderBy('name')
            ->get();

        $currencies = CompanyCurrency::where('company_id', $this->pageCompanyId)
            ->where('enabled', true)
            ->get();

        $canReadSalary = $this->canMoney('payroll.salary.view');
        if (! $canReadSalary) {
            $this->entryAllocations = [];
        }
        $unpaidEntries = [];
        if ($this->employeeId !== null && $this->canMoney('payroll.salary.view')) {
            $emp = Employee::withTrashed()->where('company_id', $this->pageCompanyId)->find($this->employeeId);
            if ($emp !== null) {
                $positions = app(PayrollReadService::class)->salaryPositions($emp, auth()->user());
                $unpaidEntries = array_filter($positions, fn ($p) => $p['is_unpaid'] && $p['currency_code'] === $this->currencyCode);
            }
        }

        $accounts = collect();
        if ($this->paymentMethod === 'check') {
            $accounts = app(Phase7SettlementAccounts::class)->query($this->pageCompanyId, 'bank')->where('currency_code', $this->currencyCode)
                ->get();
        } else {
            $accounts = app(Phase7SettlementAccounts::class)->query($this->pageCompanyId, $this->paymentMethod)->where('currency_code', $this->currencyCode)
                ->get();
        }

        $totalAllocated = BigDecimal::zero();
        foreach ($this->entryAllocations as $amt) {
            if (trim((string) $amt) !== '' && is_numeric($amt)) {
                $totalAllocated = $totalAllocated->plus(BigDecimal::of($amt));
            }
        }

        return view('livewire.pages.payroll.salary-payment-form', [
            'employees' => $employees,
            'currencies' => $currencies,
            'accounts' => $accounts,
            'unpaidEntries' => $unpaidEntries,
            'totalAllocated' => (string) $totalAllocated,
        ]);
    }
}
