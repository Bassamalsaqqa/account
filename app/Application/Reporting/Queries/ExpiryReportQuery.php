<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\InventoryReportHelper;
use App\Application\Reporting\Support\InventoryReportRead;
use App\Application\Reporting\Support\OperationalReportRead as Read;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ExpiryReportQuery
{
    public function __construct(private ReportingGuard $guard) {}

    /** @param ReportFilters|array<string,mixed> $filters */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $company = $this->guard->company($company, $actor);
        $user = $this->guard->authorize($company, $actor, ['reports.inventory.view', 'inventory.stock.view']);
        $f = Read::filters($company, $filters, ['product_id', 'warehouse_id', 'category_id', 'status'], ['status' => ['expired', 'soon', 'valid', 'unknown']]);
        InventoryReportHelper::validateFilters($company, $f);

        $q = InventoryReportRead::movements($company, $f, true)
            ->join('inventory_lots as lot', 'lot.id', '=', 'sm.lot_id')->where('lot.company_id', $company->id)
            ->whereColumn('lot.product_id', 'sm.product_id')
            ->select('lot.id as lot_id', 'lot.lot_number', 'lot.expiry_date', 'p.id as product_id', 'p.sku', 'p.name_ar', 'p.name_en', 'u.code as unit_code',
                'sm.warehouse_id', 'w.name_ar as warehouse_name_ar', 'w.name_en as warehouse_name_en')
            ->groupBy('lot.id', 'lot.lot_number', 'lot.expiry_date', 'p.id', 'p.sku', 'p.name_ar', 'p.name_en', 'u.code', 'sm.warehouse_id', 'w.name_ar', 'w.name_en')
            ->selectRaw('SUM(sm.quantity_delta_base) AS remaining_quantity')->havingRaw('SUM(sm.quantity_delta_base)>0')
            ->selectRaw("CASE WHEN lot.expiry_date IS NULL THEN 'unknown' WHEN lot.expiry_date < ? THEN 'expired'
                WHEN lot.expiry_date > ? AND lot.expiry_date <= DATE_ADD(?, INTERVAL 30 DAY) THEN 'soon' ELSE 'valid' END AS expiry_status",
                [$f->period->endDate, $f->period->endDate, $f->period->endDate]);
        $scope = DB::query()->fromSub($q, 'lot_positions');
        if ($f->status !== null) {
            $scope->where('expiry_status', $f->status);
        }
        $totals = ['expired_count' => 0, 'soon_count' => 0, 'valid_count' => 0, 'unknown_count' => 0];
        foreach ((clone $scope)->selectRaw('expiry_status,COUNT(*) AS total')->groupBy('expiry_status')->get() as $r) {
            $totals[$r->expiry_status.'_count'] = (int) $r->total;
        }
        $totals['total_active_lots'] = (clone $scope)->count();

        return Read::result('inventory.expiry', $company, $f, $scope->orderBy('expiry_date')->orderBy('lot_id')->orderBy('warehouse_id'), $totals,
            static fn (object $r): array => InventoryReportRead::identity($r) + [
                'lot_id' => (int) $r->lot_id, 'lot_number' => (string) $r->lot_number, 'expiry_date' => $r->expiry_date === null ? null : (string) $r->expiry_date,
                'expiry_status' => (string) $r->expiry_status, 'remaining_quantity' => Read::decimal((string) $r->remaining_quantity)],
            ['as_of_date' => $f->period->endDate]);

    }
}
