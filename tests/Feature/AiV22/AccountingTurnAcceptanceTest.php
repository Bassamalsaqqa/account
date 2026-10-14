<?php

declare(strict_types=1);

namespace Tests\Feature\AiV22;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AssistantTurnContext;
use App\Application\AI\AssistantTurnService;
use App\Application\AI\AssistantUnavailable;
use App\Models\AssistantRequest;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\AiV22\AccountingTurnFixtures;
use Tests\TestCase;

class AccountingTurnAcceptanceTest extends TestCase
{
    use AccountingTurnFixtures;
    use RefreshDatabase;

    public function test_question_one_ahmed_debt_and_last_invoice(): void
    {
        $mock = $this->mockDeepSeekResponse([
            [
                'id' => 'call_1',
                'name' => 'run_report',
                'arguments' => [
                    'version' => 1,
                    'type' => 'report_query',
                    'report_key' => 'customers.balances',
                    'filters' => ['customer_name' => 'أحمد'],
                    'display' => 'summary',
                ],
            ],
            [
                'id' => 'call_2',
                'name' => 'run_report',
                'arguments' => [
                    'version' => 1,
                    'type' => 'report_query',
                    'report_key' => 'sales.last-invoice',
                    'filters' => ['customer_name' => 'أحمد'],
                    'display' => 'summary',
                ],
            ],
        ]);

        Http::fake(['*' => Http::response($mock)]);

        $requestKey = (string) Str::uuid();
        $sessionId = 'sess-acceptance-q1';

        $result = app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'قديش على أحمد دين؟ وشو آخر فاتورة إله؟',
            $requestKey,
            $sessionId
        );

        $this->assertSame('answer', $result['status']);
        $this->assertSame(1, $result['model_calls']);
        $this->assertTrue(Str::isUuid((string) $result['conversation_token']));
        $this->assertCount(2, $result['reports']);

        // Report 1: customers.balances
        $balancesReport = $result['reports'][0];
        $this->assertSame('customers.balances', $balancesReport['report_key']);
        $this->assertNotEmpty($balancesReport['rows']);
        $this->assertIsArray($balancesReport['rows']);
        $ahmedRow = null;
        foreach ($balancesReport['rows'] as $row) {
            if (($row['customer_code'] ?? null) === 'C-AHMED') {
                $ahmedRow = $row;
            }
        }
        $this->assertNotNull($ahmedRow);
        // Outstanding balance: Oct (500 + 300) + Aug (200) = 1000.00 ILS (Sep was paid 450)
        $this->assertSame('1000.000000', (string) $ahmedRow['outstanding_balance']);

        // Report 2: sales.last-invoice
        $lastInvoiceReport = $result['reports'][1];
        $this->assertSame('sales.last-invoice', $lastInvoiceReport['report_key']);
        $this->assertNotEmpty($lastInvoiceReport['rows']);
        $lastInvoiceRow = $lastInvoiceReport['rows'][0];
        $this->assertSame($this->octInvoice2->invoice_number, $lastInvoiceRow['document_number'] ?? $lastInvoiceRow['invoice_number']);
        $this->assertSame('2026-10-10', $lastInvoiceRow['business_date'] ?? $lastInvoiceRow['issue_date']);

        // HTTP verification: exactly one planning call, no result egress
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://api.deepseek.com/chat/completions', $request->url());
            $this->assertSame('deepseek-flash', $request['model']);
            $this->assertSame('required', $request['tool_choice']);
            $data = $request->data();
            $messages = $data['messages'];
            $this->assertCount(2, $messages);
            $this->assertSame('system', $messages[0]['role']);
            $this->assertSame('user', $messages[1]['role']);
            $this->assertSame('قديش على أحمد دين؟ وشو آخر فاتورة إله؟', $messages[1]['content']);

            $payload = json_encode($data);
            // No result egress: actual financial amounts or records are NEVER sent to LLM
            $this->assertStringNotContainsString('1000.000000', $payload);
            $this->assertStringNotContainsString($this->octInvoice2->invoice_number, $payload);

