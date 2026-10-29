<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Models\Company;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use LogicException;
use WeakMap;

/** Commercial admission only; existing tenant permissions and canonical authority remain mandatory. */
final class TenantWriteAccess
{
    /** @var WeakMap<DatabaseTransactionRecord, array<int, true>> */
    private WeakMap $transactions;

    public function __construct()
    {
        $this->transactions = new WeakMap;
    }

    /**
     * Check inside the caller's canonical transaction, before any business effect.
     * Company then subscription locks serialize renewal/policy changes with writers.
     */
    public function assertWritable(int $companyId): void
    {
        if (! config('subscriptions.enforcement_enabled', false)) {
            return;
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Tenant write admission requires the canonical transaction.');
        }
        $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
        $subscription = DB::table('platform_subscriptions')->where('company_id', $companyId)->lockForUpdate()->first();
        if ($subscription === null || ! $subscription->enforcement_enabled) {
            return;
        }
        $timeline = app(SubscriptionTimeline::class)->evaluate(
            $subscription->renews_on, $company->timezone, (int) $subscription->grace_days,
            json_decode($subscription->reminder_days, true, 512, JSON_THROW_ON_ERROR), now()->toDateTimeImmutable()
        );
        if ($timeline['state'] === 'read_only') {
            throw new AuthorizationException(__('subscriptions.tenant_read_only'));
        }

        // Keep the root transaction identity, not a session/request-wide entitlement cache.
        $connection = DB::connection();
        $root = app('db.transactions')->getPendingTransactions()->first(
            fn (DatabaseTransactionRecord $record): bool => $record->connection === $connection->getName() && $record->level === 1
        );
        if ($root === null) {
            throw new LogicException('A tracked tenant write transaction is required.');
        }
        $companies = $this->transactions[$root] ?? [];
        $companies[$companyId] = true;
        $this->transactions[$root] = $companies;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function transaction(int $companyId, Closure $callback, int $attempts = 1): mixed
    {
        return DB::transaction(function () use ($companyId, $callback) {
            $this->assertWritable($companyId);
            $result = $callback();
            $this->assertWritable($companyId);

            return $result;
        }, $attempts);
    }

    /** Final admission immediately before the framework's outermost PDO commit. */
    public function committing(TransactionCommitting $event): void
    {
        $connection = $event->connection;
        $root = app('db.transactions')->getPendingTransactions()->first(
            fn (DatabaseTransactionRecord $record): bool => $record->connection === $connection->getName() && $record->level === 1
        );
        if ($root === null || ! isset($this->transactions[$root])) {
            return;
        }
        try {
            if ($connection !== DB::connection()) {
                throw new LogicException('Tenant writes must commit on their original canonical connection.');
            }
            foreach (array_keys($this->transactions[$root]) as $companyId) {
                $this->assertWritable($companyId);
            }
        } catch (\Throwable $exception) {
            // Laravel's commit-exception path does not roll back arbitrary admission failures.
            // Explicitly roll back before throwing so no PDO transaction remains open.
            $connection->rollBack(0);
            throw $exception;
        } finally {
            unset($this->transactions[$root]);
        }
    }
}
