<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Application\Platform\ManageSubscription;
use App\Application\Platform\PlatformAccess;
use App\Application\Platform\PlatformSessionProof;
use App\Models\Company;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

final class PlatformBillingConcurrencyTest extends TestCase
{
    /** @return array<string, mixed> */
    private function operator(): array
    {
        if (auth()->check()) {
            $this->post('/logout')->assertRedirect();
        }
        $user = User::factory()->create();
        $grant = new PlatformOperatorGrant;
        $grant->forceFill(['user_id' => $user->id, 'active' => true, 'permissions' => PlatformAccess::PERMISSIONS, 'revision' => 1])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->postJson('/confirm-password', ['password' => 'password'])->assertStatus(201);

        return ['actor' => $user->id, 'proof' => session(PlatformSessionProof::KEY)];
    }

    private function fixture(callable $test): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $company = Company::factory()->create();
            $identity = $this->operator();
            $result = app(ManageSubscription::class)->execute($company->public_id, 'configure', [
                'request_key' => (string) Str::uuid(), 'expected_revision' => 0, 'plan' => 'Synthetic', 'fee' => '100', 'currency' => 'ILS',
                'starts_on' => '2026-01-01', 'renews_on' => '2026-02-01', 'grace_days' => 7, 'reminder_days' => [14, 7, 1], 'enforcement_enabled' => false,
            ]);
            $payment = ['request_key' => (string) Str::uuid(), 'expected_revision' => 1, 'charge_id' => $result['charge_id'],
                'amount' => '70', 'currency' => 'ILS', 'received_on' => '2026-01-01'];
            $test($schema, $company, $identity, $payment);
            $this->assertDatabaseCount('posting_batches', 0);
            $this->assertDatabaseCount('stock_movements', 0);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }

    /** @param array<string, mixed> $input */
    private function start(DisposableMariaDbSchema $schema, string $directory, string $slot, array $input): Process
    {
        file_put_contents($directory.'/'.$slot.'.json', json_encode($input, JSON_THROW_ON_ERROR));
        chmod($directory.'/'.$slot.'.json', 0600);
        $process = new Process([PHP_BINARY, base_path('tests/Support/platform-billing-worker.php'), $directory, $slot], base_path(), $schema->environment());
        $process->setTimeout(90)->start();
        $deadline = microtime(true) + 30;
        while (! is_file($directory.'/'.$slot.'.ready')) {
            if (! $process->isRunning() || microtime(true) > $deadline) {
                $process->stop();
                $this->fail('Billing worker did not reach the owned barrier: '.$process->getErrorOutput());
            }
            usleep(1000);
        }

        return $process;
    }

    public function test_simultaneous_identical_retries_converge_to_one_receipt(): void
    {
        $this->race(false);
    }

    public function test_competing_operators_cannot_overpay_the_same_charge(): void
    {
        $this->race(true);
    }

    private function race(bool $competing): void
    {
        $this->fixture(function ($schema, $company, $identity, $payment) use ($competing): void {
            $otherIdentity = $competing ? $this->operator() : $identity;
            $otherPayment = $competing ? array_replace($payment, ['request_key' => (string) Str::uuid()]) : $payment;
            $directory = sys_get_temp_dir().'/ms2_billing_race_'.bin2hex(random_bytes(8));
            mkdir($directory, 0700);
            $a = $b = null;
            try {
                $a = $this->start($schema, $directory, 'worker-a', $identity + ['company' => $company->public_id, 'payload' => $payment]);
                $b = $this->start($schema, $directory, 'worker-b', $otherIdentity + ['company' => $company->public_id, 'payload' => $otherPayment]);
                touch($directory.'/go');
                $this->assertSame(0, $a->wait(), $a->getErrorOutput());
                $this->assertSame(0, $b->wait(), $b->getErrorOutput());
                $first = json_decode($a->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $second = json_decode($b->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                if ($competing) {
                    $this->assertEqualsCanonicalizing(['recorded', 'conflict'], [$first['outcome'], $second['outcome']]);
                } else {
                    $this->assertSame('recorded', $first['outcome']);
                    $this->assertSame($first, $second);
                }
                $this->assertDatabaseCount('platform_subscription_payments', 1);
                $this->assertSame('70.000000', DB::table('platform_subscription_payments')->sum('amount'));
                $this->assertDatabaseCount('platform_billing_requests', 2);
            } finally {
                $a?->stop();
                $b?->stop();
                foreach (glob($directory.'/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        });
    }

    public function test_old_repeatable_read_snapshot_cannot_hide_a_committed_receipt(): void
    {
        $this->fixture(function ($schema, $company, $identity, $payment): void {
            $peerName = 'ms2_billing_snapshot_peer';
            config(['database.connections.'.$peerName => config('database.connections.'.DB::getDefaultConnection())]);
            $peer = DB::connection($peerName);
            try {
                DB::beginTransaction();
                $this->assertCount(0, DB::table('platform_subscription_payments')->get());
                $peer->table('platform_subscription_payments')->insert([
                    'company_id' => $company->id, 'charge_id' => $payment['charge_id'], 'operator_user_id' => $identity['actor'],
                    'amount' => '80.000000', 'currency' => 'ILS', 'received_on' => '2026-01-01', 'created_at' => now(),
                ]);
                $peer->table('platform_subscriptions')->where('company_id', $company->id)->update(['revision' => 2]);
                try {
                    app(ManageSubscription::class)->execute($company->public_id, 'payment', array_replace($payment, ['expected_revision' => 2, 'amount' => '50']));
                    $this->fail('An old snapshot allowed a 130% settlement.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('amount', $exception->errors());
                    $this->assertSame('80.000000', $peer->table('platform_subscription_payments')->sum('amount'));
                }
            } finally {
                DB::rollBack(0);
                DB::purge($peerName);
                config(['database.connections.'.$peerName => null]);
            }
        });
    }
}
