<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\UpdatePurchaseDraftAction;
use App\Application\AI\PurchaseDraftPayload;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
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
use App\Services\Purchasing\PurchaseReturnAmounts;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
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
     * Update draft rejects swapped or mismatched product_id on line public_id
     */
    public function test_update_draft_rejects_swapped_or_mismatched_product_id_on_line_public_id(): void
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

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
        $editable = app(PurchaseDraftBuilder::class)->editableData($draft);

        // Client attempts to swap product_id of line 0 from Soap to Oil while retaining Soap line public_id
        $oilUnit = ProductUnit::where('product_id', $this->oil->id)->where('is_base', true)->firstOrFail();
        $editable['lines'][0]['product_id'] = $this->oil->id;
        $editable['lines'][0]['product_unit_id'] = $oilUnit->id;

        $this->expectException(ValidationException::class);
        try {
            app(UpdatePurchaseDraftAction::class)->execute($draft, $this->owner, $editable);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
            $this->assertSame(__('purchasing.stale_line_intent'), $e->errors()['lines'][0]);
            throw $e;
        }
    }

    /**
     * Update draft rejects swapped or mismatched product_unit_id on line public_id
     */
    public function test_update_draft_rejects_swapped_or_mismatched_product_unit_id_on_line_public_id(): void
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
            'discount_scope' => 'document',
            'lines' => [
                ['product_id' => $this->soap->id, 'quantity' => '5', 'unit_cost' => '10.00'],
            ],
        ];

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
        $editable = app(PurchaseDraftBuilder::class)->editableData($draft);

        // Client changes unit to carton while retaining the original line public_id
        $editable['lines'][0]['product_unit_id'] = $altProductUnit->id;

        $this->expectException(ValidationException::class);
        try {
            app(UpdatePurchaseDraftAction::class)->execute($draft, $this->owner, $editable);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
            $this->assertSame(__('purchasing.stale_line_intent'), $e->errors()['lines'][0]);
            throw $e;
        }
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

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $payload);
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
}
