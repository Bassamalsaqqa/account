<?php

declare(strict_types=1);

use App\Actions\Inventory\PostOpeningStockAction;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException as InventoryIdempotencyConflictException;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\Exceptions\InventorySecurityException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Posting\Exceptions\IdempotencyConflictException as PostingIdempotencyConflictException;
use App\Exceptions\IdempotencyConflictException as AppIdempotencyConflictException;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// Refuse unowned schemas before querying fixtures or invoking canonical Actions.
$settings = DisposableMariaDbSchema::testSettings();
if (getenv('DB_HOST') !== $settings['HOST'] || getenv('DB_PORT') !== $settings['PORT']) {
    throw new RuntimeException('Stock event race database destination refused before bootstrap.');
}
$cachePath = getenv('APP_CONFIG_CACHE') ?: dirname(__DIR__, 3).'/bootstrap/cache/config.php';
if (is_file($cachePath)) {
    throw new RuntimeException('Stock event race cached configuration refused before bootstrap.');
}
DisposableMariaDbSchema::assertPrimarySchema((string) getenv('DB_DATABASE'));

$payloadPath = $argv[1] ?? '';
if (! is_file($payloadPath) || filesize($payloadPath) > 65536) {
    throw new RuntimeException('Stock event race payload refused.');
}
$payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
$directory = realpath(dirname($payloadPath));
if ($directory === false || ! preg_match('/^stock-event-race-[a-f0-9]{16}$/D', basename($directory))) {
    throw new RuntimeException('Stock event race directory refused.');
}
foreach (['ready', 'attempting', 'release'] as $key) {
    if (! is_string($payload[$key] ?? null) || dirname($payload[$key]) !== $directory
        || ! preg_match('/^(?:ready-[01]|attempting-[01]|release)$/D', basename($payload[$key]))) {
        throw new RuntimeException('Stock event race barrier refused.');
    }
}
if (($payload['operation'] ?? null) !== 'opening_stock') {
    throw new RuntimeException('Stock event race operation refused.');
}

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());

// Authenticate and warm current grants before the parent changes them at the lock boundary.
$company = Company::findOrFail($payload['company_id']);
$actor = User::findOrFail($payload['actor_id']);
auth()->login($actor);
app(CompanyContext::class)->setCompany($company, $actor);
setPermissionsTeamId($company->id);
$actor->load('roles.permissions', 'permissions');
$product = Product::where('company_id', $company->id)->findOrFail($payload['product_id']);
$warehouse = Warehouse::where('company_id', $company->id)->findOrFail($payload['warehouse_id']);

// The action itself must wait on the parent-held company lock and refresh authorization.
file_put_contents($payload['ready'], 'ready');

$deadline = microtime(true) + 20;
while (! is_file($payload['release'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Stock event race barrier timed out.');
    }
    usleep(10000);
}
file_put_contents($payload['attempting'], 'attempting');

try {
    $quantity = Quantity::of($payload['quantity']);
    $unitCost = (string) $payload['unit_cost'];
    $idempotencyKey = (string) $payload['idempotency_key'];
    $unitId = isset($payload['unit_id']) ? (int) $payload['unit_id'] : null;

    $result = app(PostOpeningStockAction::class)->execute(
        company: $company,
        product: $product,
        warehouse: $warehouse,
        quantity: $quantity,
        unitCostBase: $unitCost,
        user: $actor,
        idempotencyKey: $idempotencyKey,
        unitId: $unitId,
    );

    echo json_encode([
        'status' => 'ok',
        'movement_id' => $result['movement']->id,
        'batch_id' => $result['batch']?->id,
    ], JSON_THROW_ON_ERROR);
} catch (InventoryIdempotencyConflictException|PostingIdempotencyConflictException|AppIdempotencyConflictException) {
    echo json_encode(['status' => 'idempotency_conflict'], JSON_THROW_ON_ERROR);
} catch (AuthorizationException|InvalidInventoryMovementException|InventorySecurityException) {
    echo json_encode(['status' => 'denied'], JSON_THROW_ON_ERROR);
}
