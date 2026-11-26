<?php

declare(strict_types=1);

use App\Application\Diagnostics\SubmitBugReport;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 3).'/vendor/autoload.php';

DisposableMariaDbSchema::testSettings();
DisposableMariaDbSchema::assertPrimarySchema((string) getenv('DB_DATABASE'));
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());

$payloadPath = $argv[1] ?? '';
if (! is_string($payloadPath) || ! is_file($payloadPath) || filesize($payloadPath) > 8192) {
    throw new RuntimeException('Bug report race payload refused.');
}

/** @var array<string, mixed> $payload */
$payload = json_decode((string) file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
$directory = realpath(dirname($payloadPath));
if ($directory === false || ! preg_match('/^phase-diag-race-[a-f0-9]{16}$/D', basename($directory))) {
    throw new RuntimeException('Bug report race directory refused.');
}

foreach (['ready', 'attempting', 'release'] as $key) {
    if (! is_string($payload[$key] ?? null)
        || dirname($payload[$key]) !== $directory
        || ! preg_match('/^(?:ready-[01]|attempting-[01]|release)$/D', basename($payload[$key]))) {
        throw new RuntimeException('Bug report race barrier refused.');
    }
}

$company = Company::query()->findOrFail($payload['company_id']);
$actor = User::query()->findOrFail($payload['actor_id']);

auth()->login($actor);
app(CompanyContext::class)->setCompany($company, $actor);

file_put_contents($payload['ready'], 'ready');

$deadline = microtime(true) + 20;
while (! is_file($payload['release'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Bug report race barrier timed out.');
    }
    usleep(10000);
}

file_put_contents($payload['attempting'], 'attempting');

// Wait together behind the parent transaction lock on companies so both workers enter SubmitBugReport together.
DB::transaction(static function () use ($company): void {
    DB::table('companies')->where('id', $company->id)->lockForUpdate()->first();
});

$request = Request::create(
    '/diagnostics/bug-reports',
    'POST',
    server: ['REMOTE_ADDR' => '127.0.0.1'],
);
$request->setUserResolver(static fn (): User => $actor);

try {
    /** @var array<string, mixed> $reportPayload */
    $reportPayload = $payload['report_payload'];
    $result = app(SubmitBugReport::class)->execute($request, $reportPayload);

    echo json_encode([
        'status' => 'ok',
        'id' => $result['report']->id,
        'public_ref' => $result['report']->public_ref,
        'replay' => $result['replay'],
        'note' => $result['report']->note,
    ], JSON_THROW_ON_ERROR);
} catch (ConflictHttpException $e) {
    echo json_encode([
        'status' => 'conflict',
        'code' => $e->getStatusCode(),
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
