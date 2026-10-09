<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\DTO\ReportFilters;
use App\Models\Company;
use App\Models\PostingBatch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class TradeEventActivity
{
    /**
     * Assert canonical void provenance for sales documents in company.
     * Fails closed if foreign, unlinked, or incoherent void/reversal batches are referenced.
     */
    public static function assertSalesVoidProvenance(Company $company): void
    {
        TradeProvenance::sales($company);
    }

    /**
     * Assert canonical void provenance for purchase documents in company.
     */
    public static function assertPurchaseVoidProvenance(Company $company): void
    {
        TradeProvenance::purchases($company);
    }

    /**
     * Build unified sales document activity query (Invoices + Returns + Inverses).
     */
    public static function salesDocumentActivityQuery(Company $company, ReportFilters $filters, bool $withCost = false): Builder
    {

        $startDate = $filters->period->startDate;
        $endDate = $filters->period->endDate;

        // 1. Original Invoices: join canonical same-company posted original batch
        $invoices = DB::table('sales_invoices as si')
            ->join('posting_batches as ob', function ($join) use ($company): void {
                $join->on('ob.id', '=', 'si.posting_batch_id')
                    ->where('ob.company_id', '=', $company->id)
                    ->whereIn('ob.status', [PostingBatch::STATUS_POSTED, PostingBatch::STATUS_REVERSED])
                    ->where('ob.source_type', '=', 'sales_invoice')
                    ->whereColumn('ob.source_id', '=', 'si.id');
            })
            ->where('si.company_id', $company->id)
            ->whereIn('si.status', ['posted', 'void'])
            ->whereBetween('si.issue_date', [$startDate, $endDate])
            ->select([
                'si.id as document_id',
                DB::raw("'invoice' as event_type"),
                'si.issue_date as business_date',
                'si.invoice_number as document_number',
                'si.customer_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_ar')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_en')), '')) as customer_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_en')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_ar')), '')) as customer_name_en"),

                'si.warehouse_id',
                'si.currency_code',
                'si.exchange_rate',
                'si.grand_total_base as gross_sales_base',
                DB::raw('(si.grand_total_base - si.tax_total_base) as revenue_base'),
                'si.discount_total_base as discounts_base',
                DB::raw('0.000000 as returns_base'),
                DB::raw('0.000000 as returns_revenue_base'),
                'si.tax_total_base as tax_base',
                ($withCost ? 'si.cogs_total_base as cogs_base' : DB::raw('NULL as cogs_base')),
                'si.grand_total_currency as gross_sales_currency',
                DB::raw('0.000000 as returns_currency'),
                'si.discount_total_currency as discounts_currency',
                'si.tax_total_currency as tax_currency',
                DB::raw('1 as is_issued_invoice'),
            ]);

        // 2. Inverse Invoices: join canonical reversal batch
        $inverseInvoices = DB::table('sales_invoices as si')
            ->join('posting_batches as vb', function ($join) use ($company): void {
                $join->on('vb.id', '=', 'si.void_posting_batch_id')
                    ->where('vb.company_id', '=', $company->id)
                    ->where('vb.status', '=', PostingBatch::STATUS_POSTED)
                    ->where('vb.source_type', '=', 'reversal')
                    ->whereColumn('vb.reversal_of_id', '=', 'si.posting_batch_id');
            })
            ->where('si.company_id', $company->id)
            ->where('si.status', '=', 'void')
            ->whereBetween('vb.posting_date', [$startDate, $endDate])
            ->select([
                'si.id as document_id',
                DB::raw("'inverse_invoice' as event_type"),
                'vb.posting_date as business_date',
                'si.invoice_number as document_number',
                'si.customer_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_ar')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_en')), '')) as customer_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_en')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_ar')), '')) as customer_name_en"),

                'si.warehouse_id',
                'si.currency_code',
                'si.exchange_rate',
                DB::raw('(-si.grand_total_base) as gross_sales_base'),
                DB::raw('(-(si.grand_total_base - si.tax_total_base)) as revenue_base'),
                DB::raw('(-si.discount_total_base) as discounts_base'),
                DB::raw('0.000000 as returns_base'),
                DB::raw('0.000000 as returns_revenue_base'),
                DB::raw('(-si.tax_total_base) as tax_base'),
                ($withCost ? DB::raw('(-si.cogs_total_base) as cogs_base') : DB::raw('NULL as cogs_base')),
                DB::raw('(-si.grand_total_currency) as gross_sales_currency'),
                DB::raw('0.000000 as returns_currency'),
                DB::raw('(-si.discount_total_currency) as discounts_currency'),
                DB::raw('(-si.tax_total_currency) as tax_currency'),
                DB::raw('0 as is_issued_invoice'),
            ]);

        // 3. Original Returns: join canonical same-company posted original batch
        $returns = DB::table('sales_returns as sr')
            ->join('posting_batches as ob', function ($join) use ($company): void {
                $join->on('ob.id', '=', 'sr.posting_batch_id')
                    ->where('ob.company_id', '=', $company->id)
                    ->whereIn('ob.status', [PostingBatch::STATUS_POSTED, PostingBatch::STATUS_REVERSED])
                    ->where('ob.source_type', '=', 'sales_return')
                    ->whereColumn('ob.source_id', '=', 'sr.id');
            })
            ->where('sr.company_id', $company->id)
            ->whereIn('sr.status', ['posted', 'void'])
            ->whereBetween('sr.issue_date', [$startDate, $endDate])
            ->select([
                'sr.id as document_id',
                DB::raw("'return' as event_type"),
                'sr.issue_date as business_date',
                'sr.return_number as document_number',
                'sr.customer_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_ar')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_en')), '')) as customer_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_en')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_ar')), '')) as customer_name_en"),

                'sr.warehouse_id',
                'sr.currency_code',
                'sr.exchange_rate',
                DB::raw('0.000000 as gross_sales_base'),
                DB::raw('(-(sr.grand_total_base - sr.tax_total_base)) as revenue_base'),
                'sr.discount_total_base as discounts_base',
                'sr.grand_total_base as returns_base',
                DB::raw('(sr.grand_total_base - sr.tax_total_base) as returns_revenue_base'),
                DB::raw('(-sr.tax_total_base) as tax_base'),
                ($withCost ? DB::raw('(-sr.cogs_total_base) as cogs_base') : DB::raw('NULL as cogs_base')),
                DB::raw('0.000000 as gross_sales_currency'),
                'sr.grand_total_currency as returns_currency',
                DB::raw('0.000000 as discounts_currency'),
                DB::raw('(-sr.tax_total_currency) as tax_currency'),
                DB::raw('0 as is_issued_invoice'),
            ]);

        // 4. Inverse Returns: join canonical reversal batch
        $inverseReturns = DB::table('sales_returns as sr')
            ->join('posting_batches as vrb', function ($join) use ($company): void {
                $join->on('vrb.id', '=', 'sr.void_posting_batch_id')
                    ->where('vrb.company_id', '=', $company->id)
                    ->where('vrb.status', '=', PostingBatch::STATUS_POSTED)
                    ->where('vrb.source_type', '=', 'reversal')
                    ->whereColumn('vrb.reversal_of_id', '=', 'sr.posting_batch_id');
            })
            ->where('sr.company_id', $company->id)
            ->where('sr.status', '=', 'void')
            ->whereBetween('vrb.posting_date', [$startDate, $endDate])
            ->select([
                'sr.id as document_id',
                DB::raw("'inverse_return' as event_type"),
                'vrb.posting_date as business_date',
                'sr.return_number as document_number',
                'sr.customer_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_ar')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_en')), '')) as customer_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_en')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_ar')), '')) as customer_name_en"),

                'sr.warehouse_id',
                'sr.currency_code',
                'sr.exchange_rate',
                DB::raw('0.000000 as gross_sales_base'),
                DB::raw('(sr.grand_total_base - sr.tax_total_base) as revenue_base'),
                DB::raw('(-sr.discount_total_base) as discounts_base'),
                DB::raw('(-sr.grand_total_base) as returns_base'),
                DB::raw('(-(sr.grand_total_base - sr.tax_total_base)) as returns_revenue_base'),
                'sr.tax_total_base as tax_base',
                ($withCost ? 'sr.cogs_total_base as cogs_base' : DB::raw('NULL as cogs_base')),
                DB::raw('0.000000 as gross_sales_currency'),
                DB::raw('(-sr.grand_total_currency) as returns_currency'),
                DB::raw('0.000000 as discounts_currency'),
                'sr.tax_total_currency as tax_currency',
                DB::raw('0 as is_issued_invoice'),
            ]);

        // Apply entity filters if set
        if ($filters->customerId !== null) {
            $invoices->where('si.customer_id', $filters->customerId);
            $inverseInvoices->where('si.customer_id', $filters->customerId);
            $returns->where('sr.customer_id', $filters->customerId);
            $inverseReturns->where('sr.customer_id', $filters->customerId);
        }

        if ($filters->warehouseId !== null) {
            $invoices->where('si.warehouse_id', $filters->warehouseId);
            $inverseInvoices->where('si.warehouse_id', $filters->warehouseId);
            $returns->where('sr.warehouse_id', $filters->warehouseId);
            $inverseReturns->where('sr.warehouse_id', $filters->warehouseId);
        }

        if ($filters->currencyCode !== null) {
            $invoices->where('si.currency_code', $filters->currencyCode);
            $inverseInvoices->where('si.currency_code', $filters->currencyCode);
            $returns->where('sr.currency_code', $filters->currencyCode);
            $inverseReturns->where('sr.currency_code', $filters->currencyCode);
        }

        $union = $invoices->unionAll($inverseInvoices)->unionAll($returns)->unionAll($inverseReturns);

        return DB::query()->fromSub($union, 'events');
    }

    /**
     * Build unified sales line activity query (Invoice lines + Return lines + Inverses).
     */
    public static function salesLineActivityQuery(Company $company, ReportFilters $filters, bool $withCost = false): Builder
    {

        $startDate = $filters->period->startDate;
        $endDate = $filters->period->endDate;

        // 1. Original Invoice Lines
        $lines = DB::table('sales_invoice_lines as sil')
            ->join('sales_invoices as si', 'si.id', '=', 'sil.sales_invoice_id')
            ->join('posting_batches as ob', function ($join) use ($company): void {
                $join->on('ob.id', '=', 'si.posting_batch_id')
                    ->where('ob.company_id', '=', $company->id)
                    ->whereIn('ob.status', [PostingBatch::STATUS_POSTED, PostingBatch::STATUS_REVERSED])
                    ->where('ob.source_type', '=', 'sales_invoice')
                    ->whereColumn('ob.source_id', '=', 'si.id');
            })
            ->leftJoin('products as p', 'p.id', '=', 'sil.product_id')
            ->where('sil.company_id', $company->id)
            ->where('si.company_id', $company->id)
            ->whereIn('si.status', ['posted', 'void'])
            ->whereBetween('si.issue_date', [$startDate, $endDate])
            ->select([
                'sil.id as line_id',
                'si.id as document_id',
                'si.invoice_number as document_number',
                DB::raw("'invoice_line' as event_type"),
                'si.issue_date as business_date',
                'si.customer_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_ar')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_en')), '')) as customer_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_en')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_ar')), '')) as customer_name_en"),

                'si.warehouse_id',
                'sil.product_id',
                'p.category_id',
                'sil.item_description',
                'sil.product_sku',
                'sil.product_name_ar',
                'sil.product_name_en',
                'sil.unit_name_ar',
                'sil.unit_name_en',
                'sil.unit_price',
                'si.currency_code',
                'sil.quantity_base',
                'sil.line_total_base',
                DB::raw('(sil.line_total_base - sil.line_tax_base) as line_revenue_base'),
                'sil.line_discount_base',
                'sil.line_tax_base',
                ($withCost ? 'sil.cogs_total_base' : DB::raw('NULL as cogs_total_base')),
                DB::raw('1 as sign'),
            ]);

        // 2. Inverse Invoice Lines
        $inverseLines = DB::table('sales_invoice_lines as sil')
            ->join('sales_invoices as si', 'si.id', '=', 'sil.sales_invoice_id')
            ->join('posting_batches as vb', function ($join) use ($company): void {
                $join->on('vb.id', '=', 'si.void_posting_batch_id')
                    ->where('vb.company_id', '=', $company->id)
                    ->where('vb.status', '=', PostingBatch::STATUS_POSTED)
                    ->where('vb.source_type', '=', 'reversal')
                    ->whereColumn('vb.reversal_of_id', '=', 'si.posting_batch_id');
            })
            ->leftJoin('products as p', 'p.id', '=', 'sil.product_id')
            ->where('sil.company_id', $company->id)
            ->where('si.company_id', $company->id)
            ->where('si.status', '=', 'void')
            ->whereBetween('vb.posting_date', [$startDate, $endDate])
            ->select([
                'sil.id as line_id',
                'si.id as document_id',
                'si.invoice_number as document_number',
                DB::raw("'inverse_invoice_line' as event_type"),
                'vb.posting_date as business_date',
                'si.customer_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_ar')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_en')), '')) as customer_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_en')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_ar')), '')) as customer_name_en"),

                'si.warehouse_id',
                'sil.product_id',
                'p.category_id',
                'sil.item_description',
                'sil.product_sku',
                'sil.product_name_ar',
                'sil.product_name_en',
                'sil.unit_name_ar',
                'sil.unit_name_en',
                'sil.unit_price',
                'si.currency_code',
                DB::raw('(-sil.quantity_base) as quantity_base'),
                DB::raw('(-sil.line_total_base) as line_total_base'),
                DB::raw('(-(sil.line_total_base - sil.line_tax_base)) as line_revenue_base'),
                DB::raw('(-sil.line_discount_base) as line_discount_base'),
                DB::raw('(-sil.line_tax_base) as line_tax_base'),
                ($withCost ? DB::raw('(-sil.cogs_total_base) as cogs_total_base') : DB::raw('NULL as cogs_total_base')),
                DB::raw('-1 as sign'),
            ]);

        // 3. Original Return Lines
        $returnLines = DB::table('sales_return_lines as srl')
            ->join('sales_returns as sr', 'sr.id', '=', 'srl.sales_return_id')
            ->join('posting_batches as ob', function ($join) use ($company): void {
                $join->on('ob.id', '=', 'sr.posting_batch_id')
                    ->where('ob.company_id', '=', $company->id)
                    ->whereIn('ob.status', [PostingBatch::STATUS_POSTED, PostingBatch::STATUS_REVERSED])
                    ->where('ob.source_type', '=', 'sales_return')
                    ->whereColumn('ob.source_id', '=', 'sr.id');
            })
            ->leftJoin('products as p', 'p.id', '=', 'srl.product_id')
            ->where('srl.company_id', $company->id)
            ->where('sr.company_id', $company->id)
            ->whereIn('sr.status', ['posted', 'void'])
            ->whereBetween('sr.issue_date', [$startDate, $endDate])
            ->select([
                'srl.id as line_id',
                'sr.id as document_id',
                'sr.return_number as document_number',
                DB::raw("'return_line' as event_type"),
                'sr.issue_date as business_date',
                'sr.customer_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_ar')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_en')), '')) as customer_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_en')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_ar')), '')) as customer_name_en"),

                'sr.warehouse_id',
                'srl.product_id',
                'p.category_id',
                'srl.item_description',
                'srl.product_sku',
                'srl.product_name_ar',
                'srl.product_name_en',
                'srl.unit_name_ar',
                'srl.unit_name_en',
                'srl.unit_price',
                'sr.currency_code',
                DB::raw('(-srl.quantity_base) as quantity_base'),
                DB::raw('(-srl.line_total_base) as line_total_base'),
                DB::raw('(-(srl.line_total_base - srl.line_tax_base)) as line_revenue_base'),
                DB::raw('(-srl.line_discount_base) as line_discount_base'),
                DB::raw('(-srl.line_tax_base) as line_tax_base'),
                ($withCost ? DB::raw('(-srl.cogs_total_base) as cogs_total_base') : DB::raw('NULL as cogs_total_base')),
                DB::raw('-1 as sign'),
            ]);

        // 4. Inverse Return Lines
        $inverseReturnLines = DB::table('sales_return_lines as srl')
            ->join('sales_returns as sr', 'sr.id', '=', 'srl.sales_return_id')
            ->join('posting_batches as vrb', function ($join) use ($company): void {
                $join->on('vrb.id', '=', 'sr.void_posting_batch_id')
                    ->where('vrb.company_id', '=', $company->id)
                    ->where('vrb.status', '=', PostingBatch::STATUS_POSTED)
                    ->where('vrb.source_type', '=', 'reversal')
                    ->whereColumn('vrb.reversal_of_id', '=', 'sr.posting_batch_id');
            })
            ->leftJoin('products as p', 'p.id', '=', 'srl.product_id')
            ->where('srl.company_id', $company->id)
            ->where('sr.company_id', $company->id)
            ->where('sr.status', '=', 'void')
            ->whereBetween('vrb.posting_date', [$startDate, $endDate])
            ->select([
                'srl.id as line_id',
                'sr.id as document_id',
                'sr.return_number as document_number',
                DB::raw("'inverse_return_line' as event_type"),
                'vrb.posting_date as business_date',
                'sr.customer_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_ar')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_en')), '')) as customer_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_en')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_ar')), '')) as customer_name_en"),

                'sr.warehouse_id',
                'srl.product_id',
                'p.category_id',
                'srl.item_description',
                'srl.product_sku',
                'srl.product_name_ar',
                'srl.product_name_en',
                'srl.unit_name_ar',
                'srl.unit_name_en',
                'srl.unit_price',
                'sr.currency_code',
                'srl.quantity_base',
                'srl.line_total_base',
                DB::raw('(srl.line_total_base - srl.line_tax_base) as line_revenue_base'),
                'srl.line_discount_base',
                'srl.line_tax_base',
                ($withCost ? 'srl.cogs_total_base' : DB::raw('NULL as cogs_total_base')),
                DB::raw('1 as sign'),
            ]);

        // Filters
        if ($filters->customerId !== null) {
            $lines->where('si.customer_id', $filters->customerId);
            $inverseLines->where('si.customer_id', $filters->customerId);
            $returnLines->where('sr.customer_id', $filters->customerId);
            $inverseReturnLines->where('sr.customer_id', $filters->customerId);
        }

        if ($filters->productId !== null) {
            $lines->where('sil.product_id', $filters->productId);
            $inverseLines->where('sil.product_id', $filters->productId);
            $returnLines->where('srl.product_id', $filters->productId);
            $inverseReturnLines->where('srl.product_id', $filters->productId);
        }

        if ($filters->categoryId !== null) {
            $lines->where('p.category_id', $filters->categoryId);
            $inverseLines->where('p.category_id', $filters->categoryId);
            $returnLines->where('p.category_id', $filters->categoryId);
            $inverseReturnLines->where('p.category_id', $filters->categoryId);
        }

        if ($filters->warehouseId !== null) {
            $lines->where('si.warehouse_id', $filters->warehouseId);
            $inverseLines->where('si.warehouse_id', $filters->warehouseId);
            $returnLines->where('sr.warehouse_id', $filters->warehouseId);
            $inverseReturnLines->where('sr.warehouse_id', $filters->warehouseId);
        }

        if ($filters->currencyCode !== null) {
            $lines->where('si.currency_code', $filters->currencyCode);
            $inverseLines->where('si.currency_code', $filters->currencyCode);
            $returnLines->where('sr.currency_code', $filters->currencyCode);
            $inverseReturnLines->where('sr.currency_code', $filters->currencyCode);
        }

        $union = $lines->unionAll($inverseLines)->unionAll($returnLines)->unionAll($inverseReturnLines);

        return DB::query()->fromSub($union, 'lines');
    }

    /**
     * Build unified purchase document activity query (Purchases + Returns + Inverses).
     */
    public static function purchaseDocumentActivityQuery(Company $company, ReportFilters $filters): Builder
    {

        $startDate = $filters->period->startDate;
        $endDate = $filters->period->endDate;

        // 1. Original Purchases: canonical same-company posted batch
        $purchases = DB::table('purchases as p')
            ->join('posting_batches as ob', function ($join) use ($company): void {
                $join->on('ob.id', '=', 'p.posting_batch_id')
                    ->where('ob.company_id', '=', $company->id)
                    ->whereIn('ob.status', [PostingBatch::STATUS_POSTED, PostingBatch::STATUS_REVERSED])
                    ->where('ob.source_type', '=', 'purchase')
                    ->whereColumn('ob.source_id', '=', 'p.id');
            })
            ->where('p.company_id', $company->id)
            ->where('p.status', '=', 'posted')
            ->whereBetween('p.purchase_date', [$startDate, $endDate])
            ->select([
                'p.id as document_id',
                DB::raw("'purchase' as event_type"),
                'p.purchase_date as business_date',
                'p.purchase_number as document_number',
                'p.vendor_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.vendor_snapshot, '$.name_ar')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.vendor_snapshot, '$.name_en')), '')) as vendor_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.vendor_snapshot, '$.name_en')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.vendor_snapshot, '$.name_ar')), '')) as vendor_name_en"),

                'p.warehouse_id',
                'p.currency_code',
                'p.exchange_rate',
                'p.grand_total_base as commercial_purchases_base',
                'p.discount_total_base as discounts_base',
                DB::raw('0.000000 as returns_base'),
                'p.tax_total_base as tax_base',
                'p.grand_total_currency as commercial_purchases_currency',
                DB::raw('0.000000 as returns_currency'),
                'p.discount_total_currency as discounts_currency',
                'p.tax_total_currency as tax_currency',
                DB::raw('1 as is_issued_purchase'),
            ]);

        // 3. Purchase Returns: includes zero-effect posted returns (where posting_batch_id may be null)
        $returns = DB::table('purchase_returns as pr')
            ->where('pr.company_id', $company->id)
            ->where('pr.status', '=', 'posted')
            ->whereBetween('pr.return_date', [$startDate, $endDate])
            ->select([
                'pr.id as document_id',
                DB::raw("'purchase_return' as event_type"),
                'pr.return_date as business_date',
                'pr.return_number as document_number',
                'pr.vendor_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(pr.vendor_snapshot, '$.name_ar')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(pr.vendor_snapshot, '$.name_en')), '')) as vendor_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(pr.vendor_snapshot, '$.name_en')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(pr.vendor_snapshot, '$.name_ar')), '')) as vendor_name_en"),

                'pr.warehouse_id',
                'pr.currency_code',
                'pr.exchange_rate',
                DB::raw('0.000000 as commercial_purchases_base'),
                'pr.discount_total_base as discounts_base',
                'pr.grand_total_base as returns_base',
                'pr.tax_total_base as tax_base',
                DB::raw('0.000000 as commercial_purchases_currency'),
                'pr.grand_total_currency as returns_currency',
                'pr.discount_total_currency as discounts_currency',
                'pr.tax_total_currency as tax_currency',
                DB::raw('0 as is_issued_purchase'),
            ]);

        if ($filters->vendorId !== null) {
            $purchases->where('p.vendor_id', $filters->vendorId);
            $returns->where('pr.vendor_id', $filters->vendorId);
        }

        if ($filters->warehouseId !== null) {
            $purchases->where('p.warehouse_id', $filters->warehouseId);
            $returns->where('pr.warehouse_id', $filters->warehouseId);
        }

        if ($filters->currencyCode !== null) {
            $purchases->where('p.currency_code', $filters->currencyCode);
            $returns->where('pr.currency_code', $filters->currencyCode);
        }

        $union = $purchases->unionAll($returns);

        return DB::query()->fromSub($union, 'events');
    }

    /**
     * Build unified purchase line activity query.
     */
    public static function purchaseLineActivityQuery(Company $company, ReportFilters $filters): Builder
    {

        $startDate = $filters->period->startDate;
        $endDate = $filters->period->endDate;

        // 1. Original Purchase Lines
        $lines = DB::table('purchase_lines as pl')
            ->join('purchases as p', 'p.id', '=', 'pl.purchase_id')
            ->join('posting_batches as ob', function ($join) use ($company): void {
                $join->on('ob.id', '=', 'p.posting_batch_id')
                    ->where('ob.company_id', '=', $company->id)
                    ->whereIn('ob.status', [PostingBatch::STATUS_POSTED, PostingBatch::STATUS_REVERSED])
                    ->where('ob.source_type', '=', 'purchase')
                    ->whereColumn('ob.source_id', '=', 'p.id');
            })
            ->where('pl.company_id', $company->id)
            ->where('p.company_id', $company->id)
            ->where('p.status', '=', 'posted')
            ->whereBetween('p.purchase_date', [$startDate, $endDate])
            ->select([
                'pl.id as line_id',
                'p.id as document_id',
                'p.purchase_number as document_number',
                DB::raw("'purchase_line' as event_type"),
                'p.purchase_date as business_date',
                'p.vendor_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.vendor_snapshot, '$.name_ar')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.vendor_snapshot, '$.name_en')), '')) as vendor_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.vendor_snapshot, '$.name_en')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.vendor_snapshot, '$.name_ar')), '')) as vendor_name_en"),

                'p.warehouse_id',
                'pl.product_id',
                'pl.item_description',
                'pl.product_sku',
                'pl.product_name_ar',
                'pl.product_name_en',
                'pl.unit_name_ar',
                'pl.unit_name_en',
                'pl.unit_cost',
                'p.currency_code',
                'pl.quantity_base',
                'pl.line_total_base as commercial_line_total_base',
                DB::raw('(pl.line_total_base - CASE WHEN pl.purchase_tax_account_id IS NULL THEN 0 ELSE pl.line_tax_base END + COALESCE(pl.landed_cost_allocated_base, 0)) as inventory_acquisition_base'),
                DB::raw('COALESCE(pl.landed_cost_allocated_base, 0.000000) as landed_cost_allocated_base'),
                'pl.inventory_unit_cost_base',
                DB::raw('1 as sign'),
            ]);

        // 3. Purchase Return Lines: includes zero-effect posted returns
        $returnLines = DB::table('purchase_return_lines as prl')
            ->join('purchase_returns as pr', 'pr.id', '=', 'prl.purchase_return_id')
            ->where('prl.company_id', $company->id)
            ->where('pr.company_id', $company->id)
            ->where('pr.status', '=', 'posted')
            ->whereBetween('pr.return_date', [$startDate, $endDate])
            ->select([
                'prl.id as line_id',
                'pr.id as document_id',
                'pr.return_number as document_number',
                DB::raw("'return_line' as event_type"),
                'pr.return_date as business_date',
                'pr.vendor_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(pr.vendor_snapshot, '$.name_ar')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(pr.vendor_snapshot, '$.name_en')), '')) as vendor_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(pr.vendor_snapshot, '$.name_en')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(pr.vendor_snapshot, '$.name_ar')), '')) as vendor_name_en"),

                'pr.warehouse_id',
                'prl.product_id',
                'prl.item_description',
                'prl.product_sku',
                'prl.product_name_ar',
                'prl.product_name_en',
                'prl.unit_name_ar',
                'prl.unit_name_en',
                'prl.unit_cost',
                'pr.currency_code',
                DB::raw('(-prl.quantity_base) as quantity_base'),
                DB::raw('(-prl.line_total_base) as commercial_line_total_base'),
                DB::raw('(-prl.inventory_value_removed_base) as inventory_acquisition_base'),
                DB::raw('0.000000 as landed_cost_allocated_base'),
                DB::raw('NULL as inventory_unit_cost_base'),
                DB::raw('-1 as sign'),
            ]);

        if ($filters->vendorId !== null) {
            $lines->where('p.vendor_id', $filters->vendorId);
            $returnLines->where('pr.vendor_id', $filters->vendorId);
        }

        if ($filters->productId !== null) {
            $lines->where('pl.product_id', $filters->productId);
            $returnLines->where('prl.product_id', $filters->productId);
        }

        if ($filters->warehouseId !== null) {
            $lines->where('p.warehouse_id', $filters->warehouseId);
            $returnLines->where('pr.warehouse_id', $filters->warehouseId);
        }

        if ($filters->currencyCode !== null) {
            $lines->where('p.currency_code', $filters->currencyCode);
            $returnLines->where('pr.currency_code', $filters->currencyCode);
        }

        $union = $lines->unionAll($returnLines);

        return DB::query()->fromSub($union, 'lines');
    }
}
