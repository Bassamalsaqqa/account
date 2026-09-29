<?php

namespace Tests\Feature\Hostinger;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class SeparatedPublicRootTest extends TestCase
{
    /** @var array<string> */
    private array $createdLinks = [];

    /** @var array<string> */
    private array $createdFiles = [];

    /** @var array<string> */
    private array $createdDirs = [];

    protected function tearDown(): void
    {
        // 1. Remove all created symbolic links / junctions first without descending into them
        foreach ($this->createdLinks as $link) {
            if (file_exists($link) || is_link($link) || (PHP_OS_FAMILY === 'Windows' && is_dir($link))) {
                if (PHP_OS_FAMILY === 'Windows') {
                    exec(sprintf('cmd /c rmdir %s', escapeshellarg($link)));
                } else {
                    @unlink($link);
                }
            }
        }
        $this->createdLinks = [];

        // 2. Remove all tracked created files
        foreach ($this->createdFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->createdFiles = [];

        // 3. Remove all tracked directories in reverse order (leaf to root)
        foreach (array_reverse($this->createdDirs) as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        $this->createdDirs = [];

        parent::tearDown();
    }

    public function test_standard_local_public_index_boots_request_successfully(): void
    {
        $indexPath = base_path('public/index.php');
        $this->assertFileExists($indexPath);

        $result = $this->executeIndexRequest($indexPath, base_path('public'), [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/up',
            'HTTP_HOST' => '127.0.0.1',
            'SERVER_NAME' => '127.0.0.1',
        ]);

        $this->assertSame(0, $result['exitCode'], 'Expected standard local public/index.php to boot cleanly. Error: '.$result['errorOutput']);
        $this->assertSame(200, $result['statusCode'], 'Expected HTTP status 200 for standard local boot.');
    }

    public function test_separated_hostinger_topology_boots_request_successfully(): void
    {
        $tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hostinger_topo_'.uniqid();
        $this->trackDirectory($tempRoot);

        $domainsDir = $tempRoot.DIRECTORY_SEPARATOR.'domains';
        $this->trackDirectory($domainsDir);

        $domainDir = $domainsDir.DIRECTORY_SEPARATOR.'palsync.net';
        $this->trackDirectory($domainDir);

        $publicHtmlDir = $domainDir.DIRECTORY_SEPARATOR.'public_html';
        $this->trackDirectory($publicHtmlDir);

        $publicDir = $publicHtmlDir.DIRECTORY_SEPARATOR.'account';
        $this->trackDirectory($publicDir);

        $accountingLink = $domainDir.DIRECTORY_SEPARATOR.'accounting';
        $this->createDirectoryLink(base_path(), $accountingLink);

        $separatedIndex = $publicDir.DIRECTORY_SEPARATOR.'index.php';
        copy(base_path('public/index.php'), $separatedIndex);
        $this->createdFiles[] = $separatedIndex;

        // Synchronize compiled Vite build assets just like bin/deploy.sh
        $this->copyPublicAssets($publicDir);

        $result = $this->executeIndexRequest($separatedIndex, $publicDir, [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/login',
            'HTTP_HOST' => 'account.palsync.net',
            'SERVER_NAME' => 'account.palsync.net',
        ]);

        $this->assertSame(0, $result['exitCode'], 'Separated public/index.php must resolve accounting root and boot without error. Output: '.$result['errorOutput'].' '.$result['output']);
        $this->assertSame(200, $result['statusCode'], 'Expected HTTP status 200 for separated Hostinger topology. Error: '.$result['errorOutput']);
        $this->assertStringContainsString('<!DOCTYPE html>', $result['output']);
        $this->assertStringContainsString('التاجر الصغير', $result['output']);
    }

    public function test_explicit_laravel_app_path_environment_variable_boots_request(): void
    {
        $tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hostinger_env_'.uniqid();
        $this->trackDirectory($tempRoot);

        $separatedIndex = $tempRoot.DIRECTORY_SEPARATOR.'index.php';
        copy(base_path('public/index.php'), $separatedIndex);
        $this->createdFiles[] = $separatedIndex;

        // Synchronize compiled Vite build assets
        $this->copyPublicAssets($tempRoot);

        $result = $this->executeIndexRequest($separatedIndex, $tempRoot, [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/login',
            'HTTP_HOST' => 'account.palsync.net',
            'SERVER_NAME' => 'account.palsync.net',
            'LARAVEL_APP_PATH' => base_path(),
        ]);

        $this->assertSame(0, $result['exitCode'], 'Explicit LARAVEL_APP_PATH must boot cleanly. Output: '.$result['errorOutput']);
        $this->assertSame(200, $result['statusCode'], 'Expected HTTP status 200 for explicit LARAVEL_APP_PATH.');
        $this->assertStringContainsString('<!DOCTYPE html>', $result['output']);
    }

    public function test_separated_index_fails_closed_when_app_path_cannot_be_resolved(): void
    {
        $tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hostinger_fail_'.uniqid();
        $this->trackDirectory($tempRoot);

        $separatedIndex = $tempRoot.DIRECTORY_SEPARATOR.'index.php';
        copy(base_path('public/index.php'), $separatedIndex);
        $this->createdFiles[] = $separatedIndex;

        $result = $this->executeIndexRequest($separatedIndex, $tempRoot, [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/login',
            'HTTP_HOST' => 'unknown.example.com',
            'SERVER_NAME' => 'unknown.example.com',
        ]);

        $this->assertSame(1, $result['exitCode'], 'Unresolvable application path must fail closed with exit code 1');
        $this->assertSame(500, $result['statusCode'], 'Unresolvable application path must set HTTP status 500.');
        $this->assertStringContainsString('Configuration Error: Laravel application root not found', $result['output']);
    }

    /**
     * @param  array<string, string>  $serverVars
     * @return array{exitCode: int|null, statusCode: int|null, output: string, errorOutput: string}
     */
    private function executeIndexRequest(string $indexPath, string $workingDir, array $serverVars = []): array
    {
        $runnerCode = sprintf(
            'register_shutdown_function(function() { echo PHP_EOL . "STATUS_CODE:" . http_response_code(); }); '.
            '$_SERVER["REQUEST_METHOD"] = "%s"; '.
            '$_SERVER["REQUEST_URI"] = "%s"; '.
            '$_SERVER["HTTP_HOST"] = "%s"; '.
            '$_SERVER["SERVER_NAME"] = "%s"; '.
            'ob_start(); '.
            'require %s; '.
            '$out = ob_get_clean(); '.
            'echo $out;',
            $serverVars['REQUEST_METHOD'] ?? 'GET',
            $serverVars['REQUEST_URI'] ?? '/login',
            $serverVars['HTTP_HOST'] ?? '127.0.0.1',
            $serverVars['SERVER_NAME'] ?? '127.0.0.1',
            var_export($indexPath, true)
        );

        $process = new Process([
            PHP_BINARY,
            '-d', 'variables_order=EGPCS',
            '-r', $runnerCode,
        ], $workingDir, $serverVars);

        $process->run();

        $output = $process->getOutput();
        $statusCode = null;
        if (preg_match('/STATUS_CODE:(\d+)/', $output, $matches)) {
            $statusCode = (int) $matches[1];
            $output = preg_replace('/\r?\n?STATUS_CODE:\d+/', '', $output) ?? $output;
        }

        return [
            'exitCode' => $process->getExitCode(),
            'statusCode' => $statusCode,
            'output' => $output,
            'errorOutput' => $process->getErrorOutput(),
        ];
    }

    private function copyPublicAssets(string $destinationPublicDir): void
    {
        $buildDest = $destinationPublicDir.DIRECTORY_SEPARATOR.'build';
        $this->trackDirectory($buildDest);

        $assetsDest = $buildDest.DIRECTORY_SEPARATOR.'assets';
        $this->trackDirectory($assetsDest);

        $manifest = [];
        foreach (['resources/css/app.css' => 'app-test.css', 'resources/js/app.js' => 'app-test.js'] as $source => $filename) {
            $asset = $assetsDest.DIRECTORY_SEPARATOR.$filename;
            file_put_contents($asset, '');
            $this->createdFiles[] = $asset;
            $manifest[$source] = ['file' => 'assets/'.$filename, 'src' => $source, 'isEntry' => true];
        }

        $destManifest = $buildDest.DIRECTORY_SEPARATOR.'manifest.json';
        file_put_contents($destManifest, json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->createdFiles[] = $destManifest;
    }

    private function trackDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $this->createdDirs[] = $path;
    }

    private function createDirectoryLink(string $target, string $link): void
    {
        $target = realpath($target) ?: $target;

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf('cmd /c mklink /J %s %s', escapeshellarg($link), escapeshellarg($target));
            exec($cmd, $output, $returnCode);
            if ($returnCode !== 0 || ! is_dir($link)) {
                $this->fail("Failed to create directory junction from {$link} to {$target}. Output: ".implode("\n", $output));
            }
        } else {
            if (! @symlink($target, $link)) {
                $this->fail("Failed to create symlink from {$link} to {$target}.");
            }
        }

        $this->createdLinks[] = $link;
    }
}
