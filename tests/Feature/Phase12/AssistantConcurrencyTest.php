<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Models\AssistantRequest;
use App\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\Phase12\AssistantFixtures;
use Tests\TestCase;

class AssistantConcurrencyTest extends TestCase
{
    use AssistantFixtures;

    private ?DisposableMariaDbSchema $fixtureSchema = null;

    private array $primaryCounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $primary = DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($primary);
        foreach (['assistant_requests', 'sales_invoices', 'posting_batches', 'stock_movements', 'companies', 'users'] as $table) {
            $this->primaryCounts[$table] = DB::table($table)->count();
        }
        $this->fixtureSchema = DisposableMariaDbSchema::createFromSource($primary);
        $this->fixtureSchema->switchLaravelConnection();
        app(CompanyContext::class)->clear();
        $this->assistantFixtures();
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        try {
            $this->fixtureSchema?->restoreLaravelConnection();
            $this->fixtureSchema?->drop();
            foreach ($this->primaryCounts as $table => $count) {
                $this->assertSame($count, DB::table($table)->count(), 'No race fixtures may leak into the primary schema.');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_same_key_converges_to_one_reservation_on_independent_connections(): void
    {
        $key = (string) Str::uuid();
        $results = $this->race([$key, $key], [str_repeat('a', 64), str_repeat('a', 64)]);
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $created = array_column($results, 'created');
        sort($created);
        $this->assertSame([false, true], $created);
        $this->assertReservationOnly();
    }

    public function test_distinct_keys_cannot_concurrently_exceed_the_same_company_or_actor_budget(): void
    {
        $results = $this->race([(string) Str::uuid(), (string) Str::uuid()], [str_repeat('a', 64), str_repeat('a', 64)]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['budget', 'ok'], $statuses);
        $this->assertReservationOnly();
    }

    public function test_same_key_with_conflicting_description_hash_cannot_replay(): void
    {
        $key = (string) Str::uuid();
        $results = $this->race([$key, $key], [str_repeat('a', 64), str_repeat('b', 64)]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['ok', 'request_conflict'], $statuses);
        $this->assertReservationOnly();
    }

    private function assertReservationOnly(): void
    {
        $this->assertSame(1, AssistantRequest::count());
        $this->assertSame('1000', (string) AssistantRequest::sum('reserved_cost_micros'));
        foreach (['sales_invoices', 'posting_batches', 'stock_movements', 'command_receipts'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    private function race(array $keys, array $hashes): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase12-budget-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $directory = realpath($directory);
        $files = [];
        $processes = [];
        try {
            $environment = $this->fixtureSchema->environment();
            foreach (DisposableMariaDbSchema::testSettings() as $key => $value) {
                $environment['PHASE8_TEST_DB_'.$key] = $value;
            }
            $environment['APP_KEY'] = config('app.key');
            $environment['CACHE_STORE'] = 'array';
            foreach ([0, 1] as $index) {
                $path = $directory.DIRECTORY_SEPARATOR.'input-'.$index.'.json';
                $ready = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $attempting = $directory.DIRECTORY_SEPARATOR.'attempting-'.$index;
                $release = $directory.DIRECTORY_SEPARATOR.'release';
                file_put_contents($path, json_encode(['company_id' => $this->company->id, 'actor_id' => $this->owner->id,
                    'key' => $keys[$index], 'hash' => $hashes[$index], 'ready' => $ready, 'attempting' => $attempting, 'release' => $release], JSON_THROW_ON_ERROR));
                chmod($path, 0600);
                array_push($files, $path, $ready, $attempting);
                $files[] = $release;
                $process = new Process([PHP_BINARY, 'tests/Support/Phase12/budget-race-worker.php', $path], base_path(), $environment);
                $process->setTimeout(35);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 25;
            foreach ([0, 1] as $index) {
                while (! is_file($directory.DIRECTORY_SEPARATOR.'ready-'.$index)) {
                    if (microtime(true) > $deadline || ! $processes[$index]->isRunning()) {
                        $this->fail('Worker failed before race barrier: '.$processes[$index]->getErrorOutput());
                    }
                    usleep(10000);
                }
            }
            DB::beginTransaction();
            Company::whereKey($this->company->id)->lockForUpdate()->firstOrFail();
            file_put_contents($release, 'release');
            foreach ([0, 1] as $index) {
                while (! is_file($directory.DIRECTORY_SEPARATOR.'attempting-'.$index)) {
                    if (microtime(true) > $deadline) {
                        $this->fail('Workers did not contend.');
                    }
                    usleep(10000);
                }
            }
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (array_unique($files) as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }
}
