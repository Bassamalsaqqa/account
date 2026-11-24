<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Actions\Purchasing\RecordStagedPurchaseBillAction;
use App\Actions\Purchasing\UpdatePurchaseDraftAction;
use App\Application\AI\PurchaseDraftCommands;
use App\Application\AI\PurchaseDraftPayload;
use App\Http\Controllers\Api\Mobile\V1\PurchaseController;
use App\Models\AiPurchaseDraftReceipt;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\Currency;
use App\Models\LedgerAccount;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Corrections\PurchaseCorrection;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\PurchaseDraftBuilder;
use App\Services\Purchasing\PurchaseDraftSnapshot;
use App\Services\Purchasing\PurchaseDraftWriter;
use App\Services\Purchasing\PurchaseReadModel;
use App\Services\Purchasing\PurchaseReturnAmounts;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PurchaseDocumentDiscountWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Vendor $vendor;

    protected Warehouse $warehouse;

    protected Product $soap;

    protected Product $shampoo;

    protected Product $oil;

    protected TaxRate $tax16;

    protected TaxRate $taxZero;

    protected TaxRate $taxInclusive;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create(['locale' => 'en']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة المشتريات',
            'name_en' => 'Purchasing Test Co',
        ]);
        $this->activate();

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد الخصم',
            'name_en' => 'Discount Supplier',
        ]);
        $this->warehouse = Warehouse::where('company_id', $this->company->id)->where('active', true)->firstOrFail();

        $pieceUnit = Unit::where('code', 'piece')->firstOrFail();

        $catalog = app(ProductCatalogService::class);
        $this->soap = $catalog->createProduct($this->company, [
            'name_ar' => 'صابون', 'name_en' => 'Soap', 'sku' => 'SOAP-01',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => false,
            'base_unit_id' => $pieceUnit->id, 'default_purchase_cost_base' => '10.000000',
        ], $this->owner->id);

        $this->shampoo = $catalog->createProduct($this->company, [
            'name_ar' => 'شامبو', 'name_en' => 'Shampoo', 'sku' => 'SHMP-01',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => false,
            'base_unit_id' => $pieceUnit->id, 'default_purchase_cost_base' => '200.000000',
        ], $this->owner->id);

        $this->oil = $catalog->createProduct($this->company, [
            'name_ar' => 'زيت', 'name_en' => 'Oil', 'sku' => 'OIL-01',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => false,
            'base_unit_id' => $pieceUnit->id, 'default_purchase_cost_base' => '50.000000',
        ], $this->owner->id);

        $inputTaxAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'tax_input')->first();

        $this->tax16 = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT16',
            'name_ar' => 'ضريبة 16%',
            'name_en' => 'VAT 16%',
            'rate' => '16.0000',
            'calculation' => TaxRate::CALC_EXCLUSIVE,
            'purchase_tax_account_id' => $inputTaxAccount?->id,
            'active' => true,
        ]);

        $this->taxZero = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'ZERO0',
            'name_ar' => 'معفى 0%',
            'name_en' => 'Zero Rated 0%',
            'rate' => '0.0000',
            'calculation' => TaxRate::CALC_EXCLUSIVE,
            'purchase_tax_account_id' => $inputTaxAccount?->id,
            'active' => true,
        ]);

        $this->taxInclusive = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'INC16',
            'name_ar' => 'ضريبة شاملة 16%',
            'name_en' => 'Inclusive VAT 16%',
            'rate' => '16.0000',
            'calculation' => TaxRate::CALC_INCLUSIVE,
            'purchase_tax_account_id' => $inputTaxAccount?->id,
            'active' => true,
        ]);
    }

    protected function activate(): void
    {
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);
        setPermissionsTeamId($this->company->id);
        $this->owner->unsetRelation('roles')->unsetRelation('permissions');
    }

    /**
     * Vector 1: ILS Standard 2-Decimal Distribution (Owner Brief Benchmark)
     */
    public function test_vector_1_create_purchase_draft_with_fixed_global_discount(): void
    {
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_type' => 'fixed',
            'discount_value' => '17.00',
            'discount_scope' => 'document',
            'lines' => [
                ['product_id' => $this->soap->id, 'quantity' => '1', 'unit_cost' => '100.00', 'tax_rate_id' => $this->taxZero->id],
                ['product_id' => $this->shampoo->id, 'quantity' => '1', 'unit_cost' => '200.00', 'tax_rate_id' => $this->taxZero->id],
                ['product_id' => $this->oil->id, 'quantity' => '1', 'unit_cost' => '50.00', 'tax_rate_id' => $this->taxZero->id],
            ],
        ];

        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);

        $this->assertSame('draft', $purchase->status);
        $this->assertSame('fixed', $purchase->discount_type);
        $this->assertSame('17.000000', (string) $purchase->discount_value);
        $this->assertSame('document', $purchase->discount_scope);
        $this->assertSame('subtotal', $purchase->discount_basis);
        $this->assertSame(2, (int) $purchase->discount_calculation_version);

        // Header totals: Subtotal 350, Discount 17, Grand Total 333
        $this->assertSame('350.000000', (string) $purchase->subtotal_currency);
        $this->assertSame('17.000000', (string) $purchase->discount_total_currency);
        $this->assertSame('0.000000', (string) $purchase->tax_total_currency);
        $this->assertSame('333.000000', (string) $purchase->grand_total_currency);

        $lines = $purchase->lines->sortBy('line_number')->values();
        $this->assertCount(3, $lines);

        // Line 1: 100.00 - 4.86 = 95.14
        $this->assertSame('4.860000', (string) $lines[0]->allocated_document_discount);
        $this->assertSame('0.000000', (string) $lines[0]->manual_discount_amount);
        $this->assertSame('4.860000', (string) $lines[0]->line_discount);
        $this->assertSame('95.140000', (string) $lines[0]->line_total);

        // Line 2: 200.00 - 9.71 = 190.29
        $this->assertSame('9.710000', (string) $lines[1]->allocated_document_discount);
        $this->assertSame('0.000000', (string) $lines[1]->manual_discount_amount);
        $this->assertSame('9.710000', (string) $lines[1]->line_discount);
        $this->assertSame('190.290000', (string) $lines[1]->line_total);

        // Line 3: 50.00 - 2.43 = 47.57
        $this->assertSame('2.430000', (string) $lines[2]->allocated_document_discount);
        $this->assertSame('0.000000', (string) $lines[2]->manual_discount_amount);
        $this->assertSame('2.430000', (string) $lines[2]->line_discount);
        $this->assertSame('47.570000', (string) $lines[2]->line_total);

        // Editable data check
        $editable = app(PurchaseDraftBuilder::class)->editableData($purchase);
        $this->assertSame('fixed', $editable['discount_type']);
        $this->assertSame('17.000000', $editable['discount_value']);
        $this->assertSame('document', $editable['discount_scope']);
        $this->assertSame('4.860000', $editable['lines'][0]['allocated_document_discount']);
    }

    /**
     * Vector 3: Mixed Tax-Exclusive (16%) and Exempt (0%) Lines with Posting & GL Balancing
     */
    public function test_vector_3_post_purchase_with_global_discount_mixed_tax_and_gl_balancing(): void
    {
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_type' => 'fixed',
            'discount_value' => '30.00',
            'discount_scope' => 'document',
            'lines' => [
                ['product_id' => $this->soap->id, 'quantity' => '10', 'unit_cost' => '10.00', 'tax_rate_id' => $this->tax16->id],
                ['product_id' => $this->shampoo->id, 'quantity' => '1', 'unit_cost' => '200.00', 'tax_rate_id' => $this->taxZero->id],
            ],
        ];

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
        $posted = app(PostPurchaseAction::class)->execute($draft, $this->owner);

        $this->assertSame(Purchase::STATUS_POSTED, $posted->status);
        $this->assertNotNull($posted->purchase_number);
        $this->assertNotNull($posted->posted_at);

        // Verification of invoice totals:
        // Subtotal: 300.00 | Discount: 30.00 | Tax: 14.40 | Grand Total: 284.40
        $this->assertSame('300.000000', (string) $posted->subtotal_currency);
        $this->assertSame('30.000000', (string) $posted->discount_total_currency);
        $this->assertSame('14.400000', (string) $posted->tax_total_currency);
        $this->assertSame('284.400000', (string) $posted->grand_total_currency);
        $this->assertSame('284.400000', (string) $posted->grand_total_base);

        // GL Posting Verification:
        // Dr Inventory: 270.00 (90.00 + 180.00)
        // Dr Input Tax: 14.40
        // Cr Accounts Payable: 284.40
        $batch = PostingBatch::where('company_id', $this->company->id)->whereKey($posted->posting_batch_id)->firstOrFail();
        $lines = PostingLine::where('posting_batch_id', $batch->id)->orderBy('line_number')->get();

        $inventoryAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'inventory')->firstOrFail();
        $payableAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_payable')->firstOrFail();
        $taxAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'tax_input')->firstOrFail();

        $inventoryLine = $lines->where('ledger_account_id', $inventoryAccount->id)->first();
        $this->assertNotNull($inventoryLine);
        $this->assertSame('270.000000', (string) $inventoryLine->debit_base);
        $this->assertSame('0.000000', (string) $inventoryLine->credit_base);

        $taxLine = $lines->where('ledger_account_id', $taxAccount->id)->first();
        $this->assertNotNull($taxLine);
        $this->assertSame('14.400000', (string) $taxLine->debit_base);
        $this->assertSame('0.000000', (string) $taxLine->credit_base);

        $payableLine = $lines->where('ledger_account_id', $payableAccount->id)->first();
        $this->assertNotNull($payableLine);
        $this->assertSame('0.000000', (string) $payableLine->debit_base);
        $this->assertSame('284.400000', (string) $payableLine->credit_base);

        // Exact debit == credit conservation
        $totalDebit = $lines->reduce(fn (BigDecimal $carry, $l) => $carry->plus($l->debit_base), BigDecimal::zero());
        $totalCredit = $lines->reduce(fn (BigDecimal $carry, $l) => $carry->plus($l->credit_base), BigDecimal::zero());
        $this->assertTrue($totalDebit->isEqualTo($totalCredit));
        $this->assertSame('284.400000', (string) $totalDebit->toScale(6));

        // Moving-average inventory unit cost verification:
        // Line 1 Soap: 90.00 / 10 = 9.000000
        // Line 2 Shampoo: 180.00 / 1 = 180.000000
        $postedLines = $posted->lines()->orderBy('line_number')->get();
        $this->assertSame('9.000000', (string) $postedLines[0]->inventory_unit_cost_base);
        $this->assertSame('180.000000', (string) $postedLines[1]->inventory_unit_cost_base);
    }

    /**
     * Vector 4: Edge Cases & Validation Rules
     */
    public function test_new_purchase_rejects_manual_line_discount(): void
    {
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_scope' => 'document',
            'lines' => [
                [
                    'product_id' => $this->soap->id,
                    'quantity' => '1',
                    'unit_cost' => '100.00',
                    'discount_type' => 'fixed',
                    'discount_value' => '10.00',
                ],
            ],
        ];

        $this->expectException(ValidationException::class);
        app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
    }

    public function test_document_discount_rejects_tax_inclusive_line(): void
    {
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_type' => 'fixed',
            'discount_value' => '10.00',
            'lines' => [
                [
                    'product_id' => $this->soap->id,
                    'quantity' => '1',
                    'unit_cost' => '100.00',
                    'tax_rate_id' => $this->taxInclusive->id,
                ],
            ],
        ];

        $this->expectException(ValidationException::class);
        app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
    }

    public function test_document_discount_rejects_exceeding_subtotal(): void
    {
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_type' => 'fixed',
            'discount_value' => '100.01',
            'lines' => [
                ['product_id' => $this->soap->id, 'quantity' => '1', 'unit_cost' => '100.00'],
            ],
        ];

        $this->expectException(ValidationException::class);
        app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
    }

    public function test_document_discount_rejects_negative_value(): void
    {
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_type' => 'fixed',
            'discount_value' => '-5.00',
            'lines' => [
                ['product_id' => $this->soap->id, 'quantity' => '1', 'unit_cost' => '100.00'],
            ],
        ];

        $this->expectException(ValidationException::class);
        app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
    }

    public function test_100_percent_discount_purchase_posts_zero_value_under_adr_0013(): void
    {
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_type' => 'percent',
            'discount_value' => '100',
            'lines' => [
                ['product_id' => $this->soap->id, 'quantity' => '5', 'unit_cost' => '20.00'],
            ],
        ];

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
        $posted = app(PostPurchaseAction::class)->execute($draft, $this->owner);

        $this->assertSame(Purchase::STATUS_POSTED, $posted->status);
        $this->assertNotNull($posted->purchase_number);
        // ADR 0013 invariant: zero commercial total has stock receipt and numbering, but no empty journal batch
        $this->assertNull($posted->posting_batch_id);
        $this->assertSame('0.000000', (string) $posted->grand_total_currency);
        $this->assertSame('0.000000', (string) $posted->grand_total_base);
    }

    /**
     * Partial and residual purchase return on discounted purchase line
     */
    public function test_partial_and_residual_purchase_return_on_discounted_line(): void
    {
        // 10 units @ 10.00 ILS = 100.00 ILS, fixed document discount 20.00 ILS => Net 80.00 ILS (8.00 ILS/unit)
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_type' => 'fixed',
            'discount_value' => '20.00',
            'lines' => [
                ['product_id' => $this->soap->id, 'quantity' => '10', 'unit_cost' => '10.00'],
            ],
        ];

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
        $posted = app(PostPurchaseAction::class)->execute($draft, $this->owner);
        $line = $posted->lines->first();

        // 1. Partial return: return 4 units
        // Expected subtotal = 40.00 ILS, allocated return discount = 8.00 ILS (4/10 * 20), Net return = 32.00 ILS
        $return1 = PurchaseReturn::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'purchase_id' => $posted->id,
            'warehouse_id' => $this->warehouse->id,
            'vendor_id' => $this->vendor->id,
            'return_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1',
            'status' => 'draft',
            'created_by' => $this->owner->id,
            'subtotal_currency' => '0', 'discount_total_currency' => '0', 'tax_total_currency' => '0', 'grand_total_currency' => '0',
            'subtotal_base' => '0', 'discount_total_base' => '0', 'tax_total_base' => '0', 'grand_total_base' => '0',
        ]);

        $rLine1 = PurchaseReturnLine::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'purchase_return_id' => $return1->id,
            'purchase_line_id' => $line->id,
            'line_number' => 1,
            'item_description' => 'Soap return',
            'product_id' => $this->soap->id,
            'product_unit_id' => $line->product_unit_id,
            'unit_conversion_ratio' => '1',
            'quantity' => '4.000000',
            'quantity_base' => '4.000000',
            'unit_cost' => '10.000000',
            'discount_value' => '0',
            'tax_rate_id' => null,
            'line_subtotal' => '0', 'line_discount' => '0', 'line_tax' => '0', 'line_total' => '0',
            'line_subtotal_base' => '0', 'line_discount_base' => '0', 'line_tax_base' => '0', 'line_total_base' => '0',
        ]);

        app(PurchaseReturnAmounts::class)->refresh($return1);

        $rLine1->refresh();
        $this->assertSame('40.000000', (string) $rLine1->line_subtotal);
        $this->assertSame('8.000000', (string) $rLine1->line_discount);
        $this->assertSame('32.000000', (string) $rLine1->line_total);

        // Simulate posting return 1
        DB::table('purchase_returns')->where('id', $return1->id)->update(['status' => PurchaseReturn::STATUS_POSTED]);

        // 2. Residual return: return remaining 6 units
        // Expected subtotal = 60.00 ILS, allocated return discount = 12.00 ILS (exact remaining 20 - 8), Net return = 48.00 ILS
        $return2 = PurchaseReturn::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'purchase_id' => $posted->id,
            'warehouse_id' => $this->warehouse->id,
            'vendor_id' => $this->vendor->id,
            'return_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1',
            'status' => 'draft',
            'created_by' => $this->owner->id,
            'subtotal_currency' => '0', 'discount_total_currency' => '0', 'tax_total_currency' => '0', 'grand_total_currency' => '0',
            'subtotal_base' => '0', 'discount_total_base' => '0', 'tax_total_base' => '0', 'grand_total_base' => '0',
        ]);

        $rLine2 = PurchaseReturnLine::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'purchase_return_id' => $return2->id,
            'purchase_line_id' => $line->id,
            'line_number' => 1,
            'item_description' => 'Soap return',
            'product_id' => $this->soap->id,
            'product_unit_id' => $line->product_unit_id,
            'unit_conversion_ratio' => '1',
            'quantity' => '6.000000',
            'quantity_base' => '6.000000',
            'unit_cost' => '10.000000',
            'discount_value' => '0',
            'tax_rate_id' => null,
            'line_subtotal' => '0', 'line_discount' => '0', 'line_tax' => '0', 'line_total' => '0',
            'line_subtotal_base' => '0', 'line_discount_base' => '0', 'line_tax_base' => '0', 'line_total_base' => '0',
        ]);

        app(PurchaseReturnAmounts::class)->refresh($return2);

        $rLine2->refresh();
        $this->assertSame('60.000000', (string) $rLine2->line_subtotal);
        $this->assertSame('12.000000', (string) $rLine2->line_discount);
        $this->assertSame('48.000000', (string) $rLine2->line_total);

        // Total return discounts = 8.00 + 12.00 = 20.00 exactly (cleared full original discount)
        $this->assertSame('20.000000', (string) BigDecimal::of($rLine1->line_discount)->plus($rLine2->line_discount)->toScale(6));
    }

    /**
     * AI Payload Normalization and Hash Reproducibility
     */
    public function test_ai_draft_payload_normalization_and_hash(): void
    {
        $payload1 = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'en',
            'discount_type' => 'fixed',
            'discount_value' => '15.500000',
            'lines' => [
                ['product_id' => $this->soap->id, 'quantity' => '2', 'unit_cost' => '10'],
            ],
        ];

        // Same economic intent with reordered keys and trailing decimal formatting
        $payload2 = [
            'lines' => [
                ['quantity' => '2.0', 'unit_cost' => '10.000000', 'product_id' => $this->soap->id],
            ],
            'discount_value' => '15.5',
            'discount_type' => 'fixed',
            'document_locale' => 'en',
            'exchange_rate' => '1',
            'currency_code' => 'ILS',
            'purchase_date' => '2026-11-24',
            'warehouse_id' => $this->warehouse->id,
            'vendor_id' => $this->vendor->id,
        ];

        $ai = app(PurchaseDraftPayload::class);
        $canonical1 = $ai->canonicalize($payload1);
        $canonical2 = $ai->canonicalize($payload2);

        $this->assertSame($canonical1, $canonical2);
        $this->assertSame($ai->hash($canonical1), $ai->hash($canonical2));
    }

    /**
     * Purchase Correction preserves Document Discount and passes digest
     */
    public function test_purchase_correction_preserves_document_discount_and_passes_digest(): void
    {
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_type' => 'fixed',
            'discount_value' => '10.00',
            'discount_scope' => 'document',
            'lines' => [
                ['product_id' => $this->soap->id, 'quantity' => '5', 'unit_cost' => '20.00'],
            ],
        ];

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
        $posted = app(PostPurchaseAction::class)->execute($draft, $this->owner);

        // Correction Preview
        $correction = app(PurchaseCorrection::class);
        $preview = $correction->preview($posted, $this->owner, 'edit', 'Correction test reason', '2026-11-24');

        $this->assertArrayHasKey('token', $preview);
        $this->assertEmpty($preview['blockers']);

        // Execute Correction
        $replacement = $correction->execute($preview['token'], $this->owner);

        $this->assertSame('draft', $replacement->status);
        $this->assertSame('fixed', $replacement->discount_type);
        $this->assertSame('10.000000', (string) $replacement->discount_value);
        $this->assertSame('document', $replacement->discount_scope);
        $this->assertSame('90.000000', (string) $replacement->grand_total_currency);
    }

    /**
     * Update draft rejects duplicate line public_ids
     */
    public function test_update_draft_rejects_duplicate_line_public_ids(): void
    {
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_type' => 'none',
            'discount_value' => '0',
            'discount_scope' => 'document',
            'lines' => [
                ['product_id' => $this->soap->id, 'quantity' => '5', 'unit_cost' => '10.00'],
                ['product_id' => $this->shampoo->id, 'quantity' => '2', 'unit_cost' => '20.00'],
            ],
        ];

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload, ['allow_legacy_scope' => true]);
        $editable = app(PurchaseDraftBuilder::class)->editableData($draft);

        // Forged client payload duplicates the public_id of line 0 onto line 1
        $editable['lines'][1]['public_id'] = $editable['lines'][0]['public_id'];

        $this->expectException(ValidationException::class);
        try {
            app(UpdatePurchaseDraftAction::class)->execute($draft, $this->owner, $editable);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
            $this->assertSame(__('purchasing.duplicate_line_public_id'), $e->errors()['lines'][0]);
            throw $e;
        }
    }

    /**
     * Update draft with replaced product_id on line public_id resets legacy discount without transferring
     */
    public function test_update_draft_with_replaced_product_id_resets_legacy_discount_without_transferring(): void
    {
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_type' => 'none',
            'discount_value' => '0',
            'discount_scope' => 'line',
            'lines' => [
                ['product_id' => $this->soap->id, 'quantity' => '5', 'unit_cost' => '10.00', 'discount_type' => 'fixed', 'discount_value' => '5.00'],
                ['product_id' => $this->shampoo->id, 'quantity' => '2', 'unit_cost' => '20.00', 'discount_type' => 'percent', 'discount_value' => '10.00'],
            ],
        ];

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload, ['allow_legacy_scope' => true]);
        $editable = app(PurchaseDraftBuilder::class)->editableData($draft);

        // Client changes product of line 0 from Soap to Oil while retaining line public_id
        $oilUnit = ProductUnit::where('product_id', $this->oil->id)->where('is_base', true)->firstOrFail();
        $editable['lines'][0]['product_id'] = $this->oil->id;
        $editable['lines'][0]['product_unit_id'] = $oilUnit->id;
        unset($editable['lines'][0]['discount_type'], $editable['lines'][0]['discount_value']);

        $updated = app(UpdatePurchaseDraftAction::class)->execute($draft, $this->owner, $editable);

        $line0 = $updated->lines->where('product_id', $this->oil->id)->sole();
        $line1 = $updated->lines->where('product_id', $this->shampoo->id)->sole();

        // Line 0 was replaced: legacy discount from Soap is reset, NOT transferred to Oil
        $this->assertNull($line0->discount_type);
        $this->assertSame('0.000000', (string) $line0->discount_value);
        $this->assertSame('0.000000', (string) $line0->line_discount);

        // Line 1 was unchanged: legacy discount strictly preserved
        $this->assertSame('percent', $line1->discount_type);
        $this->assertSame('10.000000', (string) $line1->discount_value);
        $this->assertSame('4.000000', (string) $line1->line_discount);
    }

    /**
     * Update draft with replaced product_unit_id on line public_id resets legacy discount without transferring
     */
    public function test_update_draft_with_replaced_unit_id_resets_legacy_discount_without_transferring(): void
    {
        $cartonUnit = Unit::firstOrCreate(
            ['code' => 'carton'],
            ['name_ar' => 'كرتونة', 'name_en' => 'Carton', 'symbol_ar' => 'كرتونة', 'symbol_en' => 'ctn']
        );
        $altProductUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->soap->id,
            'unit_id' => $cartonUnit->id,
            'conversion_to_base' => '10.000000',
            'is_base' => false,
            'active' => true,
        ]);

        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_type' => 'none',
            'discount_value' => '0',
            'discount_scope' => 'line',
            'lines' => [
                ['product_id' => $this->soap->id, 'quantity' => '5', 'unit_cost' => '10.00', 'discount_type' => 'fixed', 'discount_value' => '5.00'],
            ],
        ];

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload, ['allow_legacy_scope' => true]);
        $editable = app(PurchaseDraftBuilder::class)->editableData($draft);

        // Client changes unit to carton while retaining the original line public_id
        $editable['lines'][0]['product_unit_id'] = $altProductUnit->id;
        $editable['lines'][0]['quantity'] = '1';
        $editable['lines'][0]['unit_cost'] = '90.00';
        unset($editable['lines'][0]['discount_type'], $editable['lines'][0]['discount_value']);

        $updated = app(UpdatePurchaseDraftAction::class)->execute($draft, $this->owner, $editable);
        $line0 = $updated->lines->firstOrFail();

        // Line 0 unit was replaced: legacy discount is reset, NOT transferred to the new unit
        $this->assertNull($line0->discount_type);
        $this->assertSame('0.000000', (string) $line0->discount_value);
        $this->assertSame('0.000000', (string) $line0->line_discount);
        $this->assertSame('90.000000', (string) $line0->line_total);
    }

    /**
     * Update legacy draft preserves exact raw line discounts when omitted by client
     */
    public function test_update_legacy_draft_preserves_exact_raw_line_discount_when_omitted_by_client(): void
    {
        // Create legacy purchase with explicit per-line discounts (fixed and percent) and 6-decimal precision
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_type' => 'none',
            'discount_value' => '0',
            'discount_scope' => 'line',
            'lines' => [
                [
                    'product_id' => $this->soap->id,
                    'quantity' => '2',
                    'unit_cost' => '50.00',
                    'discount_type' => 'fixed',
                    'discount_value' => '12.345600',
                ],
                [
                    'product_id' => $this->shampoo->id,
                    'quantity' => '1',
                    'unit_cost' => '100.00',
                    'discount_type' => 'percent',
                    'discount_value' => '15.500000',
                ],
            ],
        ];

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload, ['allow_legacy_scope' => true]);
        $this->assertSame('line', $draft->discount_scope);

        $line0 = $draft->lines->where('product_id', $this->soap->id)->sole();
        $line1 = $draft->lines->where('product_id', $this->shampoo->id)->sole();
        $this->assertSame('fixed', $line0->discount_type);
        $this->assertSame('12.345600', (string) $line0->discount_value);
        $this->assertSame('percent', $line1->discount_type);
        $this->assertSame('15.500000', (string) $line1->discount_value);

        // editableData() returns exact raw values without display formatting/rounding
        $editable = app(PurchaseDraftBuilder::class)->editableData($draft);
        $this->assertSame('12.345600', $editable['lines'][0]['discount_value']);
        $this->assertSame('15.500000', $editable['lines'][1]['discount_value']);

        // Web UI client sends update: omits line discount inputs or resets them to none/0, while updating line 0 quantity
        $webClientPayload = $editable;
        $webClientPayload['lines'][0]['quantity'] = '3';
        // Web client stripped line discount fields
        unset($webClientPayload['lines'][0]['discount_type'], $webClientPayload['lines'][0]['discount_value']);
        // Second line sends default none / 0
        $webClientPayload['lines'][1]['discount_type'] = 'none';
        $webClientPayload['lines'][1]['discount_value'] = '0';

        $updated = app(UpdatePurchaseDraftAction::class)->execute($draft, $this->owner, $webClientPayload);

        $updatedLine0 = $updated->lines->where('product_id', $this->soap->id)->sole();
        $updatedLine1 = $updated->lines->where('product_id', $this->shampoo->id)->sole();

        // Exact raw legacy discount type and value are preserved without loss of precision
        $this->assertSame('fixed', $updatedLine0->discount_type);
        $this->assertSame('12.345600', (string) $updatedLine0->discount_value);
        $this->assertSame('percent', $updatedLine1->discount_type);
        $this->assertSame('15.500000', (string) $updatedLine1->discount_value);

        // Calculated line totals reflect the preserved line discounts:
        // Line 0: qty 3 * 50 = 150 subtotal - 12.35 fixed discount = 137.65
        $this->assertSame('12.350000', (string) $updatedLine0->line_discount);
        $this->assertSame('137.650000', (string) $updatedLine0->line_total);

        // Line 1: qty 1 * 100 = 100 subtotal - 15.50% = 15.50 discount = 84.50
        $this->assertSame('15.500000', (string) $updatedLine1->line_discount);
        $this->assertSame('84.500000', (string) $updatedLine1->line_total);
    }

    public function test_new_purchase_rejects_line_discounts_without_explicit_line_scope(): void
    {
        $payload = [
            'vendor_id' => $this->vendor->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'lines' => [
                [
                    'product_id' => $this->soap->id,
                    'quantity' => '2',
                    'unit_cost' => '50.00',
                    'discount_type' => 'fixed',
                    'discount_value' => '10.00',
                ],
            ],
        ];

        $this->expectException(ValidationException::class);
        app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
    }

    public function test_inconsistent_discount_scope_and_header_discount_rejected(): void
    {
        $payload = [
            'vendor_id' => $this->vendor->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_scope' => 'line',
            'discount_type' => 'fixed',
            'discount_value' => '5.00',
            'lines' => [
                [
                    'product_id' => $this->soap->id,
                    'quantity' => '2',
                    'unit_cost' => '50.00',
                ],
            ],
        ];

        $this->expectException(ValidationException::class);
        app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
    }

    public function test_legitimate_unit_replacement_on_legacy_draft_resets_line_discount_without_error(): void
    {
        $boxUnit = Unit::where('code', 'box')->firstOrFail();
        $boxProductUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->soap->id,
            'unit_id' => $boxUnit->id,
            'conversion_to_base' => '10.000000',
            'active' => true,
        ]);

        $payload = [
            'vendor_id' => $this->vendor->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_type' => 'none',
            'discount_value' => '0',
            'discount_scope' => 'line',
            'lines' => [
                [
                    'product_id' => $this->soap->id,
                    'quantity' => '2',
                    'unit_cost' => '50.00',
                    'discount_type' => 'fixed',
                    'discount_value' => '12.000000',
                ],
                [
                    'product_id' => $this->shampoo->id,
                    'quantity' => '1',
                    'unit_cost' => '100.00',
                    'discount_type' => 'percent',
                    'discount_value' => '10.000000',
                ],
            ],
        ];

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload, ['allow_legacy_scope' => true]);
        $editable = app(PurchaseDraftBuilder::class)->editableData($draft);

        // User changes unit on line 0 to Box (10x), unit cost to 450, quantity 1
        $editable['lines'][0]['product_unit_id'] = $boxProductUnit->id;
        $editable['lines'][0]['quantity'] = '1';
        $editable['lines'][0]['unit_cost'] = '450.00';
        unset($editable['lines'][0]['discount_type'], $editable['lines'][0]['discount_value']);

        $updated = app(UpdatePurchaseDraftAction::class)->execute($draft, $this->owner, $editable);

        $line0 = $updated->lines->where('product_id', $this->soap->id)->sole();
        $line1 = $updated->lines->where('product_id', $this->shampoo->id)->sole();

        // Line 0 unit was replaced: legacy discount is reset to none / 0 without error
        $this->assertNull($line0->discount_type);
        $this->assertSame('0.000000', (string) $line0->discount_value);
        $this->assertSame('0.000000', (string) $line0->line_discount);
        $this->assertSame('450.000000', (string) $line0->line_total);

        // Line 1 unit was NOT changed: legacy discount is strictly preserved
        $this->assertSame('percent', $line1->discount_type);
        $this->assertSame('10.000000', (string) $line1->discount_value);
        $this->assertSame('10.000000', (string) $line1->line_discount);
        $this->assertSame('90.000000', (string) $line1->line_total);
    }

    public function test_ai_purchase_draft_receipt_idempotency_retries_for_pre_upgrade_legacy_hash(): void
    {
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'en',
            'lines' => [
                [
                    'product_id' => $this->soap->id,
                    'quantity' => '5.000000',
                    'unit_cost' => '20.000000',
                ],
            ],
        ];

        $commands = app(PurchaseDraftCommands::class);
        $formKey = (string) Str::uuid();

        // First creation via AI command
        $purchase = $commands->create($this->company, $this->owner, $payload, $formKey);
        $this->assertNotNull($purchase->id);

        // Simulate pre-upgrade receipt: manually store legacy hash (without discount_type, discount_value, discount_scope)
        $intent = app(PurchaseDraftPayload::class);
        $canonical = $intent->canonicalize($payload);
        $legacyHash = $intent->legacyHash($canonical);

        AiPurchaseDraftReceipt::where('form_key', $formKey)->update(['payload_hash' => $legacyHash]);

        // Retrying identical form must succeed via legacy hash equivalence
        $retryPurchase = $commands->create($this->company, $this->owner, $payload, $formKey);
        $this->assertSame($purchase->id, $retryPurchase->id);

        // Retrying with changed economics (quantity = 6) must reject with 409
        $changedPayload = $payload;
        $changedPayload['lines'][0]['quantity'] = '6.000000';

        try {
            $commands->create($this->company, $this->owner, $changedPayload, $formKey);
            $this->fail('Expected HttpException 409');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_record_staged_purchase_bill_with_fixed_zero_placeholders_succeeds_and_posts(): void
    {
        config([
            'mobile.enabled' => true,
            'mobile.merchant_api_enabled' => true,
        ]);

        $deviceId = (string) Str::uuid();
        $device = MobileDevice::create([
            'device_id' => $deviceId,
            'device_name' => 'Merchant Test Phone',
            'user_id' => $this->owner->id,
        ]);

        $rawToken = 'st_m_'.bin2hex(random_bytes(32));
        $token = MobileAccessToken::create([
            'token_hash' => hash('sha256', $rawToken),
            'device_id' => $deviceId,
            'user_id' => $this->owner->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->owner->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        $this->owner->givePermissionTo([
            'purchasing.purchase.create',
            'purchasing.purchase.post',
        ]);

        $action = app(RecordStagedPurchaseBillAction::class);
        $res = $action->execute(
            company: $this->company,
            actor: $this->owner,
            supplierPublicId: (string) $this->vendor->public_id,
            billNumber: 'STAGED-INV-001',
            linesInput: [
                [
                    'product_id' => (string) $this->soap->public_id,
                    'quantity' => '10.000000',
                    'unit_cost' => '15.000000',
                    'discount_type' => 'fixed',
                    'discount_value' => '0',
                ],
            ],
            idempotencyKey: (string) Str::uuid(),
            mobileToken: $token,
            mobileDevice: $device
        );

        $bill = Purchase::where('company_id', $this->company->id)->where('public_id', $res['snapshot']['id'])->firstOrFail();
        $this->assertNotNull($bill);
        $this->assertSame(Purchase::STATUS_POSTED, $bill->status);
        $this->assertTrue((bool) $bill->is_staged);
        $this->assertSame('document', $bill->discount_scope);

        $line = $bill->lines()->firstOrFail();
        $this->assertNull($line->discount_type);
        $this->assertSame('0.000000', (string) $line->discount_value);
        $this->assertSame('0.000000', (string) $line->manual_discount_amount);
        $this->assertSame('0.000000', (string) $line->allocated_document_discount);
        $this->assertSame('150.000000', (string) $line->line_total);
    }

    public function test_purchase_return_preserves_frozen_purchase_precision_when_currency_scale_mutates(): void
    {
        // 1. Enable JOD currency with 3 minor units in company
        $jod = Currency::where('code', 'JOD')->first();
        if ($jod === null) {
            $jod = Currency::create(['code' => 'JOD', 'name' => 'Jordanian Dinar', 'symbol' => 'JD', 'minor_units' => 3, 'active' => true]);
        } else {
            $jod->update(['minor_units' => 3, 'active' => true]);
        }
        CompanyCurrency::firstOrCreate(
            ['company_id' => $this->company->id, 'currency_code' => 'JOD'],
            ['enabled' => true, 'is_base' => false]
        );

        // 2. Create and post a JOD purchase (precision = 3) with document discount
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'JOD',
            'exchange_rate' => '1.4100000000',
            'discount_type' => 'fixed',
            'discount_value' => '10.000',
            'lines' => [
                [
                    'product_id' => $this->soap->id,
                    'quantity' => '10.000000',
                    'unit_cost' => '10.000',
                ],
            ],
        ];

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
        $this->assertSame(3, $draft->currency_minor_units);
        $this->assertSame('90.000000', (string) $draft->grand_total_currency);

        $posted = app(PostPurchaseAction::class)->execute($draft, $this->owner);
        $this->assertSame(3, $posted->currency_minor_units);

        // 3. Mutate JOD in currencies table to 2 decimal places to simulate currency definition changes
        $jod->update(['minor_units' => 2]);

        // 4. Create partial purchase return (return 4 out of 10)
        $returnDraftAction = app(CreatePurchaseReturnDraftAction::class);
        $origLine = $posted->lines->firstOrFail();

        $return1 = $returnDraftAction->execute($this->company, $this->owner, [
            'purchase_id' => $posted->id,
            'return_date' => '2026-11-24',
            'lines' => [
                [
                    'purchase_line_id' => $origLine->id,
                    'quantity' => '4.000000',
                ],
            ],
        ]);

        // Return must inherit purchase's frozen precision (3)
        $this->assertSame(3, $return1->currency_minor_units);
        // Allocation: 4 / 10 of 90.000 = 36.000
        $this->assertSame('36.000000', (string) $return1->grand_total_currency);

        // Posting partial return 1 verifies validateDraftIntegrity with frozen precision (3)
        $postedReturn1 = app(PostPurchaseReturnAction::class)->execute($return1, $this->owner);
        $this->assertSame(PurchaseReturn::STATUS_POSTED, $postedReturn1->status);

        // 5. Create final return for remainder (return 6 out of 10)
        $return2 = $returnDraftAction->execute($this->company, $this->owner, [
            'purchase_id' => $posted->id,
            'return_date' => '2026-11-24',
            'lines' => [
                [
                    'purchase_line_id' => $origLine->id,
                    'quantity' => '6.000000',
                ],
            ],
        ]);

        $this->assertSame(3, $return2->currency_minor_units);
        // Allocation: remaining 90.000 - 36.000 = 54.000
        $this->assertSame('54.000000', (string) $return2->grand_total_currency);

        $postedReturn2 = app(PostPurchaseReturnAction::class)->execute($return2, $this->owner);
        $this->assertSame(PurchaseReturn::STATUS_POSTED, $postedReturn2->status);

        // Conservation: 36.000000 + 54.000000 == 90.000000
        $totalReturned = BigDecimal::of((string) $postedReturn1->grand_total_currency)
            ->plus((string) $postedReturn2->grand_total_currency);
        $this->assertTrue($totalReturned->isEqualTo(BigDecimal::of((string) $posted->grand_total_currency)));
    }

    public function test_mobile_return_preview_aligns_with_authoritative_discount_allocation(): void
    {
        // Purchase: 10 units at 50 = 500, with document discount of 50 => grand total = 450
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'discount_type' => 'fixed',
            'discount_value' => '50.00',
            'lines' => [
                [
                    'product_id' => $this->soap->id,
                    'quantity' => '10.000000',
                    'unit_cost' => '50.00',
                ],
            ],
        ];

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
        $posted = app(PostPurchaseAction::class)->execute($draft, $this->owner);
        $origLine = $posted->lines->firstOrFail();

        // Mobile preview return of 4 units: 4 / 10 of 450 = 180.00 (NOT 4 * 50 = 200)
        $controller = app(PurchaseController::class);
        $request = Request::create(
            "/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns/preview",
            'POST',
            [
                'purchase_id' => $posted->public_id,
                'lines' => [
                    [
                        'purchase_line_id' => $origLine->public_id,
                        'product_id' => $this->soap->public_id,
                        'quantity' => '4.000000',
                    ],
                ],
            ]
        );
        $request->attributes->set('mobile_user', $this->owner);
        $request->attributes->set('mobile_company', $this->company);

        $response = $controller->previewReturn($request, (string) $this->company->public_id);
        $data = $response->getData(true);

        $this->assertSame('180.00', $data['total_debit_ils']);
        $this->assertSame('180.00', $data['lines'][0]['line_total_ils']);
        $this->assertSame('50.00', $data['lines'][0]['unit_cost_ils']);
    }

    public function test_public_callers_and_ai_cannot_select_legacy_scope_or_manual_line_discounts(): void
    {
        // 1. CreatePurchaseDraftAction rejects discount_scope = 'line'
        $payload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_type' => 'none',
            'discount_value' => '0',
            'discount_scope' => 'line',
            'lines' => [
                [
                    'product_id' => $this->soap->id,
                    'quantity' => '5',
                    'unit_cost' => '10.00',
                ],
            ],
        ];

        try {
            app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
            $this->fail('Expected ValidationException for legacy line discount scope');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount_scope', $e->errors());
        }

        // 2. AI payload rejects discount_scope = 'line'
        $aiPayload = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'en',
            'discount_scope' => 'line',
            'lines' => [
                [
                    'product_id' => $this->soap->id,
                    'quantity' => '5.000000',
                    'unit_cost' => '20.000000',
                ],
            ],
        ];

        try {
            app(PurchaseDraftPayload::class)->canonicalize($aiPayload);
            $this->fail('Expected InvalidArgumentException for AI legacy scope');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Legacy line discount scope is not selectable via AI assistant.', $e->getMessage());
        }

        // 3. AI payload rejects manual line discounts
        $aiPayloadWithLineDiscount = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'en',
            'discount_scope' => 'document',
            'lines' => [
                [
                    'product_id' => $this->soap->id,
                    'quantity' => '5.000000',
                    'unit_cost' => '20.000000',
                    'discount_type' => 'fixed',
                    'discount_value' => '5.000000',
                ],
            ],
        ];

        try {
            app(PurchaseDraftPayload::class)->canonicalize($aiPayloadWithLineDiscount);
            $this->fail('Expected InvalidArgumentException for AI line discounts');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Manual line discounts are not supported via AI assistant.', $e->getMessage());
        }

        // 4. Adding a new row with manual discount to an existing legacy draft throws manual_line_discount_forbidden
        $legacyDraft = app(PurchaseDraftBuilder::class)->prepare(
            $this->company,
            [
                'vendor_id' => $this->vendor->id,
                'warehouse_id' => $this->warehouse->id,
                'purchase_date' => '2026-11-24',
                'currency_code' => 'ILS',
                'exchange_rate' => '1',
                'discount_type' => 'none',
                'discount_value' => '0',
                'discount_scope' => 'line',
                'lines' => [
                    ['product_id' => $this->soap->id, 'quantity' => '2', 'unit_cost' => '50.00'],
                ],
            ],
            null,
            ['allow_legacy_scope' => true]
        );
        $draftModel = Purchase::create($legacyDraft->header + ['company_id' => $this->company->id, 'created_by' => $this->owner->id]);
        app(PurchaseDraftWriter::class)->replaceLines($draftModel, $legacyDraft);

        $editable = app(PurchaseDraftBuilder::class)->editableData($draftModel);
        // Add a second brand new row with a manual line discount
        $editable['lines'][] = [
            'product_id' => $this->shampoo->id,
            'quantity' => '1',
            'unit_cost' => '100.00',
            'discount_type' => 'fixed',
            'discount_value' => '15.00',
        ];

        try {
            app(UpdatePurchaseDraftAction::class)->execute($draftModel, $this->owner, $editable);
            $this->fail('Expected ValidationException when adding new row with discount to legacy draft');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
        }
    }

    public function test_legacy_discount_breakdown_derived_fallback_in_read_model_and_snapshot(): void
    {
        // 1. Create legacy draft with line discount via trusted internal option
        $prepared = app(PurchaseDraftBuilder::class)->prepare(
            $this->company,
            [
                'vendor_id' => $this->vendor->id,
                'warehouse_id' => $this->warehouse->id,
                'purchase_date' => '2026-11-24',
                'currency_code' => 'ILS',
                'exchange_rate' => '1',
                'discount_type' => 'none',
                'discount_value' => '0',
                'discount_scope' => 'line',
                'lines' => [
                    [
                        'product_id' => $this->soap->id,
                        'quantity' => '2',
                        'unit_cost' => '50.00',
                        'discount_type' => 'fixed',
                        'discount_value' => '15.00',
                    ],
                ],
            ],
            null,
            ['allow_legacy_scope' => true]
        );
        $draft = Purchase::create($prepared->header + ['company_id' => $this->company->id, 'created_by' => $this->owner->id]);
        app(PurchaseDraftWriter::class)->replaceLines($draft, $prepared);

        // Simulate pre-migration database rows: currency_minor_units null, manual_discount_amount 0
        $draft->update(['currency_minor_units' => null]);
        PurchaseLine::where('purchase_id', $draft->id)->update([
            'manual_discount_amount' => '0.000000',
            'allocated_document_discount' => '0.000000',
        ]);

        $line = $draft->lines()->firstOrFail();
        $this->assertSame('15.000000', (string) $line->line_discount);
        $this->assertSame('0.000000', (string) $line->manual_discount_amount);

        // 2. PurchaseReadModel::detail derives manual_discount_amount from line_discount
        $detail = app(PurchaseReadModel::class)->detail($draft, true);
        $this->assertSame(2, $detail['currency_minor_units']);
        $this->assertSame('15.000000', $detail['lines'][0]['manual_discount_amount']);
        $this->assertSame('0.000000', $detail['lines'][0]['allocated_document_discount']);
        $this->assertSame('fixed', $detail['lines'][0]['discount_type']);
        $this->assertSame('15.000000', (string) $detail['lines'][0]['discount_value']);

        // 3. PurchaseDraftSnapshot derives manual_discount_amount from line_discount
        $snapshot = app(PurchaseDraftSnapshot::class)->capture($draft);
        $this->assertSame('15.000000', $snapshot['lines'][0]['manual_discount_amount']);
        $this->assertSame('0.000000', $snapshot['lines'][0]['allocated_document_discount']);

        // 4. PurchaseDraftBuilder::editableData derives manual_discount_amount from line_discount
        $editable = app(PurchaseDraftBuilder::class)->editableData($draft);
        $this->assertSame('15.000000', $editable['lines'][0]['manual_discount_amount']);
        $this->assertSame('0.000000', $editable['lines'][0]['allocated_document_discount']);
    }
}
