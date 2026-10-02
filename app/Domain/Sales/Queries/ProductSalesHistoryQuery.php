<?php

declare(strict_types=1);

namespace App\Domain\Sales\Queries;

use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use Illuminate\Database\Eloquent\Collection;

class ProductSalesHistoryQuery
{
    /**
     * Retrieve recent sales lines for a product across all customers.
     *
     * @return Collection<int, SalesInvoiceLine>
     */
    public function execute(Product $product, int $limit = 20): Collection
    {
        return SalesInvoiceLine::query()
            ->with(['salesInvoice.customer', 'productUnit.unit'])
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->whereHas('salesInvoice', fn ($q) => $q->where('status', SalesInvoice::STATUS_POSTED))
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();
    }
}
