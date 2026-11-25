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
use RuntimeException;
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

    public const DOMAIN_COMMANDS = [
        'accounting' => 'accounting:reconcile',
        'inventory' => 'inventory:reconcile',
        'sales' => 'sales:reconcile',
        'payables' => 'payables:reconcile',
        'money' => 'money:reconcile',
        'phase7' => 'phase7:reconcile',
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
        protected ?ReconciliationProcessRunner $processRunner = null,
    ) {
        $this->processRunner ??= app(ReconciliationProcessRunner::class);
    }

    /**
     * Executes the six-domain read-only reconciliation across companies.
     *
     * Invariants:
     * - Default OFF until approved configuration unless $force is true.
     * - Bounded shared-hosting execution time via child processes with actual termination.
     * - Mutex safe TTL exceeding enforced lifetime.
     * - Keyset / chunked company retrieval avoiding loading all companies at once.
     * - Strictly read-only; NEVER auto-corrects ledger or inventory.
     * - Unknown company must fail (no zero-healthy).
     * - companiesAudited counts actual completed companies only.
     * - TIMEOUT_ABORTED never sends HEALTHY heartbeat.
     * - Sanitizes all findings to guarantee allowlisted category/count/status only.
     * - Error results, CLI, and logs redact raw DB exceptions.
     * - Preserves ambient CompanyContext in finally.
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

        // 2. Non-overlapping mutex lock with safe TTL exceeding enforced lifetime
        $maxSeconds = (int) config('reconciliation.max_execution_seconds', 180);
        $configuredTtl = (int) config('reconciliation.lock_ttl_seconds', 300);
        $lockTtl = max($configuredTtl, $maxSeconds + 60);

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
        $companiesAudited = 0;
        $domainSummary = $this->emptyDomainSummary();

        // 3. Ambient CompanyContext preservation
        $context = app(CompanyContext::class);
        $prevCompany = $context->hasCompany() ? $context->company() : null;
        $prevUser = $context->user();
        if ($prevCompany !== null) {
            $context->clear(sessionless: true);
        }

        try {
            return CompanyScope::executeWithoutScope(function () use (
                $companyPublicId,
                $startTime,
                $maxSeconds,
                &$companiesAudited,
                &$domainSummary
            ): ReconciliationRunResult {
                // 4. Unknown company must fail (no zero-healthy)
                if ($companyPublicId !== null) {
                    /** @var Company|null $targetCompany */
                    $targetCompany = Company::where('public_id', $companyPublicId)->select(['id', 'public_id'])->first();

                    if ($targetCompany === null) {
                        $duration = round(microtime(true) - $startTime, 3);
                        $errorMessage = "Company with public ID [{$companyPublicId}] not found.";
                        Log::error("Reconciliation orchestration failed: {$errorMessage}");

                        $failedResult = new ReconciliationRunResult(
                            status: 'FAILURE',
                            durationSeconds: $duration,
                            companiesAudited: 0,
                            domainSummary: $this->emptyDomainSummary(false),
                            errorMessage: $errorMessage,
                        );

                        $this->alertService->sendFailureAlert(new RuntimeException($errorMessage), $failedResult);

                        return $failedResult;
                    }

                    $singleCompanyList = [$targetCompany];
                } else {
                    $singleCompanyList = null;
                }

                /** @var list<array{company_public_id: string, domain: string, category: string, status: string, count: int}> $findings */
                $findings = [];
                $timedOut = false;
                $recordPlatformHealth = (bool) config('reconciliation.record_platform_health', true);
                $perTaskTimeout = (float) config('reconciliation.per_task_timeout_seconds', 30.0);
                $chunkSize = (int) config('reconciliation.company_chunk_size', 50);

                $lastId = 0;

                while (true) {
                    if ($singleCompanyList !== null) {
                        $companies = collect($singleCompanyList);
                    } else {
                        // 5. Keyset / chunked company retrieval avoiding loading all companies at once
                        $companies = Company::query()
                            ->select(['id', 'public_id'])
                            ->where('id', '>', $lastId)
                            ->orderBy('id', 'asc')
                            ->limit($chunkSize)
                            ->get();

                        if ($companies->isEmpty()) {
                            break;
                        }
                    }

                    foreach ($companies as $company) {
                        $lastId = (int) $company->id;
                        $companyDomainResults = [];
                        $companyCompletedAllDomains = true;

                        foreach (self::DOMAINS as $domain) {
                            $elapsed = microtime(true) - $startTime;
                            $remainingBudget = $maxSeconds - $elapsed;

                            // Bounded runtime check before spawning child process
                            if ($remainingBudget <= 5.0) {
                                Log::warning("Reconciliation orchestration bounded runtime budget reached ({$elapsed}s >= {$maxSeconds}s). Aborting remaining domains.");
                                $timedOut = true;
                                $companyCompletedAllDomains = false;
                                break 2;
                            }

                            $taskTimeout = min($perTaskTimeout, max(1.0, $remainingBudget - 2.0));
                            $commandName = self::DOMAIN_COMMANDS[$domain];

                            // 6. Bounded child process execution through existing console
                            $processResult = $this->processRunner->runDomain(
                                $commandName,
                                (string) $company->public_id,
                                $taskTimeout
                            );

                            if ($processResult['timedOut']) {
                                Log::warning("Reconciliation child process timed out for domain [{$domain}] on company [{$company->public_id}].");
                                $timedOut = true;
                                $domainSummary[$domain]['healthy'] = false;
                                $companyDomainResults[$domain] = false;
                                $companyCompletedAllDomains = false;

                                $findings[] = [
                                    'company_public_id' => (string) $company->public_id,
                                    'domain' => $domain,
                                    'category' => ReconciliationAlertSanitizer::CATEGORY_TIMEOUT,
                                    'status' => 'TIMEOUT',
                                    'count' => 1,
                                ];
                                $domainSummary[$domain]['violation_count'] += 1;

                                break 2;
                            }

                            if ($processResult['success']) {
                                $companyDomainResults[$domain] = true;
                            } else {
                                $domainSummary[$domain]['healthy'] = false;
                                $companyDomainResults[$domain] = false;

                                // 7. Extract and sanitize findings to allowlisted category/count/status only
                                $sanitizedFindings = $this->extractAndSanitizeFindings(
                                    $domain,
                                    (string) $company->public_id,
                                    $processResult['output'],
                                    $processResult['errorMessage']
                                );

                                $domainSummary[$domain]['violation_count'] += count($sanitizedFindings);
                                foreach ($sanitizedFindings as $f) {
                                    $findings[] = $f;
                                }
                            }
                        }

                        // 8. Correct companiesAudited count: actual completed companies only!
                        $companiesAudited++;

                        if ($recordPlatformHealth) {
                            try {
                                DB::table('platform_company_health')->updateOrInsert(
                                    ['company_id' => $company->id],
                                    ['domains' => json_encode($companyDomainResults, JSON_THROW_ON_ERROR), 'checked_at' => now()]
                                );
                            } catch (Throwable $dbEx) {
                                Log::warning('Platform company health snapshot update skipped: '.ReconciliationAlertSanitizer::redactExceptionMessage($dbEx));
                            }
                        }
                    }

                    if ($singleCompanyList !== null || $timedOut) {
                        break;
                    }
                }

                $duration = round(microtime(true) - $startTime, 3);
                $hasViolations = ! empty($findings);

                $status = match (true) {
                    $timedOut => 'TIMEOUT_ABORTED',
                    $hasViolations => 'NONZERO_FINDINGS',
                    $companiesAudited === 0 && $companyPublicId !== null => 'FAILURE',
                    default => 'HEALTHY',
                };

                $result = new ReconciliationRunResult(
                    status: $status,
                    durationSeconds: $duration,
                    companiesAudited: $companiesAudited,
                    domainSummary: $domainSummary,
                    findings: $findings,
                );

                // 9. Alert dispatching: TIMEOUT_ABORTED never sends HEALTHY heartbeat
                try {
                    if ($timedOut) {
                        $this->alertService->sendFailureAlert(
                            new RuntimeException("Reconciliation orchestration timed out after {$duration}s (TIMEOUT_ABORTED)."),
                            $result
                        );
                    } elseif ($hasViolations) {
                        $this->alertService->sendNonzeroAlert($result);
                    } else {
                        $this->alertService->sendHeartbeat($result);
                    }
                } catch (Throwable $alertEx) {
                    Log::warning('Alert dispatch failed: '.ReconciliationAlertSanitizer::redactExceptionMessage($alertEx));
                }

                return $result;
            });
        } catch (Throwable $e) {
            $duration = round(microtime(true) - $startTime, 3);
            $redactedMessage = ReconciliationAlertSanitizer::redactExceptionMessage($e);

            $failedResult = new ReconciliationRunResult(
                status: 'FAILURE',
                durationSeconds: $duration,
                companiesAudited: $companiesAudited,
                domainSummary: $domainSummary,
                errorMessage: $redactedMessage,
            );

            try {
                $this->alertService->sendFailureAlert($e, $failedResult);
            } catch (Throwable) {
                // Alert service failure safety
            }

            return $failedResult;
        } finally {
            // 10. Always preserve and restore CompanyContext and release mutex lock
            try {
                if ($prevCompany !== null) {
                    $context->setCompany($prevCompany, $prevUser);
                } else {
                    $context->clear(sessionless: true);
                }
            } catch (Throwable $contextEx) {
                Log::warning('Failed restoring previous CompanyContext in reconciliation finally: '.$contextEx->getMessage());
            }

            try {
                $lock->release();
            } catch (Throwable) {
                // Mutex release safety
            }
        }
    }

    /**
     * Extracts violation lines and sanitizes them into allowlisted category, count, and status.
     * Guarantees zero raw finding, exception text, or plain amounts in findings.
     *
     * @return list<array{company_public_id: string, domain: string, category: string, status: string, count: int}>
     */
    protected function extractAndSanitizeFindings(
        string $domain,
        string $companyPublicId,
        string $output,
        ?string $errorMessage = null
    ): array {
        $findings = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '[x]') || str_starts_with($trimmed, '-') || str_contains($trimmed, 'UNHEALTHY') || str_contains($trimmed, 'INTEGRITY VIOLATIONS') || str_contains($trimmed, 'Violations found')) {
                $sanitized = $this->sanitizer->sanitize($domain, $trimmed);
                $findings[] = [
                    'company_public_id' => $companyPublicId,
                    'domain' => $domain,
                    'category' => $sanitized['category'],
                    'status' => 'UNHEALTHY',
                    'count' => 1,
                ];
            }
        }

        if (empty($findings)) {
            $category = $this->sanitizer->classifyCategory($domain, $errorMessage ?? $output);
            $findings[] = [
                'company_public_id' => $companyPublicId,
                'domain' => $domain,
                'category' => $category,
                'status' => 'UNHEALTHY',
                'count' => 1,
            ];
        }

        return $findings;
    }

    /**
     * @return array<string, array{healthy: bool, violation_count: int}>
     */
    public function emptyDomainSummary(bool $healthy = true): array
    {
        return [
            'accounting' => ['healthy' => $healthy, 'violation_count' => 0],
            'inventory' => ['healthy' => $healthy, 'violation_count' => 0],
            'sales' => ['healthy' => $healthy, 'violation_count' => 0],
            'payables' => ['healthy' => $healthy, 'violation_count' => 0],
            'money' => ['healthy' => $healthy, 'violation_count' => 0],
            'phase7' => ['healthy' => $healthy, 'violation_count' => 0],
        ];
    }
}
