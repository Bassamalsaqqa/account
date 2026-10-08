<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Expenses;

use App\Actions\Expenses\ReverseExpenseAction;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\Expense;
use App\Services\Expenses\ExpenseReadService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class ExpenseDetail extends Component
{
    use AuthorizesMoneyPages;

    #[Locked]
    public string $publicId;

    public string $reversalReason = '';

    public string $reversalDate = '';

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
        $this->authorizeMoney('money.expense.view');

        $expense = Expense::where('company_id', $this->pageCompanyId)
            ->where('public_id', $this->publicId)
            ->firstOrFail();

        $company = app(CompanyContext::class)->company();
        $this->reversalDate = Carbon::now($company->timezone)->toDateString();
    }

    public function reverse(): void
    {
        $this->authorizeMoney('money.expense.reverse');

        $expense = Expense::where('company_id', $this->pageCompanyId)
            ->where('public_id', $this->publicId)
            ->firstOrFail();

        if ($expense->classification === Expense::CLASSIFICATION_LANDED_COST) {
            $this->authorizeMoney('purchasing.cost.view');
            $this->authorizeMoney('purchasing.landed_cost.manage');
        }

        if ($expense->payment_method === Expense::METHOD_CHECK) {
            $this->addError('reversal', __('expenses.cannot_reverse_check'));

            return;
        }

        $this->validate([
            'reversalReason' => 'required|string|max:500',
            'reversalDate' => 'required|date_format:Y-m-d',
        ]);

        try {
            app(ReverseExpenseAction::class)->execute(
                $expense,
                auth()->user(),
                $this->reversalReason,
                $this->reversalDate
            );

            session()->flash('success', __('expenses.reversed_successfully'));
        } catch (\Exception $e) {
            $this->addError('reversal', __('money.invalid_request'));
        }
    }

    public function render(): View
    {
        $this->authorizeMoney('money.expense.view');

        $expense = Expense::where('company_id', $this->pageCompanyId)
            ->where('public_id', $this->publicId)
            ->firstOrFail();

        $detail = app(ExpenseReadService::class)->detail($expense, auth()->user());

        $canReverse = $this->canMoney('money.expense.reverse')
            && $expense->status === Expense::STATUS_POSTED
            && $expense->payment_method !== Expense::METHOD_CHECK
            && ! $detail['is_capitalized'];

        if ($expense->classification === Expense::CLASSIFICATION_LANDED_COST) {
            $canReverse = $canReverse
                && $this->canMoney('purchasing.cost.view')
                && $this->canMoney('purchasing.landed_cost.manage');
        }

        return view('livewire.pages.expenses.expense-detail', [
            'expense' => $expense,
            'detail' => $detail,
            'canReverse' => $canReverse,
            'baseCurrencyCode' => app(CompanyContext::class)->company()->base_currency_code,
            'canViewPurchase' => $this->canMoney('purchasing.purchase.view'),
            'canViewVendor' => $this->canMoney('vendors.view'),
        ]);
    }
}