            return true;
        });
    }

    public function test_question_two_compare_this_month_with_last_month(): void
    {
        $mock = $this->mockDeepSeekResponse([
            [
                'id' => 'call_1',
                'name' => 'run_report',
                'arguments' => [
                    'version' => 1,
                    'type' => 'report_query',
                    'report_key' => 'sales.summary',
                    'filters' => [
                        'preset' => 'this_month',
                        'compare' => 'previous_period',
                    ],
                    'display' => 'summary',
                ],
            ],
        ]);

        Http::fake(['*' => Http::response($mock)]);

        $requestKey = (string) Str::uuid();
        $sessionId = 'sess-acceptance-q2';

        $result = app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'قارن مبيعات هالشهر بالشهر الماضي.',
            $requestKey,
            $sessionId
        );

        $this->assertSame('answer', $result['status']);
        $this->assertSame(1, $result['model_calls']);
        $this->assertTrue(Str::isUuid((string) $result['conversation_token']));
        $this->assertNotEmpty($result['reports']);

        $salesReport = $result['reports'][0];
        $this->assertSame('sales.summary', $salesReport['report_key']);
        $this->assertSame('2026-10-01', $salesReport['period']['start_date']);
        $this->assertSame('2026-10-31', $salesReport['period']['end_date']);
        // Canonical Oct sales = 500 + 300 = 800.00 ILS
        $this->assertSame('800.000000', (string) ($salesReport['totals']['gross_sales_base'] ?? $salesReport['totals']['net_sales_base']));

        $this->assertSame('2026-09-01', $salesReport['comparison']['period']['start_date']);
        $this->assertSame('2026-09-30', $salesReport['comparison']['period']['end_date']);
        $this->assertSame('450.000000', $salesReport['comparison']['totals']['gross_sales_base']);
        // Exactly one HTTP call, no result egress
        Http::assertSentCount(1);
    }

    public function test_question_three_low_stock_items(): void
    {
        $mock = $this->mockDeepSeekResponse([
            [
                'id' => 'call_1',
                'name' => 'run_report',
                'arguments' => [
                    'version' => 1,
                    'type' => 'report_query',
                    'report_key' => 'inventory.low-stock',
                    'filters' => [],
                    'display' => 'summary',
                ],
            ],
        ]);

        Http::fake(['*' => Http::response($mock)]);

        $requestKey = (string) Str::uuid();
        $sessionId = 'sess-acceptance-q3';

        $result = app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'شو الأصناف اللي قربت تخلص؟',
            $requestKey,
            $sessionId
        );

        $this->assertSame('answer', $result['status']);
        $this->assertNotEmpty($result['reports']);
        $report = $result['reports'][0];
        $this->assertSame('inventory.low-stock', $report['report_key']);

        // Assert actual canonical values from fixtures
        $this->assertNotEmpty($report['rows']);
        $this->assertIsArray($report['rows']);
        $lowItem = null;
        foreach ($report['rows'] as $row) {
            if (($row['sku'] ?? null) === 'LOW-STOCK-01') {
                $lowItem = $row;
            }
        }
        $this->assertNotNull($lowItem);
        $this->assertSame('out', $lowItem['stock_status'] ?? $lowItem['status']);
        $this->assertSame('10.000000', (string) $lowItem['minimum_stock']);
        $this->assertSame(0, $result['model_calls']);
        Http::assertNothingSent();
    }

    public function test_question_four_followup_reuses_typed_period_context_with_zero_model_calls(): void
    {
        // Turn 1: Question 2 to establish typed period context
        $mock = $this->mockDeepSeekResponse([
            [
                'id' => 'call_1',
                'name' => 'run_report',
                'arguments' => [
                    'version' => 1,
                    'type' => 'report_query',
                    'report_key' => 'sales.summary',
                    'filters' => [
                        'preset' => 'this_month',
                        'compare' => 'previous_period',
                    ],
                    'display' => 'summary',
                ],
            ],
        ]);

        Http::fake(['*' => Http::response($mock)]);

        $sessionId = 'sess-acceptance-q4';
        $turn1 = app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'قارن مبيعات هالشهر بالشهر الماضي.',
            (string) Str::uuid(),
            $sessionId
        );

        $this->assertSame('answer', $turn1['status']);
        $this->assertSame(1, $turn1['model_calls']);
        Http::assertSentCount(1);

        $token = $turn1['conversation_token'];
        $this->assertNotNull($token);

        // Turn 2: Followup question with period context reuse
        $turn2 = app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'طيب وماذا عن الشهر اللي قبله؟',
            (string) Str::uuid(),
            $sessionId,
            $token
        );

        $this->assertSame('answer', $turn2['status']);
        // Followup reuses typed period context: ZERO model calls
        $this->assertSame(0, $turn2['model_calls']);
        // Still only 1 HTTP sent overall (0 sent during turn 2)
        Http::assertSentCount(1);

        $this->assertNotEmpty($turn2['reports']);
        $followupReport = $turn2['reports'][0];
        $this->assertSame('sales.summary', $followupReport['report_key']);
        // Period shifts to the month before (August 2026: 2026-08-01 to 2026-08-31)
        $this->assertSame('2026-08-01', $followupReport['period']['start_date']);
        $this->assertSame('2026-08-31', $followupReport['period']['end_date']);
        // Canonical August sales: 200.00 ILS
        $this->assertSame('200.000000', (string) ($followupReport['totals']['gross_sales_base'] ?? $followupReport['totals']['net_sales_base']));
    }

    public function test_negative_foreign_customer_never_resolves_and_no_data_leaks(): void
    {
        $otherUser = User::factory()->create();
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherUser, [
            'name_ar' => 'شركة أجنبية',
            'name_en' => 'Foreign Company',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($otherCompany, $otherUser);
        Customer::create([
            'company_id' => $otherCompany->id,
            'name_ar' => 'عميل أجنبي',
            'name_en' => 'Foreign Customer',
            'code' => 'C-FOREIGN',
            'created_by' => $otherUser->id,
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        $mock = $this->mockDeepSeekResponse([
            [
                'id' => 'call_1',
                'name' => 'run_report',
                'arguments' => [
                    'version' => 1,
                    'type' => 'report_query',
                    'report_key' => 'customers.balances',
                    'filters' => ['customer_name' => 'عميل أجنبي'],
                    'display' => 'summary',
                ],
            ],
        ]);

        Http::fake(['*' => Http::response($mock)]);

        $result = app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'كم رصيد عميل أجنبي؟',
            (string) Str::uuid(),
            'sess-acceptance-foreign'
        );

        $this->assertSame('clarification', $result['status']);
        $this->assertSame('entity_missing', $result['reason']);
        $this->assertSame([], $result['reports']);
    }

    public function test_negative_ambiguous_customer_names_returns_clarification(): void
    {
        Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل مكرر',
            'name_en' => 'Ambiguous Customer',
            'code' => 'C-AMB-1',
            'created_by' => $this->owner->id,
        ]);
        Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل مكرر',
            'name_en' => 'Ambiguous Customer',
            'code' => 'C-AMB-2',
            'created_by' => $this->owner->id,
        ]);

        $mock = $this->mockDeepSeekResponse([
            [
                'id' => 'call_1',
                'name' => 'run_report',
                'arguments' => [
                    'version' => 1,
                    'type' => 'report_query',
                    'report_key' => 'customers.balances',
                    'filters' => ['customer_name' => 'عميل مكرر'],
                    'display' => 'summary',
                ],
            ],
        ]);

        Http::fake(['*' => Http::response($mock)]);

        $result = app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'كم رصيد عميل مكرر؟',
            (string) Str::uuid(),
            'sess-acceptance-ambiguous'
        );

        $this->assertSame('clarification', $result['status']);
        $this->assertSame('entity_ambiguous', $result['reason']);
        $this->assertSame([], $result['reports']);
    }

    public function test_no_financial_writes_after_fixtures_and_no_walkin_customers(): void
    {
        $invoiceCountBefore = SalesInvoice::count();
        $lineCountBefore = SalesInvoiceLine::count();
        $paymentCountBefore = CustomerPayment::count();
        $movementCountBefore = StockMovement::count();
        $journalCountBefore = PostingLine::count();
        $batchCountBefore = PostingBatch::count();
        $customerCountBefore = Customer::count();

        $mock = $this->mockDeepSeekResponse([
            [
                'id' => 'call_1',
                'name' => 'run_report',
                'arguments' => [
                    'version' => 1,
                    'type' => 'report_query',
                    'report_key' => 'sales.summary',
                    'filters' => ['preset' => 'this_month'],
                    'display' => 'summary',
                ],
            ],
        ]);

        Http::fake(['*' => Http::response($mock)]);

        $result = app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'ملخص مبيعات هذا الشهر',
            (string) Str::uuid(),
            'sess-acceptance-invariants'
        );

        $this->assertSame('answer', $result['status']);

        // Assert not a single financial or stock write occurred
        $this->assertSame($invoiceCountBefore, SalesInvoice::count());
        $this->assertSame($lineCountBefore, SalesInvoiceLine::count());
        $this->assertSame($paymentCountBefore, CustomerPayment::count());
        $this->assertSame($movementCountBefore, StockMovement::count());
        $this->assertSame($journalCountBefore, PostingLine::count());
        $this->assertSame($batchCountBefore, PostingBatch::count());
        $this->assertSame($customerCountBefore, Customer::count());

        $this->assertSame(0, $result['model_calls']);
        Http::assertNothingSent();

        // Assert no walk-in or anonymous customer exists
        $this->assertDatabaseMissing('customers', [
            'company_id' => $this->company->id,
            'code' => 'WALK-IN',
        ]);
        $this->assertDatabaseMissing('customers', [
            'company_id' => $this->company->id,
            'name_ar' => 'عميل نقدي عام',
        ]);
    }

    /** @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private function reportResponse(string $key, array $filters): array
    {
        return $this->mockDeepSeekResponse([['id' => 'call_1', 'name' => 'run_report',
            'arguments' => ['version' => 1, 'type' => 'report_query', 'report_key' => $key,
                'filters' => $filters, 'display' => 'summary']]]);
    }

    public function test_known_named_customer_cannot_be_dropped_into_company_wide_balances(): void
    {
        Http::fake(['*' => Http::response($this->reportResponse('customers.balances', ['currency_code' => 'ILS']))]);
        $result = app(AssistantTurnService::class)->run($this->company, $this->owner,
            'How much does Ahmed owe us?', (string) Str::uuid(), 'named-constraint-session');
        $this->assertSame('clarification', $result['status']);
        $this->assertSame('question_invalid', $result['reason']);
        $this->assertSame([], $result['reports']);
        Http::assertSentCount(1);
    }

    public function test_model_cannot_discard_the_requested_period_or_currency(): void
    {
        Http::fake(['*' => Http::response($this->reportResponse('sales.summary', ['preset' => 'last_month']))]);
        $result = app(AssistantTurnService::class)->run($this->company, $this->owner,
            'Tell me how sales are doing this month in USD', (string) Str::uuid(), 'constraints-session');
        $this->assertSame('clarification', $result['status']);
        $this->assertSame('question_invalid', $result['reason']);
        $this->assertSame([], $result['reports']);
        Http::assertSentCount(1);
    }

    public function test_latest_invoice_english_question_is_a_read_not_a_transaction(): void
    {
        Http::fake(['*' => Http::response($this->reportResponse('sales.last-invoice', ['customer_name' => 'Ahmed']))]);
        $before = SalesInvoice::count();
        $result = app(AssistantTurnService::class)->run($this->company, $this->owner,
            'What is Ahmed\'s last sales invoice?', (string) Str::uuid(), 'invoice-read-session');
        $this->assertSame('answer', $result['status']);
        $this->assertSame($this->octInvoice2->invoice_number, $result['reports'][0]['rows'][0]['invoice_number']);
        $this->assertSame($before, SalesInvoice::count());
        Http::assertSentCount(1);
    }

    public function test_replaying_same_question_recomputes_authorized_results_without_another_model_call(): void
    {
        Http::fake(['*' => Http::response($this->reportResponse('sales.summary', ['preset' => 'this_month']))]);
        $key = (string) Str::uuid();
        $service = app(AssistantTurnService::class);
        $first = $service->run($this->company, $this->owner, 'Could you tell me about sales this month?', $key, 'replay-session');
        $again = $service->run($this->company, $this->owner, 'Could you tell me about sales this month?', $key, 'replay-session');
        $this->assertSame('answer', $again['status']);
        $this->assertTrue($again['replayed']);
        $this->assertSame(0, $again['model_calls']);
        $this->assertSame($first['reports'][0]['totals'], $again['reports'][0]['totals']);
        $this->assertSame(1, AssistantRequest::where('request_key', $key)->count());
        Http::assertSentCount(1);
    }

    public function test_same_request_key_cannot_silently_replace_the_question(): void
    {
        Http::fake(['*' => Http::response($this->reportResponse('sales.summary', ['preset' => 'this_month']))]);
        $key = (string) Str::uuid();
        $service = app(AssistantTurnService::class);
        $service->run($this->company, $this->owner, 'Could you tell me about sales this month?', $key, 'conflict-session');
        try {
            $service->run($this->company, $this->owner, 'Could you tell me about sales last month?', $key, 'conflict-session');
            $this->fail('A conflicting payload replaced an existing question.');
        } catch (AssistantUnavailable $error) {
            $this->assertSame('request_conflict', $error->reason);
        }
        Http::assertSentCount(1);
    }

    public function test_followup_context_is_not_used_when_company_has_disabled_followups(): void
    {
        $token = app(AssistantTurnContext::class)->store($this->company, $this->owner,
            'disabled-followup-session', [['version' => 1, 'type' => 'report_query', 'report_key' => 'sales.summary',
                'filters' => ['preset' => 'this_month'], 'display' => 'summary']]);
        $this->aiPolicy->update(['follow_up_enabled' => false]);
        Http::fake();
        try {
            app(AssistantTurnService::class)->run($this->company, $this->owner,
                'what about the previous month', (string) Str::uuid(), 'disabled-followup-session', $token);
            $this->fail('Disabled follow-up context was used.');
        } catch (AssistantUnavailable $error) {
            $this->assertSame('proposal_stale', $error->reason);
        }
        Http::assertNothingSent();
    }
}
