<?php

declare(strict_types=1);

use App\Actions\Company\ToggleCompanyMemberStatusAction;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\MobileDevice;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Sales\DocumentSequenceService;
use App\Support\Mobile\Receipt\Exceptions\FinancialExecutionDeniedException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventAuthenticationException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventDuplicatePayloadException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventForeignScopeException;
use App\Support\Mobile\Receipt\FinancialExecutionAdapterInterface;
use App\Support\Mobile\Receipt\MobileEventReceiptManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\StrictBarrier;

$directory = $argv[1] ?? '';
$slot = $argv[2] ?? '';

if (! preg_match('/^worker-[ab]$/D', $slot) || ! str_starts_with(basename($directory), 'mobile_receipt_race_') || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())) {
    fwrite(STDERR, 'Unowned mobile receipt race directory.'.PHP_EOL);
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['mobile.enabled' => true]);

DisposableMariaDbSchema::assertPrimarySchema((string) DB::connection()->getDatabaseName());

$input = json_decode((string) file_get_contents($directory.'/'.$slot.'.json'), true, 512, JSON_THROW_ON_ERROR);
$mode = (string) ($input['mode'] ?? 'receipt');

// Setup observable query event listener for forced held-lock interleavings
$holdTable = $input['hold_lock_table'] ?? null;
$holdMs = (int) ($input['hold_ms'] ?? 0);
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

// Pause after candidate pluck has returned, before any Company lock executes.
$candidatePaused = false;
if (($input['pause_before_company_lock'] ?? false) === true) {
    DB::connection()->beforeExecuting(function (string $sql) use ($directory, &$candidatePaused): void {
        if (! $candidatePaused && str_contains(strtolower($sql), 'from `companies`') && str_contains(strtolower($sql), 'for update')) {
            $candidatePaused = true;
            file_put_contents($directory.'/candidate-returned', 'before-company-lock');
            StrictBarrier::await(fn (): bool => is_file($directory.'/release-candidate'), fn (): bool => true);
        }
    });
}

