<?php

declare(strict_types=1);

namespace App\Application\CompanyDataExport\DTO;

final class ExportResult
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(
        public readonly string $zipPath,
        public readonly string $tempDir,
        public readonly array $manifest,
        public readonly string $archiveFilename,
        public readonly int $byteSize
    ) {}
}
