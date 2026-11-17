<?php

declare(strict_types=1);

use App\Actions\Purchasing\DeletePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Models\Company;
use App\Models\Purchase;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$settings = DisposableMariaDbSchema::testSettings();
if (getenv('DB_HOST') !== $settings['HOST'] || getenv('DB_PORT') !== $settings['PORT']) {
    throw new RuntimeException('Purchase delete race destination refused.');
}
DisposableMariaDbSchema::assertPrimarySchema((string) getenv('DB_DATABASE'));
if (is_file(getenv('APP_CONFIG_CACHE') ?: dirname(__DIR__, 3).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Purchase delete race cached configuration refused.');
}
$payloadPath = $argv[1] ?? '';
if (! is_file($payloadPath) || filesize($payloadPath) > 131072) {
    throw new RuntimeException('Purchase delete race payload refused.');
}
$directory = realpath(dirname($payloadPath));
if ($directory === false || ! preg_match('/^purchase-delete-race-[a-f0-9]{16}$/D', basename($directory))) {
    throw new RuntimeException('Purchase delete race directory refused.');
}
$payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
foreach (['ready', 'attempting', 'release'] as $barrier) {
    if (! is_string($payload[$barrier] ?? null) || dirname($payload[$barrier]) !== $directory
        || ! preg_match('/^(?:ready-[01]|attempting-[01]|release-[01])$/D', basename($payload[$barrier]))) {
        throw new RuntimeException('Purchase delete race barrier refused: '.($payload[$barrier] ?? 'null'));
    }
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());

$actor = User::findOrFail($payload['actor_id']);
$company = Company::findOrFail($payload['company_id']);
auth()->login($actor);
app(CompanyContext::class)->setCompany($company, $actor);
setPermissionsTeamId($company->id);

file_put_contents($payload['ready'], (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
$deadline = microtime(true) + 20;
while (! is_file($payload['release'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Purchase delete race release timed out.');
    }
    usleep(5000);
}
file_put_contents($payload['attempting'], 'attempting');

$operation = $payload['operation'] ?? '';
$holdBeforeCommit = (bool) ($payload['hold_before_commit'] ?? false);
$lockedBarrier = $payload['locked_barrier'] ?? null;
$proceedBarrier = $payload['proceed_barrier'] ?? null;

// The pause occurs after the canonical action itself acquires its first Company lock.
// No synthetic outer transaction or Purchase-first lock changes the production lock order.
if ($holdBeforeCommit) {
    foreach (['locked_barrier' => 'locked-0', 'proceed_barrier' => 'proceed-0'] as $field => $name) {
        if (($payload[$field] ?? null) !== $directory.DIRECTORY_SEPARATOR.$name) {
            throw new RuntimeException('Purchase race held-lock barrier refused.');
        }
    }
    $held = false;
    DB::listen(function (QueryExecuted $query) use (&$held, $lockedBarrier, $proceedBarrier): void {
        if ($held || ! str_contains(strtolower($query->sql), 'from `companies`')
            || ! str_contains(strtolower($query->sql), 'for update')) {
            return;
        }
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Canonical lock hook requires an active transaction.');
        }
        $held = true;
        file_put_contents($lockedBarrier, 'locked');
        $deadline = microtime(true) + 20;
        while (! is_file($proceedBarrier)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Canonical lock release timed out.');
            }
            usleep(5000);
        }
    });
}

try {
    if ($operation === 'post') {
        $purchase = Purchase::where('company_id', $company->id)->findOrFail($payload['purchase_id']);
        $posted = app(PostPurchaseAction::class)->execute($purchase, $actor);

        echo json_encode([
            'status' => 'posted',
            'id' => $posted->id,
            'purchase_number' => $posted->purchase_number,
            'posting_batch_id' => $posted->posting_batch_id,
        ], JSON_THROW_ON_ERROR);
    } elseif ($operation === 'delete') {
        $purchase = Purchase::where('company_id', $company->id)->findOrFail($payload['purchase_id']);
        app(DeletePurchaseDraftAction::class)->execute($purchase, $actor);

        echo json_encode([
            'status' => 'deleted',
            'id' => $payload['purchase_id'],
        ], JSON_THROW_ON_ERROR);
    } else {
        throw new RuntimeException('Unknown operation: '.$operation);
    }
} catch (ValidationException $e) {
    echo json_encode([
        'status' => 'validation_blocked',
        'errors' => $e->errors(),
    ], JSON_THROW_ON_ERROR);
} catch (ModelNotFoundException) {
    echo json_encode([
        'status' => 'not_found',
    ], JSON_THROW_ON_ERROR);
} catch (AuthorizationException) {
    echo json_encode([
        'status' => 'unauthorized',
    ], JSON_THROW_ON_ERROR);
}
