<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class PostingArchitectureBoundaryTest extends TestCase
{
    /**
     * Enforce architectural constraint:
     * Only AccountingPostingService may persist PostingBatch and PostingLine records.
     */
    public function test_only_accounting_posting_service_creates_posting_records(): void
    {
        $appPath = realpath(__DIR__.'/../../../app');
        $this->assertNotFalse($appPath);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $allowedFiles = [
            realpath(__DIR__.'/../../../app/Services/Posting/AccountingPostingService.php'),
        ];

        $prohibitedPatterns = [
            'PostingBatch::create',
            'PostingLine::create',
            'PostingBatch::insert',
            'PostingLine::insert',
            "DB::table('posting_batches')->insert",
            "DB::table('posting_lines')->insert",
            'DB::table("posting_batches")->insert',
            'DB::table("posting_lines")->insert',
        ];

        $violations = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getRealPath();
            if (in_array($filePath, $allowedFiles, true)) {
                continue;
            }

            $content = file_get_contents($filePath);
            if ($content === false) {
                continue;
            }

            foreach ($prohibitedPatterns as $pattern) {
                if (str_contains($content, $pattern)) {
                    $violations[] = "File [{$file->getPathname()}] violates posting write boundary by invoking [{$pattern}].";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Architecture boundary violation detected: Only AccountingPostingService may create posting records.\n".implode("\n", $violations)
        );
    }

    /**
     * Enforce architectural constraint:
     * Only AccountingPostingService may update PostingBatch records (narrow reversal state transition).
     * No other application file may update PostingBatch.
     */
    public function test_only_accounting_posting_service_updates_posting_batch(): void
    {
        $appPath = realpath(__DIR__.'/../../../app');
        $this->assertNotFalse($appPath);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $allowedFiles = [
            realpath(__DIR__.'/../../../app/Services/Posting/AccountingPostingService.php'),
        ];

        $violations = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getRealPath();
            if (in_array($filePath, $allowedFiles, true)) {
                continue;
            }

            $content = file_get_contents($filePath);
            if ($content === false) {
                continue;
            }

            if (str_contains($content, 'PostingBatch') || str_contains($content, 'posting_batches')) {
                if (str_contains($content, "DB::table('posting_batches')->update")
                    || str_contains($content, 'DB::table("posting_batches")->update')
                    || str_contains($content, 'PostingBatch::query()->update')
                    || preg_match('/PostingBatch::.*->update\s*\(/', $content) === 1
                    || preg_match('/\$batch->update\s*\(/', $content) === 1
                    || preg_match('/\$batch->save\s*\(/', $content) === 1
                ) {
                    $violations[] = "File [{$file->getPathname()}] violates immutable batch constraint by mutating PostingBatch records.";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Architecture boundary violation detected: Only AccountingPostingService may update PostingBatch.\n".implode("\n", $violations)
        );
    }
}
