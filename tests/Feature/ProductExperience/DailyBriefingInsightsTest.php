<?php

declare(strict_types=1);

namespace Tests\Feature\ProductExperience;

use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantDailyBriefing;
use App\Application\AI\AssistantReportExecutor;
use App\Application\AI\AssistantUnavailable;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Livewire\AI\AssistantLauncher;
use App\Models\CompanyUser;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

final class DailyBriefingInsightsTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00'));
        $this->finalAiFixtures();
        Http::preventStrayRequests();

        config([
            'ai_assistant.enabled' => true,
            'ai_assistant.questions.enabled' => true,
            'ai_assistant.daily_briefing_enabled' => true,
        ]);

        $settings = $this->aiPolicy->settings;
        $settings['questions'] = [
            'enabled' => true,
            'allowed_reports' => AssistantDailyBriefing::CARD_KEYS,
        ];
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => true,
            'personal_credentials_enabled' => false,
            'follow_up_enabled' => true,
            'workflow_mode' => 'seamless',
            'speech_method' => 'disabled',
            'settings' => $settings,
        ], $this->aiPolicy->revision);

        $governance = app(AiGovernance::class);
        $governance->recordConsent(
            $this->company,
            $this->owner,
            $this->textConnection->public_id,
            $governance->consentFingerprint($this->aiPolicy, $this->textConnection, 'question'),
            'question'
        );
    }

    public function test_sales_comparison_omitted_when_not_in_company_allowlist(): void
    {
        $settings = $this->aiPolicy->settings;
        $settings['questions']['allowed_reports'] = array_values(array_diff(AssistantDailyBriefing::CARD_KEYS, ['sales.summary']));
        $this->aiPolicy->update(['settings' => $settings]);
        // Existing restrictive policy must not gain comparison capability.
        $briefingService = app(AssistantDailyBriefing::class);
        $briefing = $briefingService->generate($this->company, $this->owner, 'omission-session');

        self::assertArrayHasKey('insights', $briefing);
        $kinds = array_column($briefing['insights'], 'kind');

        // sales_comparison MUST be omitted: no permission broadening or silent activation
        self::assertNotContains('sales_comparison', $kinds);
    }

    public function test_sales_comparison_omitted_when_user_lacks_permission(): void
    {
        // Allow sales.summary in company policy
        $settings = $this->aiPolicy->settings;
        $settings['questions'] = [
            'enabled' => true,
            'allowed_reports' => AssistantDailyBriefing::CARD_KEYS,
        ];
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => true,
            'personal_credentials_enabled' => false,
            'follow_up_enabled' => true,
            'workflow_mode' => 'seamless',
            'speech_method' => 'disabled',
            'settings' => $settings,
        ], $this->aiPolicy->revision);

        // Create reader without sales permissions
        $reader = User::factory()->create(['locale' => 'en']);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $reader->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        $role = Role::create([
            'company_id' => $this->company->id,
            'name' => 'Inventory Only Reader',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo(['ai.assistant.use', 'inventory.stock.view', 'reports.inventory.view']);
        $reader->assignRole($role);

        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);

        $briefingService = app(AssistantDailyBriefing::class);
        $briefing = $briefingService->generate($this->company, $reader, 'reader-omission-session');

        $kinds = array_column($briefing['insights'], 'kind');
        self::assertNotContains('sales_comparison', $kinds);
        self::assertNotContains('sales_unpaid', $kinds);

        // Restore context
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
    }

    public function test_sales_comparison_computes_exact_decimal_differences_and_trend(): void
    {
        // 1. Enable sales.summary in allowed_reports
        $settings = $this->aiPolicy->settings;
        $settings['questions'] = [
            'enabled' => true,
            'allowed_reports' => AssistantDailyBriefing::CARD_KEYS,
        ];
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => true,
            'personal_credentials_enabled' => false,
            'follow_up_enabled' => true,
            'workflow_mode' => 'seamless',
            'speech_method' => 'disabled',
            'settings' => $settings,
        ], $this->aiPolicy->revision);

        // 2. Post sales invoice in previous month (September 2026) -> net sales 300.000000
        $previousDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-09-15',
            'due_date' => null,
            'lines' => [
                ['item_description' => 'Sept Sales', 'quantity' => '6', 'unit_price' => '50.000000'],
            ],
        ]);
        app(PostSalesInvoiceAction::class)->execute($previousDraft, $this->owner);

        // 3. Post sales invoice in current month (October 2026) -> net sales 500.000000
        $currentDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-10-10',
            'due_date' => null,
            'lines' => [
                ['item_description' => 'Oct Sales', 'quantity' => '10', 'unit_price' => '50.000000'],
            ],
        ]);
        app(PostSalesInvoiceAction::class)->execute($currentDraft, $this->owner);

        $briefingService = app(AssistantDailyBriefing::class);
        $briefing = $briefingService->generate($this->company, $this->owner, 'comparison-session');

        self::assertArrayHasKey('insights', $briefing);
        $insightsByKind = [];
        foreach ($briefing['insights'] as $insight) {
            $insightsByKind[$insight['kind']] = $insight;
        }

        self::assertArrayHasKey('sales_comparison', $insightsByKind);
        $salesInsight = $insightsByKind['sales_comparison'];

        self::assertSame('sales_comparison', $salesInsight['kind']);
        self::assertSame('assistant_briefing.insights.sales_comparison', $salesInsight['label_key']);
        self::assertSame('ILS', $salesInsight['currency']);
        self::assertNotEmpty($salesInsight['source_url']);
        self::assertNotEmpty($salesInsight['previous_source_url']);
        self::assertNotSame($salesInsight['source_url'], $salesInsight['previous_source_url']);

        $values = $salesInsight['values'];
        self::assertSame('500.000000', $values['current_net_sales']);
        self::assertSame('300.000000', $values['previous_net_sales']);
        self::assertSame('200.000000', $values['difference']);
        self::assertSame('up', $values['trend']);
        self::assertSame('1', $values['current_invoice_count']);
        self::assertSame('1', $values['previous_invoice_count']);

        // Strict invariant: all values are strings; no PHP floats
        foreach ($values as $k => $v) {
            self::assertIsString($v, "Insight value [{$k}] must be a string, float prohibited.");
        }
    }

    public function test_sales_comparison_downward_trend_with_exact_decimals(): void
    {
        $settings = $this->aiPolicy->settings;
        $settings['questions'] = [
            'enabled' => true,
            'allowed_reports' => AssistantDailyBriefing::CARD_KEYS,
        ];
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => true,
            'personal_credentials_enabled' => false,
            'follow_up_enabled' => true,
            'workflow_mode' => 'seamless',
            'speech_method' => 'disabled',
            'settings' => $settings,
        ], $this->aiPolicy->revision);

        // Previous month higher sales: 800.000000
        $previousDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-09-20',
            'due_date' => null,
            'lines' => [
                ['item_description' => 'High Sept Sales', 'quantity' => '16', 'unit_price' => '50.000000'],
            ],
        ]);
        app(PostSalesInvoiceAction::class)->execute($previousDraft, $this->owner);

        // Current month lower sales: 250.000000
        $currentDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-10-05',
            'due_date' => null,
            'lines' => [
                ['item_description' => 'Low Oct Sales', 'quantity' => '5', 'unit_price' => '50.000000'],
            ],
        ]);
        app(PostSalesInvoiceAction::class)->execute($currentDraft, $this->owner);

        $briefingService = app(AssistantDailyBriefing::class);
        $briefing = $briefingService->generate($this->company, $this->owner, 'downward-trend-session');

        $insightsByKind = array_column($briefing['insights'], null, 'kind');
        self::assertArrayHasKey('sales_comparison', $insightsByKind);

        $values = $insightsByKind['sales_comparison']['values'];
        self::assertSame('250.000000', $values['current_net_sales']);
        self::assertSame('800.000000', $values['previous_net_sales']);
        self::assertSame('-550.000000', $values['difference']);
        self::assertSame('down', $values['trend']);
    }

    public function test_insights_actionable_links_and_bounded_values_for_unpaid_stock_expiry(): void
    {
        // 1. Sales unpaid
        $salesDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-10-10',
            'due_date' => null,
            'lines' => [
                ['item_description' => 'Soap Cartons', 'quantity' => '10', 'unit_price' => '50.000000'],
            ],
        ]);
        app(PostSalesInvoiceAction::class)->execute($salesDraft, $this->owner);

        // 2. Purchases unpaid
        $this->aiProduct->update(['product_type' => 'stock', 'track_stock' => true]);
        $this->aiCarton->update(['is_default_purchase' => true]);
        $vendor = Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مورد الاختبار',
            'name_en' => 'Test Vendor',
            'preferred_locale' => 'en',
            'created_by' => $this->owner->id,
        ]);
        $purchaseDraft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $this->aiWarehouse->id,
            'purchase_date' => '2026-10-08',
            'due_date' => '2026-10-28',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $this->aiProduct->id,
                    'quantity' => '5.000000',
                    'unit_cost' => '30.000000',
                ],
            ],
        ]);
        app(PostPurchaseAction::class)->execute($purchaseDraft, $this->owner);

        // 3. Low stock product (threshold 10, stock 0)
        $lowStockProduct = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'INSIGHT-LOW-01',
            'name_ar' => 'صنف منخفض',
            'name_en' => 'Low Stock Product',
            'base_unit_id' => $this->aiProduct->base_unit_id,
            'product_type' => 'stock',
            'track_stock' => true,
            'minimum_stock_base' => '10.000000',
            'default_sale_price_base' => '20.000000',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        // 4. Expiry product
        $expiryProduct = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'INSIGHT-EXP-01',
            'name_ar' => 'صنف صلاحية',
            'name_en' => 'Expiry Tracked Product',
            'base_unit_id' => $this->aiProduct->base_unit_id,
            'product_type' => 'stock',
            'track_stock' => true,
            'track_expiry' => true,
            'default_sale_price_base' => '40.000000',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
        foreach ([$lowStockProduct, $expiryProduct] as $product) {
            ProductUnit::create([
                'company_id' => $this->company->id, 'product_id' => $product->id,
                'unit_id' => $product->base_unit_id, 'conversion_to_base' => '1.000000',
                'is_base' => true, 'is_default_purchase' => true, 'active' => true,
            ]);
        }
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $expiryProduct,
            warehouse: $this->aiWarehouse,
            quantity: Quantity::of('8.000000'),
            unitCostBase: '20.000000',
            user: $this->owner,
            idempotencyKey: 'insight-expiry-lot-1',
            lotNumber: 'LOT-INSIGHT-2026',
            expiryDate: '2026-10-25',
            movementDate: '2026-10-01',
        );

        $briefingService = app(AssistantDailyBriefing::class);
        $briefing = $briefingService->generate($this->company, $this->owner, 'actionable-insights-session');

        $insightsByKind = array_column($briefing['insights'], null, 'kind');

        // Check sales_unpaid
        self::assertArrayHasKey('sales_unpaid', $insightsByKind);
        $salesUnpaid = $insightsByKind['sales_unpaid'];
        self::assertSame('assistant_briefing.insights.sales_unpaid', $salesUnpaid['label_key']);
        self::assertNull($salesUnpaid['currency']);
        self::assertNull($salesUnpaid['previous_source_url']);
        self::assertStringContainsString('reports/sales.unpaid', $salesUnpaid['source_url']);
        self::assertGreaterThanOrEqual(1, (int) $salesUnpaid['values']['unpaid_count']);
        self::assertSame('500.000000', $salesUnpaid['amounts']['ILS']);

        // Check purchases_unpaid
        self::assertArrayHasKey('purchases_unpaid', $insightsByKind);
        $purchasesUnpaid = $insightsByKind['purchases_unpaid'];
        self::assertSame('assistant_briefing.insights.purchases_unpaid', $purchasesUnpaid['label_key']);
        self::assertNull($purchasesUnpaid['currency']);
        self::assertNull($purchasesUnpaid['previous_source_url']);
        self::assertStringContainsString('reports/purchases.unpaid', $purchasesUnpaid['source_url']);
        self::assertGreaterThanOrEqual(1, (int) $purchasesUnpaid['values']['unpaid_count']);
        self::assertSame('150.000000', $purchasesUnpaid['amounts']['ILS']);

        // Check low_stock
        self::assertArrayHasKey('low_stock', $insightsByKind);
        $lowStock = $insightsByKind['low_stock'];
        self::assertSame('assistant_briefing.insights.low_stock', $lowStock['label_key']);
        self::assertNull($lowStock['currency']);
        self::assertNull($lowStock['previous_source_url']);
        self::assertStringContainsString('reports/inventory.low-stock', $lowStock['source_url']);
        self::assertGreaterThanOrEqual(1, (int) $lowStock['values']['total_alert_count']);

        // Check expiry
        self::assertArrayHasKey('expiry', $insightsByKind);
        $expiry = $insightsByKind['expiry'];
        self::assertSame('assistant_briefing.insights.expiry', $expiry['label_key']);
        self::assertNull($expiry['currency']);
        self::assertNull($expiry['previous_source_url']);
        self::assertStringContainsString('reports/inventory.expiry', $expiry['source_url']);
        self::assertGreaterThanOrEqual(1, (int) $expiry['values']['total_active_lots']);

        // Verify aggregate size bounded
        $encoded = json_encode($briefing, JSON_THROW_ON_ERROR);
        self::assertLessThanOrEqual(AssistantReportExecutor::MAX_OUTPUT_BYTES, strlen($encoded));
    }

    public function test_mid_query_permission_revocation_fails_proposal_stale_for_insights(): void
    {
        // Allow sales.summary in policy
        $settings = $this->aiPolicy->settings;
        $settings['questions'] = [
            'enabled' => true,
            'allowed_reports' => AssistantDailyBriefing::CARD_KEYS,
        ];
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => true,
            'personal_credentials_enabled' => false,
            'follow_up_enabled' => true,
            'workflow_mode' => 'seamless',
            'speech_method' => 'disabled',
            'settings' => $settings,
        ], $this->aiPolicy->revision);

        $reader = User::factory()->create(['locale' => 'en']);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $reader->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        $role = Role::create([
            'company_id' => $this->company->id,
            'name' => 'Revocable Reader for Insights',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo([
            'ai.assistant.use',
            'reports.sales.view',
            'sales.invoice.view',
        ]);
        $reader->assignRole($role);

        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);

        $revoked = false;
        DB::listen(function ($query) use ($role, &$revoked) {
            if (! $revoked && str_contains($query->sql, 'from `sales_invoices`')) {
                $revoked = true;
                $role->revokePermissionTo('reports.sales.view');
            }
        });

        $briefingService = app(AssistantDailyBriefing::class);

        try {
            $briefingService->generate($this->company, $reader, 'mid-query-insights-session');
            self::fail('A revoked read must throw and not return stale or unauthorized insight data.');
        } catch (AssistantUnavailable $e) {
            self::assertSame('proposal_stale', $e->diagnosticReason());
        } catch (AuthorizationException) {
            self::assertTrue($revoked);
        } finally {
            $this->actingAs($this->owner);
            app(CompanyContext::class)->setCompany($this->company, $this->owner);
        }
    }

    public function test_browser_privacy_policy_truthful_copy(): void
    {
        $enPolicy = __('ai_product.browser_privacy_policy', [], 'en');
        $arPolicy = __('ai_product.browser_privacy_policy', [], 'ar');

        // English assertions
        self::assertStringContainsString('native browser speech', $enPolicy);
        self::assertStringContainsString('uses no paid AI', $enPolicy);
        self::assertStringContainsString('Recognized text is editable', $enPolicy);
        self::assertStringContainsString('cancellable grace period', $enPolicy);

        // Arabic assertions
        self::assertStringContainsString('التعرف الصوتي', $arPolicy);
        self::assertStringContainsString('لا يستهلك ذكاءً اصطناعياً مدفوعاً', $arPolicy);
        self::assertStringContainsString('قابلاً للتعديل', $arPolicy);
        self::assertStringContainsString('مهلة قابلة للإلغاء', $arPolicy);
    }

    public function test_mixed_usd_jod_outstanding_amounts_remain_separate_without_invented_base_currency(): void
    {
        $this->aiProduct->update(['product_type' => 'stock', 'track_stock' => true]);
        $this->aiCarton->update(['is_default_purchase' => true]);
        $vendor = Vendor::create(['company_id' => $this->company->id, 'name_ar' => 'مورد العملات', 'name_en' => 'Currency vendor', 'created_by' => $this->owner->id]);
        foreach ([['USD', '10.250000', '5.500000', '3.500000'], ['JOD', '3.125000', '2.125000', '5.000000']] as [$currency, $price, $cost, $rate]) {
            $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $this->customer->id, 'currency_code' => $currency, 'exchange_rate' => $rate,
                'issue_date' => '2026-10-10', 'due_date' => null,
                'lines' => [['item_description' => 'Synthetic service', 'quantity' => '1', 'unit_price' => $price]],
            ]);
            app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);
            $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
                'vendor_id' => $vendor->id, 'warehouse_id' => $this->aiWarehouse->id,
                'purchase_date' => '2026-10-08', 'due_date' => null, 'currency_code' => $currency,
                'exchange_rate' => $rate, 'document_locale' => 'ar',
                'lines' => [['product_id' => $this->aiProduct->id, 'quantity' => '1', 'unit_cost' => $cost]],
            ]);
            app(PostPurchaseAction::class)->execute($purchase, $this->owner);
        }
        $briefing = app(AssistantDailyBriefing::class)->generate($this->company, $this->owner, 'mixed-currency-session');
        $insights = array_column($briefing['insights'], null, 'kind');
        $this->assertSame(['JOD' => '3.125000', 'USD' => '10.250000'], $insights['sales_unpaid']['amounts']);
        $this->assertSame(['JOD' => '2.125000', 'USD' => '5.500000'], $insights['purchases_unpaid']['amounts']);
        foreach (['sales_unpaid', 'purchases_unpaid'] as $kind) {
            $this->assertNull($insights[$kind]['currency']);
            $this->assertArrayNotHasKey('ILS', $insights[$kind]['amounts']);
            $this->assertArrayNotHasKey('outstanding_amount', $insights[$kind]['values']);
            $this->assertSame('2', $insights[$kind]['values']['unpaid_count']);
        }
        Http::assertNothingSent();
    }

    public function test_insight_only_policy_is_discoverable_and_revoked_values_are_removed_from_livewire_payload(): void
    {
        $settings = $this->aiPolicy->settings;
        $settings['questions']['allowed_reports'] = ['sales.summary'];
        $this->aiPolicy->update(['settings' => $settings]);
        $this->assertTrue(app(AssistantDailyBriefing::class)->isAvailable($this->company, $this->owner));
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant')->call('loadDailyBriefing')
            ->assertSet('isBriefing', true)->assertSet('turnReports', [])->assertCount('briefingInsights', 1);
        $role = $this->owner->roles()->where('name', 'Owner')->firstOrFail();
        $role->revokePermissionTo('reports.sales.view');
        $component->call('$refresh')->assertSet('briefingInsights', [])->assertSet('turnReports', []);
        Http::assertNothingSent();
    }
}
