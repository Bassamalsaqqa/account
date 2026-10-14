<?php

declare(strict_types=1);

namespace Tests\Feature\StockAccountingIntegrity;

use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Posting\AccountingPostingService;
use App\Services\Purchasing\PurchasePostingScope;
use App\Services\Purchasing\PurchaseReturnPostingScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mockery;
use ReflectionProperty;
use Tests\Feature\Phase5D\Phase5DTestCase;

final class PurchasingAccountingBoundaryTest extends Phase5DTestCase
{
    private function state(): array
    {
        $state = [];
        $tables = [
            'purchases',
            'purchase_lines',
            'purchase_line_lots',
            'purchase_returns',
            'purchase_return_lines',
            'purchase_return_allocations',
            'document_sequences',
            'posting_batches',
            'posting_lines',
            'audit_events',
            'inventory_operations',
            'stock_movements',
            'inventory_lots',
            'inventory_balances',
            'inventory_lot_balances',
            'inventory_cost_states',
        ];

        foreach ($tables as $table) {
            $state[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
        }

        return $state;
    }

    private function rejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected runtime authority rejection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    private function dummyPurchasePostingCommand(int $companyId, int $purchaseId, ?User $actor = null, string $sourceType = 'purchase', ?string $batchNumber = null): PostingCommand
    {
        $actor ??= $this->owner;
        $invAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'inventory')->first()
            ?? LedgerAccount::where('company_id', $this->company->id)->firstOrFail();
        $apAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_payable')->first()
            ?? LedgerAccount::where('company_id', $this->company->id)->skip(1)->firstOrFail();

        $company = clone $this->company;
        $company->id = $companyId;

        return new PostingCommand(
            company: $company,
            postingDate: CarbonImmutable::parse('2026-10-04'),
            sourceType: $sourceType,
            sourceId: $purchaseId,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: "test-cmd-{$sourceType}-{$purchaseId}-".uniqid(),
            postedBy: $actor,
            batchNumber: $batchNumber,
            lines: [
                PostingLineCommand::debit(1, $invAccount->id, '100.000000', 'ILS', '100.000000', ExchangeRate::one()),
                PostingLineCommand::credit(2, $apAccount->id, '100.000000', 'ILS', '100.000000', ExchangeRate::one()),
            ]
        );
    }

    public function test_gl_posting_primitive_cannot_independently_post_purchase_outside_canonical_scope(): void
    {
        $cmd = $this->dummyPurchasePostingCommand($this->company->id, 999);

        // Directly asserting posting outside active scope must be rejected
        $this->rejected(fn () => PurchasePostingScope::assertPosting($cmd));
        $this->rejected(fn () => app(PurchasePostingScope::class)->assertPostingCommand($cmd));
    }

    public function test_gl_posting_primitive_cannot_independently_post_purchase_return_outside_canonical_scope(): void
    {
        $cmd = $this->dummyPurchasePostingCommand($this->company->id, 999, sourceType: 'purchase_return');

        // Directly asserting return posting outside active scope must be rejected
        $this->rejected(fn () => PurchaseReturnPostingScope::assertPosting($cmd));
        $this->rejected(fn () => app(PurchaseReturnPostingScope::class)->assertPostingCommand($cmd));
    }

    public function test_purchase_posting_scope_denies_arbitrary_transaction_or_idle_owner(): void
    {
        $cmd = $this->dummyPurchasePostingCommand($this->company->id, 999);

        DB::transaction(function () use ($cmd): void {
            // In an arbitrary transaction without active canonical action
            $this->rejected(fn () => PurchasePostingScope::assertPosting($cmd));

            $scope = app(PurchasePostingScope::class);
            $action = new PostPurchaseAction;
            // Action does not own scope yet
            $this->rejected(fn () => $scope->preparePosting($action, $cmd));
        });
    }

