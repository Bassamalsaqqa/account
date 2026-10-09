<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

final class ProductAggregationHelper
{
    /**
     * Build SQL expression for a stable product group key.
     * Known products group strictly by product_id.
     * Null-product free-text lines group by an exact commercial identity tuple.
     */
    public static function groupKeySql(string $alias = 'l'): string
    {
        return "CASE WHEN {$alias}.product_id IS NOT NULL THEN CONCAT('P:', {$alias}.product_id) "
            ."ELSE CONCAT('F:', SHA2(CONCAT("
            ."LENGTH(COALESCE({$alias}.item_description, '')), ':', COALESCE({$alias}.item_description, ''), '|', "
            ."LENGTH(COALESCE({$alias}.product_sku, '')), ':', COALESCE({$alias}.product_sku, ''), '|', "
            ."LENGTH(COALESCE({$alias}.product_name_ar, '')), ':', COALESCE({$alias}.product_name_ar, ''), '|', "
            ."LENGTH(COALESCE({$alias}.product_name_en, '')), ':', COALESCE({$alias}.product_name_en, ''), '|', "
            ."LENGTH(COALESCE({$alias}.unit_name_ar, '')), ':', COALESCE({$alias}.unit_name_ar, ''), '|', "
            ."LENGTH(COALESCE({$alias}.unit_name_en, '')), ':', COALESCE({$alias}.unit_name_en, '')"
            .'), 256)) END';
    }

    /**
     * Build window function to rank rows within each product group.
     * Prefers original lines (invoice_line / purchase_line) over returns/inverses,
     * then latest business date, document ID, and line ID.
     */
    public static function representativeRankSql(string $alias = 'l'): string
    {
        $groupKey = self::groupKeySql($alias);

        return "ROW_NUMBER() OVER (PARTITION BY {$groupKey} ORDER BY "
            ."CASE WHEN {$alias}.event_type IN ('invoice_line', 'purchase_line') THEN 1 ELSE 2 END ASC, "
            ."{$alias}.business_date DESC, {$alias}.document_id DESC, {$alias}.line_id DESC)";
    }
}
