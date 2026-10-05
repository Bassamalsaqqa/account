<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Domain\Inventory\ValueObjects\Quantity;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseLineLot;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use App\Models\PurchaseReturnLine;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Audit\AuditService;
use App\Services\Purchasing\PurchaseDocumentRules;
use App\Services\Purchasing\PurchaseIdentitySnapshot;
use App\Services\Purchasing\PurchasePostingCommandBuilder;
use App\Services\Purchasing\PurchaseReturnAmounts;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreatePurchaseReturnDraftAction
{
    /**
     * @param  array{
     *     purchase_id: int,
     *     return_date?: ?string,
     *     reason?: ?string,
     *     notes?: ?string,
     *     lines: list<array{
     *         purchase_line_id: int,
     *         quantity: string|numeric,
     *         allocations?: list<array{
     *             original_stock_movement_id: int,
     *             purchase_line_lot_id?: ?int,
     *             inventory_lot_id?: ?int,
     *             quantity: string|numeric,
     *         }>
     *     }>
     * }  $data
     */
    public function execute(Company $company, User $actor, array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($company, $actor, $data): PurchaseReturn {
            Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            $guard = app(SalesActorGuard::class);
            $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.return.manage');
            $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.cost.view');

            $context = app(CompanyContext::class);
            if (! $context->hasCompany() || (int) $context->companyId() !== (int) $company->id) {
                throw new NoActiveCompanyException("Active company context does not match company [{$company->id}].");
            }

            if (! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
                throw new AuthorizationException('Actor must be authenticated and match user.');
            }

            /** @var Purchase $purchase */
            $purchase = Purchase::where('company_id', $company->id)
                ->where('id', $data['purchase_id'])
                ->lockForUpdate()
                ->firstOrFail();

            app(PurchasePostingCommandBuilder::class)->validatePosted($purchase);

            /** @var Warehouse $warehouse */
            $warehouse = Warehouse::where('company_id', $company->id)
                ->where('id', $purchase->warehouse_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $warehouse->active) {
                throw new InvalidArgumentException('Original warehouse is inactive.');
            }

            /** @var Vendor $vendor */
            $vendor = Vendor::withTrashed()
                ->where('company_id', $company->id)
                ->where('id', $purchase->vendor_id)
                ->lockForUpdate()
                ->firstOrFail();

            $rules = app(PurchaseDocumentRules::class);
            $returnDateStr = $data['return_date'] ?? now($company->timezone)->format('Y-m-d');
            $rules->date($returnDateStr);
            $returnDate = Carbon::parse($returnDateStr);

            if ($returnDate->lt($purchase->purchase_date)) {
                throw new InvalidArgumentException('Return date cannot be earlier than original purchase date.');
            }

            if (empty($data['lines'])) {
                throw new InvalidArgumentException('Purchase return must contain at least one line.');
            }

            $identity = app(PurchaseIdentitySnapshot::class);

            /** @var PurchaseReturn $return */
            $return = PurchaseReturn::create([
                'company_id' => $company->id,
                'purchase_id' => $purchase->id,
                'vendor_id' => $purchase->vendor_id,
                'warehouse_id' => $purchase->warehouse_id,
                'currency_code' => $purchase->currency_code,
                'base_currency_code' => $purchase->base_currency_code,
                'exchange_rate' => $purchase->exchange_rate,
                'return_date' => $returnDateStr,
                'status' => PurchaseReturn::STATUS_DRAFT,
                'subtotal_currency' => '0',
                'discount_total_currency' => '0',
                'tax_total_currency' => '0',
                'grand_total_currency' => '0',
                'subtotal_base' => '0',
                'discount_total_base' => '0',
                'tax_total_base' => '0',
                'grand_total_base' => '0',
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'document_locale' => $purchase->document_locale,
                'vendor_snapshot' => $identity->vendor($vendor),
                'company_snapshot' => $identity->company($company),
                'created_by' => $actor->id,
            ]);

            $seen = [];
            foreach ($data['lines'] as $idx => $lineInput) {
                $pLineId = (int) $lineInput['purchase_line_id'];
                if (isset($seen[$pLineId])) {
                    throw new InvalidArgumentException('Combine repeated original purchase-line quantities into one return line.');
                }
                $seen[$pLineId] = true;

                /** @var PurchaseLine $pLine */
                $pLine = PurchaseLine::where('company_id', $company->id)
                    ->where('purchase_id', $purchase->id)
                    ->where('id', $pLineId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $qty = Quantity::of($lineInput['quantity']);
                if (! $qty->isPositive()) {
                    throw new InvalidArgumentException('Return line quantity must be strictly positive.');
                }

                $ratio = BigDecimal::of((string) $pLine->unit_conversion_ratio);
                $qtyBase = $qty->toBigDecimal()->multipliedBy($ratio)->toScale(6);

                /** @var PurchaseReturnLine $retLine */
                $retLine = PurchaseReturnLine::create([
                    'company_id' => $company->id,
                    'purchase_return_id' => $return->id,
                    'purchase_line_id' => $pLine->id,
                    'line_number' => $idx + 1,
                    'product_id' => $pLine->product_id,
                    'product_unit_id' => $pLine->product_unit_id,
                    'item_description' => $pLine->item_description,
                    'quantity' => (string) $qty->toScale(),
                    'quantity_base' => (string) $qtyBase,
                    'unit_cost' => (string) $pLine->unit_cost,
                    'discount_type' => $pLine->discount_type,
                    'discount_value' => (string) $pLine->discount_value,
                    'line_discount' => '0',
                    'tax_rate_snapshot' => $pLine->tax_rate_snapshot,
                    'tax_inclusive' => (bool) $pLine->tax_inclusive,
                    'purchase_tax_account_id' => $pLine->purchase_tax_account_id,
                    'line_subtotal' => '0',
                    'line_tax' => '0',
                    'line_total' => '0',
                    'line_subtotal_base' => '0',
                    'line_discount_base' => '0',
                    'line_tax_base' => '0',
                    'line_total_base' => '0',
                    'unit_conversion_ratio' => (string) $pLine->unit_conversion_ratio,
                    'unit_name_ar' => $pLine->unit_name_ar,
                    'unit_name_en' => $pLine->unit_name_en,
                    'product_sku' => $pLine->product_sku,
                    'product_name_ar' => $pLine->product_name_ar,
                    'product_name_en' => $pLine->product_name_en,
                ]);

                /** @var Product $product */
                $product = Product::where('company_id', $company->id)->findOrFail($pLine->product_id);

                if ($product->track_expiry) {
                    if (empty($lineInput['allocations'])) {
                        throw new InvalidArgumentException("Lot allocations required for expiry-tracked product [{$product->id}].");
                    }

                    $allocSum = BigDecimal::zero();
                    foreach ($lineInput['allocations'] as $allocInput) {
                        $allocQty = Quantity::of($allocInput['quantity']);
                        if (! $allocQty->isPositive()) {
                            throw new InvalidArgumentException('Allocation quantity must be strictly positive.');
                        }

                        $lotLine = PurchaseLineLot::where('company_id', $company->id)
                            ->where('purchase_line_id', $pLine->id)
                            ->where('id', $allocInput['purchase_line_lot_id'] ?? 0)
                            ->first();

                        if ($lotLine === null) {
                            throw new InvalidArgumentException('Lot allocation does not belong to the original purchase line.');
                        }

                        if ((int) $lotLine->stock_movement_id !== (int) $allocInput['original_stock_movement_id']) {
                            throw new InvalidArgumentException('Original stock movement ID does not match lot receipt movement.');
                        }

                        $allocQtyBase = $allocQty->toBigDecimal()->multipliedBy($ratio)->toScale(6);

                        PurchaseReturnAllocation::create([
                            'company_id' => $company->id,
                            'purchase_return_id' => $return->id,
                            'purchase_return_line_id' => $retLine->id,
                            'original_stock_movement_id' => $lotLine->stock_movement_id,
                            'purchase_line_lot_id' => $lotLine->id,
                            'inventory_lot_id' => $lotLine->created_inventory_lot_id,
                            'quantity' => (string) $allocQty->toScale(),
                            'quantity_base' => (string) $allocQtyBase,
                        ]);

                        $allocSum = $allocSum->plus($allocQty->toBigDecimal());
                    }

                    if (! $allocSum->isEqualTo($qty->toBigDecimal())) {
                        throw new InvalidArgumentException('The sum of lot allocation quantities must exactly equal the line return quantity.');
                    }
                } else {
                    if ($pLine->stock_movement_id === null) {
                        throw new InvalidArgumentException('Original purchase line has no receipt stock movement.');
                    }

                    PurchaseReturnAllocation::create([
                        'company_id' => $company->id,
                        'purchase_return_id' => $return->id,
                        'purchase_return_line_id' => $retLine->id,
                        'original_stock_movement_id' => $pLine->stock_movement_id,
                        'purchase_line_lot_id' => null,
                        'inventory_lot_id' => null,
                        'quantity' => (string) $qty->toScale(),
                        'quantity_base' => (string) $qtyBase,
                    ]);
                }
            }

            app(PurchaseReturnAmounts::class)->refresh($return);

            app(AuditService::class)->log(
                (int) $company->id,
                'purchase_return.draft_created',
                'Purchase return draft created',
                $actor->id,
                $return,
                null,
                ['purchase_id' => $purchase->id, 'vendor_id' => $purchase->vendor_id]
            );

            return $return->fresh(['lines.allocations']);
        });
    }
}
