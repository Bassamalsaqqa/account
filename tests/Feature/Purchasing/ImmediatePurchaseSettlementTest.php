<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\UpdatePurchaseDraftAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Application\Commands\ImmediatePurchaseCommands;
use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Models\Check;
use App\Models\Company;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\PurchaseDraftBuilder;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ImmediatePurchaseSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Vendor $vendor;

    private Warehouse $warehouse;

    private Product $standardProduct;

    private Product $expiryProduct;

    private MoneyAccount $cashIls;

    private MoneyAccount $bankIls;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة المشتريات المباشرة',
            'name_en' => 'Immediate Purchasing Co',
        ]);
        $this->activate($this->owner);

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'المورد المعتمد',
            'name_en' => 'Canonical Vendor',
        ]);
        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();

        $piece = Unit::where('code', 'piece')->firstOrFail();

        $this->standardProduct = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج قياسي',
            'name_en' => 'Standard Product',
            'sku' => 'SKU-STD',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $piece->id,
        ], $this->owner->id);

        $this->expiryProduct = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج بتاريخ صلاحية',
            'name_en' => 'Expiry Product',
            'sku' => 'SKU-EXP',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => true,
            'base_unit_id' => $piece->id,
        ], $this->owner->id);

        $this->cashIls = MoneyAccount::where('company_id', $this->company->id)
            ->where('currency_code', 'ILS')
            ->where('account_type', MoneyAccount::TYPE_CASH)
            ->firstOrFail();

        $this->bankIls = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, [
            'account_type' => 'bank',
            'name_ar' => 'حساب بنكي شيكل',
            'name_en' => 'ILS Bank Account',
            'currency_code' => 'ILS',
        ]);
    }

    private function activate(User $actor): void
    {
        app(CompanyContext::class)->setCompany($this->company, $actor);
        $this->actingAs($actor);
        setPermissionsTeamId($this->company->id);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function draft(string $currency = 'ILS', string $rate = '1', array $lines = []): Purchase
    {
        if (empty($lines)) {
            $lines = [[
                'product_id' => $this->standardProduct->id,
                'quantity' => '10',
                'unit_cost' => '10',
            ]];
        }

        return app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-06',
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'document_locale' => 'ar',
            'lines' => $lines,
        ]);
    }

    public function test_default_auto_lot_for_expiry_product_without_manual_lot_or_expiry(): void
    {
        $form = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->expiryProduct->id)
            ->set('lines.0.quantity', '5')
            ->set('lines.0.unit_cost', '20')
            ->assertSee(__('purchasing.receiving_intent'))
            ->assertSee(__('purchase_settlement.auto_lot_notice'))
            ->call('save')
            ->assertHasNoErrors();

        $purchase = Purchase::firstOrFail();
        $this->assertSame('draft', $purchase->status);
        $this->assertCount(1, $purchase->lines);
        $line = $purchase->lines->sole();
        $this->assertCount(1, $line->lots);

        $lot = $line->lots->sole();
        $this->assertSame('5.000000', (string) $lot->quantity);
        $this->assertNull($lot->lot_number);
        $this->assertNull($lot->expiry_date);

        // Reopening form preserves auto-lot status and integer presentation
        $editForm = Livewire::test(PurchaseForm::class, ['publicId' => $purchase->public_id]);
        $this->assertFalse($editForm->get('expandedLotCustomization.0') ?? false);
        $this->assertSame('5', $editForm->get('lines.0.quantity'));
        $this->assertSame('5', $editForm->get('lines.0.lots.0.quantity'));

        // Post purchase atomically using the auto-lot
        $commands = app(ImmediatePurchaseCommands::class);
        $preview = $commands->preview($purchase, $this->owner, ['mode' => 'unpaid']);
        $result = $commands->execute($purchase, $this->owner, $preview['confirmation_token'], 'key-autolot');

        $this->assertTrue($result['purchase']->isPosted());
        $this->assertNull($result['payment']);
        $this->assertDatabaseHas('inventory_lots', [
            'company_id' => $this->company->id,
            'product_id' => $this->expiryProduct->id,
            'lot_number' => null,
            'expiry_date' => null,
        ]);
    }

    public function test_customizable_split_lots_preserved_on_save_and_reopen(): void
    {
        $form = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->expiryProduct->id)
            ->set('lines.0.quantity', '10')
            ->set('lines.0.unit_cost', '15')
            ->call('customizeLots', 0)
            ->set('lines.0.lots.0.quantity', '4')
            ->set('lines.0.lots.0.lot_number', 'BATCH-A')
            ->set('lines.0.lots.0.expiry_date', '2027-06-01')
            ->call('addLot', 0)
            ->set('lines.0.lots.1.quantity', '6')
            ->set('lines.0.lots.1.lot_number', 'BATCH-B')
            ->set('lines.0.lots.1.expiry_date', '2027-12-01')
            ->call('save')
            ->assertHasNoErrors();

        $purchase = Purchase::firstOrFail();
        $line = $purchase->lines->sole();
        $this->assertCount(2, $line->lots);

        $editable = app(PurchaseDraftBuilder::class)->editableData($purchase);
        $this->assertSame('4', $editable['lines'][0]['lots'][0]['quantity']);
        $this->assertSame('6', $editable['lines'][0]['lots'][1]['quantity']);

        // Reopening preserves split lot customization
        $editForm = Livewire::test(PurchaseForm::class, ['publicId' => $purchase->public_id]);
        $this->assertTrue($editForm->get('expandedLotCustomization.0'));
        $this->assertCount(2, $editForm->get('lines.0.lots'));

        // Post purchase with split lots
        $preview = app(ImmediatePurchaseCommands::class)->preview($purchase, $this->owner, ['mode' => 'unpaid']);
        $result = app(ImmediatePurchaseCommands::class)->execute($purchase, $this->owner, $preview['confirmation_token'], 'key-split');
        $this->assertTrue($result['purchase']->isPosted());

        $this->assertDatabaseHas('inventory_lots', [
            'company_id' => $this->company->id,
            'lot_number' => 'BATCH-A',
            'expiry_date' => '2027-06-01',
        ]);
        $this->assertDatabaseHas('inventory_lots', [
            'company_id' => $this->company->id,
            'lot_number' => 'BATCH-B',
            'expiry_date' => '2027-12-01',
        ]);
    }

    public function test_quantity_formatter_trims_integer_zeros_and_preserves_fractions(): void
    {
        $this->assertSame('10', SalesMoneyFormatter::formatQuantity('10.000000'));
        $this->assertSame('10.5', SalesMoneyFormatter::formatQuantity('10.500000'));
        $this->assertSame('0.125', SalesMoneyFormatter::formatQuantity('0.125000'));
        $this->assertSame('100.00', SalesMoneyFormatter::formatMoneyInput('100.000000'));
    }

    public function test_full_cash_settlement_preview_and_atomic_execution(): void
    {
        $purchase = $this->draft('ILS', '1', [[
            'product_id' => $this->standardProduct->id,
            'quantity' => '5',
            'unit_cost' => '20',
        ]]);
        $this->assertSame('100.000000', (string) $purchase->grand_total_currency);

        $commands = app(ImmediatePurchaseCommands::class);
        $choice = [
            'mode' => 'full',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashIls->id,
            'payment_date' => '2026-11-06',
            'amount' => '100',
            'exchange_rate' => '1',
        ];

        // Preview creates token with NO writes
        $preview = $commands->preview($purchase, $this->owner, $choice);
        $this->assertTrue($preview['can_execute']);
        $this->assertSame('0.000000', $preview['settlement']['outstanding_purchase_amount']);
        $this->assertSame('100.000000', $preview['settlement']['paid_amount']);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('vendor_payments', 0);

        // Execute atomically
        $first = $commands->execute($purchase, $this->owner, $preview['confirmation_token'], 'purchase-full-key');
        $this->assertTrue($first['purchase']->isPosted());
        $this->assertNotNull($first['payment']);
        $this->assertSame('100.000000', (string) $first['payment']->amount);
        $this->assertSame(VendorPayment::METHOD_CASH, $first['payment']->payment_method);
        $this->assertSame($this->cashIls->id, $first['payment']->money_account_id);

        $this->assertDatabaseCount('vendor_payments', 1);
        $this->assertDatabaseCount('vendor_payment_allocations', 1);
        $this->assertDatabaseCount('posting_batches', 2); // 1 purchase + 1 payment

        // Idempotent retry converges to same payment
        $retry = $commands->execute($purchase, $this->owner, $preview['confirmation_token'], 'purchase-full-key');
        $this->assertSame($first['payment']->id, $retry['payment']->id);
        $this->assertDatabaseCount('vendor_payments', 1);
        $this->assertDatabaseCount('posting_batches', 2);
    }

    public function test_partial_settlement_and_explicit_unpaid_options(): void
    {
        $purchase = $this->draft('ILS', '1', [[
            'product_id' => $this->standardProduct->id,
            'quantity' => '10',
            'unit_cost' => '10',
        ]]);
        $this->assertSame('100.000000', (string) $purchase->grand_total_currency);

        $commands = app(ImmediatePurchaseCommands::class);
        $choice = [
            'mode' => 'partial',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashIls->id,
            'payment_date' => '2026-11-06',
            'amount' => '40',
            'purchase_amount' => '40',
            'exchange_rate' => '1',
        ];

        $preview = $commands->preview($purchase, $this->owner, $choice);
        $this->assertSame('60.000000', $preview['settlement']['outstanding_purchase_amount']);
        $this->assertSame('40.000000', $preview['settlement']['purchase_amount']);

        $result = $commands->execute($purchase, $this->owner, $preview['confirmation_token'], 'key-partial');
        $this->assertTrue($result['purchase']->isPosted());
        $this->assertSame('40.000000', (string) $result['payment']->amount);
        $this->assertSame('60.000000', (string) $result['purchase']->calculateOutstanding());
    }

    public function test_outgoing_check_settlement(): void
    {
        $purchase = $this->draft('ILS', '1', [[
            'product_id' => $this->standardProduct->id,
            'quantity' => '2',
            'unit_cost' => '50',
        ]]);

        $choice = [
            'mode' => 'full',
            'payment_method' => 'check',
            'money_account_id' => $this->bankIls->id,
            'currency_code' => 'ILS',
            'check_number' => 'CHK-90210',
            'bank_name' => 'Bank of Palestine',
            'drawer' => 'Immediate Purchase Co',
            'payment_date' => '2026-11-06',
            'due_date' => '2026-12-06',
            'amount' => '100',
            'exchange_rate' => '1',
        ];

        $commands = app(ImmediatePurchaseCommands::class);
        $preview = $commands->preview($purchase, $this->owner, $choice);
        $this->assertTrue($preview['can_execute']);
        $this->assertSame('CHK-90210', $preview['settlement']['check_number']);

        $result = $commands->execute($purchase, $this->owner, $preview['confirmation_token'], 'key-check');
        $this->assertTrue($result['purchase']->isPosted());
        $this->assertNotNull($result['payment']);

        $check = Check::where('company_id', $this->company->id)->where('check_number', 'CHK-90210')->firstOrFail();
        $this->assertSame('outgoing', $check->direction);
        $this->assertSame('100.000000', (string) $check->amount);
        $this->assertSame($check->id, $result['payment']->check_id);
    }

    public function test_cross_currency_settlement_requires_both_amounts_and_base_fx_is_one(): void
    {
        $purchase = $this->draft('USD', '3.5', [[
            'product_id' => $this->standardProduct->id,
            'quantity' => '10',
            'unit_cost' => '10',
        ]]);
        $this->assertSame('100.000000', (string) $purchase->grand_total_currency);

        $commands = app(ImmediatePurchaseCommands::class);

        // Missing payment_currency_amount for cross-currency settlement fails
        $this->expectException(InvalidArgumentException::class);
        $commands->preview($purchase, $this->owner, [
            'mode' => 'full',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashIls->id,
            'payment_date' => '2026-11-06',
            'amount' => '350',
            'exchange_rate' => '1',
        ]);
    }

    public function test_cross_currency_settlement_succeeds_with_both_amounts(): void
    {
        $purchase = $this->draft('USD', '3.5', [[
            'product_id' => $this->standardProduct->id,
            'quantity' => '10',
            'unit_cost' => '10',
        ]]);

        $commands = app(ImmediatePurchaseCommands::class);
        $choice = [
            'mode' => 'full',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashIls->id,
            'payment_date' => '2026-11-06',
            'amount' => '350',
            'exchange_rate' => '1',
            'payment_currency_amount' => '350',
        ];

        $preview = $commands->preview($purchase, $this->owner, $choice);
        $this->assertSame('100.000000', $preview['settlement']['purchase_amount']);
        $this->assertSame('350.000000', $preview['settlement']['payment_currency_amount']);

        $result = $commands->execute($purchase, $this->owner, $preview['confirmation_token'], 'key-cross-currency');
        $this->assertTrue($result['purchase']->isPosted());
        $this->assertSame('ILS', $result['payment']->currency_code);
        $this->assertDatabaseHas('vendor_payment_allocations', [
            'purchase_id' => $purchase->id,
            'allocated_amount' => '100.000000',
            'payment_currency_amount' => '350.000000',
        ]);
    }

    public function test_atomic_rollback_on_payment_failure(): void
    {
        $purchase = $this->draft('ILS', '1', [[
            'product_id' => $this->standardProduct->id,
            'quantity' => '5',
            'unit_cost' => '20',
        ]]);

        $choice = [
            'mode' => 'full',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashIls->id,
            'payment_date' => '2026-11-06',
            'amount' => '100',
            'exchange_rate' => '1',
        ];

        $commands = app(ImmediatePurchaseCommands::class);
        $preview = $commands->preview($purchase, $this->owner, $choice);

        $beforePurchase = $purchase->fresh()->toArray();
        $beforeSequences = DB::table('document_sequences')->orderBy('id')->get()->toJson();

        $this->mock(PostVendorPaymentAction::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andReturnUsing(function (): never {
                throw new RuntimeException('Synthetic vendor payment failure');
            });
        });

        $commands = app(ImmediatePurchaseCommands::class);
        try {
            $commands->execute($purchase, $this->owner, $preview['confirmation_token'], 'key-rollback');
            $this->fail('Should have failed and rolled back.');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic vendor payment failure', $e->getMessage());
        }

        // Verify full rollback: purchase is still draft, no sequence jump, no postings
        $fresh = $purchase->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->posting_batch_id);
        $this->assertSame($beforeSequences, DB::table('document_sequences')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('vendor_payments', 0);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('command_receipts', 0);
    }

    public function test_reused_key_with_different_payload_is_rejected(): void
    {
        $purchase = $this->draft('ILS', '1', [[
            'product_id' => $this->standardProduct->id,
            'quantity' => '5',
            'unit_cost' => '20',
        ]]);

        $commands = app(ImmediatePurchaseCommands::class);
        $one = $commands->preview($purchase, $this->owner, [
            'mode' => 'partial',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashIls->id,
            'payment_date' => '2026-11-06',
            'amount' => '30',
            'purchase_amount' => '30',
            'exchange_rate' => '1',
        ]);
        $two = $commands->preview($purchase, $this->owner, [
            'mode' => 'partial',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashIls->id,
            'payment_date' => '2026-11-06',
            'amount' => '50',
            'purchase_amount' => '50',
            'exchange_rate' => '1',
        ]);

        $commands->execute($purchase, $this->owner, $one['confirmation_token'], 'key-conflict');

        $this->expectException(HttpException::class);
        $commands->execute($purchase, $this->owner, $two['confirmation_token'], 'key-conflict');
    }

    public function test_permission_revocation_denies_settlement(): void
    {
        $purchase = $this->draft('ILS', '1', [[
            'product_id' => $this->standardProduct->id,
            'quantity' => '5',
            'unit_cost' => '20',
        ]]);

        $commands = app(ImmediatePurchaseCommands::class);
        $preview = $commands->preview($purchase, $this->owner, [
            'mode' => 'full',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashIls->id,
            'payment_date' => '2026-11-06',
            'amount' => '100',
            'exchange_rate' => '1',
        ]);

        Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail()
            ->revokePermissionTo('money.vendor_payment.create');

        $this->expectException(AuthorizationException::class);
        $commands->execute($purchase, $this->owner, $preview['confirmation_token'], 'key-perm-revoked');
    }

    public function test_stale_consent_rejected_on_same_total_draft_edit_between_preview_and_execute(): void
    {
        $purchase = $this->draft('ILS', '1', [[
            'product_id' => $this->standardProduct->id,
            'quantity' => '10',
            'unit_cost' => '10',
        ]]);
        $this->assertSame('100.000000', (string) $purchase->grand_total_currency);

        $commands = app(ImmediatePurchaseCommands::class);
        $choice = [
            'mode' => 'full',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashIls->id,
            'payment_date' => '2026-11-06',
            'amount' => '100',
            'exchange_rate' => '1',
        ];

        $preview = $commands->preview($purchase, $this->owner, $choice);
        $this->assertTrue($preview['can_execute']);
        $staleToken = $preview['confirmation_token'];

        // Update draft with different lines and quantities, keeping the exact same grand total (100 ILS)
        app(UpdatePurchaseDraftAction::class)->execute($purchase, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-06',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'document_locale' => 'ar',
            'lines' => [[
                'product_id' => $this->standardProduct->id,
                'quantity' => '5',
                'unit_cost' => '20',
            ]],
        ]);

        $fresh = $purchase->fresh();
        $this->assertSame('100.000000', (string) $fresh->grand_total_currency);
        $this->assertSame('draft', $fresh->status);

        // Execute with stale token MUST be rejected with 409
        try {
            $commands->execute($fresh, $this->owner, $staleToken, 'key-stale-test');
            $this->fail('Expected stale consent rejection.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('Purchase draft changed since review; review the purchase again.', $e->getMessage());
        }

        // Verify zero economic writes occurred
        $this->assertSame('draft', $fresh->fresh()->status);
        $this->assertDatabaseCount('vendor_payments', 0);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('command_receipts', 0);

        // Fresh preview on current draft succeeds and can execute
        $freshPreview = $commands->preview($fresh, $this->owner, $choice);
        $result = $commands->execute($fresh, $this->owner, $freshPreview['confirmation_token'], 'key-fresh-execute');
        $this->assertTrue($result['purchase']->isPosted());
        $this->assertNotNull($result['payment']);
        $this->assertDatabaseCount('vendor_payments', 1);
        $this->assertDatabaseCount('posting_batches', 2);
    }

    public function test_stale_consent_rejected_on_vendor_change_with_same_total(): void
    {
        $purchase = $this->draft('ILS', '1', [[
            'product_id' => $this->standardProduct->id,
            'quantity' => '5',
            'unit_cost' => '20',
        ]]);

        $secondVendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد آخر',
            'name_en' => 'Second Vendor',
        ]);

        $commands = app(ImmediatePurchaseCommands::class);
        $preview = $commands->preview($purchase, $this->owner, [
            'mode' => 'full',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashIls->id,
            'payment_date' => '2026-11-06',
            'amount' => '100',
            'exchange_rate' => '1',
        ]);

        // Change vendor
        app(UpdatePurchaseDraftAction::class)->execute($purchase, $this->owner, [
            'vendor_id' => $secondVendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-06',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'document_locale' => 'ar',
            'lines' => [[
                'product_id' => $this->standardProduct->id,
                'quantity' => '5',
                'unit_cost' => '20',
            ]],
        ]);

        $this->expectException(HttpException::class);
        $commands->execute($purchase->fresh(), $this->owner, $preview['confirmation_token'], 'key-vendor-stale');
    }

    public function test_stale_consent_rejected_on_lot_modification_between_preview_and_execute(): void
    {
        $productUnitId = $this->expiryProduct->productUnits()->firstOrFail()->id;
        $purchase = $this->draft('ILS', '1', [[
            'product_id' => $this->expiryProduct->id,
            'product_unit_id' => $productUnitId,
            'quantity' => '10',
            'unit_cost' => '10',
            'lots' => [
                [
                    'product_unit_id' => $productUnitId,
                    'lot_number' => 'LOT-ALPHA',
                    'expiry_date' => '2027-01-01',
                    'quantity' => '5',
                ],
                [
                    'product_unit_id' => $productUnitId,
                    'lot_number' => 'LOT-BETA',
                    'expiry_date' => '2027-06-01',
                    'quantity' => '5',
                ],
            ],
        ]]);

        $commands = app(ImmediatePurchaseCommands::class);
        $preview = $commands->preview($purchase, $this->owner, ['mode' => 'unpaid']);

        // Update lot details
        app(UpdatePurchaseDraftAction::class)->execute($purchase, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-06',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'document_locale' => 'ar',
            'lines' => [[
                'product_id' => $this->expiryProduct->id,
                'product_unit_id' => $productUnitId,
                'quantity' => '10',
                'unit_cost' => '10',
                'lots' => [
                    [
                        'product_unit_id' => $productUnitId,
                        'lot_number' => 'LOT-MODIFIED',
                        'expiry_date' => '2027-12-31',
                        'quantity' => '10',
                    ],
                ],
            ]],
        ]);

        $this->expectException(HttpException::class);
        $commands->execute($purchase->fresh(), $this->owner, $preview['confirmation_token'], 'key-lot-stale');
    }

    public function test_replay_validates_canonical_posted_integrity_and_rejects_corrupted_purchase(): void
    {
        $purchase = $this->draft('ILS', '1', [[
            'product_id' => $this->standardProduct->id,
            'quantity' => '5',
            'unit_cost' => '20',
        ]]);

        $commands = app(ImmediatePurchaseCommands::class);
        $preview = $commands->preview($purchase, $this->owner, [
            'mode' => 'full',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashIls->id,
            'payment_date' => '2026-11-06',
            'amount' => '100',
            'exchange_rate' => '1',
        ]);

        $first = $commands->execute($purchase, $this->owner, $preview['confirmation_token'], 'key-replay-integrity');
        $this->assertTrue($first['purchase']->isPosted());

        // Replay with untouched valid purchase succeeds
        $replay = $commands->execute($purchase, $this->owner, $preview['confirmation_token'], 'key-replay-integrity');
        $this->assertSame($first['purchase']->id, $replay['purchase']->id);
        $this->assertSame($first['payment']->id, $replay['payment']->id);

        // Corrupt purchase status and verify replay catches integrity violation
        DB::table('purchases')->where('id', $purchase->id)->update(['status' => 'draft']);

        $this->expectException(InvalidArgumentException::class);
        $commands->execute($purchase->fresh(), $this->owner, $preview['confirmation_token'], 'key-replay-integrity');
    }

    public function test_zero_cost_purchase_settlement_unpaid_succeeds_without_journal_postings(): void
    {
        $purchase = $this->draft('ILS', '1', [[
            'product_id' => $this->standardProduct->id,
            'quantity' => '10',
            'unit_cost' => '0',
        ]]);
        $this->assertSame('0.000000', (string) $purchase->grand_total_currency);

        $commands = app(ImmediatePurchaseCommands::class);
        $preview = $commands->preview($purchase, $this->owner, ['mode' => 'unpaid']);
        $this->assertTrue($preview['can_execute']);
        $this->assertSame('0.000000', $preview['settlement']['outstanding_purchase_amount']);

        $result = $commands->execute($purchase, $this->owner, $preview['confirmation_token'], 'key-zero-cost');
        $this->assertTrue($result['purchase']->isPosted());
        $this->assertNull($result['payment']);
        $this->assertNull($result['purchase']->posting_batch_id);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('vendor_payments', 0);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_form_reindex_preserves_expanded_lot_customization_on_line_removal(): void
    {
        $form = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->standardProduct->id)
            ->call('addLine')
            ->call('selectProduct', 1, $this->expiryProduct->id)
            ->call('customizeLots', 1)
            ->set('lines.1.lots.0.lot_number', 'BATCH-PRESERVE')
            ->call('removeLine', 0);

        // Now former line 1 is line 0
        $this->assertTrue($form->get('expandedLotCustomization.0'));
        $this->assertSame('BATCH-PRESERVE', $form->get('lines.0.lots.0.lot_number'));
    }
}
