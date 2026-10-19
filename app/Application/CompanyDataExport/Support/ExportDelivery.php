<?php

declare(strict_types=1);

namespace App\Application\CompanyDataExport\Support;

use Illuminate\Support\Facades\Log;
use RuntimeException;

final class ExportDelivery
{
    public const int CHUNK_BYTES = 65_536;

    /**
     * Stream the export zip archive while continually asserting actor authority.
     *
     * @param  callable(): bool  $authorized
     * @param  (callable(string): void)|null  $emit
     */
    public function send(string $zipPath, callable $authorized, ?callable $emit = null): void
    {
        $fp = fopen($zipPath, 'rb');
        if ($fp === false) {
            throw new RuntimeException('Unable to open export archive for streaming.');
        }

        $emit ??= static function (string $chunk): void {
            echo $chunk;
            flush();
        };

        try {
            while (! feof($fp)) {
                $chunk = fread($fp, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read export archive stream.');
                }
                if ($chunk === '') {
                    break;
                }

                if (! $authorized()) {
                    Log::notice('Company data export delivery halted: authority changed or revoked.');
                    break;
                }

                $emit($chunk);

                if (connection_aborted()) {
                    break;
                }
            }
        } finally {
            fclose($fp);
        }
    }
}
