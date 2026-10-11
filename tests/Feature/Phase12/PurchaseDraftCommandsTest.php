<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\PurchaseDraftCommands;
use App\Models\AiCompanyPolicy;
use App\Models\AiPurchaseDraftReceipt;
use App\Models\AssistantRequest;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PurchaseDraftCommandsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Vendor $vendor;

    private Product $product;

    private ProductUnit $carton;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create();
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'شركة تجريبية', 'name_en' => 'Synthetic Purchase']);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);
        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, ['name_ar' => 'مورد تجريبي', 'name_en' => 'Synthetic Vendor']);
        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صابون', 'name_en' => 'Soap', 'sku' => 'SOAP', 'product_type' => Product::TYPE_STOCK,
            'track_stock' => true, 'track_expiry' => true,
            'base_unit_id' => Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail()->id,
            'default_purchase_cost_base' => '2.000000',
        ], $this->owner->id);
        $this->carton = app(ProductCatalogService::class)->addOrUpdateAlternateUnit($this->product,
            Unit::where('company_id', $this->company->id)->where('code', 'carton')->firstOrFail()->id, '12', false, true);
    }

    public function test_manual_explicit_save_without_ai_creates_only_one_draft_and_no_posting_effects(): void
    {
        config(['ai_assistant.enabled' => false]);
        $before = $this->effects();
        $key = (string) Str::uuid();
        $first = app(PurchaseDraftCommands::class)->create($this->company, $this->owner, $this->payload(), $key);
        $second = app(PurchaseDraftCommands::class)->create($this->company, $this->owner, $this->payload(), $key);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Purchase::count());
        $this->assertSame(1, AiPurchaseDraftReceipt::count());
        $this->assertSame('draft', $first->status);
        $this->assertSame('48.000000', $first->grand_total_currency);
        $this->assertSame($before, $this->effects());
        $this->assertSame(0, AssistantRequest::count());
    }

    public function test_canonical_decimal_replay_survives_master_name_changes_and_rejects_changed_economics(): void
    {
        $key = (string) Str::uuid();
        $first = app(PurchaseDraftCommands::class)->create($this->company, $this->owner, $this->payload(), $key);
        $this->vendor->update(['name_en' => 'Renamed after Save']);
        $payload = $this->payload();
        $payload['lines'][0]['quantity'] = '2.000000';
        $payload['lines'][0]['unit_cost'] = '24.000000';
        $payload['exchange_rate'] = '1.0000000000';
        $this->assertSame($first->id, app(PurchaseDraftCommands::class)->create($this->company, $this->owner, $payload, $key)->id);
        $payload['lines'][0]['unit_cost'] = '25';
        try {
            app(PurchaseDraftCommands::class)->create($this->company, $this->owner, $payload, $key);
            $this->fail('Changed payload must conflict, not create another draft.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(1, Purchase::count());
    }

    public function test_float_and_reserved_effect_fields_cannot_enter_the_draft_command(): void
    {
        foreach (['float', 'posting'] as $case) {
            $payload = $this->payload();
            if ($case === 'float') {
                $payload['lines'][0]['unit_cost'] = 24.0;
            } else {
                $payload['posting_batch_id'] = 1;
            }
            try {
                app(PurchaseDraftCommands::class)->create($this->company, $this->owner, $payload, (string) Str::uuid());
                $this->fail('Unsafe command input must refuse.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, Purchase::count());
                $this->assertSame(0, AiPurchaseDraftReceipt::count());
            }
        }
    }

    public function test_actor_downgrade_rechecks_cost_permission_even_on_receipt_replay(): void
    {
        $key = (string) Str::uuid();
        app(PurchaseDraftCommands::class)->create($this->company, $this->owner, $this->payload(), $key);
        $this->owner->syncRoles(Role::where('company_id', $this->company->id)->where('name', 'Viewer')->firstOrFail());
        try {
            app(PurchaseDraftCommands::class)->create($this->company, $this->owner, $this->payload(), $key);
            $this->fail('Role downgrade must not return protected purchase costs.');
        } catch (AuthorizationException) {
            $this->assertSame(1, Purchase::count());
        }
    }

    public function test_final_human_fields_are_atomically_recorded_with_purchase_receipt_and_encrypted_provenance(): void
    {
        $key = (string) Str::uuid();
        $source = $this->source($key);
        $payload = $this->payload();
        $payload['lines'][0]['unit_cost'] = '25.125';
        $purchase = app(PurchaseDraftCommands::class)->create($this->company, $this->owner, $payload, $key, $source->id);
        $source->refresh();
        $this->assertSame('accepted', $source->status);
        $this->assertSame($purchase->id, $source->purchase_id);
        $this->assertNull($source->sales_invoice_id);
        $this->assertSame('25.125', $source->approved_command['lines'][0]['unit_cost']);
        $this->assertSame('50.250000', $purchase->grand_total_currency);
        $this->assertArrayNotHasKey('approved_command', $source->toArray());
        $this->assertSame(1, AiPurchaseDraftReceipt::count());
    }

    public function test_provenance_failure_rolls_back_draft_lines_receipt_and_audit_atomically(): void
    {
        $key = (string) Str::uuid();
        $source = $this->source($key);
        $before = $this->effects();
        $auditCount = DB::table('audit_events')->count();
        $dispatcher = AssistantRequest::getEventDispatcher();
        AssistantRequest::setEventDispatcher(clone $dispatcher);
        AssistantRequest::updating(function (AssistantRequest $request): void {
            if ($request->status === 'accepted') {
                throw new RuntimeException('Synthetic provenance failure');
            }
        });
        try {
            try {
                app(PurchaseDraftCommands::class)->create($this->company, $this->owner, $this->payload(), $key, $source->id);
                $this->fail('Synthetic provenance failure must roll back the whole command.');
            } catch (RuntimeException $e) {
                $this->assertSame('Synthetic provenance failure', $e->getMessage());
                $this->assertSame(0, Purchase::count());
                $this->assertSame(0, DB::table('purchase_lines')->count());
                $this->assertSame(0, AiPurchaseDraftReceipt::count());
                $this->assertSame('form_ready', $source->fresh()->status);
                $this->assertSame($auditCount, DB::table('audit_events')->count());
                $this->assertSame($before, $this->effects());
            }
        } finally {
            AssistantRequest::setEventDispatcher($dispatcher);
        }
    }

    public function test_wrong_form_or_expired_source_cannot_be_recorded_as_accepted_ai(): void
    {
        $source = $this->source((string) Str::uuid());
        try {
            app(PurchaseDraftCommands::class)->create($this->company, $this->owner, $this->payload(), (string) Str::uuid(), $source->id);
            $this->fail('A different form must not adopt AI provenance.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
        }
        $source->forceFill(['created_at' => now()->subSeconds(601)])->save();
        try {
            app(PurchaseDraftCommands::class)->create($this->company, $this->owner, $this->payload(), $source->form_key, $source->id);
            $this->fail('Expired source must not be accepted.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
        }
        $this->assertSame(0, Purchase::count());
    }

    private function payload(): array
    {
        return ['vendor_id' => $this->vendor->id, 'warehouse_id' => Warehouse::where('company_id', $this->company->id)->firstOrFail()->id,
            'vendor_invoice_number' => 'SYNTHETIC-001', 'purchase_date' => '2026-10-11', 'due_date' => null,
            'currency_code' => 'ILS', 'exchange_rate' => '1', 'document_locale' => 'ar', 'notes' => null,
            'lines' => [['product_id' => $this->product->id, 'product_unit_id' => $this->carton->id,
                'quantity' => '2', 'unit_cost' => '24', 'lots' => []]]];
    }

    private function source(string $formKey): AssistantRequest
    {
        config(['ai_assistant.enabled' => true]);
        AiCompanyPolicy::create(['company_id' => $this->company->id, 'enabled' => true, 'revision' => 1, 'settings' => []]);

        return AssistantRequest::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id,
            'request_key' => (string) Str::uuid(), 'purpose' => 'intent', 'input_hash' => hash('sha256', 'synthetic'),
            'provider' => 'deterministic', 'status' => 'form_ready', 'reserved_cost_micros' => 0,
            'intent_kind' => 'purchase', 'policy_revision' => 1, 'form_key' => $formKey]);
    }

    private function effects(): array
    {
        $rows = [];
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'inventory_lots', 'inventory_balances', 'document_sequences'] as $table) {
            $rows[$table] = DB::table($table)->get()->toJson();
        }

        return $rows;
    }
}
