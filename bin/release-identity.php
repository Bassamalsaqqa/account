<?php

declare(strict_types=1);

// Run only at build/deployment time. This script does not bootstrap Laravel or use a DB.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Support\ReleaseIdentity;
use Symfony\Component\Process\Process;

try {
    $options = getopt('', ['write', 'check', 'check-cache', 'expected-code:']);
    $modes = array_values(array_intersect(['write', 'check', 'check-cache'], array_keys($options)));
    $expected = $options['expected-code'] ?? null;
    if (count($modes) !== 1 || ! is_string($expected) || preg_match('/\A[0-9a-f]{40}\z/', $expected) !== 1) {
        throw new RuntimeException('Use --write, --check or --check-cache with --expected-code=<full source SHA>.');
    }
    $root = dirname(__DIR__);
    $git = static function (array $arguments) use ($root): string {
        $process = new Process(['git', '-C', $root, ...$arguments]);
        $process->setTimeout(10)->mustRun();

        return trim($process->getOutput());
    };
    if (! hash_equals($expected, $git(['rev-parse', 'HEAD'])) || $git(['status', '--porcelain', '--untracked-files=no']) !== '') {
        throw new RuntimeException('Release identity requires the expected clean tracked source revision.');
    }
    $version = trim((string) file_get_contents($root.'/VERSION'));
    $manifest = $root.'/public/build/manifest.json';
    if (preg_match('/\Ab[0-9]+\.[0-9]+(?:\.[0-9]+)?\z/', $version) !== 1 || ! is_file($manifest) || is_link($manifest)) {
        throw new RuntimeException('A valid VERSION and built asset manifest are required.');
    }
    $identity = ['version' => $version, 'source_sha' => $expected, 'asset_manifest_sha256' => hash_file('sha256', $manifest)];
    $metadata = $root.'/bootstrap/cache/release.json';
    if (is_link($metadata) || is_link(dirname($metadata))) {
        throw new RuntimeException('Linked release metadata paths are refused.');
    }
    if ($modes[0] === 'write') {
        $temporary = tempnam(dirname($metadata), 'release-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot stage release metadata.');
        }
        try {
            if (file_put_contents($temporary, json_encode(['schema' => 1, ...$identity], JSON_THROW_ON_ERROR)."\n") === false
                || ! chmod($temporary, 0644) || ! rename($temporary, $metadata)) {
                throw new RuntimeException('Cannot publish release metadata.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
    if (ReleaseIdentity::read($root.'/VERSION', $metadata) !== $identity) {
        throw new RuntimeException('Release metadata does not match source, VERSION and built assets.');
    }
    if ($modes[0] === 'check-cache') {
        $cache = $root.'/bootstrap/cache/config.php';
        $configuration = is_file($cache) && ! is_link($cache) ? require $cache : null;
        if (! is_array($configuration) || ($configuration['release'] ?? null) !== $identity) {
            throw new RuntimeException('Cached release identity is missing or stale.');
        }
    }
    fwrite(STDOUT, 'Release identity verified: '.$version.' '.$expected."\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
