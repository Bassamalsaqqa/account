<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CustomerCatalogService
{
    /** @param array<string, mixed> $data */
    public function save(Company $company, User $actor, array $data, ?int $customerId = null): Customer
    {
        return DB::transaction(function () use ($company, $actor, $data, $customerId): Customer {
            $company = app(SalesActorGuard::class)->lockAndAuthorize($company->id, $actor, 'customers.manage');
            $customer = $customerId === null ? new Customer : Customer::where('company_id', $company->id)->whereKey($customerId)->lockForUpdate()->firstOrFail();
            $rules = [
                'name_ar' => ['required', 'string', 'max:255'],
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
            $payload = Validator::make($data, $rules)->validate();
            if (array_key_exists('credit_limit', $data)) {
                $limit = $data['credit_limit'] === null ? null : MoneyAmount::from($data['credit_limit']);
                if ($limit?->getAmount()->isNegative()) {
                    throw ValidationException::withMessages(['credit_limit' => __('validation.min.numeric', ['attribute' => __('sales.credit_limit'), 'min' => 0])]);
                }
                $payload['credit_limit'] = $limit === null ? null : (string) $limit;
            }
            $customer->fill($payload);
            $customer->company_id = $company->id;
            $customer->updated_by = $actor->id;
            if (! $customer->exists) {
                $customer->created_by = $actor->id;
            }
            $customer->save();

            return $customer->refresh();
        });
    }
}
