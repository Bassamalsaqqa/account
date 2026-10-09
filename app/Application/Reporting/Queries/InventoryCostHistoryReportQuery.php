<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Security\ReportPermissionCatalog;
use App\Application\Reporting\Support\InventoryReportHelper;
use App\Application\Reporting\Support\InventoryReportRead;
use App\Application\Reporting\Support\OperationalReportRead as Read;
use App\Models\Company;
use App\Models\User;

final class InventoryCostHistoryReportQuery
{
    public function __construct(private ReportingGuard $guard) {}

    /** @param ReportFilters|array<string,mixed> $filters */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $company = $this->guard->company($company, $actor);
        $user = $this->guard->authorize($company, $actor, ReportPermissionCatalog::INVENTORY_VALUATION);
        $f = Read::filters($company, $filters, ['product_id', 'warehouse_id', 'category_id', 'status'], ['status' => ['opening_balance', 'purchase', 'purchase_return', 'sale', 'sale_return', 'transfer_in', 'transfer_out', 'adjustment_increase', 'adjustment_decrease', 'damage', 'loss', 'damage_or_loss', 'expiry_disposal', 'adjustment']]);
        InventoryReportHelper::validateFilters($company, $f);
        $cost = $this->guard->allows($company, ReportPermissionCatalog::INVENTORY_VALUATION, $actor);
        $q = InventoryReportRead::movements($company, $f);
        if ($f->status === 'adjustment') {
            $q->whereIn('sm.movement_type', ['adjustment_increase', 'adjustment_decrease', 'damage', 'loss', 'damage_or_loss']);
        } elseif ($f->status !== null) {
            $q->where('sm.movement_type', $f->status);
        }
        $r = (clone $q)->selectRaw('COALESCE(SUM(CASE WHEN sm.quantity_delta_base>0 THEN sm.quantity_delta_base ELSE 0 END),0) AS inbound,
            COALESCE(SUM(CASE WHEN sm.quantity_delta_base<0 THEN -sm.quantity_delta_base ELSE 0 END),0) AS outbound,
            COALESCE(SUM(sm.quantity_delta_base),0) AS net')->first();
        $totals = ['total_inbound' => Read::decimal((string) $r->inbound), 'total_outbound' => Read::decimal((string) $r->outbound),
            'net_quantity_delta' => Read::decimal((string) $r->net), 'movement_count' => (clone $q)->count()];
        if ($cost) {
            $totals['total_value_delta'] = Read::decimal((string) (clone $q)->sum('sm.value_delta_base'));
        }
        $q->select('sm.id as movement_id', 'sm.public_id', 'sm.movement_date', 'sm.movement_type', 'sm.quantity_delta_base', 'sm.source_type', 'sm.source_id', 'sm.reason',
            'p.id as product_id', 'p.sku', 'p.name_ar', 'p.name_en', 'u.code as unit_code', 'sm.warehouse_id', 'w.name_ar as warehouse_name_ar', 'w.name_en as warehouse_name_en');
        $q->selectRaw($cost ? 'sm.unit_cost_base,sm.value_delta_base,sm.average_cost_after' : 'NULL AS unit_cost_base,NULL AS value_delta_base,NULL AS average_cost_after');

        return Read::result('inventory.cost_history', $company, $f, $q->orderBy('sm.movement_date')->orderBy('sm.id'), $totals,
            static function (object $r) use ($cost): array {
                return InventoryReportRead::identity($r) + [
                    'movement_id' => (int) $r->movement_id, 'public_id' => (string) $r->public_id, 'movement_date' => (string) $r->movement_date,
                    'movement_type' => (string) $r->movement_type, 'quantity_delta_base' => Read::decimal((string) $r->quantity_delta_base),
                    'unit_cost_base' => $cost && $r->unit_cost_base !== null ? Read::decimal((string) $r->unit_cost_base) : null,
                    'value_delta_base' => $cost ? Read::decimal((string) $r->value_delta_base) : null,
                    'average_cost_after' => $cost && $r->average_cost_after !== null ? Read::decimal((string) $r->average_cost_after) : null,
                    'source_type' => (string) $r->source_type, 'source_id' => $r->source_id === null ? null : (int) $r->source_id, 'reason' => $r->reason === null ? null : (string) $r->reason];
            }, ['cost_redacted' => ! $cost, 'identity_semantics' => 'current_product_identity']);

    }
}
