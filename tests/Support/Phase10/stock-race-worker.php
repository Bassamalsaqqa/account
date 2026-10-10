<?php

declare(strict_types=1);

use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Exceptions\IdempotencyConflictException;
use App\Models\Company;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// Refuse unowned schemas before querying fixtures or invoking canonical Actions.
DisposableMariaDbSchema::testSettings();
DisposableMariaDbSchema::assertPrimarySchema((string) getenv('DB_DATABASE'));
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
$payloadPath = $argv[1] ?? '';
if (! is_file($payloadPath) || filesize($payloadPath) > 4096) {
    throw new RuntimeException('Stock race payload refused.');
}
$payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
$directory = realpath(dirname($payloadPath));
if ($directory === false || ! preg_match('/^phase10-stock-race-[a-f0-9]{16}$/D', basename($directory))) {
    throw new RuntimeException('Stock race directory refused.');
}
foreach (['ready', 'attempting', 'release'] as $key) {
    if (! is_string($payload[$key] ?? null) || dirname($payload[$key]) !== $directory
        || ! preg_match('/^(?:ready-[01]|attempting-[01]|release)$/D', basename($payload[$key]))) {
        throw new RuntimeException('Stock race barrier refused.');
    }
}
if (! in_array($payload['operation'] ?? null, ['sale', 'purchase', 'receipt'], true)) {
    throw new RuntimeException('Stock race operation refused.');
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
        throw new RuntimeException('Stock race barrier timed out.');
    }
    usleep(10000);
}
file_put_contents($payload['attempting'], 'attempting');
try {
    if ($payload['operation'] === 'sale') {
        $document = SalesInvoice::where('company_id', $company->id)->findOrFail($payload['document_id']);
        $posted = app(PostSalesInvoiceAction::class)->execute($document, $actor);
        $number = $posted->invoice_number;
    } elseif ($payload['operation'] === 'purchase') {
        $document = Purchase::where('company_id', $company->id)->findOrFail($payload['document_id']);
        $posted = app(PostPurchaseAction::class)->execute($document, $actor);
        $number = $posted->purchase_number;
    } else {
        $posted = app(PostCustomerPaymentAction::class)->execute($company, $actor, [
            'customer_id' => $payload['customer_id'], 'money_account_id' => $payload['money_account_id'],
            'payment_date' => '2026-10-03', 'payment_method' => 'cash', 'amount' => $payload['amount'],
            'exchange_rate' => '1', 'idempotency_key' => $payload['key'],
            'allocations' => [['sales_invoice_id' => $payload['document_id'], 'allocated_amount' => $payload['amount']]],
        ]);
        $number = $posted->payment_number;
    }
    echo json_encode(['status' => 'ok', 'id' => $posted->id, 'posting_batch_id' => $posted->posting_batch_id,
        'number' => $number], JSON_THROW_ON_ERROR);
} catch (InsufficientStockException) {
    // A business outcome, not a credential-bearing exception or private payload.
    echo json_encode(['status' => 'insufficient_stock', 'id' => $payload['document_id']], JSON_THROW_ON_ERROR);
} catch (IdempotencyConflictException) {
    echo json_encode(['status' => 'idempotency_conflict'], JSON_THROW_ON_ERROR);
}
