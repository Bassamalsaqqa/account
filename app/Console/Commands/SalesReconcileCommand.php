<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

class SalesReconcileCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sales:reconcile {companyPublicId? : The ULID public ID of a specific company to reconcile}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit and reconcile sales records, tenant isolation, invariants and accounting links';

    public function handle(SalesReconciliationService $reconciler): int
    {
        $publicId = $this->argument('companyPublicId');

        $context = app(CompanyContext::class);
        $prevCompany = $context->hasCompany() ? $context->company() : null;
        $prevUser = $context->user();
        if ($prevCompany !== null) {
            $context->clear();
        }

        try {
            return CompanyScope::executeWithoutScope(function () use ($reconciler, $publicId): int {
                if ($publicId !== null) {
                    /** @var Company|null $company */
                    $company = Company::where('public_id', (string) $publicId)->first();

                    if ($company === null) {
                        $this->error("Company with public ID [{$publicId}] not found.");

                        return self::FAILURE;
                    }

                    $companies = collect([$company]);
                } else {
                    $companies = Company::all();
                }

                if ($companies->isEmpty()) {
                    $this->info('No companies found in database.');

                    return self::SUCCESS;
                }

                $hasAnyFailures = false;

                foreach ($companies as $company) {
                    $this->info("Auditing sales records for company: {$company->displayName()} ({$company->public_id})...");
                    $report = $reconciler->reconcile($company, isSystem: true);

                    if (! $report->isHealthy) {
                        $hasAnyFailures = true;
                        $this->error("INTEGRITY VIOLATIONS DETECTED for [{$company->displayName()}]:");
                        foreach ($report->violations as $violation) {
                            $this->line("  [x] {$violation}");
                        }
                    } else {
                        $this->info("Sales records integrity healthy. Verified {$report->stats['customers_count']} customers, {$report->stats['quotations_count']} quotations, {$report->stats['invoices_count']} invoices, {$report->stats['returns_count']} returns, {$report->stats['payments_count']} payments.");
                    }
                }

                return $hasAnyFailures ? self::FAILURE : self::SUCCESS;
            });
        } finally {
            if ($prevCompany !== null) {
                $context->setCompany($prevCompany, $prevUser);
            }
        }
    }
}
