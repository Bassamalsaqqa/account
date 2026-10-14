<?php

declare(strict_types=1);

namespace App\Application\Reporting\Presentation;

use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Security\ReportingGuard;
use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use App\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;

final class DashboardReports
{
    /** @return array<string, mixed> */
    public function read(Company $company, string $preset, ?string $from = null, ?string $to = null, bool $includeAdditionalPositions = true): array
    {
        $guard = app(ReportingGuard::class);
        $company = $guard->company($company);
        $authority = $guard->permissionNames(auth()->user());
        $period = $preset === 'custom' ? ReportPeriod::custom($from ?? '', $to ?? '', $company) : ReportPeriod::fromPreset($preset, $company);
        $today = ReportPeriod::fromPreset('today', $company);
        $registry = app(ReportRegistry::class);
        $presenter = app(ReportPresenter::class);
        $currency = (string) $company->base_currency_code;
        $groups = [
            'activity' => ['sales.summary', 'profit', 'expenses.summary', 'purchases.summary'],
            'positions' => ['customers.balances', 'vendors.balances', 'money.balances', 'money.checks', 'inventory.valuation', 'payroll.summary'],
            'alerts' => ['customers.overdue', 'purchases.unpaid', 'money.due-checks', 'money.returned-checks', 'inventory.low-stock', 'inventory.expiry', 'payroll.unpaid'],
        ];
        $output = ['activity' => [], 'positions' => [], 'alerts' => [], 'today' => $today->endDate,
            'period' => $period->toArray(), 'currency' => (string) $company->base_currency_code,
            'comparison' => null, 'attention' => [], 'company_name' => $company->displayName()];
        foreach ($groups as $group => $keys) {
            foreach ($keys as $key) {
                // Registry execution already checks fresh source permissions before any
                // report query. Do not repeat that same check just to discover a card.
                $definition = $registry->definition($key);
                $filters = ['per_page' => 5];
                if (! $definition['current']) {
                    $filters['period'] = ($group === 'activity' ? $period : $today)->toArray();
                }
                // Secondary positions are links on the initial dashboard. Their full
                // canonical report runs when opened, rather than loading hidden cards.
                if (! $includeAdditionalPositions && $group === 'positions'
                    && in_array($key, ['money.checks', 'inventory.valuation', 'payroll.summary'], true)) {
                    if ($registry->allows($company, $key)) {
                        $output[$group][$key] = ['title' => $registry->title($key), 'totals' => [], 'metrics' => [],
                            'url' => route('reports.show', ['reportKey' => $key, 'filters' => $filters])];
                    }

                    continue;
                }
                try {
                    $result = $registry->execute($company, $key, $filters);
                } catch (AuthorizationException $exception) {
                    continue;
                }
                $output[$group][$key] = ['title' => $registry->title($key),
                    'totals' => $presenter->totals($result->totals, (string) $company->base_currency_code),
                    'metrics' => $this->metrics($key, $result->totals, (string) $company->base_currency_code),
                    'url' => route('reports.show', ['reportKey' => $key, 'filters' => $filters])];
                if ($group === 'alerts') {
                    $count = $this->alertCount($key, $result->totals, $result->pagination);
                    if ($count > 0) {
                        $output['attention'][] = ['key' => $key, 'title' => $registry->title($key), 'count' => $count,
                            'url' => $output[$group][$key]['url'], 'metrics' => $output[$group][$key]['metrics']];
                    }
                }
                if ($key === 'sales.summary') {
                    $previous = $period->previous();
                    try {
                        $prior = $registry->execute($company, $key, ['per_page' => 1, 'period' => $previous->toArray()]);
                        $currentAmount = BigDecimal::of((string) ($result->totals['net_sales_base'] ?? '0'));
                        $priorAmount = BigDecimal::of((string) ($prior->totals['net_sales_base'] ?? '0'));
                        $difference = $currentAmount->minus($priorAmount);
                        $maximum = BigDecimal::max($currentAmount->abs(), $priorAmount->abs());
                        $output['comparison'] = ['current' => (string) $currentAmount, 'previous' => (string) $priorAmount,
                            'current_display' => SalesMoneyFormatter::format($currentAmount, $currency),
                            'previous_display' => SalesMoneyFormatter::format($priorAmount, $currency),
                            'difference_display' => SalesMoneyFormatter::format($difference->abs(), $currency),
                            'direction' => $difference->getSign(), 'previous_period' => $previous->toArray(),
                            'percent' => $priorAmount->isPositive() ? (string) $difference->multipliedBy(100)->dividedBy($priorAmount, 2, RoundingMode::HALF_UP) : null,
                            'current_width' => $maximum->isZero() ? '0' : (string) $currentAmount->abs()->multipliedBy(100)->dividedBy($maximum, 2, RoundingMode::HALF_UP),
                            'previous_width' => $maximum->isZero() ? '0' : (string) $priorAmount->abs()->multipliedBy(100)->dividedBy($maximum, 2, RoundingMode::HALF_UP),
                            'url' => $output[$group][$key]['url']];
                    } catch (AuthorizationException) {
                        // Final authority check below refuses every earlier result after revocation.
                    }
                }
            }
        }

        $guard->company($company);
        if ($authority !== $guard->permissionNames(auth()->user())) {
            throw new AuthorizationException('Dashboard authority changed during report retrieval.');
        }

        return $output;
    }

