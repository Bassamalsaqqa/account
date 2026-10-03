<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Company;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Audit\AuditService;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class VendorCatalogService
{
    /** @param array<string, mixed> $data */
    public function save(Company $company, User $actor, array $data, ?int $vendorId = null): Vendor
    {
        return DB::transaction(function () use ($company, $actor, $data, $vendorId): Vendor {
            $company = app(SalesActorGuard::class)->lockAndAuthorize($company->id, $actor, 'vendors.manage');

            $vendor = $vendorId === null
                ? new Vendor
                : Vendor::where('company_id', $company->id)->whereKey($vendorId)->lockForUpdate()->firstOrFail();

            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    $data[$key] = trim($value) === '' ? null : trim($value);
                }
            }
            if (isset($data['code']) && is_string($data['code'])) {
                $data['code'] = strtoupper($data['code']);
            }
            if (isset($data['country_code']) && is_string($data['country_code'])) {
                $data['country_code'] = strtoupper($data['country_code']);
            }

            $rules = [
                'name_ar' => ['required', 'string', 'max:255'],
                'name_en' => ['nullable', 'string', 'max:255'],
                'business_name_ar' => ['nullable', 'string', 'max:255'],
                'business_name_en' => ['nullable', 'string', 'max:255'],
                'code' => [
                    'nullable',
                    'string',
                    'max:64',
                    Rule::unique('vendors', 'code')
                        ->where('company_id', $company->id)
                        ->ignore($vendorId),
                ],
                'phone' => ['nullable', 'string', 'max:64'],
                'whatsapp' => ['nullable', 'string', 'max:64'],
                'email' => ['nullable', 'email', 'max:255'],
                'tax_number' => ['nullable', 'string', 'max:64'],
                'address_ar' => ['nullable', 'string'],
                'address_en' => ['nullable', 'string'],
                'city_ar' => ['nullable', 'string', 'max:100'],
                'city_en' => ['nullable', 'string', 'max:100'],
                'postal_code' => ['nullable', 'string', 'max:32'],
                'country_code' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{2}$/D'],
                'preferred_locale' => ['nullable', Rule::in($company->languages()->where('enabled', true)->pluck('locale')->all())],
                'default_currency_code' => ['nullable', Rule::in($company->currencies()->where('enabled', true)->pluck('currency_code')->all())],
                'notes' => ['nullable', 'string'],
                'active' => ['sometimes', 'boolean'],
                'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            ];

            $payload = Validator::make($data, $rules)->validate();

            if (array_key_exists('active', $payload)) {
                $payload['status'] = $payload['active'] ? 'active' : 'inactive';
                unset($payload['active']);
            }
            $before = $vendor->exists ? $vendor->only(array_keys($payload)) : null;

            $vendor->fill($payload);
            $vendor->company_id = $company->id;
            $vendor->updated_by = $actor->id;

            if (! $vendor->exists) {
                $vendor->created_by = $actor->id;
            }

            $vendor->save();
            app(AuditService::class)->log($company->id, 'vendor.saved', 'Vendor configuration saved', $actor->id, $vendor, $before, $vendor->only(array_keys($payload)));

            return $vendor->refresh();
        });
    }
}
