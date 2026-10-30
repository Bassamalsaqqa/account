<?php

declare(strict_types=1);

namespace Tests\Feature\Fin1;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Application\Commands\ImmediateSaleCommands;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

final class ImmediateSaleConcurrencyTest extends TestCase
{
    private function setupFixture(DisposableMariaDbSchema $schema): array
    {
        $schema->switchLaravelConnection();

        $owner = User::factory()->create(['locale' => 'en']);
        $company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'شركة البيع المتزامن',
            'name_en' => 'Concurrency Sale Co',
            'base_currency_code' => 'ILS',
        ]);

        $this->actingAs($owner);
        app(CompanyContext::class)->setCompany($company, $owner);

        $customer = Customer::create([
            'company_id' => $company->id,
            'name_ar' => 'عميل السباق',
            'name_en' => 'Race Customer',
            'currency_code' => 'ILS',
            'active' => true,
            'created_by' => $owner->id,
        ]);

        $cash = MoneyAccount::where('company_id', $company->id)->where('currency_code', 'ILS')->firstOrFail();
        $unit = Unit::where('company_id', $company->id)->where('code', 'piece')->firstOrFail();
        $warehouse = Warehouse::where('company_id', $company->id)->where('is_default', true)->firstOrFail();
        $product = Product::create(['company_id' => $company->id, 'name_ar' => 'Synthetic race stock',
            'base_unit_id' => $unit->id, 'product_type' => 'stock', 'track_stock' => true, 'track_expiry' => false, 'active' => true, 'created_by' => $owner->id]);
        $productUnit = ProductUnit::create(['company_id' => $company->id, 'product_id' => $product->id, 'unit_id' => $unit->id,
            'conversion_to_base' => '1.000000', 'is_base' => true, 'is_default_sale' => true, 'is_default_purchase' => true,
            'active' => true, 'created_by' => $owner->id]);
        app(PostOpeningStockAction::class)->execute(company: $company, product: $product, warehouse: $warehouse,
            quantity: Quantity::of('2'), unitCostBase: '10', user: $owner, idempotencyKey: 'race-opening-stock',
            unitId: $unit->id, movementDate: '2026-10-29');

        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($company, $owner, [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-29',
            'document_locale' => 'en',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $productUnit->id,
                    'item_description' => 'Race service item',
                    'quantity' => '1',
                    'unit_price' => '100.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $owner, [
            'mode' => 'full',
            'money_account_id' => (int) $cash->id,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'payment_date' => '2026-10-29',
            'amount' => '100',
            'exchange_rate' => '1',
        ]);

        return [$company, $owner, $invoice, $preview['confirmation_token']];
    }

    private function start(DisposableMariaDbSchema $schema, string $directory, string $slot, array $input): Process
    {
        file_put_contents($directory.'/'.$slot.'.json', json_encode($input, JSON_THROW_ON_ERROR));
        chmod($directory.'/'.$slot.'.json', 0600);
        $settings = DisposableMariaDbSchema::testSettings();
        $env = array_replace([
            'APP_KEY' => (string) (config('app.key') ?: getenv('APP_KEY')),
            'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
            'PHASE8_TEST_DB_HOST' => $settings['HOST'],
            'PHASE8_TEST_DB_PORT' => $settings['PORT'],
            'PHASE8_TEST_DB_USERNAME' => $settings['USERNAME'],
            'PHASE8_TEST_DB_PASSWORD' => $settings['PASSWORD'],
            'PHASE8_TEST_DB_EMPTY_PASSWORD' => $settings['PASSWORD'] === '' ? '1' : '0',
        ], $schema->environment());
        $process = new Process([PHP_BINARY, base_path('tests/Support/fin1-sale-worker.php'), $directory, $slot], base_path(), $env);
        $process->setTimeout(90)->start();
        $deadline = microtime(true) + 30;
        while (! is_file($directory.'/'.$slot.'.ready')) {
            if (! $process->isRunning() || microtime(true) > $deadline) {
                $process->stop();
                $this->fail('Sale worker did not reach the owned barrier: [err='.$process->getErrorOutput().'] [out='.$process->getOutput().']');
            }
            usleep(1000);
        }

        return $process;
    }

    public function test_simultaneous_identical_retries_converge_to_one_sale_and_payment(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $schema->migrate();
            [$company, $owner, $invoice, $token] = $this->setupFixture($schema);

            $directory = sys_get_temp_dir().'/fin1_sale_race_'.bin2hex(random_bytes(8));
            mkdir($directory, 0700);
            $a = $b = null;
            try {
                $input = [
                    'actor_id' => $owner->id,
                    'invoice_id' => $invoice->id,
                    'token' => $token,
                    'key' => 'fin1-race-identical-key',
                    'company_id' => $company->id,
                ];

                $a = $this->start($schema, $directory, 'worker-a', $input);
                $b = $this->start($schema, $directory, 'worker-b', $input);
                touch($directory.'/go');

                $this->assertSame(0, $a->wait(), $a->getErrorOutput());
                $this->assertSame(0, $b->wait(), $b->getErrorOutput());

                $first = json_decode($a->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $second = json_decode($b->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                $this->assertSame('success', $first['outcome']);
                $this->assertSame('success', $second['outcome']);
                $this->assertSame($first['invoice_id'], $second['invoice_id']);
                $this->assertSame($first['payment_id'], $second['payment_id']);

                // Exactly one payment, one invoice posted, exactly two posting batches (invoice + receipt)
                $this->assertDatabaseCount('customer_payments', 1);
                $this->assertDatabaseCount('posting_batches', 3); // Opening stock, invoice and receipt.
                $this->assertDatabaseCount('stock_movements', 2); // Opening stock and exactly one sale.
                $this->assertDatabaseHas('inventory_balances', ['company_id' => $company->id, 'quantity_base' => '1.000000']);
                $this->assertDatabaseCount('command_receipts', 2); // Invoice command and combined sale; replay adds neither.
                $this->assertSame('posted', $invoice->fresh()->status);
            } finally {
                $a?->stop();
                $b?->stop();
                foreach (glob($directory.'/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }

    public function test_competing_requests_on_same_draft_permit_exactly_one_sale(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $schema->migrate();
            [$company, $owner, $invoice, $token] = $this->setupFixture($schema);

            $directory = sys_get_temp_dir().'/fin1_sale_race_'.bin2hex(random_bytes(8));
            mkdir($directory, 0700);
            $a = $b = null;
            try {
                // Independently authorized actors review the same stocked draft.
                $colleague = User::factory()->create();
                $company->users()->attach($colleague->id, ['is_owner' => false, 'status' => 'active']);
                $role = Role::create(['name' => 'Synthetic Sale Colleague', 'guard_name' => 'web', 'company_id' => $company->id]);
                $role->givePermissionTo(['sales.invoice.view', 'sales.invoice.post', 'sales.invoice.change_price', 'money.receipt.create', 'money.receipt.allocate']);
                $colleague->assignRole($role);
                $this->actingAs($colleague);
                app(CompanyContext::class)->setCompany($company, $colleague);
                $cash = MoneyAccount::where('company_id', $company->id)->where('currency_code', 'ILS')->firstOrFail();
                $colleaguePreview = app(ImmediateSaleCommands::class)->preview($invoice, $colleague, [
                    'mode' => 'full', 'money_account_id' => (int) $cash->id, 'payment_method' => 'cash',
                    'payment_date' => '2026-10-29', 'amount' => '100', 'exchange_rate' => '1',
                ]);
                $inputA = [
                    'actor_id' => $colleague->id,
                    'invoice_id' => $invoice->id,
                    'token' => $colleaguePreview['confirmation_token'],
                    'key' => 'competing-key-A',
                    'company_id' => $company->id,
                ];
                $inputB = [
                    'actor_id' => $owner->id,
                    'invoice_id' => $invoice->id,
                    'token' => $token,
                    'key' => 'competing-key-B',
                    'company_id' => $company->id,
                ];

                $a = $this->start($schema, $directory, 'worker-a', $inputA);
                $b = $this->start($schema, $directory, 'worker-b', $inputB);
                touch($directory.'/go');

                $this->assertSame(0, $a->wait(), $a->getErrorOutput());
                $this->assertSame(0, $b->wait(), $b->getErrorOutput());

                $first = json_decode($a->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $second = json_decode($b->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                // Exactly one must succeed and one must conflict (409)
                $outcomes = [$first['outcome'], $second['outcome']];
                $this->assertEqualsCanonicalizing(['success', 'conflict'], $outcomes);

                $conflict = $first['outcome'] === 'conflict' ? $first : $second;
                $this->assertSame(409, $conflict['status']);

                $this->assertDatabaseCount('customer_payments', 1);
                $this->assertDatabaseCount('posting_batches', 3);
                $this->assertDatabaseCount('stock_movements', 2);
                $this->assertDatabaseHas('inventory_balances', ['company_id' => $company->id, 'quantity_base' => '1.000000']);
                $this->assertSame('posted', $invoice->fresh()->status);
            } finally {
                $a?->stop();
                $b?->stop();
                foreach (glob($directory.'/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }
}
