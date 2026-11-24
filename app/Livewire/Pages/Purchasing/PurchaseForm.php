<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\UpdatePurchaseDraftAction;
use App\Application\AI\AiDraftProvenance;
use App\Application\AI\PurchaseDraftCommands;
use App\Application\Commands\ImmediatePurchaseCommands;
use App\Domain\Inventory\Exceptions\InvalidQuantityException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\Exceptions\InvalidMoneyException;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Purchasing\DocumentDiscountAllocator;
use App\Domain\Purchasing\DocumentDiscountIntent;
use App\Domain\Purchasing\DocumentDiscountLineCandidate;
use App\Domain\Purchasing\PurchaseCalculator;
use App\Domain\Purchasing\Queries\VendorProductPriceHistoryQuery;
use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use App\Exceptions\NoActiveCompanyException;
use App\Livewire\Concerns\AdjustsDocumentQuantity;
use App\Livewire\Concerns\AiUnsavedForm;
use App\Livewire\Concerns\CameraBarcodeScan;
use App\Livewire\Concerns\HandlesSearchableSelectors;
use App\Livewire\Concerns\QuickPartyCreate;
use App\Livewire\Concerns\QuickProductCreate;
use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\TaxRate;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Money\EligibleMoneyAccounts;
use App\Services\Purchasing\DuplicateVendorInvoice;
use App\Services\Purchasing\PurchaseDocumentRules;
use App\Services\Purchasing\PurchaseDraftBuilder;
use App\Services\Purchasing\PurchaseFormConfirmation;
use App\Services\Purchasing\PurchaseProductSelection;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[Layout('layouts.app')]
class PurchaseForm extends Component
{
    use AdjustsDocumentQuantity;
    use AiUnsavedForm;
    use AuthorizesPurchasingPages;
    use CameraBarcodeScan;
    use HandlesSearchableSelectors;
    use QuickPartyCreate;
    use QuickProductCreate;

    protected function quickProductFormPermission(): string
    {
        return $this->quickPartyFormPermission();
    }

    protected function assertQuickProductDraft(): void
    {
        $this->authorizeForm();
    }

    protected function quickPartyKind(): string
    {
        return 'vendor';
    }

    protected function quickPartyFormPermission(): string
    {
        return $this->publicId === null ? 'purchasing.purchase.create' : 'purchasing.purchase.edit_draft';
    }

    protected function authorizeQuickPartyForm(): Company
    {
        return $this->authorizeForm();
    }

    protected function selectQuickParty(int $id): void
    {
        $this->vendor_id = $id;
        $this->updatedVendorId();
    }

    #[Locked]
    public ?string $publicId = null;

    #[Locked]
    public ?string $savedDraftPublicId = null;

    #[Locked]
    public ?string $savedDraftPayloadHash = null;

    #[Locked]
    public string $confirmationRequestIdentity = '';

    /** @var array{public_id: string, token: string, key: string, digest: string, paid: bool}|null */
    #[Locked]
    public ?array $completedConfirmation = null;

    public ?int $vendor_id = null;

    public ?int $warehouse_id = null;

    public ?string $vendor_invoice_number = null;

    public string $purchase_date = '';

    public ?string $due_date = null;

    public string $currency_code = 'ILS';

    #[Locked]
    public bool $currencyManuallySelected = false;

    #[Locked]
    public string $previousCurrencyCode = 'ILS';

    public string $exchange_rate = '1.0000000000';

    public string $document_locale = 'ar';

    public ?string $notes = null;

    #[Locked]
    public bool $receivingStock = false;

    public string $barcodeSearch = '';

    public string $vendorSearch = '';

    public string $productSearch = '';

    /** @var list<array<string, mixed>> */
    public array $lines = [];

    /** @var array<string, string> */
    public array $totals = [];

    /** @var array<string, mixed> */
    public array $settlement = [
        'mode' => 'unpaid',
        'payment_method' => 'cash',
        'money_account_id' => '',
        'payment_date' => '',
        'amount' => '',
        'exchange_rate' => '1.0000000000',
        'purchase_amount' => '',
        'payment_currency_amount' => '',
        'reference_number' => '',
        'notes' => '',
        'currency_code' => '',
        'check_number' => '',
        'bank_name' => '',
        'drawer' => '',
        'due_date' => '',
    ];

    #[Locked]
    public ?int $defaultSettlementAccountId = null;

    #[Locked]
    public string $previousSettlementCurrency = '';

    #[Locked]
    public bool $settlementPreview = false;

    #[Locked]
    public bool $showPreview = false;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $previewData = null;

    #[Locked]
    public ?string $confirmationToken = null;

    #[Locked]
    public ?string $executeKey = null;

    #[Locked]
    public ?string $previewDigest = null;

    #[Locked]
    public ?string $previewError = null;

    /** @var array<int, bool> */
    public array $expandedLotCustomization = [];

    /** @var array<int, bool> */
    public array $expandedRowOptions = [];

    #[Locked]
    public string $discount_scope = 'document';

    #[Locked]
    public int $discount_calculation_version = 1;

    /** @var array<string, array{discount_type: string, discount_value: string}> */
    #[Locked]
    public array $persistedLineDiscounts = [];

    public string $discount_type = 'none';

    public string $discount_value = '0';

    public bool $showDiscountControl = false;

    public bool $showMoreDetails = false;

    public ?string $previewGrandTotal = null;

    protected function authorizeForm(): Company
    {
        $company = $this->authorizePurchasing($this->publicId === null ? 'purchasing.purchase.create' : 'purchasing.purchase.edit_draft');
        $this->authorizePurchasing('purchasing.cost.view');
        if ($this->publicId !== null && ($this->completedConfirmation['public_id'] ?? null) !== $this->publicId
            && ($this->confirmationRequestIdentity === '' || ! app(PurchaseFormConfirmation::class)->completed(
                (int) $company->id, auth()->user(), $this->publicId, $this->confirmationRequestIdentity
            ))) {
            Purchase::where('company_id', $company->id)->where('public_id', $this->publicId)->firstOrFail()->assertMutableDraft();
        }

        return $company;
    }

    public function mount(CompanyContext $context, ?string $publicId = null, ?string $receiveProduct = null): void
    {
        $this->pageCompanyId = $context->companyId();
        $this->publicId = $publicId;
        $this->confirmationRequestIdentity = app(PurchaseFormConfirmation::class)->identity($this->pageCompanyId, auth()->user(), $publicId);
        $receiveProduct ??= request()->query('receiveProduct');
        abort_unless($receiveProduct === null || is_string($receiveProduct), 422);
        $company = $this->authorizeForm();
        // These are separate explicit entry intents; neither may silently replace the other's lines.
        abort_if($publicId === null && $receiveProduct !== null && request()->query('assistant') !== null, 422);
        if ($publicId !== null) {
            $purchase = Purchase::where('company_id', $company->id)->where('public_id', $publicId)->firstOrFail();
            $editableData = app(PurchaseDraftBuilder::class)->editableData($purchase);
            foreach ($editableData as $key => $value) {
                $this->{$key} = $value;
            }
            $this->currencyManuallySelected = true;
            $this->discount_type = $editableData['discount_type'] ?? ($purchase->discount_type ?? 'none');
            $this->discount_value = isset($editableData['discount_value'])
                ? (string) $editableData['discount_value']
                : (isset($purchase->discount_value) ? (string) $purchase->discount_value : '0');
            $this->discount_scope = $editableData['discount_scope'] ?? ($purchase->discount_scope ?? 'document');
            $this->discount_calculation_version = (int) ($editableData['discount_calculation_version'] ?? ($purchase->discount_calculation_version ?? 1));
            $this->persistedLineDiscounts = [];
            foreach ($editableData['lines'] as $l) {
                if (! empty($l['public_id'])) {
                    $this->persistedLineDiscounts[$l['public_id']] = [
                        'discount_type' => $l['discount_type'] ?? 'none',
                        'discount_value' => (string) ($l['discount_value'] ?? '0'),
                    ];
                }
            }
            if ($this->discount_type !== 'none' || ($this->discount_scope === 'document' && ! BigDecimal::of((string) ($this->discount_value ?: '0'))->isZero())) {
                $this->showDiscountControl = true;
            }
            foreach ($this->lines as $index => $line) {
                $lots = $line['lots'] ?? [];
                if (count($lots) > 1 || (! empty($lots[0]['lot_number'])) || (! empty($lots[0]['expiry_date'])) || (isset($lots[0]['quantity']) && (string) $lots[0]['quantity'] !== (string) $line['quantity'])) {
                    $this->expandedLotCustomization[$index] = true;
                }
            }
        } else {
            $this->discount_scope = 'document';
            $this->discount_calculation_version = 1;
            $this->discount_type = 'none';
            $this->discount_value = '0';
            $this->showDiscountControl = false;
            $this->purchase_date = now($company->timezone)->format('Y-m-d');
            $this->due_date = app(PurchaseDocumentRules::class)->defaultDueDate($company, $this->purchase_date);
            $this->currency_code = $company->base_currency_code;
            $currentLocale = app()->getLocale();
            $this->document_locale = $company->isLanguageEnabled($currentLocale) ? $currentLocale : $company->default_locale;
            try {
                $this->warehouse_id = app(PurchaseDocumentRules::class)->warehouse($company, null)->id;
            } catch (\InvalidArgumentException) {
                $this->warehouse_id = null;
            }
            $this->addLine();
            if ($receiveProduct !== null) {
                $product = Product::where('company_id', $company->id)->where('public_id', $receiveProduct)
                    ->where('active', true)->where('track_stock', true)->firstOrFail();
                $this->selectProduct(0, $product->id);
                $this->receivingStock = true;
            }
        }
        if ($publicId === null) {
            $this->mountAiInput('purchase');
        }
        $this->previousCurrencyCode = $this->currency_code;
        $this->settlement['payment_date'] = now($company->timezone)->format('Y-m-d');
        $this->settlement['due_date'] = now($company->timezone)->format('Y-m-d');
        $this->settlement['currency_code'] = $this->currency_code ?: $company->base_currency_code;
        $this->syncSettlementDefaults('settlement.mode');
        $this->recalculate();
    }

