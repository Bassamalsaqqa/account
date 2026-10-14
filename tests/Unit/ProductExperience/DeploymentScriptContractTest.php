<?php

declare(strict_types=1);

namespace Tests\Unit\ProductExperience;

use PHPUnit\Framework\TestCase;

class DeploymentScriptContractTest extends TestCase
{
    protected string $scriptPath;

    /** @var list<string> */
    private array $ownedFixtureDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->scriptPath = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'deploy.sh';
    }

    public function test_script_exists_and_has_fail_closed_maintenance_contract(): void
    {
        $this->assertFileExists($this->scriptPath);

        $content = file_get_contents($this->scriptPath);
        $this->assertIsString($content);

        // 1. Must use set -eo pipefail
        $this->assertMatchesRegularExpression('/set\s+-eo\s+pipefail/', $content);

        // 2. Must NOT swallow artisan down errors with || true
        $this->assertDoesNotMatchRegularExpression('/artisan\s+down[^\n]*\|\|\s*true/', $content);

        // 3. Must execute artisan down --retry=60
        $this->assertMatchesRegularExpression('/"\$PHP_BIN"\s+artisan\s+down\s+--retry=60/', $content);

        // 4. MAINTENANCE_ACTIVE=1 must only be set AFTER artisan down
        $downPos = strpos($content, '"$PHP_BIN" artisan down --retry=60');
        $maintenanceActivePos = strpos($content, 'MAINTENANCE_ACTIVE=1', $downPos ?: 0);
        $this->assertNotFalse($downPos, 'artisan down command was not found in deploy.sh');
        $this->assertNotFalse($maintenanceActivePos, 'MAINTENANCE_ACTIVE=1 was not found after artisan down');
        $this->assertGreaterThan($downPos, $maintenanceActivePos, 'MAINTENANCE_ACTIVE=1 must occur after artisan down');

        // 5. composer install and migrations must occur AFTER MAINTENANCE_ACTIVE=1
        $composerPos = strpos($content, 'install --no-dev', $maintenanceActivePos);
        $migratePos = strpos($content, 'artisan migrate --force', $maintenanceActivePos);
        $this->assertNotFalse($composerPos, 'composer install was not found after maintenance mode activation');
        $this->assertNotFalse($migratePos, 'artisan migrate was not found after maintenance mode activation');
        $this->assertGreaterThan($maintenanceActivePos, $composerPos);
        $this->assertGreaterThan($maintenanceActivePos, $migratePos);

        // 6. cleanup_on_error must hold failure maintenance when MAINTENANCE_ACTIVE=1
        $this->assertStringContainsString('if [ $MAINTENANCE_ACTIVE -eq 1 ]; then', $content);
        $this->assertStringContainsString('WARNING: Application was left in maintenance mode due to a deployment failure.', $content);
        $this->assertDoesNotMatchRegularExpression('/cleanup_on_error\(\)\s*\{[^}]*^\s*"\$PHP_BIN"\s+artisan\s+up/sm', $content, 'cleanup_on_error must not bring the app back up on failure');
    }

    public function test_synthetic_shell_execution_fails_closed_when_down_fails(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }

        $fixtureDir = $this->createSyntheticDeploymentFixture();

        try {
            // Configure stub php to fail on down
            $stubPhp = $fixtureDir.DIRECTORY_SEPARATOR.'php_stub.sh';
            file_put_contents($stubPhp, implode("\n", [
                '#!/usr/bin/env bash',
                'set -euo pipefail',
                'if [ "$1" = "-r" ]; then',
                '    case "$2" in *PHP_VERSION_ID*) printf "80425" ;; esac',
                '    exit 0',
                'fi',
                'if [ "$1" != "artisan" ]; then exit 0; fi',
                'shift',
                'printf "%s\n" "$*" >> "$COMMAND_TRACE"',
                'if [ "$1" = "down" ]; then',
                '    echo "Simulated artisan down failure" >&2',
                '    exit 1',
                'fi',
                'exit 0',
            ]));
            chmod($stubPhp, 0755);

            $runner = $fixtureDir.DIRECTORY_SEPARATOR.'run_test.sh';
            $unixScriptPath = str_replace('\\', '/', $this->scriptPath);
            $unixFixtureDir = str_replace('\\', '/', $fixtureDir);
            $unixStubPhp = str_replace('\\', '/', $stubPhp);

            file_put_contents($runner, implode("\n", [
                '#!/usr/bin/env bash',
                'export APP_DIR="'.$unixFixtureDir.'/app"',
                'export PUBLIC_DIR="'.$unixFixtureDir.'/web"',
                'export PHP_BIN="'.$unixStubPhp.'"',
                'export COMPOSER_BIN="'.$unixFixtureDir.'/app/composer.phar"',
                'export COMMAND_TRACE="'.$unixFixtureDir.'/commands"',
                ': > "$COMMAND_TRACE"',
                'bash "'.$unixScriptPath.'" > "'.$unixFixtureDir.'/output.log" 2>&1',
                'echo $? > "'.$unixFixtureDir.'/exit_code"',
            ]));
            chmod($runner, 0755);

            exec('"'.$bash.'" "'.str_replace('\\', '/', $runner).'" 2>&1');

            $exitCode = (int) trim((string) @file_get_contents($fixtureDir.DIRECTORY_SEPARATOR.'exit_code'));
            $output = (string) @file_get_contents($fixtureDir.DIRECTORY_SEPARATOR.'output.log');
            $commands = (string) @file_get_contents($fixtureDir.DIRECTORY_SEPARATOR.'commands');

            $this->assertNotSame(0, $exitCode, 'Deployment must fail when artisan down fails');
            $this->assertStringContainsString('down --retry=60', $commands);

            // Fail closed: neither composer nor migrate nor bootstrap commands executed
            $this->assertStringNotContainsString('migrate', $commands);
            $this->assertStringNotContainsString('purchasing:bootstrap', $commands);
            $this->assertStringNotContainsString('documents:bootstrap', $commands);
            $this->assertStringNotContainsString('config:cache', $commands);

            // Maintenance mode was never successfully entered, so failure banner must not claim app was left in maintenance
            $this->assertStringNotContainsString('Application was left in maintenance mode', $output);
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_synthetic_shell_execution_holds_maintenance_on_subsequent_failure(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }

        $fixtureDir = $this->createSyntheticDeploymentFixture();

        try {
            // Configure stub php: down succeeds, but migrate fails
            $stubPhp = $fixtureDir.DIRECTORY_SEPARATOR.'php_stub.sh';
            file_put_contents($stubPhp, implode("\n", [
                '#!/usr/bin/env bash',
                'set -euo pipefail',
                'if [ "$1" = "-r" ]; then',
                '    case "$2" in *PHP_VERSION_ID*) printf "80425" ;; esac',
                '    exit 0',
                'fi',
                'if [ "$1" != "artisan" ]; then exit 0; fi',
                'shift',
                'printf "%s\n" "$*" >> "$COMMAND_TRACE"',
                'if [ "$1" = "migrate" ]; then',
                '    echo "Simulated migration failure" >&2',
                '    exit 42',
                'fi',
                'exit 0',
            ]));
            chmod($stubPhp, 0755);

            $runner = $fixtureDir.DIRECTORY_SEPARATOR.'run_test.sh';
            $unixScriptPath = str_replace('\\', '/', $this->scriptPath);
            $unixFixtureDir = str_replace('\\', '/', $fixtureDir);
            $unixStubPhp = str_replace('\\', '/', $stubPhp);

            file_put_contents($runner, implode("\n", [
                '#!/usr/bin/env bash',
                'export APP_DIR="'.$unixFixtureDir.'/app"',
                'export PUBLIC_DIR="'.$unixFixtureDir.'/web"',
                'export PHP_BIN="'.$unixStubPhp.'"',
                'export COMPOSER_BIN="'.$unixFixtureDir.'/app/composer.phar"',
                'export COMMAND_TRACE="'.$unixFixtureDir.'/commands"',
                ': > "$COMMAND_TRACE"',
                'bash "'.$unixScriptPath.'" > "'.$unixFixtureDir.'/output.log" 2>&1',
                'echo $? > "'.$unixFixtureDir.'/exit_code"',
            ]));
            chmod($runner, 0755);

            exec('"'.$bash.'" "'.str_replace('\\', '/', $runner).'" 2>&1');

            $exitCode = (int) trim((string) @file_get_contents($fixtureDir.DIRECTORY_SEPARATOR.'exit_code'));
            $output = (string) @file_get_contents($fixtureDir.DIRECTORY_SEPARATOR.'output.log');
            $commands = (string) @file_get_contents($fixtureDir.DIRECTORY_SEPARATOR.'commands');

            $this->assertNotSame(0, $exitCode, 'Deployment must fail when migration fails');
            $this->assertStringContainsString('down --retry=60', $commands);
            $this->assertStringContainsString('migrate --force', $commands);

            // Failure maintenance held
            $this->assertStringContainsString('WARNING: Application was left in maintenance mode due to a deployment failure.', $output);
            $this->assertStringNotContainsString('up', $commands);
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    protected function resolveBashBinary(): ?string
    {
        $candidates = [
            'C:\\Program Files\\Git\\bin\\bash.exe',
            'C:\\Program Files\\Git\\usr\\bin\\bash.exe',
            'bash',
        ];

        foreach ($candidates as $candidate) {
            $test = @shell_exec('"'.$candidate.'" -c "echo BASH_AVAILABLE" 2>&1');
            if (is_string($test) && str_contains($test, 'BASH_AVAILABLE')) {
                return $candidate;
            }
        }

        return null;
    }

    protected function createSyntheticDeploymentFixture(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'p8_deploy_test_'.bin2hex(random_bytes(6));
        mkdir($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'storage', 0755, true);
        mkdir($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache', 0755, true);
        mkdir($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build', 0755, true);
        mkdir($dir.DIRECTORY_SEPARATOR.'web', 0755, true);

        touch($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'.env');
        touch($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'composer.phar');
        touch($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php');
        touch($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'.htaccess');
        file_put_contents($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'manifest.json', '{}');
        $this->ownedFixtureDirectories[] = $dir;

        return $dir;
    }

    protected function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $resolvedDirectory = realpath($dir);
        $resolvedTemporaryRoot = realpath(sys_get_temp_dir());
        if (! in_array($dir, $this->ownedFixtureDirectories, true)
            || $resolvedDirectory === false
            || $resolvedTemporaryRoot === false
            || dirname($resolvedDirectory) !== $resolvedTemporaryRoot
            || preg_match('/^p8_deploy_test_[a-f0-9]{12}$/', basename($resolvedDirectory)) !== 1) {
            throw new \RuntimeException('Refusing cleanup of a directory not owned by this deployment test.');
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolvedDirectory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }

        @rmdir($dir);
    }
}
