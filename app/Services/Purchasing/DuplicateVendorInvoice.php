<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\CompanyPurchaseSetting;
use App\Models\Purchase;

final class DuplicateVendorInvoice
{
    public function exists(int $companyId, ?int $vendorId, ?string $number, ?int $exceptId = null): bool
    {
        if ($vendorId === null || trim($number ?? '') === ''
            || ! CompanyPurchaseSetting::where('company_id', $companyId)->value('warn_duplicate_vendor_invoice')) {
            return false;
        }

        return Purchase::where('company_id', $companyId)->where('vendor_id', $vendorId)
            ->where('vendor_invoice_number', trim($number))->where('status', '!=', Purchase::STATUS_VOID)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
    }
}
