<?php

declare(strict_types=1);

namespace Tests\Feature\CompanyDataExport;

use App\Application\CompanyDataExport\Security\ExportGuard;
use App\Application\CompanyDataExport\Services\CompanyDataExportService;
use App\Models\AuditEvent;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Phase8\Phase8TestCase;
use ZipArchive;

final class ExportReauthenticationTest extends Phase8TestCase
{
    private string $baseTemp;

    private const KNOWN_PASSWORD = 'SecretOwnerPassword123!';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->baseTemp = storage_path('framework/testing/a2-reauth-'.bin2hex(random_bytes(8)));
        config([
            'company_data_export.temp_directory' => $this->baseTemp.'/files',
            'company_data_export.lock_directory' => $this->baseTemp.'/locks',
        ]);

        $this->owner->update(['password' => Hash::make(self::KNOWN_PASSWORD)]);

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);
        $this->withSession([
            auth()->guard()->getName() => (int) $this->owner->id,
            'active_company_id' => (int) $this->company->id,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->baseTemp)) {
                CompanyDataExportService::cleanDirectory($this->baseTemp);
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Exact browser headers matching company-data-download.js.
     *
     * @return array<string, string>
     */
    private function browserHeaders(): array
    {
        return [
            'Accept' => 'application/json, application/zip',
            'X-Requested-With' => 'XMLHttpRequest',
        ];
    }

    public function test_browser_headers_with_expired_session_returns_423_json_without_redirect_or_artifacts(): void
    {
        $expiredTime = time() - 14400; // 4 hours ago, beyond default auth.password_timeout (10800s)

        $response = $this->withHeaders($this->browserHeaders())
            ->withSession([
                auth()->guard()->getName() => (int) $this->owner->id,
                'active_company_id' => (int) $this->company->id,
                'auth.password_confirmed_at' => $expiredTime,
            ])
            ->post(route('settings.company-data.download'));

        $response->assertStatus(423);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeaderMissing('Location');
        $response->assertDontSee($this->company->name_ar);
        $this->assertFalse($response->isRedirect());
        $response->assertJson([
            'message' => 'Password confirmation required.',
        ]);

        // Verify no audit event was logged for export preparation
        $auditCount = AuditEvent::where('company_id', $this->company->id)
            ->where('event_key', 'company.data_export.prepared')
            ->count();
        $this->assertSame(0, $auditCount);

        // Verify no CSV or ZIP export artifacts were created in temp directory
        $this->assertDirectoryDoesNotExist((string) config('company_data_export.temp_directory'));
    }

    public function test_fortify_password_confirm_store_then_explicit_post_downloads_complete_zip(): void
    {
        // 1. Initial attempt with expired password confirmation must return 423
        $firstResponse = $this->withHeaders($this->browserHeaders())
            ->withSession([
                auth()->guard()->getName() => (int) $this->owner->id,
                'active_company_id' => (int) $this->company->id,
                'auth.password_confirmed_at' => time() - 14400,
            ])
            ->post(route('settings.company-data.download'));

        $firstResponse->assertStatus(423);

        // 2. Confirm password through real named Fortify route
        $confirmResponse = $this->post(route('password.confirm.store'), [
            'password' => self::KNOWN_PASSWORD,
        ], [
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'application/json',
        ]);

        $confirmResponse->assertStatus(201);
        $this->assertSame(0, AuditEvent::where('event_key', 'company.data_export.prepared')->count());

        // 3. Explicit retry POST with exact same browser headers (no auto-export after 423)
        $exportResponse = $this->withHeaders($this->browserHeaders())
            ->post(route('settings.company-data.download'));

        $exportResponse->assertOk();
        $exportResponse->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('attachment;', (string) $exportResponse->headers->get('Content-Disposition'));

        $content = $exportResponse->streamedContent();
        $this->assertNotEmpty($content);

        $expectedLength = (int) $exportResponse->headers->get('Content-Length');
        $this->assertSame(strlen($content), $expectedLength);

        // Verify ZIP contents: manifest.json + 40 datasets = 41 files
        $tempZip = $this->baseTemp.'/verified-download.zip';
        file_put_contents($tempZip, $content);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tempZip));
        $this->assertSame(41, $zip->numFiles);

        $manifestJson = $zip->getFromName('manifest.json');
        $this->assertNotFalse($manifestJson);

        $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('1.0', $manifest['manifest_version']);
        $this->assertCount(40, $manifest['datasets']);
        $this->assertSame((int) $this->company->id, $manifest['company']['id']);
        $this->assertSame((int) $this->owner->id, $manifest['exported_by']['user_id']);

        $zip->close();
        @unlink($tempZip);
        $this->assertEmpty(glob($this->baseTemp.'/files/export_*') ?: []);

        // Verify audit event exists after authorized export
        $auditCount = AuditEvent::where('company_id', $this->company->id)
            ->where('event_key', 'company.data_export.prepared')
            ->count();
        $this->assertSame(1, $auditCount);
    }

    public function test_direct_export_guard_authorize_request_with_stale_timestamp_produces_423(): void
    {
        $guard = app(ExportGuard::class);

        $request = Request::create(route('settings.company-data.download'), 'POST');
        $request->setUserResolver(fn () => $this->owner);

        $session = app('session.store');
        $session->put([
            auth()->guard()->getName() => (int) $this->owner->id,
            'active_company_id' => (int) $this->company->id,
            'auth.password_confirmed_at' => time() - 14400, // 4 hours ago (stale)
        ]);
        $session->save();
        $request->setLaravelSession($session);

        try {
            $guard->authorizeRequest($request);
            $this->fail('Expected HttpException 423 was not thrown.');
        } catch (HttpException $e) {
            $this->assertSame(423, $e->getStatusCode());
            $this->assertSame(__('company_data_export.password_confirmation_required'), $e->getMessage());
        }
    }
}
