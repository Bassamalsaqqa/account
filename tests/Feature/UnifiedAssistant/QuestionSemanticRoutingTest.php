<?php

declare(strict_types=1);

namespace Tests\Feature\UnifiedAssistant;

use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantDeterministicClassifier;
use App\Application\AI\AssistantQuestionPlanner;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\DeterministicTransactionParser;
use App\Models\AssistantRequest;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

final class QuestionSemanticRoutingTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        config(['ai_assistant.questions.enabled' => true]);
        $settings = $this->aiPolicy->settings;
        $settings['questions'] = ['enabled' => true, 'allowed_reports' => AssistantReportCatalog::KEYS];
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => true,
            'workflow_mode' => 'seamless', 'speech_method' => 'cloud', 'settings' => $settings,
        ], (int) $this->aiPolicy->revision);
        Http::preventStrayRequests();
    }

    public function test_qualified_typed_and_transcribed_reports_never_deliver_company_wide_answers(): void
    {
        $queries = [
            'sales for Ahmed this month', 'مبيعات أحمد هذا الشهر',
            'purchases from Acme today', 'مشتريات سامر اليوم',
            'customer balances Ahmed', 'أرصدة العملاء أحمد',
            'sales of milk today', 'مبيعات الحليب اليوم',
            'sales in Hebron today', 'مبيعات الخليل اليوم',
            'low stock today in USD', 'المخزون المنخفض اليوم بالدولار',
            'sales today in EUR', 'مبيعات اليوم باليورو',
            'sales next week', 'مبيعات الأسبوع القادم',
            'sales today for January', 'مبيعات اليوم لشهر يناير',
        ];
        foreach ($queries as $query) {
            Livewire::test('ai.assistant-launcher')->call('showAssistant')
                ->set('text', $query)->call('prepare')
                ->assertSet('reportResult', null)->assertSet('error', __('assistant_v2.error_question_invalid'));
            Livewire::test('ai.assistant-launcher')->call('showAssistant')
                ->call('receiveTranscript', $query, null)
                ->assertSet('reportResult', null)->assertSet('error', __('assistant_v2.error_question_invalid'));
        }
        Http::assertNothingSent();
        $this->assertSame(0, AssistantRequest::count());
        $this->assertNoEconomicWrites();
    }

    public function test_direct_question_planner_refuses_qualifiers_before_provider_dispatch(): void
    {
        $this->consent('question');
        Http::fake(['*' => Http::response($this->response($this->reportPlan()))]);
        foreach (['turnover for Ahmed this month', 'أعطني حصيلة أحمد هذا الشهر', 'turnover in Hebron today',
            'turnover today EUR', 'turnover next week', 'أنشئ فاتورة لخليل فيها 5 علب حليب'] as $query) {
            try {
                app(AssistantQuestionPlanner::class)->plan($this->company, $this->owner, $query,
                    (string) Str::uuid(), session()->getId());
                $this->fail('Qualified or transaction input cannot become an unqualified provider plan: '.$query);
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason);
            }
        }
        Http::assertNothingSent();
        $this->assertSame(0, AssistantRequest::count());
    }

    public function test_unrecognized_dual_capability_input_requires_choice_before_any_provider_call(): void
    {
        $this->consent('question');
        Http::fake(['*' => Http::response($this->response($this->reportPlan()))]);
        foreach (['put that on the usual bill please', 'جهزها مثل العادة', 'Show our turnover for this month'] as $text) {
            $typed = Livewire::test('ai.assistant-launcher')->call('showAssistant')
                ->set('text', $text)->call('prepare')->assertSet('reportResult', null);
            $this->assertNotNull($typed->get('ambiguousChoice'));
            $spoken = Livewire::test('ai.assistant-launcher')->call('showAssistant')
                ->call('receiveTranscript', $text, null)->assertSet('reportResult', null);
            $this->assertNotNull($spoken->get('ambiguousChoice'));
        }
        Http::assertNothingSent();
        $this->assertSame(0, AssistantRequest::count());
        $this->assertNoEconomicWrites();
    }

    public function test_informal_invoice_commands_use_transaction_consent_and_existing_unsaved_handoff(): void
    {
        $this->consent('transaction');
        $queries = ['أنشئ فاتورة لأحمد فيها 5 كرتونة SOAP-3L بسعر 80 ILS',
            'make Ahmed an invoice for 5 carton SOAP-3L at 80 ILS'];
        $sequence = Http::sequence();
        foreach ($queries as $query) {
            $sequence->push($this->response($this->salesProposal($query)))
                ->push($this->response($this->salesProposal($query)));
        }
        Http::fake(['*' => $sequence]);
        foreach ($queries as $query) {
            Livewire::test('ai.assistant-launcher')->call('showAssistant')->set('text', $query)
                ->call('prepare')->assertSet('error', null)->assertRedirect();
            Livewire::test('ai.assistant-launcher')->call('showAssistant')
                ->call('receiveTranscript', $query, null)->assertRedirect();
        }
        $this->assertSame(['intent'], AssistantRequest::query()->distinct()->pluck('purpose')->all());
        $this->assertSame(['form_ready'], AssistantRequest::query()->distinct()->pluck('status')->all());
        Http::assertSentCount(4);
        $this->assertNoEconomicWrites();
    }

    public function test_explicit_report_choice_preserves_filters_and_requires_question_consent(): void
    {
        Http::fake(['*' => Http::response($this->response($this->reportPlan()))]);
        $query = 'Show our turnover for this month';
        $component = Livewire::test('ai.assistant-launcher')->call('showAssistant')
            ->set('text', $query)->call('prepare');
        $this->assertNotNull($component->get('ambiguousChoice'));
        $component->call('chooseOperation', 'report')->assertSet('reportResult', null)
            ->assertSet('error', __('assistant_v2.error_consent'));
        Http::assertNothingSent();
        $this->consent('question');
        Livewire::test('ai.assistant-launcher')->call('showAssistant')->set('text', $query)->call('prepare')
            ->call('chooseOperation', 'report')->assertSet('reportResult.report_key', 'sales.summary')
            ->assertSet('reportResult.period.preset', 'this_month');
        Http::assertSentCount(1);
        $this->assertSame('question', AssistantRequest::sole()->purpose);
        $this->assertNoEconomicWrites();
    }

    public function test_provider_cannot_drop_or_change_an_explicit_currency_or_period(): void
    {
        $this->consent('question');
        $variants = [['preset' => 'this_month', 'currency_code' => null],
            ['preset' => 'last_month', 'currency_code' => 'USD'],
            ['preset' => 'this_month', 'currency_code' => 'ILS']];
        $sequence = Http::sequence();
        foreach ($variants as $filters) {
            $plan = $this->reportPlan();
            $plan['filters'] = $filters;
            $sequence->push($this->response($plan));
        }
        Http::fake(['*' => $sequence]);
        foreach ($variants as $filters) {
            try {
                app(AssistantQuestionPlanner::class)->plan($this->company, $this->owner,
                    'Show our turnover for this month in USD', (string) Str::uuid(), session()->getId());
                $this->fail('Provider output must preserve explicitly requested filters.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason);
            }
        }
        Http::assertSentCount(3);
        $this->assertSame(['failed'], AssistantRequest::query()->distinct()->pluck('status')->all());
        $this->assertNoEconomicWrites();
    }

    public function test_unknown_transaction_choice_uses_original_text_and_transaction_scope(): void
    {
        $query = 'put that on the usual bill please';
        // Unsupported is a valid transaction-provider response; no guessed customer/product.
        Http::fake(['*' => Http::response($this->response(['version' => 2, 'intent' => 'uncertain']))]);
        $this->consent('question');
        $component = Livewire::test('ai.assistant-launcher')->call('showAssistant')
            ->set('text', $query)->call('prepare');
        $component->call('chooseOperation', 'transaction')->assertSet('reportResult', null)
            ->assertSet('error', __('assistant_v2.error_consent'));
        Http::assertNothingSent();
        $this->consent('transaction');
        $component = Livewire::test('ai.assistant-launcher')->call('showAssistant')
            ->set('text', $query)->call('prepare')->set('text', 'sales today')
            ->call('chooseOperation', 'transaction')->assertSet('reportResult', null);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->data()['messages'][1]['content'] === $query);
        $this->assertSame('intent', AssistantRequest::sole()->purpose);
        $this->assertNoEconomicWrites();
    }

    public function test_operation_choice_rechecks_revoked_membership_before_dispatch(): void
    {
        $reader = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $reader->id, 'status' => 'active', 'is_owner' => false]);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'Choice regression', 'guard_name' => 'web']);
        $role->syncPermissions(['ai.assistant.use', 'ai.sales.use', 'sales.invoice.create', 'sales.invoice.view', 'reports.sales.view']);
        $reader->assignRole($role);
        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);
        $component = Livewire::test('ai.assistant-launcher')->call('showAssistant')
            ->set('text', 'Show our turnover for this month')->call('prepare');
        $this->assertNotNull($component->get('ambiguousChoice'));
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $reader->id)->update(['status' => 'inactive']);
        $component->call('chooseOperation', 'report')->assertForbidden();
        Http::assertNothingSent();
        $this->assertSame(0, AssistantRequest::count());
    }

    public function test_provider_cannot_invent_a_missing_period_or_currency(): void
    {
        $this->consent('question');
        $plan = $this->reportPlan();
        $plan['filters']['currency_code'] = 'USD';
        Http::fake(['*' => Http::sequence()->push($this->response($plan))->push($this->response($plan))]);
        foreach (['Show our turnover', 'Show our turnover for this month'] as $query) {
            try {
                app(AssistantQuestionPlanner::class)->plan($this->company, $this->owner, $query,
                    (string) Str::uuid(), session()->getId());
                $this->fail('Missing economic filters must not be invented.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason);
            }
        }
        Http::assertSentCount(2);
    }

    public function test_informal_global_purchase_commands_preserve_unsaved_handoff_in_both_languages(): void
    {
        $this->aiProduct->update(['product_type' => 'stock', 'track_stock' => true, 'track_expiry' => false]);
        $this->aiCarton->update(['is_default_purchase' => true]);
        app(VendorCatalogService::class)->save($this->company, $this->owner, ['name_ar' => 'مورد', 'name_en' => 'Supplier']);
        $this->consent('transaction');
        $proposal = app(DeterministicTransactionParser::class)->parse('buy from "Supplier": 2 "carton" "SOAP-3L" at 24 ILS');
        $proposal['evidence'] = array_map(fn ($path, $quote) => ['path' => $path, 'quote' => $quote],
            array_keys($proposal['evidence']), array_values($proposal['evidence']));
        Http::fake(['*' => Http::sequence()->push($this->response($proposal))->push($this->response($proposal))
            ->push($this->response($proposal))->push($this->response($proposal))]);
        foreach (['buy 2 carton SOAP-3L from Supplier at 24 ILS',
            'اعمل فاتورة شراء من Supplier 2 carton SOAP-3L بسعر 24 ILS'] as $query) {
            Livewire::test('ai.assistant-launcher')->call('showAssistant')->set('text', $query)
                ->call('prepare')->assertSet('error', null)->assertRedirect();
            Livewire::test('ai.assistant-launcher')->call('showAssistant')
                ->call('receiveTranscript', $query, null)->assertSet('error', null)->assertRedirect();
        }
        Http::assertSentCount(4);
        $this->assertSame(['intent'], AssistantRequest::query()->distinct()->pluck('purpose')->all());
        $this->assertSame(['purchase'], AssistantRequest::query()->distinct()->pluck('intent_kind')->all());
        $this->assertSame(['form_ready'], AssistantRequest::query()->distinct()->pluck('status')->all());
        $this->assertNoEconomicWrites();
    }

    public function test_provider_cannot_replace_the_requested_report_with_a_different_tool(): void
    {
        $this->consent('question');
        $plan = $this->reportPlan();
        $plan['report_key'] = 'purchases.summary';
        Http::fake(['*' => Http::response($this->response($plan))]);
        try {
            app(AssistantQuestionPlanner::class)->plan($this->company, $this->owner,
                'Show our turnover for this month', (string) Str::uuid(), session()->getId());
            $this->fail('A provider cannot change the requested report.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('question_invalid', $e->reason);
        }
        Http::assertSentCount(1);
        $this->assertNoEconomicWrites();
    }

    public function test_previous_report_context_does_not_capture_a_new_unknown_operation(): void
    {
        $component = Livewire::test('ai.assistant-launcher')->call('showAssistant')
            ->set('text', 'sales today')->call('prepare')->assertSet('reportResult.report_key', 'sales.summary');
        $component->set('text', 'put that on the usual bill please')->call('prepare')
            ->assertSet('reportResult', null);
        $this->assertNotNull($component->get('ambiguousChoice'));
        $this->assertNull(app(AssistantDeterministicClassifier::class)->classifyFollowup(
            'Show our turnover this month', 'inventory.expiry', ['preset' => 'today']));
        Http::assertNothingSent();
        $this->assertSame(0, AssistantRequest::count());
        $this->assertNoEconomicWrites();
    }

    private function consent(string $scope): void
    {
        foreach ([$this->textConnection, $this->fallbackConnection] as $connection) {
            app(AiGovernance::class)->recordConsent($this->company, $this->owner, $connection->public_id,
                app(AiGovernance::class)->consentFingerprint($this->aiPolicy, $connection, $scope), $scope);
        }
    }

    private function reportPlan(): array
    {
        return ['version' => 1, 'type' => 'report_query', 'report_key' => 'sales.summary',
            'filters' => ['preset' => 'this_month', 'currency_code' => null], 'display' => 'summary'];
    }

    private function salesProposal(string $text): array
    {
        return ['version' => 2, 'intent' => 'sales', 'party' => str_contains($text, 'أحمد') ? 'أحمد' : 'Ahmed', 'currency' => 'ILS',
            'warehouse' => null, 'exchange_rate' => null, 'date' => null, 'due_date' => null,
            'vendor_invoice_number' => null, 'notes' => null,
            'lines' => [['product' => 'SOAP-3L', 'unit' => str_contains($text, 'كرتونة') ? 'كرتونة' : 'carton', 'quantity' => '5', 'unit_price' => '80',
                'unit_cost' => null, 'discount_type' => null, 'discount_value' => null, 'tax' => null, 'lots' => []]],
            'evidence' => [['path' => 'party', 'quote' => str_contains($text, 'أحمد') ? 'أحمد' : 'Ahmed'],
                ['path' => 'currency', 'quote' => 'ILS'], ['path' => 'lines.0.product', 'quote' => 'SOAP-3L'],
                ['path' => 'lines.0.unit', 'quote' => str_contains($text, 'كرتونة') ? 'كرتونة' : 'carton'],
                ['path' => 'lines.0.quantity', 'quote' => '5'], ['path' => 'lines.0.unit_price', 'quote' => '80']]];
    }

    private function response(array $plan): array
    {
        return ['choices' => [['message' => ['content' => json_encode($plan, JSON_THROW_ON_ERROR)], 'finish_reason' => 'stop']]];
    }

    private function assertNoEconomicWrites(): void
    {
        foreach (['sales_invoices', 'purchases', 'posting_batches', 'stock_movements'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }
}
