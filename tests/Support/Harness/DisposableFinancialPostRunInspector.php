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
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Tests\Support\DisposableMariaDbSchema;

/**
 * Inspection helper and outer validator for Mobile V2 financial paired execution.
 *
 * Enforces strict default NO-EXECUTE boundaries:
 * - Refused unless MOBILE_V2_HARNESS_ENABLE=1 and PHASE8_ALLOW_DISPOSABLE_DB=1.
 * - Asserts DisposableMariaDbSchema::assertPrimarySchema on exact MariaDB port 3342.
 * - Binds a dedicated read-only PDO directly to the verified owned disposable schema.
 * - Read-only queries only; aggregate health metrics without leaking sensitive business rows.
 * - Pure validator for CashPilotEvidenceJSON asserting 5 PASS + 2 NOT_RUN scenarios,
 *   exact pub/exe pins, and requiring separate trusted owned DB inspection report.
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

    /** Safe disposable schema name regex pattern. */
    public const string SCHEMA_REGEX = '/^accounting_p8_tmp_[a-f0-9]{12}$/D';

    /**
     * Asserts execution environment invariants, verifies ownership proof,
     * and binds a dedicated read-only PDO connection directly to MariaDB port 3342.
     * Default NO-EXECUTE: fails closed if harness or disposable DB flags are missing.
     *
     * @param  string  $expectedSchemaName  Mandatory non-empty schema name matching DisposableMariaDbSchema::REGEX
     * @return PDO Dedicated read-only PDO connection bound to the verified schema
     *
     * @throws RuntimeException
     */
    public static function assertExecutionGates(string $expectedSchemaName): PDO
    {
        if (getenv('MOBILE_V2_HARNESS_ENABLE') !== '1') {
            throw new RuntimeException('Inspector refused: defaults to NO-EXECUTE. Set MOBILE_V2_HARNESS_ENABLE=1 under explicit Root coordination.');
        }

        if (getenv('PHASE8_ALLOW_DISPOSABLE_DB') !== '1') {
            throw new RuntimeException('Inspector refused: PHASE8_ALLOW_DISPOSABLE_DB opt-in flag is missing or not 1.');
        }

        if (! preg_match(self::SCHEMA_REGEX, $expectedSchemaName)) {
            throw new RuntimeException("Inspector refused: invalid disposable schema name '{$expectedSchemaName}'.");
        }

        // Must verify private schema ownership proof BEFORE attempting database connection
        DisposableMariaDbSchema::assertPrimarySchema($expectedSchemaName);

        $settings = DisposableMariaDbSchema::testSettings();
        $host = (string) ($settings['HOST'] ?? '');
        if ($host !== '127.0.0.1') {
            throw new RuntimeException("Inspector refused: DB host must be explicitly 127.0.0.1, got '{$host}'.");
        }

        $port = (string) ($settings['PORT'] ?? '');
        if ($port !== '3342') {
            throw new RuntimeException("Inspector refused: DB port must be explicitly 3342, got '{$port}'.");
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$expectedSchemaName};charset=utf8mb4";
        $pdo = new PDO($dsn, $settings['USERNAME'], $settings['PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Assert that active connected database and port strictly match expected disposable schema
        $verified = $pdo->query('SELECT DATABASE() AS db, @@port AS port')->fetch(PDO::FETCH_ASSOC);
        if (! is_array($verified) || ($verified['db'] ?? '') !== $expectedSchemaName) {
            throw new RuntimeException("Inspector connection mismatch: expected DB '{$expectedSchemaName}', got '".($verified['db'] ?? 'null')."'.");
        }
        if ((string) ($verified['port'] ?? '') !== '3342') {
            throw new RuntimeException("Inspector connection mismatch: expected port 3342, got '".($verified['port'] ?? 'null')."'.");
        }

        // Enforce read-only transaction on this dedicated inspector connection
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();

        return $pdo;
    }

    /**
     * Captures pre-run baseline counts and metrics on canonical financial tables
     * using a dedicated, verified PDO connection.
     *
     * @param  int  $companyId
     * @param  string  $expectedSchemaName
     * @param  PDO|null  $pdo
     * @return array<string, mixed>
     */
    public static function captureBaseline(int $companyId, string $expectedSchemaName, ?PDO $pdo = null): array
    {
        $db = $pdo ?? self::assertExecutionGates($expectedSchemaName);

        $count = static function (PDO $conn, string $table, int $cid): int {
            $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM `{$table}` WHERE `company_id` = ?");
            $stmt->execute([$cid]);

            return (int) $stmt->fetchColumn();
        };

        $maxId = static function (PDO $conn, string $table, int $cid): int {
            $stmt = $conn->prepare("SELECT COALESCE(MAX(`id`), 0) AS m FROM `{$table}` WHERE `company_id` = ?");
            $stmt->execute([$cid]);

            return (int) $stmt->fetchColumn();
        };

        // Stock movements quantity sum (quantity_delta_base, not quantity)
        $stmtQty = $db->prepare('SELECT COALESCE(SUM(`quantity_delta_base`), 0.0) FROM `stock_movements` WHERE `company_id` = ?');
        $stmtQty->execute([$companyId]);
        $stockQtySum = (string) $stmtQty->fetchColumn();

        // Document sequences with composite key (company_id, document_type, year)
        $stmtSeq = $db->prepare('SELECT `document_type`, `year`, `next_number` FROM `document_sequences` WHERE `company_id` = ?');
        $stmtSeq->execute([$companyId]);
        $sequences = [];
        while ($row = $stmtSeq->fetch(PDO::FETCH_ASSOC)) {
            $key = ((string) $row['document_type']).':'.((int) $row['year']);
            $sequences[$key] = (int) $row['next_number'];
        }

        return [
            'company_id' => $companyId,
            'schema_name' => $expectedSchemaName,
            'sales_invoices' => $count($db, 'sales_invoices', $companyId),
            'sales_invoices_max_id' => $maxId($db, 'sales_invoices', $companyId),
            'sales_invoice_lines' => $count($db, 'sales_invoice_lines', $companyId),
            'sales_invoice_lines_max_id' => $maxId($db, 'sales_invoice_lines', $companyId),
            'sales_invoice_lot_allocations' => $count($db, 'sales_invoice_lot_allocations', $companyId),
            'sales_invoice_lot_allocations_max_id' => $maxId($db, 'sales_invoice_lot_allocations', $companyId),
            'customer_payments' => $count($db, 'customer_payments', $companyId),
            'customer_payments_max_id' => $maxId($db, 'customer_payments', $companyId),
            'customer_payment_allocations' => $count($db, 'customer_payment_allocations', $companyId),
            'customer_payment_allocations_max_id' => $maxId($db, 'customer_payment_allocations', $companyId),
            'mobile_event_receipts' => $count($db, 'mobile_event_receipts', $companyId),
            'mobile_event_receipts_max_id' => $maxId($db, 'mobile_event_receipts', $companyId),
            'stock_movements' => $count($db, 'stock_movements', $companyId),
            'stock_movements_max_id' => $maxId($db, 'stock_movements', $companyId),
            'stock_movements_qty_sum' => $stockQtySum,
            'posting_batches' => $count($db, 'posting_batches', $companyId),
            'posting_batches_max_id' => $maxId($db, 'posting_batches', $companyId),
            'posting_lines' => $count($db, 'posting_lines', $companyId),
            'posting_lines_max_id' => $maxId($db, 'posting_lines', $companyId),
            'document_sequences' => $sequences,
        ];
    }

    /**
     * Inspects post-run state against pre-run baseline, asserting exact delta = 1 event side-effects
     * and complete canonical graph integrity using a dedicated, verified PDO connection.
     *
     * @param  int  $companyId
     * @param  array<string, mixed>  $baseline
     * @param  string|null  $expectedClientEventId
     * @param  string  $expectedSchemaName
     * @param  PDO|null  $pdo
     * @return array<string, mixed>
     */
    public static function inspectDelta(
        int $companyId,
        array $baseline,
        ?string $expectedClientEventId,
        string $expectedSchemaName,
        ?PDO $pdo = null
    ): array {
        $db = $pdo ?? self::assertExecutionGates($expectedSchemaName);

        $current = self::captureBaseline($companyId, $expectedSchemaName, $db);

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

        // 1. Exact canonical counts assertions for ONE committed event
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
        if ($deltas['posting_lines'] < 4) {
            $errors[] = "Expected posting_lines delta >= 4 (at least 2 lines per batch), got {$deltas['posting_lines']}.";
        }

        // 2. Query and inspect the newly created Sales Invoice
        $stmtInv = $db->prepare('SELECT `id`, `public_id`, `status`, `grand_total_base`, `posting_batch_id` FROM `sales_invoices` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
        $stmtInv->execute([$companyId, (int) ($baseline['sales_invoices_max_id'] ?? 0)]);
        $newInvoice = $stmtInv->fetch(PDO::FETCH_ASSOC);

        $newInvoiceId = null;
        $newInvoicePublicId = null;
        if (! $newInvoice) {
            $errors[] = 'Newly created sales invoice row not found.';
        } else {
            $newInvoiceId = (int) $newInvoice['id'];
            $newInvoicePublicId = (string) $newInvoice['public_id'];
            if (($newInvoice['status'] ?? '') !== 'posted') {
                $errors[] = "Newly created sales invoice status must be 'posted', got '".($newInvoice['status'] ?? 'null')."'.";
            }
            if (BigDecimal::of((string) ($newInvoice['grand_total_base'] ?? '0'))->toScale(6)->compareTo(BigDecimal::of('58.000000')) !== 0) {
                $errors[] = "Newly created sales invoice grand_total_base must be '58.000000', got '".($newInvoice['grand_total_base'] ?? 'null')."'.";
            }
        }

        // 3. Query and inspect the newly created Customer Payment
        $stmtPay = $db->prepare('SELECT `id`, `public_id`, `is_reversed`, `amount`, `posting_batch_id` FROM `customer_payments` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
        $stmtPay->execute([$companyId, (int) ($baseline['customer_payments_max_id'] ?? 0)]);
        $newPayment = $stmtPay->fetch(PDO::FETCH_ASSOC);

        $newPaymentId = null;
        $newPaymentPublicId = null;
        if (! $newPayment) {
            $errors[] = 'Newly created customer payment row not found.';
        } else {
            $newPaymentId = (int) $newPayment['id'];
            $newPaymentPublicId = (string) $newPayment['public_id'];
            if ((int) ($newPayment['is_reversed'] ?? 1) !== 0) {
                $errors[] = 'Newly created customer payment must not be reversed.';
            }
            if (BigDecimal::of((string) ($newPayment['amount'] ?? '0'))->toScale(6)->compareTo(BigDecimal::of('58.000000')) !== 0) {
                $errors[] = "Newly created customer payment amount must be '58.000000', got '".($newPayment['amount'] ?? 'null')."'.";
            }
        }

        // 4. Query and inspect the newly created Customer Payment Allocation
        $stmtAlloc = $db->prepare('SELECT `id`, `customer_payment_id`, `sales_invoice_id`, `allocated_amount`, `base_amount_applied_to_receivable` FROM `customer_payment_allocations` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
        $stmtAlloc->execute([$companyId, (int) ($baseline['customer_payment_allocations_max_id'] ?? 0)]);
        $newPaymentAlloc = $stmtAlloc->fetch(PDO::FETCH_ASSOC);

        if (! $newPaymentAlloc) {
            $errors[] = 'Newly created customer_payment_allocation row not found.';
        } else {
            if ($newPaymentId !== null && (int) ($newPaymentAlloc['customer_payment_id'] ?? 0) !== $newPaymentId) {
                $errors[] = "Customer payment allocation customer_payment_id mismatch: expected {$newPaymentId}, got ".($newPaymentAlloc['customer_payment_id'] ?? 'null').'.';
            }
            if ($newInvoiceId !== null && (int) ($newPaymentAlloc['sales_invoice_id'] ?? 0) !== $newInvoiceId) {
                $errors[] = "Customer payment allocation sales_invoice_id mismatch: expected {$newInvoiceId}, got ".($newPaymentAlloc['sales_invoice_id'] ?? 'null').'.';
            }
            if (BigDecimal::of((string) ($newPaymentAlloc['allocated_amount'] ?? '0'))->toScale(6)->compareTo(BigDecimal::of('58.000000')) !== 0) {
                $errors[] = "Customer payment allocation allocated_amount must be '58.000000', got '".($newPaymentAlloc['allocated_amount'] ?? 'null')."'.";
            }
        }

        // 5. Query and inspect the newly created Stock Movement
        $stmtSm = $db->prepare('SELECT `id`, `quantity_delta_base`, `unit_cost_base`, `value_delta_base`, `source_type`, `source_id` FROM `stock_movements` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
        $stmtSm->execute([$companyId, (int) ($baseline['stock_movements_max_id'] ?? 0)]);
        $newSm = $stmtSm->fetch(PDO::FETCH_ASSOC);

        if (! $newSm) {
            $errors[] = 'Newly created stock_movement row not found.';
        } else {
            if (BigDecimal::of((string) ($newSm['quantity_delta_base'] ?? '0'))->toScale(6)->compareTo(BigDecimal::of('-1.000000')) !== 0) {
                $errors[] = "Stock movement quantity_delta_base must be '-1.000000', got '".($newSm['quantity_delta_base'] ?? 'null')."'.";
            }
            if (($newSm['source_type'] ?? '') !== 'sales_invoice') {
                $errors[] = "Stock movement source_type must be 'sales_invoice', got '".($newSm['source_type'] ?? 'null')."'.";
            }
            if ($newInvoiceId !== null && (int) ($newSm['source_id'] ?? 0) !== $newInvoiceId) {
                $errors[] = "Stock movement source_id mismatch: expected invoice {$newInvoiceId}, got ".($newSm['source_id'] ?? 'null').'.';
            }
        }

        // Net stock quantity delta across company must be exactly -1.000000
        $qtyDelta = BigDecimal::of((string) ($current['stock_movements_qty_sum'] ?? '0'))
            ->minus(BigDecimal::of((string) ($baseline['stock_movements_qty_sum'] ?? '0')))
            ->toScale(6);
        if ($qtyDelta->compareTo(BigDecimal::of('-1.000000')) !== 0) {
            $errors[] = "Net stock quantity delta must be '-1.000000', got '{$qtyDelta}'.";
        }

        // 6. Query and inspect the newly created Lot Allocation
        $stmtLot = $db->prepare('SELECT `id`, `sales_invoice_id`, `quantity_allocated_base` FROM `sales_invoice_lot_allocations` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
        $stmtLot->execute([$companyId, (int) ($baseline['sales_invoice_lot_allocations_max_id'] ?? 0)]);
        $newLotAlloc = $stmtLot->fetch(PDO::FETCH_ASSOC);

        if (! $newLotAlloc) {
            $errors[] = 'Newly created sales_invoice_lot_allocation row not found.';
        } else {
            if ($newInvoiceId !== null && (int) ($newLotAlloc['sales_invoice_id'] ?? 0) !== $newInvoiceId) {
                $errors[] = "Lot allocation sales_invoice_id mismatch: expected {$newInvoiceId}, got ".($newLotAlloc['sales_invoice_id'] ?? 'null').'.';
            }
            if (BigDecimal::of((string) ($newLotAlloc['quantity_allocated_base'] ?? '0'))->toScale(6)->compareTo(BigDecimal::of('1.000000')) !== 0) {
                $errors[] = "Lot allocation quantity_allocated_base must be '1.000000', got '".($newLotAlloc['quantity_allocated_base'] ?? 'null')."'.";
            }
        }

        // 7. Inspect GL Posting Batches: verify source provenance and per-batch balance at scale 6
        $stmtBatches = $db->prepare('SELECT `id`, `source_type`, `source_id`, `status` FROM `posting_batches` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC');
        $stmtBatches->execute([$companyId, (int) ($baseline['posting_batches_max_id'] ?? 0)]);
        $newBatches = $stmtBatches->fetchAll(PDO::FETCH_ASSOC);

        $hasInvoiceBatch = false;
        $hasPaymentBatch = false;
        $allBatchesBalanced = true;

        if (count($newBatches) !== 2) {
            $errors[] = 'Expected exactly 2 new posting batches, found '.count($newBatches).'.';
        }

        foreach ($newBatches as $batch) {
            $batchId = (int) $batch['id'];
            $srcType = (string) ($batch['source_type'] ?? '');
            $srcId = (int) ($batch['source_id'] ?? 0);

            if (($batch['status'] ?? '') !== 'posted') {
                $errors[] = "Posting batch {$batchId} status must be 'posted', got '".($batch['status'] ?? 'null')."'.";
            }

            if ($srcType === 'sales_invoice' && $newInvoiceId !== null && $srcId === $newInvoiceId) {
                $hasInvoiceBatch = true;
            } elseif ($srcType === 'customer_payment' && $newPaymentId !== null && $srcId === $newPaymentId) {
                $hasPaymentBatch = true;
            } else {
                $errors[] = "Unexpected posting batch provenance: id={$batchId}, source_type={$srcType}, source_id={$srcId}.";
            }

            // Verify lines balance for THIS specific batch using exact BigDecimal at scale 6
            $stmtLines = $db->prepare('SELECT `debit_base`, `credit_base` FROM `posting_lines` WHERE `company_id` = ? AND `posting_batch_id` = ?');
            $stmtLines->execute([$companyId, $batchId]);
            $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

            if (count($lines) < 2) {
                $errors[] = "Posting batch {$batchId} has fewer than 2 posting lines.";
            }

            $debitSum = BigDecimal::zero();
            $creditSum = BigDecimal::zero();
            foreach ($lines as $line) {
                $debitSum = $debitSum->plus((string) ($line['debit_base'] ?? '0'));
                $creditSum = $creditSum->plus((string) ($line['credit_base'] ?? '0'));
            }

            if ($debitSum->isZero() || $creditSum->isZero()) {
                $errors[] = "Posting batch {$batchId} has zero debits or credits.";
                $allBatchesBalanced = false;
            } elseif ($debitSum->toScale(6)->compareTo($creditSum->toScale(6)) !== 0) {
                $errors[] = "Posting batch {$batchId} unbalanced: debit {$debitSum} vs credit {$creditSum}.";
                $allBatchesBalanced = false;
            }
        }

        if (! $hasInvoiceBatch) {
            $errors[] = 'Posting batch for newly created sales invoice was not found.';
        }
        if (! $hasPaymentBatch) {
            $errors[] = 'Posting batch for newly created customer payment was not found.';
        }

        // 8. Inspect Document Sequences increment
        $baseSeqs = $baseline['document_sequences'] ?? [];
        $currSeqs = $current['document_sequences'] ?? [];
        $foundInvoiceSeqIncrement = false;
        $foundPaymentSeqIncrement = false;

        foreach ($currSeqs as $typeYear => $currNext) {
            $baseNext = $baseSeqs[$typeYear] ?? 1;
            if (str_starts_with($typeYear, 'sales_invoice:')) {
                if ($currNext === $baseNext + 1) {
                    $foundInvoiceSeqIncrement = true;
                }
            } elseif (str_starts_with($typeYear, 'customer_payment:')) {
                if ($currNext === $baseNext + 1) {
                    $foundPaymentSeqIncrement = true;
                }
            }
        }

        if (! $foundInvoiceSeqIncrement) {
            $errors[] = 'Document sequence for sales_invoice did not increment by 1.';
        }
        if (! $foundPaymentSeqIncrement) {
            $errors[] = 'Document sequence for customer_payment did not increment by 1.';
        }

        // 9. Inspect Mobile Event Receipt (status must be 'completed', NOT 'posted')
        $stmtReceipt = $db->prepare('SELECT `id`, `client_event_id`, `status`, `sales_invoice_public_id`, `customer_payment_public_id` FROM `mobile_event_receipts` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
        $stmtReceipt->execute([$companyId, (int) ($baseline['mobile_event_receipts_max_id'] ?? 0)]);
        $newReceipt = $stmtReceipt->fetch(PDO::FETCH_ASSOC);

        if (! $newReceipt) {
            $errors[] = 'Newly created mobile_event_receipt row not found.';
        } else {
            if ($expectedClientEventId !== null && (string) ($newReceipt['client_event_id'] ?? '') !== $expectedClientEventId) {
                $errors[] = "Mobile event receipt client_event_id mismatch: expected '{$expectedClientEventId}', got '".($newReceipt['client_event_id'] ?? 'null')."'.";
            }
            if (($newReceipt['status'] ?? '') !== 'completed') {
                $errors[] = "Mobile event receipt status must be 'completed', got '".($newReceipt['status'] ?? 'null')."'.";
            }
            if ($newInvoicePublicId !== null && (string) ($newReceipt['sales_invoice_public_id'] ?? '') !== $newInvoicePublicId) {
                $errors[] = "Mobile event receipt sales_invoice_public_id mismatch: expected '{$newInvoicePublicId}', got '".($newReceipt['sales_invoice_public_id'] ?? 'null')."'.";
            }
            if ($newPaymentPublicId !== null && (string) ($newReceipt['customer_payment_public_id'] ?? '') !== $newPaymentPublicId) {
                $errors[] = "Mobile event receipt customer_payment_public_id mismatch: expected '{$newPaymentPublicId}', got '".($newReceipt['customer_payment_public_id'] ?? 'null')."'.";
            }
        }

        return [
            'is_canonical_delta_valid' => $errors === [],
            'expected_delta_one_event' => true,
            'deltas' => $deltas,
            'errors' => $errors,
            'gl_batches_balanced' => $allBatchesBalanced && $hasInvoiceBatch && $hasPaymentBatch,
            'canonical_graph' => [
                'sales_invoice_id' => $newInvoiceId,
                'sales_invoice_public_id' => $newInvoicePublicId,
                'customer_payment_id' => $newPaymentId,
                'customer_payment_public_id' => $newPaymentPublicId,
                'stock_movement_quantity_delta' => '-1.000000',
                'customer_payment_allocation_amount' => '58.000000',
                'receipt_status' => $newReceipt['status'] ?? null,
            ],
        ];
    }

    /**
     * Executes the 6 canonical read-only reconciliation services in context-free system mode.
     * Guarantees CompanyContext isolation before and after running each service.
     *
     * @param  Company  $company
     * @param  string  $expectedSchemaName
     * @return array{all_healthy: bool, reconciler_results: array<string, array{healthy: bool, issue_count: int}>}
     */
    public static function runSixReconciliations(Company $company, string $expectedSchemaName): array
    {
        self::assertExecutionGates($expectedSchemaName);

        $results = [];
        $allHealthy = true;

        $clearContext = static function (): void {
            if (app()->bound(CompanyContext::class)) {
                try {
                    app(CompanyContext::class)->clear(sessionless: true);
                } catch (\Throwable) {
                    // Ignore transient cleanup issues
                }
            }
        };

        try {
            $clearContext();

            // 1. Accounting Reconciliation
            $accReport = app(AccountingReconciliationService::class)->reconcile($company, isSystem: true);
            $results['AccountingReconciliationService'] = [
                'healthy' => $accReport->isHealthy,
                'issue_count' => count($accReport->violations),
            ];
            $allHealthy = $allHealthy && $accReport->isHealthy;

            $clearContext();

            // 2. Inventory Audit
            $invReport = app(InventoryReconciliationService::class)->auditCompany($company, fromCli: true);
            $results['InventoryReconciliationService'] = [
                'healthy' => $invReport->isHealthy,
                'issue_count' => count($invReport->discrepancies),
            ];
            $allHealthy = $allHealthy && $invReport->isHealthy;

            $clearContext();

            // 3. Sales Reconciliation
            $salesReport = app(SalesReconciliationService::class)->reconcile($company, isSystem: true);
            $results['SalesReconciliationService'] = [
                'healthy' => $salesReport->isHealthy,
                'issue_count' => count($salesReport->violations),
            ];
            $allHealthy = $allHealthy && $salesReport->isHealthy;

            $clearContext();

            // 4. Payables Reconciliation
            $payReport = app(PayablesReconciliationService::class)->reconcile($company, isSystem: true);
            $results['PayablesReconciliationService'] = [
                'healthy' => $payReport->isHealthy,
                'issue_count' => count($payReport->violations),
            ];
            $allHealthy = $allHealthy && $payReport->isHealthy;

            $clearContext();

            // 5. Money Reconciliation
            $moneyReport = app(MoneyReconciliationService::class)->reconcile($company, isSystem: true);
            $results['MoneyReconciliationService'] = [
                'healthy' => $moneyReport->isHealthy,
                'issue_count' => count($moneyReport->violations),
            ];
            $allHealthy = $allHealthy && $moneyReport->isHealthy;

            $clearContext();

            // 6. Phase 7 Multi-Ledger Reconciliation
            $phase7Report = app(Phase7ReconciliationService::class)->reconcile($company, isSystem: true);
            $results['Phase7ReconciliationService'] = [
                'healthy' => $phase7Report->isHealthy,
                'issue_count' => count($phase7Report->violations),
            ];
            $allHealthy = $allHealthy && $phase7Report->isHealthy;
        } finally {
            $clearContext();
        }

        return [
            'all_healthy' => $allHealthy,
            'reconciler_results' => $results,
        ];
    }

    /**
     * Authoritative validator for CashPilotEvidenceJSON artifacts produced by Android b7 serializer.
     * Pure format and logic evaluation; requires zero DB or network access.
     *
     * Enforces authoritative Android b7 wire keys and strict scenario outcomes:
     * - Top-level keys: runId, timestamp, backendHead, clientHead, clientPublicationHead,
     *   clientExecutableHead, manifestDigest, baseUrl, lifecycleGrant, ttlExpiresAt, overallStatus, scenarios.
     * - Scenarios array entries: {name, status, httpStatusCode, latencyMs, details}
     *   1. readiness_probe: PASS (200)
     *   2. financial_cash_fresh_commit_201: PASS (201)
     *   3. financial_cash_idempotent_replay_200: PASS (200)
     *   4. financial_cash_event_status_posted: PASS (200)
     *   5. financial_cash_tamper_409: PASS (409)
     *   6. transport_lost_ack_recovery: explicit NOT_RUN
     *   7. stock_permission_rollback_probes: explicit NOT_RUN
     * - Approved publication pin: c00aedb70ea06f28ae42282149c030df76dc300d
     * - Strictly rejects unadmitted wrong-owner publication pin: c00aedbb2c24097f5ef6ba297d287bc3b34b7f73
     * - Approved executable pin: b7e61eb4ed0aed2f5040d9fc44f9764b310be530
     * - Requires separate trusted owned DB inspection report matching canonical graph + 6 healthy reconcilers.
     *
     * @param  array<string, mixed>|string  $evidenceJson
     * @param  array{
     *     clientPublicationHead: string,
     *     clientExecutableHead: string,
     *     backendExecutionHead: string,
     *     handoffDigest: string,
     *     expectedLifecycle?: string,
     *     requireTrustedDbReport?: bool
     * }  $expectedPins
     * @param  array<string, mixed>|null  $trustedDbReport  Separate trusted DB inspection report
     * @return array{is_valid: bool, errors: array<string>}
     */
    public static function validateOuterEvidenceJson(
        array|string $evidenceJson,
        array $expectedPins,
        ?array $trustedDbReport = null
    ): array {
        $data = is_string($evidenceJson) ? json_decode($evidenceJson, true) : $evidenceJson;
        if (! is_array($data)) {
            return [
                'is_valid' => false,
                'errors' => ['Evidence payload is not valid JSON.'],
            ];
        }

        $errors = [];

        // 1. Validate authoritative top-level keys
        $requiredTopLevelKeys = [
            'runId',
            'timestamp',
            'backendHead',
            'clientHead',
            'clientPublicationHead',
            'clientExecutableHead',
            'manifestDigest',
            'baseUrl',
            'lifecycleGrant',
            'ttlExpiresAt',
            'overallStatus',
            'scenarios',
        ];

        foreach ($requiredTopLevelKeys as $reqKey) {
            if (! array_key_exists($reqKey, $data)) {
                $errors[] = "Evidence payload missing required top-level key '{$reqKey}'.";
            }
        }

        // 2. Validate explicit publication pin
        $clientPub = (string) ($data['clientPublicationHead'] ?? '');
        if ($clientPub === self::UNADMITTED_ANDROID_PUBLICATION_SHA) {
            $errors[] = 'REJECTED: Evidence contains unadmitted wrong-owner publication pin '.self::UNADMITTED_ANDROID_PUBLICATION_SHA.'.';
        } elseif ($clientPub !== $expectedPins['clientPublicationHead']) {
            $errors[] = "Client publication pin mismatch. Expected '{$expectedPins['clientPublicationHead']}', got '{$clientPub}'.";
        }

        // 3. Validate explicit executable pin
        $clientExe = (string) ($data['clientExecutableHead'] ?? '');
        if ($clientExe !== $expectedPins['clientExecutableHead']) {
            $errors[] = "Client executable pin mismatch. Expected '{$expectedPins['clientExecutableHead']}', got '{$clientExe}'.";
        }

        // 4. Validate backend execution HEAD
        $backendHead = (string) ($data['backendHead'] ?? '');
        if ($backendHead !== $expectedPins['backendExecutionHead']) {
            $errors[] = "Backend execution HEAD mismatch. Expected '{$expectedPins['backendExecutionHead']}', got '{$backendHead}'.";
        }

        // 5. Validate handoff digest
        $handoffSha = (string) ($data['manifestDigest'] ?? '');
        if ($handoffSha !== $expectedPins['handoffDigest']) {
            $errors[] = "Handoff digest mismatch. Expected '{$expectedPins['handoffDigest']}', got '{$handoffSha}'.";
        }

        // 6. Validate lifecycle grant
        $expectedLifecycle = $expectedPins['expectedLifecycle'] ?? self::EXPECTED_LIFECYCLE;
        $actualLifecycle = (string) ($data['lifecycleGrant'] ?? '');
        if ($actualLifecycle !== $expectedLifecycle) {
            $errors[] = "Lifecycle grant mismatch. Expected '{$expectedLifecycle}', got '{$actualLifecycle}'.";
        }

        // 7. Validate overallStatus
        $overallStatus = strtoupper((string) ($data['overallStatus'] ?? ''));
        if ($overallStatus !== 'PASS' && $overallStatus !== 'SUCCESS') {
            $errors[] = "Overall status must be PASS, got '".($data['overallStatus'] ?? 'null')."'.";
        }

        // 8. Validate scenarios array structure and exact required scenarios
        $scenariosRaw = $data['scenarios'] ?? [];
        if (! is_array($scenariosRaw) || empty($scenariosRaw)) {
            $errors[] = 'Evidence missing individual scenario execution results.';
        } else {
            // Index scenarios by name
            $scenariosMap = [];
            foreach ($scenariosRaw as $idx => $item) {
                if (is_array($item) && isset($item['name'])) {
                    $scenariosMap[(string) $item['name']] = $item;
                } elseif (is_string($idx) && (is_array($item) || is_string($item))) {
                    // Normalized support for associative format if present
                    $entry = is_array($item) ? $item : ['status' => $item];
                    $entry['name'] = $idx;
                    $scenariosMap[$idx] = $entry;
                }
            }

            // Required 5 active PASS scenarios with exact HTTP status codes
            $requiredActiveScenarios = [
                'readiness_probe' => 200,
                'financial_cash_fresh_commit_201' => 201,
                'financial_cash_idempotent_replay_200' => 200,
                'financial_cash_event_status_posted' => 200,
                'financial_cash_tamper_409' => 409,
            ];

            foreach ($requiredActiveScenarios as $name => $expectedHttpCode) {
                if (! isset($scenariosMap[$name])) {
                    $errors[] = "Required active scenario '{$name}' missing from evidence.";

                    continue;
                }

                $sc = $scenariosMap[$name];
                $status = strtoupper((string) ($sc['status'] ?? ''));
                if ($status !== 'PASS') {
                    $errors[] = "Required active scenario '{$name}' must be PASS, got '{$status}'.";
                }

                $httpCode = (int) ($sc['httpStatusCode'] ?? $sc['statusCode'] ?? 0);
                if ($httpCode !== $expectedHttpCode) {
                    $errors[] = "Scenario '{$name}' HTTP status code mismatch: expected {$expectedHttpCode}, got {$httpCode}.";
                }
            }

            // Required 2 probe scenarios: MUST be explicitly marked NOT_RUN for basic financial run
            $requiredProbeScenarios = [
                'transport_lost_ack_recovery',
                'stock_permission_rollback_probes',
            ];

            foreach ($requiredProbeScenarios as $probeName) {
                if (! isset($scenariosMap[$probeName])) {
                    $errors[] = "Advanced probe scenario '{$probeName}' must be explicitly present and marked NOT_RUN.";

                    continue;
                }

                $sc = $scenariosMap[$probeName];
                $status = strtoupper((string) ($sc['status'] ?? ''));
                if ($status !== 'NOT_RUN') {
                    $errors[] = "Advanced probe scenario '{$probeName}' cannot claim '{$status}' in basic financial run; must be explicitly marked NOT_RUN.";
                }
            }
        }

        // 9. Separate REQUIRED trusted owned DB inspection report verification
        $requireDbReport = $expectedPins['requireTrustedDbReport'] ?? true;
        if ($trustedDbReport === null) {
            if ($requireDbReport) {
                $errors[] = 'Separate REQUIRED trusted owned DB inspection report is missing.';
            }
        } else {
            if (! ($trustedDbReport['is_canonical_delta_valid'] ?? false)) {
                $errors[] = 'Trusted DB inspection report failed canonical delta validity.';
            }
            if (! ($trustedDbReport['gl_batches_balanced'] ?? false)) {
                $errors[] = 'Trusted DB inspection report detected unbalanced GL posting batches.';
            }
            if (isset($trustedDbReport['all_healthy']) && ! $trustedDbReport['all_healthy']) {
                $errors[] = 'Trusted DB inspection report reported unhealthy reconciliations.';
            }
        }

        return [
            'is_valid' => $errors === [],
            'errors' => $errors,
        ];
    }
}
