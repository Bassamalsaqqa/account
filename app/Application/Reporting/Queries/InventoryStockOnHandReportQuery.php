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

final class InventoryStockOnHandReportQuery
{
    public function __construct(private ReportingGuard $guard) {}

    /** @param ReportFilters|array<string,mixed> $filters */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $company = $this->guard->company($company, $actor);
        $user = $this->guard->authorize($company, $actor, ['reports.inventory.view', 'inventory.stock.view']);
        $f = Read::filters($company, $filters, ['product_id', 'warehouse_id', 'category_id', 'grouping'], ['grouping' => ['product', 'warehouse']]);
        InventoryReportHelper::validateFilters($company, $f);
        $cost = $this->guard->canViewInventoryCost($user) && $this->guard->allows($company, 'reports.cost.view', $actor);
        $q = InventoryReportRead::positions($company, $f, $cost);
        $totals = ['total_quantity' => Read::decimal((string) (clone $q)->sum('on_hand_base')), 'products_count' => (clone $q)->count(),
            'in_stock_count' => (clone $q)->where('on_hand_base', '>', 0)->count()];
        if ($cost) {
            $totals['total_valuation'] = Read::decimal((string) (clone $q)->sum('value_base'));
        }

        return Read::result('inventory.stock_on_hand', $company, $f, $q->orderBy('sku')->orderBy('product_id')->orderBy('warehouse_id'), $totals,
            static function (object $r) use ($cost): array {
                $row = InventoryReportRead::identity($r);
                $row['quantity_on_hand'] = Read::decimal((string) $r->on_hand_base);
                $row['value_base'] = $cost ? Read::decimal((string) $r->value_base) : null;
                $row['average_unit_cost_base'] = $cost ? InventoryReportHelper::calculateAverageCost((string) $r->value_base, (string) $r->on_hand_base) : null;

                return $row;
            }, ['as_of_date' => $f->period->endDate, 'cost_redacted' => ! $cost, 'identity_semantics' => 'current_product_identity']);

    }
}
