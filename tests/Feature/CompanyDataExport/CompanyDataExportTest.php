<?php

declare(strict_types=1);

namespace Tests\Feature\CompanyDataExport;

use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Payroll\ReverseSalaryEntryAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Application\CompanyDataExport\Security\ExportGuard;
use App\Application\CompanyDataExport\Services\CompanyDataExportService;
use App\Application\CompanyDataExport\Support\ExportDelivery;
use App\Application\CompanyDataExport\Support\ExportLock;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\Feature\Phase8\Phase8TestCase;
use ZipArchive;

class CompanyDataExportTest extends Phase8TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $baseTemp = storage_path('framework/testing/a2-export-'.bin2hex(random_bytes(8)));
        config(['company_data_export.temp_directory' => $baseTemp.'/files',
            'company_data_export.lock_directory' => $baseTemp.'/locks']);

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);
        $this->withSession([
            auth()->guard()->getName() => (int) $this->owner->id,
            'active_company_id' => (int) $this->company->id,
        ]);
    }

    public function test_owner_can_successfully_download_complete_company_data_export_zip(): void
    {
        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('settings.company-data.download'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));

        // Capture streamed response content
        $content = $response->streamedContent();
        $this->assertNotEmpty($content);

        // Verify ZIP structure
        $tempZip = tempnam(sys_get_temp_dir(), 'test_zip_');
        $this->assertNotFalse($tempZip);
        file_put_contents($tempZip, $content);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tempZip));

        // Must contain manifest.json
        $manifestJson = $zip->getFromName('manifest.json');
        $this->assertNotFalse($manifestJson);

        $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('1.0', $manifest['manifest_version']);
        $this->assertNotEmpty($manifest['data_as_of']);
        $this->assertSame((int) $this->company->id, $manifest['company']['id']);
        $this->assertSame((int) $this->owner->id, $manifest['exported_by']['user_id']);
        $this->assertSame('complete', $manifest['completeness']['status']);
        $this->assertSame('d27adbad93fce8bd7aaf8afa6cdfaa0e33b91bde', $manifest['source_version']['baseline_commit']);
        $this->assertCount(40, $manifest['datasets']);
        $this->assertGreaterThan(0, $manifest['total_records']);

        // Check each dataset file inside the ZIP and verify row counts match manifest
        foreach ($manifest['datasets'] as $dataset) {
            $filename = $dataset['filename'];
            $csvContent = $zip->getFromName($filename);
            $this->assertNotFalse($csvContent, "Dataset {$filename} is missing from ZIP.");

            // Verify UTF-8 BOM
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csvContent, "Dataset {$filename} missing UTF-8 BOM.");

            // Count CSV rows (excluding header)
            $csvStream = fopen('php://temp', 'w+');
            fwrite($csvStream, substr($csvContent, 3));
            rewind($csvStream);
            fgetcsv($csvStream, null, ',', '"', '');
            $csvRowCount = 0;
            while (fgetcsv($csvStream, null, ',', '"', '') !== false) {
                $csvRowCount++;
            }
            fclose($csvStream);
            $this->assertSame($dataset['row_count'], $csvRowCount, "Row count mismatch in {$filename}");
        }

        $zip->close();
        @unlink($tempZip);
    }

    public function test_tenant_isolation_does_not_leak_other_company_records(): void
    {
        // 1. Create a second independent company in isolated scope
        app(CompanyContext::class)->clear();
        session()->forget('active_company_id');
        CompanyScope::executeWithoutScope(function () {
            $otherOwner = User::factory()->create(['password' => Hash::make('password')]);
            $otherCompany = Company::create([
                'public_id' => '01AN4Z07BY79KA1307SR9X4MV2',
                'name_ar' => 'شركة أخرى معزولة',
                'name_en' => 'Other Company',
                'base_currency_code' => 'ILS',
                'status' => 'active',
            ]);
            CompanyUser::create([
                'company_id' => $otherCompany->id,
                'user_id' => $otherOwner->id,
                'is_owner' => true,
                'status' => 'active',
            ]);

            // Add a customer specifically in Company B
            Customer::create([
                'company_id' => $otherCompany->id,
                'name_ar' => 'عميل الشركة الثانية السري',
                'name_en' => 'Secret Customer B',
                'status' => 'active',
                'created_by' => $otherOwner->id,
            ]);
        });
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        // 2. Export Company A data
        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('settings.company-data.download'));

        $response->assertOk();
        $zipContent = $response->streamedContent();

        $tempZip = tempnam(sys_get_temp_dir(), 'tenant_zip_');
        $this->assertNotFalse($tempZip);
        file_put_contents($tempZip, $zipContent);

        $zip = new ZipArchive;
        $zip->open($tempZip);

        $customersCsv = $zip->getFromName('customers.csv');
        $this->assertNotFalse($customersCsv);
        $this->assertStringNotContainsString('عميل الشركة الثانية السري', $customersCsv);
        $this->assertStringNotContainsString('Secret Customer B', $customersCsv);

        $zip->close();
        @unlink($tempZip);
    }

    public function test_non_owner_is_forbidden_even_if_admin(): void
    {
        // Create an Admin user in Company A who is NOT the Owner
        $adminUser = User::factory()->create(['password' => Hash::make('password')]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $adminUser->id,
            'is_owner' => false,
            'status' => 'active',
        ]);

        $adminRole = Role::where('name', 'Admin')->first();
        if ($adminRole !== null) {
            $adminUser->assignRole($adminRole);
        }

        app(CompanyContext::class)->setCompany($this->company, $adminUser);
        $this->actingAs($adminUser);
        $this->withSession([auth()->guard()->getName() => (int) $adminUser->id]);

        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('settings.company-data.download'));

        $response->assertForbidden();
    }

    public function test_revoked_or_inactive_membership_is_forbidden(): void
    {
        // Inactive status: web tenancy redirects to company selection (302)
        CompanyUser::where('company_id', $this->company->id)
            ->where('user_id', $this->owner->id)
            ->update(['status' => 'inactive']);

        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('settings.company-data.download'));

        $this->assertTrue(in_array($response->status(), [302, 403], true));

        // Active membership but non-owner: guard aborts 403
        CompanyUser::where('company_id', $this->company->id)
            ->where('user_id', $this->owner->id)
            ->update(['status' => 'active', 'is_owner' => false]);

        $response2 = $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('settings.company-data.download'));

        $response2->assertForbidden();
    }

    public function test_unconfirmed_password_is_forbidden(): void
    {
        // Request without auth.password_confirmed_at in session
        $response = $this->postJson(route('settings.company-data.download'));

        $response->assertStatus(423);
    }

    public function test_stale_password_confirmation_is_forbidden(): void
    {
        // 4 hours ago (default timeout is 3 hours / 10800s)
        $response = $this->withSession(['auth.password_confirmed_at' => time() - 14400])
            ->postJson(route('settings.company-data.download'));

        $response->assertStatus(423);
    }

    public function test_changed_password_revokes_export_authority(): void
    {
        $guard = app(ExportGuard::class);
        $request = Request::create('/settings/company-data/download', 'POST');
        $request->setUserResolver(fn () => $this->owner);
        $session = app('session.store');
        $session->put('auth.password_confirmed_at', time());
        $session->put(auth()->guard()->getName(), (int) $this->owner->id);
        $session->put('active_company_id', (int) $this->company->id);
        $session->save();
        $request->setLaravelSession($session);

        $authData = $guard->authorizeRequest($request);
        $fingerprint = $authData['fingerprint'];

        // Change the user's password in database
        $this->owner->update(['password' => Hash::make('new-password-after-auth')]);

        // Guard must detect changed password hash and throw AuthorizationException
        $this->expectException(AuthorizationException::class);
        $guard->assertFingerprintValid($fingerprint, $this->company, $this->owner, $request);
    }

    public function test_session_revocation_during_delivery_halts_delivery(): void
    {
        // Create a dummy archive file for delivery test
        $tempFile = tempnam(sys_get_temp_dir(), 'delivery_test_');
        $this->assertNotFalse($tempFile);
        file_put_contents($tempFile, str_repeat('A', 131072)); // 128 KB (2 chunks)

        $delivery = new ExportDelivery;
        $chunksEmitted = 0;
        $revokedAfterFirstChunk = false;

        $delivery->send(
            $tempFile,
            function () use (&$revokedAfterFirstChunk): bool {
                // Return false after first chunk to simulate session revocation
                if ($revokedAfterFirstChunk) {
                    return false;
                }
                $revokedAfterFirstChunk = true;

                return true;
            },
            function (string $chunk) use (&$chunksEmitted): void {
                $chunksEmitted++;
            }
        );

        // Only 1 chunk should be emitted, second chunk halted
        $this->assertSame(1, $chunksEmitted);
        @unlink($tempFile);
    }

    public function test_destroyed_persisted_session_revokes_authority_even_when_request_still_has_values(): void
    {
        $guard = app(ExportGuard::class);
        $request = Request::create('/settings/company-data/download', 'POST');
        $request->setUserResolver(fn () => $this->owner);
        $session = app('session.store');
        $session->put([
            'auth.password_confirmed_at' => time(),
            auth()->guard()->getName() => (int) $this->owner->id,
            'active_company_id' => (int) $this->company->id,
        ]);
        $session->save();
        $request->setLaravelSession($session);
        $authData = $guard->authorizeRequest($request);

        $guard->assertFingerprintValid($authData['fingerprint'], $this->company, $this->owner, $request);
        $session->getHandler()->destroy($session->getId());
        $this->assertSame((int) $this->owner->id, $session->get(auth()->guard()->getName()));

        $this->expectException(AuthorizationException::class);
        $guard->assertFingerprintValid($authData['fingerprint'], $this->company, $this->owner, $request);
    }

    public function test_secrets_and_passwords_are_strictly_excluded(): void
    {
        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('settings.company-data.download'));

        $response->assertOk();
        $zipContent = $response->streamedContent();

        $tempZip = tempnam(sys_get_temp_dir(), 'secrets_zip_');
        $this->assertNotFalse($tempZip);
        file_put_contents($tempZip, $zipContent);

        $zip = new ZipArchive;
        $zip->open($tempZip);

        // Check manifest and every file
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $content = $zip->getFromIndex($i);
            $this->assertIsString($content);

            // Assert no password hashes
            $this->assertStringNotContainsString('$2y$', $content, "File {$name} leaked bcrypt hash.");
            $this->assertStringNotContainsString('remember_token', $content, "File {$name} leaked remember_token column.");
            $this->assertStringNotContainsString('two_factor_secret', $content, "File {$name} leaked two_factor_secret column.");
        }

        $zip->close();
        @unlink($tempZip);
    }

    public function test_hostile_csv_formula_injection_and_arabic_quotes_and_leading_zeros(): void
    {
        // Create hostile customer with formula name, Arabic quotes & newlines, and leading zero phone
        $hostileName = "=SUM(1,2) + CMD|' /C calc'!A0";
        $arabicText = "مورد \"قديم\",\nفرع غزة المعتمد";

        Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => $arabicText,
            'name_en' => $hostileName,
            'phone' => '0599123456', // Leading zero identifier
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        // Create product with leading zeroes in barcode
        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unit->unit_id,
            'barcode' => '0012345678',
            'type' => 'CODE128',
            'is_primary' => true,
        ]);

        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('settings.company-data.download'));

        $response->assertOk();
        $zipContent = $response->streamedContent();

        $tempZip = tempnam(sys_get_temp_dir(), 'hostile_zip_');
        $this->assertNotFalse($tempZip);
        file_put_contents($tempZip, $zipContent);

        $zip = new ZipArchive;
        $zip->open($tempZip);

        // Check customers.csv
        $customersCsv = $zip->getFromName('customers.csv');
        $this->assertNotFalse($customersCsv);

        // Verify formula prefixing with single quote
        $this->assertStringContainsString("'=SUM(1,2)", $customersCsv);

        // Verify leading zero on phone is preserved with apostrophe encoding (not Excel code formula)
        $this->assertStringContainsString("'0599123456", $customersCsv);

        // Verify Arabic text with quotes and newlines round-trips via fgetcsv
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, substr($customersCsv, 3)); // skip BOM
        rewind($stream);

        $foundArabic = false;
        while (($row = fgetcsv($stream)) !== false) {
            if (in_array($arabicText, $row, true)) {
                $foundArabic = true;
                break;
            }
        }
        fclose($stream);
        $this->assertTrue($foundArabic, 'Arabic multiline text with quotes was distorted in CSV.');

        // Check product_barcodes.csv for leading zero barcode
        $barcodesCsv = $zip->getFromName('product_barcodes.csv');
        $this->assertNotFalse($barcodesCsv);
        $this->assertStringContainsString("'0012345678", $barcodesCsv);

        $zip->close();
        @unlink($tempZip);
    }

    public function test_employee_pii_in_gl_postings_is_redacted_while_preserving_numeric_completeness(): void
    {
        // Use the canonical payroll event and reversal: no direct history writes.
        $this->employee->update(['name' => 'John Doe Secret Salary']);
        $entry = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'recognition_date' => '2026-10-01',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'base_salary' => '5000.000000',
            'bonus' => '0',
            'deduction' => '0',
            'advances' => [],
            'idempotency_key' => 'export-canonical-salary',
        ]);
        app(ReverseSalaryEntryAction::class)->execute(
            $entry,
            $this->owner,
            'John Doe confidential payroll correction',
            '2026-10-02',
        );

        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('settings.company-data.download'));

        $response->assertOk();
        $zipContent = $response->streamedContent();

        $tempZip = tempnam(sys_get_temp_dir(), 'gl_pii_zip_');
        $this->assertNotFalse($tempZip);
        file_put_contents($tempZip, $zipContent);

        $zip = new ZipArchive;
        $zip->open($tempZip);

        $batchesCsv = $zip->getFromName('posting_batches.csv');
        $this->assertNotFalse($batchesCsv);
        $this->assertStringNotContainsString('John Doe', $batchesCsv);
        $this->assertStringContainsString('[CONFIDENTIAL_PAYROLL_ENTRY]', $batchesCsv);

        $linesCsv = $zip->getFromName('posting_lines.csv');
        $this->assertNotFalse($linesCsv);
        $this->assertStringNotContainsString('John Doe', $linesCsv);
        $this->assertStringContainsString('5000.000000', $linesCsv); // Exact numeric amounts preserved

        $zip->close();
        @unlink($tempZip);
    }

    public function test_filesystem_concurrency_lock_preserves_lock_file_without_unlink(): void
    {
        $lock = new ExportLock;
        $lock->acquire($this->company);

        // Concurrent request must fail with 429
        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('settings.company-data.download'));
        $response->assertStatus(429);

        // Release lock
        $lock->release();

        // Lock file MUST remain on disk to prevent inode races
        $dir = (string) config('company_data_export.lock_directory', storage_path('app/private/company_exports/locks'));
        $lockFile = $dir.DIRECTORY_SEPARATOR.'company_export_'.(int) $this->company->id.'.lock';
        $this->assertFileExists($lockFile, 'Lock file was unlinked, which creates an inode race condition.');

        // Next export succeeds using the same persistent lock file
        $successResponse = $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('settings.company-data.download'));
        $successResponse->assertOk();
        $successResponse->streamedContent();
    }

    public function test_temporary_directory_and_files_are_cleaned_up(): void
    {
        $baseTemp = (string) config('company_data_export.temp_directory', storage_path('app/private/company_exports'));
        CompanyDataExportService::cleanDirectory($baseTemp);

        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('settings.company-data.download'));

        $response->assertOk();
        // Consume stream to trigger finally block in streamDownload
        $response->streamedContent();

        // Verify no leftover export_* directories remain in baseTemp
        if (is_dir($baseTemp)) {
            $dirs = array_filter(
                scandir($baseTemp) ?: [],
                fn ($item) => str_starts_with($item, 'export_') && is_dir($baseTemp.DIRECTORY_SEPARATOR.$item)
            );
            $this->assertEmpty($dirs, 'Temporary export directories were not cleaned up.');
        }
    }

    public function test_no_economic_mutation_occurs_during_export(): void
    {
        // Create an invoice first so we have records (non-stock item)
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => Customer::create([
                'company_id' => $this->company->id,
                'name_ar' => 'عميل اختبار',
                'status' => 'active',
                'created_by' => $this->owner->id,
            ])->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-19',
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'Test Service Line',
                    'quantity' => '2.000000',
                    'unit_price' => '50.000000',
                ],
            ],
        ]);
        app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        $this->assertSixReconciliationsHealthy();

        // Snapshot counts before export
        $invoicesBefore = SalesInvoice::count();
        $postingLinesBefore = DB::table('posting_lines')->count();
        $stockMovementsBefore = DB::table('stock_movements')->count();
        $customersBefore = Customer::count();
        $productsBefore = Product::count();
        $auditEventsBefore = AuditEvent::count();
        $financialFingerprintBefore = $this->financialFingerprint();

        $queryCount = 0;
        $measuring = true;
        DB::listen(static function () use (&$queryCount, &$measuring): void {
            if ($measuring) {
                $queryCount++;
            }
        });
        $started = hrtime(true);

        // Run export
        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('settings.company-data.download'));
        $response->assertOk();
        $archive = $response->streamedContent();
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;
        $measuring = false;

        $zipPath = tempnam(sys_get_temp_dir(), 'a2_measurement_');
        $this->assertNotFalse($zipPath);
        file_put_contents($zipPath, $archive);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));
        $manifestText = $zip->getFromName('manifest.json');
        $this->assertIsString($manifestText);
        $manifest = json_decode($manifestText, true, flags: JSON_THROW_ON_ERROR);
        $zip->close();
        unlink($zipPath);

        // Snapshot counts after export
        $this->assertSame($invoicesBefore, SalesInvoice::count());
        $this->assertSame($postingLinesBefore, DB::table('posting_lines')->count());
        $this->assertSame($stockMovementsBefore, DB::table('stock_movements')->count());
        $this->assertSame($customersBefore, Customer::count());
        $this->assertSame($productsBefore, Product::count());
        $this->assertSame($financialFingerprintBefore, $this->financialFingerprint());
        $this->assertSixReconciliationsHealthy();

        $measurementDirectory = base_path('.ai/a2');
        if (! is_dir($measurementDirectory)) {
            mkdir($measurementDirectory, 0700, true);
        }
        file_put_contents($measurementDirectory.'/export-measurement.json', json_encode([
            'provenance' => 'Local disposable MariaDB synthetic canonical posted service invoice; not production or device evidence',
            'fixture' => 'Phase8 foundation plus posted ILS 100 service invoice',
            'elapsed_ms_including_generation_and_delivery' => round($elapsedMs, 2),
            'sql_queries_including_guard_and_delivery_checks' => $queryCount,
            'process_peak_memory_bytes' => memory_get_peak_usage(true),
            'archive_bytes' => strlen($archive),
            'manifest_total_records' => $manifest['total_records'],
            'dataset_count' => count($manifest['datasets']),
            'preservation_sha256_before' => $financialFingerprintBefore,
            'preservation_sha256_after' => $this->financialFingerprint(),
            'six_reconciliations_before_and_after' => 'healthy',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        // Verify only the immutable audit event was logged with 'company.data_export.prepared'
        $this->assertSame($auditEventsBefore + 1, AuditEvent::count());
        $latestAudit = AuditEvent::latest('id')->first();
        $this->assertNotNull($latestAudit);
        $this->assertSame('company.data_export.prepared', $latestAudit->event_key);
        $this->assertSame((int) $this->company->id, (int) $latestAudit->company_id);
        $this->assertSame((int) $this->owner->id, (int) $latestAudit->actor_user_id);
    }

    public function test_large_synthetic_directory_export_is_bounded_and_complete(): void
    {
        $customersBefore = Customer::where('company_id', $this->company->id)->count();
        for ($index = 1; $index <= 1000; $index++) {
            Customer::create([
                'company_id' => $this->company->id,
                'name_ar' => 'عميل تجريبي '.$index,
                'name_en' => 'Synthetic directory customer '.$index,
                'phone' => '0599'.str_pad((string) $index, 6, '0', STR_PAD_LEFT),
                'active' => true,
                'created_by' => $this->owner->id,
            ]);
        }
        app(CompanyContext::class)->clear();
        CompanyScope::executeWithoutScope(function (): void {
            $otherOwner = User::factory()->create();
            $otherCompany = Company::create([
                'name_ar' => 'شركة أخرى معزولة',
                'name_en' => 'Isolated directory company',
                'base_currency_code' => 'ILS',
                'status' => 'active',
            ]);
            CompanyUser::create([
                'company_id' => $otherCompany->id,
                'user_id' => $otherOwner->id,
                'is_owner' => true,
                'status' => 'active',
            ]);
            Customer::create([
                'company_id' => $otherCompany->id,
                'name_ar' => 'عميل سري للشركة الأخرى',
                'name_en' => 'OTHER_COMPANY_DIRECTORY_SENTINEL',
                'active' => true,
                'created_by' => $otherOwner->id,
            ]);
        });
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $queries = 0;
        $measuring = true;
        DB::listen(static function () use (&$queries, &$measuring): void {
            if ($measuring) {
                $queries++;
            }
        });
        $started = hrtime(true);
        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('settings.company-data.download'));
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $archive = $response->streamedContent();
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;
        $measuring = false;

        $zipPath = tempnam(sys_get_temp_dir(), 'a2_directory_');
        $this->assertNotFalse($zipPath);
        file_put_contents($zipPath, $archive);
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($zipPath));
            $manifestJson = $zip->getFromName('manifest.json');
            $this->assertIsString($manifestJson);
            $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('complete', $manifest['completeness']['status']);
            $this->assertFalse($manifest['completeness']['truncated']);
            $customerDataset = collect($manifest['datasets'])->firstWhere('key', 'customers');
            $this->assertNotNull($customerDataset);
            $this->assertSame($customersBefore + 1000, $customerDataset['row_count']);
            $customersCsv = $zip->getFromName('customers.csv');
            $this->assertIsString($customersCsv);
            $this->assertStringNotContainsString('OTHER_COMPANY_DIRECTORY_SENTINEL', $customersCsv);
            $csv = fopen('php://temp', 'w+');
            $this->assertIsResource($csv);
            fwrite($csv, substr($customersCsv, 3));
            rewind($csv);
            fgetcsv($csv, null, ',', '"', '');
            $actualRows = 0;
            while (fgetcsv($csv, null, ',', '"', '') !== false) {
                $actualRows++;
            }
            fclose($csv);
            $this->assertSame($customerDataset['row_count'], $actualRows);
            $this->assertLessThanOrEqual(50_000, $manifest['total_records']);
            $this->assertLessThanOrEqual(52_428_800, strlen($archive));
        } finally {
            $zip->close();
            unlink($zipPath);
        }

        $measurementDirectory = base_path('.ai/a2');
        if (! is_dir($measurementDirectory)) {
            mkdir($measurementDirectory, 0700, true);
        }
        file_put_contents($measurementDirectory.'/export-directory-measurement.json', json_encode([
            'provenance' => 'Local disposable MariaDB directory-scale fixture; not Hostinger, production, physical device or financial-load evidence',
            'fixture' => 'Phase8 foundation plus 1000 synthetic customers; second-company customer excluded',
            'elapsed_ms_including_generation_and_delivery' => round($elapsedMs, 2),
            'sql_queries_including_guard_and_delivery_checks' => $queries,
            'process_peak_memory_bytes' => memory_get_peak_usage(true),
            'archive_bytes' => strlen($archive),
            'manifest_total_records' => $manifest['total_records'],
            'manifest_customer_records' => $customerDataset['row_count'],
            'dataset_count' => count($manifest['datasets']),
            'completeness' => $manifest['completeness']['status'],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function financialFingerprint(): string
    {
        $records = [];
        foreach (['sales_invoices', 'sales_invoice_lines', 'posting_batches', 'posting_lines', 'stock_movements', 'inventory_operations'] as $table) {
            $records[$table] = DB::table($table)->where('company_id', $this->company->id)->orderBy('id')->get()->all();
        }

        return hash('sha256', json_encode($records, JSON_THROW_ON_ERROR));
    }

    private function assertSixReconciliationsHealthy(): void
    {
        $inventory = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($inventory->isHealthy, json_encode($inventory->discrepancies, JSON_THROW_ON_ERROR));
        foreach ([AccountingReconciliationService::class, SalesReconciliationService::class,
            PayablesReconciliationService::class, MoneyReconciliationService::class, Phase7ReconciliationService::class] as $service) {
            $report = app($service)->reconcile($this->company);
            $this->assertTrue($report->isHealthy, $service.': '.json_encode($report->violations, JSON_THROW_ON_ERROR));
        }
    }
}
