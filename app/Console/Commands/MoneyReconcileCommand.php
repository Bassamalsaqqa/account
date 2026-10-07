<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Money\MoneyReconciliationService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

final class MoneyReconcileCommand extends Command
{
    protected $signature = 'money:reconcile {companyPublicId?}';

    protected $description = 'Read-only audit of Money account routing and immutable source history';

    public function handle(MoneyReconciliationService $service): int
    {
        $context = app(CompanyContext::class);
        $company = $context->hasCompany() ? $context->company() : null;
        $actor = $context->user();
        $context->clear();
        try {
            return CompanyScope::executeWithoutScope(function () use ($service): int {
                $query = Company::query();
                if ($this->argument('companyPublicId') !== null) {
                    $query->where('public_id', $this->argument('companyPublicId'));
                }
                $companies = $query->get();
                if ($companies->isEmpty() && $this->argument('companyPublicId') !== null) {
                    $this->error('Company not found.');

                    return self::FAILURE;
                }
                $healthy = true;
                foreach ($companies as $company) {
                    $report = $service->reconcile($company, isSystem: true);
                    $this->line($company->public_id.': '.($report->isHealthy ? 'HEALTHY' : 'UNHEALTHY'));
                    foreach ($report->violations as $violation) {
                        $this->error($violation);
                    }
                    $healthy = $healthy && $report->isHealthy;
                }

                return $healthy ? self::SUCCESS : self::FAILURE;
            });
        } finally {
            if ($company !== null) {
                $context->setCompany($company, $actor);
            }
        }
    }
}
