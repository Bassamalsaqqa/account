<?php

declare(strict_types=1);

namespace App\Application\Reporting\Presentation;

use App\Application\Reporting\Security\ReportingGuard;
use App\Models\Company;

/** Explicit source capabilities shared by discovery, Livewire and CSV schemas. */
final class ReportPresentationPolicy
{
    public function salesCost(Company $company): bool
    {
        return app(ReportingGuard::class)->allows($company, ['reports.cost.view', 'reports.profit.view', 'inventory.cost.view']);
    }

    /** @param array<string,mixed> $definition
     * @return array<string,mixed> */
    public function definition(Company $company, array $definition): array
    {
        $guard = app(ReportingGuard::class);
        $guard->authorize($company);
        if (isset($definition['options']['sort']) && ! $this->salesCost($company)) {
            $definition['options']['sort'] = array_values(array_diff($definition['options']['sort'], ['profit_desc']));
        }
        if ($definition['group'] === 'expenses' && ! $guard->allows($company, ['money.expense.view', 'purchasing.cost.view'])) {
            $definition['options']['status'] = array_values(array_diff($definition['options']['status'] ?? [], ['landed_cost']));
        }
        $definition['columns'] = $this->columns($company, $definition['columns'], (string) $definition['query']);

        return $definition;
    }

    /** @param list<array{key:string,type:string}> $columns
     * @return list<array{key:string,type:string}> */
    public function columns(Company $company, array $columns, string $reportType): array
    {
        $guard = app(ReportingGuard::class);
        $guard->authorize($company);
        $sales = str_starts_with($reportType, 'sales.') || str_contains($reportType, 'Sales');
        $inventory = str_starts_with($reportType, 'inventory.') || str_contains($reportType, 'Inventory') || str_contains($reportType, 'Stock') || str_contains($reportType, 'TransferHistory');
        $allowed = null;

        return array_values(array_filter($columns, function (array $column) use ($company, $guard, $sales, $inventory, $reportType, &$allowed): bool {
            // Supplier spend is purchasing cost even though this report lives under Inventory.
            if ($column['key'] === 'total_spend_base' && (in_array($reportType, ['inventory.vendor_products', 'inventory.vendor-products'], true) || str_contains($reportType, 'InventoryVendorProductsReportQuery'))) {
                return $guard->allows($company, ['purchasing.cost.view', 'reports.cost.view']);
            }
            if (! in_array($column['key'], [
                'cogs_base', 'cogs_total_base', 'gross_profit_base', 'gross_margin', 'margin_percent',
                'profit_base', 'unit_cost_base', 'average_unit_cost_base', 'valuation_base', 'value_base',
                'cost_base', 'total_cost_base', 'inventory_unit_cost_base', 'landed_cost_allocated_base', 'total_acquisition_cost_base', 'average_cost_after', 'value_delta_base',
            ], true)) {
                return true;
            }
            $allowed ??= $sales ? $this->salesCost($company) : ($inventory
                ? $guard->allows($company, ['inventory.cost.view', 'reports.cost.view'])
                : $guard->allows($company, 'purchasing.cost.view'));

            return $allowed;
        }));
    }
}
