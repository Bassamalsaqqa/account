<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Application\Platform\TenantWriteAccess;
use App\Models\Company;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

final class TenantWriteCommitBoundaryTest extends TestCase
{
    public function test_delayed_outer_commit_rolls_back_after_calendar_expiry_without_leaving_pdo_open(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            config(['subscriptions.enforcement_enabled' => true]);
            $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 11, 4)->setTime(23, 59, 59));
            $company = Company::factory()->create(['timezone' => 'UTC']);
            DB::table('platform_subscriptions')->insert([
                'company_id' => $company->id, 'plan' => 'Synthetic', 'fee' => '100', 'currency' => 'ILS',
                'starts_on' => '2026-10-01', 'renews_on' => '2026-10-29', 'grace_days' => 7,
                'reminder_days' => '[14,7,1]', 'enforcement_enabled' => true,
                'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $before = (array) $company->fresh();
            $connection = DB::connection();
            $connection->beginTransaction();
            app(TenantWriteAccess::class)->transaction($company->id, function () use ($company): void {
                DB::table('companies')->where('id', $company->id)->update(['name_en' => 'Must roll back']);
            });
            $this->assertSame(1, $connection->transactionLevel());
            $this->travel(1)->seconds();
            try {
                $connection->commit();
                $this->fail('A delayed outer commit bypassed expiry admission.');
            } catch (AuthorizationException) {
                $this->assertFalse($connection->getPdo()->inTransaction());
                $this->assertSame(0, $connection->transactionLevel());
                $this->assertSame($before, (array) $company->fresh());
            }
            // The same connection must remain usable after the failed commit.
            DB::transaction(function () use ($company): void {
                DB::table('platform_company_health')->insert([
                    'company_id' => $company->id, 'domains' => '{}', 'checked_at' => now(),
                ]);
            });
            $this->assertDatabaseCount('platform_company_health', 1);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            $this->travelBack();
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }
}
