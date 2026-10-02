<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Sales\EnsureDefaultMoneyAccountAction;
use App\Models\Company;
use App\Services\Sales\DocumentSequenceService;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SalesBootstrapCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sales:bootstrap {companyPublicId? : The ULID public ID of a specific company to bootstrap} {--all : Bootstrap all companies}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Idempotently provision document sequences and default cash account for one or all companies';

    public function handle(
        DocumentSequenceService $sequenceService,
        EnsureDefaultMoneyAccountAction $moneyAccountAction,
        CompanyRoleService $roleService,
    ): int {
        $publicId = $this->argument('companyPublicId');
        $bootstrapAll = (bool) $this->option('all');

        $context = app(CompanyContext::class);
        if ($context->hasCompany()) {
            $this->error('System sales bootstrap requires no active company context.');

            return self::FAILURE;
        }

        return CompanyScope::executeWithoutScope(function () use ($sequenceService, $moneyAccountAction, $roleService, $publicId, $bootstrapAll): int {
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
                $this->info("Bootstrapping sales defaults for company: {$company->displayName()} ({$company->public_id})...");
                DB::transaction(function () use ($company, $roleService, $sequenceService, $moneyAccountAction): void {
                    Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
                    $roleService->upgradeSalesCatalog($company);
                    $sequenceService->ensureDefaultSequences($company->id);
                    $moneyAccountAction->execute($company);
                });
                $this->info("Sales defaults provisioned successfully for [{$company->displayName()}].");
            }

            return self::SUCCESS;
        });
    }
}
