<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\Exceptions\InventorySecurityException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Posting\AccountingPostingService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class AdjustStockAction
{
    public function __construct(
        protected InventoryMovementService $inventoryService,
        protected AccountingPostingService $postingService,
    ) {}

    /**
     * @return array{movement: StockMovement, batch: ?PostingBatch}
     */
    public function execute(
        Company $company,
        Product $product,
        Warehouse $warehouse,
        string $type,
        Quantity $quantity,
        string $reason,
        User $user,
        string $idempotencyKey,
        ?string $unitCostBase = null,
        ?int $unitId = null,
        ?int $lotId = null,
        ?string $lotNumber = null,
        ?string $expiryDate = null,
        ?string $movementDate = null,
    ): array {
        if (! in_array($type, [
            StockMovement::TYPE_ADJUSTMENT_INCREASE,
            StockMovement::TYPE_ADJUSTMENT_DECREASE,
            StockMovement::TYPE_DAMAGE_OR_LOSS,
        ], true)) {
            throw new InvalidInventoryMovementException("Invalid adjustment type [{$type}].");
        }

        if (trim($reason) === '') {
            throw new InvalidInventoryMovementException('Reason is mandatory for stock adjustment operations.');
        }

        // 1. Require matching company context — no auto-activation
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || $context->companyId() !== $company->id) {
            throw new InvalidInventoryMovementException(
                "Active company context does not match target company [{$company->id}]. Activate the correct company context before calling this action."
            );
        }

        // 2. Authenticated actor validation
        $authUser = auth()->user();
        if ($authUser !== null && (int) $authUser->id !== (int) $user->id) {
            throw new InventorySecurityException("Authenticated user does not match action actor [{$user->id}].");
        }

        // 3. Permission authorization
        if (! $user->hasPermissionTo('inventory.stock.adjust')) {
            throw new AuthorizationException('User does not have permission to adjust inventory.');
        }

        $normalizedCost = ($unitCostBase !== null && trim($unitCostBase) !== '') ? trim($unitCostBase) : null;

        // Section 3: Explicit cost on adjustment increase requires cost.view
        if ($type === StockMovement::TYPE_ADJUSTMENT_INCREASE && $normalizedCost !== null) {
            if (! $user->hasPermissionTo('inventory.cost.view')) {
                throw new AuthorizationException('Specifying explicit unit cost on stock adjustment increase requires inventory.cost.view permission.');
            }
        }

        return DB::transaction(function () use (
            $company,
            $product,
            $warehouse,
            $type,
            $quantity,
            $reason,
            $user,
            $idempotencyKey,
            $normalizedCost,
            $unitId,
            $lotId,
            $lotNumber,
            $expiryDate,
            $movementDate,
        ): array {
            // Locked company check
            /** @var Company $lockedCompany */
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            if ($lockedCompany->status !== 'active') {
                throw new InvalidInventoryMovementException("Cannot execute stock movements on inactive company [{$lockedCompany->id}].");
            }

            // Locked active membership check
            $companyUser = CompanyUser::where('company_id', $lockedCompany->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (! $companyUser || $companyUser->status !== 'active') {
                throw new InvalidInventoryMovementException("Actor [{$user->id}] is not an active member of company [{$lockedCompany->id}].");
            }

            $date = $movementDate ?? now()->toDateString();

            // 1. Record Inventory Movement — pass unitCostBase directly through unchanged (no pre-engine lookup)
            $movementCommand = new StockMovementCommand(
                companyId: $lockedCompany->id,
                movementType: $type,
                movementDate: $date,
                lines: [
                    new StockMovementLineCommand(
                        productId: $product->id,
                        warehouseId: $warehouse->id,
                        quantity: $quantity,
                        unitId: $unitId,
                        unitCostBase: $normalizedCost,
                        lotId: $lotId,
                        lotNumber: $lotNumber,
                        expiryDate: $expiryDate,
                    ),
                ],
                sourceType: 'stock_adjustment',
                sourceId: $lockedCompany->id,
                idempotencyKey: "{$idempotencyKey}:inv",
                createdBy: $user->id,
                reason: $reason,
            );

            $movements = $this->inventoryService->record($movementCommand);
            /** @var StockMovement $movement */
            $movement = $movements[0];

            // 2. Financial Recognition — GL amount comes from actual immutable movement value
            $batch = null;
            $movementValue = BigDecimal::of((string) $movement->value_delta_base)->abs();

            if ($movementValue->isPositive()) {
                /** @var LedgerAccount $inventoryAccount */
                $inventoryAccount = LedgerAccount::where('company_id', $lockedCompany->id)
                    ->where('system_key', 'inventory')
                    ->firstOrFail();

                /** @var LedgerAccount $lossAccount */
                $lossAccount = LedgerAccount::where('company_id', $lockedCompany->id)
                    ->where('system_key', 'inventory_loss')
                    ->firstOrFail();

                $currency = $lockedCompany->base_currency;
                $valStr = (string) $movementValue->toScale(6);

                if ($type === StockMovement::TYPE_ADJUSTMENT_INCREASE) {
                    // Dr Inventory / Cr Inventory Loss
                    $debitAccountId = $inventoryAccount->id;
                    $creditAccountId = $lossAccount->id;
                    $descDebit = "Inventory increase: {$product->name_ar}";
                    $descCredit = "Inventory adjustment gain/recovery: {$product->name_ar}";
                } else {
                    // Dr Inventory Loss / Cr Inventory
                    $debitAccountId = $lossAccount->id;
                    $creditAccountId = $inventoryAccount->id;
                    $descDebit = "Inventory loss/shrinkage: {$product->name_ar}";
                    $descCredit = "Inventory reduction: {$product->name_ar}";
                }

                $postingCommand = new PostingCommand(
                    company: $lockedCompany,
                    postingDate: Carbon::parse($date),
                    sourceType: 'stock_adjustment',
                    sourceId: $movement->id,
                    transactionCurrencyCode: $currency,
                    baseCurrencyCode: $currency,
                    exchangeRate: ExchangeRate::one(),
                    idempotencyKey: "{$idempotencyKey}:gl",
                    postedBy: $user,
                    description: "Stock adjustment [{$type}] for {$product->name_ar}: {$reason}",
                    lines: [
                        PostingLineCommand::debit(
                            lineNumber: 1,
                            ledgerAccountId: $debitAccountId,
                            amount: $valStr,
                            transactionCurrencyCode: $currency,
                            transactionAmount: $valStr,
                            exchangeRate: ExchangeRate::one(),
                            description: $descDebit,
                        ),
                        PostingLineCommand::credit(
                            lineNumber: 2,
                            ledgerAccountId: $creditAccountId,
                            amount: $valStr,
                            transactionCurrencyCode: $currency,
                            transactionAmount: $valStr,
                            exchangeRate: ExchangeRate::one(),
                            description: $descCredit,
                        ),
                    ],
                );

                $batch = $this->postingService->post($postingCommand);
            }

            return [
                'movement' => $movement,
                'batch' => $batch,
            ];
        });
    }
}
