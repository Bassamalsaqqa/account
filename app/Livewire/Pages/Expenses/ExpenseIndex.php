<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Expenses;

use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\ExpenseCategory;
use App\Services\Expenses\ExpenseReadService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class ExpenseIndex extends Component
{
    use AuthorizesMoneyPages, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'cat')]
    public ?int $categoryId = null;

    #[Url(as: 'class')]
    public string $classification = '';

    #[Url(as: 'method')]
    public string $paymentMethod = '';

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'from')]
    public string $fromDate = '';

    #[Url(as: 'to')]
    public string $toDate = '';

    public function updated(): void
    {
        $this->authorizeMoney('money.expense.view');
        $this->resetPage();
    }

    public function render(): View
    {
        $this->authorizeMoney('money.expense.view');

        $readService = app(ExpenseReadService::class);
        $expenses = $readService->paginate($this->pageCompanyId, auth()->user(), [
            'search' => $this->search,
            'category_id' => $this->categoryId,
            'classification' => $this->classification,
            'payment_method' => $this->paymentMethod,
            'status' => $this->status,
            'from_date' => $this->fromDate,
            'to_date' => $this->toDate,
        ]);

        $categories = ExpenseCategory::where('company_id', $this->pageCompanyId)
            ->where('active', true)
            ->orderBy('name_ar')
            ->get();

        return view('livewire.pages.expenses.expense-index', [
            'expenses' => $expenses,
            'categories' => $categories,
            'canManage' => $this->canMoney('money.expense.manage'),
            'canCost' => $this->canMoney('purchasing.cost.view'),
        ]);
    }
}
