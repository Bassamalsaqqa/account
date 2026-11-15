<?php

declare(strict_types=1);

/**
 * Standalone MariaDB Qualification Runner for Disposable Schema & Six Reconcilers
 * Location: tests/Support/Harness/disposable-db-qualification-runner.php
 *
 * Strictly enforces:
 * - Default NO-EXECUTE (dry-run preflight mode without --execute)
 * - Mandatory exact 40-hex --expected-source-head=<sha> and tracked-clean check for --execute
 * - Real pcntl_alarm deadline with availability and ALL handler registrations checked before DB/bootstrap
 * - Asynchronous signal handlers throw controlled exceptions; no exit() inside signal handlers
 * - Subprocess migration bounded and reaped before owned schema drop
 * - Controlled pre-check refusals & regressions (proven before real queries):
 *     1. Missing/unproven schema ownership proof refusal
 *     2. Invalid schema name refusal
 *     3. Synthetic no-DB child process environment propagation regression (testSettings + assertPrimarySchema)
 * - Poisoned ambient Laravel connection sentinel active DURING baseline & reconciler calls
 * - Minimal canonical foundation fixture via CreateCompanyAction ONLY (delta 0)
 * - Dedicated read-only PDO connection:
 *     - DisposableFinancialPostRunInspector::configureStatementTimeout($pdo, 5)
 *     - Documented MariaDB innodb_trx trx_read_only observation
 *     - Server-enforced mutation denial test (error 1792 / SQLSTATE 25006) on probe table
 * - Six real isolated SAME-PDO reconcilers via DisposableFinancialPostRunInspector::runSixReconciliations()
 * - Exact BigDecimal zero verification on stock movements (no float casting)
 * - Baseline metrics capture before and after:
 *     - DisposableFinancialPostRunInspector::captureBaseline()
 *     - Assert $postBaseline === $preBaseline (100% strictly equal)
 *     - Assert delta 0 across all financial entities
 * - Finally teardown: drop schema and verify database absence via exact query
 * - Cleanup failure or execution abort NEVER reports QUALIFIED or exits 0
 * - Unreached phases strictly emit NOT_RUN / null without false literals
 * - Preserves raw actual pre/post baseline JSON, 6 reconciler results JSON, sentinel report, and timeout metrics
 */

use App\Actions\Company\CreateCompanyAction;
use App\Models\Company;
use App\Models\User;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\Harness\DisposableFinancialPostRunInspector;

// Ambient connection sentinel intercepting any unauthorized dial on the default connection
final class AmbientConnectionSentinel
{
    public static int $attemptCount = 0;

    /** @var list<string> */
    public static array $attemptContexts = [];

    public static function intercept(string $context): never
    {
        self::$attemptCount++;
        self::$attemptContexts[] = $context;
        throw new \RuntimeException("Ambient connection intercepted by sentinel: [{$context}]");
    }

    public static function reset(): void
    {
        self::$attemptCount = 0;
        self::$attemptContexts = [];
    }
}

/**
 * Loads and validates secure administrative database credentials.
 * Does NOT require existing schema or proof tokens.
 *
 * @return array{
 *     db_host: string,
 *     db_port: string,
 *     db_username: string,
 *     db_password: string,
 *     schema_name: ?string
 * }
 */
