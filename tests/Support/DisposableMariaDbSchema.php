<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Test-only owner of a freshly created schema. Never adopts or resets an existing schema. */
final class DisposableMariaDbSchema
{
    private const string REGEX = '/^accounting_p8_tmp_[a-f0-9]{12}$/D';

    private ?PDO $adminPdo = null;

    private ?string $schemaName = null;

    private bool $owned = false;

    private ?string $proofPath = null;

    private ?string $proofNonce = null;

    private ?string $previousConnection = null;

    private ?string $connectionName = null;

    private ?CompanyContext $previousContext = null;

    private ?Authenticatable $previousActor = null;

    private mixed $previousTeamId = null;

    /** @var list<string> */
    private array $tempFiles = [];

    /** @var array<string,string> */
    private array $settings = [];

    public function __destruct()
    {
        try {
            $this->drop();
        } catch (\Throwable $e) {
            error_log('Disposable test cleanup failed: '.$e->getMessage());
        }
    }

    public static function create(): self
    {
        $instance = new self;
        $instance->settings = self::testSettings();
        $name = self::generateSafeName();
        $instance->getAdminPdo()->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $instance->schemaName = $name;
        $instance->owned = true;

        return $instance;
    }

    /** Explicit administrative test configuration is mandatory, even for a standalone runner. */
    /** @return array<string,string> */
    public static function testSettings(): array
    {
        $booted = function_exists('app') && app()->bound('env');
        if (($booted && ! app()->environment('testing')) || getenv('APP_ENV') !== 'testing') {
            throw new RuntimeException('DisposableMariaDbSchema refused: APP_ENV is not testing.');
        }
        if (getenv('PHASE8_ALLOW_DISPOSABLE_DB') !== '1') {
            throw new RuntimeException('DisposableMariaDbSchema refused: PHASE8_ALLOW_DISPOSABLE_DB opt-in flag is missing or not 1.');
        }
        $settings = [];
        foreach (['HOST', 'PORT', 'USERNAME', 'PASSWORD'] as $key) {
            $value = getenv('PHASE8_TEST_DB_'.$key);
            if ($key === 'PASSWORD' && $value === false && getenv('PHASE8_TEST_DB_EMPTY_PASSWORD') === '1') {
                $value = '';
            }
            if ($value === false || ($key !== 'PASSWORD' && $value === '')) {
                throw new RuntimeException('Explicit disposable test administrative connection is required.');
            }
            $settings[$key] = $value;
        }
        if (! in_array($settings['HOST'], ['127.0.0.1', 'localhost', '::1'], true)
            || ! ctype_digit($settings['PORT']) || (int) $settings['PORT'] < 1 || (int) $settings['PORT'] > 65535) {
            throw new RuntimeException('DisposableMariaDbSchema refused: invalid/non-local test server.');
        }

        return $settings;
    }

