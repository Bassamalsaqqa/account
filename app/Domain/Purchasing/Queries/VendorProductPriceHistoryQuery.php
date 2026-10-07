<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Queries;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Vendor;
use Illuminate\Support\Collection;

class VendorProductPriceHistoryQuery
{
    /**
     * Retrieve recent authoritative posted purchase price lines for a specific vendor and product pair.
     *
     * @return Collection<int, PurchasePriceHistoryItem>
     */
    public function execute(Vendor|int $vendor, Product|int $product, int $companyId, int $limit = 10): Collection
    {
        app(PurchasingHistoryGuard::class)->authorize($companyId);
        $vendorId = app(PurchasingHistoryGuard::class)->validateVendor($vendor, $companyId);
        $productId = app(PurchasingHistoryGuard::class)->validateProduct($product, $companyId);

        $boundedLimit = max(1, min(100, $limit));

        $lines = PurchaseLine::query()
            ->select('purchase_lines.*')
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->where('purchase_lines.company_id', $companyId)
            ->where('purchase_lines.product_id', $productId)
            ->where('purchases.company_id', $companyId)
            ->where('purchases.vendor_id', $vendorId)
            ->where('purchases.status', Purchase::STATUS_POSTED)
            ->whereNotNull('purchases.purchase_number')
            ->whereNotNull('purchases.posted_at')
            ->whereNotNull('purchases.posting_batch_id')
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
     * Retrieve the most recent posted purchase price line for a vendor and product pair.
     */
    public function latest(Vendor|int $vendor, Product|int $product, int $companyId): ?PurchasePriceHistoryItem
    {
        return $this->execute($vendor, $product, $companyId, limit: 1)->first();
    }

    /**
     * Retrieve the most recent posted purchase price line for a vendor across multiple products in a single batch query.
     *
     * @param  list<int>  $productIds
     * @return array<int, PurchasePriceHistoryItem> Map of product_id => PurchasePriceHistoryItem
     */
    public function latestForProducts(Vendor|int $vendor, array $productIds, int $companyId): array
    {
        $guard = app(PurchasingHistoryGuard::class);
        $guard->authorize($companyId);
        $vendorId = $guard->validateVendor($vendor, $companyId);
        $cleanProductIds = $guard->validateProducts($productIds, $companyId);
        if ($cleanProductIds === []) {
            return [];
        }

        // Rank in SQL; hydrate at most one line per requested product, not its entire history.
        $ranked = PurchaseLine::query()
            ->select('purchase_lines.id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY purchase_lines.product_id ORDER BY purchases.purchase_date DESC, purchases.id DESC, purchase_lines.line_number ASC, purchase_lines.id ASC) AS history_rank')
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->where('purchase_lines.company_id', $companyId)
            ->whereIn('purchase_lines.product_id', $cleanProductIds)
            ->where('purchases.company_id', $companyId)
            ->where('purchases.vendor_id', $vendorId)
            ->where('purchases.status', Purchase::STATUS_POSTED)
            ->whereNotNull('purchases.purchase_number')
            ->whereNotNull('purchases.posted_at')
            ->whereNotNull('purchases.posting_batch_id')
            ->toBase();

        $lineIds = PurchaseLine::withoutGlobalScopes()
            ->fromSub($ranked, 'ranked_lines')
            ->where('history_rank', 1)
            ->toBase()
            ->pluck('id');

        $lines = PurchaseLine::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $lineIds)
            ->with([
                'purchase.vendor' => fn ($q) => $q->withTrashed(),
                'product' => fn ($q) => $q->withTrashed(),
            ])
            ->get();

        app(PurchaseHistoryProvenance::class)->assertLines($lines, $companyId);
        $result = [];
        foreach ($lines as $line) {
            if (! isset($result[$line->product_id])) {
                $result[$line->product_id] = PurchasePriceHistoryItem::fromLine($line);
            }
        }

        return $result;
    }
}
