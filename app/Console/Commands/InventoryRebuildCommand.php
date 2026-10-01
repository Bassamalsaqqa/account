<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Inventory\InventoryRebuildService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

class InventoryRebuildCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:rebuild {companyPublicId? : The ULID public ID of a specific company to rebuild} {--all : Rebuild all companies}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild inventory balances, cost states, and lot balances strictly from immutable movements';

    public function handle(InventoryRebuildService $rebuilder): int
    {
        $publicId = $this->argument('companyPublicId');
        $rebuildAll = (bool) $this->option('all');

        $context = app(CompanyContext::class);
        $prevCompany = $context->hasCompany() ? $context->company() : null;
        $prevUser = $context->user();
        if ($prevCompany !== null) {
            $context->clear();
        }

        try {
            return CompanyScope::executeWithoutScope(function () use ($rebuilder, $publicId, $rebuildAll): int {
                if ($publicId !== null) {
                    /** @var Company|null $company */
                    $company = Company::where('public_id', (string) $publicId)->first();

                    if ($company === null) {
                        $this->error("Company with public ID [{$publicId}] not found.");

                        return self::FAILURE;
                    }

                    $companies = collect([$company]);
                } elseif ($rebuildAll) {
                    $companies = Company::all();
                } else {
                    $this->error('Please specify a company public ID or pass the --all option.');

                    return self::FAILURE;
                }

                if ($companies->isEmpty()) {
                    $this->info('No companies found in database.');

                    return self::SUCCESS;
                }

                foreach ($companies as $company) {
                    $this->info("Rebuilding inventory caches for company: {$company->displayName()} ({$company->public_id})...");
                    $stats = $rebuilder->rebuildForCompany($company, fromCli: true);

                    $this->info("Rebuild complete for [{$company->displayName()}]. Rebuilt {$stats['rebuilt_balances']} warehouse balances, {$stats['rebuilt_cost_states']} cost states, {$stats['rebuilt_lot_balances']} lot balances.");
                }

                return self::SUCCESS;
            });
        } finally {
            if ($prevCompany !== null) {
                $context->setCompany($prevCompany, $prevUser);
            }
        }
    }
}
