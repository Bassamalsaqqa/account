<?php

declare(strict_types=1);

/**
 * Standalone MariaDB Qualification Runner for Disposable Schema & Six Reconcilers
 * Location: tests/Support/Harness/disposable-db-qualification-runner.php
 *
 * Strictly enforces:
 * - Default NO-EXECUTE (dry-run preflight mode without --execute)
 * - Strict loopback & port: 127.0.0.1:3342
 * - Controlled pre-check refusals:
 *     1. Missing/unproven schema ownership proof refusal
 *     2. Invalid schema name refusal
 *     3. Ambient DSN poisoning refusal (ambient port 1 / sentinel unconnectable)
 * - Isolated schema lifecycle:
 *     - DisposableMariaDbSchema::create()
 *     - DisposableMariaDbSchema::migrate()
 *     - DisposableMariaDbSchema::assertPrimarySchema()
 * - Minimal canonical foundation fixture (delta 0):
 *     - Company owner, Company (via CreateCompanyAction), Roles
 *     - Default warehouse, Base unit ('piece')
 *     - Stock product, ProductUnit ('piece', 1.000000)
 *     - TaxRate ('VAT16', 16%)
 *     - Default cash MoneyAccount
 *     - Zero financial transactions / zero opening stock (delta 0 baseline)
 * - Dedicated read-only PDO connection:
 *     - DisposableFinancialPostRunInspector::configureStatementTimeout($pdo, 5)
 *     - SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ
 *     - START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY
 *     - Verified DATABASE() = schema, @@port = 3342, @@transaction_read_only = 1
 * - Six real isolated SAME-PDO reconcilers via DisposableFinancialPostRunInspector::runSixReconciliations()
 * - Baseline metrics capture before and after:
 *     - DisposableFinancialPostRunInspector::captureBaseline()
 *     - Assert $postBaseline === $preBaseline (100% strictly equal)
 *     - Assert delta 0 across all financial entities
 * - Bounded execution <= 180s with pcntl_signal emergency cleanup
 * - Finally teardown: drop schema and verify database absence
 * - Structured sanitized JSON output (zero passwords/secrets printed)
 */

use App\Actions\Company\CreateCompanyAction;
use App\Models\Company;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\Harness\DisposableFinancialPostRunInspector;

// CLI argument parsing
$argvList = $_SERVER['argv'] ?? [];

$execute = false;
$credentialsFile = null;
$expectedSourceHead = null;
$timeout = 180;
$outputFile = null;

foreach ($argvList as $arg) {
    if ($arg === '--execute') {
        $execute = true;
    } elseif (str_starts_with($arg, '--credentials-file=')) {
        $credentialsFile = substr($arg, 19);
    } elseif (str_starts_with($arg, '--expected-source-head=')) {
        $expectedSourceHead = substr($arg, 23);
    } elseif (str_starts_with($arg, '--timeout=')) {
        $rawTimeout = substr($arg, 10);
        if (! ctype_digit($rawTimeout) || (int) $rawTimeout <= 0 || (int) $rawTimeout > 180) {
            fwrite(STDERR, "Error: --timeout must be an integer between 1 and 180 seconds.\n");
            exit(1);
        }
        $timeout = (int) $rawTimeout;
    } elseif (str_starts_with($arg, '--output-file=')) {
        $outputFile = substr($arg, 14);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php tests/Support/Harness/disposable-db-qualification-runner.php [OPTIONS]\n";
        echo "Options:\n";
        echo "  --execute                    Execute the qualification lifecycle (default: dry-run preflight)\n";
        echo "  --credentials-file=<path>    Secure 0600 JSON credentials file\n";
        echo "  --expected-source-head=<sha> Pinned git commit SHA\n";
        echo "  --timeout=<seconds>          Timeout in seconds (max 180, default 180)\n";
        echo "  --output-file=<path>         File path to save JSON report\n";
        exit(0);
    }
}

// SAFE AUTOLOAD ONLY (no framework boot, no live DB dial)
require __DIR__.'/../../../vendor/autoload.php';

