<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Company;
use App\Models\LandedCostAllocation;
use App\Models\LedgerAccount;
use App\Models\Purchase;
use App\Models\TaxRate;

final class PurchaseDraftSnapshot
{
    /**
     * Reads a complete, deterministic canonical snapshot of a locked draft purchase
     * fresh from the database under the current transaction lock.
     *
     * @return array<string, mixed>
     */
    public function capture(Purchase $purchase): array
    {
        $locked = Purchase::where('company_id', $purchase->company_id)
            ->lockForUpdate()
            ->findOrFail($purchase->id);

        $locked->assertMutableDraft();

        $company = Company::where('id', $locked->company_id)->lockForUpdate()->firstOrFail();

        // Freshly query ordered lines and lots under update lock
        $lines = $locked->lines()
            ->withoutGlobalScopes()
            ->orderBy('line_number')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $linesArray = [];
        $taxAccountsArray = [];

        foreach ($lines as $line) {
            $lots = $line->lots()
                ->withoutGlobalScopes()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $lotsArray = [];
            foreach ($lots as $lot) {
                $lotsArray[] = [
                    'product_unit_id' => (int) $line->product_unit_id,
                    'lot_number' => $lot->lot_number !== null ? trim($lot->lot_number) : null,
                    'expiry_date' => $lot->expiry_date?->format('Y-m-d'),
                    'quantity' => (string) $lot->quantity,
                    'quantity_base' => (string) $lot->quantity_base,
                ];
            }

            $taxRateId = $line->tax_rate_id ? (int) $line->tax_rate_id : null;
            $taxRateSnapshot = $line->tax_rate_snapshot !== null ? (string) $line->tax_rate_snapshot : null;

            if ($taxRateId !== null) {
                $tax = TaxRate::where('company_id', $company->id)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->find($taxRateId);
                $taxAccountId = app(PurchaseInputTaxAccount::class)->resolve((int) $company->id, $tax?->purchase_tax_account_id)?->id;
                $taxAccountsArray[(int) $line->line_number] = [
                    'tax_rate_id' => $taxRateId,
                    'tax_rate' => $tax?->rate !== null ? (string) $tax->rate : null,
                    'tax_account_id' => $taxAccountId,
                ];
            }

            $linesArray[] = [
                'line_number' => (int) $line->line_number,
                'product_id' => (int) $line->product_id,
                'product_unit_id' => (int) $line->product_unit_id,
                'item_description' => trim((string) $line->item_description),
                'quantity' => (string) $line->quantity,
                'quantity_base' => (string) $line->quantity_base,
                'unit_cost' => (string) $line->unit_cost,
                'unit_conversion_ratio' => (string) $line->unit_conversion_ratio,
                'discount_type' => $line->discount_type,
                'discount_value' => (string) $line->discount_value,
                'tax_rate_id' => $taxRateId,
                'tax_rate_snapshot' => $taxRateSnapshot,
                'tax_inclusive' => (bool) $line->tax_inclusive,
                'line_subtotal' => (string) $line->line_subtotal,
                'line_subtotal_base' => (string) $line->line_subtotal_base,
                'line_discount' => (string) $line->line_discount,
                'line_discount_base' => (string) $line->line_discount_base,
                'line_tax' => (string) $line->line_tax,
                'line_tax_base' => (string) $line->line_tax_base,
                'line_total' => (string) $line->line_total,
                'line_total_base' => (string) $line->line_total_base,
                'lots' => $lotsArray,
            ];
        }

        // Relevant landed cost allocations attached to this draft purchase
        $landedCosts = LandedCostAllocation::where('company_id', $locked->company_id)
            ->where('purchase_id', $locked->id)
            ->where('status', LandedCostAllocation::STATUS_DRAFT)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $landedCostsArray = [];
        foreach ($landedCosts as $alloc) {
            $landedCostsArray[] = [
                'purchase_line_id' => (int) $alloc->purchase_line_id,
                'expense_id' => (int) $alloc->expense_id,
                'allocation_method' => $alloc->allocation_method,
                'allocated_base' => (string) $alloc->allocated_base,
            ];
        }

        // Posting / Master-policy economic context
        $inventoryAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'inventory')->lockForUpdate()->first();
        $payableAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'accounts_payable')->lockForUpdate()->first();
        $clearingAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'landed_cost_clearing')->lockForUpdate()->first();

        return [
            'version' => 1,
            'header' => [
                'company_id' => (int) $locked->company_id,
                'purchase_id' => (int) $locked->id,
                'public_id' => (string) $locked->public_id,
                'vendor_id' => (int) $locked->vendor_id,
                'warehouse_id' => (int) $locked->warehouse_id,
                'purchase_date' => $locked->purchase_date->format('Y-m-d'),
                'due_date' => $locked->due_date?->format('Y-m-d'),
                'vendor_invoice_number' => $locked->vendor_invoice_number !== null ? trim($locked->vendor_invoice_number) : null,
                'currency_code' => (string) $locked->currency_code,
                'exchange_rate' => (string) $locked->exchange_rate,
                'document_locale' => (string) $locked->document_locale,
                'notes' => $locked->notes !== null ? trim($locked->notes) : null,
                'subtotal_currency' => (string) $locked->subtotal_currency,
                'subtotal_base' => (string) $locked->subtotal_base,
                'discount_total_currency' => (string) $locked->discount_total_currency,
                'discount_total_base' => (string) $locked->discount_total_base,
                'tax_total_currency' => (string) $locked->tax_total_currency,
                'tax_total_base' => (string) $locked->tax_total_base,
                'grand_total_currency' => (string) $locked->grand_total_currency,
                'grand_total_base' => (string) $locked->grand_total_base,
            ],
            'lines' => $linesArray,
            'landed_costs' => $landedCostsArray,
            'posting_context' => [
                'base_currency_code' => $company->base_currency_code,
                'inventory_account_id' => $inventoryAccount?->id,
                'payable_account_id' => $payableAccount?->id,
                'clearing_account_id' => $clearingAccount?->id,
                'tax_accounts' => $taxAccountsArray,
            ],
        ];
    }

    /**
     * Compute deterministic sha256 digest of the canonical snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function digest(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Verify whether the current locked draft purchase matches the expected digest.
     */
    public function verify(Purchase $locked, string $expectedDigest): bool
    {
        $currentSnapshot = $this->capture($locked);
        $currentDigest = $this->digest($currentSnapshot);

        return hash_equals($expectedDigest, $currentDigest);
    }
}
