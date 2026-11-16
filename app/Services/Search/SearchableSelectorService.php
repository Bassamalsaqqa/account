<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

final class SearchableSelectorService
{
    public const MAX_TERM_LENGTH = 160;

    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 50;

    /**
     * Map of selector types to capability allowlists per workflow context.
     * All permissions are verified against CompanyRoleService::PERMISSIONS.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const CAPABILITIES = [
        'customer' => [
            'sales' => ['sales.invoice.view', 'sales.invoice.create', 'sales.invoice.edit_draft'],
            'quotation' => ['sales.quote.view', 'sales.quote.create', 'sales.quote.edit'],
            'receipt' => ['money.receipt.view', 'money.receipt.create'],
            'statement' => ['sales.statement.view', 'customers.statement.view'],
            'management' => ['customers.view', 'customers.manage'],
            'reports' => ['reports.sales.view', 'reports.financial.view'],
        ],
        'vendor' => [
            'purchasing' => ['purchasing.purchase.view', 'purchasing.purchase.create', 'purchasing.purchase.edit_draft'],
            'payment' => ['money.vendor_payment.create'],
            'statement' => ['vendors.statement.view'],
            'management' => ['vendors.view', 'vendors.manage'],
            'reports' => ['reports.purchases.view', 'reports.financial.view'],
        ],
        'product' => [
            'sales' => ['sales.invoice.create', 'sales.invoice.edit_draft', 'sales.invoice.view', 'sales.return.create'],
            'quotation' => ['sales.quote.create', 'sales.quote.edit', 'sales.quote.view'],
            'purchasing' => ['purchasing.purchase.create', 'purchasing.purchase.edit_draft', 'purchasing.return.manage', 'purchasing.purchase.view'],
            'inventory' => ['inventory.stock.view', 'inventory.product.manage', 'inventory.stock.transfer', 'inventory.stock.adjust'],
            'reports' => ['reports.inventory.view', 'reports.sales.view', 'reports.purchases.view'],
        ],
        'warehouse' => [
            'general' => ['sales.invoice.create', 'sales.invoice.edit_draft', 'purchasing.purchase.create', 'inventory.stock.view', 'inventory.stock.transfer', 'reports.inventory.view', 'inventory.stock.adjust'],
        ],
    ];

    public function __construct(
        private readonly CompanyContext $companyContext
    ) {}

    public const CURSOR_VERSION = 1;

    public const MAX_OFFSET = 100000;

    /**
     * Encode an opaque, versioned, integrity-protected cursor bound to tenant, type, context, and query.
     */
    public function encodeCursor(int $companyId, string $type, string $context, string $term, int $offset): string
    {
        if ($offset < 0 || $offset > self::MAX_OFFSET) {
            throw new \InvalidArgumentException('Pagination offset is out of valid bounds.');
        }

        $payload = [
            'v' => self::CURSOR_VERSION,
            'c' => $companyId,
            't' => $type,
            'ctx' => $context,
            'qh' => hash('xxh128', $term),
            'o' => $offset,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $key = (string) config('app.key');
        $mac = hash_hmac('sha256', $json, $key);

        return rtrim(strtr(base64_encode($json.'.'.$mac), '+/', '-_'), '=');
    }

    /**
     * Decode and strictly validate an integrity-protected pagination cursor.
     * Throws InvalidArgumentException on tamper, bad signature, bounds violation,
     * malformed types, cross-tenant, cross-type, cross-context, or cross-query reuse.
     *
     * @return array{offset: int}
     */
    public function validateCursor(?string $cursor, int $companyId, string $type, string $context, string $term): array
    {
        if ($cursor === null || trim($cursor) === '') {
            return ['offset' => 0];
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Malformed pagination cursor encoding.');
        }

        $lastDot = strrpos($decoded, '.');
        if ($lastDot === false) {
            throw new \InvalidArgumentException('Missing cursor integrity signature.');
        }

        $json = substr($decoded, 0, $lastDot);
        $providedMac = substr($decoded, $lastDot + 1);

        $key = (string) config('app.key');
        $expectedMac = hash_hmac('sha256', $json, $key);

        if (! hash_equals($expectedMac, $providedMac)) {
            throw new \InvalidArgumentException('Tampered pagination cursor signature.');
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Malformed cursor payload JSON.', 0, $e);
        }

        if (! is_array($data)
            || ! isset($data['v'], $data['c'], $data['t'], $data['ctx'], $data['qh'], $data['o'])
            || ! is_int($data['v'])
            || $data['v'] !== self::CURSOR_VERSION
            || ! is_int($data['c'])
            || ! is_string($data['t'])
            || ! is_string($data['ctx'])
            || ! is_string($data['qh'])
            || ! is_int($data['o'])
            || $data['o'] < 0
            || $data['o'] > self::MAX_OFFSET) {
            throw new \InvalidArgumentException('Invalid pagination cursor types or bounds.');
        }

        if ($data['c'] !== $companyId) {
            throw new \InvalidArgumentException('Cross-tenant pagination cursor reuse is rejected.');
        }

        if ($data['t'] !== $type) {
            throw new \InvalidArgumentException('Cross-type pagination cursor reuse is rejected.');
        }

        if ($data['ctx'] !== $context) {
            throw new \InvalidArgumentException('Cross-workflow pagination cursor reuse is rejected.');
        }

        $expectedHash = hash('xxh128', $term);
        if (! hash_equals($expectedHash, $data['qh'])) {
            throw new \InvalidArgumentException('Cross-query pagination cursor reuse is rejected.');
        }

        return ['offset' => $data['o']];
    }

    /**
     * Perform a bounded, tenant-isolated, capability-checked search for selectors.
     *
     * @param array{
     *     context?: string,
     *     cursor?: ?string,
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
     *     selected_item: ?array{
     *         id: string,
     *         label: string,
     *         sublabel: ?string,
     *         meta: array<string, mixed>,
     *         selected: bool
     *     },
     *     has_more: bool,
     *     next_cursor: ?string,
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
            default => throw new \InvalidArgumentException("Unsupported selector type [{$type}]."),
        };
    }

    /**
     * Authorize that the actor has appropriate scoped capability without leaking permissions.
     * Derives permissions strictly on the server; client options are never authority.
     * Unknown permissions fail closed without crashing valid users.
     *
     * @param  array<string, mixed>  $options
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

        $allowlists = self::CAPABILITIES[$type] ?? null;
        if ($allowlists === null) {
            throw new AuthorizationException("Unsupported selector type [{$type}].");
        }

        $context = $options['context'] ?? null;

        if ($context !== null && isset($allowlists[$context])) {
            foreach ($allowlists[$context] as $perm) {
                try {
                    if ($actor->hasPermissionTo($perm)) {
                        return;
                    }
                } catch (PermissionDoesNotExist) {
                    continue;
                }
            }

            throw new AuthorizationException("Actor lacks permissions for [{$type}] in context [{$context}].");
        }

        // Check if actor has ANY recognized permission for this type across allowed contexts
        foreach ($allowlists as $perms) {
            foreach ($perms as $perm) {
                try {
                    if ($actor->hasPermissionTo($perm)) {
                        return;
                    }
                } catch (PermissionDoesNotExist) {
                    continue;
                }
            }
        }

        throw new AuthorizationException("Actor lacks authorization to search [{$type}].");
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
     * @param  array<string, mixed>  $options
     * @return array{items: list<array<string, mixed>>, selected_item: ?array<string, mixed>, has_more: bool, next_cursor: ?string, limit: int, total: int}
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
        $context = (string) ($options['context'] ?? 'sales');
        $cursorInfo = $this->validateCursor($options['cursor'] ?? null, (int) $company->id, 'customer', $context, $term);
        $offset = $cursorInfo['offset'];

        $query = Customer::where('company_id', $company->id)
            ->where('status', 'active');

        if (! empty($options['exclude_ids'])) {
            $query->whereNotIn('id', $options['exclude_ids']);
        }

        if ($term !== '') {
            $like = '%'.self::escapeLike($term).'%';
            $prefix = self::escapeLike($term).'%';

            $query->where(function (Builder $q) use ($like) {
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
            ->orderBy('id', 'asc');

        // Fetch limit + 1 from offset to detect has_more without a separate COUNT query
        $records = (clone $query)->offset($offset)->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
            $nextCursor = $this->encodeCursor((int) $company->id, 'customer', $context, $term, $offset + $limit);
        } else {
            $nextCursor = null;
        }

        $baseCurrency = $company->base_currency_code;
        $items = [];
        $seen = [];
        $selectedItem = null;

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

            $dto = (new SearchableOptionDTO(
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

            $items[] = $dto;
            if ($isSelected) {
                $selectedItem = $dto;
            }
        }

        // Separate hydration: if selected record is outside this page, hydrate independently without corrupting stream
        if ($selectedId !== null && $selectedItem === null && $offset === 0) {
            $selectedRecord = Customer::where('company_id', $company->id)
                ->where('id', $selectedId)
                ->where('status', 'active')
                ->first();
            if ($selectedRecord !== null) {
                $ar = (string) ($selectedRecord->name_ar ?? '');
                $en = (string) ($selectedRecord->name_en ?? '');
                $name = $locale === 'ar' ? ($ar !== '' ? $ar : $en) : ($en !== '' ? $en : $ar);
                $curr = $selectedRecord->default_currency_code ?: $baseCurrency;
                $selectedItem = (new SearchableOptionDTO(
                    id: (string) $selectedRecord->id,
                    label: "{$name} ({$curr})",
                    sublabel: $selectedRecord->code ?: $selectedRecord->phone,
                    meta: [
                        'code' => $selectedRecord->code,
                        'phone' => $selectedRecord->phone,
                        'currency_code' => $curr,
                        'tax_number' => $selectedRecord->tax_number,
                    ],
                    selected: true
                ))->toArray();
            }
        }

        return [
            'items' => $items,
            'selected_item' => $selectedItem,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
            'limit' => $limit,
            'total' => count($items),
        ];
    }

    /**
     * Search vendors with priority: exact code/phone -> prefix -> contains.
     * In payment context, includes soft-deleted vendors with posted purchases.
     *
     * @param  array<string, mixed>  $options
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
        $context = (string) ($options['context'] ?? 'purchasing');
        $isPayment = ($context === 'payment');

        $cursorInfo = $this->validateCursor($options['cursor'] ?? null, (int) $company->id, 'vendor', $context, $term);
        $offset = $cursorInfo['offset'];

        $query = Vendor::where('company_id', $company->id);

        if ($isPayment) {
            $query->withTrashed()->where(function (Builder $q): void {
                $q->where(fn ($active) => $active->where('status', 'active')->whereNull('deleted_at'))
                    ->orWhereHas('purchases', fn ($p) => $p->where('status', Purchase::STATUS_POSTED));
            });
        } else {
            $query->where('status', 'active');
        }

        if (! empty($options['exclude_ids'])) {
            $query->whereNotIn('id', $options['exclude_ids']);
        }

        if ($term !== '') {
            $like = '%'.self::escapeLike($term).'%';
            $prefix = self::escapeLike($term).'%';

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
            ->orderBy('id', 'asc');

        $records = (clone $query)->offset($offset)->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
            $nextCursor = $this->encodeCursor((int) $company->id, 'vendor', $context, $term, $offset + $limit);
        } else {
            $nextCursor = null;
        }

        $baseCurrency = $company->base_currency_code;
        $items = [];
        $seen = [];
        $selectedItem = null;

        foreach ($records as $vendor) {
            if (isset($seen[$vendor->id])) {
                continue;
            }
            $seen[$vendor->id] = true;

            $ar = (string) ($vendor->name_ar ?? '');
            $en = (string) ($vendor->name_en ?? '');
            $name = $locale === 'ar' ? ($ar !== '' ? $ar : $en) : ($en !== '' ? $en : $ar);
            $curr = $vendor->default_currency_code ?: $baseCurrency;
            $label = "{$name} ({$curr})";

            $sublabel = $vendor->code ?: $vendor->phone;
            $isSelected = ($selectedId !== null && (int) $vendor->id === $selectedId);

            $dto = (new SearchableOptionDTO(
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

            $items[] = $dto;
            if ($isSelected) {
                $selectedItem = $dto;
            }
        }

        // Separate hydration: if selected record is outside this page, hydrate independently without corrupting stream
        if ($selectedId !== null && $selectedItem === null && $offset === 0) {
            $selQuery = Vendor::where('company_id', $company->id)->where('id', $selectedId);
            if ($isPayment) {
                $selQuery->withTrashed()->where(function (Builder $q): void {
                    $q->where(fn ($active) => $active->where('status', 'active')->whereNull('deleted_at'))
                        ->orWhereHas('purchases', fn ($p) => $p->where('status', Purchase::STATUS_POSTED));
                });
            } else {
                $selQuery->where('status', 'active');
            }
            $selectedRecord = $selQuery->first();
            if ($selectedRecord !== null) {
                $ar = (string) ($selectedRecord->name_ar ?? '');
                $en = (string) ($selectedRecord->name_en ?? '');
                $name = $locale === 'ar' ? ($ar !== '' ? $ar : $en) : ($en !== '' ? $en : $ar);
                $curr = $selectedRecord->default_currency_code ?: $baseCurrency;
                $selectedItem = (new SearchableOptionDTO(
                    id: (string) $selectedRecord->id,
                    label: "{$name} ({$curr})",
                    sublabel: $selectedRecord->code ?: $selectedRecord->phone,
                    meta: [
                        'code' => $selectedRecord->code,
                        'phone' => $selectedRecord->phone,
                        'currency_code' => $curr,
                    ],
                    selected: true
                ))->toArray();
            }
        }

        return [
            'items' => $items,
            'selected_item' => $selectedItem,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
            'limit' => $limit,
            'total' => count($items),
        ];
    }

    /**
     * Search products with exact barcode unit resolution and cost redaction.
     * Server derives stock-only predicate for inventory and purchasing contexts.
     *
     * @param  array<string, mixed>  $options
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
        $context = (string) ($options['context'] ?? 'sales');
        $isStockOnly = in_array($context, ['inventory', 'purchasing'], true);

        $cursorInfo = $this->validateCursor($options['cursor'] ?? null, (int) $company->id, 'product', $context, $term);
        $offset = $cursorInfo['offset'];

        $canViewCost = match ($context) {
            'purchasing' => $actor->hasPermissionTo('purchasing.cost.view'),
            'inventory' => $actor->hasPermissionTo('inventory.cost.view'),
            default => false, // NEVER expose cost in sales or public contexts
        };

        // 1. Check exact barcode match for product + unit mapping
        $exactBarcode = null;
        $exactBarcodeProduct = null;
        $exactBarcodeId = 0;
        $matchedUnitId = null;
        $matchedProductUnitId = null;

        if ($term !== '') {
            $exactBarcode = ProductBarcode::where('company_id', $company->id)
                ->where('barcode', $term)
                ->first();

            if ($exactBarcode !== null) {
                $barcodeProductQuery = Product::where('company_id', $company->id)
                    ->where('id', $exactBarcode->product_id)
                    ->where('active', true);

                if ($isStockOnly) {
                    $barcodeProductQuery->where('product_type', Product::TYPE_STOCK)->where('track_stock', true);
                }

                $exactBarcodeProduct = $barcodeProductQuery->first();

                if ($exactBarcodeProduct !== null) {
                    $exactBarcodeId = (int) $exactBarcodeProduct->id;
                    if ($exactBarcode->unit_id !== null) {
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
        }

        $query = Product::where('company_id', $company->id)
            ->where('active', true);

        if ($isStockOnly) {
            $query->where('product_type', Product::TYPE_STOCK)->where('track_stock', true);
        }

        if (! empty($options['exclude_ids'])) {
            $query->whereNotIn('id', $options['exclude_ids']);
        }

        if ($term !== '') {
            $like = '%'.self::escapeLike($term).'%';
            $prefix = self::escapeLike($term).'%';

            $query->where(function (Builder $q) use ($like, $company, $term) {
                $q->where('name_ar', 'LIKE', $like)
                    ->orWhere('name_en', 'LIKE', $like)
                    ->orWhere('sku', 'LIKE', $like)
                    ->orWhereHas('barcodes', fn ($b) => $b->where('company_id', $company->id)->where('barcode', $term));
            });

            // Priority: exact barcode match -> exact SKU -> prefix SKU -> prefix name -> contains
            $query->orderByRaw(
                'CASE WHEN id = ? THEN 0 WHEN sku = ? THEN 1 WHEN sku LIKE ? THEN 2 WHEN name_ar LIKE ? OR name_en LIKE ? THEN 3 ELSE 4 END ASC',
                [$exactBarcodeId, $term, $prefix, $prefix, $prefix]
            );
        }

        $nameCol = $locale === 'ar' ? 'name_ar' : 'name_en';
        $fallbackCol = $locale === 'ar' ? 'name_en' : 'name_ar';
        $query->orderByRaw("COALESCE(NULLIF({$nameCol}, ''), {$fallbackCol}) ASC")
            ->orderBy('id', 'asc');

        $records = (clone $query)->offset($offset)->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
            $nextCursor = $this->encodeCursor((int) $company->id, 'product', $context, $term, $offset + $limit);
        } else {
            $nextCursor = null;
        }

        $items = [];
        $seen = [];
        $selectedItem = null;

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

            $dto = (new SearchableOptionDTO(
                id: (string) $product->id,
                label: $label,
                sublabel: $sku,
                meta: $meta,
                selected: $isSelected
            ))->toArray();

            $items[] = $dto;
            if ($isSelected) {
                $selectedItem = $dto;
            }
        }

        // Separate hydration: if selected record is outside this page, hydrate independently without corrupting stream
        if ($selectedId !== null && $selectedItem === null && $offset === 0) {
            $selQuery = Product::where('company_id', $company->id)
                ->where('id', $selectedId)
                ->where('active', true);

            if ($isStockOnly) {
                $selQuery->where('product_type', Product::TYPE_STOCK)->where('track_stock', true);
            }

            $selectedRecord = $selQuery->first();
            if ($selectedRecord !== null) {
                $ar = (string) ($selectedRecord->name_ar ?? '');
                $en = (string) ($selectedRecord->name_en ?? '');
                $name = $locale === 'ar' ? ($ar !== '' ? $ar : $en) : ($en !== '' ? $en : $ar);
                $sku = (string) ($selectedRecord->sku ?? '');
                $label = $sku !== '' ? "{$name} ({$sku})" : $name;

                $meta = [
                    'sku' => $sku,
                    'base_unit_id' => $selectedRecord->base_unit_id,
                    'default_sale_price_base' => $selectedRecord->default_sale_price_base !== null ? (string) $selectedRecord->default_sale_price_base : null,
                ];

                if ($canViewCost && $selectedRecord->default_purchase_cost_base !== null) {
                    $meta['default_purchase_cost_base'] = (string) $selectedRecord->default_purchase_cost_base;
                }

                $selectedItem = (new SearchableOptionDTO(
                    id: (string) $selectedRecord->id,
                    label: $label,
                    sublabel: $sku,
                    meta: $meta,
                    selected: true
                ))->toArray();
            }
        }

        return [
            'items' => $items,
            'selected_item' => $selectedItem,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
            'limit' => $limit,
            'total' => count($items),
        ];
    }

    /**
     * Search warehouses for active company.
     *
     * @param  array<string, mixed>  $options
     * @return array{items: list<array<string, mixed>>, selected_item: ?array<string, mixed>, has_more: bool, next_cursor: ?string, limit: int, total: int}
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
        $context = (string) ($options['context'] ?? 'general');
        $cursorInfo = $this->validateCursor($options['cursor'] ?? null, (int) $company->id, 'warehouse', $context, $term);
        $offset = $cursorInfo['offset'];

        $query = Warehouse::where('company_id', $company->id)
            ->where('active', true);

        if ($term !== '') {
            $like = '%'.self::escapeLike($term).'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('name_ar', 'LIKE', $like)
                    ->orWhere('name_en', 'LIKE', $like)
                    ->orWhere('code', 'LIKE', $like);
            });
        }

        $query->orderBy('is_default', 'desc')
            ->orderBy('name_ar', 'asc')
            ->orderBy('id', 'asc');

        $records = (clone $query)->offset($offset)->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
            $nextCursor = $this->encodeCursor((int) $company->id, 'warehouse', $context, $term, $offset + $limit);
        } else {
            $nextCursor = null;
        }

        $items = [];
        $seen = [];
        $selectedItem = null;

        foreach ($records as $w) {
            if (isset($seen[$w->id])) {
                continue;
            }
            $seen[$w->id] = true;
            $name = $locale === 'ar' ? ($w->name_ar ?: $w->name_en) : ($w->name_en ?: $w->name_ar);
            $label = $w->code ? "{$name} ({$w->code})" : $name;
            $isSelected = ($selectedId !== null && (int) $w->id === $selectedId);

            $dto = (new SearchableOptionDTO(
                id: (string) $w->id,
                label: $label,
                sublabel: $w->code,
                meta: ['code' => $w->code, 'is_default' => (bool) $w->is_default],
                selected: $isSelected
            ))->toArray();

            $items[] = $dto;
            if ($isSelected) {
                $selectedItem = $dto;
            }
        }

        // Separate hydration: if selected record is outside this page, hydrate independently without corrupting stream
        if ($selectedId !== null && $selectedItem === null && $offset === 0) {
            $selected = Warehouse::where('company_id', $company->id)->where('id', $selectedId)->where('active', true)->first();
            if ($selected !== null) {
                $name = $locale === 'ar' ? ($selected->name_ar ?: $selected->name_en) : ($selected->name_en ?: $selected->name_ar);
                $label = $selected->code ? "{$name} ({$selected->code})" : $name;

                $selectedItem = (new SearchableOptionDTO(
                    id: (string) $selected->id,
                    label: $label,
                    sublabel: $selected->code,
                    meta: ['code' => $selected->code, 'is_default' => (bool) $selected->is_default],
                    selected: true
                ))->toArray();
            }
        }

        return [
            'items' => $items,
            'selected_item' => $selectedItem,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
            'limit' => $limit,
            'total' => count($items),
        ];
    }
}
