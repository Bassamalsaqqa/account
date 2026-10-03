<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Purchasing\EnsurePurchasingFoundationAction;
use App\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Console\Command;

class PurchasingBootstrapCommand extends Command
{
    protected $signature = 'purchasing:bootstrap {companyPublicId?} {--all}';

    protected $description = 'Provision only purchase settings, sequence definitions and the Owner static permission catalog';

    public function handle(EnsurePurchasingFoundationAction $action): int
    {
        if (app(CompanyContext::class)->hasCompany()) {
            $this->error('System purchasing bootstrap requires no active company context.');

            return self::FAILURE;
        }
        $publicId = $this->argument('companyPublicId');
        if ($publicId === null && ! $this->option('all')) {
            $this->error('Specify a company public ID or --all.');

            return self::FAILURE;
        }
        $companies = Company::query()->when($publicId !== null, fn ($query) => $query->where('public_id', $publicId))->orderBy('id')->get();
        if ($companies->isEmpty()) {
            $this->info('No companies found in database.');

            return $publicId === null ? self::SUCCESS : self::FAILURE;
        }
        foreach ($companies as $company) {
            $action->execute($company);
            $this->info("Purchasing configuration provisioned: {$company->public_id}");
        }

        return self::SUCCESS;
    }
}