// Signal connection ID and readiness at the barrier
file_put_contents($directory.'/'.$slot.'.connection', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
if ($mode === 'password_change') {
    file_put_contents($directory.'/'.$slot.'.attempt', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
}
file_put_contents($directory.'/'.$slot.'.ready', 'ready');

// Wait for go signal
$deadline = microtime(true) + 30;
while (! is_file($directory.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Mobile identity race barrier timeout waiting for go.');
    }
    usleep(1000);
}

// Optional delay or wait before starting operation
if (isset($input['wait_before_start_file'])) {
    $waitFile = $directory.'/'.$input['wait_before_start_file'];
    $waitDeadline = microtime(true) + 15;
    while (! is_file($waitFile)) {
        if (microtime(true) > $waitDeadline) {
            throw new RuntimeException('Start ACK deadline expired.');
        }
        usleep(1000);
    }
}
if (isset($input['start_delay_ms'])) {
    usleep(((int) $input['start_delay_ms']) * 1000);
}

$startTime = microtime(true);
$retries = 0;
$deadlocks = 0;

try {
    if ($mode === 'login') {
        $loginData = [
            'email' => (string) $input['email'],
            'password' => (string) $input['password'],
            'device_id' => (string) $input['device_id'],
            'device_name' => (string) ($input['device_name'] ?? 'Race Device'),
            'challenge_id' => (string) $input['challenge_id'],
            'nonce' => (string) $input['nonce'],
        ];
        if (isset($input['mfa_code'])) {
            $loginData['mfa_code'] = (string) $input['mfa_code'];
        }
        if (isset($input['recovery_code'])) {
            $loginData['recovery_code'] = (string) $input['recovery_code'];
        }

        $request = Request::create(
            '/api/mobile/v1/auth/login',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => (string) ($input['ip'] ?? '127.0.0.1'),
            ],
            json_encode($loginData, JSON_THROW_ON_ERROR)
        );

        // Production HTTP Kernel execution through MobileApiBoundary
        /** @var Illuminate\Contracts\Http\Kernel $httpKernel */
        $httpKernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $response = $httpKernel->handle($request);
        if (($input['pause_before_company_lock'] ?? false) === true && ! $candidatePaused) {
            throw new RuntimeException('Required candidate barrier was not reached.');
        }
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getContent(), true) ?? [];
        if ($status >= 500) {
            fwrite(STDERR, 'Worker '.$slot.' login HTTP '.$status.': '.json_encode($body).PHP_EOL);
        }
        $httpKernel->terminate($request, $response);

        echo json_encode([
            'mode' => 'login',
            'outcome' => $status === 200 ? 'logged_in' : 'auth_error',
            'status' => $status,
            'token' => $body['token'] ?? null,
            'companies_count' => isset($body['companies']) ? count($body['companies']) : 0,
            'body' => $body,
            'deadlocks' => $deadlocks,
            'retries' => $retries,
            'duration' => microtime(true) - $startTime,
        ], JSON_THROW_ON_ERROR);

    } elseif ($mode === 'revoke') {
        $deviceId = (string) $input['device_id'];
        $rawToken = (string) ($input['raw_token'] ?? '');
        $reason = (string) ($input['reason'] ?? 'Concurrent security test revocation');

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
        if ($rawToken !== '') {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$rawToken;
        }

        $request = Request::create(
            '/api/mobile/v1/devices/'.$deviceId.'/revoke',
            'POST',
            [],
            [],
            [],
            $headers,
            json_encode(['reason' => $reason], JSON_THROW_ON_ERROR)
        );

        // Production HTTP Kernel execution through MobileApiBoundary and MobileAuthenticate
        /** @var Illuminate\Contracts\Http\Kernel $httpKernel */
        $httpKernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $response = $httpKernel->handle($request);
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getContent(), true) ?? [];
        if ($status >= 500) {
            fwrite(STDERR, 'Worker '.$slot.' revoke HTTP '.$status.': '.json_encode($body).PHP_EOL);
        }
        $httpKernel->terminate($request, $response);

        echo json_encode([
            'mode' => 'revoke',
            'outcome' => $status === 200 ? 'revoked' : 'revoke_error',
            'status' => $status,
            'device_id' => $deviceId,
            'body' => $body,
            'deadlocks' => $deadlocks,
            'retries' => $retries,
            'duration' => microtime(true) - $startTime,
        ], JSON_THROW_ON_ERROR);

    } elseif ($mode === 'toggle_member') {
        $companyId = (int) $input['company_id'];
        $targetUserId = (int) $input['target_user_id'];
        $actorId = (int) $input['actor_id'];

        file_put_contents($directory.'/'.$slot.'.connection', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
        // Execute exactly once, matching the production action's retry policy.
        $company = Company::findOrFail($companyId);
        $membership = CompanyUser::where('company_id', $companyId)->where('user_id', $targetUserId)->firstOrFail();
        $actor = User::findOrFail($actorId);
        $updated = app(ToggleCompanyMemberStatusAction::class)->execute($company, $membership, $actor);

        echo json_encode([
            'mode' => 'toggle_member',
            'outcome' => 'status_updated',
            'status' => 200,
            'new_status' => $updated->status,
            'deadlocks' => $deadlocks,
            'retries' => $retries,
            'duration' => microtime(true) - $startTime,
        ], JSON_THROW_ON_ERROR);

    } elseif ($mode === 'password_change') {
        $userId = (int) $input['user_id'];
        $newPassword = (string) $input['new_password'];

        $user = User::findOrFail($userId);
        file_put_contents($directory.'/'.$slot.'.attempt', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
        app(ResetUserPassword::class)->reset($user, [
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);
        $user->refresh();

        echo json_encode([
            'mode' => 'password_change',
            'outcome' => 'password_updated',
            'status' => 200,
            'new_epoch' => 'epoch-'.substr(hash('sha256', (string) $user->password), 0, 16),
            'deadlocks' => $deadlocks,
            'retries' => $retries,
            'duration' => microtime(true) - $startTime,
        ], JSON_THROW_ON_ERROR);

    } else {
        // Receipt intake mode (default)
        $companyId = (int) $input['company_id'];
        $clientEventId = (string) $input['client_event_id'];
        $tokenHash = (string) $input['token_hash'];
        $eventVersion = (int) ($input['event_version'] ?? 2);
        $representation = (string) ($input['representation'] ?? 'v2_intent');
        $canonicalBytes = (string) $input['canonical_bytes'];
        $executorMode = (string) ($input['executor_mode'] ?? 'canonical');

        $executor = null;
        if ($executorMode === 'canonical') {
            $executor = new class($barrier, $holdTable !== null) implements FinancialExecutionAdapterInterface
            {
                public function __construct(private StrictBarrier $barrier, private bool $required) {}

                public function authorize(
                    Company $company,
                    User $actor,
                    MobileDevice $device,
                    string $operation,
                    ?array $payload = null
                ): void {
                    if ($operation === 'execute') {
                        foreach ([
                            'sales.invoice.create',
                            'sales.invoice.post',
                            'money.receipt.create',
                            'money.receipt.allocate',
                        ] as $perm) {
                            if (! $actor->hasPermissionTo($perm)) {
                                throw new AuthorizationException("Missing permission [{$perm}]");
                            }
                        }
                    } elseif (in_array($operation, ['replay', 'lookup'], true)) {
                        foreach (['sales.invoice.view', 'money.receipt.view'] as $perm) {
                            if (! $actor->hasPermissionTo($perm)) {
                                throw new AuthorizationException("Missing read permission [{$perm}]");
                            }
                        }
                    }
                }

                public function execute(
                    Company $company,
                    User $actor,
                    MobileDevice $device,
                    array $payload,
                    string $idempotencySuffix
                ): array {
                    if ($this->required) {
                        $this->barrier->requireHeld();
                    }
                    $customer = Customer::where('company_id', $company->id)->firstOrFail();
                    $warehouse = Warehouse::where('company_id', $company->id)->where('is_default', true)->firstOrFail();
                    $product = Product::where('company_id', $company->id)->where('track_stock', true)->firstOrFail();
                    $productUnit = ProductUnit::where('company_id', $company->id)->where('product_id', $product->id)->firstOrFail();
                    $cash = MoneyAccount::where('company_id', $company->id)->where('account_type', 'cash')->firstOrFail();

                    $docNumber = app(DocumentSequenceService::class)->generateNextNumber(
                        (int) $company->id,
                        DocumentSequence::TYPE_SALES_INVOICE
                    );

                    $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($company, $actor, [
                        'customer_id' => $customer->id,
                        'warehouse_id' => $warehouse->id,
                        'currency_code' => $company->base_currency_code,
                        'exchange_rate' => '1.0000000000',
                        'issue_date' => '2026-11-12',
                        'document_locale' => 'en',
                        'lines' => [
                            [
                                'product_id' => $product->id,
                                'product_unit_id' => $productUnit->id,
                                'item_description' => 'Worker concurrent item',
                                'quantity' => '1.000000',
                                'unit_price' => '25.000000',
                                'discount_type' => 'none',
                                'discount_value' => '0',
                                'tax_rate_id' => null,
                            ],
                        ],
                    ]);

                    $posted = app(PostSalesInvoiceAction::class)->execute($invoice, $actor);

                    $payment = app(PostCustomerPaymentAction::class)->execute($company, $actor, [
                        'customer_id' => $customer->id,
                        'money_account_id' => $cash->id,
                        'payment_method' => 'cash',
                        'payment_date' => '2026-11-12',
                        'amount' => '25.000000',
                        'exchange_rate' => '1.0000000000',
                        'idempotency_key' => 'worker-pay-'.$idempotencySuffix,
                        'allocations' => [
                            [
                                'sales_invoice_id' => (int) $posted->id,
                                'allocated_amount' => '25.000000',
                            ],
                        ],
                    ]);

                    return [
                        'sales_invoice_id' => (int) $posted->id,
                        'sales_invoice_public_id' => $posted->public_id,
                        'customer_payment_id' => (int) $payment->id,
                        'customer_payment_public_id' => $payment->public_id,
                        'command_receipt_id' => null,
                        'terminal_canonical_result' => [
                            'status' => 'completed',
                            'invoice_number' => $docNumber,
                            'invoice_public_id' => $posted->public_id,
                            'payment_public_id' => $payment->public_id,
                            'grand_total' => '25.000000',
                            'paid_amount' => '25.000000',
                        ],
                    ];
                }
            };
            $app->instance(FinancialExecutionAdapterInterface::class, $executor);
        }

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
            'mode' => 'receipt',
            'outcome' => $result['outcome'],
            'status' => 200,
            'receipt_id' => $result['receipt_id'],
            'sales_invoice_id' => $result['sales_invoice_id'],
            'sales_invoice_public_id' => $result['sales_invoice_public_id'],
            'customer_payment_id' => $result['customer_payment_id'],
            'customer_payment_public_id' => $result['customer_payment_public_id'],
            'terminal_canonical_result' => $result['terminal_canonical_result'],
            'deadlocks' => $deadlocks,
            'retries' => $retries,
            'duration' => microtime(true) - $startTime,
        ], JSON_THROW_ON_ERROR);
    }
} catch (MobileEventDuplicatePayloadException $e) {
    echo json_encode([
        'mode' => $mode,
        'outcome' => 'conflict',
        'status' => 409,
        'message' => $e->getMessage(),
        'deadlocks' => $deadlocks,
        'retries' => $retries,
        'duration' => microtime(true) - $startTime,
    ], JSON_THROW_ON_ERROR);
} catch (MobileEventForeignScopeException $e) {
    echo json_encode([
        'mode' => $mode,
        'outcome' => 'foreign_scope',
        'status' => 403,
        'message' => $e->getMessage(),
        'deadlocks' => $deadlocks,
        'retries' => $retries,
        'duration' => microtime(true) - $startTime,
    ], JSON_THROW_ON_ERROR);
} catch (FinancialExecutionDeniedException $e) {
    echo json_encode([
        'mode' => $mode,
        'outcome' => 'financial_denied',
        'status' => 403,
        'message' => $e->getMessage(),
        'deadlocks' => $deadlocks,
        'retries' => $retries,
        'duration' => microtime(true) - $startTime,
    ], JSON_THROW_ON_ERROR);
} catch (MobileEventAuthenticationException $e) {
    echo json_encode([
        'mode' => $mode,
        'outcome' => 'auth_error',
        'status' => 401,
        'message' => $e->getMessage(),
        'deadlocks' => $deadlocks,
        'retries' => $retries,
        'duration' => microtime(true) - $startTime,
    ], JSON_THROW_ON_ERROR);
} catch (AuthorizationException $e) {
    echo json_encode([
        'mode' => $mode,
        'outcome' => 'unauthorized',
        'status' => 403,
        'message' => $e->getMessage(),
        'deadlocks' => $deadlocks,
        'retries' => $retries,
        'duration' => microtime(true) - $startTime,
    ], JSON_THROW_ON_ERROR);
} catch (HttpException $e) {
    echo json_encode([
        'mode' => $mode,
        'outcome' => 'http_error',
        'status' => $e->getStatusCode(),
        'message' => $e->getMessage(),
        'deadlocks' => $deadlocks,
        'retries' => $retries,
        'duration' => microtime(true) - $startTime,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    echo json_encode([
        'mode' => $mode,
        'outcome' => 'error',
        'status' => 500,
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'deadlocks' => $deadlocks,
        'retries' => $retries,
        'duration' => microtime(true) - $startTime,
    ], JSON_THROW_ON_ERROR);
    $hasFatalError = true;
}

if ($holdTable !== null) {
    try {
        $barrier->requireHeld();
    } catch (Throwable $barrierEx) {
        fwrite(STDERR, $barrierEx->getMessage().PHP_EOL);
        exit(1);
    }
}
if (isset($hasFatalError) && $hasFatalError) {
    exit(1);
}
