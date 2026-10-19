<?php

declare(strict_types=1);

namespace App\Application\CompanyDataExport\Datasets;

use App\Application\Reporting\Support\InventoryReportHelper;
use App\Domain\Purchasing\Queries\VendorBalanceQuery;
use App\Domain\Sales\Queries\CustomerBalanceQuery;
use App\Models\Company;
use App\Services\Accounting\DerivedLedgerBalanceService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type Dataset array{key: string, filename: string, description: string, columns: list<array{key: string, label: string, type: string}>, fetch: callable(Company, int): Generator<list<array<string, mixed>>>}
 */
final class ExportDatasetRegistry
{
    /**
     * Return definitions for all 40 V1 datasets.
     *
     * @return list<array{
     *     key: string,
     *     filename: string,
     *     description: string,
     *     columns: list<array{key: string, label: string, type: string}>,
     *     fetch: callable(Company, int): Generator<list<array<string, mixed>>>
     * }>
     */
    public static function all(): array
    {
        return [
            self::companyDataset(),
            self::companyCurrenciesDataset(),
            self::exchangeRatesDataset(),
            self::documentSettingsDataset(),
            self::inventorySettingsDataset(),
            self::purchaseSettingsDataset(),
            self::customersDataset(),
            self::customerBalancesDataset(),
            self::vendorsDataset(),
            self::vendorBalancesDataset(),
            self::warehousesDataset(),
            self::unitsDataset(),
            self::productCategoriesDataset(),
            self::productsDataset(),
            self::productUnitsDataset(),
            self::productBarcodesDataset(),
            self::inventoryStockOnHandDataset(),
            self::quotationsDataset(),
            self::quotationLinesDataset(),
            self::salesInvoicesDataset(),
            self::salesInvoiceLinesDataset(),
            self::salesReturnsDataset(),
            self::salesReturnLinesDataset(),
            self::purchasesDataset(),
            self::purchaseLinesDataset(),
            self::purchaseReturnsDataset(),
            self::purchaseReturnLinesDataset(),
            self::customerPaymentsDataset(),
            self::customerPaymentAllocationsDataset(),
            self::vendorPaymentsDataset(),
            self::vendorPaymentAllocationsDataset(),
            self::moneyAccountsDataset(),
            self::moneyTransfersDataset(),
            self::checksDataset(),
            self::expenseCategoriesDataset(),
            self::expensesDataset(),
            self::ledgerAccountsDataset(),
            self::postingBatchesDataset(),
            self::postingLinesDataset(),
            self::trialBalanceDataset(),
        ];
    }

