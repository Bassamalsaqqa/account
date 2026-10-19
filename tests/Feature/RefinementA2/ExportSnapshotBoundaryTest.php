<?php

declare(strict_types=1);

namespace Tests\Feature\RefinementA2;

use App\Application\CompanyDataExport\Support\ExportSnapshotSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

final class ExportSnapshotBoundaryTest extends TestCase
{
    public function test_real_snapshot_is_consistent_read_only_and_restores_connection(): void
    {
        DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
        DB::statement('CREATE TABLE a2_snapshot_probe (id INT PRIMARY KEY, value INT) ENGINE=InnoDB');
        DB::table('a2_snapshot_probe')->insert(['id' => 1, 'value' => 10]);
        $configuration = DB::connection()->getConfig();
        $other = new PDO('mysql:host='.$configuration['host'].';port='.$configuration['port'].';dbname='.$configuration['database'], $configuration['username'], $configuration['password']);
        $beforeTimeout = DB::scalar('SELECT @@session.max_statement_time');
        $snapshot = new ExportSnapshotSession;
        try {
            $snapshot->begin(10);
            $this->assertSame(10, DB::table('a2_snapshot_probe')->value('value'));
            $other->exec('UPDATE a2_snapshot_probe SET value = 20 WHERE id = 1');
            $this->assertSame(10, DB::table('a2_snapshot_probe')->value('value'));
            try {
                DB::table('a2_snapshot_probe')->where('id', 1)->update(['value' => 30]);
                $this->fail('The export snapshot allowed a write.');
            } catch (QueryException $exception) {
                $this->assertSame(1792, $exception->errorInfo[1]);
            }
        } finally {
            $snapshot->restore();
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertEquals($beforeTimeout, DB::scalar('SELECT @@session.max_statement_time'));
        $this->assertSame(20, DB::table('a2_snapshot_probe')->value('value'));
        DB::statement('DROP TABLE a2_snapshot_probe');
    }

    public function test_mariadb_interrupts_slow_sql_and_hook_survives_repeated_exports(): void
    {
        DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
        $beforeTimeout = DB::scalar('SELECT @@session.max_statement_time');
        for ($iteration = 0; $iteration < 2; $iteration++) {
            $snapshot = new ExportSnapshotSession;
            $start = microtime(true);
            try {
                $snapshot->begin(1);
                DB::select('SELECT SLEEP(3)');
                $this->fail('MariaDB failed to enforce the export SQL deadline.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('max_statement_time', $exception->getMessage());
                $this->assertLessThan(2.5, microtime(true) - $start);
            } finally {
                $snapshot->restore();
            }
            $this->assertSame(0, DB::transactionLevel());
            $this->assertEquals($beforeTimeout, DB::scalar('SELECT @@session.max_statement_time'));
        }
    }
}
