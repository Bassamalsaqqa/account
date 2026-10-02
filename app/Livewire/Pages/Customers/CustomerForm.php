<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Customers;

use App\Models\Customer;
use App\Services\Sales\CustomerCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CustomerForm extends Component
{
    public ?Customer $customer = null;

    public bool $isEditing = false;

    public string $name_ar = '';

    public ?string $name_en = null;

    public ?string $business_name = null;

    public ?string $business_name_en = null;

    public ?string $code = null;

    public ?string $phone = null;

    public ?string $whatsapp = null;

    public ?string $email = null;

    public ?string $tax_number = null;

    public ?string $address_line_1_ar = null;

    public ?string $address_line_1_en = null;

    public ?string $city_ar = null;

    public ?string $city_en = null;

    public string $preferred_locale = 'ar';

    public string $default_currency_code = 'ILS';

    public ?string $credit_limit = null;

    public ?string $notes = null;

    public bool $active = true;

    public function mount(CompanyContext $context, ?string $publicId = null): void
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('customers.manage')) {
            abort(403, 'Unauthorized.');
        }

        if ($publicId !== null) {
            $this->customer = Customer::where('company_id', $company->id)
                ->where('public_id', $publicId)
                ->firstOrFail();

            $this->isEditing = true;
            $this->name_ar = $this->customer->name_ar;
            $this->name_en = $this->customer->name_en;
            $this->business_name = $this->customer->business_name;
            $this->business_name_en = $this->customer->business_name_en;
            $this->code = $this->customer->code;
            $this->phone = $this->customer->phone;
            $this->whatsapp = $this->customer->whatsapp;
            $this->email = $this->customer->email;
            $this->tax_number = $this->customer->tax_number;
            $this->address_line_1_ar = $this->customer->address_line_1_ar;
            $this->address_line_1_en = $this->customer->address_line_1_en;
            $this->city_ar = $this->customer->city_ar;
            $this->city_en = $this->customer->city_en;
            $this->preferred_locale = $this->customer->preferred_locale ?? $company->default_locale;
            $this->default_currency_code = $this->customer->default_currency_code ?? $company->base_currency_code;
            $this->credit_limit = $this->customer->credit_limit !== null ? (string) $this->customer->credit_limit : null;
            $this->notes = $this->customer->notes;
            $this->active = (bool) $this->customer->active;
        } else {
            $this->default_currency_code = $company->base_currency_code;
            $this->preferred_locale = $company->default_locale ?? 'ar';
        }
    }

    public function save(CompanyContext $context): mixed
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('customers.manage')) {
            abort(403, 'Unauthorized.');
        }

        $enabledCurrencies = $company->currencies()->where('enabled', true)->pluck('currency_code')->all();

        $validated = $this->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'business_name_en' => ['nullable', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers', 'code')
                    ->where('company_id', $company->id)
                    ->ignore($this->customer?->id),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'whatsapp' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'address_line_1_ar' => ['nullable', 'string'],
            'address_line_1_en' => ['nullable', 'string'],
            'city_ar' => ['nullable', 'string', 'max:100'],
            'city_en' => ['nullable', 'string', 'max:100'],
            'preferred_locale' => ['required', 'string', 'in:ar,en'],
            'default_currency_code' => ['required', 'string', Rule::in($enabledCurrencies)],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'active' => ['boolean'],
        ]);

        $payload = [
            'name_ar' => $validated['name_ar'],
            'name_en' => $validated['name_en'] ?? null,
            'business_name_ar' => $validated['business_name'] ?? null,
            'business_name_en' => $validated['business_name_en'] ?? null,
            'code' => ! empty($validated['code']) ? $validated['code'] : null,
            'phone' => $validated['phone'] ?? null,
            'whatsapp' => $validated['whatsapp'] ?? null,
            'email' => $validated['email'] ?? null,
            'tax_number' => $validated['tax_number'] ?? null,
            'address_line_1_ar' => $validated['address_line_1_ar'] ?? null,
            'address_line_1_en' => $validated['address_line_1_en'] ?? null,
            'city_ar' => $validated['city_ar'] ?? null,
            'city_en' => $validated['city_en'] ?? null,
            'preferred_locale' => $validated['preferred_locale'],
            'default_currency_code' => $validated['default_currency_code'],
            'credit_limit' => isset($validated['credit_limit']) && trim($validated['credit_limit']) !== '' ? $validated['credit_limit'] : null,
            'notes' => $validated['notes'] ?? null,
            'active' => $validated['active'],
            'updated_by' => $user->id,
        ];

        if ($this->isEditing && $this->customer !== null) {
            $this->customer = app(CustomerCatalogService::class)->save($company, $user, $payload, $this->customer->id);

            session()->flash('success', __('sales.updated_successfully'));

            return redirect()->route('customers.show', $this->customer->public_id);
        }

        $customer = app(CustomerCatalogService::class)->save($company, $user, $payload);

        session()->flash('success', __('sales.created_successfully'));

        return redirect()->route('customers.show', $customer->public_id);
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();
        $currencies = $company->currencies()->where('enabled', true)->get();

        return view('livewire.pages.customers.customer-form', [
            'currencies' => $currencies,
            'baseCurrency' => $company->base_currency_code,
        ]);
    }
}
