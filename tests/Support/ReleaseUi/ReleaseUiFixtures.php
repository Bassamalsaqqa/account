<?php

declare(strict_types=1);

namespace Tests\Support\ReleaseUi;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Money\PostMoneyTransferAction;
use App\Actions\Payroll\CreateEmployeeAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Payroll\PostSalaryPaymentAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Models\Company;
use App\Models\CompanyLanguage;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MoneyAccount;
use App\Models\MoneyTransfer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SalaryEntry;
use App\Models\SalaryPayment;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class ReleaseUiFixtures
{
    /**
     * Seed reproducible dummy UI fixtures and dev tester user.
     * Credentials are written strictly to $credentialFile with 0600 mode.
     *
     * @return array<string, mixed>
     */
    public static function seed(string $credentialFile): array
    {
        // 1. Generate dev user credentials
        $rawPassword = 'AgyRel!'.bin2hex(random_bytes(10));
        $email = 'dev_ui_tester_'.bin2hex(random_bytes(4)).'@example.test';

        $credData = json_encode([
            'email' => $email,
            'password' => $rawPassword,
            'created_at' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (file_put_contents($credentialFile, $credData) === false) {
            throw new RuntimeException("Failed to write dev credentials to {$credentialFile}");
        }
        chmod($credentialFile, 0600);

        // 2. Create dev user with Arabic initial locale
        $user = new User([
            'name' => 'UI Release Tester',
            'email' => $email,
            'password' => Hash::make($rawPassword),
            'locale' => 'ar',
        ]);
        $user->email_verified_at = now()->toDateTimeString();
        $user->save();

        // 3. Clear any ambient context before company creation
        $context = app(CompanyContext::class);
        $context->clear();

        // 4. Create isolated Company
        $company = app(CreateCompanyAction::class)->execute($user, [
            'name_ar' => 'شركة الأفق للاختبار',
            'name_en' => 'Horizon Test Trading',
            'base_currency_code' => 'ILS',
        ]);

        // 5. Establish context for current actor
        $context->setCompany($company, $user);
        setPermissionsTeamId($company->id);
        auth()->setUser($user);
        $user->last_active_company_id = $company->id;
        $user->save();

        // Ensure both 'ar' and 'en' locales are explicitly enabled
        CompanyLanguage::updateOrCreate(
            ['company_id' => $company->id, 'locale' => 'ar'],
            ['enabled' => true]
        );
        CompanyLanguage::updateOrCreate(
            ['company_id' => $company->id, 'locale' => 'en'],
            ['enabled' => true]
        );

        // 6. Find default warehouse and piece unit
        $warehouse = Warehouse::where('company_id', $company->id)->firstOrFail();
        $pieceUnit = Unit::where('company_id', $company->id)->where('code', 'piece')->firstOrFail();

        // 7. Seed stocked products
        $catalogService = app(ProductCatalogService::class);
        $product = $catalogService->createProduct($company, [
            'name_ar' => 'زيت زيتون ممتاز',
            'name_en' => 'Extra Virgin Olive Oil',
            'sku' => 'PRD-OIL-01',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $pieceUnit->id,
        ], $user->id);
        $productUnit = ProductUnit::where('product_id', $product->id)->firstOrFail();

        // 8. Seed Vendor
        $vendorService = app(VendorCatalogService::class);
        $vendor = $vendorService->save($company, $user, [
            'name_ar' => 'مورد التوريدات الشاملة',
            'name_en' => 'Global Supplies Vendor',
        ]);

        // 9. Seed Customer
        $customer = Customer::create([
            'company_id' => $company->id,
            'name_ar' => 'شركة التجارة الحديثة',
            'name_en' => 'Modern Trade Customer',
            'preferred_locale' => 'ar',
            'created_by' => $user->id,
        ]);

        // 10. Cash money account and Bank money account
        $cashAccount = MoneyAccount::where('company_id', $company->id)->where('currency_code', 'ILS')->where('account_type', MoneyAccount::TYPE_CASH)->firstOrFail();
        $bankAccount = app(CreateMoneyAccountAction::class)->execute($company, $user, [
            'account_type' => MoneyAccount::TYPE_BANK,
            'name_ar' => 'بنك الأفق التجاري',
            'name_en' => 'Horizon Commercial Bank',
            'currency_code' => 'ILS',
            'bank_name' => 'Bank of Palestine',
        ]);

        // 11. Post Purchase (20 units @ 50 ILS)
        $purchaseDraft = app(CreatePurchaseDraftAction::class)->execute($company, $user, [
            'vendor_id' => $vendor->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'vendor_invoice_number' => 'PUR-UI-001',
            'purchase_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $productUnit->id,
                    'item_description' => 'زيت زيتون ممتاز - عبوة 1 لتر',
                    'quantity' => '20',
                    'unit_cost' => '50.00',
                ],
            ],
        ]);
        /** @var Purchase $purchase */
        $purchase = app(PostPurchaseAction::class)->execute($purchaseDraft, $user);

        // 12. Vendor Payment against purchase (for standalone reversal test)
        /** @var VendorPayment $vendorPayment */
        $vendorPayment = app(PostVendorPaymentAction::class)->execute($company, $user, [
            'vendor_id' => $vendor->id,
            'money_account_id' => $cashAccount->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'amount' => '200.00',
            'exchange_rate' => '1',
            'idempotency_key' => 'vpay_'.bin2hex(random_bytes(6)),
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '200.00'],
            ],
        ]);

        // 13. Purchase Return (2 units)
        $purchaseReturnDraft = app(CreatePurchaseReturnDraftAction::class)->execute($company, $user, [
            'purchase_id' => $purchase->id,
            'return_date' => now()->toDateString(),
            'reason' => 'عبوات معيبة',
            'lines' => [
                [
                    'purchase_line_id' => $purchase->lines->first()->id,
                    'quantity' => '2',
                ],
            ],
        ]);
        /** @var PurchaseReturn $purchaseReturn */
        $purchaseReturn = app(PostPurchaseReturnAction::class)->execute($purchaseReturnDraft, $user);

        // 14. Post Sales Invoice (5 units @ 80 ILS)
        $invoiceDraft = app(CreateSalesInvoiceDraftAction::class)->execute($company, $user, [
            'customer_id' => $customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'issue_date' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $product->id,
                    'item_description' => 'زيت زيتون ممتاز',
                    'quantity' => '5',
                    'unit_price' => '80.00',
                    'warehouse_id' => $warehouse->id,
                ],
            ],
        ]);
        /** @var SalesInvoice $salesInvoice */
        $salesInvoice = app(PostSalesInvoiceAction::class)->execute($invoiceDraft, $user);

        // 15. Customer Payment against invoice (for standalone reversal test)
        /** @var CustomerPayment $customerPayment */
        $customerPayment = app(PostCustomerPaymentAction::class)->execute($company, $user, [
            'customer_id' => $customer->id,
            'money_account_id' => $cashAccount->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'amount' => '150.00',
            'exchange_rate' => '1',
            'idempotency_key' => 'cpay_'.bin2hex(random_bytes(6)),
            'allocations' => [
                ['sales_invoice_id' => $salesInvoice->id, 'allocated_amount' => '150.00'],
            ],
        ]);

        // 16. Sales Return (1 unit)
        $salesReturnDraft = app(CreateSalesReturnDraftAction::class)->execute($company, $user, [
            'sales_invoice_id' => $salesInvoice->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'طلب العميل الإرجاع',
            'lines' => [
                [
                    'sales_invoice_line_id' => $salesInvoice->lines->first()->id,
                    'quantity' => '1',
                ],
            ],
        ]);
        /** @var SalesReturn $salesReturn */
        $salesReturn = app(PostSalesReturnAction::class)->execute($salesReturnDraft, $user);

        // 17. Money Transfer between Cash and Bank (for standalone reversal test)
        /** @var MoneyTransfer $transfer */
        $transfer = app(PostMoneyTransferAction::class)->execute($company, $user, [
            'from_money_account_id' => $cashAccount->id,
            'to_money_account_id' => $bankAccount->id,
            'transfer_date' => now()->toDateString(),
            'from_amount' => '100.00',
            'to_amount' => '100.00',
            'from_exchange_rate' => '1',
            'to_exchange_rate' => '1',
            'notes' => 'تحويل سيولة إلى الحساب البنكي',
            'idempotency_key' => 'trf_'.bin2hex(random_bytes(6)),
        ]);

        // 18. Operating Expense (Electricity) (for standalone reversal test)
        $expenseCategory = ExpenseCategory::where('company_id', $company->id)->where('code', 'electricity')->firstOrFail();
        /** @var Expense $expense */
        $expense = app(PostExpenseAction::class)->execute($company, $user, [
            'category_id' => $expenseCategory->id,
            'expense_date' => now()->toDateString(),
            'classification' => 'operating',
            'description' => 'فاتورة الكهرباء للمكتب الرئيسي',
            'currency_code' => 'ILS',
            'amount' => '75.00',
            'exchange_rate' => '1',
            'payment_method' => 'cash',
            'money_account_id' => $cashAccount->id,
            'idempotency_key' => 'exp_'.bin2hex(random_bytes(6)),
        ]);

        // 19. Employee & Payroll (Advance, Salary Entry, Salary Payment for standalone reversal test)
        /** @var Employee $employee */
        $employee = app(CreateEmployeeAction::class)->execute($company, $user, [
            'code' => 'EMP-001',
            'name' => 'خالد عبد الله',
            'job_title' => 'محاسب عام',
            'hire_date' => '2026-01-01',
            'default_salary' => '3000.00',
            'salary_currency_code' => 'ILS',
            'active' => true,
        ]);

        /** @var EmployeeAdvance $advance */
        $advance = app(PostEmployeeAdvanceAction::class)->execute($company, $user, [
            'employee_id' => $employee->id,
            'advance_date' => now()->toDateString(),
            'currency_code' => 'ILS',
            'amount' => '300.00',
            'exchange_rate' => '1',
            'payment_method' => 'cash',
            'money_account_id' => $cashAccount->id,
            'idempotency_key' => 'adv_'.bin2hex(random_bytes(6)),
        ]);

        /** @var SalaryEntry $salaryEntry */
        $salaryEntry = app(PostSalaryEntryAction::class)->execute($company, $user, [
            'employee_id' => $employee->id,
            'recognition_date' => now()->toDateString(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'base_salary' => '3000.00',
            'advances' => [
                ['advance_id' => $advance->id, 'allocated_amount' => '300.00'],
            ],
            'idempotency_key' => 'sal_'.bin2hex(random_bytes(6)),
        ]);

        /** @var SalaryPayment $salaryPayment */
        $salaryPayment = app(PostSalaryPaymentAction::class)->execute($company, $user, [
            'employee_id' => $employee->id,
            'payment_date' => now()->toDateString(),
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'amount' => '2700.00',
            'payment_method' => 'cash',
            'money_account_id' => $cashAccount->id,
            'allocations' => [
                ['salary_entry_id' => $salaryEntry->id, 'allocated_amount' => '2700.00'],
            ],
            'idempotency_key' => 'spay_'.bin2hex(random_bytes(6)),
        ]);

        return [
            'user_email' => $email,
            'company_id' => $company->id,
            'purchase' => [
                'id' => $purchase->id,
                'public_id' => $purchase->public_id,
                'number' => $purchase->vendor_invoice_number,
            ],
            'purchase_return' => [
                'id' => $purchaseReturn->id,
                'public_id' => $purchaseReturn->public_id,
            ],
            'vendor_payment' => [
                'id' => $vendorPayment->id,
                'public_id' => $vendorPayment->public_id,
            ],
            'sales_invoice' => [
                'id' => $salesInvoice->id,
                'public_id' => $salesInvoice->public_id,
                'number' => $salesInvoice->invoice_number,
            ],
            'customer_payment' => [
                'id' => $customerPayment->id,
                'public_id' => $customerPayment->public_id,
            ],
            'sales_return' => [
                'id' => $salesReturn->id,
                'public_id' => $salesReturn->public_id,
            ],
            'transfer' => [
                'id' => $transfer->id,
                'public_id' => $transfer->public_id,
            ],
            'expense' => [
                'id' => $expense->id,
                'public_id' => $expense->public_id,
            ],
            'employee' => [
                'id' => $employee->id,
                'public_id' => $employee->public_id,
                'code' => $employee->code,
            ],
            'payroll_advance' => [
                'id' => $advance->id,
                'advance_number' => $advance->advance_number,
            ],
            'payroll_salary_entry' => [
                'id' => $salaryEntry->id,
                'salary_number' => $salaryEntry->salary_number,
            ],
            'payroll_salary_payment' => [
                'id' => $salaryPayment->id,
                'payment_number' => $salaryPayment->payment_number,
            ],
        ];
    }
}