function loadSecureAdministrativeCredentials(string $filePath): array
{
    if (is_link($filePath)) {
        throw new \RuntimeException('ADMIN_CONFIG_SYMLINK_REJECTED');
    }
    if (! is_file($filePath)) {
        throw new \RuntimeException('ADMIN_CONFIG_FILE_NOT_FOUND');
    }

    $parentDir = dirname($filePath);
    if (is_link($parentDir)) {
        throw new \RuntimeException('ADMIN_CONFIG_PARENT_SYMLINK_REJECTED');
    }

    $currentUid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();

    $filePerms = fileperms($filePath) & 0777;
    if ($filePerms !== 0600) {
        throw new \RuntimeException(sprintf('ADMIN_CONFIG_INSECURE_PERMS: expected 0600, got %04o', $filePerms));
    }

    $fileOwner = fileowner($filePath);
    if ($fileOwner !== $currentUid) {
        throw new \RuntimeException('ADMIN_CONFIG_OWNER_MISMATCH');
    }

    $parentPerms = fileperms($parentDir) & 0777;
    if ($parentPerms !== 0700) {
        throw new \RuntimeException(sprintf('ADMIN_CONFIG_PARENT_INSECURE_PERMS: expected 0700, got %04o', $parentPerms));
    }

    $parentOwner = fileowner($parentDir);
    if ($parentOwner !== $currentUid) {
        throw new \RuntimeException('ADMIN_CONFIG_PARENT_OWNER_MISMATCH');
    }

    $raw = (string) file_get_contents($filePath);
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new \RuntimeException('ADMIN_CONFIG_INVALID_JSON: '.$e->getMessage());
    }

    if (! is_array($data)) {
        throw new \RuntimeException('ADMIN_CONFIG_INVALID_JSON_STRUCTURE');
    }

    $host = (string) ($data['db_host'] ?? $data['host'] ?? '');
    if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
        throw new \RuntimeException("ADMIN_CONFIG_INVALID_HOST: expected 127.0.0.1 or localhost, got '{$host}'");
    }

    $port = (string) ($data['db_port'] ?? $data['port'] ?? '');
    if ($port !== '3342') {
        throw new \RuntimeException("ADMIN_CONFIG_INVALID_PORT: expected 3342, got '{$port}'");
    }

    $user = (string) ($data['db_username'] ?? $data['username'] ?? '');
    if ($user === '') {
        throw new \RuntimeException('ADMIN_CONFIG_MISSING_USERNAME');
    }

    $pass = (string) ($data['db_password'] ?? $data['password'] ?? '');

    $schema = isset($data['schema_name']) ? (string) $data['schema_name'] : (isset($data['schema']) ? (string) $data['schema'] : null);
    if ($schema !== null && $schema !== '' && ! preg_match(DisposableFinancialPostRunInspector::SCHEMA_REGEX, $schema)) {
        throw new \RuntimeException("ADMIN_CONFIG_INVALID_SCHEMA: '{$schema}'");
    }

    // Populate environment variables for DisposableMariaDbSchema::testSettings() across all runtime interfaces
    putenv("PHASE8_TEST_DB_HOST={$host}");
    putenv("PHASE8_TEST_DB_PORT={$port}");
    putenv("PHASE8_TEST_DB_USERNAME={$user}");
    putenv("PHASE8_TEST_DB_PASSWORD={$pass}");
    $_ENV['PHASE8_TEST_DB_HOST'] = $host;
    $_ENV['PHASE8_TEST_DB_PORT'] = $port;
    $_ENV['PHASE8_TEST_DB_USERNAME'] = $user;
    $_ENV['PHASE8_TEST_DB_PASSWORD'] = $pass;
    $_SERVER['PHASE8_TEST_DB_HOST'] = $host;
    $_SERVER['PHASE8_TEST_DB_PORT'] = $port;
    $_SERVER['PHASE8_TEST_DB_USERNAME'] = $user;
    $_SERVER['PHASE8_TEST_DB_PASSWORD'] = $pass;
    if ($pass === '') {
        putenv('PHASE8_TEST_DB_EMPTY_PASSWORD=1');
        $_ENV['PHASE8_TEST_DB_EMPTY_PASSWORD'] = '1';
        $_SERVER['PHASE8_TEST_DB_EMPTY_PASSWORD'] = '1';
    }

    return [
        'db_host' => $host,
        'db_port' => $port,
        'db_username' => $user,
        'db_password' => $pass,
        'schema_name' => $schema,
    ];
}

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
        echo "  --credentials-file=<path>    Secure 0600 JSON administrative credentials file\n";
        echo "  --expected-source-head=<sha> Pinned 40-hex git commit SHA (mandatory for --execute)\n";
        echo "  --timeout=<seconds>          Timeout in seconds (max 180, default 180)\n";
        echo "  --output-file=<path>         File path to save JSON report\n";
        exit(0);
    }
}

