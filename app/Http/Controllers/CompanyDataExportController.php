<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\CompanyDataExport\Security\ExportGuard;
use App\Application\CompanyDataExport\Services\CompanyDataExportService;
use App\Application\CompanyDataExport\Support\ExportDelivery;
use App\Application\CompanyDataExport\Support\ExportLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use PDOException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class CompanyDataExportController
{
    /**
     * Download guarded, Owner-only point-in-time company data export archive.
     */
    public function __invoke(
        Request $request,
        ExportGuard $guard,
        CompanyDataExportService $exportService
    ): StreamedResponse {
        // 1. Pre-flight authentication, company context, owner role, password confirmation, and rate limit
        $authData = $guard->authorizeRequest($request);
        $company = $authData['company'];
        $user = $authData['user'];
        $fingerprint = $authData['fingerprint'];

        // 2. Bounded concurrency (global concurrency 1 and company lock via flock)
        $lock = new ExportLock;
        $lock->acquire($company);

        try {
            // 3. Generate disk-backed CSVs, manifest.json, and compressed ZIP archive
            $exportResult = $exportService->export($company, $user, $fingerprint, $request);
        } catch (Throwable $e) {
            $lock->release();
            $this->handleExportException($e);
        }

        $zipPath = $exportResult->zipPath;
        $tempDir = $exportResult->tempDir;
        $archiveFilename = $exportResult->archiveFilename;

        // 4. Stream response with live authority re-validation and cleanup
        return response()->streamDownload(
            function () use ($zipPath, $tempDir, $lock, $guard, $company, $user, $fingerprint, $request): void {
                try {
                    $delivery = new ExportDelivery;
                    $delivery->send($zipPath, function () use ($guard, $fingerprint, $company, $user, $request): bool {
                        try {
                            $guard->assertFingerprintValid($fingerprint, $company, $user, $request);

                            return true;
                        } catch (Throwable) {
                            return false;
                        }
                    });
                } finally {
                    // Always clean up private temporary export files
                    if (is_file($zipPath)) {
                        @unlink($zipPath);
                    }
                    CompanyDataExportService::cleanDirectory($tempDir);
                    $lock->release();
                }
            },
            $archiveFilename,
            [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Length' => (string) $exportResult->byteSize,
            ]
        );
    }

    /**
     * Sanitize and localize export errors without exposing paths or SQL internals.
     */
    private function handleExportException(Throwable $e): never
    {
        if ($e instanceof AuthorizationException) {
            abort(403, $e->getMessage() ?: __('company_data_export.unauthorized_owner_only'));
        }

        if ($e instanceof HttpExceptionInterface) {
            throw $e;
        }

        $message = $e->getMessage();

        if ($message === __('company_data_export.execution_timeout')) {
            abort(503, __('company_data_export.execution_timeout'));
        }

        if ($message === __('company_data_export.row_limit_exceeded')) {
            abort(422, __('company_data_export.row_limit_exceeded'));
        }

        if ($message === __('company_data_export.byte_limit_exceeded')) {
            abort(422, __('company_data_export.byte_limit_exceeded'));
        }

        if ($message === __('company_data_export.memory_limit_exceeded')) {
            abort(422, __('company_data_export.memory_limit_exceeded'));
        }

        if ($message === __('company_data_export.export_in_progress')) {
            abort(429, __('company_data_export.export_in_progress'));
        }

        // Database or query exceptions: sanitize to 503 service unavailable
        if ($e instanceof QueryException || $e instanceof PDOException) {
            abort(503, __('company_data_export.execution_timeout'));
        }

        // Sanitized generic fallback
        abort(500, __('company_data_export.archive_creation_failed'));
    }
}
