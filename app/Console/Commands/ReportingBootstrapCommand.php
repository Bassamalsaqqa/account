<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Reporting\EnsureReportingFoundationAction;
use App\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Console\Command;

class ReportingBootstrapCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reporting:bootstrap {companyPublicId? : The ULID public ID of a specific company to bootstrap} {--all : Bootstrap all companies}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision Phase 8 reporting catalog permissions for Owner role only';

    public function handle(EnsureReportingFoundationAction $action): int
    {
        if (app(CompanyContext::class)->hasCompany()) {
            $this->error('System reporting bootstrap requires no active company context.');

            return self::FAILURE;
        }

        $publicId = $this->argument('companyPublicId');
        if ($publicId === null && ! $this->option('all')) {
            $this->error('Specify a company public ID or --all.');

            return self::FAILURE;
        }

        $companies = Company::query()
            ->when($publicId !== null, fn ($query) => $query->where('public_id', $publicId))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->info('No companies found in database.');

            return $publicId === null ? self::SUCCESS : self::FAILURE;
        }

        foreach ($companies as $company) {
            $action->execute($company);
            $this->info("Reporting configuration provisioned: {$company->public_id}");
        }

        return self::SUCCESS;
    }
}
