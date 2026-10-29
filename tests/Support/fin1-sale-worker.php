<?php

declare(strict_types=1);

use App\Application\Commands\ImmediateSaleCommands;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());

$directory = $argv[1];
$slot = $argv[2];
if (! preg_match('/^worker-[ab]$/D', $slot) || ! str_starts_with(basename($directory), 'fin1_sale_race_') || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())) {
    throw new RuntimeException('Unowned sale race directory.');
}

$input = json_decode(file_get_contents($directory.'/'.$slot.'.json'), true, 512, JSON_THROW_ON_ERROR);
$actor = User::query()->findOrFail($input['actor_id']);
$company = Company::query()->findOrFail($input['company_id']);

$request = Request::create('/sales/invoices/'.$input['invoice_id'], 'POST');
$session = app('session')->driver();
$session->start();
$request->setLaravelSession($session);
$request->setUserResolver(static fn () => $actor);
$app->instance('request', $request);
auth()->setUser($actor);

app(CompanyContext::class)->setCompany($company, $actor);
setPermissionsTeamId($company->id);

$invoice = SalesInvoice::query()->findOrFail($input['invoice_id']);

file_put_contents($directory.'/'.$slot.'.ready', 'ready');
$deadline = microtime(true) + 30;
while (! is_file($directory.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Sale race barrier timeout.');
    }
    usleep(1000);
}

try {
    $result = app(ImmediateSaleCommands::class)->execute($invoice, $actor, $input['token'], $input['key']);
    echo json_encode([
        'outcome' => 'success',
        'invoice_id' => $result['invoice']->id,
        'invoice_status' => $result['invoice']->status,
        'payment_id' => $result['payment']?->id,
        'payment_amount' => (string) $result['payment']?->amount,
    ], JSON_THROW_ON_ERROR);
} catch (HttpException $exception) {
    echo json_encode([
        'outcome' => 'conflict',
        'status' => $exception->getStatusCode(),
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode([
        'outcome' => 'error',
        'class' => get_class($exception),
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