    public function hydrate(): void
    {
        $this->authorizeForm();
    }

    public function updatedVendorId(): void
    {
        $company = $this->authorizeForm();
        if ($this->vendor_id !== null) {
            $vendor = app(PurchaseDocumentRules::class)->vendor($company, $this->vendor_id);
            if (! $this->currencyManuallySelected) {
                $this->initializeCurrency($company, app(PurchaseDocumentRules::class)->defaultCurrency($company, $vendor));
            }
        }
    }

    public function updatedCurrencyCode(): void
    {
        $company = $this->authorizeForm();
        $this->currencyManuallySelected = true;
        $this->initializeCurrency($company, $this->currency_code);
    }

    private function initializeCurrency(Company $company, string $currency): void
    {
        $changed = $currency !== $this->previousCurrencyCode;
        $this->currency_code = $currency;
        if ($currency === $company->base_currency_code) {
            $this->exchange_rate = '1.0000000000';
        } elseif ($changed) {
            // No authoritative quote exists; require a rate for the new currency.
            $this->exchange_rate = '';
        }
        if ($changed) {
            foreach ($this->lines as &$line) {
                if (! empty($line['product_id'])) {
                    // Supplier costs and discounts cannot be reinterpreted in another currency.
                    $line['unit_cost'] = '';
                    $line['discount_type'] = 'none';
                    $line['discount_value'] = '0';
                    $this->addError('currency', __('purchasing.currency_changed_reenter_costs'));
                }
            }
        }
        $this->previousCurrencyCode = $currency;
        $this->recalculate();
    }

    public function updated(?string $property = null): void
    {
        if ($this->showPreview && ! in_array($property, ['showPreview', 'confirmationToken', 'executeKey', 'previewDigest', 'previewData', 'previewError', 'productSearch'], true)) {
            $this->cancelPreview();
        }

        if ($property !== null && str_starts_with($property, 'settlement.')) {
            $this->syncSettlementDefaults($property);
        }
    }

    public function updatedExchangeRate(): void
    {
        $this->recalculate();
    }

    public function toggleDiscountControl(): void
    {
        $this->showDiscountControl = ! $this->showDiscountControl;
        if (! $this->showDiscountControl) {
            $this->discount_type = 'none';
            $this->discount_value = '0';
            $this->resetErrorBag('discount_value');
            $this->recalculate();
        } elseif ($this->discount_type === 'none') {
            $this->discount_type = 'percent';
        }
    }

    public function updatedDiscountType(): void
    {
        if ($this->discount_type === 'none') {
            $this->discount_value = '0';
        }
        $this->recalculate();
    }

    public function updatedDiscountValue(): void
    {
        $this->resetErrorBag('discount_value');
        $this->recalculate();
    }

    public function toggleMoreDetails(): void
    {
        $this->showMoreDetails = ! $this->showMoreDetails;
    }

    public function toggleRowOptions(int $index): void
    {
        $this->expandedRowOptions[$index] = ! ($this->expandedRowOptions[$index] ?? false);
    }

    public function updatedLines(): void
    {
        foreach ($this->lines as &$line) {
            if (! empty($line['public_id']) && isset($this->persistedLineDiscounts[$line['public_id']])) {
                $line['discount_type'] = $this->persistedLineDiscounts[$line['public_id']]['discount_type'];
                $line['discount_value'] = $this->persistedLineDiscounts[$line['public_id']]['discount_value'];
            } else {
                $line['discount_type'] = 'none';
                $line['discount_value'] = '0';
            }
        }
        unset($line);

        foreach ($this->lines as $index => &$line) {
            if (! empty($line['product_id']) && ! ($this->expandedLotCustomization[$index] ?? false)) {
                if (count($line['lots'] ?? []) === 1 && empty($line['lots'][0]['lot_number']) && empty($line['lots'][0]['expiry_date'])) {
                    $line['lots'][0]['quantity'] = (string) $line['quantity'];
                }
            }
        }
        unset($line);
        $this->recalculate();
    }

    public function saveAndConfirm(): mixed
    {
        $company = $this->authorizeForm();
        if ($this->showQuickVendorModal || $this->showQuickProductModal) {
            return null;
        }
        try {
            $confirmation = app(PurchaseFormConfirmation::class)->execute(
                (int) $company->id, auth()->user(), $this->publicId, $this->confirmationRequestIdentity,
                $this->settlementDigest(), function (): ?array {
                    $this->saveAndConfirmOnce();

                    return $this->completedConfirmation;
                },
                legacyDigest: $this->legacySettlementDigest()
            );
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            $this->previewError = __('commands.form_changed_repreview_required');

            return null;
        }
        if ($confirmation === null) {
            return null;
        }
        $this->completedConfirmation = $confirmation;

        return redirect()->route('purchases.show', $confirmation['public_id']);
    }

    private function saveAndConfirmOnce(): mixed
    {
        $company = $this->authorizeForm();
        $this->authorizePurchasing('purchasing.purchase.post');
        if ($this->showQuickVendorModal || $this->showQuickProductModal) {
            return null;
        }
        if ($this->completedConfirmation !== null) {
            return $this->confirmPost();
        }
        $this->resetErrorBag('draft');
        $this->cancelPreview();
        $this->resetValidation();
        $response = $this->save(true);
        if ($this->getErrorBag()->isNotEmpty() || $this->previewError !== null) {
            return null;
        }
        if ($this->showPreview && ($this->previewData['can_execute'] ?? false) === true) {
            return $this->confirmPost();
        }

        return $response;
    }

    public function saveDraft(): mixed
    {
        if ($this->showQuickVendorModal || $this->showQuickProductModal) {
            return null;
        }

        if ($this->savedDraftPublicId !== null) {
            $this->authorizeForm();
            if (! hash_equals((string) $this->savedDraftPayloadHash, hash('sha256', json_encode($this->draftData(), JSON_THROW_ON_ERROR)))) {
                $this->addError('draft', __('workflow.saved_draft_changed'));

                return null;
            }

            return redirect()->route('purchases.show', $this->savedDraftPublicId);
        }

        return $this->save(false);
    }

