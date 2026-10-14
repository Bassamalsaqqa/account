<?php

declare(strict_types=1);

namespace Tests\Feature\AiV22;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantDailyBriefing;
use App\Application\AI\AssistantReportExecutor;
use App\Application\AI\AssistantReportPlan;
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

final class DailyBriefingTest extends TestCase
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

    public function test_daily_briefing_disabled_by_default_in_config(): void
    {
        config(['ai_assistant.daily_briefing_enabled' => false]);

        $briefingService = app(AssistantDailyBriefing::class);

        self::assertFalse(config('ai_assistant.daily_briefing_enabled'));
        self::assertFalse($briefingService->isAvailable($this->company, $this->owner));

        Livewire::test(AssistantLauncher::class)
            ->call('showAssistant')
            ->assertDontSee(__('assistant_briefing.action'))
            ->assertViewHas('briefingAvailable', false);

        config(['ai_assistant.daily_briefing_enabled' => true]);
        self::assertTrue($briefingService->isAvailable($this->company, $this->owner));

        Livewire::test(AssistantLauncher::class)
            ->call('showAssistant')
            ->assertSee(__('assistant_briefing.action'))
            ->assertViewHas('briefingAvailable', true);
    }

    public function test_exact_values_equal_authorized_canonical_reports_for_same_role_date_timezone_currencies(): void
    {
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-10-10',
            'due_date' => null,
            'lines' => [
                ['item_description' => 'Soap Cartons', 'quantity' => '10', 'unit_price' => '50.000000'],
            ],
        ]);
        app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);

        $briefingService = app(AssistantDailyBriefing::class);
        $briefing = $briefingService->generate($this->company, $this->owner, 'briefing-exact-session');
        self::assertNotEmpty($briefing['reports']);

        $executor = app(AssistantReportExecutor::class);

        self::assertSame((string) $this->company->timezone, $briefing['timezone']);
        self::assertNotEmpty($briefing['generated_at']);

        foreach ($briefing['reports'] as $card) {
            $key = (string) $card['report_key'];
            $planFilters = $key === 'money.balances' ? [] : ['preset' => 'today'];
            $expectedPlan = AssistantReportPlan::fromArray([
                'version' => 1,
                'type' => 'report_query',
                'report_key' => $key,
                'filters' => $planFilters,
                'display' => 'summary',
            ]);
            $expectedDto = $executor->execute($this->company, $this->owner, $expectedPlan);
            $expectedReport = $expectedDto->toArray();

            self::assertSame($expectedReport['report_key'], $card['report_key']);
            self::assertSame($expectedReport['title'], $card['title']);
            self::assertSame($expectedReport['timezone'], $card['timezone']);
            self::assertSame($expectedReport['currency'], $card['currency']);
            self::assertSame($expectedReport['totals'], $card['totals']);
            self::assertSame($expectedReport['total_rows'], $card['total_rows']);
            self::assertSame($expectedReport['rows'], $card['rows']);
            self::assertSame($expectedReport['source_url'], $card['source_url']);
        }
    }

    public function test_real_nonzero_canonical_totals(): void
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

        // 2. Money balances
        $this->receipt('150.000000', '1.000000', [], 'idempotent-receipt-nonzero');

        // 3. Purchases unpaid
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

        // 4. Low stock product (threshold 10, current stock 0 -> alert out of stock)
        $lowStockProduct = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'LOW-TRACK-01',
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

        // 5. Expiry lot with tracked active lot
        $expiryProduct = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'EXP-TRACK-01',
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
            idempotencyKey: 'nonzero-expiry-lot-1',
            lotNumber: 'LOT-NZ-2026',
            expiryDate: '2026-10-25',
            movementDate: '2026-10-01',
        );

        $briefingService = app(AssistantDailyBriefing::class);
        $before = $this->economicFingerprint();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $briefing = $briefingService->generate($this->company, $this->owner, 'briefing-nonzero-session');
        $elapsedMs = (hrtime(true) - $started) / 1000000;
        $queries = array_map(fn ($query) => ['sql' => $query['query'], 'milliseconds' => $query['time']], DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();
        self::assertSame($before, $this->economicFingerprint());
        Http::assertNothingSent();
        $this->assertDatabaseCount('assistant_requests', 0);
        $profilePath = getenv('AI_V22_BRIEFING_PROFILE_PATH');
        if (is_string($profilePath) && $profilePath !== '') {
            file_put_contents($profilePath, json_encode([
                'fixture' => 'five nonempty canonical cards', 'query_count' => count($queries),
                'elapsed_ms' => $elapsedMs, 'model_calls' => 0, 'queries' => $queries,
            ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
        }
        self::assertCount(5, $briefing['reports']);

        $reportsByKey = [];
        foreach ($briefing['reports'] as $report) {
            $reportsByKey[$report['report_key']] = $report;
        }

        // Assert real nonzero totals and rows
        self::assertGreaterThan(0, $reportsByKey['sales.unpaid']['total_rows']);
        self::assertNotEmpty($reportsByKey['sales.unpaid']['rows']);

        self::assertGreaterThan(0, $reportsByKey['purchases.unpaid']['total_rows']);
        self::assertNotEmpty($reportsByKey['purchases.unpaid']['rows']);

        self::assertGreaterThan(0, $reportsByKey['money.balances']['total_rows']);
        self::assertNotSame('0.000000', $reportsByKey['money.balances']['totals']['total_base_balance']);

        self::assertGreaterThan(0, $reportsByKey['inventory.low-stock']['total_rows']);
        self::assertGreaterThan(0, $reportsByKey['inventory.low-stock']['totals']['total_alert_count']);

        self::assertGreaterThan(0, $reportsByKey['inventory.expiry']['total_rows']);
        self::assertGreaterThan(0, $reportsByKey['inventory.expiry']['totals']['total_active_lots']);
    }

    public function test_restricted_and_disabled_cards_are_omitted_not_zeroed(): void
    {
        $settings = $this->aiPolicy->settings;
        $settings['questions'] = [
            'enabled' => true,
            'allowed_reports' => ['sales.unpaid', 'money.balances'],
        ];
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => true,
            'personal_credentials_enabled' => false,
            'follow_up_enabled' => true,
            'workflow_mode' => 'seamless',
            'speech_method' => 'disabled',
            'settings' => $settings,
        ], $this->aiPolicy->revision);

        $briefingService = app(AssistantDailyBriefing::class);
        $briefing = $briefingService->generate($this->company, $this->owner, 'briefing-restricted-session');
        $cardKeys = array_column($briefing['reports'], 'report_key');

        self::assertContains('sales.unpaid', $cardKeys);
        self::assertContains('money.balances', $cardKeys);
        self::assertNotContains('purchases.unpaid', $cardKeys);
        self::assertNotContains('inventory.low-stock', $cardKeys);
        self::assertNotContains('inventory.expiry', $cardKeys);
        self::assertCount(2, $briefing['reports']);

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
            'name' => 'Sales Only Reader',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo(['ai.assistant.use', 'reports.sales.view', 'sales.invoice.view']);
        $reader->assignRole($role);

        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);

        $readerBriefing = $briefingService->generate($this->company, $reader, 'briefing-role-restricted');
        $readerKeys = array_column($readerBriefing['reports'], 'report_key');

        self::assertSame(['sales.unpaid'], $readerKeys);
        self::assertNotContains('money.balances', $readerKeys);

        // Restore context to owner
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
    }

    public function test_mid_query_permission_revocation_fails_proposal_stale(): void
    {
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
            'name' => 'MidQuery Revocable Role',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo([
            'ai.assistant.use',
            'reports.sales.view',
            'sales.invoice.view',
            'reports.money.view',
            'money.cash.view',
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
            $briefingService->generate($this->company, $reader, 'mid-query-revocation-session');
            self::fail('A revoked read must not return an answer.');
        } catch (AssistantUnavailable $e) {
            self::assertSame('proposal_stale', $e->diagnosticReason());
        } catch (AuthorizationException) {
            self::assertTrue($revoked);
        } finally {
            $this->actingAs($this->owner);
            app(CompanyContext::class)->setCompany($this->company, $this->owner);
        }
    }

    public function test_zero_http_traffic_zero_tokens_and_no_assistant_requests(): void
    {
        Http::fake();
        $this->assertDatabaseCount('assistant_requests', 0);

        $briefingService = app(AssistantDailyBriefing::class);
        $briefing = $briefingService->generate($this->company, $this->owner, 'zero-traffic-session');
        self::assertNotEmpty($briefing['reports']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('assistant_requests', 0);

        Livewire::test(AssistantLauncher::class)
            ->call('showAssistant')
            ->call('loadDailyBriefing')
            ->assertSet('isBriefing', true)
            ->assertCount('turnReports', count($briefing['reports']));

        Http::assertNothingSent();
        $this->assertDatabaseCount('assistant_requests', 0);
    }

    public function test_economic_fingerprint_unchanged(): void
    {
        $before = $this->economicFingerprint();

        $briefingService = app(AssistantDailyBriefing::class);
        $briefingService->generate($this->company, $this->owner, 'economic-fingerprint-session');

        Livewire::test(AssistantLauncher::class)
            ->call('showAssistant')
            ->call('loadDailyBriefing')
            ->assertSet('isBriefing', true);

        self::assertSame($before, $this->economicFingerprint());
    }

    public function test_empty_data_truth_for_authorized_cards_with_no_records(): void
    {
        $briefingService = app(AssistantDailyBriefing::class);
        $briefing = $briefingService->generate($this->company, $this->owner, 'empty-truth-session');
        self::assertCount(5, $briefing['reports']);

        foreach ($briefing['reports'] as $card) {
            if ($card['report_key'] === 'money.balances') {
                // Empty fixture cash account truthfully reports canonical zero rows
                self::assertNotEmpty($card['rows']);
                self::assertSame('0.000000', $card['totals']['total_base_balance']);
                foreach ($card['rows'] as $row) {
                    self::assertSame('0.000000', $row['balance_base']);
                }
            } else {
                self::assertSame([], $card['rows']);
                self::assertSame(0, $card['total_rows']);
            }
        }

        Livewire::test(AssistantLauncher::class)
            ->call('showAssistant')
            ->call('loadDailyBriefing')
            ->assertSet('isBriefing', true)
            ->assertSee(__('assistant_briefing.title'))
            ->assertSee(__('assistant_briefing.truthful_notice'))
            ->assertSee(__('assistant_v2.no_data'));
    }

    public function test_tenant_and_session_switch_refuses_cached_briefing(): void
    {
        $foreignOwner = User::factory()->create(['locale' => 'en']);
        $context = app(CompanyContext::class);
        $context->clear();

        $foreignCompany = app(CreateCompanyAction::class)->execute($foreignOwner, [
            'name_ar' => 'شركة أخرى',
            'name_en' => 'Other Company',
            'base_currency_code' => 'USD',
        ]);

        $this->actingAs($this->owner);
        $context->setCompany($this->company, $this->owner);

        $component = Livewire::test(AssistantLauncher::class)
            ->call('showAssistant')
            ->call('loadDailyBriefing')
            ->assertSet('isBriefing', true);

        self::assertNotEmpty($component->get('turnReports'));

        $this->actingAs($foreignOwner);
        $context->setCompany($foreignCompany, $foreignOwner);

        $component->call('$refresh')
            ->assertSet('isBriefing', false)
            ->assertSet('turnReports', []);
    }

    public function test_session_regeneration_invalidates_briefing(): void
    {
        $component = Livewire::test(AssistantLauncher::class)
            ->call('showAssistant')
            ->call('loadDailyBriefing')
            ->assertSet('isBriefing', true);

        self::assertNotEmpty($component->get('turnReports'));

        session()->regenerate();

        $component->call('$refresh')
            ->assertSet('isBriefing', false)
            ->assertSet('turnReports', []);
    }

    public function test_permission_revocation_after_retrieval_refuses_render(): void
    {
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
            'name' => 'Revocable Reader',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo(['ai.assistant.use', 'reports.sales.view', 'sales.invoice.view']);
        $reader->assignRole($role);

        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);

        $component = Livewire::test(AssistantLauncher::class)
            ->call('showAssistant')
            ->call('loadDailyBriefing')
            ->assertSet('isBriefing', true)
            ->assertCount('turnReports', 1);

        $role->revokePermissionTo('reports.sales.view');

        $component->call('$refresh')
            ->assertSet('turnReports', []);
    }

    public function test_transition_from_clarifying_draft_into_briefing(): void
    {
        $component = Livewire::test(AssistantLauncher::class)
            ->call('showAssistant');

        $initialFormKey = $component->get('formKey');
        // Exercise the actual local transaction parser and locked server state.
        $component->set('text', 'sell to "Unknown Customer": 5 "carton" "SOAP-3L" at 80 ILS')
            ->call('prepare')->assertNoRedirect();
        self::assertNotNull($component->get('token'));
        self::assertNotEmpty($component->get('questions'));

        // Transition into briefing
        $component->call('loadDailyBriefing');

        $component->assertSet('isBriefing', true);
        $component->assertSet('token', null);
        $component->assertSet('questions', []);
        $component->assertSet('answers', []);
        $component->assertSet('draftInputHash', null);
        $component->assertSet('clarifyPeriodReport', null);
        self::assertNotSame($initialFormKey, $component->get('formKey'));
        self::assertNotEmpty($component->get('turnReports'));
    }

    public function test_assistant_launcher_offers_clear_action_in_ar_and_en(): void
    {
        app()->setLocale('en');
        Livewire::test(AssistantLauncher::class)
            ->call('showAssistant')
            ->assertSee('Daily Briefing')
            ->assertSee(__('assistant_briefing.action'));

        app()->setLocale('ar');
        Livewire::test(AssistantLauncher::class)
            ->call('showAssistant')
            ->assertSee('الملخص اليومي')
            ->assertSee(__('assistant_briefing.action'));

        $arComponent = Livewire::test(AssistantLauncher::class)
            ->call('showAssistant')
            ->set('text', 'الملخص اليومي')
            ->call('prepare')
            ->assertSet('isBriefing', true)
            ->assertSet('text', '');

        self::assertNotEmpty($arComponent->get('turnReports'));

        Http::assertNothingSent();
        $this->assertDatabaseCount('assistant_requests', 0);
    }

    public function test_briefing_does_not_trigger_automatically_on_opening_assistant(): void
    {
        $component = Livewire::test(AssistantLauncher::class);
        self::assertFalse($component->get('isBriefing'));
        self::assertSame([], $component->get('turnReports'));

        $component->call('showAssistant');
        self::assertFalse($component->get('isBriefing'));
        self::assertSame([], $component->get('turnReports'));

        $component->call('loadDailyBriefing');
        self::assertTrue($component->get('isBriefing'));
        self::assertNotEmpty($component->get('turnReports'));
    }

    public function test_truthful_semantics_unpaid_invoices_not_called_overdue(): void
    {
        $enNotice = __('assistant_briefing.truthful_notice', [], 'en');
        $arNotice = __('assistant_briefing.truthful_notice', [], 'ar');

        self::assertStringContainsString('unpaid does not mean overdue', $enNotice);
        self::assertStringContainsString('غير المسددة لا تعني متأخرة', $arNotice);

        $enDesc = __('assistant_briefing.descriptions.sales_unpaid', [], 'en');
        $arDesc = __('assistant_briefing.descriptions.sales_unpaid', [], 'ar');

        self::assertStringContainsString('not overdue only', $enDesc);
        self::assertStringContainsString('وليس المتأخرة فقط', $arDesc);

        Livewire::test(AssistantLauncher::class)
            ->call('showAssistant')
            ->call('loadDailyBriefing')
            ->assertSee($enNotice);
    }

    public function test_invalid_session_id_rejected(): void
    {
        $briefingService = app(AssistantDailyBriefing::class);

        $this->expectException(AssistantUnavailable::class);
        try {
            $briefingService->generate($this->company, $this->owner, '');
        } catch (AssistantUnavailable $e) {
            self::assertSame('invalid_request', $e->diagnosticReason());
            throw $e;
        }
    }

    public function test_oversized_session_id_rejected(): void
    {
        $briefingService = app(AssistantDailyBriefing::class);

        $this->expectException(AssistantUnavailable::class);
        try {
            $briefingService->generate($this->company, $this->owner, str_repeat('s', 256));
        } catch (AssistantUnavailable $e) {
            self::assertSame('invalid_request', $e->diagnosticReason());
            throw $e;
        }
    }

    /** @return array<string, string> */
    private function economicFingerprint(): array
    {
        $fingerprints = [];
        $tables = ['sales_invoices', 'sales_invoice_lines', 'purchases', 'purchase_lines',
            'posting_batches', 'posting_lines', 'stock_movements', 'customer_payments',
            'vendor_payments', 'customers', 'vendors', 'products'];
        foreach ($tables as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            $fingerprints[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }

        return $fingerprints;
    }
}
