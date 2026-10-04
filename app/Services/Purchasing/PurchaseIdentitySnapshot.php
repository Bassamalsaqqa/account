<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Company;
use App\Models\Vendor;

final class PurchaseIdentitySnapshot
{
    /** @return array<string, mixed> */
    public function vendor(Vendor $vendor): array
    {
        return $vendor->only(['name_ar', 'name_en', 'business_name_ar', 'business_name_en', 'phone', 'email', 'tax_number', 'address_ar', 'address_en', 'city_ar', 'city_en', 'postal_code', 'country_code']);
    }

    /** @return array<string, mixed> */
    public function company(Company $company): array
    {
        return $company->only(['name_ar', 'name_en', 'phone', 'email', 'tax_number', 'address_ar', 'address_en']);
    }
}
