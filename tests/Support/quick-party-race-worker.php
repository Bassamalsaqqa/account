<?php

declare(strict_types=1);

use App\Exceptions\IdempotencyConflictException;
use App\Models\Company;
use App\Models\User;
use App\Services\Sales\QuickPartyCreation;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
DisposableMariaDbSchema::testSettings();
DisposableMariaDbSchema::assertPrimarySchema((string) getenv('DB_DATABASE'));
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());

$payloadPath = $argv[1] ?? '';
if (! is_file($payloadPath) || filesize($payloadPath) > 8192) {
    throw new RuntimeException('Party race payload refused.');
}
$payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
$directory = realpath(dirname($payloadPath));
if ($directory === false || ! preg_match('/^quick-party-race-[a-f0-9]{16}$/D', basename($directory))) {
    throw new RuntimeException('Party race directory refused.');
}
foreach (['ready', 'attempting', 'release'] as $key) {
    if (! is_string($payload[$key] ?? null) || dirname($payload[$key]) !== $directory
        || ! preg_match('/^(?:ready-[01]|attempting-[01]|release)$/D', basename($payload[$key]))) {
        throw new RuntimeException('Party race barrier refused.');
    }
}

$company = Company::findOrFail($payload['company_id']);
$actor = User::findOrFail($payload['actor_id']);
auth()->login($actor);
app(CompanyContext::class)->setCompany($company, $actor);
setPermissionsTeamId($company->id);
file_put_contents($payload['ready'], 'ready');
$deadline = microtime(true) + 20;
while (! is_file($payload['release'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Party race barrier timed out.');
    }
    usleep(10000);
}
file_put_contents($payload['attempting'], 'attempting');
try {
    $product = app(QuickPartyCreation::class)->execute($company, $actor, $payload['kind'], $payload['key'], $payload['data'], $payload['kind'] === 'customer' ? 'sales.invoice.create' : 'purchasing.purchase.create');
    echo json_encode(['status' => 'ok', 'id' => $product->id], JSON_THROW_ON_ERROR);
} catch (IdempotencyConflictException) {
    echo json_encode(['status' => 'conflict'], JSON_THROW_ON_ERROR);
}
