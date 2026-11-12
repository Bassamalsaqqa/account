<?php

declare(strict_types=1);

namespace Tests\Support\Pilot;

use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyMobileSetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\LedgerAccount;
use App\Models\MobileDevice;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\RecoveryCode;

final class PilotFixtures
{
    /**
     * Seeds an ephemeral two-tenant dataset with staff, customers, products, units, cash accounts,
     * and scoped devices. Writes private synthetic credentials to $credentialPath with 0600 mode.
     *
     * @return array<string, mixed> Public scenario and scope metadata (NO SECRETS EMBEDDED)
     */
    public static function seed(string $credentialPath): array
    {
        // Ensure test currencies exist in global master
        $ils = Currency::firstOrCreate(
            ['code' => 'ILS'],
            ['name' => 'Israeli New Shekel', 'minor_units' => 2, 'symbol' => '₪', 'active' => true]
        );
        $usd = Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'minor_units' => 2, 'symbol' => '$', 'active' => true]
        );
        $jod = Currency::firstOrCreate(
            ['code' => 'JOD'],
            ['name' => 'Jordanian Dinar', 'minor_units' => 3, 'symbol' => 'JD', 'active' => true]
        );

        $roleService = app(CompanyRoleService::class);
        $roleService->ensurePermissionsExist();

        // Generate temporary synthetic passwords
        $passwords = [
            'staff1' => 'PilotPass1_'.bin2hex(random_bytes(8)).'!',
            'staff2' => 'PilotPass2_'.bin2hex(random_bytes(8)).'!',
            'staff3' => 'PilotPass3_'.bin2hex(random_bytes(8)).'!',
            'staff4' => 'PilotPass4_'.bin2hex(random_bytes(8)).'!',
        ];

        // 1. Create Base Companies
        /** @var Company $companyA */
        $companyA = Company::create([
            'name_ar' => 'شركة الأمل للتجزئة',
            'name_en' => 'Al-Amal Retail Company',
            'base_currency_code' => 'ILS',
            'status' => 'active',
        ]);
        $roleService->seedCompanyRoles($companyA);

        /** @var Company $companyB */
        $companyB = Company::create([
            'name_ar' => 'شركة القدس للتجارة',
            'name_en' => 'Al-Quds Trading',
            'base_currency_code' => 'USD',
            'status' => 'active',
        ]);
        $roleService->seedCompanyRoles($companyB);

        // 2. Create Users & Initial Memberships
        // User 1: Primary staff in Company A (Cashier)
        $user1 = User::create([
            'name' => 'Tariq Cashier',
            'email' => 'tariq.cashier@pilot.local',
            'password' => Hash::make($passwords['staff1']),
            'email_verified_at' => now(),
        ]);
        $user1->companies()->attach($companyA->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($companyA->id);
        $user1->assignRole('Cashier');

        // User 2: MFA Staff in Company A
        $totpProvider = app(TwoFactorAuthenticationProvider::class);
        $mfaSecret = $totpProvider->generateSecretKey();
        $recoveryCodes = [
            RecoveryCode::generate(),
            RecoveryCode::generate(),
            RecoveryCode::generate(),
        ];
        $user2 = User::create([
            'name' => 'Mona MFA Staff',
            'email' => 'mona.mfa@pilot.local',
            'password' => Hash::make($passwords['staff2']),
            'email_verified_at' => now(),
        ]);
        $user2->forceFill([
            'two_factor_secret' => encrypt($mfaSecret),
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $user2->companies()->attach($companyA->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($companyA->id);
        $user2->assignRole('Cashier');

        // User 3: Tenant 2 ONLY Staff (Non-member of Company A)
        $user3 = User::create([
            'name' => 'Zaid Tenant2 Only',
            'email' => 'zaid.tenant2@pilot.local',
            'password' => Hash::make($passwords['staff3']),
            'email_verified_at' => now(),
        ]);
        $user3->companies()->attach($companyB->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($companyB->id);
        $user3->assignRole('Cashier');

        // User 4: Company A staff with restricted permissions (lacks customers.view)
        $user4 = User::create([
            'name' => 'Rami NoPerm',
            'email' => 'rami.noperm@pilot.local',
            'password' => Hash::make($passwords['staff4']),
            'email_verified_at' => now(),
        ]);
        $user4->companies()->attach($companyA->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($companyA->id);
        $user4->givePermissionTo('sales.invoice.view');

        $context = app(CompanyContext::class);

        // -------------------------------------------------------------
        // Tenant 1 Context: Al-Amal Retail Company (ILS base)
        // -------------------------------------------------------------
        $context->setCompany($companyA, $user1);
        auth()->login($user1);

        CompanyCurrency::firstOrCreate(
            ['company_id' => $companyA->id, 'currency_code' => 'ILS'],
            ['exchange_rate_to_base' => '1.000000', 'enabled' => true]
        );
        CompanyCurrency::firstOrCreate(
            ['company_id' => $companyA->id, 'currency_code' => 'USD'],
            ['exchange_rate_to_base' => '3.700000', 'enabled' => true]
        );

        $warehouseA = Warehouse::create([
            'company_id' => $companyA->id,
            'code' => 'WH-A-MAIN',
            'name_ar' => 'المستودع الرئيسي أ',
            'name_en' => 'Main Warehouse A',
            'active' => true,
            'is_default' => true,
            'created_by' => $user1->id,
        ]);

        $ledgerA = LedgerAccount::create([
            'company_id' => $companyA->id,
            'code' => '1010',
            'name_ar' => 'النقدية بالصندوق',
            'name_en' => 'Cash on Hand',
            'account_type' => LedgerAccount::TYPE_ASSET,
            'normal_balance' => LedgerAccount::BALANCE_DEBIT,
            'is_control' => false,
            'is_system' => false,
            'active' => true,
        ]);

        $cashAccountA = MoneyAccount::create([
            'company_id' => $companyA->id,
            'account_type' => MoneyAccount::TYPE_CASH,
            'name_ar' => 'صندوق نقاط البيع - شيكل',
            'name_en' => 'POS Cash Register (ILS)',
            'currency_code' => 'ILS',
            'ledger_account_id' => $ledgerA->id,
            'is_active' => true,
            'created_by' => $user1->id,
        ]);

        $walkInCustA = Customer::create([
            'company_id' => $companyA->id,
            'code' => 'CUST-WALK-IN',
            'name_ar' => 'عميل نقدي عام',
            'name_en' => 'Walk-in Customer A',
            'status' => 'active',
            'created_by' => $user1->id,
        ]);

        $regularCustA = Customer::create([
            'company_id' => $companyA->id,
            'code' => 'CUST-A01',
            'name_ar' => 'أحمد خليل',
            'name_en' => 'Ahmad Khalil',
            'phone' => '+970599112233',
            'status' => 'active',
            'created_by' => $user1->id,
        ]);

        $regularCustA2 = Customer::create([
            'company_id' => $companyA->id,
            'code' => 'CUST-A02',
            'name_ar' => 'سارة حسن',
            'name_en' => 'Sara Hassan',
            'phone' => '+970599223344',
            'status' => 'active',
            'created_by' => $user1->id,
        ]);

        $inactiveCustA = Customer::create([
            'company_id' => $companyA->id,
            'code' => 'CUST-A99',
            'name_ar' => 'عميل موقف',
            'name_en' => 'Suspended Customer A',
            'status' => 'inactive',
            'created_by' => $user1->id,
        ]);

        $unitPcsA = Unit::create([
            'company_id' => $companyA->id,
            'code' => 'PCS',
            'name_ar' => 'قطعة',
            'name_en' => 'Piece',
            'symbol_en' => 'pc',
            'allows_fraction' => false,
            'decimal_places' => 0,
            'active' => true,
        ]);

        $unitKgA = Unit::create([
            'company_id' => $companyA->id,
            'code' => 'KG',
            'name_ar' => 'كيلوغرام',
            'name_en' => 'Kilogram',
            'symbol_en' => 'kg',
            'allows_fraction' => true,
            'decimal_places' => 3,
            'active' => true,
        ]);

        $taxVat16 = TaxRate::create([
            'company_id' => $companyA->id,
            'code' => 'VAT16',
            'name_ar' => 'ضريبة القيمة المضافة 16%',
            'name_en' => 'Standard VAT 16%',
            'rate' => '16.000000',
            'calculation' => 'exclusive',
            'active' => true,
            'created_by' => $user1->id,
        ]);

        CompanyMobileSetting::create([
            'company_id' => $companyA->id,
            'walk_in_customer_id' => $walkInCustA->id,
            'approved_cash_account_id' => $cashAccountA->id,
            'default_warehouse_id' => $warehouseA->id,
            'default_tax_rate_id' => $taxVat16->id,
            'offline_policy_enabled' => false,
            'offline_cash_only' => true,
            'max_offline_hours' => 12,
            'max_offline_sales_count' => 50,
            'max_offline_total_amount' => 5000,
            'catalog_snapshot_version' => 'snap-pilot-v1',
        ]);

        // Products for Tenant 1
        $prod1 = Product::create([
            'company_id' => $companyA->id,
            'base_unit_id' => $unitPcsA->id,
            'sku' => 'P-OIL-01',
            'name_ar' => 'زيت زيتون بكر ممتاز 1 لتر',
            'name_en' => 'Extra Virgin Olive Oil 1L',
            'default_sale_price_base' => '25.00',
            'default_purchase_price_base' => '18.00', // Sensitive cost to verify redaction!
            'track_stock' => true,
            'active' => true,
            'created_by' => $user1->id,
        ]);
        ProductUnit::create([
            'company_id' => $companyA->id,
            'product_id' => $prod1->id,
            'unit_id' => $unitPcsA->id,
            'conversion_to_base' => '1.000000',
            'default_sale_price_base' => '25.00',
            'is_base' => true,
            'is_default_sale' => true,
            'active' => true,
        ]);
        ProductBarcode::create([
            'company_id' => $companyA->id,
            'product_id' => $prod1->id,
            'barcode' => '7290001112223',
            'is_primary' => true,
        ]);
        InventoryBalance::create([
            'company_id' => $companyA->id,
            'warehouse_id' => $warehouseA->id,
            'product_id' => $prod1->id,
            'quantity_base' => '50.000000',
        ]);

        $prod2 = Product::create([
            'company_id' => $companyA->id,
            'base_unit_id' => $unitKgA->id,
            'sku' => 'P-FLOUR-10',
            'name_ar' => 'طحين قمح بلدي',
            'name_en' => 'Local Wheat Flour',
            'default_sale_price_base' => '40.00',
            'default_purchase_price_base' => '28.50', // Sensitive cost
            'track_stock' => true,
            'active' => true,
            'created_by' => $user1->id,
        ]);
        ProductUnit::create([
            'company_id' => $companyA->id,
            'product_id' => $prod2->id,
            'unit_id' => $unitKgA->id,
            'conversion_to_base' => '1.000000',
            'default_sale_price_base' => '40.00',
            'is_base' => true,
            'is_default_sale' => true,
            'active' => true,
        ]);
        ProductBarcode::create([
            'company_id' => $companyA->id,
            'product_id' => $prod2->id,
            'barcode' => '7290001112224',
            'is_primary' => true,
        ]);
        InventoryBalance::create([
            'company_id' => $companyA->id,
            'warehouse_id' => $warehouseA->id,
            'product_id' => $prod2->id,
            'quantity_base' => '20.000000',
        ]);

        $prod3 = Product::create([
            'company_id' => $companyA->id,
            'base_unit_id' => $unitPcsA->id,
            'sku' => 'P-SUGAR-01',
            'name_ar' => 'سكر أبيض 1 كغم',
            'name_en' => 'White Sugar 1Kg',
            'default_sale_price_base' => '5.50',
            'default_purchase_price_base' => '3.80',
            'track_stock' => false,
            'active' => true,
            'created_by' => $user1->id,
        ]);
        ProductUnit::create([
            'company_id' => $companyA->id,
            'product_id' => $prod3->id,
            'unit_id' => $unitPcsA->id,
            'conversion_to_base' => '1.000000',
            'default_sale_price_base' => '5.50',
            'is_base' => true,
            'is_default_sale' => true,
            'active' => true,
        ]);

        // -------------------------------------------------------------
        // Tenant 2 Context: Al-Quds Trading (USD base)
        // -------------------------------------------------------------
        $context->setCompany($companyB, $user3);
        auth()->login($user3);

        CompanyCurrency::firstOrCreate(
            ['company_id' => $companyB->id, 'currency_code' => 'USD'],
            ['exchange_rate_to_base' => '1.000000', 'enabled' => true]
        );

        $warehouseB = Warehouse::create([
            'company_id' => $companyB->id,
            'code' => 'WH-B-MAIN',
            'name_ar' => 'مستودع القدس الرئيسي',
            'name_en' => 'Al-Quds Main Warehouse',
            'active' => true,
            'is_default' => true,
            'created_by' => $user3->id,
        ]);

        $ledgerB = LedgerAccount::create([
            'company_id' => $companyB->id,
            'code' => '1010',
            'name_ar' => 'النقدية بالدولار',
            'name_en' => 'Cash on Hand (USD)',
            'account_type' => LedgerAccount::TYPE_ASSET,
            'normal_balance' => LedgerAccount::BALANCE_DEBIT,
            'is_control' => false,
            'is_system' => false,
            'active' => true,
        ]);

        $cashAccountB = MoneyAccount::create([
            'company_id' => $companyB->id,
            'account_type' => MoneyAccount::TYPE_CASH,
            'name_ar' => 'صندوق القدس - دولار',
            'name_en' => 'Al-Quds Register (USD)',
            'currency_code' => 'USD',
            'ledger_account_id' => $ledgerB->id,
            'is_active' => true,
            'created_by' => $user3->id,
        ]);

        $walkInCustB = Customer::create([
            'company_id' => $companyB->id,
            'code' => 'CUST-WALK-IN-B',
            'name_ar' => 'عميل نقدي القدس',
            'name_en' => 'Walk-in Customer B',
            'status' => 'active',
            'created_by' => $user3->id,
        ]);

        $regularCustB = Customer::create([
            'company_id' => $companyB->id,
            'code' => 'CUST-B01',
            'name_ar' => 'خالد عمر',
            'name_en' => 'Khaled Omar',
            'phone' => '+970599554433',
            'status' => 'active',
            'created_by' => $user3->id,
        ]);

        $unitPcsB = Unit::create([
            'company_id' => $companyB->id,
            'code' => 'PCS',
            'name_ar' => 'حبة',
            'name_en' => 'Piece',
            'symbol_en' => 'pc',
            'allows_fraction' => false,
            'decimal_places' => 0,
            'active' => true,
        ]);

        CompanyMobileSetting::create([
            'company_id' => $companyB->id,
            'walk_in_customer_id' => $walkInCustB->id,
            'approved_cash_account_id' => $cashAccountB->id,
            'default_warehouse_id' => $warehouseB->id,
            'default_tax_rate_id' => null,
            'offline_policy_enabled' => false,
            'offline_cash_only' => true,
            'max_offline_hours' => 12,
            'max_offline_sales_count' => 50,
            'max_offline_total_amount' => 5000,
            'catalog_snapshot_version' => 'snap-pilot-v1',
        ]);

        $prodB1 = Product::create([
            'company_id' => $companyB->id,
            'base_unit_id' => $unitPcsB->id,
            'sku' => 'PB-COFFEE-01',
            'name_ar' => 'بن محمص ممتاز 500غ',
            'name_en' => 'Roasted Coffee 500g',
            'default_sale_price_base' => '12.00',
            'default_purchase_price_base' => '7.50',
            'track_stock' => true,
            'active' => true,
            'created_by' => $user3->id,
        ]);
        ProductUnit::create([
            'company_id' => $companyB->id,
            'product_id' => $prodB1->id,
            'unit_id' => $unitPcsB->id,
            'conversion_to_base' => '1.000000',
            'default_sale_price_base' => '12.00',
            'is_base' => true,
            'is_default_sale' => true,
            'active' => true,
        ]);

        auth()->logout();
        $context->clear(true);

        // -------------------------------------------------------------
        // Devices
        // -------------------------------------------------------------
        $device1Id = '00000000-0000-4000-8000-000000000001';
        $device2RevokedId = '00000000-0000-4000-8000-000000000002';
        $device3MfaId = '00000000-0000-4000-8000-000000000003';
        $device4Tenant2Id = '00000000-0000-4000-8000-000000000004';
        $device5RestrictedId = '00000000-0000-4000-8000-000000000005';

        // Pre-register revoked device
        MobileDevice::create([
            'device_id' => $device2RevokedId,
            'user_id' => $user1->id,
            'device_name' => 'Revoked Android Tablet',
            'platform' => 'android',
            'app_version' => '1.0.0',
            'revoked_at' => now(),
        ]);

        // -------------------------------------------------------------
        // Write Private Synthetic Credentials (0600 mode)
        // -------------------------------------------------------------
        $privateCredentials = [
            'staff1' => [
                'email' => $user1->email,
                'password' => $passwords['staff1'],
                'device_id' => $device1Id,
                'device_name' => 'Pixel 8 Pilot',
            ],
            'staff2_mfa' => [
                'email' => $user2->email,
                'password' => $passwords['staff2'],
                'device_id' => $device3MfaId,
                'device_name' => 'Galaxy S24 Pilot',
                'totp_secret' => $mfaSecret,
                'recovery_codes' => $recoveryCodes,
            ],
            'staff3_tenant2' => [
                'email' => $user3->email,
                'password' => $passwords['staff3'],
                'device_id' => $device4Tenant2Id,
                'device_name' => 'Al-Quds Tablet',
            ],
            'staff4_restricted' => [
                'email' => $user4->email,
                'password' => $passwords['staff4'],
                'device_id' => $device5RestrictedId,
                'device_name' => 'Restricted POS',
            ],
            'revoked_device_id' => $device2RevokedId,
        ];

        $credDir = dirname($credentialPath);
        if (! is_dir($credDir)) {
            mkdir($credDir, 0700, true);
        }
        chmod($credDir, 0700);

        file_put_contents(
            $credentialPath,
            json_encode($privateCredentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        chmod($credentialPath, 0600);

        // Return public metadata only (NO secrets/passwords/tokens embedded)
        return [
            'company_a' => [
                'public_id' => $companyA->public_id,
                'name' => $companyA->name_en,
                'base_currency' => 'ILS',
                'products_count' => 3,
                'customers_count' => 3,
            ],
            'company_b' => [
                'public_id' => $companyB->public_id,
                'name' => $companyB->name_en,
                'base_currency' => 'USD',
                'products_count' => 1,
                'customers_count' => 2,
            ],
            'scenarios' => [
                'SCN-01-CHALLENGE' => [
                    'title' => 'Cryptographic Challenge Request',
                    'endpoint' => '/api/mobile/v1/auth/challenge',
                    'method' => 'POST',
                    'device_id' => $device1Id,
                    'expected_status' => 200,
                ],
                'SCN-02-LOGIN' => [
                    'title' => 'Production Staff Login & Token Issuance',
                    'endpoint' => '/api/mobile/v1/auth/login',
                    'method' => 'POST',
                    'actor_email' => $user1->email,
                    'device_id' => $device1Id,
                    'expected_status' => 200,
                ],
                'SCN-03-MFA-FLOW' => [
                    'title' => 'Fresh Challenge MFA Flow & Recovery Code Consumption',
                    'endpoint' => '/api/mobile/v1/auth/login',
                    'method' => 'POST',
                    'actor_email' => $user2->email,
                    'device_id' => $device3MfaId,
                    'expected_mfa_status' => 'MFA_REQUIRED',
                ],
                'SCN-04-BOOTSTRAP' => [
                    'title' => 'Tenant 1 Bootstrap Synchronization',
                    'endpoint' => '/api/mobile/v1/companies/{companyA}/bootstrap',
                    'method' => 'GET',
                    'company_id' => $companyA->public_id,
                    'expected_status' => 200,
                ],
                'SCN-05-PRODUCT-CATALOG' => [
                    'title' => 'Tenant 1 Product Catalog with Cost Redaction & Decimal Parity',
                    'endpoint' => '/api/mobile/v1/companies/{companyA}/products',
                    'method' => 'GET',
                    'company_id' => $companyA->public_id,
                    'expected_status' => 200,
                ],
                'SCN-06-CUSTOMER-DIRECTORY' => [
                    'title' => 'Tenant 1 Customer Directory Synchronization',
                    'endpoint' => '/api/mobile/v1/companies/{companyA}/customers',
                    'method' => 'GET',
                    'company_id' => $companyA->public_id,
                    'expected_status' => 200,
                ],
                'SCN-07-REVOCATION-AND-EPOCH' => [
                    'title' => 'Device Revocation & Password Epoch Invalidation',
                    'endpoint' => '/api/mobile/v1/auth/login',
                    'revoked_device_id' => $device2RevokedId,
                    'expected_status' => 401,
                ],
                'SCN-08-TENANT-SCOPE-ZERO-ORACLE' => [
                    'title' => 'Tenant Isolation Guard (Zero Existence/Digest Oracle)',
                    'endpoint' => '/api/mobile/v1/companies/{companyA}/products',
                    'nonmember_email' => $user3->email,
                    'target_company' => $companyA->public_id,
                    'expected_status' => 403,
                ],
                'SCN-09-PERMISSION-BOUNDARY' => [
                    'title' => 'Real Role/Permission Enforcement (No Bypass)',
                    'endpoint' => '/api/mobile/v1/companies/{companyA}/customers',
                    'restricted_email' => $user4->email,
                    'company_id' => $companyA->public_id,
                    'expected_status' => 403,
                ],
                'SCN-10-V2-DISABLED-BOUNDARY' => [
                    'title' => 'Mobile V2 Disabled Boundary (Fallback 404)',
                    'endpoint' => '/api/mobile/v2/companies/{companyA}/bootstrap',
                    'method' => 'GET',
                    'expected_status' => 404,
                ],
                'SCN-11-FINANCIAL-DENY-ADAPTER' => [
                    'title' => 'Production Financial Execution Adapter Remains DENY',
                    'adapter' => 'App\\Support\\Mobile\\Receipt\\DenyFinancialExecutionAdapter',
                    'expected_behavior' => 'Throws FinancialExecutionDeniedException on execute/replay/lookup',
                ],
            ],
            'credential_file' => $credentialPath,
        ];
    }
}
