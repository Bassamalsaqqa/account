<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing\Settings;

use App\Models\CompanyPurchaseSetting;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseSettingsService;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseSettingsForm extends Component
{
    #[Locked]
    public int $settingsCompanyId;

    public ?int $default_payment_terms_days = null;

    public ?int $default_receiving_warehouse_id = null;

    public bool $warn_duplicate_vendor_invoice = true;

    public function mount(CompanyContext $context): void
    {
        $this->settingsCompanyId = (int) $context->companyId();
        $this->authorizeSettings();

        $company = $context->company();

        /** @var CompanyPurchaseSetting|null $settings */
        $settings = DB::transaction(function () use ($company): CompanyPurchaseSetting {
            app(SalesActorGuard::class)->lockAndAuthorize($company->id, auth()->user(), 'settings.purchases.manage');

            return CompanyPurchaseSetting::firstOrCreate(['company_id' => $company->id])->refresh();
        });

        $this->default_payment_terms_days = $settings->default_payment_terms_days;
        $this->default_receiving_warehouse_id = $settings->default_receiving_warehouse_id;
        $this->warn_duplicate_vendor_invoice = (bool) $settings->warn_duplicate_vendor_invoice;
    }

    public function save(CompanyContext $context): void
    {
        $this->authorizeSettings();

        $company = $context->company();
        $user = auth()->user();

        $this->validate([
            'default_payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'default_receiving_warehouse_id' => ['nullable', 'integer'],
            'warn_duplicate_vendor_invoice' => ['boolean'],
        ]);

        try {
            app(PurchaseSettingsService::class)->save($company, $user, [
                'default_payment_terms_days' => $this->default_payment_terms_days,
                'default_receiving_warehouse_id' => $this->default_receiving_warehouse_id,
                'warn_duplicate_vendor_invoice' => $this->warn_duplicate_vendor_invoice,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->addError('default_receiving_warehouse_id', $e->getMessage());

            return;
        }

        session()->flash('success', __('purchasing.settings_saved_successfully'));
    }

    private function authorizeSettings(): void
    {
        abort_unless(auth()->check(), 403);

        try {
            DB::transaction(function (): void {
                app(SalesActorGuard::class)->lockAndAuthorize(
                    $this->settingsCompanyId, auth()->user(), 'settings.purchases.manage');
            });
        } catch (AuthorizationException $e) {
            abort(403);
        }
    }

    public function render(CompanyContext $context): View
    {
        $this->authorizeSettings();

        $company = $context->company();
        $warehouses = Warehouse::where('company_id', $company->id)
            ->where('active', true)
            ->orderBy('name_ar')
            ->get();

        return view('livewire.pages.purchasing.settings.purchase-settings-form', [
            'warehouses' => $warehouses,
        ]);
    }
}