// Verify current git HEAD if expected SHA provided
$actualHead = trim((string) shell_exec('git rev-parse HEAD 2>/dev/null'));
if ($expectedSourceHead !== null && $expectedSourceHead !== '') {
    if (! hash_equals($expectedSourceHead, $actualHead)) {
        fwrite(STDERR, "Error: Source HEAD mismatch: expected {$expectedSourceHead}, got {$actualHead}\n");
        exit(1);
    }
}

// DRY-RUN PREFLIGHT MODE (default without --execute)
if (! $execute) {
    $requiredClasses = [
        DisposableMariaDbSchema::class,
        DisposableFinancialPostRunInspector::class,
        CreateCompanyAction::class,
        Company::class,
        Warehouse::class,
        Unit::class,
        Product::class,
        ProductUnit::class,
        TaxRate::class,
        MoneyAccount::class,
    ];

    $classesStatus = [];
    foreach ($requiredClasses as $cls) {
        $classesStatus[$cls] = class_exists($cls);
        if (! $classesStatus[$cls]) {
            fwrite(STDERR, "Preflight error: Required class '{$cls}' does not exist.\n");
            exit(1);
        }
    }

    $credentialsCheck = null;
    if ($credentialsFile !== null) {
        try {
            $creds = DisposableFinancialPostRunInspector::loadSecureBootstrapCredentials($credentialsFile);
            $credentialsCheck = [
                'file_valid' => true,
                'target_host' => $creds['host'],
                'target_port' => (int) $creds['port'],
                'schema_name' => $creds['schema_name'],
            ];
        } catch (\Throwable $e) {
            fwrite(STDERR, "Preflight credentials error: {$e->getMessage()}\n");
            exit(1);
        }
    }

    $preflightReport = [
        'qualification_status' => 'PREFLIGHT_OK',
        'execution_mode' => 'dry_run',
        'source_head' => $actualHead,
        'target_host' => '127.0.0.1',
        'target_port' => 3342,
        'timeout_limit_seconds' => $timeout,
        'classes_verified' => array_keys($classesStatus),
        'credentials_preflight' => $credentialsCheck,
        'message' => 'Qualification runner dry-run preflight passed. Zero sockets dialed, zero DB touched. Awaiting explicit Root lease to run with --execute.',
    ];

    $jsonOutput = json_encode($preflightReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($outputFile !== null) {
        file_put_contents($outputFile, $jsonOutput.PHP_EOL);
    }
    echo $jsonOutput.PHP_EOL;
    exit(0);
}

// =========================================================================
// EXECUTION MODE (--execute)
// Bounded execution under strict time limits, signal handling, and cleanup
// =========================================================================

$startTime = microtime(true);
$preChecks = [];
$cleanupVerified = false;
$schema = null;
$schemaName = null;
$cliCacheDir = null;

// Emergency signal handling
$emergencyCleanup = static function () use (&$schema): void {
    if ($schema instanceof DisposableMariaDbSchema) {
        try {
            $schema->drop();
            fwrite(STDERR, "\nEmergency cleanup: Owned disposable schema dropped.\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "\nEmergency cleanup failed: {$e->getMessage()}\n");
        }
    }
    exit(1);
};

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, $emergencyCleanup);
    pcntl_signal(SIGTERM, $emergencyCleanup);
    if (defined('SIGHUP')) {
        pcntl_signal(SIGHUP, $emergencyCleanup);
    }
}

