<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Queries;

use App\Models\Purchase;
use App\Models\PurchaseLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final readonly class PurchasePriceHistoryItem
{
    public function __construct(
        public int $purchase_id,
        public string $purchase_public_id,
        public string $purchase_number,
        public string $purchase_date,
        public int $vendor_id,
        public ?string $vendor_public_id,
        public string $vendor_name,
        public ?string $vendor_code,
        public int $product_id,
        public ?string $product_public_id,
        public ?string $product_sku,
        public string $product_name,
        public int $product_unit_id,
        public string $unit_name,
        public string $unit_conversion_ratio,
        public string $quantity,
        public string $quantity_base,
        public string $unit_cost,
        public string $currency_code,
        public string $base_currency_code,
        public string $exchange_rate,
        public ?string $discount_type,
        public string $discount_value,
        public string $line_discount,
        public string $line_subtotal,
        public string $line_tax,
        public string $line_total,
        public string $line_subtotal_base,
        public string $line_discount_base,
        public string $line_tax_base,
        public string $line_total_base,
        public string $net_commercial_total_base,
        public ?string $net_commercial_price_per_base_unit,
        public bool $tax_inclusive,
        public ?string $tax_rate_snapshot,
    ) {}

    /**
     * Build an immutable history item from an authoritative posted purchase line.
     */
    public static function fromLine(PurchaseLine $line, ?string $locale = null): self
    {
        $locale ??= app()->getLocale();
        $purchase = $line->purchase;
        if ($purchase === null) {
            $purchase = Purchase::where('company_id', $line->company_id)->findOrFail($line->purchase_id);
        }

        $vendorSnapshot = $purchase->vendor_snapshot;
        if (! is_array($vendorSnapshot)) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }
        $vendor = $purchase->vendor;
        $nameKeys = $locale === 'en'
            ? ['name_en', 'name_ar', 'business_name_en', 'business_name_ar']
            : ['name_ar', 'name_en', 'business_name_ar', 'business_name_en'];
        $vendorName = self::historicalName(array_map(fn ($key) => $vendorSnapshot[$key] ?? null, $nameKeys));

        $vendorCode = isset($vendorSnapshot['code']) && (string) $vendorSnapshot['code'] !== ''
            ? (string) $vendorSnapshot['code']
            : null;

        $product = $line->product;
        $productName = self::historicalName($locale === 'en'
            ? [$line->product_name_en, $line->product_name_ar]
            : [$line->product_name_ar, $line->product_name_en]);
        $productSku = $line->product_sku !== null ? (string) $line->product_sku : null;
        $unitName = self::historicalName($locale === 'en'
            ? [$line->unit_name_en, $line->unit_name_ar]
            : [$line->unit_name_ar, $line->unit_name_en]);

        // Derive historical tax-exclusive net commercial amount in base currency: (line_total_base - line_tax_base)
        $lineTotalBase = BigDecimal::of((string) $line->line_total_base);
        $lineTaxBase = BigDecimal::of((string) $line->line_tax_base);
        $netCommercialBase = $lineTotalBase->minus($lineTaxBase);

        $qtyBase = BigDecimal::of((string) $line->quantity_base);
        $netPricePerBaseUnit = null;
        if ($qtyBase->isPositive()) {
            $netPricePerBaseUnit = (string) $netCommercialBase->dividedBy($qtyBase, 6, RoundingMode::HALF_UP);
        }

        return new self(
            purchase_id: (int) $purchase->id,
            purchase_public_id: (string) $purchase->public_id,
            purchase_number: (string) ($purchase->purchase_number ?? ''),
            purchase_date: $purchase->purchase_date->format('Y-m-d'),
            vendor_id: (int) $purchase->vendor_id,
            vendor_public_id: $vendor?->public_id,
            vendor_name: $vendorName,
            vendor_code: $vendorCode,
            product_id: (int) $line->product_id,
            product_public_id: $product !== null && ! $product->trashed() ? $product->public_id : null,
            product_sku: $productSku,
            product_name: $productName,
            product_unit_id: (int) $line->product_unit_id,
            unit_name: $unitName,
            unit_conversion_ratio: (string) $line->unit_conversion_ratio,
            quantity: (string) $line->quantity,
            quantity_base: (string) $line->quantity_base,
            unit_cost: (string) $line->unit_cost,
            currency_code: (string) $purchase->currency_code,
            base_currency_code: (string) $purchase->base_currency_code,
            exchange_rate: (string) $purchase->exchange_rate,
            discount_type: $line->discount_type,
            discount_value: (string) $line->discount_value,
            line_discount: (string) $line->line_discount,
            line_subtotal: (string) $line->line_subtotal,
            line_tax: (string) $line->line_tax,
            line_total: (string) $line->line_total,
            line_subtotal_base: (string) $line->line_subtotal_base,
            line_discount_base: (string) $line->line_discount_base,
            line_tax_base: (string) $line->line_tax_base,
            line_total_base: (string) $line->line_total_base,
            net_commercial_total_base: (string) $netCommercialBase->toScale(6, RoundingMode::HALF_UP),
            net_commercial_price_per_base_unit: $netPricePerBaseUnit,
            tax_inclusive: (bool) $line->tax_inclusive,
            tax_rate_snapshot: $line->tax_rate_snapshot !== null ? (string) $line->tax_rate_snapshot : null,
        );
    }

    /** @param list<mixed> $names */
    private static function historicalName(array $names): string
    {
        foreach ($names as $name) {
            if ($name === null) {
                continue;
            }
            if (! is_string($name)) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }
            if (trim($name) !== '') {
                return $name;
            }
        }

        throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'purchase_id' => $this->purchase_id,
            'purchase_public_id' => $this->purchase_public_id,
            'purchase_number' => $this->purchase_number,
            'purchase_date' => $this->purchase_date,
            'vendor_id' => $this->vendor_id,
            'vendor_public_id' => $this->vendor_public_id,
            'vendor_name' => $this->vendor_name,
            'vendor_code' => $this->vendor_code,
            'product_id' => $this->product_id,
            'product_public_id' => $this->product_public_id,
            'product_sku' => $this->product_sku,
            'product_name' => $this->product_name,
            'product_unit_id' => $this->product_unit_id,
            'unit_name' => $this->unit_name,
            'unit_conversion_ratio' => $this->unit_conversion_ratio,
            'quantity' => $this->quantity,
            'quantity_base' => $this->quantity_base,
            'unit_cost' => $this->unit_cost,
            'currency_code' => $this->currency_code,
            'base_currency_code' => $this->base_currency_code,
            'exchange_rate' => $this->exchange_rate,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'line_discount' => $this->line_discount,
            'line_subtotal' => $this->line_subtotal,
            'line_tax' => $this->line_tax,
            'line_total' => $this->line_total,
            'line_subtotal_base' => $this->line_subtotal_base,
            'line_discount_base' => $this->line_discount_base,
            'line_tax_base' => $this->line_tax_base,
            'line_total_base' => $this->line_total_base,
            'net_commercial_total_base' => $this->net_commercial_total_base,
            'net_commercial_price_per_base_unit' => $this->net_commercial_price_per_base_unit,
            'tax_inclusive' => $this->tax_inclusive,
            'tax_rate_snapshot' => $this->tax_rate_snapshot,
        ];
    }
}
