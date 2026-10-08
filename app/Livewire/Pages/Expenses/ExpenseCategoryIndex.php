<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Expenses;

use App\Actions\Expenses\CreateExpenseCategoryAction;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ExpenseCategoryIndex extends Component
{
    use AuthorizesMoneyPages;

    public string $nameAr = '';

    public string $nameEn = '';

    public string $code = '';

    public ?int $ledgerAccountId = null;

    public bool $active = true;

    public function mount(): void
    {
        $this->authorizeMoney('money.expense.manage');

        $firstAccount = LedgerAccount::where('company_id', $this->pageCompanyId)
            ->where('account_type', 'expense')
            ->where('normal_balance', 'debit')->where('is_control', false)->where('active', true)
            ->where('parent_id', LedgerAccount::where('company_id', $this->pageCompanyId)->where('system_key', 'operating_expense_parent')->value('id'))
            ->first();

        if ($firstAccount !== null) {
            $this->ledgerAccountId = $firstAccount->id;
        }
    }

    public function createCategory(): void
    {
        $this->authorizeMoney('money.expense.manage');

        $this->validate([
            'nameAr' => 'required|string|max:128',
            'nameEn' => 'nullable|string|max:128',
            'code' => 'required|string|max:32',
            'ledgerAccountId' => 'required|integer|exists:ledger_accounts,id',
        ]);

        try {
            app(CreateExpenseCategoryAction::class)->execute(
                app(CompanyContext::class)->company(),
                auth()->user(),
                [
                    'name_ar' => $this->nameAr,
                    'name_en' => $this->nameEn,
                    'code' => $this->code,
                    'ledger_account_id' => $this->ledgerAccountId,
                    'active' => $this->active,
                ]
            );

            $this->nameAr = '';
            $this->nameEn = '';
            $this->code = '';

            session()->flash('success', __('expenses.created_successfully'));
        } catch (\InvalidArgumentException|ModelNotFoundException $e) {
            $this->addError('ledgerAccountId', __('money.invalid_request'));
        }
    }

    public function render(): View
    {
        $this->authorizeMoney('money.expense.manage');

        $categories = ExpenseCategory::where('company_id', $this->pageCompanyId)
            ->with('ledgerAccount')
            ->orderBy('id')
            ->get();

        $accounts = LedgerAccount::where('company_id', $this->pageCompanyId)
            ->where('account_type', 'expense')
            ->where('normal_balance', 'debit')->where('is_control', false)->where('active', true)
            ->where('parent_id', LedgerAccount::where('company_id', $this->pageCompanyId)->where('system_key', 'operating_expense_parent')->value('id'))
            ->orderBy('code')
            ->get();

        return view('livewire.pages.expenses.expense-category-index', [
            'categories' => $categories,
            'accounts' => $accounts,
        ]);
    }
}
