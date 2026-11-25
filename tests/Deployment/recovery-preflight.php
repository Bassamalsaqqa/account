#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Small Trader Accounting — Recovery Preflight Standalone Test Suite
 *
 * Standalone PHP 8.4 test harness for bin/recovery-preflight.php.
 * Operates without loading Laravel, Artisan, database, or external network.
 * Executes genuine subprocess invocations against synthetic fixtures in an isolated
 * temporary sandbox, verifying fail-closed behaviors, key redaction, and limits.
 */

namespace SmallTrader\Tests\Deployment;

use RuntimeException;

final class RecoveryPreflightTestRunner
{
    private string $tempDir;

    private string $phpBinary;

    private string $preflightScript;

    private int $testsRun = 0;

    private int $assertionsRun = 0;

    private int $skippedTests = 0;

    private int $originalUmask;

    private array $failures = [];

    public function __construct()
    {
        $this->originalUmask = umask(0077);
        $this->phpBinary = PHP_BINARY;
        $this->preflightScript = realpath(__DIR__.'/../../bin/recovery-preflight.php') ?: '';
        if (! file_exists($this->preflightScript)) {
            fwrite(STDERR, "ERROR: bin/recovery-preflight.php not found at {$this->preflightScript}\n");
            exit(1);
        }

        $baseTemp = sys_get_temp_dir();
        $this->tempDir = $baseTemp.DIRECTORY_SEPARATOR.'p10_recovery_harness_'.bin2hex(random_bytes(8));
        if (! mkdir($this->tempDir, 0700, true)) {
            fwrite(STDERR, "ERROR: Could not create temporary directory at {$this->tempDir}\n");
            exit(1);
        }
    }

    public function runAll(): void
    {
        echo "================================================================================\n";
        echo "RECOVERY PREFLIGHT STANDALONE TEST SUITE (PHP 8.4 SUBPROCESS HARNESS)\n";
        echo "================================================================================\n";
        echo "Target Script: {$this->preflightScript}\n";
        echo "PHP Binary:    {$this->phpBinary}\n";
        echo "Temp Sandbox:  {$this->tempDir}\n";
        echo "--------------------------------------------------------------------------------\n\n";

        try {
            $this->testMissingInvocationArguments();
            $this->testUntrustedManifestDigest();
            $this->testMalformedManifestJson();
            $this->testManifestSecretLeakRejected();
            $this->testCodeIdentityMismatch();
            $this->testCorruptedComponentHash();
            $this->testComponentPathEscapeOrTraversal();
            $this->testFreshTransferOfOldPointStaleRpo();
            $this->testInvertedTimestampsRejected();
            $this->testFutureTimestampsRejected();
            $this->testActiveEnvInScratchRejected();
            $this->testStaleCacheInBootstrapCacheRejected();
            $this->testLegitimateConfigServicesAllowed();
            $this->testNonNumericLoopbackHostRejected();
            $this->testNonDisposableSchemaRejected();
            $this->testTargetUserPolicyRejected();
            $this->testOwnershipProofMismatchRejected();
            $this->testSensitiveSecretRedactedInCliError();
            $this->testSymlinkRefused();
            $this->testPlatformPermissionsReported();
            $this->testValidCurrentModeReturnsExit2();
            $this->testValidHistoricalModeReturnsExit2();
            $this->testValidCurrentReleaseCandidateWithExplicitSchemaCountsReturnsExit2();
            $this->testValidDeployedReleaseWithExplicitSchemaCountsReturnsExit2();
            $this->testValidHistoricalModeWithMatchingExplicitSchemaCountsReturnsExit2();
            $this->testSchemaTablesMismatchRejected();
            $this->testSchemaMigrationsMismatchRejected();
            $this->testCurrentModeWithoutExpectedCountsRejectsModernSchema();
            $this->testHistoricalModeRejectsModernSchemaCounts();
            $this->testCurrentCodeShaUntrustedRejected();
            $this->testMalformedSchemaTablesNonNumericRejected();
            $this->testMalformedSchemaMigrationsZeroRejected();
            $this->testIncompleteSchemaSpecificationRejected();
            $this->testMalformedCodeShaRejected();
            $this->testMalformedManifestSchemaObjectRejected();
            $this->testValidNewReleaseWithExplicitOperatorPinReturnsExit2();
            $this->testUnknownShaWithExplicitSchemaButWithoutBuildPinRejected();
            $this->testExplicitReleasePinWithoutCurrentModeRejected();
            $this->testExplicitReleasePinWithoutSchemaCountsRejected();
            $this->testExplicitReleasePinMismatchedManifestDigestRejected();
            $this->testExplicitReleasePinMismatchedCodeShaRejected();
            $this->testExplicitReleasePinMismatchedBuildDigestRejected();
            $this->testExplicitReleasePinMalformedBuildDigestRejected();
            $this->testExplicitReleasePinMismatchedTablesRejected();
            $this->testExplicitReleasePinMismatchedMigrationsRejected();
            $this->testHistoricalLaneRefusesOperatorReleasePinOverride();
        } finally {
            $this->secureCleanup();
            umask($this->originalUmask);
        }

        echo "\n--------------------------------------------------------------------------------\n";
        echo "TEST SUMMARY:\n";
        $executed = $this->testsRun - $this->skippedTests;
        echo "Tests executed:      {$executed}\n";
        echo "Assertions verified: {$this->assertionsRun}\n";
        echo "Tests NOT RUN:       {$this->skippedTests}\n";
        echo 'Failures:            '.count($this->failures)."\n";

        if (count($this->failures) > 0) {
            echo "\nFAILURES:\n";
            foreach ($this->failures as $f) {
                echo "  - {$f}\n";
            }
            echo "================================================================================\n";
            exit(1);
        }

        echo "STATUS: EXECUTED CHECKS PASSED; REVIEW NOT RUN COVERAGE SEPARATELY\n";
        echo "================================================================================\n";
        exit(0);
    }

