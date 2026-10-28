<?php

declare(strict_types=1);

namespace Tests\Feature\Ux0;

use App\Actions\Company\CreateCompanyAction;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

/** Independent connections and DDL require committed fixtures in a separately owned schema. */
final class QuickPartyConcurrencyTest extends TestCase
{
    private ?DisposableMariaDbSchema $fixtureSchema = null;

    private Company $company;

    private User $owner;

    /** @var array<string,int> */
    private array $primaryCounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $primary = DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($primary);
        foreach (['companies', 'users', 'customers', 'vendors', 'party_creation_requests', 'posting_batches', 'stock_movements'] as $table) {
            $this->primaryCounts[$table] = DB::table($table)->count();
        }
        $this->fixtureSchema = DisposableMariaDbSchema::createFromSource($primary);
        $this->fixtureSchema->switchLaravelConnection();
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create();
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Synthetic quick party race', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);
        setPermissionsTeamId($this->company->id);
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        try {
            $this->fixtureSchema?->restoreLaravelConnection();
            $this->fixtureSchema?->drop();
            foreach ($this->primaryCounts as $table => $count) {
                $this->assertSame($count, DB::table($table)->count(), 'Owned race fixture leaked into primary schema: '.$table);
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @return array<string,mixed> */
    private function intent(string $name = 'Concurrent party'): array
    {
        return ['key' => 'd8c0dd4a-6740-493c-8fe3-c9f4d1b3ab38', 'data' => ['name_ar' => null, 'name_en' => $name, 'code' => 'RACE-NEW']];
    }

    public function test_independent_connections_converge_to_one_party_and_receipt(): void
    {
        foreach (['customer', 'vendor'] as $kind) {
            $intent = $this->intent() + ['kind' => $kind];
            $intent['key'] = (string) Str::uuid();
            $results = $this->race($intent, $intent);
            $this->assertSame('ok', $results[0]['status']);
            $this->assertSame('ok', $results[1]['status']);
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertSame(1, DB::table($kind === 'customer' ? 'customers' : 'vendors')->where('code', 'RACE-NEW')->count());
            $this->assertSame(1, DB::table('party_creation_requests')->where('party_type', $kind)->count());
            $this->assertSame(0, DB::table('posting_batches')->count());
            $this->assertSame(0, DB::table('stock_movements')->count());
        }
    }

    public function test_concurrent_conflicting_payloads_have_one_success_and_one_conflict(): void
    {
        $results = $this->race($this->intent('First payload') + ['kind' => 'customer'], $this->intent('Different payload') + ['kind' => 'customer']);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses);
        $this->assertSame(1, DB::table('customers')->where('code', 'RACE-NEW')->count());
        $this->assertSame(1, DB::table('party_creation_requests')->count());
        $this->assertSame(0, DB::table('posting_batches')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    /**
     * @param  array<string,mixed>  $first
     * @param  array<string,mixed>  $second
     * @param  (?callable():void)  $beforeRelease
     * @return array{array<string,mixed>,array<string,mixed>}
     */
    private function race(array $first, array $second, ?callable $beforeRelease = null): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'quick-party-race-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create owned race directory.');
        }
        $release = $directory.DIRECTORY_SEPARATOR.'release';
        $processes = [];
        $files = [];
        $readyFiles = [];
        $attemptFiles = [];

        $appKey = (string) (config('app.key') ?: getenv('APP_KEY'));
        if ($appKey === '') {
            throw new \RuntimeException('A valid APP_KEY is required for the party race harness.');
        }

        $environment = [
            'APP_KEY' => $appKey,
            'DB_URL' => '',
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'COMMAND_API_ENABLED' => 'false',
        ];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) config('database.connections.mysql.'.$key);
        }
        foreach ([
            'PHASE8_ALLOW_DISPOSABLE_DB', 'PHASE8_TEST_DB_HOST', 'PHASE8_TEST_DB_PORT', 'PHASE8_TEST_DB_USERNAME',
            'PHASE8_TEST_DB_EMPTY_PASSWORD', 'PHASE8_DISPOSABLE_DB_PROOF', 'PHASE8_DISPOSABLE_DB_NONCE', 'APP_CONFIG_CACHE',
        ] as $key) {
            $environment[$key] = getenv($key);
        }
        // Credentials stay in process environment, never payload files or reported results.
        $environment['PHASE8_TEST_DB_PASSWORD'] = getenv('PHASE8_TEST_DB_PASSWORD');
        $environment = array_replace($environment, $this->fixtureSchema->environment());
        $locked = false;
        try {
            DB::beginTransaction();
            $locked = true;
            DB::table('companies')->where('id', $this->company->id)->lockForUpdate()->first();
            foreach ([$first, $second] as $index => $intent) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR.'payload-'.$index.'.json';
                $ready = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $attempting = $directory.DIRECTORY_SEPARATOR.'attempting-'.$index;
                $files = array_merge($files, [$payloadPath, $ready, $attempting]);
                $readyFiles[] = $ready;
                $attemptFiles[] = $attempting;
                file_put_contents($payloadPath, json_encode($intent + [
                    'company_id' => $this->company->id,
                    'actor_id' => $intent['actor_id'] ?? $this->owner->id,
                    'ready' => $ready,
                    'attempting' => $attempting,
                    'release' => $release,
                ], JSON_THROW_ON_ERROR));
                chmod($payloadPath, 0600);
                $process = new Process([PHP_BINARY, base_path('tests/Support/quick-party-race-worker.php'), $payloadPath], base_path(), $environment);
                $process->setTimeout(45)->start();
                $processes[] = $process;
            }
            $this->waitForFiles($readyFiles, $processes);
            file_put_contents($release, 'release');
            $this->waitForFiles($attemptFiles, $processes);
            usleep(200000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Both independent command attempts must overlap behind the company lock.');
                $this->assertSame('', $process->getOutput());
            }
            if ($beforeRelease !== null) {
                $beforeRelease();
            }
            DB::commit();
            $locked = false;
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return [$results[0], $results[1]];
        } finally {
            if ($locked) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (array_merge($files, [$release]) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /**
     * @param  list<string>  $files
     * @param  list<Process>  $processes
     */
    private function waitForFiles(array $files, array $processes): void
    {
        $deadline = microtime(true) + 20;
        do {
            if (count(array_filter($files, 'is_file')) === count($files)) {
                return;
            }
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail('Party race child exited before the barrier: '.$process->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Independent party race barrier timed out.');
    }
}
