<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Application\Platform\PlatformSessionProof;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

class PlatformConcurrencyTest extends TestCase
{
    /** @param callable(DisposableMariaDbSchema, array<string, mixed>, array<string, mixed>, string): void $test */
    private function fixture(callable $test): void
    {
        $schema = DisposableMariaDbSchema::create();
        $directory = sys_get_temp_dir().'/ms1a_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $user = User::factory()->create();
            $user->forceFill(['two_factor_secret' => Crypt::encrypt('synthetic-secret'), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => Crypt::encrypt(json_encode(['race-recovery']))])->save();
            $grant = new PlatformOperatorGrant;
            $grant->forceFill(['user_id' => $user->id, 'active' => true, 'permissions' => ['company.provision'], 'revision' => 1])->save();
            $this->withSession(['login.id' => $user->id])->post('/two-factor-challenge', ['recovery_code' => 'race-recovery'])->assertRedirect();
            $identity = ['actor' => $user->id, 'proof' => session(PlatformSessionProof::KEY)];
            $this->assertIsArray($identity['proof']);
            $payload = ['request_key' => (string) Str::uuid(), 'name_ar' => 'شركة سباق تجريبية', 'name_en' => 'Race Synthetic Business', 'base_currency_code' => 'ILS', 'default_locale' => 'ar', 'timezone' => 'Asia/Hebron', 'owner_name' => 'Synthetic Owner', 'owner_email' => 'race.owner@example.test', 'password' => 'Race-synthetic-password!', 'password_confirmation' => 'Race-synthetic-password!'];
            $test($schema, $identity, $payload, $directory);
        } finally {
            $schema->restoreLaravelConnection();
            $schema->drop();
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /** @param array<string, mixed> $input */
    private function start(DisposableMariaDbSchema $schema, string $directory, string $slot, array $input): Process
    {
        file_put_contents($directory.'/'.$slot.'.json', json_encode($input, JSON_THROW_ON_ERROR));
        chmod($directory.'/'.$slot.'.json', 0600);
        $process = new Process([PHP_BINARY, base_path('tests/Support/platform-provision-worker.php'), $directory, $slot], base_path(), $schema->environment());
        $process->setTimeout(90)->start();
        $deadline = microtime(true) + 30;
        while (! is_file($directory.'/'.$slot.'.ready')) {
            if (! $process->isRunning() || microtime(true) > $deadline) {
                $process->stop();
                $this->fail('Owned concurrency worker did not reach barrier: '.$process->getErrorOutput());
            }
            usleep(1000);
        }

        return $process;
    }

    public function test_concurrent_identical_requests_converge_to_one_company_and_owner(): void
    {
        $this->fixture(function ($schema, $identity, $payload, $directory): void {
            $a = $this->start($schema, $directory, 'worker-a', $identity + ['payload' => $payload]);
            $b = null;
            try {
                $b = $this->start($schema, $directory, 'worker-b', $identity + ['payload' => $payload]);
                touch($directory.'/go');
                $this->assertSame(0, $a->wait(), $a->getErrorOutput());
                $this->assertSame(0, $b->wait(), $b->getErrorOutput());
                $first = json_decode($a->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $second = json_decode($b->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('created', $first['outcome']);
                $this->assertSame($first, $second);
                $this->assertSame(1, DB::table('companies')->count());
                $this->assertSame(2, DB::table('users')->count());
                $this->assertSame(1, DB::table('company_user')->count());
                $this->assertSame(1, DB::table('platform_provisioning_requests')->count());
                $this->assertSame(1, DB::table('platform_audit_events')->where('event_key', 'company.provisioned')->count());
                $this->assertSame(0, DB::table('posting_batches')->count());
            } finally {
                $a->stop();
                $b?->stop();
            }
        });
    }

    public function test_concurrent_changed_payload_cannot_reuse_request_key(): void
    {
        $this->fixture(function ($schema, $identity, $payload, $directory): void {
            $a = $this->start($schema, $directory, 'worker-a', $identity + ['payload' => $payload]);
            $b = null;
            try {
                $b = $this->start($schema, $directory, 'worker-b', $identity + ['payload' => array_replace($payload, ['name_en' => 'Different'])]);
                touch($directory.'/go');
                $this->assertSame(0, $a->wait(), $a->getErrorOutput());
                $this->assertSame(0, $b->wait(), $b->getErrorOutput());
                $outcomes = [json_decode($a->getOutput(), true)['outcome'], json_decode($b->getOutput(), true)['outcome']];
                $this->assertEqualsCanonicalizing(['created', 'conflict'], $outcomes);
                $this->assertSame(1, DB::table('companies')->count());
                $this->assertSame(2, DB::table('users')->count());
            } finally {
                $a->stop();
                $b?->stop();
            }
        });
    }

    public function test_revocation_committed_before_worker_authorization_denies_creation(): void
    {
        $this->fixture(function ($schema, $identity, $payload, $directory): void {
            $worker = $this->start($schema, $directory, 'worker-a', $identity + ['payload' => $payload]);
            try {
                DB::table('platform_operator_grants')->where('user_id', $identity['actor'])->update(['active' => false, 'revision' => 2]);
                touch($directory.'/go');
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $this->assertSame('denied', json_decode($worker->getOutput(), true)['outcome']);
                $this->assertSame(0, DB::table('companies')->count());
                $this->assertSame(1, DB::table('users')->count());
            } finally {
                $worker->stop();
            }
        });
    }

    public function test_authorized_transaction_finishes_before_waiting_revocation_and_later_work_is_denied(): void
    {
        $this->fixture(function ($schema, $identity, $payload, $directory): void {
            $creator = $this->start($schema, $directory, 'worker-a', $identity + ['payload' => $payload, 'pause_after_authorization' => true]);
            $revoker = null;
            try {
                touch($directory.'/go');
                $deadline = microtime(true) + 30;
                while (! is_file($directory.'/authorized')) {
                    if (! $creator->isRunning() || microtime(true) > $deadline) {
                        $this->fail('Creator did not reach the authorized transaction barrier');
                    }
                    usleep(1000);
                }
                $revoker = $this->start($schema, $directory, 'worker-b', $identity + ['operation' => 'revoke']);
                $deadline = microtime(true) + 10;
                $waiting = false;
                do {
                    if (is_file($directory.'/revoker-connection')) {
                        $connection = (int) file_get_contents($directory.'/revoker-connection');
                        $waiting = (int) DB::selectOne('SELECT COUNT(*) AS waiting FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX t ON t.trx_id = w.requesting_trx_id WHERE t.trx_mysql_thread_id = ?', [$connection])->waiting > 0;
                    }
                    if (! $waiting) {
                        usleep(1000);
                    }
                } while (! $waiting && $revoker->isRunning() && microtime(true) < $deadline);
                $this->assertTrue($waiting, 'Revocation must be observed waiting on the held authorization lock');
                touch($directory.'/release-authorized');
                $this->assertSame(0, $creator->wait(), $creator->getErrorOutput());
                $this->assertSame(0, $revoker->wait(), $revoker->getErrorOutput());
                $this->assertSame('created', json_decode($creator->getOutput(), true)['outcome']);
                $this->assertSame('revoked', json_decode($revoker->getOutput(), true)['outcome']);
                $this->assertSame(1, DB::table('companies')->count());
                $this->assertFalse((bool) DB::table('platform_operator_grants')->value('active'));
                unlink($directory.'/worker-a.ready');
                $later = $this->start($schema, $directory, 'worker-a', $identity + ['payload' => array_replace($payload, ['request_key' => (string) Str::uuid(), 'owner_email' => 'later.owner@example.test'])]);
                try {
                    $this->assertSame(0, $later->wait(), $later->getErrorOutput());
                    $this->assertSame('denied', json_decode($later->getOutput(), true)['outcome']);
                    $this->assertSame(1, DB::table('companies')->count());
                } finally {
                    $later->stop();
                }
            } finally {
                touch($directory.'/release-authorized');
                $creator->stop();
                $revoker?->stop();
            }
        });
    }
}
