<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Exceptions\ReportingException;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\InventoryReportHelper;
use App\Application\Reporting\Support\OperationalReportRead as Read;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class TransferHistoryReportQuery
{
    public function __construct(private ReportingGuard $guard) {}

    /** @param ReportFilters|array<string,mixed> $filters */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $company = $this->guard->company($company, $actor);
        $user = $this->guard->authorize($company, $actor, ['reports.inventory.view', 'inventory.stock.view']);
        $f = Read::filters($company, $filters, ['product_id', 'warehouse_id', 'category_id'], []);

        InventoryReportHelper::validateFilters($company, $f);
        $cost = $this->guard->canViewInventoryCost($user) && $this->guard->allows($company, 'reports.cost.view', $actor);
        $legs = DB::table('stock_movements as sm')->where('sm.company_id', $company->id)->whereIn('sm.movement_type', ['transfer_in', 'transfer_out'])
            ->whereBetween('sm.movement_date', [$f->period->startDate, $f->period->endDate])->select('sm.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY sm.inventory_operation_id,sm.product_id,sm.lot_id,sm.movement_type ORDER BY sm.id) AS pair_number');
        $q = DB::query()->fromSub($legs, 'out')->joinSub($legs, 'in', function (JoinClause $j): void {
            $j->on('in.inventory_operation_id', '=', 'out.inventory_operation_id')->on('in.product_id', '=', 'out.product_id')
                ->on('in.pair_number', '=', 'out.pair_number')->whereRaw('in.lot_id <=> out.lot_id')->where('in.movement_type', 'transfer_in');
        })->join('products as p', 'p.id', '=', 'out.product_id')->join('warehouses as source', 'source.id', '=', 'out.warehouse_id')
            ->join('warehouses as destination', 'destination.id', '=', 'in.warehouse_id')
            ->where('out.movement_type', 'transfer_out')->where('p.company_id', $company->id)->where('source.company_id', $company->id)->where('destination.company_id', $company->id);
        if ($f->productId !== null) {
            $q->where('out.product_id', $f->productId);
        }
        if ($f->categoryId !== null) {
            $q->where('p.category_id', $f->categoryId);
        }
        if ($f->warehouseId !== null) {
            $q->where(function (Builder $q) use ($f): void {
                $q->where('out.warehouse_id', $f->warehouseId)->orWhere('in.warehouse_id', $f->warehouseId);
            });
        }
        $r = (clone $q)->selectRaw('COALESCE(SUM(in.quantity_delta_base),0) AS quantity,COALESCE(SUM(in.quantity_delta_base+out.quantity_delta_base),0) AS net')->first();
        if ((clone $q)->whereRaw('(in.quantity_delta_base+out.quantity_delta_base<>0 OR in.value_delta_base+out.value_delta_base<>0)')->exists()) {
            throw new ReportingException('Incoherent stock transfer pair.');
        }
        $q->select('out.id', 'out.inventory_operation_id', 'out.movement_date', 'out.product_id', 'p.sku', 'p.name_ar', 'p.name_en',
            'out.warehouse_id as from_warehouse_id', 'in.warehouse_id as to_warehouse_id', 'source.name_ar as source_ar', 'source.name_en as source_en',
            'destination.name_ar as destination_ar', 'destination.name_en as destination_en', 'in.quantity_delta_base as quantity')
            ->selectRaw($cost ? 'out.unit_cost_base' : 'NULL AS unit_cost_base');
        $scope = DB::query()->fromSub($q, 'transfer_history');

        return Read::result('inventory.transfers', $company, $f, $scope->orderBy('movement_date')->orderBy('id'), [
            'total_transferred_quantity' => Read::decimal((string) $r->quantity), 'company_net_quantity_delta' => Read::decimal((string) $r->net),
            'transfer_count' => (clone $scope)->count()], static fn (object $r): array => [
                'operation_id' => (int) $r->inventory_operation_id, 'transfer_date' => (string) $r->movement_date, 'product_id' => (int) $r->product_id,
                'sku' => (string) $r->sku, 'product_name' => Read::name($r->name_ar, $r->name_en), 'from_warehouse_id' => (int) $r->from_warehouse_id,
                'from_warehouse_name' => Read::name($r->source_ar, $r->source_en), 'to_warehouse_id' => (int) $r->to_warehouse_id,
                'to_warehouse_name' => Read::name($r->destination_ar, $r->destination_en), 'quantity_transferred' => Read::decimal((string) $r->quantity),
                'unit_cost_base' => $cost ? Read::decimal((string) $r->unit_cost_base) : null], ['conserves_company_totals' => true, 'cost_redacted' => ! $cost]);

    }
}
