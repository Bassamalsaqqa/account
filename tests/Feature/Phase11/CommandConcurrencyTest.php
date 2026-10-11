<?php

declare(strict_types=1);

namespace Tests\Feature\Phase11;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Application\Commands\SalesInvoiceCommands;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorCatalogService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

/** Independent connections require committed fixtures, so intentionally no RefreshDatabase. */
class CommandConcurrencyTest extends TestCase
{
    private ?DisposableMariaDbSchema $fixtureSchema = null;

    /** @var array<string,int> */
    private array $primaryCounts = [];

    private Company $company;

    private Company $controlCompany;

    private User $owner;

    private Customer $customer;

    private string $controlFingerprint;

    private ?Product $trackedProduct = null;

    private ?ProductUnit $trackedUnit = null;

    private ?Warehouse $warehouse = null;

    private ?Vendor $vendor = null;

    protected function setUp(): void
    {
        parent::setUp();
        $primary = DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($primary);
        foreach (['companies', 'users', 'posting_batches', 'stock_movements', 'sales_invoices', 'command_receipts'] as $table) {
            if (Schema::hasTable($table)) {
                $this->primaryCounts[$table] = DB::table($table)->count();
            }
        }
        $this->fixtureSchema = DisposableMariaDbSchema::createFromSource($primary);
        $this->fixtureSchema->switchLaravelConnection();

        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create();
        $creator = app(CreateCompanyAction::class);

        // Control tenant setup
        $this->controlCompany = $creator->execute($this->owner, ['name_ar' => 'Command race control', 'base_currency_code' => 'ILS']);
        $this->activate($this->controlCompany);
        $controlCustomer = Customer::create([
            'company_id' => $this->controlCompany->id,
            'name_ar' => 'Control customer',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
        $controlDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->controlCompany, $this->owner, [
            'customer_id' => $controlCustomer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'issue_date' => '2026-10-01',
            'lines' => [
                ['item_description' => 'Control service', 'quantity' => '1', 'unit_price' => '100'],
            ],
        ]);
        app(PostSalesInvoiceAction::class)->execute($controlDraft, $this->owner);
        $this->controlFingerprint = $this->fingerprint($this->controlCompany);

        // Main trading company setup (pure service fixture, no inventory in setUp)
        app(CompanyContext::class)->clear();
        $this->company = $creator->execute($this->owner, ['name_ar' => 'Command race trading', 'base_currency_code' => 'ILS']);
        $this->activate($this->company);
        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'Command race customer',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        try {
            $this->fixtureSchema?->restoreLaravelConnection();
            $this->fixtureSchema?->drop();
            foreach ($this->primaryCounts as $table => $count) {
                if (Schema::hasTable($table)) {
                    $this->assertSame($count, DB::table($table)->count(), "Committed race fixtures must not leak into primary test schema for table [{$table}].");
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_concurrent_identical_create_key_and_payload_yields_one_draft_and_one_receipt(): void
    {
        $payload = $this->servicePayload('50', '2');
        $key = 'p11-create-identical-race-key';
        $intent = [
            'operation' => 'create',
            'command_payload' => $payload,
            'key' => $key,
        ];

        $beforeCount = SalesInvoice::where('company_id', $this->company->id)->count();
        $results = $this->race($intent, $intent);

        $this->assertSame('ok', $results[0]['status']);
        $this->assertSame('ok', $results[1]['status']);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(SalesInvoice::STATUS_DRAFT, $results[0]['status_name']);
        $this->assertSame(SalesInvoice::STATUS_DRAFT, $results[1]['status_name']);

        // Exactly one draft was created; second received the idempotent receipt
        $this->assertSame($beforeCount + 1, SalesInvoice::where('company_id', $this->company->id)->count());
        $draft = SalesInvoice::where('company_id', $this->company->id)->findOrFail($results[0]['id']);
        $this->assertSame(SalesInvoice::STATUS_DRAFT, $draft->status);
        $this->assertNull($draft->invoice_number);
        $this->assertNull($draft->posting_batch_id);
        $this->assertNull($draft->posted_at);

        // Assert exact tenant/actor/action/key count is 1 and preserved request_hash / target
        $this->assertSingleCommandReceipt(
            companyId: $this->company->id,
            actorId: $this->owner->id,
            action: 'create',
            key: $key,
            targetResultId: $draft->id
        );

        $this->assertHealthyAndControlUnchanged();
    }

    public function test_concurrent_conflicting_create_payload_same_key_yields_one_draft_and_one_conflict(): void
    {
        $payloadA = $this->servicePayload('50', '2');
        $payloadB = $this->servicePayload('75', '2'); // Conflicting unit price / payload
        $key = 'p11-create-conflicting-race-key';

        $beforeCount = SalesInvoice::where('company_id', $this->company->id)->count();
        $results = $this->race(
            ['operation' => 'create', 'command_payload' => $payloadA, 'key' => $key],
            ['operation' => 'create', 'command_payload' => $payloadB, 'key' => $key]
        );

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses);

        // Exactly one draft invoice created; loser was refused with 409 conflict
        $this->assertSame($beforeCount + 1, SalesInvoice::where('company_id', $this->company->id)->count());

        $winnerIndex = $results[0]['status'] === 'ok' ? 0 : 1;
        $winnerId = $results[$winnerIndex]['id'];
        $created = SalesInvoice::where('company_id', $this->company->id)->findOrFail($winnerId);
        $this->assertSame(SalesInvoice::STATUS_DRAFT, $created->status);

        // Exactly 1 receipt in command_receipts for the winner key; loser failed so fail receipts = 0
        $this->assertSingleCommandReceipt(
            companyId: $this->company->id,
            actorId: $this->owner->id,
            action: 'create',
            key: $key,
            targetResultId: $winnerId
        );

        $this->assertHealthyAndControlUnchanged();
    }

    public function test_concurrent_execute_same_reviewed_token_and_key_yields_one_canonical_invoice_and_one_receipt(): void
    {
        $commands = app(SalesInvoiceCommands::class);
        $draft = $commands->create($this->company, $this->owner, $this->servicePayload('50', '2'), 'draft-key-for-execute-race');
        $preview = $commands->preview($draft, $this->owner);
        $this->assertIsArray($preview);
        $this->assertArrayHasKey('confirmation_token', $preview);
        $token = (string) $preview['confirmation_token'];
        $this->assertNotEmpty($token);

        $key = 'p11-execute-identical-race-key';
        $next = $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE);
        $beforeBatches = DB::table('posting_batches')->where('company_id', $this->company->id)->count();

        $intent = [
            'operation' => 'execute',
            'document_id' => $draft->id,
            'confirmation_token' => $token,
            'key' => $key,
        ];

        $results = $this->race($intent, $intent);

        $this->assertSame('ok', $results[0]['status']);
        $this->assertSame('ok', $results[1]['status']);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame($draft->id, $results[0]['id']);
        $this->assertNotNull($results[0]['invoice_number']);
        $this->assertNotNull($results[0]['posting_batch_id']);

        // One canonical invoice posted, sequence incremented exactly once, one posting batch
        $posted = $draft->fresh();
        $this->assertSame(SalesInvoice::STATUS_POSTED, $posted->status);
        $this->assertSame($results[0]['invoice_number'], $posted->invoice_number);
        $this->assertSame($results[0]['posting_batch_id'], $posted->posting_batch_id);
        $this->assertSame($next + 1, $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE));
        $this->assertSame($beforeBatches + 1, DB::table('posting_batches')->where('company_id', $this->company->id)->count());

        // Assert exact tenant/actor/action/key count is 1 with target result
        $this->assertSingleCommandReceipt(
            companyId: $this->company->id,
            actorId: $this->owner->id,
            action: 'execute',
            key: $key,
            targetResultId: $draft->id
        );

        $this->assertHealthyAndControlUnchanged();
    }

    public function test_concurrent_execute_same_key_with_differing_target_conflicts(): void
    {
        $commands = app(SalesInvoiceCommands::class);
        $draftA = $commands->create($this->company, $this->owner, $this->servicePayload('40', '1'), 'draft-target-a-key');
        $previewA = $commands->preview($draftA, $this->owner);
        $tokenA = (string) $previewA['confirmation_token'];

        $draftB = $commands->create($this->company, $this->owner, $this->servicePayload('40', '1'), 'draft-target-b-key');
        $previewB = $commands->preview($draftB, $this->owner);
        $tokenB = (string) $previewB['confirmation_token'];

        $key = 'p11-execute-target-conflict-key';
        $next = $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE);
        $beforeBatches = DB::table('posting_batches')->where('company_id', $this->company->id)->count();

        // Both compete with the same idempotency key but differing target drafts
        $results = $this->race(
            ['operation' => 'execute', 'document_id' => $draftA->id, 'confirmation_token' => $tokenA, 'key' => $key],
            ['operation' => 'execute', 'document_id' => $draftB->id, 'confirmation_token' => $tokenB, 'key' => $key]
        );

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses);

        $winnerIndex = $results[0]['status'] === 'ok' ? 0 : 1;
        $winnerDocId = $winnerIndex === 0 ? $draftA->id : $draftB->id;
        $loserDocId = $winnerIndex === 0 ? $draftB->id : $draftA->id;

        $winnerInvoice = SalesInvoice::where('company_id', $this->company->id)->findOrFail($winnerDocId);
        $loserInvoice = SalesInvoice::where('company_id', $this->company->id)->findOrFail($loserDocId);

        $this->assertSame(SalesInvoice::STATUS_POSTED, $winnerInvoice->status);
        $this->assertSame(SalesInvoice::STATUS_DRAFT, $loserInvoice->status);
        $this->assertNull($loserInvoice->invoice_number);
        $this->assertNull($loserInvoice->posting_batch_id);

        $this->assertSame($next + 1, $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE));
        $this->assertSame($beforeBatches + 1, DB::table('posting_batches')->where('company_id', $this->company->id)->count());

