<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Application\AI\SalesAssistant;
use App\Application\AI\SalesProposalResolver;
use App\Application\Commands\SalesInvoiceCommands;
use App\Models\CompanyCurrency;
use App\Models\InventoryBalance;
use App\Models\PostingBatch;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Vendor;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Phase12\AssistantFixtures;
use Tests\TestCase;

class AssistantEconomicTest extends TestCase
{
    use AssistantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assistantFixtures();
    }

    public function test_exact_jod_tax_discount_and_fx_are_calculated_only_by_canonical_draft_command(): void
    {
        CompanyCurrency::updateOrCreate(['company_id' => $this->company->id, 'currency_code' => 'JOD'], ['enabled' => true]);
        $tax = TaxRate::create(['company_id' => $this->company->id, 'code' => 'VAT-AI', 'name_ar' => 'ضريبة', 'name_en' => 'AI tax', 'rate' => '16', 'calculation' => 'exclusive', 'active' => true]);
        $this->proposal['currency'] = 'دينار';
        $this->proposal['exchange_rate'] = '5.0';
        $this->proposal['lines'][0] = ['product' => 'SOAP-3L', 'unit' => 'piece', 'quantity' => '3', 'unit_price' => '1.125', 'discount_type' => 'percent', 'discount_value' => '10', 'tax' => $tax->name_en];
        $resolved = app(SalesProposalResolver::class)->resolve($this->company, $this->owner, $this->proposal);
        $this->assertNotNull($resolved['payload']);
        $invoice = app(SalesInvoiceCommands::class)->create($this->company, $this->owner, $resolved['payload'], (string) Str::uuid());
        $this->assertSame('3.375000', (string) $invoice->subtotal_currency);
        $this->assertSame('0.338000', (string) $invoice->discount_total_currency);
        $this->assertSame('0.486000', (string) $invoice->tax_total_currency);
        $this->assertSame('3.523000', (string) $invoice->grand_total_currency);
        $this->assertSame('17.615000', (string) $invoice->grand_total_base);
        $this->assertSame(0, PostingBatch::count());
    }

    public function test_stock_carton_draft_preview_and_confirm_use_canonical_fefo_and_reconcile(): void
    {
        $this->seedLots();
        $this->proposal['lines'][0]['quantity'] = '2';
        $this->fakeIntent($this->proposal);
        $assistant = app(SalesAssistant::class);
        $reply = $assistant->interpret($this->company, $this->owner, 'Two cartons', (string) Str::uuid(), true);
        $applied = $assistant->apply($this->company, $this->owner, $reply['token']);
        $commands = app(SalesInvoiceCommands::class);
        $beforeStock = StockMovement::count();
        $beforeBatches = PostingBatch::count();
        $key = (string) Str::uuid();
        $invoice = DB::transaction(function () use ($commands, $assistant, $applied, $key) {
            $invoice = $commands->create($this->company, $this->owner, $applied['payload'], $key);
            $assistant->recordDraft($this->company, $this->owner, $applied['request_id'], $invoice, $applied['payload'], $key);

            return $invoice;
        });
        $preview = $commands->preview($invoice, $this->owner);
        $this->assertTrue($preview['can_execute']);
        $this->assertSame($beforeStock, StockMovement::count());
        $this->assertSame($beforeBatches, PostingBatch::count());
        $posted = $commands->execute($invoice, $this->owner, $preview['confirmation_token'], (string) Str::uuid());
        $this->assertTrue($posted->isPosted());
        $this->assertSame('16.000000', (string) InventoryBalance::where('product_id', $this->aiProduct->id)->sum('quantity_base'));
        $allocated = $posted->lotAllocations()->with('lot')->get();
        $this->assertSame(['AI-EARLY'], $allocated->map(fn ($allocation) => $allocation->lot->lot_number)->all());
        foreach (['accounting:reconcile', 'inventory:reconcile', 'sales:reconcile', 'money:reconcile', 'phase7:reconcile'] as $command) {
            $this->assertSame(0, Artisan::call($command, ['companyPublicId' => $this->company->public_id]), Artisan::output());
        }
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_changed_stock_blocks_old_confirmation_and_rolls_back_all_economic_effects(): void
    {
        $this->seedLots();
        $this->proposal['lines'][0]['quantity'] = '2';
        $resolved = app(SalesProposalResolver::class)->resolve($this->company, $this->owner, $this->proposal);
        $commands = app(SalesInvoiceCommands::class);
        $first = $commands->create($this->company, $this->owner, $resolved['payload'], (string) Str::uuid());
        $oldPreview = $commands->preview($first, $this->owner);
        $second = $commands->create($this->company, $this->owner, $resolved['payload'], (string) Str::uuid());
        $secondPreview = $commands->preview($second, $this->owner);
        $commands->execute($second, $this->owner, $secondPreview['confirmation_token'], (string) Str::uuid());
        $before = [PostingBatch::count(), StockMovement::count()];
        try {
            $commands->execute($first, $this->owner, $oldPreview['confirmation_token'], (string) Str::uuid());
            $this->fail('Stock changes must refuse stale confirmation.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertTrue($first->fresh()->isDraft());
        $this->assertSame($before, [PostingBatch::count(), StockMovement::count()]);
    }

    private function seedLots(): void
    {
        $this->aiProduct->update(['product_type' => 'stock', 'track_stock' => true, 'track_expiry' => true]);
        $vendor = Vendor::create(['company_id' => $this->company->id, 'code' => 'AI-VENDOR', 'name_ar' => 'مورد', 'name_en' => 'AI fixture vendor', 'status' => 'active', 'created_by' => $this->owner->id]);
        $unit = $this->aiProduct->productUnits()->where('is_base', true)->firstOrFail();
        $unit->update(['is_default_purchase' => true]);
        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $vendor->id, 'warehouse_id' => $this->aiWarehouse->id, 'purchase_date' => now()->toDateString(),
            'currency_code' => 'ILS', 'exchange_rate' => '1', 'lines' => [[
                'product_id' => $this->aiProduct->id, 'product_unit_id' => $unit->id, 'quantity' => '40', 'unit_cost' => '2',
                'lots' => [
                    ['product_unit_id' => $unit->id, 'quantity' => '24', 'lot_number' => 'AI-EARLY', 'expiry_date' => now()->addMonth()->toDateString()],
                    ['product_unit_id' => $unit->id, 'quantity' => '16', 'lot_number' => 'AI-LATE', 'expiry_date' => now()->addMonths(2)->toDateString()],
                ],
            ]],
        ]);
        app(PostPurchaseAction::class)->execute($purchase, $this->owner);
    }

    public function test_colloquial_dates_use_company_timezone_at_the_utc_day_boundary(): void
    {
        $this->company->update(['timezone' => 'Asia/Hebron']);
        $this->travelTo(Carbon::parse('2026-10-11 23:30:00', 'UTC'));
        try {
            $this->proposal['issue_date'] = 'اليوم';
            $this->proposal['due_date'] = 'today';
            $resolved = app(SalesProposalResolver::class)->resolve($this->company, $this->owner, $this->proposal);
            $this->assertSame('2026-10-12', $resolved['payload']['issue_date']);
            $this->assertSame('2026-10-12', $resolved['payload']['due_date']);
            $this->proposal['issue_date'] = 'امبارح';
            $resolved = app(SalesProposalResolver::class)->resolve($this->company, $this->owner, $this->proposal);
            $this->assertSame('2026-10-11', $resolved['payload']['issue_date']);
        } finally {
            $this->travelBack();
        }
    }
}
