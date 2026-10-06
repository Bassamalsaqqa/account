<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Queries;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;

final class PurchasingHistoryGuard
{
    /**
     * Authorize the current user for purchase price history queries in the given company.
     *
     * @throws AuthorizationException
     */
    public function authorize(int $companyId): User
    {
        if (! auth()->check()) {
            throw new AuthorizationException('Authentication is required to view purchase price history.');
        }

        /** @var User $actor */
        $actor = auth()->user();

        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== $companyId) {
            throw new AuthorizationException('Active company context must match the requested company.');
        }

        $company = Company::find($companyId);
        if ($company === null || $company->status !== 'active') {
            throw new AuthorizationException('Active company is required.');
        }

        $membership = CompanyUser::where('company_id', $companyId)
            ->where('user_id', $actor->id)
            ->first();

        if ($membership === null || $membership->status !== 'active') {
            throw new AuthorizationException('Active membership is required.');
        }

        setPermissionsTeamId($companyId);
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        if (! $actor->hasPermissionTo('purchasing.cost.view')) {
            throw new AuthorizationException('Permission purchasing.cost.view is required to view purchase price history.');
        }

        return $actor;
    }

    /**
     * Validate that a product belongs to the requested company (including soft deleted).
     *
     * @throws AuthorizationException
     */
    public function validateProduct(Product|int $product, int $companyId): int
    {
        if ($product instanceof Product) {
            if ((int) $product->company_id !== $companyId) {
                throw new AuthorizationException('Cross-tenant history query is forbidden.');
            }
            $product = (int) $product->id;
        }

        $exists = Product::withTrashed()
            ->where('company_id', $companyId)
            ->where('id', $product)
            ->exists();

        if (! $exists) {
            throw new AuthorizationException('Product not found in active company.');
        }

        return $product;
    }

    /**
     * Validate that a vendor belongs to the requested company (including soft deleted).
     *
     * @throws AuthorizationException
     */
    public function validateVendor(Vendor|int $vendor, int $companyId): int
    {
        if ($vendor instanceof Vendor) {
            if ((int) $vendor->company_id !== $companyId) {
                throw new AuthorizationException('Cross-tenant history query is forbidden.');
            }
            $vendor = (int) $vendor->id;
        }

        $exists = Vendor::withTrashed()
            ->where('company_id', $companyId)
            ->where('id', $vendor)
            ->exists();

        if (! $exists) {
            throw new AuthorizationException('Vendor not found in active company.');
        }

        return $vendor;
    }

    /** @param list<int> $productIds
     * @return list<int>
     */
    public function validateProducts(array $productIds, int $companyId): array
    {
        foreach ($productIds as $id) {
            if ($id <= 0) {
                throw new AuthorizationException('Invalid product identity.');
            }
        }
        $ids = array_values(array_unique($productIds));
        if (count($ids) > 100) {
            throw new AuthorizationException('Purchase history requests are limited to 100 products.');
        }
        if ($ids !== [] && Product::withTrashed()->where('company_id', $companyId)->whereIn('id', $ids)->count() !== count($ids)) {
            throw new AuthorizationException('Product not found in active company.');
        }

        return $ids;
    }
}
