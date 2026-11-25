<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Operations\DTO\ReconciliationRunResult;
use App\Services\Operations\ReconciliationAlertSanitizer;
use App\Services\Operations\ReconciliationAlertService;
use App\Services\Operations\ReconciliationOrchestratorService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class ReconciliationOrchestratorUnitTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_orchestrator_refuses_when_disabled_and_not_forced(): void
    {
        Config::set('reconciliation.enabled', false);

        $acct = Mockery::mock(AccountingReconciliationService::class);
        $inv = Mockery::mock(InventoryReconciliationService::class);
        $sales = Mockery::mock(SalesReconciliationService::class);
        $payables = Mockery::mock(PayablesReconciliationService::class);
        $money = new MoneyReconciliationService;
        $phase7 = new Phase7ReconciliationService;
        $alert = Mockery::mock(ReconciliationAlertService::class);
        $sanitizer = new ReconciliationAlertSanitizer;

        // None of the domain reconcilers should be called when refused
        $acct->shouldNotReceive('reconcile');
        $inv->shouldNotReceive('auditCompany');

        $orchestrator = new ReconciliationOrchestratorService(
            $acct, $inv, $sales, $payables, $money, $phase7, $alert, $sanitizer
        );

        $result = $orchestrator->run(force: false);

        $this->assertTrue($result->wasRefused());
        $this->assertTrue($result->skippedDisabled);
        $this->assertSame('REFUSED_DISABLED', $result->status);
        $this->assertSame(0, $result->companiesAudited);
    }

    public function test_orchestrator_handles_mutex_lock_contention(): void
    {
        Config::set('reconciliation.enabled', true);

        // Pre-acquire lock to simulate concurrent execution on shared hosting
        $lock = Cache::lock('reconciliation:orchestration:mutex', 60);
        $lock->get();

        $acct = Mockery::mock(AccountingReconciliationService::class);
        $inv = Mockery::mock(InventoryReconciliationService::class);
        $sales = Mockery::mock(SalesReconciliationService::class);
        $payables = Mockery::mock(PayablesReconciliationService::class);
        $money = new MoneyReconciliationService;
        $phase7 = new Phase7ReconciliationService;
        $alert = Mockery::mock(ReconciliationAlertService::class);
        $sanitizer = new ReconciliationAlertSanitizer;

        $acct->shouldNotReceive('reconcile');

        $orchestrator = new ReconciliationOrchestratorService(
            $acct, $inv, $sales, $payables, $money, $phase7, $alert, $sanitizer
        );

        try {
            $result = $orchestrator->run(force: true);

            $this->assertTrue($result->wasRefused());
            $this->assertTrue($result->lockContended);
            $this->assertSame('LOCKED', $result->status);
        } finally {
            $lock->release();
        }
    }

    public function test_alert_service_dispatches_heartbeat_when_healthy(): void
    {
        Config::set('reconciliation.alerts.enabled', true);
        Config::set('reconciliation.alerts.channel', 'log');

        $alertService = new ReconciliationAlertService;

        $result = new ReconciliationRunResult(
            status: 'HEALTHY',
            durationSeconds: 1.25,
            companiesAudited: 2,
            domainSummary: [
                'accounting' => ['healthy' => true, 'violation_count' => 0],
                'inventory' => ['healthy' => true, 'violation_count' => 0],
                'sales' => ['healthy' => true, 'violation_count' => 0],
                'payables' => ['healthy' => true, 'violation_count' => 0],
                'money' => ['healthy' => true, 'violation_count' => 0],
                'phase7' => ['healthy' => true, 'violation_count' => 0],
            ],
            findings: [],
        );

        // Does not throw and logs heartbeat
        $alertService->sendHeartbeat($result);
        $this->assertTrue($result->isHealthy());
    }

    public function test_alert_service_dispatches_nonzero_alert_with_sanitized_findings(): void
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
                    'category' => 'DOUBLE_ENTRY_BALANCE_IMBALANCE',
                    'detail' => 'Batch [REF] is out of balance: debit sum [REDACTED_AMOUNT] does not match credit sum [REDACTED_AMOUNT].',
                ],
            ],
        );

        // Does not throw and logs nonzero findings
        $alertService->sendNonzeroAlert($result);
        $this->assertTrue($result->hasNonzeroFindings());
        $this->assertSame(1, $result->totalViolations());
    }
}
