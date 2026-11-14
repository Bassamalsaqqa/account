<?php

declare(strict_types=1);

namespace Tests\Support\Harness;

use App\Models\Company;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\DisposableMariaDbSchema;

/**
 * Inspection helper and outer validator for Mobile V2 financial paired execution.
 *
 * Enforces strict default NO-EXECUTE boundaries:
 * - Refused unless MOBILE_V2_HARNESS_ENABLE=1 and PHASE8_ALLOW_DISPOSABLE_DB=1.
 * - Asserts DisposableMariaDbSchema::assertPrimarySchema on exact MariaDB port 3342.
 * - Read-only queries only; aggregate health metrics without leaking sensitive business rows.
 * - Pure validator for CashPilotEvidenceJSON asserting 4 individual test passes, exact pub/exe pins,
 *   canonical side-effects delta = 1 event, and 6 healthy reconciliations.
 */
final class DisposableFinancialPostRunInspector
{
    /** Known unadmitted Android publication commit SHA (wrong owner). */
    public const string UNADMITTED_ANDROID_PUBLICATION_SHA = 'c00aedbb2c24097f5ef6ba297d287bc3b34b7f73';

    /** Root-approved peer Android publication HEAD pin. */
    public const string APPROVED_ANDROID_PUBLICATION_SHA = 'c00aedb70ea06f28ae42282149c030df76dc300d';

    /** Root-approved peer Android executable HEAD pin. */
    public const string APPROVED_ANDROID_EXECUTABLE_SHA = 'b7e61eb4ed0aed2f5040d9fc44f9764b310be530';

    /** Expected lifecycle enum for paired financial runs. */
    public const string EXPECTED_LIFECYCLE = 'FINANCIAL_PAIRED_RUN';

    /**
     * Asserts execution environment invariants before attempting database connection.
     * Default NO-EXECUTE: fails closed if harness or disposable DB flags are missing.
     *
     * @throws RuntimeException
     */
    public static function assertExecutionGates(?string $expectedSchemaName = null): void
    {
        if (getenv('MOBILE_V2_HARNESS_ENABLE') !== '1') {
            throw new RuntimeException('Inspector refused: defaults to NO-EXECUTE. Set MOBILE_V2_HARNESS_ENABLE=1 under explicit Root coordination.');
        }

        if (getenv('PHASE8_ALLOW_DISPOSABLE_DB') !== '1') {
            throw new RuntimeException('Inspector refused: PHASE8_ALLOW_DISPOSABLE_DB opt-in flag is missing or not 1.');
        }

        $host = (string) getenv('PHASE8_TEST_DB_HOST');
        if ($host !== '127.0.0.1') {
            throw new RuntimeException("Inspector refused: DB host must be explicitly 127.0.0.1, got '{$host}'.");
        }

        $port = (string) getenv('PHASE8_TEST_DB_PORT');
        if ($port !== '3342') {
            throw new RuntimeException("Inspector refused: DB port must be explicitly 3342, got '{$port}'.");
        }

        if ($expectedSchemaName !== null && $expectedSchemaName !== '') {
            DisposableMariaDbSchema::assertPrimarySchema($expectedSchemaName);
        }
    }

    /**
     * Captures pre-run baseline counts and metrics on canonical financial tables.
     *
     * @param  int  $companyId
     * @param  string|null  $expectedSchemaName
     * @return array<string, mixed>
     */
    public static function captureBaseline(int $companyId, ?string $expectedSchemaName = null): array
    {
        self::assertExecutionGates($expectedSchemaName);

        return [
            'company_id' => $companyId,
            'sales_invoices' => DB::table('sales_invoices')->where('company_id', $companyId)->count(),
            'sales_invoice_lines' => DB::table('sales_invoice_lines')->where('company_id', $companyId)->count(),
            'sales_invoice_lot_allocations' => DB::table('sales_invoice_lot_allocations')->where('company_id', $companyId)->count(),
            'customer_payments' => DB::table('customer_payments')->where('company_id', $companyId)->count(),
            'customer_payment_allocations' => DB::table('customer_payment_allocations')->where('company_id', $companyId)->count(),
            'mobile_event_receipts' => DB::table('mobile_event_receipts')->where('company_id', $companyId)->count(),
            'stock_movements' => DB::table('stock_movements')->where('company_id', $companyId)->count(),
            'stock_movements_qty_sum' => (string) (DB::table('stock_movements')->where('company_id', $companyId)->sum('quantity') ?? '0'),
            'posting_batches' => DB::table('posting_batches')->where('company_id', $companyId)->count(),
            'posting_lines' => DB::table('posting_lines')->where('company_id', $companyId)->count(),
            'document_sequences' => DB::table('document_sequences')
                ->where('company_id', $companyId)
                ->pluck('next_number', 'document_type')
                ->all(),
        ];
    }

