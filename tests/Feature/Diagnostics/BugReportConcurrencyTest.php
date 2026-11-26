<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostics;

use App\Actions\Company\CreateCompanyAction;
use App\Models\BugReport;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

/** Independent connections require committed fixtures, so intentionally no RefreshDatabase. */
final class BugReportConcurrencyTest extends TestCase
{
    private ?DisposableMariaDbSchema $fixtureSchema = null;

    /** @var array<string, int> */
    private array $primaryCounts = [];

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $primary = DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($primary);
        foreach (['companies', 'users', 'bug_reports'] as $table) {
            if (Schema::hasTable($table)) {
                $this->primaryCounts[$table] = DB::table($table)->count();
            }
        }

        $this->fixtureSchema = DisposableMariaDbSchema::createFromSource($primary);
        $this->fixtureSchema->switchLaravelConnection();

        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create();
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة السباق للاختبار',
            'name_en' => 'Race Test Co',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        try {
            $this->fixtureSchema?->restoreLaravelConnection();
            $this->fixtureSchema?->drop();
            foreach ($this->primaryCounts as $table => $count) {
                if (Schema::hasTable($table)) {
                    $this->assertSame($count, DB::table($table)->count());
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_concurrent_identical_idempotency_submissions_converge_to_one_bug_report(): void
    {
        $key = (string) Str::uuid();
        $payload = [
            'idempotency_key' => $key,
            'category' => 'save_error',
            'note' => 'Concurrent identical report note.',
            'include_diagnostics' => true,
            'screen' => [
                'route_name' => 'dashboard',
                'viewport' => 'desktop',
                'livewire_failed' => false,
            ],
        ];

        $results = $this->race($payload, $payload);
        $replays = array_values(array_filter($results, static fn (array $r): bool => ($r['replay'] ?? null) === true));
        $created = array_values(array_filter($results, static fn (array $r): bool => ($r['replay'] ?? null) === false));

        $this->assertCount(1, $created);
        $this->assertCount(1, $replays);
        $this->assertSame($created[0]['id'], $replays[0]['id']);
        $this->assertSame($created[0]['public_ref'], $replays[0]['public_ref']);
        $this->assertSame(1, BugReport::query()->count());
    }

    public function test_concurrent_conflicting_payload_same_idempotency_key_returns_one_conflict_and_preserves_winner(): void
    {
        $key = (string) Str::uuid();
        $first = [
            'idempotency_key' => $key,
            'category' => 'save_error',
            'note' => 'First concurrent payload note.',
            'include_diagnostics' => false,
        ];
        $second = [
            'idempotency_key' => $key,
            'category' => 'save_error',
            'note' => 'Second differing payload note.',
            'include_diagnostics' => false,
        ];

        $results = $this->race($first, $second);
        $ok = array_values(array_filter($results, static fn (array $r): bool => ($r['status'] ?? null) === 'ok'));
        $conflicts = array_values(array_filter($results, static fn (array $r): bool => ($r['status'] ?? null) === 'conflict'));

        $this->assertCount(1, $ok);
        $this->assertCount(1, $conflicts);
        $this->assertSame(409, $conflicts[0]['code']);
        $this->assertSame(1, BugReport::query()->count());

        $stored = BugReport::query()->firstOrFail();
        $this->assertSame($ok[0]['id'], $stored->id);
        $this->assertSame($ok[0]['note'], $stored->note);
    }

    /**
     * @param  array<string, mixed>  $firstPayload
     * @param  array<string, mixed>  $secondPayload
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function race(array $firstPayload, array $secondPayload): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase-diag-race-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create owned bug report race directory.');
        }

        $release = $directory.DIRECTORY_SEPARATOR.'release';
        $processes = [];
        $files = [];
        $readyFiles = [];
        $attemptFiles = [];

        $appKey = (string) (config('app.key') ?: getenv('APP_KEY'));
        if ($appKey === '') {
            throw new \RuntimeException('A valid APP_KEY is required for the bug report race harness.');
        }

        $environment = [
            'APP_KEY' => $appKey,
            'DB_URL' => '',
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
        ];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) config('database.connections.mysql.'.$key);
        }
        foreach ([
            'PHASE8_ALLOW_DISPOSABLE_DB',
            'PHASE8_TEST_DB_HOST',
            'PHASE8_TEST_DB_PORT',
            'PHASE8_TEST_DB_USERNAME',
            'PHASE8_TEST_DB_EMPTY_PASSWORD',
            'PHASE8_DISPOSABLE_DB_PROOF',
            'PHASE8_DISPOSABLE_DB_NONCE',
            'APP_CONFIG_CACHE',
        ] as $key) {
            $val = getenv($key);
            if ($val !== false) {
                $environment[$key] = $val;
            }
        }
        $dbPassword = getenv('PHASE8_TEST_DB_PASSWORD');
        if ($dbPassword !== false) {
            $environment['PHASE8_TEST_DB_PASSWORD'] = $dbPassword;
        }
        $environment = array_replace($environment, $this->fixtureSchema->environment());

        $locked = false;
        try {
            DB::beginTransaction();
            $locked = true;
            DB::table('companies')->where('id', $this->company->id)->lockForUpdate()->first();

            foreach ([$firstPayload, $secondPayload] as $index => $reportPayload) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR.'payload-'.$index.'.json';
                $ready = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $attempting = $directory.DIRECTORY_SEPARATOR.'attempting-'.$index;
                $files = array_merge($files, [$payloadPath, $ready, $attempting]);
                $readyFiles[] = $ready;
                $attemptFiles[] = $attempting;

                file_put_contents($payloadPath, json_encode([
                    'company_id' => $this->company->id,
                    'actor_id' => $this->owner->id,
                    'report_payload' => $reportPayload,
                    'ready' => $ready,
                    'attempting' => $attempting,
                    'release' => $release,
                ], JSON_THROW_ON_ERROR));
                chmod($payloadPath, 0600);

                $process = new Process(
                    [PHP_BINARY, base_path('tests/Support/Diagnostics/bug-report-race-worker.php'), $payloadPath],
                    base_path(),
                    $environment,
                );
                $process->setTimeout(45)->start();
                $processes[] = $process;
            }

            $this->waitForFiles($readyFiles, $processes);
            file_put_contents($release, 'release');
            $this->waitForFiles($attemptFiles, $processes);
            usleep(200000);

            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Workers must block together behind the company lock.');
            }

            DB::commit();
            $locked = false;

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertIsArray($decoded);
                $results[] = $decoded;
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
                    $this->fail('Bug report race worker exited early: '.$process->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('Bug report race barrier timed out.');
    }
}
