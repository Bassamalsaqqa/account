<?php

declare(strict_types=1);

namespace Tests\Unit\ReleaseUi;

use App\Support\ReleaseIdentity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ReleaseIdentityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/release-identity-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/VERSION', "b1.3\n");
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_unknown_build_is_explicit_without_metadata(): void
    {
        $this->assertSame(['version' => 'b1.3', 'source_sha' => null, 'asset_manifest_sha256' => null], $this->read());
    }

    public function test_only_valid_metadata_bound_to_the_tracked_version_is_displayed(): void
    {
        $identity = ['version' => 'b1.3', 'source_sha' => str_repeat('a', 40), 'asset_manifest_sha256' => str_repeat('b', 64)];
        file_put_contents($this->directory.'/release.json', json_encode(['schema' => 1, ...$identity]));
        $this->assertSame($identity, $this->read());
        file_put_contents($this->directory.'/VERSION', 'b1.4');
        $this->assertNull($this->read()['source_sha'], 'Stale metadata must not claim the new release version.');
    }

    public function test_malformed_partial_and_oversized_metadata_never_claim_a_build(): void
    {
        foreach (['{', 'null', '[]', '{"schema":1,"version":"b1.3","source_sha":"latest"}', str_repeat(' ', 1025)] as $bytes) {
            file_put_contents($this->directory.'/release.json', $bytes);
            clearstatcache();
            $this->assertNull($this->read()['source_sha']);
        }
    }

    public function test_linked_metadata_is_refused(): void
    {
        file_put_contents($this->directory.'/other.json', json_encode(['schema' => 1, 'version' => 'b1.3', 'source_sha' => str_repeat('a', 40), 'asset_manifest_sha256' => str_repeat('b', 64)]));
        symlink($this->directory.'/other.json', $this->directory.'/release.json');
        $this->assertNull($this->read()['source_sha']);
    }

    public function test_cli_refuses_missing_or_wrong_operator_pin_without_booting_the_app(): void
    {
        $root = dirname(__DIR__, 3);
        foreach ([[], ['--check', '--expected-code='.str_repeat('0', 40)]] as $arguments) {
            $process = new Process([PHP_BINARY, $root.'/bin/release-identity.php', ...$arguments], $root);
            $process->setTimeout(15)->run();
            $this->assertSame(1, $process->getExitCode());
            $this->assertNotSame('', $process->getErrorOutput());
            $this->assertStringNotContainsString('SQLSTATE', $process->getErrorOutput());
        }
    }

    public function test_real_cli_binds_assets_and_refuses_missing_or_stale_config_cache(): void
    {
        $root = dirname(__DIR__, 3);
        mkdir($this->directory.'/bin');
        mkdir($this->directory.'/bootstrap/cache', 0700, true);
        mkdir($this->directory.'/public/build', 0700, true);
        // Reuse installed test dependencies only; the fixture is an independent temporary Git repository.
        symlink($root.'/vendor', $this->directory.'/vendor');
        copy($root.'/bin/release-identity.php', $this->directory.'/bin/release-identity.php');
        file_put_contents($this->directory.'/.gitignore', "/vendor\n/bootstrap/cache\n/public/build\n");
        file_put_contents($this->directory.'/public/build/manifest.json', '{}');
        $run = function (array $arguments): Process {
            $process = new Process($arguments, $this->directory);
            $process->setTimeout(15)->run();

            return $process;
        };
        $this->assertSame(0, $run(['git', 'init', '-q'])->getExitCode());
        $this->assertSame(0, $run(['git', 'add', '.'])->getExitCode());
        $this->assertSame(0, $run(['git', '-c', 'user.name=Release test', '-c', 'user.email=release@example.test', 'commit', '-qm', 'Synthetic release fixture'])->getExitCode());
        $sha = trim($run(['git', 'rev-parse', 'HEAD'])->getOutput());
        $command = [PHP_BINARY, 'bin/release-identity.php'];
        $write = $run([...$command, '--write', '--expected-code='.$sha]);
        $this->assertSame(0, $write->getExitCode(), $write->getErrorOutput());
        $identity = ReleaseIdentity::read($this->directory.'/VERSION', $this->directory.'/bootstrap/cache/release.json');
        $this->assertSame($sha, $identity['source_sha']);
        $this->assertSame(hash('sha256', '{}'), $identity['asset_manifest_sha256']);
        $missing = $run([...$command, '--check-cache', '--expected-code='.$sha]);
        $this->assertSame(1, $missing->getExitCode());
        $this->assertStringContainsString('Cached release identity', $missing->getErrorOutput());
        file_put_contents($this->directory.'/bootstrap/cache/config.php', '<?php return '.var_export(['release' => $identity], true).';');
        $valid = $run([...$command, '--check-cache', '--expected-code='.$sha]);
        $this->assertSame(0, $valid->getExitCode(), $valid->getErrorOutput());
        file_put_contents($this->directory.'/bootstrap/cache/config.php', '<?php return ["release" => ["version" => "old"]];');
        $this->assertSame(1, $run([...$command, '--check-cache', '--expected-code='.$sha])->getExitCode());
        file_put_contents($this->directory.'/public/build/manifest.json', '{"changed":true}');
        $stale = $run([...$command, '--check', '--expected-code='.$sha]);
        $this->assertSame(1, $stale->getExitCode());
        $this->assertStringContainsString('does not match', $stale->getErrorOutput());
    }

    private function read(): array
    {
        return ReleaseIdentity::read($this->directory.'/VERSION', $this->directory.'/release.json');
    }
}
