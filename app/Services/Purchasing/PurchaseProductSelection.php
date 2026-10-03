<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Models\Product;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class PurchaseProductSelection
{
    /** @return array{product_id: int, product_unit_id: int, item_description: string, unit_cost: string, track_expiry: bool} */
    public function select(int $productId, ?int $unitId, string $currency, string $exchangeRate): array
    {
        return DB::transaction(function () use ($productId, $unitId, $currency, $exchangeRate): array {
            $context = app(CompanyContext::class);
            $company = app(SalesActorGuard::class)->lockAndAuthorize($context->companyId(), auth()->user(), 'purchasing.cost.view');
            $rules = app(PurchaseDocumentRules::class);
            $rules->header($company, $currency, $exchangeRate, now($company->timezone)->format('Y-m-d'), null, $company->default_locale);
            $product = Product::where('company_id', $company->id)->lockForUpdate()->findOrFail($productId);
            $unit = $rules->selectedUnit($product, $unitId, '1');
            $base = $unit->default_purchase_price_base !== null
                ? BigDecimal::of($unit->default_purchase_price_base)
                : BigDecimal::of($product->default_purchase_cost_base ?? '0')->multipliedBy($unit->conversion_to_base);
            $cost = $base->dividedBy(ExchangeRate::from($exchangeRate)->getValue(), $currency === 'JOD' ? 3 : 2, RoundingMode::HALF_UP);

            return ['product_id' => (int) $product->id, 'product_unit_id' => (int) $unit->id,
                'item_description' => $product->displayName(), 'unit_cost' => (string) $cost, 'track_expiry' => (bool) $product->track_expiry];
        });
    }
}
