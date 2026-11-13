<?php

declare(strict_types=1);

// No autoload, environment loading, application bootstrap or socket before guard.
require __DIR__.'/Guard.php';
require __DIR__.'/Fingerprint.php';

use App\Models\Company;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Config\Repository;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use ReleaseAudit\Fingerprint;
use ReleaseAudit\Guard;

umask(0077);
ini_set('display_errors', '0');
$pdo = null;
try {
    $root = dirname(__DIR__, 2);
    $c = Guard::preflight($argv, $root);
    $pdo = new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['schema'].';charset=utf8mb4', (string) $c['user'], (string) $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $identity = $pdo->query('SELECT DATABASE(), @@hostname, @@server_id')->fetch(PDO::FETCH_NUM);
    if (! is_array($identity) || count($identity) !== 3
        || $identity[0] !== $c['schema']
        || $identity[1] !== $c['server_hostname']
        || (int) $identity[2] !== (int) $c['server_id']) {
        Guard::refuse();
    }
    $before = Fingerprint::capture($pdo);
    // Minimal isolated container: no application providers, .env, sessions or auth.
    $app = new Application($root);
    $app->instance('config', new Repository(['database' => ['default' => 'audit', 'connections' => []]]));
    $app->singleton(CompanyContext::class);
    $connection = new MySqlConnection($pdo, (string) $c['schema'], '', ['name' => 'audit']);
    $manager = new DatabaseManager($app, new ConnectionFactory($app));
    $manager->extend('audit', fn () => $connection);
    $app['config']->set('database.connections.audit', ['driver' => 'audit']);
    $app->instance('db', $manager);
    Facade::setFacadeApplication($app);
    Model::setConnectionResolver($manager);
    $domains = [];
    $healthy = true;
    foreach (Company::orderBy('id')->get() as $company) {
        foreach ([
            'accounting' => AccountingReconciliationService::class,
            'inventory' => InventoryReconciliationService::class,
            'sales' => SalesReconciliationService::class,
            'payables' => PayablesReconciliationService::class,
            'money' => MoneyReconciliationService::class,
            'phase7' => Phase7ReconciliationService::class,
        ] as $domain => $class) {
            $service = $app->make($class);
            $report = $domain === 'inventory' ? $service->auditCompany($company, true) : $service->reconcile($company, true);
            $findings = $domain === 'inventory' ? $report->discrepancies : $report->violations;
            $ok = $report->isHealthy === true && $findings === [];
            $healthy = $healthy && $ok;
            $domains[] = [
                'company_id' => $company->id,
                'domain' => $domain,
                'healthy' => $ok,
                'count' => count($findings),
                'digest' => hash('sha256', serialize($findings)),
            ];
        }
    }
    $after = Fingerprint::capture($pdo);
    if ($before !== $after) {
        Guard::refuse();
    }
    $pdo->rollBack();
    $result = [
        'version' => 1,
        'healthy' => $healthy && $domains !== [],
        'scope_empty' => $domains === [],
        'code' => $argv[4],
        'executable_sha256' => hash_file('sha256', __FILE__),
        'implementation_sha256' => Guard::implementationDigest(),
        'build_manifest_sha256' => is_file($root.'/public/build/manifest.json') ? hash_file('sha256', $root.'/public/build/manifest.json') : null,
        'companies' => count($domains) === 0 ? 0 : intdiv(count($domains), 6),
        'domains' => $domains,
        'tables' => $before,
    ];
    $handle = fopen($argv[5], 'x');
    if ($handle === false) {
        Guard::refuse();
    }
    $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
    if (fwrite($handle, $json) !== strlen($json) || ! fclose($handle)) {
        Guard::refuse();
    }
    exit($healthy && $domains !== [] ? 0 : 2);
} catch (Throwable) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "AUDIT_REFUSED\n");
    exit(1);
}
