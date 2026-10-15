<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Exceptions\IdempotencyConflictException;
use App\Models\Company;
use App\Services\Sales\QuickPartyCreation;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait QuickPartyCreate
{
    abstract protected function quickPartyKind(): string;

    abstract protected function quickPartyFormPermission(): string;

    abstract protected function authorizeQuickPartyForm(): Company;

    abstract protected function selectQuickParty(int $id): void;

    public bool $showQuickCustomerModal = false;

    public bool $showQuickVendorModal = false;

    #[Locked]
    public ?string $quickPartyToken = null;

    public string $quick_customer_name_ar = '';

    public string $quick_customer_name_en = '';

    public string $quick_customer_phone = '';

    public string $quick_customer_code = '';

    public string $quick_vendor_name_ar = '';

    public string $quick_vendor_name_en = '';

    public string $quick_vendor_phone = '';

    public string $quick_vendor_code = '';

    public function openQuickCustomerModal(): void
    {
        $this->openQuickParty('customer');
    }

    public function openQuickVendorModal(): void
    {
        $this->openQuickParty('vendor');
    }

    public function closeQuickCustomerModal(): void
    {
        $this->closeQuickParty('customer');
    }

    public function closeQuickVendorModal(): void
    {
        $this->closeQuickParty('vendor');
    }

    public function saveQuickCustomer(): void
    {
        $this->saveQuickParty('customer');
    }

    public function saveQuickVendor(): void
    {
        $this->saveQuickParty('vendor');
    }

    private function quickPartyPermission(string $kind): string
    {
        abort_unless($kind === $this->quickPartyKind(), 403);

        return $this->quickPartyFormPermission();
    }

    private function quickPartyCompany(string $kind): Company
    {
        $permission = $this->quickPartyPermission($kind);
        $context = app(CompanyContext::class);
        abort_unless(auth()->check() && $context->hasCompany() && $context->companyId() === $this->pageCompanyId, 403);
        $this->authorizeQuickPartyForm();

        return DB::transaction(function () use ($kind, $permission): Company {
            $guard = app(SalesActorGuard::class);
            $company = $guard->lockAndAuthorize($this->pageCompanyId, auth()->user(), $permission);
            $guard->lockAndAuthorize($this->pageCompanyId, auth()->user(), $kind === 'customer' ? 'customers.manage' : 'vendors.manage');

            return $company;
        });
    }

    private function openQuickParty(string $kind): void
    {
        $this->quickPartyCompany($kind);
        $this->resetQuickPartyFields($kind);
        $this->quickPartyToken = (string) Str::uuid();
        $this->showQuickCustomerModal = $kind === 'customer';
        $this->showQuickVendorModal = $kind === 'vendor';
    }

    private function closeQuickParty(string $kind): void
    {
        abort_unless($kind === $this->quickPartyKind(), 403);
        $context = app(CompanyContext::class);
        abort_unless(auth()->check() && $context->hasCompany() && $context->companyId() === $this->pageCompanyId, 403);
        $this->showQuickCustomerModal = false;
        $this->showQuickVendorModal = false;
        $this->quickPartyToken = null;
        $this->resetQuickPartyFields($kind);
    }

    private function resetQuickPartyFields(string $kind): void
    {
        $fields = [];
        foreach (['name_ar', 'name_en', 'phone', 'code'] as $field) {
            $property = 'quick_'.$kind.'_'.$field;
            $this->{$property} = '';
            $fields[] = $property;
        }
        $this->resetErrorBag($fields);
    }

    private function saveQuickParty(string $kind): void
    {
        $company = $this->quickPartyCompany($kind);
        abort_unless(($kind === 'customer' ? $this->showQuickCustomerModal : $this->showQuickVendorModal)
            && $this->quickPartyToken !== null, 403);
        $prefix = 'quick_'.$kind.'_';
        $rules = [];
        foreach (['name_ar' => 255, 'name_en' => 255, 'phone' => 64, 'code' => 64] as $field => $limit) {
            $this->{$prefix.$field} = trim($this->{$prefix.$field});
            $rules[$prefix.$field] = [$field === 'name_ar' ? 'required' : 'nullable', 'string', 'max:'.$limit];
        }
        $this->validate($rules);
        $data = ['name_ar' => $this->{$prefix.'name_ar'}, 'name_en' => $this->{$prefix.'name_en'} !== '' ? $this->{$prefix.'name_en'} : null,
            'phone' => $this->{$prefix.'phone'} !== '' ? $this->{$prefix.'phone'} : null, 'code' => $this->{$prefix.'code'} !== '' ? $this->{$prefix.'code'} : null,
            'preferred_locale' => $this->document_locale, 'default_currency_code' => $this->currency_code, 'status' => 'active'];
        try {
            $party = app(QuickPartyCreation::class)->execute($company, auth()->user(), $kind,
                $this->quickPartyToken, $data, $this->quickPartyPermission($kind));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError(isset($rules[$prefix.$field]) ? $prefix.$field : $prefix.'name_ar', $message);
                }
            }

            return;
        } catch (IdempotencyConflictException) {
            $this->addError($prefix.'name_ar', __('workflow.token_payload_mismatch'));

            return;
        }
        $this->closeQuickParty($kind);
        $this->selectQuickParty((int) $party->id);
    }
}
