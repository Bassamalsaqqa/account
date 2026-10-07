<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Accounting\EnsurePhase7FoundationAction;
use App\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Console\Command;

class Phase7BootstrapCommand extends Command
{
    protected $signature = 'phase7:bootstrap {companyPublicId?} {--all}';

    protected $description = 'Provision Phase 7 accounts, categories, sequences, and Owner catalog permissions';

    public function handle(EnsurePhase7FoundationAction $action): int
    {
        if (app(CompanyContext::class)->hasCompany()) {
            $this->error('System Phase 7 bootstrap requires no active company context.');

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
            $this->info("Phase 7 configuration provisioned: {$company->public_id}");
        }

        return self::SUCCESS;
    }
}
