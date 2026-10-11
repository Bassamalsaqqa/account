<?php

declare(strict_types=1);

use App\Application\AI\AssistantBudget;
use App\Application\AI\AssistantUnavailable;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 3).'/vendor/autoload.php';
DisposableMariaDbSchema::testSettings();
DisposableMariaDbSchema::assertPrimarySchema((string) getenv('DB_DATABASE'));
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
$path = $argv[1] ?? '';
$directory = realpath(dirname($path));
if ($directory === false || ! preg_match('/\Aphase12-budget-race-[a-f0-9]{16}\z/', basename($directory))
    || ! is_file($path) || filesize($path) > 4096) {
    throw new RuntimeException('Owned race input required.');
}
$payload = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
foreach (['ready', 'attempting', 'release'] as $field) {
    if (! is_string($payload[$field] ?? null) || dirname($payload[$field]) !== $directory
        || ! preg_match('/\A(?:ready-[01]|attempting-[01]|release)\z/', basename($payload[$field]))) {
        throw new RuntimeException('Owned race barrier required.');
    }
}
config([
    'ai_assistant.enabled' => true, 'ai_assistant.provider_approved' => true, 'ai_assistant.provider' => 'gateway',
    'ai_assistant.request_cost_ceiling_micros' => 1000, 'ai_assistant.daily_company_cost_ceiling_micros' => 1000,
    'ai_assistant.monthly_company_cost_ceiling_micros' => 1000, 'ai_assistant.daily_user_cost_ceiling_micros' => 1000,
    'ai_assistant.monthly_user_cost_ceiling_micros' => 1000,
]);
$company = Company::findOrFail($payload['company_id']);
$actor = User::findOrFail($payload['actor_id']);
auth()->login($actor);
app(CompanyContext::class)->setCompany($company, $actor);
file_put_contents($payload['ready'], 'ready');
$deadline = microtime(true) + 20;
while (! is_file($payload['release'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Race barrier timeout.');
    }
    usleep(10000);
}
file_put_contents($payload['attempting'], 'attempting');
try {
    $request = app(AssistantBudget::class)->reserve($company, $actor, $payload['key'], 'intent', $payload['hash']);
    echo json_encode(['status' => 'ok', 'id' => (int) $request->id, 'created' => $request->wasRecentlyCreated], JSON_THROW_ON_ERROR);
} catch (AssistantUnavailable $e) {
    echo json_encode(['status' => $e->reason], JSON_THROW_ON_ERROR);
}