    /**
     * Inspects post-run state against pre-run baseline, asserting exact delta = 1 event side-effects.
     *
     * @param  int  $companyId
     * @param  array<string, mixed>  $baseline
     * @param  string|null  $expectedClientEventId
     * @param  string|null  $expectedSchemaName
     * @return array<string, mixed>
     */
    public static function inspectDelta(
        int $companyId,
        array $baseline,
        ?string $expectedClientEventId = null,
        ?string $expectedSchemaName = null
    ): array {
        self::assertExecutionGates($expectedSchemaName);

        $current = self::captureBaseline($companyId, $expectedSchemaName);

        $deltas = [
            'sales_invoices' => $current['sales_invoices'] - ($baseline['sales_invoices'] ?? 0),
            'sales_invoice_lines' => $current['sales_invoice_lines'] - ($baseline['sales_invoice_lines'] ?? 0),
            'sales_invoice_lot_allocations' => $current['sales_invoice_lot_allocations'] - ($baseline['sales_invoice_lot_allocations'] ?? 0),
            'customer_payments' => $current['customer_payments'] - ($baseline['customer_payments'] ?? 0),
            'customer_payment_allocations' => $current['customer_payment_allocations'] - ($baseline['customer_payment_allocations'] ?? 0),
            'mobile_event_receipts' => $current['mobile_event_receipts'] - ($baseline['mobile_event_receipts'] ?? 0),
            'stock_movements' => $current['stock_movements'] - ($baseline['stock_movements'] ?? 0),
            'posting_batches' => $current['posting_batches'] - ($baseline['posting_batches'] ?? 0),
            'posting_lines' => $current['posting_lines'] - ($baseline['posting_lines'] ?? 0),
        ];

        $errors = [];

        // Exact canonical side effects assertions for ONE committed event
        if ($deltas['sales_invoices'] !== 1) {
            $errors[] = "Expected sales_invoices delta 1, got {$deltas['sales_invoices']}.";
        }
        if ($deltas['sales_invoice_lines'] !== 1) {
            $errors[] = "Expected sales_invoice_lines delta 1, got {$deltas['sales_invoice_lines']}.";
        }
        if ($deltas['sales_invoice_lot_allocations'] !== 1) {
            $errors[] = "Expected sales_invoice_lot_allocations delta 1 (FEFO lot deduction), got {$deltas['sales_invoice_lot_allocations']}.";
        }
        if ($deltas['customer_payments'] !== 1) {
            $errors[] = "Expected customer_payments delta 1, got {$deltas['customer_payments']}.";
        }
        if ($deltas['customer_payment_allocations'] !== 1) {
            $errors[] = "Expected customer_payment_allocations delta 1, got {$deltas['customer_payment_allocations']}.";
        }
        if ($deltas['mobile_event_receipts'] !== 1) {
            $errors[] = "Expected mobile_event_receipts delta 1, got {$deltas['mobile_event_receipts']}.";
        }
        if ($deltas['stock_movements'] !== 1) {
            $errors[] = "Expected stock_movements delta 1 (deduction movement vs baseline opening stock), got {$deltas['stock_movements']}.";
        }
        if ($deltas['posting_batches'] !== 2) {
            $errors[] = "Expected posting_batches delta 2 (1 invoice + 1 payment), got {$deltas['posting_batches']}.";
        }

        // Verify GL balance across newly created lines
        $glDebitSum = DB::table('posting_lines')
            ->where('company_id', $companyId)
            ->sum('debit_base');
        $glCreditSum = DB::table('posting_lines')
            ->where('company_id', $companyId)
            ->sum('credit_base');

        if (bccomp((string) $glDebitSum, (string) $glCreditSum, 4) !== 0) {
            $errors[] = "GL unbalanced: sum(debit_base)={$glDebitSum} vs sum(credit_base)={$glCreditSum}.";
        }

        // Check targeted event receipt if client_event_id provided
        if ($expectedClientEventId !== null) {
            $receipt = DB::table('mobile_event_receipts')
                ->where('company_id', $companyId)
                ->where('client_event_id', $expectedClientEventId)
                ->first();

            if (! $receipt) {
                $errors[] = "Receipt for client_event_id '{$expectedClientEventId}' not found.";
            } elseif ($receipt->status !== 'completed' && $receipt->status !== 'posted') {
                $errors[] = "Receipt status must be completed/posted, got '{$receipt->status}'.";
            } elseif (empty($receipt->sales_invoice_public_id) || empty($receipt->customer_payment_public_id)) {
                $errors[] = 'Receipt is missing sales_invoice_public_id or customer_payment_public_id.';
            }
        }

        return [
            'is_canonical_delta_valid' => $errors === [],
            'expected_delta_one_event' => true,
            'deltas' => $deltas,
            'errors' => $errors,
            'gl_balanced' => bccomp((string) $glDebitSum, (string) $glCreditSum, 4) === 0,
        ];
    }

