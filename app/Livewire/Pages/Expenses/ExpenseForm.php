<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Expenses;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Money\IssueCheckAction;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\Check;
use App\Models\CompanyCurrency;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Vendor;
use App\Services\Phase7\Phase7SettlementAccounts;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\Exception\MathException;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class ExpenseForm extends Component
{
    use AuthorizesMoneyPages, WithFileUploads;

    #[Locked]
    public string $requestKey;

    public ?int $categoryId = null;

    public string $classification = 'operating';

    public string $description = '';

    public string $expenseDate = '';

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

    // Optional Payee / Vendor
    public ?int $vendorId = null;

    public string $payeeName = '';

    public string $notes = '';

    public mixed $attachment = null;

    public function mount(): void
    {
        $this->authorizeMoney('money.expense.manage');

        $company = app(CompanyContext::class)->company();
        $this->currencyCode = $company->base_currency_code;
        $this->expenseDate = Carbon::now($company->timezone)->toDateString();
        $this->dueDate = Carbon::now($company->timezone)->toDateString();
        $this->requestKey = (string) Str::uuid();

        // Default to first active category
        $firstCat = ExpenseCategory::where('company_id', $this->pageCompanyId)
            ->where('active', true)
            ->orderBy('id')
            ->first();
        if ($firstCat !== null) {
            $this->categoryId = $firstCat->id;
        }

        // Default to first cash account
        $firstCash = app(Phase7SettlementAccounts::class)->query($this->pageCompanyId, 'cash')->where('currency_code', $this->currencyCode)
            ->first();
        if ($firstCash !== null) {
            $this->moneyAccountId = $firstCash->id;
        }
    }

    public function updatedPaymentMethod(): void
    {
        if ($this->paymentMethod === 'check') {
            $this->authorizeMoney('money.check.outgoing.manage');
            // default drawn bank
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
        $this->authorizeMoney('money.expense.manage');
        if ($this->paymentMethod === 'check') {
            $this->authorizeMoney('money.check.outgoing.manage');
        }

        $rules = [
            'categoryId' => 'required|integer|exists:expense_categories,id',
            'classification' => 'required|in:operating,landed_cost',
            'description' => 'required|string|max:500',
            'expenseDate' => 'required|date_format:Y-m-d',
            'amount' => 'required|numeric|gt:0',
            'currencyCode' => 'required|string|size:3',
            'exchangeRate' => 'required|numeric|gt:0',
            'paymentMethod' => 'required|in:cash,bank,check',
            'payeeName' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ];

        if ($this->paymentMethod === 'check') {
            $rules['checkNumber'] = 'required|string|max:64';
            $rules['dueDate'] = 'required|date_format:Y-m-d';
            $rules['drawnMoneyAccountId'] = 'required|integer|exists:money_accounts,id';
        } else {
            $rules['moneyAccountId'] = 'required|integer|exists:money_accounts,id';
        }

        $this->validate($rules);
        if ($this->classification === 'landed_cost') {
            $this->authorizeMoney('purchasing.cost.view');
            $this->authorizeMoney('purchasing.landed_cost.manage');
        }

        $attachmentPath = null;
        $attachmentName = null;
        $attachmentMime = null;
        $attachmentSize = null;

        $attachmentOwned = false;
        $company = app(CompanyContext::class)->company();
        $user = auth()->user();

        try {
            if ($this->attachment !== null) {
                $attachmentName = $this->attachment->getClientOriginalName();
                $attachmentMime = $this->attachment->getMimeType();
                $attachmentSize = $this->attachment->getSize();
                $attachmentPath = "expenses/{$this->pageCompanyId}/".Str::uuid().'.'.$this->attachment->extension();
                $this->attachment->storeAs("expenses/{$this->pageCompanyId}", basename($attachmentPath), 'local');
            }

            $attachmentMetadata = [
                'attachment_path' => $attachmentPath,
                'attachment_name' => $attachmentName,
                'attachment_mime' => $attachmentMime,
                'attachment_size' => $attachmentSize,
            ];

            if ($this->paymentMethod === 'check') {
                // Check request identity includes attachment metadata. Preserve the
                // original attachment on retries; the action still validates every
                // financial intent field and the existing canonical Check history.
                $existingCheck = Check::where('company_id', $company->id)
                    ->where('idempotency_key', $this->requestKey)->first();
                if ($existingCheck !== null) {
                    $existingExpense = Expense::where('company_id', $company->id)
                        ->where('check_id', $existingCheck->id)->sole();
                    $attachmentMetadata = $existingExpense->only(array_keys($attachmentMetadata));
                }
                $check = app(IssueCheckAction::class)->execute($company, $user, [
                    'source_type' => 'expense',
                    'category_id' => $this->categoryId,
                    'amount' => $this->amount,
                    'currency_code' => $this->currencyCode,
                    'exchange_rate' => $this->exchangeRate,
                    'date' => $this->expenseDate,
                    'due_date' => $this->dueDate,
                    'check_number' => $this->checkNumber,
                    'bank_name' => $this->bankName ?: 'Bank',
                    'money_account_id' => $this->drawnMoneyAccountId,
                    'vendor_id' => $this->vendorId,
                    'payee_name' => $this->payeeName,
                    'classification' => $this->classification,
                    'description' => $this->description,
                    'notes' => $this->notes,
                    ...$attachmentMetadata,
                    'idempotency_key' => $this->requestKey,
                ]);

                if ((int) $check->company_id !== (int) $company->id) {
                    throw new \LogicException('Expense Check Company mismatch.');
                }
                $expense = Expense::where('company_id', $company->id)->where('check_id', $check->id)->sole();
                $attachmentOwned = $attachmentPath !== null && $expense->attachment_path === $attachmentPath;

                session()->flash('success', __('expenses.created_successfully'));
                $this->redirect(route('money.checks.show', $check->public_id), navigate: true);

                return;
            }

            $expense = app(PostExpenseAction::class)->execute($company, $user, [
                'category_id' => $this->categoryId,
                'amount' => $this->amount,
                'currency_code' => $this->currencyCode,
                'exchange_rate' => $this->exchangeRate,
                'expense_date' => $this->expenseDate,
                'payment_method' => $this->paymentMethod,
                'money_account_id' => $this->moneyAccountId,
                'vendor_id' => $this->vendorId,
                'payee_name' => $this->payeeName,
                'classification' => $this->classification,
                'description' => $this->description,
                'notes' => $this->notes,
                'attachment_path' => $attachmentPath,
                'attachment_name' => $attachmentName,
                'attachment_mime' => $attachmentMime,
                'attachment_size' => $attachmentSize,
                'idempotency_key' => $this->requestKey,
            ]);

            if ((int) $expense->company_id !== (int) $company->id) {
                throw new \LogicException('Expense Company mismatch.');
            }
            $attachmentOwned = $attachmentPath !== null && $expense->attachment_path === $attachmentPath;

            session()->flash('success', __('expenses.created_successfully'));
            $this->redirect(route('expenses.show', $expense->public_id), navigate: true);
        } catch (\InvalidArgumentException|MathException|ModelNotFoundException $e) {
            $this->addError('payment', __('money.invalid_request'));
        } finally {
            // Delete only this attempt's upload, never a previously owned file.
            // Unexpected exceptions propagate after this ownership cleanup.
            if ($attachmentPath !== null && ! $attachmentOwned) {
                Storage::disk('local')->delete($attachmentPath);
            }
        }
    }

    public function render(): View
    {
        $this->authorizeMoney('money.expense.manage');

        $categories = ExpenseCategory::where('company_id', $this->pageCompanyId)
            ->where('active', true)
            ->orderBy('name_ar')
            ->get();

        $currencies = CompanyCurrency::where('company_id', $this->pageCompanyId)
            ->where('enabled', true)
            ->get();

        $vendors = Vendor::where('company_id', $this->pageCompanyId)
            ->where('status', 'active')
            ->orderBy('name_ar')
            ->get();

        $accounts = collect();
        if ($this->paymentMethod === 'check') {
            $accounts = app(Phase7SettlementAccounts::class)->query($this->pageCompanyId, 'bank')->where('currency_code', $this->currencyCode)
                ->get();
        } else {
            $accounts = app(Phase7SettlementAccounts::class)->query($this->pageCompanyId, $this->paymentMethod)->where('currency_code', $this->currencyCode)
                ->get();
        }

        return view('livewire.pages.expenses.expense-form', [
            'categories' => $categories,
            'currencies' => $currencies,
            'vendors' => $vendors,
            'accounts' => $accounts,
            'canLandedCost' => $this->canMoney('purchasing.cost.view') && $this->canMoney('purchasing.landed_cost.manage'),
        ]);
    }
}
