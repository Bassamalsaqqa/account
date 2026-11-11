<?php

declare(strict_types=1);

namespace App\Support\Mobile;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

final class CatalogSnapshot
{
    public const int CHUNK_SIZE = 256;

    /**
     * Bind an operation and its catalog snapshot version within one verified MariaDB consistent read.
     * Uses REPEATABLE READ isolation without leaking connection settings or taking financial row locks.
     */
    public static function withConsistentRead(callable $callback): mixed
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() > 0) {
            // The caller owns ambient transaction isolation. This branch cannot
            // establish a new consistent snapshot; callers must already use
            // REPEATABLE READ. Standard mobile HTTP entrypoints start at level 0.
            return $callback();
        }

        $driver = $connection->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Non-session SET TRANSACTION applies strictly to the NEXT transaction performed on this connection
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        $connection->beginTransaction();
        try {
            $result = $callback();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Hashes an O(n) company catalog scan without restricted costs.
     * Keyset chunks of 256 bound each PHP/PDO result buffer independently of catalog size.
     */
    public static function version(Company $company): string
    {
        if (DB::transactionLevel() > 0) {
            return self::computeVersion($company);
        }

        return (string) self::withConsistentRead(fn (): string => self::computeVersion($company));
    }

    /**
     * Compute catalog snapshot fingerprint using unambiguous typed canonical JSON row framing.
     */
    public static function computeVersion(Company $company): string
    {
        $hasProducts = false;
        $ctx = hash_init('sha256');

        // 1. Keyset bounded chunks of active products in company
        $lastId = 0;
        do {
            $rows = DB::table('products as p')
                ->where('p.company_id', $company->id)
                ->where('p.active', 1)
                ->whereNull('p.deleted_at')
                ->where('p.id', '>', $lastId)
                ->select([
                    'p.id',
                    'p.public_id',
                    'p.sku',
                    'p.name_ar',
                    'p.name_en',
                    'p.product_type',
                    'p.active',
                    'p.base_unit_id',
                    'p.default_sale_price_base',
                    'p.track_stock',
                    'p.updated_at',
                ])
                ->orderBy('p.id', 'asc')
                ->limit(self::CHUNK_SIZE)
                ->get();

            foreach ($rows as $row) {
                $hasProducts = true;
                $lastId = (int) $row->id;
                hash_update($ctx, json_encode([
                    'P',
                    (int) $row->id,
                    (string) $row->public_id,
                    (string) $row->sku,
                    (string) $row->name_ar,
                    $row->name_en !== null ? (string) $row->name_en : null,
                    (string) $row->product_type,
                    (bool) $row->active,
                    (int) $row->base_unit_id,
                    $row->default_sale_price_base !== null ? (string) $row->default_sale_price_base : null,
                    (bool) $row->track_stock,
                    (string) $row->updated_at,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            }
        } while ($rows->count() === self::CHUNK_SIZE);

        if (! $hasProducts) {
            return self::emptyVersion();
        }

        // 2. Keyset bounded chunks of active product units (scoped on both sides, cost excluded)
        $lastId = 0;
        do {
            $rows = DB::table('product_units as pu')
                ->join('products as p', 'p.id', '=', 'pu.product_id')
                ->join('units as u', 'u.id', '=', 'pu.unit_id')
                ->where('pu.company_id', $company->id)
                ->where('p.company_id', $company->id)
                ->where('u.company_id', $company->id)
                ->where('pu.active', 1)
                ->where('p.active', 1)
                ->whereNull('p.deleted_at')
                ->where('pu.id', '>', $lastId)
                ->select([
                    'pu.id',
                    'pu.product_id',
                    'p.public_id as product_public_id',
                    'pu.unit_id',
                    'u.public_id as unit_public_id',
                    'pu.conversion_to_base',
                    'pu.is_base',
                    'pu.is_default_sale',
                    'pu.default_sale_price_base',
                    'pu.active',
                    'pu.updated_at',
                ])
                ->orderBy('pu.id', 'asc')
                ->limit(self::CHUNK_SIZE)
                ->get();

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                hash_update($ctx, json_encode([
                    'PU',
                    (int) $row->id,
                    (int) $row->product_id,
                    (string) $row->product_public_id,
                    (int) $row->unit_id,
                    (string) $row->unit_public_id,
                    (string) $row->conversion_to_base,
                    (bool) $row->is_base,
                    (bool) $row->is_default_sale,
                    $row->default_sale_price_base !== null ? (string) $row->default_sale_price_base : null,
                    (bool) $row->active,
                    (string) $row->updated_at,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            }
        } while ($rows->count() === self::CHUNK_SIZE);

        // 3. Keyset bounded chunks of active units for company
        $lastId = 0;
        do {
            $rows = DB::table('units as u')
                ->where('u.company_id', $company->id)
                ->where('u.active', 1)
                ->where('u.id', '>', $lastId)
                ->select([
                    'u.id',
                    'u.public_id',
                    'u.code',
                    'u.name_ar',
                    'u.name_en',
                    'u.symbol_ar',
                    'u.symbol_en',
                    'u.allows_fraction',
                    'u.decimal_places',
                    'u.active',
                    'u.updated_at',
                ])
                ->orderBy('u.id', 'asc')
                ->limit(self::CHUNK_SIZE)
                ->get();

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                hash_update($ctx, json_encode([
                    'U',
                    (int) $row->id,
                    (string) $row->public_id,
                    (string) $row->code,
                    (string) $row->name_ar,
                    $row->name_en !== null ? (string) $row->name_en : null,
                    $row->symbol_ar !== null ? (string) $row->symbol_ar : null,
                    $row->symbol_en !== null ? (string) $row->symbol_en : null,
                    (bool) $row->allows_fraction,
                    (int) $row->decimal_places,
                    (bool) $row->active,
                    (string) $row->updated_at,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            }
        } while ($rows->count() === self::CHUNK_SIZE);

        // 4. Keyset bounded chunks of product barcodes for company (scoped on both sides)
        $lastId = 0;
        do {
            $rows = DB::table('product_barcodes as pb')
                ->join('products as p', 'p.id', '=', 'pb.product_id')
                ->where('pb.company_id', $company->id)
                ->where('p.company_id', $company->id)
                ->where('p.active', 1)
                ->whereNull('p.deleted_at')
                ->where('pb.id', '>', $lastId)
                ->select([
                    'pb.id',
                    'pb.product_id',
                    'p.public_id as product_public_id',
                    'pb.unit_id',
                    'pb.barcode',
                    'pb.type',
                    'pb.is_primary',
                    'pb.updated_at',
                ])
                ->orderBy('pb.id', 'asc')
                ->limit(self::CHUNK_SIZE)
                ->get();

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                hash_update($ctx, json_encode([
                    'B',
                    (int) $row->id,
                    (int) $row->product_id,
                    (string) $row->product_public_id,
                    $row->unit_id !== null ? (int) $row->unit_id : null,
                    (string) $row->barcode,
                    $row->type !== null ? (string) $row->type : null,
                    (bool) $row->is_primary,
                    (string) $row->updated_at,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            }
        } while ($rows->count() === self::CHUNK_SIZE);

        // 5. Keyset bounded chunks of inventory balances for company (scoped on both sides)
        $lastId = 0;
        do {
            $rows = DB::table('inventory_balances as ib')
                ->join('products as p', 'p.id', '=', 'ib.product_id')
                ->where('ib.company_id', $company->id)
                ->where('p.company_id', $company->id)
                ->where('p.active', 1)
                ->where('p.track_stock', 1)
                ->whereNull('p.deleted_at')
                ->where('ib.id', '>', $lastId)
                ->select([
                    'ib.id',
                    'ib.product_id',
                    'p.public_id as product_public_id',
                    'ib.warehouse_id',
                    'ib.quantity_base',
                    'ib.updated_at',
                ])
                ->orderBy('ib.id', 'asc')
                ->limit(self::CHUNK_SIZE)
                ->get();

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                hash_update($ctx, json_encode([
                    'IB',
                    (int) $row->id,
                    (int) $row->product_id,
                    (string) $row->product_public_id,
                    (int) $row->warehouse_id,
                    (string) $row->quantity_base,
                    (string) $row->updated_at,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            }
        } while ($rows->count() === self::CHUNK_SIZE);

        return hash_final($ctx);
    }

    /** Only valid after verifying that the authorized company catalog is empty. */
    public static function emptyVersion(): string
    {
        return hash('sha256', '[]');
    }
}
