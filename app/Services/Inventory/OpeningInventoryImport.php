<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Actions\Inventory\PostOpeningStockAction;
use App\Application\Platform\TenantWriteAccess;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Audit\AuditService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

/** One reviewed stocktake, all rows in one company-serialized canonical transaction. */
final class OpeningInventoryImport
{
    private const EVENT = 'inventory.opening_import.completed';

    /** @param array<string, mixed> $plan
     * @return array<string, mixed> */
    public function run(string $workbook, string $sourceSha, array $plan, string $planSha, Company $company, User $actor, bool $apply = false): array
    {
        $source = app(OpeningInventoryWorkbook::class)->read($workbook, $sourceSha);
        if (! preg_match('/^[a-f0-9]{64}$/D', $planSha) || ! hash_equals($planSha, $this->hash($plan))) {
            throw new InvalidArgumentException('Reviewed plan checksum mismatch.');
        }
        $this->validatePlan($plan, $source, $company, $actor);

        $execute = function () use ($plan, $planSha, $source, $company, $actor, $apply): array {
            if ($apply) {
                foreach (['inventory.product.manage', 'inventory.stock.adjust', 'inventory.cost.view'] as $permission) {
                    app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, $permission);
                }
            } else {
                $this->authorizeRead($company, $actor);
            }
            $owner = CompanyUser::where('company_id', $company->id)->where('user_id', $plan['owner_id'])->where('is_owner', true)->where('status', 'active')->first();
            if ($owner === null || User::whereKey($plan['owner_id'])->value('name') !== $plan['owner_name']) {
                throw new InvalidArgumentException('Reviewed company owner identity no longer matches.');
            }
            $receipt = AuditEvent::where('company_id', $company->id)->where('event_key', self::EVENT)
                ->where('meta_json->source_sha256', $source['source_sha256'])->first();
            if ($receipt !== null) {
                $meta = $receipt->getAttribute('meta_json');
                if (! is_array($meta) || ($meta['plan_sha256'] ?? null) !== $planSha || (int) $receipt->actor_user_id !== (int) $actor->id) {
                    throw new InvalidArgumentException('This source was already applied with a different plan or actor.');
                }

                return ['status' => 'ALREADY_APPLIED', 'audit_public_id' => $receipt->public_id, 'result' => $receipt->after_json];
            }
            if (! hash_equals($plan['baseline_sha256'], $this->baseline($company))) {
                throw new InvalidArgumentException('Tenant state changed; regenerate and review the plan.');
            }
            foreach (['stock_movements', 'posting_batches', 'sales_invoices', 'purchases', 'inventory_operations'] as $table) {
                if (DB::table($table)->where('company_id', $company->id)->exists()) {
                    throw new InvalidArgumentException('Existing business history requires a separately reviewed cutover treatment.');
                }
            }
            $this->reconcile($company, $actor);
            $catalog = Product::withTrashed()->where('company_id', $company->id)->get();
            $groups = [];
            $mapping = [];
            $total = BigDecimal::zero();
            $quantity = BigDecimal::zero();
            foreach ($source['rows'] as $index => $row) {
                $choice = $plan['rows'][$index];
                if ($choice['exclude']) {
                    $mapping[] = ['source_row' => $row['source_row'], 'action' => 'EXCLUDED', 'reason' => $choice['exclusion_reason']];

                    continue;
                }
                $unit = Unit::where('company_id', $company->id)->where('public_id', $choice['unit_public_id'])->where('active', true)->firstOrFail();
                $warehouse = Warehouse::where('company_id', $company->id)->where('public_id', $choice['warehouse_public_id'])->where('active', true)->firstOrFail();
                $rowQuantity = BigDecimal::of($row['quantity']);
                $rowQuantity->toScale($unit->allows_fraction ? (int) $unit->decimal_places : 0);
                if (BigDecimal::of($row['unit_cost'])->isZero() && ! $choice['zero_cost_confirmed']) {
                    throw new InvalidArgumentException("Row {$row['source_row']}: zero cost requires explicit review.");
                }
                $key = $choice['product_key'];
                $attributes = ['name_en' => $row['name_en'], 'base_unit_id' => $unit->id, 'default_purchase_cost_base' => $row['unit_cost'], 'default_sale_price_base' => $row['sale_price'],
                    'sku' => $plan['code_kind'] === 'sku' ? $row['code'] : null];
                if (isset($groups[$key]) && $groups[$key]['attributes'] !== $attributes) {
                    throw new InvalidArgumentException("Row {$row['source_row']}: grouped product has conflicting unit, identity or prices.");
                }
                if (! isset($groups[$key])) {
                    $product = $choice['existing_product_public_id'] === null ? null : Product::withTrashed()->where('company_id', $company->id)->where('public_id', $choice['existing_product_public_id'])->firstOrFail();
                    $nameMatches = $catalog->filter(
                        fn (Product $p): bool => in_array(mb_strtolower(trim($row['name_en'])), [mb_strtolower(trim($p->name_en ?? '')), mb_strtolower(trim($p->name_ar ?? ''))], true)
                    );
                    if ($product === null && $nameMatches->isNotEmpty()) {
                        throw new InvalidArgumentException("Row {$row['source_row']}: existing/soft-deleted name match needs explicit resolution.");
                    }
                    if ($product !== null && ($product->trashed() || ! $product->active || ! $product->track_stock || ! $product->track_expiry
                        || (int) $product->base_unit_id !== (int) $unit->id || ! $nameMatches->contains('id', $product->id))) {
                        throw new InvalidArgumentException("Row {$row['source_row']}: existing product attributes are incompatible; no automatic overwrite.");
                    }
                    if ($product === null && $apply) {
                        $product = app(ProductCatalogService::class)->createProduct($company, $attributes + ['name_ar' => null, 'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => true, 'active' => true], (int) $actor->id);
                    }
                    $groups[$key] = ['attributes' => $attributes, 'product' => $product];
                }
                $product = $groups[$key]['product'];
                if ($row['code'] !== null) {
                    $barcode = ProductBarcode::where('company_id', $company->id)->where('barcode', $row['code'])->first();
                    $sku = Product::withTrashed()->where('company_id', $company->id)->where('sku', $row['code'])->first();
                    if (($sku !== null && ($plan['code_kind'] !== 'sku' || $product === null || (int) $sku->id !== (int) $product->id))
                        || ($barcode !== null && ($plan['code_kind'] !== 'barcode' || $product === null || (int) $barcode->product_id !== (int) $product->id))) {
                        throw new InvalidArgumentException("Row {$row['source_row']}: code collision requires explicit resolution.");
                    }
                    if ($barcode !== null && $barcode->unit_id !== null && (int) $barcode->unit_id !== (int) $unit->id) {
                        throw new InvalidArgumentException("Row {$row['source_row']}: barcode unit differs from the reviewed base unit.");
                    }
                    if ($apply && $plan['code_kind'] === 'barcode' && $barcode === null) {
                        $firstBarcode = ! ProductBarcode::where('company_id', $company->id)->where('product_id', $product->id)->exists();
                        app(ProductCatalogService::class)->addBarcode($product, $row['code'], (int) $unit->id, $firstBarcode);
                    }
                }
                $result = null;
                if ($apply) {
                    $reason = 'Stocktake source '.$source['source_sha256'].'; row '.$row['source_row'].'; provided by '.$plan['source_provider'].'; imported by operator '.$actor->id;
                    $result = app(PostOpeningStockAction::class)->execute($company, $product, $warehouse, Quantity::of($row['quantity']), $row['unit_cost'], $actor,
                        'oi:'.substr($source['source_sha256'], 0, 40).':'.$row['source_row'], (int) $unit->id, null, $choice['expiry_date'], $plan['movement_date'], $reason);
                }
                $quantity = $quantity->plus($row['quantity']);
                $total = $total->plus(BigDecimal::of($row['quantity'])->multipliedBy($row['unit_cost']));
                $mapping[] = ['source_row' => $row['source_row'], 'product_key' => $key, 'product_public_id' => $product?->public_id,
                    'action' => $apply ? 'POSTED' : ($product === null ? 'CREATE_PRODUCT_AND_POST_OPENING' : 'MATCH_PRODUCT_AND_POST_OPENING'),
                    'movement_id' => $result === null ? null : $result['movement']->id, 'posting_batch_id' => $result === null ? null : $result['batch']?->id];
            }
            $report = ['rows' => $mapping, 'quantity' => (string) $quantity->toScale(6), 'value_base' => (string) $total->toScale(6), 'currency' => $company->base_currency];
            if ($apply) {
                $this->reconcile($company, $actor);
                foreach (['inventory.product.manage', 'inventory.stock.adjust', 'inventory.cost.view'] as $permission) {
                    app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, $permission);
                }
                app(AuditService::class)->log((int) $company->id, self::EVENT, 'Controlled stocktake opening inventory', (int) $actor->id, $company,
                    after: $report, meta: ['source_sha256' => $source['source_sha256'], 'rows_sha256' => $source['rows_sha256'], 'plan_sha256' => $planSha, 'source_provider' => $plan['source_provider'], 'stock_count_date' => $plan['stock_count_date']]);
            }

            return ['status' => $apply ? 'APPLIED' : 'DRY_RUN', 'result' => $report];
        };

        if ($apply) {
            return app(TenantWriteAccess::class)->transaction((int) $company->id, $execute);
        }

        // Server-enforced read-only transaction: even accidental helper writes cannot escape dry-run.
        if (DB::transactionLevel() !== 0) {
            throw new InvalidArgumentException('Dry-run requires an independent read-only connection transaction.');
        }
        DB::statement('SET TRANSACTION READ ONLY');
        DB::beginTransaction();
        try {
            return $execute();
        } finally {
            DB::rollBack();
        }
    }

