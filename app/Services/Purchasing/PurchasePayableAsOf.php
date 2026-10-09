<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\VendorPaymentAllocation;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Read-only business-date positions. This does not authorize new allocations. */
final class PurchasePayableAsOf
{
    /**
     * @param  iterable<Purchase>  $purchases
     * @return array<int, BigDecimal> Positive outstanding at company-local end of day.
     */
    public function forPurchases(iterable $purchases, Carbon $asOf): array
    {
        return $this->positions($purchases, $asOf, true);
    }

    /**
     * Bounded interactive read using canonical source/batch metadata. Deep
     * economic replay remains authoritative in forPurchases(), reconciliation
     * and posting retries; it is not repeated for every reporting row.
     *
     * @param  iterable<Purchase>  $purchases
     * @return array<int, BigDecimal>
     */
    public function forHistory(iterable $purchases, Carbon $asOf): array
    {
        return $this->positions($purchases, $asOf, false);
    }

    /** @param iterable<Purchase> $purchases
     * @return array<int, BigDecimal> */
    private function positions(iterable $purchases, Carbon $asOf, bool $deep): array
    {
        $purchases = collect($purchases);
        if ($purchases->isEmpty()) {
            return [];
        }

        if (! $deep && $purchases->count() > 500) {
            throw new InvalidArgumentException('Historical payables must be read in batches of at most 500 purchases.');
        }
        $companyId = (int) $purchases->first()->company_id;
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== $companyId) {
            throw new InvalidArgumentException('Historical payable positions require matching company context.');
        }
        $date = $asOf->toDateString();
        // Reversal batches persist the Company-local business date at execution.
        // Do not reinterpret historical reversal instants in today's timezone.
        $result = [];
        foreach ($purchases as $purchase) {
            if ((int) $purchase->company_id !== $companyId || ! $purchase->isPosted()
                || $purchase->purchase_date->format('Y-m-d') > $date) {
                throw new InvalidArgumentException('Purchase is outside the historical company/date boundary.');
            }
            if ($deep) {
                app(PurchasePostingCommandBuilder::class)->validatePosted($purchase);
            }
            $result[(int) $purchase->id] = BigDecimal::of($purchase->grand_total_currency);
        }
        $ids = array_keys($result);
        if (! $deep) {
            $this->assertReadProvenance($companyId, $ids, $date);
        }
        if ($deep) {
            $returns = PurchaseReturn::where('company_id', $companyId)->whereIn('purchase_id', $ids)
                ->where('status', Purchase::STATUS_POSTED)->where('return_date', '<=', $date)->get();
            foreach ($returns as $return) {
                app(PurchaseReturnPostingCommandBuilder::class)->validatePosted($return);
                $id = (int) $return->purchase_id;
                $result[$id] = $result[$id]->minus($return->grand_total_currency);
            }
        } else {
            $returns = DB::table('purchase_returns')
                ->where('company_id', $companyId)->whereIn('purchase_id', $ids)
                ->where('status', Purchase::STATUS_POSTED)->where('return_date', '<=', $date)
                ->selectRaw('purchase_id, SUM(grand_total_currency) AS relief')->groupBy('purchase_id')->get();
            foreach ($returns as $return) {
                $id = (int) $return->purchase_id;
                $result[$id] = $result[$id]->minus((string) $return->relief);
            }
        }
        $allocations = VendorPaymentAllocation::query()
            ->join('vendor_payments as payment', 'payment.id', '=', 'vendor_payment_allocations.vendor_payment_id')
            ->leftJoin('posting_batches as payment_reversal', function ($join) use ($companyId): void {
                $join->on('payment_reversal.id', '=', 'payment.reversal_posting_batch_id')->where('payment_reversal.company_id', $companyId);
            })
            ->leftJoin('vendor_payment_application_events as event', 'event.id', '=', 'vendor_payment_allocations.application_event_id')
            ->where('vendor_payment_allocations.company_id', $companyId)
            ->where('payment.company_id', $companyId)
            ->whereIn('vendor_payment_allocations.purchase_id', $ids)
            ->whereNotNull('payment.posted_at')->whereNotNull('payment.posting_batch_id')
            ->where('payment.payment_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('payment.reversed_at')->orWhere('payment_reversal.posting_date', '>', $date))
            ->where(function ($q) use ($companyId, $date): void {
                $q->whereNull('vendor_payment_allocations.application_event_id')
                    ->orWhere(function ($event) use ($companyId, $date): void {
                        $event->where('event.company_id', $companyId)
                            ->whereColumn('event.vendor_payment_id', 'payment.id')
                            ->whereNotNull('event.applied_at')->where('event.application_date', '<=', $date)
                            ->where(fn ($rev) => $rev->whereNull('event.reversed_at')->orWhere('payment_reversal.posting_date', '>', $date));
                    });
            })
            ->selectRaw('vendor_payment_allocations.purchase_id, SUM(vendor_payment_allocations.allocated_amount) as relief')
            ->groupBy('vendor_payment_allocations.purchase_id')->get();
        foreach ($allocations as $row) {
            $id = (int) $row->purchase_id;
            $result[$id] = $result[$id]->minus((string) $row->getAttribute('relief'));
        }
        foreach ($result as $id => $position) {
            $result[$id] = ($position->isPositive() ? $position : BigDecimal::zero())->toScale(6);
        }

        return $result;
    }

