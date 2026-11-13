<?php

declare(strict_types=1);

namespace Tests\Unit\ReleaseAudit;

use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class OwnershipLifecycleRefusalTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, bool, string}> */
    public static function refusedConfigurations(): array
    {
        return [
            'opt-in absent' => [[], false, 'opt-in'],
            'persistent port' => [['port' => '3353'], true, '3353'],
            'default port' => [['port' => '3306'], true, '3342'],
            'foreign host' => [['host' => 'example.invalid'], true, '127.0.0.1'],
            'persistent schema' => [['database' => 'accounting'], true, 'schema'],
            'URL override' => [['url' => 'mysql://example.invalid/accounting'], true, 'URL'],
            'socket override' => [['unix_socket' => '/tmp/unowned.sock'], true, 'unix_socket'],
            'read splitting' => [['read' => ['host' => 'example.invalid']], true, 'read/write'],
            'proof absent' => [[], true, 'proof'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('refusedConfigurations')]
    public function test_actual_setup_and_partial_teardown_refuse_without_queries(array $overrides, bool $optIn, string $expected): void
    {
        $saved = [];
        foreach (['APP_ENV', 'PHASE8_ALLOW_DISPOSABLE_DB', 'PHASE8_DISPOSABLE_DB_PROOF', 'PHASE8_DISPOSABLE_DB_NONCE', 'PHASE8_TEST_DB_HOST', 'PHASE8_TEST_DB_PORT', 'PHASE8_TEST_DB_USERNAME', 'PHASE8_TEST_DB_PASSWORD', 'PHASE8_TEST_DB_EMPTY_PASSWORD'] as $name) {
            $saved[$name] = getenv($name);
        }
        $queries = [];
        $failure = null;
        $instance = new ReleaseAuditDatabaseTest('test_engine_readonly_snapshot_enforces_no_dml');
        $instance->afterApplicationCreated(function () use ($overrides, &$queries): void {
            config(['database.default' => 'mysql', 'database.connections.mysql' => array_replace([
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3342',
                'database' => 'accounting_p8_tmp_111122223333', 'username' => 'test_runner',
                'password' => '', 'unix_socket' => '', 'url' => null,
            ], $overrides)]);
            app('events')->listen(QueryExecuted::class, function ($query) use (&$queries): void {
                $queries[] = $query->sql;
            });
        });
        putenv('APP_ENV=testing');
        putenv('PHASE8_ALLOW_DISPOSABLE_DB='.($optIn ? '1' : '0'));
        foreach (['HOST' => '127.0.0.1', 'PORT' => '3342', 'USERNAME' => 'test_runner', 'PASSWORD' => '', 'EMPTY_PASSWORD' => '1'] as $key => $value) {
            putenv('PHASE8_TEST_DB_'.$key.'='.$value);
        }
        putenv('PHASE8_DISPOSABLE_DB_PROOF');
        putenv('PHASE8_DISPOSABLE_DB_NONCE');
        try {
            try {
                (new ReflectionMethod($instance, 'setUp'))->invoke($instance);
            } catch (RuntimeException $exception) {
                $failure = $exception;
            } finally {
                (new ReflectionMethod($instance, 'tearDown'))->invoke($instance);
            }
            self::assertInstanceOf(RuntimeException::class, $failure);
            self::assertStringContainsString($expected, $failure->getMessage());
            self::assertSame([], $queries, 'Refused setup and partial teardown must execute zero queries.');
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name.'='.$value);
            }
            $this->refreshApplication();
        }
    }
}
