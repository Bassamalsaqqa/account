<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Application\Platform\TenantWriteAccess;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\Audit\AuditService;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CustomerCatalogService
{
    /** @param array<string, mixed> $data */
    public function save(Company $company, User $actor, array $data, ?int $customerId = null): Customer
    {
        return app(TenantWriteAccess::class)->transaction((int) $company->id, function () use ($company, $actor, $data, $customerId): Customer {
            $company = app(SalesActorGuard::class)->lockAndAuthorize($company->id, $actor, 'customers.manage');
            $customer = $customerId === null ? new Customer : Customer::where('company_id', $company->id)->whereKey($customerId)->lockForUpdate()->firstOrFail();

            if (array_key_exists('address', $data) && ! array_key_exists('address_ar', $data)) {
                $data['address_ar'] = $data['address'];
            }

            foreach (['name_ar', 'name_en', 'phone', 'whatsapp', 'email', 'tax_number', 'address_ar', 'address_en', 'address_line_1_ar', 'address_line_1_en', 'city_ar', 'city_en', 'notes'] as $fieldKey) {
                if (array_key_exists($fieldKey, $data) && is_string($data[$fieldKey])) {
                    $trimmed = trim($data[$fieldKey]);
                    $data[$fieldKey] = $trimmed === '' ? null : $trimmed;
                }
            }

            $effectiveNameAr = array_key_exists('name_ar', $data) ? $data['name_ar'] : ($customer->exists ? $customer->name_ar : null);
            $effectiveNameEn = array_key_exists('name_en', $data) ? $data['name_en'] : ($customer->exists ? $customer->name_en : null);

            $rules = [
                'name_ar' => ['nullable', 'string', 'max:255'],
                'name_en' => ['nullable', 'string', 'max:255'],
                'business_name_ar' => ['nullable', 'string', 'max:255'],
                'business_name_en' => ['nullable', 'string', 'max:255'],
                'code' => ['nullable', 'string', 'max:64', Rule::unique('customers', 'code')->where('company_id', $company->id)->ignore($customerId)],
                'phone' => ['nullable', 'string', 'max:64'],
                'whatsapp' => ['nullable', 'string', 'max:64'],
                'email' => ['nullable', 'email', 'max:255'],
                'tax_number' => ['nullable', 'string', 'max:64'],
                'address_line_1_ar' => ['nullable', 'string'],
                'address_line_1_en' => ['nullable', 'string'],
                'address_ar' => ['nullable', 'string'],
                'address_en' => ['nullable', 'string'],
                'city_ar' => ['nullable', 'string', 'max:100'],
                'city_en' => ['nullable', 'string', 'max:100'],
                'preferred_locale' => ['nullable', Rule::in($company->languages()->where('enabled', true)->pluck('locale')->all())],
                'default_currency_code' => ['nullable', Rule::in($company->currencies()->where('enabled', true)->pluck('currency_code')->all())],
                'notes' => ['nullable', 'string'],
                'active' => ['sometimes', 'boolean'],
                'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            ];

            $validator = Validator::make($data, $rules);
            $validator->after(function ($validator) use ($effectiveNameAr, $effectiveNameEn): void {
                $hasAr = is_string($effectiveNameAr) && trim($effectiveNameAr) !== '';
                $hasEn = is_string($effectiveNameEn) && trim($effectiveNameEn) !== '';
                if (! $hasAr && ! $hasEn) {
                    $validator->errors()->add('name_ar', __('validation.required_without', [
                        'attribute' => __('sales.name_ar'),
                        'values' => __('sales.name_en'),
                    ]));
                }
            });

            $payload = $validator->validate();
            if (array_key_exists('credit_limit', $data)) {
                $rawLimit = $data['credit_limit'];
                if (is_string($rawLimit) && trim($rawLimit) === '') {
                    $rawLimit = null;
                } elseif (is_numeric($rawLimit)) {
                    $rawLimit = (string) $rawLimit;
                }
                $limit = $rawLimit === null ? null : MoneyAmount::from($rawLimit);
                if ($limit?->getAmount()->isNegative()) {
                    throw ValidationException::withMessages(['credit_limit' => __('validation.min.numeric', ['attribute' => __('sales.credit_limit'), 'min' => 0])]);
                }
                $payload['credit_limit'] = $limit === null ? null : (string) $limit->getAmount()->toScale(2, RoundingMode::HALF_UP);
            }
            $before = $customer->exists ? $customer->only(array_keys($payload)) : null;
            $customer->fill($payload);
            $customer->company_id = $company->id;
            $customer->updated_by = $actor->id;
            if (! $customer->exists) {
                $customer->created_by = $actor->id;
            }
            $customer->save();
            app(AuditService::class)->log($company->id, 'customer.saved', 'Customer configuration saved', $actor->id, $customer, $before, $customer->only(array_keys($payload)));

            return $customer->refresh();
        });
    }
}
