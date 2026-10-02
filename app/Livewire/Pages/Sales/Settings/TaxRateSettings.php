<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales\Settings;

use App\Models\LedgerAccount;
use App\Models\TaxRate;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class TaxRateSettings extends Component
{
    public bool $showFormModal = false;

    public ?int $editingTaxId = null;

    public string $code = '';

    public string $name_ar = '';

    public ?string $name_en = null;

    public string $rate = '0.00';

    public string $calculation = 'exclusive';

    public ?int $sales_tax_account_id = null;

    public bool $active = true;

    public function mount(CompanyContext $context): void
    {
        $user = auth()->user();
        if (! $user->hasRole(['Owner', 'Administrator'])) {
            abort(403, 'Unauthorized.');
        }

        $company = $context->company();
        $defaultTaxOutput = LedgerAccount::where('company_id', $company->id)
            ->where('system_key', 'tax_output')
            ->first();

        if ($defaultTaxOutput !== null) {
            $this->sales_tax_account_id = $defaultTaxOutput->id;
        }
    }

    public function newTaxRate(): void
    {
        $this->editingTaxId = null;
        $this->code = '';
        $this->name_ar = '';
        $this->name_en = null;
        $this->rate = '16.00';
        $this->calculation = 'exclusive';
        $this->active = true;

        $company = app(CompanyContext::class)->company();
        $defaultTaxOutput = LedgerAccount::where('company_id', $company->id)
            ->where('system_key', 'tax_output')
            ->first();

        if ($defaultTaxOutput !== null) {
            $this->sales_tax_account_id = $defaultTaxOutput->id;
        }

        $this->showFormModal = true;
    }

    public function editTaxRate(int $id): void
    {
        $company = app(CompanyContext::class)->company();
        $tax = TaxRate::where('company_id', $company->id)->findOrFail($id);

        $this->editingTaxId = $tax->id;
        $this->code = $tax->code;
        $this->name_ar = $tax->name_ar;
        $this->name_en = $tax->name_en;
        $this->rate = (string) $tax->rate;
        $this->calculation = $tax->calculation;
        $this->sales_tax_account_id = $tax->sales_tax_account_id;
        $this->active = (bool) $tax->active;

        $this->showFormModal = true;
    }

    public function save(): void
    {
        $company = app(CompanyContext::class)->company();

        $this->validate([
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('tax_rates', 'code')
                    ->where('company_id', $company->id)
                    ->ignore($this->editingTaxId),
            ],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'calculation' => ['required', 'string', 'in:exclusive,inclusive'],
            'sales_tax_account_id' => [
                'required',
                'integer',
                Rule::exists('ledger_accounts', 'id')->where('company_id', $company->id),
            ],
            'active' => ['boolean'],
        ]);

        $payload = [
            'code' => strtoupper(trim($this->code)),
            'name_ar' => trim($this->name_ar),
            'name_en' => $this->name_en ? trim($this->name_en) : null,
            'rate' => $this->rate,
            'calculation' => $this->calculation,
            'sales_tax_account_id' => $this->sales_tax_account_id,
            'active' => $this->active,
        ];

        if ($this->editingTaxId !== null) {
            $tax = TaxRate::where('company_id', $company->id)->findOrFail($this->editingTaxId);
            $tax->update($payload);
            session()->flash('success', __('sales.updated_successfully'));
        } else {
            TaxRate::create(array_merge($payload, [
                'company_id' => $company->id,
            ]));
            session()->flash('success', __('sales.created_successfully'));
        }

        $this->showFormModal = false;
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();
        $taxRates = TaxRate::with('salesTaxAccount')
            ->where('company_id', $company->id)
            ->get();

        $accounts = LedgerAccount::where('company_id', $company->id)
            ->where('active', true)
            ->orderBy('code')
            ->get();

        return view('livewire.pages.sales.settings.tax-rate-settings', [
            'taxRates' => $taxRates,
            'accounts' => $accounts,
        ]);
    }
}