    // 1. Company
    /** @return Dataset */
    private static function companyDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'name_ar', 'label' => 'Name (Arabic)', 'type' => 'text'],
            ['key' => 'name_en', 'label' => 'Name (English)', 'type' => 'text'],
            ['key' => 'legal_name_ar', 'label' => 'Legal Name (Arabic)', 'type' => 'text'],
            ['key' => 'legal_name_en', 'label' => 'Legal Name (English)', 'type' => 'text'],
            ['key' => 'base_currency_code', 'label' => 'Base Currency', 'type' => 'text'],
            ['key' => 'default_locale', 'label' => 'Default Locale', 'type' => 'text'],
            ['key' => 'timezone', 'label' => 'Timezone', 'type' => 'text'],
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'text'],
            ['key' => 'whatsapp', 'label' => 'WhatsApp', 'type' => 'text'],
            ['key' => 'email', 'label' => 'Email', 'type' => 'text'],
            ['key' => 'website', 'label' => 'Website', 'type' => 'text'],
            ['key' => 'address_ar', 'label' => 'Address (Arabic)', 'type' => 'text'],
            ['key' => 'address_en', 'label' => 'Address (English)', 'type' => 'text'],
            ['key' => 'registration_number', 'label' => 'Registration Number', 'type' => 'text'],
            ['key' => 'tax_number', 'label' => 'Tax Number', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
            ['key' => 'updated_at', 'label' => 'Updated At', 'type' => 'text'],
        ];

        return [
            'key' => 'company',
            'filename' => 'company.csv',
            'description' => 'Company legal profile and configuration',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                $keys = array_map(fn ($c) => $c['key'], $columns);
                $rows = DB::table('companies')
                    ->select($keys)
                    ->where('id', $company->id)
                    ->get()
                    ->map(fn ($r) => (array) $r)
                    ->all();
                if (! empty($rows)) {
                    yield $rows;
                }
            },
        ];
    }

    // 2. Company Currencies
    /** @return Dataset */
    private static function companyCurrenciesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'currency_code', 'label' => 'Currency Code', 'type' => 'text'],
            ['key' => 'enabled', 'label' => 'Enabled', 'type' => 'text'],
            ['key' => 'is_base', 'label' => 'Is Base', 'type' => 'text'],
            ['key' => 'display_order', 'label' => 'Display Order', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'company_currencies',
            'filename' => 'company_currencies.csv',
            'description' => 'Enabled currencies and base currency flags',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                $keys = array_map(fn ($c) => $c['key'], $columns);
                $rows = DB::table('company_currencies')
                    ->select($keys)
                    ->where('company_id', $company->id)
                    ->orderBy('display_order')
                    ->get()
                    ->map(fn ($r) => (array) $r)
                    ->all();
                if (! empty($rows)) {
                    yield $rows;
                }
            },
        ];
    }

    // 3. Exchange Rates
    /** @return Dataset */
    private static function exchangeRatesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'base_currency_code', 'label' => 'Base Currency', 'type' => 'text'],
            ['key' => 'currency_code', 'label' => 'Foreign Currency', 'type' => 'text'],
            ['key' => 'rate', 'label' => 'Rate', 'type' => 'decimal'],
            ['key' => 'effective_at', 'label' => 'Effective At', 'type' => 'text'],
            ['key' => 'source', 'label' => 'Source', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'exchange_rates',
            'filename' => 'exchange_rates.csv',
            'description' => 'Historical currency exchange rates',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('exchange_rates', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 4. Document Settings
    /** @return Dataset */
    private static function documentSettingsDataset(): array
    {
        $columns = [
            ['key' => 'default_document_locale', 'label' => 'Default Locale', 'type' => 'text'],
            ['key' => 'show_logo', 'label' => 'Show Logo', 'type' => 'text'],
            ['key' => 'show_qr_by_default', 'label' => 'Show QR by Default', 'type' => 'text'],
            ['key' => 'show_product_images_on_quotes', 'label' => 'Show Images on Quotes', 'type' => 'text'],
            ['key' => 'invoice_footer_ar', 'label' => 'Invoice Footer (Arabic)', 'type' => 'text'],
            ['key' => 'invoice_footer_en', 'label' => 'Invoice Footer (English)', 'type' => 'text'],
            ['key' => 'quotation_terms_ar', 'label' => 'Quotation Terms (Arabic)', 'type' => 'text'],
            ['key' => 'quotation_terms_en', 'label' => 'Quotation Terms (English)', 'type' => 'text'],
        ];

        return [
            'key' => 'document_settings',
            'filename' => 'document_settings.csv',
            'description' => 'Document presentation preferences and templates',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                $keys = array_map(fn ($c) => $c['key'], $columns);
                $rows = DB::table('company_document_settings')
                    ->select($keys)
                    ->where('company_id', $company->id)
                    ->get()
                    ->map(fn ($r) => (array) $r)
                    ->all();
                if (! empty($rows)) {
                    yield $rows;
                }
            },
        ];
    }

    // 5. Inventory Settings
    /** @return Dataset */
    private static function inventorySettingsDataset(): array
    {
        $columns = [
            ['key' => 'allow_negative_stock', 'label' => 'Allow Negative Stock', 'type' => 'text'],
            ['key' => 'default_warehouse_id', 'label' => 'Default Warehouse ID', 'type' => 'text'],
            ['key' => 'default_cost_method', 'label' => 'Default Cost Method', 'type' => 'text'],
            ['key' => 'default_expiry_warning_days', 'label' => 'Expiry Warning Days', 'type' => 'text'],
        ];

        return [
            'key' => 'inventory_settings',
            'filename' => 'inventory_settings.csv',
            'description' => 'Inventory policy and default warehouse settings',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                $keys = array_map(fn ($c) => $c['key'], $columns);
                $rows = DB::table('company_inventory_settings')
                    ->select($keys)
                    ->where('company_id', $company->id)
                    ->get()
                    ->map(fn ($r) => (array) $r)
                    ->all();
                if (! empty($rows)) {
                    yield $rows;
                }
            },
        ];
    }

    // 6. Purchase Settings
    /** @return Dataset */
    private static function purchaseSettingsDataset(): array
    {
        $columns = [
            ['key' => 'default_payment_terms_days', 'label' => 'Default Payment Terms Days', 'type' => 'text'],
            ['key' => 'default_receiving_warehouse_id', 'label' => 'Default Receiving Warehouse ID', 'type' => 'text'],
            ['key' => 'warn_duplicate_vendor_invoice', 'label' => 'Warn Duplicate Invoice', 'type' => 'text'],
        ];

        return [
            'key' => 'purchase_settings',
            'filename' => 'purchase_settings.csv',
            'description' => 'Purchasing preferences and payment terms',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                $keys = array_map(fn ($c) => $c['key'], $columns);
                $rows = DB::table('company_purchase_settings')
                    ->select($keys)
                    ->where('company_id', $company->id)
                    ->get()
                    ->map(fn ($r) => (array) $r)
                    ->all();
                if (! empty($rows)) {
                    yield $rows;
                }
            },
        ];
    }

    // 7. Customers
    /** @return Dataset */
    private static function customersDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'code', 'label' => 'Customer Code', 'type' => 'text'],
            ['key' => 'name_ar', 'label' => 'Name (Arabic)', 'type' => 'text'],
            ['key' => 'name_en', 'label' => 'Name (English)', 'type' => 'text'],
            ['key' => 'business_name_ar', 'label' => 'Business Name (Arabic)', 'type' => 'text'],
            ['key' => 'business_name_en', 'label' => 'Business Name (English)', 'type' => 'text'],
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'text'],
            ['key' => 'whatsapp', 'label' => 'WhatsApp', 'type' => 'text'],
            ['key' => 'email', 'label' => 'Email', 'type' => 'text'],
            ['key' => 'tax_number', 'label' => 'Tax Number', 'type' => 'text'],
            ['key' => 'address_ar', 'label' => 'Address (Arabic)', 'type' => 'text'],
            ['key' => 'address_en', 'label' => 'Address (English)', 'type' => 'text'],
            ['key' => 'city_ar', 'label' => 'City (Arabic)', 'type' => 'text'],
            ['key' => 'city_en', 'label' => 'City (English)', 'type' => 'text'],
            ['key' => 'postal_code', 'label' => 'Postal Code', 'type' => 'text'],
            ['key' => 'country_code', 'label' => 'Country Code', 'type' => 'text'],
            ['key' => 'preferred_locale', 'label' => 'Preferred Locale', 'type' => 'text'],
            ['key' => 'default_currency_code', 'label' => 'Default Currency', 'type' => 'text'],
            ['key' => 'credit_limit', 'label' => 'Credit Limit', 'type' => 'decimal'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
            ['key' => 'deleted_at', 'label' => 'Archived At', 'type' => 'text'],
        ];

        return [
            'key' => 'customers',
            'filename' => 'customers.csv',
            'description' => 'Customer master directory',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('customers', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns), softDeletes: true);
            },
        ];
    }

    // 8. Customer Balances
    /** @return Dataset */
    private static function customerBalancesDataset(): array
    {
        return [
            'key' => 'customer_balances',
            'filename' => 'customer_balances.csv',
            'description' => 'Authoritative accounts receivable balances per customer and currency',
            'columns' => [
                ['key' => 'customer_id', 'label' => 'Customer ID', 'type' => 'text'],
                ['key' => 'customer_code', 'label' => 'Customer Code', 'type' => 'text'],
                ['key' => 'customer_name_ar', 'label' => 'Name (Arabic)', 'type' => 'text'],
                ['key' => 'customer_name_en', 'label' => 'Name (English)', 'type' => 'text'],
                ['key' => 'currency_code', 'label' => 'Currency Code', 'type' => 'text'],
                ['key' => 'invoiced_amount', 'label' => 'Invoiced Amount', 'type' => 'decimal'],
                ['key' => 'outstanding_balance', 'label' => 'Outstanding Balance', 'type' => 'decimal'],
            ],
            'fetch' => function (Company $company, int $chunkSize): Generator {
                $query = DB::table('customers')
                    ->select(['id', 'code', 'name_ar', 'name_en'])
                    ->where('company_id', $company->id)
                    ->orderBy('id');

                $lastId = 0;
                while (true) {
                    $customers = (clone $query)->where('id', '>', $lastId)->limit($chunkSize)->get();
                    if ($customers->isEmpty()) {
                        break;
                    }
                    $lastId = (int) $customers->last()->id;
                    $ids = array_map(fn ($c) => (int) $c->id, $customers->all());
                    $balances = app(CustomerBalanceQuery::class)->execute($ids);

                    $chunkRows = [];
                    foreach ($customers as $customer) {
                        $perCurrency = $balances[(int) $customer->id] ?? [];
                        if (empty($perCurrency)) {
                            $perCurrency[(string) $company->base_currency_code] = ['invoiced' => '0.000000', 'outstanding' => '0.000000'];
                        }
                        ksort($perCurrency);
                        foreach ($perCurrency as $currency => $b) {
                            $chunkRows[] = [
                                'customer_id' => (string) $customer->id,
                                'customer_code' => (string) ($customer->code ?? ''),
                                'customer_name_ar' => (string) $customer->name_ar,
                                'customer_name_en' => (string) ($customer->name_en ?? ''),
                                'currency_code' => (string) $currency,
                                'invoiced_amount' => (string) BigDecimal::of($b['invoiced'])->toScale(6),
                                'outstanding_balance' => (string) BigDecimal::of($b['outstanding'])->toScale(6),
                            ];
                        }
                    }
                    if (! empty($chunkRows)) {
                        yield $chunkRows;
                    }
                }
            },
        ];
    }

    // 9. Vendors
    /** @return Dataset */
    private static function vendorsDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'code', 'label' => 'Vendor Code', 'type' => 'text'],
            ['key' => 'name_ar', 'label' => 'Name (Arabic)', 'type' => 'text'],
            ['key' => 'name_en', 'label' => 'Name (English)', 'type' => 'text'],
            ['key' => 'business_name_ar', 'label' => 'Business Name (Arabic)', 'type' => 'text'],
            ['key' => 'business_name_en', 'label' => 'Business Name (English)', 'type' => 'text'],
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'text'],
            ['key' => 'whatsapp', 'label' => 'WhatsApp', 'type' => 'text'],
            ['key' => 'email', 'label' => 'Email', 'type' => 'text'],
            ['key' => 'tax_number', 'label' => 'Tax Number', 'type' => 'text'],
            ['key' => 'address_ar', 'label' => 'Address (Arabic)', 'type' => 'text'],
            ['key' => 'address_en', 'label' => 'Address (English)', 'type' => 'text'],
            ['key' => 'city_ar', 'label' => 'City (Arabic)', 'type' => 'text'],
            ['key' => 'city_en', 'label' => 'City (English)', 'type' => 'text'],
            ['key' => 'postal_code', 'label' => 'Postal Code', 'type' => 'text'],
            ['key' => 'country_code', 'label' => 'Country Code', 'type' => 'text'],
            ['key' => 'preferred_locale', 'label' => 'Preferred Locale', 'type' => 'text'],
            ['key' => 'default_currency_code', 'label' => 'Default Currency', 'type' => 'text'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
            ['key' => 'deleted_at', 'label' => 'Archived At', 'type' => 'text'],
        ];

        return [
            'key' => 'vendors',
            'filename' => 'vendors.csv',
            'description' => 'Vendor master directory',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('vendors', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns), softDeletes: true);
            },
        ];
    }

    // 10. Vendor Balances
    /** @return Dataset */
    private static function vendorBalancesDataset(): array
    {
        return [
            'key' => 'vendor_balances',
            'filename' => 'vendor_balances.csv',
            'description' => 'Authoritative accounts payable balances per vendor and currency',
            'columns' => [
                ['key' => 'vendor_id', 'label' => 'Vendor ID', 'type' => 'text'],
                ['key' => 'vendor_code', 'label' => 'Vendor Code', 'type' => 'text'],
                ['key' => 'vendor_name_ar', 'label' => 'Name (Arabic)', 'type' => 'text'],
                ['key' => 'vendor_name_en', 'label' => 'Name (English)', 'type' => 'text'],
                ['key' => 'currency_code', 'label' => 'Currency Code', 'type' => 'text'],
                ['key' => 'purchased_amount', 'label' => 'Purchased Amount', 'type' => 'decimal'],
                ['key' => 'returned_amount', 'label' => 'Returned Amount', 'type' => 'decimal'],
                ['key' => 'paid_amount', 'label' => 'Paid Amount', 'type' => 'decimal'],
                ['key' => 'balance', 'label' => 'Payable Balance', 'type' => 'decimal'],
            ],
            'fetch' => function (Company $company, int $chunkSize): Generator {
                $query = DB::table('vendors')
                    ->select(['id', 'code', 'name_ar', 'name_en'])
                    ->where('company_id', $company->id)
                    ->orderBy('id');

                $lastId = 0;
                while (true) {
                    $vendors = (clone $query)->where('id', '>', $lastId)->limit($chunkSize)->get();
                    if ($vendors->isEmpty()) {
                        break;
                    }
                    $lastId = (int) $vendors->last()->id;
                    $ids = array_map(fn ($v) => (int) $v->id, $vendors->all());
                    $balances = app(VendorBalanceQuery::class)->execute($ids);

                    $chunkRows = [];
                    foreach ($vendors as $vendor) {
                        $perCurrency = $balances[(int) $vendor->id] ?? [];
                        if (empty($perCurrency)) {
                            $perCurrency[(string) $company->base_currency_code] = [
                                'purchased' => '0.000000', 'returned' => '0.000000',
                                'paid' => '0.000000', 'balance' => '0.000000',
                            ];
                        }
                        ksort($perCurrency);
                        foreach ($perCurrency as $currency => $b) {
                            $chunkRows[] = [
                                'vendor_id' => (string) $vendor->id,
                                'vendor_code' => (string) ($vendor->code ?? ''),
                                'vendor_name_ar' => (string) $vendor->name_ar,
                                'vendor_name_en' => (string) ($vendor->name_en ?? ''),
                                'currency_code' => (string) $currency,
                                'purchased_amount' => (string) BigDecimal::of($b['purchased'])->toScale(6),
                                'returned_amount' => (string) BigDecimal::of($b['returned'])->toScale(6),
                                'paid_amount' => (string) BigDecimal::of($b['paid'])->toScale(6),
                                'balance' => (string) BigDecimal::of($b['balance'])->toScale(6),
                            ];
                        }
                    }
                    if (! empty($chunkRows)) {
                        yield $chunkRows;
                    }
                }
            },
        ];
    }

    // 11. Warehouses
    /** @return Dataset */
    private static function warehousesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'code', 'label' => 'Warehouse Code', 'type' => 'text'],
            ['key' => 'name_ar', 'label' => 'Name (Arabic)', 'type' => 'text'],
            ['key' => 'name_en', 'label' => 'Name (English)', 'type' => 'text'],
            ['key' => 'address_ar', 'label' => 'Address (Arabic)', 'type' => 'text'],
            ['key' => 'address_en', 'label' => 'Address (English)', 'type' => 'text'],
            ['key' => 'is_default', 'label' => 'Is Default', 'type' => 'text'],
            ['key' => 'active', 'label' => 'Active', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
            ['key' => 'deleted_at', 'label' => 'Archived At', 'type' => 'text'],
        ];

        return [
            'key' => 'warehouses',
            'filename' => 'warehouses.csv',
            'description' => 'Warehouse storage facilities',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('warehouses', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns), softDeletes: true);
            },
        ];
    }

    // 12. Units
    /** @return Dataset */
    private static function unitsDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'code', 'label' => 'Unit Code', 'type' => 'text'],
            ['key' => 'name_ar', 'label' => 'Name (Arabic)', 'type' => 'text'],
            ['key' => 'name_en', 'label' => 'Name (English)', 'type' => 'text'],
            ['key' => 'symbol_ar', 'label' => 'Symbol (Arabic)', 'type' => 'text'],
            ['key' => 'symbol_en', 'label' => 'Symbol (English)', 'type' => 'text'],
            ['key' => 'allows_fraction', 'label' => 'Allows Fraction', 'type' => 'text'],
            ['key' => 'decimal_places', 'label' => 'Decimal Places', 'type' => 'text'],
            ['key' => 'active', 'label' => 'Active', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'units',
            'filename' => 'units.csv',
            'description' => 'Measurement units and symbols',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('units', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 13. Product Categories
    /** @return Dataset */
    private static function productCategoriesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'parent_id', 'label' => 'Parent ID', 'type' => 'text'],
            ['key' => 'name_ar', 'label' => 'Name (Arabic)', 'type' => 'text'],
            ['key' => 'name_en', 'label' => 'Name (English)', 'type' => 'text'],
            ['key' => 'description_ar', 'label' => 'Description (Arabic)', 'type' => 'text'],
            ['key' => 'description_en', 'label' => 'Description (English)', 'type' => 'text'],
            ['key' => 'active', 'label' => 'Active', 'type' => 'text'],
            ['key' => 'sort_order', 'label' => 'Sort Order', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
            ['key' => 'deleted_at', 'label' => 'Archived At', 'type' => 'text'],
        ];

        return [
            'key' => 'product_categories',
            'filename' => 'product_categories.csv',
            'description' => 'Product category taxonomy',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('product_categories', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns), softDeletes: true);
            },
        ];
    }

    // 14. Products
    /** @return Dataset */
    private static function productsDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'sku', 'label' => 'SKU', 'type' => 'text'],
            ['key' => 'name_ar', 'label' => 'Name (Arabic)', 'type' => 'text'],
            ['key' => 'name_en', 'label' => 'Name (English)', 'type' => 'text'],
            ['key' => 'description_ar', 'label' => 'Description (Arabic)', 'type' => 'text'],
            ['key' => 'description_en', 'label' => 'Description (English)', 'type' => 'text'],
            ['key' => 'product_type', 'label' => 'Type', 'type' => 'text'],
            ['key' => 'category_id', 'label' => 'Category ID', 'type' => 'text'],
            ['key' => 'brand_id', 'label' => 'Brand ID', 'type' => 'text'],
            ['key' => 'base_unit_id', 'label' => 'Base Unit ID', 'type' => 'text'],
            ['key' => 'track_stock', 'label' => 'Track Stock', 'type' => 'text'],
            ['key' => 'track_expiry', 'label' => 'Track Expiry', 'type' => 'text'],
            ['key' => 'minimum_stock_base', 'label' => 'Minimum Stock', 'type' => 'decimal'],
            ['key' => 'default_purchase_cost_base', 'label' => 'Default Purchase Cost', 'type' => 'decimal'],
            ['key' => 'default_sale_price_base', 'label' => 'Default Sale Price', 'type' => 'decimal'],
            ['key' => 'active', 'label' => 'Active', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
            ['key' => 'deleted_at', 'label' => 'Archived At', 'type' => 'text'],
        ];

        return [
            'key' => 'products',
            'filename' => 'products.csv',
            'description' => 'Item catalog master records',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('products', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns), softDeletes: true);
            },
        ];
    }

    // 15. Product Units
    /** @return Dataset */
    private static function productUnitsDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'product_id', 'label' => 'Product ID', 'type' => 'text'],
            ['key' => 'unit_id', 'label' => 'Unit ID', 'type' => 'text'],
            ['key' => 'conversion_to_base', 'label' => 'Conversion to Base', 'type' => 'decimal'],
            ['key' => 'is_base', 'label' => 'Is Base', 'type' => 'text'],
            ['key' => 'is_default_purchase', 'label' => 'Default Purchase', 'type' => 'text'],
            ['key' => 'is_default_sale', 'label' => 'Default Sale', 'type' => 'text'],
            ['key' => 'default_purchase_price_base', 'label' => 'Purchase Price (Base)', 'type' => 'decimal'],
            ['key' => 'default_sale_price_base', 'label' => 'Sale Price (Base)', 'type' => 'decimal'],
            ['key' => 'active', 'label' => 'Active', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'product_units',
            'filename' => 'product_units.csv',
            'description' => 'Packaging unit conversions and unit prices',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('product_units', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 16. Product Barcodes
    /** @return Dataset */
    private static function productBarcodesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'product_id', 'label' => 'Product ID', 'type' => 'text'],
            ['key' => 'unit_id', 'label' => 'Unit ID', 'type' => 'text'],
            ['key' => 'barcode', 'label' => 'Barcode', 'type' => 'text'],
            ['key' => 'type', 'label' => 'Type', 'type' => 'text'],
            ['key' => 'is_primary', 'label' => 'Is Primary', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'product_barcodes',
            'filename' => 'product_barcodes.csv',
            'description' => 'Product packaging barcodes and unit mappings',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('product_barcodes', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 17. Inventory Stock on Hand
    /** @return Dataset */
    private static function inventoryStockOnHandDataset(): array
    {
        return [
            'key' => 'inventory_stock_on_hand',
            'filename' => 'inventory_stock_on_hand.csv',
            'description' => 'Authoritative stock on hand positions and average unit valuation',
            'columns' => [
                ['key' => 'product_id', 'label' => 'Product ID', 'type' => 'text'],
                ['key' => 'sku', 'label' => 'SKU', 'type' => 'text'],
                ['key' => 'product_name_ar', 'label' => 'Product Name (Arabic)', 'type' => 'text'],
                ['key' => 'product_name_en', 'label' => 'Product Name (English)', 'type' => 'text'],
                ['key' => 'warehouse_id', 'label' => 'Warehouse ID', 'type' => 'text'],
                ['key' => 'warehouse_name_ar', 'label' => 'Warehouse (Arabic)', 'type' => 'text'],
                ['key' => 'warehouse_name_en', 'label' => 'Warehouse (English)', 'type' => 'text'],
                ['key' => 'unit_code', 'label' => 'Unit Code', 'type' => 'text'],
                ['key' => 'quantity_on_hand', 'label' => 'Quantity on Hand', 'type' => 'decimal'],
                ['key' => 'value_base', 'label' => 'Total Valuation (Base)', 'type' => 'decimal'],
                ['key' => 'average_unit_cost_base', 'label' => 'Average Unit Cost (Base)', 'type' => 'decimal'],
            ],
            'fetch' => function (Company $company, int $chunkSize): Generator {
                $query = DB::table('stock_movements as sm')
                    ->join('products as p', 'p.id', '=', 'sm.product_id')
                    ->join('warehouses as w', 'w.id', '=', 'sm.warehouse_id')
                    ->join('units as u', 'u.id', '=', 'p.base_unit_id')
                    ->where('sm.company_id', $company->id)
                    ->where('p.company_id', $company->id)
                    ->where('w.company_id', $company->id)
                    ->where('u.company_id', $company->id)
                    ->select('p.id as product_id', 'p.sku', 'p.name_ar as product_name_ar', 'p.name_en as product_name_en')
                    ->addSelect('w.id as warehouse_id', 'w.name_ar as warehouse_name_ar', 'w.name_en as warehouse_name_en', 'u.code as unit_code')
                    ->selectRaw('COALESCE(SUM(sm.quantity_delta_base), 0) AS quantity_on_hand')
                    ->selectRaw('COALESCE(SUM(sm.value_delta_base), 0) AS value_base')
                    ->groupBy('p.id', 'p.sku', 'p.name_ar', 'p.name_en', 'w.id', 'w.name_ar', 'w.name_en', 'u.code')
                    ->orderBy('p.id')
                    ->orderBy('w.id');

                $offset = 0;
                while (true) {
                    $rows = (clone $query)->offset($offset)->limit($chunkSize)->get();
                    if ($rows->isEmpty()) {
                        break;
                    }
                    $offset += $rows->count();
                    $chunkRows = [];
                    foreach ($rows as $r) {
                        $qty = BigDecimal::of((string) $r->quantity_on_hand);
                        $val = BigDecimal::of((string) $r->value_base);
                        $avgCost = InventoryReportHelper::calculateAverageCost($val, $qty);

                        $chunkRows[] = [
                            'product_id' => (string) $r->product_id,
                            'sku' => (string) $r->sku,
                            'product_name_ar' => (string) $r->product_name_ar,
                            'product_name_en' => (string) ($r->product_name_en ?? ''),
                            'warehouse_id' => (string) $r->warehouse_id,
                            'warehouse_name_ar' => (string) $r->warehouse_name_ar,
                            'warehouse_name_en' => (string) ($r->warehouse_name_en ?? ''),
                            'unit_code' => (string) $r->unit_code,
                            'quantity_on_hand' => (string) $qty->toScale(6),
                            'value_base' => (string) $val->toScale(6),
                            'average_unit_cost_base' => $avgCost,
                        ];
                    }
                    yield $chunkRows;
                }
            },
        ];
    }

    // 18. Quotations
    /** @return Dataset */
    private static function quotationsDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'quotation_number', 'label' => 'Quotation Number', 'type' => 'text'],
            ['key' => 'customer_id', 'label' => 'Customer ID', 'type' => 'text'],
            ['key' => 'currency_code', 'label' => 'Currency', 'type' => 'text'],
            ['key' => 'exchange_rate', 'label' => 'Exchange Rate', 'type' => 'decimal'],
            ['key' => 'issue_date', 'label' => 'Issue Date', 'type' => 'text'],
            ['key' => 'expiry_date', 'label' => 'Expiry Date', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ['key' => 'pricing_tier', 'label' => 'Pricing Tier', 'type' => 'text'],
            ['key' => 'subtotal_currency', 'label' => 'Subtotal (Currency)', 'type' => 'decimal'],
            ['key' => 'discount_total_currency', 'label' => 'Discount Total (Currency)', 'type' => 'decimal'],
            ['key' => 'tax_total_currency', 'label' => 'Tax Total (Currency)', 'type' => 'decimal'],
            ['key' => 'grand_total_currency', 'label' => 'Grand Total (Currency)', 'type' => 'decimal'],
            ['key' => 'subtotal_base', 'label' => 'Subtotal (Base)', 'type' => 'decimal'],
            ['key' => 'discount_total_base', 'label' => 'Discount Total (Base)', 'type' => 'decimal'],
            ['key' => 'tax_total_base', 'label' => 'Tax Total (Base)', 'type' => 'decimal'],
            ['key' => 'grand_total_base', 'label' => 'Grand Total (Base)', 'type' => 'decimal'],
            ['key' => 'converted_to_invoice_id', 'label' => 'Converted to Invoice ID', 'type' => 'text'],
            ['key' => 'converted_at', 'label' => 'Converted At', 'type' => 'text'],
            ['key' => 'sent_at', 'label' => 'Sent At', 'type' => 'text'],
            ['key' => 'accepted_at', 'label' => 'Accepted At', 'type' => 'text'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ['key' => 'terms', 'label' => 'Terms', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'quotations',
            'filename' => 'quotations.csv',
            'description' => 'Sales quotation headers',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('quotations', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 19. Quotation Lines
    /** @return Dataset */
    private static function quotationLinesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'quotation_id', 'label' => 'Quotation ID', 'type' => 'text'],
            ['key' => 'line_number', 'label' => 'Line Number', 'type' => 'text'],
            ['key' => 'product_id', 'label' => 'Product ID', 'type' => 'text'],
            ['key' => 'product_unit_id', 'label' => 'Product Unit ID', 'type' => 'text'],
            ['key' => 'item_description', 'label' => 'Description', 'type' => 'text'],
            ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal'],
            ['key' => 'quantity_base', 'label' => 'Quantity (Base)', 'type' => 'decimal'],
            ['key' => 'unit_price', 'label' => 'Unit Price', 'type' => 'decimal'],
            ['key' => 'discount_type', 'label' => 'Discount Type', 'type' => 'text'],
            ['key' => 'discount_value', 'label' => 'Discount Value', 'type' => 'decimal'],
            ['key' => 'tax_rate_snapshot', 'label' => 'Tax Rate', 'type' => 'decimal'],
            ['key' => 'tax_inclusive', 'label' => 'Tax Inclusive', 'type' => 'text'],
            ['key' => 'line_subtotal', 'label' => 'Line Subtotal', 'type' => 'decimal'],
            ['key' => 'line_discount', 'label' => 'Line Discount', 'type' => 'decimal'],
            ['key' => 'line_tax', 'label' => 'Line Tax', 'type' => 'decimal'],
            ['key' => 'line_total', 'label' => 'Line Total', 'type' => 'decimal'],
            ['key' => 'line_total_base', 'label' => 'Line Total (Base)', 'type' => 'decimal'],
            ['key' => 'unit_conversion_ratio', 'label' => 'Conversion Ratio', 'type' => 'decimal'],
            ['key' => 'unit_name_ar', 'label' => 'Unit (Arabic)', 'type' => 'text'],
            ['key' => 'unit_name_en', 'label' => 'Unit (English)', 'type' => 'text'],
            ['key' => 'product_sku', 'label' => 'Product SKU', 'type' => 'text'],
            ['key' => 'product_name_ar', 'label' => 'Product Name (Arabic)', 'type' => 'text'],
            ['key' => 'product_name_en', 'label' => 'Product Name (English)', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'quotation_lines',
            'filename' => 'quotation_lines.csv',
            'description' => 'Sales quotation detailed line items',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('quotation_lines', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 20. Sales Invoices
    /** @return Dataset */
    private static function salesInvoicesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'invoice_number', 'label' => 'Invoice Number', 'type' => 'text'],
            ['key' => 'customer_id', 'label' => 'Customer ID', 'type' => 'text'],
            ['key' => 'warehouse_id', 'label' => 'Warehouse ID', 'type' => 'text'],
            ['key' => 'quotation_id', 'label' => 'Quotation ID', 'type' => 'text'],
            ['key' => 'currency_code', 'label' => 'Currency', 'type' => 'text'],
            ['key' => 'exchange_rate', 'label' => 'Exchange Rate', 'type' => 'decimal'],
            ['key' => 'issue_date', 'label' => 'Issue Date', 'type' => 'text'],
            ['key' => 'due_date', 'label' => 'Due Date', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ['key' => 'subtotal_currency', 'label' => 'Subtotal (Currency)', 'type' => 'decimal'],
            ['key' => 'discount_total_currency', 'label' => 'Discount Total (Currency)', 'type' => 'decimal'],
            ['key' => 'tax_total_currency', 'label' => 'Tax Total (Currency)', 'type' => 'decimal'],
            ['key' => 'grand_total_currency', 'label' => 'Grand Total (Currency)', 'type' => 'decimal'],
            ['key' => 'subtotal_base', 'label' => 'Subtotal (Base)', 'type' => 'decimal'],
            ['key' => 'discount_total_base', 'label' => 'Discount Total (Base)', 'type' => 'decimal'],
            ['key' => 'tax_total_base', 'label' => 'Tax Total (Base)', 'type' => 'decimal'],
            ['key' => 'grand_total_base', 'label' => 'Grand Total (Base)', 'type' => 'decimal'],
            ['key' => 'cogs_total_base', 'label' => 'COGS Total (Base)', 'type' => 'decimal'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ['key' => 'terms', 'label' => 'Terms', 'type' => 'text'],
            ['key' => 'posted_at', 'label' => 'Posted At', 'type' => 'text'],
            ['key' => 'voided_at', 'label' => 'Voided At', 'type' => 'text'],
            ['key' => 'void_reason', 'label' => 'Void Reason', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'sales_invoices',
            'filename' => 'sales_invoices.csv',
            'description' => 'Sales invoice master records and commercial headers',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('sales_invoices', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 21. Sales Invoice Lines
    /** @return Dataset */
    private static function salesInvoiceLinesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'sales_invoice_id', 'label' => 'Sales Invoice ID', 'type' => 'text'],
            ['key' => 'line_number', 'label' => 'Line Number', 'type' => 'text'],
            ['key' => 'product_id', 'label' => 'Product ID', 'type' => 'text'],
            ['key' => 'product_unit_id', 'label' => 'Product Unit ID', 'type' => 'text'],
            ['key' => 'item_description', 'label' => 'Description', 'type' => 'text'],
            ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal'],
            ['key' => 'quantity_base', 'label' => 'Quantity (Base)', 'type' => 'decimal'],
            ['key' => 'unit_price', 'label' => 'Unit Price', 'type' => 'decimal'],
            ['key' => 'discount_type', 'label' => 'Discount Type', 'type' => 'text'],
            ['key' => 'discount_value', 'label' => 'Discount Value', 'type' => 'decimal'],
            ['key' => 'tax_rate_snapshot', 'label' => 'Tax Rate', 'type' => 'decimal'],
            ['key' => 'tax_inclusive', 'label' => 'Tax Inclusive', 'type' => 'text'],
            ['key' => 'line_subtotal', 'label' => 'Line Subtotal', 'type' => 'decimal'],
            ['key' => 'line_discount', 'label' => 'Line Discount', 'type' => 'decimal'],
            ['key' => 'line_tax', 'label' => 'Line Tax', 'type' => 'decimal'],
            ['key' => 'line_total', 'label' => 'Line Total', 'type' => 'decimal'],
            ['key' => 'line_subtotal_base', 'label' => 'Line Subtotal (Base)', 'type' => 'decimal'],
            ['key' => 'line_discount_base', 'label' => 'Line Discount (Base)', 'type' => 'decimal'],
            ['key' => 'line_tax_base', 'label' => 'Line Tax (Base)', 'type' => 'decimal'],
            ['key' => 'line_total_base', 'label' => 'Line Total (Base)', 'type' => 'decimal'],
            ['key' => 'cogs_total_base', 'label' => 'COGS Total (Base)', 'type' => 'decimal'],
            ['key' => 'cogs_unit_base', 'label' => 'COGS Unit (Base)', 'type' => 'decimal'],
            ['key' => 'unit_conversion_ratio', 'label' => 'Conversion Ratio', 'type' => 'decimal'],
            ['key' => 'unit_name_ar', 'label' => 'Unit (Arabic)', 'type' => 'text'],
            ['key' => 'unit_name_en', 'label' => 'Unit (English)', 'type' => 'text'],
            ['key' => 'product_sku', 'label' => 'Product SKU', 'type' => 'text'],
            ['key' => 'product_name_ar', 'label' => 'Product Name (Arabic)', 'type' => 'text'],
            ['key' => 'product_name_en', 'label' => 'Product Name (English)', 'type' => 'text'],
            ['key' => 'stock_movement_id', 'label' => 'Stock Movement ID', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'sales_invoice_lines',
            'filename' => 'sales_invoice_lines.csv',
            'description' => 'Sales invoice item lines, pricing, and cost allocations',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('sales_invoice_lines', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 22. Sales Returns
    /** @return Dataset */
    private static function salesReturnsDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'return_number', 'label' => 'Return Number', 'type' => 'text'],
            ['key' => 'sales_invoice_id', 'label' => 'Sales Invoice ID', 'type' => 'text'],
            ['key' => 'customer_id', 'label' => 'Customer ID', 'type' => 'text'],
            ['key' => 'warehouse_id', 'label' => 'Warehouse ID', 'type' => 'text'],
            ['key' => 'currency_code', 'label' => 'Currency', 'type' => 'text'],
            ['key' => 'exchange_rate', 'label' => 'Exchange Rate', 'type' => 'decimal'],
            ['key' => 'issue_date', 'label' => 'Issue Date', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ['key' => 'subtotal_currency', 'label' => 'Subtotal (Currency)', 'type' => 'decimal'],
            ['key' => 'discount_total_currency', 'label' => 'Discount Total (Currency)', 'type' => 'decimal'],
            ['key' => 'tax_total_currency', 'label' => 'Tax Total (Currency)', 'type' => 'decimal'],
            ['key' => 'grand_total_currency', 'label' => 'Grand Total (Currency)', 'type' => 'decimal'],
            ['key' => 'subtotal_base', 'label' => 'Subtotal (Base)', 'type' => 'decimal'],
            ['key' => 'discount_total_base', 'label' => 'Discount Total (Base)', 'type' => 'decimal'],
            ['key' => 'tax_total_base', 'label' => 'Tax Total (Base)', 'type' => 'decimal'],
            ['key' => 'grand_total_base', 'label' => 'Grand Total (Base)', 'type' => 'decimal'],
            ['key' => 'cogs_total_base', 'label' => 'COGS Total (Base)', 'type' => 'decimal'],
            ['key' => 'reason', 'label' => 'Reason', 'type' => 'text'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ['key' => 'posted_at', 'label' => 'Posted At', 'type' => 'text'],
            ['key' => 'voided_at', 'label' => 'Voided At', 'type' => 'text'],
            ['key' => 'void_reason', 'label' => 'Void Reason', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'sales_returns',
            'filename' => 'sales_returns.csv',
            'description' => 'Customer credit notes and sales returns',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('sales_returns', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 23. Sales Return Lines
    /** @return Dataset */
    private static function salesReturnLinesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'sales_return_id', 'label' => 'Sales Return ID', 'type' => 'text'],
            ['key' => 'sales_invoice_line_id', 'label' => 'Original Invoice Line ID', 'type' => 'text'],
            ['key' => 'line_number', 'label' => 'Line Number', 'type' => 'text'],
            ['key' => 'product_id', 'label' => 'Product ID', 'type' => 'text'],
            ['key' => 'product_unit_id', 'label' => 'Product Unit ID', 'type' => 'text'],
            ['key' => 'item_description', 'label' => 'Description', 'type' => 'text'],
            ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal'],
            ['key' => 'quantity_base', 'label' => 'Quantity (Base)', 'type' => 'decimal'],
            ['key' => 'unit_price', 'label' => 'Unit Price', 'type' => 'decimal'],
            ['key' => 'tax_rate_snapshot', 'label' => 'Tax Rate', 'type' => 'decimal'],
            ['key' => 'tax_inclusive', 'label' => 'Tax Inclusive', 'type' => 'text'],
            ['key' => 'line_subtotal', 'label' => 'Line Subtotal', 'type' => 'decimal'],
            ['key' => 'line_discount', 'label' => 'Line Discount', 'type' => 'decimal'],
            ['key' => 'line_tax', 'label' => 'Line Tax', 'type' => 'decimal'],
            ['key' => 'line_total', 'label' => 'Line Total', 'type' => 'decimal'],
            ['key' => 'line_subtotal_base', 'label' => 'Line Subtotal (Base)', 'type' => 'decimal'],
            ['key' => 'line_discount_base', 'label' => 'Line Discount (Base)', 'type' => 'decimal'],
            ['key' => 'line_tax_base', 'label' => 'Line Tax (Base)', 'type' => 'decimal'],
            ['key' => 'line_total_base', 'label' => 'Line Total (Base)', 'type' => 'decimal'],
            ['key' => 'cogs_total_base', 'label' => 'COGS Total (Base)', 'type' => 'decimal'],
            ['key' => 'cogs_unit_base', 'label' => 'COGS Unit (Base)', 'type' => 'decimal'],
            ['key' => 'unit_conversion_ratio', 'label' => 'Conversion Ratio', 'type' => 'decimal'],
            ['key' => 'unit_name_ar', 'label' => 'Unit (Arabic)', 'type' => 'text'],
            ['key' => 'unit_name_en', 'label' => 'Unit (English)', 'type' => 'text'],
            ['key' => 'product_sku', 'label' => 'Product SKU', 'type' => 'text'],
            ['key' => 'product_name_ar', 'label' => 'Product Name (Arabic)', 'type' => 'text'],
            ['key' => 'product_name_en', 'label' => 'Product Name (English)', 'type' => 'text'],
            ['key' => 'lot_id', 'label' => 'Lot ID', 'type' => 'text'],
            ['key' => 'stock_movement_id', 'label' => 'Stock Movement ID', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'sales_return_lines',
            'filename' => 'sales_return_lines.csv',
            'description' => 'Sales return item line details',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('sales_return_lines', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 24. Purchases
    /** @return Dataset */
    private static function purchasesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'purchase_number', 'label' => 'Purchase Number', 'type' => 'text'],
            ['key' => 'vendor_id', 'label' => 'Vendor ID', 'type' => 'text'],
            ['key' => 'vendor_invoice_number', 'label' => 'Vendor Invoice Number', 'type' => 'text'],
            ['key' => 'warehouse_id', 'label' => 'Warehouse ID', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ['key' => 'purchase_date', 'label' => 'Purchase Date', 'type' => 'text'],
            ['key' => 'due_date', 'label' => 'Due Date', 'type' => 'text'],
            ['key' => 'currency_code', 'label' => 'Currency', 'type' => 'text'],
            ['key' => 'base_currency_code', 'label' => 'Base Currency', 'type' => 'text'],
            ['key' => 'exchange_rate', 'label' => 'Exchange Rate', 'type' => 'decimal'],
            ['key' => 'subtotal_currency', 'label' => 'Subtotal (Currency)', 'type' => 'decimal'],
            ['key' => 'discount_total_currency', 'label' => 'Discount Total (Currency)', 'type' => 'decimal'],
            ['key' => 'tax_total_currency', 'label' => 'Tax Total (Currency)', 'type' => 'decimal'],
            ['key' => 'grand_total_currency', 'label' => 'Grand Total (Currency)', 'type' => 'decimal'],
            ['key' => 'subtotal_base', 'label' => 'Subtotal (Base)', 'type' => 'decimal'],
            ['key' => 'discount_total_base', 'label' => 'Discount Total (Base)', 'type' => 'decimal'],
            ['key' => 'tax_total_base', 'label' => 'Tax Total (Base)', 'type' => 'decimal'],
            ['key' => 'grand_total_base', 'label' => 'Grand Total (Base)', 'type' => 'decimal'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ['key' => 'posted_at', 'label' => 'Posted At', 'type' => 'text'],
            ['key' => 'voided_at', 'label' => 'Voided At', 'type' => 'text'],
            ['key' => 'void_reason', 'label' => 'Void Reason', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'purchases',
            'filename' => 'purchases.csv',
            'description' => 'Vendor purchase bills and receipt headers',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('purchases', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 25. Purchase Lines
    /** @return Dataset */
    private static function purchaseLinesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'purchase_id', 'label' => 'Purchase ID', 'type' => 'text'],
            ['key' => 'line_number', 'label' => 'Line Number', 'type' => 'text'],
            ['key' => 'product_id', 'label' => 'Product ID', 'type' => 'text'],
            ['key' => 'product_unit_id', 'label' => 'Product Unit ID', 'type' => 'text'],
            ['key' => 'item_description', 'label' => 'Description', 'type' => 'text'],
            ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal'],
            ['key' => 'quantity_base', 'label' => 'Quantity (Base)', 'type' => 'decimal'],
            ['key' => 'unit_cost', 'label' => 'Unit Cost', 'type' => 'decimal'],
            ['key' => 'discount_type', 'label' => 'Discount Type', 'type' => 'text'],
            ['key' => 'discount_value', 'label' => 'Discount Value', 'type' => 'decimal'],
            ['key' => 'tax_rate_snapshot', 'label' => 'Tax Rate', 'type' => 'decimal'],
            ['key' => 'tax_inclusive', 'label' => 'Tax Inclusive', 'type' => 'text'],
            ['key' => 'line_discount', 'label' => 'Line Discount', 'type' => 'decimal'],
            ['key' => 'line_subtotal', 'label' => 'Line Subtotal', 'type' => 'decimal'],
            ['key' => 'line_tax', 'label' => 'Line Tax', 'type' => 'decimal'],
            ['key' => 'line_total', 'label' => 'Line Total', 'type' => 'decimal'],
            ['key' => 'line_subtotal_base', 'label' => 'Line Subtotal (Base)', 'type' => 'decimal'],
            ['key' => 'line_discount_base', 'label' => 'Line Discount (Base)', 'type' => 'decimal'],
            ['key' => 'line_tax_base', 'label' => 'Line Tax (Base)', 'type' => 'decimal'],
            ['key' => 'line_total_base', 'label' => 'Line Total (Base)', 'type' => 'decimal'],
            ['key' => 'unit_conversion_ratio', 'label' => 'Conversion Ratio', 'type' => 'decimal'],
            ['key' => 'inventory_unit_cost_base', 'label' => 'Inventory Unit Cost (Base)', 'type' => 'decimal'],
            ['key' => 'landed_cost_allocated_base', 'label' => 'Landed Cost Allocated', 'type' => 'decimal'],
            ['key' => 'unit_name_ar', 'label' => 'Unit (Arabic)', 'type' => 'text'],
            ['key' => 'unit_name_en', 'label' => 'Unit (English)', 'type' => 'text'],
            ['key' => 'product_sku', 'label' => 'Product SKU', 'type' => 'text'],
            ['key' => 'product_name_ar', 'label' => 'Product Name (Arabic)', 'type' => 'text'],
            ['key' => 'product_name_en', 'label' => 'Product Name (English)', 'type' => 'text'],
            ['key' => 'stock_movement_id', 'label' => 'Stock Movement ID', 'type' => 'text'],
            ['key' => 'purchase_tax_account_id', 'label' => 'Tax Account ID', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'purchase_lines',
            'filename' => 'purchase_lines.csv',
            'description' => 'Vendor purchase item line details and landed costs',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('purchase_lines', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 26. Purchase Returns
    /** @return Dataset */
    private static function purchaseReturnsDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'return_number', 'label' => 'Return Number', 'type' => 'text'],
            ['key' => 'purchase_id', 'label' => 'Original Purchase ID', 'type' => 'text'],
            ['key' => 'vendor_id', 'label' => 'Vendor ID', 'type' => 'text'],
            ['key' => 'warehouse_id', 'label' => 'Warehouse ID', 'type' => 'text'],
            ['key' => 'currency_code', 'label' => 'Currency', 'type' => 'text'],
            ['key' => 'base_currency_code', 'label' => 'Base Currency', 'type' => 'text'],
            ['key' => 'exchange_rate', 'label' => 'Exchange Rate', 'type' => 'decimal'],
            ['key' => 'return_date', 'label' => 'Return Date', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ['key' => 'subtotal_currency', 'label' => 'Subtotal (Currency)', 'type' => 'decimal'],
            ['key' => 'discount_total_currency', 'label' => 'Discount Total (Currency)', 'type' => 'decimal'],
            ['key' => 'tax_total_currency', 'label' => 'Tax Total (Currency)', 'type' => 'decimal'],
            ['key' => 'grand_total_currency', 'label' => 'Grand Total (Currency)', 'type' => 'decimal'],
            ['key' => 'subtotal_base', 'label' => 'Subtotal (Base)', 'type' => 'decimal'],
            ['key' => 'discount_total_base', 'label' => 'Discount Total (Base)', 'type' => 'decimal'],
            ['key' => 'tax_total_base', 'label' => 'Tax Total (Base)', 'type' => 'decimal'],
            ['key' => 'grand_total_base', 'label' => 'Grand Total (Base)', 'type' => 'decimal'],
            ['key' => 'reason', 'label' => 'Reason', 'type' => 'text'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ['key' => 'posted_at', 'label' => 'Posted At', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'purchase_returns',
            'filename' => 'purchase_returns.csv',
            'description' => 'Vendor return credits and debit notes',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('purchase_returns', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 27. Purchase Return Lines
    /** @return Dataset */
    private static function purchaseReturnLinesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'purchase_return_id', 'label' => 'Purchase Return ID', 'type' => 'text'],
            ['key' => 'purchase_line_id', 'label' => 'Original Line ID', 'type' => 'text'],
            ['key' => 'line_number', 'label' => 'Line Number', 'type' => 'text'],
            ['key' => 'product_id', 'label' => 'Product ID', 'type' => 'text'],
            ['key' => 'product_unit_id', 'label' => 'Product Unit ID', 'type' => 'text'],
            ['key' => 'item_description', 'label' => 'Description', 'type' => 'text'],
            ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal'],
            ['key' => 'quantity_base', 'label' => 'Quantity (Base)', 'type' => 'decimal'],
            ['key' => 'unit_cost', 'label' => 'Unit Cost', 'type' => 'decimal'],
            ['key' => 'discount_type', 'label' => 'Discount Type', 'type' => 'text'],
            ['key' => 'discount_value', 'label' => 'Discount Value', 'type' => 'decimal'],
            ['key' => 'tax_rate_snapshot', 'label' => 'Tax Rate', 'type' => 'decimal'],
            ['key' => 'tax_inclusive', 'label' => 'Tax Inclusive', 'type' => 'text'],
            ['key' => 'line_discount', 'label' => 'Line Discount', 'type' => 'decimal'],
            ['key' => 'line_subtotal', 'label' => 'Line Subtotal', 'type' => 'decimal'],
            ['key' => 'line_tax', 'label' => 'Line Tax', 'type' => 'decimal'],
            ['key' => 'line_total', 'label' => 'Line Total', 'type' => 'decimal'],
            ['key' => 'line_subtotal_base', 'label' => 'Line Subtotal (Base)', 'type' => 'decimal'],
            ['key' => 'line_discount_base', 'label' => 'Line Discount (Base)', 'type' => 'decimal'],
            ['key' => 'line_tax_base', 'label' => 'Line Tax (Base)', 'type' => 'decimal'],
            ['key' => 'line_total_base', 'label' => 'Line Total (Base)', 'type' => 'decimal'],
            ['key' => 'unit_conversion_ratio', 'label' => 'Conversion Ratio', 'type' => 'decimal'],
            ['key' => 'historical_receipt_value_base', 'label' => 'Historical Receipt Value', 'type' => 'decimal'],
            ['key' => 'inventory_value_removed_base', 'label' => 'Inventory Value Removed', 'type' => 'decimal'],
            ['key' => 'valuation_adjustment_base', 'label' => 'Valuation Adjustment', 'type' => 'decimal'],
            ['key' => 'unit_name_ar', 'label' => 'Unit (Arabic)', 'type' => 'text'],
            ['key' => 'unit_name_en', 'label' => 'Unit (English)', 'type' => 'text'],
            ['key' => 'product_sku', 'label' => 'Product SKU', 'type' => 'text'],
            ['key' => 'product_name_ar', 'label' => 'Product Name (Arabic)', 'type' => 'text'],
            ['key' => 'product_name_en', 'label' => 'Product Name (English)', 'type' => 'text'],
            ['key' => 'stock_movement_id', 'label' => 'Stock Movement ID', 'type' => 'text'],
            ['key' => 'purchase_tax_account_id', 'label' => 'Tax Account ID', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'purchase_return_lines',
            'filename' => 'purchase_return_lines.csv',
            'description' => 'Vendor return item line details',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('purchase_return_lines', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 28. Customer Payments
    /** @return Dataset */
    private static function customerPaymentsDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'payment_number', 'label' => 'Payment Number', 'type' => 'text'],
            ['key' => 'customer_id', 'label' => 'Customer ID', 'type' => 'text'],
            ['key' => 'money_account_id', 'label' => 'Money Account ID', 'type' => 'text'],
            ['key' => 'payment_date', 'label' => 'Payment Date', 'type' => 'text'],
            ['key' => 'payment_method', 'label' => 'Payment Method', 'type' => 'text'],
            ['key' => 'currency_code', 'label' => 'Currency', 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'decimal'],
            ['key' => 'exchange_rate', 'label' => 'Exchange Rate', 'type' => 'decimal'],
            ['key' => 'amount_base', 'label' => 'Amount (Base)', 'type' => 'decimal'],
            ['key' => 'reference_number', 'label' => 'Reference Number', 'type' => 'text'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ['key' => 'is_reversed', 'label' => 'Is Reversed', 'type' => 'text'],
            ['key' => 'reversed_at', 'label' => 'Reversed At', 'type' => 'text'],
            ['key' => 'reversal_reason', 'label' => 'Reversal Reason', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'customer_payments',
            'filename' => 'customer_payments.csv',
            'description' => 'Customer receipts and payment records',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('customer_payments', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 29. Customer Payment Allocations
    /** @return Dataset */
    private static function customerPaymentAllocationsDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'customer_payment_id', 'label' => 'Customer Payment ID', 'type' => 'text'],
            ['key' => 'sales_invoice_id', 'label' => 'Sales Invoice ID', 'type' => 'text'],
            ['key' => 'allocated_amount', 'label' => 'Allocated Amount', 'type' => 'decimal'],
            ['key' => 'payment_currency_amount', 'label' => 'Payment Currency Amount', 'type' => 'decimal'],
            ['key' => 'invoice_exchange_rate', 'label' => 'Invoice Exchange Rate', 'type' => 'decimal'],
            ['key' => 'payment_exchange_rate', 'label' => 'Payment Exchange Rate', 'type' => 'decimal'],
            ['key' => 'base_amount_applied_to_receivable', 'label' => 'Base Amount Applied', 'type' => 'decimal'],
            ['key' => 'settlement_base_value', 'label' => 'Settlement Base Value', 'type' => 'decimal'],
            ['key' => 'realized_fx_gain_loss_base', 'label' => 'Realized FX Gain/Loss', 'type' => 'decimal'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'customer_payment_allocations',
            'filename' => 'customer_payment_allocations.csv',
            'description' => 'Receipt-to-invoice settlements and realized FX',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('customer_payment_allocations', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 30. Vendor Payments
    /** @return Dataset */
    private static function vendorPaymentsDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'payment_number', 'label' => 'Payment Number', 'type' => 'text'],
            ['key' => 'vendor_id', 'label' => 'Vendor ID', 'type' => 'text'],
            ['key' => 'money_account_id', 'label' => 'Money Account ID', 'type' => 'text'],
            ['key' => 'payment_date', 'label' => 'Payment Date', 'type' => 'text'],
            ['key' => 'payment_method', 'label' => 'Payment Method', 'type' => 'text'],
            ['key' => 'currency_code', 'label' => 'Currency', 'type' => 'text'],
            ['key' => 'base_currency_code', 'label' => 'Base Currency', 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'decimal'],
            ['key' => 'exchange_rate', 'label' => 'Exchange Rate', 'type' => 'decimal'],
            ['key' => 'amount_base', 'label' => 'Amount (Base)', 'type' => 'decimal'],
            ['key' => 'reference_number', 'label' => 'Reference Number', 'type' => 'text'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ['key' => 'is_reversed', 'label' => 'Is Reversed', 'type' => 'text'],
            ['key' => 'reversed_at', 'label' => 'Reversed At', 'type' => 'text'],
            ['key' => 'reversal_reason', 'label' => 'Reversal Reason', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'vendor_payments',
            'filename' => 'vendor_payments.csv',
            'description' => 'Vendor disbursements and payments',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('vendor_payments', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 31. Vendor Payment Allocations
    /** @return Dataset */
    private static function vendorPaymentAllocationsDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'vendor_payment_id', 'label' => 'Vendor Payment ID', 'type' => 'text'],
            ['key' => 'purchase_id', 'label' => 'Purchase ID', 'type' => 'text'],
            ['key' => 'allocated_amount', 'label' => 'Allocated Amount', 'type' => 'decimal'],
            ['key' => 'payment_currency_amount', 'label' => 'Payment Currency Amount', 'type' => 'decimal'],
            ['key' => 'purchase_exchange_rate', 'label' => 'Purchase Exchange Rate', 'type' => 'decimal'],
            ['key' => 'payment_exchange_rate', 'label' => 'Payment Exchange Rate', 'type' => 'decimal'],
            ['key' => 'base_amount_applied_to_payable', 'label' => 'Base Amount Applied', 'type' => 'decimal'],
            ['key' => 'settlement_base_value', 'label' => 'Settlement Base Value', 'type' => 'decimal'],
            ['key' => 'realized_fx_gain_loss_base', 'label' => 'Realized FX Gain/Loss', 'type' => 'decimal'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'vendor_payment_allocations',
            'filename' => 'vendor_payment_allocations.csv',
            'description' => 'Payment-to-purchase bill settlements and realized FX',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('vendor_payment_allocations', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 32. Money Accounts
    /** @return Dataset */
    private static function moneyAccountsDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'account_type', 'label' => 'Account Type', 'type' => 'text'],
            ['key' => 'name_ar', 'label' => 'Name (Arabic)', 'type' => 'text'],
            ['key' => 'name_en', 'label' => 'Name (English)', 'type' => 'text'],
            ['key' => 'currency_code', 'label' => 'Currency', 'type' => 'text'],
            ['key' => 'ledger_account_id', 'label' => 'Ledger Account ID', 'type' => 'text'],
            ['key' => 'bank_name', 'label' => 'Bank Name', 'type' => 'text'],
            ['key' => 'account_number', 'label' => 'Account Number', 'type' => 'text'],
            ['key' => 'iban', 'label' => 'IBAN', 'type' => 'text'],
            ['key' => 'is_active', 'label' => 'Is Active', 'type' => 'text'],
            ['key' => 'sort_order', 'label' => 'Sort Order', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'money_accounts',
            'filename' => 'money_accounts.csv',
            'description' => 'Cash registers, bank accounts, and vaults',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('money_accounts', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns), softDeletes: true);
            },
        ];
    }

    // 33. Money Transfers
    /** @return Dataset */
    private static function moneyTransfersDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'transfer_number', 'label' => 'Transfer Number', 'type' => 'text'],
            ['key' => 'transfer_date', 'label' => 'Transfer Date', 'type' => 'text'],
            ['key' => 'from_money_account_id', 'label' => 'From Account ID', 'type' => 'text'],
            ['key' => 'from_currency_code', 'label' => 'From Currency', 'type' => 'text'],
            ['key' => 'from_amount', 'label' => 'From Amount', 'type' => 'decimal'],
            ['key' => 'from_exchange_rate', 'label' => 'From Exchange Rate', 'type' => 'decimal'],
            ['key' => 'to_money_account_id', 'label' => 'To Account ID', 'type' => 'text'],
            ['key' => 'to_currency_code', 'label' => 'To Currency', 'type' => 'text'],
            ['key' => 'to_amount', 'label' => 'To Amount', 'type' => 'decimal'],
            ['key' => 'to_exchange_rate', 'label' => 'To Exchange Rate', 'type' => 'decimal'],
            ['key' => 'base_currency_code', 'label' => 'Base Currency', 'type' => 'text'],
            ['key' => 'base_value_from', 'label' => 'Base Value From', 'type' => 'decimal'],
            ['key' => 'base_value_to', 'label' => 'Base Value To', 'type' => 'decimal'],
            ['key' => 'fx_gain_loss_base', 'label' => 'FX Gain/Loss (Base)', 'type' => 'decimal'],
            ['key' => 'is_reversed', 'label' => 'Is Reversed', 'type' => 'text'],
            ['key' => 'reversal_date', 'label' => 'Reversal Date', 'type' => 'text'],
            ['key' => 'reversed_at', 'label' => 'Reversed At', 'type' => 'text'],
            ['key' => 'reversal_reason', 'label' => 'Reversal Reason', 'type' => 'text'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'money_transfers',
            'filename' => 'money_transfers.csv',
            'description' => 'Internal treasury transfers and currency conversions',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('money_transfers', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 34. Checks
    /** @return Dataset */
    private static function checksDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'direction', 'label' => 'Direction', 'type' => 'text'],
            ['key' => 'check_number', 'label' => 'Check Number', 'type' => 'text'],
            ['key' => 'customer_id', 'label' => 'Customer ID', 'type' => 'text'],
            ['key' => 'vendor_id', 'label' => 'Vendor ID', 'type' => 'text'],
            ['key' => 'drawn_money_account_id', 'label' => 'Drawn Account ID', 'type' => 'text'],
            ['key' => 'bank_name', 'label' => 'Bank Name', 'type' => 'text'],
            ['key' => 'drawer', 'label' => 'Drawer', 'type' => 'text'],
            ['key' => 'currency_code', 'label' => 'Currency', 'type' => 'text'],
            ['key' => 'base_currency_code', 'label' => 'Base Currency', 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'decimal'],
            ['key' => 'exchange_rate', 'label' => 'Exchange Rate', 'type' => 'decimal'],
            ['key' => 'amount_base', 'label' => 'Amount (Base)', 'type' => 'decimal'],
            ['key' => 'received_issued_date', 'label' => 'Received/Issued Date', 'type' => 'text'],
            ['key' => 'due_date', 'label' => 'Due Date', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'checks',
            'filename' => 'checks.csv',
            'description' => 'Commercial bank checks received and issued',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('checks', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 35. Expense Categories
    /** @return Dataset */
    private static function expenseCategoriesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'code', 'label' => 'Category Code', 'type' => 'text'],
            ['key' => 'name_ar', 'label' => 'Name (Arabic)', 'type' => 'text'],
            ['key' => 'name_en', 'label' => 'Name (English)', 'type' => 'text'],
            ['key' => 'ledger_account_id', 'label' => 'Ledger Account ID', 'type' => 'text'],
            ['key' => 'active', 'label' => 'Active', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'expense_categories',
            'filename' => 'expense_categories.csv',
            'description' => 'Operating and general expense categories',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('expense_categories', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns), softDeletes: true);
            },
        ];
    }

    // 36. Expenses
    /** @return Dataset */
    private static function expensesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'expense_number', 'label' => 'Expense Number', 'type' => 'text'],
            ['key' => 'expense_date', 'label' => 'Expense Date', 'type' => 'text'],
            ['key' => 'category_id', 'label' => 'Category ID', 'type' => 'text'],
            ['key' => 'vendor_id', 'label' => 'Vendor ID', 'type' => 'text'],
            ['key' => 'payee_name', 'label' => 'Payee Name', 'type' => 'text'],
            ['key' => 'classification', 'label' => 'Classification', 'type' => 'text'],
            ['key' => 'description', 'label' => 'Description', 'type' => 'text'],
            ['key' => 'currency_code', 'label' => 'Currency', 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'decimal'],
            ['key' => 'exchange_rate', 'label' => 'Exchange Rate', 'type' => 'decimal'],
            ['key' => 'base_amount', 'label' => 'Base Amount', 'type' => 'decimal'],
            ['key' => 'payment_method', 'label' => 'Payment Method', 'type' => 'text'],
            ['key' => 'money_account_id', 'label' => 'Money Account ID', 'type' => 'text'],
            ['key' => 'check_id', 'label' => 'Check ID', 'type' => 'text'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ['key' => 'posted_at', 'label' => 'Posted At', 'type' => 'text'],
            ['key' => 'reversed_at', 'label' => 'Reversed At', 'type' => 'text'],
            ['key' => 'reversal_reason', 'label' => 'Reversal Reason', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'expenses',
            'filename' => 'expenses.csv',
            'description' => 'Posted operating expenses and vouchers',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('expenses', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 37. Ledger Accounts (Chart of Accounts)
    /** @return Dataset */
    private static function ledgerAccountsDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'code', 'label' => 'Account Code', 'type' => 'text'],
            ['key' => 'system_key', 'label' => 'System Key', 'type' => 'text'],
            ['key' => 'name_ar', 'label' => 'Name (Arabic)', 'type' => 'text'],
            ['key' => 'name_en', 'label' => 'Name (English)', 'type' => 'text'],
            ['key' => 'account_type', 'label' => 'Account Type', 'type' => 'text'],
            ['key' => 'normal_balance', 'label' => 'Normal Balance', 'type' => 'text'],
            ['key' => 'parent_id', 'label' => 'Parent ID', 'type' => 'text'],
            ['key' => 'is_control', 'label' => 'Is Control', 'type' => 'text'],
            ['key' => 'is_system', 'label' => 'Is System', 'type' => 'text'],
            ['key' => 'active', 'label' => 'Active', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'ledger_accounts',
            'filename' => 'ledger_accounts.csv',
            'description' => 'General ledger chart of accounts hierarchy',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                return self::chunkTable('ledger_accounts', $company->id, $chunkSize, array_map(fn ($c) => $c['key'], $columns));
            },
        ];
    }

    // 38. Posting Batches
    /** @return Dataset */
    private static function postingBatchesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'public_id', 'label' => 'Public ID', 'type' => 'text'],
            ['key' => 'batch_number', 'label' => 'Batch Number', 'type' => 'text'],
            ['key' => 'posting_date', 'label' => 'Posting Date', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ['key' => 'source_type', 'label' => 'Source Type', 'type' => 'text'],
            ['key' => 'source_id', 'label' => 'Source ID', 'type' => 'text'],
            ['key' => 'transaction_currency_code', 'label' => 'Transaction Currency', 'type' => 'text'],
            ['key' => 'base_currency_code', 'label' => 'Base Currency', 'type' => 'text'],
            ['key' => 'exchange_rate', 'label' => 'Exchange Rate', 'type' => 'decimal'],
            ['key' => 'description', 'label' => 'Description', 'type' => 'text'],
            ['key' => 'posted_at', 'label' => 'Posted At', 'type' => 'text'],
            ['key' => 'reversal_of_id', 'label' => 'Reversal of Batch ID', 'type' => 'text'],
            ['key' => 'reversed_by_batch_id', 'label' => 'Reversed by Batch ID', 'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'posting_batches',
            'filename' => 'posting_batches.csv',
            'description' => 'Immutable financial journal batches and events',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize) use ($columns): Generator {
                $keys = array_map(fn ($c) => $c['key'], $columns);
                $lastId = 0;
                while (true) {
                    $rows = DB::table('posting_batches')
                        ->select($keys)
                        ->where('company_id', $company->id)
                        ->where('id', '>', $lastId)
                        ->orderBy('id')
                        ->limit($chunkSize)
                        ->get();

                    if ($rows->isEmpty()) {
                        break;
                    }

                    $lastId = (int) $rows->last()->id;
                    $chunkRows = [];
                    foreach ($rows as $r) {
                        $row = (array) $r;
                        // Redact employee PII in descriptions for payroll sources
                        if (in_array($row['source_type'] ?? '', ['employee_advance', 'salary_entry', 'salary_payment', 'reversal'], true)) {
                            $row['description'] = $row['source_type'] === 'reversal'
                                ? '[REVERSAL_DESCRIPTION_REDACTED]' : '[CONFIDENTIAL_PAYROLL_ENTRY]';
                        }
                        $chunkRows[] = $row;
                    }

                    yield $chunkRows;
                }
            },
        ];
    }

    // 39. Posting Lines
    /** @return Dataset */
    private static function postingLinesDataset(): array
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'posting_batch_id', 'label' => 'Posting Batch ID', 'type' => 'text'],
            ['key' => 'ledger_account_id', 'label' => 'Ledger Account ID', 'type' => 'text'],
            ['key' => 'line_number', 'label' => 'Line Number', 'type' => 'text'],
            ['key' => 'description', 'label' => 'Description', 'type' => 'text'],
            ['key' => 'debit_base', 'label' => 'Debit (Base)', 'type' => 'decimal'],
            ['key' => 'credit_base', 'label' => 'Credit (Base)', 'type' => 'decimal'],
            ['key' => 'transaction_currency_code', 'label' => 'Transaction Currency', 'type' => 'text'],
            ['key' => 'transaction_amount', 'label' => 'Transaction Amount', 'type' => 'decimal'],
            ['key' => 'exchange_rate', 'label' => 'Exchange Rate', 'type' => 'decimal'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'text'],
        ];

        return [
            'key' => 'posting_lines',
            'filename' => 'posting_lines.csv',
            'description' => 'General ledger double-entry journal lines',
            'columns' => $columns,
            'fetch' => function (Company $company, int $chunkSize): Generator {
                $lastId = 0;
                while (true) {
                    $rows = DB::table('posting_lines as pl')
                        ->join('posting_batches as pb', 'pb.id', '=', 'pl.posting_batch_id')
                        ->select([
                            'pl.id',
                            'pl.posting_batch_id',
                            'pl.ledger_account_id',
                            'pl.line_number',
                            'pl.description',
                            'pl.debit_base',
                            'pl.credit_base',
                            'pl.transaction_currency_code',
                            'pl.transaction_amount',
                            'pl.exchange_rate',
                            'pl.created_at',
                            'pb.source_type as _batch_source_type',
                        ])
                        ->where('pl.company_id', $company->id)
                        ->where('pb.company_id', $company->id)
                        ->where('pl.id', '>', $lastId)
                        ->orderBy('pl.id')
                        ->limit($chunkSize)
                        ->get();

                    if ($rows->isEmpty()) {
                        break;
                    }

                    $lastId = (int) $rows->last()->id;
                    $chunkRows = [];
                    foreach ($rows as $r) {
                        // Reversal reasons can repeat confidential payroll provenance.
                        $isPayroll = in_array($r->_batch_source_type, ['employee_advance', 'salary_entry', 'salary_payment', 'reversal'], true);
                        $chunkRows[] = [
                            'id' => (string) $r->id,
                            'posting_batch_id' => (string) $r->posting_batch_id,
                            'ledger_account_id' => (string) $r->ledger_account_id,
                            'line_number' => (string) $r->line_number,
                            'description' => $isPayroll
                                ? ($r->_batch_source_type === 'reversal' ? '[REVERSAL_DESCRIPTION_REDACTED]' : '[CONFIDENTIAL_PAYROLL_ENTRY]')
                                : (string) ($r->description ?? ''),
                            'debit_base' => (string) $r->debit_base,
                            'credit_base' => (string) $r->credit_base,
                            'transaction_currency_code' => (string) ($r->transaction_currency_code ?? ''),
                            'transaction_amount' => $r->transaction_amount === null ? null : (string) $r->transaction_amount,
                            'exchange_rate' => $r->exchange_rate === null ? null : (string) $r->exchange_rate,
                            'created_at' => (string) ($r->created_at ?? ''),
                        ];
                    }

                    yield $chunkRows;
                }
            },
        ];
    }

    // 40. Trial Balance (Financial Statement Projection)
    /** @return Dataset */
    private static function trialBalanceDataset(): array
    {
        return [
            'key' => 'trial_balance',
            'filename' => 'trial_balance.csv',
            'description' => 'Authoritative cumulative Trial Balance as of export',
            'columns' => [
                ['key' => 'account_id', 'label' => 'Account ID', 'type' => 'text'],
                ['key' => 'account_code', 'label' => 'Account Code', 'type' => 'text'],
                ['key' => 'name_ar', 'label' => 'Name (Arabic)', 'type' => 'text'],
                ['key' => 'name_en', 'label' => 'Name (English)', 'type' => 'text'],
                ['key' => 'account_type', 'label' => 'Account Type', 'type' => 'text'],
                ['key' => 'normal_balance', 'label' => 'Normal Balance', 'type' => 'text'],
                ['key' => 'total_debit', 'label' => 'Total Debit (Base)', 'type' => 'decimal'],
                ['key' => 'total_credit', 'label' => 'Total Credit (Base)', 'type' => 'decimal'],
                ['key' => 'balance', 'label' => 'Net Balance (Base)', 'type' => 'decimal'],
            ],
            'fetch' => function (Company $company, int $chunkSize): Generator {
                // Ensure active company context is aligned with the export company
                $context = app(CompanyContext::class);
                if (! $context->hasCompany() || $context->companyId() !== $company->id) {
                    throw new AuthorizationException('Active company does not match export company.');
                }

                $statement = app(DerivedLedgerBalanceService::class)->getTrialBalance($company);
                $chunkRows = [];
                foreach ($statement['rows'] as $r) {
                    $chunkRows[] = [
                        'account_id' => (string) $r['account_id'],
                        'account_code' => (string) $r['code'],
                        'name_ar' => (string) $r['name_ar'],
                        'name_en' => (string) ($r['name_en'] ?? ''),
                        'account_type' => (string) $r['account_type'],
                        'normal_balance' => (string) $r['normal_balance'],
                        'total_debit' => (string) $r['total_debit'],
                        'total_credit' => (string) $r['total_credit'],
                        'balance' => (string) $r['balance'],
                    ];
                    if (count($chunkRows) >= $chunkSize) {
                        yield $chunkRows;
                        $chunkRows = [];
                    }
                }
                if (! empty($chunkRows)) {
                    yield $chunkRows;
                }
            },
        ];
    }

    /**
     * Chunk standard company-scoped tables efficiently by ID with explicit field selection.
     *
     * @param  list<string>  $columns
     * @return Generator<list<array<string, mixed>>>
     */
    private static function chunkTable(string $table, int $companyId, int $chunkSize, array $columns, bool $softDeletes = false): Generator
    {
        $lastId = 0;
        while (true) {
            $query = DB::table($table)
                ->select($columns)
                ->where('company_id', $companyId)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunkSize);

            $rows = $query->get();
            if ($rows->isEmpty()) {
                break;
            }

            $lastId = (int) $rows->last()->id;
            yield $rows->map(fn ($r) => (array) $r)->all();
        }
    }
}
