<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Domain\Money\Exceptions\InvalidMoneyException;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Exceptions\IdempotencyConflictException;
use App\Models\Company;
use App\Models\Unit;
use App\Services\Inventory\QuickProductCreation;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

trait QuickProductCreate
{
    abstract protected function assertQuickProductDraft(): void;

    abstract protected function quickProductFormPermission(): string;

    #[Locked]
    public ?int $quickProductDocumentId = null;

    public bool $showQuickProductModal = false;

    #[Locked]
    public ?string $quickProductToken = null;

    public string $quick_product_name_ar = '';

    public string $quick_product_name_en = '';

    public string $quick_product_sku = '';

    public ?int $quick_product_base_unit_id = null;

    public bool $quick_product_track_expiry = false;

    private function authorizeQuickProduct(): Company
    {
        $context = app(CompanyContext::class);
        abort_unless(auth()->check() && $context->hasCompany() && $context->companyId() === $this->pageCompanyId, 403);
        $this->authorizeQuickPartyForm();
        $this->assertQuickProductDraft();

        return DB::transaction(function () {
            $guard = app(SalesActorGuard::class);
            $company = $guard->lockAndAuthorize($this->pageCompanyId, auth()->user(), $this->quickProductFormPermission());
            $guard->lockAndAuthorize($this->pageCompanyId, auth()->user(), 'inventory.product.manage');

            return $company;
        });
    }

    public function openQuickProductModal(): void
    {
        $company = $this->authorizeQuickProduct();
        $this->resetQuickProduct();
        $this->quick_product_base_unit_id = Unit::where('company_id', $company->id)->where('active', true)
            ->orderByRaw("CASE WHEN code = 'piece' THEN 0 ELSE 1 END")->orderBy('id')->value('id');
        $this->quickProductToken = (string) Str::uuid();
        $this->showQuickProductModal = true;
    }

    public function closeQuickProductModal(): void
    {
        $context = app(CompanyContext::class);
        abort_unless(auth()->check() && $context->hasCompany() && $context->companyId() === $this->pageCompanyId, 403);
        $this->showQuickProductModal = false;
        $this->quickProductToken = null;
        $this->resetQuickProduct();
    }

    private function resetQuickProduct(): void
    {
        $this->reset('quick_product_name_ar', 'quick_product_name_en', 'quick_product_sku', 'quick_product_base_unit_id', 'quick_product_track_expiry');
        $this->resetErrorBag(['quick_product_name_ar', 'quick_product_name_en', 'quick_product_sku', 'quick_product_base_unit_id', 'quick_product_track_expiry']);
    }

    /** @return Collection<int, Unit> */
    #[Computed]
    public function quickProductUnits(): Collection
    {
        $company = $this->authorizeQuickProduct();

        return Unit::where('company_id', $company->id)->where('active', true)->orderBy('name_ar')->get();
    }

    public function saveQuickProduct(): void
    {
        // Keep the fresh draft check, master creation and canonical line selection behind one company lock.
        DB::transaction(function (): void {
            $this->createAndSelectQuickProduct();
        });
    }

    private function createAndSelectQuickProduct(): void
    {
        $company = $this->authorizeQuickProduct();
        abort_unless($this->showQuickProductModal && $this->quickProductToken !== null, 403);
        try {
            ExchangeRate::from($this->exchange_rate);
        } catch (InvalidMoneyException) {
            $this->addError('quick_product_name_ar', __('quick_product.rate_required'));

            return;
        }
        try {
            $product = app(QuickProductCreation::class)->execute($company, auth()->user(), $this->quickProductToken, [
                'name_ar' => $this->quick_product_name_ar, 'name_en' => $this->quick_product_name_en,
                'sku' => $this->quick_product_sku, 'base_unit_id' => $this->quick_product_base_unit_id,
                'track_expiry' => $this->quick_product_track_expiry,
            ], $this->quickProductFormPermission());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError('quick_product_'.$field, $message);
                }
            }

            return;
        } catch (IdempotencyConflictException) {
            $this->addError('quick_product_name_ar', __('workflow.token_payload_mismatch'));

            return;
        }
        // Reuse only an untouched empty row; preserve descriptions, prices and other edits.
        $index = null;
        foreach ($this->lines as $candidate => $line) {
            if (empty($line['product_id']) && ($line['item_description'] ?? '') === ''
                && ($line['quantity'] ?? null) === '1'
                && in_array($line['unit_price'] ?? $line['unit_cost'] ?? null, ['0', '0.00'], true)
                && ($line['discount_type'] ?? 'none') === 'none' && ($line['discount_value'] ?? '0') === '0'
                && empty($line['tax_rate_id']) && empty($line['lots']) && empty($line['public_id'])) {
                $index = $candidate;
                break;
            }
        }
        if ($index === null) {
            $this->addLine();
            $index = array_key_last($this->lines);
        }
        $this->selectProduct($index, (int) $product->id);
        $this->productSearch = '';
        $this->closeQuickProductModal();
        $this->dispatch('quick-product-created');
    }
}
