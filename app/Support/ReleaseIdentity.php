<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;

/** Build-time metadata, never a lookup of the latest repository revision. */
final class ReleaseIdentity
{
    /** @return array{version: string, source_sha: ?string, asset_manifest_sha256: ?string} */
    public static function read(string $versionFile, string $metadataFile): array
    {
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
        $version = preg_match('/\Ab[0-9]+\.[0-9]+(?:\.[0-9]+)?\z/', $version) === 1 ? $version : 'unknown';
        $unknown = ['version' => $version, 'source_sha' => null, 'asset_manifest_sha256' => null];
        if (! is_file($metadataFile) || is_link($metadataFile) || filesize($metadataFile) > 1024) {
            return $unknown;
        }
        try {
            $data = json_decode((string) file_get_contents($metadataFile), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $unknown;
        }
        if (! is_array($data) || ($data['schema'] ?? null) !== 1 || ($data['version'] ?? null) !== $version
            || ! is_string($data['source_sha'] ?? null) || preg_match('/\A[0-9a-f]{40}\z/', $data['source_sha']) !== 1
            || ! is_string($data['asset_manifest_sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/', $data['asset_manifest_sha256']) !== 1) {
            return $unknown;
        }

        return ['version' => $version, 'source_sha' => $data['source_sha'], 'asset_manifest_sha256' => $data['asset_manifest_sha256']];
    }
}