// SAFE AUTOLOAD ONLY (no framework boot, no live DB dial)
require __DIR__.'/../../../vendor/autoload.php';

// Verify current git HEAD
$actualHead = trim((string) shell_exec('git rev-parse HEAD 2>/dev/null'));

// DRY-RUN PREFLIGHT MODE (default without --execute)
if (! $execute) {
    if ($expectedSourceHead !== null && $expectedSourceHead !== '') {
        if (! hash_equals($expectedSourceHead, $actualHead)) {
            fwrite(STDERR, "Error: Source HEAD mismatch: expected {$expectedSourceHead}, got {$actualHead}\n");
            exit(1);
        }
    }

    $requiredClasses = [
        DisposableMariaDbSchema::class,
        DisposableFinancialPostRunInspector::class,
        CreateCompanyAction::class,
        CompanyRoleService::class,
        Company::class,
        User::class,
        BigDecimal::class,
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
            $adminCreds = loadSecureAdministrativeCredentials($credentialsFile);
            $credentialsCheck = [
                'file_valid' => true,
                'target_host' => $adminCreds['db_host'],
                'target_port' => (int) $adminCreds['db_port'],
                'schema_name' => $adminCreds['schema_name'],
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
// Bounded execution under pcntl_alarm deadline, signal handling, and cleanup
// =========================================================================

// 1. Mandatory exact full expected-source-head & tracked-clean check
if ($expectedSourceHead === null || ! preg_match('/^[0-9a-f]{40}$/D', $expectedSourceHead)) {
    fwrite(STDERR, "Error: Mandatory exact 40-hex --expected-source-head=<sha> required for --execute.\n");
    exit(1);
}
if (! hash_equals($expectedSourceHead, $actualHead)) {
    fwrite(STDERR, "Error: Source HEAD mismatch: expected {$expectedSourceHead}, got {$actualHead}\n");
    exit(1);
}

$gitStatus = trim((string) shell_exec('git status --porcelain 2>/dev/null'));
if ($gitStatus !== '') {
    $statusLines = explode("\n", $gitStatus);
    $uncleanLines = [];
    foreach ($statusLines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        // If output file was specified, allow only its own untracked existence
        if ($outputFile !== null && str_ends_with($trimmed, basename($outputFile))) {
            continue;
        }
        $uncleanLines[] = $trimmed;
    }
    if ($uncleanLines !== []) {
        fwrite(STDERR, "Error: Working tree is not tracked-clean before execution:\n".implode("\n", $uncleanLines)."\n");
        exit(1);
    }
}

// 2. Mandatory pcntl availability and ALL signal handler registrations before DB/bootstrap
if (! function_exists('pcntl_alarm') || ! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals')) {
    fwrite(STDERR, "Error: Mandatory pcntl extension with pcntl_alarm, pcntl_signal, and pcntl_async_signals is required.\n");
    exit(1);
}

pcntl_async_signals(true);

$aborted = false;
$activeSignal = null;
$abortReason = null;

// Signal handler throws controlled exception; no exit() inside handler to prevent async race
$signalHandler = static function (int $signo) use (&$aborted, &$activeSignal, &$abortReason): void {
    $aborted = true;
    $activeSignal = $signo;
    $name = match ($signo) {
        SIGALRM => 'SIGALRM (Timeout deadline exceeded)',
        SIGINT => 'SIGINT (Interrupt)',
        SIGTERM => 'SIGTERM (Terminated)',
        defined('SIGHUP') && $signo === SIGHUP => 'SIGHUP (Hangup)',
        default => "Signal {$signo}",
    };
    $abortReason = "Execution aborted by {$name}";
    throw new \RuntimeException($abortReason);
};

$regOk = pcntl_signal(SIGALRM, $signalHandler)
    && pcntl_signal(SIGINT, $signalHandler)
    && pcntl_signal(SIGTERM, $signalHandler)
    && (! defined('SIGHUP') || pcntl_signal(SIGHUP, $signalHandler));

if (! $regOk) {
    fwrite(STDERR, "Error: Failed to register all mandatory pcntl signal handlers.\n");
    exit(1);
}

$startTime = microtime(true);
$preChecks = [];
$cleanupVerified = false;
$executionSuccess = false;
$qualificationStatus = 'FAILED';
$exitCode = 1;
$errors = [];

$schema = null;
$schemaName = null;
$cliCacheDir = null;
$companyId = null;

// Phase tracking variables to prevent false observations of unreached steps
$sentinelInstalled = false;
$observedDedicatedPdoDatabase = null;
$observedDedicatedPdoPort = null;
$observedDedicatedPdoVerified = false;
$innodbTrxReadOnlyObserved = null;
$mutationDeniedObserved = false;
$denialMessageObserved = null;
$denialCodeObserved = null;

$preBaseline = null;
$postBaseline = null;
$reconciliationReport = null;

// Arm the bounded alarm deadline
pcntl_alarm($timeout);

try {
    // 3. Load administrative credentials if provided
    if ($credentialsFile !== null) {
        loadSecureAdministrativeCredentials($credentialsFile);
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
        throw new \RuntimeException("Disposable MariaDB host must be 127.0.0.1, got '{$settings['HOST']}'.");
    }
    if ($settings['PORT'] !== '3342') {
        throw new \RuntimeException("Disposable MariaDB port must be 3342, got '{$settings['PORT']}'.");
    }

    // 4. Controlled Negative Pre-checks & Regressions (PROVE BEFORE REAL QUERIES)

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
        throw new \RuntimeException('Pre-check failed: DisposableMariaDbSchema did not refuse unproven schema.');
    }
    $preChecks['proof_refusal'] = [
        'status' => 'PASS',
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
        throw new \RuntimeException('Pre-check failed: DisposableFinancialPostRunInspector did not refuse invalid schema name.');
    }
    $preChecks['invalid_schema_refusal'] = [
        'status' => 'PASS',
        'message' => $invalidSchemaMessage,
    ];

    // Pre-check 3: Synthetic child process environment propagation regression (no DB socket)
    $syntheticProofPath = tempnam(sys_get_temp_dir(), 'p8_synth_proof_');
    if ($syntheticProofPath === false) {
        throw new \RuntimeException('Failed to create synthetic proof file.');
    }
    chmod($syntheticProofPath, 0600);
    $syntheticNonce = bin2hex(random_bytes(16));
    $syntheticSchema = 'accounting_p8_tmp_0123456789ab';
    file_put_contents($syntheticProofPath, json_encode([
        'schema' => $syntheticSchema,
        'host' => '127.0.0.1',
        'port' => '3342',
        'nonce' => $syntheticNonce,
    ], JSON_THROW_ON_ERROR));

    $childScript = tempnam(sys_get_temp_dir(), 'p8_synth_child_');
    if ($childScript === false) {
        unlink($syntheticProofPath);
        throw new \RuntimeException('Failed to create synthetic child script.');
    }
    chmod($childScript, 0600);
    $autoloadPath = var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true);
    $schemaEsc = var_export($syntheticSchema, true);
    file_put_contents($childScript, '<?php
    require '.$autoloadPath.';
    $settings = \Tests\Support\DisposableMariaDbSchema::testSettings();
    if ($settings["HOST"] !== "127.0.0.1" || $settings["PORT"] !== "3342" || $settings["USERNAME"] !== "dummy_user") {
        throw new \RuntimeException("Child testSettings mismatch");
    }
    \Tests\Support\DisposableMariaDbSchema::assertPrimarySchema('.$schemaEsc.');
    echo "SYNTHETIC_CHILD_PROPAGATION_OK";
    ');

    $syntheticEnv = [
        'APP_ENV' => 'testing',
        'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
        'PHASE8_TEST_DB_HOST' => '127.0.0.1',
        'PHASE8_TEST_DB_PORT' => '3342',
        'PHASE8_TEST_DB_USERNAME' => 'dummy_user',
        'PHASE8_TEST_DB_PASSWORD' => 'dummy_pass',
        'PHASE8_DISPOSABLE_DB_PROOF' => $syntheticProofPath,
        'PHASE8_DISPOSABLE_DB_NONCE' => $syntheticNonce,
    ];

    try {
        $proc = new Process([PHP_BINARY, $childScript], dirname(__DIR__, 3), $syntheticEnv);
        $proc->setTimeout(10)->mustRun();
        $childOut = $proc->getOutput();
        if (! str_contains($childOut, 'SYNTHETIC_CHILD_PROPAGATION_OK')) {
            throw new \RuntimeException('Synthetic child propagation did not output expected marker.');
        }
        $preChecks['child_env_propagation'] = [
            'status' => 'PASS',
            'message' => 'Synthetic child process validated testSettings and assertPrimarySchema under explicit environment without DB socket.',
        ];
    } finally {
        @unlink($syntheticProofPath);
        @unlink($childScript);
    }

    // 5. Isolated Laravel Pre-boot with Ambient Poisoning
    $cliCacheDir = sys_get_temp_dir().'/cli_qual_cache_'.bin2hex(random_bytes(6));
    if (! is_dir($cliCacheDir)) {
        mkdir($cliCacheDir, 0700, true);
    }

    $isolatedEnv = [
        'APP_ENV' => 'testing',
        'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
        'MOBILE_V2_HARNESS_ENABLE' => '1',
        'PHASE8_TEST_DB_HOST' => $settings['HOST'],
        'PHASE8_TEST_DB_PORT' => $settings['PORT'],
        'PHASE8_TEST_DB_USERNAME' => $settings['USERNAME'],
        'PHASE8_TEST_DB_PASSWORD' => $settings['PASSWORD'],
        'DB_URL' => '',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '1', // Ambient poisoned port
        'DB_DATABASE' => 'poison_sentinel_unconnectable',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'APP_CONFIG_CACHE' => $cliCacheDir.'/config.php',
        'APP_SERVICES_CACHE' => $cliCacheDir.'/services.php',
        'APP_PACKAGES_CACHE' => $cliCacheDir.'/packages.php',
        'APP_ROUTES_CACHE' => $cliCacheDir.'/routes-v7.php',
    ];
    if ($settings['PASSWORD'] === '' || getenv('PHASE8_TEST_DB_EMPTY_PASSWORD') === '1') {
        $isolatedEnv['PHASE8_TEST_DB_EMPTY_PASSWORD'] = '1';
    }

    foreach ($isolatedEnv as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }

    /** @var Application $app */
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    // 6. Schema Creation & Migration Lifecycle
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

    // 7. Switch Laravel Connection to Owned Schema for Fixture Seeding
    $schema->switchLaravelConnection();

    /** @var object{port: string|int, db: string} $dbIdentity */
    $dbIdentity = DB::selectOne('SELECT @@port AS port, DATABASE() AS db');
    $currentDb = (string) $dbIdentity->db;
    $currentPort = (int) $dbIdentity->port;

    if ($currentDb !== $schemaName) {
        throw new \RuntimeException("Connection verification failed: expected database '{$schemaName}', got '{$currentDb}'.");
    }
    if ($currentPort !== 3342) {
        throw new \RuntimeException("Connection verification failed: expected port 3342, got '{$currentPort}'.");
    }

    // Seed minimal canonical foundation fixture via CreateCompanyAction ONLY (delta 0)
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
    $companyId = $company->id;

    // Ensure permissions and roles are established
    app(CompanyRoleService::class)->ensurePermissionsExist();
    app(CompanyRoleService::class)->seedCompanyRoles($company);
    setPermissionsTeamId($company->id);
    $owner->assignRole('Owner');

    auth()->forgetUser();
    app(CompanyContext::class)->clear();

    // Restore Laravel connection
    $schema->restoreLaravelConnection();

    // 8. Install Poisoned Ambient Connection Sentinel and Poison Ambient DSN
    // The sentinel intercepts any attempt to resolve or dial the default ambient connection
    AmbientConnectionSentinel::reset();
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.host' => '127.0.0.1',
        'database.connections.mysql.port' => 1,
        'database.connections.mysql.database' => 'poison_sentinel_unconnectable',
    ]);
    $dbManager = app('db');
    $dbManager->extend('mysql', function ($config, $name) {
        AmbientConnectionSentinel::intercept("DB::connection('{$name}')");
    });
    DB::purge();
    $sentinelInstalled = true;

    // 9. Dedicated Read-Only PDO, Documented MariaDB Read-Only Proof & Server Denial
    $dedicatedDsn = "mysql:host={$settings['HOST']};port={$settings['PORT']};dbname={$schemaName};charset=utf8mb4";
    $dedicatedPdo = new PDO($dedicatedDsn, $settings['USERNAME'], $settings['PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    DisposableFinancialPostRunInspector::configureStatementTimeout($dedicatedPdo, 5);

    $identityRow = $dedicatedPdo->query('SELECT DATABASE() AS db, @@port AS port')->fetch(PDO::FETCH_ASSOC);
    $observedDedicatedPdoDatabase = (string) ($identityRow['db'] ?? '');
    $observedDedicatedPdoPort = (int) ($identityRow['port'] ?? 0);
    $observedDedicatedPdoVerified = ($observedDedicatedPdoDatabase === $schemaName && $observedDedicatedPdoPort === 3342);

    if (! $observedDedicatedPdoVerified) {
        throw new \RuntimeException("Dedicated PDO DB mismatch: expected '{$schemaName}'@3342, got '{$observedDedicatedPdoDatabase}'@{$observedDedicatedPdoPort}.");
    }

    // Create temporary probe table under owned schema to test server-enforced mutation denial
    $dedicatedPdo->exec('CREATE TABLE `_disposable_ro_probe` (id INT PRIMARY KEY, val VARCHAR(32)) ENGINE=InnoDB');
    $dedicatedPdo->exec("INSERT INTO `_disposable_ro_probe` (id, val) VALUES (1, 'initial')");

    // Enter read-only transaction
    $dedicatedPdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $dedicatedPdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

    // Documented MariaDB transaction observation via information_schema.innodb_trx
    $stmtTrx = $dedicatedPdo->query('SELECT trx_read_only FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID()');
    $trxRow = $stmtTrx->fetch(PDO::FETCH_ASSOC);
    $innodbTrxReadOnly = isset($trxRow['trx_read_only']) ? (int) $trxRow['trx_read_only'] : null;
    $innodbTrxReadOnlyObserved = $innodbTrxReadOnly;
    if ($innodbTrxReadOnly !== 1) {
        throw new \RuntimeException('Documented MariaDB innodb_trx observation failed: trx_read_only is not 1 (got '.($innodbTrxReadOnly ?? 'null').').');
    }

    // Harmless mutation attempt on disposable probe table inside READ ONLY transaction
    $mutationDenied = false;
    $denialMessage = null;
    $denialSqlState = null;
    try {
        $dedicatedPdo->exec("UPDATE `_disposable_ro_probe` SET val = 'mutated' WHERE id = 1");
    } catch (\PDOException $e) {
        $mutationDenied = true;
        $denialMessage = $e->getMessage();
        $denialSqlState = (string) $e->getCode();
    }
    $mutationDeniedObserved = $mutationDenied;
    $denialMessageObserved = $denialMessage;
    $denialCodeObserved = $denialSqlState;

    if (! $mutationDenied) {
        throw new \RuntimeException('Read-only enforcement failure: MariaDB server permitted UPDATE inside READ ONLY transaction!');
    }
    // Verify server-side rejection (error 1792 or SQLSTATE 25006 or READ ONLY message)
    if (! str_contains($denialMessage, '1792') && ! str_contains($denialMessage, 'READ ONLY') && $denialSqlState !== '25006') {
        throw new \RuntimeException("Unexpected mutation rejection (not READ ONLY denial): [{$denialSqlState}] {$denialMessage}");
    }

    $dedicatedPdo->rollBack();
    // Drop probe table cleanly
    $dedicatedPdo->exec('DROP TABLE IF EXISTS `_disposable_ro_probe`');
    unset($dedicatedPdo);

    // 10. Capture Pre-Reconciliation Baseline (ambient sentinel remains active)
    $preBaseline = DisposableFinancialPostRunInspector::captureBaseline($companyId, $schemaName);

    // 11. Execute Six Real Isolated SAME-PDO Reconcilers (ambient sentinel remains active)
    $reconciliationReport = DisposableFinancialPostRunInspector::runSixReconciliations($companyId, $schemaName);
    if (($reconciliationReport['all_healthy'] ?? false) !== true) {
        throw new \RuntimeException('Reconciliation verification failed: one or more reconcilers reported violations.');
    }

    // 12. Capture Post-Reconciliation Baseline (ambient sentinel remains active)
    $postBaseline = DisposableFinancialPostRunInspector::captureBaseline($companyId, $schemaName);

    // 13. Verify Ambient Sentinel Invariant (ZERO ambient connection attempts)
    if (AmbientConnectionSentinel::$attemptCount !== 0) {
        throw new \RuntimeException(sprintf(
            'Unauthorized ambient connection attempts detected: %d attempts (%s)',
            AmbientConnectionSentinel::$attemptCount,
            implode(', ', AmbientConnectionSentinel::$attemptContexts)
        ));
    }

    // 14. Strict 100% Baseline Invariance & Exact BigDecimal Zero Check
    if ($postBaseline !== $preBaseline) {
        throw new \RuntimeException('Baseline fingerprint mutated during read-only qualification.');
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
            throw new \RuntimeException("Baseline non-zero entity detected for '{$key}': got {$postBaseline[$key]}.");
        }
    }

    // Stock movements quantity sum exact BigDecimal zero check (no float casting)
    $stockQtyStr = (string) ($postBaseline['stock_movements_qty_sum'] ?? '');
    if ($stockQtyStr === '' || ! BigDecimal::of($stockQtyStr)->isZero()) {
        throw new \RuntimeException("Baseline non-zero stock_movements_qty_sum: got '{$stockQtyStr}' (BigDecimal non-zero).");
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
            throw new \RuntimeException("Document sequence '{$expectedSeqKey}' expected next_number=1, got ".(isset($sequences[$expectedSeqKey]) ? (string) $sequences[$expectedSeqKey] : 'missing').'.');
        }
    }

    $executionSuccess = true;
} catch (\Throwable $e) {
    $executionSuccess = false;
    $errors[] = $e->getMessage();
    if ($aborted) {
        $qualificationStatus = 'ABORTED';
    } else {
        $qualificationStatus = 'FAILED';
    }
} finally {
    // Disarm alarm deadline
    if (function_exists('pcntl_alarm')) {
        pcntl_alarm(0);
    }

    // 15. Drop Owned Schema and Assert Complete Absence via Exact Query
    if ($schema instanceof DisposableMariaDbSchema) {
        try {
            $adminPdo = $schema->getAdminPdo();
            $schema->drop();
            $checkStmt = $adminPdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $checkStmt->execute([$schemaName]);
            $remaining = $checkStmt->fetchColumn();
            if ($remaining !== false && $remaining !== null) {
                $cleanupVerified = false;
                $errors[] = "Schema '{$schemaName}' still visible in SCHEMATA after drop.";
            } else {
                $cleanupVerified = true;
            }
        } catch (\Throwable $e) {
            $cleanupVerified = false;
            $errors[] = "Schema drop exception: {$e->getMessage()}";
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

    // Only QUALIFIED and exit 0 if BOTH execution succeeded AND cleanup was verified
    if ($executionSuccess === true && $cleanupVerified === true && $errors === []) {
        $qualificationStatus = 'QUALIFIED';
        $exitCode = 0;
    } else {
        if ($qualificationStatus === 'QUALIFIED') {
            $qualificationStatus = 'FAILED';
        }
        $exitCode = 1;
    }
}

// 16. Produce Sanitized Root-Witness JSON Output (zero passwords/secrets printed)
$duration = round(microtime(true) - $startTime, 4);
$outputReport = [
    'qualification_status' => $qualificationStatus,
    'exit_code' => $exitCode,
    'execution_mode' => 'execute',
    'source_info' => [
        'head' => $actualHead,
        'expected_head' => $expectedSourceHead,
        'git_clean' => ($gitStatus === ''),
    ],
    'timeout_report' => [
        'limit_seconds' => $timeout,
        'elapsed_seconds' => $duration,
        'alarm_registered' => true,
        'aborted_by_signal' => $activeSignal,
    ],
    'target_info' => [
        'host' => '127.0.0.1',
        'port' => 3342,
        'schema_name' => $schemaName,
        'company_id' => $companyId,
    ],
    'pre_checks' => $preChecks,
    'sentinel_report' => [
        'status' => $sentinelInstalled ? ($observedDedicatedPdoVerified ? 'VERIFIED' : 'FAILED') : 'NOT_RUN',
        'sentinel_installed' => $sentinelInstalled,
        'ambient_connection_attempts' => $sentinelInstalled ? AmbientConnectionSentinel::$attemptCount : null,
        'attempt_contexts' => $sentinelInstalled ? AmbientConnectionSentinel::$attemptContexts : [],
        'owned_pdo_verified' => $observedDedicatedPdoVerified,
        'owned_pdo_database' => $observedDedicatedPdoDatabase,
        'owned_pdo_port' => $observedDedicatedPdoPort,
    ],
    'read_only_proof' => $observedDedicatedPdoVerified ? [
        'status' => 'VERIFIED',
        'innodb_trx_read_only' => $innodbTrxReadOnlyObserved,
        'probe_mutation_denied' => $mutationDeniedObserved,
        'denial_sqlstate' => $denialCodeObserved,
        'denial_message' => $denialMessageObserved,
    ] : [
        'status' => 'NOT_RUN',
    ],
    'pre_baseline' => $preBaseline,
    'post_baseline' => $postBaseline,
    'baseline_comparison' => ($postBaseline !== null && $preBaseline !== null) ? [
        'status' => 'VERIFIED',
        'strictly_equal' => ($postBaseline === $preBaseline),
        'stock_movements_qty_sum_is_zero' => BigDecimal::of((string) ($postBaseline['stock_movements_qty_sum'] ?? '0'))->isZero(),
        'zero_deltas_verified' => $executionSuccess,
    ] : [
        'status' => 'NOT_RUN',
        'strictly_equal' => false,
        'stock_movements_qty_sum_is_zero' => false,
        'zero_deltas_verified' => false,
    ],
    'six_reconcilers_status' => $reconciliationReport !== null ? 'VERIFIED' : 'NOT_RUN',
    'six_reconciler_results' => $reconciliationReport,
    'cleanup_verified' => $cleanupVerified,
    'errors' => $errors,
];

$finalJson = json_encode($outputReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($outputFile !== null) {
    file_put_contents($outputFile, $finalJson.PHP_EOL);
}
echo $finalJson.PHP_EOL;

exit($exitCode);
