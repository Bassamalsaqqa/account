<?php

declare(strict_types=1);
use App\Actions\Purchasing\EnsurePurchasingFoundationAction;
use App\Application\Platform\PlatformSessionProof;
use App\Application\Platform\ProvisionCompany;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\DisposableMariaDbSchema;

// Child process for an explicitly owned disposable-schema concurrency test only.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
$directory = $argv[1];
$slot = $argv[2];
if (! preg_match('/^worker-[ab]$/D', $slot) || ! str_starts_with(basename($directory), 'ms1a_race_') || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())) {
    throw new RuntimeException('Unowned concurrency barrier directory');
}
$input = json_decode(file_get_contents($directory.'/'.$slot.'.json'), true, flags: JSON_THROW_ON_ERROR);
$user = User::query()->findOrFail($input['actor']);
$request = Request::create('/platform/companies', 'POST');
$session = app('session')->driver();
$session->start();
$session->put(PlatformSessionProof::KEY, $input['proof']);
$session->put('auth.password_confirmed_at', now()->timestamp);
$request->setLaravelSession($session);
$request->setUserResolver(static fn () => $user);
$app->instance('request', $request);
auth()->setUser($user);
file_put_contents($directory.'/'.$slot.'.ready', 'ready');
$deadline = microtime(true) + 30;
while (! is_file($directory.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timeout');
    }
    usleep(1000);
}
if (($input['operation'] ?? null) === 'revoke') {
    if (($input['prove_lock_wait'] ?? false) === true) {
        // Prove this connection cannot acquire this exact grant while creation holds it.
        // A server-global wait counter could instead observe another concurrent test.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            DB::transaction(static function () use ($user): void {
                DB::table('platform_operator_grants')->where('user_id', $user->id)->lockForUpdate()->first();
            });
            throw new RuntimeException('Revocation acquired the authorization grant before creator release.');
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1205) {
                throw $exception;
            }
            file_put_contents($directory.'/revoker-lock-timeout', '1205');
        } finally {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 30');
        }
    }
    file_put_contents($directory.'/revoker-connection', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
    DB::transaction(static function () use ($user): void {
        DB::table('platform_operator_grants')->where('user_id', $user->id)->lockForUpdate()->first();
        DB::table('platform_operator_grants')->where('user_id', $user->id)->update(['active' => false, 'revision' => 2]);
    });
    echo json_encode(['outcome' => 'revoked'], JSON_THROW_ON_ERROR);
    exit;
}
if (($input['pause_after_authorization'] ?? false) === true) {
    $original = app(EnsurePurchasingFoundationAction::class);
    app()->instance(EnsurePurchasingFoundationAction::class, new class($original, $directory)
    {
        public function __construct(private EnsurePurchasingFoundationAction $original, private string $directory) {}

        public function execute(Company $company): void
        {
            file_put_contents($this->directory.'/authorized', 'locked');
            $deadline = microtime(true) + 30;
            while (! is_file($this->directory.'/release-authorized')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Authorized transaction barrier timed out');
                }
                usleep(1000);
            }
            $this->original->execute($company);
        }
    });
}
try {
    $company = app(ProvisionCompany::class)->execute($input['payload']);
    echo json_encode(['outcome' => 'created', 'company_id' => $company->id], JSON_THROW_ON_ERROR);
} catch (ValidationException $e) {
    echo json_encode(['outcome' => 'conflict', 'fields' => array_keys($e->errors())], JSON_THROW_ON_ERROR);
} catch (AuthorizationException) {
    echo json_encode(['outcome' => 'denied'], JSON_THROW_ON_ERROR);
}