    /** @param list<int> $ids */
    private function assertReadProvenance(int $companyId, array $ids, string $date): void
    {
        $purchases = DB::table('purchases as p')
            ->join('posting_batches as b', 'b.id', '=', 'p.posting_batch_id')
            ->whereIn('p.id', $ids)->where('p.company_id', $companyId)
            ->where('p.status', 'posted')->whereNotNull('p.purchase_number')->where('p.purchase_number', '!=', '')
            ->whereNotNull('p.posted_at')->whereNotNull('p.posted_by')
            ->where('b.company_id', $companyId)->where('b.source_type', 'purchase')
            ->whereColumn('b.source_id', 'p.id')->where('b.status', 'posted')
            ->whereNull('b.reversal_of_id')->whereColumn('b.posting_date', 'p.purchase_date')->count();
        if ($purchases !== count($ids)) {
            throw new InvalidArgumentException('Incoherent historical Purchase posting provenance.');
        }
        $invalidReturn = DB::table('purchase_returns as r')
            ->leftJoin('posting_batches as b', 'b.id', '=', 'r.posting_batch_id')
            ->where('r.company_id', $companyId)->whereIn('r.purchase_id', $ids)
            ->where('r.status', 'posted')->where('r.return_date', '<=', $date)
            ->where(function ($q) use ($companyId): void {
                $q->whereNull('r.return_number')->orWhere('r.return_number', '')->orWhereNull('r.posted_at')->orWhereNull('r.posted_by')
                    ->orWhere(function ($q) use ($companyId): void {
                        $q->whereNotNull('r.posting_batch_id')->where(function ($q) use ($companyId): void {
                            $q->whereNull('b.id')->orWhere('b.company_id', '!=', $companyId)
                                ->orWhere('b.source_type', '!=', 'purchase_return')->orWhereColumn('b.source_id', '!=', 'r.id')
                                ->orWhere('b.status', '!=', 'posted')->orWhereNotNull('b.reversal_of_id')
                                ->orWhereColumn('b.posting_date', '!=', 'r.return_date');
                        });
                    })->orWhere(function ($q): void {
                        $q->whereNull('r.posting_batch_id')->where(function ($q): void {
                            $q->where('r.grand_total_base', '!=', '0')->orWhereExists(function ($q): void {
                                $q->selectRaw('1')->from('purchase_return_lines as line')->whereColumn('line.purchase_return_id', 'r.id')
                                    ->where('line.inventory_value_removed_base', '!=', '0');
                            });
                        });
                    });
            })->exists();
        if ($invalidReturn) {
            throw new InvalidArgumentException('Incoherent historical Purchase Return posting provenance.');
        }
    }
}
