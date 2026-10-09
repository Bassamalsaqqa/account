<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Reporting\EnsureReportingFoundationAction;
use App\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Console\Command;

final class DocumentsBootstrapCommand extends Command
{
    protected $signature = 'documents:bootstrap {companyPublicId?} {--all}';

    protected $description = 'Provision Phase 9 Owner permissions without changing other roles or business records';

    public function handle(EnsureReportingFoundationAction $action): int
    {
        if (app(CompanyContext::class)->hasCompany() || ($this->argument('companyPublicId') === null && ! $this->option('all'))) {
            $this->error('Use a company public ID or --all, with no active company context.');

            return self::FAILURE;
        }
        $query = Company::query()->when($this->argument('companyPublicId'), fn ($query, $id) => $query->where('public_id', $id));
        if (! $query->exists()) {
            return self::FAILURE;
        }
        foreach ($query->orderBy('id')->cursor() as $company) {
            $action->execute($company);
            $this->info('Document permissions provisioned: '.$company->public_id);
        }

        return self::SUCCESS;
    }
}
