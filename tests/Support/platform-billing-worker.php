<?php

declare(strict_types=1);

use App\Application\Platform\ManageSubscription;
use App\Application\Platform\PlatformSessionProof;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
$directory = $argv[1];
$slot = $argv[2];
if (! preg_match('/^worker-[ab]$/D', $slot) || ! str_starts_with(basename($directory), 'ms2_billing_race_') || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())) {
    throw new RuntimeException('Unowned billing race directory.');
}
$input = json_decode(file_get_contents($directory.'/'.$slot.'.json'), true, 512, JSON_THROW_ON_ERROR);
$actor = User::query()->findOrFail($input['actor']);
$request = Request::create('/platform/companies/'.$input['company'].'/subscription/payment', 'POST');
$session = app('session')->driver();
$session->start();
$session->put(PlatformSessionProof::KEY, $input['proof']);
$session->put('auth.password_confirmed_at', now()->timestamp);
$request->setLaravelSession($session);
$request->setUserResolver(static fn () => $actor);
$app->instance('request', $request);
auth()->setUser($actor);
file_put_contents($directory.'/'.$slot.'.ready', 'ready');
$deadline = microtime(true) + 30;
while (! is_file($directory.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Billing race barrier timeout.');
    }
    usleep(1000);
}
try {
    $result = app(ManageSubscription::class)->execute($input['company'], 'payment', $input['payload']);
    echo json_encode(['outcome' => 'recorded', 'result' => $result], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['outcome' => 'conflict', 'fields' => array_keys($exception->errors())], JSON_THROW_ON_ERROR);
}
