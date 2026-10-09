<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;

final readonly class ReportFilters
{
    public const int MAX_PAGE_WINDOW = 50_000;

    public const array ALLOWED_KEYS = [
        'company_id', 'period', 'preset', 'from', 'to', 'start_date', 'end_date',
        'customer_id', 'vendor_id', 'product_id', 'category_id', 'warehouse_id',
        'employee_id', 'money_account_id', 'currency_code', 'status', 'grouping',
        'sort', 'page', 'per_page',
    ];

    public function __construct(
        public ReportPeriod $period,
        public ?int $customerId = null,
        public ?int $vendorId = null,
        public ?int $productId = null,
        public ?int $categoryId = null,
        public ?int $warehouseId = null,
        public ?int $employeeId = null,
        public ?int $moneyAccountId = null,
        public ?string $currencyCode = null,
        public ?string $status = null,
        public ?string $grouping = null,
        public ?string $sort = null,
        public int $page = 1,
        public int $perPage = 50,
        public bool $isExplicitPeriod = true,
    ) {
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw new InvalidReportFilterException('Pagination requires a positive page and per_page between 1 and 100.');
        }
        self::assertSafePage($page, $perPage);
        foreach ([$customerId, $vendorId, $productId, $categoryId, $warehouseId, $employeeId, $moneyAccountId] as $id) {
            if ($id !== null && $id < 1) {
                throw new InvalidReportFilterException('Entity identities must be positive integers.');
            }
        }
    }

    public function validateForCompany(Company $company, ?ReportPeriod $defaultPeriod = null): self
    {
        $isExplicit = $this->isExplicitPeriod;
        if (! $this->isExplicitPeriod && $defaultPeriod !== null) {
            $period = $defaultPeriod;
        } else {
            $period = $this->period;
        }

        if ($period->timezone !== (string) $company->timezone) {
            $period = new ReportPeriod(
                $period->preset,
                $period->startDate,
                $period->endDate,
                (string) $company->timezone,
            );
        }

        $models = [
            'customer_id' => [Customer::class, $this->customerId],
            'vendor_id' => [Vendor::class, $this->vendorId],
            'product_id' => [Product::class, $this->productId],
            'warehouse_id' => [Warehouse::class, $this->warehouseId],
            'employee_id' => [Employee::class, $this->employeeId],
            'money_account_id' => [MoneyAccount::class, $this->moneyAccountId],
        ];
        foreach ($models as $field => [$modelClass, $id]) {
            if ($id !== null) {
                /** @var class-string<Model> $modelClass */
                if (! $modelClass::withoutGlobalScopes()->where('company_id', $company->id)->whereKey($id)->exists()) {
                    throw InvalidReportFilterException::foreignEntity($field, $id, (int) $company->id);
                }
            }
        }

        $currency = $this->currencyCode !== null ? strtoupper($this->currencyCode) : null;
        if ($currency !== null && ! CompanyCurrency::withoutGlobalScopes()->where('company_id', $company->id)->where('currency_code', $currency)->exists()) {
            throw new InvalidReportFilterException('Currency is not configured for this company.');
        }

        return new self(
            period: $period,
            customerId: $this->customerId,
            vendorId: $this->vendorId,
            productId: $this->productId,
            categoryId: $this->categoryId,
            warehouseId: $this->warehouseId,
            employeeId: $this->employeeId,
            moneyAccountId: $this->moneyAccountId,
            currencyCode: $currency,
            status: $this->status,
            grouping: $this->grouping,
            sort: $this->sort,
            page: $this->page,
            perPage: $this->perPage,
            isExplicitPeriod: $isExplicit,
        );
    }

    public function withPeriod(ReportPeriod $period, bool $isExplicit = true): self
    {
        return new self(
            period: $period,
            customerId: $this->customerId,
            vendorId: $this->vendorId,
            productId: $this->productId,
            categoryId: $this->categoryId,
            warehouseId: $this->warehouseId,
            employeeId: $this->employeeId,
            moneyAccountId: $this->moneyAccountId,
            currencyCode: $this->currencyCode,
            status: $this->status,
            grouping: $this->grouping,
            sort: $this->sort,
            page: $this->page,
            perPage: $this->perPage,
            isExplicitPeriod: $isExplicit,
        );
    }

    /**
     * Category identity is validated against the exact category model by each
     * report family; ProductCategory and ExpenseCategory are different domains.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(Company $company, array $input, ?ReportPeriod $defaultPeriod = null): self
    {
        foreach (array_keys($input) as $key) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                throw InvalidReportFilterException::unknownKey((string) $key);
            }
        }
        if (isset($input['company_id']) && self::positiveInteger($input['company_id'], 'company_id') !== (int) $company->id) {
            throw InvalidReportFilterException::foreignEntity('company_id', $input['company_id'], (int) $company->id);
        }

        $isExplicitPeriod = true;
        $periodInput = $input['period'] ?? null;
        if ($periodInput instanceof ReportPeriod) {
            $period = new ReportPeriod($periodInput->preset, $periodInput->startDate, $periodInput->endDate, (string) $company->timezone);
        } elseif (is_array($periodInput)) {
            foreach (array_keys($periodInput) as $key) {
                if (! in_array($key, ['preset', 'start_date', 'end_date', 'from', 'to', 'timezone'], true)) {
                    throw InvalidReportFilterException::unknownKey('period.'.(string) $key);
                }
            }
            $startDate = self::text($periodInput['start_date'] ?? $periodInput['from'] ?? null, 'period.start_date');
            $endDate = self::text($periodInput['end_date'] ?? $periodInput['to'] ?? null, 'period.end_date');
            $preset = self::text($periodInput['preset'] ?? null, 'period.preset');
            $tzMeta = self::text($periodInput['timezone'] ?? null, 'period.timezone');
            if ($tzMeta !== null) {
                try {
                    new \DateTimeZone($tzMeta);
                } catch (\Throwable) {
                    throw new InvalidReportFilterException('Invalid timezone in period metadata: '.(string) $tzMeta);
                }
            }

            if ($startDate === null && $endDate === null) {
                if ($preset === ReportPeriod::PRESET_CUSTOM) {
                    throw new InvalidReportFilterException('Custom period requires explicit start_date and end_date.');
                }
                if ($preset !== null) {
                    $period = ReportPeriod::fromPreset($preset, $company);
                } else {
                    $isExplicitPeriod = false;
                    $period = $defaultPeriod ?? ReportPeriod::fromPreset(ReportPeriod::PRESET_THIS_MONTH, $company);
                }
            } else {
                if ($startDate === null || $endDate === null) {
                    throw new InvalidReportFilterException('Period requires both start_date and end_date.');
                }
                $period = new ReportPeriod(
                    $preset ?? ReportPeriod::PRESET_CUSTOM,
                    $startDate,
                    $endDate,
                    (string) $company->timezone,
                );
            }
        } elseif ($periodInput !== null) {
            throw new InvalidReportFilterException('Period must be a ReportPeriod or date-range array.');
        } elseif (isset($input['from']) || isset($input['start_date']) || isset($input['to']) || isset($input['end_date'])) {
            $period = ReportPeriod::custom(
                self::text($input['from'] ?? $input['start_date'] ?? null, 'from') ?? '',
                self::text($input['to'] ?? $input['end_date'] ?? null, 'to') ?? '',
                $company,
            );
        } elseif (isset($input['preset'])) {
            $period = ReportPeriod::fromPreset(self::text($input['preset'], 'preset') ?? 'this_month', $company);
        } else {
            $isExplicitPeriod = false;
            $period = $defaultPeriod ?? ReportPeriod::fromPreset('this_month', $company);
        }

        $ids = [];
        $models = [
            'customer_id' => Customer::class, 'vendor_id' => Vendor::class,
            'product_id' => Product::class, 'warehouse_id' => Warehouse::class,
            'employee_id' => Employee::class, 'money_account_id' => MoneyAccount::class,
        ];
        foreach ([...array_keys($models), 'category_id'] as $key) {
            $ids[$key] = ! isset($input[$key]) || $input[$key] === '' ? null : self::positiveInteger($input[$key], $key);
            if ($ids[$key] !== null && isset($models[$key])) {
                /** @var class-string<Model> $model */
                $model = $models[$key];
                if (! $model::withoutGlobalScopes()->where('company_id', $company->id)->whereKey($ids[$key])->exists()) {
                    throw InvalidReportFilterException::foreignEntity($key, $ids[$key], (int) $company->id);
                }
            }
        }
        $currency = self::text($input['currency_code'] ?? null, 'currency_code');
        $currency = $currency === null ? null : strtoupper($currency);
        if ($currency !== null && ! CompanyCurrency::withoutGlobalScopes()->where('company_id', $company->id)->where('currency_code', $currency)->exists()) {
            throw new InvalidReportFilterException('Currency is not configured for this company.');
        }

        return new self(
            period: $period,
            customerId: $ids['customer_id'], vendorId: $ids['vendor_id'], productId: $ids['product_id'],
            categoryId: $ids['category_id'], warehouseId: $ids['warehouse_id'], employeeId: $ids['employee_id'],
            moneyAccountId: $ids['money_account_id'], currencyCode: $currency,
            status: self::text($input['status'] ?? null, 'status'),
            grouping: self::text($input['grouping'] ?? null, 'grouping'),
            sort: self::text($input['sort'] ?? null, 'sort'),
            page: self::positiveInteger($input['page'] ?? 1, 'page'),
            perPage: self::positiveInteger($input['per_page'] ?? 50, 'per_page'),
            isExplicitPeriod: $isExplicitPeriod,
        );
    }

    public static function assertSafePage(int $page, int $perPage): void
    {
        // Divide before multiplying: even PHP_INT_MAX cannot overflow this boundary.
        if ($page < 1 || $perPage < 1 || $perPage > 100 || $page > intdiv(self::MAX_PAGE_WINDOW, $perPage)) {
            throw new InvalidReportFilterException('Report page exceeds the safe 50,000-row paging window. Narrow the filters.');
        }
    }

    public static function positiveInteger(mixed $value, string $field): int
    {
        if ((! is_int($value) && (! is_string($value) || ! preg_match('/^[1-9]\d*$/D', $value)))
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidReportFilterException("Filter [$field] requires a positive integer identity.");
        }

        return (int) $value;
    }

    private static function text(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidReportFilterException("Filter [$field] requires text.");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'period' => $this->period->toArray(),
            'customer_id' => $this->customerId, 'vendor_id' => $this->vendorId, 'product_id' => $this->productId,
            'category_id' => $this->categoryId, 'warehouse_id' => $this->warehouseId, 'employee_id' => $this->employeeId,
            'money_account_id' => $this->moneyAccountId, 'currency_code' => $this->currencyCode,
            'status' => $this->status, 'grouping' => $this->grouping, 'sort' => $this->sort,
            'page' => $this->page, 'per_page' => $this->perPage,
        ];
    }
}
