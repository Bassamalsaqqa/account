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
use App\Support\Mobile\Contract\CanonicalJsonSerializer;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
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
     * @param  string  $expectedClientEventId
     * @param  string  $expectedSchemaName
     * @param  string  $expectedPayloadDigest
     * @return array<string, mixed>
     *
     * @throws RuntimeException|InvalidArgumentException
     */
    public static function inspectDelta(
        int $companyId,
        array $baseline,
        string $expectedClientEventId,
        string $expectedSchemaName,
        string $expectedPayloadDigest
    ): array {
        if ($expectedClientEventId === '' || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $expectedClientEventId)) {
            throw new InvalidArgumentException("Inspector refused: expectedClientEventId must be a valid non-empty UUID, got '{$expectedClientEventId}'.");
        }

        if ($expectedPayloadDigest === '') {
            throw new InvalidArgumentException('Inspector refused: expectedPayloadDigest must be a non-empty 64-hex SHA-256 hash.');
        }

        $normalizedExpectedDigest = str_starts_with($expectedPayloadDigest, 'sha256:')
            ? substr($expectedPayloadDigest, 7)
            : $expectedPayloadDigest;
        if (! preg_match('/^[0-9a-f]{64}$/iD', $normalizedExpectedDigest)) {
            throw new InvalidArgumentException("Inspector refused: expectedPayloadDigest must be a valid 64-hex SHA-256 hash, got '{$expectedPayloadDigest}'.");
        }

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
            $stmtInv = $db->prepare('SELECT `id`, `public_id`, `company_id`, `status`, `grand_total_base`, `posting_batch_id` FROM `sales_invoices` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
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

            // 2b. Query and inspect the newly created Sales Invoice Line
            $stmtLine = $db->prepare('SELECT `id`, `public_id`, `company_id`, `sales_invoice_id`, `line_number`, `quantity`, `unit_price`, `line_total` FROM `sales_invoice_lines` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
            $stmtLine->execute([$companyId, (int) $baseline['sales_invoice_lines_max_id']]);
            $newLine = $stmtLine->fetch(PDO::FETCH_ASSOC);

            $newLineId = null;
            if (! $newLine) {
                $errors[] = 'Newly created sales invoice line row not found.';
            } else {
                $newLineId = (int) $newLine['id'];
                if ($newInvoiceId !== null && (int) ($newLine['sales_invoice_id'] ?? 0) !== $newInvoiceId) {
                    $errors[] = "Sales invoice line sales_invoice_id mismatch: expected invoice {$newInvoiceId}, got ".($newLine['sales_invoice_id'] ?? 'null').'.';
                }
                if (BigDecimal::of((string) ($newLine['quantity'] ?? '0'))->toScale(6)->compareTo(BigDecimal::of('1.000000')) !== 0) {
                    $errors[] = "Sales invoice line quantity must be '1.000000', got '".($newLine['quantity'] ?? 'null')."'.";
                }
                if (BigDecimal::of((string) ($newLine['line_total'] ?? '0'))->toScale(6)->compareTo(BigDecimal::of('58.000000')) !== 0) {
                    $errors[] = "Sales invoice line line_total must be '58.000000', got '".($newLine['line_total'] ?? 'null')."'.";
                }
            }

            // 3. Query and inspect the newly created Customer Payment
            $stmtPay = $db->prepare('SELECT `id`, `public_id`, `company_id`, `payment_method`, `money_account_id`, `is_reversed`, `reversed_at`, `amount`, `amount_base`, `posting_batch_id` FROM `customer_payments` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
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
            $stmtAlloc = $db->prepare('SELECT `id`, `company_id`, `customer_payment_id`, `sales_invoice_id`, `allocated_amount`, `payment_currency_amount`, `base_amount_applied_to_receivable` FROM `customer_payment_allocations` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
            $stmtAlloc->execute([$companyId, (int) $baseline['customer_payment_allocations_max_id']]);
            $newPaymentAlloc = $stmtAlloc->fetch(PDO::FETCH_ASSOC);

            $newPaymentAllocId = null;
            if (! $newPaymentAlloc) {
                $errors[] = 'Newly created customer_payment_allocation row not found.';
            } else {
                $newPaymentAllocId = (int) $newPaymentAlloc['id'];
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
            $stmtSm = $db->prepare('SELECT `id`, `company_id`, `source_type`, `source_id`, `quantity_delta_base`, `unit_cost_base`, `value_delta_base` FROM `stock_movements` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
            $stmtSm->execute([$companyId, (int) $baseline['stock_movements_max_id']]);
            $newSm = $stmtSm->fetch(PDO::FETCH_ASSOC);

            $newSmId = null;
            if (! $newSm) {
                $errors[] = 'Newly created stock_movement row not found.';
            } else {
                $newSmId = (int) $newSm['id'];
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
            $stmtLot = $db->prepare('SELECT `id`, `company_id`, `sales_invoice_id`, `sales_invoice_line_id`, `stock_movement_id`, `quantity_allocated_base` FROM `sales_invoice_lot_allocations` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
            $stmtLot->execute([$companyId, (int) $baseline['sales_invoice_lot_allocations_max_id']]);
            $newLotAlloc = $stmtLot->fetch(PDO::FETCH_ASSOC);

            $newLotAllocId = null;
            if (! $newLotAlloc) {
                $errors[] = 'Newly created sales_invoice_lot_allocation row not found.';
            } else {
                $newLotAllocId = (int) $newLotAlloc['id'];
                if ($newInvoiceId !== null && (int) ($newLotAlloc['sales_invoice_id'] ?? 0) !== $newInvoiceId) {
                    $errors[] = "Lot allocation sales_invoice_id mismatch: expected {$newInvoiceId}, got ".($newLotAlloc['sales_invoice_id'] ?? 'null').'.';
                }
                if ($newLineId !== null && (int) ($newLotAlloc['sales_invoice_line_id'] ?? 0) !== $newLineId) {
                    $errors[] = "Lot allocation sales_invoice_line_id mismatch: expected line {$newLineId}, got ".($newLotAlloc['sales_invoice_line_id'] ?? 'null').'.';
                }
                if ($newSmId !== null) {
                    if (! isset($newLotAlloc['stock_movement_id']) || $newLotAlloc['stock_movement_id'] === null) {
                        $errors[] = 'Lot allocation stock_movement_id must be non-null.';
                    } elseif ((int) $newLotAlloc['stock_movement_id'] !== $newSmId) {
                        $errors[] = "Lot allocation stock_movement_id mismatch: expected stock movement {$newSmId}, got ".($newLotAlloc['stock_movement_id'] ?? 'null').'.';
                    }
                }
                if (BigDecimal::of((string) ($newLotAlloc['quantity_allocated_base'] ?? '0'))->toScale(6)->compareTo(BigDecimal::of('1.000000')) !== 0) {
                    $errors[] = "Lot allocation quantity_allocated_base must be '1.000000', got '".($newLotAlloc['quantity_allocated_base'] ?? 'null')."'.";
                }
            }

            // 7. Inspect GL Posting Batches: verify source provenance and per-batch balance at scale 6
            $stmtBatches = $db->prepare('SELECT `id`, `company_id`, `source_type`, `source_id`, `status` FROM `posting_batches` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC');
            $stmtBatches->execute([$companyId, (int) $baseline['posting_batches_max_id']]);
            $newBatches = $stmtBatches->fetchAll(PDO::FETCH_ASSOC);

            $hasInvoiceBatch = false;
            $hasPaymentBatch = false;
            $allBatchesBalanced = true;
            $structuredBatches = [];
            $seenBatchIds = [];
            $seenLineIdsAcrossBatches = [];

            if (count($newBatches) !== 2) {
                $errors[] = 'Expected exactly 2 new posting batches, found '.count($newBatches).'.';
            }

            foreach ($newBatches as $batch) {
                $batchId = (int) $batch['id'];
                $srcType = (string) ($batch['source_type'] ?? '');
                $srcId = (int) ($batch['source_id'] ?? 0);

                if (isset($seenBatchIds[$batchId])) {
                    $errors[] = "Duplicate posting batch id {$batchId} detected.";
                }
                $seenBatchIds[$batchId] = true;

                if ($batchId <= (int) $baseline['posting_batches_max_id']) {
                    $errors[] = "Posting batch id {$batchId} must be newer than baseline max {$baseline['posting_batches_max_id']}.";
                }

                if ((int) ($batch['company_id'] ?? 0) !== $companyId) {
                    $errors[] = "Posting batch {$batchId} company_id mismatch: expected {$companyId}, got ".($batch['company_id'] ?? 'null').'.';
                }

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
                $stmtLines = $db->prepare('SELECT `id`, `company_id`, `posting_batch_id`, `ledger_account_id`, `debit_base`, `credit_base` FROM `posting_lines` WHERE `company_id` = ? AND `posting_batch_id` = ? ORDER BY `id` ASC');
                $stmtLines->execute([$companyId, $batchId]);
                $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

                if (count($lines) < 2) {
                    $errors[] = "Posting batch {$batchId} has fewer than 2 posting lines.";
                }

                $debitSum = BigDecimal::zero();
                $creditSum = BigDecimal::zero();
                $sanitizedLines = [];
                foreach ($lines as $line) {
                    $lineId = (int) $line['id'];
                    if (isset($seenLineIdsAcrossBatches[$lineId])) {
                        $errors[] = "Duplicate posting line ID {$lineId} detected across posting batches.";
                    }
                    $seenLineIdsAcrossBatches[$lineId] = true;

                    if ($lineId <= (int) $baseline['posting_lines_max_id']) {
                        $errors[] = "Posting line ID {$lineId} must be newer than baseline max {$baseline['posting_lines_max_id']}.";
                    }

                    if ((int) ($line['company_id'] ?? 0) !== $companyId) {
                        $errors[] = "Posting line {$lineId} company_id mismatch: expected {$companyId}, got ".($line['company_id'] ?? 'null').'.';
                    }

                    if ((int) ($line['posting_batch_id'] ?? 0) !== $batchId) {
                        $errors[] = "Posting line {$lineId} posting_batch_id mismatch: expected {$batchId}, got ".($line['posting_batch_id'] ?? 'null').'.';
                    }

                    if ((int) ($line['ledger_account_id'] ?? 0) <= 0) {
                        $errors[] = "Posting line {$lineId} ledger_account_id must be a positive integer.";
                    }

                    $debitSum = $debitSum->plus((string) ($line['debit_base'] ?? '0'));
                    $creditSum = $creditSum->plus((string) ($line['credit_base'] ?? '0'));
                    $sanitizedLines[] = [
                        'id' => $lineId,
                        'company_id' => (int) ($line['company_id'] ?? $companyId),
                        'posting_batch_id' => (int) $line['posting_batch_id'],
                        'ledger_account_id' => (int) $line['ledger_account_id'],
                        'debit_base' => (string) ($line['debit_base'] ?? '0.000000'),
                        'credit_base' => (string) ($line['credit_base'] ?? '0.000000'),
                    ];
                }

                if ($debitSum->isZero() || $creditSum->isZero()) {
                    $errors[] = "Posting batch {$batchId} has zero debits or credits.";
                    $allBatchesBalanced = false;
                } elseif ($debitSum->toScale(6)->compareTo($creditSum->toScale(6)) !== 0) {
                    $errors[] = "Posting batch {$batchId} unbalanced: debit {$debitSum} vs credit {$creditSum}.";
                    $allBatchesBalanced = false;
                }

                $structuredBatches[] = [
                    'id' => $batchId,
                    'company_id' => (int) ($batch['company_id'] ?? $companyId),
                    'source_type' => $srcType,
                    'source_id' => $srcId,
                    'status' => (string) ($batch['status'] ?? ''),
                    'lines' => $sanitizedLines,
                ];
            }

            if (! $hasInvoiceBatch) {
                $errors[] = 'Posting batch for newly created sales invoice was not found.';
            }
            if (! $hasPaymentBatch) {
                $errors[] = 'Posting batch for newly created customer payment was not found.';
            }

            $totalSelectedLineCount = count($seenLineIdsAcrossBatches);
            if ($totalSelectedLineCount !== $deltas['posting_lines']) {
                $errors[] = "Total selected posting lines count {$totalSelectedLineCount} does not match posting_lines delta {$deltas['posting_lines']}.";
            }

            // 8. Inspect Document Sequences increment
            $baseSeqs = $baseline['document_sequences'];
            $currSeqs = $current['document_sequences'];
            $foundInvoiceSeqIncrement = false;
            $foundPaymentSeqIncrement = false;

            if (empty($baseSeqs) || empty($currSeqs)) {
                $errors[] = 'Document sequences before and after must be non-empty.';
            }

            foreach ($baseSeqs as $typeYear => $baseNext) {
                if (! array_key_exists($typeYear, $currSeqs)) {
                    $errors[] = "Document sequence '{$typeYear}' missing from post-run state.";
                    continue;
                }
                $currNext = $currSeqs[$typeYear];
                if (str_starts_with((string) $typeYear, 'sales_invoice:')) {
                    if ($currNext === $baseNext + 1) {
                        $foundInvoiceSeqIncrement = true;
                    } elseif ($currNext !== $baseNext) {
                        $errors[] = "Document sequence '{$typeYear}' invalid increment: base {$baseNext}, current {$currNext}.";
                    }
                } elseif (str_starts_with((string) $typeYear, 'customer_payment:')) {
                    if ($currNext === $baseNext + 1) {
                        $foundPaymentSeqIncrement = true;
                    } elseif ($currNext !== $baseNext) {
                        $errors[] = "Document sequence '{$typeYear}' invalid increment: base {$baseNext}, current {$currNext}.";
                    }
                } else {
                    if ($currNext !== $baseNext) {
                        $errors[] = "Unrelated document sequence '{$typeYear}' mutated: base {$baseNext}, current {$currNext}.";
                    }
                }
            }

            foreach ($currSeqs as $typeYear => $currNext) {
                if (! array_key_exists($typeYear, $baseSeqs)) {
                    $errors[] = "Unexpected document sequence '{$typeYear}' in post-run state.";
                }
            }

            if (! $foundInvoiceSeqIncrement) {
                $errors[] = 'Document sequence for sales_invoice did not increment by 1.';
            }
            if (! $foundPaymentSeqIncrement) {
                $errors[] = 'Document sequence for customer_payment did not increment by 1.';
            }

            // 9. Inspect Mobile Event Receipt
            $stmtReceipt = $db->prepare('SELECT `id`, `company_id`, `client_event_id`, `payload_digest`, `sales_invoice_id`, `sales_invoice_public_id`, `customer_payment_id`, `customer_payment_public_id`, `status` FROM `mobile_event_receipts` WHERE `company_id` = ? AND `id` > ? ORDER BY `id` ASC LIMIT 1');
            $stmtReceipt->execute([$companyId, (int) $baseline['mobile_event_receipts_max_id']]);
            $newReceipt = $stmtReceipt->fetch(PDO::FETCH_ASSOC);

            $newReceiptId = null;
            if (! $newReceipt) {
                $errors[] = 'Newly created mobile_event_receipt row not found.';
            } else {
                $newReceiptId = (int) $newReceipt['id'];
                if ((int) ($newReceipt['company_id'] ?? 0) !== $companyId) {
                    $errors[] = "Mobile event receipt company_id mismatch: expected {$companyId}, got ".($newReceipt['company_id'] ?? 'null').'.';
                }
                if ((string) ($newReceipt['client_event_id'] ?? '') !== $expectedClientEventId) {
                    $errors[] = "Mobile event receipt client_event_id mismatch: expected '{$expectedClientEventId}', got '".($newReceipt['client_event_id'] ?? 'null')."'.";
                }
                if (($newReceipt['status'] ?? '') !== 'completed') {
                    $errors[] = "Mobile event receipt status must be 'completed', got '".($newReceipt['status'] ?? 'null')."'.";
                }
                if ($newInvoiceId !== null && (int) ($newReceipt['sales_invoice_id'] ?? 0) !== $newInvoiceId) {
                    $errors[] = "Mobile event receipt sales_invoice_id mismatch: expected {$newInvoiceId}, got ".($newReceipt['sales_invoice_id'] ?? 'null').'.';
                }
                if ($newInvoicePublicId !== null && (string) ($newReceipt['sales_invoice_public_id'] ?? '') !== $newInvoicePublicId) {
                    $errors[] = "Mobile event receipt sales_invoice_public_id mismatch: expected '{$newInvoicePublicId}', got '".($newReceipt['sales_invoice_public_id'] ?? 'null')."'.";
                }
                if ($newPaymentId !== null && (int) ($newReceipt['customer_payment_id'] ?? 0) !== $newPaymentId) {
                    $errors[] = "Mobile event receipt customer_payment_id mismatch: expected {$newPaymentId}, got ".($newReceipt['customer_payment_id'] ?? 'null').'.';
                }
                if ($newPaymentPublicId !== null && (string) ($newReceipt['customer_payment_public_id'] ?? '') !== $newPaymentPublicId) {
                    $errors[] = "Mobile event receipt customer_payment_public_id mismatch: expected '{$newPaymentPublicId}', got '".($newReceipt['customer_payment_public_id'] ?? 'null')."'.";
                }
                if ($normalizedExpectedDigest !== null) {
                    $normReceiptDigest = str_starts_with((string) ($newReceipt['payload_digest'] ?? ''), 'sha256:')
                        ? substr((string) $newReceipt['payload_digest'], 7)
                        : (string) ($newReceipt['payload_digest'] ?? '');
                    if (! hash_equals($normalizedExpectedDigest, $normReceiptDigest)) {
                        $errors[] = "Mobile event receipt payload_digest mismatch: expected '{$normalizedExpectedDigest}', got '{$normReceiptDigest}'.";
                    }
                }
            }

            return [
                'is_canonical_delta_valid' => $errors === [],
                'expected_delta_one_event' => true,
                'deltas' => $deltas,
                'errors' => $errors,
                'gl_batches_balanced' => $allBatchesBalanced && $hasInvoiceBatch && $hasPaymentBatch,
                'canonical_graph' => [
                    'client_event_id' => (string) ($newReceipt['client_event_id'] ?? $expectedClientEventId),
                    'sales_invoice_id' => $newInvoiceId,
                    'sales_invoice_public_id' => $newInvoicePublicId,
                    'customer_payment_id' => $newPaymentId,
                    'customer_payment_public_id' => $newPaymentPublicId,
                    'stock_movement_quantity_delta' => $newSm !== null ? (string) ($newSm['quantity_delta_base'] ?? '') : null,
                    'customer_payment_allocation_amount' => $newPaymentAlloc !== null ? (string) ($newPaymentAlloc['allocated_amount'] ?? '') : null,
                    'customer_payment_allocation_payment_currency_amount' => $newPaymentAlloc !== null ? (string) ($newPaymentAlloc['payment_currency_amount'] ?? '') : null,
                    'receipt_status' => $newReceipt !== null ? (string) ($newReceipt['status'] ?? '') : null,
                    'sales_invoice' => $newInvoice ? [
                        'id' => (int) $newInvoice['id'],
                        'public_id' => (string) $newInvoice['public_id'],
                        'company_id' => (int) ($newInvoice['company_id'] ?? $companyId),
                        'status' => (string) $newInvoice['status'],
                        'grand_total_base' => (string) $newInvoice['grand_total_base'],
                        'posting_batch_id' => (int) $newInvoice['posting_batch_id'],
                    ] : null,
                    'sales_invoice_line' => $newLine ? [
                        'id' => (int) $newLine['id'],
                        'public_id' => (string) $newLine['public_id'],
                        'company_id' => (int) ($newLine['company_id'] ?? $companyId),
                        'sales_invoice_id' => (int) $newLine['sales_invoice_id'],
                        'line_number' => (int) $newLine['line_number'],
                        'quantity' => (string) $newLine['quantity'],
                        'unit_price' => (string) $newLine['unit_price'],
                        'line_total' => (string) $newLine['line_total'],
                    ] : null,
                    'sales_invoice_lot_allocation' => $newLotAlloc ? [
                        'id' => (int) $newLotAlloc['id'],
                        'company_id' => (int) ($newLotAlloc['company_id'] ?? $companyId),
                        'sales_invoice_id' => (int) $newLotAlloc['sales_invoice_id'],
                        'sales_invoice_line_id' => (int) $newLotAlloc['sales_invoice_line_id'],
                        'stock_movement_id' => isset($newLotAlloc['stock_movement_id']) && $newLotAlloc['stock_movement_id'] !== null ? (int) $newLotAlloc['stock_movement_id'] : null,
                        'quantity_allocated_base' => (string) $newLotAlloc['quantity_allocated_base'],
                    ] : null,
                    'customer_payment' => $newPayment ? [
                        'id' => (int) $newPayment['id'],
                        'public_id' => (string) $newPayment['public_id'],
                        'company_id' => (int) ($newPayment['company_id'] ?? $companyId),
                        'payment_method' => (string) $newPayment['payment_method'],
                        'money_account_id' => (int) $newPayment['money_account_id'],
                        'is_reversed' => (int) $newPayment['is_reversed'],
                        'reversed_at' => $newPayment['reversed_at'],
                        'amount' => (string) $newPayment['amount'],
                        'amount_base' => (string) $newPayment['amount_base'],
                        'posting_batch_id' => (int) $newPayment['posting_batch_id'],
                    ] : null,
                    'customer_payment_allocation' => $newPaymentAlloc ? [
                        'id' => (int) $newPaymentAlloc['id'],
                        'company_id' => (int) ($newPaymentAlloc['company_id'] ?? $companyId),
                        'customer_payment_id' => (int) $newPaymentAlloc['customer_payment_id'],
                        'sales_invoice_id' => (int) $newPaymentAlloc['sales_invoice_id'],
                        'allocated_amount' => (string) $newPaymentAlloc['allocated_amount'],
                        'payment_currency_amount' => (string) $newPaymentAlloc['payment_currency_amount'],
                    ] : null,
                    'stock_movement' => $newSm ? [
                        'id' => (int) $newSm['id'],
                        'company_id' => (int) ($newSm['company_id'] ?? $companyId),
                        'source_type' => (string) $newSm['source_type'],
                        'source_id' => (int) $newSm['source_id'],
                        'quantity_delta_base' => (string) $newSm['quantity_delta_base'],
                    ] : null,
                    'posting_batches' => $structuredBatches,
                    'document_sequences' => [
                        'before' => $baseSeqs,
                        'after' => $currSeqs,
                    ],
                    'mobile_event_receipt' => $newReceipt ? [
                        'id' => (int) $newReceipt['id'],
                        'company_id' => (int) $newReceipt['company_id'],
                        'client_event_id' => (string) $newReceipt['client_event_id'],
                        'payload_digest' => (string) $newReceipt['payload_digest'],
                        'sales_invoice_id' => (int) $newReceipt['sales_invoice_id'],
                        'sales_invoice_public_id' => (string) $newReceipt['sales_invoice_public_id'],
                        'customer_payment_id' => (int) $newReceipt['customer_payment_id'],
                        'customer_payment_public_id' => (string) $newReceipt['customer_payment_public_id'],
                        'status' => (string) $newReceipt['status'],
                    ] : null,
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
     * Binds minimal isolated container services required by CompanyContext and reconciliation
     * services: sessionless RequestGuard with null user contracts, array-only CacheManager,
     * and Spatie PermissionRegistrar. Autoload only, zero session files, zero default DB dials.
     */
    /**
     * Binds minimal isolated container services required by CompanyContext and reconciliation
     * services: sessionless RequestGuard with null user contracts, array-only CacheManager,
     * and Spatie PermissionRegistrar. Autoload only, zero session files, zero default DB dials.
     */
    public static function bindIsolatedContainerServices(\Illuminate\Foundation\Application $app): void
    {
        $appDir = dirname(__DIR__, 3);
        $permissionConfig = file_exists($appDir.'/config/permission.php')
            ? require $appDir.'/config/permission.php'
            : [];

        $existingConfig = $app->bound('config') ? $app->make('config')->all() : [];
        $mergedConfig = array_replace_recursive([
            'database' => [
                'default' => 'disposable_inspector',
                'connections' => [],
            ],
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => [
                        'driver' => 'array',
                        'serialize' => false,
                    ],
                ],
            ],
            'permission' => $permissionConfig,
        ], $existingConfig);

        $configRepo = new \Illuminate\Config\Repository($mergedConfig);
        // Force isolation invariants: caller config cannot override database default or cache array driver
        $configRepo->set('database.default', 'disposable_inspector');
        $configRepo->set('database.connections', []);
        $configRepo->set('cache.default', 'array');
        $configRepo->set('cache.stores.array', [
            'driver' => 'array',
            'serialize' => false,
        ]);
        $app->instance('config', $configRepo);

        // Minimal legitimate sessionless RequestGuard with null user contracts (zero session/DB IO)
        $sessionlessGuard = new \Illuminate\Auth\RequestGuard(
            fn () => null,
            new \Illuminate\Http\Request()
        );
        $authManager = new class($sessionlessGuard) implements \Illuminate\Contracts\Auth\Factory {
            public function __construct(private \Illuminate\Contracts\Auth\Guard $guard) {}
            public function guard($name = null): \Illuminate\Contracts\Auth\Guard { return $this->guard; }
            public function shouldUse($name): void {}
            public function __call(string $method, array $args): mixed { return $this->guard->{$method}(...$args); }
        };
        $app->instance('auth', $authManager);
        $app->instance(\Illuminate\Contracts\Auth\Factory::class, $authManager);
        $app->instance(\Illuminate\Contracts\Auth\Guard::class, $sessionlessGuard);

        // Array-only CacheManager and singleton PermissionRegistrar for CompanyContext::clearSessionless
        $cacheManager = new \Illuminate\Cache\CacheManager($app);
        $app->instance('cache', $cacheManager);
        $app->instance(\Illuminate\Contracts\Cache\Factory::class, $cacheManager);
        $app->instance(\Illuminate\Cache\CacheManager::class, $cacheManager);
        $app->singleton(\Spatie\Permission\PermissionRegistrar::class);

        $app->singleton(CompanyContext::class);
    }

    /**
     * Creates an isolated minimal Application container with sessionless auth,
     * array cache, and singleton PermissionRegistrar, restoring the ambient global container.
     */
    public static function createIsolatedBootstrapApplication(): \Illuminate\Foundation\Application
    {
        $rawContainer = (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue();

        try {
            $appDir = dirname(__DIR__, 3);
            $app = new \Illuminate\Foundation\Application($appDir);
            $app['env'] = 'testing';

            self::bindIsolatedContainerServices($app);

            return $app;
        } finally {
            \Illuminate\Container\Container::setInstance($rawContainer);
        }
    }

    /**
     * Ensures minimal standalone CLI bootstrap dependency closure before Spatie permission team snapshot.
     * Preserves caller's existing application, team ID, and facades when already bound.
     * If Container::$instance is null or bare generic Container, sets an isolated Application instance.
     * Never overwrites partially configured caller Application bindings.
     */
    public static function ensureStandaloneBootstrapDependencyClosure(): void
    {
        $container = \Illuminate\Container\Container::getInstance();
        if ($container instanceof \Illuminate\Foundation\Application) {
            // Preserves caller's existing application, team ID, and facades intact
            return;
        }

        $app = self::createIsolatedBootstrapApplication();
        \Illuminate\Container\Container::setInstance($app);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    }

    /**
     * Binds the isolated database connection directly wrapping the verified read-only PDO instance,
     * ensuring database.connections contains ONLY the owned connection and database.default is disposable_inspector.
     */
    public static function bindIsolatedDatabaseManager(\Illuminate\Foundation\Application $app, PDO $pdo, string $expectedSchemaName): \Illuminate\Database\DatabaseManager
    {
        $connection = new \Illuminate\Database\MySqlConnection($pdo, $expectedSchemaName, '', [
            'name' => 'disposable_inspector',
            'database' => $expectedSchemaName,
            'host' => '127.0.0.1',
            'port' => 3342,
            'url' => null,
        ]);
        $manager = new \Illuminate\Database\DatabaseManager($app, new \Illuminate\Database\Connectors\ConnectionFactory($app));
        $manager->extend('disposable_inspector', fn () => $connection);

        $app['config']->set('database.default', 'disposable_inspector');
        $app['config']->set('database.connections', [
            'disposable_inspector' => [
                'driver' => 'disposable_inspector',
                'database' => $expectedSchemaName,
                'host' => '127.0.0.1',
                'port' => 3342,
                'url' => null,
            ],
        ]);
        $app->instance('db', $manager);

        return $manager;
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
        // 1. Capture exact original global container instance (without triggering auto-instantiation)
        $containerInstanceRef = new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance');
        $previousGlobalContainer = $containerInstanceRef->getValue();

        // 2. Capture exact original facade application
        $facadeAppRef = new \ReflectionProperty(\Illuminate\Support\Facades\Facade::class, 'app');
        $previousApp = $facadeAppRef->getValue();

        // 3. Capture exact original resolved facade instances
        $facadeResolvedRef = new \ReflectionProperty(\Illuminate\Support\Facades\Facade::class, 'resolvedInstance');
        $previousResolvedInstances = $facadeResolvedRef->getValue();

        // 4. Capture exact original model connection resolver
        $previousResolver = \Illuminate\Database\Eloquent\Model::getConnectionResolver();

        $pdo = null;
        $app = null;
        $manager = null;
        $bareBootstrapApp = null;
        $hasSnapshottedTeamId = false;
        $previousTeamId = null;
        $results = [];
        $allHealthy = true;
        $primaryException = null;

        try {
            // 5. Snapshot caller permission team state WITHIN outer restoration try/finally
            if (function_exists('getPermissionsTeamId')) {
                try {
                    if ($previousGlobalContainer instanceof \Illuminate\Foundation\Application) {
                        // Caller is an existing Application: snapshot team ID using caller's application
                        // without mutating caller's container bindings or facades.
                        $previousTeamId = getPermissionsTeamId();
                        $hasSnapshottedTeamId = true;
                    } else {
                        // Bare CLI context (Container::$instance is null or bare generic Container):
                        // Scope temporary bootstrap container as active global while resolving registrar
                        // (required for global config() helper in PermissionRegistrar::__construct),
                        // with guaranteed immediate restoration of original global/facades in finally.
                        $bareBootstrapApp = self::createIsolatedBootstrapApplication();
                        try {
                            \Illuminate\Container\Container::setInstance($bareBootstrapApp);
                            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
                            \Illuminate\Support\Facades\Facade::setFacadeApplication($bareBootstrapApp);

                            $registrar = $bareBootstrapApp->make(\Spatie\Permission\PermissionRegistrar::class);
                            $previousTeamId = $registrar->getPermissionsTeamId();
                            $hasSnapshottedTeamId = true;
                        } finally {
                            \Illuminate\Container\Container::setInstance($previousGlobalContainer);
                            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
                            if (is_array($previousResolvedInstances)) {
                                $facadeResolvedRef->setValue(null, $previousResolvedInstances);
                            }
                            \Illuminate\Support\Facades\Facade::setFacadeApplication($previousApp);
                        }
                    }
                } catch (\Throwable $e) {
                    // Do not mask genuine permission resolution errors; fail-closed
                    throw new \RuntimeException('Failed to snapshot caller permission team ID: '.$e->getMessage(), previous: $e);
                }
            }

            $pdo = self::assertExecutionGates($expectedSchemaName);
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

            // Minimal isolated container: autoload only, no full service providers, sessions, or background jobs.
            $app = self::createIsolatedBootstrapApplication();
            $manager = self::bindIsolatedDatabaseManager($app, $pdo, $expectedSchemaName);

            // Clear caller cached facade instances BEFORE isolated facade use
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
            \Illuminate\Container\Container::setInstance($app);
            \Illuminate\Database\Eloquent\Model::setConnectionResolver($manager);

            // Assert strict isolation invariants
            if (\Illuminate\Support\Facades\DB::getFacadeRoot() !== $manager) {
                throw new \RuntimeException('DB facade root does not match isolated database manager.');
            }
            if (\Illuminate\Database\Eloquent\Model::getConnectionResolver() !== $manager) {
                throw new \RuntimeException('Model connection resolver does not match isolated database manager.');
            }
            if ($manager->getDefaultConnection() !== 'disposable_inspector') {
                throw new \RuntimeException("Manager default connection must be 'disposable_inspector', got: ".$manager->getDefaultConnection());
            }
            if ($manager->connection('disposable_inspector')->getPdo() !== $pdo) {
                throw new \RuntimeException('Manager connection PDO does not match verified read-only PDO instance.');
            }
            if (\Illuminate\Support\Facades\DB::getPdo() !== $pdo) {
                throw new \RuntimeException('DB facade PDO does not match verified read-only PDO instance.');
            }

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
        } catch (\Throwable $e) {
            $primaryException = $e;
            throw $e;
        } finally {
            $cleanupExceptions = [];

            // 1. Rollback snapshot on read-only PDO
            if ($pdo instanceof PDO) {
                try {
                    $pdo->rollBack();
                } catch (\Throwable $e) {
                    $cleanupExceptions[] = 'PDO rollback failed: '.$e->getMessage();
                }
            }

            // 2. Purge database manager (purge disconnects and unsets connection instance; no trailing disconnect)
            if ($manager instanceof \Illuminate\Database\DatabaseManager) {
                try {
                    $manager->purge('disposable_inspector');
                } catch (\Throwable $e) {
                    $cleanupExceptions[] = 'DB manager purge failed: '.$e->getMessage();
                }
            }

            // 3. Clear only isolated context while isolated container and facades are still active
            if ($app instanceof \Illuminate\Foundation\Application && $app->bound(CompanyContext::class)) {
                try {
                    $app->make(CompanyContext::class)->clear(sessionless: true);
                } catch (\Throwable $e) {
                    $cleanupExceptions[] = 'Isolated CompanyContext clear failed: '.$e->getMessage();
                }
            }

            // 4. Restore exact previous model connection resolver
            try {
                if ($previousResolver !== null) {
                    \Illuminate\Database\Eloquent\Model::setConnectionResolver($previousResolver);
                } else {
                    \Illuminate\Database\Eloquent\Model::unsetConnectionResolver();
                }
            } catch (\Throwable $e) {
                $cleanupExceptions[] = 'Model resolver restoration failed: '.$e->getMessage();
            }

            // 5. Clear isolated facade cache and safely restore caller facade application and cached instances
            try {
                \Illuminate\Support\Facades\Facade::clearResolvedInstances();
                if (is_array($previousResolvedInstances)) {
                    $facadeResolvedRef->setValue(null, $previousResolvedInstances);
                }
                \Illuminate\Support\Facades\Facade::setFacadeApplication($previousApp);
            } catch (\Throwable $e) {
                $cleanupExceptions[] = 'Facade application restoration failed: '.$e->getMessage();
            }

            // 6. Restore exact previous global container (including null)
            try {
                \Illuminate\Container\Container::setInstance($previousGlobalContainer);
            } catch (\Throwable $e) {
                $cleanupExceptions[] = 'Global container restoration failed: '.$e->getMessage();
            }

            // 7. Restore exact previous permission team ID (without touching caller context or old guard/session)
            if ($hasSnapshottedTeamId && function_exists('setPermissionsTeamId')) {
                try {
                    if ($previousGlobalContainer instanceof \Illuminate\Foundation\Application) {
                        setPermissionsTeamId($previousTeamId);
                    }
                } catch (\Throwable $e) {
                    $cleanupExceptions[] = 'Permission teamId restoration failed: '.$e->getMessage();
                }
            }

            // 8. Flush isolated applications
            if ($app instanceof \Illuminate\Foundation\Application) {
                try {
                    $app->flush();
                } catch (\Throwable $e) {
                    $cleanupExceptions[] = 'Isolated app flush failed: '.$e->getMessage();
                }
            }
            if ($bareBootstrapApp instanceof \Illuminate\Foundation\Application) {
                try {
                    $bareBootstrapApp->flush();
                } catch (\Throwable $e) {
                    $cleanupExceptions[] = 'Bare bootstrap app flush failed: '.$e->getMessage();
                }
            }

            // Ensure cleanup failure is not swallowed when claiming successful reconciliation
            if (! empty($cleanupExceptions)) {
                $cleanupSummary = implode('; ', $cleanupExceptions);
                if ($primaryException !== null) {
                    throw new \RuntimeException(
                        "Reconciliation failed with error: {$primaryException->getMessage()}. Additionally, cleanup failed: {$cleanupSummary}",
                        previous: $primaryException
                    );
                }
                throw new \RuntimeException("Reconciliation cleanup failed: {$cleanupSummary}");
            }
        }

        return [
            'all_healthy' => $allHealthy,
            'reconciler_results' => $results,
        ];
    }

    /**
     * Canonicalizes raw baseline metrics into the authoritative deterministic schema.
     *
     * @param  int  $companyId
     * @param  string  $schemaName
     * @param  array<string, mixed>  $baseline
     * @return array<string, mixed>
     */
    public static function canonicalizeBaselineMetrics(int $companyId, string $schemaName, array $baseline): array
    {
        return [
            'company_id' => $companyId,
            'schema_name' => $schemaName,
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
    }

    /**
     * Computes the authoritative canonical baseline metrics SHA-256 hash.
     *
     * @param  int  $companyId
     * @param  string  $schemaName
     * @param  array<string, mixed>  $baseline
     * @return string 64-hex SHA-256 hash
     */
    public static function computeCanonicalBaselineHash(int $companyId, string $schemaName, array $baseline): string
    {
        $canonical = self::canonicalizeBaselineMetrics($companyId, $schemaName, $baseline);

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Composes a complete, bounded, deterministic Trusted DB Inspection Report
     * from verified baseline, delta, and reconciliation results.
     * Generates a root-pinnable canonical report digest over deterministic metrics.
     *
     * @param  array{
     *     scope: string,
     *     runId: string,
     *     eventId: string,
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
    public static function computeReportDigest(array $coreDeterministicPayload): string
    {
        return hash('sha256', CanonicalJsonSerializer::serialize($coreDeterministicPayload));
    }

    /**
     * Composes an authoritative trusted DB inspection report from individually verified components.
     *
     * @param  array{
     *     scope: string,
     *     runId: string,
     *     eventId: string,
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
        foreach (['scope', 'runId', 'eventId', 'schemaName', 'companyId', 'sourceHead', 'handoffDigest'] as $mKey) {
            if (! isset($metadata[$mKey]) || $metadata[$mKey] === '' || $metadata[$mKey] === 0) {
                throw new InvalidArgumentException("Missing required metadata field '{$mKey}' for trusted DB report composition.");
            }
        }

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', (string) $metadata['eventId'])) {
            throw new InvalidArgumentException("Invalid eventId format '{$metadata['eventId']}' in metadata: expected UUID.");
        }

        // Canonical baseline metrics hash
        $canonicalBaseline = self::canonicalizeBaselineMetrics(
            (int) $metadata['companyId'],
            (string) $metadata['schemaName'],
            $baseline
        );
        $baselineHash = hash('sha256', (string) json_encode($canonicalBaseline, JSON_UNESCAPED_SLASHES));

        $isDeltaValid = (bool) ($deltaResult['is_canonical_delta_valid'] ?? false);
        $glBalanced = (bool) ($deltaResult['gl_batches_balanced'] ?? false);
        $allHealthy = (bool) ($reconcileResult['all_healthy'] ?? false);

        // Core deterministic payload for root-pinnable report digest: binds event identity, deltas, and canonical graph
        $coreDeterministicPayload = [
            'scope' => (string) $metadata['scope'],
            'run_id' => (string) $metadata['runId'],
            'event_id' => (string) $metadata['eventId'],
            'schema_name' => (string) $metadata['schemaName'],
            'company_id' => (int) $metadata['companyId'],
            'source_head' => (string) $metadata['sourceHead'],
            'handoff_digest' => (string) $metadata['handoffDigest'],
            'baseline_hash' => $baselineHash,
            'deltas' => $deltaResult['deltas'] ?? [],
            'canonical_graph' => $deltaResult['canonical_graph'] ?? [],
            'is_canonical_delta_valid' => $isDeltaValid,
            'gl_batches_balanced' => $glBalanced,
            'all_healthy' => $allHealthy,
            'reconciler_results' => $reconcileResult['reconciler_results'] ?? [],
        ];

        $reportDigest = self::computeReportDigest($coreDeterministicPayload);

        return [
            'scope' => (string) $metadata['scope'],
            'run_id' => (string) $metadata['runId'],
            'event_id' => (string) $metadata['eventId'],
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
     *     expectedRunId: string,
     *     expectedEventId: string,
     *     expectedPayloadDigest: string,
     *     expectedBaselineHash: string,
     *     expectedReportDigest?: string,
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

            // Required 2 probe scenarios: MUST be explicitly present and marked 'NOT_RUN' with statusCode exact JSON null
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
                if (! array_key_exists('httpStatusCode', $sc) || $sc['httpStatusCode'] !== null) {
                    $val = array_key_exists('httpStatusCode', $sc) ? json_encode($sc['httpStatusCode']) : 'MISSING';
                    $errors[] = "Advanced probe scenario '{$probeName}' httpStatusCode must be exact JSON null, got {$val}.";
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

            $expectedEventId = (string) ($expectedPins['expectedEventId'] ?? '');
            if ($expectedEventId === '' || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $expectedEventId)) {
                $errors[] = "Expected pin 'expectedEventId' is mandatory and must be a valid UUID; cannot be omitted or empty.";
            } else {
                if ((string) ($trustedDbReport['event_id'] ?? '') !== $expectedEventId) {
                    $errors[] = "Trusted DB report event_id mismatch: expected '{$expectedEventId}', got '".($trustedDbReport['event_id'] ?? 'null')."'.";
                }
                $graphClientEventId = (string) ($trustedDbReport['canonical_graph']['client_event_id'] ?? '');
                if ($graphClientEventId !== $expectedEventId) {
                    $errors[] = "Trusted DB report canonical_graph client_event_id mismatch: expected '{$expectedEventId}', got '{$graphClientEventId}'.";
                }
            }

            $expectedPayloadDigest = (string) ($expectedPins['expectedPayloadDigest'] ?? '');
            $normExpectedDigest = null;
            if ($expectedPayloadDigest === '') {
                $errors[] = "Expected pin 'expectedPayloadDigest' is mandatory and external; cannot be omitted or empty.";
            } else {
                $normExpectedDigest = str_starts_with($expectedPayloadDigest, 'sha256:')
                    ? substr($expectedPayloadDigest, 7)
                    : $expectedPayloadDigest;
                if (! preg_match('/^[0-9a-f]{64}$/iD', $normExpectedDigest)) {
                    $errors[] = "Invalid expectedPayloadDigest format '{$expectedPayloadDigest}': expected 64-character hex SHA-256.";
                    $normExpectedDigest = null;
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

            // Assert complete exact expected deltas
            $deltas = $trustedDbReport['deltas'] ?? null;
            if (! is_array($deltas) || empty($deltas)) {
                $errors[] = 'Trusted DB report deltas array is missing or empty.';
            } else {
                $expectedDeltaCounts = [
                    'sales_invoices' => 1,
                    'sales_invoice_lines' => 1,
                    'sales_invoice_lot_allocations' => 1,
                    'customer_payments' => 1,
                    'customer_payment_allocations' => 1,
                    'mobile_event_receipts' => 1,
                    'stock_movements' => 1,
                    'posting_batches' => 2,
                ];
                foreach ($expectedDeltaCounts as $dKey => $expectedCount) {
                    if (! array_key_exists($dKey, $deltas)) {
                        $errors[] = "Trusted DB report deltas missing required key '{$dKey}'.";
                    } elseif ($deltas[$dKey] !== $expectedCount) {
                        $errors[] = "Trusted DB report delta '{$dKey}' mismatch: expected {$expectedCount}, got {$deltas[$dKey]}.";
                    }
                }
                if (! array_key_exists('posting_lines', $deltas)) {
                    $errors[] = "Trusted DB report deltas missing required key 'posting_lines'.";
                } elseif (($deltas['posting_lines'] ?? 0) < 4) {
                    $errors[] = "Trusted DB report delta 'posting_lines' must be >= 4, got ".($deltas['posting_lines'] ?? 'null').'.';
                }
            }

            // Assert complete canonical graph entity identities and relationships
            $graph = $trustedDbReport['canonical_graph'] ?? null;
            if (! is_array($graph) || empty($graph)) {
                $errors[] = 'Trusted DB report canonical_graph is missing or empty.';
            } else {
                $baseMetrics = $trustedDbReport['baseline_metrics'] ?? null;
                if (! is_array($baseMetrics)) {
                    $errors[] = 'Trusted DB report missing baseline_metrics.';
                }

                $invMaxId = (int) ($baseMetrics['sales_invoices_max_id'] ?? 0);
                $lineMaxId = (int) ($baseMetrics['sales_invoice_lines_max_id'] ?? 0);
                $lotMaxId = (int) ($baseMetrics['sales_invoice_lot_allocations_max_id'] ?? 0);
                $payMaxId = (int) ($baseMetrics['customer_payments_max_id'] ?? 0);
                $payAllocMaxId = (int) ($baseMetrics['customer_payment_allocations_max_id'] ?? 0);
                $smMaxId = (int) ($baseMetrics['stock_movements_max_id'] ?? 0);
                $receiptMaxId = (int) ($baseMetrics['mobile_event_receipts_max_id'] ?? 0);
                $batchMaxId = (int) ($baseMetrics['posting_batches_max_id'] ?? 0);
                $lineMaxIdGl = (int) ($baseMetrics['posting_lines_max_id'] ?? 0);

                $invId = $graph['sales_invoice_id'] ?? null;
                $invPubId = (string) ($graph['sales_invoice_public_id'] ?? '');
                $payId = $graph['customer_payment_id'] ?? null;
                $payPubId = (string) ($graph['customer_payment_public_id'] ?? '');

                if (! is_int($invId) || $invId <= $invMaxId) {
                    $errors[] = "Canonical graph sales_invoice_id must be newer than baseline max ({$invMaxId}), got ".json_encode($invId).'.';
                }
                if ($invPubId === '') {
                    $errors[] = 'Canonical graph sales_invoice_public_id must be a non-empty string.';
                }
                if (! is_int($payId) || $payId <= $payMaxId) {
                    $errors[] = "Canonical graph customer_payment_id must be newer than baseline max ({$payMaxId}), got ".json_encode($payId).'.';
                }
                if ($payPubId === '') {
                    $errors[] = 'Canonical graph customer_payment_public_id must be a non-empty string.';
                }
                // Domain distinction is enforced through distinct public ULIDs and strictly validated FK pointers
                if ($invPubId !== '' && $payPubId !== '' && $invPubId === $payPubId) {
                    $errors[] = "Canonical graph sales_invoice_public_id and customer_payment_public_id must be distinct ('{$invPubId}').";
                }

                if (($graph['stock_movement_quantity_delta'] ?? '') !== '-1.000000') {
                    $errors[] = "Canonical graph stock_movement_quantity_delta must be '-1.000000', got '".($graph['stock_movement_quantity_delta'] ?? 'null')."'.";
                }
                if (($graph['customer_payment_allocation_amount'] ?? '') !== '58.000000') {
                    $errors[] = "Canonical graph customer_payment_allocation_amount must be '58.000000', got '".($graph['customer_payment_allocation_amount'] ?? 'null')."'.";
                }
                if (($graph['customer_payment_allocation_payment_currency_amount'] ?? '') !== '58.000000') {
                    $errors[] = "Canonical graph customer_payment_allocation_payment_currency_amount must be '58.000000', got '".($graph['customer_payment_allocation_payment_currency_amount'] ?? 'null')."'.";
                }
                if (($graph['receipt_status'] ?? '') !== 'completed') {
                    $errors[] = "Canonical graph receipt_status must be 'completed', got '".($graph['receipt_status'] ?? 'null')."'.";
                }

                // 1. Sales Invoice entity in canonical_graph
                $gInvoice = $graph['sales_invoice'] ?? null;
                if (! is_array($gInvoice)) {
                    $errors[] = 'Canonical graph sales_invoice entry is missing or not an array.';
                } else {
                    if ((int) ($gInvoice['id'] ?? 0) <= $invMaxId) {
                        $errors[] = "Canonical graph sales_invoice.id must be newer than baseline max ({$invMaxId}), got ".($gInvoice['id'] ?? 'null').'.';
                    }
                    if ((int) ($gInvoice['id'] ?? 0) !== $invId) {
                        $errors[] = "Canonical graph sales_invoice.id mismatch: expected {$invId}, got ".($gInvoice['id'] ?? 'null').'.';
                    }
                    if ((string) ($gInvoice['public_id'] ?? '') !== $invPubId) {
                        $errors[] = "Canonical graph sales_invoice.public_id mismatch: expected '{$invPubId}', got '".($gInvoice['public_id'] ?? 'null')."'.";
                    }
                    if ($expectedCompanyId > 0 && (int) ($gInvoice['company_id'] ?? 0) !== $expectedCompanyId) {
                        $errors[] = "Canonical graph sales_invoice.company_id mismatch: expected {$expectedCompanyId}, got ".($gInvoice['company_id'] ?? 'null').'.';
                    }
                    if (($gInvoice['status'] ?? '') !== 'posted') {
                        $errors[] = "Canonical graph sales_invoice.status must be 'posted', got '".($gInvoice['status'] ?? 'null')."'.";
                    }
                    if (($gInvoice['grand_total_base'] ?? '') !== '58.000000') {
                        $errors[] = "Canonical graph sales_invoice.grand_total_base must be '58.000000', got '".($gInvoice['grand_total_base'] ?? 'null')."'.";
                    }
                    if (! is_int($gInvoice['posting_batch_id'] ?? null) || ($gInvoice['posting_batch_id'] ?? 0) <= $batchMaxId) {
                        $errors[] = "Canonical graph sales_invoice.posting_batch_id must be newer than baseline max ({$batchMaxId}).";
                    }
                }

                // 2. Sales Invoice Line entity in canonical_graph
                $gLine = $graph['sales_invoice_line'] ?? null;
                $lineId = null;
                if (! is_array($gLine)) {
                    $errors[] = 'Canonical graph sales_invoice_line entry is missing or not an array.';
                } else {
                    $lineId = (int) ($gLine['id'] ?? 0);
                    if ($lineId <= $lineMaxId) {
                        $errors[] = "Canonical graph sales_invoice_line.id must be newer than baseline max ({$lineMaxId}), got {$lineId}.";
                    }
                    if ((string) ($gLine['public_id'] ?? '') === '') {
                        $errors[] = 'Canonical graph sales_invoice_line.public_id must be a non-empty string.';
                    }
                    if ($expectedCompanyId > 0 && (int) ($gLine['company_id'] ?? 0) !== $expectedCompanyId) {
                        $errors[] = "Canonical graph sales_invoice_line.company_id mismatch: expected {$expectedCompanyId}, got ".($gLine['company_id'] ?? 'null').'.';
                    }
                    if ((int) ($gLine['sales_invoice_id'] ?? 0) !== $invId) {
                        $errors[] = "Canonical graph sales_invoice_line.sales_invoice_id mismatch: expected {$invId}, got ".($gLine['sales_invoice_id'] ?? 'null').'.';
                    }
                    if (($gLine['quantity'] ?? '') !== '1.000000') {
                        $errors[] = "Canonical graph sales_invoice_line.quantity must be '1.000000', got '".($gLine['quantity'] ?? 'null')."'.";
                    }
                    if (($gLine['unit_price'] ?? '') !== '50.000000') {
                        $errors[] = "Canonical graph sales_invoice_line.unit_price must be '50.000000', got '".($gLine['unit_price'] ?? 'null')."'.";
                    }
                    if (($gLine['line_total'] ?? '') !== '58.000000') {
                        $errors[] = "Canonical graph sales_invoice_line.line_total must be '58.000000', got '".($gLine['line_total'] ?? 'null')."'.";
                    }
                }

                // 3. Stock Movement entity in canonical_graph
                $gSm = $graph['stock_movement'] ?? null;
                $smId = null;
                if (! is_array($gSm)) {
                    $errors[] = 'Canonical graph stock_movement entry is missing or not an array.';
                } else {
                    $smId = (int) ($gSm['id'] ?? 0);
                    if ($smId <= $smMaxId) {
                        $errors[] = "Canonical graph stock_movement.id must be newer than baseline max ({$smMaxId}), got {$smId}.";
                    }
                    if ($expectedCompanyId > 0 && (int) ($gSm['company_id'] ?? 0) !== $expectedCompanyId) {
                        $errors[] = "Canonical graph stock_movement.company_id mismatch: expected {$expectedCompanyId}, got ".($gSm['company_id'] ?? 'null').'.';
                    }
                    if (($gSm['source_type'] ?? '') !== 'sales_invoice') {
                        $errors[] = "Canonical graph stock_movement.source_type must be 'sales_invoice', got '".($gSm['source_type'] ?? 'null')."'.";
                    }
                    if ((int) ($gSm['source_id'] ?? 0) !== $invId) {
                        $errors[] = "Canonical graph stock_movement.source_id mismatch: expected {$invId}, got ".($gSm['source_id'] ?? 'null').'.';
                    }
                    if (($gSm['quantity_delta_base'] ?? '') !== '-1.000000') {
                        $errors[] = "Canonical graph stock_movement.quantity_delta_base must be '-1.000000', got '".($gSm['quantity_delta_base'] ?? 'null')."'.";
                    }
                }

                // 4. Sales Invoice Lot Allocation entity in canonical_graph
                $gLotAlloc = $graph['sales_invoice_lot_allocation'] ?? null;
                if (! is_array($gLotAlloc)) {
                    $errors[] = 'Canonical graph sales_invoice_lot_allocation entry is missing or not an array.';
                } else {
                    $lotId = (int) ($gLotAlloc['id'] ?? 0);
                    if ($lotId <= $lotMaxId) {
                        $errors[] = "Canonical graph sales_invoice_lot_allocation.id must be newer than baseline max ({$lotMaxId}), got {$lotId}.";
                    }
                    if ($expectedCompanyId > 0 && (int) ($gLotAlloc['company_id'] ?? 0) !== $expectedCompanyId) {
                        $errors[] = "Canonical graph sales_invoice_lot_allocation.company_id mismatch: expected {$expectedCompanyId}, got ".($gLotAlloc['company_id'] ?? 'null').'.';
                    }
                    if ((int) ($gLotAlloc['sales_invoice_id'] ?? 0) !== $invId) {
                        $errors[] = "Canonical graph sales_invoice_lot_allocation.sales_invoice_id mismatch: expected {$invId}, got ".($gLotAlloc['sales_invoice_id'] ?? 'null').'.';
                    }
                    if ($lineId !== null && (int) ($gLotAlloc['sales_invoice_line_id'] ?? 0) !== $lineId) {
                        $errors[] = "Canonical graph sales_invoice_lot_allocation.sales_invoice_line_id mismatch: expected {$lineId}, got ".($gLotAlloc['sales_invoice_line_id'] ?? 'null').'.';
                    }
                    if (! isset($gLotAlloc['stock_movement_id']) || $gLotAlloc['stock_movement_id'] === null) {
                        $errors[] = 'Canonical graph sales_invoice_lot_allocation.stock_movement_id must be non-null.';
                    } elseif ($smId !== null && (int) $gLotAlloc['stock_movement_id'] !== $smId) {
                        $errors[] = "Canonical graph sales_invoice_lot_allocation.stock_movement_id mismatch: expected {$smId}, got ".($gLotAlloc['stock_movement_id'] ?? 'null').'.';
                    }
                    if (($gLotAlloc['quantity_allocated_base'] ?? '') !== '1.000000') {
                        $errors[] = "Canonical graph sales_invoice_lot_allocation.quantity_allocated_base must be '1.000000', got '".($gLotAlloc['quantity_allocated_base'] ?? 'null')."'.";
                    }
                }

                // 5. Customer Payment entity in canonical_graph
                $gPayment = $graph['customer_payment'] ?? null;
                if (! is_array($gPayment)) {
                    $errors[] = 'Canonical graph customer_payment entry is missing or not an array.';
                } else {
                    if ((int) ($gPayment['id'] ?? 0) <= $payMaxId) {
                        $errors[] = "Canonical graph customer_payment.id must be newer than baseline max ({$payMaxId}), got ".($gPayment['id'] ?? 'null').'.';
                    }
                    if ((int) ($gPayment['id'] ?? 0) !== $payId) {
                        $errors[] = "Canonical graph customer_payment.id mismatch: expected {$payId}, got ".($gPayment['id'] ?? 'null').'.';
                    }
                    if ((string) ($gPayment['public_id'] ?? '') !== $payPubId) {
                        $errors[] = "Canonical graph customer_payment.public_id mismatch: expected '{$payPubId}', got '".($gPayment['public_id'] ?? 'null')."'.";
                    }
                    if ($expectedCompanyId > 0 && (int) ($gPayment['company_id'] ?? 0) !== $expectedCompanyId) {
                        $errors[] = "Canonical graph customer_payment.company_id mismatch: expected {$expectedCompanyId}, got ".($gPayment['company_id'] ?? 'null').'.';
                    }
                    if (($gPayment['payment_method'] ?? '') !== 'cash') {
                        $errors[] = "Canonical graph customer_payment.payment_method must be 'cash', got '".($gPayment['payment_method'] ?? 'null')."'.";
                    }
                    if (! is_int($gPayment['money_account_id'] ?? null) || ($gPayment['money_account_id'] ?? 0) <= 0) {
                        $errors[] = 'Canonical graph customer_payment.money_account_id must be a positive integer.';
                    }
                    if (! array_key_exists('is_reversed', $gPayment) || (int) $gPayment['is_reversed'] !== 0) {
                        $errors[] = 'Canonical graph customer_payment.is_reversed must be 0.';
                    }
                    if (! array_key_exists('reversed_at', $gPayment) || $gPayment['reversed_at'] !== null) {
                        $errors[] = "Canonical graph customer_payment.reversed_at must be null, got ".json_encode($gPayment['reversed_at'] ?? 'MISSING').'.';
                    }
                    if (($gPayment['amount'] ?? '') !== '58.000000') {
                        $errors[] = "Canonical graph customer_payment.amount must be '58.000000', got '".($gPayment['amount'] ?? 'null')."'.";
                    }
                    if (($gPayment['amount_base'] ?? '') !== '58.000000') {
                        $errors[] = "Canonical graph customer_payment.amount_base must be '58.000000', got '".($gPayment['amount_base'] ?? 'null')."'.";
                    }
                    if (! is_int($gPayment['posting_batch_id'] ?? null) || ($gPayment['posting_batch_id'] ?? 0) <= $batchMaxId) {
                        $errors[] = "Canonical graph customer_payment.posting_batch_id must be newer than baseline max ({$batchMaxId}).";
                    }
                }

                // 6. Customer Payment Allocation entity in canonical_graph
                $gPayAlloc = $graph['customer_payment_allocation'] ?? null;
                if (! is_array($gPayAlloc)) {
                    $errors[] = 'Canonical graph customer_payment_allocation entry is missing or not an array.';
                } else {
                    $allocId = (int) ($gPayAlloc['id'] ?? 0);
                    if ($allocId <= $payAllocMaxId) {
                        $errors[] = "Canonical graph customer_payment_allocation.id must be newer than baseline max ({$payAllocMaxId}), got {$allocId}.";
                    }
                    if ($expectedCompanyId > 0 && (int) ($gPayAlloc['company_id'] ?? 0) !== $expectedCompanyId) {
                        $errors[] = "Canonical graph customer_payment_allocation.company_id mismatch: expected {$expectedCompanyId}, got ".($gPayAlloc['company_id'] ?? 'null').'.';
                    }
                    if ((int) ($gPayAlloc['customer_payment_id'] ?? 0) !== $payId) {
                        $errors[] = "Canonical graph customer_payment_allocation.customer_payment_id mismatch: expected {$payId}, got ".($gPayAlloc['customer_payment_id'] ?? 'null').'.';
                    }
                    if ((int) ($gPayAlloc['sales_invoice_id'] ?? 0) !== $invId) {
                        $errors[] = "Canonical graph customer_payment_allocation.sales_invoice_id mismatch: expected {$invId}, got ".($gPayAlloc['sales_invoice_id'] ?? 'null').'.';
                    }
                    if (($gPayAlloc['allocated_amount'] ?? '') !== '58.000000') {
                        $errors[] = "Canonical graph customer_payment_allocation.allocated_amount must be '58.000000', got '".($gPayAlloc['allocated_amount'] ?? 'null')."'.";
                    }
                    if (($gPayAlloc['payment_currency_amount'] ?? '') !== '58.000000') {
                        $errors[] = "Canonical graph customer_payment_allocation.payment_currency_amount must be '58.000000', got '".($gPayAlloc['payment_currency_amount'] ?? 'null')."'.";
                    }
                }

                // 7. GL Posting Batches in canonical_graph
                $gBatches = $graph['posting_batches'] ?? null;
                if (! is_array($gBatches) || count($gBatches) !== 2) {
                    $errors[] = 'Canonical graph posting_batches must contain exactly 2 batches, got '.(is_array($gBatches) ? count($gBatches) : 'non-array').'.';
                } else {
                    $foundInvBatch = false;
                    $foundPayBatch = false;
                    $seenOuterBatchIds = [];
                    $seenOuterLineIds = [];

                    foreach ($gBatches as $b) {
                        $bId = (int) ($b['id'] ?? 0);
                        if ($bId <= $batchMaxId) {
                            $errors[] = "Canonical graph posting batch ID {$bId} must be newer than baseline max ({$batchMaxId}).";
                        }
                        if (isset($seenOuterBatchIds[$bId])) {
                            $errors[] = "Duplicate posting batch ID {$bId} detected in canonical graph.";
                        }
                        $seenOuterBatchIds[$bId] = true;

                        if ($expectedCompanyId > 0 && (int) ($b['company_id'] ?? 0) !== $expectedCompanyId) {
                            $errors[] = "Canonical graph posting batch {$bId} company_id mismatch: expected {$expectedCompanyId}, got ".($b['company_id'] ?? 'null').'.';
                        }

                        $srcType = (string) ($b['source_type'] ?? '');
                        $srcId = (int) ($b['source_id'] ?? 0);
                        $status = (string) ($b['status'] ?? '');
                        if ($status !== 'posted') {
                            $errors[] = "Canonical graph posting batch {$bId} status must be 'posted', got '{$status}'.";
                        }
                        if ($srcType === 'sales_invoice' && $srcId === $invId) {
                            $foundInvBatch = true;
                            if (isset($gInvoice['posting_batch_id']) && $bId !== (int) $gInvoice['posting_batch_id']) {
                                $errors[] = "Posting batch ID {$bId} does not match sales_invoice.posting_batch_id {$gInvoice['posting_batch_id']}.";
                            }
                        } elseif ($srcType === 'customer_payment' && $srcId === $payId) {
                            $foundPayBatch = true;
                            if (isset($gPayment['posting_batch_id']) && $bId !== (int) $gPayment['posting_batch_id']) {
                                $errors[] = "Posting batch ID {$bId} does not match customer_payment.posting_batch_id {$gPayment['posting_batch_id']}.";
                            }
                        }

                        $bLines = $b['lines'] ?? [];
                        if (! is_array($bLines) || count($bLines) < 2) {
                            $errors[] = "Canonical graph posting batch {$bId} must have >= 2 posting lines.";
                        } else {
                            $deb = BigDecimal::zero();
                            $crd = BigDecimal::zero();
                            foreach ($bLines as $l) {
                                $lId = (int) ($l['id'] ?? 0);
                                if ($lId <= $lineMaxIdGl) {
                                    $errors[] = "Canonical graph posting line ID {$lId} must be newer than baseline max ({$lineMaxIdGl}).";
                                }
                                if (isset($seenOuterLineIds[$lId])) {
                                    $errors[] = "Duplicate posting line ID {$lId} detected across posting batches.";
                                }
                                $seenOuterLineIds[$lId] = true;

                                if ($expectedCompanyId > 0 && (int) ($l['company_id'] ?? 0) !== $expectedCompanyId) {
                                    $errors[] = "Canonical graph posting line {$lId} company_id mismatch: expected {$expectedCompanyId}, got ".($l['company_id'] ?? 'null').'.';
                                }

                                if ((int) ($l['posting_batch_id'] ?? 0) !== $bId) {
                                    $errors[] = "Canonical graph posting line {$lId} posting_batch_id mismatch: expected {$bId}, got ".($l['posting_batch_id'] ?? 'null').'.';
                                }

                                if ((int) ($l['ledger_account_id'] ?? 0) <= 0) {
                                    $errors[] = "Canonical graph posting line {$lId} ledger_account_id must be a positive integer.";
                                }

                                $deb = $deb->plus((string) ($l['debit_base'] ?? '0'));
                                $crd = $crd->plus((string) ($l['credit_base'] ?? '0'));
                            }
                            if ($deb->isZero() || $crd->isZero() || $deb->toScale(6)->compareTo($crd->toScale(6)) !== 0) {
                                $errors[] = "Canonical graph posting batch {$bId} lines are unbalanced: debit {$deb} vs credit {$crd}.";
                            }
                        }
                    }
                    if (! $foundInvBatch) {
                        $errors[] = "Canonical graph missing posting batch for sales_invoice {$invId}.";
                    }
                    if (! $foundPayBatch) {
                        $errors[] = "Canonical graph missing posting batch for customer_payment {$payId}.";
                    }

                    $totalOuterLines = count($seenOuterLineIds);
                    if ($totalOuterLines !== ($deltas['posting_lines'] ?? 0)) {
                        $errors[] = "Canonical graph total posting lines across batches ({$totalOuterLines}) does not match deltas['posting_lines'] (".($deltas['posting_lines'] ?? 'null').").";
                    }
                }

                // 8. Document Sequences in canonical_graph
                $gDocSeqs = $graph['document_sequences'] ?? null;
                if (! is_array($gDocSeqs) || ! isset($gDocSeqs['before']) || ! isset($gDocSeqs['after']) || ! is_array($gDocSeqs['before']) || ! is_array($gDocSeqs['after'])) {
                    $errors[] = "Canonical graph document_sequences must contain 'before' and 'after' sequence mappings.";
                } else {
                    $beforeSeqs = $gDocSeqs['before'];
                    $afterSeqs = $gDocSeqs['after'];
                    $pinnedBaseSeqs = $baseMetrics['document_sequences'] ?? null;

                    if (empty($beforeSeqs) || empty($afterSeqs)) {
                        $errors[] = 'Canonical graph document_sequences before and after maps must be non-empty.';
                    } elseif ($beforeSeqs !== $pinnedBaseSeqs) {
                        $errors[] = "Canonical graph document_sequences['before'] must strictly match baseline_metrics['document_sequences'].";
                    } else {
                        $foundInvoiceSeqIncrement = false;
                        $foundPaymentSeqIncrement = false;

                        foreach ($beforeSeqs as $seqKey => $beforeNext) {
                            if (! array_key_exists($seqKey, $afterSeqs)) {
                                $errors[] = "Canonical graph document_sequences key '{$seqKey}' missing from after sequences.";
                                continue;
                            }
                            $afterNext = $afterSeqs[$seqKey];
                            if (str_starts_with((string) $seqKey, 'sales_invoice:')) {
                                if ($afterNext === $beforeNext + 1) {
                                    $foundInvoiceSeqIncrement = true;
                                } elseif ($afterNext !== $beforeNext) {
                                    $errors[] = "Canonical graph document sequence '{$seqKey}' invalid change: before {$beforeNext}, after {$afterNext}.";
                                }
                            } elseif (str_starts_with((string) $seqKey, 'customer_payment:')) {
                                if ($afterNext === $beforeNext + 1) {
                                    $foundPaymentSeqIncrement = true;
                                } elseif ($afterNext !== $beforeNext) {
                                    $errors[] = "Canonical graph document sequence '{$seqKey}' invalid change: before {$beforeNext}, after {$afterNext}.";
                                }
                            } else {
                                if ($afterNext !== $beforeNext) {
                                    $errors[] = "Unrelated document sequence '{$seqKey}' mutated: before {$beforeNext}, after {$afterNext}.";
                                }
                            }
                        }

                        foreach ($afterSeqs as $seqKey => $afterNext) {
                            if (! array_key_exists($seqKey, $beforeSeqs)) {
                                $errors[] = "Unexpected document sequence key '{$seqKey}' in after sequences.";
                            }
                        }

                        if (! $foundInvoiceSeqIncrement) {
                            $errors[] = 'Canonical graph document_sequences missing exact +1 increment for sales_invoice.';
                        }
                        if (! $foundPaymentSeqIncrement) {
                            $errors[] = 'Canonical graph document_sequences missing exact +1 increment for customer_payment.';
                        }
                    }
                }

                // 9. Mobile Event Receipt in canonical_graph
                $gReceipt = $graph['mobile_event_receipt'] ?? null;
                if (! is_array($gReceipt)) {
                    $errors[] = 'Canonical graph mobile_event_receipt entry is missing or not an array.';
                } else {
                    $rId = (int) ($gReceipt['id'] ?? 0);
                    if ($rId <= $receiptMaxId) {
                        $errors[] = "Canonical graph mobile_event_receipt.id must be newer than baseline max ({$receiptMaxId}), got {$rId}.";
                    }
                    if ($expectedCompanyId > 0 && (int) ($gReceipt['company_id'] ?? 0) !== $expectedCompanyId) {
                        $errors[] = "Canonical graph mobile_event_receipt.company_id mismatch: expected {$expectedCompanyId}, got ".($gReceipt['company_id'] ?? 'null').'.';
                    }
                    if ($expectedEventId !== '' && (string) ($gReceipt['client_event_id'] ?? '') !== $expectedEventId) {
                        $errors[] = "Canonical graph mobile_event_receipt.client_event_id mismatch: expected '{$expectedEventId}', got '".($gReceipt['client_event_id'] ?? 'null')."'.";
                    }
                    if (($gReceipt['status'] ?? '') !== 'completed') {
                        $errors[] = "Canonical graph mobile_event_receipt.status must be 'completed', got '".($gReceipt['status'] ?? 'null')."'.";
                    }
                    if ((int) ($gReceipt['sales_invoice_id'] ?? 0) !== $invId) {
                        $errors[] = "Canonical graph mobile_event_receipt.sales_invoice_id mismatch: expected {$invId}, got ".($gReceipt['sales_invoice_id'] ?? 'null').'.';
                    }
                    if ((string) ($gReceipt['sales_invoice_public_id'] ?? '') !== $invPubId) {
                        $errors[] = "Canonical graph mobile_event_receipt.sales_invoice_public_id mismatch: expected '{$invPubId}', got '".($gReceipt['sales_invoice_public_id'] ?? 'null')."'.";
                    }
                    if ((int) ($gReceipt['customer_payment_id'] ?? 0) !== $payId) {
                        $errors[] = "Canonical graph mobile_event_receipt.customer_payment_id mismatch: expected {$payId}, got ".($gReceipt['customer_payment_id'] ?? 'null').'.';
                    }
                    if ((string) ($gReceipt['customer_payment_public_id'] ?? '') !== $payPubId) {
                        $errors[] = "Canonical graph mobile_event_receipt.customer_payment_public_id mismatch: expected '{$payPubId}', got '".($gReceipt['customer_payment_public_id'] ?? 'null')."'.";
                    }
                    if ($normExpectedDigest !== null) {
                        $rDigest = (string) ($gReceipt['payload_digest'] ?? '');
                        $normReceiptDigest = str_starts_with($rDigest, 'sha256:') ? substr($rDigest, 7) : $rDigest;
                        if (! hash_equals($normExpectedDigest, $normReceiptDigest)) {
                            $errors[] = "Canonical graph mobile_event_receipt.payload_digest mismatch: expected '{$normExpectedDigest}', got '{$normReceiptDigest}'.";
                        }
                    }
                }
            }

            // Recompute deterministic core payload (including event identity, deltas, and graph) and verify self-digest
            $coreDeterministicPayload = [
                'scope' => (string) ($trustedDbReport['scope'] ?? ''),
                'run_id' => (string) ($trustedDbReport['run_id'] ?? ''),
                'event_id' => (string) ($trustedDbReport['event_id'] ?? ''),
                'schema_name' => (string) ($trustedDbReport['schema_name'] ?? ''),
                'company_id' => (int) ($trustedDbReport['company_id'] ?? 0),
                'source_head' => (string) ($trustedDbReport['source_head'] ?? ''),
                'handoff_digest' => (string) ($trustedDbReport['handoff_digest'] ?? ''),
                'baseline_hash' => (string) ($trustedDbReport['baseline_hash'] ?? ''),
                'deltas' => $trustedDbReport['deltas'] ?? [],
                'canonical_graph' => $trustedDbReport['canonical_graph'] ?? [],
                'is_canonical_delta_valid' => (bool) ($trustedDbReport['is_canonical_delta_valid'] ?? false),
                'gl_batches_balanced' => (bool) ($trustedDbReport['gl_batches_balanced'] ?? false),
                'all_healthy' => (bool) ($trustedDbReport['all_healthy'] ?? false),
                'reconciler_results' => $trustedDbReport['reconciler_results'] ?? [],
            ];
            $recomputedDigest = self::computeReportDigest($coreDeterministicPayload);
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

    /**
     * Validates outer evidence JSON against the authoritative advanced probe contract
     * (docs/integration/mobile-v2-advanced-probe-schema.json).
     *
     * Invariants enforced:
     * - Contract version must be 'v2_advanced_probe_evidence_v1'
     * - Scope must be 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION'
     * - Exactly 7 scenarios present:
     *   1. readiness_probe: PASS (200)
     *   2. financial_cash_fresh_commit_201: PASS (201)
     *   3. financial_cash_idempotent_replay_200: PASS (200)
     *   4. financial_cash_event_status_posted: PASS (200)
     *   5. financial_cash_tamper_409: PASS (409)
     *   6. transport_lost_ack_recovery: PASS (200)
     *   7. stock_permission_rollback_probes: PASS (in [403, 422, 500])
     * - lost_ack_recovery_evidence must verify single-response suppression at test-transport boundary
     *   and recovery via GET status returning HTTP 200 with completed receipt and matching payload digest.
     * - rollback_probes_evidence must verify zero economic delta and healthy reconciliations.
     * - Separate trusted DB report is mandatory and must match execution context.
     *
     * @param  array<string, mixed>|string  $evidenceJson
     * @param  array<string, mixed>  $expectedPins
     * @param  array<string, mixed>|null  $trustedDbReport
     * @return array{is_valid: bool, errors: array<string>}
     */
    public static function validateAdvancedProbeEvidenceJson(
        array|string $evidenceJson,
        array $expectedPins,
        ?array $trustedDbReport = null
    ): array {
        if ($expectedPins === null || $expectedPins === []) {
            return [
                'is_valid' => false,
                'errors' => ['expectedPins parameter is mandatory and cannot be empty.'],
            ];
        }

        $data = is_string($evidenceJson) ? json_decode($evidenceJson, true) : $evidenceJson;
        if (! is_array($data)) {
            return [
                'is_valid' => false,
                'errors' => ['Advanced probe evidence must be a valid JSON object.'],
            ];
        }

        $errors = [];

        // 1. Contract version & scope
        if (($data['contract_version'] ?? '') !== 'v2_advanced_probe_evidence_v1') {
            $errors[] = "Advanced probe evidence contract_version must be 'v2_advanced_probe_evidence_v1', got '".($data['contract_version'] ?? 'null')."'.";
        }
        if (($data['scope'] ?? '') !== 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION') {
            $errors[] = "Advanced probe evidence scope must be 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION', got '".($data['scope'] ?? 'null')."'.";
        }

        // 2. Strict Pin Verification (Mandatory)
        $requiredPinKeys = [
            'expectedRunId' => 'run_id',
            'expectedClientEventId' => 'client_event_id',
            'expectedSchemaName' => 'schema_name',
            'expectedHandoffSha' => 'handoff_sha256',
            'backendExecutionHead' => 'backend_head',
            'clientPublicationHead' => 'android_publication_head',
            'clientExecutableHead' => 'android_executable_head',
        ];
        foreach ($requiredPinKeys as $pinKey => $dataKey) {
            $pinVal = (string) ($expectedPins[$pinKey] ?? '');
            if ($pinVal === '') {
                $errors[] = "Missing required pin check: '{$pinKey}'.";
                continue;
            }
            $dataVal = (string) ($data[$dataKey] ?? '');
            if ($dataVal === '' || ! hash_equals($pinVal, $dataVal)) {
                $errors[] = "Evidence '{$dataKey}' mismatch: expected '{$pinVal}', got '{$dataVal}'.";
            }
        }

        // Source pins verification
        $expSourcePins = $expectedPins['expectedSourcePins'] ?? null;
        if (! is_array($expSourcePins)) {
            $errors[] = "Missing required pin check: 'expectedSourcePins'.";
        } else {
            $dataSourcePins = $data['source_pins'] ?? null;
            if (! is_array($dataSourcePins)) {
                $errors[] = "Evidence source_pins block is missing or not an array.";
            } else {
                foreach (['source_fixture_sha256', 'guard_test_sha256', 'cli_command_sha256', 'cli_runner_sha256'] as $pinKey) {
                    $expPin = (string) ($expSourcePins[$pinKey] ?? '');
                    $actualPin = (string) ($dataSourcePins[$pinKey] ?? '');
                    if ($expPin === '' || ! hash_equals($expPin, $actualPin)) {
                        $errors[] = "Evidence source_pins '{$pinKey}' mismatch: expected '{$expPin}', got '{$actualPin}'.";
                    }
                }
            }
        }

        // 3. Scenarios verification
        $scenarios = $data['scenarios'] ?? null;
        if (! is_array($scenarios) || count($scenarios) !== 7) {
            $errors[] = 'Advanced probe evidence scenarios must be an array of exactly 7 items.';
        } else {
            $scenariosMap = [];
            foreach ($scenarios as $sc) {
                if (is_array($sc) && isset($sc['name']) && is_string($sc['name'])) {
                    $scenariosMap[$sc['name']] = $sc;
                }
            }

            $expectedScenarios = [
                'readiness_probe' => 200,
                'financial_cash_fresh_commit_201' => 201,
                'financial_cash_idempotent_replay_200' => 200,
                'financial_cash_event_status_posted' => 200,
                'financial_cash_tamper_409' => 409,
                'transport_lost_ack_recovery' => 200,
            ];

            foreach ($expectedScenarios as $name => $expCode) {
                if (! isset($scenariosMap[$name])) {
                    $errors[] = "Scenario '{$name}' is missing from evidence.";
                    continue;
                }
                $sc = $scenariosMap[$name];
                if (($sc['status'] ?? '') !== 'PASS') {
                    $errors[] = "Scenario '{$name}' must have status 'PASS', got '".($sc['status'] ?? 'null')."'.";
                }
                if (($sc['httpStatusCode'] ?? null) !== $expCode) {
                    $errors[] = "Scenario '{$name}' httpStatusCode mismatch: expected {$expCode}, got ".($sc['httpStatusCode'] ?? 'null').'.';
                }
            }

            // Probe 7: stock_permission_rollback_probes
            if (! isset($scenariosMap['stock_permission_rollback_probes'])) {
                $errors[] = "Scenario 'stock_permission_rollback_probes' is missing from evidence.";
            } else {
                $sc = $scenariosMap['stock_permission_rollback_probes'];
                if (($sc['status'] ?? '') !== 'PASS') {
                    $errors[] = "Scenario 'stock_permission_rollback_probes' must have status 'PASS', got '".($sc['status'] ?? 'null')."'.";
                }
                $scCode = $sc['httpStatusCode'] ?? null;
                if (! in_array($scCode, [403, 422, 500], true)) {
                    $errors[] = "Scenario 'stock_permission_rollback_probes' httpStatusCode must be 403, 422, or 500, got ".($scCode ?? 'null').'.';
                }
            }
        }

        // 4. Lost ACK recovery evidence
        $lostAck = $data['lost_ack_recovery_evidence'] ?? null;
        if (! is_array($lostAck)) {
            $errors[] = 'lost_ack_recovery_evidence block is missing or not an array.';
        } else {
            if (($lostAck['suppression_mode'] ?? '') !== 'TEST_TRANSPORT_BOUNDARY_SINGLE_RESPONSE_DROP') {
                $errors[] = "lost_ack_recovery_evidence suppression_mode must be 'TEST_TRANSPORT_BOUNDARY_SINGLE_RESPONSE_DROP'.";
            }

            $lostAckScenario = $scenariosMap['transport_lost_ack_recovery'] ?? null;
            $lostAckPass = is_array($lostAckScenario) && ($lostAckScenario['status'] ?? '') === 'PASS';
            $actualWitnessState = (string) ($lostAck['network_witness_state'] ?? '');

            if ($lostAckPass) {
                // In acceptance validation, if scenario is PASS, validator must REFUSE 'UNRUN_AWAITING_REAL_NETWORK_WITNESS'
                if ($actualWitnessState === 'UNRUN_AWAITING_REAL_NETWORK_WITNESS') {
                    $errors[] = "When scenario 'transport_lost_ack_recovery' is PASS, network_witness_state cannot be 'UNRUN_AWAITING_REAL_NETWORK_WITNESS' and must be 'CAPTURED_REAL_TRANSPORT_DROP'.";
                } elseif ($actualWitnessState !== 'CAPTURED_REAL_TRANSPORT_DROP') {
                    $errors[] = "lost_ack_recovery_evidence network_witness_state must be 'CAPTURED_REAL_TRANSPORT_DROP', got '{$actualWitnessState}'.";
                }

                // If witness file is specified, validate the witness file directly
                $witnessPath = (string) ($lostAck['suppression_witness_file'] ?? $lostAck['witness_file'] ?? '');
                if ($witnessPath !== '') {
                    if (is_link($witnessPath)) {
                        $errors[] = "Suppression witness file cannot be a symlink: {$witnessPath}.";
                    } elseif (! is_file($witnessPath)) {
                        $errors[] = "Suppression witness file does not exist: {$witnessPath}.";
                    } else {
                        $parentDir = dirname($witnessPath);
                        if (is_link($parentDir)) {
                            $errors[] = "Suppression witness parent directory cannot be a symlink: {$parentDir}.";
                        } elseif (! is_dir($parentDir)) {
                            $errors[] = "Suppression witness parent directory does not exist: {$parentDir}.";
                        } else {
                            $parentSt = @stat($parentDir);
                            $currentUid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();
                            if ($parentSt !== false) {
                                if ($parentSt['uid'] !== $currentUid) {
                                    $errors[] = "Suppression witness parent directory owner UID mismatch: expected {$currentUid}, got {$parentSt['uid']}.";
                                }
                                if (($parentSt['mode'] & 0777) !== 0700) {
                                    $errors[] = sprintf("Suppression witness parent directory permissions insecure: expected 0700, got %04o.", $parentSt['mode'] & 0777);
                                }
                            }
                        }

                        $st = @stat($witnessPath);
                        if ($st !== false) {
                            $currentUid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();
                            if ($st['uid'] !== $currentUid) {
                                $errors[] = "Suppression witness file owner UID mismatch: expected {$currentUid}, got {$st['uid']}.";
                            }
                            if (($st['mode'] & 0777) !== 0600) {
                                $errors[] = sprintf("Suppression witness file permissions insecure: expected 0600, got %04o.", $st['mode'] & 0777);
                            }
                        }
                        $rawWitness = (string) file_get_contents($witnessPath);
                        $witnessData = json_decode($rawWitness, true);
                        if (! is_array($witnessData)) {
                            $errors[] = "Suppression witness file contains invalid JSON.";
                        } else {
                            if (($witnessData['contract_version'] ?? '') !== 'lost_ack_witness_v1') {
                                $errors[] = "Witness file contract_version must be 'lost_ack_witness_v1'.";
                            }
                            if (($witnessData['suppression_mode'] ?? '') !== 'TEST_TRANSPORT_BOUNDARY_SINGLE_RESPONSE_DROP') {
                                $errors[] = "Witness file suppression_mode must be 'TEST_TRANSPORT_BOUNDARY_SINGLE_RESPONSE_DROP'.";
                            }
                            if (($witnessData['network_witness_state'] ?? '') !== 'CAPTURED_REAL_TRANSPORT_DROP') {
                                $errors[] = "Witness file network_witness_state must be 'CAPTURED_REAL_TRANSPORT_DROP'.";
                            }
                            $wEventId = (string) ($witnessData['event_id'] ?? $witnessData['client_event_id'] ?? '');
                            if ($wEventId !== (string) ($expectedPins['expectedClientEventId'] ?? '')) {
                                $errors[] = "Witness file event_id mismatch: expected '".($expectedPins['expectedClientEventId'] ?? '')."', got '{$wEventId}'.";
                            }
                            if (($witnessData['schema_name'] ?? '') !== (string) ($expectedPins['expectedSchemaName'] ?? '')) {
                                $errors[] = "Witness file schema_name mismatch: expected '".($expectedPins['expectedSchemaName'] ?? '')."', got '".($witnessData['schema_name'] ?? '')."'.";
                            }
                            if (! is_bool($witnessData['db_commit_verified'] ?? null) || $witnessData['db_commit_verified'] !== true) {
                                $errors[] = "Witness file db_commit_verified must be boolean true.";
                            }
                            $proxyDropped = ($witnessData['proxy_socket_closed_without_response'] ?? null) === true
                                || ($witnessData['client_socket_dropped'] ?? null) === true;
                            if (! $proxyDropped) {
                                $errors[] = "Witness file proxy_socket_closed_without_response must be boolean true.";
                            }
                            if (($witnessData['bytes_forwarded'] ?? -1) !== 0) {
                                $errors[] = "Witness file bytes_forwarded must be integer 0, got ".($witnessData['bytes_forwarded'] ?? 'null').".";
                            }
                            if (empty($witnessData['target_nonce_hash']) || ! preg_match('/^[0-9a-f]{64}$/', (string) $witnessData['target_nonce_hash'])) {
                                $errors[] = "Witness file target_nonce_hash must be a 64-hex lowercase SHA-256 string.";
                            }
                            if ((int) ($witnessData['sales_invoice_id'] ?? 0) <= 0) {
                                $errors[] = "Witness file sales_invoice_id must be positive integer.";
                            }
                            if ((int) ($witnessData['customer_payment_id'] ?? 0) <= 0) {
                                $errors[] = "Witness file customer_payment_id must be positive integer.";
                            }
                            if (($witnessData['response_status_code_before_suppression'] ?? 0) !== 201) {
                                $errors[] = "Witness file response_status_code_before_suppression must be 201.";
                            }
                        }
                    }
                }
            } else {
                if ($actualWitnessState !== 'UNRUN_AWAITING_REAL_NETWORK_WITNESS' && $actualWitnessState !== 'CAPTURED_REAL_TRANSPORT_DROP') {
                    $errors[] = "lost_ack_recovery_evidence network_witness_state must be 'UNRUN_AWAITING_REAL_NETWORK_WITNESS' or 'CAPTURED_REAL_TRANSPORT_DROP', got '{$actualWitnessState}'.";
                }
            }

            // Strict typed boolean check
            $commitVerified = $lostAck['server_commit_verified_before_suppression'] ?? null;
            if (! is_bool($commitVerified) || $commitVerified !== true) {
                $errors[] = 'lost_ack_recovery_evidence server_commit_verified_before_suppression must be boolean true.';
            }
            if (($lostAck['recovery_method'] ?? '') !== 'RETAINED_EVENT_GET_STATUS') {
                $errors[] = "lost_ack_recovery_evidence recovery_method must be 'RETAINED_EVENT_GET_STATUS'.";
            }
            if (($lostAck['recovery_http_status'] ?? 0) !== 200) {
                $errors[] = "lost_ack_recovery_evidence recovery_http_status must be 200, got ".($lostAck['recovery_http_status'] ?? 'null').'.';
            }
            if (($lostAck['receipt_status'] ?? '') !== 'posted') {
                $errors[] = "lost_ack_recovery_evidence receipt_status must be 'posted', got '".($lostAck['receipt_status'] ?? 'null')."'.";
            }
            $expDigest = (string) ($expectedPins['expectedPayloadDigest'] ?? '');
            if ($expDigest === '' || ! hash_equals($expDigest, (string) ($lostAck['matching_payload_digest'] ?? ''))) {
                $errors[] = "lost_ack_recovery_evidence matching_payload_digest mismatch: expected '{$expDigest}', got '".($lostAck['matching_payload_digest'] ?? 'null')."'.";
            }
        }

        // 5. Rollback probes evidence (Named required probe set)
        $rollback = $data['rollback_probes_evidence'] ?? null;
        if (! is_array($rollback)) {
            $errors[] = 'rollback_probes_evidence block is missing or not an array.';
        } else {
            $probes = $rollback['probes'] ?? null;
            if (! is_array($probes) || count($probes) < 3) {
                $errors[] = 'rollback_probes_evidence probes must contain at least 3 probe results.';
            } else {
                $requiredProbeNames = [
                    'permission_denial' => 403,
                    'insufficient_stock' => 422,
                    'controlled_fault_after_posting' => 500,
                ];
                $allowedProbeKeys = [
                    'probe_name',
                    'client_event_id',
                    'actual_http_status',
                    'expected_http_status',
                    'economic_delta_zero',
                    'reconciliations_healthy',
                    'issue_count',
                    'reconciliations',
                    'baseline_metrics',
                    'post_metrics',
                    'schema_name',
                    'run_id',
                    'company_id',
                ];
                $observedProbes = [];
                $observedEventIds = [];

                foreach ($probes as $idx => $p) {
                    if (! is_array($p)) {
                        $errors[] = "Rollback probe #{$idx} must be an object.";
                        continue;
                    }
                    $pName = (string) ($p['probe_name'] ?? '');
                    if ($pName === '') {
                        $errors[] = "Rollback probe #{$idx} missing probe_name.";
                        continue;
                    }

                    if (isset($observedProbes[$pName])) {
                        $errors[] = "Duplicate rollback probe '{$pName}'.";
                        continue;
                    }
                    $observedProbes[$pName] = true;

                    // Reject unknown fields in probe object
                    foreach (array_keys($p) as $fieldKey) {
                        if (! in_array($fieldKey, $allowedProbeKeys, true)) {
                            $errors[] = "Rollback probe '{$pName}' contains unknown field '{$fieldKey}'.";
                        }
                    }

                    if (! isset($requiredProbeNames[$pName])) {
                        $errors[] = "Unknown rollback probe '{$pName}'.";
                        continue;
                    }

                    $expStatus = $requiredProbeNames[$pName];
                    if (($p['actual_http_status'] ?? 0) !== $expStatus) {
                        $errors[] = "Rollback probe '{$pName}' actual_http_status mismatch: expected {$expStatus}, got ".($p['actual_http_status'] ?? 'null').'.';
                    }

                    // Per-probe report crossbindings (when expectedPins present)
                    if (isset($p['schema_name']) && ! hash_equals((string) ($expectedPins['expectedSchemaName'] ?? ''), (string) $p['schema_name'])) {
                        $errors[] = "Rollback probe '{$pName}' schema_name mismatch: expected '".($expectedPins['expectedSchemaName'] ?? '')."', got '".((string) $p['schema_name'])."'.";
                    }
                    if (isset($p['run_id']) && ! hash_equals((string) ($expectedPins['expectedRunId'] ?? ''), (string) $p['run_id'])) {
                        $errors[] = "Rollback probe '{$pName}' run_id mismatch: expected '".($expectedPins['expectedRunId'] ?? '')."', got '".((string) $p['run_id'])."'.";
                    }
                    if (isset($p['company_id']) && (int) ($expectedPins['companyId'] ?? 0) !== (int) $p['company_id']) {
                        $errors[] = "Rollback probe '{$pName}' company_id mismatch: expected ".($expectedPins['companyId'] ?? 0).", got ".(int) $p['company_id'].".";
                    }

                    // Unique distinct client_event_id per probe
                    $pEventId = (string) ($p['client_event_id'] ?? '');
                    if ($pEventId !== '') {
                        if (! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $pEventId)) {
                            $errors[] = "Rollback probe '{$pName}' client_event_id must be valid UUID, got '{$pEventId}'.";
                        } elseif ($pEventId === (string) ($expectedPins['expectedClientEventId'] ?? '')) {
                            $errors[] = "Rollback probe '{$pName}' client_event_id cannot match the primary execution client_event_id.";
                        } elseif (isset($observedEventIds[$pEventId])) {
                            $errors[] = "Rollback probe '{$pName}' reused client_event_id '{$pEventId}' from probe '{$observedEventIds[$pEventId]}'.";
                        } else {
                            $observedEventIds[$pEventId] = $pName;
                        }
                    }

                    // Strict typed boolean checks (reject truthy strings)
                    $deltaZero = $p['economic_delta_zero'] ?? null;
                    if (! is_bool($deltaZero) || $deltaZero !== true) {
                        $errors[] = "Rollback probe '{$pName}' economic_delta_zero must be boolean true.";
                    }
                    $reconHealthy = $p['reconciliations_healthy'] ?? null;
                    if (! is_bool($reconHealthy) || $reconHealthy !== true) {
                        $errors[] = "Rollback probe '{$pName}' reconciliations_healthy must be boolean true.";
                    }
                    if (isset($p['issue_count']) && ($p['issue_count'] ?? -1) !== 0) {
                        $errors[] = "Rollback probe '{$pName}' issue_count must be 0, got ".($p['issue_count'] ?? 'null').'.';
                    }

                    // Check exact reconciliations dictionary
                    $recons = $p['reconciliations'] ?? null;
                    if (! is_array($recons)) {
                        $errors[] = "Rollback probe '{$pName}' reconciliations dictionary is missing or not an array.";
                    } else {
                        foreach ($recons as $rcKey => $rVal) {
                            if (! is_bool($rVal) || $rVal !== true) {
                                $errors[] = "Rollback probe '{$pName}' reconciliation '{$rcKey}' must be boolean true.";
                            }
                        }
                    }

                    // Assert exact zero delta on full typed metrics dictionaries
                    $baseMetrics = $p['baseline_metrics'] ?? null;
                    $postMetrics = $p['post_metrics'] ?? null;
                    if ($baseMetrics !== null || $postMetrics !== null) {
                        if (! is_array($baseMetrics) || ! is_array($postMetrics)) {
                            $errors[] = "Rollback probe '{$pName}' baseline_metrics and post_metrics are required dictionaries.";
                        } else {
                            $deltaRes = self::assertRollbackZeroDelta($baseMetrics, $postMetrics);
                            if (! $deltaRes['is_zero_delta']) {
                                foreach ($deltaRes['errors'] as $de) {
                                    $errors[] = "Rollback probe '{$pName}': {$de}";
                                }
                            }
                        }
                    }
                }

                foreach ($requiredProbeNames as $reqName => $status) {
                    if (! isset($observedProbes[$reqName])) {
                        $errors[] = "Mandatory rollback probe '{$reqName}' is missing from evidence.";
                    }
                }
            }
        }

        // 6. Mandatory trusted DB inspection report
        if ($trustedDbReport === null) {
            $errors[] = 'Separate REQUIRED trusted owned DB inspection report is mandatory; cannot be omitted.';
        } else {
            if (($trustedDbReport['scope'] ?? '') !== 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION') {
                $errors[] = "Trusted DB report scope mismatch: expected 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION', got '".($trustedDbReport['scope'] ?? 'null')."'.";
            }

            $coreDeterministicPayload = [
                'scope' => (string) ($trustedDbReport['scope'] ?? ''),
                'run_id' => (string) ($trustedDbReport['run_id'] ?? ''),
                'event_id' => (string) ($trustedDbReport['event_id'] ?? ''),
                'schema_name' => (string) ($trustedDbReport['schema_name'] ?? ''),
                'company_id' => (int) ($trustedDbReport['company_id'] ?? 0),
                'source_head' => (string) ($trustedDbReport['source_head'] ?? ''),
                'handoff_digest' => (string) ($trustedDbReport['handoff_digest'] ?? ''),
                'baseline_hash' => (string) ($trustedDbReport['baseline_hash'] ?? ''),
                'deltas' => $trustedDbReport['deltas'] ?? [],
                'canonical_graph' => $trustedDbReport['canonical_graph'] ?? [],
                'is_canonical_delta_valid' => (bool) ($trustedDbReport['is_canonical_delta_valid'] ?? false),
                'gl_batches_balanced' => (bool) ($trustedDbReport['gl_batches_balanced'] ?? false),
                'all_healthy' => (bool) ($trustedDbReport['all_healthy'] ?? false),
                'reconciler_results' => $trustedDbReport['reconciler_results'] ?? [],
            ];
            $computedReportDigest = self::computeReportDigest($coreDeterministicPayload);
            $actualReportDigest = (string) ($trustedDbReport['report_digest'] ?? $trustedDbReport['digest'] ?? '');
            if ($actualReportDigest === '' || ! hash_equals($computedReportDigest, $actualReportDigest)) {
                $errors[] = "Trusted DB report report_digest mismatch against canonical serialization: expected '{$computedReportDigest}', got '{$actualReportDigest}'.";
            }
            $expReportDigest = (string) ($expectedPins['expectedReportDigest'] ?? $expectedPins['expectedTrustedReportDigest'] ?? '');
            if ($expReportDigest !== '' && ! hash_equals($expReportDigest, $actualReportDigest)) {
                $errors[] = "Expected report_digest mismatch: expected '{$expReportDigest}', got '{$actualReportDigest}'.";
            }

            // Cross-check immutable file SHA pin (expectedDbReportSha) if passed
            if (! empty($expectedPins['expectedDbReportSha']) && ! empty($expectedPins['actualDbReportFileSha'])) {
                if (! hash_equals((string) $expectedPins['expectedDbReportSha'], (string) $expectedPins['actualDbReportFileSha'])) {
                    $errors[] = "Trusted DB report file SHA mismatch: expected '{$expectedPins['expectedDbReportSha']}', got '{$expectedPins['actualDbReportFileSha']}'.";
                }
            }

            // Cross-bind backend_execution_head_sha, schema_name, company_id, run_id, client_event_id, candidate_handoff_digest
            if (isset($expectedPins['backendExecutionHead']) && ! hash_equals((string) $expectedPins['backendExecutionHead'], (string) ($trustedDbReport['backend_execution_head_sha'] ?? ''))) {
                $errors[] = "Trusted DB report backend_execution_head_sha mismatch: expected '{$expectedPins['backendExecutionHead']}', got '".($trustedDbReport['backend_execution_head_sha'] ?? '')."'.";
            }
            if (isset($expectedPins['expectedSchemaName']) && ! hash_equals((string) $expectedPins['expectedSchemaName'], (string) ($trustedDbReport['schema_name'] ?? ''))) {
                $errors[] = "Trusted DB report schema_name mismatch: expected '{$expectedPins['expectedSchemaName']}', got '".($trustedDbReport['schema_name'] ?? '')."'.";
            }
            $expectedCompanyId = (int) ($expectedPins['expectedCompanyId'] ?? $expectedPins['companyId'] ?? 0);
            if ($expectedCompanyId > 0 && (int) ($trustedDbReport['company_id'] ?? -1) !== $expectedCompanyId) {
                $errors[] = "Trusted DB report company_id mismatch: expected {$expectedCompanyId}, got ".($trustedDbReport['company_id'] ?? -1).".";
            }
            if (isset($expectedPins['expectedRunId']) && ! hash_equals((string) $expectedPins['expectedRunId'], (string) ($trustedDbReport['run_id'] ?? ''))) {
                $errors[] = "Trusted DB report run_id mismatch: expected '{$expectedPins['expectedRunId']}', got '".($trustedDbReport['run_id'] ?? '')."'.";
            }
            if (isset($expectedPins['expectedClientEventId']) && ! hash_equals((string) $expectedPins['expectedClientEventId'], (string) ($trustedDbReport['client_event_id'] ?? ''))) {
                $errors[] = "Trusted DB report client_event_id mismatch: expected '{$expectedPins['expectedClientEventId']}', got '".($trustedDbReport['client_event_id'] ?? '')."'.";
            }
            if (isset($expectedPins['expectedHandoffSha']) && ! hash_equals((string) $expectedPins['expectedHandoffSha'], (string) ($trustedDbReport['candidate_handoff_digest'] ?? ''))) {
                $errors[] = "Trusted DB report candidate_handoff_digest mismatch: expected '{$expectedPins['expectedHandoffSha']}', got '".($trustedDbReport['candidate_handoff_digest'] ?? '')."'.";
            }

            if (! is_bool($trustedDbReport['is_canonical_delta_valid'] ?? null) || $trustedDbReport['is_canonical_delta_valid'] !== true) {
                $errors[] = 'Trusted DB report is_canonical_delta_valid must be boolean true.';
            }
            if (! is_bool($trustedDbReport['gl_batches_balanced'] ?? null) || $trustedDbReport['gl_batches_balanced'] !== true) {
                $errors[] = 'Trusted DB report gl_batches_balanced must be boolean true.';
            }
            if (! is_bool($trustedDbReport['all_healthy'] ?? null) || $trustedDbReport['all_healthy'] !== true) {
                $errors[] = 'Trusted DB report all_healthy must be boolean true.';
            }
        }

        return [
            'is_valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * Asserts that a rollback probe resulted in exactly zero net delta across all entity counts,
     * sequences, stock quantities, and row fingerprints. Fails closed on any missing, non-integer,
     * or mismatched metrics.
     *
     * @param  array<string, mixed>  $baseline  Pre-probe metrics
     * @param  array<string, mixed>  $postProbe  Post-probe metrics
     * @return array{is_zero_delta: bool, deltas: array<string, int>, errors: array<string>}
     */
    public static function assertRollbackZeroDelta(array $baseline, array $postProbe): array
    {
        $allowedMetricKeys = [
            'sales_invoices',
            'sales_invoice_lines',
            'sales_invoice_lot_allocations',
            'customer_payments',
            'customer_payment_allocations',
            'mobile_event_receipts',
            'stock_movements',
            'posting_batches',
            'posting_lines',
            'document_sequence_next_number',
            'inventory_balance_quantity_base',
            'row_fingerprint_sha256',
        ];
        $requiredCountKeys = [
            'sales_invoices',
            'sales_invoice_lines',
            'sales_invoice_lot_allocations',
            'customer_payments',
            'customer_payment_allocations',
            'mobile_event_receipts',
            'stock_movements',
            'posting_batches',
            'posting_lines',
            'document_sequence_next_number',
        ];

        $deltas = [];
        $errors = [];

        // Fail-closed on empty or incomplete dictionaries
        if ($baseline === [] || $postProbe === []) {
            return [
                'is_zero_delta' => false,
                'deltas' => [],
                'errors' => ['Baseline or postProbe metrics array cannot be empty.'],
            ];
        }

        // Reject unknown metric keys
        foreach (array_keys($baseline) as $k) {
            if (! in_array($k, $allowedMetricKeys, true)) {
                $errors[] = "Baseline metrics contains unknown key '{$k}'.";
            }
        }
        foreach (array_keys($postProbe) as $k) {
            if (! in_array($k, $allowedMetricKeys, true)) {
                $errors[] = "Post-probe metrics contains unknown key '{$k}'.";
            }
        }

        foreach ($requiredCountKeys as $k) {
            if (! array_key_exists($k, $baseline)) {
                $errors[] = "Baseline metrics missing required key '{$k}'.";
                continue;
            }
            if (! array_key_exists($k, $postProbe)) {
                $errors[] = "Post-probe metrics missing required key '{$k}'.";
                continue;
            }

            if (! is_int($baseline[$k])) {
                $errors[] = "Baseline metric '{$k}' must be integer, got " . gettype($baseline[$k]) . '.';
                continue;
            }
            if ($baseline[$k] < 0) {
                $errors[] = "Baseline metric '{$k}' must be non-negative integer, got {$baseline[$k]}.";
                continue;
            }
            if (! is_int($postProbe[$k])) {
                $errors[] = "Post-probe metric '{$k}' must be integer, got " . gettype($postProbe[$k]) . '.';
                continue;
            }
            if ($postProbe[$k] < 0) {
                $errors[] = "Post-probe metric '{$k}' must be non-negative integer, got {$postProbe[$k]}.";
                continue;
            }

            $delta = $postProbe[$k] - $baseline[$k];
            $deltas[$k] = $delta;
            if ($delta !== 0) {
                $errors[] = "Rollback entity '{$k}' delta must be 0, got {$delta} (baseline: {$baseline[$k]}, post: {$postProbe[$k]}).";
            }
        }

        // Sequence preservation check
        if (isset($baseline['document_sequence_next_number'], $postProbe['document_sequence_next_number'])) {
            if ($baseline['document_sequence_next_number'] !== $postProbe['document_sequence_next_number']) {
                $errors[] = "Document sequence leaked: baseline {$baseline['document_sequence_next_number']} != post {$postProbe['document_sequence_next_number']}.";
            }
        }

        // Stock quantity preservation check
        if (! isset($baseline['inventory_balance_quantity_base']) || ! isset($postProbe['inventory_balance_quantity_base'])) {
            $errors[] = "Missing 'inventory_balance_quantity_base' metric in baseline or postProbe.";
        } else {
            $baseQty = (string) $baseline['inventory_balance_quantity_base'];
            $postQty = (string) $postProbe['inventory_balance_quantity_base'];
            if ($baseQty === '' || ! is_numeric($baseQty) || (float) $baseQty < 0.0) {
                $errors[] = "Baseline inventory_balance_quantity_base must be non-negative numeric string, got '{$baseQty}'.";
            }
            if ($postQty === '' || ! is_numeric($postQty) || (float) $postQty < 0.0) {
                $errors[] = "Post-probe inventory_balance_quantity_base must be non-negative numeric string, got '{$postQty}'.";
            }
            if ($baseQty !== $postQty) {
                $errors[] = "Inventory stock quantity mutated: baseline '{$baseQty}' != post '{$postQty}'.";
            }
        }

        // Full row fingerprint hash preservation check (64-hex lowercase)
        if (! isset($baseline['row_fingerprint_sha256']) || ! isset($postProbe['row_fingerprint_sha256'])) {
            $errors[] = "Missing 'row_fingerprint_sha256' in baseline or postProbe.";
        } else {
            $baseFp = (string) $baseline['row_fingerprint_sha256'];
            $postFp = (string) $postProbe['row_fingerprint_sha256'];
            if (! preg_match('/^[0-9a-f]{64}$/', $baseFp)) {
                $errors[] = "Baseline row_fingerprint_sha256 must be 64-hex lowercase SHA-256, got '{$baseFp}'.";
            }
            if (! preg_match('/^[0-9a-f]{64}$/', $postFp)) {
                $errors[] = "Post-probe row_fingerprint_sha256 must be 64-hex lowercase SHA-256, got '{$postFp}'.";
            }
            if ($baseFp !== '' && ! hash_equals($baseFp, $postFp)) {
                $errors[] = "Row fingerprint mismatch: baseline '{$baseFp}' != post '{$postFp}'.";
            }
        }

        return [
            'is_zero_delta' => $errors === [],
            'deltas' => $deltas,
            'errors' => $errors,
        ];
    }

    /**
     * Executes real PDO queries to capture pre/post probe snapshot for rollback zero-delta validation.
     * Captures entity row counts, sequence state, stock balance, and full table row SHA-256 fingerprints.
     *
     * @param  PDO  $pdo  Read-only PDO connection to verified disposable database
     * @param  int  $companyId  Company internal ID
     * @return array<string, mixed>
     */
    public static function captureRollbackProbeSnapshot(PDO $pdo, int $companyId): array
    {
        if ($companyId <= 0) {
            throw new InvalidArgumentException("captureRollbackProbeSnapshot requires positive companyId, got {$companyId}.");
        }

        $tables = [
            'sales_invoices',
            'sales_invoice_lines',
            'sales_invoice_lot_allocations',
            'customer_payments',
            'customer_payment_allocations',
            'mobile_event_receipts',
            'stock_movements',
            'posting_batches',
            'posting_lines',
        ];

        $counts = [];
        $fingerprintParts = [];

        foreach ($tables as $table) {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(MAX(id), 0) AS max_id FROM `{$table}` WHERE company_id = ?");
            $stmt->execute([$companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $counts[$table] = (int) ($row['c'] ?? 0);
            $fingerprintParts[] = "{$table}:count=".($row['c'] ?? 0).':max_id='.($row['max_id'] ?? 0);
        }

        // Sequence check: query max sequence / next number if document_sequences table exists
        $seqNext = 0;
        try {
            $seqStmt = $pdo->prepare('SELECT COALESCE(MAX(current_number), 0) AS cur FROM document_sequences WHERE company_id = ?');
            $seqStmt->execute([$companyId]);
            $seqRow = $seqStmt->fetch(PDO::FETCH_ASSOC);
            $seqNext = (int) ($seqRow['cur'] ?? 0);
        } catch (\Throwable) {
            $seqNext = 0;
        }
        $counts['document_sequence_next_number'] = $seqNext;
        $fingerprintParts[] = "sequence:next={$seqNext}";

        // Inventory balance check
        $stockQty = 0;
        try {
            $stockStmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) AS total_qty FROM inventory_transactions WHERE company_id = ?');
            $stockStmt->execute([$companyId]);
            $stockRow = $stockStmt->fetch(PDO::FETCH_ASSOC);
            $stockQty = (int) ($stockRow['total_qty'] ?? 0);
        } catch (\Throwable) {
            $stockQty = 0;
        }
        $counts['inventory_balance_quantity_base'] = $stockQty;
        $fingerprintParts[] = "stock:qty={$stockQty}";

        $counts['row_fingerprint_sha256'] = hash('sha256', implode('|', $fingerprintParts));

        return $counts;
    }
}
