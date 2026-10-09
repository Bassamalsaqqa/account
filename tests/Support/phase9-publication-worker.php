<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\PublicShare;
use App\Models\User;
use App\Services\Catalogs\CatalogService;
use App\Services\Sales\PublicShareService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableMariaDbSchema;

require __DIR__.'/../../vendor/autoload.php';

// Test-only CLI. Ownership proof is checked before querying or mutating any table.
DisposableMariaDbSchema::testSettings();
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
$payload = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$company = Company::findOrFail($payload['company_id']);
$actor = User::findOrFail($payload['actor_id']);
auth()->login($actor);
app(CompanyContext::class)->setCompany($company, $actor);
setPermissionsTeamId($company->id);
file_put_contents($payload['ready'], 'ready');
$deadline = microtime(true) + 20;
while (! is_file($payload['release'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Publication test barrier timed out.');
    }
    usleep(10000);
}
file_put_contents($payload['attempting'], 'attempting');
try {
    if ($payload['operation'] === 'statement') {
        $result = app(PublicShareService::class)->createShare($company, $actor, PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            $payload['subject_id'], null, 'Concurrent-test-password', $payload['key'], $payload['scope']);
        $share = $result['share'];
        echo json_encode(['status' => 'ok', 'id' => $share->id, 'token_hash' => hash('sha256', $result['raw_token']),
            'content_hash' => $share->content_hash, 'ciphertext_hash' => hash('sha256', $share->encrypted_snapshot)], JSON_THROW_ON_ERROR);
    } elseif ($payload['operation'] === 'catalog') {
        $catalog = app(CatalogService::class)->publish($payload['subject_id'], $payload['revision'], $payload['preview_hash'], $payload['key']);
        echo json_encode(['status' => 'ok', 'id' => $catalog->id, 'revision' => $catalog->published_revision,
            'content_hash' => $catalog->published_hash], JSON_THROW_ON_ERROR);
    } else {
        throw new RuntimeException('Unknown test operation.');
    }
} catch (InvalidArgumentException $exception) {
    // Report only the expected semantic category, never private payloads or tokens.
    if (! str_contains($exception->getMessage(), 'conflict')) {
        throw $exception;
    }
    echo json_encode(['status' => 'conflict'], JSON_THROW_ON_ERROR);
}
