<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Application\Reporting\Presentation\DashboardReports;
use App\Application\Reporting\Presentation\ReportRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReadProjectionFreshnessTest extends Phase8TestCase
{
    public function test_navigation_does_not_hide_unexpected_database_failures(): void
    {
        DB::connection()->beforeExecuting(static function (string $sql): void {
            if (str_contains($sql, '`permissions`')) {
                throw new RuntimeException('Synthetic permission-store failure');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Synthetic permission-store failure');
        app(ReportRegistry::class)->visible($this->company);
    }

    public function test_dashboard_refuses_membership_revoked_during_report_retrieval(): void
    {
        $reads = 0;
        $revoked = false;
        DB::listen(function (QueryExecuted $event) use (&$reads, &$revoked): void {
            if (! $revoked && str_starts_with($event->sql, 'select') && str_contains($event->sql, '`company_user`') && ++$reads === 3) {
                $revoked = true;
                DB::table('company_user')->where('company_id', $this->company->id)
                    ->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
            }
        });

        try {
            app(DashboardReports::class)->readHome($this->company, 'this_month');
            $this->fail('Dashboard delivered after membership revocation');
        } catch (AuthorizationException) {
            $this->assertTrue($revoked);
        }
    }

    public function test_navigation_refuses_membership_revoked_during_visibility_projection(): void
    {
        $reads = 0;
        $revoked = false;
        DB::listen(function (QueryExecuted $event) use (&$reads, &$revoked): void {
            if (! $revoked && str_starts_with($event->sql, 'select') && str_contains($event->sql, '`company_user`') && ++$reads === 2) {
                $revoked = true;
                DB::table('company_user')->where('company_id', $this->company->id)
                    ->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
            }
        });

        try {
            app(ReportRegistry::class)->visible($this->company);
            $this->fail('Navigation delivered after membership revocation');
        } catch (AuthorizationException) {
            $this->assertTrue($revoked);
        }
    }
}
