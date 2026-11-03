<?php

declare(strict_types=1);

namespace App\Support;

final class DocumentIdentity
{
    public const array FIELDS = [
        'id',
        'product_id',
        'product_unit_id',
        'item_description',
        'unit_price',
        'unit_cost',
        'discount_type',
        'discount_value',
        'tax_rate_id',
        'tax_inclusive',
        'lot_id',
        'lots',
        'lot_allocations',
        'warehouse_id',
        'purchase_line_id',
        'sales_invoice_line_id',
    ];
}
