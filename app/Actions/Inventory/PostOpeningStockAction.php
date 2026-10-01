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

class PostOpeningStockAction
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
        Quantity $quantity,
        string $unitCostBase,
        User $user,
        string $idempotencyKey,
        ?int $unitId = null,
        ?string $lotNumber = null,
        ?string $expiryDate = null,
        ?string $movementDate = null,
        ?string $reason = null,
    ): array {
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

        // 3. Permission authorization: requires stock.adjust AND cost.view, no role bypass
        if (! $user->hasPermissionTo('inventory.stock.adjust') || ! $user->hasPermissionTo('inventory.cost.view')) {
            throw new AuthorizationException('Opening stock requires both inventory.stock.adjust and inventory.cost.view permissions.');
        }

        return DB::transaction(function () use (
            $company,
            $product,
            $warehouse,
            $quantity,
            $unitCostBase,
            $user,
            $idempotencyKey,
            $unitId,
            $lotNumber,
            $expiryDate,
            $movementDate,
            $reason,
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

            // 1. Record Inventory Movement
            $movementCommand = new StockMovementCommand(
                companyId: $lockedCompany->id,
                movementType: StockMovement::TYPE_OPENING_BALANCE,
                movementDate: $date,
                lines: [
                    new StockMovementLineCommand(
                        productId: $product->id,
                        warehouseId: $warehouse->id,
                        quantity: $quantity,
                        unitId: $unitId,
                        unitCostBase: $unitCostBase,
                        lotNumber: $lotNumber,
                        expiryDate: $expiryDate,
                    ),
                ],
                sourceType: 'opening_stock',
                sourceId: $lockedCompany->id,
                idempotencyKey: "{$idempotencyKey}:inv",
                createdBy: $user->id,
                reason: $reason ?? 'Opening Stock Balance',
            );

            $movements = $this->inventoryService->record($movementCommand);
            /** @var StockMovement $movement */
            $movement = $movements[0];

            // 2. Financial Recognition (if value > 0): Dr Inventory / Cr Opening Balance Equity
            $batch = null;
            $movementValue = BigDecimal::of((string) $movement->value_delta_base);

            if ($movementValue->isPositive()) {
                /** @var LedgerAccount $inventoryAccount */
                $inventoryAccount = LedgerAccount::where('company_id', $lockedCompany->id)
                    ->where('system_key', 'inventory')
                    ->firstOrFail();

                /** @var LedgerAccount $obeAccount */
                $obeAccount = LedgerAccount::where('company_id', $lockedCompany->id)
                    ->where('system_key', 'opening_balance_equity')
                    ->firstOrFail();

                $currency = $lockedCompany->base_currency;
                $valStr = (string) $movementValue->toScale(6);

                $postingCommand = new PostingCommand(
                    company: $lockedCompany,
                    postingDate: Carbon::parse($date),
                    sourceType: 'opening_stock',
                    sourceId: $movement->id,
                    transactionCurrencyCode: $currency,
                    baseCurrencyCode: $currency,
                    exchangeRate: ExchangeRate::one(),
                    idempotencyKey: "{$idempotencyKey}:gl",
                    postedBy: $user,
                    description: "Opening stock balance for {$product->name_ar} (Qty: {$movement->quantity_delta_base})",
                    lines: [
                        PostingLineCommand::debit(
                            lineNumber: 1,
                            ledgerAccountId: $inventoryAccount->id,
                            amount: $valStr,
                            transactionCurrencyCode: $currency,
                            transactionAmount: $valStr,
                            exchangeRate: ExchangeRate::one(),
                            description: "Inventory opening balance: {$product->name_ar}",
                        ),
                        PostingLineCommand::credit(
                            lineNumber: 2,
                            ledgerAccountId: $obeAccount->id,
                            amount: $valStr,
                            transactionCurrencyCode: $currency,
                            transactionAmount: $valStr,
                            exchangeRate: ExchangeRate::one(),
                            description: "Opening equity offset: {$product->name_ar}",
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
