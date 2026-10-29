<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

final class TenantSubscriptionNotice
{
    /** Only tenant-safe status is projected; no fee, receipt or internal commercial notes.
     * @return array{state: string, days_until_renewal: int, read_only_from: string, reminder_due: bool}|null
     */
    public function current(): ?array
    {
        $context = app(CompanyContext::class);
        if (! config('subscriptions.enforcement_enabled', false) || ! $context->hasCompany()) {
            return null;
        }
        $row = DB::table('platform_subscriptions as subscription')->join('companies', 'companies.id', '=', 'subscription.company_id')
            ->where('subscription.company_id', $context->companyId())->where('subscription.enforcement_enabled', true)
            ->select(['subscription.renews_on', 'subscription.grace_days', 'subscription.reminder_days', 'companies.timezone'])->first();
        if ($row === null) {
            return null;
        }
        $timeline = app(SubscriptionTimeline::class)->evaluate($row->renews_on, $row->timezone, (int) $row->grace_days,
            json_decode($row->reminder_days, true, 512, JSON_THROW_ON_ERROR), now()->toDateTimeImmutable());

        return $timeline['state'] === 'active' ? null : $timeline;
    }
}
