<?php

declare(strict_types=1);

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\SaveTaxRateAction;
use App\Actions\Sales\UpdateMoneyAccountAction;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Mobile\Offline\OfflineSaleConflict;
use App\Support\Mobile\Offline\OfflineSaleEvent;
use App\Support\Mobile\Receipt\Exceptions\FinancialExecutionDeniedException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventAuthenticationException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventDuplicatePayloadException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventForeignScopeException;
use App\Support\Mobile\Receipt\FinancialExecutionAdapterInterface;
use App\Support\Mobile\Receipt\MobileEventReceiptManager;
use App\Support\Mobile\Receipt\NativeOnlineCashFinancialExecutionAdapter;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\QualifiedCashFinancialExecutionAdapter;
use Tests\Support\StrictBarrier;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DisposableMariaDbSchema::assertPrimarySchema((string) DB::connection()->getDatabaseName());

$directory = $argv[1] ?? '';
$slot = $argv[2] ?? '';

if (! preg_match('/^worker-[ab]$/D', $slot) || ! str_starts_with(basename($directory), 'mobile_receipt_race_') || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())) {
    throw new RuntimeException('Unowned mobile receipt race directory.');
}

$input = json_decode(file_get_contents($directory.'/'.$slot.'.json'), true, 512, JSON_THROW_ON_ERROR);

$mode = (string) ($input['mode'] ?? 'v2_receipt');

$holdTable = $input['hold_lock_table'] ?? null;
$waitForFile = $input['wait_for_file'] ?? null;
$lockSignaled = false;
$barrier = new StrictBarrier;

if ($holdTable !== null) {
    DB::listen(function ($query) use ($holdTable, $directory, $slot, $waitForFile, &$lockSignaled, $barrier) {
        $sql = strtolower($query->sql);
        if (! $lockSignaled && $barrier->observe($sql, $holdTable)) {
            $lockSignaled = true;
            file_put_contents($directory.'/'.$slot.'.locked', 'locked');
            if ($waitForFile !== null) {
                $timeout = microtime(true) + 15;
                while (! is_file($directory.'/'.$waitForFile)) {
                    if (microtime(true) > $timeout) {
                        throw new RuntimeException('Held-lock release ACK deadline expired.');
                    }
                    usleep(1000);
                }
            } else {
                throw new RuntimeException('Explicit held-lock release ACK is required; timed holds are unqualified.');
            }
        }
    });
}

// Signal connection ID and readiness at the barrier
file_put_contents($directory.'/'.$slot.'.connection', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
file_put_contents($directory.'/'.$slot.'.ready', 'ready');

// Wait for go signal
$deadline = microtime(true) + 30;
while (! is_file($directory.'/go') && ! is_file($directory.'/go-'.$slot)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Mobile receipt race barrier timeout waiting for go.');
    }
    usleep(1000);
}

