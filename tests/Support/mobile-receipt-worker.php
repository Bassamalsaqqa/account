<?php

declare(strict_types=1);

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\MobileAccessToken;
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
use App\Support\Mobile\Receipt\NativeFinancialExecutionCapability;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
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

$companyId = (int) $input['company_id'];
$clientEventId = (string) $input['client_event_id'];
$tokenHash = (string) $input['token_hash'];
$eventVersion = (int) ($input['event_version'] ?? 2);
$representation = (string) ($input['representation'] ?? 'v2_intent');
$canonicalBytes = (string) $input['canonical_bytes'];
$executorMode = (string) ($input['executor_mode'] ?? 'canonical');

$tokenRecord = MobileAccessToken::where('token_hash', $tokenHash)->firstOrFail();
$actor = User::findOrFail($tokenRecord->user_id);
$companyRecord = Company::findOrFail($companyId);
// Define executor based on input mode
$executor = null;
if ($executorMode === 'qualified_cash') {
    $executor = new QualifiedCashFinancialExecutionAdapter;
    $app->instance(FinancialExecutionAdapterInterface::class, $executor);
} elseif ($executorMode === 'canonical') {
    $executor = new class implements FinancialExecutionAdapterInterface
    {
        public function authorize(
            Company $company,
            User $actor,
            MobileDevice $device,
            string $operation,
            ?array $payload = null
        ): void {
            // Test fixture: verify actual canonical Spatie permissions
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
            string $idempotencySuffix,
            ?NativeFinancialExecutionCapability $capability = null
        ): array {
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
