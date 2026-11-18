<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Company;
use App\Models\CompanyPurchaseSetting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class EnsureCompanyInTransitWarehouseAction
{
    public function execute(Company $company, User $actor): Warehouse
    {
        /** @var CompanyPurchaseSetting|null $settings */
        $settings = CompanyPurchaseSetting::where('company_id', $company->id)
            ->lockForUpdate()
            ->first();

        if ($settings === null) {
            $settings = CompanyPurchaseSetting::create([
                'company_id' => $company->id,
                'warn_duplicate_vendor_invoice' => true,
            ]);
            $settings = CompanyPurchaseSetting::where('company_id', $company->id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        // 1. If in_transit_warehouse_id is set, verify it
        if ($settings->in_transit_warehouse_id !== null) {
            /** @var Warehouse|null $existing */
            $existing = Warehouse::where('company_id', $company->id)
                ->where('id', $settings->in_transit_warehouse_id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! $existing->is_transit) {
                    throw new InvalidArgumentException("Configured in-transit warehouse [{$existing->id}] is not marked as transit.");
                }
                if (! $existing->active) {
                    $existing->active = true;
                    $existing->save();
                }

                return $existing;
            }
        }

        // 2. Check if another warehouse in the company already has is_transit = true
        /** @var Warehouse|null $existingTransit */
        $existingTransit = Warehouse::where('company_id', $company->id)
            ->where('is_transit', true)
            ->lockForUpdate()
            ->first();

        if ($existingTransit !== null) {
            if (! $existingTransit->active) {
                $existingTransit->active = true;
                $existingTransit->save();
            }
            $settings->in_transit_warehouse_id = $existingTransit->id;
            $settings->save();

            return $existingTransit;
        }

        // 3. Reject code collision if a non-transit warehouse uses code 'WH-TRANSIT'
        $codeCollision = Warehouse::where('company_id', $company->id)
            ->where('code', 'WH-TRANSIT')
            ->first();

        if ($codeCollision !== null) {
            throw new InvalidArgumentException("Warehouse code collision: An existing non-transit warehouse [{$codeCollision->id}] uses code 'WH-TRANSIT'.");
        }

        // 4. Create the dedicated system-managed in-transit warehouse
        $warehouse = Warehouse::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $company->id,
            'name_ar' => 'مستودع البضائع بالطريق',
            'name_en' => 'Goods In-Transit Warehouse',
            'code' => 'WH-TRANSIT',
            'is_default' => false,
            'is_transit' => true,
            'active' => true,
            'created_by' => $actor->id,
        ]);

        $settings->in_transit_warehouse_id = $warehouse->id;
        $settings->save();

        return $warehouse;
    }
}
