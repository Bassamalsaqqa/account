<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\Exceptions\ReportingException;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/** Lightweight, batched read-side ownership checks; never replays stock or GL economics. */
final class TradeProvenance
{
    public static function sales(Company $company): void
    {
        self::document($company, 'sales_invoices', 'sales_invoice', 'invoice_number', true);
        self::document($company, 'sales_returns', 'sales_return', 'return_number', true);
        self::lines($company, 'sales_invoice_lines', 'sales_invoices', 'sales_invoice_id');
        self::lines($company, 'sales_return_lines', 'sales_returns', 'sales_return_id');
    }

    public static function purchases(Company $company): void
    {
        if (DB::table('purchases')->where('company_id', $company->id)->whereNotIn('status', ['draft', 'posted'])->exists()
            || DB::table('purchases')->where('company_id', $company->id)->whereNotNull('void_posting_batch_id')->exists()) {
            throw new ReportingException('Unsupported Purchase void lifecycle.');
        }
        self::document($company, 'purchases', 'purchase', 'purchase_number', false);
        self::document($company, 'purchase_returns', 'purchase_return', 'return_number', false, true);
        self::lines($company, 'purchase_lines', 'purchases', 'purchase_id');
        self::lines($company, 'purchase_return_lines', 'purchase_returns', 'purchase_return_id');
    }

    private static function document(Company $company, string $table, string $source, string $number, bool $voidable, bool $zeroAllowed = false): void
    {
        $party = str_starts_with($table, 'sales_') ? 'customer' : 'vendor';
        $partyTable = $party === 'customer' ? 'customers' : 'vendors';
        $q = DB::table("$table as d")->leftJoin('posting_batches as b', 'b.id', '=', 'd.posting_batch_id')
            ->leftJoin($partyTable.' as party', 'party.id', '=', 'd.'.$party.'_id')
            ->where('d.company_id', $company->id)->whereIn('d.status', $voidable ? ['posted', 'void'] : ['posted']);
        $bad = (clone $q)->where(function ($q) use ($company, $source, $number, $zeroAllowed): void {
            $q->whereNull('party.id')->orWhere('party.company_id', '!=', $company->id)
                ->orWhereNull("d.$number")->orWhere("d.$number", '')->orWhereNull('d.posted_at')->orWhereNull('d.posted_by')
                ->orWhere(function ($q) use ($company, $source, $zeroAllowed): void {
                    if ($zeroAllowed) {
                        // Null GL is legitimate only for a commercial and inventory zero-effect return.
                        $q->whereNotNull('d.posting_batch_id');
                    }
                    $q->where(function ($q) use ($company, $source): void {
                        $q->whereNull('b.id')->orWhere('b.company_id', '!=', $company->id)
                            ->orWhere('b.source_type', '!=', $source)->orWhereColumn('b.source_id', '!=', 'd.id')
                            ->orWhereNotIn('b.status', ['posted', 'reversed'])->orWhereNotNull('b.reversal_of_id')
                            ->orWhereNull('b.posting_date');
                    });
                });
            if ($zeroAllowed) {
                $q->orWhere(function ($q): void {
                    $q->whereNull('d.posting_batch_id')->where(function ($q): void {
                        $q->where('d.grand_total_base', '!=', '0')->orWhereExists(function ($q): void {
                            $q->selectRaw('1')->from('purchase_return_lines as l')->whereColumn('l.purchase_return_id', 'd.id')
                                ->where('l.inventory_value_removed_base', '!=', '0');
                        });
                    });
                });
            }
        })->exists();
        if ($bad) {
            throw new ReportingException("Incoherent canonical $source history.");
        }
        if ($voidable) {
            $badVoid = (clone $q)->leftJoin('posting_batches as v', 'v.id', '=', 'd.void_posting_batch_id')->where('d.status', 'void')
                ->where(function ($q) use ($company): void {
                    $q->whereNull('v.id')->orWhere('v.company_id', '!=', $company->id)->orWhere('v.source_type', '!=', 'reversal')
                        ->orWhere('v.status', '!=', 'posted')->orWhereNull('v.posting_date')->orWhereNull('v.reversal_of_id')
                        ->orWhereColumn('v.reversal_of_id', '!=', 'd.posting_batch_id')
                        ->orWhereNull('b.reversed_by_batch_id')->orWhereColumn('b.reversed_by_batch_id', '!=', 'v.id');
                })->exists();
            if ($badVoid) {
                throw new ReportingException("Incoherent canonical $source void history.");
            }
        }
    }

    private static function lines(Company $company, string $table, string $documents, string $fk): void
    {
        if (DB::table("$table as l")->leftJoin("$documents as d", 'd.id', '=', "l.$fk")
            ->where('l.company_id', $company->id)->where(function ($q) use ($company): void {
                $q->whereNull('d.id')->orWhere('d.company_id', '!=', $company->id);
            })->exists()) {
            throw new ReportingException('Cross-company or orphan trade line.');
        }
    }
}
