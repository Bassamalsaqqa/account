<?php

declare(strict_types=1);

namespace App\Domain\Sales\Queries;

use App\Application\Reporting\Security\ReportingGuard;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** A bounded, read-only cue from posted lines; never replaces an editable price. */
final class CustomerLastPriceQuery
{
    /**
     * @param  list<int>  $productIds
     * @return array<int, array{price:string, currency:string, unit_ar:string, unit_en:?string, date:string}>
     */
    public function execute(Company $company, User $actor, int $customerId, array $productIds): array
    {
        $guard = app(ReportingGuard::class);
        $guard->authorize($company, $actor, ['reports.sales.view', 'sales.invoice.view']);
        $company = $guard->company($company, $actor);
        Customer::where('company_id', $company->id)->whereKey($customerId)->firstOrFail();
        $productIds = array_values(array_unique($productIds));
        abort_if(count($productIds) > 100, 422);
        abort_unless(Product::where('company_id', $company->id)->whereIn('id', $productIds)->count() === count($productIds), 404);
        if ($productIds === []) {
            return [];
        }

        $ranked = DB::table('sales_invoice_lines as l')->join('sales_invoices as i', 'i.id', '=', 'l.sales_invoice_id')
            ->where('l.company_id', $company->id)->where('i.company_id', $company->id)
            ->where('i.customer_id', $customerId)->where('i.status', 'posted')->whereNotNull('i.posting_batch_id')
            ->whereIn('l.product_id', $productIds)
            ->select('l.product_id', 'l.unit_price', 'l.unit_name_ar', 'l.unit_name_en', 'i.currency_code', 'i.issue_date')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY l.product_id ORDER BY i.issue_date DESC, i.id DESC, l.line_number DESC, l.id DESC) AS price_rank');
        $rows = DB::query()->fromSub($ranked, 'prices')->where('price_rank', 1)->get();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->product_id] = [
                'price' => (string) $row->unit_price, 'currency' => (string) $row->currency_code,
                'unit_ar' => (string) $row->unit_name_ar, 'unit_en' => $row->unit_name_en,
                'date' => (string) $row->issue_date,
            ];
        }

        return $result;
    }
}
