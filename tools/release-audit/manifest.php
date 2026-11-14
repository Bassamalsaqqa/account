<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$head = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
$status = trim((string) shell_exec('git -C '.escapeshellarg($root).' status --porcelain 2>/dev/null'));

$manifestFiles = [
    'tools/release-audit/Guard.php',
    'tools/release-audit/Fingerprint.php',
    'tools/release-audit/audit.php',
    'tools/release-audit/compare.php',
    'tools/release-audit/manifest.php',
    'tests/Unit/ReleaseAudit/RefusalTest.php',
    'tests/Unit/ReleaseAudit/ReleaseAuditDatabaseTest.php',
    'tests/Unit/ReleaseAudit/OwnershipLifecycleRefusalTest.php',
    'tests/Unit/ReleaseAudit/compare_classification_test.py',
    'docs/READ_ONLY_RELEASE_AUDIT.md',
];

$hashes = [];
$missing = false;
foreach ($manifestFiles as $f) {
    $full = $root.'/'.$f;
    if (is_file($full)) {
        $hashes[$f] = hash_file('sha256', $full);
    } else {
        $hashes[$f] = 'MISSING';
        $missing = true;
    }
}

$isClean = ($status === '' && ! $missing && preg_match('/^[a-f0-9]{40}$/D', $head) === 1);

$output = [
    'git_head' => $head,
    'clean' => $isClean,
    'git_status' => $status,
    'files' => $hashes,
];

if (in_array('--json', $argv, true)) {
    echo json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
} else {
    echo "Git HEAD: {$head}\n";
    echo 'Git Status: '.($status === '' ? 'CLEAN' : "DIRTY\n{$status}")."\n\n";
    echo "File SHA256 Manifest:\n";
    foreach ($hashes as $f => $h) {
        printf("%-64s  %s\n", $h, $f);
    }
}

if (! $isClean) {
    fwrite(STDERR, "MANIFEST_ERROR: working directory is dirty, files missing, or invalid HEAD SHA\n");
    exit(1);
}

exit(0);
