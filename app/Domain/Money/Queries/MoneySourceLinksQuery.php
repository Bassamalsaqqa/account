<?php

declare(strict_types=1);

namespace App\Domain\Money\Queries;

use App\Services\Money\MoneyActorGuard;
use App\Services\Phase7\Phase7FinancialRead;
use App\Services\Purchasing\VendorFinancialRead;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Bounded batched navigation; a Money permission never grants access to the source document. */
final class MoneySourceLinksQuery
{
    /** @param iterable<\stdClass> $rows
     * @return array<int, string>
     */
    public function forRows(int $companyId, iterable $rows): array
    {
        $rows = collect($rows);
        $originals = DB::table('posting_batches')->where('company_id', $companyId)->whereIn('id', $rows->pluck('reversal_of_id')->filter())->get()->keyBy('id');
        $sources = [];
        foreach ($rows as $row) {
            $source = $row->source_type === 'reversal' ? $originals->get($row->reversal_of_id) : $row;
            if ($source !== null) {
                $sources[$source->source_type][(int) $row->id] = (int) $source->source_id;
            }
        }
        $links = [];
        foreach (['customer_payment' => ['customer_payments', 'money.receipt.view', 'payments.show'],
            'vendor_payment' => ['vendor_payments', 'vendors.statement.view', 'vendor-payments.show'],
            'expense' => ['expenses', 'money.expense.view', 'expenses.show'],
            'money_transfer' => ['money_transfers', 'money.transfer.view', 'money.transfers.show'],
            'check_event' => ['check_events', 'money.check.view', 'money.checks.show']] as $type => [$table, $permission, $route]) {
            $ids = $sources[$type] ?? [];
            if ($ids === []) {
                continue;
            }
            $allowed = $this->allowed($companyId, $permission);
            if ($type === 'vendor_payment') {
                $allowed = app(VendorFinancialRead::class)->allows($companyId);
            }
            if (! $allowed) {
                continue;
            }
            $query = DB::table($table)->where($table.'.company_id', $companyId)->whereIn($table.'.id', array_values($ids));
            if ($type === 'expense') {
                $query->whereIn('expenses.id', app(Phase7FinancialRead::class)->visibleExpenseIds($companyId));
            }
            if ($type === 'check_event') {
                $query->join('checks', 'checks.id', '=', 'check_events.check_id')->where('checks.company_id', $companyId)
                    ->whereIn('checks.id', app(Phase7FinancialRead::class)->visibleCheckIds($companyId));
                $found = $query->pluck('checks.public_id', 'check_events.id');
            } else {
                $found = $query->pluck('public_id', 'id');
            }
            foreach ($ids as $rowId => $sourceId) {
                if ($found->has($sourceId)) {
                    $links[$rowId] = route($route, $found->get($sourceId));
                }
            }
        }

        return $links;
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
