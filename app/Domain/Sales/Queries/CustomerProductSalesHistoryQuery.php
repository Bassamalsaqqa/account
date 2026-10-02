<?php

declare(strict_types=1);

namespace App\Domain\Sales\Queries;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use Illuminate\Database\Eloquent\Collection;

class CustomerProductSalesHistoryQuery
{
    /**
     * Retrieve recent sales lines for a specific customer and product.
     *
     * @return Collection<int, SalesInvoiceLine>
     */
    public function execute(Customer $customer, Product $product, int $limit = 10): Collection
    {
        return SalesInvoiceLine::query()
            ->with(['salesInvoice', 'productUnit.unit'])
            ->where('company_id', $customer->company_id)
            ->where('product_id', $product->id)
            ->whereHas('salesInvoice', function ($q) use ($customer) {
                $q->where('company_id', $customer->company_id)
                    ->where('customer_id', $customer->id)
                    ->where('status', SalesInvoice::STATUS_POSTED);
            })
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();
    }
}
