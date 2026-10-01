<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Models\Company;
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
use Illuminate\Support\Facades\DB;

class DisposeExpiredStockAction
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
        int $lotId,
        Quantity $quantity,
        string $reason,
        User $user,
        string $idempotencyKey,
        ?int $unitId = null,
        ?string $movementDate = null,
    ): array {
        if (! $product->track_expiry) {
            throw new InvalidInventoryMovementException("Cannot execute expiry disposal on non-expiry tracked product [{$product->id}].");
        }

        if (trim($reason) === '') {
            throw new InvalidInventoryMovementException('Reason is mandatory for expired stock disposal.');
        }

        // Require matching company context — no auto-activation
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || $context->companyId() !== $company->id) {
            throw new InvalidInventoryMovementException(
                "Active company context does not match target company [{$company->id}]. Activate the correct company context before calling this action."
            );
        }

        return DB::transaction(function () use (
            $company,
            $product,
            $warehouse,
            $lotId,
            $quantity,
            $reason,
            $user,
            $idempotencyKey,
            $unitId,
            $movementDate,
        ): array {
            $date = $movementDate ?? now()->toDateString();

            // 1. Record Inventory Movement — lot expiry validation happens inside InventoryMovementService
            $movementCommand = new StockMovementCommand(
                companyId: $company->id,
                movementType: StockMovement::TYPE_EXPIRY_DISPOSAL,
                movementDate: $date,
                lines: [
                    new StockMovementLineCommand(
                        productId: $product->id,
                        warehouseId: $warehouse->id,
                        quantity: $quantity,
                        unitId: $unitId,
                        lotId: $lotId,
                    ),
                ],
                sourceType: 'expiry_disposal',
                sourceId: $company->id,
                idempotencyKey: "{$idempotencyKey}:inv",
                createdBy: $user->id,
                reason: $reason,
            );

            $movements = $this->inventoryService->record($movementCommand);
            /** @var StockMovement $movement */
            $movement = $movements[0];

            // 2. Financial Recognition: Dr Expiry Loss / Cr Inventory
            $batch = null;
            $movementValue = BigDecimal::of((string) $movement->value_delta_base)->abs();

            if ($movementValue->isPositive()) {
                /** @var LedgerAccount $inventoryAccount */
                $inventoryAccount = LedgerAccount::where('company_id', $company->id)
                    ->where('system_key', 'inventory')
                    ->firstOrFail();

                /** @var LedgerAccount $expiryLossAccount */
                $expiryLossAccount = LedgerAccount::where('company_id', $company->id)
                    ->where('system_key', 'expiry_loss')
                    ->firstOrFail();

                $currency = $company->base_currency;
                $valStr = (string) $movementValue->toScale(6);

                $postingCommand = new PostingCommand(
                    company: $company,
                    postingDate: Carbon::parse($date),
                    sourceType: 'expiry_disposal',
                    sourceId: $movement->id,
                    transactionCurrencyCode: $currency,
                    baseCurrencyCode: $currency,
                    exchangeRate: ExchangeRate::one(),
                    idempotencyKey: "{$idempotencyKey}:gl",
                    postedBy: $user,
                    description: "Expired stock disposal for {$product->name_ar} (Lot #{$lotId}): {$reason}",
                    lines: [
                        PostingLineCommand::debit(
                            lineNumber: 1,
                            ledgerAccountId: $expiryLossAccount->id,
                            amount: $valStr,
                            transactionCurrencyCode: $currency,
                            transactionAmount: $valStr,
                            exchangeRate: ExchangeRate::one(),
                            description: "Loss on expired goods: {$product->name_ar}",
                        ),
                        PostingLineCommand::credit(
                            lineNumber: 2,
                            ledgerAccountId: $inventoryAccount->id,
                            amount: $valStr,
                            transactionCurrencyCode: $currency,
                            transactionAmount: $valStr,
                            exchangeRate: ExchangeRate::one(),
                            description: "Inventory reduction (expired): {$product->name_ar}",
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
