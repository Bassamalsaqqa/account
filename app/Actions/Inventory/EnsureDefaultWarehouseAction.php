<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Company;
use App\Models\CompanyInventorySettings;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EnsureDefaultWarehouseAction
{
    /**
     * Idempotently ensure exactly one active default warehouse exists for the company.
     * Runs atomically under company lock. Resolves zero, multiple, or inactive default states.
     */
    public function execute(Company $company, ?int $actorUserId = null): Warehouse
    {
        return DB::transaction(function () use ($company, $actorUserId): Warehouse {
            // Lock Company row FOR UPDATE for company-first concurrency safety
            /** @var Company $lockedCompany */
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->firstOrFail();

            // 1. Find all warehouses flagged as default
            $defaultWarehouses = Warehouse::where('company_id', $lockedCompany->id)
                ->where('is_default', true)
                ->lockForUpdate()
                ->get();

            $activeDefaults = $defaultWarehouses->filter(fn (Warehouse $w) => $w->active);
            $inactiveDefaults = $defaultWarehouses->filter(fn (Warehouse $w) => ! $w->active);

            // Clean up inactive default flags
            foreach ($inactiveDefaults as $inact) {
                $inact->update(['is_default' => false]);
            }

            // Case A: Exactly one active default exists
            if ($activeDefaults->count() === 1) {
                /** @var Warehouse $activeDefault */
                $activeDefault = $activeDefaults->first();
                $this->syncSettings($lockedCompany, $activeDefault);

                return $activeDefault;
            }

            // Case B: Multiple active defaults exist — keep the first, demote the rest
            if ($activeDefaults->count() > 1) {
                /** @var Warehouse $chosenDefault */
                $chosenDefault = $activeDefaults->sortBy('id')->first();
                foreach ($activeDefaults as $other) {
                    if ($other->id !== $chosenDefault->id) {
                        $other->update(['is_default' => false]);
                    }
                }
                $this->syncSettings($lockedCompany, $chosenDefault);

                return $chosenDefault;
            }

            // Case C: Zero active defaults exist — try promoting an existing active warehouse
            /** @var Warehouse|null $existingActive */
            $existingActive = Warehouse::where('company_id', $lockedCompany->id)
                ->where('active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($existingActive !== null) {
                $existingActive->update(['is_default' => true]);
                $this->syncSettings($lockedCompany, $existingActive);

                return $existingActive;
            }

            // Case D: Zero active warehouses exist — provision canonical default
            $creatorId = $actorUserId;
            if ($creatorId === null) {
                /** @var User|null $firstMember */
                $firstMember = $lockedCompany->users()->where('company_user.status', 'active')->first();
                if ($firstMember === null) {
                    throw new InvalidArgumentException("Cannot provision default warehouse for company [{$lockedCompany->id}] without an active user member.");
                }
                $creatorId = $firstMember->id;
            }

            $warehouse = Warehouse::create([
                'company_id' => $lockedCompany->id,
                'code' => 'WH-MAIN',
                'name_ar' => 'المستودع الرئيسي',
                'name_en' => 'Main Warehouse',
                'address_ar' => null,
                'address_en' => null,
                'is_default' => true,
                'active' => true,
                'created_by' => $creatorId,
            ]);

            $this->syncSettings($lockedCompany, $warehouse);

            return $warehouse;
        });
    }

    private function syncSettings(Company $company, Warehouse $warehouse): void
    {
        $settings = CompanyInventorySettings::where('company_id', $company->id)->first();
        if ($settings !== null && $settings->default_warehouse_id !== $warehouse->id) {
            $settings->update(['default_warehouse_id' => $warehouse->id]);
        }
    }
}
