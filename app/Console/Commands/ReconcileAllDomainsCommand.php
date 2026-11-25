<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Operations\ReconciliationOrchestratorService;
use Illuminate\Console\Command;

class ReconcileAllDomainsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reconciliation:run
                            {companyPublicId? : ULID public ID of a specific company to reconcile}
                            {--force : Force execution even if scheduled reconciliation is disabled in config}';

    /**
     * Aliases for the command.
     *
     * @var list<string>
     */
    protected $aliases = ['reconciliation:orchestrate', 'platform:reconcile-all'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit and reconcile all six financial and inventory domains with bounded runtime, non-overlap protection, and health/alert reporting';

    public function handle(ReconciliationOrchestratorService $orchestrator): int
    {
        $publicId = $this->argument('companyPublicId');
        $force = (bool) $this->option('force');

        $this->info('Starting six-domain operational reconciliation audit...');

        $result = $orchestrator->run($publicId !== null ? (string) $publicId : null, $force);

        if ($result->wasRefused()) {
            if ($result->skippedDisabled) {
                $this->warn('Reconciliation orchestration is disabled in configuration (RECONCILIATION_SCHEDULE_ENABLED=false).');
                $this->comment('Pass --force to execute a manual audit while scheduled orchestration is disabled.');

                return self::SUCCESS;
            }

            if ($result->lockContended) {
                $this->warn('Another reconciliation orchestration run is currently active. Aborting to avoid overlap.');

                return self::SUCCESS;
            }
        }

        if ($result->status === 'FAILURE') {
            $this->error('Reconciliation orchestration encountered an execution failure: '.($result->errorMessage ?? 'Unknown error'));

            return self::FAILURE;
        }

        if ($result->status === 'TIMEOUT_ABORTED') {
            $this->warn('Reconciliation orchestration reached runtime budget and aborted remaining companies.');
        }

        $this->newLine();
        $this->info("Audit completed in {$result->durationSeconds}s across {$result->companiesAudited} company/companies.");
        $this->newLine();

        // Print domain summary table
        $tableData = [];
        foreach ($result->domainSummary as $domain => $data) {
            $tableData[] = [
                'Domain' => ucfirst($domain),
                'Status' => $data['healthy'] ? 'HEALTHY' : 'UNHEALTHY',
                'Findings' => $data['violation_count'],
            ];
        }
        $this->table(['Domain', 'Status', 'Findings'], $tableData);

        if ($result->hasNonzeroFindings()) {
            $this->newLine();
            $this->error("INTEGRITY VIOLATIONS DETECTED ({$result->totalViolations()} findings):");
            foreach ($result->findings as $finding) {
                $this->line("  [x] [{$finding['company_public_id']}] [{$finding['domain']}] {$finding['category']}: {$finding['detail']}");
            }

            return self::FAILURE;
        }

        $this->info('✓ All six domains are healthy across all audited companies.');

        return self::SUCCESS;
    }
}
