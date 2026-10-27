<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Bounded host/cron job; platform HTTP requests only read its aggregate result. */
final class PlatformHealthCommand extends Command
{
    protected $signature = 'platform:health-snapshot {company : Company public ID}';

    protected $description = 'Record six read-only reconciliation booleans for one company; no financial details';

    public function handle(): int
    {
        if (app(CompanyContext::class)->hasCompany()) {
            $this->error('Health snapshots require a host command without active tenant context.');

            return self::FAILURE;
        }
        $company = Company::query()->where('public_id', (string) $this->argument('company'))->firstOrFail();
        $domains = [];
        foreach ([
            'accounting' => AccountingReconciliationService::class,
            'inventory' => InventoryReconciliationService::class,
            'sales' => SalesReconciliationService::class,
            'money' => MoneyReconciliationService::class,
            'payables' => PayablesReconciliationService::class,
            'phase7' => Phase7ReconciliationService::class,
        ] as $domain => $service) {
            $report = $domain === 'inventory'
                ? app($service)->auditCompany($company, fromCli: true)
                : app($service)->reconcile($company, isSystem: true);
            $domains[$domain] = $report->isHealthy;
        }
        DB::table('platform_company_health')->updateOrInsert(['company_id' => $company->id], [
            'domains' => json_encode($domains, JSON_THROW_ON_ERROR), 'checked_at' => now(),
        ]);
        $this->info('One timestamped company health snapshot recorded. No financial details retained.');

        return self::SUCCESS;
    }
}
