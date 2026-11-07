<?php

declare(strict_types=1);

namespace App\Services\Corrections;

use App\Models\SalesInvoice;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SalesDependencySnapshot
{
    /** @return array<string, mixed> */
    public function capture(SalesInvoice $invoice, bool $replacement, bool $lock = false): array
    {
        $company = (int) $invoice->company_id;
        $snapshot = [];
        $read = function (string $table, string $columns, callable $scope) use ($company, $lock, &$snapshot) {
            $q = DB::table($table)->where(fn (Builder $q) => $table === 'units' ? $q->where('company_id', $company)->orWhereNull('company_id') : $q->where('company_id', $company));
            $scope($q);
            if ($lock) {
                $q->lockForUpdate();
            }
            $rows = $q->select(explode(' ', $columns))->orderBy('id')->limit(2001)->get();
            if ($rows->count() > 2000) {
                throw ValidationException::withMessages(['correction' => __('corrections.graph_too_large')]);
            }
            $snapshot[$table] = $rows->all();

            return $rows;
        };
        $read('sales_invoices', 'id customer_id warehouse_id status issue_date due_date currency_code exchange_rate document_locale notes terms posting_batch_id void_posting_batch_id grand_total_currency grand_total_base', fn ($q) => $q->where('id', $invoice->id));
        $lines = $read('sales_invoice_lines', 'id product_id product_unit_id line_number item_description quantity quantity_base unit_price unit_conversion_ratio discount_type discount_value tax_rate_id tax_rate_snapshot tax_inclusive line_discount line_tax line_total line_total_base', fn ($q) => $q->where('sales_invoice_id', $invoice->id));
        $lots = $read('sales_invoice_lot_allocations', 'id sales_invoice_line_id inventory_lot_id quantity_allocated_base unit_cost_base total_cost_base stock_movement_id', fn ($q) => $q->where('sales_invoice_id', $invoice->id));
        $returns = $read('sales_returns', 'id sales_invoice_id status issue_date reason notes document_locale posting_batch_id void_posting_batch_id grand_total_currency grand_total_base', fn ($q) => $q->where('sales_invoice_id', $invoice->id));
        $read('sales_return_lines', 'id sales_return_id sales_invoice_line_id quantity quantity_base line_total line_tax line_discount', fn ($q) => $q->whereIn('sales_return_id', $returns->pluck('id')));
        $returnLots = $read('sales_return_lot_allocations', 'id sales_return_id sales_return_line_id inventory_lot_id quantity_allocated_base unit_cost_base total_cost_base stock_movement_id', fn ($q) => $q->whereIn('sales_return_id', $returns->pluck('id')));
        $movementIds = $lots->pluck('stock_movement_id')->merge($returnLots->pluck('stock_movement_id'))->filter();
        $read('stock_movements', 'id product_id warehouse_id lot_id movement_type movement_date quantity_delta_base unit_cost_base value_delta_base unit_id source_quantity conversion_to_base source_type source_id source_line_id reversal_of_id', fn ($q) => $q->where(fn ($q) => $q->whereIn('id', $movementIds)->orWhereIn('reversal_of_id', $movementIds)));
        $batches = collect([$invoice->posting_batch_id])->merge($returns->pluck('posting_batch_id'))->filter();
        $read('posting_batches', 'id posting_date status reversal_of_id reversed_by_batch_id source_type source_id', fn ($q) => $q->where(fn ($q) => $q->whereIn('id', $batches)->orWhereIn('reversal_of_id', $batches)));
        $read('posting_lines', 'id posting_batch_id ledger_account_id line_number debit_base credit_base transaction_currency_code transaction_amount exchange_rate', fn ($q) => $q->whereIn('posting_batch_id', $batches));
        $allocations = $read('customer_payment_allocations', 'id customer_payment_id sales_invoice_id allocated_amount application_event_id prior_posting_batch_id settlement_base_value', fn ($q) => $q->whereIn('customer_payment_id', DB::table('customer_payment_allocations')->select('customer_payment_id')->where('company_id', $company)->where('sales_invoice_id', $invoice->id)));
        $payments = $read('customer_payments', 'id is_reversed check_id amount amount_base posting_batch_id reversal_posting_batch_id', fn ($q) => $q->whereIn('id', $allocations->pluck('customer_payment_id')));
        $read('customer_payment_application_events', 'id customer_payment_id applied_at reversed_at posting_batch_id reversal_posting_batch_id request_hash', fn ($q) => $q->whereIn('customer_payment_id', $payments->pluck('id')));
        $products = $lines->pluck('product_id')->filter()->unique();
        $read('inventory_balances', 'id product_id warehouse_id quantity_base', fn ($q) => $q->whereIn('product_id', $products)->where('warehouse_id', $invoice->warehouse_id));
        $read('inventory_cost_states', 'id product_id quantity_base average_cost_base inventory_value_base', fn ($q) => $q->whereIn('product_id', $products));
        $read('inventory_lot_balances', 'id product_id warehouse_id lot_id quantity_base', fn ($q) => $q->whereIn('lot_id', $lots->pluck('inventory_lot_id')->merge($returnLots->pluck('inventory_lot_id'))->filter())->where('warehouse_id', $invoice->warehouse_id));
        if ($replacement) {
            $productRows = $read('products', 'id active product_type track_stock track_expiry base_unit_id default_sale_price_base', fn ($q) => $q->whereIn('id', $products));
            $units = $read('product_units', 'id product_id unit_id conversion_to_base is_base is_default_sale active default_sale_price_base', fn ($q) => $q->whereIn('product_id', $products));
            $read('units', 'id active allows_fraction decimal_places', fn ($q) => $q->whereIn('id', $units->pluck('unit_id')->merge($productRows->pluck('base_unit_id'))));
            $read('tax_rates', 'id active rate calculation sales_tax_account_id', fn ($q) => $q->whereIn('id', $lines->pluck('tax_rate_id')->filter()));
            $read('customers', 'id status preferred_locale', fn ($q) => $q->where('id', $invoice->customer_id));
            $read('warehouses', 'id active', fn ($q) => $q->where('id', $invoice->warehouse_id));
            $read('company_currencies', 'id currency_code enabled is_base', fn ($q) => $q->where('currency_code', $invoice->currency_code));
            $read('company_languages', 'id locale enabled', fn ($q) => $q->where('locale', $invoice->document_locale));
        }
        $snapshot['company'] = DB::table('companies')->where('id', $company)->select(['id', 'status', 'base_currency_code', 'timezone', 'default_locale'])->get()->all();

        return $snapshot;
    }
}
