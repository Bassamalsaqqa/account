<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Application\Platform\TenantWriteAccess;
use App\Models\Company;
use App\Models\CompanyInventorySettings;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Sales\SalesActorGuard;
use InvalidArgumentException;

final class UpdateExpiryWarningDaysAction
{
    public function execute(Company $company, int $days, User $actor): void
    {
        if ($days < 1 || $days > 365) {
            throw new InvalidArgumentException('Expiry warning days must be between 1 and 365.');
        }

        app(TenantWriteAccess::class)->transaction((int) $company->id, function () use ($company, $days, $actor): void {
            $company = app(SalesActorGuard::class)->lockAndAuthorize($company->id, $actor, 'settings.company.manage');
            $settings = CompanyInventorySettings::where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            $before = $settings->default_expiry_warning_days;
            $settings->update(['default_expiry_warning_days' => $days]);
            app(AuditService::class)->log(
                companyId: $company->id,
                eventKey: 'company.expiry_warning_updated',
                summary: 'Expiry warning window updated',
                actorUserId: $actor->id,
                subject: $company,
                before: ['default_expiry_warning_days' => $before],
                after: ['default_expiry_warning_days' => $days],
            );
        });
    }
}