    /** Selected canonical aggregates only; no mixed-currency summation or hidden fields.
     * @param  array<string, mixed>  $totals
     * @return list<array{key:string,value:string,display:string,currency:string}>
     */
    private function metrics(string $key, array $totals, string $currency): array
    {
        $fields = match ($key) {
            'sales.summary' => ['net_sales_base', 'original_invoice_count', 'average_invoice_base'],
            'purchases.summary' => ['net_purchases_base', 'purchase_count'],
            'profit' => ['net_profit', 'gross_profit', 'operating_expenses'],
            'expenses.summary' => ['ordinary_operating_expense_base'],
            'customers.balances', 'customers.overdue' => ['outstanding_by_currency'],
            'vendors.balances' => ['balance_by_currency'],
            'money.balances' => ['total_base_balance', 'currency_balances'],
            'inventory.valuation' => ['total_valuation'],
            'payroll.summary', 'payroll.unpaid' => ['unpaid_salary_by_currency'],
            'purchases.unpaid' => ['outstanding_by_currency'],
            default => [],
        };
        $out = [];
        foreach ($fields as $field) {
            $value = $totals[$field] ?? null;
            if (is_array($value)) {
                foreach ($value as $code => $amount) {
                    if (preg_match('/^[A-Z]{3}$/D', (string) $code) && (is_string($amount) || is_int($amount))) {
                        $out[] = ['key' => $field, 'value' => (string) $amount, 'display' => SalesMoneyFormatter::format($amount, (string) $code), 'currency' => (string) $code];
                    }
                }
            } elseif (is_string($value) || is_int($value)) {
                $isCount = str_contains($field, 'count');
                $out[] = ['key' => $field, 'value' => (string) $value,
                    'display' => $isCount ? (string) $value : SalesMoneyFormatter::format($value, $currency), 'currency' => $isCount ? '' : $currency];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $totals
     * @param  array<string, mixed>|null  $pagination
     */
    private function alertCount(string $key, array $totals, ?array $pagination): int
    {
        if ($key === 'inventory.expiry') {
            return (int) ($totals['expired_count'] ?? 0) + (int) ($totals['soon_count'] ?? 0);
        }
        $field = match ($key) {
            'customers.overdue' => 'overdue_invoice_count',
            'inventory.low-stock' => 'total_alert_count',
            'purchases.unpaid' => 'unpaid_purchase_count',
            'money.due-checks', 'money.returned-checks' => 'total_checks',
            default => '',
        };

        return (int) ($totals[$field] ?? $pagination['total'] ?? 0);
    }
}