    public function scanBarcode(): void
    {
        $this->authorizeForm();
        $raw = trim((string) $this->barcodeSearch);
        if ($raw === '') {
            return;
        }

        // Reject oversized barcode instead of truncating to a different valid barcode
        if (mb_strlen($raw) > 128) {
            $this->addError('barcodeSearch', __('workflow.barcode_too_long'));

            return;
        }

        if ($this->showPreview) {
            $this->cancelPreview();
        }

        $term = $raw;
        $company = Company::where('id', $this->pageCompanyId)->firstOrFail();

        // 1. Try exact barcode
        $barcode = ProductBarcode::with(['product.productUnits.unit', 'unit'])
            ->where('company_id', $company->id)
            ->whereHas('product', fn ($q) => $q->where('company_id', $company->id)
                ->where('active', true)
                ->where('product_type', Product::TYPE_STOCK)
                ->where('track_stock', true))
            ->where('barcode', $term)
            ->first();

        $product = null;
        $productUnit = null;

        if ($barcode !== null) {
            $product = $barcode->product;
            if ($barcode->unit_id !== null) {
                // Must strictly find active unit in same company for this product
                $productUnit = ProductUnit::where('company_id', $company->id)
                    ->where('product_id', $product->id)
                    ->where('unit_id', $barcode->unit_id)
                    ->where('active', true)
                    ->whereHas('unit', fn ($u) => $u->where('company_id', $company->id)->where('active', true))
                    ->first();

                // Reject missing, inactive or foreign unit rather than fallback!
                if ($productUnit === null) {
                    $this->addError('barcodeSearch', __('workflow.barcode_unit_mismatch'));

                    return;
                }
            } else {
                $productUnit = null;
            }
        } else {
            // 2. Try SKU exact match
            $product = Product::with(['productUnits.unit'])
                ->where('company_id', $company->id)
                ->where('active', true)
                ->where('product_type', Product::TYPE_STOCK)
                ->where('track_stock', true)
                ->where('sku', $term)
                ->first();

            if ($product !== null) {
                $productUnit = null;
            }
        }

        if ($product === null) {
            $this->addError('barcodeSearch', __('workflow.product_not_found'));

            return;
        }

        // Canonical product selection BEFORE matching existing row to resolve concrete effective product_unit_id
        try {
            $selected = app(PurchaseProductSelection::class)->select(
                (int) $product->id,
                $productUnit?->id,
                $this->currency_code,
                $this->exchange_rate
            );
        } catch (\InvalidArgumentException|ModelNotFoundException|InvalidMoneyException|InvalidQuantityException|InvalidUnitConversionException $e) {
            $this->addError('barcodeSearch', __('purchasing.invalid_draft'));

            return;
        }

        $selectedUnitId = (int) $selected['product_unit_id'];
        $selectedCost = (string) $selected['unit_cost'];
        $selectedDescription = (string) $selected['item_description'];

        // Preserve lot/line data; safest append or fill truly empty row, do not silently merge differing unit/lot/cost/tax/discount/description lines.
        $existingIndex = null;
        foreach ($this->lines as $idx => $line) {
            $linePid = ! empty($line['product_id']) ? (int) $line['product_id'] : null;
            $lineUnitId = ! empty($line['product_unit_id']) ? (int) $line['product_unit_id'] : null;
            $lineCost = (string) ($line['unit_cost'] ?? '');
            $lineDesc = (string) ($line['item_description'] ?? '');
            $hasLots = ! empty($line['lots']);
            $discountType = $line['discount_type'] ?? 'none';
            $taxRateId = $line['tax_rate_id'] ?? null;

            if ($linePid === (int) $product->id
                && $lineUnitId === $selectedUnitId
                && ! $hasLots
                && $lineCost === $selectedCost
                && $lineDesc === $selectedDescription
                && $discountType === 'none'
                && empty($taxRateId)
            ) {
                $existingIndex = $idx;
                break;
            }
        }

        if ($existingIndex !== null) {
            if (! is_string($this->lines[$existingIndex]['quantity']) || preg_match('/^\d+(?:\.\d{1,6})?$/D', $this->lines[$existingIndex]['quantity']) !== 1) {
                $this->addError("lines.{$existingIndex}.quantity", __('commands.exact_string_required'));

                return;
            }
            $curQty = BigDecimal::of((string) ($this->lines[$existingIndex]['quantity'] ?: '0'));
            $this->lines[$existingIndex]['quantity'] = (string) $curQty->plus(1);
        } else {
            $isTrulyEmptyFirstRow = count($this->lines) === 1
                && empty($this->lines[0]['product_id'])
                && trim((string) ($this->lines[0]['item_description'] ?? '')) === ''
                && empty($this->lines[0]['lots'])
                && in_array((string) ($this->lines[0]['quantity'] ?? ''), ['1', '1.000000', '1.00', ''], true)
                && in_array((string) ($this->lines[0]['unit_cost'] ?? ''), ['0', '0.00', '0.000000', ''], true)
                && (($this->lines[0]['discount_type'] ?? 'none') === 'none')
                && empty($this->lines[0]['tax_rate_id']);

            $newLine = array_replace([
                'product_id' => $product->id,
                'product_unit_id' => $selectedUnitId,
                'item_description' => $selectedDescription,
                'quantity' => '1',
                'unit_cost' => $selectedCost,
                'discount_type' => 'none',
                'discount_value' => '0',
                'tax_rate_id' => null,
                'lots' => [],
            ], $selected);

            if ($isTrulyEmptyFirstRow) {
                $this->lines[0] = $newLine;
            } else {
                $this->lines[] = $newLine;
            }
        }

        $this->barcodeSearch = '';
        $this->resetErrorBag('barcodeSearch');
        $this->recalculate();
        $this->dispatch('barcode-scanned');
    }

    public function addLine(): void
    {
        if ($this->showPreview) {
            $this->cancelPreview();
        }
        $this->authorizeForm();
        $this->lines[] = ['product_id' => null, 'product_unit_id' => null, 'item_description' => '',
            'quantity' => '1', 'unit_cost' => '0', 'discount_type' => 'none', 'discount_value' => '0', 'tax_rate_id' => null, 'lots' => []];
    }

    public function removeLine(int $index): void
    {
        if ($this->showPreview) {
            $this->cancelPreview();
        }
        $this->authorizeForm();
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);

        $newExpanded = [];
        $newRowOptions = [];
        foreach ($this->lines as $newIdx => $line) {
            $oldIdx = $newIdx >= $index ? $newIdx + 1 : $newIdx;
            if (isset($this->expandedLotCustomization[$oldIdx])) {
                $newExpanded[$newIdx] = $this->expandedLotCustomization[$oldIdx];
            }
            if (isset($this->expandedRowOptions[$oldIdx])) {
                $newRowOptions[$newIdx] = $this->expandedRowOptions[$oldIdx];
            }
        }
        $this->expandedLotCustomization = $newExpanded;
        $this->expandedRowOptions = $newRowOptions;

