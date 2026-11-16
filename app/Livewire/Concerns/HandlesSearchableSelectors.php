<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Livewire\Pages\Inventory\OpeningStockForm;
use App\Livewire\Pages\Inventory\StockAdjustmentForm;
use App\Livewire\Pages\Inventory\StockMovementIndex;
use App\Livewire\Pages\Inventory\StockTransferForm;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\PaymentForm;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\Company;
use App\Models\User;
use App\Services\Search\SearchableSelectorService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;

trait HandlesSearchableSelectors
{
    /**
     * Allowed selector types for this component.
     * Derived server-side; client cannot query arbitrary types.
     *
     * @return list<string>
     */
    protected function allowedSearchableSelectorTypes(): array
    {
        return match (static::class) {
            InvoiceForm::class,
            QuotationForm::class => ['customer', 'product'],
            PurchaseForm::class => ['vendor', 'product'],
            PaymentForm::class => ['customer'],
            \App\Livewire\Pages\Purchasing\PaymentForm::class => ['vendor'],
            OpeningStockForm::class,
            StockAdjustmentForm::class,
            StockTransferForm::class,
            StockMovementIndex::class => ['product'],
            default => ['customer', 'vendor', 'product', 'warehouse'],
        };
    }

    /**
     * Scoped workflow context for permission checks and eligibility derivation.
     * Derived server-side; client cannot choose authority or context.
     */
    protected function searchableSelectorContext(): string
    {
        return match (static::class) {
            InvoiceForm::class => 'sales',
            QuotationForm::class => 'quotation',
            PurchaseForm::class => 'purchasing',
            PaymentForm::class => 'receipt',
            \App\Livewire\Pages\Purchasing\PaymentForm::class => 'payment',
            OpeningStockForm::class,
            StockAdjustmentForm::class,
            StockTransferForm::class,
            StockMovementIndex::class => 'inventory',
            default => 'sales',
        };
    }

    /**
     * Search options for direct in-field comboboxes.
     * Callable directly from Alpine via $wire.searchSelector(type, query, selectedId, options).
     * Public client options are sanitized: query, limit, cursor, and generation only.
     *
     * @param  array<string, mixed>  $options
     * @return array{
     *     items: list<array<string, mixed>>,
     *     has_more: bool,
     *     next_cursor: ?string,
     *     limit: int,
     *     total: int,
     *     generation: int,
     *     company_id: int
     * }
     */
    public function searchSelector(
        string $type,
        string $query = '',
        ?int $selectedId = null,
        array $options = []
    ): array {
        $context = app(CompanyContext::class);
        $companyId = property_exists($this, 'pageCompanyId') ? (int) $this->pageCompanyId : (int) $context->companyId();

        abort_unless(auth()->check() && $context->hasCompany() && (int) $context->companyId() === $companyId, 403);

        // Verify type is strictly allowed by this component
        $allowedTypes = $this->allowedSearchableSelectorTypes();
        if (! in_array($type, $allowedTypes, true)) {
            throw new AuthorizationException("Selector type [{$type}] is not allowed for this workflow.");
        }

        /** @var User $actor */
        $actor = auth()->user();
        /** @var Company $company */
        $company = $context->company();

        $clientGeneration = (int) ($options['generation'] ?? 0);
        $limit = isset($options['limit']) ? (int) $options['limit'] : SearchableSelectorService::DEFAULT_LIMIT;
        $cursor = isset($options['cursor']) && is_string($options['cursor']) ? $options['cursor'] : null;

        // Build server-controlled options ONLY. Ignore all client-supplied authority/context overrides.
        $serverOptions = [
            'context' => $this->searchableSelectorContext(),
            'cursor' => $cursor,
        ];

        $service = app(SearchableSelectorService::class);
        $result = $service->search(
            company: $company,
            actor: $actor,
            type: $type,
            rawTerm: $query,
            selectedId: $selectedId,
            limit: $limit,
            options: $serverOptions
        );

        return array_merge($result, [
            'generation' => $clientGeneration,
            'company_id' => (int) $company->id,
        ]);
    }
}
