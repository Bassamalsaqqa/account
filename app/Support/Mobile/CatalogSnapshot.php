<?php

declare(strict_types=1);

namespace App\Support\Mobile;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

final class CatalogSnapshot
{
    /**
     * Truthfully reflects company catalog changes boundedly without memory scanning or cost leakage.
     * Uses streaming company-scoped queries with constant O(1) buffer memory.
     */
    public static function version(Company $company): string
    {
        $hasProducts = false;
        $ctx = hash_init('sha256');

        // 1. Stream active products in company
        $products = DB::table('products')
            ->where('company_id', $company->id)
            ->where('active', 1)
            ->whereNull('deleted_at')
            ->select(['id', 'sku', 'name_ar', 'name_en', 'base_unit_id', 'default_sale_price_base', 'track_stock', 'updated_at'])
            ->orderBy('id', 'asc');

        foreach ($products->cursor() as $row) {
            $hasProducts = true;
            hash_update($ctx, "P:{$row->id}:{$row->sku}:{$row->name_ar}:{$row->name_en}:{$row->base_unit_id}:{$row->default_sale_price_base}:{$row->track_stock}:{$row->updated_at}|");
        }

        if (! $hasProducts) {
            return self::emptyVersion();
        }

        // 2. Stream active product units for company
        $productUnits = DB::table('product_units as pu')
            ->join('products as p', 'p.id', '=', 'pu.product_id')
            ->where('pu.company_id', $company->id)
            ->where('pu.active', 1)
            ->where('p.active', 1)
            ->whereNull('p.deleted_at')
            ->select(['pu.id', 'pu.product_id', 'pu.unit_id', 'pu.conversion_to_base', 'pu.is_base', 'pu.is_default_sale', 'pu.default_sale_price_base', 'pu.updated_at'])
            ->orderBy('pu.id', 'asc');

        foreach ($productUnits->cursor() as $row) {
            hash_update($ctx, "PU:{$row->id}:{$row->product_id}:{$row->unit_id}:{$row->conversion_to_base}:{$row->is_base}:{$row->is_default_sale}:{$row->default_sale_price_base}:{$row->updated_at}|");
        }

        // 3. Stream active units for company
        $units = DB::table('units')
            ->where('company_id', $company->id)
            ->where('active', 1)
            ->select(['id', 'code', 'name_ar', 'name_en', 'symbol_ar', 'symbol_en', 'allows_fraction', 'decimal_places', 'updated_at'])
            ->orderBy('id', 'asc');

        foreach ($units->cursor() as $row) {
            hash_update($ctx, "U:{$row->id}:{$row->code}:{$row->name_ar}:{$row->name_en}:{$row->symbol_ar}:{$row->symbol_en}:{$row->allows_fraction}:{$row->decimal_places}:{$row->updated_at}|");
        }

        // 4. Stream product barcodes for company
        $barcodes = DB::table('product_barcodes as pb')
            ->join('products as p', 'p.id', '=', 'pb.product_id')
            ->where('pb.company_id', $company->id)
            ->where('p.active', 1)
            ->whereNull('p.deleted_at')
            ->select(['pb.id', 'pb.product_id', 'pb.barcode', 'pb.is_primary', 'pb.updated_at'])
            ->orderBy('pb.id', 'asc');

        foreach ($barcodes->cursor() as $row) {
            hash_update($ctx, "B:{$row->id}:{$row->product_id}:{$row->barcode}:{$row->is_primary}:{$row->updated_at}|");
        }

        // 5. Stream inventory balances for company
        $balances = DB::table('inventory_balances as ib')
            ->join('products as p', 'p.id', '=', 'ib.product_id')
            ->where('ib.company_id', $company->id)
            ->where('p.active', 1)
            ->where('p.track_stock', 1)
            ->whereNull('p.deleted_at')
            ->select(['ib.id', 'ib.product_id', 'ib.warehouse_id', 'ib.quantity_base', 'ib.updated_at'])
            ->orderBy('ib.id', 'asc');

        foreach ($balances->cursor() as $row) {
            hash_update($ctx, "IB:{$row->id}:{$row->product_id}:{$row->warehouse_id}:{$row->quantity_base}:{$row->updated_at}|");
        }

        return hash_final($ctx);
    }

    /** Only valid after verifying that the authorized company catalog is empty. */
    public static function emptyVersion(): string
    {
        return hash('sha256', '[]');
    }
}
