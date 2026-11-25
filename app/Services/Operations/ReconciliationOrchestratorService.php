<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Company;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Operations\DTO\ReconciliationRunResult;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconciliationOrchestratorService
{
    public const DOMAINS = [
        'accounting',
        'inventory',
        'sales',
        'payables',
        'money',
        'phase7',
    ];

    public function __construct(
        protected AccountingReconciliationService $accountingReconciler,
        protected InventoryReconciliationService $inventoryReconciler,
        protected SalesReconciliationService $salesReconciler,
        protected PayablesReconciliationService $payablesReconciler,
        protected MoneyReconciliationService $moneyReconciler,
        protected Phase7ReconciliationService $phase7Reconciler,
        protected ReconciliationAlertService $alertService,
        protected ReconciliationAlertSanitizer $sanitizer,
    ) {}

    /**
     * Executes the six-domain read-only reconciliation across companies.
     *
     * Invariants:
     * - Default OFF until approved configuration unless $force is true.
     * - Bounded shared-hosting execution time.
     * - Non-overlapping mutex lock without daemons.
     * - Strictly read-only; NEVER auto-corrects ledger or inventory.
     * - Sanitizes all findings to guarantee zero financial values or PII in alerts.
     */
    public function run(?string $companyPublicId = null, bool $force = false): ReconciliationRunResult
    {
        // 1. Check configuration toggle: Default OFF unless force
        $enabled = (bool) config('reconciliation.enabled', false);
        if (! $enabled && ! $force) {
            Log::info('Reconciliation orchestration skipped: disabled in configuration (RECONCILIATION_SCHEDULE_ENABLED=false).');

            return new ReconciliationRunResult(
                status: 'REFUSED_DISABLED',
                durationSeconds: 0.0,
                companiesAudited: 0,
                domainSummary: $this->emptyDomainSummary(),
                skippedDisabled: true,
            );
        }

        // 2. Non-overlapping mutex lock (cache lock)
        $lockTtl = (int) config('reconciliation.lock_ttl_seconds', 300);
        $lock = Cache::lock('reconciliation:orchestration:mutex', $lockTtl);

        if (! $lock->get()) {
            Log::warning('Reconciliation orchestration skipped: another run is already in progress.');

            return new ReconciliationRunResult(
                status: 'LOCKED',
                durationSeconds: 0.0,
                companiesAudited: 0,
                domainSummary: $this->emptyDomainSummary(),
                lockContended: true,
            );
        }

        $startTime = microtime(true);
        $maxSeconds = (int) config('reconciliation.max_execution_seconds', 180);
        if (function_exists('set_time_limit')) {
            @set_time_limit($maxSeconds);
        }

        // 3. Ambient CompanyContext preservation and cleanup
        $context = app(CompanyContext::class);
        $prevCompany = $context->hasCompany() ? $context->company() : null;
        $prevUser = $context->user();
        if ($prevCompany !== null) {
            $context->clear();
        }

        try {
            return CompanyScope::executeWithoutScope(function () use (
                $companyPublicId,
                $startTime,
                $maxSeconds
            ): ReconciliationRunResult {
                $companiesQuery = Company::query()->orderBy('id');
                if ($companyPublicId !== null) {
                    $companiesQuery->where('public_id', $companyPublicId);
                }

                $companies = $companiesQuery->get();
                $companiesCount = $companies->count();

                $domainSummary = [
                    'accounting' => ['healthy' => true, 'violation_count' => 0],
                    'inventory' => ['healthy' => true, 'violation_count' => 0],
                    'sales' => ['healthy' => true, 'violation_count' => 0],
                    'payables' => ['healthy' => true, 'violation_count' => 0],
                    'money' => ['healthy' => true, 'violation_count' => 0],
                    'phase7' => ['healthy' => true, 'violation_count' => 0],
                ];

                /** @var list<array{company_public_id: string, domain: string, category: string, detail: string}> $findings */
                $findings = [];
                $timedOut = false;
                $recordPlatformHealth = (bool) config('reconciliation.record_platform_health', true);

                foreach ($companies as $company) {
                    // Check runtime bounds
                    $elapsed = microtime(true) - $startTime;
                    if ($elapsed >= ($maxSeconds - 10)) {
                        Log::warning("Reconciliation orchestration bounded runtime budget reached ({$elapsed}s >= {$maxSeconds}s). Aborting remaining companies.");
                        $timedOut = true;
                        break;
                    }

                    $companyDomains = [];

                    // Domain 1: Accounting (Read-Only)
                    $acctReport = $this->accountingReconciler->reconcile($company, isSystem: true);
                    $companyDomains['accounting'] = $acctReport->isHealthy;
                    if (! $acctReport->isHealthy) {
                        $domainSummary['accounting']['healthy'] = false;
                        $domainSummary['accounting']['violation_count'] += count($acctReport->violations);
                        foreach ($acctReport->violations as $v) {
                            $sanitized = $this->sanitizer->sanitize('accounting', $v);
                            $findings[] = [
                                'company_public_id' => $company->public_id,
                                'domain' => 'accounting',
                                'category' => $sanitized['category'],
                                'detail' => $sanitized['detail'],
                            ];
                        }
                    }

                    // Domain 2: Inventory (Read-Only Audit)
                    $invReport = $this->inventoryReconciler->auditCompany($company, fromCli: true);
                    $companyDomains['inventory'] = $invReport->isHealthy;
                    if (! $invReport->isHealthy) {
                        $domainSummary['inventory']['healthy'] = false;
                        $domainSummary['inventory']['violation_count'] += count($invReport->discrepancies);
                        foreach ($invReport->discrepancies as $v) {
                            $sanitized = $this->sanitizer->sanitize('inventory', $v);
                            $findings[] = [
                                'company_public_id' => $company->public_id,
                                'domain' => 'inventory',
                                'category' => $sanitized['category'],
                                'detail' => $sanitized['detail'],
                            ];
                        }
                    }

                    // Domain 3: Sales (Read-Only)
                    $salesReport = $this->salesReconciler->reconcile($company, isSystem: true);
                    $companyDomains['sales'] = $salesReport->isHealthy;
                    if (! $salesReport->isHealthy) {
                        $domainSummary['sales']['healthy'] = false;
                        $domainSummary['sales']['violation_count'] += count($salesReport->violations);
                        foreach ($salesReport->violations as $v) {
                            $sanitized = $this->sanitizer->sanitize('sales', $v);
                            $findings[] = [
                                'company_public_id' => $company->public_id,
                                'domain' => 'sales',
                                'category' => $sanitized['category'],
                                'detail' => $sanitized['detail'],
                            ];
                        }
                    }

                    // Domain 4: Payables (Read-Only)
                    $payablesReport = $this->payablesReconciler->reconcile($company, isSystem: true);
                    $companyDomains['payables'] = $payablesReport->isHealthy;
                    if (! $payablesReport->isHealthy) {
                        $domainSummary['payables']['healthy'] = false;
                        $domainSummary['payables']['violation_count'] += count($payablesReport->violations);
                        foreach ($payablesReport->violations as $v) {
                            $sanitized = $this->sanitizer->sanitize('payables', $v);
                            $findings[] = [
                                'company_public_id' => $company->public_id,
                                'domain' => 'payables',
                                'category' => $sanitized['category'],
                                'detail' => $sanitized['detail'],
                            ];
                        }
                    }

                    // Domain 5: Money (Read-Only)
                    $moneyReport = $this->moneyReconciler->reconcile($company, isSystem: true);
                    $companyDomains['money'] = $moneyReport->isHealthy;
                    if (! $moneyReport->isHealthy) {
                        $domainSummary['money']['healthy'] = false;
                        $domainSummary['money']['violation_count'] += count($moneyReport->violations);
                        foreach ($moneyReport->violations as $v) {
                            $sanitized = $this->sanitizer->sanitize('money', $v);
                            $findings[] = [
                                'company_public_id' => $company->public_id,
                                'domain' => 'money',
                                'category' => $sanitized['category'],
                                'detail' => $sanitized['detail'],
                            ];
                        }
                    }

                    // Domain 6: Phase 7 (Read-Only)
                    $phase7Report = $this->phase7Reconciler->reconcile($company, isSystem: true);
                    $companyDomains['phase7'] = $phase7Report->isHealthy;
                    if (! $phase7Report->isHealthy) {
                        $domainSummary['phase7']['healthy'] = false;
                        $domainSummary['phase7']['violation_count'] += count($phase7Report->violations);
                        foreach ($phase7Report->violations as $v) {
                            $sanitized = $this->sanitizer->sanitize('phase7', $v);
                            $findings[] = [
                                'company_public_id' => $company->public_id,
                                'domain' => 'phase7',
                                'category' => $sanitized['category'],
                                'detail' => $sanitized['detail'],
                            ];
                        }
                    }

                    // Record snapshot in platform_company_health if enabled
                    if ($recordPlatformHealth) {
                        DB::table('platform_company_health')->updateOrInsert(
                            ['company_id' => $company->id],
                            ['domains' => json_encode($companyDomains, JSON_THROW_ON_ERROR), 'checked_at' => now()]
                        );
                    }
                }

                $duration = round(microtime(true) - $startTime, 3);
                $hasViolations = ! empty($findings);

                $status = match (true) {
                    $timedOut => 'TIMEOUT_ABORTED',
                    $hasViolations => 'NONZERO_FINDINGS',
                    default => 'HEALTHY',
                };

                $result = new ReconciliationRunResult(
                    status: $status,
                    durationSeconds: $duration,
                    companiesAudited: $companiesCount,
                    domainSummary: $domainSummary,
                    findings: $findings,
                );

                // Dispatch appropriate alert
                if ($hasViolations) {
                    $this->alertService->sendNonzeroAlert($result);
                } else {
                    $this->alertService->sendHeartbeat($result);
                }

                return $result;
            });
        } catch (Throwable $e) {
            $duration = round(microtime(true) - $startTime, 3);
            $failedResult = new ReconciliationRunResult(
                status: 'FAILURE',
                durationSeconds: $duration,
                companiesAudited: 0,
                domainSummary: $this->emptyDomainSummary(),
                errorMessage: get_class($e).': '.$e->getMessage(),
            );

            $this->alertService->sendFailureAlert($e, $failedResult);

            return $failedResult;
        } finally {
            if ($prevCompany !== null) {
                $context->setCompany($prevCompany, $prevUser);
            }
            $lock->release();
        }
    }

    /**
     * @return array<string, array{healthy: bool, violation_count: int}>
     */
    protected function emptyDomainSummary(): array
    {
        return [
            'accounting' => ['healthy' => true, 'violation_count' => 0],
            'inventory' => ['healthy' => true, 'violation_count' => 0],
            'sales' => ['healthy' => true, 'violation_count' => 0],
            'payables' => ['healthy' => true, 'violation_count' => 0],
            'money' => ['healthy' => true, 'violation_count' => 0],
            'phase7' => ['healthy' => true, 'violation_count' => 0],
        ];
    }
}
