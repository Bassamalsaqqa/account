<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Services\Inventory\OpeningInventoryImport;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
$directory = $argv[1] ?? '';
$slot = $argv[2] ?? '';
if (! preg_match('/^worker-[ab]$/D', $slot) || ! str_starts_with(basename($directory), 'opening_race_') || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())) {
    throw new RuntimeException('Unowned synthetic importer race.');
}
$input = json_decode((string) file_get_contents($directory.'/input.json'), true, 64, JSON_THROW_ON_ERROR);
$company = Company::findOrFail($input['company_id']);
$actor = User::findOrFail($input['actor_id']);
auth()->setUser($actor);
app(CompanyContext::class)->setCompany($company, $actor);
file_put_contents($directory.'/'.$slot.'.ready', 'ready');
$deadline = microtime(true) + 30;
while (! is_file($directory.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Synthetic importer barrier expired.');
    }
    usleep(1000);
}
try {
    $service = app(OpeningInventoryImport::class);
    $result = $service->run($input['workbook'], $input['source_sha'], $input['plan'], $service->hash($input['plan']), $company, $actor, true);
    echo json_encode(['status' => $result['status']], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
} finally {
    app(CompanyContext::class)->clear();
}
