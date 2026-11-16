<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SearchableSelectorService
{
    public const MAX_TERM_LENGTH = 160;

    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 50;

    /**
     * Map of selector types to capability allowlists.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const CAPABILITIES = [
        'customer' => [
            'sales' => ['sales.invoice.view', 'sales.invoice.create', 'sales.invoice.edit_draft'],
            'quotation' => ['sales.quote.view', 'sales.quote.create', 'sales.quote.edit'],
            'returns' => ['sales.return.view', 'sales.return.create'],
            'receipt' => ['money.receipt.view', 'money.receipt.create'],
            'statement' => ['sales.statement.view', 'customers.statement.view'],
            'management' => ['customers.view', 'customers.manage'],
            'reports' => ['reports.sales.view', 'reports.financial.view'],
        ],
        'vendor' => [
            'purchasing' => ['purchasing.purchase.view', 'purchasing.purchase.create', 'purchasing.purchase.edit_draft', 'purchasing.view'],
            'returns' => ['purchasing.return.view', 'purchasing.return.manage'],
            'payment' => ['money.vendor_payment.view', 'money.vendor_payment.create', 'purchasing.payment.create'],
            'statement' => ['vendors.statement.view'],
            'management' => ['vendors.view', 'vendors.manage'],
            'reports' => ['reports.purchases.view', 'reports.financial.view'],
        ],
        'product' => [
            'sales' => ['sales.invoice.create', 'sales.invoice.edit_draft', 'sales.quote.create', 'sales.quote.edit', 'sales.return.create', 'sales.invoice.view'],
            'purchasing' => ['purchasing.purchase.create', 'purchasing.purchase.edit_draft', 'purchasing.return.manage', 'purchasing.purchase.view'],
            'inventory' => ['inventory.stock.view', 'inventory.product.manage', 'inventory.stock.transfer', 'inventory.movement.view'],
            'reports' => ['reports.inventory.view', 'reports.sales.view', 'reports.purchases.view'],
        ],
        'warehouse' => [
            'general' => ['sales.invoice.create', 'sales.invoice.edit_draft', 'purchasing.purchase.create', 'inventory.stock.view', 'inventory.stock.transfer', 'reports.inventory.view'],
        ],
        'employee' => [
            'payroll' => ['payroll.salary.manage', 'payroll.advance.manage', 'payroll.view', 'reports.payroll.view'],
        ],
        'money_account' => [
            'money' => ['money.account.view', 'money.receipt.create', 'money.vendor_payment.create', 'money.transfer.manage', 'reports.financial.view'],
        ],
    ];

    public function __construct(
        private readonly CompanyContext $companyContext
    ) {}

    /**
     * Perform a bounded, tenant-isolated, capability-checked search for selectors.
     *
     * @param array{
     *     capability?: string,
     *     context?: string,
     *     include_cost?: bool,
     *     only_stock?: bool,
     *     exclude_ids?: list<int>,
     *     locale?: string
     * } $options
     * @return array{
     *     items: list<array{
     *         id: string,
     *         label: string,
     *         sublabel: ?string,
     *         meta: array<string, mixed>,
     *         selected: bool
     *     }>,
     *     has_more: bool,
     *     limit: int,
     *     total: int
     * }
     */
    public function search(
        Company $company,
        User $actor,
        string $type,
        string $rawTerm = '',
        ?int $selectedId = null,
        int $limit = self::DEFAULT_LIMIT,
        array $options = []
    ): array {
        $this->authorize($company, $actor, $type, $options);

        $limit = max(1, min($limit, self::MAX_LIMIT));
        $term = mb_substr(trim($rawTerm), 0, self::MAX_TERM_LENGTH);
        $locale = $options['locale'] ?? app()->getLocale();

        return match ($type) {
            'customer' => $this->searchCustomers($company, $actor, $term, $selectedId, $limit, $locale, $options),
            'vendor' => $this->searchVendors($company, $actor, $term, $selectedId, $limit, $locale, $options),
            'product' => $this->searchProducts($company, $actor, $term, $selectedId, $limit, $locale, $options),
            'warehouse' => $this->searchWarehouses($company, $actor, $term, $selectedId, $limit, $locale, $options),
            'employee' => $this->searchEmployees($company, $actor, $term, $selectedId, $limit, $locale, $options),
            'money_account' => $this->searchMoneyAccounts($company, $actor, $term, $selectedId, $limit, $locale, $options),
            default => throw new \InvalidArgumentException("Unsupported selector type [{$type}]."),
        };
    }

    /**
     * Authorize that the actor has appropriate scoped capability without leaking permissions.
     *
     * @param array<string, mixed> $options
     */
    public function authorize(Company $company, User $actor, string $type, array $options = []): void
    {
        if (! $actor->relationLoaded('roles')) {
            $actor->load('roles.permissions');
        }

        // Intra-tenant membership verification
        if ($this->companyContext->hasCompany() && (int) $this->companyContext->companyId() !== (int) $company->id) {
            throw new AuthorizationException('Actor does not belong to the requested company.');
        }

        // If explicit capability specified, verify actor has it directly
        if (! empty($options['capability'])) {
            if (! $actor->hasPermissionTo($options['capability'])) {
                throw new AuthorizationException("Actor lacks required capability [{$options['capability']}].");
            }

            return;
        }

        // If context specified, verify actor has any permission in that context
        $context = $options['context'] ?? null;
        $allowlists = self::CAPABILITIES[$type] ?? null;

        if ($allowlists === null) {
            return;
        }

        if ($context !== null && isset($allowlists[$context])) {
            foreach ($allowlists[$context] as $perm) {
                if ($actor->hasPermissionTo($perm)) {
                    return;
                }
            }
            throw new AuthorizationException("Actor lacks permissions for [{$type}] in context [{$context}].");
        }

        // Check if actor has ANY recognized permission for this type across all contexts
        foreach ($allowlists as $perms) {
            foreach ($perms as $perm) {
                if ($actor->hasPermissionTo($perm)) {
                    return;
                }
            }
        }

        throw new AuthorizationException("Actor lacks authorization to search [{$type}].");
    }

    /**
     * Re-authorize and fetch an entity for canonical form selection.
     */
    public function authorizeAndResolve(Company $company, User $actor, string $type, int $id, array $options = []): ?object
    {
        $this->authorize($company, $actor, $type, $options);

        return match ($type) {
            'customer' => Customer::where('company_id', $company->id)->where('status', 'active')->whereKey($id)->first(),
            'vendor' => Vendor::where('company_id', $company->id)->where('status', 'active')->whereKey($id)->first(),
            'product' => Product::where('company_id', $company->id)->where('active', true)->whereKey($id)->first(),
            'warehouse' => Warehouse::where('company_id', $company->id)->where('active', true)->whereKey($id)->first(),
            default => null,
        };
    }

    /**
     * Escape LIKE wildcards to prevent pattern injection while preserving query accuracy.
     */
    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * Search customers with priority: exact code/phone -> prefix -> contains.
     *
     * @param array<string, mixed> $options
     * @return array{items: list<array<string, mixed>>, has_more: bool, limit: int, total: int}
     */
    private function searchCustomers(
        Company $company,
        User $actor,
        string $term,
        ?int $selectedId,
        int $limit,
        string $locale,
        array $options
    ): array {
        $query = Customer::where('company_id', $company->id)
            ->where('status', 'active');

        if (! empty($options['exclude_ids'])) {
            $query->whereNotIn('id', $options['exclude_ids']);
        }

        if ($term !== '') {
            $like = '%' . self::escapeLike($term) . '%';
            $prefix = self::escapeLike($term) . '%';

            $query->where(function (Builder $q) use ($like, $term) {
                $q->where('name_ar', 'LIKE', $like)
                    ->orWhere('name_en', 'LIKE', $like)
                    ->orWhere('code', 'LIKE', $like)
                    ->orWhere('phone', 'LIKE', $like)
                    ->orWhere('tax_number', 'LIKE', $like);
            });

            // Priority: exact code -> prefix name -> contains
            $query->orderByRaw(
                'CASE WHEN code = ? THEN 0 WHEN code LIKE ? THEN 1 WHEN name_ar LIKE ? OR name_en LIKE ? THEN 2 ELSE 3 END ASC',
                [$term, $prefix, $prefix, $prefix]
            );
        }

        $nameCol = $locale === 'ar' ? 'name_ar' : 'name_en';
        $fallbackCol = $locale === 'ar' ? 'name_en' : 'name_ar';
        $query->orderByRaw("COALESCE(NULLIF({$nameCol}, ''), {$fallbackCol}) ASC")
            ->orderBy('id', 'ASC');

        // Fetch limit + 1 to detect has_more without a separate COUNT query
        $records = (clone $query)->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
        }

        // Retain selected record hydration bounded independently
        $selectedRecord = null;
        if ($selectedId !== null && ! $records->contains('id', $selectedId)) {
            $selectedRecord = Customer::where('company_id', $company->id)
                ->where('id', $selectedId)
                ->first();
            if ($selectedRecord !== null) {
                $records->prepend($selectedRecord);
            }
        }

        $baseCurrency = $company->base_currency_code;
        $items = [];
        $seen = [];

        foreach ($records as $customer) {
            if (isset($seen[$customer->id])) {
                continue;
            }
            $seen[$customer->id] = true;

            $ar = (string) ($customer->name_ar ?? '');
            $en = (string) ($customer->name_en ?? '');
            $name = $locale === 'ar' ? ($ar !== '' ? $ar : $en) : ($en !== '' ? $en : $ar);
            $curr = $customer->default_currency_code ?: $baseCurrency;
            $label = "{$name} ({$curr})";

            $sublabel = $customer->code ?: $customer->phone;
            $isSelected = ($selectedId !== null && (int) $customer->id === $selectedId);

            $items[] = (new SearchableOptionDTO(
                id: (string) $customer->id,
                label: $label,
                sublabel: $sublabel,
                meta: [
                    'code' => $customer->code,
                    'phone' => $customer->phone,
                    'currency_code' => $curr,
                    'tax_number' => $customer->tax_number,
                ],
                selected: $isSelected
            ))->toArray();
        }

        return [
            'items' => $items,
            'has_more' => $hasMore,
            'limit' => $limit,
            'total' => count($items),
        ];
    }

    /**
     * Search vendors with priority: exact code/phone -> prefix -> contains.
     *
     * @param array<string, mixed> $options
     * @return array{items: list<array<string, mixed>>, has_more: bool, limit: int, total: int}
     */
    private function searchVendors(
        Company $company,
        User $actor,
        string $term,
        ?int $selectedId,
        int $limit,
        string $locale,
        array $options
    ): array {
        $query = Vendor::where('company_id', $company->id)
            ->where('status', 'active');

        if (! empty($options['exclude_ids'])) {
            $query->whereNotIn('id', $options['exclude_ids']);
        }

        if ($term !== '') {
            $like = '%' . self::escapeLike($term) . '%';
            $prefix = self::escapeLike($term) . '%';

            $query->where(function (Builder $q) use ($like) {
                $q->where('name_ar', 'LIKE', $like)
                    ->orWhere('name_en', 'LIKE', $like)
                    ->orWhere('code', 'LIKE', $like)
                    ->orWhere('phone', 'LIKE', $like)
                    ->orWhere('tax_number', 'LIKE', $like);
            });

            $query->orderByRaw(
                'CASE WHEN code = ? THEN 0 WHEN code LIKE ? THEN 1 WHEN name_ar LIKE ? OR name_en LIKE ? THEN 2 ELSE 3 END ASC',
                [$term, $prefix, $prefix, $prefix]
            );
        }

        $nameCol = $locale === 'ar' ? 'name_ar' : 'name_en';
        $fallbackCol = $locale === 'ar' ? 'name_en' : 'name_ar';
        $query->orderByRaw("COALESCE(NULLIF({$nameCol}, ''), {$fallbackCol}) ASC")
            ->orderBy('id', 'ASC');

        $records = (clone $query)->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
        }

        $selectedRecord = null;
        if ($selectedId !== null && ! $records->contains('id', $selectedId)) {
            $selectedRecord = Vendor::where('company_id', $company->id)
                ->where('id', $selectedId)
                ->first();
            if ($selectedRecord !== null) {
                $records->prepend($selectedRecord);
            }
        }

        $baseCurrency = $company->base_currency_code;
        $items = [];
        $seen = [];

        foreach ($records as $vendor) {
            if (isset($seen[$vendor->id])) {
                continue;
            }
            $seen[$vendor->id] = true;

            $ar = (string) ($vendor->name_ar ?? '');
            $en = (string) ($vendor->name_en ?? '');
            $name = $locale === 'ar' ? ($ar !== '' ? $ar : $en) : ($en !== '' ? $en : $ar);
            $curr = $vendor->default_currency_code ?: $baseCurrency;
            $label = $vendor->code ? "{$name} ({$vendor->code})" : "{$name} ({$curr})";
            $sublabel = $vendor->code ?: $vendor->phone;
            $isSelected = ($selectedId !== null && (int) $vendor->id === $selectedId);

            $items[] = (new SearchableOptionDTO(
                id: (string) $vendor->id,
                label: $label,
                sublabel: $sublabel,
                meta: [
                    'code' => $vendor->code,
                    'phone' => $vendor->phone,
                    'currency_code' => $curr,
                ],
                selected: $isSelected
            ))->toArray();
        }

        return [
            'items' => $items,
            'has_more' => $hasMore,
            'limit' => $limit,
            'total' => count($items),
        ];
    }

    /**
     * Search products with exact barcode unit resolution and cost redaction.
     *
     * @param array<string, mixed> $options
     * @return array{items: list<array<string, mixed>>, has_more: bool, limit: int, total: int}
     */
    private function searchProducts(
        Company $company,
        User $actor,
        string $term,
        ?int $selectedId,
        int $limit,
        string $locale,
        array $options
    ): array {
        $context = $options['context'] ?? 'sales';
        $canViewCost = match ($context) {
            'purchasing' => $actor->hasPermissionTo('purchasing.cost.view'),
            'inventory' => $actor->hasPermissionTo('inventory.cost.view'),
            default => false, // NEVER expose cost in sales or public contexts
        };

        // 1. Check exact barcode match for product + unit mapping
        $exactBarcode = null;
        $exactBarcodeProduct = null;
        $matchedUnitId = null;
        $matchedProductUnitId = null;

        if ($term !== '') {
            $exactBarcode = ProductBarcode::where('company_id', $company->id)
                ->where('barcode', $term)
                ->first();

            if ($exactBarcode !== null) {
                $exactBarcodeProduct = Product::where('company_id', $company->id)
                    ->where('id', $exactBarcode->product_id)
                    ->where('active', true)
                    ->first();

                if ($exactBarcodeProduct !== null && $exactBarcode->unit_id !== null) {
                    $matchedUnitId = (int) $exactBarcode->unit_id;
                    $matchedPu = ProductUnit::where('company_id', $company->id)
                        ->where('product_id', $exactBarcodeProduct->id)
                        ->where('unit_id', $matchedUnitId)
                        ->where('active', true)
                        ->first();
                    $matchedProductUnitId = $matchedPu ? (int) $matchedPu->id : null;
                }
            }
        }

        $query = Product::where('company_id', $company->id)
            ->where('active', true);

        if (! empty($options['only_stock'])) {
            $query->where('product_type', Product::TYPE_STOCK)->where('track_stock', true);
        }

        if (! empty($options['exclude_ids'])) {
            $query->whereNotIn('id', $options['exclude_ids']);
        }

        if ($term !== '') {
            $like = '%' . self::escapeLike($term) . '%';
            $prefix = self::escapeLike($term) . '%';

            $query->where(function (Builder $q) use ($like, $company, $term) {
                $q->where('name_ar', 'LIKE', $like)
                    ->orWhere('name_en', 'LIKE', $like)
                    ->orWhere('sku', 'LIKE', $like)
                    ->orWhereHas('barcodes', fn ($b) => $b->where('company_id', $company->id)->where('barcode', $term));
            });

            // Priority: exact SKU/barcode match -> prefix SKU -> prefix name -> contains
            $query->orderByRaw(
                'CASE WHEN sku = ? THEN 0 WHEN sku LIKE ? THEN 1 WHEN name_ar LIKE ? OR name_en LIKE ? THEN 2 ELSE 3 END ASC',
                [$term, $prefix, $prefix, $prefix]
            );
        }

        $nameCol = $locale === 'ar' ? 'name_ar' : 'name_en';
        $fallbackCol = $locale === 'ar' ? 'name_en' : 'name_ar';
        $query->orderByRaw("COALESCE(NULLIF({$nameCol}, ''), {$fallbackCol}) ASC")
            ->orderBy('id', 'ASC');

        $records = (clone $query)->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
        }

        // If exact barcode matched a product not in the collection, prepend it
        if ($exactBarcodeProduct !== null && ! $records->contains('id', $exactBarcodeProduct->id)) {
            $records->prepend($exactBarcodeProduct);
        }

        // Selected record hydration
        if ($selectedId !== null && ! $records->contains('id', $selectedId)) {
            $selectedRecord = Product::where('company_id', $company->id)
                ->where('id', $selectedId)
                ->first();
            if ($selectedRecord !== null) {
                $records->prepend($selectedRecord);
            }
        }

        $items = [];
        $seen = [];

        foreach ($records as $product) {
            if (isset($seen[$product->id])) {
                continue;
            }
            $seen[$product->id] = true;

            $ar = (string) ($product->name_ar ?? '');
            $en = (string) ($product->name_en ?? '');
            $name = $locale === 'ar' ? ($ar !== '' ? $ar : $en) : ($en !== '' ? $en : $ar);
            $sku = (string) ($product->sku ?? '');
            $label = $sku !== '' ? "{$name} ({$sku})" : $name;

            $meta = [
                'sku' => $sku,
                'base_unit_id' => $product->base_unit_id,
                'default_sale_price_base' => $product->default_sale_price_base !== null ? (string) $product->default_sale_price_base : null,
            ];

            // Strict server-side cost redaction
            if ($canViewCost && $product->default_purchase_cost_base !== null) {
                $meta['default_purchase_cost_base'] = (string) $product->default_purchase_cost_base;
            }

            // If this item was the exact barcode match, inject the exact unit resolution
            if ($exactBarcodeProduct !== null && (int) $product->id === (int) $exactBarcodeProduct->id) {
                $meta['matched_barcode'] = $term;
                $meta['matched_unit_id'] = $matchedUnitId;
                $meta['matched_product_unit_id'] = $matchedProductUnitId;
            }

            $isSelected = ($selectedId !== null && (int) $product->id === $selectedId);

            $items[] = (new SearchableOptionDTO(
                id: (string) $product->id,
                label: $label,
                sublabel: $sku,
                meta: $meta,
                selected: $isSelected
            ))->toArray();
        }

        return [
            'items' => $items,
            'has_more' => $hasMore,
            'limit' => $limit,
            'total' => count($items),
        ];
    }

    /**
     * Search warehouses for active company.
     *
     * @param array<string, mixed> $options
     * @return array{items: list<array<string, mixed>>, has_more: bool, limit: int, total: int}
     */
    private function searchWarehouses(
        Company $company,
        User $actor,
        string $term,
        ?int $selectedId,
        int $limit,
        string $locale,
        array $options
    ): array {
        $query = Warehouse::where('company_id', $company->id)
            ->where('active', true);

        if ($term !== '') {
            $like = '%' . self::escapeLike($term) . '%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('name_ar', 'LIKE', $like)
                    ->orWhere('name_en', 'LIKE', $like)
                    ->orWhere('code', 'LIKE', $like);
            });
        }

        $query->orderBy('is_default', 'DESC')
            ->orderBy('name_ar', 'ASC')
            ->orderBy('id', 'ASC');

        $records = (clone $query)->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
        }

        if ($selectedId !== null && ! $records->contains('id', $selectedId)) {
            $selected = Warehouse::where('company_id', $company->id)->where('id', $selectedId)->first();
            if ($selected !== null) {
                $records->prepend($selected);
            }
        }

        $items = [];
        $seen = [];
        foreach ($records as $w) {
            if (isset($seen[$w->id])) {
                continue;
            }
            $seen[$w->id] = true;
            $name = $locale === 'ar' ? ($w->name_ar ?: $w->name_en) : ($w->name_en ?: $w->name_ar);
            $label = $w->code ? "{$name} ({$w->code})" : $name;

            $items[] = (new SearchableOptionDTO(
                id: (string) $w->id,
                label: $label,
                sublabel: $w->code,
                meta: ['code' => $w->code, 'is_default' => (bool) $w->is_default],
                selected: ($selectedId !== null && (int) $w->id === $selectedId)
            ))->toArray();
        }

        return [
            'items' => $items,
            'has_more' => $hasMore,
            'limit' => $limit,
            'total' => count($items),
        ];
    }

    /**
     * Search employees for active company.
     *
     * @param array<string, mixed> $options
     * @return array{items: list<array<string, mixed>>, has_more: bool, limit: int, total: int}
     */
    private function searchEmployees(
        Company $company,
        User $actor,
        string $term,
        ?int $selectedId,
        int $limit,
        string $locale,
        array $options
    ): array {
        $query = Employee::where('company_id', $company->id)
            ->whereNull('deleted_at');

        if ($term !== '') {
            $like = '%' . self::escapeLike($term) . '%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('name', 'LIKE', $like)
                    ->orWhere('code', 'LIKE', $like);
            });
        }

        $query->orderBy('name', 'ASC')->orderBy('id', 'ASC');

        $records = (clone $query)->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
        }

        if ($selectedId !== null && ! $records->contains('id', $selectedId)) {
            $selected = Employee::where('company_id', $company->id)->where('id', $selectedId)->first();
            if ($selected !== null) {
                $records->prepend($selected);
            }
        }

        $items = [];
        $seen = [];
        foreach ($records as $emp) {
            if (isset($seen[$emp->id])) {
                continue;
            }
            $seen[$emp->id] = true;
            $label = $emp->code ? "{$emp->name} ({$emp->code})" : $emp->name;

            $items[] = (new SearchableOptionDTO(
                id: (string) $emp->id,
                label: $label,
                sublabel: $emp->code,
                meta: ['code' => $emp->code],
                selected: ($selectedId !== null && (int) $emp->id === $selectedId)
            ))->toArray();
        }

        return [
            'items' => $items,
            'has_more' => $hasMore,
            'limit' => $limit,
            'total' => count($items),
        ];
    }

    /**
     * Search money accounts for active company.
     *
     * @param array<string, mixed> $options
     * @return array{items: list<array<string, mixed>>, has_more: bool, limit: int, total: int}
     */
    private function searchMoneyAccounts(
        Company $company,
        User $actor,
        string $term,
        ?int $selectedId,
        int $limit,
        string $locale,
        array $options
    ): array {
        $query = MoneyAccount::where('company_id', $company->id)
            ->whereNull('deleted_at');

        if ($term !== '') {
            $like = '%' . self::escapeLike($term) . '%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('name_ar', 'LIKE', $like)
                    ->orWhere('name_en', 'LIKE', $like)
                    ->orWhere('account_number', 'LIKE', $like);
            });
        }

        $query->orderBy('sort_order', 'ASC')->orderBy('name_ar', 'ASC');

        $records = (clone $query)->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
        }

        if ($selectedId !== null && ! $records->contains('id', $selectedId)) {
            $selected = MoneyAccount::where('company_id', $company->id)->where('id', $selectedId)->first();
            if ($selected !== null) {
                $records->prepend($selected);
            }
        }

        $items = [];
        $seen = [];
        foreach ($records as $acc) {
            if (isset($seen[$acc->id])) {
                continue;
            }
            $seen[$acc->id] = true;
            $name = $locale === 'ar' ? ($acc->name_ar ?: $acc->name_en) : ($acc->name_en ?: $acc->name_ar);
            $label = "{$name} ({$acc->currency_code})";

            $items[] = (new SearchableOptionDTO(
                id: (string) $acc->id,
                label: $label,
                sublabel: $acc->account_number,
                meta: ['currency_code' => $acc->currency_code, 'account_type' => $acc->account_type],
                selected: ($selectedId !== null && (int) $acc->id === $selectedId)
            ))->toArray();
        }

        return [
            'items' => $items,
            'has_more' => $hasMore,
            'limit' => $limit,
            'total' => count($items),
        ];
    }
}
