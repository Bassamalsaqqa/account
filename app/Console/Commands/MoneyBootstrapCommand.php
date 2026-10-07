<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Money\EnsureMoneyFoundationAction;
use App\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Console\Command;

final class MoneyBootstrapCommand extends Command
{
    protected $signature = 'money:bootstrap {companyPublicId?} {--all}';

    protected $description = 'Provision Transfer numbering and Owner Money permissions without financial effects or non-owner role changes';

    public function handle(EnsureMoneyFoundationAction $action): int
    {
        if (app(CompanyContext::class)->hasCompany() || ($this->argument('companyPublicId') === null && ! $this->option('all'))) {
            $this->error('Requires no ambient Company and a public ID or --all.');

            return self::FAILURE;
        }
        $companies = Company::when($this->argument('companyPublicId') !== null, fn ($q) => $q->where('public_id', $this->argument('companyPublicId')))->orderBy('id')->get();
        foreach ($companies as $company) {
            $action->execute($company);
            $this->info('Money configuration provisioned: '.$company->public_id);
        }

        return $companies->isEmpty() && ! $this->option('all') ? self::FAILURE : self::SUCCESS;
    }
}
