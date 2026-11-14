<?php

declare(strict_types=1);

namespace Tests\Feature\Money;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Domain\Posting\Exceptions\PostingValidationException;
use App\Domain\Sales\Calculators\SalesLineCalculationInput;
use App\Domain\Sales\Calculators\SalesLineCalculator;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyUser;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\CheckHistory;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Money\CustomerPaymentHistory;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Money\MoneyValues;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\DraftInvoiceIntegrity;
use App\Services\Sales\ReceiptPreview;
use App\Services\Sales\SalesCorrectionAudit;
use App\Services\Sales\SalesDocumentRules;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class CurrencyMinorUnitQualificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Customer $customer;

    protected CurrencyMinorUnitsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['locale' => 'en']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة مؤهلة للعملات',
            'name_en' => 'Currency Qualified Trader',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل اختبار العملة',
            'name_en' => 'Currency Test Customer',
            'preferred_locale' => 'en',
            'created_by' => $this->owner->id,
        ]);

        $this->resolver = app(CurrencyMinorUnitsResolver::class);
    }

    public function test_authoritative_resolver_resolves_standard_currencies(): void
    {
        $this->assertSame(2, $this->resolver->resolve('ILS'));
        $this->assertSame(2, $this->resolver->resolve('USD'));
        $this->assertSame(3, $this->resolver->resolve('JOD'));
    }

    public function test_authoritative_resolver_resolves_0_minor_3_minor_and_supported_higher_precision(): void
    {
        // 3 minor units (non-JOD: KWD, BHD, OMR)
        Currency::updateOrInsert(['code' => 'KWD'], ['name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'minor_units' => 3, 'active' => true]);
        Currency::updateOrInsert(['code' => 'BHD'], ['name' => 'Bahraini Dinar', 'symbol' => 'BD', 'minor_units' => 3, 'active' => true]);
        Currency::updateOrInsert(['code' => 'OMR'], ['name' => 'Omani Rial', 'symbol' => 'OMR', 'minor_units' => 3, 'active' => true]);

        // 0 minor units (JPY)
        Currency::updateOrInsert(['code' => 'JPY'], ['name' => 'Japanese Yen', 'symbol' => '¥', 'minor_units' => 0, 'active' => true]);

        // Higher precision within schema decimal(20, 6)
        Currency::updateOrInsert(['code' => 'CLF'], ['name' => 'Unidad de Fomento', 'symbol' => 'UF', 'minor_units' => 4, 'active' => true]);
        Currency::updateOrInsert(['code' => 'HI6'], ['name' => 'High Six Precision', 'symbol' => 'H6', 'minor_units' => 6, 'active' => true]);

        $this->assertSame(3, $this->resolver->resolve('KWD'));
        $this->assertSame(3, $this->resolver->resolve('BHD'));
        $this->assertSame(3, $this->resolver->resolve('OMR'));
        $this->assertSame(0, $this->resolver->resolve('JPY'));
        $this->assertSame(4, $this->resolver->resolve('CLF'));
        $this->assertSame(6, $this->resolver->resolve('HI6'));
    }

    public function test_authoritative_resolver_rejects_unknown_and_inactive_master_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown currency [XYZ].');
        $this->resolver->resolve('XYZ');
    }

    public function test_authoritative_resolver_rejects_empty_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency code is required.');
        $this->resolver->resolve('   ');
    }

    public function test_authoritative_resolver_rejects_inactive_master_currency(): void
    {
        Currency::updateOrInsert(['code' => 'INA'], ['name' => 'Inactive Currency', 'symbol' => 'INA', 'minor_units' => 2, 'active' => false]);

        // Historical resolution succeeds
        $this->assertSame(2, $this->resolver->resolve('INA'));

        // New transaction eligibility rejects inactive currency
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency [INA] is inactive.');
        $this->resolver->resolveForCompany($this->company, 'INA');
    }

    public function test_authoritative_resolver_rejects_minor_units_outside_supported_domain_0_to_6(): void
    {
        Currency::updateOrInsert(['code' => 'INV'], ['name' => 'Invalid Scale Currency', 'symbol' => 'INV', 'minor_units' => 7, 'active' => true]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Supported range is 0 to 6.');
        $this->resolver->resolve('INV');
    }

    public function test_authoritative_resolver_enforces_company_enabled_currency(): void
    {
        Currency::updateOrInsert(['code' => 'KWD'], ['name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'minor_units' => 3, 'active' => true]);

        // KWD not yet added to company
        try {
            $this->resolver->resolveForCompany($this->company, 'KWD');
            $this->fail('Expected InvalidArgumentException for unenabled company currency.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not enabled for company', $e->getMessage());
        }

        // Add KWD but with enabled = false
        CompanyCurrency::create([
            'company_id' => $this->company->id,
            'currency_code' => 'KWD',
            'enabled' => false,
            'is_base' => false,
            'display_order' => 10,
        ]);

        try {
            $this->resolver->resolveForCompany($this->company, 'KWD');
            $this->fail('Expected InvalidArgumentException for disabled company currency.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not enabled for company', $e->getMessage());
        }

        // Enable KWD for company
        CompanyCurrency::where('company_id', $this->company->id)->where('currency_code', 'KWD')->update(['enabled' => true]);
        $this->resolver->flush();
        $this->assertSame(3, $this->resolver->resolveForCompany($this->company, 'KWD'));
    }

    public function test_money_values_amount_exact_unnecessary_precision_and_trailing_zeros(): void
    {
        Currency::updateOrInsert(['code' => 'KWD'], ['name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'minor_units' => 3, 'active' => true]);
        Currency::updateOrInsert(['code' => 'JPY'], ['name' => 'Japanese Yen', 'symbol' => '¥', 'minor_units' => 0, 'active' => true]);
        Currency::updateOrInsert(['code' => 'CLF'], ['name' => 'Unidad de Fomento', 'symbol' => 'UF', 'minor_units' => 4, 'active' => true]);

        // 2 minor units (ILS):
        $val2 = MoneyValues::amount('10.50', 'ILS');
        $this->assertTrue($val2->isEqualTo('10.5'));
        $this->assertSame('10.500000', (string) $val2);

        // Trailing zeros accepted
        $val2Zeros = MoneyValues::amount('10.500000', 'ILS');
        $this->assertTrue($val2Zeros->isEqualTo('10.5'));

        // Overprecision rejected without silent rounding
        try {
            MoneyValues::amount('10.501', 'ILS');
            $this->fail('Expected InvalidArgumentException on 3rd decimal for 2-minor currency.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Amount must match the currency minor-unit precision exactly.', $e->getMessage());
        }

        // Exact partial receipt 0.000001 ILS reject
        try {
            MoneyValues::amount('0.000001', 'ILS');
            $this->fail('Expected InvalidArgumentException for 0.000001 ILS.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Amount must match the currency minor-unit precision exactly.', $e->getMessage());
        }

        // 3 minor units (KWD): accepts up to 3 decimals, rejects 4th
        $val3 = MoneyValues::amount('25.125', 'KWD');
        $this->assertTrue($val3->isEqualTo('25.125'));
        $val3Zeros = MoneyValues::amount('25.125000', 'KWD');
        $this->assertTrue($val3Zeros->isEqualTo('25.125'));
        try {
            MoneyValues::amount('25.1251', 'KWD');
            $this->fail('Expected overprecision rejection for 4th decimal in 3-minor currency.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Amount must match the currency minor-unit precision exactly.', $e->getMessage());
        }

        // 0 minor units (JPY): accepts integer, rejects any decimal
        $val0 = MoneyValues::amount('1500', 'JPY');
        $this->assertTrue($val0->isEqualTo('1500'));
        $val0Zeros = MoneyValues::amount('1500.00', 'JPY');
        $this->assertTrue($val0Zeros->isEqualTo('1500'));
        try {
            MoneyValues::amount('1500.1', 'JPY');
            $this->fail('Expected overprecision rejection for decimal in 0-minor currency.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Amount must match the currency minor-unit precision exactly.', $e->getMessage());
        }

        // 4 minor units (CLF): accepts up to 4 decimals, rejects 5th
        $val4 = MoneyValues::amount('1.2345', 'CLF');
        $this->assertTrue($val4->isEqualTo('1.2345'));
        try {
            MoneyValues::amount('1.23456', 'CLF');
            $this->fail('Expected overprecision rejection for 5th decimal in 4-minor currency.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Amount must match the currency minor-unit precision exactly.', $e->getMessage());
        }
    }

    public function test_sales_document_rules_price_permission_with_packaging_conversion_and_canonical_minor_units(): void
    {
        Currency::updateOrInsert(['code' => 'KWD'], ['name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'minor_units' => 3, 'active' => true]);
        Currency::updateOrInsert(['code' => 'JPY'], ['name' => 'Japanese Yen', 'symbol' => '¥', 'minor_units' => 0, 'active' => true]);

        CompanyCurrency::create(['company_id' => $this->company->id, 'currency_code' => 'KWD', 'enabled' => true, 'is_base' => false, 'display_order' => 5]);
        CompanyCurrency::create(['company_id' => $this->company->id, 'currency_code' => 'JPY', 'enabled' => true, 'is_base' => false, 'display_order' => 6]);

        $baseUnit = Unit::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'piece'],
            ['name_ar' => 'قطعة', 'name_en' => 'Piece', 'symbol' => 'pc', 'allow_decimal' => false, 'active' => true]
        );
        $boxUnit = Unit::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'box'],
            ['name_ar' => 'صندوق', 'name_en' => 'Box', 'symbol' => 'bx', 'allow_decimal' => false, 'active' => true]
        );

        $product = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'PRD-CUR-1',
            'name_ar' => 'منتج تسعير',
            'name_en' => 'Pricing Product',
            'product_type' => 'stock',
            'base_unit_id' => $baseUnit->id,
            'active' => true,
            'default_sale_price_base' => '100.000000',
            'created_by' => $this->owner->id,
        ]);

        // Base unit: Piece
        $piece = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $baseUnit->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'active' => true,
            'default_sale_price_base' => '100.000000',
        ]);

        // Packaging unit: Box of 12 (suggestion = 100 * 12 = 1200 base)
        $box = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'conversion_to_base' => '12.000000',
            'is_base' => false,
            'is_default_sale' => false,
            'is_default_purchase' => false,
            'active' => true,
            'default_sale_price_base' => null, // inherits 100 * 12 = 1200
        ]);

        $rules = app(SalesDocumentRules::class);

        // Case 1: ILS (2 minor units), rate 1.0. Piece price = 100.00.
        // Without change_price permission:
        $rules->price($product, $piece, $this->owner, BigDecimal::one(), 'ILS', BigDecimal::of('100.00'));

        // Packaging conversion: Box of 12 = 1200.00
        $rules->price($product, $box, $this->owner, BigDecimal::one(), 'ILS', BigDecimal::of('1200.00'));

        // Case 2: JOD (3 minor units), rate 0.20 (suggestion = 100 / 0.20 = 500.000)
        $rules->price($product, $piece, $this->owner, BigDecimal::of('0.20'), 'JOD', BigDecimal::of('500.000'));

        // Case 3: KWD (3 minor units), rate 0.08 (suggestion = 100 / 0.08 = 1250.000)
        $rules->price($product, $piece, $this->owner, BigDecimal::of('0.08'), 'KWD', BigDecimal::of('1250.000'));

        // Case 4: JPY (0 minor units), rate 40 (suggestion = 100 / 40 = 2.5 -> scale 0 HALF_UP = 3)
        $rules->price($product, $piece, $this->owner, BigDecimal::of('40'), 'JPY', BigDecimal::of('3'));

        // User without change_price permission trying to use non-matching price -> throws AuthorizationException
        $restrictedUser = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $restrictedUser->id,
            'status' => 'active',
            'is_owner' => false,
        ]);

        try {
            $rules->price($product, $piece, $restrictedUser, BigDecimal::one(), 'ILS', BigDecimal::of('99.00'));
            $this->fail('Expected AuthorizationException for restricted user changing price.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Manual price permission is required', $e->getMessage());
        }

        // Grant permission to change price
        Permission::findOrCreate('sales.invoice.change_price');
        $restrictedUser->givePermissionTo('sales.invoice.change_price');

        // Now price check passes even with arbitrary price
        $rules->price($product, $piece, $restrictedUser, BigDecimal::one(), 'ILS', BigDecimal::of('85.50'));
    }

    public function test_sales_line_calculator_inclusive_tax_and_zero_value_items(): void
    {
        $calculator = app(SalesLineCalculator::class);

        // Required prompt assertion: inclusive ILS 50 @ 16 tax 6.90
        $inputInclusive50 = new SalesLineCalculationInput(
            quantity: '1',
            unitPrice: '50.00',
            discountType: null,
            discountValue: '0',
            taxRate: '16.00',
            taxInclusive: true,
            currencyMinorUnits: 2,
            exchangeRate: '1.00',
            baseCurrencyMinorUnits: 2
        );
        $resultInclusive50 = $calculator->calculate($inputInclusive50);
        $this->assertSame('6.900000', (string) $resultInclusive50->tax);
        $this->assertSame('6.90', (string) $resultInclusive50->tax->toScale(2));
        $this->assertSame('43.100000', (string) $resultInclusive50->netBeforeTax);
        $this->assertSame('43.10', (string) $resultInclusive50->netBeforeTax->toScale(2));
        $this->assertSame('50.000000', (string) $resultInclusive50->total);
        $this->assertSame('50.00', (string) $resultInclusive50->total->toScale(2));

        // Non-JOD 3-minor inclusive tax (KWD @ 15%): KWD 100.000 inclusive @ 15%
        // 100 / 1.15 = 86.9565217... -> scale 3 HALF_UP = 86.957 net, tax = 13.043
        $inputKwd = new SalesLineCalculationInput(
            quantity: '1',
            unitPrice: '100.000',
            discountType: null,
            discountValue: '0',
            taxRate: '15.00',
            taxInclusive: true,
            currencyMinorUnits: 3,
            exchangeRate: '1.00',
            baseCurrencyMinorUnits: 2
        );
        $resultKwd = $calculator->calculate($inputKwd);
        $this->assertSame('13.043000', (string) $resultKwd->tax);
        $this->assertSame('13.043', (string) $resultKwd->tax->toScale(3));
        $this->assertSame('86.957000', (string) $resultKwd->netBeforeTax);
        $this->assertSame('86.957', (string) $resultKwd->netBeforeTax->toScale(3));
        $this->assertSame('100.000000', (string) $resultKwd->total);
        $this->assertSame('100.000', (string) $resultKwd->total->toScale(3));

        // 0-minor units (JPY @ 10%): JPY 1050 inclusive @ 10%
        // 1050 / 1.10 = 954.5454... -> scale 0 HALF_UP = 955 net, tax = 95
        $inputJpy = new SalesLineCalculationInput(
            quantity: '1',
            unitPrice: '1050',
            discountType: null,
            discountValue: '0',
            taxRate: '10.00',
            taxInclusive: true,
            currencyMinorUnits: 0,
            exchangeRate: '1.00',
            baseCurrencyMinorUnits: 2
        );
        $resultJpy = $calculator->calculate($inputJpy);
        $this->assertSame('95.000000', (string) $resultJpy->tax);
        $this->assertSame('95', (string) $resultJpy->tax->toScale(0));
        $this->assertSame('955.000000', (string) $resultJpy->netBeforeTax);
        $this->assertSame('955', (string) $resultJpy->netBeforeTax->toScale(0));
        $this->assertSame('1050.000000', (string) $resultJpy->total);
        $this->assertSame('1050', (string) $resultJpy->total->toScale(0));

        // Legitimate zero-value item policy unchanged (free sample or promotional warranty replacement)
        $inputZero = new SalesLineCalculationInput(
            quantity: '5',
            unitPrice: '0.00',
            discountType: null,
            discountValue: '0',
            taxRate: '16.00',
            taxInclusive: false,
            currencyMinorUnits: 2,
            exchangeRate: '1.00',
            baseCurrencyMinorUnits: 2
        );
        $resultZero = $calculator->calculate($inputZero);
        $this->assertSame('0.000000', (string) $resultZero->subtotal);
        $this->assertSame('0.000000', (string) $resultZero->tax);
        $this->assertSame('0.000000', (string) $resultZero->total);
    }

    public function test_post_customer_payment_rejects_overprecision_and_disabled_company_currency(): void
    {
        $cash = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, [
            'account_type' => 'cash',
            'name_ar' => 'صندوق شيكل',
            'name_en' => 'Cash ILS',
            'currency_code' => 'ILS',
        ]);

        $action = app(PostCustomerPaymentAction::class);

        // Test rejection of overprecision: 0.000001 ILS
        try {
            $action->execute($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'money_account_id' => $cash->id,
                'payment_date' => '2026-10-15',
                'payment_method' => 'cash',
                'amount' => '0.000001',
                'exchange_rate' => '1.0000000000',
                'idempotency_key' => 'rcpt-prec-1',
                'allocations' => [],
            ]);
            $this->fail('Expected InvalidArgumentException for 0.000001 ILS payment precision.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('precision exceeds 2 decimals', $e->getMessage());
        }

        // Test acceptance of trailing zeros: 100.500000 ILS
        $payment = $action->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $cash->id,
            'payment_date' => '2026-10-15',
            'payment_method' => 'cash',
            'amount' => '100.500000',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'rcpt-zeros-1',
            'allocations' => [],
        ]);

        $this->assertSame('100.500000', (string) $payment->amount);
        $this->assertFalse($payment->is_reversed);
        $this->assertNotNull($payment->posting_batch_id);

        // Test rejection of disabled / revoked company currency
        Currency::updateOrInsert(['code' => 'KWD'], ['name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'minor_units' => 3, 'active' => true]);
        CompanyCurrency::create([
            'company_id' => $this->company->id,
            'currency_code' => 'KWD',
            'enabled' => true,
            'is_base' => false,
            'display_order' => 20,
        ]);
        $kwdAccount = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, [
            'account_type' => 'cash',
            'name_ar' => 'صندوق دينار كويتي',
            'name_en' => 'Cash KWD',
            'currency_code' => 'KWD',
        ]);

        // Revoke KWD for company
        CompanyCurrency::where('company_id', $this->company->id)->where('currency_code', 'KWD')->update(['enabled' => false]);

        try {
            $action->execute($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'money_account_id' => $kwdAccount->id,
                'payment_date' => '2026-10-15',
                'payment_method' => 'cash',
                'amount' => '50.000',
                'exchange_rate' => '12.0000000000',
                'idempotency_key' => 'rcpt-revoked-kwd',
                'allocations' => [],
            ]);
            $this->fail('Expected InvalidArgumentException for revoked company currency.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Receipt currency is not enabled', $e->getMessage());
        }
    }

    public function test_end_to_end_sales_invoice_posting_and_receipt_across_diverse_minor_units(): void
    {
        // Setup KWD (3 minor units) and JPY (0 minor units)
        Currency::updateOrInsert(['code' => 'KWD'], ['name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'minor_units' => 3, 'active' => true]);
        Currency::updateOrInsert(['code' => 'JPY'], ['name' => 'Japanese Yen', 'symbol' => '¥', 'minor_units' => 0, 'active' => true]);

        CompanyCurrency::create(['company_id' => $this->company->id, 'currency_code' => 'KWD', 'enabled' => true, 'is_base' => false, 'display_order' => 11]);
        CompanyCurrency::create(['company_id' => $this->company->id, 'currency_code' => 'JPY', 'enabled' => true, 'is_base' => false, 'display_order' => 12]);

        $tax = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT16',
            'name_ar' => 'ضريبة 16%',
            'name_en' => 'VAT 16%',
            'rate' => '16.000000',
            'calculation' => TaxRate::CALC_EXCLUSIVE,
            'active' => true,
        ]);

        // 1. Create and post invoice in KWD (3 minor units): unit price 50.125, qty 2 = 100.250 subtotal, tax 16% = 16.040, grand total = 116.290
        $kwdDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'KWD',
            'exchange_rate' => '12.0000000000',
            'issue_date' => '2026-10-18',
            'lines' => [
                [
                    'item_description' => 'Kuwait Consulting Work',
                    'quantity' => '2',
                    'unit_price' => '50.125',
                    'tax_rate_id' => $tax->id,
                ],
            ],
        ]);

        $this->assertSame('100.250000', (string) $kwdDraft->subtotal_currency);
        $this->assertSame('16.040000', (string) $kwdDraft->tax_total_currency);
        $this->assertSame('116.290000', (string) $kwdDraft->grand_total_currency);

        // Verify DraftInvoiceIntegrity does not fail for non-JOD 3-minor currency KWD!
        app(DraftInvoiceIntegrity::class)->validate($kwdDraft, $kwdDraft->lines);

        // Post KWD invoice
        $kwdPosted = app(PostSalesInvoiceAction::class)->execute($kwdDraft, $this->owner);
        $this->assertTrue($kwdPosted->isPosted());
        $this->assertNotNull($kwdPosted->posting_batch_id);

        // Pay KWD invoice with exact 3-minor receipt
        $kwdCash = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, [
            'account_type' => 'cash',
            'name_ar' => 'صندوق كويتي',
            'name_en' => 'Cash KWD Account',
            'currency_code' => 'KWD',
        ]);

        $kwdPayment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $kwdCash->id,
            'payment_date' => '2026-10-18',
            'payment_method' => 'cash',
            'amount' => '116.290',
            'exchange_rate' => '12.0000000000',
            'idempotency_key' => 'rcpt-kwd-full-1',
            'allocations' => [
                [
                    'sales_invoice_id' => $kwdPosted->id,
                    'allocated_amount' => '116.290',
                ],
            ],
        ]);

        $this->assertSame('116.290000', (string) $kwdPayment->amount);
        $this->assertSame('0.000000', (string) $kwdPayment->unallocated_amount);
        $this->assertSame('0.000000', (string) $kwdPosted->fresh()->calculateOutstanding());

        // 2. Create and post invoice in JPY (0 minor units): unit price 5000, qty 3 = 15000 subtotal, 0 tax
        $jpyDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'JPY',
            'exchange_rate' => '0.0250000000',
            'issue_date' => '2026-10-19',
            'lines' => [
                [
                    'item_description' => 'Tokyo Electronics',
                    'quantity' => '3',
                    'unit_price' => '5000',
                ],
            ],
        ]);

        $this->assertSame('15000.000000', (string) $jpyDraft->grand_total_currency);
        app(DraftInvoiceIntegrity::class)->validate($jpyDraft, $jpyDraft->lines);

        $jpyPosted = app(PostSalesInvoiceAction::class)->execute($jpyDraft, $this->owner);
        $this->assertTrue($jpyPosted->isPosted());

        // Pay JPY invoice with 0-minor receipt
        $jpyCash = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, [
            'account_type' => 'cash',
            'name_ar' => 'صندوق ين',
            'name_en' => 'Cash JPY Account',
            'currency_code' => 'JPY',
        ]);

        $jpyPayment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $jpyCash->id,
            'payment_date' => '2026-10-19',
            'payment_method' => 'cash',
            'amount' => '15000',
            'exchange_rate' => '0.0250000000',
            'idempotency_key' => 'rcpt-jpy-full-1',
            'allocations' => [
                [
                    'sales_invoice_id' => $jpyPosted->id,
                    'allocated_amount' => '15000',
                ],
            ],
        ]);

        $this->assertSame('15000.000000', (string) $jpyPayment->amount);
        $this->assertSame('0.000000', (string) $jpyPayment->unallocated_amount);
        $this->assertSame('0.000000', (string) $jpyPosted->fresh()->calculateOutstanding());
    }

    public function test_receipt_preview_scales_allocated_and_unallocated_to_canonical_minor_units(): void
    {
        Currency::updateOrInsert(['code' => 'KWD'], ['name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'minor_units' => 3, 'active' => true]);
        Currency::updateOrInsert(['code' => 'JPY'], ['name' => 'Japanese Yen', 'symbol' => '¥', 'minor_units' => 0, 'active' => true]);

        $preview = app(ReceiptPreview::class);

        // ILS: 2 minor units
        $resIls = $preview->calculate('100.00', '1.0', [
            ['allocated_amount' => '40.00', 'invoice_exchange_rate' => '1.0'],
        ], 'ILS');
        $this->assertSame('40.00', $resIls['allocated']);
        $this->assertSame('60.00', $resIls['unallocated']);

        // JOD: 3 minor units
        $resJod = $preview->calculate('100.000', '1.0', [
            ['allocated_amount' => '40.125', 'invoice_exchange_rate' => '1.0'],
        ], 'JOD');
        $this->assertSame('40.125', $resJod['allocated']);
        $this->assertSame('59.875', $resJod['unallocated']);

        // KWD: 3 minor units
        $resKwd = $preview->calculate('50.000', '12.0', [
            ['allocated_amount' => '15.500', 'invoice_exchange_rate' => '12.0'],
        ], 'KWD');
        $this->assertSame('15.500', $resKwd['allocated']);
        $this->assertSame('34.500', $resKwd['unallocated']);

        // JPY: 0 minor units
        $resJpy = $preview->calculate('1000', '0.025', [
            ['allocated_amount' => '300', 'invoice_exchange_rate' => '0.025'],
        ], 'JPY');
        $this->assertSame('300', $resJpy['allocated']);
        $this->assertSame('700', $resJpy['unallocated']);
    }

    public function test_authoritative_resolver_distinguishes_historical_read_from_new_transaction_eligibility(): void
    {
        Currency::updateOrInsert(['code' => 'RET'], ['name' => 'Retired Currency', 'symbol' => 'RT', 'minor_units' => 3, 'active' => false]);
        CompanyCurrency::create([
            'company_id' => $this->company->id,
            'currency_code' => 'RET',
            'enabled' => false,
            'is_base' => false,
            'display_order' => 20,
        ]);

        // Historical read paths must succeed
        $this->assertSame(3, $this->resolver->resolve('RET'));
        $this->assertSame(3, $this->resolver->resolveHistorical('RET'));
        $moneyAmount = MoneyValues::amount('15.125', 'RET');
        $this->assertSame('15.125000', (string) $moneyAmount);

        // New transaction paths must reject
        try {
            $this->resolver->resolveForCompany($this->company, 'RET');
            $this->fail('Expected InvalidArgumentException for inactive master currency in new transactions.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Currency [RET] is inactive.', $e->getMessage());
        }

        // Active master but disabled company currency
        Currency::updateOrInsert(['code' => 'DIS'], ['name' => 'Disabled For Tenant', 'symbol' => 'DS', 'minor_units' => 0, 'active' => true]);
        CompanyCurrency::create([
            'company_id' => $this->company->id,
            'currency_code' => 'DIS',
            'enabled' => false,
            'is_base' => false,
            'display_order' => 21,
        ]);

        // Historical read succeeds
        $this->assertSame(0, $this->resolver->resolve('DIS'));
        $this->assertSame('500.000000', (string) MoneyValues::amount('500', 'DIS'));

        // Tenant transaction fails
        try {
            $this->resolver->resolveForCompany($this->company, 'DIS');
            $this->fail('Expected InvalidArgumentException for disabled company currency.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Currency [DIS] is not enabled for company', $e->getMessage());
        }
    }

    public function test_historical_reads_and_audits_preserve_retired_and_disabled_currencies(): void
    {
        Currency::updateOrInsert(['code' => 'KWD'], ['name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'minor_units' => 3, 'active' => true]);
        Currency::updateOrInsert(['code' => 'JPY'], ['name' => 'Japanese Yen', 'symbol' => '¥', 'minor_units' => 0, 'active' => true]);

        // Enable KWD and JPY
        CompanyCurrency::updateOrInsert(['company_id' => $this->company->id, 'currency_code' => 'KWD'], ['enabled' => true, 'is_base' => false, 'display_order' => 11]);
        CompanyCurrency::updateOrInsert(['company_id' => $this->company->id, 'currency_code' => 'JPY'], ['enabled' => true, 'is_base' => false, 'display_order' => 12]);

        $tax = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT16_HIST',
            'name_ar' => 'ضريبة 16%',
            'name_en' => 'VAT 16%',
            'rate' => '16.000000',
            'calculation' => TaxRate::CALC_EXCLUSIVE,
            'active' => true,
        ]);

        // Post KWD invoice
        $kwdDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'KWD',
            'exchange_rate' => '12.0000000000',
            'issue_date' => '2026-10-21',
            'lines' => [
                [
                    'item_description' => 'Historical KWD Service',
                    'quantity' => '2',
                    'unit_price' => '50.125',
                    'tax_rate_id' => $tax->id,
                ],
            ],
        ]);
        $kwdPosted = app(PostSalesInvoiceAction::class)->execute($kwdDraft, $this->owner);

        // Post JPY invoice
        $jpyDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'JPY',
            'exchange_rate' => '0.0250000000',
            'issue_date' => '2026-10-21',
            'lines' => [
                [
                    'item_description' => 'Historical JPY Goods',
                    'quantity' => '1',
                    'unit_price' => '2500',
                ],
            ],
        ]);
        $jpyPosted = app(PostSalesInvoiceAction::class)->execute($jpyDraft, $this->owner);

        // Post KWD customer payment
        $kwdCash = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, [
            'account_type' => 'cash',
            'name_ar' => 'صندوق دينار تاريخي',
            'name_en' => 'Historical KWD Cash',
            'currency_code' => 'KWD',
        ]);
        $kwdPayment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $kwdCash->id,
            'payment_date' => '2026-10-21',
            'payment_method' => 'cash',
            'amount' => '116.290',
            'exchange_rate' => '12.0000000000',
            'idempotency_key' => 'rcpt-kwd-hist-1',
            'allocations' => [
                [
                    'sales_invoice_id' => $kwdPosted->id,
                    'allocated_amount' => '116.290',
                ],
            ],
        ]);

        // Receive incoming check in KWD
        Permission::findOrCreate('money.check.incoming.manage');
        Permission::findOrCreate('money.receipt.create');
        $this->owner->givePermissionTo(['money.check.incoming.manage', 'money.receipt.create']);
        $kwdCheck = app(ReceiveCheckAction::class)->execute($this->company, $this->owner, [
            'idempotency_key' => 'chk-kwd-hist-1',
            'currency_code' => 'KWD',
            'amount' => '50.000',
            'exchange_rate' => '12.0000000000',
            'check_number' => 'CHK-KWD-001',
            'bank_name' => 'National Bank of Kuwait',
            'date' => '2026-10-21',
            'due_date' => '2026-10-25',
            'customer_id' => $this->customer->id,
        ]);

        // Now RETIRE both currencies: deactivate in master and disable for company
        Currency::where('code', 'KWD')->update(['active' => false]);
        Currency::where('code', 'JPY')->update(['active' => false]);
        CompanyCurrency::where('company_id', $this->company->id)->whereIn('currency_code', ['KWD', 'JPY'])->update(['enabled' => false]);

        // Flush resolver cache to ensure fresh state
        $this->resolver->flush();

        // 1. MoneyValues::amount must continue to resolve historical precision without error
        $this->assertSame('116.290000', (string) MoneyValues::amount((string) $kwdPosted->grand_total_currency, 'KWD'));
        $this->assertSame('2500.000000', (string) MoneyValues::amount((string) $jpyPosted->grand_total_currency, 'JPY'));
        $this->assertSame('100.000000', (string) MoneyValues::amount('100.00', 'ILS'));
        $this->assertSame('50.000000', (string) MoneyValues::amount('50.000', 'JOD'));

        // 2. SalesCorrectionAudit must run across historical invoice lines and pass without error
        $errors = app(SalesCorrectionAudit::class)->audit((int) $this->company->id);
        $this->assertEmpty($errors, 'SalesCorrectionAudit reported errors on historical lines with retired currencies: '.json_encode($errors));

        // 3. CustomerPaymentHistory and CheckHistory must validate posted records without throwing
        app(CustomerPaymentHistory::class)->validate($kwdPayment);
        app(CheckHistory::class)->validate($kwdCheck);

        // 4. New transactions in retired currency MUST be rejected
        try {
            app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'currency_code' => 'KWD',
                'exchange_rate' => '12.0',
                'issue_date' => '2026-10-22',
                'lines' => [['item_description' => 'Disallowed', 'quantity' => '1', 'unit_price' => '10.000']],
            ]);
            $this->fail('Expected rejection of new draft in disabled/retired currency.');
        } catch (InvalidArgumentException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'inactive') ||
                str_contains($e->getMessage(), 'Enabled currency') ||
                str_contains($e->getMessage(), 'not enabled')
            );
        }
    }

    public function test_partial_and_full_sales_returns_succeed_after_currency_deactivation_and_disablement(): void
    {
        Permission::findOrCreate('sales.return.create');
        $this->owner->givePermissionTo('sales.return.create');

        // Re-enable KWD temporarily to post the original invoice
        Currency::updateOrInsert(['code' => 'KWD'], ['name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'minor_units' => 3, 'active' => true]);
        CompanyCurrency::updateOrInsert(['company_id' => $this->company->id, 'currency_code' => 'KWD'], ['enabled' => true, 'is_base' => false, 'display_order' => 11]);
        $this->resolver->flush();

        $tax = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT16_RET',
            'name_ar' => 'ضريبة 16%',
            'name_en' => 'VAT 16%',
            'rate' => '16.000000',
            'calculation' => TaxRate::CALC_EXCLUSIVE,
            'active' => true,
        ]);

        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'KWD',
            'exchange_rate' => '12.0000000000',
            'issue_date' => '2026-10-22',
            'lines' => [
                [
                    'item_description' => 'Returnable KWD Unit',
                    'quantity' => '2',
                    'unit_price' => '50.125',
                    'tax_rate_id' => $tax->id,
                ],
            ],
        ]);
        $postedInvoice = app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);
        $lineId = $postedInvoice->lines->first()->id;

        // RETIRE KWD: Deactivate in master Currency table
        Currency::where('code', 'KWD')->update(['active' => false]);
        $this->resolver->flush();

        // 1. Partial Return: Return 0.5 of 2 units succeeds even though master Currency is inactive
        $partialReturnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $postedInvoice->id,
            'issue_date' => '2026-10-22',
            'lines' => [
                [
                    'sales_invoice_line_id' => $lineId,
                    'quantity' => '0.5',
                ],
            ],
        ]);

        $this->assertSame('25.063000', (string) $partialReturnDraft->subtotal_currency);
        $this->assertSame('4.010000', (string) $partialReturnDraft->tax_total_currency);
        $this->assertSame('29.073000', (string) $partialReturnDraft->grand_total_currency);

        $postedReturn1 = app(PostSalesReturnAction::class)->execute($partialReturnDraft, $this->owner);
        $this->assertTrue($postedReturn1->isPosted());
        $this->assertNotNull($postedReturn1->posting_batch_id);

        // 2. Second Return: Return 0.5 unit (1 unit returned total, 1 unit remaining)
        $secondReturnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $postedInvoice->id,
            'issue_date' => '2026-10-22',
            'lines' => [
                [
                    'sales_invoice_line_id' => $lineId,
                    'quantity' => '0.5',
                ],
            ],
        ]);

        $this->assertSame('25.062000', (string) $secondReturnDraft->subtotal_currency);
        $this->assertSame('4.010000', (string) $secondReturnDraft->tax_total_currency);
        $this->assertSame('29.072000', (string) $secondReturnDraft->grand_total_currency);

        $postedReturn2 = app(PostSalesReturnAction::class)->execute($secondReturnDraft, $this->owner);
        $this->assertTrue($postedReturn2->isPosted());

        // 3. Preserve posting controls: when company currency is disabled, posting controls are enforced
        CompanyCurrency::where('company_id', $this->company->id)->where('currency_code', 'KWD')->update(['enabled' => false]);
        $this->resolver->flush();

        // Return draft creation still succeeds and resolves scale without throwing
        $postDisableDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $postedInvoice->id,
            'issue_date' => '2026-10-22',
            'lines' => [
                [
                    'sales_invoice_line_id' => $lineId,
                    'quantity' => '1.0',
                ],
            ],
        ]);
        $this->assertSame('50.125000', (string) $postDisableDraft->subtotal_currency);

        // But posting to GL enforces AccountingPostingService posting controls (not silently waived)
        try {
            app(PostSalesReturnAction::class)->execute($postDisableDraft, $this->owner);
            $this->fail('Expected PostingValidationException when company currency row is disabled.');
        } catch (PostingValidationException $e) {
            $this->assertStringContainsString('Currency is not enabled for company', $e->getMessage());
        }

        // Verify original invoice integrity is preserved
        $freshInvoice = $postedInvoice->fresh();
        $this->assertTrue($freshInvoice->isPosted());
        $this->assertSame('116.290000', (string) $freshInvoice->grand_total_currency);
    }

    public function test_query_amplification_bounded_between_1_and_100_lines_without_price_change_permission(): void
    {
        // Create a restricted user who belongs to company with permission to create sales drafts,
        // but strictly WITHOUT permission to change prices.
        $restrictedUser = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $restrictedUser->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        Permission::findOrCreate('sales.invoice.create');
        $restrictedUser->givePermissionTo('sales.invoice.create');

        $baseUnit = Unit::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'piece_qa'],
            ['name_ar' => 'قطعة', 'name_en' => 'Piece', 'symbol' => 'pc', 'allow_decimal' => false, 'active' => true]
        );

        // Create 100 products with default sales price 100.00
        $products = [];
        $productUnits = [];
        for ($i = 1; $i <= 100; $i++) {
            $p = Product::create([
                'company_id' => $this->company->id,
                'sku' => "PRD-QA-{$i}",
                'name_ar' => "منتج {$i}",
                'name_en' => "Product {$i}",
                'product_type' => 'stock',
                'base_unit_id' => $baseUnit->id,
                'active' => true,
                'default_sale_price_base' => '100.000000',
                'created_by' => $this->owner->id,
            ]);
            $pu = ProductUnit::create([
                'company_id' => $this->company->id,
                'product_id' => $p->id,
                'unit_id' => $baseUnit->id,
                'conversion_to_base' => '1.000000',
                'is_base' => true,
                'is_default_sale' => true,
                'is_default_purchase' => true,
                'active' => true,
                'default_sale_price_base' => '100.000000',
            ]);
            $products[] = $p;
            $productUnits[] = $pu;
        }

        // Prepare 1-line draft input
        $action = app(CreateSalesInvoiceDraftAction::class);
        $lines1 = [
            [
                'product_id' => $products[0]->id,
                'product_unit_id' => $productUnits[0]->id,
                'item_description' => 'Item 1',
                'quantity' => '1',
                'unit_price' => '100.00',
            ],
        ];

        // Measure queries for 1 line
        $this->actingAs($restrictedUser);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $draft1 = $action->execute($this->company, $restrictedUser, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0',
            'issue_date' => '2026-10-20',
            'lines' => $lines1,
        ]);
        $queries1 = DB::getQueryLog();
        DB::disableQueryLog();

        $currencyQueries1 = array_filter($queries1, fn ($q) => str_contains((string) $q['query'], 'currencies') || str_contains((string) $q['query'], 'company_currencies'));

        // Prepare 100-line draft input
        $lines100 = [];
        for ($i = 0; $i < 100; $i++) {
            $lines100[] = [
                'product_id' => $products[$i]->id,
                'product_unit_id' => $productUnits[$i]->id,
                'item_description' => "Item {$i}",
                'quantity' => '1',
                'unit_price' => '100.00',
            ];
        }

        // Flush cache to ensure a fresh benchmark start
        app(CurrencyMinorUnitsResolver::class)->flush();

        // Measure queries for 100 lines
        DB::enableQueryLog();
        DB::flushQueryLog();
        $draft100 = $action->execute($this->company, $restrictedUser, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0',
            'issue_date' => '2026-10-20',
            'lines' => $lines100,
        ]);
        $queries100 = DB::getQueryLog();
        DB::disableQueryLog();
        $this->actingAs($this->owner);

        $currencyQueries100 = array_filter($queries100, fn ($q) => str_contains((string) $q['query'], 'currencies') || str_contains((string) $q['query'], 'company_currencies'));

        // Bounded queries: line loop performs zero per-line currency metadata queries
        $this->assertCount(count($currencyQueries1), $currencyQueries100, 'Currency metadata queries must not amplify with line count.');
        $this->assertLessThanOrEqual(5, count($currencyQueries100), 'Currency queries must remain bounded to operation boundary.');
    }

    public function test_currency_enablement_changes_between_requests_not_cached_past_authority_check(): void
    {
        Currency::updateOrInsert(['code' => 'KWD'], ['name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'minor_units' => 3, 'active' => true]);
        CompanyCurrency::updateOrInsert(['company_id' => $this->company->id, 'currency_code' => 'KWD'], ['enabled' => true, 'is_base' => false, 'display_order' => 11]);

        $resolver = app(CurrencyMinorUnitsResolver::class);
        $resolver->flush();

        // In active session, resolution succeeds
        $this->assertSame(3, $resolver->resolveForCompany($this->company, 'KWD'));

        // Admin disables KWD in DB
        CompanyCurrency::where('company_id', $this->company->id)->where('currency_code', 'KWD')->update(['enabled' => false]);

        // Between requests / retries, flush() is called
        $resolver->flush();

        // Authority check must immediately reflect DB state, not stale cache
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency [KWD] is not enabled for company');
        $resolver->resolveForCompany($this->company, 'KWD');
    }

    public function test_all_six_canonical_reconciliations_pass_after_representative_transactions_and_returns(): void
    {
        // Set up active standard and foreign currencies
        Currency::updateOrInsert(['code' => 'KWD'], ['name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'minor_units' => 3, 'active' => true]);
        Currency::updateOrInsert(['code' => 'JPY'], ['name' => 'Japanese Yen', 'symbol' => '¥', 'minor_units' => 0, 'active' => true]);
        CompanyCurrency::updateOrInsert(['company_id' => $this->company->id, 'currency_code' => 'KWD'], ['enabled' => true, 'is_base' => false, 'display_order' => 11]);
        CompanyCurrency::updateOrInsert(['company_id' => $this->company->id, 'currency_code' => 'JPY'], ['enabled' => true, 'is_base' => false, 'display_order' => 12]);

        $tax = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT16_REC',
            'name_ar' => 'ضريبة 16%',
            'name_en' => 'VAT 16%',
            'rate' => '16.000000',
            'calculation' => TaxRate::CALC_EXCLUSIVE,
            'active' => true,
        ]);

        // 1. ILS base transaction + payment
        $ilsDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0',
            'issue_date' => '2026-10-23',
            'lines' => [
                ['item_description' => 'Standard Base Item', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate_id' => $tax->id],
            ],
        ]);
        $ilsPosted = app(PostSalesInvoiceAction::class)->execute($ilsDraft, $this->owner);

        $ilsCash = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, [
            'account_type' => 'cash', 'name_ar' => 'صندوق شيكل للمطابقة', 'name_en' => 'ILS Cash Rec', 'currency_code' => 'ILS',
        ]);
        app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'money_account_id' => $ilsCash->id, 'payment_date' => '2026-10-23',
            'payment_method' => 'cash', 'amount' => '116.00', 'exchange_rate' => '1.0', 'idempotency_key' => 'rcpt-rec-ils-1',
            'allocations' => [['sales_invoice_id' => $ilsPosted->id, 'allocated_amount' => '116.00']],
        ]);

        // 2. KWD foreign transaction + return
        $kwdDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'KWD',
            'exchange_rate' => '12.0000000000',
            'issue_date' => '2026-10-23',
            'lines' => [
                ['item_description' => 'Foreign KWD Goods', 'quantity' => '2', 'unit_price' => '50.000', 'tax_rate_id' => $tax->id],
            ],
        ]);
        $kwdPosted = app(PostSalesInvoiceAction::class)->execute($kwdDraft, $this->owner);

        $kwdReturnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $kwdPosted->id,
            'issue_date' => '2026-10-23',
            'lines' => [['sales_invoice_line_id' => $kwdPosted->lines->first()->id, 'quantity' => '1']],
        ]);
        app(PostSalesReturnAction::class)->execute($kwdReturnDraft, $this->owner);

        // 3. JPY foreign transaction + return
        $jpyDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'JPY',
            'exchange_rate' => '0.0250000000',
            'issue_date' => '2026-10-23',
            'lines' => [
                ['item_description' => 'Foreign JPY Item', 'quantity' => '1', 'unit_price' => '3000'],
            ],
        ]);
        $jpyPosted = app(PostSalesInvoiceAction::class)->execute($jpyDraft, $this->owner);

        $jpyReturnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $jpyPosted->id,
            'issue_date' => '2026-10-23',
            'lines' => [['sales_invoice_line_id' => $jpyPosted->lines->first()->id, 'quantity' => '1']],
        ]);
        app(PostSalesReturnAction::class)->execute($jpyReturnDraft, $this->owner);

        // Record database fingerprint before reconciliation
        $fingerprintBefore = $this->getDatabaseFingerprint();

        // Execute all 6 reconciliation services and assert no errors
        $accountingReport = app(AccountingReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($accountingReport->isHealthy, 'Accounting reconciliation failed: '.json_encode($accountingReport->violations));
        $this->assertEmpty($accountingReport->violations, 'Accounting reconciliation has violations');

        $salesReport = app(SalesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($salesReport->isHealthy, 'Sales reconciliation failed: '.json_encode($salesReport->violations));
        $this->assertEmpty($salesReport->violations, 'Sales reconciliation has violations');

        $payablesReport = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($payablesReport->isHealthy, 'Payables reconciliation failed: '.json_encode($payablesReport->violations));
        $this->assertEmpty($payablesReport->violations, 'Payables reconciliation has violations');

        $moneyReport = app(MoneyReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($moneyReport->isHealthy, 'Money reconciliation failed: '.json_encode($moneyReport->violations));
        $this->assertEmpty($moneyReport->violations, 'Money reconciliation has violations');

        $phase7Report = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($phase7Report->isHealthy, 'Phase 7 reconciliation failed: '.json_encode($phase7Report->violations));
        $this->assertEmpty($phase7Report->violations, 'Phase 7 reconciliation has violations');

        $inventoryReport = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($inventoryReport->isHealthy, 'Inventory audit failed: '.json_encode($inventoryReport->discrepancies));
        $this->assertEmpty($inventoryReport->discrepancies, 'Inventory audit has discrepancies');

        // Confirm reconciliations are strictly read-only
        $fingerprintAfter = $this->getDatabaseFingerprint();
        $this->assertSame($fingerprintBefore, $fingerprintAfter, 'Reconciliation services mutated persisted database state.');
    }

    /** @return array<string, string> */
    private function getDatabaseFingerprint(): array
    {
        $fingerprints = [];
        foreach (DB::select('SHOW TABLES') as $table) {
            $name = array_values((array) $table)[0];
            $rows = DB::table($name)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $fingerprints[$name] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }
        ksort($fingerprints);

        return $fingerprints;
    }
}
