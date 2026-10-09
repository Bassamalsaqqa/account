<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class CsvReportDelivery
{
    public const int CHUNK_BYTES = 65_536;

    /** @param resource $spool @param callable():bool $authorized @param null|callable(string):void $emit */
    public function send($spool, callable $authorized, ?callable $emit = null): void
    {
        $emit ??= static function (string $chunk): void {
            echo $chunk;
            flush();
        };
        try {
            while (! feof($spool)) {
                $chunk = fread($spool, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read export spool.');
                }
                if ($chunk === '') {
                    break;
                }
                try {
                    $allowed = $authorized();
                } catch (AuthorizationException) {
                    $allowed = false;
                }
                if (! $allowed) {
                    // Headers may already be sent. Stop future bytes; do not claim a late403.
                    Log::notice('Report CSV delivery stopped because authority changed.');
                    break;
                }
                $emit($chunk);
                if (connection_aborted()) {
                    break;
                }
            }
        } finally {
            fclose($spool);
        }
    }
}
