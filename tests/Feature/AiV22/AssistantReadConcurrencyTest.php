<?php

declare(strict_types=1);

namespace Tests\Feature\AiV22;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\AiCompanyPolicy;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

/** Committed synthetic fixtures and independent connections; intentionally no RefreshDatabase. */
class AssistantReadConcurrencyTest extends TestCase
{
    private ?DisposableMariaDbSchema $ownedSchema = null;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $primary = DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($primary);
        $this->ownedSchema = DisposableMariaDbSchema::createFromSource($primary);
        $this->ownedSchema->switchLaravelConnection();
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create();
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'Synthetic read company', 'name_en' => 'Synthetic read company', 'base_currency_code' => 'ILS',
        ]);
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        setPermissionsTeamId($this->company->id);
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'Synthetic customer',
            'name_en' => 'Synthetic customer', 'preferred_locale' => 'en', 'created_by' => $this->owner->id]);
        foreach (['2026-10-05' => '800', '2026-09-05' => '450'] as $date => $amount) {
            $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1',
                'issue_date' => $date, 'lines' => [['item_description' => 'Synthetic read item', 'quantity' => '1', 'unit_price' => $amount]],
            ]);
            app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        }
        AiCompanyPolicy::create(['company_id' => $this->company->id, 'enabled' => true,
            'settings' => ['questions' => ['enabled' => true, 'allowed_reports' => ['sales.summary']]]]);
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        try {
            $this->ownedSchema?->restoreLaravelConnection();
            $this->ownedSchema?->drop();
        } finally {
            parent::tearDown();
        }
    }

    public function test_four_independent_readers_return_identical_canonical_values_without_writes(): void
    {
        $before = $this->economicFingerprint();
        $results = $this->readers(4);
        foreach ($results as $result) {
            $this->assertSame('ok', $result['status']);
            $this->assertSame('800.000000', $result['gross_sales_base']);
            $this->assertSame('450.000000', $result['previous_gross_sales_base']);
        }
        $this->assertSame($before, $this->economicFingerprint());
    }

    public function test_permission_downgrade_after_warmed_authorization_refuses_both_readers(): void
    {
        $before = $this->economicFingerprint();
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'Synthetic No Report Access', 'guard_name' => 'web']);
        $results = $this->readers(2, function () use ($role): void {
            $this->owner->syncRoles($role);
        });
        foreach ($results as $result) {
            $this->assertSame(['status' => 'unauthorized'], $result);
        }
        $this->assertSame($before, $this->economicFingerprint());
    }

    public function test_membership_revocation_after_warmed_authorization_refuses_both_readers(): void
    {
        $before = $this->economicFingerprint();
        $results = $this->readers(2, function (): void {
            CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        });
        foreach ($results as $result) {
            $this->assertSame(['status' => 'unauthorized'], $result);
        }
        $this->assertSame($before, $this->economicFingerprint());
    }

    /** @return array<string,string> */
    private function economicFingerprint(): array
    {
        $fingerprints = [];
        foreach (['sales_invoices', 'sales_invoice_lines', 'posting_batches', 'posting_lines', 'stock_movements', 'assistant_requests'] as $table) {
            $fingerprints[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $fingerprints;
    }

    /**
     * @param  (?callable():void)  $beforeRelease
     * @return list<array<string,mixed>>
     */
    private function readers(int $count, ?callable $beforeRelease = null): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai-v22-read-race-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create owned read barrier directory.');
        }
        $release = $directory.DIRECTORY_SEPARATOR.'release';
        $processes = $readyFiles = $files = [];
        try {
            for ($index = 0; $index < $count; $index++) {
                $ready = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $path = $directory.DIRECTORY_SEPARATOR.'payload-'.$index.'.json';
                file_put_contents($path, json_encode(['company_id' => $this->company->id, 'actor_id' => $this->owner->id,
                    'ready' => $ready, 'release' => $release], JSON_THROW_ON_ERROR));
                chmod($path, 0600);
                $files = array_merge($files, [$path, $ready]);
                $readyFiles[] = $ready;
                $process = new Process([PHP_BINARY, base_path('tests/Support/AiV22/read-profile-worker.php'), $path],
                    base_path(), array_replace($this->ownedSchema->environment(), ['APP_KEY' => (string) config('app.key'), 'MAIL_MAILER' => 'array']));
                $process->setTimeout(45)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 20;
            while (count(array_filter($readyFiles, 'is_file')) !== $count) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Read worker exited before barrier: '.$process->getErrorOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    $this->fail('Read worker barrier timed out.');
                }
                usleep(10000);
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Every independent reader must be waiting at the same release barrier.');
                $this->assertSame('', $process->getOutput(), 'No reader may produce a report before release.');
            }
            if ($beforeRelease !== null) {
                $beforeRelease();
            }
            file_put_contents($release, 'release');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $profilePath = getenv('AI_V22_CONCURRENCY_PATH');
            if (is_string($profilePath) && $profilePath !== '') {
                file_put_contents($profilePath, json_encode(['readers' => $count, 'results' => $results], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
            }

            return $results;
        } finally {
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
}