    /** @param array<string, mixed> $plan */
    public function hash(array $plan): string
    {
        return hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
    }

    public function baseline(Company $company): string
    {
        $rows = ['company' => $company->fresh()->only(['id', 'public_id', 'name_ar', 'name_en', 'status', 'timezone', 'base_currency_code'])];
        foreach (['products', 'product_units', 'product_barcodes', 'units', 'warehouses', 'stock_movements', 'inventory_lots', 'inventory_lot_balances', 'inventory_balances', 'inventory_cost_states', 'posting_batches', 'posting_lines', 'ledger_accounts', 'sales_invoices', 'purchases', 'inventory_operations'] as $table) {
            $rows[$table] = DB::table($table)->where('company_id', $company->id)->orderBy('id')->get()->all();
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function authorizeRead(Company $company, User $actor): void
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || $context->companyId() !== (int) $company->id || ! auth()->check() || (int) auth()->id() !== (int) $actor->id
            || $company->fresh()->status !== 'active' || ! CompanyUser::where('company_id', $company->id)->where('user_id', $actor->id)->where('status', 'active')->exists()) {
            throw new AuthorizationException('An authenticated active tenant operator is required.');
        }
        setPermissionsTeamId((int) $company->id);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        foreach (['inventory.product.manage', 'inventory.stock.adjust', 'inventory.cost.view'] as $permission) {
            if (! $actor->hasPermissionTo($permission)) {
                throw new AuthorizationException('Required inventory permissions are missing.');
            }
        }
    }

