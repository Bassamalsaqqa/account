<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Accounting\AccountingReconciliationService;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

class AccountingReconcileCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accounting:reconcile {companyPublicId? : The ULID public ID of a specific company to reconcile}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit and reconcile double-entry ledger integrity, balance equality, and system chart requirements';

    public function handle(AccountingReconciliationService $reconciler): int
    {
        $publicId = $this->argument('companyPublicId');

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
                $this->info("Auditing company: {$company->displayName()} ({$company->public_id})...");
                $report = $reconciler->reconcile($company, isSystem: true);

                if (! $report->isHealthy) {
                    $hasAnyFailures = true;
                    $this->error("INTEGRITY VIOLATIONS DETECTED for [{$company->displayName()}]:");
                    foreach ($report->violations as $violation) {
                        $this->line("  [x] {$violation}");
                    }
                } else {
                    $this->info("Ledger integrity healthy. {$report->stats['batches_count']} batches, {$report->stats['lines_count']} lines, {$report->stats['accounts_count']} accounts verified.");
                }
            }

            return $hasAnyFailures ? self::FAILURE : self::SUCCESS;
        });
    }
}
