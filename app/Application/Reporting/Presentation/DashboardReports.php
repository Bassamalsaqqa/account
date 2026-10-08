<?php

declare(strict_types=1);

namespace App\Application\Reporting\Presentation;

use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Security\ReportingGuard;
use App\Models\Company;
use Illuminate\Auth\Access\AuthorizationException;

final class DashboardReports
{
    /** @return array{activity:array<string,array<string,mixed>>,positions:array<string,array<string,mixed>>,alerts:array<string,array<string,mixed>>,today:string} */
    public function read(Company $company, string $preset, ?string $from = null, ?string $to = null): array
    {
        $company = app(ReportingGuard::class)->company($company);
        $period = $preset === 'custom' ? ReportPeriod::custom($from ?? '', $to ?? '', $company) : ReportPeriod::fromPreset($preset, $company);
        $today = ReportPeriod::fromPreset('today', $company);
        $registry = app(ReportRegistry::class);
        $presenter = app(ReportPresenter::class);
        $groups = [
            'activity' => ['sales.summary', 'profit', 'expenses.summary', 'purchases.summary'],
            'positions' => ['customers.balances', 'vendors.balances', 'money.balances', 'money.checks', 'inventory.valuation', 'payroll.summary'],
            'alerts' => ['customers.overdue', 'purchases.unpaid', 'money.due-checks', 'money.returned-checks', 'inventory.low-stock', 'inventory.expiry', 'payroll.unpaid'],
        ];
        $output = ['activity' => [], 'positions' => [], 'alerts' => [], 'today' => $today->endDate];
        foreach ($groups as $group => $keys) {
            foreach ($keys as $key) {
                // Restricted cards are not queried, calculated or serialized.
                if (! $registry->allows($company, $key)) {
                    continue;
                }
                $definition = $registry->definition($key);
                $filters = ['per_page' => 5];
                if (! $definition['current']) {
                    $filters['period'] = ($group === 'activity' ? $period : $today)->toArray();
                }
                try {
                    $result = $registry->execute($company, $key, $filters);
                } catch (AuthorizationException $exception) {
                    continue;
                }
                $output[$group][$key] = ['title' => $registry->title($key),
                    'totals' => $presenter->totals($result->totals, (string) $company->base_currency_code),
                    'url' => route('reports.show', ['reportKey' => $key, 'filters' => $filters])];
            }
        }

        return $output;
    }
}