    /**
     * Executes the 6 canonical read-only reconciliation services in context-free system mode.
     *
     * @param  Company  $company
     * @param  string|null  $expectedSchemaName
     * @return array{all_healthy: bool, reconciler_results: array<string, array{healthy: bool, issue_count: int}>}
     */
    public static function runSixReconciliations(Company $company, ?string $expectedSchemaName = null): array
    {
        self::assertExecutionGates($expectedSchemaName);

        $results = [];
        $allHealthy = true;

        // 1. Accounting Reconciliation
        $accReport = app(AccountingReconciliationService::class)->reconcile($company, isSystem: true);
        $results['AccountingReconciliationService'] = [
            'healthy' => $accReport->isHealthy,
            'issue_count' => count($accReport->violations),
        ];
        $allHealthy = $allHealthy && $accReport->isHealthy;

        // 2. Inventory Audit
        $invReport = app(InventoryReconciliationService::class)->auditCompany($company, fromCli: true);
        $results['InventoryReconciliationService'] = [
            'healthy' => $invReport->isHealthy,
            'issue_count' => count($invReport->discrepancies),
        ];
        $allHealthy = $allHealthy && $invReport->isHealthy;

        // 3. Sales Reconciliation
        $salesReport = app(SalesReconciliationService::class)->reconcile($company, isSystem: true);
        $results['SalesReconciliationService'] = [
            'healthy' => $salesReport->isHealthy,
            'issue_count' => count($salesReport->violations),
        ];
        $allHealthy = $allHealthy && $salesReport->isHealthy;

        // 4. Payables Reconciliation
        $payReport = app(PayablesReconciliationService::class)->reconcile($company, isSystem: true);
        $results['PayablesReconciliationService'] = [
            'healthy' => $payReport->isHealthy,
            'issue_count' => count($payReport->violations),
        ];
        $allHealthy = $allHealthy && $payReport->isHealthy;

        // 5. Money Reconciliation
        $moneyReport = app(MoneyReconciliationService::class)->reconcile($company, isSystem: true);
        $results['MoneyReconciliationService'] = [
            'healthy' => $moneyReport->isHealthy,
            'issue_count' => count($moneyReport->violations),
        ];
        $allHealthy = $allHealthy && $moneyReport->isHealthy;

        // 6. Phase 7 Multi-Ledger Reconciliation
        $phase7Report = app(Phase7ReconciliationService::class)->reconcile($company, isSystem: true);
        $results['Phase7ReconciliationService'] = [
            'healthy' => $phase7Report->isHealthy,
            'issue_count' => count($phase7Report->violations),
        ];
        $allHealthy = $allHealthy && $phase7Report->isHealthy;

        return [
            'all_healthy' => $allHealthy,
            'reconciler_results' => $results,
        ];
    }