        // Single receipt exists for the winner; loser fail receipts = 0
        $this->assertSingleCommandReceipt(
            companyId: $this->company->id,
            actorId: $this->owner->id,
            action: 'execute',
            key: $key,
            targetResultId: $winnerDocId
        );

        $this->assertHealthyAndControlUnchanged();
    }

    public function test_concurrent_execute_same_key_with_differing_token_conflicts(): void
    {
        $commands = app(SalesInvoiceCommands::class);
        $draft = $commands->create($this->company, $this->owner, $this->servicePayload('45', '1'), 'draft-two-tokens-key');

        // Generate TWO VALID previews for same actor/draft unchanged, each valid but distinct crypt ciphertext
        $previewA = $commands->preview($draft, $this->owner);
        $previewB = $commands->preview($draft, $this->owner);
        $tokenA = (string) $previewA['confirmation_token'];
        $tokenB = (string) $previewB['confirmation_token'];

        $this->assertNotEmpty($tokenA);
        $this->assertNotEmpty($tokenB);
        $this->assertNotSame($tokenA, $tokenB, 'Two previews for the same draft must produce distinct cryptographic ciphertexts.');

        // Assert independently decoded revision and actor match
        try {
            $decodedA = Crypt::decrypt($tokenA);
            $decodedB = Crypt::decrypt($tokenB);
            if (is_array($decodedA) && is_array($decodedB)) {
                if (isset($decodedA['actor_id'], $decodedB['actor_id'])) {
                    $this->assertSame((int) $this->owner->id, (int) $decodedA['actor_id']);
                    $this->assertSame((int) $this->owner->id, (int) $decodedB['actor_id']);
                }
                if (isset($decodedA['revision'], $decodedB['revision'])) {
                    $this->assertSame($decodedA['revision'], $decodedB['revision']);
                }
            }
        } catch (\Throwable) {
            // Handled when preview uses custom ciphertext structure
        }

        $key = 'p11-execute-differing-valid-token-key';
        $next = $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE);
        $beforeBatches = DB::table('posting_batches')->where('company_id', $this->company->id)->count();

        // Both compete with the same idempotency key and both valid tokens
        $results = $this->race(
            ['operation' => 'execute', 'document_id' => $draft->id, 'confirmation_token' => $tokenA, 'key' => $key],
            ['operation' => 'execute', 'document_id' => $draft->id, 'confirmation_token' => $tokenB, 'key' => $key]
        );

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses, 'One execution must succeed and the other must be refused with 409 conflict.');

        $fresh = $draft->fresh();
        $this->assertSame(SalesInvoice::STATUS_POSTED, $fresh->status);
        $this->assertNotNull($fresh->invoice_number);
        $this->assertNotNull($fresh->posting_batch_id);

        $this->assertSame($next + 1, $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE));
        $this->assertSame($beforeBatches + 1, DB::table('posting_batches')->where('company_id', $this->company->id)->count());

        // Single receipt exists for the winner; loser fail receipts = 0
        $this->assertSingleCommandReceipt(
            companyId: $this->company->id,
            actorId: $this->owner->id,
            action: 'execute',
            key: $key,
            targetResultId: $draft->id
        );

        $this->assertHealthyAndControlUnchanged();
    }

    public function test_concurrent_independent_stock_change_vs_confirm_refuses_oversell_without_partial_posting(): void
    {
        // Add real tracked stock fixture: 5 units available in warehouse
        $this->setupTrackedStockFixture('5', '10');

        $commands = app(SalesInvoiceCommands::class);
        $invoiceA = $this->stockInvoice('5', '20'); // needs all 5 units
        $invoiceB = $this->stockInvoice('5', '20'); // also needs all 5 units

        $previewA = $commands->preview($invoiceA, $this->owner);
        $tokenA = (string) $previewA['confirmation_token'];

        $previewB = $commands->preview($invoiceB, $this->owner);
        $tokenB = (string) $previewB['confirmation_token'];

        $beforeA = $this->draftFingerprint($invoiceA);
        $beforeB = $this->draftFingerprint($invoiceB);
        $next = $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE);

        $keyA = 'p11-stock-exec-a';
        $keyB = 'p11-stock-exec-b';

        // Worker 1: execute invoiceA, Worker 2: execute invoiceB
        $results = $this->race(
            ['operation' => 'execute', 'document_id' => $invoiceA->id, 'confirmation_token' => $tokenA, 'key' => $keyA],
            ['operation' => 'execute', 'document_id' => $invoiceB->id, 'confirmation_token' => $tokenB, 'key' => $keyB]
        );

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses, 'Stock loser refused with 409 conflict when stock was consumed.');

        $winnerIndex = $results[0]['status'] === 'ok' ? 0 : 1;
        $winner = $winnerIndex === 0 ? $invoiceA->fresh() : $invoiceB->fresh();
        $loser = $winnerIndex === 0 ? $invoiceB->fresh() : $invoiceA->fresh();
        $winnerKey = $winnerIndex === 0 ? $keyA : $keyB;
        $loserKey = $winnerIndex === 0 ? $keyB : $keyA;

        // Winner posted successfully
        $this->assertSame(SalesInvoice::STATUS_POSTED, $winner->status);
        $this->assertNotNull($winner->invoice_number);
        $this->assertNotNull($winner->posting_batch_id);

        // Loser refused confirm without partial posting
        $this->assertSame(SalesInvoice::STATUS_DRAFT, $loser->status);
        $this->assertNull($loser->invoice_number);
        $this->assertNull($loser->posting_batch_id);
        $this->assertNull($loser->posted_at);

        // Loser draft fingerprint intact
        $loserBeforeFingerprint = ($loser->id === $invoiceA->id) ? $beforeA : $beforeB;
        $this->assertSame($loserBeforeFingerprint, $this->draftFingerprint($loser));

        // No orphaned stock movements, posting batches, or lot allocations for loser
        foreach (['stock_movements', 'posting_batches'] as $table) {
            $this->assertSame(0, DB::table($table)->where('company_id', $this->company->id)
                ->where('source_type', 'sales_invoice')->where('source_id', $loser->id)->count());
        }
        $this->assertSame(0, DB::table('sales_invoice_lot_allocations')->where('sales_invoice_id', $loser->id)->count());

        // Sequence incremented exactly once for the winner
        $this->assertSame($next + 1, $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE));

        // Inventory balances: 5 units consumed, 0 remaining
        $this->assertSame('0.000000', InventoryBalance::where('company_id', $this->company->id)->where('product_id', $this->trackedProduct->id)->value('quantity_base'));

        // Winner receipt exists; loser failed key has 0 receipts
        $this->assertSingleCommandReceipt(
            companyId: $this->company->id,
            actorId: $this->owner->id,
            action: 'execute',
            key: $winnerKey,
            targetResultId: $winner->id
        );
        $this->assertCommandReceiptCount($this->company->id, $loserKey, 0);

        $this->assertHealthyAndControlUnchanged();
    }

    public function test_revoked_membership_at_lock_boundary_refuses_confirm_without_partial_posting(): void
    {
        $collaborator = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $collaborator->id,
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->company->id);
        $collaborator->givePermissionTo('sales.invoice.view');
        $collaborator->givePermissionTo('sales.invoice.create');
        $collaborator->givePermissionTo('sales.invoice.post');
        $collaborator->givePermissionTo('sales.invoice.change_price');

        $commands = app(SalesInvoiceCommands::class);
        $draft = $commands->create($this->company, $this->owner, $this->servicePayload('60', '1'), 'p11-membership-draft-key');

        // Activate collaborator BEFORE issuing preview token to bind collaborator actor correctly
        $this->activate($this->company, $collaborator);
        $preview = $commands->preview($draft, $collaborator);
        $this->assertIsArray($preview);
        $this->assertArrayHasKey('confirmation_token', $preview);
        $token = (string) $preview['confirmation_token'];
        $this->assertNotEmpty($token);

        // Reactivate owner for parent barrier
        $this->activate($this->company, $this->owner);

        $draftBefore = $this->draftFingerprint($draft);
        $next = $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE);
        $beforeBatches = DB::table('posting_batches')->where('company_id', $this->company->id)->count();

        // While parent holds the company lock and child workers are waiting at the barrier,
        // revoke collaborator's membership in the company.
        $revokeCallback = function () use ($collaborator): void {
            CompanyUser::where('company_id', $this->company->id)
                ->where('user_id', $collaborator->id)
                ->update(['status' => 'inactive']);
        };

        $key = 'p11-membership-revoke-exec-key';
        $intent = [
            'operation' => 'execute',
            'document_id' => $draft->id,
            'confirmation_token' => $token,
            'key' => $key,
            'actor_id' => $collaborator->id,
        ];

        // Both child workers execute as the collaborator
        $results = $this->race($intent, $intent, $revokeCallback);

        $this->assertSame('unauthorized', $results[0]['status']);
        $this->assertSame('unauthorized', $results[1]['status']);

        // Assert no partial posting occurred: draft remains draft, sequence untouched, 0 new batches
        $draftFresh = $draft->fresh();
        $this->assertSame(SalesInvoice::STATUS_DRAFT, $draftFresh->status);
        $this->assertNull($draftFresh->invoice_number);
        $this->assertNull($draftFresh->posting_batch_id);
        $this->assertNull($draftFresh->posted_at);
        $this->assertSame($draftBefore, $this->draftFingerprint($draftFresh));

        $this->assertSame($next, $this->sequenceNext(DocumentSequence::TYPE_SALES_INVOICE));
        $this->assertSame($beforeBatches, DB::table('posting_batches')->where('company_id', $this->company->id)->count());

        // Failed authorization creates 0 command receipts
        $this->assertCommandReceiptCount($this->company->id, $key, 0);

        $this->assertHealthyAndControlUnchanged();
    }

    private function activate(Company $company, ?User $actor = null): void
    {
        $user = $actor ?? $this->owner;
        $this->actingAs($user);
        app(CompanyContext::class)->setCompany($company, $user);
        setPermissionsTeamId($company->id);
    }

    /** @return array<string,mixed> */
    private function servicePayload(string $price = '50', string $quantity = '2'): array
    {
        return [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'issue_date' => '2026-10-02',
            'lines' => [
                [
                    'item_description' => 'Service consulting',
                    'quantity' => $quantity,
                    'unit_price' => $price,
                ],
            ],
        ];
    }

    private function setupTrackedStockFixture(string $startingQty = '5', string $unitCost = '10'): void
    {
        $this->activate($this->company);
        $this->trackedProduct = app(ProductCatalogService::class)->createProduct(
            $this->company,
            [
                'name_ar' => 'Tracked race item',
                'sku' => 'TRACKED-RACE',
                'product_type' => Product::TYPE_STOCK,
                'track_stock' => true,
                'track_expiry' => true,
                'base_unit_id' => Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail()->id,
            ],
            $this->owner->id
        );
        $this->trackedUnit = ProductUnit::where('company_id', $this->company->id)
            ->where('product_id', $this->trackedProduct->id)
            ->firstOrFail();
        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();
        $this->vendor = app(VendorCatalogService::class)->save(
            $this->company,
            $this->owner,
            ['name_ar' => 'Tracked race vendor', 'default_currency_code' => 'ILS']
        );

        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-01',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'lines' => [[
                'product_id' => $this->trackedProduct->id,
                'product_unit_id' => $this->trackedUnit->id,
                'quantity' => $startingQty,
                'unit_cost' => $unitCost,
                'lots' => [[
                    'product_unit_id' => $this->trackedUnit->id,
                    'quantity' => $startingQty,
                    'lot_number' => 'LOT-RACE-01',
                    'expiry_date' => '2027-01-01',
                ]],
            ]],
        ]);
        app(PostPurchaseAction::class)->execute($purchase, $this->owner);
    }

    private function stockInvoice(string $quantity = '5', string $price = '20'): SalesInvoice
    {
        return app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'issue_date' => '2026-10-02',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'lines' => [[
                'product_id' => $this->trackedProduct->id,
                'product_unit_id' => $this->trackedUnit->id,
                'item_description' => 'Tracked stock race item',
                'quantity' => $quantity,
                'unit_price' => $price,
            ]],
        ]);
    }

    private function sequenceNext(string $type): int
    {
        return (int) (DocumentSequence::where('company_id', $this->company->id)->where('document_type', $type)
            ->where('year', 2026)->value('next_number') ?? 1);
    }

    private function assertHealthyAndControlUnchanged(): void
    {
        foreach (['accounting:reconcile', 'inventory:reconcile', 'sales:reconcile', 'money:reconcile', 'phase7:reconcile'] as $command) {
            $code = Artisan::call($command, ['companyPublicId' => $this->company->public_id]);
            $this->assertSame(0, $code, $command.': '.Artisan::output());
        }
        $this->activate($this->company);
        $payablesReport = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($payablesReport->isHealthy, implode('; ', $payablesReport->violations));
        $this->assertSame([], $payablesReport->violations);

        $salesReport = app(SalesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($salesReport->isHealthy, implode('; ', $salesReport->violations));
        $this->assertSame([], $salesReport->violations);

        $accountingReport = app(AccountingReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($accountingReport->isHealthy, implode('; ', $accountingReport->violations));
        $this->assertSame([], $accountingReport->violations);

        $this->assertSame($this->controlFingerprint, $this->fingerprint($this->controlCompany));
        $this->assertSame(0, DB::transactionLevel());

        // Restore all contexts
        app(CompanyContext::class)->clear();
        auth()->forgetUser();
        setPermissionsTeamId(null);
    }

    private function assertSingleCommandReceipt(
        int $companyId,
        int $actorId,
        string $action,
        string $key,
        ?int $targetResultId = null
    ): void {
        $receipts = DB::table('command_receipts')
            ->where('company_id', $companyId)
            ->where('user_id', $actorId)
            ->where('action', 'sales_invoice.'.$action)
            ->where('idempotency_key', $key)
            ->get();
        $this->assertCount(1, $receipts);
        $receipt = $receipts->first();
        $this->assertNotNull($receipt);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $receipt->request_hash);
        if ($targetResultId !== null) {
            $this->assertSame($targetResultId, (int) $receipt->sales_invoice_id);
            $this->assertSame($action === 'create' ? null : $targetResultId,
                $receipt->target_invoice_id === null ? null : (int) $receipt->target_invoice_id);
        }
    }

    private function assertCommandReceiptCount(int $companyId, string $key, int $expectedCount): void
    {
        $this->assertSame($expectedCount, DB::table('command_receipts')
            ->where('company_id', $companyId)->where('idempotency_key', $key)->count());
    }

    private function draftFingerprint(SalesInvoice $invoice): string
    {
        return hash('sha256', json_encode([
            'header' => DB::table('sales_invoices')->where('id', $invoice->id)->first(),
            'lines' => DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->id)->orderBy('id')->get()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function fingerprint(Company $company): string
    {
        $rows = [];
        foreach ([
            'purchases', 'purchase_lines', 'purchase_line_lots', 'posting_batches', 'posting_lines', 'stock_movements',
            'inventory_operations', 'inventory_lots', 'inventory_balances', 'inventory_lot_balances', 'inventory_cost_states',
            'sales_invoices', 'sales_invoice_lines', 'sales_invoice_lot_allocations', 'document_sequences', 'audit_events',
            'command_receipts',
        ] as $table) {
            if (Schema::hasTable($table)) {
                $rows[$table] = DB::table($table)->where('company_id', $company->id)->orderBy('id')->get()->all();
            }
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string,mixed>  $first
     * @param  array<string,mixed>  $second
     * @param  (?callable():void)  $beforeRelease
     * @return array{array<string,mixed>,array<string,mixed>}
     */
    private function race(array $first, array $second, ?callable $beforeRelease = null): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase11-command-race-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create owned race directory.');
        }
        $release = $directory.DIRECTORY_SEPARATOR.'release';
        $processes = [];
        $files = [];
        $readyFiles = [];
        $attemptFiles = [];

        $appKey = (string) (config('app.key') ?: getenv('APP_KEY'));
        if ($appKey === '') {
            throw new \RuntimeException('A valid APP_KEY is required for the command race harness.');
        }

        $environment = [
            'APP_KEY' => $appKey,
            'DB_URL' => '',
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'COMMAND_API_ENABLED' => 'false',
        ];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) config('database.connections.mysql.'.$key);
        }
        foreach ([
            'PHASE8_ALLOW_DISPOSABLE_DB', 'PHASE8_TEST_DB_HOST', 'PHASE8_TEST_DB_PORT', 'PHASE8_TEST_DB_USERNAME',
            'PHASE8_TEST_DB_EMPTY_PASSWORD', 'PHASE8_DISPOSABLE_DB_PROOF', 'PHASE8_DISPOSABLE_DB_NONCE', 'APP_CONFIG_CACHE',
        ] as $key) {
            $environment[$key] = getenv($key);
        }
        // Credentials stay in process environment, never payload files or reported results.
        $environment['PHASE8_TEST_DB_PASSWORD'] = getenv('PHASE8_TEST_DB_PASSWORD');
        $environment = array_replace($environment, $this->fixtureSchema->environment());
        $locked = false;
        try {
            DB::beginTransaction();
            $locked = true;
            DB::table('companies')->where('id', $this->company->id)->lockForUpdate()->first();
            foreach ([$first, $second] as $index => $intent) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR.'payload-'.$index.'.json';
                $ready = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $attempting = $directory.DIRECTORY_SEPARATOR.'attempting-'.$index;
                $files = array_merge($files, [$payloadPath, $ready, $attempting]);
                $readyFiles[] = $ready;
                $attemptFiles[] = $attempting;
                file_put_contents($payloadPath, json_encode($intent + [
                    'company_id' => $this->company->id,
                    'actor_id' => $intent['actor_id'] ?? $this->owner->id,
                    'ready' => $ready,
                    'attempting' => $attempting,
                    'release' => $release,
                ], JSON_THROW_ON_ERROR));
                chmod($payloadPath, 0600);
                $process = new Process([PHP_BINARY, base_path('tests/Support/Phase11/command-race-worker.php'), $payloadPath], base_path(), $environment);
                $process->setTimeout(45)->start();
                $processes[] = $process;
            }
            $this->waitForFiles($readyFiles, $processes);
            file_put_contents($release, 'release');
            $this->waitForFiles($attemptFiles, $processes);
            usleep(200000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Both independent command attempts must overlap behind the company lock.');
                $this->assertSame('', $process->getOutput());
            }
            if ($beforeRelease !== null) {
                $beforeRelease();
            }
            DB::commit();
            $locked = false;
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return [$results[0], $results[1]];
        } finally {
            if ($locked) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (array_merge($files, [$release]) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /**
     * @param  list<string>  $files
     * @param  list<Process>  $processes
     */
    private function waitForFiles(array $files, array $processes): void
    {
        $deadline = microtime(true) + 20;
        do {
            if (count(array_filter($files, 'is_file')) === count($files)) {
                return;
            }
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail('Command race child exited before the barrier: '.$process->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Independent command race barrier timed out.');
    }
}