if ($mode === 'http') {
    config([
        'mobile.enabled' => true,
        'mobile.merchant_api_enabled' => true,
    ]);

    // Execute through normal HTTP Kernel
    $http = $input['http'] ?? [];
    $method = (string) ($http['method'] ?? 'POST');
    $uri = (string) ($http['uri'] ?? '/');
    $headers = (array) ($http['headers'] ?? []);
    $body = $http['body'] ?? [];

    $server = ['REMOTE_ADDR' => '127.0.0.1'];
    foreach ($headers as $k => $v) {
        $upper = strtoupper(str_replace('-', '_', (string) $k));
        if (in_array($upper, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            $server[$upper] = (string) $v;
        } else {
            $server['HTTP_'.$upper] = (string) $v;
        }
    }

    $rawContent = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : (string) $body;
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $request = Request::create($uri, $method, [], [], [], $server, $rawContent);
    $response = $kernel->handle($request);

    $content = (string) $response->getContent();
    $decodedBody = json_decode($content, true);

    echo json_encode([
        'status' => $response->getStatusCode(),
        'headers' => [
            'Idempotency-Key' => $response->headers->get('Idempotency-Key'),
            'X-Idempotent-Replay' => $response->headers->get('X-Idempotent-Replay'),
        ],
        'body' => $decodedBody ?? $content,
    ], JSON_THROW_ON_ERROR);

    $kernel->terminate($request, $response);
    exit(0);
}

if ($mode === 'web_sale') {
    $company = Company::query()->findOrFail($input['company_id']);
    $owner = User::query()->findOrFail($input['user_id']);
    auth()->setUser($owner);
    app(CompanyContext::class)->setCompany($company, $owner);
    $invoice = SalesInvoice::query()->findOrFail($input['invoice_id']);
    try {
        app(PostSalesInvoiceAction::class)->execute($invoice, $owner);
        echo json_encode(['outcome' => 'posted'], JSON_THROW_ON_ERROR);
    } catch (InsufficientStockException) {
        echo json_encode(['outcome' => 'stock_unavailable'], JSON_THROW_ON_ERROR);
    }
    exit(0);
}

if (in_array($mode, ['cash_active', 'tax_route'], true)) {
    $company = Company::query()->findOrFail($input['company_id']);
    $owner = User::query()->findOrFail($input['user_id']);
    auth()->setUser($owner);
    app(CompanyContext::class)->setCompany($company, $owner);

    // Verify the real action acquires settings before any affected account or tax row.
    $settingsLock = new StrictBarrier;
    $targetLock = new StrictBarrier;
    $target = $mode === 'cash_active' ? 'money_accounts' : 'ledger_accounts';
    DB::listen(static function ($query) use ($settingsLock, $targetLock, $target): void {
        $settingsLock->observe($query->sql, 'company_mobile_settings');
        if ($targetLock->observe($query->sql, $target)) {
            $settingsLock->requireHeld();
        }
    });
    if ($mode === 'cash_active') {
        app(UpdateMoneyAccountAction::class)->execute($company, $owner, $input['target_id'], $input['data']);
    } else {
        app(SaveTaxRateAction::class)->execute($company, $owner, $input['data'], $input['target_id']);
    }
    $targetLock->requireHeld();
    echo json_encode(['outcome' => $mode], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($mode === 'policy_disable') {
    DB::transaction(function () use ($input): void {
        $settings = CompanyMobileSetting::query()->whereKey($input['company_id'])->lockForUpdate()->firstOrFail();
        $settings->update(['offline_policy_enabled' => false]);
    });
    echo json_encode(['outcome' => 'policy_disabled'], JSON_THROW_ON_ERROR);
    exit(0);
}

if (in_array($mode, ['password_reset', 'mfa_enable'], true)) {
    DB::transaction(function () use ($input, $mode): void {
        $user = User::query()->whereKey($input['user_id'])->lockForUpdate()->firstOrFail();
        if ($mode === 'mfa_enable') {
            app(EnableTwoFactorAuthentication::class)($user);
        } else {
            app(ResetUserPassword::class)->reset($user, [
                'password' => 'ResetStaffPass123!',
                'password_confirmation' => 'ResetStaffPass123!',
            ]);
        }
    });
    echo json_encode(['outcome' => $mode], JSON_THROW_ON_ERROR);
    exit(0);
}

$companyId = (int) $input['company_id'];
$clientEventId = (string) $input['client_event_id'];
$tokenHash = (string) $input['token_hash'];
$eventVersion = (int) ($input['event_version'] ?? 2);
$representation = (string) ($input['representation'] ?? 'v2_intent');
$canonicalBytes = (string) $input['canonical_bytes'];
$executorMode = (string) ($input['executor_mode'] ?? 'canonical');

// Define executor based on input mode
$executor = null;
if ($executorMode === 'qualified_cash' || $executorMode === 'canonical') {
    $executor = new QualifiedCashFinancialExecutionAdapter;
    $app->instance(FinancialExecutionAdapterInterface::class, $executor);
}

if ($executorMode === 'production_cash') {
    config([
        'mobile.enabled' => true,
        'mobile.merchant_api_enabled' => true,
        'mobile.v2_enabled' => true,
        'mobile.v2_cash_slice_enabled' => true,
        'mobile.v2_offline_sales_enabled' => $representation === OfflineSaleEvent::REPRESENTATION,
    ]);
    $executor = app(NativeOnlineCashFinancialExecutionAdapter::class);
    $app->instance(FinancialExecutionAdapterInterface::class, $executor);
}

// Execute through manager
try {
    $manager = new MobileEventReceiptManager($executor);
    $result = $manager->recordOrReplay(
        companyId: $companyId,
        clientEventId: $clientEventId,
        tokenHash: $tokenHash,
        eventVersion: $eventVersion,
        representation: $representation,
        canonicalBytes: $canonicalBytes
    );

    echo json_encode([
        'outcome' => $result['outcome'],
        'status' => 200,
        'receipt_id' => $result['receipt_id'],
        'sales_invoice_id' => $result['sales_invoice_id'],
        'sales_invoice_public_id' => $result['sales_invoice_public_id'],
        'customer_payment_id' => $result['customer_payment_id'],
        'customer_payment_public_id' => $result['customer_payment_public_id'],
        'terminal_canonical_result' => $result['terminal_canonical_result'],
    ], JSON_THROW_ON_ERROR);
} catch (OfflineSaleConflict $e) {
    echo json_encode([
        'outcome' => 'conflict',
        'status' => 409,
        'reason' => $e->reason,
    ], JSON_THROW_ON_ERROR);
} catch (MobileEventDuplicatePayloadException $e) {
    echo json_encode([
        'outcome' => 'conflict',
        'status' => 409,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
} catch (MobileEventForeignScopeException $e) {
    echo json_encode([
        'outcome' => 'foreign_scope',
        'status' => 403,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
} catch (FinancialExecutionDeniedException $e) {
    echo json_encode([
        'outcome' => 'financial_denied',
        'status' => 403,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
} catch (MobileEventAuthenticationException $e) {
    echo json_encode([
        'outcome' => 'auth_error',
        'status' => 401,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
} catch (HttpException $e) {
    echo json_encode([
        'outcome' => 'http_error',
        'status' => $e->getStatusCode(),
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode([
        'outcome' => 'error',
        'status' => 500,
        'class' => get_class($e),
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
