<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Company;
use App\Models\CompanyPurchaseSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit\AuditService;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class PurchaseSettingsService
{
    /** @param array<string, mixed> $data */
    public function save(Company $company, User $actor, array $data): CompanyPurchaseSetting
    {
        return DB::transaction(function () use ($company, $actor, $data): CompanyPurchaseSetting {
            app(SalesActorGuard::class)->lockAndAuthorize($company->id, $actor, 'settings.purchases.manage');

            $values = Validator::make($data, [
                'default_payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
                'default_receiving_warehouse_id' => ['nullable', 'integer'],
                'warn_duplicate_vendor_invoice' => ['required', 'boolean'],
            ])->validate();

            // Validate the warehouse belongs to this company and is active
            if (($values['default_receiving_warehouse_id'] ?? null) !== null) {
                $warehouse = Warehouse::where('company_id', $company->id)
                    ->where('id', $values['default_receiving_warehouse_id'])
                    ->lockForUpdate()
                    ->first();

                if ($warehouse === null || ! $warehouse->active) {
                    throw new InvalidArgumentException(__('purchasing.invalid_warehouse'));
                }
            }

            /** @var CompanyPurchaseSetting $settings */
            $settings = CompanyPurchaseSetting::firstOrNew(['company_id' => $company->id]);
            $before = $settings->exists ? $settings->only(array_keys($values)) : null;
            $settings->company_id = $company->id;
            $settings->default_payment_terms_days = $values['default_payment_terms_days'] ?? null;
            $settings->default_receiving_warehouse_id = $values['default_receiving_warehouse_id'] ?? null;
            $settings->warn_duplicate_vendor_invoice = (bool) $values['warn_duplicate_vendor_invoice'];
            $settings->save();
            app(AuditService::class)->log($company->id, 'settings.purchases.saved', 'Purchase settings saved', $actor->id, $settings, $before, $settings->only(array_keys($values)));

            return $settings->refresh();
        });
    }
}
