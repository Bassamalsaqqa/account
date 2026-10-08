<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ProfitReportResult;
use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Exceptions\MissingSystemAccountException;
use App\Application\Reporting\Exceptions\ReportingException;
use App\Application\Reporting\Security\ReportingGuard;
use App\Domain\Accounting\Catalog\SystemAccountsCatalog;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class ProfitReportQuery
{
    /**
     * Required system account keys that must exist and possess correct semantics.
     * Missing or corrupt required accounts cause fail-closed rejection.
     *
     * @var list<string>
     */
    public const array REQUIRED_SYSTEM_KEYS = [
        'sales_revenue',
        'sales_returns',
        'cogs',
        'operating_expense_parent',
        'salary_expense',
        'inventory_loss',
        'expiry_loss',
        'fx_gain',
        'fx_loss',
    ];

    public function __construct(
        protected ReportingGuard $guard
    ) {}

    /**
     * Execute GL-authoritative Profit Report.
     *
     * @param  ReportFilters|array<string, mixed>  $filters
     */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ProfitReportResult
    {
        // 1. Authorize: Fresh guard validates authentication, active company context,
        // active membership, and reports.profit.view + reports.cost.view permissions.
        $this->guard->authorizeProfit($company, $actor);

        // 2. Validate filters
        $company = $this->guard->company($company, $actor);
        $validatedFilters = ReportFilters::fromArray($company, $filters instanceof ReportFilters ? $filters->toArray() : $filters);
        foreach (['customer_id', 'vendor_id', 'product_id', 'category_id', 'warehouse_id', 'employee_id', 'money_account_id', 'currency_code', 'status', 'grouping', 'sort'] as $field) {
            if ($validatedFilters->toArray()[$field] !== null) {
                throw new InvalidReportFilterException("Filter [$field] is not supported by the GL Profit report.");
            }
        }
        if ($validatedFilters->page !== 1) {
            throw new InvalidReportFilterException('The Profit statement has one aggregate page.');
        }

        $period = $validatedFilters->period;

        // 3. Fail-closed: Verify required system accounts integrity
        $accounts = LedgerAccount::withoutGlobalScopes()->where('company_id', $company->id)->get();
        $systemGroups = $accounts->whereNotNull('system_key')->groupBy('system_key');
        $systemAccounts = $accounts->whereNotNull('system_key')->keyBy('system_key');
        $definitions = collect(SystemAccountsCatalog::all())->keyBy('systemKey');
        foreach (self::REQUIRED_SYSTEM_KEYS as $requiredKey) {
            $account = $systemAccounts->get($requiredKey);
            $definition = $definitions->get($requiredKey);
            $expectedParent = $definition?->parentSystemKey === null
                ? null : $systemAccounts->get($definition->parentSystemKey)?->id;
            if ($account === null || $definition === null || $systemGroups->get($requiredKey)?->count() !== 1
                || $account->account_type !== $definition->accountType
                || $account->normal_balance !== $definition->normalBalance
                || $account->is_control !== $definition->isControl || ! $account->is_system
                || ($account->parent_id === null ? null : (int) $account->parent_id) !== $expectedParent) {
                throw MissingSystemAccountException::forSystemKey($requiredKey, (int) $company->id);
            }
        }
        $operatingRoot = (int) $systemAccounts->get('operating_expense_parent')->id;
        $accountsById = $accounts->keyBy('id');
        $operatingIds = [$operatingRoot];
        foreach ($accounts as $account) {
            $parentId = $account->parent_id;
            $seen = [(int) $account->id];
            while ($parentId !== null) {
                if (in_array((int) $parentId, $seen, true) || ! $accountsById->has($parentId)) {
                    throw new ReportingException('Incoherent same-company account hierarchy.');
                }
                if ((int) $parentId === $operatingRoot) {
                    $operatingIds[] = (int) $account->id;
                    break;
                }
                $seen[] = (int) $parentId;
                $parentId = $accountsById->get($parentId)->parent_id;
            }
        }
        if (DB::table('posting_batches')->where('company_id', $company->id)
            ->whereBetween('posting_date', [$period->startDate, $period->endDate])
            ->where(function ($query) use ($company): void {
                $query->whereNotIn('status', [PostingBatch::STATUS_POSTED, PostingBatch::STATUS_REVERSED])
                    ->orWhere('base_currency_code', '!=', $company->base_currency_code);
            })->exists()) {
            throw new ReportingException('Invalid posting state or base-currency provenance in report period.');
        }

        if (DB::table('posting_batches as batch')
            ->join('posting_lines as line', 'line.posting_batch_id', '=', 'batch.id')
            ->leftJoin('ledger_accounts as account', 'account.id', '=', 'line.ledger_account_id')
            ->where('batch.company_id', $company->id)
            ->whereBetween('batch.posting_date', [$period->startDate, $period->endDate])
            ->where(function ($query) use ($company): void {
                $query->where('line.company_id', '!=', $company->id)
                    ->orWhereNull('account.id')
                    ->orWhere('account.company_id', '!=', $company->id);
            })->exists()) {
            throw new ReportingException('Incoherent company/account provenance in report period.');
        }
        // 4. Query canonical GL PostingLines by PostingBatch posting_date.
        // Both posted and reversed batches participate at their respective event dates.
        // Landed Cost clearing/capitalization (asset) is strictly excluded via account_type filter.
        $rows = DB::table('posting_lines')
            ->join('posting_batches', 'posting_lines.posting_batch_id', '=', 'posting_batches.id')
            ->join('ledger_accounts', 'posting_lines.ledger_account_id', '=', 'ledger_accounts.id')
            ->where('posting_lines.company_id', $company->id)
            ->where('posting_batches.company_id', $company->id)
            ->where('ledger_accounts.company_id', $company->id)
            ->where('posting_batches.posting_date', '>=', $period->startDate)
            ->where('posting_batches.posting_date', '<=', $period->endDate)
            ->whereIn('ledger_accounts.account_type', [LedgerAccount::TYPE_REVENUE, LedgerAccount::TYPE_EXPENSE])
            ->groupBy(
                'ledger_accounts.id',
                'ledger_accounts.code',
                'ledger_accounts.system_key',
                'ledger_accounts.name_ar',
                'ledger_accounts.name_en',
                'ledger_accounts.account_type',
                'ledger_accounts.normal_balance',
                'ledger_accounts.parent_id'
            )
            ->select([
                'ledger_accounts.id as account_id',
                'ledger_accounts.code',
                'ledger_accounts.system_key',
                'ledger_accounts.name_ar',
                'ledger_accounts.name_en',
                'ledger_accounts.account_type',
                'ledger_accounts.normal_balance',
                'ledger_accounts.parent_id',
                DB::raw('COALESCE(SUM(posting_lines.debit_base), 0) as total_debit'),
                DB::raw('COALESCE(SUM(posting_lines.credit_base), 0) as total_credit'),
            ])
            ->orderBy('ledger_accounts.code')
            ->get();

        // 5. Aggregate with exact BigDecimal primitives
        $salesRevenue = BigDecimal::zero();
        $salesReturns = BigDecimal::zero();
        $cogs = BigDecimal::zero();
        $salaryExpense = BigDecimal::zero();
        $inventoryLoss = BigDecimal::zero();
        $expiryLoss = BigDecimal::zero();
        $fxGain = BigDecimal::zero();
        $fxLoss = BigDecimal::zero();
        $operatingExpenses = BigDecimal::zero();
        $otherRevenue = BigDecimal::zero();
        $otherExpense = BigDecimal::zero();

        $operatingExpenseBreakdown = [];

        foreach ($rows as $row) {
            $debit = BigDecimal::of((string) $row->total_debit);
            $credit = BigDecimal::of((string) $row->total_credit);
            $key = $row->system_key;
            $type = $row->account_type;

            match ($key) {
                'sales_revenue' => $salesRevenue = $salesRevenue->plus($credit->minus($debit)),
                'sales_returns' => $salesReturns = $salesReturns->plus($debit->minus($credit)),
                'cogs' => $cogs = $cogs->plus($debit->minus($credit)),
                'salary_expense' => $salaryExpense = $salaryExpense->plus($debit->minus($credit)),
                'inventory_loss' => $inventoryLoss = $inventoryLoss->plus($debit->minus($credit)),
                'expiry_loss' => $expiryLoss = $expiryLoss->plus($debit->minus($credit)),
                'fx_gain' => $fxGain = $fxGain->plus($credit->minus($debit)),
                'fx_loss' => $fxLoss = $fxLoss->plus($debit->minus($credit)),
                default => (function () use (
                    $row, $debit, $credit, $type,
                    &$operatingExpenses, &$operatingExpenseBreakdown,
                    &$otherRevenue, &$otherExpense, $operatingIds
                ) {
                    if ($type === LedgerAccount::TYPE_EXPENSE) {
                        // Ordinary operating expense account
                        $netExpense = $debit->minus($credit);
                        if (! in_array((int) $row->account_id, $operatingIds, true)) {
                            $otherExpense = $otherExpense->plus($netExpense);

                            return;
                        }
                        $operatingExpenses = $operatingExpenses->plus($netExpense);

                        $operatingExpenseBreakdown[] = [
                            'account_id' => (int) $row->account_id,
                            'code' => (string) $row->code,
                            'name_ar' => (string) $row->name_ar,
                            'name_en' => (string) $row->name_en,
                            'system_key' => $row->system_key ? (string) $row->system_key : null,
                            'amount' => (string) $netExpense->toScale(6),
                        ];
                    } elseif ($type === LedgerAccount::TYPE_REVENUE) {
                        // Other revenue account
                        $netRevenue = $credit->minus($debit);
                        $otherRevenue = $otherRevenue->plus($netRevenue);
                    }
                })(),
            };
        }

        // Subtotals and Net Profit
        $netSales = $salesRevenue->minus($salesReturns);
        $grossProfit = $netSales->minus($cogs);

        $netProfit = $grossProfit
            ->minus($operatingExpenses)
            ->minus($salaryExpense)
            ->minus($inventoryLoss)
            ->minus($expiryLoss)
            ->plus($fxGain)
            ->minus($fxLoss)
            ->plus($otherRevenue)
            ->minus($otherExpense);

        $baseCurrency = (string) $company->base_currency_code;

        $rowsData = [
            [
                'key' => 'sales_revenue',
                'name_ar' => 'إيرادات المبيعات',
                'name_en' => 'Sales Revenue',
                'amount' => (string) $salesRevenue->toScale(6),
                'type' => 'revenue',
            ],
            [
                'key' => 'sales_returns',
                'name_ar' => 'مردودات ومسموحات المبيعات',
                'name_en' => 'Sales Returns',
                'amount' => (string) $salesReturns->toScale(6),
                'type' => 'contra_revenue',
            ],
            [
                'key' => 'net_sales',
                'name_ar' => 'صافي المبيعات',
                'name_en' => 'Net Sales',
                'amount' => (string) $netSales->toScale(6),
                'type' => 'subtotal',
            ],
            [
                'key' => 'cogs',
                'name_ar' => 'تكلفة البضاعة المباعة',
                'name_en' => 'Cost of Goods Sold',
                'amount' => (string) $cogs->toScale(6),
                'type' => 'cogs',
            ],
            [
                'key' => 'gross_profit',
                'name_ar' => 'إجمالي الربح',
                'name_en' => 'Gross Profit',
                'amount' => (string) $grossProfit->toScale(6),
                'type' => 'subtotal',
            ],
            [
                'key' => 'operating_expenses',
                'name_ar' => 'المصاريف التشغيلية (بدون الرواتب)',
                'name_en' => 'Operating Expenses (excl. Salaries)',
                'amount' => (string) $operatingExpenses->toScale(6),
                'type' => 'expense_subtotal',
            ],
            [
                'key' => 'salary_expense',
                'name_ar' => 'مصاريف الرواتب والأجور',
                'name_en' => 'Salary Expense',
                'amount' => (string) $salaryExpense->toScale(6),
                'type' => 'expense',
            ],
            [
                'key' => 'inventory_loss',
                'name_ar' => 'خسائر فروقات الجرد والتلف',
                'name_en' => 'Inventory Discrepancy & Shrinkage Loss',
                'amount' => (string) $inventoryLoss->toScale(6),
                'type' => 'expense',
            ],
            [
                'key' => 'expiry_loss',
                'name_ar' => 'خسائر انتهاء الصلاحية',
                'name_en' => 'Expired Goods Loss',
                'amount' => (string) $expiryLoss->toScale(6),
                'type' => 'expense',
            ],
            [
                'key' => 'fx_gain',
                'name_ar' => 'أرباح فروق أسعار الصرف',
                'name_en' => 'Foreign Exchange Gains',
                'amount' => (string) $fxGain->toScale(6),
                'type' => 'other_income',
            ],
            [
                'key' => 'fx_loss',
                'name_ar' => 'خسائر فروق أسعار الصرف',
                'name_en' => 'Foreign Exchange Losses',
                'amount' => (string) $fxLoss->toScale(6),
                'type' => 'other_expense',
            ],
            [
                'key' => 'other_revenue',
                'name_ar' => 'إيرادات أخرى',
                'name_en' => 'Other Revenue',
                'amount' => (string) $otherRevenue->toScale(6),
                'type' => 'other_income',
            ],
            [
                'key' => 'other_expense',
                'name_ar' => 'مصروفات أخرى',
                'name_en' => 'Other Expenses',
                'amount' => (string) $otherExpense->toScale(6),
                'type' => 'other_expense',
            ],
            [
                'key' => 'net_profit',
                'name_ar' => 'صافي الربح',
                'name_en' => 'Net Profit',
                'amount' => (string) $netProfit->toScale(6),
                'type' => 'total',
            ],
        ];

        return new ProfitReportResult(
            filters: $validatedFilters->toArray(),
            salesRevenue: (string) $salesRevenue->toScale(6),
            salesReturns: (string) $salesReturns->toScale(6),
            netSales: (string) $netSales->toScale(6),
            cogs: (string) $cogs->toScale(6),
            grossProfit: (string) $grossProfit->toScale(6),
            operatingExpenses: (string) $operatingExpenses->toScale(6),
            operatingExpenseBreakdown: $operatingExpenseBreakdown,
            salaryExpense: (string) $salaryExpense->toScale(6),
            inventoryLoss: (string) $inventoryLoss->toScale(6),
            expiryLoss: (string) $expiryLoss->toScale(6),
            fxGain: (string) $fxGain->toScale(6),
            fxLoss: (string) $fxLoss->toScale(6),
            otherRevenue: (string) $otherRevenue->toScale(6),
            otherExpense: (string) $otherExpense->toScale(6),
            netProfit: (string) $netProfit->toScale(6),
            baseCurrency: $baseCurrency,
            rows: $rowsData
        );
    }
}
