<?php

declare(strict_types=1);

use App\Application\AI\AssistantReportExecutor;
use App\Application\AI\AssistantReportPlan;
use App\Application\AI\AssistantUnavailable;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$settings = DisposableMariaDbSchema::testSettings();
if (getenv('DB_HOST') !== $settings['HOST'] || getenv('DB_PORT') !== $settings['PORT']) {
    throw new RuntimeException('Read worker database destination refused before bootstrap.');
}
if (is_file(getenv('APP_CONFIG_CACHE') ?: dirname(__DIR__, 3).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Read worker cached configuration refused.');
}
DisposableMariaDbSchema::assertPrimarySchema((string) getenv('DB_DATABASE'));
$path = $argv[1] ?? '';
$directory = realpath(dirname($path));
if ($directory === false || ! preg_match('/^ai-v22-read-race-[a-f0-9]{16}$/D', basename($directory))
    || ! is_file($path) || filesize($path) > 8192) {
    throw new RuntimeException('Read worker payload location refused.');
}
$payload = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
foreach (['ready', 'release'] as $key) {
    if (! is_string($payload[$key] ?? null) || dirname($payload[$key]) !== $directory
        || ! preg_match('/^(?:ready-[0-3]|release)$/D', basename($payload[$key]))) {
        throw new RuntimeException('Read worker barrier location refused.');
    }
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
config(['ai_assistant.enabled' => true, 'ai_assistant.questions.enabled' => true]);
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 10:00:00'));
Carbon::setTestNow(CarbonImmutable::now());
Http::preventStrayRequests();
$company = Company::findOrFail($payload['company_id']);
$actor = User::findOrFail($payload['actor_id']);
auth()->login($actor);
app(CompanyContext::class)->setCompany($company, $actor);
setPermissionsTeamId($company->id);
// Deliberately warm the actor's permission relations before a potential parent revocation.
$actor->getAllPermissions();
file_put_contents($payload['ready'], 'ready');
$deadline = microtime(true) + 20;
while (! is_file($payload['release'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Read worker barrier timed out.');
    }
    usleep(10000);
}
try {
    $start = hrtime(true);
    $queryCount = 0;
    DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
        $queryCount++;
    });
    $plan = AssistantReportPlan::fromArray(['version' => 1, 'type' => 'report_query',
        'report_key' => 'sales.summary', 'filters' => ['preset' => 'this_month', 'compare' => 'previous_period'],
        'display' => 'summary']);
    $result = app(AssistantReportExecutor::class)->execute($company, $actor, $plan);
    echo json_encode(['status' => 'ok', 'gross_sales_base' => $result->totals['gross_sales_base'],
        'previous_gross_sales_base' => $result->comparison['totals']['gross_sales_base'],
        'query_count' => $queryCount,
        'milliseconds' => (hrtime(true) - $start) / 1000000], JSON_THROW_ON_ERROR);
} catch (AuthorizationException) {
    echo json_encode(['status' => 'unauthorized'], JSON_THROW_ON_ERROR);
} catch (AssistantUnavailable $error) {
    echo json_encode(['status' => 'refused', 'reason' => $error->reason], JSON_THROW_ON_ERROR);
}
