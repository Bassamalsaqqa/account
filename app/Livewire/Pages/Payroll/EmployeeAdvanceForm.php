<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Payroll;

use App\Actions\Money\IssueCheckAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\CompanyCurrency;
use App\Models\Employee;
use App\Services\Payroll\PayrollReadService;
use App\Services\Phase7\Phase7SettlementAccounts;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\Exception\MathException;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class EmployeeAdvanceForm extends Component
{
    use AuthorizesMoneyPages;

    #[Locked]
    public string $requestKey;

    #[Url(as: 'employee')]
    public ?string $employeePublicId = null;

    public ?int $employeeId = null;

    public string $advanceDate = '';

    public string $amount = '';

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

    public function mount(?string $employee = null): void
    {
        $this->authorizeMoney('payroll.advance.manage');

        if ($employee !== null) {
            $this->employeePublicId = $employee;
        }

        $company = app(CompanyContext::class)->company();
        $this->currencyCode = $company->base_currency_code;
        $this->advanceDate = Carbon::now($company->timezone)->toDateString();
        $this->dueDate = Carbon::now($company->timezone)->toDateString();
        $this->requestKey = (string) Str::uuid();

        if ($this->employeePublicId !== null) {
            $emp = Employee::where('company_id', $this->pageCompanyId)->when(! $this->canMoney('payroll.salary.view'), fn ($q) => $q->select(app(PayrollReadService::class)->identityColumns()))
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

        $this->updatedPaymentMethod();
    }

    public function save(): void
    {
        $this->authorizeMoney('payroll.advance.manage');
        if ($this->paymentMethod === 'check') {
            $this->authorizeMoney('money.check.outgoing.manage');
        }

        $rules = [
            'employeeId' => 'required|integer|exists:employees,id',
            'advanceDate' => 'required|date_format:Y-m-d',
            'amount' => 'required|numeric|gt:0',
            'currencyCode' => 'required|string|size:3',
            'exchangeRate' => 'required|numeric|gt:0',
            'paymentMethod' => 'required|in:cash,bank,check',
            'notes' => 'nullable|string|max:2000',
        ];

        if ($this->paymentMethod === 'check') {
            $rules['checkNumber'] = 'required|string|max:64';
            $rules['dueDate'] = 'required|date_format:Y-m-d';
            $rules['drawnMoneyAccountId'] = 'required|integer|exists:money_accounts,id';
        } else {
            $rules['moneyAccountId'] = 'required|integer|exists:money_accounts,id';
        }

        $this->validate($rules);

        $company = app(CompanyContext::class)->company();
        $user = auth()->user();

        try {
            if ($this->paymentMethod === 'check') {
                $check = app(IssueCheckAction::class)->execute($company, $user, [
                    'source_type' => 'employee_advance',
                    'employee_id' => $this->employeeId,
                    'amount' => $this->amount,
                    'currency_code' => $this->currencyCode,
                    'exchange_rate' => $this->exchangeRate,
                    'date' => $this->advanceDate,
                    'due_date' => $this->dueDate,
                    'check_number' => $this->checkNumber,
                    'bank_name' => $this->bankName ?: 'Bank',
                    'money_account_id' => $this->drawnMoneyAccountId,
                    'notes' => $this->notes,
                    'idempotency_key' => $this->requestKey,
                ]);

                session()->flash('success', __('payroll.advance_check_success'));
                $this->redirect(route('money.checks.show', $check->public_id), navigate: true);

                return;
            }

            $advance = app(PostEmployeeAdvanceAction::class)->execute($company, $user, [
                'employee_id' => $this->employeeId,
                'amount' => $this->amount,
                'currency_code' => $this->currencyCode,
                'exchange_rate' => $this->exchangeRate,
                'advance_date' => $this->advanceDate,
                'payment_method' => $this->paymentMethod,
                'money_account_id' => $this->moneyAccountId,
                'notes' => $this->notes,
                'idempotency_key' => $this->requestKey,
            ]);

            $emp = Employee::where('company_id', $this->pageCompanyId)->select(['id', 'public_id'])->findOrFail($this->employeeId);
            session()->flash('success', __('payroll.advance_success'));
            $this->redirect(route('employees.show', $emp->public_id), navigate: true);
        } catch (\InvalidArgumentException|MathException|ModelNotFoundException $e) {
            $this->addError('payment', __('money.invalid_request'));
        }
    }

    public function render(): View
    {
        $this->authorizeMoney('payroll.advance.manage');

        $employees = Employee::where('company_id', $this->pageCompanyId)->when(! $this->canMoney('payroll.salary.view'), fn ($q) => $q->select(app(PayrollReadService::class)->identityColumns()))
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $currencies = CompanyCurrency::where('company_id', $this->pageCompanyId)
            ->where('enabled', true)
            ->get();

        $accounts = collect();
        if ($this->paymentMethod === 'check') {
            $accounts = app(Phase7SettlementAccounts::class)->query($this->pageCompanyId, 'bank')->where('currency_code', $this->currencyCode)
                ->get();
        } else {
            $accounts = app(Phase7SettlementAccounts::class)->query($this->pageCompanyId, $this->paymentMethod)->where('currency_code', $this->currencyCode)
                ->get();
        }

        return view('livewire.pages.payroll.employee-advance-form', [
            'employees' => $employees,
            'currencies' => $currencies,
            'accounts' => $accounts,
        ]);
    }
}
