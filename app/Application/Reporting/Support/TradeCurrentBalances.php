<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\DTO\ReportFilters;
use App\Domain\Purchasing\Queries\VendorBalanceQuery;
use App\Domain\Sales\Queries\CustomerBalanceQuery;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Vendor;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;

/** Bounded master batches delegate all current position economics to the accepted engines. */
final class TradeCurrentBalances
{
    /** @return array{rows: list<array<string,mixed>>, totals: array<string,mixed>, count: int} */
    public static function read(Company $company, ReportFilters $filters, bool $vendor): array
    {
        $query = ($vendor ? Vendor::withoutGlobalScopes() : Customer::withoutGlobalScopes())->where('company_id', $company->id);
        $id = $vendor ? $filters->vendorId : $filters->customerId;
        if ($id !== null) {
            $query->whereKey($id);
        }
        $query->orderBy($filters->sort === 'code_asc' ? 'code' : 'name_ar')->orderBy('id');
        $rows = [];
        $totals = [];
        $count = 0;
        $parties = 0;
        $offset = ($filters->page - 1) * $filters->perPage;
        $query->chunk(250, function (Collection $masters) use ($vendor, $company, $filters, $offset, &$rows, &$totals, &$count, &$parties): void {
            $ids = array_map('intval', $masters->modelKeys());
            $balances = $vendor ? app(VendorBalanceQuery::class)->execute($ids) : app(CustomerBalanceQuery::class)->execute($ids);
            foreach ($masters as $master) {
                $perCurrency = $balances[(int) $master->id] ?? [];
                if ($perCurrency === [] && $filters->currencyCode === null) {
                    $perCurrency[(string) $company->base_currency_code] = $vendor
                        ? ['purchased' => '0', 'returned' => '0', 'paid' => '0', 'balance' => '0'] : ['invoiced' => '0', 'outstanding' => '0'];
                }
                ksort($perCurrency);
                $matched = false;
                foreach ($perCurrency as $currency => $balance) {
                    if ($filters->currencyCode !== null && $currency !== $filters->currencyCode) {
                        continue;
                    }
                    $matched = true;
                    $position = (string) BigDecimal::of($balance[$vendor ? 'balance' : 'outstanding'])->toScale(6);
                    $totals[$currency] = ($totals[$currency] ?? BigDecimal::zero())->plus($position);
                    $row = [($vendor ? 'vendor_id' : 'customer_id') => (int) $master->id,
                        ($vendor ? 'vendor_code' : 'customer_code') => (string) ($master->code ?? ''),
                        ($vendor ? 'vendor_name_ar' : 'customer_name_ar') => (string) $master->name_ar,
                        ($vendor ? 'vendor_name_en' : 'customer_name_en') => (string) ($master->name_en ?? ''),
                        'currency_code' => $currency];
                    if ($vendor) {
                        $row += ['purchased_amount' => (string) BigDecimal::of($balance['purchased'])->toScale(6),
                            'returned_amount' => (string) BigDecimal::of($balance['returned'])->toScale(6),
                            'paid_amount' => (string) BigDecimal::of($balance['paid'])->toScale(6), 'balance' => $position];
                    } else {
                        $row += ['invoiced_amount' => (string) BigDecimal::of($balance['invoiced'])->toScale(6), 'outstanding_balance' => $position];
                    }
                    if ($count >= $offset && count($rows) < $filters->perPage) {
                        $rows[] = $row;
                    }
                    $count++;
                }
                if ($matched) {
                    $parties++;
                }
            }
        });
        $formatted = [];
        foreach ($totals as $currency => $value) {
            $formatted[$currency] = (string) $value->toScale(6);
        }

        return ['rows' => $rows, 'count' => $count, 'totals' => [($vendor ? 'total_vendors' : 'total_customers') => $parties,
            ($vendor ? 'balance_by_currency' : 'outstanding_by_currency') => $formatted]];
    }
}
