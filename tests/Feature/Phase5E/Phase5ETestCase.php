<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Models\Company;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

abstract class Phase5ETestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Vendor $vendor;

    protected Product $product;

    protected ProductUnit $unit;

    protected Warehouse $warehouse;

    protected MoneyAccount $ilsCashAccount;

    protected MoneyAccount $usdCashAccount;

    protected MoneyAccount $usdBankAccount;

    protected MoneyAccount $jodCashAccount;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();

        $this->owner = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة المشتريات والدفع للموردين',
            'name_en' => 'Purchasing & Vendor Payments Co',
            'base_currency_code' => 'ILS',
        ]);
        $this->activate($this->owner);

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد التوريدات الرئيسي',
            'name_en' => 'Main Vendor',
            'default_currency_code' => 'ILS',
        ]);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();

        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'سلعة غذائية',
            'name_en' => 'Food Item',
            'sku' => 'ITM-01',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => Unit::where('code', 'piece')->firstOrFail()->id,
        ], $this->owner->id);
        $this->unit = ProductUnit::where('product_id', $this->product->id)->firstOrFail();

        $moneyAccountAction = app(CreateMoneyAccountAction::class);

        // ILS Cash Account
        $this->ilsCashAccount = $moneyAccountAction->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'ILS',
            'name_ar' => 'صندوق الشيكل الرئيسي',
            'name_en' => 'Main ILS Cash Box',
        ]);

        // USD Cash Account
        $this->usdCashAccount = $moneyAccountAction->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'USD',
            'name_ar' => 'صندوق الدولار النقدي',
            'name_en' => 'USD Cash Box',
        ]);

        // USD Bank Account
        $this->usdBankAccount = $moneyAccountAction->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_BANK,
            'currency_code' => 'USD',
            'name_ar' => 'حساب بنك فلسطين بالدولار',
            'name_en' => 'Bank of Palestine USD',
        ]);

        // Enable JOD in company if not present
        if (! $this->company->currencies()->where('currency_code', 'JOD')->exists()) {
            $this->company->currencies()->create([
                'currency_code' => 'JOD',
                'is_active' => true,
            ]);
        }

        // JOD Cash Account
        $this->jodCashAccount = $moneyAccountAction->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'JOD',
            'name_ar' => 'صندوق الدينار النقدي',
            'name_en' => 'JOD Cash Box',
        ]);
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
            'purchase_date' => '2026-10-01',
            'due_date' => null,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
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

    protected function createAndPostReturn(Purchase $purchase, array $changes = []): PurchaseReturn
    {
        $purchase->loadMissing('lines');
        $firstLine = $purchase->lines->first();
        $return = app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, array_replace_recursive([
            'purchase_id' => $purchase->id,
            'return_date' => '2026-10-02',
            'reason' => 'Defective goods',
            'lines' => [
                [
                    'purchase_line_id' => $firstLine?->id,
                    'quantity' => '1',
                ],
            ],
        ], $changes));

        return app(PostPurchaseReturnAction::class)->execute($return, $this->owner);
    }

    protected function customActor(array $permissions, string $roleName = 'CustomRole'): User
    {
        $user = User::factory()->create(['locale' => 'ar']);
        $this->company->users()->attach($user->id, [
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        setPermissionsTeamId($this->company->id);
        $role = Role::firstOrCreate([
            'company_id' => $this->company->id,
            'name' => $roleName.'_'.$user->id,
            'guard_name' => 'web',
        ]);
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }
}
