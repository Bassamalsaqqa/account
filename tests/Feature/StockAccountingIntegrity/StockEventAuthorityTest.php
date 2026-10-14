<?php

declare(strict_types=1);

namespace Tests\Feature\StockAccountingIntegrity;

use App\Actions\Inventory\PostOpeningStockAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Models\InventoryOperation;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\StockEventCapability;
use App\Services\Inventory\StockEventScope;
use App\Services\Posting\AccountingPostingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase8\Phase8TestCase;

final class StockEventAuthorityTest extends Phase8TestCase
{
    private function command(string $type = 'opening_balance'): StockMovementCommand
    {
        return new StockMovementCommand($this->company->id, $type, '2026-10-01', [
            new StockMovementLineCommand($this->product->id, $this->warehouse->id, Quantity::of('2'), unitCostBase: '1'),
        ], 'opening_stock', $this->company->id, 'authority-negative', $this->owner->id);
    }

    private function counts(): array
    {
        return [InventoryOperation::count(), StockMovement::count(), PostingBatch::count(), PostingLine::count()];
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid authority committed an economic effect.');
        } catch (InvalidInventoryMovementException|\InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function test_an_idle_action_and_an_arbitrary_transaction_cannot_mint_authority(): void
    {
        $before = $this->counts();
        $this->denied(fn () => DB::transaction(fn () => app(StockEventScope::class)->within(
            app(PostOpeningStockAction::class), $this->company->id, $this->company->id, $this->owner,
            fn () => app(InventoryMovementService::class)->recordCanonicalEvent($this->command(), new StockEventCapability))));
        $this->denied(fn () => app(InventoryMovementService::class)->recordCanonicalEvent($this->command(), new StockEventCapability));
        $this->assertSame($before, $this->counts());
    }

    public function test_prepared_capability_is_exact_one_use_and_expires_with_its_action_transaction(): void
    {
        $real = new InventoryMovementService;
        $captured = null;
        $command = null;
        $this->mock(InventoryMovementService::class, function ($mock) use ($real, &$captured, &$command): void {
            $mock->shouldReceive('recordCanonicalEvent')->once()->andReturnUsing(function ($actual, $capability) use ($real, &$captured, &$command) {
                $captured = $capability;
                $command = $actual;
                $this->denied(fn () => $real->recordCanonicalEvent($actual, new StockEventCapability));
                $this->denied(fn () => $real->recordCanonicalEvent($this->command(), $capability));
                $movements = $real->recordCanonicalEvent($actual, $capability);
                $this->denied(fn () => $real->recordCanonicalEvent($actual, $capability));

                return $movements;
            });
        });
        app(PostOpeningStockAction::class)->execute($this->company, $this->product, $this->warehouse,
            Quantity::of('2'), '1', $this->owner, 'authority-positive', movementDate: '2026-10-01');
        $before = $this->counts();
        $this->denied(fn () => DB::transaction(fn () => $real->recordCanonicalEvent($command, $captured)));
        $this->assertSame($before, $this->counts());
        $this->assertSame([1, 1, 1, 2], $before);
    }

    public function test_stock_only_successful_mock_posting_cannot_commit_without_actual_gl(): void
    {
        $before = $this->counts();
        $this->mock(AccountingPostingService::class, function ($mock): void {
            $mock->shouldReceive('post')->once()->andReturn(new PostingBatch(['id' => 987654]));
        });
        $this->denied(fn () => app(PostOpeningStockAction::class)->execute($this->company, $this->product,
            $this->warehouse, Quantity::of('2'), '1', $this->owner, 'missing-gl', movementDate: '2026-10-01'));
        $this->assertSame($before, $this->counts());
    }

    public function test_direct_gl_only_inventory_reversal_is_refused_even_when_caller_disguises_source(): void
    {
        $result = app(PostOpeningStockAction::class)->execute($this->company, $this->product, $this->warehouse,
            Quantity::of('2'), '1', $this->owner, 'reversal-protected', movementDate: '2026-10-01');
        $before = $this->counts();
        $batch = $result['batch'];
        $batch->source_type = 'manual';
        $this->denied(fn () => app(AccountingPostingService::class)->reverse($batch, $this->owner));
        $this->assertSame($before, $this->counts());
        $this->assertSame(PostingBatch::STATUS_POSTED, $batch->fresh()->status);
    }

    public function test_direct_gl_only_stock_source_posting_is_refused_inside_an_arbitrary_transaction(): void
    {
        $before = $this->counts();
        $accounts = LedgerAccount::where('company_id', $this->company->id)->get()->keyBy('system_key');
        foreach (['opening_stock', 'stock_adjustment', 'expiry_disposal', 'sales_invoice', 'sales_return', 'purchase', 'purchase_return'] as $source) {
            $command = new PostingCommand(company: $this->company, postingDate: Carbon::parse('2026-10-01'),
                sourceType: $source, sourceId: 999, transactionCurrencyCode: 'ILS', baseCurrencyCode: 'ILS',
                exchangeRate: ExchangeRate::one(), idempotencyKey: 'direct-'.$source, postedBy: $this->owner,
                lines: [PostingLineCommand::debit(1, $accounts['inventory']->id, '2'),
                    PostingLineCommand::credit(2, $accounts['opening_balance_equity']->id, '2')]);
            $this->denied(fn () => DB::transaction(fn () => app(AccountingPostingService::class)->post($command)));
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_rolling_back_and_replacing_the_scope_transaction_invalidates_its_capability(): void
    {
        $before = $this->counts();
        $this->mock(InventoryMovementService::class, function ($mock): void {
            $mock->shouldReceive('recordCanonicalEvent')->once()->andReturnUsing(function ($command, $capability) {
                DB::rollBack();
                DB::beginTransaction();

                return (new InventoryMovementService)->recordCanonicalEvent($command, $capability);
            });
        });
        $this->denied(fn () => app(PostOpeningStockAction::class)->execute($this->company, $this->product, $this->warehouse,
            Quantity::of('2'), '1', $this->owner, 'transaction-replacement', movementDate: '2026-10-01'));
        $this->assertSame($before, $this->counts());
    }
}
