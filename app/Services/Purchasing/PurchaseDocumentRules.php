<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Domain\Inventory\Services\UnitConversionService;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Models\Company;
use App\Models\CompanyInventorySettings;
use App\Models\CompanyPurchaseSetting;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Sales\SalesDocumentRules;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class PurchaseDocumentRules
{
    public function date(string $value): void
    {
        app(SalesDocumentRules::class)->date($value);
    }

    public function vendor(Company $company, int $id): Vendor
    {
        return Vendor::where('company_id', $company->id)->where('status', 'active')->findOrFail($id);
    }

    public function warehouse(Company $company, ?int $id): Warehouse
    {
        if ($id !== null) {
            return Warehouse::where('company_id', $company->id)->where('active', true)->findOrFail($id);
        }
        $settings = CompanyPurchaseSetting::where('company_id', $company->id)->first();
        $inventory = CompanyInventorySettings::where('company_id', $company->id)->first();
        foreach ([$settings?->default_receiving_warehouse_id, $inventory?->default_warehouse_id] as $default) {
            if ($default !== null) {
                $warehouse = Warehouse::where('company_id', $company->id)->where('active', true)->find($default);
                if ($warehouse !== null) {
                    return $warehouse;
                }
            }
        }
        throw new InvalidArgumentException(__('purchasing.receiving_warehouse_required'));
    }

    public function defaultDueDate(Company $company, string $date): ?string
    {
        $this->date($date);
        $days = CompanyPurchaseSetting::where('company_id', $company->id)->value('default_payment_terms_days');

        return $days === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $date)->addDays((int) $days)->format('Y-m-d');
    }

    public function defaultLocale(Company $company, Vendor $vendor): string
    {
        return $vendor->preferred_locale !== null && $company->isLanguageEnabled($vendor->preferred_locale)
            ? $vendor->preferred_locale : $company->default_locale;
    }

    public function defaultCurrency(Company $company, Vendor $vendor): string
    {
        return $vendor->default_currency_code !== null && $company->currencies()->where('currency_code', $vendor->default_currency_code)->where('enabled', true)->exists()
            ? $vendor->default_currency_code : $company->base_currency_code;
    }

    public function validateModelHeader(Company $company, Purchase $purchase): void
    {
        $this->vendor($company, (int) $purchase->vendor_id);
        $this->warehouse($company, (int) $purchase->warehouse_id);
        if ($purchase->base_currency_code !== $company->base_currency_code) {
            throw new InvalidArgumentException(__('purchasing.invalid_purchase_header'));
        }
        $date = (string) $purchase->getAttributes()['purchase_date'];
        $due = $purchase->getAttributes()['due_date'] ?? null;
        $this->header($company, $purchase->currency_code, $purchase->getAttributes()['exchange_rate'], $date, $due, $purchase->document_locale);
    }

    public function header(Company $company, string $currency, mixed $rate, string $date, ?string $due, string $locale): void
    {
        app(SalesDocumentRules::class)->header($company, $currency, ExchangeRate::from($rate)->getValue(), $date, $locale);
        if ($due !== null) {
            $this->date($due);
            if ($due < $date) {
                throw new InvalidArgumentException(__('purchasing.due_date_before_purchase'));
            }
        }
    }

    public function selectedUnit(Product $product, ?int $selectedId, mixed $value): ProductUnit
    {
        $quantity = Quantity::of($value);
        if (! $quantity->isPositive()) {
            throw new InvalidArgumentException(__('purchasing.positive_quantity_required'));
        }
        if (! $product->active || $product->product_type !== Product::TYPE_STOCK || ! $product->track_stock) {
            throw new InvalidArgumentException(__('purchasing.stock_products_only'));
        }
        $defaults = ProductUnit::where('company_id', $product->company_id)->where('product_id', $product->id)
            ->where('active', true)->where('is_default_purchase', true)->lockForUpdate()->get();
        if ($defaults->count() !== 1) {
            throw new InvalidArgumentException(__('purchasing.invalid_default_purchase_unit'));
        }
        $conversion = app(UnitConversionService::class);
        $conversion->validateProductBaseInvariants($defaults->first());
        $unit = $selectedId === null ? $defaults->first() : ProductUnit::where('company_id', $product->company_id)
            ->where('product_id', $product->id)->lockForUpdate()->findOrFail($selectedId);
        $base = $conversion->toBase($quantity, $unit);
        $base->validateUnitConstraints($product->baseUnit);
        // Do not silently round a draft's conversion intent.
        $exact = $quantity->toBigDecimal()->multipliedBy($unit->conversion_to_base);
        if (! $exact->isEqualTo($base->toBigDecimal())) {
            throw new InvalidArgumentException(__('purchasing.inexact_base_quantity'));
        }

        return $unit;
    }
}
