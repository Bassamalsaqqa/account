<?php

declare(strict_types=1);
use App\Http\Controllers\Api\Mobile\V1\AuthController;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\DisposableMariaDbSchema;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
$input = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
touch($argv[2]);
$request = Request::create('/api/mobile/v1/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'], json_encode($input, JSON_THROW_ON_ERROR));
if (($argv[3] ?? 'mobile') === 'browser') {
    $browser = TwoFactorLoginRequest::create('/two-factor-challenge', 'POST', ['recovery_code' => $input['recovery_code']]);
    $browser->headers->set('Accept', 'application/json');
    $session = app('session')->driver('array');
    $session->start();
    $session->put('login.id', User::where('email', $input['email'])->firstOrFail()->id);
    $browser->setLaravelSession($session);
    app()->instance('request', $browser);
    try {
        $result = app(TwoFactorAuthenticatedSessionController::class)->store($browser);
        $response = $result instanceof Response ? $result : $result->toResponse($browser);
    } catch (ValidationException $exception) {
        $response = response()->json([], 401);
    }
} else {
    $response = app(AuthController::class)->login($request);
}
echo json_encode(['status' => $response->isSuccessful() ? 200 : 401], JSON_THROW_ON_ERROR);
