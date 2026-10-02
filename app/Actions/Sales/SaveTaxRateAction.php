<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Domain\Sales\Calculators\TaxPercentage;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class SaveTaxRateAction
{
    /** @param array<string, mixed> $data */
    public function execute(Company $company, User $actor, array $data, ?int $id = null): TaxRate
    {
        return DB::transaction(function () use ($company, $actor, $data, $id): TaxRate {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'settings.taxes.manage');
            $rate = TaxPercentage::parse($data['rate'] ?? null);
            $values = Validator::make($data, [
                'code' => ['required', 'string', 'max:32', Rule::unique('tax_rates')->where('company_id', $company->id)->ignore($id)],
                'name_ar' => ['required', 'string', 'max:128'],
                'name_en' => ['nullable', 'string', 'max:128'],
                'calculation' => ['required', Rule::in(['exclusive', 'inclusive'])],
                'sales_tax_account_id' => ['required', 'integer'],
                'active' => ['required', 'boolean'],
            ])->validate();
            $account = LedgerAccount::where('company_id', $company->id)->lockForUpdate()->find($values['sales_tax_account_id']);
            // A configured output-tax account must belong to the output-tax liability hierarchy.
            $output = LedgerAccount::where('company_id', $company->id)->where('system_key', 'tax_output')->firstOrFail();
            if ($account === null || ! $account->active || $account->is_control
                || $account->account_type !== LedgerAccount::TYPE_LIABILITY
                || $account->normal_balance !== LedgerAccount::BALANCE_CREDIT
                || ((int) $account->id !== (int) $output->id && (int) $account->parent_id !== (int) $output->id)) {
                throw new InvalidArgumentException(__('sales.invalid_sales_tax_account'));
            }
            $tax = $id === null ? new TaxRate : TaxRate::where('company_id', $company->id)->lockForUpdate()->findOrFail($id);
            $before = $tax->exists ? $tax->only(array_keys($values)) : null;
            $tax->fill($values + ['company_id' => $company->id]);
            $tax->rate = (string) $rate;
            $tax->code = strtoupper(trim($values['code']));
            $tax->save();
            app(AuditService::class)->log((int) $company->id, 'settings.tax.saved', 'Tax configuration saved', $actor->id, $tax, $before, $tax->only(array_merge(array_keys($values), ['rate'])));

            return $tax;
        });
    }
}
