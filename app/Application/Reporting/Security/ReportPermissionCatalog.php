<?php

declare(strict_types=1);

namespace App\Application\Reporting\Security;

final class ReportPermissionCatalog
{
    // 1. Report module permissions
    public const string REPORTS_SALES_VIEW = 'reports.sales.view';

    public const string REPORTS_PROFIT_VIEW = 'reports.profit.view';

    public const string REPORTS_COST_VIEW = 'reports.cost.view';

    public const string REPORTS_FINANCIAL_VIEW = 'reports.financial.view';

    public const string REPORTS_TAX_VIEW = 'reports.tax.view';

    public const string REPORTS_PURCHASES_VIEW = 'reports.purchases.view';

    public const string REPORTS_INVENTORY_VIEW = 'reports.inventory.view';

    public const string REPORTS_MONEY_VIEW = 'reports.money.view';

    public const string REPORTS_EXPENSES_VIEW = 'reports.expenses.view';

    public const string REPORTS_PAYROLL_VIEW = 'reports.payroll.view';

    // 2. Underlying domain capability permissions
    public const string INVENTORY_COST_VIEW = 'inventory.cost.view';

    public const string PURCHASING_COST_VIEW = 'purchasing.cost.view';

    public const string PAYROLL_SALARY_VIEW = 'payroll.salary.view';

    public const string CUSTOMERS_STATEMENT_VIEW = 'customers.statement.view';

    public const string VENDORS_STATEMENT_VIEW = 'vendors.statement.view';

    // 3. Authoritative permission combinations per report family
    /**
     * Profit report requires both profit view and cost view authority.
     *
     * @var list<string>
     */
    public const array PROFIT = [
        self::REPORTS_PROFIT_VIEW,
        self::REPORTS_COST_VIEW,
    ];

    /**
     * Inventory valuation requires report permission, inventory cost capability, and reporting cost capability.
     *
     * @var list<string>
     */
    public const array INVENTORY_VALUATION = [
        'inventory.stock.view',
        self::REPORTS_INVENTORY_VIEW,
        self::INVENTORY_COST_VIEW,
        self::REPORTS_COST_VIEW,
    ];

    /**
     * Inventory quantity-only reports (stock on hand, movements, low stock, expiry).
     *
     * @var list<string>
     */
    public const array INVENTORY_QUANTITY = [
        'inventory.stock.view',
        self::REPORTS_INVENTORY_VIEW,
    ];

    /**
     * Purchase commercial analytics with cost breakdown.
     *
     * @var list<string>
     */
    public const array PURCHASES_COST = [
        'purchasing.purchase.view',
        self::REPORTS_PURCHASES_VIEW,
        self::PURCHASING_COST_VIEW,
    ];

    /**
     * Commercial purchase summary (vendor commercial amounts).
     *
     * @var list<string>
     */
    public const array PURCHASES_COMMERCIAL = [
        'purchasing.purchase.view',
        self::PURCHASING_COST_VIEW,
        self::REPORTS_PURCHASES_VIEW,
    ];

    /**
     * Payroll reports (salary summary, obligations, history).
     *
     * @var list<string>
     */
    public const array PAYROLL = [
        'employees.view',
        self::REPORTS_PAYROLL_VIEW,
        self::PAYROLL_SALARY_VIEW,
    ];

    /**
     * Expense reports.
     *
     * @var list<string>
     */
    public const array EXPENSES = [
        'money.expense.view',
        self::REPORTS_EXPENSES_VIEW,
    ];

    /**
     * Sales gross profit / margin analytics.
     *
     * @var list<string>
     */
    public const array SALES_PROFIT = [
        'sales.invoice.view',
        self::REPORTS_PROFIT_VIEW,
        self::INVENTORY_COST_VIEW,
        self::REPORTS_SALES_VIEW,
        self::REPORTS_COST_VIEW,
    ];

    /**
     * Sales operational summary.
     *
     * @var list<string>
     */
    public const array SALES_SUMMARY = [
        'sales.invoice.view',
        self::REPORTS_SALES_VIEW,
    ];

    /**
     * Money reports.
     *
     * @var list<string>
     */
    public const array MONEY = [
        self::REPORTS_MONEY_VIEW,
    ];

    /**
     * Financial statements.
     *
     * @var list<string>
     */
    public const array FINANCIAL = [
        self::REPORTS_FINANCIAL_VIEW,
    ];

    /**
     * Tax reports.
     *
     * @var list<string>
     */
    public const array TAX = [
        self::REPORTS_TAX_VIEW,
    ];
}
