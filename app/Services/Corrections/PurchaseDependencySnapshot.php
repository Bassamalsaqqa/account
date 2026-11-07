<?php

declare(strict_types=1);

namespace App\Services\Corrections;

use App\Models\Purchase;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicit document graph; never discovers tables or scans unrelated tenant history. */
final class PurchaseDependencySnapshot
{
    /** @return array<string, list<object>> */
    public function capture(Purchase $purchase, bool $replacement, bool $lock = false): array
    {
        $company = (int) $purchase->company_id;
        $snapshot = [];
        $read = function (string $table, string $columns, ?callable $scope = null) use (&$snapshot, $company, $lock): Collection {
            $query = DB::table($table)->where(fn (Builder $q) => $table === 'units' ? $q->where('company_id', $company)->orWhereNull('company_id') : $q->where('company_id', $company));
            if ($scope !== null) {
                $scope($query);
            }
            if ($lock) {
                $query->lockForUpdate();
            }
            $rows = $query->select(explode(' ', $columns))->orderBy($table === 'company_inventory_settings' ? 'company_id' : 'id')->limit(2001)->get();
            if ($rows->count() > 2000) {
                throw ValidationException::withMessages(['correction' => __('corrections.graph_too_large')]);
            }
            $snapshot[$table] = $rows->all();

            return $rows;
        };
        $read('purchases', 'id vendor_id warehouse_id status vendor_invoice_number purchase_date due_date currency_code exchange_rate document_locale notes posting_batch_id void_posting_batch_id void_business_date grand_total_currency grand_total_base', fn (Builder $q) => $q->where('id', $purchase->id));
        $lines = $read('purchase_lines', 'id product_id product_unit_id line_number item_description quantity quantity_base unit_cost unit_conversion_ratio discount_type discount_value tax_rate_id tax_rate_snapshot tax_inclusive line_discount line_tax line_total line_total_base inventory_unit_cost_base stock_movement_id', fn (Builder $q) => $q->where('purchase_id', $purchase->id));
        $lots = $read('purchase_line_lots', 'id purchase_line_id lot_number expiry_date quantity quantity_base created_inventory_lot_id stock_movement_id', fn (Builder $q) => $q->whereIn('purchase_line_id', $lines->pluck('id')));
        $movements = $lines->pluck('stock_movement_id')->merge($lots->pluck('stock_movement_id'))->filter()->unique();
        $read('stock_movements', 'id product_id warehouse_id lot_id movement_type movement_date quantity_delta_base unit_cost_base value_delta_base unit_id source_quantity conversion_to_base source_type source_id source_line_id reversal_of_id', fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereIn('id', $movements)->orWhereIn('reversal_of_id', $movements)));
        $allocations = $read('vendor_payment_allocations', 'id vendor_payment_id purchase_id allocated_amount application_event_id prior_posting_batch_id base_amount_applied_to_payable settlement_base_value', fn (Builder $q) => $q->whereIn('vendor_payment_id', DB::table('vendor_payment_allocations')->select('vendor_payment_id')->where('company_id', $company)->where('purchase_id', $purchase->id)));
        $payments = $read('vendor_payments', 'id payment_number vendor_id money_account_id payment_date payment_method currency_code amount amount_base exchange_rate posting_batch_id is_reversed reversal_posting_batch_id check_id', fn (Builder $q) => $q->whereIn('id', $allocations->pluck('vendor_payment_id')));
        $events = $read('vendor_payment_application_events', 'id vendor_payment_id application_date posting_batch_id reversal_posting_batch_id applied_at reversed_at request_hash', fn (Builder $q) => $q->whereIn('vendor_payment_id', $payments->pluck('id')));
        $read('checks', 'id status amount currency_code due_date', fn (Builder $q) => $q->whereIn('id', $payments->pluck('check_id')->filter()));
        $returns = $read('purchase_returns', 'id purchase_id status return_date posting_batch_id void_posting_batch_id', fn (Builder $q) => $q->where('purchase_id', $purchase->id));
        $read('purchase_return_lines', 'id purchase_return_id purchase_line_id quantity quantity_base stock_movement_id', fn (Builder $q) => $q->whereIn('purchase_return_id', $returns->pluck('id')));
        $read('purchase_return_allocations', 'id purchase_return_id original_stock_movement_id inventory_lot_id quantity_base historical_value_base inventory_value_removed_base stock_movement_id', fn (Builder $q) => $q->whereIn('purchase_return_id', $returns->pluck('id')));
        $read('landed_cost_allocations', 'id expense_id purchase_id purchase_line_id allocated_base status', fn (Builder $q) => $q->where('purchase_id', $purchase->id));
        $batches = collect([$purchase->posting_batch_id])->merge($payments->pluck('posting_batch_id'))->merge($events->pluck('posting_batch_id'))->merge($returns->pluck('posting_batch_id'))->filter()->unique();
        $read('posting_batches', 'id posting_date status source_type source_id reversal_of_id reversed_by_batch_id transaction_currency_code exchange_rate', fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereIn('id', $batches)->orWhereIn('reversal_of_id', $batches)));
        $read('posting_lines', 'id posting_batch_id ledger_account_id line_number debit_base credit_base transaction_currency_code transaction_amount exchange_rate', fn (Builder $q) => $q->whereIn('posting_batch_id', $batches));
        $products = $lines->pluck('product_id')->unique();
        $read('inventory_balances', 'id product_id warehouse_id quantity_base', fn (Builder $q) => $q->whereIn('product_id', $products)->where('warehouse_id', $purchase->warehouse_id));
        $read('inventory_cost_states', 'id product_id quantity_base average_cost_base inventory_value_base', fn (Builder $q) => $q->whereIn('product_id', $products));
        $read('inventory_lot_balances', 'id product_id warehouse_id lot_id quantity_base', fn (Builder $q) => $q->whereIn('lot_id', $lots->pluck('created_inventory_lot_id')->filter())->where('warehouse_id', $purchase->warehouse_id));
        $read('inventory_lots', 'id product_id lot_number expiry_date source_type source_id source_line_id', fn (Builder $q) => $q->whereIn('id', $lots->pluck('created_inventory_lot_id')->filter()));
        if ($replacement) {
            $productRows = $read('products', 'id active product_type track_stock track_expiry base_unit_id name_ar name_en', fn (Builder $q) => $q->whereIn('id', $products));
            $units = $read('product_units', 'id product_id unit_id conversion_to_base is_base is_default_purchase active', fn (Builder $q) => $q->whereIn('product_id', $products));
            $read('units', 'id active allows_fraction decimal_places', fn (Builder $q) => $q->whereIn('id', $units->pluck('unit_id')->merge($productRows->pluck('base_unit_id'))));
            $read('tax_rates', 'id active rate calculation purchase_tax_account_id', fn (Builder $q) => $q->whereIn('id', $lines->pluck('tax_rate_id')->filter()));
            $read('vendors', 'id status preferred_locale default_currency_code', fn (Builder $q) => $q->where('id', $purchase->vendor_id));
            $read('warehouses', 'id active', fn (Builder $q) => $q->where('id', $purchase->warehouse_id));
            $read('company_currencies', 'id currency_code enabled is_base', fn (Builder $q) => $q->whereIn('currency_code', [$purchase->currency_code, $purchase->base_currency_code]));
            $read('company_languages', 'id locale enabled', fn (Builder $q) => $q->where('locale', $purchase->document_locale));
            $read('company_inventory_settings', 'company_id allow_negative_stock default_cost_method default_warehouse_id');
        }
        $query = DB::table('companies')->where('id', $company)->select(['id', 'status', 'base_currency_code', 'timezone', 'default_locale']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $snapshot['company'] = $query->get()->all();
        $accountQuery = DB::table('money_accounts')->where('company_id', $company)->whereIn('id', $payments->pluck('money_account_id'))->select(['id', 'name_ar', 'name_en', 'currency_code', 'is_active', 'ledger_account_id'])->orderBy('id');
        if ($lock) {
            $accountQuery->lockForUpdate();
        }
        $snapshot['money_accounts'] = $accountQuery->get()->all();

        return $snapshot;
    }

    public function digest(Purchase $purchase, bool $replacement, bool $lock = false): string
    {
        return hash('sha256', json_encode($this->capture($purchase, $replacement, $lock), JSON_THROW_ON_ERROR));
    }
}