    public function test_purchase_posting_scope_denies_mismatched_command_identity_actor_source_or_company(): void
    {
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-04',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '5',
                    'unit_cost' => '10',
                ],
            ],
        ]);

        $scope = app(PurchasePostingScope::class);
        $owner = new PostPurchaseAction;
        $ownership = new ReflectionProperty($owner, 'activePostingScope');
        $ownership->setValue($owner, $scope);

        try {
            DB::transaction(function () use ($scope, $owner, $draft): void {
                $scope->withinCanonicalPosting($owner, $draft, $this->owner, function ($capability) use ($scope, $owner, $draft): void {
                    $validCmd = $this->dummyPurchasePostingCommand($this->company->id, $draft->id, $this->owner);
                    $scope->preparePosting($owner, $validCmd);

                    // Re-preparing is denied
                    $this->rejected(fn () => $scope->preparePosting($owner, $validCmd));

                    // Other command with different identity is denied
                    $otherCmd = $this->dummyPurchasePostingCommand($this->company->id, $draft->id, $this->owner);
                    $this->rejected(fn () => PurchasePostingScope::assertPosting($otherCmd));

                    // Mismatched company is denied
                    $wrongCompanyCmd = $this->dummyPurchasePostingCommand($this->company->id + 1, $draft->id, $this->owner);
                    $this->rejected(fn () => PurchasePostingScope::assertPosting($wrongCompanyCmd));

                    // Mismatched sourceId is denied
                    $wrongSourceCmd = $this->dummyPurchasePostingCommand($this->company->id, $draft->id + 1, $this->owner);
                    $this->rejected(fn () => PurchasePostingScope::assertPosting($wrongSourceCmd));

                    // Asserting the exact matching prepared command succeeds
                    PurchasePostingScope::assertPosting($validCmd);

                    // But batch not verified before return will fail if not verified
                });
            });
            $this->fail('Expected rejection due to unverified persisted batch.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('verified persisted canonical batch', $e->getMessage());
        } finally {
            $ownership->setValue($owner, null);
        }
    }

    public function test_purchase_return_posting_scope_denies_mismatched_command_identity_actor_source_or_number(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draftReturn = app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, [
            'purchase_id' => $purchase->id,
            'return_date' => '2026-10-04',
            'lines' => [
                [
                    'purchase_line_id' => $purchase->lines->first()->id,
                    'quantity' => '2',
                ],
            ],
        ]);

        $scope = app(PurchaseReturnPostingScope::class);
        $owner = new PostPurchaseReturnAction;
        $ownership = new ReflectionProperty($owner, 'activePostingScope');
        $ownership->setValue($owner, $scope);

        try {
            DB::transaction(function () use ($scope, $owner, $draftReturn): void {
                $scope->withinCanonicalReturnPosting($owner, $draftReturn, $this->owner, function ($capability) use ($scope, $owner, $draftReturn): void {
                    $scope->bindAllocatedNumber('PR-2026-0001');

                    $validCmd = $this->dummyPurchasePostingCommand($this->company->id, $draftReturn->id, $this->owner, 'purchase_return', 'PR-2026-0001');

                    $scope->preparePosting($owner, $validCmd);

                    // Other command denied
                    $otherCmd = $this->dummyPurchasePostingCommand($this->company->id, $draftReturn->id, $this->owner, 'purchase_return');
                    $this->rejected(fn () => PurchaseReturnPostingScope::assertPosting($otherCmd));

                    // Number mismatch denied
                    $mismatchedNumCmd = $this->dummyPurchasePostingCommand($this->company->id, $draftReturn->id, $this->owner, 'purchase_return', 'PR-2026-9999');
                    $this->rejected(fn () => PurchaseReturnPostingScope::assertPosting($mismatchedNumCmd));

                    // Matching command assertion succeeds
                    PurchaseReturnPostingScope::assertPosting($validCmd);
                });
            });
            $this->fail('Expected rejection due to unverified persisted batch.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('verified persisted canonical batch', $e->getMessage());
        } finally {
            $ownership->setValue($owner, null);
        }
    }

    public function test_mocked_posting_response_without_persisted_batch_prevents_orphan_stock_for_purchase(): void
    {
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-04',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '10',
                ],
            ],
        ]);

        $before = $this->state();

        $mock = Mockery::mock(AccountingPostingService::class);
        $mock->shouldReceive('post')->once()->andReturnUsing(function ($command) {
            // Fake batch not persisted in database
            $fakeBatch = new PostingBatch;
            $fakeBatch->id = 999999;
            $fakeBatch->exists = true;

            return $fakeBatch;
        });
        app()->instance(AccountingPostingService::class, $mock);

        try {
            app(PostPurchaseAction::class)->execute($draft, $this->owner);
            $this->fail('Expected failure due to unpersisted mock batch');
        } catch (ModelNotFoundException $e) {
            $this->assertSame(PostingBatch::class, $e->getModel());
        }

        // Entire transaction must roll back; no orphan stock movements committed
        $this->assertSame($before, $this->state());
        $this->assertSame(Purchase::STATUS_DRAFT, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->posting_batch_id);
        $this->assertSame(0, StockMovement::where('company_id', $this->company->id)->where('source_type', 'purchase')->count());
    }

    public function test_mocked_posting_response_without_persisted_batch_prevents_orphan_stock_for_purchase_return(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draftReturn = app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, [
            'purchase_id' => $purchase->id,
            'return_date' => '2026-10-04',
            'lines' => [
                [
                    'purchase_line_id' => $purchase->lines->first()->id,
                    'quantity' => '5',
                ],
            ],
        ]);

        $before = $this->state();

        $mock = Mockery::mock(AccountingPostingService::class);
        $mock->shouldReceive('post')->once()->andReturnUsing(function ($command) {
            $fakeBatch = new PostingBatch;
            $fakeBatch->id = 888888;
            $fakeBatch->exists = true;

            return $fakeBatch;
        });
        app()->instance(AccountingPostingService::class, $mock);

        try {
            app(PostPurchaseReturnAction::class)->execute($draftReturn, $this->owner);
            $this->fail('Expected failure due to unpersisted mock batch on return');
        } catch (ModelNotFoundException $e) {
            $this->assertSame(PostingBatch::class, $e->getModel());
        }

        // State must remain strictly unchanged; no return stock movements committed
        $this->assertSame($before, $this->state());
        $this->assertSame(PurchaseReturn::STATUS_DRAFT, $draftReturn->fresh()->status);
        $this->assertNull($draftReturn->fresh()->posting_batch_id);
        $this->assertSame(0, StockMovement::where('company_id', $this->company->id)->where('source_type', 'purchase_return')->count());
    }

    public function test_purchase_replay_outside_scope_remains_canonical_validate_posted(): void
    {
        $purchase = $this->createAndPostPurchase();
        $this->assertSame(Purchase::STATUS_POSTED, $purchase->status);
        $batchId = $purchase->posting_batch_id;
        $purchaseNumber = $purchase->purchase_number;
        $movementCount = StockMovement::where('company_id', $this->company->id)->where('source_type', 'purchase')->count();

        // Second call must validate posted and return locked record without re-entering posting scope or duplicating batches
        $replayed = app(PostPurchaseAction::class)->execute($purchase, $this->owner);
        $this->assertSame($purchase->id, $replayed->id);
        $this->assertSame(Purchase::STATUS_POSTED, $replayed->status);
        $this->assertSame($batchId, $replayed->posting_batch_id);
        $this->assertSame($purchaseNumber, $replayed->purchase_number);
        $this->assertSame($movementCount, StockMovement::where('company_id', $this->company->id)->where('source_type', 'purchase')->count());
    }

    public function test_purchase_return_replay_outside_scope_remains_canonical_validate_posted(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draftReturn = app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, [
            'purchase_id' => $purchase->id,
            'return_date' => '2026-10-04',
            'lines' => [
                [
                    'purchase_line_id' => $purchase->lines->first()->id,
                    'quantity' => '3',
                ],
            ],
        ]);
        $postedReturn = app(PostPurchaseReturnAction::class)->execute($draftReturn, $this->owner);
        $this->assertSame(PurchaseReturn::STATUS_POSTED, $postedReturn->status);
        $returnNumber = $postedReturn->return_number;
        $batchId = $postedReturn->posting_batch_id;
        $movementCount = StockMovement::where('company_id', $this->company->id)->where('source_type', 'purchase_return')->count();

        // Second call must validate posted and return locked record without duplicating batches or movements
        $replayed = app(PostPurchaseReturnAction::class)->execute($postedReturn, $this->owner);
        $this->assertSame($postedReturn->id, $replayed->id);
        $this->assertSame(PurchaseReturn::STATUS_POSTED, $replayed->status);
        $this->assertSame($returnNumber, $replayed->return_number);
        $this->assertSame($batchId, $replayed->posting_batch_id);
        $this->assertSame($movementCount, StockMovement::where('company_id', $this->company->id)->where('source_type', 'purchase_return')->count());
    }

    public function test_canonical_compensating_purchase_return_workflow_remains_allowed(): void
    {
        // No void/reversal action exists for Purchase; compensating PurchaseReturn workflow is the authoritative path
        $purchase = $this->createAndPostPurchase();
        $this->assertSame('100.000000', (string) $purchase->fresh()->grand_total_currency);
        $this->assertSame('100.000000', (string) $purchase->fresh()->payablePosition()->outstanding);

        // Compensating return of 4 items
        $draftReturn = app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, [
            'purchase_id' => $purchase->id,
            'return_date' => '2026-10-04',
            'lines' => [
                [
                    'purchase_line_id' => $purchase->lines->first()->id,
                    'quantity' => '4',
                ],
            ],
        ]);
        $postedReturn = app(PostPurchaseReturnAction::class)->execute($draftReturn, $this->owner);

        $this->assertSame(PurchaseReturn::STATUS_POSTED, $postedReturn->status);
        $this->assertNotNull($postedReturn->posting_batch_id);

        // Position accurately relieved via return
        $position = $purchase->fresh()->payablePosition();
        $this->assertSame('40.000000', (string) $position->postedReturnedAmount);
        $this->assertSame('60.000000', (string) $position->outstanding);
    }

    public function test_raw_purchase_and_return_reversal_cannot_leave_stock_unchanged(): void
    {
        $purchase = $this->createAndPostPurchase();
        $return = $this->postReturn($this->createReturnDraft($purchase));
        foreach ([$purchase->postingBatch, $return->postingBatch] as $batch) {
            $this->assertNotNull($batch);
            $before = $this->state();
            $this->rejected(fn () => app(AccountingPostingService::class)->reverse($batch, $this->owner));
            $batch->source_type = 'manual';
            $this->rejected(fn () => app(AccountingPostingService::class)->reverse($batch, $this->owner));
            $this->assertSame($before, $this->state());
            $this->assertSame(PostingBatch::STATUS_POSTED, $batch->fresh()->status);
        }
    }
}