    /** The runner passes a private ownership capability to its child processes. */
    public static function assertPrimarySchema(string $database): void
    {
        $settings = self::testSettings();
        $path = getenv('PHASE8_DISPOSABLE_DB_PROOF');
        $nonce = getenv('PHASE8_DISPOSABLE_DB_NONCE');
        if (! is_string($path) || ! is_string($nonce) || ! is_file($path) || filesize($path) > 1024) {
            throw new RuntimeException('Disposable schema ownership proof is required. Use the isolated runner.');
        }
        $proof = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($proof) || ! preg_match(self::REGEX, $database)
            || ($proof['schema'] ?? null) !== $database
            || ($proof['host'] ?? null) !== $settings['HOST']
            || ($proof['port'] ?? null) !== $settings['PORT']
            || ! is_string($proof['nonce'] ?? null) || ! hash_equals($proof['nonce'], $nonce)) {
            throw new RuntimeException('Disposable schema ownership proof does not match this database.');
        }
    }

    public static function createFromSource(string $sourceDb): self
    {
        // Compatibility with existing tests. No source data or DDL is copied.
        if (! preg_match(self::REGEX, $sourceDb)) {
            throw new RuntimeException('Refusing auxiliary fixtures from an unknown primary test schema.');
        }
        $instance = self::create();
        try {
            $instance->migrate();
        } catch (\Throwable $e) {
            $instance->drop();
            throw $e;
        }

        return $instance;
    }

    public static function generateSafeName(): string
    {
        return 'accounting_p8_tmp_'.bin2hex(random_bytes(6));
    }

    public function schemaName(): string
    {
        if (! $this->owned || $this->schemaName === null) {
            throw new RuntimeException('Cannot get schema name of unowned disposable instance.');
        }

        return $this->schemaName;
    }

    public function isOwned(): bool
    {
        return $this->owned;
    }

    /** @return array<string,string> */
    public function environment(): array
    {
        $name = $this->schemaName();
        if ($this->proofPath === null) {
            $path = tempnam(sys_get_temp_dir(), 'p8_ownership_');
            if ($path === false) {
                throw new RuntimeException('Unable to create private schema ownership proof.');
            }
            $this->tempFiles[] = $path;
            chmod($path, 0600);
            $this->proofNonce = bin2hex(random_bytes(16));
            file_put_contents($path, json_encode(['schema' => $name, 'host' => $this->settings['HOST'], 'port' => $this->settings['PORT'], 'nonce' => $this->proofNonce], JSON_THROW_ON_ERROR));
            $this->proofPath = $path;
        }

        return [
            'PHASE8_DISPOSABLE_DB_PROOF' => $this->proofPath,
            'PHASE8_DISPOSABLE_DB_NONCE' => $this->proofNonce,
            'APP_ENV' => 'testing', 'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
            'DB_URL' => '', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $this->settings['HOST'],
            'DB_PORT' => $this->settings['PORT'], 'DB_USERNAME' => $this->settings['USERNAME'],
            'DB_PASSWORD' => $this->settings['PASSWORD'], 'DB_DATABASE' => $name,
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'APP_CONFIG_CACHE' => sys_get_temp_dir().DIRECTORY_SEPARATOR.$name.'_nonexistent_config.php',
        ];
    }

    public function migrate(): void
    {
        $process = new Process([PHP_BINARY, 'artisan', 'migrate', '--database=mysql', '--force'], dirname(__DIR__, 2), $this->environment());
        $process->setTimeout(120)->mustRun();
        // Canonical catalogue provisioning, not a copy of uncommitted parent records.
        $this->runApplicationCode('app(App\\Services\\Tenancy\\CompanyRoleService::class)->ensurePermissionsExist();');
    }

    /** Uses a distinct named connection; parent test transaction/PDO is never purged or disconnected. */
    public function switchLaravelConnection(): void
    {
        if ($this->previousConnection !== null) {
            throw new RuntimeException('Disposable connection already selected.');
        }
        $name = $this->schemaName();
        $this->previousContext = clone app(CompanyContext::class);
        $this->previousActor = auth()->user();
        $this->previousTeamId = getPermissionsTeamId();
        $this->previousConnection = DB::getDefaultConnection();
        $this->connectionName = $name;
        $config = config('database.connections.mysql');
        $config = array_replace($config, [
            'url' => null, 'host' => $this->settings['HOST'], 'port' => $this->settings['PORT'],
            'username' => $this->settings['USERNAME'], 'password' => $this->settings['PASSWORD'], 'database' => $name,
        ]);
        config(['database.connections.'.$name => $config]);
        DB::setDefaultConnection($name);
    }

    public function restoreLaravelConnection(): void
    {
        if ($this->previousConnection !== null) {
            DB::setDefaultConnection($this->previousConnection);
            DB::purge($this->connectionName);
            config(['database.connections.'.$this->connectionName => null]);
            $this->previousConnection = $this->connectionName = null;
            app()->instance(CompanyContext::class, $this->previousContext);
            if ($this->previousActor !== null) {
                auth()->setUser($this->previousActor);
            } else {
                auth()->forgetUser();
            }
            setPermissionsTeamId($this->previousTeamId);
            $this->previousContext = null;
            $this->previousActor = null;
        }
    }

    public function createSeparatePdo(): PDO
    {
        $name = $this->schemaName();

        return new PDO($this->dsn($name), $this->settings['USERNAME'], $this->settings['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @param array<string,mixed> $invoicePayload */
    public function runConcurrentCanonicalPost(array $invoicePayload): void
    {
        $payload = var_export($invoicePayload, true);
        $code = '$payload = '.$payload.';
        $company = App\Models\Company::findOrFail($payload["company_id"]);
        $owner = App\Models\User::findOrFail($payload["owner_id"]);
        auth()->login($owner);
        app(App\Support\Tenancy\CompanyContext::class)->setCompany($company, $owner);
        setPermissionsTeamId($company->id);
        $draft = app(App\Actions\Sales\CreateSalesInvoiceDraftAction::class)->execute($company, $owner, [
            "customer_id" => $payload["customer_id"], "currency_code" => "ILS", "exchange_rate" => "1",
            "issue_date" => $payload["issue_date"], "lines" => [["product_id" => $payload["product_id"] ?? null,
            "item_description" => $payload["item_description"] ?? "Concurrent Item", "quantity" => $payload["quantity"], "unit_price" => $payload["unit_price"]]]]);
        app(App\Actions\Sales\PostSalesInvoiceAction::class)->execute($draft, $owner);
        echo "CANONICAL_POST_OK";';
        $output = $this->runApplicationCode($code);
        if (! str_contains($output, 'CANONICAL_POST_OK')) {
            throw new RuntimeException('Canonical concurrent post did not complete.');
        }
    }

    private function runApplicationCode(string $code): string
    {
        $root = var_export(dirname(__DIR__, 2), true);
        $name = var_export($this->schemaName(), true);
        $path = tempnam(sys_get_temp_dir(), 'p8_child_');
        if ($path === false) {
            throw new RuntimeException('Unable to create private test script.');
        }
        $this->tempFiles[] = $path;
        chmod($path, 0600);
        file_put_contents($path, '<?php require '.$root.'."/vendor/autoload.php";
        Tests\Support\DisposableMariaDbSchema::testSettings();
        $app = require '.$root.'."/bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (!app()->environment("testing") || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== '.$name.') { throw new RuntimeException("Child test isolation refused."); }
        '.$code);
        try {
            $process = new Process([PHP_BINARY, $path], dirname(__DIR__, 2), $this->environment());
            $process->setTimeout(30)->mustRun();

            return $process->getOutput();
        } finally {
            unlink($path);
        }
    }

    public function getAdminPdo(): PDO
    {
        if ($this->settings === []) {
            throw new RuntimeException('Unconfigured disposable administrative connection.');
        }
        if ($this->adminPdo === null) {
            $this->adminPdo = new PDO($this->dsn(), $this->settings['USERNAME'], $this->settings['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }

        return $this->adminPdo;
    }

    private function dsn(?string $database = null): string
    {
        return 'mysql:host='.$this->settings['HOST'].';port='.$this->settings['PORT'].';charset=utf8mb4'.($database === null ? '' : ';dbname='.$database);
    }

    public function drop(): void
    {
        if (! $this->owned || $this->schemaName === null) {
            return;
        }
        $this->restoreLaravelConnection();
        $name = $this->schemaName;
        if (! preg_match(self::REGEX, $name)) {
            throw new RuntimeException('Refusing unowned schema cleanup.');
        }
        try {
            $this->getAdminPdo()->exec("DROP DATABASE `{$name}`");
            $this->owned = false;
            $this->schemaName = null;
        } finally {
            foreach ($this->tempFiles as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $this->tempFiles = [];
        }
    }
}
