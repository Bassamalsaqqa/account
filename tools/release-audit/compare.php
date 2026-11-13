<?php

declare(strict_types=1);

namespace ReleaseAudit;

use Throwable;

require __DIR__.'/Guard.php';

try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
        Guard::refuse();
    }
    $reports = [];
    foreach (array_slice($argv, 1) as $path) {
        Guard::path(dirname($path), false);
        Guard::path($path, true);
        $reports[] = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
    [$a, $b] = $reports;
    if (($a['version'] ?? null) !== 1 || ($b['version'] ?? null) !== 1 || ! isset($a['implementation_sha256'], $a['tables'], $b['tables'])
        || $a['implementation_sha256'] !== ($b['implementation_sha256'] ?? null)) {
        Guard::refuse();
    }
    $equal = $a['tables'] === $b['tables'];
    fwrite(STDOUT, $equal ? "FINGERPRINTS_EQUAL\n" : "FINGERPRINTS_DIFFER\n");
    exit($equal ? 0 : 2);
} catch (Throwable) {
    fwrite(STDERR, "AUDIT_REFUSED\n");
    exit(1);
}
