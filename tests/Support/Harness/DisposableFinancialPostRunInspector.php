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
use PDO;
use RuntimeException;
use Tests\Support\DisposableMariaDbSchema;

/**
 * Inspection helper and outer validator for Mobile V2 financial paired execution.
 *
 * Enforces strict default NO-EXECUTE boundaries:
 * - Refused unless MOBILE_V2_HARNESS_ENABLE=1 and PHASE8_ALLOW_DISPOSABLE_DB=1.
 * - Asserts DisposableMariaDbSchema::assertPrimarySchema on exact MariaDB port 3342.
 * - Binds a dedicated read-only PDO connection directly to the verified owned disposable schema.
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
     * Safely parses child_bootstrap_config.json credentials with strict POSIX permissions
     * (0600 file, 0700 parent directory, no symlinks, owner UID match) and maps lowercase
     * keys to PHASE8_TEST_DB_* and PHASE8_DISPOSABLE_DB_* without exposing secrets.
     *
     * @param  string  $filePath
     * @param  string|null  $expectedSchemaName
     * @return array{
     *     host: string,
     *     port: string,
     *     username: string,
     *     password: string,
     *     schema_name: string,
     *     proof_path: ?string,
     *     proof_nonce: ?string
     * }
     */
    public static function loadSecureBootstrapCredentials(string $filePath, ?string $expectedSchemaName = null): array
    {
        if (is_link($filePath)) {
            throw new RuntimeException('BOOTSTRAP_CONFIG_SYMLINK_REJECTED');
        }
        if (! is_file($filePath)) {
            throw new RuntimeException('BOOTSTRAP_CONFIG_FILE_NOT_FOUND');
        }

        $parentDir = dirname($filePath);
        if (is_link($parentDir)) {
            throw new RuntimeException('BOOTSTRAP_CONFIG_PARENT_SYMLINK_REJECTED');
        }

        $currentUid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();

        $filePerms = fileperms($filePath) & 0777;
        if ($filePerms !== 0600) {
            throw new RuntimeException(sprintf('BOOTSTRAP_CONFIG_INSECURE_PERMS: expected 0600, got %04o', $filePerms));
        }

        $fileOwner = fileowner($filePath);
        if ($fileOwner !== $currentUid) {
            throw new RuntimeException('BOOTSTRAP_CONFIG_OWNER_MISMATCH');
        }

        $parentPerms = fileperms($parentDir) & 0777;
        if ($parentPerms !== 0700) {
            throw new RuntimeException(sprintf('BOOTSTRAP_CONFIG_PARENT_INSECURE_PERMS: expected 0700, got %04o', $parentPerms));
        }

        $parentOwner = fileowner($parentDir);
        if ($parentOwner !== $currentUid) {
            throw new RuntimeException('BOOTSTRAP_CONFIG_PARENT_OWNER_MISMATCH');
        }

        $raw = (string) file_get_contents($filePath);
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('BOOTSTRAP_CONFIG_INVALID_JSON: '.$e->getMessage());
        }

        if (! is_array($data)) {
            throw new RuntimeException('BOOTSTRAP_CONFIG_INVALID_JSON_STRUCTURE');
        }

        $host = (string) ($data['db_host'] ?? '');
        if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException("BOOTSTRAP_CONFIG_INVALID_HOST: expected 127.0.0.1 or localhost, got '{$host}'");
        }

        $port = (string) ($data['db_port'] ?? '');
        if ($port !== '3342') {
            throw new RuntimeException("BOOTSTRAP_CONFIG_INVALID_PORT: expected 3342, got '{$port}'");
        }

        $schema = (string) ($data['schema_name'] ?? '');
        if (! preg_match(self::SCHEMA_REGEX, $schema)) {
            throw new RuntimeException("BOOTSTRAP_CONFIG_INVALID_SCHEMA: '{$schema}'");
        }

        if ($expectedSchemaName !== null && $expectedSchemaName !== '' && $schema !== $expectedSchemaName) {
            throw new RuntimeException("BOOTSTRAP_CONFIG_SCHEMA_MISMATCH: expected '{$expectedSchemaName}', got '{$schema}'");
        }

        $user = (string) ($data['db_username'] ?? '');
        if ($user === '') {
            throw new RuntimeException('BOOTSTRAP_CONFIG_MISSING_USERNAME');
        }

        $pass = (string) ($data['db_password'] ?? '');

        // Safe environment assignment
        putenv("PHASE8_TEST_DB_HOST={$host}");
        putenv("PHASE8_TEST_DB_PORT={$port}");
        putenv("PHASE8_TEST_DB_USERNAME={$user}");
        putenv("PHASE8_TEST_DB_PASSWORD={$pass}");
        if ($pass === '') {
            putenv('PHASE8_TEST_DB_EMPTY_PASSWORD=1');
        }

        $proofPath = isset($data['proof_path']) ? (string) $data['proof_path'] : null;
        if ($proofPath !== null && $proofPath !== '') {
            putenv("PHASE8_DISPOSABLE_DB_PROOF={$proofPath}");
        }

        $proofNonce = isset($data['proof_nonce']) ? (string) $data['proof_nonce'] : null;
        if ($proofNonce !== null && $proofNonce !== '') {
            putenv("PHASE8_DISPOSABLE_DB_NONCE={$proofNonce}");
        }

        return [
            'host' => $host,
            'port' => $port,
            'username' => $user,
            'password' => $pass,
            'schema_name' => $schema,
            'proof_path' => $proofPath,
            'proof_nonce' => $proofNonce,
        ];
    }

    /**
     * Asserts execution environment invariants, verifies ownership proof,
     * and binds a dedicated read-only PDO connection directly to MariaDB port 3342.
     * Default NO-EXECUTE: fails closed if harness or disposable DB flags are missing.
     *
     * @param  string  $expectedSchemaName  Mandatory non-empty schema name matching SCHEMA_REGEX
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

        // Enforce statement timeout fail closed
        self::configureStatementTimeout($pdo, 5);

        // Assert that active connected database and port strictly match expected disposable schema
        $verified = $pdo->query('SELECT DATABASE() AS db, @@port AS port')->fetch(PDO::FETCH_ASSOC);
        if (! is_array($verified) || ($verified['db'] ?? '') !== $expectedSchemaName) {
            throw new RuntimeException("Inspector connection mismatch: expected DB '{$expectedSchemaName}', got '".($verified['db'] ?? 'null')."'.");
        }
        if ((string) ($verified['port'] ?? '') !== '3342') {
            throw new RuntimeException("Inspector connection mismatch: expected port 3342, got '".($verified['port'] ?? 'null')."'.");
        }

        return $pdo;
    }

    /**
     * Configures a finite statement timeout on a dedicated PDO connection,
     * failing closed if neither MariaDB nor MySQL syntax is supported.
     */
    public static function configureStatementTimeout(PDO $pdo, int $timeoutSeconds = 5): void
    {
        if ($timeoutSeconds <= 0) {
            throw new InvalidArgumentException('INVALID_STATEMENT_TIMEOUT');
        }

        // 1. Try MariaDB max_statement_time (seconds)
        try {
            $pdo->exec(sprintf('SET SESSION max_statement_time = %d', $timeoutSeconds));

            return;
        } catch (\Throwable) {
            // Fall through to MySQL
        }

        // 2. Try MySQL max_execution_time (milliseconds)
        try {
            $timeoutMs = $timeoutSeconds * 1000;
            $pdo->exec(sprintf('SET SESSION max_execution_time = %d', $timeoutMs));

            return;
        } catch (\Throwable) {
            // Fall through
        }

        throw new RuntimeException('STATEMENT_TIMEOUT_UNSUPPORTED');
    }

    /**
     * Captures pre-run baseline counts and metrics on canonical financial tables
     * using a dedicated, verified PDO connection.
     * Always verifies gates and proof; foreign unverified PDO injection is strictly rejected.
     *
     * @param  int  $companyId
     * @param  string  $expectedSchemaName
     * @return array<string, mixed>
     */
    public static function captureBaseline(int $companyId, string $expectedSchemaName): array
    {
        $db = self::assertExecutionGates($expectedSchemaName);
        $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

        try {
            return self::captureMetricsWithVerifiedPdo($companyId, $expectedSchemaName, $db);
        } finally {
            try {
                $db->rollBack();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Private internal metric capture on a verified read-only PDO instance.
     *
     * @param  int  $companyId
     * @param  string  $expectedSchemaName
     * @param  PDO  $db
     * @return array<string, mixed>
     */
    private static function captureMetricsWithVerifiedPdo(int $companyId, string $expectedSchemaName, PDO $db): array
    {
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
     * Fails closed on any baseline company/schema mismatch or missing baseline keys.
     *
     * @param  int  $companyId
     * @param  array<string, mixed>  $baseline
     * @param  string|null  $expectedClientEventId
     * @param  string  $expectedSchemaName
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public static function inspectDelta(
        int $companyId,
        array $baseline,
        ?string $expectedClientEventId,
        string $expectedSchemaName
    ): array {
        $db = self::assertExecutionGates($expectedSchemaName);
        $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

        try {
            // Fail-closed checks on baseline integrity: company and schema must match exactly
        if (($baseline['company_id'] ?? null) !== $companyId) {
            throw new RuntimeException("Baseline company_id mismatch: expected {$companyId}, got ".($baseline['company_id'] ?? 'null').'.');
        }
        if (($baseline['schema_name'] ?? null) !== $expectedSchemaName) {
            throw new RuntimeException("Baseline schema_name mismatch: expected '{$expectedSchemaName}', got '".($baseline['schema_name'] ?? 'null')."'.");
        }

        $requiredBaselineKeys = [
            'company_id', 'schema_name',
            'sales_invoices', 'sales_invoices_max_id',
            'sales_invoice_lines', 'sales_invoice_lines_max_id',
            'sales_invoice_lot_allocations', 'sales_invoice_lot_allocations_max_id',
            'customer_payments', 'customer_payments_max_id',
            'customer_payment_allocations', 'customer_payment_allocations_max_id',
            'mobile_event_receipts', 'mobile_event_receipts_max_id',
            'stock_movements', 'stock_movements_max_id', 'stock_movements_qty_sum',
            'posting_batches', 'posting_batches_max_id',
            'posting_lines', 'posting_lines_max_id',
            'document_sequences',
        ];
        foreach ($requiredBaselineKeys as $reqKey) {
            if (! array_key_exists($reqKey, $baseline)) {
                throw new RuntimeException("Baseline missing required key '{$reqKey}'. Cannot default to 0.");
            }
        }

        $current = self::captureMetricsWithVerifiedPdo($companyId, $expectedSchemaName, $db);

        $deltas = [
            'sales_invoices' => $current['sales_invoices'] - $baseline['sales_invoices'],
            'sales_invoice_lines' => $current['sales_invoice_lines'] - $baseline['sales_invoice_lines'],
            'sales_invoice_lot_allocations' => $current['sales_invoice_lot_allocations'] - $baseline['sales_invoice_lot_allocations'],
            'customer_payments' => $current['customer_payments'] - $baseline['customer_payments'],
            'customer_payment_allocations' => $current['customer_payment_allocations'] - $baseline['customer_payment_allocations'],
            'mobile_event_receipts' => $current['mobile_event_receipts'] - $baseline['mobile_event_receipts'],
            'stock_movements' => $current['stock_movements'] - $baseline['stock_movements'],
            'posting_batches' => $current['posting_batches'] - $baseline['posting_batches'],
            'posting_lines' => $current['posting_lines'] - $baseline['posting_lines'],
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
        $stmtInv->execute([$companyId, (int) $baseline['sales_invoices_max_id']]);
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

        // 3. Query and inspect the newly created Customer Payment using actual migration columns
        // (is_reversed = 0, reversed_at IS NULL, payment_method = 'cash', money_account_id non-null, amount = 58.000000, amount_base = 58.000000, non-null posting_batch_id)
        $stmtPay = $db->prepare('SELECT `id`, `public_id`, `payment_method`, `money_account_id`, `is_reversed`, `reversed_at`, `amount`, `amount_base`, `posting_batch_id` FROM `customer_payments` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
        $stmtPay->execute([$companyId, (int) $baseline['customer_payments_max_id']]);
        $newPayment = $stmtPay->fetch(PDO::FETCH_ASSOC);

        $newPaymentId = null;
        $newPaymentPublicId = null;
        if (! $newPayment) {
            $errors[] = 'Newly created customer payment row not found.';
        } else {
            $newPaymentId = (int) $newPayment['id'];
            $newPaymentPublicId = (string) $newPayment['public_id'];
            if ((int) ($newPayment['is_reversed'] ?? 1) !== 0) {
                $errors[] = 'Newly created customer payment must not be reversed (is_reversed must be 0).';
            }
            if ($newPayment['reversed_at'] !== null) {
                $errors[] = "Newly created customer payment must have null reversed_at, got '{$newPayment['reversed_at']}'.";
            }
            if (($newPayment['payment_method'] ?? '') !== 'cash') {
                $errors[] = "Newly created customer payment payment_method must be 'cash', got '".($newPayment['payment_method'] ?? 'null')."'.";
            }
            if (empty($newPayment['money_account_id'])) {
                $errors[] = 'Newly created customer payment must have non-null money_account_id.';
            }
            if (empty($newPayment['posting_batch_id'])) {
                $errors[] = 'Newly created customer payment must have valid non-null posting_batch_id.';
            }
            if (BigDecimal::of((string) ($newPayment['amount'] ?? '0'))->toScale(6)->compareTo(BigDecimal::of('58.000000')) !== 0) {
                $errors[] = "Newly created customer payment amount must be '58.000000', got '".($newPayment['amount'] ?? 'null')."'.";
            }
            if (BigDecimal::of((string) ($newPayment['amount_base'] ?? '0'))->toScale(6)->compareTo(BigDecimal::of('58.000000')) !== 0) {
                $errors[] = "Newly created customer payment amount_base must be '58.000000', got '".($newPayment['amount_base'] ?? 'null')."'.";
            }
        }

        // 4. Query and inspect the newly created Customer Payment Allocation
        // Both allocated_amount AND payment_currency_amount must be 58.000000
        $stmtAlloc = $db->prepare('SELECT `id`, `customer_payment_id`, `sales_invoice_id`, `allocated_amount`, `payment_currency_amount`, `base_amount_applied_to_receivable` FROM `customer_payment_allocations` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
        $stmtAlloc->execute([$companyId, (int) $baseline['customer_payment_allocations_max_id']]);
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
            if (BigDecimal::of((string) ($newPaymentAlloc['payment_currency_amount'] ?? '0'))->toScale(6)->compareTo(BigDecimal::of('58.000000')) !== 0) {
                $errors[] = "Customer payment allocation payment_currency_amount must be '58.000000', got '".($newPaymentAlloc['payment_currency_amount'] ?? 'null')."'.";
            }
        }

        // 5. Query and inspect the newly created Stock Movement
        $stmtSm = $db->prepare('SELECT `id`, `quantity_delta_base`, `unit_cost_base`, `value_delta_base`, `source_type`, `source_id` FROM `stock_movements` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
        $stmtSm->execute([$companyId, (int) $baseline['stock_movements_max_id']]);
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
        $qtyDelta = BigDecimal::of((string) $current['stock_movements_qty_sum'])
            ->minus(BigDecimal::of((string) $baseline['stock_movements_qty_sum']))
            ->toScale(6);
        if ($qtyDelta->compareTo(BigDecimal::of('-1.000000')) !== 0) {
            $errors[] = "Net stock quantity delta must be '-1.000000', got '{$qtyDelta}'.";
        }

        // 6. Query and inspect the newly created Lot Allocation
        $stmtLot = $db->prepare('SELECT `id`, `sales_invoice_id`, `quantity_allocated_base` FROM `sales_invoice_lot_allocations` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
        $stmtLot->execute([$companyId, (int) $baseline['sales_invoice_lot_allocations_max_id']]);
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
        $stmtBatches->execute([$companyId, (int) $baseline['posting_batches_max_id']]);
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
        $baseSeqs = $baseline['document_sequences'];
        $currSeqs = $current['document_sequences'];
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
        $stmtReceipt->execute([$companyId, (int) $baseline['mobile_event_receipts_max_id']]);
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
                    'customer_payment_allocation_payment_currency_amount' => '58.000000',
                    'receipt_status' => $newReceipt['status'] ?? null,
                ],
            ];
        } finally {
            try {
                $db->rollBack();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Executes the 6 canonical read-only reconciliation services in context-free system mode
     * using the minimal isolated container pattern from bin/final-release-check.php.
     * Binds all models and services to the EXACT verified read-only PDO instance
     * so that all operations execute strictly against the owned disposable schema,
     * restoring previous connection/facade state in finally without calling getPdo() of ambient.
     *
     * @param  Company|int  $companyOrId
     * @param  string  $expectedSchemaName
     * @return array{all_healthy: bool, reconciler_results: array<string, array{healthy: bool, issue_count: int}>}
     */
    public static function runSixReconciliations(Company|int $companyOrId, string $expectedSchemaName): array
    {
        $previousGlobalContainer = \Illuminate\Container\Container::getInstance();
        $previousApp = function_exists('app') ? \Illuminate\Support\Facades\Facade::getFacadeApplication() : null;
        $previousResolver = \Illuminate\Database\Eloquent\Model::getConnectionResolver();

        $pdo = null;
        $app = null;
        $manager = null;
        $connection = null;
        $results = [];
        $allHealthy = true;

        try {
            $pdo = self::assertExecutionGates($expectedSchemaName);
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

            // Minimal isolated container: autoload only, no full service providers, sessions, cache, or background jobs.
            $appDir = dirname(__DIR__, 3);
            $app = new \Illuminate\Foundation\Application($appDir);
            $app['env'] = 'testing';
            $app->instance('config', new \Illuminate\Config\Repository([
                'database' => [
                    'default' => 'disposable_inspector',
                    'connections' => [],
                ],
            ]));
            $app->singleton(CompanyContext::class);

            // Wrap the SAME verified read-only PDO connection
            $connection = new \Illuminate\Database\MySqlConnection($pdo, $expectedSchemaName, '', [
                'name' => 'disposable_inspector',
                'database' => $expectedSchemaName,
                'host' => '127.0.0.1',
                'port' => 3342,
                'url' => null,
            ]);
            $manager = new \Illuminate\Database\DatabaseManager($app, new \Illuminate\Database\Connectors\ConnectionFactory($app));
            $manager->extend('disposable_inspector', fn () => $connection);
            $app['config']->set('database.connections.disposable_inspector', [
                'driver' => 'disposable_inspector',
                'database' => $expectedSchemaName,
                'host' => '127.0.0.1',
                'port' => 3342,
                'url' => null,
            ]);
            $app->instance('db', $manager);
            \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
            \Illuminate\Database\Eloquent\Model::setConnectionResolver($manager);

            $companyId = is_int($companyOrId) ? $companyOrId : $companyOrId->id;
            $company = Company::where('id', $companyId)->firstOrFail();

            $context = $app->make(CompanyContext::class);

            $serviceMap = [
                'AccountingReconciliationService' => [
                    'class' => AccountingReconciliationService::class,
                    'isAudit' => false,
                ],
                'InventoryReconciliationService' => [
                    'class' => InventoryReconciliationService::class,
                    'isAudit' => true,
                ],
                'SalesReconciliationService' => [
                    'class' => SalesReconciliationService::class,
                    'isAudit' => false,
                ],
                'PayablesReconciliationService' => [
                    'class' => PayablesReconciliationService::class,
                    'isAudit' => false,
                ],
                'MoneyReconciliationService' => [
                    'class' => MoneyReconciliationService::class,
                    'isAudit' => false,
                ],
                'Phase7ReconciliationService' => [
                    'class' => Phase7ReconciliationService::class,
                    'isAudit' => false,
                ],
            ];

            foreach ($serviceMap as $recName => $spec) {
                $context->clear(sessionless: true);

                $service = $app->make($spec['class']);
                $report = $spec['isAudit']
                    ? $service->auditCompany($company, fromCli: true)
                    : $service->reconcile($company, isSystem: true);

                $findings = $spec['isAudit'] ? $report->discrepancies : $report->violations;
                $ok = ($report->isHealthy === true) && ($findings === []);

                $results[$recName] = [
                    'healthy' => $ok,
                    'issue_count' => count($findings),
                ];

                if (! $ok) {
                    $allHealthy = false;
                }

                $context->clear(sessionless: true);
            }
        } finally {
            if ($pdo instanceof PDO) {
                try {
                    $pdo->rollBack();
                } catch (\Throwable) {
                }
            }

            if ($manager instanceof \Illuminate\Database\DatabaseManager) {
                try {
                    $manager->purge('disposable_inspector');
                    $manager->disconnect('disposable_inspector');
                } catch (\Throwable) {
                }
            }

            try {
                if ($previousResolver !== null) {
                    \Illuminate\Database\Eloquent\Model::setConnectionResolver($previousResolver);
                } else {
                    \Illuminate\Database\Eloquent\Model::unsetConnectionResolver();
                }
            } catch (\Throwable) {
            }

            try {
                \Illuminate\Support\Facades\Facade::clearResolvedInstances();
                \Illuminate\Support\Facades\Facade::setFacadeApplication($previousApp);
            } catch (\Throwable) {
            }

            try {
                if ($previousGlobalContainer !== null) {
                    \Illuminate\Container\Container::setInstance($previousGlobalContainer);
                }
            } catch (\Throwable) {
            }

            if ($app instanceof \Illuminate\Foundation\Application) {
                try {
                    $app->flush();
                } catch (\Throwable) {
                }
            }
        }

        return [
            'all_healthy' => $allHealthy,
            'reconciler_results' => $results,
        ];
    }

    /**
     * Composes a complete, bounded, deterministic Trusted DB Inspection Report
     * from verified baseline, delta, and reconciliation results.
     * Generates a root-pinnable canonical report digest over deterministic metrics.
     *
     * @param  array{
     *     scope: string,
     *     runId: string,
     *     schemaName: string,
     *     companyId: int,
     *     sourceHead: string,
     *     handoffDigest: string,
     * }  $metadata
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $deltaResult
     * @param  array<string, mixed>  $reconcileResult
     * @return array<string, mixed>
     */
    public static function composeTrustedDbReport(
        array $metadata,
        array $baseline,
        array $deltaResult,
        array $reconcileResult
    ): array {
        foreach (['scope', 'runId', 'schemaName', 'companyId', 'sourceHead', 'handoffDigest'] as $mKey) {
            if (! isset($metadata[$mKey]) || $metadata[$mKey] === '' || $metadata[$mKey] === 0) {
                throw new InvalidArgumentException("Missing required metadata field '{$mKey}' for trusted DB report composition.");
            }
        }

        // Canonical baseline metrics hash
        $canonicalBaseline = [
            'company_id' => (int) $metadata['companyId'],
            'schema_name' => (string) $metadata['schemaName'],
            'sales_invoices' => (int) ($baseline['sales_invoices'] ?? 0),
            'sales_invoices_max_id' => (int) ($baseline['sales_invoices_max_id'] ?? 0),
            'sales_invoice_lines' => (int) ($baseline['sales_invoice_lines'] ?? 0),
            'sales_invoice_lines_max_id' => (int) ($baseline['sales_invoice_lines_max_id'] ?? 0),
            'sales_invoice_lot_allocations' => (int) ($baseline['sales_invoice_lot_allocations'] ?? 0),
            'sales_invoice_lot_allocations_max_id' => (int) ($baseline['sales_invoice_lot_allocations_max_id'] ?? 0),
            'customer_payments' => (int) ($baseline['customer_payments'] ?? 0),
            'customer_payments_max_id' => (int) ($baseline['customer_payments_max_id'] ?? 0),
            'customer_payment_allocations' => (int) ($baseline['customer_payment_allocations'] ?? 0),
            'customer_payment_allocations_max_id' => (int) ($baseline['customer_payment_allocations_max_id'] ?? 0),
            'mobile_event_receipts' => (int) ($baseline['mobile_event_receipts'] ?? 0),
            'mobile_event_receipts_max_id' => (int) ($baseline['mobile_event_receipts_max_id'] ?? 0),
            'stock_movements' => (int) ($baseline['stock_movements'] ?? 0),
            'stock_movements_max_id' => (int) ($baseline['stock_movements_max_id'] ?? 0),
            'stock_movements_qty_sum' => (string) ($baseline['stock_movements_qty_sum'] ?? '0.000000'),
            'posting_batches' => (int) ($baseline['posting_batches'] ?? 0),
            'posting_batches_max_id' => (int) ($baseline['posting_batches_max_id'] ?? 0),
            'posting_lines' => (int) ($baseline['posting_lines'] ?? 0),
            'posting_lines_max_id' => (int) ($baseline['posting_lines_max_id'] ?? 0),
            'document_sequences' => $baseline['document_sequences'] ?? [],
        ];
        $baselineHash = hash('sha256', (string) json_encode($canonicalBaseline, JSON_UNESCAPED_SLASHES));

        $isDeltaValid = (bool) ($deltaResult['is_canonical_delta_valid'] ?? false);
        $glBalanced = (bool) ($deltaResult['gl_batches_balanced'] ?? false);
        $allHealthy = (bool) ($reconcileResult['all_healthy'] ?? false);

        // Core deterministic payload for root-pinnable report digest
        $coreDeterministicPayload = [
            'scope' => (string) $metadata['scope'],
            'run_id' => (string) $metadata['runId'],
            'schema_name' => (string) $metadata['schemaName'],
            'company_id' => (int) $metadata['companyId'],
            'source_head' => (string) $metadata['sourceHead'],
            'handoff_digest' => (string) $metadata['handoffDigest'],
            'baseline_hash' => $baselineHash,
            'is_canonical_delta_valid' => $isDeltaValid,
            'gl_batches_balanced' => $glBalanced,
            'all_healthy' => $allHealthy,
            'reconciler_results' => $reconcileResult['reconciler_results'] ?? [],
        ];

        $reportDigest = hash('sha256', (string) json_encode($coreDeterministicPayload, JSON_UNESCAPED_SLASHES));

        return [
            'scope' => (string) $metadata['scope'],
            'run_id' => (string) $metadata['runId'],
            'schema_name' => (string) $metadata['schemaName'],
            'company_id' => (int) $metadata['companyId'],
            'source_head' => (string) $metadata['sourceHead'],
            'handoff_digest' => (string) $metadata['handoffDigest'],
            'baseline_hash' => $baselineHash,
            'baseline_metrics' => $canonicalBaseline,
            'deltas' => $deltaResult['deltas'] ?? [],
            'canonical_graph' => $deltaResult['canonical_graph'] ?? [],
            'is_canonical_delta_valid' => $isDeltaValid,
            'gl_batches_balanced' => $glBalanced,
            'all_healthy' => $allHealthy,
            'reconciler_results' => $reconcileResult['reconciler_results'] ?? [],
            'report_digest' => $reportDigest,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Authoritative validator for CashPilotEvidenceJSON artifacts produced by Android b7 serializer.
     * Pure format and logic evaluation; requires zero DB or network access.
     *
     * Enforces authoritative Android b7 wire keys and strict scenario outcomes:
     * - Top-level keys: runId, timestamp, backendHead, clientHead, clientPublicationHead,
     *   clientExecutableHead, manifestDigest, baseUrl, lifecycleGrant, ttlExpiresAt, overallStatus, scenarios.
     * - Scenarios MUST be a 0-indexed sequential ARRAY LIST of objects with UNIQUE names (no associative maps or duplicates).
     *   Each item: {name: string, status: string, httpStatusCode: int, latencyMs: int, details: string}
     *   Status codes must be strict integers. Status must be exact 'PASS' (not 'SUCCESS').
     *   1. readiness_probe: PASS (200)
     *   2. financial_cash_fresh_commit_201: PASS (201)
     *   3. financial_cash_idempotent_replay_200: PASS (200)
     *   4. financial_cash_event_status_posted: PASS (200)
     *   5. financial_cash_tamper_409: PASS (409)
     *   6. transport_lost_ack_recovery: explicit NOT_RUN (0)
     *   7. stock_permission_rollback_probes: explicit NOT_RUN (0)
     * - Approved publication pin: c00aedb70ea06f28ae42282149c030df76dc300d
     * - Strictly rejects unadmitted wrong-owner publication pin: c00aedbb2c24097f5ef6ba297d287bc3b34b7f73
     * - Approved executable pin: b7e61eb4ed0aed2f5040d9fc44f9764b310be530
     * - Separate trusted owned DB inspection report is ALWAYS MANDATORY (no opt-out bypass):
     *   Requires matching scope, schema, company, runId, source head, handoff digest,
     *   valid canonical delta, balanced GL batches, and ALL 6 named reconcilers healthy with issue_count = 0.
     *
     * @param  array<string, mixed>|string  $evidenceJson
     * @param  array{
     *     clientPublicationHead: string,
     *     clientExecutableHead: string,
     *     backendExecutionHead: string,
     *     handoffDigest: string,
     *     expectedLifecycle?: string,
     *     expectedSchemaName?: string,
     *     companyId?: int,
     *     expectedEventId?: string,
     *     expectedScope?: string
     * }  $expectedPins
     * @param  array<string, mixed>|null  $trustedDbReport  Separate trusted DB inspection report (MANDATORY)
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

        // 7. Validate overallStatus: must be exact 'PASS' (no 'SUCCESS' or lowercase)
        $rawStatus = (string) ($data['overallStatus'] ?? '');
        if ($rawStatus !== 'PASS') {
            $errors[] = "Overall status must be exactly 'PASS', got '{$rawStatus}'.";
        }

        // 8. Validate scenarios: MUST be a 0-indexed sequential ARRAY LIST with UNIQUE names
        $scenariosRaw = $data['scenarios'] ?? null;
        if (! is_array($scenariosRaw) || ! array_is_list($scenariosRaw)) {
            $errors[] = 'Scenarios must be a sequential array list, associative map or invalid type rejected.';
        } else {
            $scenariosMap = [];
            foreach ($scenariosRaw as $idx => $item) {
                if (! is_array($item) || ! isset($item['name']) || ! is_string($item['name'])) {
                    $errors[] = "Scenario at index {$idx} must be an object with a string 'name'.";

                    continue;
                }

                $sName = $item['name'];
                if (isset($scenariosMap[$sName])) {
                    $errors[] = "Duplicate scenario name '{$sName}' detected in scenarios list.";

                    continue;
                }
                $scenariosMap[$sName] = $item;
            }

            // Required 5 active PASS scenarios with exact strict integer HTTP status codes
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
                $status = (string) ($sc['status'] ?? '');
                if ($status !== 'PASS') {
                    $errors[] = "Required active scenario '{$name}' status must be exactly 'PASS', got '{$status}'.";
                }

                if (! isset($sc['httpStatusCode']) || ! is_int($sc['httpStatusCode'])) {
                    $errors[] = "Scenario '{$name}' httpStatusCode must be a strict integer.";
                } elseif ($sc['httpStatusCode'] !== $expectedHttpCode) {
                    $errors[] = "Scenario '{$name}' HTTP status code mismatch: expected {$expectedHttpCode}, got {$sc['httpStatusCode']}.";
                }
            }

            // Required 2 probe scenarios: MUST be explicitly present and marked 'NOT_RUN' with statusCode 0
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
                $status = (string) ($sc['status'] ?? '');
                if ($status !== 'NOT_RUN') {
                    $errors[] = "Advanced probe scenario '{$probeName}' cannot claim '{$status}' in basic financial run; must be exactly 'NOT_RUN'.";
                }
                $statusCode = $sc['httpStatusCode'] ?? null;
                if ($statusCode !== null && $statusCode !== 0) {
                    $errors[] = "Advanced probe scenario '{$probeName}' httpStatusCode must be null or 0, got ".json_encode($statusCode).".";
                }
            }
        }

        // 9. Separate REQUIRED trusted owned DB inspection report (MANDATORY, NO OPT-OUT)
        if ($trustedDbReport === null) {
            $errors[] = 'Separate REQUIRED trusted owned DB inspection report is mandatory; cannot be omitted.';
        } else {
            // Verify context matching
            $expectedScope = $expectedPins['expectedScope'] ?? 'ROOT_AUTHORIZED_PAIRED_EXECUTION';
            $actualScope = (string) ($trustedDbReport['scope'] ?? '');
            if ($actualScope !== $expectedScope) {
                $errors[] = "Trusted DB report scope mismatch: expected '{$expectedScope}', got '{$actualScope}'.";
            }

            $expectedRunId = (string) ($expectedPins['expectedRunId'] ?? '');
            if ($expectedRunId === '') {
                $errors[] = "Expected pin 'expectedRunId' is mandatory and external; cannot be omitted or empty.";
            } else {
                if ((string) ($data['runId'] ?? '') !== $expectedRunId) {
                    $errors[] = "Evidence runId mismatch: expected '{$expectedRunId}', got '".($data['runId'] ?? 'null')."'.";
                }
                if ((string) ($trustedDbReport['run_id'] ?? '') !== $expectedRunId) {
                    $errors[] = "Trusted DB report run_id mismatch: expected '{$expectedRunId}', got '".($trustedDbReport['run_id'] ?? 'null')."'.";
                }
            }

            $expectedSchema = (string) ($expectedPins['expectedSchemaName'] ?? '');
            if ($expectedSchema === '') {
                $errors[] = 'Expected schema_name is required for trusted DB binding.';
            } elseif ((string) ($trustedDbReport['schema_name'] ?? '') !== $expectedSchema) {
                $errors[] = "Trusted DB report schema_name mismatch: expected '{$expectedSchema}', got '".($trustedDbReport['schema_name'] ?? 'null')."'.";
            }

            $expectedCompanyId = (int) ($expectedPins['companyId'] ?? 0);
            if ($expectedCompanyId <= 0) {
                $errors[] = 'Expected company_id is required for trusted DB binding.';
            } elseif ((int) ($trustedDbReport['company_id'] ?? 0) !== $expectedCompanyId) {
                $errors[] = "Trusted DB report company_id mismatch: expected {$expectedCompanyId}, got ".($trustedDbReport['company_id'] ?? 'null').'.';
            }

            $expectedSourceHead = (string) ($expectedPins['backendExecutionHead'] ?? '');
            if ($expectedSourceHead === '') {
                $errors[] = 'Expected backendExecutionHead is required for trusted DB binding.';
            } elseif ((string) ($trustedDbReport['source_head'] ?? '') !== $expectedSourceHead) {
                $errors[] = "Trusted DB report source_head mismatch: expected '{$expectedSourceHead}', got '".($trustedDbReport['source_head'] ?? 'null')."'.";
            }

            $expectedHandoff = (string) ($expectedPins['handoffDigest'] ?? '');
            if ($expectedHandoff === '') {
                $errors[] = 'Expected handoffDigest is required for trusted DB binding.';
            } elseif ((string) ($trustedDbReport['handoff_digest'] ?? '') !== $expectedHandoff) {
                $errors[] = "Trusted DB report handoff_digest mismatch: expected '{$expectedHandoff}', got '".($trustedDbReport['handoff_digest'] ?? 'null')."'.";
            }

            $expectedBaselineHash = (string) ($expectedPins['expectedBaselineHash'] ?? '');
            if ($expectedBaselineHash === '') {
                $errors[] = 'Expected baseline_hash is required for trusted DB binding.';
            } elseif ((string) ($trustedDbReport['baseline_hash'] ?? '') !== $expectedBaselineHash) {
                $errors[] = "Trusted DB report baseline_hash mismatch: expected '{$expectedBaselineHash}', got '".($trustedDbReport['baseline_hash'] ?? 'null')."'.";
            }

            // Recompute deterministic core payload and verify self-digest with hash_equals
            $coreDeterministicPayload = [
                'scope' => (string) ($trustedDbReport['scope'] ?? ''),
                'run_id' => (string) ($trustedDbReport['run_id'] ?? ''),
                'schema_name' => (string) ($trustedDbReport['schema_name'] ?? ''),
                'company_id' => (int) ($trustedDbReport['company_id'] ?? 0),
                'source_head' => (string) ($trustedDbReport['source_head'] ?? ''),
                'handoff_digest' => (string) ($trustedDbReport['handoff_digest'] ?? ''),
                'baseline_hash' => (string) ($trustedDbReport['baseline_hash'] ?? ''),
                'is_canonical_delta_valid' => (bool) ($trustedDbReport['is_canonical_delta_valid'] ?? false),
                'gl_batches_balanced' => (bool) ($trustedDbReport['gl_batches_balanced'] ?? false),
                'all_healthy' => (bool) ($trustedDbReport['all_healthy'] ?? false),
                'reconciler_results' => $trustedDbReport['reconciler_results'] ?? [],
            ];
            $recomputedDigest = hash('sha256', (string) json_encode($coreDeterministicPayload, JSON_UNESCAPED_SLASHES));
            $actualDigest = (string) ($trustedDbReport['report_digest'] ?? '');
            if ($actualDigest === '' || ! hash_equals($recomputedDigest, $actualDigest)) {
                $errors[] = "Trusted DB report report_digest mismatch: recomputed '{$recomputedDigest}', got '{$actualDigest}'.";
            }
            if (isset($expectedPins['expectedReportDigest']) && ! hash_equals((string) $expectedPins['expectedReportDigest'], $actualDigest)) {
                $errors[] = "Expected report_digest mismatch: expected '{$expectedPins['expectedReportDigest']}', got '{$actualDigest}'.";
            }

            // Recompute baseline metrics hash if baseline_metrics is present
            if (isset($trustedDbReport['baseline_metrics']) && is_array($trustedDbReport['baseline_metrics'])) {
                $recomputedBaselineHash = hash('sha256', (string) json_encode($trustedDbReport['baseline_metrics'], JSON_UNESCAPED_SLASHES));
                if (! hash_equals($recomputedBaselineHash, (string) ($trustedDbReport['baseline_hash'] ?? ''))) {
                    $errors[] = "Trusted DB report baseline_metrics recomputed hash '{$recomputedBaselineHash}' does not match baseline_hash '".($trustedDbReport['baseline_hash'] ?? 'null')."'.";
                }
            }

            // Verify canonical graph and batch balance
            if (! ($trustedDbReport['is_canonical_delta_valid'] ?? false)) {
                $errors[] = 'Trusted DB inspection report failed canonical delta validity.';
            }
            if (! ($trustedDbReport['gl_batches_balanced'] ?? false)) {
                $errors[] = 'Trusted DB inspection report detected unbalanced GL posting batches.';
            }

            // Verify ALL 6 named reconcilers healthy with issue_count = 0
            if (! ($trustedDbReport['all_healthy'] ?? false)) {
                $errors[] = 'Trusted DB inspection report reported all_healthy is not true.';
            }

            $reconcilers = $trustedDbReport['reconciler_results'] ?? [];
            $requiredReconcilers = [
                'AccountingReconciliationService',
                'InventoryReconciliationService',
                'SalesReconciliationService',
                'PayablesReconciliationService',
                'MoneyReconciliationService',
                'Phase7ReconciliationService',
            ];

            foreach ($requiredReconcilers as $recName) {
                if (! isset($reconcilers[$recName]) || ! is_array($reconcilers[$recName])) {
                    $errors[] = "Trusted DB report missing named reconciliation result for '{$recName}'.";

                    continue;
                }
                $rec = $reconcilers[$recName];
                if (! ($rec['healthy'] ?? false)) {
                    $errors[] = "Trusted DB report: '{$recName}' is not healthy.";
                }
                if (($rec['issue_count'] ?? -1) !== 0) {
                    $errors[] = "Trusted DB report: '{$recName}' issue_count must be exactly 0, got ".($rec['issue_count'] ?? 'null').'.';
                }
            }
        }

        return [
            'is_valid' => $errors === [],
            'errors' => $errors,
        ];
    }
}
