<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Accounting\EnsureSystemLedgerAccountsAction;
use App\Models\Company;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

class AccountingBootstrapCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accounting:bootstrap {companyPublicId? : The ULID public ID of a specific company to provision}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision and reconcile default system chart of accounts for one or all companies';

    public function handle(EnsureSystemLedgerAccountsAction $action): int
    {
        $publicId = $this->argument('companyPublicId');

        return CompanyScope::executeWithoutScope(function () use ($action, $publicId): int {
            if ($publicId !== null) {
                /** @var Company|null $company */
                $company = Company::where('public_id', (string) $publicId)->first();

                if ($company === null) {
                    $this->error("Company with public ID [{$publicId}] not found.");

                    return self::FAILURE;
                }

                $this->info("Provisioning system accounts for company: {$company->displayName()} ({$company->public_id})...");
                $action->execute($company, isSystem: true);
                $this->info("System accounts provisioned successfully for [{$company->displayName()}].");

                return self::SUCCESS;
            }

            $companies = Company::all();

            if ($companies->isEmpty()) {
                $this->info('No companies found in database. System accounts will be provisioned on company creation.');

                return self::SUCCESS;
            }

            $count = 0;
            foreach ($companies as $company) {
                $this->line("Provisioning company: {$company->displayName()} ({$company->public_id})...");
                $action->execute($company, isSystem: true);
                $count++;
            }

            $this->info("Successfully provisioned system chart of accounts for {$count} company/companies.");

            return self::SUCCESS;
        });
    }
}
