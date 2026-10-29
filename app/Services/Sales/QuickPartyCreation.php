<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Application\Platform\TenantWriteAccess;
use App\Exceptions\IdempotencyConflictException;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Audit\AuditService;
use App\Services\Purchasing\VendorCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Human-triggered master creation and its durable retry receipt commit together. */
final class QuickPartyCreation
{
    /** @param array<string, mixed> $data */
    public function execute(Company $company, User $actor, string $kind, string $requestKey, array $data, string $formPermission): Customer|Vendor
    {
        if (! in_array($kind, ['customer', 'vendor'], true) || ! Str::isUuid($requestKey)
            || ! in_array($formPermission, $kind === 'customer'
                ? ['sales.invoice.create', 'sales.invoice.edit_draft', 'sales.quote.create', 'sales.quote.edit']
                : ['purchasing.purchase.create', 'purchasing.purchase.edit_draft'], true)) {
            throw new InvalidArgumentException('Invalid quick creation request.');
        }
        // Hash the complete accepted payload, with stable key ordering, not merely the key.
        ksort($data);
        $hash = hash('sha256', json_encode(['kind' => $kind, 'data' => $data], JSON_THROW_ON_ERROR));

        return app(TenantWriteAccess::class)->transaction((int) $company->id, function () use ($company, $actor, $kind, $requestKey, $data, $formPermission, $hash): Customer|Vendor {
            $guard = app(SalesActorGuard::class);
            $company = $guard->lockAndAuthorize((int) $company->id, $actor, $formPermission);
            $guard->lockAndAuthorize((int) $company->id, $actor, $kind === 'customer' ? 'customers.manage' : 'vendors.manage');
            if ($kind === 'vendor') {
                $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.cost.view');
            }

            $receipt = DB::table('party_creation_requests')->where('company_id', $company->id)
                ->where('user_id', $actor->id)->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($receipt !== null) {
                if ($receipt->party_type !== $kind || ! hash_equals($receipt->request_hash, $hash)) {
                    throw new IdempotencyConflictException('Quick creation key has a different payload.');
                }

                return $kind === 'customer'
                    ? Customer::where('company_id', $company->id)->where('status', 'active')->findOrFail($receipt->customer_id)
                    : Vendor::where('company_id', $company->id)->where('status', 'active')->findOrFail($receipt->vendor_id);
            }

            $party = $kind === 'customer'
                ? app(CustomerCatalogService::class)->save($company, $actor, $data)
                : app(VendorCatalogService::class)->save($company, $actor, $data);
            DB::table('party_creation_requests')->insert([
                'company_id' => $company->id, 'user_id' => $actor->id, 'request_key' => $requestKey,
                'request_hash' => $hash, 'party_type' => $kind,
                'customer_id' => $kind === 'customer' ? $party->id : null,
                'vendor_id' => $kind === 'vendor' ? $party->id : null, 'created_at' => now(),
            ]);
            app(AuditService::class)->log((int) $company->id, 'catalog.quick_created', 'Party created from an unsaved trade form',
                $actor->id, $party, meta: ['party_type' => $kind]);

            return $party;
        });
    }
}
