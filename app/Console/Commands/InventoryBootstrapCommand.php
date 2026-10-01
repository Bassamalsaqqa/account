<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Inventory\EnsureDefaultUnitsAction;
use App\Actions\Inventory\EnsureDefaultWarehouseAction;
use App\Models\Company;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

class InventoryBootstrapCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:bootstrap {companyPublicId? : The ULID public ID of a specific company to bootstrap} {--all : Bootstrap all companies}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Idempotently provision default units, main warehouse, and inventory settings for one or all companies';

    public function handle(
        EnsureDefaultUnitsAction $unitsAction,
        EnsureDefaultWarehouseAction $warehouseAction,
    ): int {
        $publicId = $this->argument('companyPublicId');
        $bootstrapAll = (bool) $this->option('all');

        $context = app(CompanyContext::class);
        $prevCompany = $context->hasCompany() ? $context->company() : null;
        $prevUser = $context->user();
        if ($prevCompany !== null) {
            $context->clear();
        }

        try {
            return CompanyScope::executeWithoutScope(function () use ($unitsAction, $warehouseAction, $publicId, $bootstrapAll): int {
                if ($publicId !== null) {
                    /** @var Company|null $company */
                    $company = Company::where('public_id', (string) $publicId)->first();

                    if ($company === null) {
                        $this->error("Company with public ID [{$publicId}] not found.");

                        return self::FAILURE;
                    }

                    $companies = collect([$company]);
                } elseif ($bootstrapAll) {
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
                    $this->info("Bootstrapping inventory defaults for company: {$company->displayName()} ({$company->public_id})...");
                    $unitsAction->execute($company);
                    $warehouseAction->execute($company);
                    $this->info("Inventory defaults provisioned successfully for [{$company->displayName()}].");
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
