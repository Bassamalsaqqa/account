<?php

declare(strict_types=1);

namespace Tests\Feature\NaturalAssistant;

use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantReportEntityResolver;
use App\Application\AI\AssistantReportPlan;
use App\Application\AI\AssistantUnavailable;
use App\Models\AiCompanyPolicy;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class AssistantReportEntityResolverTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected ?Product $product = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
        $this->travelTo(now()->setDate(2026, 10, 12));
        config([
            'ai_assistant.enabled' => true,
            'ai_assistant.questions.enabled' => true,
            'ai_assistant.provider_approved' => false,
            'ai_assistant.provider' => 'none',
        ]);
        Http::preventStrayRequests();
        AiCompanyPolicy::create([
            'company_id' => $this->company->id,
            'enabled' => true,
            'settings' => [
                'questions' => [
                    'enabled' => true,
                    'allowed_reports' => AssistantReportCatalog::KEYS,
                ],
            ],
        ]);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $piece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->first();
        if ($piece === null) {
            $piece = Unit::create([
                'company_id' => $this->company->id,
                'code' => 'piece',
                'name_ar' => 'قطعة',
                'name_en' => 'Piece',
                'allows_fraction' => false,
                'decimal_places' => 0,
                'active' => true,
            ]);
        }

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'PROD-001',
            'name_ar' => 'منتج تجريبي',
            'name_en' => 'Test Product',
            'base_unit_id' => $piece->id,
            'product_type' => 'non_stock',
            'track_stock' => false,
            'default_sale_price_base' => '10.000000',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
    }

    public function test_resolves_exact_customer_by_name_and_code(): void
    {
        $plan = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => 'sales.by-customer',
            'filters' => ['preset' => 'this_month', 'customer_name' => $this->customer->name_ar],
            'display' => 'summary',
        ]);

        $resolved = AssistantReportEntityResolver::resolve($this->company, $this->owner, $plan);

        $this->assertSame((int) $this->customer->id, $resolved['customer_id']);
        $this->assertSame(1, $resolved['page']);
        $this->assertSame(5, $resolved['per_page']);
        $this->assertArrayNotHasKey('customer_name', $resolved);
        $this->assertArrayNotHasKey('compare', $resolved);
    }

    public function test_resolves_exact_vendor_by_name_and_code(): void
    {
        $vendor = Vendor::create([
            'company_id' => $this->company->id,
            'code' => 'VEND-001',
            'name_ar' => 'مورد التقنية',
            'name_en' => 'Tech Vendor',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        $plan = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => 'purchases.by-vendor',
            'filters' => ['preset' => 'this_month', 'vendor_name' => 'Tech Vendor'],
            'display' => 'summary',
        ]);

        $resolved = AssistantReportEntityResolver::resolve($this->company, $this->owner, $plan);

        $this->assertSame((int) $vendor->id, $resolved['vendor_id']);
    }

    public function test_resolves_product_by_sku_and_name(): void
    {
        $plan = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => 'sales.by-product',
            'filters' => ['preset' => 'this_month', 'product_name' => $this->product->sku],
            'display' => 'summary',
        ]);

        $resolved = AssistantReportEntityResolver::resolve($this->company, $this->owner, $plan);

        $this->assertSame((int) $this->product->id, $resolved['product_id']);
    }

    public function test_resolves_warehouse_by_name(): void
    {
        $warehouse = Warehouse::where('company_id', $this->company->id)->where('is_default', true)->first();
        if ($warehouse !== null) {
            $warehouse->update([
                'name_ar' => 'المستودع الرئيسي',
                'name_en' => 'Main Warehouse',
            ]);
        } else {
            $warehouse = Warehouse::create([
                'company_id' => $this->company->id,
                'code' => 'WH-SYNTH-01',
                'name_ar' => 'المستودع الرئيسي',
                'name_en' => 'Main Warehouse',
                'is_default' => false,
                'active' => true,
            ]);
        }

        $plan = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => 'inventory.stock',
            'filters' => ['preset' => 'this_month', 'warehouse_name' => 'المستودع الرئيسي'],
            'display' => 'summary',
        ]);

        $resolved = AssistantReportEntityResolver::resolve($this->company, $this->owner, $plan);

        $this->assertSame((int) $warehouse->id, $resolved['warehouse_id']);
    }

    public function test_resolves_category_according_to_domain(): void
    {
        // 1. Product category for sales/inventory
        $prodCat = ProductCategory::create([
            'company_id' => $this->company->id,
            'name_ar' => 'إلكترونيات',
            'name_en' => 'Electronics',
            'active' => true,
        ]);

        $planProd = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => 'sales.by-product',
            'filters' => ['preset' => 'this_month', 'category_name' => 'Electronics'],
            'display' => 'summary',
        ]);

        $resolvedProd = AssistantReportEntityResolver::resolve($this->company, $this->owner, $planProd);
        $this->assertSame((int) $prodCat->id, $resolvedProd['category_id']);

        // 2. Expense category for expenses
        $expCat = ExpenseCategory::create([
            'company_id' => $this->company->id,
            'code' => 'QA-FUEL',
            'ledger_account_id' => LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'expense_fuel')->sole()->id,
            'created_by' => $this->owner->id,
            'name_ar' => 'وقود',
            'name_en' => 'Synthetic Fuel Category',
            'active' => true,
        ]);

        $planExp = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => 'expenses.by-category',
            'filters' => ['preset' => 'this_month', 'category_name' => 'Synthetic Fuel Category'],
            'display' => 'summary',
        ]);

        $resolvedExp = AssistantReportEntityResolver::resolve($this->company, $this->owner, $planExp);
        $this->assertSame((int) $expCat->id, $resolvedExp['category_id']);
    }

    public function test_foreign_company_entities_are_never_resolved(): void
    {
        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create();
        CompanyUser::create(['company_id' => $otherCompany->id, 'user_id' => $otherUser->id, 'status' => 'active']);
        $this->actingAs($otherUser);
        app(CompanyContext::class)->setCompany($otherCompany, $otherUser);

        $foreignCustomer = Customer::create([
            'company_id' => $otherCompany->id,
            'code' => 'CUST-FOREIGN',
            'name_ar' => 'عميل شركة أجنبية',
            'name_en' => 'Foreign Company Customer',
            'status' => 'active',
            'created_by' => $otherUser->id,
        ]);

        // Restore actor and company context before query
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        $plan = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => 'sales.by-customer',
            'filters' => ['preset' => 'this_month', 'customer_name' => 'Foreign Company Customer'],
            'display' => 'summary',
        ]);

        $this->expectException(AssistantUnavailable::class);
        try {
            AssistantReportEntityResolver::resolve($this->company, $this->owner, $plan);
        } catch (AssistantUnavailable $e) {
            $this->assertSame('entity_missing', $e->reason);
            throw $e;
        }
    }

    public function test_ambiguous_entity_matches_throw_entity_ambiguous(): void
    {
        // Two customers in the same company with the same name
        Customer::create([
            'company_id' => $this->company->id,
            'code' => 'CUST-002',
            'name_ar' => 'عميل مكرر',
            'name_en' => 'Duplicate Customer',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);
        Customer::create([
            'company_id' => $this->company->id,
            'code' => 'CUST-003',
            'name_ar' => 'عميل مكرر',
            'name_en' => 'Duplicate Customer',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        $plan = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => 'sales.by-customer',
            'filters' => ['preset' => 'this_month', 'customer_name' => 'Duplicate Customer'],
            'display' => 'summary',
        ]);

        $this->expectException(AssistantUnavailable::class);
        try {
            AssistantReportEntityResolver::resolve($this->company, $this->owner, $plan);
        } catch (AssistantUnavailable $e) {
            $this->assertSame('entity_ambiguous', $e->reason);
            throw $e;
        }
    }

    public function test_missing_entity_throws_entity_missing(): void
    {
        $plan = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => 'sales.by-customer',
            'filters' => ['preset' => 'this_month', 'customer_name' => 'Non Existent Customer 999'],
            'display' => 'summary',
        ]);

        $this->expectException(AssistantUnavailable::class);
        try {
            AssistantReportEntityResolver::resolve($this->company, $this->owner, $plan);
        } catch (AssistantUnavailable $e) {
            $this->assertSame('entity_missing', $e->reason);
            throw $e;
        }
    }

    public function test_compare_parameter_is_stripped_from_canonical_filters(): void
    {
        $plan = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => 'sales.summary',
            'filters' => ['preset' => 'this_month', 'compare' => 'previous_period'],
            'display' => 'summary',
        ]);

        $resolved = AssistantReportEntityResolver::resolve($this->company, $this->owner, $plan);

        $this->assertArrayNotHasKey('compare', $resolved);
        $this->assertSame('this_month', $resolved['period']->preset);
        $this->assertSame(1, $resolved['page']);
        $this->assertSame(5, $resolved['per_page']);
    }
}
