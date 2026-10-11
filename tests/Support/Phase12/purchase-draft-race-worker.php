<?php

declare(strict_types=1);

use App\Application\AI\PurchaseDraftCommands;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// Refuse unowned schemas before querying fixtures or invoking canonical Commands/Actions.
$settings = DisposableMariaDbSchema::testSettings();
if (getenv('DB_HOST') !== $settings['HOST'] || getenv('DB_PORT') !== $settings['PORT']) {
    throw new RuntimeException('Purchase race database destination refused before bootstrap.');
}
$cachePath = getenv('APP_CONFIG_CACHE') ?: dirname(__DIR__, 3).'/bootstrap/cache/config.php';
if (is_file($cachePath)) {
    throw new RuntimeException('Purchase race cached configuration refused before bootstrap.');
}
DisposableMariaDbSchema::assertPrimarySchema((string) getenv('DB_DATABASE'));

$payloadPath = $argv[1] ?? '';
if (! is_file($payloadPath) || filesize($payloadPath) > 65536) {
    throw new RuntimeException('Purchase race payload refused.');
}
$payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
$directory = realpath(dirname($payloadPath));
if ($directory === false || ! preg_match('/^phase12-purchase-draft-race-[a-f0-9]{16}$/D', basename($directory))) {
    throw new RuntimeException('Purchase race directory refused.');
}
foreach (['ready', 'attempting', 'release'] as $key) {
    if (! is_string($payload[$key] ?? null) || dirname($payload[$key]) !== $directory
        || ! preg_match('/^(?:ready-[01]|attempting-[01]|release)$/D', basename($payload[$key]))) {
        throw new RuntimeException('Purchase race barrier refused.');
    }
}
if (($payload['operation'] ?? null) !== 'create') {
    throw new RuntimeException('Purchase race operation refused.');
}

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());

$company = Company::findOrFail($payload['company_id']);
$actor = User::findOrFail($payload['actor_id']);
auth()->login($actor);
app(CompanyContext::class)->setCompany($company, $actor);
setPermissionsTeamId($company->id);
file_put_contents($payload['ready'], 'ready');

$deadline = microtime(true) + 20;
while (! is_file($payload['release'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Purchase race barrier timed out.');
    }
    usleep(10000);
}
file_put_contents($payload['attempting'], 'attempting');

try {
    $purchase = app(PurchaseDraftCommands::class)->create(
        $company,
        $actor,
        $payload['command_payload'],
        $payload['form_key'],
    );
    echo json_encode([
        'status' => 'ok',
        'id' => $purchase->id,
        'status_name' => $purchase->status,
    ], JSON_THROW_ON_ERROR);
} catch (HttpException $e) {
    if ($e->getStatusCode() === 409) {
        echo json_encode(['status' => 'conflict', 'http_code' => 409], JSON_THROW_ON_ERROR);
    } else {
        throw $e;
    }
} catch (AuthorizationException) {
    echo json_encode(['status' => 'unauthorized'], JSON_THROW_ON_ERROR);

}
