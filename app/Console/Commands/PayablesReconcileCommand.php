<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;
use Throwable;

class PayablesReconcileCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payables:reconcile {companyPublicId? : The ULID public ID of a specific company to reconcile}';

    /**
     * Aliases for the command.
     *
     * @var list<string>
     */
    protected $aliases = ['purchasing:reconcile'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit and reconcile payables records, tenant isolation, invariants and accounting links';

    public function handle(PayablesReconciliationService $reconciler): int
    {
        $publicId = $this->argument('companyPublicId');

        $context = app(CompanyContext::class);
        $prevCompany = $context->hasCompany() ? $context->company() : null;
        $prevUser = $context->user();
        if ($prevCompany !== null) {
            $context->clear(sessionless: true);
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

                    $this->info("Auditing payables records for company: {$company->displayName()} ({$company->public_id})...");
                    $report = $reconciler->reconcile($company, isSystem: true);

                    if (! $report->isHealthy) {
                        $this->error("INTEGRITY VIOLATIONS DETECTED for [{$company->displayName()}]:");
                        foreach ($report->violations as $violation) {
                            $this->line("  [x] {$violation}");
                        }

                        return self::FAILURE;
                    }

                    $this->info("Payables records integrity healthy for [{$company->displayName()}].");

                    return self::SUCCESS;
                }

                $hasAnyFailures = false;
                $foundAny = false;

                // Keyset/chunked execution avoiding loading all companies at once
                Company::query()->orderBy('id')->chunk(50, function ($companies) use ($reconciler, &$hasAnyFailures, &$foundAny): void {
                    foreach ($companies as $company) {
                        $foundAny = true;
                        $this->info("Auditing payables records for company: {$company->displayName()} ({$company->public_id})...");
                        $report = $reconciler->reconcile($company, isSystem: true);

                        if (! $report->isHealthy) {
                            $hasAnyFailures = true;
                            $this->error("INTEGRITY VIOLATIONS DETECTED for [{$company->displayName()}]:");
                            foreach ($report->violations as $violation) {
                                $this->line("  [x] {$violation}");
                            }
                        } else {
                            $this->info("Payables records integrity healthy for [{$company->displayName()}].");
                        }
                    }
                });

                if (! $foundAny) {
                    $this->info('No companies found in database.');

                    return self::SUCCESS;
                }

                return $hasAnyFailures ? self::FAILURE : self::SUCCESS;
            });
        } finally {
            try {
                if ($prevCompany !== null) {
                    $context->setCompany($prevCompany, $prevUser);
                } else {
                    $context->clear(sessionless: true);
                }
            } catch (Throwable) {
                // context restoration safety
            }
        }
    }
}
