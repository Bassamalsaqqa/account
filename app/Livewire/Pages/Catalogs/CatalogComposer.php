<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Catalogs;

use App\Models\Catalog;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductUnit;
use App\Models\PublicShare;
use App\Services\Catalogs\ApprovedCatalogMedia;
use App\Services\Catalogs\CatalogRenderer;
use App\Services\Catalogs\CatalogService;
use App\Services\Sales\ShareExpiry;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class CatalogComposer extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $companyId = null;

    #[Locked]
    public ?int $catalogId = null;

    #[Locked]
    public ?string $publicId = null;

    #[Locked]
    public ?int $draftRevision = null;

    #[Locked]
    public ?int $previewRevision = null;

    #[Locked]
    public ?string $previewHash = null;

    #[Locked]
    public ?string $requestKey = null;

    #[Locked]
    public ?string $url = null;

    #[Locked]
    public ?string $qr = null;

    #[Locked]
    public string $status = 'draft';

    #[Locked]
    public int $publishedRevision = 0;

    #[Locked]
    public ?string $publishedAt = null;

    public bool $hasUnsavedChanges = false;

    public bool $priceAcknowledged = false;

    /**
     * Editable, untrusted Livewire input.
     *
     * @var array<string,mixed>
     */
    public array $headers = [
        'name_ar' => '',
        'name_en' => null,
        'description_ar' => null,
        'description_en' => null,
        'locale' => 'ar',
        'show_prices' => false,
        'show_sku' => true,
        'show_description' => true,
        'show_images' => true,
        'currency_code' => null,
        'tax_basis' => null,
    ];

    /**
     * Editable, untrusted Livewire input.
     *
     * @var array<array-key,mixed>
     */
    public array $items = [];

    public string $productSearch = '';

    public bool $showShareModal = false;

    public ?string $sharePassword = null;

    public ?string $shareExpires = null;

    #[Locked]
    public ?string $expectedState = null;

    #[Locked]
    public bool $hasPassword = false;

    #[Locked]
    public ?string $currentExpires = null;

    #[Locked]
    public bool $hasExistingShare = false;

    public ?string $linkAccessExpires = null;

    public ?string $linkAccessPassword = null;

    public bool $editingLinkAccess = false;

    #[Locked]
    public ?string $newLinkRequestKey = null;

    #[Locked]
    public ?string $currentExpiresAt = null;

    #[Locked]
    public string $companyTimezone = 'UTC';

    #[Locked]
    public bool $legacyExpiry = false;

    public bool $showNewLinkModal = false;

    public ?string $newLinkPassword = null;

    public ?string $newLinkExpires = null;

    public bool $showPreviewModal = false;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $previewPayload = null;

    public bool $showQrModal = false;

    public function mount(?string $publicId = null): void
    {
        $context = app(CompanyContext::class);
        $companyId = $context->companyId();
        abort_unless(auth()->check(), 403, 'Unauthenticated.');
        $this->companyId = (int) $companyId;

        $this->authorizeFresh('catalogs.view');
        $this->authorizeSelectionFresh();

        if ($publicId !== null && $publicId !== '') {
            $this->loadCatalog($publicId);
        }
    }

    public function loadCatalog(string $publicId): void
    {
        $this->authorizeFresh('catalogs.view');
        $this->authorizeSelectionFresh();

        $catalog = Catalog::where('company_id', $this->companyId)
            ->where('public_id', $publicId)
            ->firstOrFail();

        if ($catalog->show_prices || isset($catalog->published_payload['currency_code'])) {
            $this->authorizeFresh('catalogs.show_prices');
        }

        $this->catalogId = (int) $catalog->id;
        $this->publicId = (string) $catalog->public_id;
        $this->status = (string) $catalog->status;
        $this->draftRevision = (int) $catalog->draft_revision;
        $this->publishedRevision = (int) $catalog->published_revision;
        $this->publishedAt = $this->publicationTime($catalog);

        $this->headers = [
            'name_ar' => (string) $catalog->name_ar,
            'name_en' => $catalog->name_en,
            'description_ar' => $catalog->description_ar,
            'description_en' => $catalog->description_en,
            'locale' => (string) $catalog->locale,
            'show_prices' => (bool) $catalog->show_prices,
            'show_sku' => (bool) $catalog->show_sku,
            'show_description' => (bool) $catalog->show_description,
            'show_images' => (bool) $catalog->show_images,
            'currency_code' => $catalog->currency_code,
            'tax_basis' => $catalog->tax_basis,
        ];

        $items = $catalog->items()
            ->where('company_id', $this->companyId)
            ->orderBy('position')
            ->limit(CatalogService::MAX_ITEMS + 1)
            ->get();
        abort_if($items->count() > CatalogService::MAX_ITEMS, 422);

        $this->items = [];
        foreach ($items as $item) {
            $this->items[] = [
                'product_id' => (int) $item->product_id,
                'unit_id' => (int) $item->unit_id,
                'image_id' => $item->image_id !== null ? (int) $item->image_id : null,
                'name_ar' => $item->name_ar,
                'name_en' => $item->name_en,
                'description_ar' => $item->description_ar,
                'description_en' => $item->description_en,
                'custom_price' => $item->custom_price !== null ? (string) $item->custom_price : null,
            ];
        }

        $this->hasUnsavedChanges = false;
        $this->previewHash = null;
        $this->previewRevision = null;
        $this->previewPayload = null;
        $this->requestKey = null;
        $this->newLinkRequestKey = null;
        $this->priceAcknowledged = false;
        $this->url = null;
        $this->qr = null;
        $this->hasExistingShare = false;
        $this->editingLinkAccess = false;
        $this->showNewLinkModal = false;
        $this->newLinkPassword = null;
        $this->newLinkExpires = null;
        $this->expectedState = null;
        $this->hasPassword = false;
        $this->currentExpires = null;
        $this->currentExpiresAt = null;
        $this->companyTimezone = $this->resolveCompanyTimezone();
        $this->legacyExpiry = false;
        $this->linkAccessExpires = null;
        $this->linkAccessPassword = null;

        if (auth()->user()->hasPermissionTo('catalogs.share')) {
            $share = PublicShare::where('company_id', $this->companyId)
                ->where('subject_type', 'product_catalog')
                ->where('subject_id', $catalog->id)
                ->where('is_active', true)
                ->whereNull('revoked_at')
                ->orderByDesc('id')
                ->first();

            $this->hasExistingShare = ($share !== null);

            if ($share !== null && $share->isValid() && $this->status === 'active') {
                try {
                    $result = app(CatalogService::class)->share($catalog->id);
                    $this->url = $result['url'];
                } catch (\Throwable) {
                    $this->url = null;
                }
            }
        }
    }

    public function createCatalog(): void
    {
        $this->authorizeFresh('catalogs.view');

        $this->catalogId = null;
        $this->publicId = null;
        $this->draftRevision = null;
        $this->previewRevision = null;
        $this->previewHash = null;
        $this->previewPayload = null;
        $this->requestKey = null;
        $this->newLinkRequestKey = null;
        $this->url = null;
        $this->qr = null;
        $this->hasExistingShare = false;
        $this->editingLinkAccess = false;
        $this->showNewLinkModal = false;
        $this->newLinkPassword = null;
        $this->newLinkExpires = null;
        $this->expectedState = null;
        $this->hasPassword = false;
        $this->currentExpires = null;
        $this->currentExpiresAt = null;
        $this->companyTimezone = $this->resolveCompanyTimezone();
        $this->legacyExpiry = false;
        $this->linkAccessExpires = null;
        $this->linkAccessPassword = null;
        $this->status = 'draft';
        $this->publishedRevision = 0;
        $this->publishedAt = null;
        $this->hasUnsavedChanges = false;
        $this->priceAcknowledged = false;
        $this->headers = [
            'name_ar' => '',
            'name_en' => null,
            'description_ar' => null,
            'description_en' => null,
            'locale' => 'ar',
            'show_prices' => false,
            'show_sku' => true,
            'show_description' => true,
            'show_images' => true,
            'currency_code' => null,
            'tax_basis' => null,
        ];
        $this->items = [];
    }

    public function updatedHeaders(): void
    {
        $this->assertClientBounds();
        $this->invalidatePreview();

        if (! $this->headers['show_prices']) {
            $this->priceAcknowledged = false;
            $this->headers['currency_code'] = null;
            $this->headers['tax_basis'] = null;
        }
    }

    public function updatedItems(): void
    {
        $this->assertClientBounds();
        $this->invalidatePreview();
    }

    public function updatedProductSearch(): void
    {
        $this->resetPage('productPage');
    }

    public function addProduct(int $productId): void
    {
        $this->authorizeFresh('catalogs.manage');
        $this->authorizeSelectionFresh();
        $this->assertClientBounds();

        if (count($this->items) >= CatalogService::MAX_ITEMS) {
            $this->addError('items', __('catalogs.product_limit_reached'));

            return;
        }

        foreach ($this->items as $item) {
            if ($item['product_id'] === $productId) {
                $this->addError('items', __('catalogs.already_added'));

                return;
            }
        }

        $product = Product::where('company_id', $this->companyId)
            ->where('active', true)
            ->select(['id', 'public_id', 'sku', 'name_ar', 'name_en', 'description_ar', 'description_en', 'base_unit_id'])
            ->findOrFail($productId);

        $unitId = (int) ProductUnit::where('company_id', $this->companyId)
            ->where('product_id', $productId)
            ->where('active', true)
            ->where('is_base', true)
            ->value('unit_id');

        if ($unitId === 0) {
            $unitId = (int) ProductUnit::where('company_id', $this->companyId)
                ->where('product_id', $productId)
                ->where('active', true)
                ->value('unit_id');
        }

        if ($unitId === 0) {
            $unitId = (int) $product->base_unit_id;
        }

        $primaryImageId = ProductImage::where('company_id', $this->companyId)
            ->where('product_id', $productId)
            ->where('is_primary', true)
            ->where('disk', 'public')
            ->value('id');

        $this->items[] = [
            'product_id' => (int) $product->id,
            'unit_id' => $unitId,
            'image_id' => $primaryImageId !== null ? (int) $primaryImageId : null,
            'name_ar' => null,
            'name_en' => null,
            'description_ar' => null,
            'description_en' => null,
            'custom_price' => null,
        ];

        $this->invalidatePreview();
    }

    public function removeItem(int $index): void
    {
        $this->authorizeFresh('catalogs.manage');

        if (isset($this->items[$index])) {
            unset($this->items[$index]);
            $this->items = array_values($this->items);
            $this->invalidatePreview();
        }
    }

    public function moveItemUp(int $index): void
    {
        $this->authorizeFresh('catalogs.manage');

        if ($index > 0 && isset($this->items[$index], $this->items[$index - 1])) {
            $prev = $this->items[$index - 1];
            $this->items[$index - 1] = $this->items[$index];
            $this->items[$index] = $prev;
            $this->items = array_values($this->items);
            $this->invalidatePreview();
        }
    }

    public function moveItemDown(int $index): void
    {
        $this->authorizeFresh('catalogs.manage');

        if ($index < count($this->items) - 1 && isset($this->items[$index], $this->items[$index + 1])) {
            $next = $this->items[$index + 1];
            $this->items[$index + 1] = $this->items[$index];
            $this->items[$index] = $next;
            $this->items = array_values($this->items);
            $this->invalidatePreview();
        }
    }

    public function saveDraft(): void
    {
        $this->authorizeFresh('catalogs.manage');
        $this->authorizeSelectionFresh();
        $this->assertClientBounds();
        if ($this->headers['show_prices']) {
            $this->authorizeFresh('catalogs.show_prices');
        }

        $this->validate([
            'headers.name_ar' => 'required|string|max:160',
            'headers.name_en' => 'nullable|string|max:160',
            'headers.description_ar' => 'nullable|string|max:2000',
            'headers.description_en' => 'nullable|string|max:2000',
            'headers.locale' => 'required|in:ar,en',
            'headers.show_prices' => 'required|boolean',
            'headers.show_sku' => 'required|boolean',
            'headers.show_description' => 'required|boolean',
            'headers.show_images' => 'required|boolean',
            'headers.currency_code' => 'nullable|string|size:3',
            'headers.tax_basis' => 'nullable|string|max:160',
            'items' => 'array|max:'.CatalogService::MAX_ITEMS,
            'items.*.product_id' => 'required|integer|min:1',
            'items.*.unit_id' => 'required|integer|min:1',
            'items.*.image_id' => 'nullable|integer|min:1',
            'items.*.name_ar' => 'nullable|string|max:160',
            'items.*.name_en' => 'nullable|string|max:160',
            'items.*.description_ar' => 'nullable|string|max:2000',
            'items.*.description_en' => 'nullable|string|max:2000',
            'items.*.custom_price' => ['nullable', 'string', 'regex:/^\d{1,14}(?:\.\d{1,6})?$/D'],
        ]);

        $sanitizedItems = [];
        foreach ($this->items as $item) {
            $sanitizedItems[] = [
                'product_id' => (int) $item['product_id'],
                'unit_id' => (int) $item['unit_id'],
                'image_id' => isset($item['image_id']) && $item['image_id'] !== '' ? (int) $item['image_id'] : null,
                'name_ar' => ! empty($item['name_ar']) ? $item['name_ar'] : null,
                'name_en' => ! empty($item['name_en']) ? $item['name_en'] : null,
                'description_ar' => ! empty($item['description_ar']) ? $item['description_ar'] : null,
                'description_en' => ! empty($item['description_en']) ? $item['description_en'] : null,
                'custom_price' => isset($item['custom_price']) && $item['custom_price'] !== '' ? (string) $item['custom_price'] : null,
            ];
        }

        try {
            $catalog = app(CatalogService::class)->save(
                $this->catalogId,
                $this->headers,
                $sanitizedItems,
                $this->draftRevision
            );

            $this->catalogId = (int) $catalog->id;
            $this->publicId = (string) $catalog->public_id;
            $this->draftRevision = (int) $catalog->draft_revision;
            $this->status = (string) $catalog->status;
            $this->hasUnsavedChanges = false;
            $this->previewHash = null;
            $this->previewRevision = null;
            $this->previewPayload = null;
            $this->requestKey = null;
            $this->newLinkRequestKey = null;
            $this->priceAcknowledged = false;

            session()->flash('success', __('catalogs.draft_saved'));
        } catch (InvalidArgumentException $e) {
            $this->addError('general', $e->getMessage());
        } catch (\Throwable) {
            $this->addError('general', __('catalogs.unexpected_error'));
        }
    }

    public function previewPublication(): void
    {
        $this->resetErrorBag('general');
        $this->authorizeFresh('catalogs.publish');
        if ($this->headers['show_prices']) {
            $this->authorizeFresh('catalogs.show_prices');
        }

        if ($this->catalogId === null) {
            $this->addError('general', __('catalogs.stale_preview_error'));

            return;
        }

        if ($this->hasUnsavedChanges) {
            $this->addError('general', __('catalogs.stale_preview_error'));

            return;
        }

        try {
            $preview = app(CatalogService::class)->preview($this->catalogId);

            $this->previewRevision = (int) $preview['revision'];
            $this->previewHash = (string) $preview['hash'];
            $this->previewPayload = (array) $preview['payload'];
            $this->requestKey = (string) Str::uuid();
            $this->priceAcknowledged = false;
            $this->showPreviewModal = true;
        } catch (InvalidArgumentException $e) {
            $this->addError('general', $e->getMessage());
        } catch (\Throwable) {
            $this->addError('general', __('catalogs.unexpected_error'));
        }
    }

    public function publish(): void
    {
        $this->resetErrorBag('general');
        $this->authorizeFresh('catalogs.publish');
        if (isset($this->previewPayload['currency_code'])) {
            $this->authorizeFresh('catalogs.show_prices');
        }

        if ($this->catalogId === null || $this->previewHash === null || $this->previewRevision !== $this->draftRevision) {
            $this->addError('general', __('catalogs.stale_preview_error'));

            return;
        }

        if (isset($this->previewPayload['currency_code'])) {
            if (! $this->priceAcknowledged || empty($this->previewPayload['tax_basis'])) {
                $this->addError('general', __('catalogs.price_ack_required'));

                return;
            }
        }

        $this->requestKey ??= (string) Str::uuid();

        try {
            // The acknowledgement applies to the confirmed persisted projection, never editable headers.
            $current = app(CatalogService::class)->preview($this->catalogId);
            if ($current['revision'] !== $this->previewRevision || ! hash_equals($this->previewHash, $current['hash'])) {
                throw new InvalidArgumentException(__('catalogs.stale_preview_error'));
            }
            if (isset($current['payload']['currency_code']) && ! $this->priceAcknowledged) {
                throw new InvalidArgumentException(__('catalogs.price_ack_required'));
            }
            $catalog = app(CatalogService::class)->publish(
                $this->catalogId,
                $this->previewRevision,
                $this->previewHash,
                $this->requestKey
            );

            $this->publishedRevision = (int) $catalog->published_revision;
            $this->status = (string) $catalog->status;
            $this->publishedAt = $this->publicationTime($catalog);
            $this->showPreviewModal = false;

            session()->flash('success', __('catalogs.published_successfully'));
        } catch (InvalidArgumentException $e) {
            $this->addError('general', $e->getMessage());
        } catch (\Throwable) {
            $this->addError('general', __('catalogs.unexpected_error'));
        }
    }

    public function manageState(string $state): void
    {
        if (! in_array($state, ['active', 'paused', 'revoked'], true)) {
            return;
        }

        $this->authorizeFresh($state === 'active' ? 'catalogs.publish' : 'catalogs.revoke');

        if ($this->catalogId === null) {
            return;
        }

        try {
            app(CatalogService::class)->state($this->catalogId, $state);
            $this->status = $state;

            if ($state !== 'active') {
                $this->url = null;
                $this->qr = null;
                $this->showQrModal = false;
                $this->showShareModal = false;
            }

            if ($state === 'revoked') {
                $this->url = null;
                $this->qr = null;
                $this->hasExistingShare = false;
                $this->editingLinkAccess = false;
                $this->expectedState = null;
                $this->hasPassword = false;
                $this->currentExpires = null;
                $this->currentExpiresAt = null;
                $this->linkAccessExpires = null;
                $this->linkAccessPassword = null;
                $this->newLinkRequestKey = null;
                $this->showNewLinkModal = false;
                $this->showShareModal = false;
                $this->newLinkPassword = null;
                $this->newLinkExpires = null;
            }

            session()->flash('success', __('catalogs.state_updated'));
        } catch (InvalidArgumentException $e) {
            $this->addError('general', $e->getMessage());
        } catch (\Throwable) {
            $this->addError('general', __('catalogs.unexpected_error'));
        }
    }

    public function createOrRecoverLink(): void
    {
        $this->resetErrorBag('share');
        $this->authorizeFresh('catalogs.share');
        $this->validate([
            'sharePassword' => ['nullable', 'string', 'min:8', 'max:128'],
            'shareExpires' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'sharePassword.min' => __('catalogs.access_password_invalid'),
            'sharePassword.max' => __('catalogs.access_password_invalid'),
            'shareExpires.date_format' => __('catalogs.access_expiry_invalid'),
        ]);

        if (! $this->validateCompanyExpiry($this->shareExpires, 'shareExpires')) {
            return;
        }

        if ($this->catalogId === null) {
            return;
        }

        try {
            $result = app(CatalogService::class)->share(
                $this->catalogId,
                $this->sharePassword === '' ? null : $this->sharePassword,
                $this->shareExpires === '' ? null : $this->shareExpires
            );

            $this->url = $result['url'];
            $this->sharePassword = null;
            $this->hasExistingShare = true;
            $this->editingLinkAccess = false;
            $this->showShareModal = true;
            session()->flash('success', __('catalogs.link_created'));
        } catch (InvalidArgumentException $e) {
            $this->showShareModal = true;
            $this->addError('share', $e->getMessage() === 'Catalog link is retired; explicitly issue a New Link.'
                ? __('catalogs.link_retired_notice') : $e->getMessage());
        } catch (\Throwable) {
            $this->showShareModal = true;
            $this->addError('share', __('catalogs.unexpected_error'));
        }
    }

    public function openLinkAccess(): void
    {
        $this->resetErrorBag('linkAccess');
        $this->authorizeFresh('catalogs.share');
        abort_if($this->catalogId === null, 403, 'Catalog required.');

        try {
            $settings = app(CatalogService::class)->linkSettings($this->catalogId);

            $this->expectedState = $settings['state'];
            $this->hasPassword = (bool) $settings['has_password'];
            $this->currentExpires = $settings['expires'];
            $this->currentExpiresAt = $settings['expires_at'] ?? null;
            $this->companyTimezone = $settings['timezone'];
            $this->legacyExpiry = $settings['legacy_expiry'];

            $this->linkAccessExpires = $settings['expires'];
            $this->linkAccessPassword = null;

            $this->hasExistingShare = true;
            $this->editingLinkAccess = true;
            $this->showShareModal = true;
        } catch (InvalidArgumentException $e) {
            $this->addError('linkAccess', $e->getMessage());
        } catch (\Throwable $e) {
            if ($e instanceof ModelNotFoundException) {
                throw $e;
            }
            $this->addError('linkAccess', __('catalogs.unexpected_error'));
        }
    }

    public function closeLinkAccess(): void
    {
        $this->editingLinkAccess = false;
        $this->linkAccessPassword = null;
        $this->resetErrorBag('linkAccess');
    }

    public function updateLinkAccess(): void
    {
        $this->resetErrorBag('linkAccess');
        $this->authorizeFresh('catalogs.share');
        abort_if($this->catalogId === null, 403, 'Catalog required.');

        $expectedState = $this->expectedState;
        abort_if($expectedState === null || $expectedState === '', 422, 'Expected state required.');

        if ($this->linkAccessExpires === '') {
            $this->linkAccessExpires = null;
        }
        if ($this->linkAccessPassword === '') {
            $this->linkAccessPassword = null;
        }

        $this->validate([
            'linkAccessExpires' => ['nullable', 'date_format:Y-m-d'],
            'linkAccessPassword' => ['nullable', 'string', 'min:8', 'max:128'],
        ], [
            'linkAccessExpires.date_format' => __('catalogs.access_expiry_invalid'),
            'linkAccessPassword.min' => __('catalogs.access_password_invalid'),
            'linkAccessPassword.max' => __('catalogs.access_password_invalid'),
        ]);

        if ($this->linkAccessExpires !== $this->currentExpires && ! $this->validateCompanyExpiry($this->linkAccessExpires, 'linkAccessExpires')) {
            return;
        }

        try {
            $url = app(CatalogService::class)->updateLinkAccess(
                $this->catalogId,
                $expectedState,
                $this->linkAccessExpires,
                $this->linkAccessPassword
            );

            $refreshed = app(CatalogService::class)->linkSettings($this->catalogId);
            $this->expectedState = $refreshed['state'];
            $this->hasPassword = (bool) $refreshed['has_password'];
            $this->currentExpires = $refreshed['expires'];
            $this->currentExpiresAt = $refreshed['expires_at'] ?? null;
            $this->companyTimezone = $refreshed['timezone'];
            $this->legacyExpiry = $refreshed['legacy_expiry'];
            $this->linkAccessExpires = $refreshed['expires'];

            $this->url = $url;
            $this->linkAccessPassword = null;
            $this->sharePassword = null;
            $this->qr = null;

            $this->hasExistingShare = true;
            $this->editingLinkAccess = false;
            $this->showShareModal = true;

            session()->flash('success', __('catalogs.link_access_updated'));
        } catch (InvalidArgumentException $e) {
            $message = match ($e->getMessage()) {
                'Catalog access settings changed. Reload before saving.' => __('catalogs.access_stale'),
                'An active published catalog is required.' => __('catalogs.access_requires_active'),
                default => __('catalogs.access_unavailable'),
            };
            $this->addError('linkAccess', $message);
        } catch (\Throwable $e) {
            if ($e instanceof ModelNotFoundException) {
                throw $e;
            }
            $this->addError('linkAccess', __('catalogs.unexpected_error'));
        }
    }

    public function openNewLink(): void
    {
        $this->resetErrorBag('newLink');
        $this->authorizeFresh('catalogs.share');
        $this->authorizeFresh('catalogs.publish');
        $this->authorizeSelectionFresh();
        $this->assertPriceAuthority();

        if ($this->catalogId === null || $this->publishedRevision === 0) {
            $this->addError('general', __('catalogs.access_requires_active'));

            return;
        }

        if ($this->status === 'paused') {
            $this->addError('general', __('catalogs.new_link_paused_error'));

            return;
        }

        $this->newLinkRequestKey = (string) Str::uuid();
        $this->newLinkPassword = null;
        $this->newLinkExpires = null;
        $this->companyTimezone = $this->resolveCompanyTimezone();
        $this->showNewLinkModal = true;
    }

    public function closeNewLink(): void
    {
        $this->showNewLinkModal = false;
        $this->newLinkPassword = null;
        $this->newLinkExpires = null;
        $this->resetErrorBag('newLink');
    }

    public function issueNewLink(): void
    {
        $this->resetErrorBag('newLink');
        $this->authorizeFresh('catalogs.share');
        $this->authorizeFresh('catalogs.publish');
        $this->authorizeSelectionFresh();
        $this->assertPriceAuthority();

        if ($this->catalogId === null || $this->publishedRevision === 0) {
            $this->addError('newLink', __('catalogs.access_requires_active'));

            return;
        }

        if ($this->status === 'paused') {
            $this->addError('newLink', __('catalogs.new_link_paused_error'));

            return;
        }

        $this->newLinkRequestKey ??= (string) Str::uuid();

        $this->validate([
            'newLinkPassword' => ['nullable', 'string', 'min:8', 'max:128'],
            'newLinkExpires' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'newLinkPassword.min' => __('catalogs.access_password_invalid'),
            'newLinkPassword.max' => __('catalogs.access_password_invalid'),
            'newLinkExpires.date_format' => __('catalogs.access_expiry_invalid'),
        ]);

        if (! $this->validateCompanyExpiry($this->newLinkExpires, 'newLinkExpires')) {
            return;
        }

        try {
            $password = ($this->newLinkPassword === '' || $this->newLinkPassword === null) ? null : $this->newLinkPassword;
            $expires = ($this->newLinkExpires === '' || $this->newLinkExpires === null) ? null : $this->newLinkExpires;

            $result = app(CatalogService::class)->newLink(
                $this->catalogId,
                $this->newLinkRequestKey,
                $password,
                $expires
            );

            $this->url = $result['url'];
            $this->qr = null;
            $this->status = 'active';
            $this->hasExistingShare = true;
            $this->editingLinkAccess = false;
            $this->showNewLinkModal = false;
            $this->showShareModal = true;
            $this->newLinkPassword = null;
            $this->newLinkExpires = null;
            $this->newLinkRequestKey = null;

            session()->flash('success', __('catalogs.new_link_created'));
        } catch (InvalidArgumentException $e) {
            $this->addError('newLink', $e->getMessage());
        } catch (\Throwable) {
            $this->addError('newLink', __('catalogs.unexpected_error'));
        }
    }

    private function resolveCompanyTimezone(): string
    {
        return Company::whereKey($this->companyId)->firstOrFail()->timezone;
    }

    private function validateCompanyExpiry(?string $date, string $field): bool
    {
        try {
            app(ShareExpiry::class)->catalogDate($date === '' ? null : $date, $this->resolveCompanyTimezone());

            return true;
        } catch (InvalidArgumentException) {
            $this->addError($field, __('catalogs.access_expiry_invalid'));

            return false;
        }
    }

    public function showQr(): void
    {
        $this->qr = null;
        $this->showQrModal = false;
        $this->authorizeFresh('catalogs.share');

        if ($this->url === null) {
            return;
        }

        try {
            abort_if($this->catalogId === null, 403);
            $current = app(CatalogService::class)->share($this->catalogId);
            if (! hash_equals($current['url'], $this->url)) {
                throw new InvalidArgumentException(__('catalogs.access_stale'));
            }
            $this->qr = app(CatalogRenderer::class)->generateQrDataUri($this->url);
            $this->showQrModal = true;
        } catch (InvalidArgumentException $e) {
            $this->addError('general', $e->getMessage());
        } catch (\Throwable) {
            $this->addError('general', __('catalogs.unexpected_error'));
        }
    }

    private function authorizeFresh(string $permission): Company
    {
        $context = app(CompanyContext::class);
        $companyId = $context->companyId();
        abort_unless(auth()->check(), 403, 'Unauthenticated.');
        abort_unless($this->companyId !== null && (int) $companyId === (int) $this->companyId, 403, 'Company identity mismatch.');

        return DB::transaction(fn (): Company => app(CatalogService::class)->authorize($permission));
    }

    private function authorizeSelectionFresh(): void
    {
        $this->authorizeFresh('catalogs.view');
        $permission = auth()->user()->hasPermissionTo('inventory.stock.view') ? 'inventory.stock.view' : 'inventory.product.manage';
        $this->authorizeFresh($permission);
    }

    private function publicationTime(Catalog $catalog): ?string
    {
        $date = $catalog->getAttribute('published_at');

        return $date instanceof \DateTimeInterface ? $date->format(DATE_ATOM) : null;
    }

    private function invalidatePreview(): void
    {
        $this->hasUnsavedChanges = true;
        $this->previewHash = null;
        $this->previewRevision = null;
        $this->previewPayload = null;
        $this->requestKey = null;
        $this->newLinkRequestKey = null;
        $this->priceAcknowledged = false;
    }

    private function assertClientBounds(): void
    {
        abort_unless(array_is_list($this->items) && count($this->items) <= CatalogService::MAX_ITEMS && strlen($this->productSearch) <= 120, 422);
        abort_unless($this->newLinkPassword === null || strlen($this->newLinkPassword) <= 128, 422);
        abort_unless($this->newLinkExpires === null || strlen($this->newLinkExpires) <= 10, 422);
        abort_unless($this->sharePassword === null || strlen($this->sharePassword) <= 128, 422);
        abort_unless($this->shareExpires === null || strlen($this->shareExpires) <= 10, 422);
        abort_unless($this->linkAccessPassword === null || strlen($this->linkAccessPassword) <= 128, 422);
        abort_unless($this->linkAccessExpires === null || strlen($this->linkAccessExpires) <= 10, 422);
        $allowed = ['product_id', 'unit_id', 'image_id', 'name_ar', 'name_en', 'description_ar', 'description_en', 'custom_price'];
        foreach ($this->items as $item) {
            abort_unless(is_array($item) && array_diff(array_keys($item), $allowed) === [] && array_diff($allowed, array_keys($item)) === [], 422);
            foreach (['product_id', 'unit_id'] as $identity) {
                abort_unless((is_int($item[$identity]) && $item[$identity] > 0)
                    || (is_string($item[$identity]) && preg_match('/^[1-9][0-9]{0,17}$/D', $item[$identity])), 422);
            }
            foreach ($item as $value) {
                abort_unless($value === null || is_int($value) || (is_string($value) && strlen($value) <= 8000), 422);
            }
        }
        $headerKeys = ['name_ar', 'name_en', 'description_ar', 'description_en', 'locale', 'show_prices', 'show_sku', 'show_description', 'show_images', 'currency_code', 'tax_basis'];
        abort_unless(array_diff(array_keys($this->headers), $headerKeys) === [] && array_diff($headerKeys, array_keys($this->headers)) === []
            && strlen(json_encode($this->headers, JSON_THROW_ON_ERROR)) <= 20000, 422);
        foreach ($this->headers as $value) {
            abort_unless($value === null || is_bool($value) || (is_string($value) && strlen($value) <= 8000), 422);
        }
    }

    public function hydrate(): void
    {
        $this->assertClientBounds();
        $this->authorizeFresh('catalogs.view');
        $this->authorizeSelectionFresh();
        $this->assertPriceAuthority();
    }

    private function assertPriceAuthority(): void
    {
        if (($this->headers['show_prices'] ?? false) || isset($this->previewPayload['currency_code'])
            || ($this->catalogId !== null && Catalog::where('company_id', $this->companyId)->whereKey($this->catalogId)
                ->where(fn ($query) => $query->where('show_prices', true)->orWhereNotNull('published_payload->currency_code'))->exists())) {
            $this->authorizeFresh('catalogs.show_prices');
        }
        if (! auth()->user()->hasPermissionTo('catalogs.show_prices')) {
            foreach ($this->items as &$item) {
                $item['custom_price'] = null;
            }
        }
    }

    public function render(): View
    {
        $this->authorizeFresh('catalogs.view');
        $this->authorizeSelectionFresh();
        $this->assertClientBounds();
        $this->assertPriceAuthority();

        $user = auth()->user();
        $canManage = $user->hasPermissionTo('catalogs.manage');
        $canPublish = $user->hasPermissionTo('catalogs.publish');
        $canShare = $user->hasPermissionTo('catalogs.share');
        $canRevoke = $user->hasPermissionTo('catalogs.revoke');
        $canShowPrices = $user->hasPermissionTo('catalogs.show_prices');

        $selectedProductIds = array_column($this->items, 'product_id');
        $selectedProductsMeta = [];
        $selectableUnitsByProduct = [];
        $selectableImagesByProduct = [];

        if ($selectedProductIds !== []) {
            $products = Product::where('company_id', $this->companyId)
                ->whereIn('id', $selectedProductIds)
                ->select(['id', 'public_id', 'sku', 'name_ar', 'name_en', 'description_ar', 'description_en', 'base_unit_id'])
                ->get()
                ->keyBy('id');

            foreach ($products as $id => $p) {
                $selectedProductsMeta[$id] = [
                    'id' => (int) $p->id,
                    'sku' => (string) $p->sku,
                    'name_ar' => (string) $p->name_ar,
                    'name_en' => (string) $p->name_en,
                    'description_ar' => (string) $p->description_ar,
                    'description_en' => (string) $p->description_en,
                ];
            }

            $productUnits = ProductUnit::where('company_id', $this->companyId)
                ->select(['id', 'company_id', 'product_id', 'unit_id', 'is_base', 'active'])
                ->whereIn('product_id', $selectedProductIds)
                ->where('active', true)
                ->with(['unit' => fn ($query) => $query->where('company_id', $this->companyId)->select(['id', 'company_id', 'name_ar', 'name_en', 'active'])])
                ->limit(5001)
                ->get();
            abort_if($productUnits->count() > 5000, 422);

            foreach ($productUnits as $pu) {
                if ($pu->unit !== null && $pu->unit->active) {
                    $selectableUnitsByProduct[$pu->product_id][] = [
                        'id' => (int) $pu->unit_id,
                        'name_ar' => (string) $pu->unit->name_ar,
                        'name_en' => (string) $pu->unit->name_en,
                        'is_base' => (bool) $pu->is_base,
                    ];
                }
            }

            $productImages = ProductImage::where('company_id', $this->companyId)
                ->whereIn('product_id', $selectedProductIds)
                ->where('disk', 'public')
                ->orderBy('sort_order')
                ->limit(3001)
                ->get();
            abort_if($productImages->count() > 3000, 422);

            foreach ($productImages as $img) {
                try {
                    $approved = app(ApprovedCatalogMedia::class)->approve((int) $this->companyId, (int) $img->product_id, (int) $img->id);
                    $thumbnail = app(ApprovedCatalogMedia::class)->render($approved, (int) $this->companyId, (int) $img->product_id);
                } catch (\Throwable) {
                    continue;
                }
                if ($thumbnail === null) {
                    continue;
                }
                $selectableImagesByProduct[$img->product_id][] = [
                    'id' => (int) $img->id,
                    'thumbnail' => $thumbnail,
                    'is_primary' => (bool) $img->is_primary,
                ];
            }
        }

        $availableQuery = Product::where('company_id', $this->companyId)
            ->where('active', true)
            ->select(['id', 'public_id', 'sku', 'name_ar', 'name_en', 'base_unit_id']);

        if (trim($this->productSearch) !== '') {
            $term = trim($this->productSearch);
            $availableQuery->where(function ($q) use ($term): void {
                $q->where('sku', 'like', "%{$term}%")
                    ->orWhere('name_ar', 'like', "%{$term}%")
                    ->orWhere('name_en', 'like', "%{$term}%");
            });
        }

        $availableProducts = $availableQuery->orderByDesc('id')->paginate(24, ['*'], 'productPage');

        $sidebarCatalogs = Catalog::where('company_id', $this->companyId)
            ->select(['id', 'public_id', 'name_ar', 'name_en', 'status', 'draft_revision', 'published_revision', 'published_at'])
            ->orderByDesc('id')
            ->paginate(24, ['*'], 'catalogPage');

        $currencies = CompanyCurrency::where('company_id', $this->companyId)
            ->where('enabled', true)
            ->pluck('currency_code')
            ->all();

        $previewImages = [];
        foreach ($this->previewPayload['items'] ?? [] as $position => $item) {
            if (isset($item['media'])) {
                $previewImages[$position] = app(ApprovedCatalogMedia::class)->render($item['media'], (int) $this->companyId, (int) $item['product_id']);
            }
        }

        return view('livewire.pages.catalogs.catalog-composer', [
            'canManage' => $canManage,
            'canPublish' => $canPublish,
            'canShare' => $canShare,
            'canRevoke' => $canRevoke,
            'canShowPrices' => $canShowPrices,
            'selectedProductsMeta' => $selectedProductsMeta,
            'selectableUnitsByProduct' => $selectableUnitsByProduct,
            'selectableImagesByProduct' => $selectableImagesByProduct,
            'availableProducts' => $availableProducts,
            'sidebarCatalogs' => $sidebarCatalogs,
            'currencies' => $currencies,
            'previewImages' => $previewImages,
        ]);
    }
}
