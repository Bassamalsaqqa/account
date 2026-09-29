<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateCompanyCurrenciesAction
{
    public function __construct(
        protected AuditService $audit
    ) {}

    /**
     * @param  array<string, bool>  $currenciesEnabled
     */
    public function execute(Company $company, string $baseCurrencyCode, array $currenciesEnabled, User $actor): Company
    {
        $baseCurrencyCode = strtoupper(trim($baseCurrencyCode));
        if (! in_array($baseCurrencyCode, ['ILS', 'USD', 'JOD'], true)) {
            throw new InvalidArgumentException("Base currency must be one of ILS, USD, JOD. '{$baseCurrencyCode}' given.");
        }

        // Base currency is unconditionally enabled
        $currenciesEnabled[$baseCurrencyCode] = true;

        return DB::transaction(function () use ($company, $baseCurrencyCode, $currenciesEnabled, $actor) {
            $before = [
                'base_currency_code' => $company->base_currency_code,
                'currencies' => $company->companyCurrencies->pluck('enabled', 'currency_code')->toArray(),
            ];

            $company->update(['base_currency_code' => $baseCurrencyCode]);

            // Clear all base currency flags first to maintain single-base invariant
            CompanyCurrency::where('company_id', $company->id)->update(['is_base' => false]);

            $order = 1;
            foreach (['ILS', 'USD', 'JOD'] as $code) {
                $isBase = ($code === $baseCurrencyCode);
                $isEnabled = $isBase ? true : (bool) ($currenciesEnabled[$code] ?? false);

                CompanyCurrency::updateOrCreate(
                    ['company_id' => $company->id, 'currency_code' => $code],
                    [
                        'enabled' => $isEnabled,
                        'is_base' => $isBase,
                        'display_order' => $isBase ? 1 : ++$order,
                    ]
                );
            }

            $this->audit->log(
                companyId: $company->id,
                eventKey: 'company.currencies_updated',
                summary: "Currency settings updated by {$actor->name}",
                actorUserId: $actor->id,
                subject: $company,
                before: $before,
                after: [
                    'base_currency_code' => $baseCurrencyCode,
                    'currencies' => $currenciesEnabled,
                ]
            );

            return $company;
        });
    }
}
