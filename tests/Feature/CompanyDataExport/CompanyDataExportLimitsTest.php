<?php

declare(strict_types=1);

namespace Tests\Feature\CompanyDataExport;

use App\Application\CompanyDataExport\Security\ExportGuard;
use App\Application\CompanyDataExport\Services\CompanyDataExportService;
use App\Application\CompanyDataExport\Support\ExportLock;
use App\Models\AuditEvent;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\Phase8\Phase8TestCase;

final class CompanyDataExportLimitsTest extends Phase8TestCase
{
    private string $ownedDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownedDirectory = storage_path('framework/testing/a2-export-limits-'.bin2hex(random_bytes(12)));
        config([
            'app.debug' => false,
            'company_data_export.temp_directory' => $this->ownedDirectory.'/files',
            'company_data_export.lock_directory' => $this->ownedDirectory.'/locks',
        ]);
        $this->actingAs($this->owner);
        $this->withSession([
            auth()->guard()->getName() => (int) $this->owner->id,
            'active_company_id' => (int) $this->company->id,
            'auth.password_confirmed_at' => time(),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->ownedDirectory)) {
                CompanyDataExportService::cleanDirectory($this->ownedDirectory);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_client_only_sessions_cannot_claim_live_revocation_guarantees(): void
    {
        $request = Request::create('/settings/company-data/download', 'POST');
        $request->setUserResolver(fn () => $this->owner);
        $request->setLaravelSession(app('session.store'));
        config(['session.driver' => 'cookie']);
        try {
            app(ExportGuard::class)->authorizeRequest($request);
            $this->fail('Client-only session storage must fail closed.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, AuditEvent::where('event_key', 'company.data_export.prepared')->count());
    }

    /** @return array<string, array{string, int|float, int, string}> */
    public static function limits(): array
    {
        return [
            'per-dataset rows' => ['max_rows_per_dataset', 1, 422, 'row_limit_exceeded'],
            'total rows' => ['max_total_rows', 1, 422, 'row_limit_exceeded'],
            'compressed bytes' => ['max_bytes', 1, 422, 'byte_limit_exceeded'],
            'uncompressed bytes' => ['max_uncompressed_bytes', 1, 422, 'byte_limit_exceeded'],
            'memory' => ['max_memory_bytes', 1, 422, 'memory_limit_exceeded'],
            'deadline' => ['max_execution_seconds', 0.000001, 503, 'execution_timeout'],
        ];
    }

    #[DataProvider('limits')]
    public function test_limits_fail_closed_without_archive_audit_or_leftover_files(string $key, int|float $limit, int $status, string $messageKey): void
    {
        config(['company_data_export.'.$key => $limit]);
        $preparedBefore = AuditEvent::where('event_key', 'company.data_export.prepared')->count();

        $response = $this->postJson(route('settings.company-data.download'));

        $response->assertStatus($status);
        $response->assertJsonPath('message', __('company_data_export.'.$messageKey));
        $this->assertNoCompletedArchiveOrPrivateDetails($response, $preparedBefore);
    }

    public function test_disabled_export_cannot_prepare_or_deliver_archive(): void
    {
        config(['company_data_export.enabled' => false]);
        $preparedBefore = AuditEvent::where('event_key', 'company.data_export.prepared')->count();

        $response = $this->postJson(route('settings.company-data.download'));

        $response->assertForbidden();
        $this->assertNoCompletedArchiveOrPrivateDetails($response, $preparedBefore);
    }

    public function test_global_lock_prevents_another_company_export_from_starting(): void
    {
        $first = new ExportLock;
        $second = new ExportLock;
        $first->acquire($this->company);
        // The filesystem gate only needs company identity; no second economic fixture is created.
        $otherCompany = new Company;
        $otherCompany->id = (int) $this->company->id + 10000;
        try {
            try {
                $second->acquire($otherCompany);
                $this->fail('A different company acquired the global concurrency slot.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(429, $exception->getStatusCode());
            }
        } finally {
            $second->release();
            $first->release();
        }
        $this->assertFileExists($this->ownedDirectory.'/locks/global_export.lock');
        $this->assertFileDoesNotExist($this->ownedDirectory.'/locks/company_export_'.$otherCompany->id.'.lock');
        $second->acquire($otherCompany);
        $second->release();
        $this->assertFileExists($this->ownedDirectory.'/locks/company_export_'.$otherCompany->id.'.lock');
    }

    private function assertNoCompletedArchiveOrPrivateDetails(TestResponse $response, int $preparedBefore): void
    {
        $this->assertNotSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertNull($response->headers->get('Content-Disposition'));
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString($this->ownedDirectory, $body);
        $this->assertStringNotContainsString(base_path(), $body);
        $this->assertSame($preparedBefore, AuditEvent::where('event_key', 'company.data_export.prepared')->count());
        $base = $this->ownedDirectory.'/files';
        $this->assertSame([], is_dir($base) ? array_values(array_diff(scandir($base) ?: [], ['.', '..'])) : []);
    }
}
