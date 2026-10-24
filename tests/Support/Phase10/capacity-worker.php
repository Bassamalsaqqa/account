<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// Refuse missing/mismatched ownership before application bootstrap or any query.
DisposableMariaDbSchema::testSettings();
DisposableMariaDbSchema::assertPrimarySchema((string) getenv('DB_DATABASE'));
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
$payloadPath = $argv[1] ?? '';
if (! is_file($payloadPath) || filesize($payloadPath) > 4096) {
    throw new RuntimeException('Capacity worker payload refused.');
}
$payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
$directory = realpath(dirname($payloadPath));
if ($directory === false || ! preg_match('/^phase10-capacity-[a-f0-9]{16}$/D', basename($directory))) {
    throw new RuntimeException('Capacity worker directory refused.');
}
foreach (['ready', 'release'] as $key) {
    if (! is_string($payload[$key] ?? null) || dirname($payload[$key]) !== $directory
        || ! preg_match('/^(?:ready-[0-3]|release)$/D', basename($payload[$key]))) {
        throw new RuntimeException('Capacity barrier refused.');
    }
}
if (! is_string($payload['invoice_public_id'] ?? null) || ! preg_match('/^[A-Z0-9]{26}$/D', $payload['invoice_public_id'])) {
    throw new RuntimeException('Capacity invoice identity refused.');
}
$company = Company::findOrFail($payload['company_id']);
$owner = User::findOrFail($payload['actor_id']);
auth()->login($owner);
app(CompanyContext::class)->setCompany($company, $owner);
session(['active_company_id' => $company->id, 'locale' => 'en']);
setPermissionsTeamId($company->id);
Carbon::setTestNow('2026-10-20 12:00:00');
$queries = 0;
DB::listen(static function () use (&$queries): void {
    $queries++;
});
$kernel = $app->make(HttpKernel::class);
file_put_contents($payload['ready'], 'ready');
$deadline = microtime(true) + 60;
while (! is_file($payload['release'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Capacity barrier timed out.');
    }
    usleep(10000);
}
$cpuBefore = PHP_OS_FAMILY !== 'Windows' && function_exists('getrusage') ? getrusage() : null;
$workloadStarted = microtime(true);
$workloadClock = hrtime(true);
$operations = [];
try {
    $reportQuery = http_build_query(['filters' => ['preset' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31']]);
    foreach (['dashboard' => '/dashboard', 'sales_report' => '/reports/sales.summary?'.$reportQuery,
        'invoice_pdf' => '/pdf/invoice/'.$payload['invoice_public_id'].'?locale=en'] as $name => $uri) {
        $queryStart = $queries;
        $started = microtime(true);
        $clock = hrtime(true);
        $request = Request::create('http://127.0.0.1'.$uri, 'GET', server: ['HTTP_ACCEPT' => 'text/html']);
        $response = $kernel->handle($request);
        $content = (string) $response->getContent();
        $kernel->terminate($request, $response);
        $ended = microtime(true);
        $isPdf = str_starts_with($content, '%PDF-');
        $isHtml = str_contains(strtolower(substr(ltrim($content), 0, 256)), '<html')
            || str_starts_with(strtolower(ltrim($content)), '<!doctype html');
        $operation = ['status' => $response->getStatusCode(), 'signature' => $isPdf ? 'PDF' : ($isHtml ? 'HTML' : 'OTHER'),
            'response_bytes' => strlen($content), 'response_sha256' => hash('sha256', $content),
            'started_unix' => $started, 'ended_unix' => $ended, 'wall_ms' => round((hrtime(true) - $clock) / 1_000_000, 3),
            'query_count' => $queries - $queryStart, 'process_peak_memory_bytes' => memory_get_peak_usage(true)];
        if ($response->getStatusCode() !== 200) {
            $context = app(CompanyContext::class);
            $user = auth()->user();
            $denialReason = 'none';
            $exceptionClass = null;
            if (property_exists($response, 'exception') && $response->exception instanceof Throwable) {
                $exceptionClass = $response->exception::class;
                $denialReason = 'unknown';
                $current = $response->exception;
                $depth = 0;
                while ($current !== null && $depth < 4) {
                    $msg = $current->getMessage();
                    if ($msg === 'Dashboard authority changed during report retrieval.') {
                        $denialReason = 'authority_changed';
                        break;
                    }
                    if ($msg === 'Active matching company membership is required for reports.') {
                        $denialReason = 'membership';
                        break;
                    }
                    if ($msg === 'The required report/source permission is missing.') {
                        $denialReason = 'permission';
                        break;
                    }
                    if ($msg === 'Matching company actor required.') {
                        $denialReason = 'actor';
                        break;
                    }
                    $current = $current->getPrevious();
                    $depth++;
                }
            }
            $operation['diagnostics'] = [
                'auth_check' => auth()->check(),
                'auth_id_matches' => ($user !== null && (int) $user->id === (int) $owner->id),
                'context_has_company' => $context->hasCompany(),
                'context_company_id_matches' => ($context->hasCompany() && (int) $context->companyId() === (int) $company->id),
                'team_id_matches' => ((int) getPermissionsTeamId() === (int) $company->id),
                'session_company_id_matches' => ((int) session('active_company_id') === (int) $company->id),
                'can_sales_view' => ($user !== null && $user->can('reports.sales.view')),
                'exception_class' => $exceptionClass,
                'denial_reason' => $denialReason,
            ];
        }
        $operations[$name] = $operation;
        unset($content, $response, $request, $operation);
    }
    $cpuAfter = $cpuBefore !== null ? getrusage() : null;
    $cpu = null;
    if (is_array($cpuBefore) && is_array($cpuAfter)) {
        $cpu = 0;
        foreach (['ru_utime', 'ru_stime'] as $field) {
            $cpu += (($cpuAfter[$field.'.tv_sec'] - $cpuBefore[$field.'.tv_sec']) * 1_000_000)
                + $cpuAfter[$field.'.tv_usec'] - $cpuBefore[$field.'.tv_usec'];
        }
    }
    echo json_encode(['status' => 'measured', 'pid' => getmypid(), 'started_unix' => $workloadStarted,
        'ended_unix' => microtime(true), 'wall_ms' => round((hrtime(true) - $workloadClock) / 1_000_000, 3),
        'query_count' => $queries, 'process_peak_memory_bytes' => memory_get_peak_usage(true),
        'cpu_microseconds' => $cpu, 'cpu_status' => $cpu === null ? 'NOT VERIFIED (unsupported Windows getrusage)' : 'MEASURED',
        'operations' => $operations], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    // Never serialize response bodies, credentials, SQL bindings or private exception messages.
    echo json_encode(['status' => 'measurement_failed', 'exception_class' => $exception::class], JSON_THROW_ON_ERROR);
    exit(1);
} finally {
    Carbon::setTestNow();
}
