<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Products;

use App\Models\ProductBarcode;
use App\Models\User;
use App\Services\Inventory\BarcodeLabelService;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class BarcodeLabels extends Component
{
    use WithPagination;

    #[Locked]
    public int $lockedCompanyId;

    /**
     * Maps ProductBarcode ID => positive quantity count
     *
     * @var array<int, int>
     */
    public array $quantities = [];

    public string $search = '';

    public string $preset = 'a4-3x8';

    public string $locale = 'ar';

    #[Locked]
    public ?string $previewHtml = null;

    public bool $showPreviewModal = false;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || ! auth()->check()) {
            abort(403);
        }

        $this->lockedCompanyId = (int) $context->companyId();
        $this->locale = app()->getLocale() === 'en' ? 'en' : 'ar';

        $this->authorizeAccess();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
        $this->errorMessage = null;
    }

    public function updatingPreset(string $value): void
    {
        if (! array_key_exists($value, BarcodeLabelService::SUPPORTED_PRESETS)) {
            $this->preset = 'a4-3x8';
        }
    }

    public function updatingLocale(string $value): void
    {
        $this->locale = $value === 'en' ? 'en' : 'ar';
    }

    public function selectBarcode(int $id): void
    {
        $this->authorizeAccess();
        $this->errorMessage = null;

        if ($id <= 0) {
            return;
        }

        // Verify existence and same-company ownership
        $barcode = ProductBarcode::query()
            ->where('company_id', $this->lockedCompanyId)
            ->where('id', $id)
            ->whereHas('product', fn ($q) => $q->where('active', true)->whereNull('deleted_at'))
            ->first();

        if ($barcode === null) {
            $this->errorMessage = __('labels.empty_search');

            return;
        }

        if (! isset($this->quantities[$id])) {
            if (count($this->quantities) >= BarcodeLabelService::MAX_DISTINCT_BARCODES) {
                $this->errorMessage = __('labels.max_distinct_limit');

                return;
            }
            if ($this->totalLabelsCount() + 1 > BarcodeLabelService::MAX_TOTAL_LABELS) {
                $this->errorMessage = __('labels.max_labels_limit');

                return;
            }
            $this->quantities[$id] = 1;
        }
    }

    public function removeBarcode(int $id): void
    {
        $this->authorizeAccess();
        $this->errorMessage = null;

        unset($this->quantities[$id]);
    }

    public function updateQuantity(int $id, int $count): void
    {
        $this->authorizeAccess();
        $this->errorMessage = null;
        if (! isset($this->quantities[$id])) {
            $this->selectBarcode($id);
            if (! isset($this->quantities[$id])) {
                return;
            }
        }

        if ($count <= 0) {
            unset($this->quantities[$id]);

            return;
        }

        $currentCount = $this->quantities[$id];
        $projectedTotal = $this->totalLabelsCount() - $currentCount + $count;

        if ($projectedTotal > BarcodeLabelService::MAX_TOTAL_LABELS) {
            $allowed = BarcodeLabelService::MAX_TOTAL_LABELS - ($this->totalLabelsCount() - $currentCount);
            $this->quantities[$id] = max(1, $allowed);
            $this->errorMessage = __('labels.max_labels_limit');

            return;
        }

        $this->quantities[$id] = min(BarcodeLabelService::MAX_TOTAL_LABELS, $count);
    }

    public function preview(): void
    {
        $this->authorizeAccess();
        $this->errorMessage = null;

        if (empty($this->quantities)) {
            $this->errorMessage = __('labels.empty_selected');

            return;
        }

        $service = app(BarcodeLabelService::class);
        try {
            $prepared = $service->prepare($this->quantities, $this->locale, $this->preset);
            $this->previewHtml = $service->html($prepared, printControls: true);
        } catch (\InvalidArgumentException) {
            $this->errorMessage = __('labels.invalid_selection');
            $this->previewHtml = null;

            return;
        }
        $this->showPreviewModal = true;
    }

    public function closePreview(): void
    {
        $this->showPreviewModal = false;
        $this->previewHtml = null;
    }

    public function downloadPdf(): ?StreamedResponse
    {
        $this->authorizeAccess();
        $this->errorMessage = null;

        if (empty($this->quantities)) {
            abort(422, __('labels.empty_selected'));
        }

        $service = app(BarcodeLabelService::class);
        try {
            $prepared = $service->prepare($this->quantities, $this->locale, $this->preset);
            $pdf = $service->pdf($prepared);
        } catch (\InvalidArgumentException) {
            $this->errorMessage = __('labels.invalid_selection');

            return null;
        }

        // Reauthorize actor after PDF generation before delivery
        $this->authorizeAccess();
        abort_unless($prepared === $service->prepare($this->quantities, $this->locale, $this->preset), 409);

        $filename = "barcode-labels-{$this->preset}-{$this->locale}.pdf";

        return response()->streamDownload(
            static function () use ($pdf): void {
                echo $pdf;
            },
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function totalLabelsCount(): int
    {
        $this->validate(['quantities' => 'array|max:100', 'quantities.*' => 'integer|min:1|max:500']);

        return array_sum($this->quantities);
    }

    public function distinctBarcodesCount(): int
    {
        return count($this->quantities);
    }

    public function render(): View
    {
        $this->authorizeAccess();
        $this->validate(['quantities' => 'array|max:100', 'quantities.*' => 'integer|min:1|max:500', 'search' => 'string|max:120', 'locale' => 'in:ar,en', 'preset' => 'in:a4-3x8,a4-2x7']);

        $query = ProductBarcode::query()
            ->where('company_id', $this->lockedCompanyId)
            ->whereHas('product', fn ($q) => $q->where('active', true)->whereNull('deleted_at'))
            ->with([
                'product:id,sku,name_ar,name_en,base_unit_id,company_id',
                'unit:id,name_ar,name_en,symbol_ar,symbol_en,company_id',
            ]);

        $search = trim($this->search);
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('barcode', 'like', $term)
                    ->orWhereHas('product', function ($pq) use ($term) {
                        $pq->where('sku', 'like', $term)
                            ->orWhere('name_ar', 'like', $term)
                            ->orWhere('name_en', 'like', $term);
                    });
            });
        }

        $availableBarcodes = $query->paginate(24);

        $selectedBarcodes = [];
        if (! empty($this->quantities)) {
            $selectedBarcodes = ProductBarcode::query()
                ->where('company_id', $this->lockedCompanyId)
                ->whereIn('id', array_keys($this->quantities))
                ->with([
                    'product:id,sku,name_ar,name_en,base_unit_id,company_id',
                    'unit:id,name_ar,name_en,symbol_ar,symbol_en,company_id',
                ])
                ->get();
        }

        return view('livewire.pages.products.barcode-labels', [
            'availableBarcodes' => $availableBarcodes,
            'selectedBarcodes' => $selectedBarcodes,
        ]);
    }

    /**
     * Fresh active Company membership and source authority check inside read transaction.
     */
    private function authorizeAccess(): void
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== $this->lockedCompanyId || ! auth()->check()) {
            throw new AuthorizationException('An active company matching locked context and authenticated user are required.');
        }

        DB::transaction(function () {
            /** @var User $actor */
            $actor = auth()->user();
            $guard = app(SalesActorGuard::class);
            $guard->lockAndAuthorize($this->lockedCompanyId, $actor, 'inventory.barcode_labels.print');
            if (! $actor->hasAnyPermission(['inventory.stock.view', 'inventory.product.manage'])) {
                throw new AuthorizationException('Product selection authority is required.');
            }
            $guard->lockAndAuthorize($this->lockedCompanyId, $actor, 'inventory.barcode_labels.print');
        });
    }
}
