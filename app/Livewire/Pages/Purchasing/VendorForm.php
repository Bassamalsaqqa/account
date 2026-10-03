<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\Vendor;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class VendorForm extends Component
{
    use AuthorizesPurchasingPages;

    #[Locked]
    public ?int $vendorId = null;

    public string $name_ar = '';

    public ?string $name_en = null;

    public ?string $business_name_ar = null;

    public ?string $business_name_en = null;

    public ?string $code = null;

    public ?string $phone = null;

    public ?string $whatsapp = null;

    public ?string $email = null;

    public ?string $tax_number = null;

    public ?string $address_ar = null;

    public ?string $address_en = null;

    public ?string $city_ar = null;

    public ?string $city_en = null;

    public ?string $postal_code = null;

    public string $country_code = 'PS';

    public ?string $preferred_locale = null;

    public ?string $default_currency_code = null;

    public ?string $notes = null;

    public bool $active = true;

    private const FIELDS = [
        'name_ar', 'name_en', 'business_name_ar', 'business_name_en', 'code',
        'phone', 'whatsapp', 'email', 'tax_number', 'address_ar', 'address_en',
        'city_ar', 'city_en', 'postal_code', 'country_code', 'preferred_locale',
        'default_currency_code', 'notes', 'active',
    ];

    public function mount(CompanyContext $context, ?string $publicId = null): void
    {
        $this->pageCompanyId = $context->companyId();
        $company = $this->authorizePurchasing('vendors.manage');
        if ($publicId !== null) {
            $vendor = Vendor::where('company_id', $company->id)->where('public_id', $publicId)->firstOrFail();
            $this->vendorId = $vendor->id;
            foreach (self::FIELDS as $field) {
                $this->{$field} = $vendor->{$field};
            }
        }
    }

    public function save(VendorCatalogService $service): mixed
    {
        $company = $this->authorizePurchasing('vendors.manage');
        $payload = [];
        foreach (self::FIELDS as $field) {
            $payload[$field] = $this->{$field};
        }
        $vendor = $service->save($company, auth()->user(), $payload, $this->vendorId);
        session()->flash('success', __($this->vendorId === null ? 'purchasing.created_successfully' : 'purchasing.updated_successfully'));

        return redirect()->route(auth()->user()->hasPermissionTo('vendors.view') ? 'vendors.show' : 'vendors.edit', $vendor->public_id);
    }

    public function render(): View
    {
        $company = $this->authorizePurchasing('vendors.manage');

        return view('livewire.pages.purchasing.vendor-form', [
            'currencies' => $company->currencies()->where('enabled', true)->get(),
            'locales' => $company->languages()->where('enabled', true)->get(),
            'canView' => auth()->user()->hasPermissionTo('vendors.view'),
        ]);
    }
}
