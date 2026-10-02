<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales\Settings;

use App\Actions\Sales\CreateMoneyAccountAction;
use App\Models\MoneyAccount;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class MoneyAccountSettings extends Component
{
    public bool $showFormModal = false;

    public ?int $editingAccountId = null;

    public string $account_type = MoneyAccount::TYPE_CASH;

    public string $name_ar = '';

    public ?string $name_en = null;

    public string $currency_code = 'ILS';

    public ?string $bank_name = null;

    public ?string $account_number = null;

    public ?string $iban = null;

    public int $sort_order = 0;

    public bool $is_active = true;

    public function mount(CompanyContext $context): void
    {
        $user = auth()->user();
        if (! $user->hasRole(['Owner', 'Administrator'])) {
            abort(403, 'Unauthorized.');
        }

        $company = $context->company();
        $this->currency_code = $company->base_currency_code;
    }

    public function newAccount(): void
    {
        $company = app(CompanyContext::class)->company();

        $this->editingAccountId = null;
        $this->account_type = MoneyAccount::TYPE_CASH;
        $this->name_ar = '';
        $this->name_en = null;
        $this->currency_code = $company->base_currency_code;
        $this->bank_name = null;
        $this->account_number = null;
        $this->iban = null;
        $this->sort_order = 0;
        $this->is_active = true;

        $this->showFormModal = true;
    }

    public function editAccount(int $id): void
    {
        $company = app(CompanyContext::class)->company();
        $acc = MoneyAccount::where('company_id', $company->id)->findOrFail($id);

        $this->editingAccountId = $acc->id;
        $this->account_type = $acc->account_type;
        $this->name_ar = $acc->name_ar;
        $this->name_en = $acc->name_en;
        $this->currency_code = $acc->currency_code;
        $this->bank_name = $acc->bank_name;
        $this->account_number = $acc->account_number;
        $this->iban = $acc->iban;
        $this->sort_order = (int) $acc->sort_order;
        $this->is_active = (bool) $acc->is_active;

        $this->showFormModal = true;
    }

    public function save(CreateMoneyAccountAction $createAction): void
    {
        $company = app(CompanyContext::class)->company();
        $user = auth()->user();

        $enabledCurrencies = $company->currencies()->where('enabled', true)->pluck('currency_code')->all();

        $this->validate([
            'account_type' => ['required', 'string', 'in:cash,bank'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'currency_code' => ['required', 'string', Rule::in($enabledCurrencies)],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'iban' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        if ($this->editingAccountId !== null) {
            $acc = MoneyAccount::where('company_id', $company->id)->findOrFail($this->editingAccountId);
            $acc->update([
                'name_ar' => trim($this->name_ar),
                'name_en' => $this->name_en ? trim($this->name_en) : null,
                'bank_name' => $this->bank_name,
                'account_number' => $this->account_number,
                'iban' => $this->iban,
                'sort_order' => $this->sort_order,
                'is_active' => $this->is_active,
            ]);
            session()->flash('success', __('sales.updated_successfully'));
        } else {
            $createAction->execute($company, $user, [
                'account_type' => $this->account_type,
                'name_ar' => trim($this->name_ar),
                'name_en' => $this->name_en ? trim($this->name_en) : null,
                'currency_code' => $this->currency_code,
                'bank_name' => $this->bank_name,
                'account_number' => $this->account_number,
                'iban' => $this->iban,
                'sort_order' => $this->sort_order,
            ]);
            session()->flash('success', __('sales.created_successfully'));
        }

        $this->showFormModal = false;
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();
        $accounts = MoneyAccount::with('ledgerAccount')
            ->where('company_id', $company->id)
            ->orderBy('sort_order')
            ->get();

        $currencies = $company->currencies()->where('enabled', true)->get();

        return view('livewire.pages.sales.settings.money-account-settings', [
            'accounts' => $accounts,
            'currencies' => $currencies,
        ]);
    }
}