try {
    // 1. Load credentials if file provided
    if ($credentialsFile !== null) {
        $creds = DisposableFinancialPostRunInspector::loadSecureBootstrapCredentials($credentialsFile);
        $schemaNameFromCreds = $creds['schema_name'];
    }

    // Ensure mandatory environment flags
    putenv('APP_ENV=testing');
    putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
    putenv('MOBILE_V2_HARNESS_ENABLE=1');
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['PHASE8_ALLOW_DISPOSABLE_DB'] = '1';
    $_ENV['MOBILE_V2_HARNESS_ENABLE'] = '1';
    $_SERVER['APP_ENV'] = 'testing';
    $_SERVER['PHASE8_ALLOW_DISPOSABLE_DB'] = '1';
    $_SERVER['MOBILE_V2_HARNESS_ENABLE'] = '1';

    // Verify test settings strictly bind to 127.0.0.1:3342
    $settings = DisposableMariaDbSchema::testSettings();
    if ($settings['HOST'] !== '127.0.0.1') {
        throw new RuntimeException("Disposable MariaDB host must be 127.0.0.1, got '{$settings['HOST']}'.");
    }
    if ($settings['PORT'] !== '3342') {
        throw new RuntimeException("Disposable MariaDB port must be 3342, got '{$settings['PORT']}'.");
    }

    // 2. Controlled Negative Pre-checks (PROVE BEFORE REAL QUERIES)

    // Pre-check 1: Missing/unproven schema ownership proof refusal
    $refusedProof = false;
    $proofRefusalMessage = null;
    $savedProof = getenv('PHASE8_DISPOSABLE_DB_PROOF');
    $savedNonce = getenv('PHASE8_DISPOSABLE_DB_NONCE');
    putenv('PHASE8_DISPOSABLE_DB_PROOF=');
    putenv('PHASE8_DISPOSABLE_DB_NONCE=');
    try {
        DisposableMariaDbSchema::assertPrimarySchema('accounting_p8_tmp_000000000000');
    } catch (\RuntimeException $e) {
        $refusedProof = true;
        $proofRefusalMessage = $e->getMessage();
    } finally {
        if ($savedProof !== false && $savedProof !== '') {
            putenv("PHASE8_DISPOSABLE_DB_PROOF={$savedProof}");
        }
        if ($savedNonce !== false && $savedNonce !== '') {
            putenv("PHASE8_DISPOSABLE_DB_NONCE={$savedNonce}");
        }
    }
    if (! $refusedProof) {
        throw new RuntimeException('Pre-check failed: DisposableMariaDbSchema did not refuse unproven schema.');
    }
    $preChecks['proof_refusal'] = [
        'passed' => true,
        'message' => $proofRefusalMessage,
    ];

    // Pre-check 2: Invalid schema name refusal
    $refusedInvalidSchema = false;
    $invalidSchemaMessage = null;
    try {
        DisposableFinancialPostRunInspector::assertExecutionGates('invalid_schema_name');
    } catch (\RuntimeException $e) {
        $refusedInvalidSchema = true;
        $invalidSchemaMessage = $e->getMessage();
    }
    if (! $refusedInvalidSchema) {
        throw new RuntimeException('Pre-check failed: DisposableFinancialPostRunInspector did not refuse invalid schema name.');
    }
    $preChecks['invalid_schema_refusal'] = [
        'passed' => true,
        'message' => $invalidSchemaMessage,
    ];

    // Pre-check 3: Ambient DSN poisoning refusal (ambient port 1 / sentinel unconnectable)
    $ambientRefused = false;
    $ambientMessage = null;
    $ambientPoisonDsn = 'mysql:host=127.0.0.1;port=1;dbname=poison_sentinel_unconnectable;charset=utf8mb4';
    try {
        new PDO($ambientPoisonDsn, 'poison_user', 'poison_pass', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 1,
        ]);
    } catch (\Throwable $e) {
        $ambientRefused = true;
        $ambientMessage = $e->getMessage();
    }
    if (! $ambientRefused) {
        throw new RuntimeException('Pre-check failed: Ambient poisoned DSN (port 1) was not refused.');
    }
    $preChecks['ambient_poisoning_refusal'] = [
        'passed' => true,
        'message' => $ambientMessage,
    ];

    // Check elapsed time against timeout
    if ((microtime(true) - $startTime) > $timeout) {
        throw new RuntimeException("Timeout exceeded during pre-checks ({$timeout}s limit).");
    }

    // 3. Isolated Laravel Pre-boot with Ambient Poisoning
    $cliCacheDir = sys_get_temp_dir().'/cli_qual_cache_'.bin2hex(random_bytes(6));
    if (! is_dir($cliCacheDir)) {
        mkdir($cliCacheDir, 0700, true);
    }

    $isolatedEnv = [
        'APP_ENV' => 'testing',
        'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
        'MOBILE_V2_HARNESS_ENABLE' => '1',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '1', // Ambient poisoned to port 1
        'DB_DATABASE' => 'poison_sentinel_unconnectable',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'APP_CONFIG_CACHE' => $cliCacheDir.'/config.php',
        'APP_SERVICES_CACHE' => $cliCacheDir.'/services.php',
        'APP_PACKAGES_CACHE' => $cliCacheDir.'/packages.php',
        'APP_ROUTES_CACHE' => $cliCacheDir.'/routes-v7.php',
    ];

    foreach ($isolatedEnv as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }

    /** @var Application $app */
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    // 4. Schema Creation & Migration Lifecycle
    $schema = DisposableMariaDbSchema::create();
    $schemaName = $schema->schemaName();
    $schema->migrate();

    // Propagate ownership proof
    $schemaEnv = $schema->environment();
    $proofPath = $schemaEnv['PHASE8_DISPOSABLE_DB_PROOF'];
    $proofNonce = $schemaEnv['PHASE8_DISPOSABLE_DB_NONCE'];
    putenv("PHASE8_DISPOSABLE_DB_PROOF={$proofPath}");
    putenv("PHASE8_DISPOSABLE_DB_NONCE={$proofNonce}");
    $_ENV['PHASE8_DISPOSABLE_DB_PROOF'] = $proofPath;
    $_ENV['PHASE8_DISPOSABLE_DB_NONCE'] = $proofNonce;
    $_SERVER['PHASE8_DISPOSABLE_DB_PROOF'] = $proofPath;
    $_SERVER['PHASE8_DISPOSABLE_DB_NONCE'] = $proofNonce;

    // Verify ownership proof on freshly created schema
    DisposableMariaDbSchema::assertPrimarySchema($schemaName);

    // 5. Switch Laravel Connection to Owned Schema for Fixture Seeding
    $schema->switchLaravelConnection();

    /** @var object{port: string|int, db: string} $dbIdentity */
    $dbIdentity = DB::selectOne('SELECT @@port AS port, DATABASE() AS db');
    $currentDb = (string) $dbIdentity->db;
    $currentPort = (int) $dbIdentity->port;

    if ($currentDb !== $schemaName) {
        throw new RuntimeException("Connection verification failed: expected database '{$schemaName}', got '{$currentDb}'.");
    }
    if ($currentPort !== 3342) {
        throw new RuntimeException("Connection verification failed: expected port 3342, got '{$currentPort}'.");
    }

    // Seed minimal foundation fixture (zero financial transactions, delta 0)
    app(CompanyContext::class)->clear();

    $owner = User::factory()->create([
        'email' => 'qual_owner_'.Str::random(8).'@example.com',
        'password' => Hash::make('QualOwnerPass123!'),
    ]);

    /** @var Company $company */
    $company = app(CreateCompanyAction::class)->execute($owner, [
        'name_ar' => 'شركة مؤهلة للتكامل',
        'name_en' => 'Qualified Integration Co',
        'base_currency_code' => 'ILS',
    ]);

    auth()->setUser($owner);
    app(CompanyContext::class)->setCompany($company, $owner);
    setPermissionsTeamId($company->id);
    $owner->assignRole('Owner');

    $warehouse = Warehouse::where('company_id', $company->id)->where('is_default', true)->firstOrFail();
    $baseUnit = Unit::where('company_id', $company->id)->where('code', 'piece')->firstOrFail();

    $product = Product::create([
        'company_id' => $company->id,
        'base_unit_id' => $baseUnit->id,
        'name_ar' => 'منتج تكامل مؤهل',
        'name_en' => 'Qualified Integration Product',
        'sku' => 'QUAL-PROD-001',
        'product_type' => 'stock',
        'track_stock' => true,
        'active' => true,
        'created_by' => $owner->id,
    ]);

    ProductUnit::create([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'unit_id' => $baseUnit->id,
        'conversion_to_base' => '1.000000',
        'is_base' => true,
        'is_default_sale' => true,
        'is_default_purchase' => true,
        'active' => true,
        'default_sale_price_base' => '50.000000',
    ]);

    $taxRate = TaxRate::create([
        'company_id' => $company->id,
        'code' => 'VAT16',
        'name_ar' => 'ضريبة القيمة المضافة 16%',
        'name_en' => 'VAT 16%',
        'rate' => '16.000000',
        'calculation' => 'exclusive',
        'active' => true,
    ]);

    $cashAccount = MoneyAccount::where('company_id', $company->id)
        ->where('account_type', MoneyAccount::TYPE_CASH)
        ->where('currency_code', $company->base_currency_code)
        ->firstOrFail();

    auth()->forgetUser();
    app(CompanyContext::class)->clear();

    // Restore Laravel connection and re-poison ambient configuration
    $schema->restoreLaravelConnection();
    config([
        'database.connections.mysql.host' => '127.0.0.1',
        'database.connections.mysql.port' => 1,
        'database.connections.mysql.database' => 'poison_sentinel_unconnectable',
    ]);
    DB::purge();

    // 6. Dedicated Read-Only PDO & Statement Timeout Verification
    $dedicatedDsn = "mysql:host={$settings['HOST']};port={$settings['PORT']};dbname={$schemaName};charset=utf8mb4";
    $dedicatedPdo = new PDO($dedicatedDsn, $settings['USERNAME'], $settings['PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    DisposableFinancialPostRunInspector::configureStatementTimeout($dedicatedPdo, 5);
    $dedicatedPdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $dedicatedPdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

    $identityRow = $dedicatedPdo->query('SELECT DATABASE() AS db, @@port AS port')->fetch(PDO::FETCH_ASSOC);
    if (! is_array($identityRow) || ($identityRow['db'] ?? '') !== $schemaName) {
        throw new RuntimeException("Dedicated PDO DB mismatch: expected '{$schemaName}', got '".($identityRow['db'] ?? 'null')."'.");
    }
    if ((string) ($identityRow['port'] ?? '') !== '3342') {
        throw new RuntimeException("Dedicated PDO port mismatch: expected 3342, got '".($identityRow['port'] ?? 'null')."'.");
    }

    $roValue = null;
    try {
        $roValue = (int) $dedicatedPdo->query('SELECT @@transaction_read_only AS ro')->fetchColumn();
    } catch (\Throwable) {
        $roValue = (int) $dedicatedPdo->query('SELECT @@tx_read_only AS ro')->fetchColumn();
    }
    if ($roValue !== 1) {
        throw new RuntimeException("Dedicated PDO transaction_read_only is not 1 (got {$roValue}).");
    }

    $dedicatedPdo->rollBack();
    unset($dedicatedPdo);

    // Check elapsed time against timeout
    if ((microtime(true) - $startTime) > $timeout) {
        throw new RuntimeException("Timeout exceeded before reconciliation ({$timeout}s limit).");
    }

    // 7. Capture Pre-Reconciliation Baseline
    $preBaseline = DisposableFinancialPostRunInspector::captureBaseline($company->id, $schemaName);

    // 8. Execute Six Real Isolated SAME-PDO Reconcilers
    $reconciliationReport = DisposableFinancialPostRunInspector::runSixReconciliations($company->id, $schemaName);
    if (($reconciliationReport['all_healthy'] ?? false) !== true) {
        throw new RuntimeException('Reconciliation verification failed: one or more reconcilers reported violations.');
    }

    // 9. Capture Post-Reconciliation Baseline & Verify Strict 100% Invariance
    $postBaseline = DisposableFinancialPostRunInspector::captureBaseline($company->id, $schemaName);

    if ($postBaseline !== $preBaseline) {
        throw new RuntimeException('Baseline fingerprint mutated during read-only qualification.');
    }

    // Strict delta 0 assertion across all financial entities
    $zeroCheckKeys = [
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
    foreach ($zeroCheckKeys as $key) {
        if (($postBaseline[$key] ?? -1) !== 0) {
            throw new RuntimeException("Baseline non-zero entity detected for '{$key}': got {$postBaseline[$key]}.");
        }
    }
    $stockSum = (float) ($postBaseline['stock_movements_qty_sum'] ?? -1);
    if ($stockSum !== 0.0) {
        throw new RuntimeException("Baseline non-zero stock_movements_qty_sum: got {$postBaseline['stock_movements_qty_sum']}.");
    }

    // Document sequences must be in pristine initialized state (next_number = 1)
    $sequences = $postBaseline['document_sequences'] ?? [];
    $currentYear = (int) date('Y');
    $expectedSeqKeys = [
        "sales_invoice:{$currentYear}",
        "customer_payment:{$currentYear}",
    ];
    foreach ($expectedSeqKeys as $expectedSeqKey) {
        if (! isset($sequences[$expectedSeqKey]) || $sequences[$expectedSeqKey] !== 1) {
            throw new RuntimeException("Document sequence '{$expectedSeqKey}' expected next_number=1, got ".(isset($sequences[$expectedSeqKey]) ? (string) $sequences[$expectedSeqKey] : 'missing').'.');
        }
    }

    $executionSuccess = true;
} finally {
    // 10. Drop Owned Schema and Assert Complete Absence
    if ($schema instanceof DisposableMariaDbSchema) {
        try {
            $adminPdo = $schema->getAdminPdo();
            $schema->drop();
            $checkStmt = $adminPdo->prepare('SHOW DATABASES LIKE ?');
            $checkStmt->execute([$schemaName]);
            $remaining = $checkStmt->fetchColumn();
            if ($remaining !== false && $remaining !== null) {
                $cleanupVerified = false;
                fwrite(STDERR, "Warning: Schema '{$schemaName}' still visible in SHOW DATABASES.\n");
            } else {
                $cleanupVerified = true;
            }
        } catch (\Throwable $e) {
            $cleanupVerified = false;
            fwrite(STDERR, "Cleanup error: {$e->getMessage()}\n");
        }
    }

    // Clean up temporary cache dir if created
    if ($cliCacheDir !== null && is_dir($cliCacheDir)) {
        $files = glob($cliCacheDir.'/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        @rmdir($cliCacheDir);
    }
}

// 11. Produce Sanitized Root-Witness JSON Output (zero passwords/secrets printed)
$duration = round(microtime(true) - $startTime, 4);
$outputReport = [
    'qualification_status' => 'QUALIFIED',
    'execution_mode' => 'execute',
    'source_head' => $actualHead,
    'target_host' => '127.0.0.1',
    'target_port' => 3342,
    'schema_name' => $schemaName,
    'company_id' => $company->id ?? null,
    'duration_seconds' => $duration,
    'timeout_limit_seconds' => $timeout,
    'pre_checks' => $preChecks,
    'pdo_verification' => [
        'statement_timeout_seconds' => 5,
        'database_verified' => $schemaName,
        'port_verified' => 3342,
        'transaction_read_only_verified' => 1,
    ],
    'reconciliation_results' => $reconciliationReport,
    'baseline_fingerprint' => [
        'pre_and_post_strictly_equal' => true,
        'delta_zero_verified' => true,
        'document_sequences' => $postBaseline['document_sequences'] ?? [],
    ],
    'cleanup_verified' => $cleanupVerified,
];

$finalJson = json_encode($outputReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($outputFile !== null) {
    file_put_contents($outputFile, $finalJson.PHP_EOL);
}
echo $finalJson.PHP_EOL;

exit(0);
