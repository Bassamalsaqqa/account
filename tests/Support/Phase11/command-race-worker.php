<?php

declare(strict_types=1);

use App\Actions\Sales\PostSalesInvoiceAction;
use App\Application\Commands\SalesInvoiceCommands;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Exceptions\IdempotencyConflictException;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// Refuse unowned schemas before querying fixtures or invoking canonical Commands/Actions.
DisposableMariaDbSchema::testSettings();
DisposableMariaDbSchema::assertPrimarySchema((string) getenv('DB_DATABASE'));
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());

$payloadPath = $argv[1] ?? '';
if (! is_file($payloadPath) || filesize($payloadPath) > 4096) {
    throw new RuntimeException('Command race payload refused.');
}
$payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
$directory = realpath(dirname($payloadPath));
if ($directory === false || ! preg_match('/^phase11-command-race-[a-f0-9]{16}$/D', basename($directory))) {
    throw new RuntimeException('Command race directory refused.');
}
foreach (['ready', 'attempting', 'release'] as $key) {
    if (! is_string($payload[$key] ?? null) || dirname($payload[$key]) !== $directory
        || ! preg_match('/^(?:ready-[01]|attempting-[01]|release)$/D', basename($payload[$key]))) {
        throw new RuntimeException('Command race barrier refused.');
    }
}
if (! in_array($payload['operation'] ?? null, ['create', 'update', 'preview', 'execute', 'sale'], true)) {
    throw new RuntimeException('Command race operation refused.');
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
        throw new RuntimeException('Command race barrier timed out.');
    }
    usleep(10000);
}
file_put_contents($payload['attempting'], 'attempting');

try {
    $commands = app(SalesInvoiceCommands::class);

    if ($payload['operation'] === 'create') {
        $invoice = $commands->create(
            $company,
            $actor,
            $payload['command_payload'],
            $payload['key']
        );
        echo json_encode([
            'status' => 'ok',
            'id' => $invoice->id,
            'status_name' => $invoice->status,
            'invoice_number' => $invoice->invoice_number,
            'posting_batch_id' => $invoice->posting_batch_id,
        ], JSON_THROW_ON_ERROR);
    } elseif ($payload['operation'] === 'update') {
        $invoice = SalesInvoice::where('company_id', $company->id)->findOrFail($payload['document_id']);
        $updated = $commands->update(
            $invoice,
            $actor,
            $payload['command_payload'],
            $payload['key']
        );
        echo json_encode([
            'status' => 'ok',
            'id' => $updated->id,
            'status_name' => $updated->status,
        ], JSON_THROW_ON_ERROR);
    } elseif ($payload['operation'] === 'preview') {
        $invoice = SalesInvoice::where('company_id', $company->id)->findOrFail($payload['document_id']);
        $preview = $commands->preview($invoice, $actor);
        echo json_encode([
            'status' => 'ok',
            'id' => $invoice->id,
        ], JSON_THROW_ON_ERROR);
    } elseif ($payload['operation'] === 'execute') {
        $invoice = SalesInvoice::where('company_id', $company->id)->findOrFail($payload['document_id']);
        $executed = $commands->execute(
            $invoice,
            $actor,
            $payload['confirmation_token'],
            $payload['key']
        );
        echo json_encode([
            'status' => 'ok',
            'id' => $executed->id,
            'status_name' => $executed->status,
            'invoice_number' => $executed->invoice_number,
            'posting_batch_id' => $executed->posting_batch_id,
        ], JSON_THROW_ON_ERROR);
    } elseif ($payload['operation'] === 'sale') {
        $invoice = SalesInvoice::where('company_id', $company->id)->findOrFail($payload['document_id']);
        $posted = app(PostSalesInvoiceAction::class)->execute($invoice, $actor);
        echo json_encode([
            'status' => 'ok',
            'id' => $posted->id,
            'status_name' => $posted->status,
            'invoice_number' => $posted->invoice_number,
            'posting_batch_id' => $posted->posting_batch_id,
        ], JSON_THROW_ON_ERROR);
    }
} catch (IdempotencyConflictException) {
    echo json_encode(['status' => 'conflict'], JSON_THROW_ON_ERROR);
} catch (HttpException $e) {
    if ($e->getStatusCode() === 409) {
        echo json_encode(['status' => 'conflict'], JSON_THROW_ON_ERROR);
    } else {
        throw $e;
    }
} catch (InsufficientStockException) {
    echo json_encode(['status' => 'conflict', 'id' => $payload['document_id'] ?? null], JSON_THROW_ON_ERROR);
} catch (AuthorizationException) {
    echo json_encode(['status' => 'unauthorized'], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException) {
    echo json_encode(['status' => 'conflict'], JSON_THROW_ON_ERROR);
}
