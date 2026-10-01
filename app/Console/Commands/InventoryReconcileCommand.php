<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Inventory\InventoryReconciliationService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

class InventoryReconcileCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:reconcile {companyPublicId? : The ULID public ID of a specific company to reconcile} {--all : Reconcile all companies}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit and reconcile inventory balances, lot allocations, moving average costing, and movement integrity';

    public function handle(InventoryReconciliationService $reconciler): int
    {
        $publicId = $this->argument('companyPublicId');
        $reconcileAll = (bool) $this->option('all');

        $context = app(CompanyContext::class);
        $prevCompany = $context->hasCompany() ? $context->company() : null;
        $prevUser = $context->user();
        if ($prevCompany !== null) {
            $context->clear();
        }

        try {
            return CompanyScope::executeWithoutScope(function () use ($reconciler, $publicId, $reconcileAll): int {
                if ($publicId !== null) {
                    /** @var Company|null $company */
                    $company = Company::where('public_id', (string) $publicId)->first();

                    if ($company === null) {
                        $this->error("Company with public ID [{$publicId}] not found.");

                        return self::FAILURE;
                    }

                    $companies = collect([$company]);
                } elseif ($reconcileAll) {
                    $companies = Company::all();
                } else {
                    $this->error('Please specify a company public ID or pass the --all option.');

                    return self::FAILURE;
                }

                if ($companies->isEmpty()) {
                    $this->info('No companies found in database.');

                    return self::SUCCESS;
                }

                $hasAnyFailures = false;

                foreach ($companies as $company) {
                    $this->info("Auditing inventory for company: {$company->displayName()} ({$company->public_id})...");
                    $report = $reconciler->auditCompany($company, fromCli: true);

                    if (! $report->isHealthy) {
                        $hasAnyFailures = true;
                        $this->error("INVENTORY INTEGRITY VIOLATIONS DETECTED for [{$company->displayName()}]:");
                        foreach ($report->discrepancies as $violation) {
                            $this->line("  [x] {$violation}");
                        }
                    } else {
                        $this->info("Inventory integrity healthy for [{$company->displayName()}]. Checked {$report->checkedProducts} products, {$report->checkedWarehouses} warehouses, {$report->checkedMovements} movements. Total valuation: {$report->totalValuationBase} {$company->base_currency}.");
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
