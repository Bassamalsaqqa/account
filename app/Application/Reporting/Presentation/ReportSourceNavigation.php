<?php

declare(strict_types=1);

namespace App\Application\Reporting\Presentation;

use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Security\ReportingGuard;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

final class ReportSourceNavigation
{
    /** Only immutable navigation identity comes from current records.
     * @return array<int,list<array{label:string,url:string}>> */
    public function forRows(Company $company, string $reportKey, ReportResult $result): array
    {
        $guard = app(ReportingGuard::class);
        $guard->authorize($company);
        $definitions = [
            ['field' => 'invoice_id', 'table' => 'sales_invoices', 'route' => 'invoices.show', 'permission' => 'sales.invoice.view'],
            ['field' => 'purchase_id', 'table' => 'purchases', 'route' => 'purchases.show', 'permission' => 'purchasing.purchase.view'],
            ['field' => 'check_id', 'table' => 'checks', 'route' => 'money.checks.show', 'permission' => 'money.check.view'],
            ['field' => 'expense_id', 'table' => 'expenses', 'route' => 'expenses.show', 'permission' => 'money.expense.view'],
            ['field' => 'transfer_id', 'table' => 'money_transfers', 'route' => 'money.transfers.show', 'permission' => 'money.transfer.view'],
            ['field' => 'employee_id', 'table' => 'employees', 'route' => 'employees.show', 'permission' => 'employees.view'],
            ['field' => 'product_id', 'table' => 'products', 'route' => 'products.show', 'permission' => 'inventory.stock.view', 'current' => true],
        ];
        if (str_starts_with($reportKey, 'sales.') || str_starts_with($reportKey, 'customers.')) {
            $definitions[] = ['field' => 'document_id', 'table' => 'sales_invoices', 'route' => 'invoices.show', 'permission' => 'sales.invoice.view', 'event' => 'invoice'];
            $definitions[] = ['field' => 'document_id', 'table' => 'sales_returns', 'route' => 'returns.show', 'permission' => 'sales.return.view', 'event' => 'return'];
        }
        if (str_starts_with($reportKey, 'purchases.') || str_starts_with($reportKey, 'vendors.')) {
            $definitions[] = ['field' => 'document_id', 'table' => 'purchases', 'route' => 'purchases.show', 'permission' => 'purchasing.purchase.view', 'event' => 'purchase'];
            $definitions[] = ['field' => 'document_id', 'table' => 'purchase_returns', 'route' => 'purchase-returns.show', 'permission' => 'purchasing.purchase.view', 'event' => 'return'];
        }
        if ($reportKey === 'money.receipts') {
            $definitions[] = ['field' => 'payment_id', 'table' => 'customer_payments', 'route' => 'payments.show', 'permission' => 'money.receipt.view'];
        }
        if ($reportKey === 'money.vendor-payments') {
            $definitions[] = ['field' => 'payment_id', 'table' => 'vendor_payments', 'route' => 'vendor-payments.show', 'permission' => 'purchasing.cost.view'];
        }
        $links = [];
        foreach ($definitions as $definition) {
            if (! $guard->allows($company, $definition['permission'])) {
                continue;
            }
            $indices = [];
            $ids = [];
            foreach ($result->rows as $index => $row) {
                $id = $row[$definition['field']] ?? null;
                if (! is_int($id) || $id < 1) {
                    continue;
                }
                if (isset($definition['event'])) {
                    $isReturn = str_contains((string) ($row['event_type'] ?? ''), 'return');
                    if (($definition['event'] === 'return') !== $isReturn) {
                        continue;
                    }
                }
                $indices[$index] = $id;
                $ids[] = $id;
            }
            if ($ids === []) {
                continue;
            }
            $query = DB::table($definition['table'])->where('company_id', $company->id)->whereIn('id', array_unique($ids));
            if (($definition['current'] ?? false) === true) {
                $query->whereNull('deleted_at');
            }
            $publicIds = $query->pluck('public_id', 'id');
            foreach ($indices as $index => $id) {
                if (isset($publicIds[$id])) {
                    $links[$index][] = ['label' => __('reports.open_source'), 'url' => route($definition['route'], ['publicId' => (string) $publicIds[$id]])];
                }
            }
        }

        return $links;
    }
}
