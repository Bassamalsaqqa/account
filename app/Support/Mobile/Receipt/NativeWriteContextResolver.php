<?php

declare(strict_types=1);

namespace App\Support\Mobile\Receipt;

use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\ExpenseCategory;
use App\Models\MoneyAccount;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use InvalidArgumentException;
use Throwable;

final class NativeWriteContextResolver
{
    private const string ULID_REGEX = '/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/';

    /**
     * Resolve approved active cash account with active ledger provenance.
     * Fails closed when unconfigured or invalid.
     */
    public function resolveCashAccount(
        Company $company,
        ?string $explicitPublicId = null,
        ?string $currencyCode = null
    ): MoneyAccount {
        $expectedCurrency = $currencyCode ?? (string) $company->base_currency_code;

        if ($explicitPublicId !== null && trim($explicitPublicId) !== '') {
            $trimmed = trim($explicitPublicId);
            if (preg_match(self::ULID_REGEX, $trimmed) !== 1) {
                throw new InvalidArgumentException("Explicit cash account ID [{$trimmed}] must be a valid ULID.");
            }

            /** @var MoneyAccount|null $cashAccount */
            $cashAccount = MoneyAccount::where('company_id', $company->id)
                ->where('public_id', $trimmed)
                ->where('account_type', MoneyAccount::TYPE_CASH)
                ->where('currency_code', $expectedCurrency)
                ->where('is_active', true)
                ->with('ledgerAccount')
                ->lockForUpdate()
                ->first();

            if ($cashAccount === null) {
                throw new InvalidArgumentException("Specified cash account [{$trimmed}] does not exist, is inactive, or does not match currency [{$expectedCurrency}].");
            }
        } else {
            /** @var CompanyMobileSetting|null $setting */
            $setting = CompanyMobileSetting::where('company_id', $company->id)->lockForUpdate()->first();
            if ($setting === null || $setting->approved_cash_account_id === null) {
                throw new InvalidArgumentException('No approved cash account configured for this company.');
            }

            /** @var MoneyAccount|null $cashAccount */
            $cashAccount = MoneyAccount::where('company_id', $company->id)
                ->where('id', $setting->approved_cash_account_id)
                ->where('account_type', MoneyAccount::TYPE_CASH)
                ->where('currency_code', $expectedCurrency)
                ->where('is_active', true)
                ->with('ledgerAccount')
                ->lockForUpdate()
                ->first();

            if ($cashAccount === null) {
                throw new InvalidArgumentException('Configured approved cash account is inactive, invalid, or does not match base currency.');
            }
        }

        $ledger = $cashAccount->ledgerAccount;
        if ($ledger === null || (int) $ledger->company_id !== (int) $company->id || ! $ledger->active) {
            throw new InvalidArgumentException('Cash account lacks active ledger account provenance in this company.');
        }

        return $cashAccount;
    }

    /**
     * Resolve active non-transit warehouse. Fails closed when unconfigured.
     */
    public function resolveWarehouse(
        Company $company,
        ?string $explicitPublicId = null
    ): Warehouse {
        if ($explicitPublicId !== null && trim($explicitPublicId) !== '') {
            $trimmed = trim($explicitPublicId);
            if (preg_match(self::ULID_REGEX, $trimmed) !== 1) {
                throw new InvalidArgumentException("Warehouse ID [{$trimmed}] must be a valid ULID.");
            }

            /** @var Warehouse|null $warehouse */
            $warehouse = Warehouse::where('company_id', $company->id)
                ->where('public_id', $trimmed)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if ($warehouse === null) {
                throw new InvalidArgumentException("Specified warehouse [{$trimmed}] not found or inactive.");
            }
        } else {
            /** @var CompanyMobileSetting|null $setting */
            $setting = CompanyMobileSetting::where('company_id', $company->id)->lockForUpdate()->first();
            if ($setting === null || $setting->default_warehouse_id === null) {
                throw new InvalidArgumentException('No active warehouse configured for company.');
            }

            /** @var Warehouse|null $warehouse */
            $warehouse = Warehouse::where('company_id', $company->id)
                ->where('id', $setting->default_warehouse_id)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if ($warehouse === null) {
                throw new InvalidArgumentException('Configured default warehouse is not active or found.');
            }
        }

        if ($warehouse->is_transit) {
            throw new InvalidArgumentException('In-transit warehouses cannot be manually adjusted.');
        }

        return $warehouse;
    }

    /**
     * Resolve active canonical expense category by public ID.
     * Fails closed without falling back to first() or default.
     */
    public function resolveExpenseCategory(
        Company $company,
        mixed $categoryPublicId
    ): ExpenseCategory {
        if (! is_string($categoryPublicId) || trim($categoryPublicId) === '') {
            throw new InvalidArgumentException('Valid active expense category ID is required.');
        }

        $trimmed = trim($categoryPublicId);
        if (preg_match(self::ULID_REGEX, $trimmed) !== 1) {
            throw new InvalidArgumentException("Expense category ID [{$trimmed}] must be a valid ULID.");
        }

        /** @var ExpenseCategory|null $category */
        $category = ExpenseCategory::where('company_id', $company->id)
            ->where('public_id', $trimmed)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if ($category === null) {
            throw new InvalidArgumentException("Expense category [{$trimmed}] was not found or is inactive.");
        }

        return $category;
    }

    /**
     * Validate exact decimal string without PHP float or silent rounding.
     */
    public static function validateDecimalString(
        mixed $value,
        string $fieldName,
        int $maxScale = 6,
        bool $strictlyPositive = true,
        bool $allowZero = false
    ): string {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("Field [{$fieldName}] must be an exact decimal string.");
        }

        $str = trim((string) $value);
        if ($str === '' || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $str) !== 1) {
            throw new InvalidArgumentException("Field [{$fieldName}] has an invalid decimal format.");
        }

        try {
            $bd = BigDecimal::of($str);
        } catch (Throwable) {
            throw new InvalidArgumentException("Field [{$fieldName}] cannot be parsed as a valid decimal.");
        }

        if ($strictlyPositive && $bd->isNegativeOrZero()) {
            throw new InvalidArgumentException("Field [{$fieldName}] must be strictly positive.");
        }

        if (! $strictlyPositive && ! $allowZero && $bd->isZero()) {
            throw new InvalidArgumentException("Field [{$fieldName}] cannot be zero.");
        }

        if ($bd->getScale() > $maxScale) {
            throw new InvalidArgumentException("Field [{$fieldName}] exceeds maximum decimal scale of [{$maxScale}].");
        }

        return (string) $bd;
    }

    /**
     * Validate calendar date YYYY-MM-DD.
     */
    public static function validateDate(mixed $date, string $fieldName): string
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($date)) !== 1) {
            throw new InvalidArgumentException("Field [{$fieldName}] must be a valid date in YYYY-MM-DD format.");
        }

        $trimmed = trim($date);
        try {
            Carbon::createFromFormat('!Y-m-d', $trimmed);
        } catch (Throwable) {
            throw new InvalidArgumentException("Field [{$fieldName}] is not a valid calendar date.");
        }

        return $trimmed;
    }
}
