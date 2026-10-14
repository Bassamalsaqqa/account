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

        // 4. Must verify maintenance marker exists after artisan down
        $this->assertStringContainsString('no maintenance marker was found in storage/framework/', $content);

        // 5. MAINTENANCE_ACTIVE=1 must only be set AFTER artisan down and marker check
        $downPos = strpos($content, '"$PHP_BIN" artisan down --retry=60');
        $maintenanceActivePos = strpos($content, 'MAINTENANCE_ACTIVE=1', $downPos ?: 0);
        $this->assertNotFalse($downPos, 'artisan down command was not found in deploy.sh');
        $this->assertNotFalse($maintenanceActivePos, 'MAINTENANCE_ACTIVE=1 was not found after artisan down');
        $this->assertGreaterThan($downPos, $maintenanceActivePos, 'MAINTENANCE_ACTIVE=1 must occur after artisan down');

        // 6. composer install and migrations must occur AFTER MAINTENANCE_ACTIVE=1
        $composerPos = strpos($content, 'install --no-dev', $maintenanceActivePos);
        $migratePos = strpos($content, 'artisan migrate --force', $maintenanceActivePos);
        $this->assertNotFalse($composerPos, 'composer install was not found after maintenance mode activation');
        $this->assertNotFalse($migratePos, 'artisan migrate was not found after maintenance mode activation');
        $this->assertGreaterThan($maintenanceActivePos, $composerPos);
        $this->assertGreaterThan($maintenanceActivePos, $migratePos);

        // 7. cleanup_on_error must hold failure maintenance when MAINTENANCE_ACTIVE=1
        $this->assertStringContainsString('if [ $MAINTENANCE_ACTIVE -eq 1 ]; then', $content);
        $this->assertStringContainsString('WARNING: Application was left in maintenance mode due to a deployment failure.', $content);
        $this->assertDoesNotMatchRegularExpression('/cleanup_on_error\(\)\s*\{[^}]*^\s*"\$PHP_BIN"\s+artisan\s+up/sm', $content, 'cleanup_on_error must not bring the app back up on failure');

        // 8. Script must NEVER automatically bring application up at completion
        $this->assertDoesNotMatchRegularExpression('/^\s*"\$PHP_BIN"\s+artisan\s+up\b/m', $content, 'deploy.sh must never automatically execute artisan up');

        // 9. Unsafe permissions 777 must not be present
        $this->assertDoesNotMatchRegularExpression('/chmod\s+(-R\s+)?777/', $content, 'deploy.sh must never use chmod 777');

        // 10. Default must skip migrations and bootstrap
        $this->assertStringContainsString('RUN_MIGRATIONS=0', $content);
        $this->assertStringContainsString('RUN_PURCHASING_BOOTSTRAP=0', $content);
        $this->assertStringContainsString('RUN_DOCUMENTS_BOOTSTRAP=0', $content);
    }

    public function test_synthetic_shell_execution_fails_closed_when_down_fails(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }

        $fixtureDir = $this->createSyntheticDeploymentFixture();

        try {
            $this->writeStubPhp($fixtureDir, ['fail_on_down' => true]);
            $result = $this->runDeploymentScript($bash, $fixtureDir);

            $this->assertNotSame(0, $result['exit_code'], 'Deployment must fail when artisan down fails');
            $this->assertStringContainsString('down --retry=60', $result['commands']);

            // Fail closed: neither composer nor migrate nor bootstrap commands executed
            $this->assertStringNotContainsString('migrate', $result['commands']);
            $this->assertStringNotContainsString('purchasing:bootstrap', $result['commands']);
            $this->assertStringNotContainsString('documents:bootstrap', $result['commands']);
            $this->assertStringNotContainsString('config:cache', $result['commands']);

            // Maintenance mode was never successfully entered, so failure banner must not claim app was left in maintenance
            $this->assertStringNotContainsString('Application was left in maintenance mode', $result['output']);
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
            // Configure stub php: down succeeds and touches marker, but migrate fails
            $this->writeStubPhp($fixtureDir, ['fail_on_migrate' => true]);
            $result = $this->runDeploymentScript($bash, $fixtureDir, ['--run-migrations']);

            $this->assertNotSame(0, $result['exit_code'], 'Deployment must fail when migration fails');
            $this->assertStringContainsString('down --retry=60', $result['commands']);
            $this->assertStringContainsString('migrate --force', $result['commands']);

            // Failure maintenance held
            $this->assertStringContainsString('WARNING: Application was left in maintenance mode due to a deployment failure.', $result['output']);
            $this->assertStringNotContainsString('up', $result['commands']);
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_fail_if_down_exits_zero_without_maintenance_marker(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }

        $fixtureDir = $this->createSyntheticDeploymentFixture();

        try {
            // Down exits zero but does NOT create marker
            $this->writeStubPhp($fixtureDir, ['touch_down_marker' => false]);
            $result = $this->runDeploymentScript($bash, $fixtureDir);

            $this->assertNotSame(0, $result['exit_code'], 'Deployment must fail when down exits zero without maintenance marker');
            $this->assertStringContainsString('no maintenance marker was found in storage/framework/', $result['output']);
            $this->assertStringNotContainsString('install --no-dev', $result['commands']);
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_preserves_preexisting_maintenance_ownership(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }

        $fixtureDir = $this->createSyntheticDeploymentFixture();

        try {
            // Pre-create maintenance marker
            $marker = $fixtureDir.'/app/storage/framework/down';
            file_put_contents($marker, '{"secret":"synthetic-owned-bypass"}');

            // Configure stub php to fail on migrate
            $this->writeStubPhp($fixtureDir, ['fail_on_migrate' => true]);
            $result = $this->runDeploymentScript($bash, $fixtureDir, ['--run-migrations']);

            $this->assertNotSame(0, $result['exit_code']);
            $this->assertStringContainsString('Pre-existing maintenance mode detected', $result['output']);
            $this->assertStringContainsString('Pre-existing maintenance mode ownership is preserved', $result['output']);
            $this->assertStringNotContainsString('down --retry=60', $result['commands']);
            $this->assertSame('{"secret":"synthetic-owned-bypass"}', file_get_contents($marker));
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_default_skips_migrations_and_bootstrapping_and_leaves_app_closed(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }

        $fixtureDir = $this->createSyntheticDeploymentFixture();

        try {
            $this->writeStubPhp($fixtureDir);
            $result = $this->runDeploymentScript($bash, $fixtureDir);

            $this->assertSame(0, $result['exit_code'], 'Nominal deployment must exit 0: '.$result['output']);
            $this->assertStringContainsString('down --retry=60', $result['commands']);

            // Default must NOT run migrate or bootstraps
            $this->assertStringNotContainsString('migrate', $result['commands']);
            $this->assertStringNotContainsString('purchasing:bootstrap', $result['commands']);
            $this->assertStringNotContainsString('documents:bootstrap', $result['commands']);

            // Caches warmed
            $this->assertStringContainsString('config:cache', $result['commands']);
            $this->assertStringContainsString('route:cache', $result['commands']);
            $this->assertStringContainsString('view:cache', $result['commands']);
            $this->assertStringContainsString('event:cache', $result['commands']);

            // Never automatic up
            $this->assertStringNotContainsString('up', $result['commands']);

            // Output confirms maintenance held and checks not run
            $this->assertStringContainsString('Application remains in MAINTENANCE MODE', $result['output']);
            $this->assertStringContainsString('Economic reconciliations', $result['output']);
            $this->assertStringContainsString('NOT RUN by this deployment script', $result['output']);
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_opt_in_flags_execute_migrations_and_bootstrap_and_app_remains_closed(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }

        $fixtureDir = $this->createSyntheticDeploymentFixture();

        try {
            $this->writeStubPhp($fixtureDir);
            $result = $this->runDeploymentScript($bash, $fixtureDir, ['--run-migrations', '--run-bootstrap']);

            $this->assertSame(0, $result['exit_code'], 'Opt-in deployment must exit 0: '.$result['output']);
            $this->assertStringContainsString('down --retry=60', $result['commands']);
            $this->assertStringContainsString('migrate --force', $result['commands']);
            $this->assertStringContainsString('purchasing:bootstrap --all', $result['commands']);
            $this->assertStringContainsString('documents:bootstrap --all', $result['commands']);
            $this->assertStringNotContainsString('up', $result['commands']);
            $this->assertStringContainsString('Application remains in MAINTENANCE MODE', $result['output']);
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_skip_migrate_flag_is_compatibility_safe(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }

        $fixtureDir = $this->createSyntheticDeploymentFixture();

        try {
            $this->writeStubPhp($fixtureDir);
            $result = $this->runDeploymentScript($bash, $fixtureDir, ['--skip-migrate']);

            $this->assertSame(0, $result['exit_code']);
            $this->assertStringNotContainsString('migrate', $result['commands']);
            $this->assertStringNotContainsString('up', $result['commands']);
            $this->assertStringContainsString('Application remains in MAINTENANCE MODE', $result['output']);
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_preserves_live_htaccess_byte_for_byte_with_custom_directives(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }

        $fixtureDir = $this->createSyntheticDeploymentFixture();

        try {
            // Live .htaccess with custom rule, PHP 8.4 handler, and webmanifest MIME
            $liveHtaccess = implode("\n", [
                '# Custom Hostinger Alt-PHP 8.4 handler',
                '<FilesMatch "\.(php4|php5|php3|php2|php|phtml)$">',
                '    SetHandler application/x-httpd-alt-php84',
                '</FilesMatch>',
                '',
                '# PWA Webmanifest MIME',
                'AddType application/manifest+json .webmanifest',
                '',
                '# Custom Operator Directives — MUST NOT BE OVERWRITTEN',
                'Header always set X-Custom-Deployment-Test "pr44-preserved"',
                'RewriteEngine On',
                'RewriteRule ^api/custom - [L]',
                '',
            ]);
            file_put_contents($fixtureDir.DIRECTORY_SEPARATOR.'web'.DIRECTORY_SEPARATOR.'.htaccess', $liveHtaccess);
            $initialHash = hash('sha256', $liveHtaccess);

            $this->writeStubPhp($fixtureDir);
            $result = $this->runDeploymentScript($bash, $fixtureDir);

            $this->assertSame(0, $result['exit_code'], 'Deployment should succeed: '.$result['output']);

            // Verify live .htaccess was preserved byte-for-byte
            $afterContent = (string) file_get_contents($fixtureDir.DIRECTORY_SEPARATOR.'web'.DIRECTORY_SEPARATOR.'.htaccess');
            $this->assertSame($liveHtaccess, $afterContent, 'Live .htaccess was modified instead of preserved byte-for-byte');
            $this->assertSame($initialHash, hash('sha256', $afterContent));

            // Verify protected backup exists and matches hash
            $backupDir = $fixtureDir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'htaccess_backups';
            $this->assertDirectoryExists($backupDir);
            $backups = glob($backupDir.DIRECTORY_SEPARATOR.'.htaccess.pre-deploy-*');
            $this->assertNotEmpty($backups, 'No protected .htaccess backup file found');
            $this->assertSame($initialHash, hash_file('sha256', $backups[0]));
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_refuses_when_live_htaccess_missing_manifest_mime(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }

        $fixtureDir = $this->createSyntheticDeploymentFixture();

        try {
            // Live .htaccess missing webmanifest MIME
            $liveHtaccess = implode("\n", [
                '<FilesMatch "\.(php4|php5|php3|php2|php|phtml)$">',
                '    SetHandler application/x-httpd-alt-php84',
                '</FilesMatch>',
                '# Custom Rule',
                'RewriteEngine On',
            ]);
            file_put_contents($fixtureDir.DIRECTORY_SEPARATOR.'web'.DIRECTORY_SEPARATOR.'.htaccess', $liveHtaccess);

            $this->writeStubPhp($fixtureDir);
            $result = $this->runDeploymentScript($bash, $fixtureDir);

            $this->assertNotSame(0, $result['exit_code'], 'Must fail when live .htaccess lacks manifest MIME');
            $this->assertStringContainsString('missing required PWA manifest MIME type', $result['output']);
            $this->assertStringContainsString('Manual review required', $result['output']);

            // Live .htaccess must NOT be overwritten
            $afterContent = (string) file_get_contents($fixtureDir.DIRECTORY_SEPARATOR.'web'.DIRECTORY_SEPARATOR.'.htaccess');
            $this->assertSame($liveHtaccess, $afterContent);
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_refuses_unsafe_symlink_public_directory_when_custody_not_proved(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }

        $fixtureDir = $this->createSyntheticDeploymentFixture();

        try {
            // Replace web directory with symlink to rogue directory
            $rogueDir = $fixtureDir.DIRECTORY_SEPARATOR.'rogue_web';
            mkdir($rogueDir, 0755, true);
            $webDir = $fixtureDir.DIRECTORY_SEPARATOR.'web';
            $this->removeDirectoryContents($webDir);
            rmdir($webDir);

            $created = @symlink($rogueDir, $webDir);
            if (! $created && ! is_link($webDir)) {
                $this->markTestSkipped('Filesystem symlinks not supported in this test environment.');
            }

            $this->writeStubPhp($fixtureDir);
            $result = $this->runDeploymentScript($bash, $fixtureDir);

            $this->assertNotSame(0, $result['exit_code'], 'Must fail closed on unproved symlink custody');
            $this->assertStringContainsString('Custody cannot be proved', $result['output']);
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_refuses_unsafe_symlink_htaccess(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }

        $fixtureDir = $this->createSyntheticDeploymentFixture();

        try {
            $targetHtaccess = $fixtureDir.DIRECTORY_SEPARATOR.'target_htaccess';
            file_put_contents($targetHtaccess, "AddType application/manifest+json .webmanifest\n");
            $webHtaccess = $fixtureDir.DIRECTORY_SEPARATOR.'web'.DIRECTORY_SEPARATOR.'.htaccess';

            $created = @symlink($targetHtaccess, $webHtaccess);
            if (! $created && ! is_link($webHtaccess)) {
                $this->markTestSkipped('Filesystem symlinks not supported in this test environment.');
            }

            $this->writeStubPhp($fixtureDir);
            $result = $this->runDeploymentScript($bash, $fixtureDir);

            $this->assertNotSame(0, $result['exit_code'], 'Must fail closed when .htaccess is a symlink');
            $this->assertStringContainsString('Symlinked .htaccess is refused', $result['output']);
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_refuses_when_live_htaccess_missing_php84_handler_on_hostinger(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }

        $fixtureDir = $this->createSyntheticDeploymentFixture();

        try {
            // Live .htaccess has webmanifest MIME but lacks x-httpd-alt-php84
            $liveHtaccess = implode("\n", [
                'AddType application/manifest+json .webmanifest',
                '# Custom Rule',
                'RewriteEngine On',
            ]);
            file_put_contents($fixtureDir.DIRECTORY_SEPARATOR.'web'.DIRECTORY_SEPARATOR.'.htaccess', $liveHtaccess);

            // Create fake alt-php binary
            $mockAltPhp = $fixtureDir.DIRECTORY_SEPARATOR.'mock_alt_php.sh';
            file_put_contents($mockAltPhp, "#!/usr/bin/env bash\nexit 0;\n");
            chmod($mockAltPhp, 0755);

            $this->writeStubPhp($fixtureDir);
            $result = $this->runDeploymentScript($bash, $fixtureDir, [], [
                'HOSTINGER_ALT_PHP_BIN' => str_replace('\\', '/', $mockAltPhp),
            ]);

            $this->assertNotSame(0, $result['exit_code'], 'Must fail when live .htaccess lacks Hostinger PHP 8.4 handler');
            $this->assertStringContainsString('missing the Hostinger CloudLinux PHP 8.4 handler', $result['output']);
            $this->assertStringContainsString('Manual review required', $result['output']);

            // Live .htaccess must NOT be overwritten
            $afterContent = (string) file_get_contents($fixtureDir.DIRECTORY_SEPARATOR.'web'.DIRECTORY_SEPARATOR.'.htaccess');
            $this->assertSame($liveHtaccess, $afterContent);
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_tracks_manifest_https_check_and_economic_reconciliations_as_external_prerequisites_not_performed(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }

        $fixtureDir = $this->createSyntheticDeploymentFixture();

        try {
            $this->writeStubPhp($fixtureDir);
            $result = $this->runDeploymentScript($bash, $fixtureDir);

            $this->assertSame(0, $result['exit_code']);
            $this->assertStringContainsString('NOT RUN by this deployment script', $result['output']);
            $this->assertStringContainsString('Live HTTPS asset availability and webmanifest MIME response verification', $result['output']);
            $this->assertStringContainsString('Economic reconciliations', $result['output']);
            $this->assertStringContainsString('To reopen the application ONLY after explicit Owner authorization', $result['output']);
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_web_configuration_is_preserved_before_a_dependency_failure(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }
        $fixtureDir = $this->createSyntheticDeploymentFixture();
        try {
            $live = "AddType application/manifest+json .webmanifest\nHeader always set X-Operator preserved\n";
            $path = $fixtureDir.DIRECTORY_SEPARATOR.'web'.DIRECTORY_SEPARATOR.'.htaccess';
            file_put_contents($path, $live);
            $this->writeStubPhp($fixtureDir, ['fail_on_install' => true]);
            $result = $this->runDeploymentScript($bash, $fixtureDir);
            $this->assertSame(43, $result['exit_code']);
            $this->assertStringContainsString('composer install', $result['commands']);
            $this->assertStringNotContainsString('config:cache', $result['commands']);
            $backups = glob($fixtureDir.'/app/storage/app/htaccess_backups/.htaccess.pre-deploy-*');
            $this->assertCount(1, $backups);
            $this->assertSame($live, file_get_contents($backups[0]));
            $this->assertSame($live, file_get_contents($path));
            $this->assertFileExists($fixtureDir.'/app/storage/framework/down');
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_live_configuration_is_preserved_on_cache_failure(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }
        $fixtureDir = $this->createSyntheticDeploymentFixture();
        try {
            $live = "AddType application/manifest+json .webmanifest\nHeader always set X-Operator preserved\n";
            $path = $fixtureDir.'/web/.htaccess';
            file_put_contents($path, $live);
            $this->writeStubPhp($fixtureDir, ['fail_on_cache' => true]);
            $result = $this->runDeploymentScript($bash, $fixtureDir);
            $this->assertSame(44, $result['exit_code']);
            $this->assertStringContainsString('config:cache', $result['commands']);
            $this->assertStringNotContainsString('route:cache', $result['commands']);
            $backups = glob($fixtureDir.'/app/storage/app/htaccess_backups/.htaccess.pre-deploy-*');
            $this->assertCount(1, $backups);
            $this->assertSame($live, file_get_contents($backups[0]));
            $this->assertSame($live, file_get_contents($path));
            $this->assertFileExists($fixtureDir.'/app/storage/framework/down');
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_reviewed_source_contains_correct_manifest_extension_directive(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/public/.htaccess');
        $this->assertIsString($source);
        $this->assertMatchesRegularExpression('/^\s*AddType\s+application\/manifest\+json\s+\.webmanifest\s*$/m', $source);
    }

    public function test_static_pwa_assets_are_published_without_touching_uploaded_business_files(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }
        $fixtureDir = $this->createSyntheticDeploymentFixture();
        try {
            mkdir($fixtureDir.'/web/uploads');
            file_put_contents($fixtureDir.'/web/uploads/business-fixture.txt', 'preserved-private-fixture');
            $this->writeStubPhp($fixtureDir);
            $result = $this->runDeploymentScript($bash, $fixtureDir);
            $this->assertSame(0, $result['exit_code'], $result['output']);
            foreach (['manifest.webmanifest', 'sw.js', 'offline.html', 'icons/icon-192.png', 'icons/icon-512.png', 'icons/icon-maskable.png', 'icons/icon-maskable.svg', 'icons/icon.svg'] as $asset) {
                $this->assertSame('synthetic-'.$asset, file_get_contents($fixtureDir.'/web/'.$asset));
            }
            $this->assertSame('preserved-private-fixture', file_get_contents($fixtureDir.'/web/uploads/business-fixture.txt'));
            $this->assertFileExists($fixtureDir.'/app/storage/framework/down');
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_comments_wrong_extensions_and_conflicting_mime_do_not_pass_preflight(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }
        foreach ([
            "# AddType application/manifest+json .webmanifest\n",
            "AddType application/manifest+json .json\n",
            "AddType application/manifest+json .webmanifest\nAddType text/plain .webmanifest\n",
            "AddType application/manifest+json .webmanifest\nSetHandler application/x-httpd-alt-php83\n",
        ] as $live) {
            $fixtureDir = $this->createSyntheticDeploymentFixture();
            try {
                $path = $fixtureDir.'/web/.htaccess';
                file_put_contents($path, $live);
                $this->writeStubPhp($fixtureDir);
                $result = $this->runDeploymentScript($bash, $fixtureDir);
                $this->assertNotSame(0, $result['exit_code']);
                $this->assertStringContainsString('Manual review required', $result['output']);
                $this->assertStringNotContainsString('composer install', $result['commands']);
                $this->assertStringNotContainsString('config:cache', $result['commands']);
                $this->assertSame($live, file_get_contents($path));
                $this->assertFileExists($fixtureDir.'/app/storage/framework/down');
            } finally {
                $this->removeDirectory($fixtureDir);
            }
        }
    }

    public function test_public_symlink_to_application_public_is_also_refused(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }
        $fixtureDir = $this->createSyntheticDeploymentFixture();
        try {
            $web = $fixtureDir.'/web';
            rmdir($web);
            if (! @symlink($fixtureDir.'/app/public', $web)) {
                $this->markTestSkipped('Filesystem symlinks are unavailable in this environment.');
            }
            $this->writeStubPhp($fixtureDir);
            $result = $this->runDeploymentScript($bash, $fixtureDir);
            $this->assertNotSame(0, $result['exit_code']);
            $this->assertStringContainsString('Custody cannot be proved', $result['output']);
            $this->assertStringNotContainsString('composer install', $result['commands']);
            $this->assertStringNotContainsString('config:cache', $result['commands']);
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    public function test_symlinked_private_backup_custody_is_refused_before_dependencies(): void
    {
        $bash = $this->resolveBashBinary();
        if ($bash === null) {
            $this->markTestSkipped('Bash binary is not available in this environment.');
        }
        $fixtureDir = $this->createSyntheticDeploymentFixture();
        try {
            $target = $fixtureDir.'/outside_private_storage';
            mkdir($target);
            $parent = $fixtureDir.'/app/storage/app';
            mkdir($parent);
            if (! @symlink($target, $parent.'/htaccess_backups')) {
                $this->markTestSkipped('Filesystem symlinks are unavailable in this environment.');
            }
            file_put_contents($fixtureDir.'/web/.htaccess', "AddType application/manifest+json .webmanifest\n");
            $this->writeStubPhp($fixtureDir);
            $result = $this->runDeploymentScript($bash, $fixtureDir);
            $this->assertNotSame(0, $result['exit_code']);
            $this->assertStringContainsString('backup custody is refused', $result['output']);
            $this->assertStringNotContainsString('composer install', $result['commands']);
            $this->assertSame([], array_values(array_diff(scandir($target), ['.', '..'])));
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    /**
     * @param  array{fail_on_down?: bool, touch_down_marker?: bool, fail_on_migrate?: bool, fail_on_install?: bool, fail_on_cache?: bool}  $options
     */
    protected function writeStubPhp(string $fixtureDir, array $options = []): string
    {
        $failOnDown = ($options['fail_on_down'] ?? false) ? '1' : '0';
        $touchDownMarker = ($options['touch_down_marker'] ?? true) ? '1' : '0';
        $failOnMigrate = ($options['fail_on_migrate'] ?? false) ? '1' : '0';
        $failOnInstall = ($options['fail_on_install'] ?? false) ? '1' : '0';
        $failOnCache = ($options['fail_on_cache'] ?? false) ? '1' : '0';

        $stubPhp = $fixtureDir.DIRECTORY_SEPARATOR.'php_stub.sh';
        file_put_contents($stubPhp, implode("\n", [
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'if [ "$1" = "-r" ]; then',
            '    case "$2" in *PHP_VERSION_ID*) printf "80425" ;; esac',
            '    exit 0',
            'fi',
            'if [ "$1" != "artisan" ]; then',
            '    if [ "${2:-}" = "install" ]; then',
            '        printf "composer install\n" >> "$COMMAND_TRACE"',
            '        if [ "'.$failOnInstall.'" = "1" ]; then exit 43; fi',
            '    fi',
            '    exit 0',
            'fi',
            'shift',
            'printf "%s\n" "$*" >> "$COMMAND_TRACE"',
            'if [ "$1" = "down" ]; then',
            '    if [ "'.$failOnDown.'" = "1" ]; then',
            '        echo "Simulated artisan down failure" >&2',
            '        exit 1',
            '    fi',
            '    if [ "'.$touchDownMarker.'" = "1" ]; then',
            '        touch "$APP_DIR/storage/framework/down"',
            '    fi',
            '    exit 0',
            'fi',
            'if [ "$1" = "config:cache" ] && [ "'.$failOnCache.'" = "1" ]; then exit 44; fi',
            'if [ "$1" = "migrate" ] && [ "'.$failOnMigrate.'" = "1" ]; then',
            '    echo "Simulated migration failure" >&2',
            '    exit 42',
            'fi',
            'exit 0',
        ]));
        chmod($stubPhp, 0755);

        return $stubPhp;
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     * @return array{exit_code: int, output: string, commands: string}
     */
    protected function runDeploymentScript(string $bash, string $fixtureDir, array $args = [], array $env = []): array
    {
        $stubPhp = $fixtureDir.DIRECTORY_SEPARATOR.'php_stub.sh';
        $runner = $fixtureDir.DIRECTORY_SEPARATOR.'run_test.sh';
        $unixScriptPath = str_replace('\\', '/', $this->scriptPath);
        $unixFixtureDir = str_replace('\\', '/', $fixtureDir);
        $unixStubPhp = str_replace('\\', '/', $stubPhp);
        $argString = implode(' ', array_map('escapeshellarg', $args));

        $extraEnv = '';
        foreach ($env as $name => $value) {
            $extraEnv .= 'export '.$name.'="'.$value.'"'."\n";
        }

        file_put_contents($runner, implode("\n", [
            '#!/usr/bin/env bash',
            'export APP_DIR="'.$unixFixtureDir.'/app"',
            'export PUBLIC_DIR="'.$unixFixtureDir.'/web"',
            'export PHP_BIN="'.$unixStubPhp.'"',
            'export COMPOSER_BIN="'.$unixFixtureDir.'/app/composer.phar"',
            'export COMMAND_TRACE="'.$unixFixtureDir.'/commands"',
            $extraEnv,
            ': > "$COMMAND_TRACE"',
            'bash "'.$unixScriptPath.'" '.$argString.' > "'.$unixFixtureDir.'/output.log" 2>&1',
            'echo $? > "'.$unixFixtureDir.'/exit_code"',
        ]));
        chmod($runner, 0755);

        exec('"'.$bash.'" "'.str_replace('\\', '/', $runner).'" 2>&1');

        $exitCode = (int) trim((string) @file_get_contents($fixtureDir.DIRECTORY_SEPARATOR.'exit_code'));
        $output = (string) @file_get_contents($fixtureDir.DIRECTORY_SEPARATOR.'output.log');
        $commands = (string) @file_get_contents($fixtureDir.DIRECTORY_SEPARATOR.'commands');

        return [
            'exit_code' => $exitCode,
            'output' => $output,
            'commands' => $commands,
        ];
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
        mkdir($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework', 0755, true);
        mkdir($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache', 0755, true);
        mkdir($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build', 0755, true);
        mkdir($dir.DIRECTORY_SEPARATOR.'web', 0755, true);

        touch($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'.env');
        touch($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'composer.phar');
        touch($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php');
        file_put_contents($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'.htaccess', implode("\n", [
            'RewriteEngine On',
            'AddType application/manifest+json .webmanifest',
        ]));
        file_put_contents($dir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'manifest.json', '{}');
        mkdir($dir.'/app/public/icons');
        foreach (['manifest.webmanifest', 'sw.js', 'offline.html', 'icons/icon-192.png', 'icons/icon-512.png', 'icons/icon-maskable.png', 'icons/icon-maskable.svg', 'icons/icon.svg'] as $asset) {
            file_put_contents($dir.'/app/public/'.$asset, 'synthetic-'.$asset);
        }
        $this->ownedFixtureDirectories[] = $dir;

        return $dir;
    }

    protected function removeDirectoryContents(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isLink()) {
                @unlink($item->getPathname());
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }

    protected function removeDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! is_link($dir)) {
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

        $this->removeDirectoryContents($resolvedDirectory);

        @rmdir($dir);
    }
}
