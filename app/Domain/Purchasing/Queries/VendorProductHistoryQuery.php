<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Queries;

use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Vendor;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

class VendorProductHistoryQuery
{
    public const SUMMARY_LINE_LIMIT = 100;

    /**
     * Retrieve recent authoritative posted purchase price lines for a vendor across products.
     *
     * @return Collection<int, PurchasePriceHistoryItem>
     */
    public function execute(Vendor|int $vendor, int $companyId, ?int $productId = null, int $limit = 20): Collection
    {
        app(PurchasingHistoryGuard::class)->authorize($companyId);
        $vendorId = app(PurchasingHistoryGuard::class)->validateVendor($vendor, $companyId);
        if ($productId !== null) {
            app(PurchasingHistoryGuard::class)->validateProduct($productId, $companyId);
        }

        $boundedLimit = max(1, min(100, $limit));

        $query = PurchaseLine::query()
            ->select('purchase_lines.*')
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->where('purchase_lines.company_id', $companyId)
            ->where('purchases.company_id', $companyId)
            ->where('purchases.vendor_id', $vendorId)
            ->where('purchases.status', Purchase::STATUS_POSTED)
            ->whereNotNull('purchases.purchase_number')
            ->whereNotNull('purchases.posted_at')
            ->whereNotNull('purchases.posting_batch_id');

        if ($productId !== null) {
            $query->where('purchase_lines.product_id', $productId);
        }

        $lines = $query
            ->with([
                'purchase.vendor' => fn ($q) => $q->withTrashed(),
                'product' => fn ($q) => $q->withTrashed(),
            ])
            ->orderByDesc('purchases.purchase_date')
            ->orderByDesc('purchases.id')
            ->orderBy('purchase_lines.line_number')
            ->limit($boundedLimit)
            ->get();

        app(PurchaseHistoryProvenance::class)->assertLines($lines, $companyId);

        return $lines->map(fn (PurchaseLine $line) => PurchasePriceHistoryItem::fromLine($line));
    }

    /**
     * Retrieve distinct products supplied by this vendor within the latest 100 posted lines, with a volume summary for that window.
     *
     * @return Collection<int, array{
     *     product_id: int,
     *     product_public_id: string|null,
     *     product_name: string,
     *     product_sku: string|null,
     *     latest_purchase_date: string,
     *     latest_purchase_number: string,
     *     latest_purchase_public_id: string,
     *     latest_unit_cost: string,
     *     latest_currency_code: string,
     *     latest_unit_name: string,
     *     latest_net_commercial_price_per_base_unit: string|null,
     *     base_currency_code: string,
     *     purchases_count: int,
     *     total_quantity_base: string
     * }>
     */
    public function productsSupplied(Vendor|int $vendor, int $companyId, int $limit = 50): Collection
    {
        // Counts and quantities describe this explicit recent-line window only.
        // Every contributing row is validated; no unvalidated all-time aggregate is presented.
        $recent = $this->execute($vendor, $companyId, limit: self::SUMMARY_LINE_LIMIT);
        $summaries = $recent->groupBy('product_id')->take(max(1, min(100, $limit)));

        /** @var list<array{
         *     product_id: int,
         *     product_public_id: string|null,
         *     product_name: string,
         *     product_sku: string|null,
         *     latest_purchase_date: string,
         *     latest_purchase_number: string,
         *     latest_purchase_public_id: string,
         *     latest_unit_cost: string,
         *     latest_currency_code: string,
         *     latest_unit_name: string,
         *     latest_net_commercial_price_per_base_unit: string|null,
         *     base_currency_code: string,
         *     purchases_count: int,
         *     total_quantity_base: string
         * }> $results */
        $results = [];

        foreach ($summaries as $items) {
            $latestItem = $items->first();
            $totalQuantity = BigDecimal::zero();
            foreach ($items as $item) {
                $totalQuantity = $totalQuantity->plus($item->quantity_base);
            }

            $results[] = [
                'product_id' => $latestItem->product_id,
                'product_public_id' => $latestItem->product_public_id,
                'product_name' => $latestItem->product_name,
                'product_sku' => $latestItem->product_sku,
                'latest_purchase_date' => $latestItem->purchase_date,
                'latest_purchase_number' => $latestItem->purchase_number,
                'latest_purchase_public_id' => $latestItem->purchase_public_id,
                'latest_unit_cost' => $latestItem->unit_cost,
                'latest_currency_code' => $latestItem->currency_code,
                'latest_unit_name' => $latestItem->unit_name,
                'latest_net_commercial_price_per_base_unit' => $latestItem->net_commercial_price_per_base_unit,
                'base_currency_code' => $latestItem->base_currency_code,
                'purchases_count' => $items->pluck('purchase_id')->unique()->count(),
                'total_quantity_base' => (string) $totalQuantity->toScale(6),
            ];
        }

        return collect($results);
    }
}