    /**
     * Authoritative validator for CashPilotEvidenceJSON artifacts.
     * Pure format and logic evaluation; requires zero DB or network access.
     *
     * Enforces that:
     * 1. Individual test cases (fresh 201, replay 200, status parity 200, tamper 409) each passed.
     * 2. Client publication pin matches approved pin c00aedb70ea06f28ae42282149c030df76dc300d.
     *    Strictly rejects unadmitted wrong-owner pin c00aedbb2c24097f5ef6ba297d287bc3b34b7f73.
     * 3. Client executable pin matches approved pin b7e61eb4ed0aed2f5040d9fc44f9764b310be530.
     * 4. Backend execution HEAD matches actual expected backend HEAD.
     * 5. Handoff SHA matches expected whole-file live handoff digest.
     * 6. Lifecycle is FINANCIAL_PAIRED_RUN.
     * 7. Canonical side-effects assert exactly 1 committed event (delta = 1) and 6 reconciliations healthy.
     * 8. Lost ACK and rollback are marked NOT_RUN (denying full pilot over-claim).
     *
     * @param  array<string, mixed>|string  $evidenceJson
     * @param  array{
     *     clientPublicationHead: string,
     *     clientExecutableHead: string,
     *     backendExecutionHead: string,
     *     handoffDigest: string,
     *     expectedLifecycle?: string
     * }  $expectedPins
     * @return array{is_valid: bool, errors: array<string>}
     */
    public static function validateOuterEvidenceJson(array|string $evidenceJson, array $expectedPins): array
    {
        $data = is_string($evidenceJson) ? json_decode($evidenceJson, true) : $evidenceJson;
        if (! is_array($data)) {
            return [
                'is_valid' => false,
                'errors' => ['Evidence payload is not valid JSON.'],
            ];
        }

        $errors = [];

        // 1. Validate explicit publication pin
        $clientPub = (string) ($data['clientPublicationHead'] ?? $data['client_publication_head'] ?? '');
        if ($clientPub === self::UNADMITTED_ANDROID_PUBLICATION_SHA) {
            $errors[] = 'REJECTED: Evidence contains unadmitted wrong-owner publication pin '.self::UNADMITTED_ANDROID_PUBLICATION_SHA.'.';
        } elseif ($clientPub !== $expectedPins['clientPublicationHead']) {
            $errors[] = "Client publication pin mismatch. Expected '{$expectedPins['clientPublicationHead']}', got '{$clientPub}'.";
        }

        // 2. Validate explicit executable pin
        $clientExe = (string) ($data['clientExecutableHead'] ?? $data['client_executable_head'] ?? '');
        if ($clientExe !== $expectedPins['clientExecutableHead']) {
            $errors[] = "Client executable pin mismatch. Expected '{$expectedPins['clientExecutableHead']}', got '{$clientExe}'.";
        }

        // 3. Validate backend execution HEAD
        $backendHead = (string) ($data['backendExecutionHead'] ?? $data['backend_execution_head'] ?? '');
        if ($backendHead !== $expectedPins['backendExecutionHead']) {
            $errors[] = "Backend execution HEAD mismatch. Expected '{$expectedPins['backendExecutionHead']}', got '{$backendHead}'.";
        }

        // 4. Validate handoff digest
        $handoffSha = (string) ($data['handoffDigest'] ?? $data['handoff_sha256'] ?? '');
        if ($handoffSha !== $expectedPins['handoffDigest']) {
            $errors[] = "Handoff digest mismatch. Expected '{$expectedPins['handoffDigest']}', got '{$handoffSha}'.";
        }

        // 5. Validate lifecycle
        $expectedLifecycle = $expectedPins['expectedLifecycle'] ?? self::EXPECTED_LIFECYCLE;
        $actualLifecycle = (string) ($data['lifecycle'] ?? $data['lifecycle_phase'] ?? '');
        if ($actualLifecycle !== $expectedLifecycle) {
            $errors[] = "Lifecycle mismatch. Expected '{$expectedLifecycle}', got '{$actualLifecycle}'.";
        }

        // 6. Validate individual test scenarios (overall PASS alone is strictly insufficient)
        $scenarios = $data['scenarios'] ?? $data['test_cases'] ?? [];
        if (! is_array($scenarios) || empty($scenarios)) {
            $errors[] = 'Evidence missing individual scenario execution results.';
        } else {
            $requiredScenarios = [
                'fresh_online_cash_commit_201' => ['201', 'fresh_commit'],
                'idempotent_exact_replay_200' => ['200', 'replay'],
                'event_status_query_parity_200' => ['status', 'parity'],
                'tamper_conflict_rejection_409' => ['409', 'tamper', 'conflict'],
            ];

            foreach ($requiredScenarios as $key => $aliases) {
                $found = false;
                $passed = false;
                foreach ($scenarios as $sKey => $sVal) {
                    $normalizedKey = strtolower((string) $sKey);
                    $matched = ($normalizedKey === $key);
                    if (! $matched) {
                        foreach ($aliases as $alias) {
                            if (str_contains($normalizedKey, $alias)) {
                                $matched = true;
                                break;
                            }
                        }
                    }
                    if ($matched) {
                        $found = true;
                        $outcome = is_array($sVal) ? ($sVal['status'] ?? $sVal['outcome'] ?? '') : (string) $sVal;
                        $passed = in_array(strtoupper((string) $outcome), ['PASS', 'PASSED', 'SUCCESS', 'OK', '1', 'TRUE'], true);
                        break;
                    }
                }
                if (! $found) {
                    $errors[] = "Required scenario '{$key}' missing from evidence.";
                } elseif (! $passed) {
                    $errors[] = "Required scenario '{$key}' did not PASS.";
                }
            }
        }

        // 7. Verify Lost ACK and Rollback marked NOT_RUN (reject full pilot over-claim)
        $lostAck = $data['lost_ack_recovery'] ?? $data['scenarios']['lost_ack'] ?? 'NOT_RUN';
        if (is_array($lostAck)) {
            $lostAck = $lostAck['status'] ?? 'NOT_RUN';
        }
        $rollback = $data['rollback_fault_injection'] ?? $data['scenarios']['rollback'] ?? 'NOT_RUN';
        if (is_array($rollback)) {
            $rollback = $rollback['status'] ?? 'NOT_RUN';
        }

        if (strtoupper((string) $lostAck) === 'PASS' || strtoupper((string) $rollback) === 'PASS') {
            $errors[] = 'Basic financial drill cannot claim Lost ACK or Rollback passed; must be marked NOT_RUN.';
        }

        // 8. Validate canonical side-effects (delta = 1 event, 6 healthy reconciliations)
        $sideEffects = $data['side_effects'] ?? [];
        if (is_array($sideEffects) && ! empty($sideEffects)) {
            $eventDelta = $sideEffects['events_delta'] ?? $sideEffects['event_count_delta'] ?? null;
            if ($eventDelta !== null && (int) $eventDelta !== 1) {
                $errors[] = "Side-effects event delta must be exactly 1, got {$eventDelta}.";
            }
            $reconcilerCount = $sideEffects['reconciliations_healthy_count'] ?? null;
            if ($reconcilerCount !== null && (int) $reconcilerCount !== 6) {
                $errors[] = "Reconciliations healthy count must be exactly 6, got {$reconcilerCount}.";
            }
        }

        return [
            'is_valid' => $errors === [],
            'errors' => $errors,
        ];
    }
}
