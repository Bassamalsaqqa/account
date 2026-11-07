<?php

declare(strict_types=1);

require __DIR__.'/../../../vendor/autoload.php';

use App\Models\Company;
use App\Models\User;
use App\Services\Corrections\PurchaseCorrection;
use App\Services\Corrections\TradeCorrection;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableMariaDbSchema;

DisposableMariaDbSchema::testSettings();
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
if (! app()->environment('testing')) {
    throw new RuntimeException('Test-only worker.');
}
$payload = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$actor = User::findOrFail($payload['actor']);
$company = Company::findOrFail($payload['company']);
auth()->login($actor);
app(CompanyContext::class)->setCompany($company, $actor);
setPermissionsTeamId($company->id);
app()->setLocale($payload['locale']);
$service = $payload['family'] === 'purchase' ? PurchaseCorrection::class : TradeCorrection::class;
$result = app($service)->execute($payload['token'], $actor);
fwrite(STDOUT, (string) $result->id);
