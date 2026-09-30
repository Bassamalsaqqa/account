<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Catalog;

use App\Models\LedgerAccount;

final class SystemAccountsCatalog
{
    /**
     * @return list<SystemAccountDefinition>
     */
    public static function all(): array
    {
        return [
            // 1. Assets (1xxx) - Normal Balance Debit
            new SystemAccountDefinition(
                systemKey: 'cash_control',
                code: '1101',
                nameAr: 'الصندوق الرئيسي (النقدية)',
                nameEn: 'Main Cash',
                accountType: LedgerAccount::TYPE_ASSET,
                normalBalance: LedgerAccount::BALANCE_DEBIT,
                isControl: true,
            ),
            new SystemAccountDefinition(
                systemKey: 'bank_control',
                code: '1102',
                nameAr: 'حسابات البنوك',
                nameEn: 'Bank Accounts',
                accountType: LedgerAccount::TYPE_ASSET,
                normalBalance: LedgerAccount::BALANCE_DEBIT,
                isControl: true,
            ),
            new SystemAccountDefinition(
                systemKey: 'checks_in_hand',
                code: '1103',
                nameAr: 'شيكات برسم التحصيل (في الصندوق)',
                nameEn: 'Checks in Hand',
                accountType: LedgerAccount::TYPE_ASSET,
                normalBalance: LedgerAccount::BALANCE_DEBIT,
                isControl: true,
            ),
            new SystemAccountDefinition(
                systemKey: 'accounts_receivable',
                code: '1201',
                nameAr: 'الذمم المدينة (العملاء)',
                nameEn: 'Accounts Receivable',
                accountType: LedgerAccount::TYPE_ASSET,
                normalBalance: LedgerAccount::BALANCE_DEBIT,
                isControl: true,
            ),
            new SystemAccountDefinition(
                systemKey: 'inventory',
                code: '1301',
                nameAr: 'مخزون البضائع',
                nameEn: 'Merchandise Inventory',
                accountType: LedgerAccount::TYPE_ASSET,
                normalBalance: LedgerAccount::BALANCE_DEBIT,
                isControl: true,
            ),
            new SystemAccountDefinition(
                systemKey: 'tax_input',
                code: '1401',
                nameAr: 'ضريبة القيمة المضافة المدخلات (مشتريات)',
                nameEn: 'Input VAT',
                accountType: LedgerAccount::TYPE_ASSET,
                normalBalance: LedgerAccount::BALANCE_DEBIT,
                isControl: false,
            ),

            // 2. Liabilities (2xxx) - Normal Balance Credit
            new SystemAccountDefinition(
                systemKey: 'accounts_payable',
                code: '2101',
                nameAr: 'الذمم الدائنة (الموردين)',
                nameEn: 'Accounts Payable',
                accountType: LedgerAccount::TYPE_LIABILITY,
                normalBalance: LedgerAccount::BALANCE_CREDIT,
                isControl: true,
            ),
            new SystemAccountDefinition(
                systemKey: 'checks_issued',
                code: '2102',
                nameAr: 'شيكات صادرة مؤجلة',
                nameEn: 'Checks Issued',
                accountType: LedgerAccount::TYPE_LIABILITY,
                normalBalance: LedgerAccount::BALANCE_CREDIT,
                isControl: true,
            ),
            new SystemAccountDefinition(
                systemKey: 'salary_payable',
                code: '2103',
                nameAr: 'رواتب وأجور مستحقة',
                nameEn: 'Salaries Payable',
                accountType: LedgerAccount::TYPE_LIABILITY,
                normalBalance: LedgerAccount::BALANCE_CREDIT,
                isControl: false,
            ),
            new SystemAccountDefinition(
                systemKey: 'tax_output',
                code: '2201',
                nameAr: 'ضريبة القيمة المضافة المخرجات (مبيعات)',
                nameEn: 'Output VAT',
                accountType: LedgerAccount::TYPE_LIABILITY,
                normalBalance: LedgerAccount::BALANCE_CREDIT,
                isControl: false,
            ),

            // 3. Equity (3xxx) - Normal Balance Credit
            new SystemAccountDefinition(
                systemKey: 'opening_balance_equity',
                code: '3101',
                nameAr: 'أرصدة افتتاحية رأس المال',
                nameEn: 'Opening Balance Equity',
                accountType: LedgerAccount::TYPE_EQUITY,
                normalBalance: LedgerAccount::BALANCE_CREDIT,
                isControl: false,
            ),

            // 4. Revenue (4xxx) - Normal Balance Credit
            new SystemAccountDefinition(
                systemKey: 'sales_revenue',
                code: '4101',
                nameAr: 'إيرادات المبيعات',
                nameEn: 'Sales Revenue',
                accountType: LedgerAccount::TYPE_REVENUE,
                normalBalance: LedgerAccount::BALANCE_CREDIT,
                isControl: false,
            ),
            new SystemAccountDefinition(
                systemKey: 'sales_returns',
                code: '4102',
                nameAr: 'مردودات ومسموحات المبيعات',
                nameEn: 'Sales Returns and Allowances',
                accountType: LedgerAccount::TYPE_REVENUE,
                normalBalance: LedgerAccount::BALANCE_DEBIT,
                isControl: false,
            ),
            new SystemAccountDefinition(
                systemKey: 'fx_gain',
                code: '4201',
                nameAr: 'أرباح فروق أسعار الصرف',
                nameEn: 'Foreign Exchange Gains',
                accountType: LedgerAccount::TYPE_REVENUE,
                normalBalance: LedgerAccount::BALANCE_CREDIT,
                isControl: false,
            ),

            // 5. Expenses (5xxx) - Normal Balance Debit
            new SystemAccountDefinition(
                systemKey: 'cogs',
                code: '5101',
                nameAr: 'تكلفة البضاعة المباعة',
                nameEn: 'Cost of Goods Sold',
                accountType: LedgerAccount::TYPE_EXPENSE,
                normalBalance: LedgerAccount::BALANCE_DEBIT,
                isControl: false,
            ),
            new SystemAccountDefinition(
                systemKey: 'operating_expense_parent',
                code: '5201',
                nameAr: 'مصاريف تشغيلية وإدارية',
                nameEn: 'Operating & Administrative Expenses',
                accountType: LedgerAccount::TYPE_EXPENSE,
                normalBalance: LedgerAccount::BALANCE_DEBIT,
                isControl: true,
            ),
            new SystemAccountDefinition(
                systemKey: 'salary_expense',
                code: '5202',
                nameAr: 'مصاريف الرواتب والأجور',
                nameEn: 'Salaries Expense',
                accountType: LedgerAccount::TYPE_EXPENSE,
                normalBalance: LedgerAccount::BALANCE_DEBIT,
                isControl: false,
                parentSystemKey: 'operating_expense_parent',
            ),
            new SystemAccountDefinition(
                systemKey: 'inventory_loss',
                code: '5301',
                nameAr: 'خسائر فروقات الجرد والتلف',
                nameEn: 'Inventory Discrepancy & Shrinkage Loss',
                accountType: LedgerAccount::TYPE_EXPENSE,
                normalBalance: LedgerAccount::BALANCE_DEBIT,
                isControl: false,
            ),
            new SystemAccountDefinition(
                systemKey: 'expiry_loss',
                code: '5302',
                nameAr: 'خسائر انتهاء الصلاحية',
                nameEn: 'Expired Goods Loss',
                accountType: LedgerAccount::TYPE_EXPENSE,
                normalBalance: LedgerAccount::BALANCE_DEBIT,
                isControl: false,
            ),
            new SystemAccountDefinition(
                systemKey: 'fx_loss',
                code: '5401',
                nameAr: 'خسائر فروق أسعار الصرف',
                nameEn: 'Foreign Exchange Losses',
                accountType: LedgerAccount::TYPE_EXPENSE,
                normalBalance: LedgerAccount::BALANCE_DEBIT,
                isControl: false,
            ),
        ];
    }
}
