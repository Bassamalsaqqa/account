<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Application\AI\DeterministicTransactionParser;
use App\Application\AI\TransactionProposalResolver;
use App\Application\AI\TransactionProposalSchema;
use App\Models\CompanyUser;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class TransactionProposalResolverTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
    }

    public function test_sale_resolves_exact_carton_identity_into_unsaved_fields_and_labels_defaults(): void
    {
        $text = 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';
        $result = $this->resolve($text);
        $this->assertSame([], $result['questions']);
        $this->assertSame($this->customer->id, $result['payload']['customer_id']);
        $this->assertSame($this->aiCarton->id, $result['payload']['lines'][0]['product_unit_id']);
        $this->assertSame('5', $result['payload']['lines'][0]['quantity']);
        $this->assertSame('80', $result['payload']['lines'][0]['unit_price']);
        $this->assertSame('canonical_suggestion', $result['origins']['exchange_rate']);
        $this->assertSame('company_default', $result['origins']['issue_date']);
        $this->assertSame('spoken', $result['origins']['lines.0.quantity']);
        $this->assertSame(0, SalesInvoice::count());
        $this->assertSame(0, Purchase::count());
        Http::assertNothingSent();
    }

    public function test_partial_second_line_preserves_first_line_and_asks_only_for_unresolved_product(): void
    {
        $text = 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS; 2 "piece" "UNKNOWN" at 3 ILS';
        $result = $this->resolve($text);
        $this->assertSame($this->aiProduct->id, $result['payload']['lines'][0]['product_id']);
        $this->assertSame('80', $result['payload']['lines'][0]['unit_price']);
        $this->assertNull($result['payload']['lines'][1]['product_id']);
        $this->assertSame('lines.1.product', $result['questions'][0]['path']);
        $this->assertSame([], $result['questions'][0]['candidates']);
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_ambiguous_party_is_bounded_and_explicit_choice_does_not_erase_resolved_lines(): void
    {
        $duplicate = $this->customer->replicate();
        $duplicate->code = 'OTHER-AHMED';
        $duplicate->public_id = (string) Str::ulid();
        $duplicate->save();
        $text = 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';
        $proposal = $this->proposal($text);
        $first = app(TransactionProposalResolver::class)->resolve($this->company, $this->owner, $proposal);
        $this->assertNull($first['payload']['customer_id']);
        $this->assertSame('party', $first['questions'][0]['path']);
        $this->assertCount(2, $first['questions'][0]['candidates']);
        $chosen = app(TransactionProposalResolver::class)->resolve($this->company, $this->owner, $proposal, ['party' => (int) $duplicate->id]);
        $this->assertSame($duplicate->id, $chosen['payload']['customer_id']);
        $this->assertSame([], $chosen['questions']);
        $this->assertSame($first['payload']['lines'], $chosen['payload']['lines']);
        $this->assertSame('resolved_choice', $chosen['origins']['customer_id']);
    }

    public function test_purchase_resolves_canonical_stock_unit_without_inventing_supplier_number_or_lots(): void
    {
        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, ['name_ar' => 'مورد', 'name_en' => 'Vendor']);
        $product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صابون مخزون', 'name_en' => 'Stock Soap', 'sku' => 'STOCK-SOAP',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => true,
            'base_unit_id' => $this->aiProduct->base_unit_id, 'default_purchase_cost_base' => '2',
        ], $this->owner->id);
        $unit = ProductUnit::where('company_id', $this->company->id)->where('product_id', $product->id)->where('is_base', true)->firstOrFail();
        $result = $this->resolve('buy from "Vendor": 2 "piece" "STOCK-SOAP" at 2.125 ILS');
        $this->assertSame([], $result['questions']);
        $this->assertSame($vendor->id, $result['payload']['vendor_id']);
        $this->assertSame($unit->id, $result['payload']['lines'][0]['product_unit_id']);
        $this->assertSame('2.125', $result['payload']['lines'][0]['unit_cost']);
        $this->assertNull($result['payload']['vendor_invoice_number']);
        $this->assertSame([], $result['payload']['lines'][0]['lots']);
        $this->assertContains('lines.0.lots', $result['unresolved']);
        $this->assertSame(0, Purchase::count());
        Http::assertNothingSent();
    }

    public function test_nonbase_currency_requires_fx_without_losing_resolved_identity_or_price(): void
    {
        $result = $this->resolve('sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 USD');
        $this->assertSame('USD', $result['payload']['currency_code']);
        $this->assertNull($result['payload']['exchange_rate']);
        $this->assertSame('exchange_rate', $result['questions'][0]['path']);
        $this->assertSame($this->aiProduct->id, $result['payload']['lines'][0]['product_id']);
        $this->assertSame('80', $result['payload']['lines'][0]['unit_price']);
    }

    public function test_purchase_cost_permission_is_rechecked_before_any_suggestion_is_exposed(): void
    {
        $viewer = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $viewer->id, 'status' => 'active', 'is_owner' => false]);
        $role = Role::where('company_id', $this->company->id)->where('name', 'Viewer')->firstOrFail();
        $role->givePermissionTo(['ai.assistant.use', 'ai.purchases.use', 'purchasing.purchase.create']);
        $viewer->assignRole($role);
        $this->actingAs($viewer);
        $this->expectException(AuthorizationException::class);
        $this->resolve('buy from "Vendor": 2 "piece" "SOAP-3L" at 80 ILS', $viewer);
    }

    private function proposal(string $text): array
    {
        return app(TransactionProposalSchema::class)->validate(app(DeterministicTransactionParser::class)->parse($text), $text, 'auto');
    }

    private function resolve(string $text, ?User $actor = null): array
    {
        return app(TransactionProposalResolver::class)->resolve($this->company, $actor ?? $this->owner, $this->proposal($text));
    }
}
