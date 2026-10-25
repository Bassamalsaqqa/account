<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseB1Quality;

use App\Application\AI\AssistantDeterministicClassifier;
use App\Application\AI\AssistantReportPlan;
use App\Application\AI\AssistantUnavailable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AssistantDeterministicClassifierQualityTest extends TestCase
{
    private AssistantDeterministicClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new AssistantDeterministicClassifier;
    }

    #[DataProvider('acceptedSalesPhrasesProvider')]
    public function test_accepted_sales_phrases_map_to_deterministic_plan(string $phrase, string $expectedPreset, ?string $expectedCurrency): void
    {
        $result = $this->classifier->classify($phrase);

        $this->assertNotNull($result, "Expected plan for: {$phrase}");
        $this->assertSame('plan', $result['kind']);
        $this->assertInstanceOf(AssistantReportPlan::class, $result['plan']);
        $this->assertSame('sales.summary', $result['plan']->reportKey);
        $this->assertSame($expectedPreset, $result['plan']->filters['preset'] ?? null);
        if ($expectedCurrency !== null) {
            $this->assertSame($expectedCurrency, $result['plan']->filters['currency_code'] ?? null);
        } else {
            $this->assertArrayNotHasKey('currency_code', $result['plan']->filters);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function acceptedSalesPhrasesProvider(): array
    {
        return [
            // English baseline defect sample
            'EN: What were my sales today?' => ['What were my sales today?', 'today', null],
            'EN: What was my sales today?' => ['What was my sales today?', 'today', null],
            'EN: What were our sales today?' => ['What were our sales today?', 'today', null],
            'EN: What was our sales yesterday?' => ['What was our sales yesterday?', 'yesterday', null],
            'EN: How were my sales today?' => ['How were my sales today?', 'today', null],
            'EN: How was my sales this month?' => ['How was my sales this month', 'this_month', null],
            'EN: my sales today' => ['my sales today', 'today', null],
            'EN: total sales today' => ['total sales today', 'today', null],

            // Arabic samples
            'AR: كم مبيعات اليوم؟' => ['كم مبيعات اليوم؟', 'today', null],
            'AR: مبيعاتي اليوم' => ['مبيعاتي اليوم', 'today', null],
            'AR: مبيعاتنا اليوم' => ['مبيعاتنا اليوم', 'today', null],
            'AR: كم مبيعاتي اليوم؟' => ['كم مبيعاتي اليوم؟', 'today', null],
            'AR: كم مبيعاتنا اليوم؟' => ['كم مبيعاتنا اليوم؟', 'today', null],
            'AR: مبيعاتي هذا الشهر' => ['مبيعاتي هذا الشهر', 'this_month', null],
            'AR: مبيعاتنا هذا الشهر' => ['مبيعاتنا هذا الشهر', 'this_month', null],

            // Explicit currency samples
            'EN: Sales last month in USD' => ['Sales last month in USD', 'last_month', 'USD'],
            'EN: Sales last month in ILS' => ['Sales last month in ILS', 'last_month', 'ILS'],
            'EN: Sales last month in JOD' => ['Sales last month in JOD', 'last_month', 'JOD'],
            'AR: مبيعات الشهر الماضي بالدولار' => ['مبيعات الشهر الماضي بالدولار', 'last_month', 'USD'],
            'AR: مبيعاتي الشهر الماضي بالشيكل' => ['مبيعاتي الشهر الماضي بالشيكل', 'last_month', 'ILS'],
            'AR: مبيعاتي الشهر الماضي بالدينار' => ['مبيعاتي الشهر الماضي بالدينار', 'last_month', 'JOD'],
        ];
    }

    #[DataProvider('missingPeriodClarificationProvider')]
    public function test_missing_period_phrases_require_clarification(string $phrase): void
    {
        $result = $this->classifier->classify($phrase);

        $this->assertNotNull($result, "Expected clarify_period for: {$phrase}");
        $this->assertSame('clarify_period', $result['kind']);
        $this->assertSame('sales.summary', $result['report_key']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function missingPeriodClarificationProvider(): array
    {
        return [
            'EN: Show my sales' => ['Show my sales'],
            'EN: Show our sales' => ['Show our sales'],
            'EN: Show me my sales' => ['Show me my sales'],
            'EN: sales summary' => ['sales summary'],
            'AR: كم مبيعاتي؟' => ['كم مبيعاتي؟'],
            'AR: كم مبيعاتنا؟' => ['كم مبيعاتنا؟'],
            'AR: اظهر مبيعاتي' => ['اظهر مبيعاتي'],
            'AR: عرض مبيعاتي' => ['عرض مبيعاتي'],
            'AR: ملخص المبيعات' => ['ملخص المبيعات'],
        ];
    }

    #[DataProvider('deniedQualifiersProvider')]
    public function test_denied_qualifiers_and_injections_refuse_with_question_invalid(string $phrase): void
    {
        try {
            $this->classifier->classify($phrase);
            $this->fail("Expected question_invalid exception for: {$phrase}");
        } catch (AssistantUnavailable $e) {
            $this->assertSame('question_invalid', $e->reason);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function deniedQualifiersProvider(): array
    {
        return [
            // Negation qualifiers
            'EN negation: What were sales excluding Ahmed today?' => ['What were sales excluding Ahmed today?'],
            'EN negation: What were my sales excluding Ahmed today?' => ['What were my sales excluding Ahmed today?'],
            'EN negation: What were my sales without tax today?' => ['What were my sales without tax today?'],
            'AR negation: مبيعات اليوم بدون أحمد' => ['مبيعات اليوم بدون أحمد'],
            'AR negation: مبيعاتي اليوم بدون أحمد' => ['مبيعاتي اليوم بدون أحمد'],
            'AR negation: مبيعات اليوم باستثناء فرع الخليل' => ['مبيعات اليوم باستثناء فرع الخليل'],

            // Unsupported comparisons / thresholds
            'EN comparison: What were my sales today compared to yesterday?' => ['What were my sales today compared to yesterday?'],
            'EN comparison: sales greater than 1000 today' => ['sales greater than 1000 today'],
            'AR comparison: مبيعاتي اليوم مقارنة بأمس' => ['مبيعاتي اليوم مقارنة بأمس'],
            'AR comparison: مبيعات اكثر من 500 اليوم' => ['مبيعات اكثر من 500 اليوم'],

            // Entity filters
            'EN entity: sales for Ahmed this month' => ['sales for Ahmed this month'],
            'EN entity: What were my sales for client Acme today?' => ['What were my sales for client Acme today?'],
            'AR entity: مبيعات أحمد هذا الشهر' => ['مبيعات أحمد هذا الشهر'],
            'AR entity: مبيعاتي اليوم للزبون أحمد' => ['مبيعاتي اليوم للزبون أحمد'],

            // Unsupported / unknown currencies
            'EN unknown currency: Sales today in EUR' => ['Sales today in EUR'],
            'AR unknown currency: مبيعات اليوم باليورو' => ['مبيعات اليوم باليورو'],

            // Contradictory periods / currencies
            'Contradictory currencies: Sales today in USD and ILS' => ['Sales today in USD and ILS'],
            'Contradictory periods: Sales today and yesterday' => ['Sales today and yesterday'],

            // SQL injection attempts
            'SQL injection: Show my sales; DROP TABLE companies;' => ['Show my sales; DROP TABLE companies;'],
            'SQL injection: What were my sales today -- select * from users' => ['What were my sales today -- select * from users'],
            'SQL injection: What were my sales today /* comment */' => ['What were my sales today /* comment */'],
        ];
    }

    #[DataProvider('unsupportedTopicsProvider')]
    public function test_unsupported_topics_without_tools_remain_safely_unsupported(string $phrase): void
    {
        try {
            $result = $this->classifier->classify($phrase);
            // If it returns, it must be null (never mapped to an unrelated report like sales or inventory)
            $this->assertNull($result, "Unsupported topic '{$phrase}' must not return a report plan.");
        } catch (AssistantUnavailable $e) {
            // Thrown exception with question_invalid is also safe refusal
            $this->assertSame('question_invalid', $e->reason);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsupportedTopicsProvider(): array
    {
        return [
            'Profit: What was our profit today?' => ['What was our profit today?'],
            'Profit AR: ما هو ربحنا اليوم؟' => ['ما هو ربحنا اليوم؟'],
            'Stock valuation: stock valuation today' => ['stock valuation today'],
            'Stock valuation AR: قيمة المخزون اليوم' => ['قيمة المخزون اليوم'],
        ];
    }

    #[DataProvider('transactionPhrasesProvider')]
    public function test_transaction_commands_route_to_transaction(string $phrase): void
    {
        $result = $this->classifier->classify($phrase);

        $this->assertNotNull($result);
        $this->assertSame('transaction', $result['kind']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function transactionPhrasesProvider(): array
    {
        return [
            'EN: Create an invoice for Ahmed' => ['Create an invoice for Ahmed'],
            'AR: جهز فاتورة لأحمد' => ['جهز فاتورة لأحمد'],
            'AR: أنشئ فاتورة جديدة' => ['أنشئ فاتورة جديدة'],
        ];
    }
}
