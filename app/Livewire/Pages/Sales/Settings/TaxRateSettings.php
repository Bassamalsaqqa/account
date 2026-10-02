<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales\Settings;

use App\Actions\Sales\SaveTaxRateAction;
use App\Models\LedgerAccount;
use App\Models\TaxRate;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class TaxRateSettings extends Component
{
    #[Locked]
    public int $settingsCompanyId;

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
        $this->settingsCompanyId = (int) $context->companyId();
        $user = auth()->user();
        $this->authorizeSettings();

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
        $this->authorizeSettings();
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
        $this->authorizeSettings();
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
        $this->authorizeSettings();
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
            'name_ar' => ['required', 'string', 'max:128'],
            'name_en' => ['nullable', 'string', 'max:128'],
            'rate' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,6})?$/D'],
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

        try {
            app(SaveTaxRateAction::class)->execute($company, auth()->user(), $payload, $this->editingTaxId);
        } catch (\InvalidArgumentException $exception) {
            $this->addError('rate', __('sales.invalid_tax_configuration'));

            return;
        }
        session()->flash('success', __('sales.updated_successfully'));

        $this->showFormModal = false;
    }

    private function authorizeSettings(): void
    {
        try {
            DB::transaction(function (): void {
                app(SalesActorGuard::class)->lockAndAuthorize(
                    $this->settingsCompanyId, auth()->user(), 'settings.taxes.manage');
            });
        } catch (AuthorizationException $e) {
            abort(403);
        }
    }

    public function render(CompanyContext $context): View
    {
        $this->authorizeSettings();
        $company = $context->company();
        $taxRates = TaxRate::with('salesTaxAccount')
            ->where('company_id', $company->id)
            ->get();

        $accounts = LedgerAccount::where('company_id', $company->id)
            ->where('active', true)->where('is_control', false)->where('account_type', 'liability')->where('normal_balance', 'credit')
            ->where(fn ($query) => $query->where('system_key', 'tax_output')->orWhere('parent_id', LedgerAccount::where('company_id', $company->id)->where('system_key', 'tax_output')->value('id')))
            ->orderBy('code')
            ->get();

        return view('livewire.pages.sales.settings.tax-rate-settings', [
            'taxRates' => $taxRates,
            'accounts' => $accounts,
        ]);
    }
}
