<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Company;
use App\Models\CompanyPurchaseSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit\AuditService;
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

            if ($existing === null) {
                throw new InvalidArgumentException("Configured in-transit warehouse [{$settings->in_transit_warehouse_id}] was not found for company [{$company->id}].");
            }

            if (! $existing->is_transit) {
                throw new InvalidArgumentException("Configured in-transit warehouse [{$existing->id}] is not marked as transit.");
            }

            if (! $existing->active) {
                throw new InvalidArgumentException("Configured in-transit warehouse [{$existing->id}] is inactive.");
            }

            return $existing;
        }

        // 2. Check warehouses in the company with is_transit = true
        $transitWarehouses = Warehouse::where('company_id', $company->id)
            ->where('is_transit', true)
            ->lockForUpdate()
            ->get();

        if ($transitWarehouses->count() > 1) {
            throw new InvalidArgumentException("Ambiguous in-transit warehouse mapping: company [{$company->id}] has multiple transit warehouses.");
        }

        if ($transitWarehouses->count() === 1) {
            /** @var Warehouse $existingTransit */
            $existingTransit = $transitWarehouses->first();
            if (! $existingTransit->active) {
                throw new InvalidArgumentException("Company in-transit warehouse [{$existingTransit->id}] is inactive.");
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

        app(AuditService::class)->log(
            (int) $company->id,
            'purchasing.in_transit_warehouse.provisioned',
            'Dedicated company in-transit warehouse provisioned',
            (int) $actor->id,
            $warehouse
        );

        $settings->in_transit_warehouse_id = $warehouse->id;
        $settings->save();

        return $warehouse;
    }
}
