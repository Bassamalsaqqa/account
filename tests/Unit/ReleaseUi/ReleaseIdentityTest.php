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
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
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

    private function read(): array
    {
        return ReleaseIdentity::read($this->directory.'/VERSION', $this->directory.'/release.json');
    }
}
