<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Inventory\Services\UnitConversionService;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyLanguage;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class SalesDocumentRules
{
    public function quantity(mixed $value): Quantity
    {
        $quantity = Quantity::of($value);
        if (! $quantity->isPositive()) {
            throw new InvalidArgumentException('Sales quantities must be strictly positive exact decimals.');
        }

        return $quantity;
    }

    public function date(string $date): void
    {
        if (! preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts) || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('A canonical valid Y-m-d date is required.');
        }
    }

    public function header(Company $company, string $currency, BigDecimal $rate, string $date, string $locale): void
    {
        $this->date($date);
        if (! preg_match('/\A[A-Z]{3}\z/', $currency) || ! CompanyCurrency::where('company_id', $company->id)->where('currency_code', $currency)->where('enabled', true)->exists()
            || ! $rate->isPositive() || $rate->strippedOfTrailingZeros()->getScale() > 10
            || $rate->isGreaterThan('9999999999.9999999999')
            || ($currency === $company->base_currency_code && ! $rate->isEqualTo(1))) {
            throw new InvalidArgumentException('Enabled currency and a valid exact exchange rate are required; base currency rate must be one.');
        }
        if (! in_array($locale, ['ar', 'en'], true) || ! CompanyLanguage::where('company_id', $company->id)->where('locale', $locale)->where('enabled', true)->exists()) {
            throw new InvalidArgumentException('Document language must be enabled for this company.');
        }
    }

    public function selectedUnit(Product $product, ?int $selectedId, Quantity $quantity): ProductUnit
    {
        $this->quantity($quantity->toBigDecimal());
        if (! $product->active) {
            throw new InvalidArgumentException('Inactive products cannot be used in a new sales document.');
        }
        $defaults = ProductUnit::where('company_id', $product->company_id)->where('product_id', $product->id)->where('active', true)->where('is_default_sale', true)->lockForUpdate()->get();
        if ($defaults->count() !== 1) {
            throw new InvalidArgumentException('Exactly one active default sales unit is required.');
        }
        app(UnitConversionService::class)->validateProductBaseInvariants($defaults->first());
        $selected = $selectedId === null ? $defaults->first() : ProductUnit::where('company_id', $product->company_id)->where('product_id', $product->id)->whereKey($selectedId)->lockForUpdate()->firstOrFail();
        $baseQuantity = app(UnitConversionService::class)->toBase($quantity, $selected);
        $baseQuantity->validateUnitConstraints($product->baseUnit);

        return $selected;
    }

    public function price(Product $product, ProductUnit $unit, User $actor, BigDecimal $rate, string $currency, BigDecimal $requested): void
    {
        $suggestion = $unit->default_sale_price_base;
        if ($suggestion === null && $product->default_sale_price_base !== null) {
            $suggestion = (string) BigDecimal::of((string) $product->default_sale_price_base)->multipliedBy($unit->conversion_to_base);
        }
        if ($actor->hasPermissionTo('sales.invoice.change_price')) {
            return;
        }
        if ($suggestion === null || ! $requested->toScale($currency === 'JOD' ? 3 : 2, RoundingMode::HALF_UP)
            ->isEqualTo(BigDecimal::of((string) $suggestion)->dividedBy($rate, $currency === 'JOD' ? 3 : 2, RoundingMode::HALF_UP))) {
            throw new AuthorizationException('Manual price permission is required when there is no matching suggested price.');
        }
    }
}
