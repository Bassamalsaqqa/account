<?php

declare(strict_types=1);

namespace Tests\Feature\Phase11;

use App\Actions\Company\AddCompanyMemberAction;
use App\Actions\Company\CreateCompanyAction;
use App\Actions\Company\ToggleCompanyMemberStatusAction;
use App\Actions\Company\UpdateCompanyMemberRoleAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Application\Commands\SalesInvoiceCommands;
use App\Models\CommandReceipt;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class SalesCommandWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected SalesInvoiceCommands $commands;

    protected Warehouse $warehouse;

    protected Unit $unitPiece;

    protected Product $productStock;

    protected ProductUnit $productStockUnit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
        $this->commands = app(SalesInvoiceCommands::class);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
        $this->unitPiece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        // Ensure USD and JOD currencies exist and are enabled without duplicating
        CompanyCurrency::updateOrCreate(
            ['company_id' => $this->company->id, 'currency_code' => 'USD'],
            ['enabled' => true]
        );
        CompanyCurrency::updateOrCreate(
            ['company_id' => $this->company->id, 'currency_code' => 'JOD'],
            ['enabled' => true]
        );

        $this->productStock = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'منتج مخزني',
            'name_en' => 'Stock Product',
            'sku' => 'STK-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => 'stock',
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $this->productStockUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->productStock->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'suggested_sale_price' => '25.000000',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
    }

    protected ?Vendor $seedVendor = null;

    /**
     * Helper to seed stock in the default warehouse via canonical purchase posting.
     */
    protected function seedStock(Product $product, string $quantity, string $unitCost = '10.000000'): void
    {
        if ($this->seedVendor === null) {
            $this->seedVendor = Vendor::create([
                'public_id' => (string) Str::ulid(),
                'company_id' => $this->company->id,
                'code' => 'VEND-SEED',
                'name_ar' => 'مورد الاختبار',
                'name_en' => 'Seed Vendor',
                'status' => 'active',
                'active' => true,
                'created_by' => $this->owner->id,
            ]);
        }

        $unit = ProductUnit::where('company_id', $this->company->id)
            ->where('product_id', $product->id)
            ->where('is_base', true)
            ->first();

        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->seedVendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => Carbon::now()->toDateString(),
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit?->id,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                ],
            ],
        ]);

        app(PostPurchaseAction::class)->execute($purchase, $this->owner);
    }

    protected function addMember(string $roleName, string $email = 'member@test.com'): CompanyUser
    {
        return app(AddCompanyMemberAction::class)->execute($this->company, [
            'name' => 'Test '.$roleName,
            'email' => $email,
            'password' => 'secret12345',
            'locale' => 'en',
        ], $roleName, $this->owner);
    }

    public function test_create_draft_invoice_with_exact_decimals_and_receipt(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'due_date' => '2026-11-11',
            'notes' => 'Test draft creation note',
            'lines' => [
                [
                    'item_description' => 'Consulting Service',
                    'quantity' => '2.000000',
                    'unit_price' => '150.000000',
                    'discount_type' => 'fixed',
                    'discount_value' => '10.000000',
                ],
            ],
        ];

        $invoice = $this->commands->create($this->company, $this->owner, $payload, 'key-create-01');

        $this->assertInstanceOf(SalesInvoice::class, $invoice);
        $this->assertTrue($invoice->isDraft());
        $this->assertNull($invoice->invoice_number);
        $this->assertEquals('290.000000', (string) $invoice->grand_total_currency);

        $receipt = CommandReceipt::where('company_id', $this->company->id)
            ->where('user_id', $this->owner->id)
            ->where('action', 'sales_invoice.create')
            ->where('idempotency_key', 'key-create-01')
            ->first();

        $this->assertNotNull($receipt);
        $this->assertEquals($invoice->id, $receipt->sales_invoice_id);
        $this->assertNull($receipt->target_invoice_id);
        $this->assertNotEmpty($receipt->request_hash);
    }

    public function test_create_draft_invoice_idempotent_retry_returns_same_instance(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'item_description' => 'Delivery Service',
                    'quantity' => '1.000000',
                    'unit_price' => '50.000000',
                ],
            ],
        ];

        $first = $this->commands->create($this->company, $this->owner, $payload, 'key-retry-01');
        $second = $this->commands->create($this->company, $this->owner, $payload, 'key-retry-01');

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, SalesInvoice::where('company_id', $this->company->id)->count());
        $this->assertEquals(1, CommandReceipt::where('company_id', $this->company->id)->where('idempotency_key', 'key-retry-01')->count());
    }

    public function test_create_draft_invoice_conflict_on_changed_payload_with_same_key(): void
    {
        $payload1 = [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'item_description' => 'Service A',
                    'quantity' => '1.000000',
                    'unit_price' => '50.000000',
                ],
            ],
        ];

        $payload2 = [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'item_description' => 'Service A Changed',
                    'quantity' => '2.000000',
                    'unit_price' => '50.000000',
                ],
            ],
        ];

        $this->commands->create($this->company, $this->owner, $payload1, 'key-conflict-01');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Idempotency key reused with different payload.');
        $this->commands->create($this->company, $this->owner, $payload2, 'key-conflict-01');
    }

    public function test_create_draft_invoice_scoped_by_actor_and_tenant(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'item_description' => 'Multi-actor Service',
                    'quantity' => '1.000000',
                    'unit_price' => '75.000000',
                ],
            ],
        ];

        // 1. First actor ($this->owner) in company 1 uses key
        $invoice1 = $this->commands->create($this->company, $this->owner, $payload, 'shared-key-01');

        // 2. Second actor in same company 1 with Manager role
        $membership2 = $this->addMember('Manager', 'second_actor@test.com');
        $secondUser = $membership2->user;

        $this->actingAs($secondUser);
        app(CompanyContext::class)->setCompany($this->company, $secondUser);

        // Second user uses same key -> succeeds independently because idempotency is scoped by actor
        $invoice2 = $this->commands->create($this->company, $secondUser, $payload, 'shared-key-01');

        $this->assertNotEquals($invoice1->id, $invoice2->id);

        // 3. Second tenant (distinct Company) with its own owner
        app(CompanyContext::class)->clear();
        $ownerCompany2 = User::factory()->create(['locale' => 'en']);
        $company2 = app(CreateCompanyAction::class)->execute($ownerCompany2, [
            'name_ar' => 'شركة ثانية',
            'name_en' => 'Second Company',
            'base_currency_code' => 'ILS',
        ]);
        $this->actingAs($ownerCompany2);
        app(CompanyContext::class)->setCompany($company2, $ownerCompany2);
        $customer2 = Customer::create([
            'company_id' => $company2->id,
            'name_ar' => 'عميل الشركة 2',
            'name_en' => 'Customer Co 2',
            'preferred_locale' => 'en',
            'created_by' => $ownerCompany2->id,
        ]);

        $this->actingAs($ownerCompany2);
        app(CompanyContext::class)->setCompany($company2, $ownerCompany2);

        $payload2 = [
            'customer_id' => $customer2->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'item_description' => 'Tenant 2 Service',
                    'quantity' => '1.000000',
                    'unit_price' => '75.000000',
                ],
            ],
        ];

        // Owner 2 uses the exact same key in Company 2 -> succeeds independently with its own receipt
        $invoice3 = $this->commands->create($company2, $ownerCompany2, $payload2, 'shared-key-01');
        $this->assertNotEquals($invoice1->id, $invoice3->id);
        $this->assertSame(3, DB::table('command_receipts')->where('idempotency_key', 'shared-key-01')->count());

        // 4. Foreign company refusal: attempting to create or access company 1 from company 2 context
        try {
            $this->commands->create($this->company, $ownerCompany2, $payload, 'shared-key-01');
            $this->fail('Expected foreign company creation to be refused.');
        } catch (AuthorizationException) {
            // Foreign refusal confirmed
        }

        // Restore company 1 context
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
    }

    public function test_create_draft_invoice_rejects_integer_and_float_economic_values(): void
    {
        // 1. Integer quantity
        try {
            $this->commands->create($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'issue_date' => '2026-10-11',
                'lines' => [['item_description' => 'Test', 'quantity' => 1, 'unit_price' => '10.000000']],
            ], 'k-int-qty');
            $this->fail('Expected integer quantity to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('exact decimal string', $e->getMessage());
        }

        // 2. Float quantity
        try {
            $this->commands->create($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'issue_date' => '2026-10-11',
                'lines' => [['item_description' => 'Test', 'quantity' => 1.5, 'unit_price' => '10.000000']],
            ], 'k-float-qty');
            $this->fail('Expected float quantity to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('exact decimal string', $e->getMessage());
        }

        // 3. Float exchange rate
        try {
            $this->commands->create($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'currency_code' => 'USD',
                'exchange_rate' => 3.5,
                'issue_date' => '2026-10-11',
                'lines' => [['item_description' => 'Test', 'quantity' => '1.000000', 'unit_price' => '10.000000']],
            ], 'k-float-fx');
            $this->fail('Expected float exchange rate to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('exact decimal string', $e->getMessage());
        }

        // 4. Integer unit_price
        try {
            $this->commands->create($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'issue_date' => '2026-10-11',
                'lines' => [['item_description' => 'Test', 'quantity' => '1.000000', 'unit_price' => 100]],
            ], 'k-int-price');
            $this->fail('Expected integer unit_price to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('exact decimal string', $e->getMessage());
        }
    }

    public function test_create_draft_invoice_rejects_malformed_and_scientific_values(): void
    {
        // 1. Scientific notation
        try {
            $this->commands->create($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'issue_date' => '2026-10-11',
                'lines' => [['item_description' => 'Test', 'quantity' => '1e5', 'unit_price' => '10.000000']],
            ], 'k-sci');
            $this->fail('Expected scientific notation to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('unsigned decimal string', $e->getMessage());
        }

        // 2. Nonpositive quantity
        try {
            $this->commands->create($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'issue_date' => '2026-10-11',
                'lines' => [['item_description' => 'Test', 'quantity' => '0.000000', 'unit_price' => '10.000000']],
            ], 'k-zero-qty');
            $this->fail('Expected zero quantity to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('strictly positive', $e->getMessage());
        }

        // 3. Overprecision on quantity (>6 decimals)
        try {
            $this->commands->create($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'issue_date' => '2026-10-11',
                'lines' => [['item_description' => 'Test', 'quantity' => '1.1234567', 'unit_price' => '10.000000']],
            ], 'k-scale-qty');
            $this->fail('Expected overprecision quantity to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('maximum precision of 6 decimal places', $e->getMessage());
        }

        // 4. Overprecision on exchange_rate (>10 decimals)
        try {
            $this->commands->create($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'currency_code' => 'USD',
                'exchange_rate' => '3.50000000001',
                'issue_date' => '2026-10-11',
                'lines' => [['item_description' => 'Test', 'quantity' => '1.000000', 'unit_price' => '10.000000']],
            ], 'k-scale-fx');
            $this->fail('Expected overprecision exchange rate to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('maximum precision of 10 decimal places', $e->getMessage());
        }
    }

    public function test_create_draft_invoice_rejects_unknown_keys_and_malformed_idempotency_keys(): void
    {
        // 1. Unknown header key
        try {
            $this->commands->create($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'issue_date' => '2026-10-11',
                'unknown_header_field' => 'value',
                'lines' => [['item_description' => 'Test', 'quantity' => '1.000000', 'unit_price' => '10.000000']],
            ], 'k-unknown-h');
            $this->fail('Expected unknown header key to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown header field', $e->getMessage());
        }

        // 2. Unknown line key
        try {
            $this->commands->create($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'issue_date' => '2026-10-11',
                'lines' => [['item_description' => 'Test', 'quantity' => '1.000000', 'unit_price' => '10.000000', 'extra_line_field' => true]],
            ], 'k-unknown-l');
            $this->fail('Expected unknown line key to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown line field', $e->getMessage());
        }

        // 3. Malformed idempotency key (spaces)
        try {
            $this->commands->create($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'issue_date' => '2026-10-11',
                'lines' => [['item_description' => 'Test', 'quantity' => '1.000000', 'unit_price' => '10.000000']],
            ], 'invalid key with spaces');
            $this->fail('Expected malformed idempotency key to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Malformed idempotency key', $e->getMessage());
        }
    }

    public function test_update_draft_invoice_updates_fields_and_persists_receipt(): void
    {
        $invoice = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'notes' => 'Original notes',
            'lines' => [['item_description' => 'Original Line', 'quantity' => '1.000000', 'unit_price' => '100.000000']],
        ], 'k-up-create');

        $updatePayload = [
            'notes' => 'Updated notes',
            'lines' => [['item_description' => 'Updated Line', 'quantity' => '2.000000', 'unit_price' => '120.000000']],
        ];

        $updated = $this->commands->update($invoice, $this->owner, $updatePayload, 'k-up-01');

        $this->assertEquals('Updated notes', $updated->notes);
        $this->assertEquals('240.000000', (string) $updated->grand_total_currency);

        $receipt = CommandReceipt::where('company_id', $this->company->id)
            ->where('action', 'sales_invoice.update')
            ->where('idempotency_key', 'k-up-01')
            ->first();

        $this->assertNotNull($receipt);
        $this->assertEquals($invoice->id, $receipt->target_invoice_id);

        // Replay with exact same update payload returns the same updated invoice
        $replayed = $this->commands->update($invoice, $this->owner, $updatePayload, 'k-up-01');
        $this->assertEquals($updated->id, $replayed->id);

        // Replay with changed payload throws 409
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Idempotency key reused with different payload.');
        $this->commands->update($invoice, $this->owner, ['notes' => 'Another note'], 'k-up-01');
    }

    public function test_preview_is_read_only_and_does_not_post_reserve_or_number(): void
    {
        $invoice = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'item_description' => 'Preview Service',
                    'quantity' => '1.000000',
                    'unit_price' => '200.000000',
                ],
            ],
        ], 'k-preview-01');

        $batchesBefore = PostingBatch::count();
        $movementsBefore = StockMovement::count();
        $receiptsBefore = CommandReceipt::count();

        $preview = $this->commands->preview($invoice, $this->owner);

        // Asserts state is completely unchanged
        $invoice->refresh();
        $this->assertEquals('draft', $invoice->status);
        $this->assertNull($invoice->invoice_number);
        $this->assertEquals($batchesBefore, PostingBatch::count());
        $this->assertEquals($movementsBefore, StockMovement::count());
        $this->assertEquals($receiptsBefore, CommandReceipt::count());

        // Asserts preview contents
        $this->assertIsArray($preview);
        $this->assertEquals('draft supplied rate, canonical validated; no market quote', $preview['invoice']['exchange_rate_source']);
        $this->assertStringContainsString('Stock availability and FEFO lot allocations are projected at preview time and are not reserved', $preview['stock_availability']['disclaimer']);
        $this->assertTrue($preview['can_execute']);
        $this->assertEmpty($preview['blockers']);
        $this->assertNotEmpty($preview['confirmation_token']);
        $this->assertNotEmpty($preview['draft_revision']);
        $this->assertNotEmpty($preview['preview_revision']);
    }

    public function test_preview_strictly_excludes_sensitive_cogs_and_unit_costs(): void
    {
        $this->seedStock($this->productStock, '10.000000', '15.000000');

        $invoice = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'product_id' => $this->productStock->id,
                    'product_unit_id' => $this->productStockUnit->id,
                    'item_description' => 'Stock item',
                    'quantity' => '2.000000',
                    'unit_price' => '25.000000',
                ],
            ],
        ], 'k-preview-cogs');

        $preview = $this->commands->preview($invoice, $this->owner);

        $json = json_encode($preview);
        $this->assertStringNotContainsString('cogs', strtolower($json));
        $this->assertStringNotContainsString('unit_cost', strtolower($json));
        $this->assertStringNotContainsString('total_cost', strtolower($json));
    }

    public function test_preview_and_execute_with_combined_repeated_stock_lines(): void
    {
        // Warehouse has 10 units
        $this->seedStock($this->productStock, '10.000000');

        // Invoice draft with two lines of the same product: 6 + 5 = 11 (> 10 available)
        $invoiceExceeded = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'product_id' => $this->productStock->id,
                    'product_unit_id' => $this->productStockUnit->id,
                    'item_description' => 'Stock line 1',
                    'quantity' => '6.000000',
                    'unit_price' => '25.000000',
                ],
                [
                    'product_id' => $this->productStock->id,
                    'product_unit_id' => $this->productStockUnit->id,
                    'item_description' => 'Stock line 2',
                    'quantity' => '5.000000',
                    'unit_price' => '25.000000',
                ],
            ],
        ], 'k-exceeded-stock');

        $previewExceeded = $this->commands->preview($invoiceExceeded, $this->owner);

        $this->assertFalse($previewExceeded['can_execute']);
        $this->assertNotEmpty($previewExceeded['blockers']);
        $this->assertEquals('insufficient_stock', $previewExceeded['blockers'][0]['code']);
        $this->assertEquals('11.000000', $previewExceeded['stock_availability']['products'][0]['quantity_needed_base']);
        $this->assertEquals('10.000000', $previewExceeded['stock_availability']['products'][0]['quantity_available_base']);

        // Now create draft with two lines that sum to 7 (<= 10 available)
        $invoiceSufficient = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'product_id' => $this->productStock->id,
                    'product_unit_id' => $this->productStockUnit->id,
                    'item_description' => 'Stock line 1',
                    'quantity' => '4.000000',
                    'unit_price' => '25.000000',
                ],
                [
                    'product_id' => $this->productStock->id,
                    'product_unit_id' => $this->productStockUnit->id,
                    'item_description' => 'Stock line 2',
                    'quantity' => '3.000000',
                    'unit_price' => '25.000000',
                ],
            ],
        ], 'k-sufficient-stock');

        $previewSufficient = $this->commands->preview($invoiceSufficient, $this->owner);
        $this->assertTrue($previewSufficient['can_execute']);
        $this->assertEmpty($previewSufficient['blockers']);
        $this->assertEquals('7.000000', $previewSufficient['stock_availability']['products'][0]['quantity_needed_base']);

        // Execute succeeds
        $posted = $this->commands->execute($invoiceSufficient, $this->owner, $previewSufficient['confirmation_token'], 'k-exec-stock');
        $this->assertEquals('posted', $posted->status);
        $this->assertNotNull($posted->invoice_number);
    }

    public function test_execute_posts_invoice_atomically_with_confirmation_token_and_receipt(): void
    {
        $invoice = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'item_description' => 'Execution Service',
                    'quantity' => '1.000000',
                    'unit_price' => '300.000000',
                ],
            ],
        ], 'k-exec-init');

        $preview = $this->commands->preview($invoice, $this->owner);
        $token = $preview['confirmation_token'];

        $posted = $this->commands->execute($invoice, $this->owner, $token, 'k-exec-01');

        $this->assertEquals('posted', $posted->status);
        $this->assertNotNull($posted->invoice_number);
        $this->assertNotNull($posted->posting_batch_id);

        $receipt = CommandReceipt::where('company_id', $this->company->id)
            ->where('action', 'sales_invoice.execute')
            ->where('idempotency_key', 'k-exec-01')
            ->first();

        $this->assertNotNull($receipt);
        $this->assertEquals($posted->id, $receipt->sales_invoice_id);
        $this->assertEquals($invoice->id, $receipt->target_invoice_id);
    }

    public function test_execute_idempotent_retry_does_not_repost(): void
    {
        $invoice = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'item_description' => 'Retry Service',
                    'quantity' => '1.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ], 'k-exec-retry-init');

        $preview = $this->commands->preview($invoice, $this->owner);
        $token = $preview['confirmation_token'];

        $postedFirst = $this->commands->execute($invoice, $this->owner, $token, 'k-exec-retry-key');
        $batchesCount = PostingBatch::count();

        // Exact retry with same token and key
        $postedSecond = $this->commands->execute($invoice, $this->owner, $token, 'k-exec-retry-key');

        $this->assertEquals($postedFirst->id, $postedSecond->id);
        $this->assertEquals($postedFirst->invoice_number, $postedSecond->invoice_number);
        // Does not create another posting batch
        $this->assertEquals($batchesCount, PostingBatch::count());
    }

    public function test_execute_conflict_on_reused_key_with_different_token_or_target(): void
    {
        $invoice1 = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'Item 1', 'quantity' => '1.000000', 'unit_price' => '100.000000']],
        ], 'k-c-1');

        $preview1 = $this->commands->preview($invoice1, $this->owner);
        $this->commands->execute($invoice1, $this->owner, $preview1['confirmation_token'], 'k-conflict-key');

        // Reusing same key on a different invoice throws 409
        $invoice2 = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'Item 2', 'quantity' => '1.000000', 'unit_price' => '100.000000']],
        ], 'k-c-2');

        $preview2 = $this->commands->preview($invoice2, $this->owner);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Idempotency key reused with different target invoice.');
        $this->commands->execute($invoice2, $this->owner, $preview2['confirmation_token'], 'k-conflict-key');
    }

    public function test_execute_rejects_tampered_and_invalid_tokens(): void
    {
        $invoice = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'Service', 'quantity' => '1.000000', 'unit_price' => '100.000000']],
        ], 'k-tamper-init');

        $preview = $this->commands->preview($invoice, $this->owner);
        $validToken = $preview['confirmation_token'];

        // Tamper token ciphertext
        $tamperedToken = substr($validToken, 0, -4).'wxyz';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or tampered confirmation token.');
        $this->commands->execute($invoice, $this->owner, $tamperedToken, 'k-tamper-key');
    }

    public function test_execute_rejects_expired_confirmation_token(): void
    {
        $invoice = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'Service', 'quantity' => '1.000000', 'unit_price' => '100.000000']],
        ], 'k-expire-init');

        $preview = $this->commands->preview($invoice, $this->owner);

        // Forge an expired token (expires_at 10 seconds in the past)
        $expiredPayload = [
            'company_id' => (int) $invoice->company_id,
            'actor_id' => (int) $this->owner->id,
            'invoice_id' => (int) $invoice->id,
            'invoice_public_id' => (string) $invoice->public_id,
            'draft_revision' => $preview['draft_revision'],
            'preview_revision' => $preview['preview_revision'],
            'created_at' => time() - 310,
            'expires_at' => time() - 10,
        ];
        $expiredToken = Crypt::encryptString(json_encode($expiredPayload, JSON_THROW_ON_ERROR));

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Confirmation token has expired.');
        $this->commands->execute($invoice, $this->owner, $expiredToken, 'k-expired-key');
    }

    public function test_execute_detects_stale_line_revision_when_draft_was_modified(): void
    {
        $invoice = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'Original', 'quantity' => '1.000000', 'unit_price' => '100.000000']],
        ], 'k-stale-draft');

        $preview = $this->commands->preview($invoice, $this->owner);
        $token = $preview['confirmation_token'];

        // Modify the draft after preview
        $this->commands->update($invoice, $this->owner, [
            'lines' => [['item_description' => 'Modified Line', 'quantity' => '2.000000', 'unit_price' => '100.000000']],
        ], 'k-stale-mod');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Draft has been modified since preview.');
        $this->commands->execute($invoice, $this->owner, $token, 'k-stale-exec');
    }

    public function test_execute_detects_stale_preview_revision_when_inventory_changes(): void
    {
        $this->seedStock($this->productStock, '10.000000');

        $invoice = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'product_id' => $this->productStock->id,
                    'product_unit_id' => $this->productStockUnit->id,
                    'item_description' => 'Stock item',
                    'quantity' => '3.000000',
                    'unit_price' => '25.000000',
                ],
            ],
        ], 'k-stale-inv');

        $preview = $this->commands->preview($invoice, $this->owner);
        $token = $preview['confirmation_token'];

        // Canonical independent stock change via another posted purchase
        $this->seedStock($this->productStock, '5.000000');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Inventory, tax, or pricing context has changed since preview.');
        $this->commands->execute($invoice, $this->owner, $token, 'k-stale-inv-exec');
    }

    public function test_fresh_rbac_and_membership_check_on_replay_and_downgrade(): void
    {
        $membership = $this->addMember('Manager', 'rbac_mgr@test.com');
        $member = $membership->user;

        $this->actingAs($member);
        app(CompanyContext::class)->setCompany($this->company, $member);

        $payload = [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'Service', 'quantity' => '1.000000', 'unit_price' => '100.000000']],
        ];

        // Member creates draft successfully
        $this->commands->create($this->company, $member, $payload, 'k-rbac-member');

        $staleContext = clone app(CompanyContext::class);

        // Now owner downgrades or deactivates member
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        app(ToggleCompanyMemberStatusAction::class)->execute($this->company, $membership, $this->owner);

        // Retain the pre-revocation context to prove command-level fresh authorization.
        $this->actingAs($member);
        app()->instance(CompanyContext::class, $staleContext);

        // Replay of the exact same key MUST fail fresh RBAC check before replay!
        $this->expectException(AuthorizationException::class);
        $this->commands->create($this->company, $member, $payload, 'k-rbac-member');
    }

    public function test_preview_requires_both_view_and_post_permissions_and_does_not_catch_auth_as_stock_blocker(): void
    {
        $membership = $this->addMember('Viewer', 'viewer@test.com');
        $viewer = $membership->user;

        $invoice = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'Service', 'quantity' => '1.000000', 'unit_price' => '100.000000']],
        ], 'k-auth-preview');

        $this->actingAs($viewer);
        app(CompanyContext::class)->setCompany($this->company, $viewer);

        $this->expectException(AuthorizationException::class);
        $this->commands->preview($invoice, $viewer);
    }

    public function test_exact_currencies_ils_usd_jod_formatting_and_minor_units(): void
    {
        // 1. ILS (base currency, 2 decimals)
        $invIls = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'ILS Item', 'quantity' => '1.000000', 'unit_price' => '10.500000']],
        ], 'k-cur-ils');
        $prevIls = $this->commands->preview($invIls, $this->owner);
        $this->assertEquals('10.500000', $prevIls['totals']['grand_total_currency']);

        // 2. USD (rate 3.50, 2 minor units)
        $invUsd = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'USD Item', 'quantity' => '2.000000', 'unit_price' => '20.000000']],
        ], 'k-cur-usd');
        $prevUsd = $this->commands->preview($invUsd, $this->owner);
        $this->assertEquals('40.000000', $prevUsd['totals']['grand_total_currency']);
        $this->assertEquals('140.000000', $prevUsd['totals']['grand_total_base']);

        // 3. JOD (rate 5.00, 3 minor units)
        $invJod = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'JOD',
            'exchange_rate' => '5.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'JOD Item', 'quantity' => '1.000000', 'unit_price' => '12.345000']],
        ], 'k-cur-jod');
        $prevJod = $this->commands->preview($invJod, $this->owner);
        $this->assertEquals('12.345000', $prevJod['totals']['grand_total_currency']);
        $this->assertEquals('61.725000', $prevJod['totals']['grand_total_base']);
    }

    public function test_equivalent_decimal_retry_converges_to_same_receipt(): void
    {
        $payload1 = [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'item_description' => 'Equiv service',
                    'quantity' => '1',
                    'unit_price' => '100',
                ],
            ],
        ];

        $invoice1 = $this->commands->create($this->company, $this->owner, $payload1, 'k-equiv-decimal');

        // Retry with trailing zeroes and explicit 0 discount
        $payload2 = [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'item_description' => 'Equiv service',
                    'quantity' => '1.000000',
                    'unit_price' => '100.000000',
                    'discount_value' => '0.000000',
                ],
            ],
        ];

        $invoice2 = $this->commands->create($this->company, $this->owner, $payload2, 'k-equiv-decimal');

        $this->assertEquals($invoice1->id, $invoice2->id);
    }

    public function test_canonical_percent_discount_with_tax_exact_totals(): void
    {
        $taxAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'tax_output')->firstOrFail();
        /** @var TaxRate $taxRate */
        $taxRate = TaxRate::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'code' => 'VAT16',
            'name_ar' => 'ضريبة 16%',
            'name_en' => 'VAT 16%',
            'rate' => '16.000000',
            'calculation' => TaxRate::CALC_EXCLUSIVE,
            'sales_tax_account_id' => $taxAccount->id,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $payload = [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'item_description' => 'Discounted Item',
                    'quantity' => '2.000000',
                    'unit_price' => '100.000000',
                    'discount_type' => 'percent',
                    'discount_value' => '10.000000',
                    'tax_rate_id' => $taxRate->id,
                ],
            ],
        ];

        $invoice = $this->commands->create($this->company, $this->owner, $payload, 'k-percent-tax');

        $this->assertEquals('200.000000', (string) $invoice->subtotal_currency);
        $this->assertEquals('20.000000', (string) $invoice->discount_total_currency);
        $this->assertEquals('28.800000', (string) $invoice->tax_total_currency);
        $this->assertEquals('208.800000', (string) $invoice->grand_total_currency);

        $preview = $this->commands->preview($invoice, $this->owner);
        $this->assertTrue($preview['can_execute']);
        $this->assertEquals('208.800000', $preview['totals']['grand_total_currency']);

        $posted = $this->commands->execute($invoice, $this->owner, $preview['confirmation_token'], 'k-percent-tax-exec');
        $this->assertEquals('posted', $posted->status);
        $this->assertEquals('208.800000', (string) $posted->grand_total_currency);
    }

    public function test_erased_draft_tombstone_returns_409_gone_on_retry(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'Ephemeral service', 'quantity' => '1.000000', 'unit_price' => '100.000000']],
        ];

        $invoice = $this->commands->create($this->company, $this->owner, $payload, 'k-tombstone');
        $this->assertNotNull($invoice);

        // Delete unlinked standalone draft invoice (ADR0004 permitted)
        $invoice->lines()->delete();
        $invoice->delete();

        // Exact retry with same key throws 409 Gone rather than recreating draft
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invoice associated with this idempotency receipt has been deleted.');
        $this->commands->create($this->company, $this->owner, $payload, 'k-tombstone');
    }

    public function test_stale_company_or_customer_identity_triggers_stale_preview_conflict(): void
    {
        $invoice = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'Service', 'quantity' => '1.000000', 'unit_price' => '100.000000']],
        ], 'k-stale-ident');

        $preview = $this->commands->preview($invoice, $this->owner);
        $token = $preview['confirmation_token'];

        // Customer display identity updated after preview
        $this->customer->update(['name_ar' => 'اسم عميل جديد']);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Inventory, tax, or pricing context has changed since preview.');
        $this->commands->execute($invoice, $this->owner, $token, 'k-stale-ident-exec');
    }

    public function test_preview_fresh_membership_role_and_status_checks(): void
    {
        $membership = $this->addMember('Manager', 'prev_mgr@test.com');
        $member = $membership->user;

        $invoice = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'Service', 'quantity' => '1.000000', 'unit_price' => '100.000000']],
        ], 'k-prev-auth');

        $this->actingAs($member);
        app(CompanyContext::class)->setCompany($this->company, $member);

        // Member previews successfully
        $preview = $this->commands->preview($invoice, $member);
        $this->assertNotEmpty($preview['confirmation_token']);

        $staleContext = clone app(CompanyContext::class);

        // Switch to owner and deactivate member
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        app(ToggleCompanyMemberStatusAction::class)->execute($this->company, $membership, $this->owner);

        // Retain the pre-revocation context to prove command-level fresh authorization.
        $this->actingAs($member);
        app()->instance(CompanyContext::class, $staleContext);

        try {
            $this->commands->preview($invoice, $member);
            $this->fail('Expected AuthorizationException on preview by deactivated member.');
        } catch (AuthorizationException) {
            $this->assertSame(0, PostingBatch::where('source_type', 'sales_invoice')->count());
        }

        // Restore owner context
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
    }

    public function test_replay_requires_fresh_view_permission(): void
    {
        $membership = $this->addMember('Manager', 'view_perm_mgr@test.com');
        $member = $membership->user;

        $this->actingAs($member);
        app(CompanyContext::class)->setCompany($this->company, $member);

        $payload = [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'Service', 'quantity' => '1.000000', 'unit_price' => '100.000000']],
        ];

        // Member creates draft successfully
        $this->commands->create($this->company, $member, $payload, 'k-view-replay');

        // Demote member to Warehouse role (which lacks sales.invoice.view)
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        app(UpdateCompanyMemberRoleAction::class)->execute($this->company, $membership, 'Warehouse', $this->owner);

        // Switch back to demoted member
        $this->actingAs($member);
        app(CompanyContext::class)->setCompany($this->company, $member);

        // Replay of same key MUST fail fresh RBAC check for sales.invoice.view before returning draft
        try {
            $this->commands->create($this->company, $member, $payload, 'k-view-replay');
            $this->fail('Expected AuthorizationException when replaying without view permission.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('posting_batches')->where('company_id', $this->company->id)->where('source_type', 'sales_invoice')->count());
        }

        // Restore owner context
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
    }

    public function test_missing_company_context_refuses_commands(): void
    {
        app(CompanyContext::class)->clear();

        $payload = [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'Service', 'quantity' => '1.000000', 'unit_price' => '100.000000']],
        ];

        try {
            $this->commands->create($this->company, $this->owner, $payload, 'k-no-context');
            $this->fail('Expected AuthorizationException without active company context.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('posting_batches')->where('company_id', $this->company->id)->where('source_type', 'sales_invoice')->count());
        }

        // Restore owner context
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
    }

    public function test_product_unit_tax_invalid_eligibility_blocks_preview(): void
    {
        $invoice = $this->commands->create($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                [
                    'product_id' => $this->productStock->id,
                    'product_unit_id' => $this->productStockUnit->id,
                    'item_description' => 'Stock product',
                    'quantity' => '1.000000',
                    'unit_price' => '25.000000',
                ],
            ],
        ], 'k-inelig-prod');

        // Deactivate product after draft creation
        $this->productStock->update(['active' => false]);

        $preview = $this->commands->preview($invoice, $this->owner);
        $this->assertFalse($preview['can_execute']);
        $this->assertNotEmpty($preview['blockers']);
        $this->assertEquals('product_inactive', $preview['blockers'][0]['code']);

        // Attempting execute throws 409
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invoice execution blocked:');
        $this->commands->execute($invoice, $this->owner, $preview['confirmation_token'], 'k-inelig-exec');
    }
}
