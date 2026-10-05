<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5D;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\PurchaseReturnPostingScope;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

abstract class Phase5DTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Vendor $vendor;

    protected Product $product;

    protected ProductUnit $unit;

    protected Product $expiryProduct;

    protected ProductUnit $expiryUnit;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة مردودات المشتريات',
            'name_en' => 'Purchase Returns Company',
        ]);
        $this->activate($this->owner);

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد المردودات',
            'name_en' => 'Returns Vendor',
        ]);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();

        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'زيت زيتون',
            'name_en' => 'Olive Oil',
            'sku' => 'PRT-OIL',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => Unit::where('code', 'piece')->firstOrFail()->id,
        ], $this->owner->id);
        $this->unit = ProductUnit::where('product_id', $this->product->id)->firstOrFail();

        $this->expiryProduct = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'دواء',
            'name_en' => 'Medicine',
            'sku' => 'PRT-MED',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => true,
            'base_unit_id' => Unit::where('code', 'piece')->firstOrFail()->id,
        ], $this->owner->id);
        $this->expiryUnit = ProductUnit::where('product_id', $this->expiryProduct->id)->firstOrFail();
    }

    protected function activate(User $actor): void
    {
        app(CompanyContext::class)->setCompany($this->company, $actor);
        $this->actingAs($actor);
        setPermissionsTeamId($this->company->id);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
    }

    protected function createAndPostPurchase(array $changes = []): Purchase
    {
        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, array_replace_recursive([
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-04',
            'due_date' => null,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '10',
                ],
            ],
        ], $changes));

        return app(PostPurchaseAction::class)->execute($purchase, $this->owner);
    }

    protected function createAndPostExpiryPurchase(array $lots = [], array $changes = []): Purchase
    {
        $rawLots = ! empty($lots) ? $lots : [
            [
                'lot_number' => 'LOT-001',
                'expiry_date' => '2026-12-31',
                'quantity' => '10',
            ],
        ];

        $lotsData = [];
        foreach ($rawLots as $lot) {
            $lotsData[] = array_merge([
                'product_unit_id' => $this->expiryUnit->id,
            ], $lot);
        }

        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, array_replace_recursive([
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-04',
            'due_date' => null,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $this->expiryProduct->id,
                    'quantity' => '10',
                    'unit_cost' => '20',
                    'lots' => $lotsData,
                ],
            ],
        ], $changes));

        return app(PostPurchaseAction::class)->execute($purchase, $this->owner);
    }

    protected function createReturnDraft(Purchase $purchase, array $changes = []): PurchaseReturn
    {
        $lines = [];
        foreach ($purchase->lines as $line) {
            $lineData = [
                'purchase_line_id' => $line->id,
                'quantity' => '1',
            ];

            if ($line->product->track_expiry && count($line->lots)) {
                $allocations = [];
                $firstLot = $line->lots->first();
                $allocations[] = [
                    'original_stock_movement_id' => $firstLot->stock_movement_id,
                    'purchase_line_lot_id' => $firstLot->id,
                    'inventory_lot_id' => $firstLot->created_inventory_lot_id,
                    'quantity' => '1',
                ];
                $lineData['allocations'] = $allocations;
            }

            $lines[] = $lineData;
        }

        $finalLines = $changes['lines'] ?? $lines;
        unset($changes['lines']);

        return app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, array_replace_recursive([
            'purchase_id' => $purchase->id,
            'return_date' => $purchase->purchase_date->format('Y-m-d'),
            'reason' => 'Damaged on arrival',
            'notes' => 'Returned for credit',
            'lines' => $finalLines,
        ], $changes));
    }

    protected function postReturn(PurchaseReturn $return, ?User $actor = null): PurchaseReturn
    {
        return app(PostPurchaseReturnAction::class)->execute($return, $actor ?? $this->owner);
    }

    protected function withinTestReturnPostingScope(PurchaseReturn $return, Closure $callback): mixed
    {
        return DB::transaction(function () use ($return, $callback) {
            $scope = app(PurchaseReturnPostingScope::class);
            $owner = new PostPurchaseReturnAction;
            $ownership = new ReflectionProperty($owner, 'activePostingScope');
            $ownership->setValue($owner, $scope);
            try {
                return $scope->withinCanonicalReturnPosting($owner, $return, $this->owner, $callback);
            } finally {
                $ownership->setValue($owner, null);
            }
        });
    }

    /** @return array<string, string> */
    protected function snapshotState(): array
    {
        $result = [];
        $tables = [
            'purchases', 'purchase_lines', 'purchase_line_lots',
            'purchase_returns', 'purchase_return_lines', 'purchase_return_allocations',
            'posting_batches', 'posting_lines', 'stock_movements',
            'inventory_operations', 'inventory_lots', 'inventory_balances',
            'inventory_lot_balances', 'inventory_cost_states', 'document_sequences', 'audit_events',
        ];

        foreach ($tables as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $result;
    }

    protected function tax(?int $account = null, string $mode = 'exclusive', string $code = 'VAT16', string $rate = '16.000000'): TaxRate
    {
        return TaxRate::create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name_ar' => 'ضريبة',
            'name_en' => 'Tax',
            'rate' => $rate,
            'calculation' => $mode,
            'purchase_tax_account_id' => $account,
            'active' => true,
        ]);
    }

    protected function account(string $key): LedgerAccount
    {
        return LedgerAccount::where('company_id', $this->company->id)->where('system_key', $key)->firstOrFail();
    }

    protected function purchaseAccountNet(Purchase $purchase, string $key): string
    {
        $lines = $purchase->postingBatch->lines()->where('ledger_account_id', $this->account($key)->id)->get();
        $amount = BigDecimal::zero();
        foreach ($lines as $line) {
            $amount = $amount->plus($line->debit_base)->minus($line->credit_base);
        }

        return (string) $amount->toScale(6);
    }

    protected function returnAccountNet(PurchaseReturn $return, string $key): string
    {
        if ($return->posting_batch_id === null || $return->postingBatch === null) {
            return '0.000000';
        }
        $lines = $return->postingBatch->lines()->where('ledger_account_id', $this->account($key)->id)->get();
        $amount = BigDecimal::zero();
        foreach ($lines as $line) {
            $amount = $amount->plus($line->debit_base)->minus($line->credit_base);
        }

        return (string) $amount->toScale(6);
    }
}
