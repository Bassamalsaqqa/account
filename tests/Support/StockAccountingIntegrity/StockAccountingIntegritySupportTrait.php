<?php

declare(strict_types=1);

namespace Tests\Support\StockAccountingIntegrity;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

trait StockAccountingIntegritySupportTrait
{
    /**
     * Set up a canonical company with standard and expiry-tracked products,
     * default warehouse, default unit, customer, and authenticated owner.
     *
     * @return array{
     *     company: Company,
     *     owner: User,
     *     warehouse: Warehouse,
     *     unit: Unit,
     *     product: Product,
     *     productUnit: ProductUnit,
     *     expiryProduct: Product,
     *     expiryProductUnit: ProductUnit,
     *     customer: Customer
     * }
     */
    protected function setupCanonicalCompany(string $currency = 'ILS', string $companyName = 'شركة تجارب تكامل المخزون والمحاسبة'): array
    {
        app(CompanyContext::class)->clear();

        /** @var User $owner */
        $owner = User::factory()->create(['locale' => 'ar']);

        /** @var Company $company */
        $company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => $companyName,
            'base_currency_code' => $currency,
            'default_locale' => 'ar',
        ]);

        $this->activateCompanyActor($company, $owner);

        /** @var Warehouse $warehouse */
        $warehouse = Warehouse::where('company_id', $company->id)
            ->where('is_default', true)
            ->firstOrFail();

        /** @var Unit $unit */
        $unit = Unit::where('company_id', $company->id)
            ->where('code', 'piece')
            ->firstOrFail();

        /** @var Product $product */
        $product = app(ProductCatalogService::class)->createProduct($company, [
            'name_ar' => 'منتج قياسي للتجارب',
            'name_en' => 'Standard Test Product',
            'sku' => 'SKU-STD-001',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $unit->id,
        ], $owner->id);

        /** @var Product $expiryProduct */
        $expiryProduct = app(ProductCatalogService::class)->createProduct($company, [
            'name_ar' => 'منتج تاريخ صلاحية للتجارب',
            'name_en' => 'Expiry Test Product',
            'sku' => 'SKU-EXP-001',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => true,
            'base_unit_id' => $unit->id,
        ], $owner->id);

        /** @var Customer $customer */
        $customer = Customer::create([
            'company_id' => $company->id,
            'name_ar' => 'عميل معتمد للتجارب',
            'active' => true,
            'created_by' => $owner->id,
        ]);

        /** @var ProductUnit $productUnit */
        $productUnit = ProductUnit::where('product_id', $product->id)
            ->where('is_base', true)
            ->firstOrFail();

        /** @var ProductUnit $expiryProductUnit */
        $expiryProductUnit = ProductUnit::where('product_id', $expiryProduct->id)
            ->where('is_base', true)
            ->firstOrFail();

        return [
            'company' => $company,
            'owner' => $owner,
            'warehouse' => $warehouse,
            'unit' => $unit,
            'product' => $product,
            'productUnit' => $productUnit,
            'expiryProduct' => $expiryProduct,
            'expiryProductUnit' => $expiryProductUnit,
            'customer' => $customer,
        ];
    }

    /**
     * Activate company tenancy context and acting actor.
     */
    protected function activateCompanyActor(Company $company, User $actor): void
    {
        app(CompanyContext::class)->setCompany($company, $actor);
        $this->actingAs($actor);
        setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
    }

    /**
     * Create a second warehouse for transfers in the company.
     */
    protected function createSecondWarehouse(Company $company, User $owner, string $code = 'WH-BRANCH-2'): Warehouse
    {
        return Warehouse::create([
            'company_id' => $company->id,
            'code' => $code,
            'name_ar' => 'مستودع فرعي',
            'name_en' => 'Secondary Branch Warehouse',
            'is_default' => false,
            'active' => true,
            'created_by' => $owner->id,
        ]);
    }

    /**
     * Create a company member assigned to a specific role (e.g. 'Warehouse', 'Viewer').
     */
    protected function createScopedMemberWithRole(Company $company, string $roleName): User
    {
        /** @var User $member */
        $member = User::factory()->create(['locale' => 'ar']);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $member->id,
            'role' => $roleName,
            'status' => 'active',
        ]);

        setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

        /** @var Role $role */
        $role = Role::where('company_id', $company->id)->where('name', $roleName)->firstOrFail();
        $member->assignRole($role);
        $member->unsetRelation('roles')->unsetRelation('permissions');

        return $member;
    }

    /**
     * Create a draft sales invoice with exact decimal values.
     */
    protected function createSalesInvoiceDraft(
        Company $company,
        User $user,
        Customer $customer,
        Warehouse $warehouse,
        Product $product,
        ProductUnit $productUnit,
        string $quantity = '10.000000',
        string $unitPrice = '50.000000',
        ?string $issueDate = null
    ): SalesInvoice {
        $draftAction = app(CreateSalesInvoiceDraftAction::class);
        $date = $issueDate ?? Carbon::now()->toDateString();

        return $draftAction->execute($company, $user, [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'currency_code' => $company->base_currency_code,
            'exchange_rate' => '1.0000000000',
            'issue_date' => $date,
            'due_date' => Carbon::parse($date)->addDays(30)->toDateString(),
            'lines' => [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $productUnit->id,
                    'item_description' => $product->name_ar,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);
    }

    /**
     * Create a draft sales return from a posted sales invoice line.
     */
    protected function createSalesReturnDraft(
        Company $company,
        User $user,
        SalesInvoice $invoice,
        string $quantity = '4.000000',
        ?string $reason = 'إرجاع تجريبي معتمد'
    ): SalesReturn {
        /** @var SalesInvoiceLine $line */
        $line = $invoice->lines()->firstOrFail();
        $draftReturnAction = app(CreateSalesReturnDraftAction::class);

        return $draftReturnAction->execute($company, $user, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => Carbon::now()->toDateString(),
            'reason' => $reason,
            'lines' => [
                [
                    'sales_invoice_line_id' => $line->id,
                    'quantity' => $quantity,
                ],
            ],
        ]);
    }

    /**
     * Construct a direct StockMovementCommand for economic movement diagnostic tests.
     */
    protected function makeEconomicMovementCommand(
        Company $company,
        User $user,
        Warehouse $warehouse,
        Product $product,
        string $movementType,
        string $quantity = '2.000000',
        ?string $unitCost = '1.000000',
        ?string $idempotencyKey = null,
        ?string $movementDate = null
    ): StockMovementCommand {
        return new StockMovementCommand(
            companyId: $company->id,
            movementType: $movementType,
            movementDate: $movementDate ?? Carbon::now()->toDateString(),
            lines: [
                new StockMovementLineCommand(
                    productId: $product->id,
                    warehouseId: $warehouse->id,
                    quantity: Quantity::of($quantity),
                    unitCostBase: $unitCost,
                ),
            ],
            sourceType: 'diagnostic_test',
            sourceId: 1,
            idempotencyKey: $idempotencyKey ?? (string) Str::ulid(),
            createdBy: $user->id,
            reason: "Economic movement {$movementType}"
        );
    }

    /**
     * Exact JSON snapshot of economic and provenance tables.
     *
     * @return array<string, string>
     */
    protected function snapshotAccountingStockState(): array
    {
        $tables = [
            'stock_movements',
            'inventory_operations',
            'inventory_balances',
            'inventory_cost_states',
            'inventory_lots',
            'inventory_lot_balances',
            'posting_batches',
            'posting_lines',
            'document_sequences',
            'sales_invoices',
            'sales_invoice_lines',
            'sales_invoice_lot_allocations',
            'sales_returns',
            'sales_return_lines',
            'sales_return_lot_allocations',
        ];

        $state = [];
        foreach ($tables as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $state;
    }

    /**
     * Assert that the current state of all economic and provenance tables exactly matches the snapshot.
     *
     * @param  array<string, string>  $before
     */
    protected function assertAccountingStockStateUnchanged(array $before): void
    {
        $after = $this->snapshotAccountingStockState();
        foreach ($before as $table => $json) {
            $this->assertSame(
                $json,
                $after[$table],
                "Table [{$table}] mutated unexpectedly when state was expected to be unchanged."
            );
        }
    }
}
