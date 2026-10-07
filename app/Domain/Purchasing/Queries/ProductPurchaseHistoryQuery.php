<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Queries;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use Illuminate\Support\Collection;

class ProductPurchaseHistoryQuery
{
    /**
     * Retrieve recent authoritative posted purchase price lines for a product across vendors.
     *
     * @return Collection<int, PurchasePriceHistoryItem>
     */
    public function execute(Product|int $product, int $companyId, ?int $vendorId = null, int $limit = 20): Collection
    {
        app(PurchasingHistoryGuard::class)->authorize($companyId);
        $productId = app(PurchasingHistoryGuard::class)->validateProduct($product, $companyId);
        if ($vendorId !== null) {
            app(PurchasingHistoryGuard::class)->validateVendor($vendorId, $companyId);
        }

        $boundedLimit = max(1, min(100, $limit));

        $query = PurchaseLine::query()
            ->select('purchase_lines.*')
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->where('purchase_lines.company_id', $companyId)
            ->where('purchase_lines.product_id', $productId)
            ->where('purchases.company_id', $companyId)
            ->where('purchases.status', Purchase::STATUS_POSTED)
            ->whereNotNull('purchases.purchase_number')
            ->whereNotNull('purchases.posted_at')
            ->whereNotNull('purchases.posting_batch_id');

        if ($vendorId !== null) {
            $query->where('purchases.vendor_id', $vendorId);
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
     * Retrieve the most recent posted purchase price line for a product.
     */
    public function latest(Product|int $product, int $companyId, ?int $vendorId = null): ?PurchasePriceHistoryItem
    {
        return $this->execute($product, $companyId, $vendorId, limit: 1)->first();
    }
}
