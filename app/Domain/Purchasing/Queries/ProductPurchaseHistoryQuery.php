<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Queries;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Support\TablePageSize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
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
        $query = $this->query($product, $companyId, $vendorId);
        $boundedLimit = max(1, min(100, $limit));
        $lines = $query
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

    /** @return LengthAwarePaginator<int, PurchasePriceHistoryItem> */
    public function paginate(Product $product, int $companyId, int $perPage = 50): LengthAwarePaginator
    {
        $page = $this->query($product, $companyId, null)->paginate(TablePageSize::resolve($perPage), ['purchase_lines.*'], 'purchaseHistoryPage');
        app(PurchaseHistoryProvenance::class)->assertLines($page->getCollection(), $companyId);
        $page->setCollection($page->getCollection()->map(fn (PurchaseLine $line) => PurchasePriceHistoryItem::fromLine($line)));

        return $page;
    }

    /** @return Builder<PurchaseLine> */
    private function query(Product|int $product, int $companyId, ?int $vendorId): Builder
    {
        app(PurchasingHistoryGuard::class)->authorize($companyId);
        $productId = app(PurchasingHistoryGuard::class)->validateProduct($product, $companyId);
        if ($vendorId !== null) {
            app(PurchasingHistoryGuard::class)->validateVendor($vendorId, $companyId);
        }

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

        return $query->with([
            'purchase.vendor' => fn ($q) => $q->withTrashed(),
            'product' => fn ($q) => $q->withTrashed(),
        ])->orderByDesc('purchases.purchase_date')->orderByDesc('purchases.id')->orderBy('purchase_lines.line_number')->orderBy('purchase_lines.id');
    }
}
