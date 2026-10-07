<?php

declare(strict_types=1);

namespace App\Domain\Money\Queries;

use App\Services\Money\MoneyActorGuard;
use App\Services\Purchasing\VendorFinancialRead;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** Source visibility selector used after the caller's Cash/Bank authorization. */
final class MoneyMovementVisibility
{
    public function visibleBatchIds(int $companyId): Builder
    {
        $canVendor = app(VendorFinancialRead::class)->allows($companyId);
        $canOutgoing = $this->allowed($companyId, 'money.check.view')
            && $this->allowed($companyId, 'purchasing.cost.view');
        // Inverses inherit the original economic domain, never the generic reversal label.
        $type = "CASE WHEN vb.source_type = 'reversal' THEN original.source_type ELSE vb.source_type END";
        $sourceId = "CASE WHEN vb.source_type = 'reversal' THEN original.source_id ELSE vb.source_id END";

        return DB::table('posting_batches as vb')
            ->leftJoin('posting_batches as original', function (JoinClause $join) use ($companyId): void {
                $join->on('original.id', '=', 'vb.reversal_of_id')
                    ->where('vb.source_type', 'reversal')->where('original.company_id', $companyId);
            })
            ->leftJoin('check_events as event', function (JoinClause $join) use ($companyId, $type, $sourceId): void {
                $join->on('event.id', '=', DB::raw($sourceId))
                    ->whereRaw("($type) = ?", ['check_event'])->where('event.company_id', $companyId);
            })
            ->leftJoin('checks as instrument', function (JoinClause $join) use ($companyId): void {
                $join->on('instrument.id', '=', 'event.check_id')->where('instrument.company_id', $companyId);
            })
            ->where('vb.company_id', $companyId)
            ->where(function (Builder $query) use ($type, $canVendor, $canOutgoing): void {
                $query->whereNotIn(DB::raw($type), ['vendor_payment', 'check_event']);
                if ($canVendor) {
                    $query->orWhereRaw("($type) = ?", ['vendor_payment']);
                }
                $query->orWhere(function (Builder $checks) use ($type, $canOutgoing): void {
                    $checks->whereRaw("($type) = ?", ['check_event'])
                        ->whereIn('instrument.direction', $canOutgoing ? ['incoming', 'outgoing'] : ['incoming']);
                });
            })
            ->select('vb.id');
    }

    private function allowed(int $companyId, string $permission): bool
    {
        try {
            app(MoneyActorGuard::class)->authorize($companyId, $permission);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}
