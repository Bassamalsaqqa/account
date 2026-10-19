<?php

declare(strict_types=1);

namespace App\Application\CompanyDataExport\Support;

use App\Models\Company;
use RuntimeException;

final class ExportLock
{
    /** @var resource|null */
    private $globalLockHandle = null;

    /** @var resource|null */
    private $companyLockHandle = null;

    private ?string $globalLockPath = null;

    private ?string $companyLockPath = null;

    /**
     * Acquire a non-blocking exclusive filesystem lock for global concurrency 1
     * AND a company-specific lock.
     * The lock files persist on disk across releases to prevent inode deletion races.
     *
     * @throws RuntimeException
     */
    public function acquire(Company $company): void
    {
        $dir = (string) config('company_data_export.lock_directory', storage_path('app/private/company_exports/locks'));

        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create export lock directory.');
        }

        // 1. Global concurrency 1 lock
        $this->globalLockPath = $dir.DIRECTORY_SEPARATOR.'global_export.lock';
        $globalHandle = fopen($this->globalLockPath, 'c+');

        if ($globalHandle === false) {
            throw new RuntimeException('Unable to open global export lock file.');
        }

        if (! flock($globalHandle, LOCK_EX | LOCK_NB)) {
            fclose($globalHandle);
            abort(429, __('company_data_export.export_in_progress'));
        }

        $this->globalLockHandle = $globalHandle;

        // 2. Company-specific lock
        $this->companyLockPath = $dir.DIRECTORY_SEPARATOR.'company_export_'.(int) $company->id.'.lock';
        $companyHandle = fopen($this->companyLockPath, 'c+');

        if ($companyHandle === false) {
            $this->release();
            throw new RuntimeException('Unable to open company export lock file.');
        }

        if (! flock($companyHandle, LOCK_EX | LOCK_NB)) {
            fclose($companyHandle);
            $this->release();
            abort(429, __('company_data_export.export_in_progress'));
        }

        $this->companyLockHandle = $companyHandle;
    }

    /**
     * Release the filesystem locks and close the file handles.
     * Never unlink the lock files: unlinking would create an inode race where concurrent
     * processes open different inodes and violate single-writer concurrency guarantees.
     */
    public function release(): void
    {
        if (is_resource($this->companyLockHandle)) {
            flock($this->companyLockHandle, LOCK_UN);
            fclose($this->companyLockHandle);
            $this->companyLockHandle = null;
        }

        if (is_resource($this->globalLockHandle)) {
            flock($this->globalLockHandle, LOCK_UN);
            fclose($this->globalLockHandle);
            $this->globalLockHandle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