        $this->recalculate();
    }

    public function selectProduct(int $index, mixed $productId, mixed $productUnitId = null): bool
    {
        if ($this->showPreview) {
            $this->cancelPreview();
        }
        $company = $this->authorizeForm();
        abort_unless(isset($this->lines[$index]), 422);
        unset($this->expandedLotCustomization[$index]);

        $currentPublicId = $this->lines[$index]['public_id'] ?? null;
        $persisted = $currentPublicId !== null ? ($this->persistedLineDiscounts[$currentPublicId] ?? null) : null;
        $hasPersistedDiscount = $persisted !== null && ($persisted['discount_type'] ?? 'none') !== 'none' && ! BigDecimal::of((string) ($persisted['discount_value'] ?: '0'))->isZero();

        if ($productId === '' || $productId === null) {
            if ($hasPersistedDiscount) {
                $this->addError("lines.{$index}.product_id", __('purchasing.legacy_discounted_line_immutable_product'));

                return false;
            }
            $this->lines[$index] = ['product_id' => null, 'product_unit_id' => null, 'item_description' => '',
                'quantity' => '1', 'unit_cost' => '0', 'discount_type' => 'none', 'discount_value' => '0', 'tax_rate_id' => null, 'lots' => []];
            $this->recalculate();

            return true;
        }

        try {
            if ($this->currency_code !== $company->base_currency_code && trim((string) $this->exchange_rate) === '') {
                $this->addError('exchange_rate', __('purchasing.exchange_rate_required'));

                return false;
            }
            $unitId = ($productUnitId !== null && $productUnitId !== '') ? (int) $productUnitId : null;
            $selected = app(PurchaseProductSelection::class)->select((int) $productId, $unitId, $this->currency_code, $this->exchange_rate);
        } catch (\InvalidArgumentException|InvalidMoneyException|InvalidUnitConversionException $exception) {
            $this->addError('draft', __('purchasing.invalid_draft'));

            return false;
        }

        $isSameProduct = (int) ($this->lines[$index]['product_id'] ?? 0) === (int) $productId;
        $isSameUnit = (int) ($this->lines[$index]['product_unit_id'] ?? 0) === (int) $selected['product_unit_id'];

        if ($hasPersistedDiscount) {
            if (! $isSameProduct) {
                $this->addError("lines.{$index}.product_id", __('purchasing.legacy_discounted_line_immutable_product'));

                return false;
            }
            if (! $isSameUnit) {
                $this->addError("lines.{$index}.product_unit_id", __('purchasing.legacy_discounted_line_immutable_unit'));

                return false;
            }
        }

        if ($isSameProduct && $isSameUnit && $currentPublicId !== null) {
            $selected['public_id'] = $currentPublicId;
            if ($hasPersistedDiscount) {
                $selected['discount_type'] = $persisted['discount_type'];
                $selected['discount_value'] = $persisted['discount_value'];
            }
        } else {
            unset($this->lines[$index]['public_id']);
        }

        $this->lines[$index] = array_replace($this->lines[$index], $selected, ['lots' => []]);
        $this->recalculate();

        return true;
    }

    public function changeUnit(int $index, mixed $unitId): void
    {
        if ($this->showPreview) {
            $this->cancelPreview();
        }
        $company = $this->authorizeForm();
        abort_unless(isset($this->lines[$index]['product_id']), 422);
        unset($this->expandedLotCustomization[$index]);

        $currentPublicId = $this->lines[$index]['public_id'] ?? null;
        $persisted = $currentPublicId !== null ? ($this->persistedLineDiscounts[$currentPublicId] ?? null) : null;
        $hasPersistedDiscount = $persisted !== null && ($persisted['discount_type'] ?? 'none') !== 'none' && ! BigDecimal::of((string) ($persisted['discount_value'] ?: '0'))->isZero();

        if ($unitId === '') {
            if ($hasPersistedDiscount) {
                $this->addError("lines.{$index}.product_unit_id", __('purchasing.legacy_discounted_line_immutable_unit'));

                return;
            }
            $this->lines[$index]['product_unit_id'] = null;
            $this->lines[$index]['lots'] = [];
            unset($this->lines[$index]['public_id']);

            return;
        }

        $isSameUnit = (int) ($this->lines[$index]['product_unit_id'] ?? 0) === (int) $unitId;
        if ($hasPersistedDiscount && ! $isSameUnit) {
            $this->addError("lines.{$index}.product_unit_id", __('purchasing.legacy_discounted_line_immutable_unit'));

            return;
        }

        try {
            if ($this->currency_code !== $company->base_currency_code && trim((string) $this->exchange_rate) === '') {
                $this->addError('exchange_rate', __('purchasing.exchange_rate_required'));

                return;
            }
            $selected = app(PurchaseProductSelection::class)->select($this->lines[$index]['product_id'], (int) $unitId, $this->currency_code, $this->exchange_rate);
        } catch (\InvalidArgumentException|InvalidMoneyException|InvalidUnitConversionException $exception) {
            $this->addError('draft', __('purchasing.invalid_draft'));

            return;
        }

        if ($isSameUnit && $currentPublicId !== null) {
            $selected['public_id'] = $currentPublicId;
            if ($hasPersistedDiscount) {
                $selected['discount_type'] = $persisted['discount_type'];
                $selected['discount_value'] = $persisted['discount_value'];
            }
        } else {
            unset($this->lines[$index]['public_id']);
        }

        $this->lines[$index] = array_replace($this->lines[$index], $selected, ['lots' => []]);
        $this->recalculate();
    }

    public function customizeLots(int $index): void
    {
        $company = $this->authorizeForm();
        $line = $this->lines[$index] ?? [];
        $product = Product::where('company_id', $company->id)->findOrFail($line['product_id'] ?? null);
        abort_unless($product->track_expiry, 422);

        $this->expandedLotCustomization[$index] = true;
        if (empty($this->lines[$index]['lots'])) {
            $this->lines[$index]['lots'][] = [
                'product_unit_id' => $line['product_unit_id'],
                'lot_number' => '',
                'expiry_date' => null,
                'quantity' => SalesMoneyFormatter::formatQuantity($line['quantity'] ?: '1'),
            ];
        }
    }

    public function resetToAutoLot(int $index): void
    {
        $this->authorizeForm();
        $this->expandedLotCustomization[$index] = false;
        $this->lines[$index]['lots'] = [];
    }

    public function addLot(int $index): void
    {
        $company = $this->authorizeForm();
        $line = $this->lines[$index] ?? [];
        $product = Product::where('company_id', $company->id)->findOrFail($line['product_id'] ?? null);
        abort_unless($product->track_expiry, 422);
        $this->expandedLotCustomization[$index] = true;
        $this->lines[$index]['lots'][] = [
            'product_unit_id' => $line['product_unit_id'],
            'lot_number' => '',
            'expiry_date' => null,
            'quantity' => SalesMoneyFormatter::formatQuantity($line['quantity'] ?: '1'),
        ];
    }

    public function removeLot(int $index, int $lot): void
    {
        $this->authorizeForm();
        unset($this->lines[$index]['lots'][$lot]);
        $this->lines[$index]['lots'] = array_values($this->lines[$index]['lots']);
    }

    #[Locked]
    public bool $documentTotalsValid = false;

    public function recalculate(): void
    {
        $this->documentTotalsValid = false;
        if ($this->showPreview) {
            $this->cancelPreview();
        }
        $company = $this->authorizeForm();
        $this->resetErrorBag('calculation');
        $this->resetErrorBag('exchange_rate');
        $this->resetErrorBag('discount_value');
        $results = [];
        try {
            if ($this->currency_code !== $company->base_currency_code && trim((string) $this->exchange_rate) === '') {
                $this->totals = [];
                foreach ($this->lines as &$line) {
                    unset($line['preview_total']);
                }
                $this->previewGrandTotal = '0';
                $this->documentTotalsValid = false;
                $this->addError('exchange_rate', __('purchasing.exchange_rate_required'));

                return;
            }

            foreach ($this->lines as $index => $line) {
                if (! isset($line['quantity']) || ! is_string($line['quantity']) || trim($line['quantity']) === ''
                    || ! isset($line['unit_cost']) || ! is_string($line['unit_cost']) || trim($line['unit_cost']) === '') {
                    $this->totals = [];
                    foreach ($this->lines as &$l) {
                        unset($l['preview_total']);
                    }
                    $this->previewGrandTotal = '0';
                    $this->documentTotalsValid = false;

                    return;
                }
            }

            $discountScope = $this->discount_scope ?? 'document';
            $minor = app(CurrencyMinorUnitsResolver::class)->resolve($this->currency_code);
            $allocationResult = null;

            if ($discountScope === 'document') {
                $candidates = [];
                foreach ($this->lines as $index => $line) {
                    $qty = Quantity::of($line['quantity'])->toBigDecimal();
                    $price = MoneyAmount::from($line['unit_cost'])->getAmount();
                    $subtotal = $qty->multipliedBy($price)->toScale($minor, RoundingMode::HALF_UP);
                    $tax = ! empty($line['tax_rate_id']) ? TaxRate::where('company_id', $this->pageCompanyId)->where('active', true)->find($line['tax_rate_id']) : null;

                    $candidates[] = new DocumentDiscountLineCandidate(
                        lineNumber: $index + 1,
                        unitCost: $price,
                        quantity: $qty,
                        subtotal: $subtotal,
                        taxInclusive: $tax?->calculation === TaxRate::CALC_INCLUSIVE,
                        taxRate: $tax?->rate !== null ? BigDecimal::of((string) $tax->rate) : null,
                    );
                }

                $headerDiscountType = in_array($this->discount_type, [null, '', 'none'], true) ? DocumentDiscountIntent::TYPE_NONE : $this->discount_type;
                $headerDiscountValue = trim((string) $this->discount_value) !== '' ? $this->discount_value : '0';

                try {
                    $intent = new DocumentDiscountIntent(
                        discountType: $headerDiscountType,
                        discountValue: $headerDiscountValue,
                        discountScope: 'document',
                        discountBasis: DocumentDiscountIntent::BASIS_SUBTOTAL,
                        version: DocumentDiscountIntent::VERSION_CANONICAL,
                    );
                    $allocationResult = app(DocumentDiscountAllocator::class)->allocate($intent, $candidates, $this->currency_code);
                } catch (\InvalidArgumentException $e) {
                    $this->totals = [];
                    foreach ($this->lines as &$l) {
                        unset($l['preview_total']);
                    }
                    $this->previewGrandTotal = '0';
                    $this->documentTotalsValid = false;
                    $this->addError('discount_value', $e->getMessage());

                    return;
                }
            }

            foreach ($this->lines as $index => $line) {
                $tax = ! empty($line['tax_rate_id']) ? TaxRate::where('company_id', $this->pageCompanyId)->where('active', true)->find($line['tax_rate_id']) : null;
                $qty = $line['quantity'];
                $cost = $line['unit_cost'];

                if ($discountScope === 'document' && $allocationResult !== null) {
                    $allocatedDiscount = $allocationResult->forLine($index + 1);
                    $result = app(PurchaseCalculator::class)->line(
                        $qty,
                        $cost,
                        'fixed',
                        (string) $allocatedDiscount,
                        $tax?->rate,
                        $tax?->calculation === TaxRate::CALC_INCLUSIVE,
                        $this->currency_code,
                        $this->exchange_rate
                    );
                } else {
                    $lineDiscountType = $line['discount_type'] ?? 'none';
                    if ($lineDiscountType !== 'none') {
                        if (! isset($line['discount_value']) || ! is_string($line['discount_value']) || trim($line['discount_value']) === '') {
                            $this->totals = [];
                            foreach ($this->lines as &$l) {
                                unset($l['preview_total']);
                            }
                            $this->previewGrandTotal = '0';
                            $this->documentTotalsValid = false;

                            return;
                        }
                        try {
                            BigDecimal::of($line['discount_value']);
                        } catch (\Throwable) {
                            $this->totals = [];
                            foreach ($this->lines as &$l) {
                                unset($l['preview_total']);
                            }
                            $this->previewGrandTotal = '0';
                            $this->documentTotalsValid = false;

                            return;
                        }
                    }

                    $disc = isset($line['discount_value']) && is_string($line['discount_value']) && trim($line['discount_value']) !== '' ? $line['discount_value'] : '0';
                    $result = app(PurchaseCalculator::class)->line($qty, $cost, $line['discount_type'] ?? null,
                        $disc, $tax?->rate, $tax?->calculation === TaxRate::CALC_INCLUSIVE, $this->currency_code, $this->exchange_rate);
                }

                $this->lines[$index]['preview_total'] = (string) $result->total;
                $results[] = $result;
            }
            $total = app(SalesDocumentTotalsCalculator::class)->calculate($results);
            $oldGrandTotal = $this->previewGrandTotal;
            $this->totals = ['subtotal' => (string) $total->subtotalCurrency, 'discount' => (string) $total->discountTotalCurrency,
                'tax' => (string) $total->taxTotalCurrency, 'total' => (string) $total->grandTotalCurrency];
            $this->previewGrandTotal = (string) $total->grandTotalCurrency->toScale($minor, RoundingMode::HALF_UP);
            $this->resetErrorBag('currency');
            $this->documentTotalsValid = true;

            if (($this->settlement['mode'] ?? 'unpaid') === 'full') {
                $paymentCurrency = $this->settlementPaymentCurrency();
                if ($paymentCurrency === $this->currency_code) {
                    $this->settlement['amount'] = $this->previewGrandTotal;
                } else {
                    $this->settlement['purchase_amount'] = $this->previewGrandTotal;
                }
            }
        } catch (\InvalidArgumentException|MathException|ModelNotFoundException|InvalidMoneyException|InvalidQuantityException|InvalidUnitConversionException $exception) {
            $this->totals = [];
            foreach ($this->lines as &$line) {
                unset($line['preview_total']);
            }
            $this->addError('calculation', __('purchasing.invalid_line_amounts'));
            if ($this->exchange_rate === '') {
                $this->addError('exchange_rate', __('purchasing.exchange_rate_required'));
            }
        }
    }

    public function save(bool $andPost = false): mixed
    {
        $company = $this->authorizeForm();
        if ($andPost) {
            $this->authorizePurchasing('purchasing.purchase.post');
        }

        foreach ($this->lines as $idx => $line) {
            $humanIndex = $idx + 1;
            if (! isset($line['quantity']) || ! is_string($line['quantity']) || trim($line['quantity']) === '') {
                $this->addError("lines.{$idx}.quantity", __('validation.required', ['attribute' => __('purchasing.line_attribute_quantity', ['index' => $humanIndex])]));

                return null;
            }
            if (! isset($line['unit_cost']) || ! is_string($line['unit_cost']) || trim($line['unit_cost']) === '') {
                $this->addError("lines.{$idx}.unit_cost", __('validation.required', ['attribute' => __('purchasing.line_attribute_cost', ['index' => $humanIndex])]));

                return null;
            }
            $lineDiscountType = $line['discount_type'] ?? 'none';
            if ($lineDiscountType !== 'none') {
                if (! isset($line['discount_value']) || ! is_string($line['discount_value']) || trim($line['discount_value']) === '') {
                    $this->addError("lines.{$idx}.discount_value", __('validation.required', ['attribute' => __('purchasing.line_attribute_discount', ['index' => $humanIndex])]));

                    return null;
                }
                try {
                    BigDecimal::of($line['discount_value']);
                } catch (\Throwable) {
                    $this->addError("lines.{$idx}.discount_value", __('validation.required', ['attribute' => __('purchasing.line_attribute_discount', ['index' => $humanIndex])]));

                    return null;
                }
            } else {
                $this->lines[$idx]['discount_value'] = '0';
            }
        }

        $this->resetErrorBag('discount_value');
        $data = $this->draftData();
        try {
            $purchase = $this->publicId === null
                ? ($this->savedDraftPublicId !== null
                    ? app(UpdatePurchaseDraftAction::class)->execute(Purchase::where('company_id', $company->id)->where('public_id', $this->savedDraftPublicId)->firstOrFail(), auth()->user(), $data)
                    : ($this->aiFormToken !== null && $this->aiFormKey !== null
                        ? DB::transaction(function () use ($company): Purchase {
                            app(AiDraftProvenance::class)->source($company, auth()->user(), $this->aiFormToken, session()->getId(), $this->aiFormKey, 'purchase', $this->aiFormRevision);

                            return app(PurchaseDraftCommands::class)->create($company, auth()->user(), $this->aiEditablePayload(), $this->aiFormKey, $this->aiDraftSourceId);
                        })
                        : app(CreatePurchaseDraftAction::class)->execute($company, auth()->user(), $data)))
                : app(UpdatePurchaseDraftAction::class)->execute(Purchase::where('company_id', $company->id)->where('public_id', $this->publicId)->firstOrFail(), auth()->user(), $data);
        } catch (\InvalidArgumentException|MathException|InvalidMoneyException|InvalidQuantityException|InvalidUnitConversionException|ValidationException $exception) {
            if ($exception instanceof ValidationException) {
                foreach ($exception->errors() as $key => $messages) {
                    foreach ($messages as $msg) {
                        $this->addError($key, $msg);
                    }
                }
            } else {
                $this->addError('draft', __('purchasing.invalid_draft'));
            }

            return null;
        }

        $this->savedDraftPublicId = $purchase->public_id;
        $this->savedDraftPayloadHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        $this->persistedLineDiscounts = [];
        foreach ($purchase->lines as $pLine) {
            if (! empty($pLine->public_id)) {
                $this->persistedLineDiscounts[$pLine->public_id] = [
                    'discount_type' => $pLine->discount_type ?? 'none',
                    'discount_value' => (string) ($pLine->discount_value ?? '0'),
                ];
            }
        }

        if (! $andPost) {
            $this->discardAiInput();
            session()->flash('success', __('purchasing.draft_saved'));

            return redirect()->route('purchases.show', $purchase->public_id);
        }

        $this->previewError = null;
        $this->recalculate();
        $choice = $this->settlementChoice();

        try {
            $user = auth()->user();
            $this->settlementPreview = $choice['mode'] !== 'unpaid';
            $preview = app(ImmediatePurchaseCommands::class)->preview($purchase, $user, $choice);
            $this->previewData = $preview;
            $this->confirmationToken = $preview['confirmation_token'] ?? null;
            $this->executeKey = (string) Str::uuid();
            $this->previewDigest = $this->settlementDigest();
            $this->showPreview = true;
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException || $e instanceof NoActiveCompanyException || ($e instanceof HttpException && $e->getStatusCode() === 403)) {
                throw $e;
            }
            $this->showPreview = false;
            $this->previewError = $this->resolveUserErrorMessage($e);
        }

        return null;
    }

    public function confirmPost(): mixed
    {
        $company = $this->authorizeForm();
        $this->authorizePurchasing('purchasing.purchase.post');
        $user = auth()->user();

        $confirmation = $this->completedConfirmation ?? [
            'public_id' => $this->savedDraftPublicId,
            'token' => $this->confirmationToken,
            'key' => $this->executeKey,
            'digest' => $this->previewDigest,
            'paid' => $this->settlementPreview,
        ];

        if (($this->completedConfirmation === null && ! $this->showPreview) || ! $confirmation['token'] || ! $confirmation['key'] || ! $confirmation['public_id']) {
            $this->previewError = __('commands.preview_expired');

            return null;
        }

        $digestMatches = $confirmation['digest'] !== null && (
            $confirmation['digest'] === $this->settlementDigest()
            || ($this->legacySettlementDigest() !== null && $confirmation['digest'] === $this->legacySettlementDigest())
        );

        if (! $digestMatches) {
            $this->cancelPreview();
            $this->previewError = __('commands.form_changed_repreview_required');

            return null;
        }

        if (! empty($this->previewData['blockers']) || ($this->previewData['can_execute'] ?? true) === false) {
            $this->previewError = __('commands.cannot_confirm_blockers');

            return null;
        }

        $purchase = Purchase::where('company_id', $company->id)->where('public_id', $confirmation['public_id'])->firstOrFail();

        try {
            $paid = $confirmation['paid'];
            $result = app(ImmediatePurchaseCommands::class)->execute($purchase, $user, $confirmation['token'], $confirmation['key']);
            $this->completedConfirmation = $confirmation;
            $this->cancelPreview();
            session()->flash('success', __($paid ? 'purchase_settlement.posted_with_payment' : 'purchasing.purchase_posted'));

            return redirect()->route('purchases.show', $result['purchase']->public_id);
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException || $e instanceof NoActiveCompanyException || ($e instanceof HttpException && $e->getStatusCode() === 403)) {
                throw $e;
            }
            $this->cancelPreview();
            $this->previewError = $this->resolveUserErrorMessage($e);

            return null;
        }
    }

    public function cancelPreview(): void
    {
        $this->showPreview = false;
        $this->previewData = null;
        $this->confirmationToken = null;
        $this->executeKey = null;
        $this->previewDigest = null;
        $this->settlementPreview = false;
    }

    public function settlementPaymentCurrency(): string
    {
        $company = app(CompanyContext::class)->company();
        $accountId = $this->settlement['money_account_id'] ?? null;
        if (! empty($accountId)) {
            $account = app(EligibleMoneyAccounts::class)->query($this->pageCompanyId)->find($accountId);
            if ($account !== null && ! empty($account->currency_code)) {
                return $account->currency_code;
            }
        }

        if (($this->settlement['payment_method'] ?? null) === 'check' && ! empty($this->settlement['currency_code'])) {
            return $this->settlement['currency_code'];
        }

        return $this->currency_code ?: $company->base_currency_code;
    }

    private function syncSettlementDefaults(?string $changedProperty = null): void
    {
        $user = auth()->user();
        if (! $user || ! $user->hasPermissionTo('purchasing.purchase.post') || ! $user->hasPermissionTo('money.vendor_payment.create')) {
            return;
        }
        $company = app(CompanyContext::class)->company();
        $initial = $this->previousSettlementCurrency === '';
        if ($initial) {
            $this->settlement['payment_date'] = now($company->timezone)->format('Y-m-d');
            $this->settlement['due_date'] = $this->settlement['payment_date'];
        }
        $preferredCurrency = $this->currency_code ?: $company->base_currency_code;
        $method = $this->settlement['payment_method'] ?? 'cash';
        $accountType = in_array($method, ['bank_transfer', 'check'], true) ? 'bank' : 'cash';
        $automatic = (int) ($this->settlement['money_account_id'] ?? 0) === ($this->defaultSettlementAccountId ?? 0);
        if ($initial || $changedProperty === 'settlement.payment_method' || ($changedProperty === 'currency_code' && $automatic)) {
            $account = app(EligibleMoneyAccounts::class)->query($this->pageCompanyId, $accountType)
                ->reorder()->orderByRaw('currency_code = ? desc', [$preferredCurrency])
                ->orderByRaw('currency_code = ? desc', [$company->base_currency_code])
                ->orderBy('sort_order')->orderBy('id')->first();
            $this->settlement['money_account_id'] = $account === null ? '' : (string) $account->id;
            $this->defaultSettlementAccountId = $account?->id;
        } elseif ($changedProperty === 'settlement.money_account_id') {
            $this->defaultSettlementAccountId = null;
        }
        $currency = $this->settlementPaymentCurrency();
        if ($method === 'check') {
            $this->settlement['currency_code'] = $currency;
        }
        if ($changedProperty === 'currency_code') {
            $this->settlement['purchase_amount'] = '';
            $this->settlement['payment_currency_amount'] = '';
        }
        if ($changedProperty === 'settlement.mode' && ($this->settlement['mode'] ?? null) === 'partial' && $this->settlement['amount'] === $this->previewGrandTotal) {
            $this->settlement['amount'] = '';
        }
        if ($currency === $company->base_currency_code) {
            $this->settlement['exchange_rate'] = '1.0000000000';
        } elseif ($currency !== $this->previousSettlementCurrency) {
            $this->settlement['exchange_rate'] = '';
        }
        if (! $initial && $currency !== $this->previousSettlementCurrency) {
            // Never reinterpret a previously entered amount in another currency.
            $this->settlement['amount'] = '';
            $this->settlement['purchase_amount'] = '';
            $this->settlement['payment_currency_amount'] = '';
        }
        if ($currency === $this->currency_code) {
            $this->settlement['payment_currency_amount'] = '';
            if (($this->settlement['mode'] ?? 'unpaid') === 'full' && $this->settlement['amount'] === '' && ($initial || in_array($changedProperty, ['currency_code', 'settlement.mode', 'settlement.payment_method'], true) || ($changedProperty === 'settlement.money_account_id' && $this->settlement['money_account_id'] !== ''))) {
                $this->settlement['amount'] = $this->previewGrandTotal;
            }
        }
        $this->previousSettlementCurrency = $currency;
    }

    public function settlementDigest(): string
    {
        return hash('sha256', $this->computePayloadDigest().'|'.json_encode($this->settlement, JSON_THROW_ON_ERROR));
    }

    public function computePayloadDigest(): string
    {
        $normalizedLines = [];
        foreach ($this->lines as $line) {
            $discountType = $line['discount_type'] ?? 'none';
            if ($discountType === 'percentage') {
                $discountType = 'percent';
            }
            $normalizedLines[] = [
                'product_id' => ! empty($line['product_id']) ? (int) $line['product_id'] : null,
                'product_unit_id' => ! empty($line['product_unit_id']) ? (int) $line['product_unit_id'] : null,
                'item_description' => trim((string) ($line['item_description'] ?? '')),
                'quantity' => trim((string) ($line['quantity'] ?? '')),
                'unit_cost' => trim((string) ($line['unit_cost'] ?? '')),
                'discount_type' => $discountType !== 'none' ? $discountType : null,
                'discount_value' => trim((string) ($line['discount_value'] ?? '0')),
                'tax_rate_id' => ! empty($line['tax_rate_id']) ? (int) $line['tax_rate_id'] : null,
                'lots' => $line['lots'] ?? [],
            ];
        }

        $headerDiscountType = $this->discount_type ?? 'none';
        if ($headerDiscountType === 'percentage') {
            $headerDiscountType = 'percent';
        }
        $headerDiscountValue = trim((string) ($this->discount_value ?? '0'));
        $hasHeaderDiscount = $headerDiscountType !== 'none' && $headerDiscountValue !== '' && ! BigDecimal::of($headerDiscountValue)->isZero();

        $normalized = [
            'vendor_id' => (int) $this->vendor_id,
            'warehouse_id' => $this->warehouse_id ? (int) $this->warehouse_id : null,
            'vendor_invoice_number' => $this->vendor_invoice_number ? trim($this->vendor_invoice_number) : null,
            'currency_code' => strtoupper(trim($this->currency_code)),
            'exchange_rate' => trim($this->exchange_rate),
            'purchase_date' => trim($this->purchase_date),
            'due_date' => $this->due_date ? trim($this->due_date) : null,
            'notes' => $this->notes !== null ? trim($this->notes) : null,
            'document_locale' => $this->document_locale,
            'discount_type' => $hasHeaderDiscount ? $headerDiscountType : null,
            'discount_value' => $hasHeaderDiscount ? $headerDiscountValue : null,
            'lines' => $normalizedLines,
        ];

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function computeLegacyPayloadDigest(): ?string
    {
        $headerDiscountType = $this->discount_type ?? 'none';
        if ($headerDiscountType === 'percentage') {
            $headerDiscountType = 'percent';
        }
        $headerDiscountValue = trim((string) ($this->discount_value ?? '0'));
        $hasHeaderDiscount = $headerDiscountType !== 'none' && $headerDiscountValue !== '' && ! BigDecimal::of($headerDiscountValue)->isZero();
        if ($hasHeaderDiscount) {
            return null;
        }

        $normalizedLines = [];
        foreach ($this->lines as $line) {
            $discountType = $line['discount_type'] ?? 'none';
            if ($discountType === 'percentage') {
                $discountType = 'percent';
            }
            $normalizedLines[] = [
                'product_id' => ! empty($line['product_id']) ? (int) $line['product_id'] : null,
                'product_unit_id' => ! empty($line['product_unit_id']) ? (int) $line['product_unit_id'] : null,
                'item_description' => trim((string) ($line['item_description'] ?? '')),
                'quantity' => trim((string) ($line['quantity'] ?? '')),
                'unit_cost' => trim((string) ($line['unit_cost'] ?? '')),
                'discount_type' => $discountType !== 'none' ? $discountType : null,
                'discount_value' => trim((string) ($line['discount_value'] ?? '0')),
                'tax_rate_id' => ! empty($line['tax_rate_id']) ? (int) $line['tax_rate_id'] : null,
                'lots' => $line['lots'] ?? [],
            ];
        }

        $normalized = [
            'vendor_id' => (int) $this->vendor_id,
            'warehouse_id' => $this->warehouse_id ? (int) $this->warehouse_id : null,
            'vendor_invoice_number' => $this->vendor_invoice_number ? trim($this->vendor_invoice_number) : null,
            'currency_code' => strtoupper(trim($this->currency_code)),
            'exchange_rate' => trim($this->exchange_rate),
            'purchase_date' => trim($this->purchase_date),
            'due_date' => $this->due_date ? trim($this->due_date) : null,
            'notes' => $this->notes !== null ? trim($this->notes) : null,
            'document_locale' => $this->document_locale,
            'lines' => $normalizedLines,
        ];

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function legacySettlementDigest(): ?string
    {
        $legacyPayload = $this->computeLegacyPayloadDigest();
        if ($legacyPayload === null) {
            return null;
        }

        return hash('sha256', $legacyPayload.'|'.json_encode($this->settlement, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function settlementChoice(): array
    {
        $this->validate(['settlement.mode' => ['required', 'in:unpaid,full,partial']]);
        if ($this->settlement['mode'] === 'unpaid') {
            return ['mode' => 'unpaid'];
        }

        $company = app(CompanyContext::class)->company();
        $paymentCurrency = $this->settlementPaymentCurrency();
        if ($paymentCurrency === $company->base_currency_code) {
            $this->settlement['exchange_rate'] = '1.0000000000';
        }

        $check = ($this->settlement['payment_method'] ?? null) === 'check';
        $rules = [
            'settlement.payment_method' => ['required', 'in:cash,bank_transfer,check'],
            'settlement.money_account_id' => ['required', 'integer', 'min:1'],
            'settlement.payment_date' => ['required', 'string', 'date'],
            'settlement.amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,6})?$/'],
            'settlement.exchange_rate' => ['required', 'string', 'regex:/^\d+(\.\d{1,10})?$/'],
        ];

        $isCrossCurrency = $paymentCurrency !== $this->currency_code;
        if (! $isCrossCurrency && $this->settlement['mode'] === 'full') {
            $this->settlement['amount'] = $this->previewGrandTotal;
        } elseif ($isCrossCurrency && $this->settlement['mode'] === 'full') {
            $this->settlement['purchase_amount'] = $this->previewGrandTotal;
        }

        if ($this->settlement['mode'] === 'partial' && ! $isCrossCurrency) {
            $this->settlement['purchase_amount'] = $this->settlement['amount'];
        }
        if ($this->settlement['mode'] === 'partial') {
            $rules['settlement.purchase_amount'] = ['required', 'string', 'regex:/^\d+(\.\d{1,6})?$/'];
        }

        if ($isCrossCurrency) {
            $rules['settlement.payment_currency_amount'] = ['required', 'string', 'regex:/^\d+(\.\d{1,6})?$/'];
        } else {
            $this->settlement['payment_currency_amount'] = '';
        }

        if ($check) {
            $rules += [
                'settlement.currency_code' => ['required', 'string', 'size:3'],
                'settlement.check_number' => ['required', 'string', 'max:100'],
                'settlement.bank_name' => ['required', 'string', 'max:200'],
                'settlement.drawer' => ['nullable', 'string', 'max:200'],
                'settlement.due_date' => ['required', 'date'],
            ];
        }

        $this->validate($rules, [], collect(array_keys($rules))->mapWithKeys(fn ($key) => [$key => __('purchase_settlement.'.str_replace('settlement.', '', $key))])->all());

        $payMinor = app(CurrencyMinorUnitsResolver::class)->resolve($paymentCurrency);
        $amountStr = (string) ($this->settlement['amount'] ?? '');
        if ($amountStr !== '') {
            $parts = explode('.', $amountStr);
            if (isset($parts[1]) && strlen($parts[1]) > $payMinor) {
                throw ValidationException::withMessages(['settlement.amount' => __('purchase_settlement.amount')]);
            }
        }
        if ($isCrossCurrency) {
            $purchaseMinor = app(CurrencyMinorUnitsResolver::class)->resolve($this->currency_code);
            $purchaseAmountStr = (string) ($this->settlement['purchase_amount'] ?? '');
            if ($purchaseAmountStr !== '') {
                $parts = explode('.', $purchaseAmountStr);
                if (isset($parts[1]) && strlen($parts[1]) > $purchaseMinor) {
                    throw ValidationException::withMessages(['settlement.purchase_amount' => __('purchase_settlement.amount')]);
                }
            }
            $paymentCurrencyAmountStr = (string) ($this->settlement['payment_currency_amount'] ?? '');
            if ($paymentCurrencyAmountStr !== '') {
                $parts = explode('.', $paymentCurrencyAmountStr);
                if (isset($parts[1]) && strlen($parts[1]) > $payMinor) {
                    throw ValidationException::withMessages(['settlement.payment_currency_amount' => __('purchase_settlement.amount')]);
                }
            }
        }

        if ($this->documentTotalsValid && $this->previewGrandTotal !== null) {
            $grandTotal = BigDecimal::of($this->previewGrandTotal);
            if (! $isCrossCurrency) {
                $payAmount = BigDecimal::of($this->settlement['amount'] !== '' ? $this->settlement['amount'] : '0');
                if ($this->settlement['mode'] === 'full' && ! $payAmount->isEqualTo($grandTotal)) {
                    throw ValidationException::withMessages(['settlement.amount' => __('purchase_settlement.amount')]);
                } elseif ($this->settlement['mode'] === 'partial' && ($payAmount->isZero() || $payAmount->isGreaterThanOrEqualTo($grandTotal))) {
                    throw ValidationException::withMessages(['settlement.amount' => __('purchase_settlement.amount')]);
                }
            } else {
                $purchaseAmount = BigDecimal::of(($this->settlement['purchase_amount'] ?? '') !== '' ? $this->settlement['purchase_amount'] : '0');
                if ($this->settlement['mode'] === 'full' && ! $purchaseAmount->isEqualTo($grandTotal)) {
                    throw ValidationException::withMessages(['settlement.purchase_amount' => __('purchase_settlement.amount')]);
                } elseif ($this->settlement['mode'] === 'partial' && ($purchaseAmount->isZero() || $purchaseAmount->isGreaterThanOrEqualTo($grandTotal))) {
                    throw ValidationException::withMessages(['settlement.purchase_amount' => __('purchase_settlement.amount')]);
                }
            }
        }

        $choice = array_intersect_key($this->settlement, array_flip(['mode', 'payment_method', 'amount', 'exchange_rate']));
        $choice['money_account_id'] = (int) $this->settlement['money_account_id'];
        $choice['payment_date'] = ($this->settlement['payment_date'] ?? '') ?: now($company->timezone)->format('Y-m-d');
        if ($this->settlement['mode'] === 'partial') {
            $choice['purchase_amount'] = $this->settlement['purchase_amount'];
        }
        if ($isCrossCurrency && ($this->settlement['payment_currency_amount'] ?? '') !== '') {
            $choice['payment_currency_amount'] = $this->settlement['payment_currency_amount'];
        }
        if ($check) {
            $choice += array_intersect_key($this->settlement, array_flip(['currency_code', 'check_number', 'bank_name', 'drawer', 'due_date']));
        }

        return $choice;
    }

    protected function resolveUserErrorMessage(\Throwable $e): string
    {
        if ($e instanceof AuthorizationException
            || $e instanceof NoActiveCompanyException
            || ($e instanceof HttpException && $e->getStatusCode() === 403)) {
            throw $e;
        }

        report($e);

        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'outgoing check requires an active bank account')
            || str_contains($msg, 'choose an active company payment account')
            || str_contains($msg, 'choose an active matching company payment account')) {
            $this->addError('settlement.money_account_id', __('purchase_settlement.no_accounts'));
        }
        if (str_contains($msg, 'check currency')) {
            $this->addError('settlement.currency_code', __('purchase_settlement.currency_code'));
        }
        if (str_contains($msg, 'check due date')) {
            $this->addError('settlement.due_date', __('purchase_settlement.due_date'));
        }
        if (str_contains($msg, 'cross-currency') || str_contains($msg, 'payment_currency_amount')) {
            $this->addError('settlement.payment_currency_amount', __('purchase_settlement.payment_currency_amount'));
        }
        if (str_contains($msg, 'reviewed payment and allocation amounts')
            || str_contains($msg, 'payment amount must be strictly positive')
            || (str_contains($msg, 'allocated amount') && str_contains($msg, 'exceeds'))) {
            $this->addError('settlement.amount', __('purchase_settlement.amount'));
        }
        if (str_contains($msg, 'exchange_rate') || str_contains($msg, 'exchange rate')) {
            $this->addError('settlement.exchange_rate', __('purchase_settlement.exchange_rate'));
        }

        if ($e instanceof \InvalidArgumentException) {
            if (str_contains($msg, 'token') || str_contains($msg, 'expired') || str_contains($msg, 'stale')) {
                return __('commands.preview_expired');
            }
            if (str_contains($msg, 'warehouse')) {
                return __('commands.code_warehouse_inactive');
            }
            if (str_contains($msg, 'vendor')) {
                return __('commands.code_customer_inactive');
            }
            if (str_contains($msg, 'post_integrity_failed') || str_contains($msg, 'integrity')) {
                return __('purchasing.post_integrity_failed');
            }
            if (str_contains($msg, 'positive_post_total_required')) {
                return __('purchasing.positive_post_total_required');
            }
            if (str_contains($msg, 'lot') || str_contains($msg, 'expiry')) {
                return __('purchasing.lots_must_be_complete');
            }

            return __('commands.code_validation_failed');
        }

        if ($e instanceof InvalidUnitConversionException || $e instanceof InvalidQuantityException) {
            return __('mobile.invalid_unit');
        }

        if ($e instanceof ConflictHttpException
            || $e->getCode() === 409
            || ($e instanceof HttpException && $e->getStatusCode() === 409)) {
            if (str_contains($msg, 'expired')) {
                return __('commands.preview_expired');
            }
            if (str_contains($msg, 'changed') || str_contains($msg, 'stale')) {
                return __('commands.form_changed_repreview_required');
            }

            return __('commands.conflict_error');
        }

        return __('commands.generic_error');
    }

    /** @return array<string, mixed> */
    private function draftData(): array
    {
        $company = app(CompanyContext::class)->company();
        if ($this->currency_code === $company->base_currency_code) {
            $this->exchange_rate = '1.0000000000';
        }

        $lines = $this->lines;
        foreach ($lines as &$line) {
            if (! empty($line['public_id']) && isset($this->persistedLineDiscounts[$line['public_id']])) {
                $line['discount_type'] = $this->persistedLineDiscounts[$line['public_id']]['discount_type'];
                $line['discount_value'] = $this->persistedLineDiscounts[$line['public_id']]['discount_value'];
            } else {
                $line['discount_type'] = 'none';
                $line['discount_value'] = '0';
            }
        }
        unset($line);
        $selectedProductIds = collect($lines)->pluck('product_id')->filter()->unique()->values()->all();
        $selectedProducts = ! empty($selectedProductIds)
            ? Product::where('company_id', $company->id)->whereIn('id', $selectedProductIds)->get()->keyBy('id')
            : collect();

        foreach ($lines as $idx => &$line) {
            $pid = ! empty($line['product_id']) ? (int) $line['product_id'] : null;
            $product = $pid ? $selectedProducts->get($pid) : null;
            if ($product && $product->track_expiry) {
                if (empty($line['lots']) && ! ($this->expandedLotCustomization[$idx] ?? false)) {
                    $line['lots'] = [[
                        'product_unit_id' => $line['product_unit_id'] ?? null,
                        'lot_number' => null,
                        'expiry_date' => null,
                        'quantity' => (string) ($line['quantity'] ?? ''),
                    ]];
                }
            } elseif ($product !== null) {
                $line['lots'] = [];
            }
        }
        unset($line);

        $data = [];
        foreach (['vendor_id', 'vendor_invoice_number', 'warehouse_id', 'purchase_date', 'due_date', 'currency_code', 'exchange_rate', 'document_locale', 'notes'] as $field) {
            $data[$field] = $this->{$field};
        }
        $data['discount_type'] = $this->discount_type;
        $data['discount_value'] = (string) ($this->discount_value !== '' ? $this->discount_value : '0');
        $data['discount_scope'] = $this->discount_scope ?? 'document';
        $data['discount_basis'] = 'subtotal';
        $data['discount_calculation_version'] = $this->discount_calculation_version ?? 1;
        $data['lines'] = $lines;

        return $data;
    }

    public function render(): View
    {
        $company = $this->authorizeForm();
        $term = mb_substr(trim($this->productSearch), 0, 160);
        $products = Product::where('company_id', $company->id)->where('active', true)->where('product_type', Product::TYPE_STOCK)->where('track_stock', true);
        if ($term !== '') {
            $products->where(fn ($q) => $q->where('name_ar', 'like', "%{$term}%")->orWhere('name_en', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")->orWhereHas('barcodes', fn ($b) => $b->where('company_id', $company->id)->where('barcode', $term)));
        }
        $unitOptions = [];
        $expiryLines = [];
        $selectedProductIds = collect($this->lines)->pluck('product_id')->filter()->unique()->values()->all();
        $selectedProducts = Product::where('company_id', $company->id)->whereIn('id', $selectedProductIds)
            ->with(['productUnits' => fn ($units) => $units->where('company_id', $company->id)->where('active', true)
                ->whereHas('unit', fn ($unit) => $unit->where('company_id', $company->id)->where('active', true))->with('unit')])->get()->keyBy('id');
        abort_unless($selectedProducts->count() === count($selectedProductIds), 404);
        foreach ($this->lines as $index => $line) {
            if (! empty($line['product_id'])) {
                $product = $selectedProducts->get($line['product_id']);
                $expiryLines[$index] = $product->track_expiry;
                $unitOptions[$index] = $product->productUnits;
            }
        }
        $except = $this->publicId === null ? null : Purchase::where('company_id', $company->id)->where('public_id', $this->publicId)->value('id');

        $lastPriceHints = [];
        if ($this->vendor_id !== null && auth()->check() && auth()->user()->can('purchasing.cost.view')) {
            $productIds = collect($this->lines)
                ->pluck('product_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if (! empty($productIds)) {
                $hintsByProduct = app(VendorProductPriceHistoryQuery::class)
                    ->latestForProducts($this->vendor_id, $productIds, $company->id);

                foreach ($this->lines as $index => $line) {
                    $pid = (int) ($line['product_id'] ?? 0);
                    if ($pid > 0 && isset($hintsByProduct[$pid])) {
                        $item = $hintsByProduct[$pid];
                        $lastPriceHints[$index] = [
                            'unit_cost' => $item->unit_cost,
                            'currency_code' => $item->currency_code,
                            'unit_name' => $item->unit_name,
                            'purchase_date' => $item->purchase_date,
                            'purchase_number' => $item->purchase_number,
                        ];
                    }
                }
            }
        }

        $vTerm = mb_substr(trim($this->vendorSearch), 0, 100);
        $vendorsQuery = Vendor::where('company_id', $company->id)->where('status', 'active');
        if ($vTerm !== '') {
            $vendorsQuery->where(function ($q) use ($vTerm) {
                $q->where('name_ar', 'like', "%{$vTerm}%")
                    ->orWhere('name_en', 'like', "%{$vTerm}%")
                    ->orWhere('code', 'like', "%{$vTerm}%")
                    ->orWhere('phone', 'like', "%{$vTerm}%");
            });
        }
        $matchingVendors = $vendorsQuery->orderByRaw('COALESCE(NULLIF(name_ar, ""), name_en) ASC')->limit(50)->get();
        if ($this->vendor_id) {
            $selectedVendor = Vendor::where('company_id', $company->id)
                ->where('id', $this->vendor_id)
                ->where('status', 'active')
                ->first();
            $vendors = $selectedVendor !== null ? $matchingVendors->push($selectedVendor)->unique('id') : $matchingVendors;
        } else {
            $vendors = $matchingVendors;
        }

        $user = auth()->user();
        $canPost = $user && $user->hasPermissionTo('purchasing.purchase.post');
        $canPay = $canPost && $user->hasPermissionTo('money.vendor_payment.create');
        $canPayCheck = $canPay && $user->hasPermissionTo('money.check.outgoing.manage');

        $settlementAccounts = $canPay && ($this->settlement['mode'] ?? 'unpaid') !== 'unpaid'
            ? app(EligibleMoneyAccounts::class)->query(
                (int) $company->id,
                in_array($this->settlement['payment_method'] ?? null, ['bank_transfer', 'check'], true) ? 'bank' : 'cash'
            )->get()
            : collect();

        $matchingProducts = $products->orderByRaw('COALESCE(name_ar, name_en) ASC')->orderBy('id')->limit(50)->get(['id', 'name_ar', 'name_en', 'sku']);
        $allProducts = $matchingProducts->merge($selectedProducts->values())->unique('id');

        return view('livewire.pages.purchasing.purchase-form', [
            'baseCurrencyCode' => $company->base_currency_code,
            'products' => $allProducts,
            'selectedProducts' => $selectedProducts,
            'vendors' => $vendors,
            'warehouses' => Warehouse::where('company_id', $company->id)->where('active', true)->get(),
            'currencies' => $company->currencies()->where('enabled', true)->get(),
            'languages' => $company->languages()->where('enabled', true)->get(),
            'taxes' => TaxRate::where('company_id', $company->id)->where('active', true)->get(),
            'unitOptions' => $unitOptions,
            'expiryLines' => $expiryLines,
            'duplicateWarning' => app(DuplicateVendorInvoice::class)->exists($company->id, $this->vendor_id, $this->vendor_invoice_number, $except),
            'lastPriceHints' => $lastPriceHints,
            'canManageVendors' => auth()->user()->can('vendors.manage'),
            'canPost' => $canPost,
            'canPay' => $canPay,
            'canPayCheck' => $canPayCheck,
            'settlementAccounts' => $settlementAccounts,
            'previewData' => $this->previewData,
            'showPreview' => $this->showPreview,
            'previewError' => $this->previewError,
        ]);
    }
}
