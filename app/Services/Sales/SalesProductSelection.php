<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class SalesProductSelection
{
    /** @return array{product_id: int, product_unit_id: int, item_description: string, unit_price: string, available_quantity: ?string} */
    public function select(int $productId, ?int $unitId, string $exchangeRate, ?int $warehouseId = null): array
    {
        $company = app(CompanyContext::class)->company();

        return DB::transaction(function () use ($company, $productId, $unitId, $exchangeRate, $warehouseId): array {
            $product = Product::where('company_id', $company->id)->findOrFail($productId);
            $rules = app(SalesDocumentRules::class);
            $unit = $rules->selectedUnit($product, $unitId, Quantity::of('1'));
            $rate = BigDecimal::of($exchangeRate);
            if (! $rate->isPositive()) {
                throw new \InvalidArgumentException('A positive exchange rate is required.');
            }
            $price = $rules->suggestedPrice($product, $unit, $rate) ?? BigDecimal::zero()->toScale(2);
            $available = null;
            if ($product->track_stock) {
                $query = InventoryBalance::where('company_id', $company->id)->where('product_id', $productId);
                if ($warehouseId !== null) {
                    $query->where('warehouse_id', $warehouseId);
                }
                $quantity = BigDecimal::of((string) $query->sum('quantity_base'))->dividedBy($unit->conversion_to_base, 6, RoundingMode::FLOOR);
                $available = SalesMoneyFormatter::formatQuantity((string) $quantity);
            }

            return ['product_id' => $productId, 'product_unit_id' => (int) $unit->id, 'item_description' => $product->displayName(), 'unit_price' => (string) $price, 'available_quantity' => $available];
        });
    }
}