    /** @param array<string, mixed> $plan
     * @param array<string, mixed> $source */
    private function validatePlan(array $plan, array $source, Company $company, User $actor): void
    {
        Validator::make($plan, [
            'version' => ['required', 'in:1'], 'source_sha256' => ['required', 'in:'.$source['source_sha256']], 'rows_sha256' => ['required', 'in:'.$source['rows_sha256']],
            'company_public_id' => ['required', 'in:'.$company->public_id], 'actor_id' => ['required', 'integer', 'in:'.$actor->id],
            'owner_id' => ['required', 'integer'], 'owner_name' => ['required', 'string', 'max:255'], 'source_provider' => ['required', 'string', 'max:100'],
            'baseline_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'currency' => ['required', 'in:'.$company->base_currency],
            'stock_count_date' => ['required', 'date_format:Y-m-d'], 'movement_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:stock_count_date'],
            'owned_stock_confirmed' => ['required', 'accepted'], 'complete_count_confirmed' => ['required', 'accepted'], 'not_previously_posted_confirmed' => ['required', 'accepted'], 'no_payable_confirmed' => ['required', 'accepted'],
            'prices_per_base_unit_confirmed' => ['required', 'accepted'],
            'code_kind' => ['required', 'in:barcode,sku'], 'uncoded_sku_policy' => ['required', 'in:leave_null'],
            'identity_resolution' => ['required', 'string', 'max:1000'], 'rows' => ['required', 'array', 'size:'.count($source['rows'])],
            'rows.*.source_row' => ['required', 'integer'], 'rows.*.exclude' => ['required', 'boolean'], 'rows.*.exclusion_reason' => ['nullable', 'string', 'max:500'],
            'rows.*.product_key' => ['required', 'string', 'max:64'], 'rows.*.existing_product_public_id' => ['present', 'nullable', 'ulid'],
            'rows.*.zero_cost_confirmed' => ['required', 'boolean'], 'rows.*.unknown_expiry_confirmed' => ['required', 'boolean'],
            'rows.*.unit_public_id' => ['nullable', 'ulid'], 'rows.*.warehouse_public_id' => ['nullable', 'ulid'], 'rows.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();
        if ($plan['movement_date'] > now($company->timezone)->toDateString()) {
            throw new InvalidArgumentException('Future opening dates are not supported.');
        }
        foreach ($source['rows'] as $i => $row) {
            $choice = $plan['rows'][$i] ?? [];
            if ($choice['source_row'] !== $row['source_row'] || ($choice['exclude'] && trim($choice['exclusion_reason'] ?? '') === '')
                    || (! $choice['exclude'] && (empty($choice['unit_public_id']) || empty($choice['warehouse_public_id'])
                        || (empty($choice['expiry_date']) && ! $choice['unknown_expiry_confirmed'])))
                || (! $choice['exclude'] && $row['expiry_date'] !== null && $choice['expiry_date'] !== $row['expiry_date'])) {
                throw new InvalidArgumentException("Row {$row['source_row']}: unresolved mapping, expiry, unit or exclusion.");
            }
        }
        $codes = [];
        $groupMatches = [];
        foreach ($source['rows'] as $i => $row) {
            $choice = $plan['rows'][$i];
            if ($choice['exclude']) {
                continue;
            }
            $key = $choice['product_key'];
            if (array_key_exists($key, $groupMatches) && $groupMatches[$key] !== $choice['existing_product_public_id']) {
                throw new InvalidArgumentException("Row {$row['source_row']}: inconsistent existing product resolution.");
            }
            $groupMatches[$key] = $choice['existing_product_public_id'];
            if ($row['code'] !== null && isset($codes[$row['code']]) && $codes[$row['code']] !== $key) {
                throw new InvalidArgumentException("Row {$row['source_row']}: one barcode cannot identify different products.");
            }
            if ($row['code'] !== null) {
                $codes[$row['code']] = $key;
            }
        }
    }

    private function reconcile(Company $company, User $actor): void
    {
        $context = app(CompanyContext::class);
        $context->clear();
        try {
            foreach ([AccountingReconciliationService::class => 'reconcile', InventoryReconciliationService::class => 'auditCompany',
                SalesReconciliationService::class => 'reconcile', PayablesReconciliationService::class => 'reconcile',
                MoneyReconciliationService::class => 'reconcile', Phase7ReconciliationService::class => 'reconcile'] as $service => $method) {
                if (! app($service)->$method($company, true)->isHealthy) {
                    throw new RuntimeException('Opening import refused: reconciliation is unhealthy.');
                }
            }
        } finally {
            $context->setCompany($company, $actor);
        }
    }
}
