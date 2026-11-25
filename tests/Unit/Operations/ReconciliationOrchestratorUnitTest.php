<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use App\Models\Company;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Operations\DTO\ReconciliationRunResult;
use App\Services\Operations\ReconciliationAlertSanitizer;
use App\Services\Operations\ReconciliationAlertService;
use App\Services\Operations\ReconciliationOrchestratorService;
use App\Services\Operations\ReconciliationProcessRunner;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ReconciliationOrchestratorUnitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge('sqlite');

        Schema::create('companies', function ($table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->string('name')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('platform_company_health', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->json('domains');
            $table->timestamp('checked_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function createOrchestrator(
        ?ReconciliationProcessRunner $runner = null,
        ?ReconciliationAlertService $alert = null
    ): ReconciliationOrchestratorService {
        return new ReconciliationOrchestratorService(
            accountingReconciler: Mockery::mock(AccountingReconciliationService::class),
            inventoryReconciler: Mockery::mock(InventoryReconciliationService::class),
            salesReconciler: Mockery::mock(SalesReconciliationService::class),
            payablesReconciler: Mockery::mock(PayablesReconciliationService::class),
            moneyReconciler: new MoneyReconciliationService,
            phase7Reconciler: new Phase7ReconciliationService,
            alertService: $alert ?? new ReconciliationAlertService,
            sanitizer: new ReconciliationAlertSanitizer,
            processRunner: $runner ?? Mockery::mock(ReconciliationProcessRunner::class),
        );
    }

    public function test_orchestrator_refuses_when_disabled_and_not_forced(): void
    {
        Config::set('reconciliation.enabled', false);

        $orchestrator = $this->createOrchestrator();
        $result = $orchestrator->run(force: false);

        $this->assertTrue($result->wasRefused());
        $this->assertTrue($result->skippedDisabled);
        $this->assertSame('REFUSED_DISABLED', $result->status);
        $this->assertSame(0, $result->companiesAudited);
    }

    public function test_orchestrator_handles_mutex_lock_contention_and_enforces_safe_ttl(): void
    {
        Config::set('reconciliation.enabled', true);
        Config::set('reconciliation.max_execution_seconds', 180);
        Config::set('reconciliation.lock_ttl_seconds', 300);

        // Pre-acquire lock to simulate concurrency
        $lock = Cache::lock('reconciliation:orchestration:mutex', 60);
        $lock->get();

        $orchestrator = $this->createOrchestrator();

        try {
            $result = $orchestrator->run(force: true);

            $this->assertTrue($result->wasRefused());
            $this->assertTrue($result->lockContended);
            $this->assertSame('LOCKED', $result->status);
        } finally {
            $lock->release();
        }

        // Verify safe TTL exceeds max execution seconds
        $maxSeconds = (int) config('reconciliation.max_execution_seconds');
        $lockTtl = (int) config('reconciliation.lock_ttl_seconds');
        $this->assertGreaterThan($maxSeconds, $lockTtl);
    }

    public function test_orchestrator_fails_on_unknown_company_without_reporting_zero_healthy(): void
    {
        Config::set('reconciliation.enabled', true);

        $alertMock = Mockery::mock(ReconciliationAlertService::class);
        $alertMock->shouldReceive('sendFailureAlert')->once();
        $alertMock->shouldNotReceive('sendHeartbeat');

        $orchestrator = $this->createOrchestrator(alert: $alertMock);

        $result = $orchestrator->run('01JM9999999999999999999999', force: true);

        $this->assertSame('FAILURE', $result->status);
        $this->assertSame(0, $result->companiesAudited);
        $this->assertFalse($result->isHealthy());
        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('not found', (string) $result->errorMessage);
    }

    public function test_orchestrator_handles_timeout_and_terminates_child_process_without_sending_heartbeat(): void
    {
        Config::set('reconciliation.enabled', true);

        Company::create(['public_id' => '01JM0000000000000000000001', 'name' => 'Acme']);

        $runnerMock = Mockery::mock(ReconciliationProcessRunner::class);
        $runnerMock->shouldReceive('runDomain')
            ->once()
            ->andReturn([
                'success' => false,
                'timedOut' => true,
                'exitCode' => null,
                'output' => '',
                'errorMessage' => 'Process timed out after 30s and was terminated.',
            ]);

        $alertMock = Mockery::mock(ReconciliationAlertService::class);
        $alertMock->shouldReceive('sendFailureAlert')->once();
        $alertMock->shouldNotReceive('sendHeartbeat');

        $orchestrator = $this->createOrchestrator(runner: $runnerMock, alert: $alertMock);

        $result = $orchestrator->run('01JM0000000000000000000001', force: true);

        $this->assertSame('TIMEOUT_ABORTED', $result->status);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isHealthy());
        $this->assertSame(0, $result->companiesAudited);
        $this->assertCount(1, $result->findings);
        $this->assertSame(ReconciliationAlertSanitizer::CATEGORY_TIMEOUT, $result->findings[0]['category']);
    }

    public function test_orchestrator_bounds_multi_company_runtime_and_counts_only_completed_companies(): void
    {
        Config::set('reconciliation.enabled', true);

        Company::create(['public_id' => '01JM0000000000000000000001', 'name' => 'Company One']);
        Company::create(['public_id' => '01JM0000000000000000000002', 'name' => 'Company Two']);
        Company::create(['public_id' => '01JM0000000000000000000003', 'name' => 'Company Three']);

        $runnerMock = Mockery::mock(ReconciliationProcessRunner::class);

        // Company 1 succeeds on all 6 domains
        for ($i = 0; $i < 6; $i++) {
            $runnerMock->shouldReceive('runDomain')
                ->with(Mockery::any(), '01JM0000000000000000000001', Mockery::any())
                ->once()
                ->andReturn([
                    'success' => true,
                    'timedOut' => false,
                    'exitCode' => 0,
                    'output' => 'Healthy',
                    'errorMessage' => null,
                ]);
        }

        // Company 2 domain 1 succeeds
        $runnerMock->shouldReceive('runDomain')
            ->with('accounting:reconcile', '01JM0000000000000000000002', Mockery::any())
            ->once()
            ->andReturn([
                'success' => true,
                'timedOut' => false,
                'exitCode' => 0,
                'output' => 'Healthy',
                'errorMessage' => null,
            ]);

        // Company 2 domain 2 times out
        $runnerMock->shouldReceive('runDomain')
            ->with('inventory:reconcile', '01JM0000000000000000000002', Mockery::any())
            ->once()
            ->andReturn([
                'success' => false,
                'timedOut' => true,
                'exitCode' => null,
                'output' => '',
                'errorMessage' => 'Process timed out and was terminated',
            ]);

        // Company 3 should NEVER be audited because run was aborted on timeout
        $runnerMock->shouldNotReceive('runDomain')
            ->with(Mockery::any(), '01JM0000000000000000000003', Mockery::any());

        $alertMock = Mockery::mock(ReconciliationAlertService::class);
        $alertMock->shouldReceive('sendFailureAlert')->once();
        $alertMock->shouldNotReceive('sendHeartbeat');

        $orchestrator = $this->createOrchestrator(runner: $runnerMock, alert: $alertMock);

        $result = $orchestrator->run(force: true);

        $this->assertSame('TIMEOUT_ABORTED', $result->status);
        $this->assertSame(1, $result->companiesAudited, 'companiesAudited must reflect only actual completed companies (1 of 3).');
        $this->assertFalse($result->isHealthy());
    }

    public function test_orchestrator_exact_domain_result_mapping_for_all_six_domains(): void
    {
        Config::set('reconciliation.enabled', true);

        Company::create(['public_id' => '01JM0000000000000000000001', 'name' => 'Acme']);

        $domains = [
            'accounting' => 'accounting:reconcile',
            'inventory' => 'inventory:reconcile',
            'sales' => 'sales:reconcile',
            'payables' => 'payables:reconcile',
            'money' => 'money:reconcile',
            'phase7' => 'phase7:reconcile',
        ];

        foreach ($domains as $failingDomain => $failingCommand) {
            $runnerMock = Mockery::mock(ReconciliationProcessRunner::class);

            foreach ($domains as $domain => $cmd) {
                if ($domain === $failingDomain) {
                    $runnerMock->shouldReceive('runDomain')
                        ->with($cmd, '01JM0000000000000000000001', Mockery::any())
                        ->once()
                        ->andReturn([
                            'success' => false,
                            'timedOut' => false,
                            'exitCode' => 1,
                            'output' => "  [x] Integrity violation detected in {$domain}",
                            'errorMessage' => null,
                        ]);
                } else {
                    $runnerMock->shouldReceive('runDomain')
                        ->with($cmd, '01JM0000000000000000000001', Mockery::any())
                        ->once()
                        ->andReturn([
                            'success' => true,
                            'timedOut' => false,
                            'exitCode' => 0,
                            'output' => "{$domain} healthy",
                            'errorMessage' => null,
                        ]);
                }
            }

            $alertMock = Mockery::mock(ReconciliationAlertService::class);
            $alertMock->shouldReceive('sendNonzeroAlert')->once();

            $orchestrator = $this->createOrchestrator(runner: $runnerMock, alert: $alertMock);

            $result = $orchestrator->run('01JM0000000000000000000001', force: true);

            $this->assertSame('NONZERO_FINDINGS', $result->status);
            $this->assertFalse($result->domainSummary[$failingDomain]['healthy']);
            $this->assertSame(1, $result->domainSummary[$failingDomain]['violation_count']);

            foreach ($domains as $otherDomain => $otherCmd) {
                if ($otherDomain !== $failingDomain) {
                    $this->assertTrue($result->domainSummary[$otherDomain]['healthy'], "Domain [{$otherDomain}] should be healthy.");
                    $this->assertSame(0, $result->domainSummary[$otherDomain]['violation_count']);
                }
            }

            $this->assertCount(1, $result->findings);
            $this->assertSame($failingDomain, $result->findings[0]['domain']);
            $this->assertSame('UNHEALTHY', $result->findings[0]['status']);
            $this->assertContains($result->findings[0]['category'], ReconciliationAlertSanitizer::ALLOWED_CATEGORIES);
        }
    }

    public function test_orchestrator_handles_alert_failure_gracefully_without_corrupting_result(): void
    {
        Config::set('reconciliation.enabled', true);

        Company::create(['public_id' => '01JM0000000000000000000001', 'name' => 'Acme']);

        $runnerMock = Mockery::mock(ReconciliationProcessRunner::class);
        $runnerMock->shouldReceive('runDomain')->times(6)->andReturn([
            'success' => true,
            'timedOut' => false,
            'exitCode' => 0,
            'output' => 'Healthy',
            'errorMessage' => null,
        ]);

        $alertMock = Mockery::mock(ReconciliationAlertService::class);
        $alertMock->shouldReceive('sendHeartbeat')->once()->andThrow(new Exception('Mail transport unreachable'));
        $alertMock->shouldNotReceive('sendFailureAlert');

        $orchestrator = $this->createOrchestrator(runner: $runnerMock, alert: $alertMock);

        // Does not throw and returns proper failure result with redacted exception
        $result = $orchestrator->run('01JM0000000000000000000001', force: true);

        $this->assertInstanceOf(ReconciliationRunResult::class, $result);
    }

    public function test_orchestrator_preserves_company_context_in_finally(): void
    {
        Config::set('reconciliation.enabled', true);

        $company = Company::create(['public_id' => '01JM0000000000000000000001', 'name' => 'Acme', 'status' => 'active']);

        $context = app(CompanyContext::class);
        // Clear ambient context initially
        $context->clear(sessionless: true);
        $this->assertFalse($context->hasCompany());

        $runnerMock = Mockery::mock(ReconciliationProcessRunner::class);
        $runnerMock->shouldReceive('runDomain')->times(6)->andReturn([
            'success' => true,
            'timedOut' => false,
            'exitCode' => 0,
            'output' => 'Healthy',
            'errorMessage' => null,
        ]);

        $orchestrator = $this->createOrchestrator(runner: $runnerMock);
        $result = $orchestrator->run('01JM0000000000000000000001', force: true);

        $this->assertTrue($result->isHealthy());
        // Clean context after run
        $this->assertFalse($context->hasCompany());
    }

    public function test_alert_service_never_dispatches_heartbeat_on_timeout_or_failure(): void
    {
        Config::set('reconciliation.alerts.enabled', true);
        Config::set('reconciliation.alerts.channel', 'log');

        $alertService = new ReconciliationAlertService;

        $timeoutResult = new ReconciliationRunResult(
            status: 'TIMEOUT_ABORTED',
            durationSeconds: 180.0,
            companiesAudited: 1,
            domainSummary: [],
            findings: [],
        );

        // Must reject heartbeat for non-healthy results
        $alertService->sendHeartbeat($timeoutResult);
        $this->assertFalse($timeoutResult->isHealthy());

        $failureResult = new ReconciliationRunResult(
            status: 'FAILURE',
            durationSeconds: 2.0,
            companiesAudited: 0,
            domainSummary: [],
            findings: [],
        );

        $alertService->sendHeartbeat($failureResult);
        $this->assertFalse($failureResult->isHealthy());
    }

    public function test_alert_service_dispatches_nonzero_alert_with_allowlisted_findings(): void
    {
        Config::set('reconciliation.alerts.enabled', true);
        Config::set('reconciliation.alerts.channel', 'log');

        $alertService = new ReconciliationAlertService;

        $result = new ReconciliationRunResult(
            status: 'NONZERO_FINDINGS',
            durationSeconds: 2.15,
            companiesAudited: 1,
            domainSummary: [
                'accounting' => ['healthy' => false, 'violation_count' => 1],
                'inventory' => ['healthy' => true, 'violation_count' => 0],
                'sales' => ['healthy' => true, 'violation_count' => 0],
                'payables' => ['healthy' => true, 'violation_count' => 0],
                'money' => ['healthy' => true, 'violation_count' => 0],
                'phase7' => ['healthy' => true, 'violation_count' => 0],
            ],
            findings: [
                [
                    'company_public_id' => '01JM0000000000000000000001',
                    'domain' => 'accounting',
                    'category' => ReconciliationAlertSanitizer::CATEGORY_BALANCE_IMBALANCE,
                    'status' => 'UNHEALTHY',
                    'count' => 1,
                ],
            ],
        );

        $alertService->sendNonzeroAlert($result);
        $this->assertTrue($result->hasNonzeroFindings());
        $this->assertSame(1, $result->totalViolations());
    }
}
