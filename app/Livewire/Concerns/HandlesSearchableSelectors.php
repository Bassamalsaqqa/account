<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Services\Search\SearchableSelectorService;
use App\Support\Tenancy\CompanyContext;

trait HandlesSearchableSelectors
{
    /**
     * Search options for direct in-field comboboxes.
     * Callable directly from Alpine via $wire.searchSelector(type, query, selectedId, options).
     *
     * @param array<string, mixed> $options
     * @return array{
     *     items: list<array<string, mixed>>,
     *     has_more: bool,
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

        /** @var User $actor */
        $actor = auth()->user();
        /** @var Company $company */
        $company = $context->company();

        $clientGeneration = (int) ($options['generation'] ?? 0);
        $limit = isset($options['limit']) ? (int) $options['limit'] : SearchableSelectorService::DEFAULT_LIMIT;

        $service = app(SearchableSelectorService::class);
        $result = $service->search(
            company: $company,
            actor: $actor,
            type: $type,
            rawTerm: $query,
            selectedId: $selectedId,
            limit: $limit,
            options: $options
        );

        return array_merge($result, [
            'generation' => $clientGeneration,
            'company_id' => (int) $company->id,
        ]);
    }
}
