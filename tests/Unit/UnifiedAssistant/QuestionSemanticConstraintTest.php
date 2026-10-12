<?php

declare(strict_types=1);

namespace Tests\Unit\UnifiedAssistant;

use App\Application\AI\AssistantDeterministicClassifier;
use App\Application\AI\AssistantQuestionPlanner;
use App\Application\AI\AssistantUnavailable;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuestionSemanticConstraintTest extends TestCase
{
    private AssistantDeterministicClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = app(AssistantDeterministicClassifier::class);
    }

    public function test_positive_broad_sales_and_arabic_questions_remain_supported(): void
    {
        // Positive broad sales today USD
        $planTodayUsd = $this->classifier->classify('sales today USD');
        $this->assertNotNull($planTodayUsd);
        $this->assertSame('plan', $planTodayUsd['kind']);
        $this->assertSame('sales.summary', $planTodayUsd['plan']->reportKey);
        $this->assertSame(['currency_code' => 'USD', 'preset' => 'today'], $planTodayUsd['plan']->filters);

        $planTodayInUsd = $this->classifier->classify('sales today in USD');
        $this->assertNotNull($planTodayInUsd);
        $this->assertSame('plan', $planTodayInUsd['kind']);
        $this->assertSame('sales.summary', $planTodayInUsd['plan']->reportKey);
        $this->assertSame(['currency_code' => 'USD', 'preset' => 'today'], $planTodayInUsd['plan']->filters);

        // Positive Arabic how much sales month
        $planArSalesMonth = $this->classifier->classify('كم مبيعات هذا الشهر');
        $this->assertNotNull($planArSalesMonth);
        $this->assertSame('plan', $planArSalesMonth['kind']);
        $this->assertSame('sales.summary', $planArSalesMonth['plan']->reportKey);
        $this->assertSame(['preset' => 'this_month'], $planArSalesMonth['plan']->filters);
    }

    public function test_unsupported_named_customer_vendor_product_and_location_constraints_in_report_query(): void
    {
        $cases = [
            // Named customer/client constraints
            'sales for Ahmed this month',
            'مبيعات أحمد هذا الشهر',
            'مبيعات احمد هذا الشهر',
            'customer balances Ahmed',
            'ارصدة العملاء احمد',
            'رصيد العميل احمد',

            // Named vendor constraints
            'purchases from Acme today',
            'مشتريات من شركة الأمل اليوم',
            'مشتريات من النور اليوم',

            // Product & location constraints
            'low stock product SOAP in Hebron today',
            'مخزون منخفض صابون في الخليل اليوم',
            'بضاعة ناقصة صابون في الخليل اليوم',

            // Apostrophe possessive
            "Ahmed's sales this month",
            "Acme's purchases today",
            "Ahmed's customer balances",

            // By / from / for / in names
            'sales by Ahmed',
            'sales by John this week',
            'purchases by Ahmed today',
            'sales for Ahmed',
            'purchases from Acme',
            'low stock in Hebron',
            'stock in Hebron today',
            'sales in Hebron this month',
            'مبيعات في الخليل هذا الشهر',
            'بضاعة ناقصة في الخليل',
        ];

        foreach ($cases as $query) {
            try {
                $result = $this->classifier->classify($query);
                $this->fail("Expected query with unsupported named constraint to be rejected, but got: {$query} => ".json_encode($result));
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason, "Expected question_invalid for '{$query}', got '{$e->reason}'");
            }
        }
    }

    public function test_unsupported_named_constraints_in_followup(): void
    {
        $followupCases = [
            'for Ahmed',
            'from Acme',
            "Ahmed's",
            'by Ahmed',
            'in Hebron',
            'لأحمد',
            'من شركة النور',
            'في الخليل',
        ];

        foreach ($followupCases as $followup) {
            try {
                $result = $this->classifier->classifyFollowup($followup, 'sales.summary', ['preset' => 'this_month']);
                $this->fail("Expected followup with unsupported named constraint to be rejected, but got: {$followup} => ".json_encode($result));
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason, "Expected question_invalid for followup '{$followup}', got '{$e->reason}'");
            }
        }
    }

    public function test_unsupported_inventory_currency_raises_question_invalid_not_silently_dropped(): void
    {
        $classifyCases = [
            'low stock in USD',
            'low stock USD',
            'مخزون منخفض بالدولار',
            'inventory expiry in USD',
            'تقرير الصلاحية بالدولار',
        ];

        foreach ($classifyCases as $query) {
            try {
                $result = $this->classifier->classify($query);
                $this->fail("Expected inventory query with unsupported currency to throw question_invalid, but got: {$query} => ".json_encode($result));
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason, "Expected question_invalid for inventory currency '{$query}', got '{$e->reason}'");
            }
        }

        // Followup inventory questions with currency must also throw question_invalid
        $followupCases = [
            ['inventory.low-stock', 'USD'],
            ['inventory.low-stock', 'بالدولار'],
            ['inventory.expiry', 'USD'],
            ['inventory.expiry', 'بالدولار'],
        ];

        foreach ($followupCases as [$reportKey, $currencyText]) {
            try {
                $result = $this->classifier->classifyFollowup($currencyText, $reportKey, []);
                $this->fail("Expected inventory followup with currency to throw question_invalid, but got: {$reportKey} + {$currencyText} => ".json_encode($result));
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason, "Expected question_invalid for inventory followup '{$currencyText}', got '{$e->reason}'");
            }
        }
    }

    public function test_unsupported_currencies_refused_in_classify_and_followup(): void
    {
        $classifyCases = [
            'sales this month in EUR',
            'sales this month in euro',
            'sales today in GBP',
            'مبيعات هذا الشهر باليورو',
            'مشتريات اليوم بالجنيه الاسترليني',
        ];

        foreach ($classifyCases as $query) {
            try {
                $result = $this->classifier->classify($query);
                $this->fail("Expected unsupported currency query to be rejected, but got: {$query} => ".json_encode($result));
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason, "Expected question_invalid for '{$query}', got '{$e->reason}'");
            }
        }

        $followupCases = [
            'EUR',
            'in euro',
            'GBP',
            'باليورو',
        ];

        foreach ($followupCases as $followup) {
            try {
                $result = $this->classifier->classifyFollowup($followup, 'sales.summary', ['preset' => 'this_month']);
                $this->fail("Expected unsupported currency followup to be rejected, but got: {$followup} => ".json_encode($result));
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason, "Expected question_invalid for followup '{$followup}', got '{$e->reason}'");
            }
        }
    }

    public function test_custom_dates_and_future_periods_and_contradictions_refused(): void
    {
        $classifyCases = [
            // Custom dates
            'sales on 2026-05-01',
            'sales in May',
            'مبيعات يوم 2026-05-01',
            'مبيعات شهر مايو',

            // Future periods
            'sales next week',
            'sales tomorrow',
            'مبيعات الأسبوع القادم',
            'مبيعات غدا',
            'مبيعات بكرة',

            // Contradictions: today plus yesterday
            'sales today and yesterday',
            'purchases today yesterday',
            'مبيعات اليوم وأمس',
        ];

        foreach ($classifyCases as $query) {
            try {
                $result = $this->classifier->classify($query);
                $this->fail("Expected custom/future/contradictory query to be rejected, but got: {$query} => ".json_encode($result));
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason, "Expected question_invalid for '{$query}', got '{$e->reason}'");
            }
        }

        $followupCases = [
            '2026-05-01',
            'in May',
            'next week',
            'tomorrow',
            'الأسبوع القادم',
            'غدا',
            'today and yesterday',
            'اليوم وأمس',
        ];

        foreach ($followupCases as $followup) {
            try {
                $result = $this->classifier->classifyFollowup($followup, 'sales.summary', ['preset' => 'this_month']);
                $this->fail("Expected custom/future/contradictory followup to be rejected, but got: {$followup} => ".json_encode($result));
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason, "Expected question_invalid for followup '{$followup}', got '{$e->reason}'");
            }
        }
    }

    public function test_expand_general_invoice_create_arabic_and_informal_english_transactions(): void
    {
        $txCases = [
            // Informal English invoice creation and buy/sell commands
            'create invoice',
            'make invoice',
            'draft invoice',
            'create a new invoice',
            'buy 5 boxes',
            'sell 10 items',
            'create invoice for Ahmed',
            'buy 10 items from Acme',
            'sell 3 shirts to Ahmed',

            // Arabic invoice creation commands
            'انشئ فاتورة',
            'إنشاء فاتورة',
            'اعمل فاتورة',
            'سوي فاتورة',
            'فاتورة جديدة',
        ];

        foreach ($txCases as $query) {
            $result = $this->classifier->classify($query);
            $this->assertIsArray($result, "Expected query '{$query}' to be classified as transaction, got null");
            $this->assertSame('transaction', $result['kind'] ?? null, "Expected query '{$query}' kind to be transaction, got: ".json_encode($result));
        }
    }

    public function test_planner_refuses_unsupported_semantic_constraints(): void
    {
        $planner = app(AssistantQuestionPlanner::class);
        $company = new Company;
        $company->id = 1;
        $actor = new User;
        $actor->id = 1;
        $formKey = (string) Str::uuid();
        $sessionId = 'test-session-planner';

        $unsupportedQueries = [
            'sales for Ahmed this month',
            'purchases from Acme today',
            'low stock in USD',
            'sales this month in EUR',
            'sales next week',
        ];

        foreach ($unsupportedQueries as $query) {
            try {
                $planner->plan($company, $actor, $query, $formKey, $sessionId);
                $this->fail("Expected planner to refuse '{$query}' with question_invalid");
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason, "Expected planner to reject '{$query}' with question_invalid, got '{$e->reason}'");
            }
        }
    }
}