    private function testMissingInvocationArguments(): void
    {
        $this->startTest('testMissingInvocationArguments');
        $res = $this->execPreflight(['--manifest=missing.json']);
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for incomplete invocation');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'INVOCATION', 'Reason must be INVOCATION');
    }

    private function testUntrustedManifestDigest(): void
    {
        $this->startTest('testUntrustedManifestDigest');
        $fixture = $this->createFixture('current');
        $untrustedHash = str_repeat('0', 64);

        $res = $this->execPreflight($this->buildArgs($fixture, ['manifest-sha256' => $untrustedHash]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for untrusted manifest hash');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'MANIFEST_TRUST', 'Reason must be MANIFEST_TRUST');
    }

    private function testMalformedManifestJson(): void
    {
        $this->startTest('testMalformedManifestJson');
        $fixtureDir = $this->tempDir.DIRECTORY_SEPARATOR.'malformed_json';
        mkdir($fixtureDir, 0700, true);
        $manifestPath = $fixtureDir.DIRECTORY_SEPARATOR.'manifest.json';
        file_put_contents($manifestPath, '{ not valid json: ');
        $hash = hash_file('sha256', $manifestPath);

        $fixture = $this->createFixture('current');
        $res = $this->execPreflight($this->buildArgs($fixture, [
            'manifest' => $manifestPath,
            'manifest-sha256' => $hash,
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for malformed JSON');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'MALFORMED_OR_UNREADABLE_INPUT', 'Reason must report malformed input');
    }

    private function testManifestSecretLeakRejected(): void
    {
        $this->startTest('testManifestSecretLeakRejected');
        $fixture = $this->createFixture('current');
        // Inject secret key pattern into manifest
        $manifestData = json_decode((string) file_get_contents($fixture['manifestPath']), true);
        $manifestData['leaked_secret'] = 'base64:dGVzdGtleXZhbHVlMTIzNDU2Nzg5MDEyMzQ1Njc4OTA=';
        file_put_contents($fixture['manifestPath'], json_encode($manifestData));
        $newHash = hash_file('sha256', $fixture['manifestPath']);

        $res = $this->execPreflight($this->buildArgs($fixture, ['manifest-sha256' => $newHash]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for secret key leak in manifest');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'SECRET_EXPOSURE_REFUSED', 'Reason must be SECRET_EXPOSURE_REFUSED');
    }

    private function testCodeIdentityMismatch(): void
    {
        $this->startTest('testCodeIdentityMismatch');
        $fixture = $this->createFixture('current');
        $wrongCode = str_repeat('a', 40);

        $res = $this->execPreflight($this->buildArgs($fixture, ['expected-code' => $wrongCode]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for code identity mismatch');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'CODE_BUILD_IDENTITY', 'Reason must be CODE_BUILD_IDENTITY');
    }

    private function testCorruptedComponentHash(): void
    {
        $this->startTest('testCorruptedComponentHash');
        $fixture = $this->createFixture('current');
        // Corrupt sql.enc
        file_put_contents($fixture['files']['sql'], 'TAMPERED_CIPHERTEXT');

        $res = $this->execPreflight($this->buildArgs($fixture));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for tampered component hash');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'COMPONENT_HASH' || ($data['reason'] ?? '') === 'COMPONENT_SIZE', 'Reason must cite component hash/size mismatch');
    }

    private function testComponentPathEscapeOrTraversal(): void
    {
        $this->startTest('testComponentPathEscapeOrTraversal');
        $fixture = $this->createFixture('current');
        $manifestData = json_decode((string) file_get_contents($fixture['manifestPath']), true);
        // Inject directory traversal path
        $manifestData['components']['sql']['file'] = '../escaped.enc';
        file_put_contents($fixture['manifestPath'], json_encode($manifestData));
        $newHash = hash_file('sha256', $fixture['manifestPath']);

        $res = $this->execPreflight($this->buildArgs($fixture, ['manifest-sha256' => $newHash]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for path traversal escape in component');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'COMPONENT_PATH', 'Reason must be COMPONENT_PATH');
    }

    private function testFreshTransferOfOldPointStaleRpo(): void
    {
        $this->startTest('testFreshTransferOfOldPointStaleRpo');
        $threeDaysAgo = gmdate('Y-m-d\TH:i:s\Z', time() - (72 * 3600));
        $tenMinutesAgo = gmdate('Y-m-d\TH:i:s\Z', time() - 600);

        $fixture = $this->createFixture('current', [
            'captured_at' => $threeDaysAgo,
            'completed_at' => $threeDaysAgo,
            'off_host_verified_at' => $tenMinutesAgo,
        ]);

        $res = $this->execPreflight($this->buildArgs($fixture));
        $this->assert($res['exitCode'] === 2, 'Expected exit code 2 (INSPECTION_ONLY_BOOTSTRAP_BLOCKED)');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['provisional_24h_age'] ?? '') === 'STALE', 'Provisional age must be STALE because captured_at is 72h old');
        $this->assert(($data['off_host_custody'] ?? '') === 'DECLARED_NOT_INDEPENDENTLY_VERIFIED', 'Off-host custody must be declared only');
    }

    private function testInvertedTimestampsRejected(): void
    {
        $this->startTest('testInvertedTimestampsRejected');
        $now = gmdate('Y-m-d\TH:i:s\Z', time() - 100);
        $earlier = gmdate('Y-m-d\TH:i:s\Z', time() - 500);

        $fixture = $this->createFixture('current', [
            'captured_at' => $now,
            'completed_at' => $now,
            'off_host_verified_at' => $earlier, // Inverted: off-host precedes captured_at!
        ]);

        $res = $this->execPreflight($this->buildArgs($fixture));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for inverted timestamps');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'OFF_HOST_TIMESTAMP', 'Reason must be OFF_HOST_TIMESTAMP');
    }

    private function testFutureTimestampsRejected(): void
    {
        $this->startTest('testFutureTimestampsRejected');
        $futureTime = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);

        $fixture = $this->createFixture('current', [
            'captured_at' => $futureTime,
            'completed_at' => $futureTime,
        ]);

        $res = $this->execPreflight($this->buildArgs($fixture));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for future captured_at timestamp');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'POINT_TIMESTAMPS', 'Reason must be POINT_TIMESTAMPS');
    }

    private function testActiveEnvInScratchRejected(): void
    {
        $this->startTest('testActiveEnvInScratchRejected');
        $fixture = $this->createFixture('current');
        // Add active .env to scratch root
        file_put_contents($fixture['scratchDir'].DIRECTORY_SEPARATOR.'.env', "APP_ENV=local\n");

        $res = $this->execPreflight($this->buildArgs($fixture));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for active .env in scratch');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'ACTIVE_ARCHIVED_CONFIGURATION', 'Reason must be ACTIVE_ARCHIVED_CONFIGURATION');
    }

    private function testStaleCacheInBootstrapCacheRejected(): void
    {
        $this->startTest('testStaleCacheInBootstrapCacheRejected');
        $fixture = $this->createFixture('current');
        $cacheDir = $fixture['scratchDir'].DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache';
        mkdir($cacheDir, 0700, true);
        file_put_contents($cacheDir.DIRECTORY_SEPARATOR.'config.php', '<?php return [];');

        $res = $this->execPreflight($this->buildArgs($fixture));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for stale config cache in bootstrap/cache');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'ACTIVE_STALE_FRAMEWORK_CACHE', 'Reason must be ACTIVE_STALE_FRAMEWORK_CACHE');
    }

    private function testLegitimateConfigServicesAllowed(): void
    {
        $this->startTest('testLegitimateConfigServicesAllowed');
        $fixture = $this->createFixture('current');
        // Create legitimate config/services.php (which must NOT trigger stale cache error)
        $cfgDir = $fixture['scratchDir'].DIRECTORY_SEPARATOR.'config';
        mkdir($cfgDir, 0700, true);
        file_put_contents($cfgDir.DIRECTORY_SEPARATOR.'services.php', '<?php return ["stripe" => []];');

        // Create quarantined .env inside quarantine folder
        $quarDir = $fixture['scratchDir'].DIRECTORY_SEPARATOR.'quarantine';
        mkdir($quarDir, 0700, true);
        file_put_contents($quarDir.DIRECTORY_SEPARATOR.'.env.production', "ARCHIVED=1\n");

        $res = $this->execPreflight($this->buildArgs($fixture));
        $this->assert($res['exitCode'] === 2, 'Expected exit code 2: legitimate config/services.php must be permitted');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['status'] ?? '') === 'INSPECTION_ONLY_BOOTSTRAP_BLOCKED', 'Must return INSPECTION_ONLY_BOOTSTRAP_BLOCKED');
    }

    private function testNonNumericLoopbackHostRejected(): void
    {
        $this->startTest('testNonNumericLoopbackHostRejected');
        $fixture = $this->createFixture('current');

        $res = $this->execPreflight($this->buildArgs($fixture, ['target-host' => 'localhost']));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for localhost hostname alias');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'TARGET_HOST', 'Reason must be TARGET_HOST');

        $resColon = $this->execPreflight($this->buildArgs($fixture, ['target-host' => '127.0.0.1:3306']));
        $this->assert($resColon['exitCode'] === 1, 'Expected exit code 1 for colon-separated host:port');
    }

    private function testNonDisposableSchemaRejected(): void
    {
        $this->startTest('testNonDisposableSchemaRejected');
        $fixture = $this->createFixture('current');

        $res = $this->execPreflight($this->buildArgs($fixture, ['target-schema' => 'accounting_production']));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for non-disposable schema pattern');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'TARGET_SCHEMA', 'Reason must be TARGET_SCHEMA');
    }

    private function testTargetUserPolicyRejected(): void
    {
        $this->startTest('testTargetUserPolicyRejected');
        $fixture = $this->createFixture('current');

        $res = $this->execPreflight($this->buildArgs($fixture, ['target-user' => 'root']));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for root target user');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'TARGET_USER', 'Reason must be TARGET_USER');
    }

    private function testOwnershipProofMismatchRejected(): void
    {
        $this->startTest('testOwnershipProofMismatchRejected');
        $fixture = $this->createFixture('current');
        // Overwrite ownership proof with mismatching schema
        $mismatchedProof = [
            'host' => '127.0.0.1',
            'port' => '3306',
            'schema' => 'accounting_p10_restore_different12',
            'user' => 'p10_restore_0123456789ab',
            'nonce' => str_repeat('b', 64),
        ];
        file_put_contents($fixture['proofPath'], json_encode($mismatchedProof));

        $res = $this->execPreflight($this->buildArgs($fixture));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for ownership proof binding mismatch');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'OWNERSHIP_BINDING', 'Reason must be OWNERSHIP_BINDING');
    }

    private function testSensitiveSecretRedactedInCliError(): void
    {
        $this->startTest('testSensitiveSecretRedactedInCliError');
        $fixture = $this->createFixture('current');
        $secretSentinel = 'TOP_SECRET_PROD_SENTINEL_TOKEN_12345';

        $res = $this->execPreflight($this->buildArgs($fixture, ['target-user' => $secretSentinel]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for invalid user format');
        $this->assert(! str_contains($res['output'], $secretSentinel), 'Output must NEVER echo submitted sentinel secret');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'TARGET_USER', 'Reason must be clean code TARGET_USER');
    }

    private function testSymlinkRefused(): void
    {
        $this->startTest('testSymlinkRefused');
        $fixture = $this->createFixture('current');
        $targetFile = $this->tempDir.DIRECTORY_SEPARATOR.'external_secret.txt';
        file_put_contents($targetFile, 'SECRET');
        $linkPath = $fixture['scratchDir'].DIRECTORY_SEPARATOR.'link_escape';

        $ok = @symlink($targetFile, $linkPath);
        if ($ok) {
            $res = $this->execPreflight($this->buildArgs($fixture));
            $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for symlink in scratch');
            $data = json_decode($res['stdout'], true);
            $this->assert(($data['reason'] ?? '') === 'SYMLINK_REFUSED', 'Reason must be SYMLINK_REFUSED');
        } else {
            // Symlink creation denied by OS (Windows non-elevated user)
            $this->skippedTests++;
            echo "NOT RUN: OS does not permit symlink creation; source inspection is not an executed test.\n";
        }
    }

    private function testPlatformPermissionsReported(): void
    {
        $this->startTest('testPlatformPermissionsReported');
        $fixture = $this->createFixture('current');

        $res = $this->execPreflight($this->buildArgs($fixture));
        $this->assert($res['exitCode'] === 2, 'Expected exit code 2');
        $data = json_decode($res['stdout'], true);

        if (PHP_OS_FAMILY === 'Windows') {
            $this->assert(($data['permissions'] ?? '') === 'WINDOWS_ACL_NOT_VERIFIED', 'Windows permissions must report WINDOWS_ACL_NOT_VERIFIED');
        } else {
            $this->assert(($data['permissions'] ?? '') === 'POSIX_MODES_CHECKED', 'POSIX permissions must report POSIX_MODES_CHECKED');
        }
    }

    private function testValidCurrentModeReturnsExit2(): void
    {
        $this->startTest('testValidCurrentModeReturnsExit2');
        $fixture = $this->createFixture('current');

        $res = $this->execPreflight($this->buildArgs($fixture));
        $this->assert($res['exitCode'] === 2, 'Expected dedicated exit code 2 (INSPECTION_ONLY_BOOTSTRAP_BLOCKED)');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['status'] ?? '') === 'INSPECTION_ONLY_BOOTSTRAP_BLOCKED', 'Status must be INSPECTION_ONLY_BOOTSTRAP_BLOCKED');
        $this->assert($data['bootstrap_authorized'] === false, 'bootstrap_authorized must strictly be false');
        $this->assert(($data['effective_database_connection'] ?? '') === 'NOT_VERIFIED', 'Effective DB connection must be NOT_VERIFIED');
        $this->assert(($data['network_ingress_egress_jobs'] ?? '') === 'NOT_VERIFIED', 'Network isolation must be NOT_VERIFIED');
        $this->assert(($data['mode'] ?? '') === 'current', 'Mode must be current');
    }

    private function testValidHistoricalModeReturnsExit2(): void
    {
        $this->startTest('testValidHistoricalModeReturnsExit2');
        $fixture = $this->createFixture('historical');

        $res = $this->execPreflight($this->buildArgs($fixture));
        $this->assert($res['exitCode'] === 2, 'Expected dedicated exit code 2 for historical mode');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['mode'] ?? '') === 'historical', 'Mode must be historical');
        $this->assert(($data['provisional_24h_age'] ?? '') === 'NOT_APPLICABLE_TO_CURRENT_RPO', 'Historical age must be NOT_APPLICABLE_TO_CURRENT_RPO');
    }

    private function testValidCurrentReleaseCandidateWithExplicitSchemaCountsReturnsExit2(): void
    {
        $this->startTest('testValidCurrentReleaseCandidateWithExplicitSchemaCountsReturnsExit2');
        $fixture = $this->createFixture(
            'current',
            [],
            'efa257fae488d7ea546b6050bf7e08b47f7f4980',
            ['tables' => 104, 'migrations' => 79]
        );

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'expected-tables' => '104',
            'expected-migrations' => '79',
        ]));
        $this->assert($res['exitCode'] === 2, 'Expected exit code 2 for verified current release candidate');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['status'] ?? '') === 'INSPECTION_ONLY_BOOTSTRAP_BLOCKED', 'Status must be INSPECTION_ONLY_BOOTSTRAP_BLOCKED');
        $this->assert($data['bootstrap_authorized'] === false, 'bootstrap_authorized must strictly be false');
        $this->assert(($data['schema_verified']['tables'] ?? 0) === 104, 'Tables count must be 104');
        $this->assert(($data['schema_verified']['migrations'] ?? 0) === 79, 'Migrations count must be 79');
    }

    private function testValidDeployedReleaseWithExplicitSchemaCountsReturnsExit2(): void
    {
        $this->startTest('testValidDeployedReleaseWithExplicitSchemaCountsReturnsExit2');
        $fixture = $this->createFixture(
            'current',
            [],
            'd6e70547a1f5e022cecc18029e5d7a5bba52bd13',
            ['tables' => 113, 'migrations' => 86]
        );

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'expected-tables' => '113',
            'expected-migrations' => '86',
        ]));
        $this->assert($res['exitCode'] === 2, 'Expected exit code 2 for recorded deployed release d6e7054');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['status'] ?? '') === 'INSPECTION_ONLY_BOOTSTRAP_BLOCKED', 'Status must be INSPECTION_ONLY_BOOTSTRAP_BLOCKED');
        $this->assert($data['bootstrap_authorized'] === false, 'bootstrap_authorized must strictly be false');
    }

    private function testValidHistoricalModeWithMatchingExplicitSchemaCountsReturnsExit2(): void
    {
        $this->startTest('testValidHistoricalModeWithMatchingExplicitSchemaCountsReturnsExit2');
        $fixture = $this->createFixture('historical');

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'expected-tables' => '80',
            'expected-migrations' => '61',
        ]));
        $this->assert($res['exitCode'] === 2, 'Expected exit code 2 for historical mode with matching explicit schema counts');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['mode'] ?? '') === 'historical', 'Mode must be historical');
    }

    private function testSchemaTablesMismatchRejected(): void
    {
        $this->startTest('testSchemaTablesMismatchRejected');
        $fixture = $this->createFixture(
            'current',
            [],
            '8c1fea407a9c288e5d2fbc495dada1a548386941',
            ['tables' => 104, 'migrations' => 79]
        );

        // Operator expects 99 tables, manifest has 104
        $res = $this->execPreflight($this->buildArgs($fixture, [
            'expected-tables' => '99',
            'expected-migrations' => '79',
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for schema tables mismatch');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'SCHEMA_IDENTITY', 'Reason must be SCHEMA_IDENTITY');
    }

    private function testSchemaMigrationsMismatchRejected(): void
    {
        $this->startTest('testSchemaMigrationsMismatchRejected');
        $fixture = $this->createFixture(
            'current',
            [],
            '8c1fea407a9c288e5d2fbc495dada1a548386941',
            ['tables' => 104, 'migrations' => 79]
        );

        // Operator expects 74 migrations, manifest has 79
        $res = $this->execPreflight($this->buildArgs($fixture, [
            'expected-tables' => '104',
            'expected-migrations' => '74',
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for schema migrations mismatch');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'SCHEMA_IDENTITY', 'Reason must be SCHEMA_IDENTITY');
    }

    private function testCurrentModeWithoutExpectedCountsRejectsModernSchema(): void
    {
        $this->startTest('testCurrentModeWithoutExpectedCountsRejectsModernSchema');
        $fixture = $this->createFixture(
            'current',
            [],
            '8c1fea407a9c288e5d2fbc495dada1a548386941',
            ['tables' => 104, 'migrations' => 79]
        );

        // Manifest has modern 104/79, but operator did not provide expected counts (defaults to 83/63)
        $res = $this->execPreflight($this->buildArgs($fixture));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 when modern schema is not supplied by operator');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'SCHEMA_IDENTITY', 'Reason must be SCHEMA_IDENTITY');
    }

    private function testHistoricalModeRejectsModernSchemaCounts(): void
    {
        $this->startTest('testHistoricalModeRejectsModernSchemaCounts');
        $fixture = $this->createFixture('historical');

        // Operator attempts to override historical immutable expectations with modern counts
        $res = $this->execPreflight($this->buildArgs($fixture, [
            'expected-tables' => '104',
            'expected-migrations' => '79',
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for overriding immutable historical schema');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'HISTORICAL_SCHEMA', 'Reason must be HISTORICAL_SCHEMA');
    }

    private function testCurrentCodeShaUntrustedRejected(): void
    {
        $this->startTest('testCurrentCodeShaUntrustedRejected');
        $untrustedSha = str_repeat('e', 40);
        $fixture = $this->createFixture('current', [], $untrustedSha);

        // Operator passes matching expected-code, but SHA is not in pinned CURRENT_CODE_SHA_OPTIONS
        $res = $this->execPreflight($this->buildArgs($fixture, [
            'expected-code' => $untrustedSha,
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for unpinned current code SHA');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'CURRENT_CODE_SHA', 'Reason must be CURRENT_CODE_SHA');
    }

    private function testMalformedSchemaTablesNonNumericRejected(): void
    {
        $this->startTest('testMalformedSchemaTablesNonNumericRejected');
        $fixture = $this->createFixture('current');

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'expected-tables' => 'notanumber',
            'expected-migrations' => '63',
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for non-numeric expected-tables');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'SCHEMA_SPECIFICATION', 'Reason must be SCHEMA_SPECIFICATION');
    }

    private function testMalformedSchemaMigrationsZeroRejected(): void
    {
        $this->startTest('testMalformedSchemaMigrationsZeroRejected');
        $fixture = $this->createFixture('current');

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'expected-tables' => '83',
            'expected-migrations' => '0',
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for zero expected-migrations');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'SCHEMA_SPECIFICATION', 'Reason must be SCHEMA_SPECIFICATION');
    }

    private function testIncompleteSchemaSpecificationRejected(): void
    {
        $this->startTest('testIncompleteSchemaSpecificationRejected');
        $fixture = $this->createFixture('current');

        // Supplies expected-tables without expected-migrations
        $res = $this->execPreflight($this->buildArgs($fixture, [
            'expected-tables' => '83',
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for incomplete schema specification');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'INVOCATION', 'Reason must be INVOCATION');
    }

    private function testMalformedCodeShaRejected(): void
    {
        $this->startTest('testMalformedCodeShaRejected');
        $fixture = $this->createFixture('current');

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'expected-code' => 'not-a-40-char-hex',
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for malformed expected-code format');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'CODE_IDENTITY', 'Reason must be CODE_IDENTITY');
    }

    private function testMalformedManifestSchemaObjectRejected(): void
    {
        $this->startTest('testMalformedManifestSchemaObjectRejected');
        $fixture = $this->createFixture('current');
        $manifestData = json_decode((string) file_get_contents($fixture['manifestPath']), true);
        $manifestData['schema'] = 'invalid_schema_type';
        file_put_contents($fixture['manifestPath'], json_encode($manifestData));
        $newHash = hash_file('sha256', $fixture['manifestPath']);

        $res = $this->execPreflight($this->buildArgs($fixture, ['manifest-sha256' => $newHash]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for non-array schema object in manifest');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'SCHEMA_IDENTITY', 'Reason must be SCHEMA_IDENTITY');
    }

    private function testValidNewReleaseWithExplicitOperatorPinReturnsExit2(): void
    {
        $this->startTest('testValidNewReleaseWithExplicitOperatorPinReturnsExit2');
        $newReleaseSha = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
        $releaseBuildDigest = hash('sha256', 'release_build_115_92_'.$newReleaseSha);
        $fixture = $this->createFixture(
            'current',
            [],
            $newReleaseSha,
            ['tables' => 115, 'migrations' => 92],
            $releaseBuildDigest
        );

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'mode' => 'current',
            'expected-build-sha256' => $releaseBuildDigest,
            'expected-tables' => '115',
            'expected-migrations' => '92',
        ]));
        $this->assert($res['exitCode'] === 2, 'Expected exit code 2 for explicitly operator-pinned new release');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['status'] ?? '') === 'INSPECTION_ONLY_BOOTSTRAP_BLOCKED', 'Status must be INSPECTION_ONLY_BOOTSTRAP_BLOCKED');
        $this->assert($data['bootstrap_authorized'] === false, 'bootstrap_authorized must strictly be false');
        $this->assert(($data['mode'] ?? '') === 'current', 'Mode must be current');
        $this->assert(($data['code_identity_trust'] ?? '') === 'EXPLICIT_OPERATOR_RELEASE_PIN', 'Trust source must record explicit operator release pin');
        $this->assert(($data['schema_verified'] ?? null) === ['tables' => 115, 'migrations' => 92], 'Schema verified must match 115/92');
        $this->assert(($data['actual_restore_and_key_recovery'] ?? '') === 'NOT_VERIFIED', 'Actual restore must remain NOT_VERIFIED');
    }

    private function testUnknownShaWithExplicitSchemaButWithoutBuildPinRejected(): void
    {
        $this->startTest('testUnknownShaWithExplicitSchemaButWithoutBuildPinRejected');
        $newReleaseSha = 'b2c3d4e5f60718293a4b5c6d7e8f901234567890';
        $fixture = $this->createFixture(
            'current',
            [],
            $newReleaseSha,
            ['tables' => 115, 'migrations' => 92]
        );

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'mode' => 'current',
            'expected-tables' => '115',
            'expected-migrations' => '92',
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 when unknown release SHA lacks explicit build pin');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'CURRENT_CODE_SHA', 'Reason must be CURRENT_CODE_SHA');
    }

    private function testExplicitReleasePinWithoutCurrentModeRejected(): void
    {
        $this->startTest('testExplicitReleasePinWithoutCurrentModeRejected');
        $newReleaseSha = 'c3d4e5f60718293a4b5c6d7e8f90123456789012';
        $releaseBuildDigest = hash('sha256', 'release_build_without_mode');
        $fixture = $this->createFixture(
            'current',
            [],
            $newReleaseSha,
            ['tables' => 115, 'migrations' => 92],
            $releaseBuildDigest
        );

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'expected-build-sha256' => $releaseBuildDigest,
            'expected-tables' => '115',
            'expected-migrations' => '92',
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 when explicit release pin omits --mode=current');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'RELEASE_PIN_MODE', 'Reason must be RELEASE_PIN_MODE');
    }

    private function testExplicitReleasePinWithoutSchemaCountsRejected(): void
    {
        $this->startTest('testExplicitReleasePinWithoutSchemaCountsRejected');
        $newReleaseSha = 'd4e5f60718293a4b5c6d7e8f9012345678901234';
        $releaseBuildDigest = hash('sha256', 'release_build_without_schema');
        $fixture = $this->createFixture(
            'current',
            [],
            $newReleaseSha,
            ['tables' => 83, 'migrations' => 63],
            $releaseBuildDigest
        );

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'mode' => 'current',
            'expected-build-sha256' => $releaseBuildDigest,
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 when explicit release pin omits schema counts');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'RELEASE_PIN_SCHEMA', 'Reason must be RELEASE_PIN_SCHEMA');
    }

    private function testExplicitReleasePinMismatchedManifestDigestRejected(): void
    {
        $this->startTest('testExplicitReleasePinMismatchedManifestDigestRejected');
        $newReleaseSha = 'e5f60718293a4b5c6d7e8f901234567890123456';
        $releaseBuildDigest = hash('sha256', 'release_build_mismatched_manifest');
        $fixture = $this->createFixture(
            'current',
            [],
            $newReleaseSha,
            ['tables' => 115, 'migrations' => 92],
            $releaseBuildDigest
        );

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'manifest-sha256' => str_repeat('f', 64),
            'mode' => 'current',
            'expected-build-sha256' => $releaseBuildDigest,
            'expected-tables' => '115',
            'expected-migrations' => '92',
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for mismatched manifest digest on pinned release');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'MANIFEST_TRUST', 'Reason must be MANIFEST_TRUST');
    }

    private function testExplicitReleasePinMismatchedCodeShaRejected(): void
    {
        $this->startTest('testExplicitReleasePinMismatchedCodeShaRejected');
        $newReleaseSha = 'f60718293a4b5c6d7e8f90123456789012345678';
        $otherSha = '1234567890abcdef1234567890abcdef12345678';
        $releaseBuildDigest = hash('sha256', 'release_build_mismatched_code');
        $fixture = $this->createFixture(
            'current',
            [],
            $newReleaseSha,
            ['tables' => 115, 'migrations' => 92],
            $releaseBuildDigest
        );

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'mode' => 'current',
            'expected-code' => $otherSha,
            'expected-build-sha256' => $releaseBuildDigest,
            'expected-tables' => '115',
            'expected-migrations' => '92',
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for mismatched code SHA on pinned release');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'CODE_BUILD_IDENTITY', 'Reason must be CODE_BUILD_IDENTITY');
    }

    private function testExplicitReleasePinMismatchedBuildDigestRejected(): void
    {
        $this->startTest('testExplicitReleasePinMismatchedBuildDigestRejected');
        $newReleaseSha = '0718293a4b5c6d7e8f901234567890123456789a';
        $releaseBuildDigest = hash('sha256', 'release_build_actual');
        $wrongBuildDigest = hash('sha256', 'release_build_wrong');
        $fixture = $this->createFixture(
            'current',
            [],
            $newReleaseSha,
            ['tables' => 115, 'migrations' => 92],
            $releaseBuildDigest
        );

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'mode' => 'current',
            'expected-build-sha256' => $wrongBuildDigest,
            'expected-tables' => '115',
            'expected-migrations' => '92',
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for mismatched build digest on pinned release');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'CODE_BUILD_IDENTITY', 'Reason must be CODE_BUILD_IDENTITY');
    }

    private function testExplicitReleasePinMalformedBuildDigestRejected(): void
    {
        $this->startTest('testExplicitReleasePinMalformedBuildDigestRejected');
        $newReleaseSha = '18293a4b5c6d7e8f901234567890123456789ab0';
        $fixture = $this->createFixture(
            'current',
            [],
            $newReleaseSha,
            ['tables' => 115, 'migrations' => 92]
        );

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'mode' => 'current',
            'expected-build-sha256' => 'not-a-64-char-hex-digest',
            'expected-tables' => '115',
            'expected-migrations' => '92',
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for malformed build digest');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'TRUSTED_BUILD_DIGEST', 'Reason must be TRUSTED_BUILD_DIGEST');
    }

    private function testExplicitReleasePinMismatchedTablesRejected(): void
    {
        $this->startTest('testExplicitReleasePinMismatchedTablesRejected');
        $newReleaseSha = '293a4b5c6d7e8f901234567890123456789abc01';
        $releaseBuildDigest = hash('sha256', 'release_build_mismatched_tables');
        $fixture = $this->createFixture(
            'current',
            [],
            $newReleaseSha,
            ['tables' => 115, 'migrations' => 92],
            $releaseBuildDigest
        );

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'mode' => 'current',
            'expected-build-sha256' => $releaseBuildDigest,
            'expected-tables' => '114',
            'expected-migrations' => '92',
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for mismatched tables count on pinned release');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'SCHEMA_IDENTITY', 'Reason must be SCHEMA_IDENTITY');
    }

    private function testExplicitReleasePinMismatchedMigrationsRejected(): void
    {
        $this->startTest('testExplicitReleasePinMismatchedMigrationsRejected');
        $newReleaseSha = '3a4b5c6d7e8f901234567890123456789abcd012';
        $releaseBuildDigest = hash('sha256', 'release_build_mismatched_migrations');
        $fixture = $this->createFixture(
            'current',
            [],
            $newReleaseSha,
            ['tables' => 115, 'migrations' => 92],
            $releaseBuildDigest
        );

        $res = $this->execPreflight($this->buildArgs($fixture, [
            'mode' => 'current',
            'expected-build-sha256' => $releaseBuildDigest,
            'expected-tables' => '115',
            'expected-migrations' => '91',
        ]));
        $this->assert($res['exitCode'] === 1, 'Expected exit code 1 for mismatched migrations count on pinned release');
        $data = json_decode($res['stdout'], true);
        $this->assert(($data['reason'] ?? '') === 'SCHEMA_IDENTITY', 'Reason must be SCHEMA_IDENTITY');
    }

    private function testHistoricalLaneRefusesOperatorReleasePinOverride(): void
    {
        $this->startTest('testHistoricalLaneRefusesOperatorReleasePinOverride');
        $histBuildDigest = hash('sha256', 'historical_build_override_attempt');
        $histFixture = $this->createFixture('historical', [], null, null, $histBuildDigest);

        $resModeHist = $this->execPreflight($this->buildArgs($histFixture, [
            'mode' => 'historical',
            'expected-build-sha256' => $histBuildDigest,
            'expected-tables' => '80',
            'expected-migrations' => '61',
        ]));
        $this->assert($resModeHist['exitCode'] === 1, 'Historical mode must refuse operator release pin override');
        $dataModeHist = json_decode($resModeHist['stdout'], true);
        $this->assert(($dataModeHist['reason'] ?? '') === 'RELEASE_PIN_MODE', 'Reason must be RELEASE_PIN_MODE');

        $resModeCur = $this->execPreflight($this->buildArgs($histFixture, [
            'mode' => 'current',
            'expected-build-sha256' => $histBuildDigest,
            'expected-tables' => '80',
            'expected-migrations' => '61',
        ]));
        $this->assert($resModeCur['exitCode'] === 1, 'Historical manifest must fail when inspected under current mode');
        $dataModeCur = json_decode($resModeCur['stdout'], true);
        $this->assert(($dataModeCur['reason'] ?? '') === 'MANIFEST_MODE', 'Reason must be MANIFEST_MODE');

        $histShaInCurrentFixture = $this->createFixture(
            'current',
            [],
            '96c310f30a07e97ab8e04d5afbf0b2bb805f4317',
            ['tables' => 80, 'migrations' => 61],
            $histBuildDigest
        );
        $resHistShaInCurrent = $this->execPreflight($this->buildArgs($histShaInCurrentFixture, [
            'mode' => 'current',
            'expected-build-sha256' => $histBuildDigest,
            'expected-tables' => '80',
            'expected-migrations' => '61',
        ]));
        $this->assert($resHistShaInCurrent['exitCode'] === 1, 'Historical code SHA cannot be accepted via current release pin');
        $dataHistShaInCurrent = json_decode($resHistShaInCurrent['stdout'], true);
        $this->assert(($dataHistShaInCurrent['reason'] ?? '') === 'CURRENT_CODE_SHA', 'Reason must be CURRENT_CODE_SHA');
    }

    private function createFixture(string $mode, array $timestampOverrides = [], ?string $codeShaOverride = null, ?array $schemaOverride = null, ?string $buildSha256Override = null): array
    {
        $fixDir = $this->tempDir.DIRECTORY_SEPARATOR.'fix_'.$mode.'_'.bin2hex(random_bytes(4));
        mkdir($fixDir, 0700, true);
        $scratchDir = $fixDir.DIRECTORY_SEPARATOR.'scratch';
        mkdir($scratchDir, 0700, true);

        $pointId = 'p10-point-'.bin2hex(random_bytes(6));
        $isHist = $mode === 'historical';
        $codeSha = $codeShaOverride ?? ($isHist ? '96c310f30a07e97ab8e04d5afbf0b2bb805f4317' : '8d8428261cd2a10690ab77a5c7271e46b5ff5217');

        $nowIso = gmdate('Y-m-d\TH:i:s\Z', time() - 300);
        $capturedAt = $timestampOverrides['captured_at'] ?? $nowIso;
        $completedAt = $timestampOverrides['completed_at'] ?? $nowIso;
        $offHostAt = array_key_exists('off_host_verified_at', $timestampOverrides)
            ? $timestampOverrides['off_host_verified_at']
            : $nowIso;

        $componentNames = ['sql', 'code_build', 'private_files', 'public_assets', 'encrypted_records', 'configuration'];
        $components = [];
        $files = [];

        foreach ($componentNames as $cName) {
            $content = "CIPHERTEXT_{$cName}_".bin2hex(random_bytes(16));
            $filename = "{$cName}.enc";
            $filePath = $fixDir.DIRECTORY_SEPARATOR.$filename;
            file_put_contents($filePath, $content);
            $files[$cName] = $filePath;
            $components[$cName] = [
                'file' => $filename,
                'bytes' => strlen($content),
                'sha256' => hash('sha256', $content),
                'point_id' => $pointId,
            ];
        }

        $defaultSchema = $isHist ? ['tables' => 80, 'migrations' => 61] : ['tables' => 83, 'migrations' => 63];
        $schema = $schemaOverride ?? $defaultSchema;
        $buildSha256 = $buildSha256Override ?? hash('sha256', 'synthetic_build_assets');

        $manifest = [
            'version' => 1,
            'mode' => $mode,
            'point_id' => $pointId,
            'captured_at' => $capturedAt,
            'completed_at' => $completedAt,
            'off_host_verified_at' => $offHostAt,
            'code_sha' => $codeSha,
            'build_sha256' => $buildSha256,
            'schema' => $schema,
            'key_history_refs' => [
                ['id' => 'key-primary-2026', 'cipher' => 'AES-256-CBC'],
            ],
            'config_ref' => 'cfg-ref-production',
            'components' => $components,
        ];

        $manifestPath = $fixDir.DIRECTORY_SEPARATOR.'manifest.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));
        $manifestSha256 = hash_file('sha256', $manifestPath);

        // Ownership proof file
        $schema = 'accounting_p10_restore_0123456789ab';
        $user = 'p10_restore_0123456789ab';
        $proofPath = $fixDir.DIRECTORY_SEPARATOR.'ownership-proof.json';
        $proofData = [
            'host' => '127.0.0.1',
            'port' => '3306',
            'schema' => $schema,
            'user' => $user,
            'nonce' => hash('sha256', 'synthetic_nonce_'.$pointId),
        ];
        file_put_contents($proofPath, json_encode($proofData));

        return [
            'dir' => $fixDir,
            'scratchDir' => $scratchDir,
            'manifestPath' => $manifestPath,
            'manifestSha256' => $manifestSha256,
            'codeSha' => $codeSha,
            'buildSha256' => $buildSha256,
            'proofPath' => $proofPath,
            'schema' => $schema,
            'user' => $user,
            'files' => $files,
        ];
    }

    private function buildArgs(array $fixture, array $overrides = []): array
    {
        $defaults = [
            'manifest' => $fixture['manifestPath'],
            'manifest-sha256' => $fixture['manifestSha256'],
            'expected-code' => $fixture['codeSha'],
            'scratch' => $fixture['scratchDir'],
            'ownership-proof' => $fixture['proofPath'],
            'target-host' => '127.0.0.1',
            'target-port' => '3306',
            'target-schema' => $fixture['schema'],
            'target-user' => $fixture['user'],
        ];

        $merged = array_merge($defaults, $overrides);
        $args = [];
        foreach ($merged as $k => $v) {
            $args[] = "--{$k}={$v}";
        }

        return $args;
    }

    private function execPreflight(array $args): array
    {
        $cmd = [$this->phpBinary, $this->preflightScript, ...$args];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Failed to launch preflight subprocess');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'output' => (string) $stdout.(string) $stderr,
        ];
    }

    private function startTest(string $name): void
    {
        $this->testsRun++;
        echo "  RUN  {$name}... ";
    }

    private function assert(bool $condition, string $message): void
    {
        $this->assertionsRun++;
        if (! $condition) {
            $this->failures[] = $message;
            echo "FAILED: {$message}\n";
            throw new RuntimeException("Assertion failed: {$message}");
        }
        echo "OK\n";
    }

    private function secureCleanup(): void
    {
        echo "\n[CLEANUP] Sanitizing temporary test sandbox...\n";
        $resolved = realpath($this->tempDir);
        $expectedParent = realpath(sys_get_temp_dir());
        if ($resolved === false || $expectedParent === false
            || dirname($resolved) !== $expectedParent
            || ! preg_match('/^p10_recovery_harness_[a-f0-9]{16}$/D', basename($resolved))
            || is_link($this->tempDir)) {
            throw new RuntimeException('Refusing cleanup outside owned synthetic sandbox');
        }
        $this->deleteDirectoryRecursively($this->tempDir);
        echo "[CLEANUP] Containment verified: synthetic test files securely removed.\n";
        echo "[LIMITATION NOTICE] Disk-erasure limitation: User-space file overwriting and\n";
        echo "  unlinking removes filesystem pointer entries, but cannot guarantee physical\n";
        echo "  wear-leveling cell erasure or filesystem journal wiping on SSD/NAND storage.\n";
    }

    private function deleteDirectoryRecursively(string $dir): void
    {
        if (! file_exists($dir)) {
            return;
        }

        $handle = @opendir($dir);
        if ($handle === false) {
            return;
        }

        while (($item = readdir($handle)) !== false) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->deleteDirectoryRecursively($path);
            } else {
                $size = @filesize($path) ?: 0;
                if ($size > 0 && $size < 1048576) {
                    @file_put_contents($path, str_repeat("\0", $size));
                }
                @unlink($path);
            }
        }

        closedir($handle);
        @rmdir($dir);
    }
}

if (PHP_SAPI === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $runner = new RecoveryPreflightTestRunner;
    $runner->runAll();
}
