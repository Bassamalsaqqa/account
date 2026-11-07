<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

final class PurchaseFormSnapshotConcurrencyTest extends TestCase
{
    private ?DisposableMariaDbSchema $fixture = null;

    protected function tearDown(): void
    {
        try {
            $this->fixture?->restoreLaravelConnection();
            $this->fixture?->drop();
        } finally {
            parent::tearDown();
        }
    }

    public static function modes(): array
    {
        return PurchaseFormSnapshotReplayTest::modes();
    }

    #[DataProvider('modes')]
    public function test_concurrent_identical_original_snapshots_converge_without_immutable_draft_updates(bool $existing, bool $paid): void
    {
        $primary = DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($primary);
        $primaryCounts = collect(['companies', 'purchases', 'purchase_confirmation_receipts', 'posting_batches', 'stock_movements'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
        $this->fixture = DisposableMariaDbSchema::createFromSource($primary);
        $this->fixture->switchLaravelConnection();
        $actor = User::factory()->create();
        $company = app(CreateCompanyAction::class)->execute($actor, ['name_ar' => 'اختبار تكرار التأكيد', 'name_en' => 'Confirmation Race']);
        app(CompanyContext::class)->setCompany($company, $actor);
        $this->actingAs($actor);
        setPermissionsTeamId($company->id);
        $vendor = app(VendorCatalogService::class)->save($company, $actor, ['name_ar' => 'مورد الاختبار', 'name_en' => 'Race Vendor']);
        $product = app(ProductCatalogService::class)->createProduct($company, [
            'name_ar' => 'منتج الاختبار', 'name_en' => 'Race Product', 'sku' => 'RACE',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => false,
            'base_unit_id' => Unit::where('company_id', $company->id)->where('code', 'piece')->sole()->id,
        ], $actor->id);
        $form = Livewire::test(PurchaseForm::class)->set('vendor_id', $vendor->id)->call('selectProduct', 0, $product->id)
            ->set('lines.0.quantity', '2')->set('lines.0.unit_cost', '25.125');
        if ($existing) {
            $form->call('saveDraft')->assertHasNoErrors();
            $form = Livewire::test(PurchaseForm::class, ['publicId' => $form->get('savedDraftPublicId')]);
        }
        if ($paid) {
            $form->set('settlement.mode', 'full')->set('settlement.money_account_id', MoneyAccount::where('company_id', $company->id)
                ->where('currency_code', 'ILS')->where('account_type', 'cash')->firstOrFail()->id);
        }
        $this->assertSame(0, DB::transactionLevel());
        $directory = sys_get_temp_dir().'/purchase-confirmation-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $environment = array_replace($this->fixture->environment(), [
            'APP_KEY' => (string) config('app.key'),
            'PHASE8_TEST_DB_HOST' => '127.0.0.1', 'PHASE8_TEST_DB_PORT' => '3342',
            'PHASE8_TEST_DB_USERNAME' => 'test_runner', 'PHASE8_TEST_DB_PASSWORD' => '',
        ]);
        $processes = [];
        $locked = false;
        try {
            DB::beginTransaction();
            $locked = true;
            DB::table('companies')->where('id', $company->id)->lockForUpdate()->first();
            foreach ([0, 1] as $index) {
                $path = $directory.'/payload-'.$index.'.json';
                file_put_contents($path, json_encode([
                    'actor_id' => $actor->id, 'company_id' => $company->id, 'snapshot' => $form->snapshot,
                    'ready' => $directory.'/ready-'.$index, 'attempting' => $directory.'/attempting-'.$index, 'release' => $directory.'/release',
                ], JSON_THROW_ON_ERROR));
                chmod($path, 0600);
                $process = new Process([PHP_BINARY, base_path('tests/Support/Purchasing/purchase-confirmation-race-worker.php'), $path], base_path(), $environment);
                $process->setTimeout(45)->start();
                $processes[] = $process;
            }
            $this->waitForBarriers($directory, 'ready', $processes);
            file_put_contents($directory.'/release', 'release');
            $this->waitForBarriers($directory, 'attempting', $processes);
            usleep(200000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), $process->getErrorOutput());
                $this->assertSame('', $process->getOutput());
            }
            DB::commit();
            $locked = false;
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $purchase = Purchase::sole();
            $this->assertTrue($purchase->isPosted());
            $this->assertSame('50.250000', $purchase->grand_total_currency);
            $this->assertSame(route('purchases.show', $purchase->public_id), $results[0]['redirect']);
            $this->assertSame($results[0]['redirect'], $results[1]['redirect']);
            $this->assertSame($results[0]['snapshot']['data']['completedConfirmation'], $results[1]['snapshot']['data']['completedConfirmation']);
            $this->assertSame(1, DB::table('purchase_confirmation_receipts')->count());
            $this->assertSame(1, DB::table('command_receipts')->count());
            $this->assertSame(1, DB::table('stock_movements')->count());
            $this->assertSame($paid ? 1 : 0, DB::table('vendor_payments')->count());
            $this->assertSame($paid ? 2 : 1, DB::table('posting_batches')->count());
        } finally {
            if ($locked) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
        $this->fixture->restoreLaravelConnection();
        $this->fixture->drop();
        $this->fixture = null;
        foreach ($primaryCounts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }
    }

    /** @param list<Process> $processes */
    private function waitForBarriers(string $directory, string $barrier, array $processes): void
    {
        $deadline = microtime(true) + 20;
        do {
            if (is_file($directory.'/'.$barrier.'-0') && is_file($directory.'/'.$barrier.'-1')) {
                return;
            }
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail('Confirmation race worker exited: '.$process->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Confirmation race barrier timed out.');
    }
}
