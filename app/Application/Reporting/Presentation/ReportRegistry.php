<?php

declare(strict_types=1);

namespace App\Application\Reporting\Presentation;

use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Security\ReportingGuard;
use App\Models\Company;
use App\Services\Phase7\Phase7FinancialRead;
use App\Services\Purchasing\VendorFinancialRead;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class ReportRegistry
{
    /** @var array<string, array<string, mixed>> */
    private const DEFINITIONS = [
        'sales.summary' => [
            'key' => 'sales.summary',
            'query' => 'App\Application\Reporting\Queries\SalesSummaryReportQuery',
            'group' => 'sales',
            'name_en' => 'Sales summary',
            'name_ar' => 'ملخص المبيعات',
            'columns' => [
                0 => [
                    'key' => 'document_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'business_date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'event_type',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'gross_sales_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'returns_base',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'net_sales_base',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'tax_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'sales.invoice.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'currency_code',
                2 => 'customer_id',
                3 => 'warehouse_id',
                4 => 'grouping',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'daily',
                    1 => 'weekly',
                    2 => 'monthly',
                ],
            ],
        ],
        'sales.by-period' => [
            'key' => 'sales.by-period',
            'query' => 'App\Application\Reporting\Queries\SalesSummaryReportQuery',
            'group' => 'sales',
            'name_en' => 'Sales by period',
            'name_ar' => 'المبيعات حسب الفترة',
            'columns' => [
                0 => [
                    'key' => 'period_key',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'gross_sales_base',
                    'type' => 'decimal',
                ],
                2 => [
                    'key' => 'returns_base',
                    'type' => 'decimal',
                ],
                3 => [
                    'key' => 'net_sales_base',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'original_invoice_count',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'sales.invoice.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'currency_code',
                2 => 'customer_id',
                3 => 'warehouse_id',
                4 => 'grouping',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
                'grouping' => 'daily',
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'daily',
                    1 => 'weekly',
                    2 => 'monthly',
                ],
            ],
        ],
        'sales.by-customer' => [
            'key' => 'sales.by-customer',
            'query' => 'App\Application\Reporting\Queries\SalesByCustomerReportQuery',
            'group' => 'sales',
            'name_en' => 'Sales by customer',
            'name_ar' => 'المبيعات حسب العميل',
            'columns' => [
                0 => [
                    'key' => 'customer_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'customer_code',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'original_invoice_count',
                    'type' => 'decimal',
                ],
                3 => [
                    'key' => 'net_sales_base',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'returns_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'sales.invoice.view',
                2 => 'customers.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'customer_id',
                2 => 'currency_code',
                3 => 'sort',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'sort' => [
                    0 => 'net_sales_desc',
                    1 => 'net_sales_asc',
                    2 => 'gross_sales_desc',
                    3 => 'name_asc',
                    4 => 'invoice_count_desc',
                ],
            ],
        ],
        'sales.by-product' => [
            'key' => 'sales.by-product',
            'query' => 'App\Application\Reporting\Queries\SalesByProductReportQuery',
            'group' => 'sales',
            'name_en' => 'Sales by product',
            'name_ar' => 'المبيعات حسب المنتج',
            'columns' => [
                0 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'product_sku',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'quantity_base',
                    'type' => 'decimal',
                ],
                3 => [
                    'key' => 'sales_revenue_base',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'cogs_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'gross_profit_base',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'gross_margin',
                    'type' => 'decimal',
                ],
                ['key' => 'unit_name', 'type' => 'text'],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'sales.invoice.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'product_id',
                2 => 'category_id',
                3 => 'warehouse_id',
                4 => 'currency_code',
                5 => 'sort',
                6 => 'page',
                7 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'sort' => [
                    0 => 'quantity_desc',
                    1 => 'revenue_desc',
                    2 => 'profit_desc',
                    3 => 'name_asc',
                ],
            ],
        ],
        'sales.by-category' => [
            'key' => 'sales.by-category',
            'query' => 'App\Application\Reporting\Queries\SalesByCategoryReportQuery',
            'group' => 'sales',
            'name_en' => 'Sales by category',
            'name_ar' => 'المبيعات حسب الفئة',
            'columns' => [
                0 => [
                    'key' => 'category_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'quantity_base',
                    'type' => 'decimal',
                ],
                2 => [
                    'key' => 'sales_revenue_base',
                    'type' => 'decimal',
                ],
                3 => [
                    'key' => 'cogs_base',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'gross_profit_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'gross_margin',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'sales.invoice.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'category_id',
                2 => 'currency_code',
                3 => 'warehouse_id',
                4 => 'sort',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'sort' => [
                    0 => 'revenue_desc',
                    1 => 'quantity_desc',
                    2 => 'profit_desc',
                    3 => 'name_asc',
                ],
            ],
        ],
        'sales.gross-profit' => [
            'key' => 'sales.gross-profit',
            'query' => 'App\Application\Reporting\Queries\SalesGrossProfitReportQuery',
            'group' => 'sales',
            'name_en' => 'Gross profit',
            'name_ar' => 'الربح الإجمالي',
            'columns' => [
                0 => [
                    'key' => 'document_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'business_date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'customer_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'revenue_base',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'cogs_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'gross_profit_base',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'gross_margin',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'sales.invoice.view',
                2 => 'reports.profit.view',
                3 => 'reports.cost.view',
                4 => 'inventory.cost.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'grouping',
                2 => 'customer_id',
                3 => 'product_id',
                4 => 'currency_code',
                5 => 'sort',
                6 => 'page',
                7 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
                'grouping' => 'by_invoice',
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'by_invoice',
                    1 => 'by_product',
                ],
                'sort' => [
                    0 => 'profit_desc',
                    1 => 'profit_asc',
                    2 => 'revenue_desc',
                    3 => 'margin_desc',
                    4 => 'date_desc',
                ],
            ],
        ],
        'sales.discounts' => [
            'key' => 'sales.discounts',
            'query' => 'App\Application\Reporting\Queries\SalesDiscountsReportQuery',
            'group' => 'sales',
            'name_en' => 'Sales discounts',
            'name_ar' => 'خصومات المبيعات',
            'columns' => [
                0 => [
                    'key' => 'document_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'business_date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'quantity_base',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'line_discount_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'line_total_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'sales.invoice.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'customer_id',
                2 => 'product_id',
                3 => 'currency_code',
                4 => 'warehouse_id',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'sales.returns' => [
            'key' => 'sales.returns',
            'query' => 'App\Application\Reporting\Queries\SalesReturnsReportQuery',
            'group' => 'sales',
            'name_en' => 'Sales returns',
            'name_ar' => 'مرتجعات المبيعات',
            'columns' => [
                0 => [
                    'key' => 'return_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'invoice_number',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'business_date',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'customer_name',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'grand_total_currency',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'grand_total_base',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'reason',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'sales.return.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'customer_id',
                2 => 'currency_code',
                3 => 'warehouse_id',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'sales.unpaid' => [
            'key' => 'sales.unpaid',
            'query' => 'App\Application\Reporting\Queries\SalesUnpaidInvoicesReportQuery',
            'group' => 'sales',
            'name_en' => 'Unpaid invoices',
            'name_ar' => 'الفواتير غير المسددة',
            'columns' => [
                0 => [
                    'key' => 'invoice_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'issue_date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'due_date',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'customer_name',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'grand_total_currency',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'outstanding_currency',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'sales.invoice.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'customer_id',
                2 => 'currency_code',
                3 => 'warehouse_id',
                4 => 'sort',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'sort' => [
                    0 => 'outstanding_desc',
                    1 => 'due_date_asc',
                    2 => 'issue_date_desc',
                    3 => 'overdue_desc',
                ],
            ],
        ],
        'sales.price-history' => [
            'key' => 'sales.price-history',
            'query' => 'App\Application\Reporting\Queries\SalesPriceHistoryReportQuery',
            'group' => 'sales',
            'name_en' => 'Selling price history',
            'name_ar' => 'سجل أسعار البيع',
            'columns' => [
                0 => [
                    'key' => 'invoice_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'issue_date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'customer_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'product_sku',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'quantity',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'unit_name',
                    'type' => 'text',
                ],
                7 => [
                    'key' => 'unit_price',
                    'type' => 'decimal',
                ],
                8 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                9 => [
                    'key' => 'line_total',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'sales.invoice.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'customer_id',
                2 => 'product_id',
                3 => 'currency_code',
                4 => 'sort',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'sort' => [
                    0 => 'date_desc',
                    1 => 'price_desc',
                    2 => 'price_asc',
                ],
            ],
        ],
        'customers.balances' => [
            'key' => 'customers.balances',
            'query' => 'App\Application\Reporting\Queries\CustomerBalancesReportQuery',
            'group' => 'customers',
            'name_en' => 'Customer balances',
            'name_ar' => 'أرصدة العملاء',
            'columns' => [
                0 => [
                    'key' => 'customer_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'customer_code',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'invoiced_amount',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'outstanding_balance',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'customers.view',
            ],
            'filters' => [
                0 => 'customer_id',
                1 => 'currency_code',
                2 => 'sort',
                3 => 'page',
                4 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => true,
            'options' => [
                'sort' => [
                    0 => 'name_asc',
                    1 => 'code_asc',
                ],
            ],
        ],
        'customers.statement' => [
            'key' => 'customers.statement',
            'query' => 'App\Application\Reporting\Queries\CustomerStatementReportQuery',
            'group' => 'customers',
            'name_en' => 'Customer statement',
            'name_ar' => 'كشف حساب العميل',
            'columns' => [
                0 => [
                    'key' => 'date',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'number',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'type',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'description',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'debit',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'credit',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'balance',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'customers.statement.view',
            ],
            'filters' => [
                0 => 'customer_id',
                1 => 'period',
                2 => 'currency_code',
                3 => 'page',
                4 => 'per_page',
            ],
            'required' => [
                0 => 'customer_id',
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'customers.aging' => [
            'key' => 'customers.aging',
            'query' => 'App\Application\Reporting\Queries\CustomerReceivablesAgingReportQuery',
            'group' => 'customers',
            'name_en' => 'Receivables aging',
            'name_ar' => 'أعمار ذمم العملاء',
            'columns' => [
                0 => [
                    'key' => 'customer_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'current',
                    'type' => 'decimal',
                ],
                3 => [
                    'key' => 'days_1_30',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'days_31_60',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'days_61_90',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'days_90_plus',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'unspecified',
                    'type' => 'decimal',
                ],
                8 => [
                    'key' => 'total_outstanding',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'customers.statement.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'customer_id',
                2 => 'currency_code',
                3 => 'warehouse_id',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'customers.overdue' => [
            'key' => 'customers.overdue',
            'query' => 'App\Application\Reporting\Queries\CustomerOverdueInvoicesReportQuery',
            'group' => 'customers',
            'name_en' => 'Overdue invoices',
            'name_ar' => 'الفواتير المتأخرة',
            'columns' => [
                0 => [
                    'key' => 'invoice_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'customer_name',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'due_date',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'days_overdue',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'outstanding_currency',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'customers.view',
                2 => 'sales.invoice.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'customer_id',
                2 => 'currency_code',
                3 => 'warehouse_id',
                4 => 'sort',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'sort' => [
                    0 => 'overdue_desc',
                    1 => 'outstanding_desc',
                    2 => 'due_date_asc',
                ],
            ],
        ],
        'customers.top' => [
            'key' => 'customers.top',
            'query' => 'App\Application\Reporting\Queries\CustomerTopReportQuery',
            'group' => 'customers',
            'name_en' => 'Top customers',
            'name_ar' => 'أهم العملاء',
            'columns' => [
                0 => [
                    'key' => 'rank',
                    'type' => 'decimal',
                ],
                1 => [
                    'key' => 'customer_name',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'original_invoice_count',
                    'type' => 'decimal',
                ],
                3 => [
                    'key' => 'net_sales_base',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'revenue_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'returns_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'customers.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'currency_code',
                2 => 'page',
                3 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'customers.buying-history' => [
            'key' => 'customers.buying-history',
            'query' => 'App\Application\Reporting\Queries\CustomerBuyingHistoryReportQuery',
            'group' => 'customers',
            'name_en' => 'Customer buying history',
            'name_ar' => 'سجل مشتريات العميل',
            'columns' => [
                0 => [
                    'key' => 'document_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'business_date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'customer_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'gross_sales_currency',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'returns_currency',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'revenue_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'sales.invoice.view',
                2 => 'customers.view',
            ],
            'filters' => [
                0 => 'customer_id',
                1 => 'period',
                2 => 'currency_code',
                3 => 'warehouse_id',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
                0 => 'customer_id',
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'customers.product-history' => [
            'key' => 'customers.product-history',
            'query' => 'App\Application\Reporting\Queries\CustomerProductHistoryReportQuery',
            'group' => 'customers',
            'name_en' => 'Products by customer',
            'name_ar' => 'المنتجات حسب العميل',
            'columns' => [
                0 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'product_sku',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'unit_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'total_quantity_base',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'total_spent_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'last_purchased_date',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.sales.view',
                1 => 'sales.invoice.view',
                2 => 'customers.view',
            ],
            'filters' => [
                0 => 'customer_id',
                1 => 'product_id',
                2 => 'period',
                3 => 'currency_code',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
                0 => 'customer_id',
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'purchases.summary' => [
            'key' => 'purchases.summary',
            'query' => 'App\Application\Reporting\Queries\PurchaseSummaryReportQuery',
            'group' => 'purchases',
            'name_en' => 'Purchase summary',
            'name_ar' => 'ملخص المشتريات',
            'columns' => [
                0 => [
                    'key' => 'document_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'business_date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'event_type',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'commercial_purchases_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'returns_base',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'net_purchases_base',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'tax_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.purchases.view',
                1 => 'purchasing.purchase.view',
                2 => 'purchasing.cost.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'vendor_id',
                2 => 'currency_code',
                3 => 'warehouse_id',
                4 => 'grouping',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'daily',
                    1 => 'weekly',
                    2 => 'monthly',
                ],
            ],
        ],
        'purchases.by-period' => [
            'key' => 'purchases.by-period',
            'query' => 'App\Application\Reporting\Queries\PurchaseSummaryReportQuery',
            'group' => 'purchases',
            'name_en' => 'Purchases by period',
            'name_ar' => 'المشتريات حسب الفترة',
            'columns' => [
                0 => [
                    'key' => 'period_key',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'commercial_purchases_base',
                    'type' => 'decimal',
                ],
                2 => [
                    'key' => 'returns_base',
                    'type' => 'decimal',
                ],
                3 => [
                    'key' => 'net_purchases_base',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'purchase_count',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.purchases.view',
                1 => 'purchasing.purchase.view',
                2 => 'purchasing.cost.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'vendor_id',
                2 => 'currency_code',
                3 => 'warehouse_id',
                4 => 'grouping',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
                'grouping' => 'daily',
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'daily',
                    1 => 'weekly',
                    2 => 'monthly',
                ],
            ],
        ],
        'purchases.by-vendor' => [
            'key' => 'purchases.by-vendor',
            'query' => 'App\Application\Reporting\Queries\PurchasesByVendorReportQuery',
            'group' => 'purchases',
            'name_en' => 'Purchases by vendor',
            'name_ar' => 'المشتريات حسب المورد',
            'columns' => [
                0 => [
                    'key' => 'vendor_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'vendor_code',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'purchase_count',
                    'type' => 'decimal',
                ],
                3 => [
                    'key' => 'commercial_purchases_base',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'returns_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'net_purchases_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.purchases.view',
                1 => 'purchasing.purchase.view',
                2 => 'purchasing.cost.view',
                3 => 'vendors.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'vendor_id',
                2 => 'currency_code',
                3 => 'sort',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'sort' => [
                    0 => 'purchases_desc',
                    1 => 'net_purchases_desc',
                    2 => 'name_asc',
                    3 => 'count_desc',
                ],
            ],
        ],
        'purchases.by-product' => [
            'key' => 'purchases.by-product',
            'query' => 'App\Application\Reporting\Queries\PurchasesByProductReportQuery',
            'group' => 'purchases',
            'name_en' => 'Purchases by product',
            'name_ar' => 'المشتريات حسب المنتج',
            'columns' => [
                0 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'product_sku',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'unit_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'quantity_base',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'commercial_total_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'landed_cost_allocated_base',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'total_acquisition_cost_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.purchases.view',
                1 => 'purchasing.purchase.view',
                2 => 'purchasing.cost.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'product_id',
                2 => 'vendor_id',
                3 => 'warehouse_id',
                4 => 'currency_code',
                5 => 'sort',
                6 => 'page',
                7 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'sort' => [
                    0 => 'amount_desc',
                    1 => 'quantity_desc',
                    2 => 'name_asc',
                ],
            ],
        ],
        'purchases.returns' => [
            'key' => 'purchases.returns',
            'query' => 'App\Application\Reporting\Queries\PurchaseReturnsReportQuery',
            'group' => 'purchases',
            'name_en' => 'Purchase returns',
            'name_ar' => 'مرتجعات المشتريات',
            'columns' => [
                0 => [
                    'key' => 'return_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'purchase_number',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'business_date',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'vendor_name',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'grand_total_currency',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'grand_total_base',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'reason',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.purchases.view',
                1 => 'purchasing.purchase.view',
                2 => 'purchasing.cost.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'vendor_id',
                2 => 'currency_code',
                3 => 'warehouse_id',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'purchases.unpaid' => [
            'key' => 'purchases.unpaid',
            'query' => 'App\Application\Reporting\Queries\PurchaseUnpaidReportQuery',
            'group' => 'purchases',
            'name_en' => 'Unpaid purchases',
            'name_ar' => 'المشتريات غير المسددة',
            'columns' => [
                0 => [
                    'key' => 'purchase_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'purchase_date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'due_date',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'vendor_name',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'grand_total_currency',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'outstanding_currency',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.purchases.view',
                1 => 'purchasing.purchase.view',
                2 => 'purchasing.cost.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'vendor_id',
                2 => 'currency_code',
                3 => 'warehouse_id',
                4 => 'sort',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'sort' => [
                    0 => 'outstanding_desc',
                    1 => 'due_date_asc',
                    2 => 'purchase_date_desc',
                    3 => 'overdue_desc',
                ],
            ],
        ],
        'purchases.price-history' => [
            'key' => 'purchases.price-history',
            'query' => 'App\Application\Reporting\Queries\PurchasePriceHistoryReportQuery',
            'group' => 'purchases',
            'name_en' => 'Purchase price history',
            'name_ar' => 'سجل أسعار الشراء',
            'columns' => [
                0 => [
                    'key' => 'purchase_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'purchase_date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'vendor_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'unit_name',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'quantity',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'commercial_unit_price',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                8 => [
                    'key' => 'commercial_line_total',
                    'type' => 'decimal',
                ],
                9 => [
                    'key' => 'commercial_line_total_base',
                    'type' => 'decimal',
                ],
                10 => [
                    'key' => 'net_commercial_price_per_base_unit',
                    'type' => 'decimal',
                ],
                11 => [
                    'key' => 'landed_cost_allocated_base',
                    'type' => 'decimal',
                ],
                12 => [
                    'key' => 'total_acquisition_cost_base',
                    'type' => 'decimal',
                ],
                13 => [
                    'key' => 'inventory_unit_cost_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.purchases.view',
                1 => 'purchasing.purchase.view',
                2 => 'purchasing.cost.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'vendor_id',
                2 => 'product_id',
                3 => 'currency_code',
                4 => 'sort',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'sort' => [
                    0 => 'date_desc',
                    1 => 'cost_desc',
                    2 => 'cost_asc',
                ],
            ],
        ],
        'vendors.balances' => [
            'key' => 'vendors.balances',
            'query' => 'App\Application\Reporting\Queries\VendorBalancesReportQuery',
            'group' => 'vendors',
            'name_en' => 'Vendor balances',
            'name_ar' => 'أرصدة الموردين',
            'columns' => [
                0 => [
                    'key' => 'vendor_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'vendor_code',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'purchased_amount',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'returned_amount',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'paid_amount',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'balance',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.purchases.view',
                1 => 'vendors.statement.view',
                2 => 'purchasing.cost.view',
            ],
            'filters' => [
                0 => 'vendor_id',
                1 => 'currency_code',
                2 => 'sort',
                3 => 'page',
                4 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => true,
            'options' => [
                'sort' => [
                    0 => 'name_asc',
                    1 => 'code_asc',
                ],
            ],
        ],
        'vendors.statement' => [
            'key' => 'vendors.statement',
            'query' => 'App\Application\Reporting\Queries\VendorStatementReportQuery',
            'group' => 'vendors',
            'name_en' => 'Vendor statement',
            'name_ar' => 'كشف حساب المورد',
            'columns' => [
                0 => [
                    'key' => 'date',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'number',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'type',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'description',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'debit',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'credit',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'balance',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.purchases.view',
                1 => 'vendors.statement.view',
                2 => 'purchasing.cost.view',
            ],
            'filters' => [
                0 => 'vendor_id',
                1 => 'period',
                2 => 'currency_code',
                3 => 'page',
                4 => 'per_page',
            ],
            'required' => [
                0 => 'vendor_id',
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'vendors.aging' => [
            'key' => 'vendors.aging',
            'query' => 'App\Application\Reporting\Queries\VendorPayablesAgingReportQuery',
            'group' => 'vendors',
            'name_en' => 'Payables aging',
            'name_ar' => 'أعمار ذمم الموردين',
            'columns' => [
                0 => [
                    'key' => 'vendor_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'current',
                    'type' => 'decimal',
                ],
                3 => [
                    'key' => 'days_1_30',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'days_31_60',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'days_61_90',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'days_90_plus',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'unspecified',
                    'type' => 'decimal',
                ],
                8 => [
                    'key' => 'gross_open',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.purchases.view',
                1 => 'vendors.statement.view',
                2 => 'purchasing.cost.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'vendor_id',
                2 => 'currency_code',
                3 => 'warehouse_id',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'vendors.purchase-history' => [
            'key' => 'vendors.purchase-history',
            'query' => 'App\Application\Reporting\Queries\VendorPurchaseHistoryReportQuery',
            'group' => 'vendors',
            'name_en' => 'Vendor purchase history',
            'name_ar' => 'سجل مشتريات المورد',
            'columns' => [
                0 => [
                    'key' => 'document_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'business_date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'vendor_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'commercial_purchases_currency',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'returns_currency',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'commercial_purchases_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.purchases.view',
                1 => 'purchasing.purchase.view',
                2 => 'purchasing.cost.view',
                3 => 'vendors.view',
            ],
            'filters' => [
                0 => 'vendor_id',
                1 => 'period',
                2 => 'currency_code',
                3 => 'warehouse_id',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
                0 => 'vendor_id',
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'vendors.product-history' => [
            'key' => 'vendors.product-history',
            'query' => 'App\Application\Reporting\Queries\VendorProductHistoryReportQuery',
            'group' => 'vendors',
            'name_en' => 'Products by vendor',
            'name_ar' => 'المنتجات حسب المورد',
            'columns' => [
                0 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'product_sku',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'unit_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'total_quantity_base',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'total_commercial_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'last_purchased_date',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.purchases.view',
                1 => 'purchasing.purchase.view',
                2 => 'purchasing.cost.view',
                3 => 'vendors.view',
            ],
            'filters' => [
                0 => 'vendor_id',
                1 => 'product_id',
                2 => 'period',
                3 => 'warehouse_id',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
                0 => 'vendor_id',
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'vendors.price-history' => [
            'key' => 'vendors.price-history',
            'query' => 'App\Application\Reporting\Queries\VendorProductPriceHistoryReportQuery',
            'group' => 'vendors',
            'name_en' => 'Vendor product prices',
            'name_ar' => 'أسعار منتجات المورد',
            'columns' => [
                0 => [
                    'key' => 'purchase_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'purchase_date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'vendor_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'unit_name',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'quantity',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'unit_price',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                8 => [
                    'key' => 'commercial_line_total_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.purchases.view',
                1 => 'purchasing.purchase.view',
                2 => 'purchasing.cost.view',
                3 => 'vendors.view',
            ],
            'filters' => [
                0 => 'vendor_id',
                1 => 'product_id',
                2 => 'period',
                3 => 'currency_code',
                4 => 'sort',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
                0 => 'vendor_id',
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'sort' => [
                    0 => 'date_desc',
                    1 => 'price_desc',
                    2 => 'price_asc',
                ],
            ],
        ],
        'inventory.stock' => [
            'key' => 'inventory.stock',
            'query' => 'App\Application\Reporting\Queries\InventoryStockOnHandReportQuery',
            'group' => 'inventory',
            'name_en' => 'Stock on hand',
            'name_ar' => 'المخزون المتاح',
            'columns' => [
                0 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'sku',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'warehouse_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'unit_code',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'quantity_on_hand',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'value_base',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'average_unit_cost_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.inventory.view',
                1 => 'inventory.stock.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'product_id',
                2 => 'category_id',
                3 => 'warehouse_id',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'inventory.by-warehouse' => [
            'key' => 'inventory.by-warehouse',
            'query' => 'App\Application\Reporting\Queries\InventoryStockOnHandReportQuery',
            'group' => 'inventory',
            'name_en' => 'Stock by warehouse',
            'name_ar' => 'المخزون حسب المستودع',
            'columns' => [
                0 => [
                    'key' => 'warehouse_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'sku',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'unit_code',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'quantity_on_hand',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.inventory.view',
                1 => 'inventory.stock.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'product_id',
                2 => 'category_id',
                3 => 'warehouse_id',
                4 => 'page',
                5 => 'per_page',
                6 => 'grouping',
            ],
            'required' => [
            ],
            'defaults' => [
                'grouping' => 'warehouse',
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'product',
                    1 => 'warehouse',
                ],
            ],
        ],
        'inventory.movements' => [
            'key' => 'inventory.movements',
            'query' => 'App\Application\Reporting\Queries\InventoryMovementReportQuery',
            'group' => 'inventory',
            'name_en' => 'Stock movements',
            'name_ar' => 'حركات المخزون',
            'columns' => [
                0 => [
                    'key' => 'movement_date',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'sku',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'warehouse_name',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'movement_type',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'quantity_delta_base',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'unit_cost_base',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'value_delta_base',
                    'type' => 'decimal',
                ],
                8 => [
                    'key' => 'reason',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.inventory.view',
                1 => 'inventory.stock.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'product_id',
                2 => 'category_id',
                3 => 'warehouse_id',
                4 => 'status',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'status' => [
                    0 => 'adjustment',
                    1 => 'adjustment_increase',
                    2 => 'adjustment_decrease',
                    3 => 'damage',
                    4 => 'loss',
                    5 => 'damage_or_loss',
                ],
            ],
        ],
        'inventory.valuation' => [
            'key' => 'inventory.valuation',
            'query' => 'App\Application\Reporting\Queries\InventoryValuationReportQuery',
            'group' => 'inventory',
            'name_en' => 'Inventory valuation',
            'name_ar' => 'تقييم المخزون',
            'columns' => [
                0 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'sku',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'warehouse_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'unit_code',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'quantity_on_hand',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'valuation_base',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'average_unit_cost_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.inventory.view',
                1 => 'inventory.stock.view',
                2 => 'inventory.cost.view',
                3 => 'reports.cost.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'product_id',
                2 => 'category_id',
                3 => 'warehouse_id',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'inventory.low-stock' => [
            'key' => 'inventory.low-stock',
            'query' => 'App\Application\Reporting\Queries\LowStockReportQuery',
            'group' => 'inventory',
            'name_en' => 'Low and out-of-stock',
            'name_ar' => 'المخزون المنخفض والنافد',
            'columns' => [
                0 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'sku',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'unit_code',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'quantity_on_hand',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'minimum_stock',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'shortage_quantity',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'stock_status',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.inventory.view',
                1 => 'inventory.stock.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'product_id',
                2 => 'category_id',
                3 => 'warehouse_id',
                4 => 'status',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'status' => [
                    0 => 'low',
                    1 => 'out',
                ],
            ],
        ],
        'inventory.adjustments' => [
            'key' => 'inventory.adjustments',
            'query' => 'App\Application\Reporting\Queries\InventoryMovementReportQuery',
            'group' => 'inventory',
            'name_en' => 'Stock adjustments',
            'name_ar' => 'تسويات المخزون',
            'columns' => [
                0 => [
                    'key' => 'movement_date',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'warehouse_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'movement_type',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'quantity_delta_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'reason',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.inventory.view',
                1 => 'inventory.stock.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'product_id',
                2 => 'category_id',
                3 => 'warehouse_id',
                4 => 'status',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
                'status' => 'adjustment',
            ],
            'current' => false,
            'options' => [
                'status' => [
                    0 => 'adjustment',
                    1 => 'adjustment_increase',
                    2 => 'adjustment_decrease',
                    3 => 'damage',
                    4 => 'loss',
                    5 => 'damage_or_loss',
                ],
            ],
        ],
        'inventory.cost-history' => [
            'key' => 'inventory.cost-history',
            'query' => 'App\Application\Reporting\Queries\InventoryCostHistoryReportQuery',
            'group' => 'inventory',
            'name_en' => 'Product cost history',
            'name_ar' => 'سجل تكلفة المنتج',
            'columns' => [
                0 => [
                    'key' => 'movement_date',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'sku',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'unit_code',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'quantity_delta_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'unit_cost_base',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'value_delta_base',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'average_cost_after',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.inventory.view',
                1 => 'inventory.stock.view',
                2 => 'inventory.cost.view',
                3 => 'reports.cost.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'product_id',
                2 => 'category_id',
                3 => 'warehouse_id',
                4 => 'status',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'inventory.vendor-products' => [
            'key' => 'inventory.vendor-products',
            'query' => 'App\Application\Reporting\Queries\InventoryVendorProductsReportQuery',
            'group' => 'inventory',
            'name_en' => 'Inventory products by vendor',
            'name_ar' => 'منتجات المخزون حسب المورد',
            'columns' => [
                0 => [
                    'key' => 'vendor_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'sku',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'purchase_count',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'total_quantity_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'total_spend_base',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'last_purchase_date',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.inventory.view',
                1 => 'inventory.stock.view',
                2 => 'purchasing.purchase.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'product_id',
                2 => 'vendor_id',
                3 => 'category_id',
                4 => 'warehouse_id',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'inventory.transfers' => [
            'key' => 'inventory.transfers',
            'query' => 'App\Application\Reporting\Queries\TransferHistoryReportQuery',
            'group' => 'inventory',
            'name_en' => 'Stock transfers',
            'name_ar' => 'تحويلات المخزون',
            'columns' => [
                0 => [
                    'key' => 'transfer_date',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'sku',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'from_warehouse_name',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'to_warehouse_name',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'quantity_transferred',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'unit_cost_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.inventory.view',
                1 => 'inventory.stock.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'product_id',
                2 => 'warehouse_id',
                3 => 'page',
                4 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'inventory.expiry' => [
            'key' => 'inventory.expiry',
            'query' => 'App\Application\Reporting\Queries\ExpiryReportQuery',
            'group' => 'inventory',
            'name_en' => 'Expiry report',
            'name_ar' => 'تقرير الصلاحية',
            'columns' => [
                0 => [
                    'key' => 'product_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'sku',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'warehouse_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'lot_number',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'expiry_date',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'expiry_status',
                    'type' => 'text',
                ],
                6 => [
                    'key' => 'remaining_quantity',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.inventory.view',
                1 => 'inventory.stock.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'product_id',
                2 => 'category_id',
                3 => 'warehouse_id',
                4 => 'status',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'status' => [
                    0 => 'expired',
                    1 => 'soon',
                    2 => 'valid',
                    3 => 'unknown',
                ],
            ],
        ],
        'money.balances' => [
            'key' => 'money.balances',
            'query' => 'App\Application\Reporting\Queries\MoneyBalanceReportQuery',
            'group' => 'money',
            'name_en' => 'Cash and bank balances',
            'name_ar' => 'أرصدة النقد والبنوك',
            'columns' => [
                0 => [
                    'key' => 'name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'account_type',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'balance_currency',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'balance_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'is_active',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.money.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'money_account_id',
                2 => 'currency_code',
                3 => 'page',
                4 => 'per_page',
                5 => 'grouping',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => true,
            'options' => [
                'grouping' => [
                    0 => 'cash',
                    1 => 'bank',
                ],
            ],
        ],
        'money.movements' => [
            'key' => 'money.movements',
            'query' => 'App\Application\Reporting\Queries\MoneyMovementReportQuery',
            'group' => 'money',
            'name_en' => 'Cash and bank movements',
            'name_ar' => 'حركات النقد والبنوك',
            'columns' => [
                0 => [
                    'key' => 'posting_date',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'description',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'transaction_currency_code',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'transaction_amount',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'debit_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'credit_base',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'running_currency',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'running_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.money.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'money_account_id',
                2 => 'page',
                3 => 'per_page',
            ],
            'required' => [
                0 => 'money_account_id',
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'money.receipts' => [
            'key' => 'money.receipts',
            'query' => 'App\Application\Reporting\Queries\ReceiptRegisterReportQuery',
            'group' => 'money',
            'name_en' => 'Customer receipts',
            'name_ar' => 'مقبوضات العملاء',
            'columns' => [
                0 => [
                    'key' => 'payment_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'party_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'payment_method',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'amount',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'amount_base',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'status',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.money.view',
                1 => 'money.receipt.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'customer_id',
                2 => 'currency_code',
                3 => 'money_account_id',
                4 => 'status',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'money.vendor-payments' => [
            'key' => 'money.vendor-payments',
            'query' => 'App\Application\Reporting\Queries\MoneyVendorPaymentReportQuery',
            'group' => 'money',
            'name_en' => 'Vendor payments',
            'name_ar' => 'مدفوعات الموردين',
            'columns' => [
                0 => [
                    'key' => 'payment_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'party_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'payment_method',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'amount',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'amount_base',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'status',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.money.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'vendor_id',
                2 => 'currency_code',
                3 => 'money_account_id',
                4 => 'status',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'money.transfers' => [
            'key' => 'money.transfers',
            'query' => 'App\Application\Reporting\Queries\TransferRegisterReportQuery',
            'group' => 'money',
            'name_en' => 'Money transfers',
            'name_ar' => 'التحويلات المالية',
            'columns' => [
                0 => [
                    'key' => 'transfer_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'from_account_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'from_currency_code',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'from_amount',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'to_account_name',
                    'type' => 'text',
                ],
                6 => [
                    'key' => 'to_currency_code',
                    'type' => 'text',
                ],
                7 => [
                    'key' => 'to_amount',
                    'type' => 'decimal',
                ],
                8 => [
                    'key' => 'base_value_from',
                    'type' => 'decimal',
                ],
                9 => [
                    'key' => 'base_value_to',
                    'type' => 'decimal',
                ],
                10 => [
                    'key' => 'fx_gain_loss_base',
                    'type' => 'decimal',
                ],
                11 => [
                    'key' => 'status',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.money.view',
                1 => 'money.transfer.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'money_account_id',
                2 => 'currency_code',
                3 => 'status',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'money.checks' => [
            'key' => 'money.checks',
            'query' => 'App\Application\Reporting\Queries\CheckRegisterReportQuery',
            'group' => 'money',
            'name_en' => 'Check register',
            'name_ar' => 'سجل الشيكات',
            'columns' => [
                0 => [
                    'key' => 'check_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'direction',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'party_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'received_issued_date',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'due_date',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'status',
                    'type' => 'text',
                ],
                6 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                7 => [
                    'key' => 'amount',
                    'type' => 'decimal',
                ],
                8 => [
                    'key' => 'amount_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.money.view',
                1 => 'money.check.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'customer_id',
                2 => 'vendor_id',
                3 => 'currency_code',
                4 => 'status',
                5 => 'grouping',
                6 => 'page',
                7 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'incoming',
                    1 => 'outgoing',
                ],
                'status' => [
                    0 => 'received',
                    1 => 'issued',
                    2 => 'deposited',
                    3 => 'cleared',
                    4 => 'returned',
                    5 => 'cancelled',
                    6 => 'due',
                ],
            ],
        ],
        'expenses.summary' => [
            'key' => 'expenses.summary',
            'query' => 'App\Application\Reporting\Queries\ExpenseReportQuery',
            'group' => 'expenses',
            'name_en' => 'Expense summary',
            'name_ar' => 'ملخص المصروفات',
            'columns' => [
                0 => [
                    'key' => 'category_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'category_code',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'total_base',
                    'type' => 'decimal',
                ],
                3 => [
                    'key' => 'transaction_count',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.expenses.view',
                1 => 'money.expense.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'category_id',
                2 => 'vendor_id',
                3 => 'currency_code',
                4 => 'grouping',
                5 => 'page',
                6 => 'per_page',
                7 => 'status',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'category',
                    1 => 'currency',
                    2 => 'trend',
                    3 => 'period',
                    4 => 'type',
                    5 => 'fuel',
                    6 => 'delivery',
                    7 => 'transport',
                ],
                'status' => [
                    0 => 'operating',
                    1 => 'landed_cost',
                ],
            ],
        ],
        'expenses.detail' => [
            'key' => 'expenses.detail',
            'query' => 'App\Application\Reporting\Queries\ExpenseDetailReportQuery',
            'group' => 'expenses',
            'name_en' => 'Expense history',
            'name_ar' => 'سجل المصروفات',
            'columns' => [
                0 => [
                    'key' => 'expense_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'category_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'vendor_name',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'description',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'classification',
                    'type' => 'text',
                ],
                6 => [
                    'key' => 'payment_method',
                    'type' => 'text',
                ],
                7 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                8 => [
                    'key' => 'amount',
                    'type' => 'decimal',
                ],
                9 => [
                    'key' => 'base_amount',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.expenses.view',
                1 => 'money.expense.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'category_id',
                2 => 'vendor_id',
                3 => 'currency_code',
                4 => 'status',
                5 => 'page',
                6 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'payroll.summary' => [
            'key' => 'payroll.summary',
            'query' => 'App\Application\Reporting\Queries\PayrollSummaryReportQuery',
            'group' => 'payroll',
            'name_en' => 'Salary obligations',
            'name_ar' => 'التزامات الرواتب',
            'columns' => [
                0 => [
                    'key' => 'salary_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'employee_name',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'recognition_date',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'period',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'earned_salary',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'advance_applied',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'net_payable',
                    'type' => 'decimal',
                ],
                8 => [
                    'key' => 'paid_as_of_cutoff',
                    'type' => 'decimal',
                ],
                9 => [
                    'key' => 'remaining_unpaid',
                    'type' => 'decimal',
                ],
                10 => [
                    'key' => 'remaining_unpaid_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.payroll.view',
                1 => 'employees.view',
                2 => 'payroll.salary.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'employee_id',
                2 => 'currency_code',
                3 => 'status',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'status' => [
                    0 => 'unpaid',
                    1 => 'paid',
                ],
            ],
        ],
        'payroll.payments' => [
            'key' => 'payroll.payments',
            'query' => 'App\Application\Reporting\Queries\PayrollPaymentReportQuery',
            'group' => 'payroll',
            'name_en' => 'Salary payments',
            'name_ar' => 'مدفوعات الرواتب',
            'columns' => [
                0 => [
                    'key' => 'payment_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'employee_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'payment_method',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'amount',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'base_amount',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'status',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.payroll.view',
                1 => 'employees.view',
                2 => 'payroll.salary.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'employee_id',
                2 => 'currency_code',
                3 => 'status',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'payroll.advances' => [
            'key' => 'payroll.advances',
            'query' => 'App\Application\Reporting\Queries\PayrollAdvanceReportQuery',
            'group' => 'payroll',
            'name_en' => 'Employee advances',
            'name_ar' => 'سلف الموظفين',
            'columns' => [
                0 => [
                    'key' => 'advance_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'employee_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'amount',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'base_amount',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'remaining_amount',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'remaining_base_amount',
                    'type' => 'decimal',
                ],
                8 => [
                    'key' => 'status',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.payroll.view',
                1 => 'employees.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'employee_id',
                2 => 'currency_code',
                3 => 'status',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
                'status' => [
                    0 => 'available',
                    1 => 'consumed',
                ],
            ],
        ],
        'payroll.statement' => [
            'key' => 'payroll.statement',
            'query' => 'App\Application\Reporting\Queries\PayrollEmployeeStatementReportQuery',
            'group' => 'payroll',
            'name_en' => 'Employee financial statement',
            'name_ar' => 'كشف الموظف المالي',
            'columns' => [
                0 => [
                    'key' => 'date',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'source_type',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'reference_number',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'employee_name',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'amount',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'base_amount',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'event_type',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.payroll.view',
                1 => 'employees.view',
                2 => 'payroll.salary.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'employee_id',
                2 => 'currency_code',
                3 => 'page',
                4 => 'per_page',
            ],
            'required' => [
                0 => 'employee_id',
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'profit' => [
            'key' => 'profit',
            'query' => 'App\Application\Reporting\Queries\ProfitReportQuery',
            'group' => 'profit',
            'name_en' => 'Profit and loss',
            'name_ar' => 'الأرباح والخسائر',
            'columns' => [
                0 => [
                    'key' => 'name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'amount',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.profit.view',
                1 => 'reports.cost.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'page',
                2 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
        'money.cash' => [
            'key' => 'money.cash',
            'query' => 'App\Application\Reporting\Queries\MoneyBalanceReportQuery',
            'group' => 'money',
            'name_en' => 'Cash balances',
            'name_ar' => 'أرصدة النقد',
            'columns' => [
                0 => [
                    'key' => 'name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'account_type',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'balance_currency',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'balance_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'is_active',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.money.view',
                1 => 'money.cash.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'money_account_id',
                2 => 'currency_code',
                3 => 'page',
                4 => 'per_page',
                5 => 'grouping',
            ],
            'required' => [
            ],
            'defaults' => [
                'grouping' => 'cash',
            ],
            'current' => true,
            'options' => [
                'grouping' => [
                    0 => 'cash',
                    1 => 'bank',
                ],
            ],
        ],
        'money.bank' => [
            'key' => 'money.bank',
            'query' => 'App\Application\Reporting\Queries\MoneyBalanceReportQuery',
            'group' => 'money',
            'name_en' => 'Bank balances',
            'name_ar' => 'أرصدة البنوك',
            'columns' => [
                0 => [
                    'key' => 'name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'account_type',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'balance_currency',
                    'type' => 'decimal',
                ],
                4 => [
                    'key' => 'balance_base',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'is_active',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.money.view',
                1 => 'money.bank.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'money_account_id',
                2 => 'currency_code',
                3 => 'page',
                4 => 'per_page',
                5 => 'grouping',
            ],
            'required' => [
            ],
            'defaults' => [
                'grouping' => 'bank',
            ],
            'current' => true,
            'options' => [
                'grouping' => [
                    0 => 'cash',
                    1 => 'bank',
                ],
            ],
        ],
        'money.incoming-checks' => [
            'key' => 'money.incoming-checks',
            'query' => 'App\Application\Reporting\Queries\CheckRegisterReportQuery',
            'group' => 'money',
            'name_en' => 'Incoming checks',
            'name_ar' => 'الشيكات الواردة',
            'columns' => [
                0 => [
                    'key' => 'check_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'direction',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'party_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'received_issued_date',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'due_date',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'status',
                    'type' => 'text',
                ],
                6 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                7 => [
                    'key' => 'amount',
                    'type' => 'decimal',
                ],
                8 => [
                    'key' => 'amount_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.money.view',
                1 => 'money.check.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'customer_id',
                2 => 'vendor_id',
                3 => 'currency_code',
                4 => 'status',
                5 => 'grouping',
                6 => 'page',
                7 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
                'grouping' => 'incoming',
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'incoming',
                    1 => 'outgoing',
                ],
                'status' => [
                    0 => 'received',
                    1 => 'issued',
                    2 => 'deposited',
                    3 => 'cleared',
                    4 => 'returned',
                    5 => 'cancelled',
                    6 => 'due',
                ],
            ],
        ],
        'money.outgoing-checks' => [
            'key' => 'money.outgoing-checks',
            'query' => 'App\Application\Reporting\Queries\CheckRegisterReportQuery',
            'group' => 'money',
            'name_en' => 'Outgoing checks',
            'name_ar' => 'الشيكات الصادرة',
            'columns' => [
                0 => [
                    'key' => 'check_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'direction',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'party_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'received_issued_date',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'due_date',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'status',
                    'type' => 'text',
                ],
                6 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                7 => [
                    'key' => 'amount',
                    'type' => 'decimal',
                ],
                8 => [
                    'key' => 'amount_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.money.view',
                1 => 'money.check.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'customer_id',
                2 => 'vendor_id',
                3 => 'currency_code',
                4 => 'status',
                5 => 'grouping',
                6 => 'page',
                7 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
                'grouping' => 'outgoing',
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'incoming',
                    1 => 'outgoing',
                ],
                'status' => [
                    0 => 'received',
                    1 => 'issued',
                    2 => 'deposited',
                    3 => 'cleared',
                    4 => 'returned',
                    5 => 'cancelled',
                    6 => 'due',
                ],
            ],
        ],
        'money.due-checks' => [
            'key' => 'money.due-checks',
            'query' => 'App\Application\Reporting\Queries\CheckRegisterReportQuery',
            'group' => 'money',
            'name_en' => 'Checks due',
            'name_ar' => 'الشيكات المستحقة',
            'columns' => [
                0 => [
                    'key' => 'check_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'direction',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'party_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'received_issued_date',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'due_date',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'status',
                    'type' => 'text',
                ],
                6 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                7 => [
                    'key' => 'amount',
                    'type' => 'decimal',
                ],
                8 => [
                    'key' => 'amount_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.money.view',
                1 => 'money.check.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'customer_id',
                2 => 'vendor_id',
                3 => 'currency_code',
                4 => 'status',
                5 => 'grouping',
                6 => 'page',
                7 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
                'status' => 'due',
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'incoming',
                    1 => 'outgoing',
                ],
                'status' => [
                    0 => 'received',
                    1 => 'issued',
                    2 => 'deposited',
                    3 => 'cleared',
                    4 => 'returned',
                    5 => 'cancelled',
                    6 => 'due',
                ],
            ],
        ],
        'money.returned-checks' => [
            'key' => 'money.returned-checks',
            'query' => 'App\Application\Reporting\Queries\CheckRegisterReportQuery',
            'group' => 'money',
            'name_en' => 'Returned checks',
            'name_ar' => 'الشيكات المرتجعة',
            'columns' => [
                0 => [
                    'key' => 'check_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'direction',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'party_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'received_issued_date',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'due_date',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'status',
                    'type' => 'text',
                ],
                6 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                7 => [
                    'key' => 'amount',
                    'type' => 'decimal',
                ],
                8 => [
                    'key' => 'amount_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.money.view',
                1 => 'money.check.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'customer_id',
                2 => 'vendor_id',
                3 => 'currency_code',
                4 => 'status',
                5 => 'grouping',
                6 => 'page',
                7 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
                'status' => 'returned',
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'incoming',
                    1 => 'outgoing',
                ],
                'status' => [
                    0 => 'received',
                    1 => 'issued',
                    2 => 'deposited',
                    3 => 'cleared',
                    4 => 'returned',
                    5 => 'cancelled',
                    6 => 'due',
                ],
            ],
        ],
        'expenses.by-period' => [
            'key' => 'expenses.by-period',
            'query' => 'App\Application\Reporting\Queries\ExpenseReportQuery',
            'group' => 'expenses',
            'name_en' => 'Expenses by period',
            'name_ar' => 'المصروفات حسب الفترة',
            'columns' => [
                0 => [
                    'key' => 'period',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'total_base',
                    'type' => 'decimal',
                ],
                2 => [
                    'key' => 'transaction_count',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.expenses.view',
                1 => 'money.expense.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'category_id',
                2 => 'vendor_id',
                3 => 'currency_code',
                4 => 'grouping',
                5 => 'page',
                6 => 'per_page',
                7 => 'status',
            ],
            'required' => [
            ],
            'defaults' => [
                'grouping' => 'period',
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'category',
                    1 => 'currency',
                    2 => 'trend',
                    3 => 'period',
                    4 => 'type',
                    5 => 'fuel',
                    6 => 'delivery',
                    7 => 'transport',
                ],
                'status' => [
                    0 => 'operating',
                    1 => 'landed_cost',
                ],
            ],
        ],
        'expenses.by-category' => [
            'key' => 'expenses.by-category',
            'query' => 'App\Application\Reporting\Queries\ExpenseReportQuery',
            'group' => 'expenses',
            'name_en' => 'Expenses by category',
            'name_ar' => 'المصروفات حسب الفئة',
            'columns' => [
                0 => [
                    'key' => 'category_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'category_code',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'total_base',
                    'type' => 'decimal',
                ],
                3 => [
                    'key' => 'transaction_count',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.expenses.view',
                1 => 'money.expense.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'category_id',
                2 => 'vendor_id',
                3 => 'currency_code',
                4 => 'grouping',
                5 => 'page',
                6 => 'per_page',
                7 => 'status',
            ],
            'required' => [
            ],
            'defaults' => [
                'grouping' => 'category',
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'category',
                    1 => 'currency',
                    2 => 'trend',
                    3 => 'period',
                    4 => 'type',
                    5 => 'fuel',
                    6 => 'delivery',
                    7 => 'transport',
                ],
                'status' => [
                    0 => 'operating',
                    1 => 'landed_cost',
                ],
            ],
        ],
        'expenses.by-currency' => [
            'key' => 'expenses.by-currency',
            'query' => 'App\Application\Reporting\Queries\ExpenseReportQuery',
            'group' => 'expenses',
            'name_en' => 'Expenses by currency',
            'name_ar' => 'المصروفات حسب العملة',
            'columns' => [
                0 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'total_amount',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.expenses.view',
                1 => 'money.expense.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'category_id',
                2 => 'vendor_id',
                3 => 'currency_code',
                4 => 'grouping',
                5 => 'page',
                6 => 'per_page',
                7 => 'status',
            ],
            'required' => [
            ],
            'defaults' => [
                'grouping' => 'currency',
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'category',
                    1 => 'currency',
                    2 => 'trend',
                    3 => 'period',
                    4 => 'type',
                    5 => 'fuel',
                    6 => 'delivery',
                    7 => 'transport',
                ],
                'status' => [
                    0 => 'operating',
                    1 => 'landed_cost',
                ],
            ],
        ],
        'expenses.trend' => [
            'key' => 'expenses.trend',
            'query' => 'App\Application\Reporting\Queries\ExpenseReportQuery',
            'group' => 'expenses',
            'name_en' => 'Expense trend',
            'name_ar' => 'اتجاه المصروفات',
            'columns' => [
                0 => [
                    'key' => 'period',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'total_base',
                    'type' => 'decimal',
                ],
                2 => [
                    'key' => 'transaction_count',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.expenses.view',
                1 => 'money.expense.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'category_id',
                2 => 'vendor_id',
                3 => 'currency_code',
                4 => 'grouping',
                5 => 'page',
                6 => 'per_page',
                7 => 'status',
            ],
            'required' => [
            ],
            'defaults' => [
                'grouping' => 'trend',
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'category',
                    1 => 'currency',
                    2 => 'trend',
                    3 => 'period',
                    4 => 'type',
                    5 => 'fuel',
                    6 => 'delivery',
                    7 => 'transport',
                ],
                'status' => [
                    0 => 'operating',
                    1 => 'landed_cost',
                ],
            ],
        ],
        'expenses.fuel' => [
            'key' => 'expenses.fuel',
            'query' => 'App\Application\Reporting\Queries\ExpenseReportQuery',
            'group' => 'expenses',
            'name_en' => 'Fuel expenses',
            'name_ar' => 'مصروفات الوقود',
            'columns' => [
                0 => [
                    'key' => 'category_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'category_code',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'total_base',
                    'type' => 'decimal',
                ],
                3 => [
                    'key' => 'transaction_count',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.expenses.view',
                1 => 'money.expense.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'category_id',
                2 => 'vendor_id',
                3 => 'currency_code',
                4 => 'grouping',
                5 => 'page',
                6 => 'per_page',
                7 => 'status',
            ],
            'required' => [
            ],
            'defaults' => [
                'grouping' => 'fuel',
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'category',
                    1 => 'currency',
                    2 => 'trend',
                    3 => 'period',
                    4 => 'type',
                    5 => 'fuel',
                    6 => 'delivery',
                    7 => 'transport',
                ],
                'status' => [
                    0 => 'operating',
                    1 => 'landed_cost',
                ],
            ],
        ],
        'expenses.delivery' => [
            'key' => 'expenses.delivery',
            'query' => 'App\Application\Reporting\Queries\ExpenseReportQuery',
            'group' => 'expenses',
            'name_en' => 'Delivery expenses',
            'name_ar' => 'مصروفات التوصيل',
            'columns' => [
                0 => [
                    'key' => 'category_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'category_code',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'total_base',
                    'type' => 'decimal',
                ],
                3 => [
                    'key' => 'transaction_count',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.expenses.view',
                1 => 'money.expense.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'category_id',
                2 => 'vendor_id',
                3 => 'currency_code',
                4 => 'grouping',
                5 => 'page',
                6 => 'per_page',
                7 => 'status',
            ],
            'required' => [
            ],
            'defaults' => [
                'grouping' => 'delivery',
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'category',
                    1 => 'currency',
                    2 => 'trend',
                    3 => 'period',
                    4 => 'type',
                    5 => 'fuel',
                    6 => 'delivery',
                    7 => 'transport',
                ],
                'status' => [
                    0 => 'operating',
                    1 => 'landed_cost',
                ],
            ],
        ],
        'expenses.transport' => [
            'key' => 'expenses.transport',
            'query' => 'App\Application\Reporting\Queries\ExpenseReportQuery',
            'group' => 'expenses',
            'name_en' => 'Transport expenses',
            'name_ar' => 'مصروفات النقل',
            'columns' => [
                0 => [
                    'key' => 'category_name',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'category_code',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'total_base',
                    'type' => 'decimal',
                ],
                3 => [
                    'key' => 'transaction_count',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.expenses.view',
                1 => 'money.expense.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'category_id',
                2 => 'vendor_id',
                3 => 'currency_code',
                4 => 'grouping',
                5 => 'page',
                6 => 'per_page',
                7 => 'status',
            ],
            'required' => [
            ],
            'defaults' => [
                'grouping' => 'transport',
            ],
            'current' => false,
            'options' => [
                'grouping' => [
                    0 => 'category',
                    1 => 'currency',
                    2 => 'trend',
                    3 => 'period',
                    4 => 'type',
                    5 => 'fuel',
                    6 => 'delivery',
                    7 => 'transport',
                ],
                'status' => [
                    0 => 'operating',
                    1 => 'landed_cost',
                ],
            ],
        ],
        'payroll.unpaid' => [
            'key' => 'payroll.unpaid',
            'query' => 'App\Application\Reporting\Queries\PayrollSummaryReportQuery',
            'group' => 'payroll',
            'name_en' => 'Unpaid salaries',
            'name_ar' => 'الرواتب غير المدفوعة',
            'columns' => [
                0 => [
                    'key' => 'salary_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'employee_name',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'recognition_date',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'period',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'earned_salary',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'advance_applied',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'net_payable',
                    'type' => 'decimal',
                ],
                8 => [
                    'key' => 'paid_as_of_cutoff',
                    'type' => 'decimal',
                ],
                9 => [
                    'key' => 'remaining_unpaid',
                    'type' => 'decimal',
                ],
                10 => [
                    'key' => 'remaining_unpaid_base',
                    'type' => 'decimal',
                ],
            ],
            'permissions' => [
                0 => 'reports.payroll.view',
                1 => 'employees.view',
                2 => 'payroll.salary.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'employee_id',
                2 => 'currency_code',
                3 => 'status',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
                'status' => 'unpaid',
            ],
            'current' => false,
            'options' => [
                'status' => [
                    0 => 'unpaid',
                    1 => 'paid',
                ],
            ],
        ],
        'payroll.outstanding-advances' => [
            'key' => 'payroll.outstanding-advances',
            'query' => 'App\Application\Reporting\Queries\PayrollAdvanceReportQuery',
            'group' => 'payroll',
            'name_en' => 'Outstanding employee advances',
            'name_ar' => 'سلف الموظفين المتبقية',
            'columns' => [
                0 => [
                    'key' => 'advance_number',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'date',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'employee_name',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'amount',
                    'type' => 'decimal',
                ],
                5 => [
                    'key' => 'base_amount',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'remaining_amount',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'remaining_base_amount',
                    'type' => 'decimal',
                ],
                8 => [
                    'key' => 'status',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.payroll.view',
                1 => 'employees.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'employee_id',
                2 => 'currency_code',
                3 => 'status',
                4 => 'page',
                5 => 'per_page',
            ],
            'required' => [
            ],
            'defaults' => [
                'status' => 'available',
            ],
            'current' => false,
            'options' => [
                'status' => [
                    0 => 'available',
                    1 => 'consumed',
                ],
            ],
        ],
        'payroll.salary-history' => [
            'key' => 'payroll.salary-history',
            'query' => 'App\Application\Reporting\Queries\PayrollEmployeeStatementReportQuery',
            'group' => 'payroll',
            'name_en' => 'Employee salary history',
            'name_ar' => 'سجل رواتب الموظف',
            'columns' => [
                0 => [
                    'key' => 'date',
                    'type' => 'text',
                ],
                1 => [
                    'key' => 'source_type',
                    'type' => 'text',
                ],
                2 => [
                    'key' => 'reference_number',
                    'type' => 'text',
                ],
                3 => [
                    'key' => 'employee_name',
                    'type' => 'text',
                ],
                4 => [
                    'key' => 'currency_code',
                    'type' => 'text',
                ],
                5 => [
                    'key' => 'amount',
                    'type' => 'decimal',
                ],
                6 => [
                    'key' => 'base_amount',
                    'type' => 'decimal',
                ],
                7 => [
                    'key' => 'event_type',
                    'type' => 'text',
                ],
            ],
            'permissions' => [
                0 => 'reports.payroll.view',
                1 => 'employees.view',
                2 => 'payroll.salary.view',
            ],
            'filters' => [
                0 => 'period',
                1 => 'employee_id',
                2 => 'currency_code',
                3 => 'page',
                4 => 'per_page',
            ],
            'required' => [
                0 => 'employee_id',
            ],
            'defaults' => [
            ],
            'current' => false,
            'options' => [
            ],
        ],
    ];

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return self::DEFINITIONS;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /** @return array<string, mixed> */
    public function definition(string $key): array
    {
        if (! isset(self::DEFINITIONS[$key])) {
            abort(404);
        }

        return self::DEFINITIONS[$key];
    }

    /** @return array<string, array<string, mixed>> */
    public function visible(Company $company): array
    {
        return array_filter(self::DEFINITIONS, fn (array $definition): bool => $this->allows($company, $definition['key']));
    }

    public function allows(Company $company, string $key): bool
    {
        $definition = $this->definition($key);
        $guard = app(ReportingGuard::class);
        if (! $guard->allows($company, $definition['permissions'])) {
            return false;
        }
        if ($definition['query'] === 'App\\Application\\Reporting\\Queries\\MoneyVendorPaymentReportQuery' && ! app(VendorFinancialRead::class)->allows((int) $company->id)) {
            return false;
        }
        if (in_array($definition['key'], ['payroll.advances', 'payroll.outstanding-advances'], true) && ! app(Phase7FinancialRead::class)->advance((int) $company->id)) {
            return false;
        }
        if (in_array($definition['key'], ['money.balances', 'money.movements'], true)) {
            return $guard->allows($company, 'money.cash.view') || $guard->allows($company, 'money.bank.view');
        }

        return true;
    }

    /** @param array<string, mixed> $filters */
    public function execute(Company $company, string $key, array $filters): ReportResult
    {
        if (! $this->allows($company, $key)) {
            throw new AuthorizationException('Report source access is required.');
        }
        $definition = $this->definition($key);
        foreach (array_keys($filters) as $field) {
            if (! in_array($field, [...$definition['filters'], 'preset', 'from', 'to', 'start_date', 'end_date'], true)) {
                throw new InvalidArgumentException('Unsupported report filter.');
            }
        }
        $filters = array_replace($definition['defaults'], $filters);
        foreach ($definition['required'] as $field) {
            if (! isset($filters[$field]) || $filters[$field] === '') {
                throw new InvalidArgumentException('A required report selection is missing.');
            }
        }
        $query = app($definition['query']);
        $callback = [$query, 'execute'];
        if (! is_callable($callback)) {
            throw new \LogicException('Invalid report query registration.');
        }
        $result = $callback($company, $filters);
        if (! $result instanceof ReportResult) {
            throw new \LogicException('Invalid report result.');
        }

        return $result;
    }

    public function title(string $key): string
    {
        $definition = $this->definition($key);

        return (string) $definition[app()->getLocale() === 'ar' ? 'name_ar' : 'name_en'];
    }
}
