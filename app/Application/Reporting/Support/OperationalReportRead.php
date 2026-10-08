<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Exceptions\ReportingException;
use App\Models\Company;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** SQL-only read plumbing shared by operational report families. No canonical writes. */
final class OperationalReportRead
{
    /** @param ReportFilters|array<string,mixed> $input
     * @param  list<string>  $allowed
     * @param  array<string,list<string>>  $choices
     */
    public static function filters(Company $company, ReportFilters|array $input, array $allowed, array $choices = []): ReportFilters
    {
        $f = ReportFilters::fromArray($company, $input instanceof ReportFilters ? $input->toArray() : $input);
        foreach ($f->toArray() as $field => $value) {
            if (in_array($field, ['period', 'page', 'per_page'], true) || $value === null) {
                continue;
            }
            if (! in_array($field, $allowed, true)) {
                throw new InvalidReportFilterException("Unsupported operational report filter [$field].");
            }
            if (isset($choices[$field]) && ! in_array($value, $choices[$field], true)) {
                throw new InvalidReportFilterException("Unsupported operational report value [$field].");
            }
        }

        return $f;
    }

    public static function decimal(string|int|null $value): string
    {
        return (string) BigDecimal::of((string) ($value ?? '0'))->toScale(6);
    }

    /** @return array<string,mixed> */
    public static function snapshot(?string $json): array
    {
        $value = $json === null ? null : json_decode($json, true);

        return is_array($value) ? $value : [];
    }

    public static function name(?string $ar, ?string $en): string
    {
        $names = app()->getLocale() === 'en' ? [$en, $ar] : [$ar, $en];
        foreach ($names as $name) {
            if ($name !== null && trim($name) !== '') {
                return $name;
            }
        }

        return (string) __('money.unavailable');
    }

    /** @return array<string,string> */
    public static function currencyTotals(Builder $query, string $amount = 'amount'): array
    {
        $result = [];
        foreach ((clone $query)->reorder()->selectRaw("currency_code, COALESCE(SUM($amount),0) AS total")
            ->groupBy('currency_code')->get() as $row) {
            $result[(string) $row->currency_code] = self::decimal((string) $row->total);
        }

        return $result;
    }

    /** @param array<string,mixed> $totals @param callable(object):array<string,mixed> $map
     * @param  array<string,mixed>  $meta
     */
    public static function result(string $type, Company $company, ReportFilters $f, Builder $query, array $totals, callable $map, array $meta = []): ReportResult
    {
        $count = (clone $query)->reorder()->count();
        $rows = [];
        foreach ((clone $query)->forPage($f->page, $f->perPage)->get() as $row) {
            $rows[] = $map($row);
        }

        return new ReportResult($type, $f->toArray(), $totals, $rows,
            ['base_currency_code' => $company->base_currency_code],
            ['current_page' => $f->page, 'per_page' => $f->perPage, 'total' => $count,
                'last_page' => max(1, intdiv(max(0, $count - 1), $f->perPage) + 1)],
            Carbon::now($company->timezone)->toIso8601String(), $meta);
    }

    /** A batched lightweight original/inverse provenance check, not economic replay.
     * @param  array<string,string>  $columns
     */
    public static function eventLegs(Builder $sources, Company $company, string $type, string $dateColumn, array $columns): Builder
    {
        $joined = (clone $sources)->leftJoin('posting_batches as original', 'original.id', '=', 's.posting_batch_id')
            ->leftJoin('posting_batches as inverse', 'inverse.id', '=', 's.reversal_posting_batch_id');
        $invalid = (clone $joined)->where(function (Builder $q) use ($company, $type, $dateColumn): void {
            $q->where(function (Builder $original) use ($company, $type, $dateColumn): void {
                $original->whereNotNull('s.posting_batch_id')->where(function (Builder $bad) use ($company, $type, $dateColumn): void {
                    $bad->whereNull('original.id')->orWhere('original.company_id', '<>', $company->id)
                        ->orWhere('original.source_type', '<>', $type)->orWhereColumn('original.source_id', '<>', 's.id')
                        ->orWhereColumn('original.posting_date', '<>', 's.'.$dateColumn)
                        ->orWhereNotIn('original.status', ['posted', 'reversed']);
                });
            })->orWhere(function (Builder $reversed) use ($company): void {
                $reversed->whereNotNull('s.reversal_posting_batch_id')->where(function (Builder $bad) use ($company): void {
                    $bad->whereNull('inverse.id')->orWhere('inverse.company_id', '<>', $company->id)
                        ->orWhere('inverse.source_type', '<>', 'reversal')->orWhere('inverse.status', '<>', 'posted')
                        ->orWhereNull('inverse.reversal_of_id')->orWhereColumn('inverse.reversal_of_id', '<>', 's.posting_batch_id')
                        ->orWhereNull('original.reversed_by_batch_id')->orWhereColumn('original.reversed_by_batch_id', '<>', 'inverse.id')
                        ->orWhereColumn('inverse.posting_date', '<', 'original.posting_date');
                });
            });
        });
        if ($invalid->exists()) {
            throw new ReportingException('Incoherent canonical operational event provenance.');
        }
        // Nonzero events require a batch, and financial reversal requires its exact inverse.
        $flag = in_array($type, ['customer_payment', 'vendor_payment', 'money_transfer'], true) ? 's.is_reversed = 1' : "s.status = 'reversed'";
        $nonzero = $type === 'salary_entry' ? '(s.base_earned_salary <> 0 OR s.base_advance_relief <> 0 OR s.base_payable <> 0 OR s.realized_fx_gain_loss_base <> 0)' : '1=1';
        if ((clone $joined)->whereRaw("($nonzero) AND (s.posting_batch_id IS NULL OR (($flag) AND s.reversal_posting_batch_id IS NULL))")->exists()) {
            throw new ReportingException('Missing canonical operational event batch.');
        }
        $selects = ['s.id', 's.public_id'];
        foreach ($columns as $alias => $column) {
            $selects[] = 's.'.$column.' as '.$alias;
        }
        $a = (clone $joined)->select($selects)->selectRaw('s.'.$dateColumn.' AS date, 0 AS is_reversal');
        $b = (clone $joined)->whereNotNull('s.reversal_posting_batch_id')->select($selects)
            ->selectRaw('inverse.posting_date AS date, 1 AS is_reversal');

        return DB::query()->fromSub($a->unionAll($b), 'legs');
    }
}
