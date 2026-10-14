<?php

declare(strict_types=1);

namespace Tests\Feature\AiV22;

use App\Application\AI\AssistantTurnService;
use App\Models\PostingLine;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/** Reuses canonical posted fixtures; run with --filter test_compound_ to avoid inherited regression repetition. */
final class CompoundAccountingTurnTest extends AccountingTurnAcceptanceTest
{
    /** @return array<string, array{string, string}> */
    public static function naturalCompoundQuestions(): array
    {
        return [
            'English architect example' => ["What's Ahmed's outstanding balance, and what were our sales this month?", 'Ahmed'],
            'Palestinian Arabic' => ['قديش على أحمد دين؟ وشو مبيعات هالشهر؟', 'أحمد'],
        ];
    }

    #[DataProvider('naturalCompoundQuestions')]
    public function test_compound_current_customer_debt_does_not_inherit_independent_sales_period(string $text, string $customer): void
    {
        $before = $this->economicCounts();
        $calls = [
            ['id' => 'call_balance', 'name' => 'run_report', 'arguments' => $this->scopedProposal('customers.balances', ['customer_name' => $customer])],
            ['id' => 'call_sales', 'name' => 'run_report', 'arguments' => $this->scopedProposal('sales.summary', ['preset' => 'this_month'])],
        ];
        Http::fake(['*' => Http::response($this->mockDeepSeekResponse($calls))]);
        $result = app(AssistantTurnService::class)->run($this->company, $this->owner, $text, (string) Str::uuid(), 'sess-compound-review');
        self::assertSame('answer', $result['status']);
        self::assertCount(2, $result['reports']);
        self::assertSame('customers.balances', $result['reports'][0]['report_key']);
        $rows = array_values(array_filter($result['reports'][0]['rows'], fn (array $row): bool => ($row['customer_code'] ?? null) === 'C-AHMED'));
        self::assertCount(1, $rows);
        self::assertSame('1000.000000', $rows[0]['outstanding_balance']);
        self::assertSame('sales.summary', $result['reports'][1]['report_key']);
        self::assertSame('2026-10-01', $result['reports'][1]['period']['start_date']);
        self::assertSame('2026-10-31', $result['reports'][1]['period']['end_date']);
        self::assertSame('800.000000', $result['reports'][1]['totals']['gross_sales_base']);
        self::assertSame(1, $result['model_calls']);
        Http::assertSentCount(1);
        self::assertSame($before, $this->economicCounts());
    }

    public function test_compound_omitted_named_entity_does_not_return_a_company_wide_customer_balance(): void
    {
        $before = $this->economicCounts();
        Http::fake(['*' => Http::response($this->mockDeepSeekResponse([
            ['id' => 'call_balance', 'name' => 'run_report', 'arguments' => $this->scopedProposal('customers.balances', [])],
            ['id' => 'call_sales', 'name' => 'run_report', 'arguments' => $this->scopedProposal('sales.summary', ['preset' => 'this_month'])],
        ]))]);
        $result = app(AssistantTurnService::class)->run($this->company, $this->owner,
            "What's Ahmed's outstanding balance, and what were our sales this month?", (string) Str::uuid(), 'sess-compound-dropped-name');
        self::assertSame('clarification', $result['status']);
        self::assertSame([], $result['reports']);
        Http::assertSentCount(1);
        self::assertSame($before, $this->economicCounts());
    }

    public function test_compound_model_cannot_drop_an_independent_clause_even_if_the_surviving_report_is_authorized(): void
    {
        Http::fake(['*' => Http::response($this->mockDeepSeekResponse([
            ['id' => 'call_sales', 'name' => 'run_report', 'arguments' => $this->scopedProposal('sales.summary', ['preset' => 'this_month'], 'Sales this month')],
        ]))]);
        $result = app(AssistantTurnService::class)->run($this->company, $this->owner,
            'Sales this month; Balance for customer Ahmed', (string) Str::uuid(), 'sess-compound-dropped-clause');
        self::assertSame('clarification', $result['status']);
        self::assertSame([], $result['reports']);
        Http::assertSentCount(1);
    }

    public function test_compound_model_cannot_borrow_another_scopes_currency(): void
    {
        Http::fake(['*' => Http::response($this->mockDeepSeekResponse([
            ['id' => 'call_balance', 'name' => 'run_report', 'arguments' => $this->scopedProposal('customers.balances', ['customer_name' => 'Ahmed', 'currency_code' => 'ILS'])],
            ['id' => 'call_sales', 'name' => 'run_report', 'arguments' => $this->scopedProposal('sales.summary', ['preset' => 'this_month', 'currency_code' => 'USD'])],
        ]))]);
        $result = app(AssistantTurnService::class)->run($this->company, $this->owner,
            'Balance for customer Ahmed in USD; Sales this month in ILS', (string) Str::uuid(), 'sess-compound-borrowed-currency');
        self::assertSame('clarification', $result['status']);
        self::assertSame([], $result['reports']);
        Http::assertSentCount(1);
    }

    /** @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private function scopedProposal(string $key, array $filters, ?string $fragment = null): array
    {
        $proposal = ['version' => 1, 'type' => 'report_query', 'report_key' => $key, 'filters' => $filters, 'display' => 'summary'];
        if ($fragment !== null) {
            $proposal['request_fragment'] = $fragment;
        }

        return $proposal;
    }

    /** @return array{invoices: int, lines: int, stock: int} */
    private function economicCounts(): array
    {
        return ['invoices' => SalesInvoice::withoutGlobalScopes()->count(),
            'lines' => PostingLine::withoutGlobalScopes()->count(),
            'stock' => StockMovement::withoutGlobalScopes()->count()];
    }
}
