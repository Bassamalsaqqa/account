<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$settings = DisposableMariaDbSchema::testSettings();
if (getenv('DB_HOST') !== $settings['HOST'] || getenv('DB_PORT') !== $settings['PORT']) {
    throw new RuntimeException('Purchase confirmation race destination refused.');
}
DisposableMariaDbSchema::assertPrimarySchema((string) getenv('DB_DATABASE'));
if (is_file(getenv('APP_CONFIG_CACHE') ?: dirname(__DIR__, 3).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Purchase confirmation race cached configuration refused.');
}
$payloadPath = $argv[1] ?? '';
if (! is_file($payloadPath) || filesize($payloadPath) > 131072) {
    throw new RuntimeException('Purchase confirmation race payload refused.');
}
$directory = realpath(dirname($payloadPath));
if ($directory === false || ! preg_match('/^purchase-confirmation-race-[a-f0-9]{16}$/D', basename($directory))) {
    throw new RuntimeException('Purchase confirmation race directory refused.');
}
$payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
foreach (['ready', 'attempting', 'release'] as $barrier) {
    if (! is_string($payload[$barrier] ?? null) || dirname($payload[$barrier]) !== $directory
        || ! preg_match('/^(?:ready-[01]|attempting-[01]|release)$/D', basename($payload[$barrier]))) {
        throw new RuntimeException('Purchase confirmation race barrier refused.');
    }
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
request()->headers->set('X-Livewire', 'true');
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
$actor = User::findOrFail($payload['actor_id']);
$company = Company::findOrFail($payload['company_id']);
auth()->login($actor);
app(CompanyContext::class)->setCompany($company, $actor);
setPermissionsTeamId($company->id);
file_put_contents($payload['ready'], 'ready');
$deadline = microtime(true) + 20;
while (! is_file($payload['release'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Purchase confirmation race release timed out.');
    }
    usleep(10000);
}
file_put_contents($payload['attempting'], 'attempting');
[$snapshot, $effects] = app('livewire')->update($payload['snapshot'], [], [['method' => 'saveAndConfirm', 'params' => []]]);
echo json_encode(['redirect' => $effects['redirect'] ?? null, 'snapshot' => $snapshot], JSON_THROW_ON_ERROR);
