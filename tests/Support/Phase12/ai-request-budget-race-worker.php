<?php

declare(strict_types=1);

use App\Application\AI\AiRequestBudget;
use App\Application\AI\AssistantUnavailable;
use App\Models\AiProviderConnection;
use App\Models\AssistantRequest;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// Verify environment before bootstrap.
if (getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('AI budget race refused: APP_ENV is not testing.');
}

$settings = DisposableMariaDbSchema::testSettings();
if (getenv('DB_HOST') !== $settings['HOST'] || getenv('DB_PORT') !== $settings['PORT']) {
    throw new RuntimeException('AI budget race database destination refused before bootstrap.');
}

$cachePath = getenv('APP_CONFIG_CACHE') ?: dirname(__DIR__, 3).'/bootstrap/cache/config.php';
if (is_file($cachePath)) {
    throw new RuntimeException('AI budget race cached configuration refused before bootstrap.');
}

DisposableMariaDbSchema::assertPrimarySchema((string) getenv('DB_DATABASE'));

// Validate payload file and directory before bootstrap.
$payloadPath = $argv[1] ?? '';
if (! is_file($payloadPath) || filesize($payloadPath) > 65536) {
    throw new RuntimeException('AI budget race payload refused.');
}

$payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
$directory = realpath(dirname($payloadPath));
if ($directory === false || ! preg_match('/^phase12-ai-budget-race-[a-f0-9]{16}$/D', basename($directory))) {
    throw new RuntimeException('AI budget race directory refused.');
}

foreach (['ready', 'attempting', 'release'] as $key) {
    if (! is_string($payload[$key] ?? null) || dirname($payload[$key]) !== $directory
        || ! preg_match('/^(?:ready-[01]|attempting-[01]|release)$/D', basename($payload[$key]))) {
        throw new RuntimeException('AI budget race barrier refused.');
    }
}

if (! in_array($payload['operation'] ?? null, ['reserve', 'begin_attempt'], true)) {
    throw new RuntimeException('AI budget race operation refused.');
}

// Bootstrap Laravel.
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());

// In-memory safety and runtime overrides.
Http::preventStrayRequests();

config([
    'ai_assistant.enabled' => true,
    'ai_assistant.provider_approved' => true,
    'ai_assistant.provider' => 'direct',
    'ai_assistant.speech_enabled' => true,
    'ai_assistant.request_cost_ceiling_micros' => 1000,
    'ai_assistant.daily_company_cost_ceiling_micros' => 100000,
    'ai_assistant.monthly_company_cost_ceiling_micros' => 1000000,
    'ai_assistant.daily_user_cost_ceiling_micros' => 100000,
    'ai_assistant.monthly_user_cost_ceiling_micros' => 1000000,
]);

if (isset($payload['frozen_time']) && is_string($payload['frozen_time'])) {
    Carbon::setTestNow(Carbon::parse($payload['frozen_time']));
}

if (isset($payload['config_overrides']) && is_array($payload['config_overrides'])) {
    foreach ($payload['config_overrides'] as $cfgKey => $cfgVal) {
        if (! str_starts_with($cfgKey, 'ai_assistant.')) {
            throw new RuntimeException('Only ai_assistant configuration overrides are permitted in child.');
        }
    }
    config($payload['config_overrides']);
}

// Tenant context and actor setup.
$company = Company::findOrFail($payload['company_id']);
$actor = User::findOrFail($payload['actor_id']);
auth()->login($actor);
app(CompanyContext::class)->setCompany($company, $actor);
setPermissionsTeamId($company->id);

// Signal ready and wait for release.
file_put_contents($payload['ready'], 'ready');

$deadline = microtime(true) + 20;
while (! is_file($payload['release'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('AI budget race barrier timed out.');
    }
    usleep(10000);
}
file_put_contents($payload['attempting'], 'attempting');

// Execute operation.
try {
    if ($payload['operation'] === 'reserve') {
        $connections = [];
        if (! empty($payload['connection_ids'])) {
            foreach ($payload['connection_ids'] as $connId) {
                $connections[] = AiProviderConnection::findOrFail($connId);
            }
        }

        $result = app(AiRequestBudget::class)->reserve(
            $company,
            $actor,
            $payload['request_key'],
            $payload['form_key'],
            $payload['purpose'],
            $payload['intent'],
            $payload['input_hash'],
            $connections,
            $payload['audio_milliseconds'] ?? 60000,
        );

        echo json_encode([
            'status' => 'ok',
            'created' => $result['created'],
            'request_id' => $result['request']->id,
            'request_key' => $result['request']->request_key,
            'reserved_cost_micros' => $result['request']->reserved_cost_micros,
            'cost_status' => $result['request']->cost_status,
            'attempt_count' => $result['request']->attempt_count,
            'status_name' => $result['request']->status,
            'input_hash' => $result['request']->input_hash,
            'form_key' => $result['request']->form_key,
            'purpose' => $result['request']->purpose,
            'intent_kind' => $result['request']->intent_kind,
        ], JSON_THROW_ON_ERROR);
    } elseif ($payload['operation'] === 'begin_attempt') {
        $request = AssistantRequest::findOrFail($payload['request_id']);
        $connection = AiProviderConnection::findOrFail($payload['connection_id']);

        app(AiRequestBudget::class)->beginAttempt($company, $actor, $request, $connection);
        $fresh = $request->fresh();

        echo json_encode([
            'status' => 'ok',
            'request_id' => $fresh->id,
            'attempt_count' => $fresh->attempt_count,
            'reserved_cost_micros' => $fresh->reserved_cost_micros,
            'cost_status' => $fresh->cost_status,
        ], JSON_THROW_ON_ERROR);
    }
} catch (AssistantUnavailable $e) {
    echo json_encode([
        'status' => 'assistant_unavailable',
        'reason' => $e->reason,
    ], JSON_THROW_ON_ERROR);
} catch (AuthorizationException $e) {
    echo json_encode([
        'status' => 'unauthorized',
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
} catch (ModelNotFoundException $e) {
    echo json_encode([
        'status' => 'not_found',
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
