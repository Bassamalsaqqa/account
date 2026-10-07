<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

class Phase7ReconcileCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'phase7:reconcile {companyPublicId? : The ULID public ID of a specific company to reconcile}';

    /**
     * Aliases for the command.
     *
     * @var list<string>
     */
    protected $aliases = ['accounting:reconcile-phase7'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit and reconcile Phase 7 expenses, payroll, advances, and landed cost clearing';

    public function handle(Phase7ReconciliationService $reconciler): int
    {
        $publicId = $this->argument('companyPublicId');

        $context = app(CompanyContext::class);
        $prevCompany = $context->hasCompany() ? $context->company() : null;
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

                $hasFailure = false;

                foreach ($companies as $company) {
                    $this->info("Reconciling Phase 7 records for company: {$company->name_ar} [{$company->public_id}]...");

                    $report = $reconciler->reconcile($company, isSystem: true);

                    if ($report->isHealthy) {
                        $this->info("✓ Company [{$company->public_id}] Phase 7 reconciliation HEALTHY.");
                        if (! empty($report->stats)) {
                            $this->table(
                                ['Metric', 'Value'],
                                collect($report->stats)->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])->values()->all()
                            );
                        }
                    } else {
                        $hasFailure = true;
                        $this->error("✗ Company [{$company->public_id}] Phase 7 reconciliation UNHEALTHY.");
                        $this->warn('Violations found:');
                        foreach ($report->violations as $violation) {
                            $this->line("  - {$violation}");
                        }
                    }
                    $this->newLine();
                }

                return $hasFailure ? self::FAILURE : self::SUCCESS;
            });
        } finally {
            if ($prevCompany !== null) {
                $context->setCompany($prevCompany);
            }
        }
    }
}
