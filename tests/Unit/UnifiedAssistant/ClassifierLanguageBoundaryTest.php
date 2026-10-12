<?php

declare(strict_types=1);

namespace Tests\Unit\UnifiedAssistant;

use App\Application\AI\AssistantDeterministicClassifier;
use App\Application\AI\AssistantReportPlan;
use App\Application\AI\AssistantUnavailable;
use App\Application\Reporting\DTO\ReportPeriod;
use Tests\TestCase;

class ClassifierLanguageBoundaryTest extends TestCase
{
    private AssistantDeterministicClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new AssistantDeterministicClassifier;
    }

    /**
     * Valid Arabic queries must support both taa marbuta (ة) and haa (ه) normalization
     * across customer balances, vendor balances, item expiry, and historical period presets.
     */
    public function test_arabic_taa_marbuta_normalization_across_all_intents(): void
    {
        // 1. Customer balances
        $customerCases = [
            'أرصدة العملاء',
            'ارصده العملاء',
            'أرصدة الزبائن',
            'ارصده الزبائن',
            'ديون العملاء',
            'مستحقات العملاء',
            'مستحقات الزبائن',
        ];
        foreach ($customerCases as $query) {
            $result = $this->classifier->classify($query);
            $this->assertIsArray($result, "Expected query '{$query}' to be classified.");
            $this->assertSame('plan', $result['kind'] ?? null, "Query '{$query}' should produce a plan.");
            $this->assertInstanceOf(AssistantReportPlan::class, $result['plan']);
            $this->assertSame('customers.balances', $result['plan']->reportKey);
            $this->assertSame([], $result['plan']->filters);
        }

        // 2. Vendor balances
        $vendorCases = [
            'أرصدة الموردين',
            'ارصده الموردين',
            'ديون الموردين',
            'مستحقات الموردين',
            'كم للموردين',
            'كم علينا للموردين',
        ];
        foreach ($vendorCases as $query) {
            $result = $this->classifier->classify($query);
            $this->assertIsArray($result, "Expected query '{$query}' to be classified.");
            $this->assertSame('plan', $result['kind'] ?? null, "Query '{$query}' should produce a plan.");
            $this->assertInstanceOf(AssistantReportPlan::class, $result['plan']);
            $this->assertSame('vendors.balances', $result['plan']->reportKey);
            $this->assertSame([], $result['plan']->filters);
        }

        // 3. Item expiry with explicit preset
        $expiryCases = [
            'تقرير الصلاحية هذا الشهر',
            'تقرير الصلاحيه هذا الشهر',
            'صلاحية المواد هذا الشهر',
            'صلاحيه المواد هذا الشهر',
            'المخزون المنتهي هذا الشهر',
            'البضاعة المنتهية هذا الشهر',
            'البضاعه المنتهيه هذا الشهر',
        ];
        foreach ($expiryCases as $query) {
            $result = $this->classifier->classify($query);
            $this->assertIsArray($result, "Expected query '{$query}' to be classified.");
            $this->assertSame('plan', $result['kind'] ?? null, "Query '{$query}' should produce a plan.");
            $this->assertInstanceOf(AssistantReportPlan::class, $result['plan']);
            $this->assertSame('inventory.expiry', $result['plan']->reportKey);
            $this->assertSame(['preset' => ReportPeriod::PRESET_THIS_MONTH], $result['plan']->filters);
        }

        // 4. Last year preset normalization
        $lastYearCases = [
            'كم مبيعات السنة الماضية' => 'sales.summary',
            'كم مبيعات السنه الماضيه' => 'sales.summary',
            'ملخص المبيعات السنة الماضية' => 'sales.summary',
            'تقرير المبيعات العام الماضي' => 'sales.summary',
            'ملخص المشتريات السنة السابقة' => 'purchases.summary',
            'تقرير المشتريات السنه الماضيه' => 'purchases.summary',
        ];
        foreach ($lastYearCases as $query => $expectedReport) {
            $result = $this->classifier->classify($query);
            $this->assertIsArray($result, "Expected query '{$query}' to be classified.");
            $this->assertSame('plan', $result['kind'] ?? null, "Query '{$query}' should produce a plan.");
            $this->assertInstanceOf(AssistantReportPlan::class, $result['plan']);
            $this->assertSame($expectedReport, $result['plan']->reportKey);
            $this->assertSame(['preset' => ReportPeriod::PRESET_LAST_YEAR], $result['plan']->filters);
        }
    }

    /**
     * The Arabic common question meaning "How much are sales this month" must deterministically
     * resolve to sales.summary for this_month without calling any external provider.
     */
    public function test_arabic_common_question_how_much_sales_this_month_is_deterministic(): void
    {
        $phrasings = [
            'كم مبيعات هذا الشهر',
            'كم المبيعات هذا الشهر',
            'كم مبيعات الشهر الحالي',
            'كم بعنا هذا الشهر',
        ];

        foreach ($phrasings as $query) {
            $result = $this->classifier->classify($query);
            $this->assertIsArray($result, "Expected deterministic classification for '{$query}'.");
            $this->assertSame('plan', $result['kind'] ?? null);
            $this->assertInstanceOf(AssistantReportPlan::class, $result['plan']);
            $this->assertSame('sales.summary', $result['plan']->reportKey);
            $this->assertSame(['preset' => ReportPeriod::PRESET_THIS_MONTH], $result['plan']->filters);
        }
    }

    /**
     * All seven curated report intents in English must be recognized deterministically.
     */
    public function test_english_seven_report_intents(): void
    {
        $cases = [
            'sales summary this month' => ['sales.summary', ['preset' => ReportPeriod::PRESET_THIS_MONTH]],
            'how much did we sell this month' => ['sales.summary', ['preset' => ReportPeriod::PRESET_THIS_MONTH]],
            'customer balances' => ['customers.balances', []],
            'client balances' => ['customers.balances', []],
            'who owes us' => ['customers.balances', []],
            'purchases summary this month' => ['purchases.summary', ['preset' => ReportPeriod::PRESET_THIS_MONTH]],
            'how much did we buy this month' => ['purchases.summary', ['preset' => ReportPeriod::PRESET_THIS_MONTH]],
            'vendor balances' => ['vendors.balances', []],
            'supplier balances' => ['vendors.balances', []],
            'what do we owe vendors' => ['vendors.balances', []],
            'low stock this month' => ['inventory.low-stock', ['preset' => ReportPeriod::PRESET_THIS_MONTH]],
            'items running low this month' => ['inventory.low-stock', ['preset' => ReportPeriod::PRESET_THIS_MONTH]],
            'expiry report this month' => ['inventory.expiry', ['preset' => ReportPeriod::PRESET_THIS_MONTH]],
            'expired stock this month' => ['inventory.expiry', ['preset' => ReportPeriod::PRESET_THIS_MONTH]],
            'cash and bank balances' => ['money.balances', []],
            'cash balance' => ['money.balances', []],
            'bank balance' => ['money.balances', []],
        ];

        foreach ($cases as $query => [$expectedReport, $expectedFilters]) {
            $result = $this->classifier->classify($query);
            $this->assertIsArray($result, "Expected query '{$query}' to be classified.");
            $this->assertSame('plan', $result['kind'] ?? null, "Query '{$query}' should produce a plan.");
            $this->assertInstanceOf(AssistantReportPlan::class, $result['plan']);
            $this->assertSame($expectedReport, $result['plan']->reportKey);
            $this->assertSame($expectedFilters, $result['plan']->filters);
        }
    }

    /**
     * Mixed Arabic/English queries across all seven intents must be supported.
     */
    public function test_mixed_language_seven_report_intents(): void
    {
        $cases = [
            'sales summary هذا الشهر' => ['sales.summary', ['preset' => ReportPeriod::PRESET_THIS_MONTH]],
            'تقرير sales this month' => ['sales.summary', ['preset' => ReportPeriod::PRESET_THIS_MONTH]],
            'customer balances بالشيكل' => ['customers.balances', ['currency_code' => 'ILS']],
            'أرصدة customers' => ['customers.balances', []],
            'purchases summary هذا الشهر in USD' => ['purchases.summary', ['currency_code' => 'USD', 'preset' => ReportPeriod::PRESET_THIS_MONTH]],
            'ملخص purchases this month' => ['purchases.summary', ['preset' => ReportPeriod::PRESET_THIS_MONTH]],
            'vendor balances بالدينار' => ['vendors.balances', ['currency_code' => 'JOD']],
            'أرصدة suppliers' => ['vendors.balances', []],
            'low stock هذا الاسبوع' => ['inventory.low-stock', ['preset' => ReportPeriod::PRESET_THIS_WEEK]],
            'المخزون المنخفض this week' => ['inventory.low-stock', ['preset' => ReportPeriod::PRESET_THIS_WEEK]],
            'expiry report هذا الشهر' => ['inventory.expiry', ['preset' => ReportPeriod::PRESET_THIS_MONTH]],
            'تقرير expiry this month' => ['inventory.expiry', ['preset' => ReportPeriod::PRESET_THIS_MONTH]],
            'cash and bank بالدينار' => ['money.balances', ['currency_code' => 'JOD']],
            'رصيد bank in USD' => ['money.balances', ['currency_code' => 'USD']],
        ];

        foreach ($cases as $query => [$expectedReport, $expectedFilters]) {
            $result = $this->classifier->classify($query);
            $this->assertIsArray($result, "Expected query '{$query}' to be classified.");
            $this->assertSame('plan', $result['kind'] ?? null, "Query '{$query}' should produce a plan.");
            $this->assertInstanceOf(AssistantReportPlan::class, $result['plan']);
            $this->assertSame($expectedReport, $result['plan']->reportKey);
            $this->assertSame($expectedFilters, $result['plan']->filters);
        }
    }

    /**
     * Explicit period and currency tokens must be extracted into canonical filters.
     */
    public function test_explicit_period_and_currency_extraction(): void
    {
        // Periods
        $periodCases = [
            'sales summary today' => ReportPeriod::PRESET_TODAY,
            'sales summary yesterday' => ReportPeriod::PRESET_YESTERDAY,
            'sales summary this week' => ReportPeriod::PRESET_THIS_WEEK,
            'sales summary last week' => ReportPeriod::PRESET_LAST_WEEK,
            'sales summary this month' => ReportPeriod::PRESET_THIS_MONTH,
            'sales summary last month' => ReportPeriod::PRESET_LAST_MONTH,
            'sales summary this quarter' => ReportPeriod::PRESET_THIS_QUARTER,
            'sales summary this year' => ReportPeriod::PRESET_THIS_YEAR,
            'sales summary last year' => ReportPeriod::PRESET_LAST_YEAR,
        ];
        foreach ($periodCases as $query => $preset) {
            $result = $this->classifier->classify($query);
            $this->assertIsArray($result);
            $this->assertSame('plan', $result['kind']);
            $this->assertSame($preset, $result['plan']->filters['preset'] ?? null);
        }

        // Currencies
        $currencyCases = [
            'sales summary this month in ILS' => 'ILS',
            'sales summary this month in shekels' => 'ILS',
            'مبيعات هذا الشهر بالشيكل' => 'ILS',
            'مبيعات هذا الشهر شيكل' => 'ILS',
            'sales summary this month in USD' => 'USD',
            'sales summary this month in dollars' => 'USD',
            'مبيعات هذا الشهر بالدولار' => 'USD',
            'sales summary this month in JOD' => 'JOD',
            'sales summary this month in dinars' => 'JOD',
            'مبيعات هذا الشهر بالدينار' => 'JOD',
        ];
        foreach ($currencyCases as $query => $currency) {
            $result = $this->classifier->classify($query);
            $this->assertIsArray($result);
            $this->assertSame('plan', $result['kind']);
            $this->assertSame($currency, $result['plan']->filters['currency_code'] ?? null);
        }

        // Reports that do not support a currency filter must refuse the constraint.
        $inventoryCases = [
            'low stock this month in USD',
            'expiry report this month in ILS',
        ];
        foreach ($inventoryCases as $query) {
            try {
                $this->classifier->classify($query);
                $this->fail('Unsupported inventory currency must not be dropped.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason);
            }
        }
    }

    /**
     * Transaction draft commands in English and Arabic must be recognized and retained as 'transaction'.
     * Ambiguous mixes of transaction command and report keyword must be flagged as 'ambiguous_operation'.
     */
    public function test_transaction_draft_commands_retained(): void
    {
        $txDrafts = [
            'invoice for customer Ahmed',
            'sell to Rami 10 items',
            'sold to "Store": 5 carton SOAP at 80 ILS',
            'buy from "Vendor": 10 box MILK at 50 ILS',
            'bought from supplier Al-Amal',
            'purchase order for Acme',
            'فاتورة بيع للزبون خليل',
            'فاتورة مبيعات نقدية',
            'بعت 10 علب حليب بسعر 50 شيكل',
            'فاتورة شراء من التاجر سامر',
            'فاتورة مشتريات بضاعة',
            'اشتريت 20 كرتونة ماء',
        ];

        foreach ($txDrafts as $query) {
            $result = $this->classifier->classify($query);
            $this->assertIsArray($result, "Expected query '{$query}' to be classified as transaction draft.");
            $this->assertSame('transaction', $result['kind'] ?? null, "Query '{$query}' should have kind 'transaction'.");
        }

        // Ambiguous combination of draft and report keyword
        $ambiguousCases = [
            'فاتورة مبيعات و تقرير المبيعات',
            'بيع ملخص المبيعات',
        ];
        foreach ($ambiguousCases as $query) {
            $result = $this->classifier->classify($query);
            $this->assertIsArray($result, "Expected query '{$query}' to be flagged as ambiguous.");
            $this->assertSame('ambiguous_operation', $result['kind'] ?? null);
            $this->assertSame('sales.summary', $result['report_key'] ?? null);
        }
    }

    /**
     * Historical report queries missing an explicit period preset must request clarification,
     * retaining any detected currency filter.
     */
    public function test_missing_period_clarification(): void
    {
        $cases = [
            'sales summary' => ['sales.summary', []],
            'ملخص المبيعات' => ['sales.summary', []],
            'sales summary in USD' => ['sales.summary', ['currency_code' => 'USD']],
            'purchases summary' => ['purchases.summary', []],
            'ملخص المشتريات بالشيكل' => ['purchases.summary', ['currency_code' => 'ILS']],
            'low stock' => ['inventory.low-stock', []],
            'المخزون المنخفض' => ['inventory.low-stock', []],
            'expiry report' => ['inventory.expiry', []],
            'تقرير الصلاحية' => ['inventory.expiry', []],
        ];

        foreach ($cases as $query => [$expectedReport, $expectedFilters]) {
            $result = $this->classifier->classify($query);
            $this->assertIsArray($result, "Expected query '{$query}' to request period clarification.");
            $this->assertSame('clarify_period', $result['kind'] ?? null);
            $this->assertSame($expectedReport, $result['report_key'] ?? null);
            $this->assertSame($expectedFilters, $result['filters'] ?? null);
        }
    }

    /**
     * Current balance reports (customers.balances, vendors.balances, money.balances)
     * cannot accept historical period presets and must throw question_invalid.
     */
    public function test_current_balances_refuse_historical_presets(): void
    {
        $cases = [
            'customer balances last month',
            'customer balances this month',
            'vendor balances yesterday',
            'supplier balances last year',
            'cash balance last month',
            'money balances this week',
            'ارصدة العملاء الشهر الماضي',
            'أرصدة العملاء هذا الشهر',
            'ارصدة الموردين الاسبوع الماضي',
            'أرصدة الموردين الشهر السابق',
            'رصيد الصندوق الشهر الماضي',
            'رصيد البنك السنة الماضية',
        ];

        foreach ($cases as $query) {
            try {
                $this->classifier->classify($query);
                $this->fail("Current balance query '{$query}' with historical preset must throw AssistantUnavailable('question_invalid').");
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason, "Query '{$query}' failed with unexpected reason '{$e->reason}'.");
            }
        }
    }

    /**
     * Contradictory currencies or contradictory periods must never silently resolve
     * to an arbitrarily chosen single currency or single period.
     */
    public function test_contradictory_currencies_and_periods_do_not_produce_silent_single_value_plan(): void
    {
        $contradictoryCurrencies = [
            'sales summary this month in USD and ILS',
            'sales summary this month in ILS and JOD',
            'مبيعات هذا الشهر بالدولار والشيكل',
            'customer balances in ILS and USD',
        ];

        foreach ($contradictoryCurrencies as $query) {
            $this->assertSafeRefusalOrClarification($query);
        }

        $contradictoryPeriods = [
            'sales summary this month and last month',
            'sales summary today and yesterday',
            'مبيعات هذا الشهر والشهر الماضي',
            'purchases summary this week and last year',
        ];

        foreach ($contradictoryPeriods as $query) {
            $this->assertSafeRefusalOrClarification($query);
        }
    }

    /**
     * Queries specifying a named customer or vendor must not silently drop the entity filter
     * and emit a broad, unconstrained report for all customers/vendors.
     */
    public function test_named_customer_and_vendor_filters_do_not_silently_produce_broad_reports(): void
    {
        $queries = [
            'customer balances for customer Ahmed',
            'ارصدة العملاء للزبون خليل',
            'sales summary this month for client Acme',
            'vendor balances for supplier Al-Amal',
            'ارصدة الموردين للشركة العالمية',
            'purchases summary this month from vendor Horizon',
        ];

        foreach ($queries as $query) {
            $this->assertSafeRefusalOrClarification($query);
        }
    }

    /**
     * Queries containing negation or unsupported comparisons/thresholds must not silently
     * ignore the restriction and return broad or economically altered answers.
     */
    public function test_negation_and_unsupported_comparisons_fail_closed(): void
    {
        $queries = [
            'sales summary this month not including cash',
            'مبيعات هذا الشهر بدون نقدي',
            'sales summary this month except returns',
            'purchases summary this month excluding tax',
            'sales summary this month compared to last year',
            'مبيعات هذا الشهر مقارنة بالشهر الماضي',
            'sales this month greater than 1000',
            'مبيعات هذا الشهر اعلى من 500',
            'sales summary this month top 10 items',
        ];

        foreach ($queries as $query) {
            $this->assertSafeRefusalOrClarification($query);
        }
    }

    /**
     * Empty input, whitespace-only, text exceeding max character limits, and completely
     * unrelated queries must safely return null.
     */
    public function test_boundary_safety_and_unrelated_inputs_fail_closed(): void
    {
        $this->assertNull($this->classifier->classify(''));
        $this->assertNull($this->classifier->classify('   '));
        $this->assertNull($this->classifier->classify("\t\n  \r"));
        $this->assertNull($this->classifier->classify(str_repeat('word ', 1000))); // exceeds 4000 chars

        $unrelated = [
            'What is the capital of France?',
            'مرحبا كيف حالك اليوم',
            'Create a new user account with role admin',
            'Please update the system configuration',
        ];

        foreach ($unrelated as $query) {
            $this->assertNull($this->classifier->classify($query), "Unrelated query '{$query}' should return null.");
        }
    }

    /**
     * Followup question changing period preset updates the plan without cached value bleed.
     * (samequestionlastmonth)
     */
    public function test_classify_followup_same_question_last_month(): void
    {
        $planEn = $this->classifier->classifyFollowup('last month', 'sales.summary', ['preset' => 'this_month']);
        $this->assertInstanceOf(AssistantReportPlan::class, $planEn);
        $this->assertSame('sales.summary', $planEn->reportKey);
        $this->assertSame(['preset' => ReportPeriod::PRESET_LAST_MONTH], $planEn->filters);

        $planAr = $this->classifier->classifyFollowup('الشهر الماضي', 'sales.summary', ['preset' => 'this_month']);
        $this->assertInstanceOf(AssistantReportPlan::class, $planAr);
        $this->assertSame('sales.summary', $planAr->reportKey);
        $this->assertSame(['preset' => ReportPeriod::PRESET_LAST_MONTH], $planAr->filters);

        $planWeek = $this->classifier->classifyFollowup('الاسبوع الماضي', 'purchases.summary', ['preset' => 'this_week']);
        $this->assertInstanceOf(AssistantReportPlan::class, $planWeek);
        $this->assertSame('purchases.summary', $planWeek->reportKey);
        $this->assertSame(['preset' => ReportPeriod::PRESET_LAST_WEEK], $planWeek->filters);
    }

    /**
     * Followup attempting to apply a historical preset to a current balance report must be refused.
     * (currentrefusal)
     */
    public function test_classify_followup_current_balance_refusal(): void
    {
        $refusals = [
            ['customers.balances', 'last month'],
            ['customers.balances', 'الشهر الماضي'],
            ['vendors.balances', 'yesterday'],
            ['vendors.balances', 'الاسبوع الماضي'],
            ['money.balances', 'this year'],
        ];

        foreach ($refusals as [$reportKey, $followupText]) {
            try {
                $this->classifier->classifyFollowup($followupText, $reportKey, []);
                $this->fail("Expected followup '{$followupText}' on current report '{$reportKey}' to throw question_invalid.");
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason);
            }
        }
    }

    /**
     * Followup changing or adding currency overrides metadata correctly.
     * (currencyoverrides)
     */
    public function test_classify_followup_currency_overrides(): void
    {
        // Override currency on historical report
        $plan = $this->classifier->classifyFollowup('بالشيكل', 'sales.summary', ['preset' => 'this_month', 'currency_code' => 'USD']);
        $this->assertInstanceOf(AssistantReportPlan::class, $plan);
        $this->assertSame('sales.summary', $plan->reportKey);
        $this->assertSame(['preset' => 'this_month', 'currency_code' => 'ILS'], $plan->filters);

        // Add currency to current balance report
        $planBalances = $this->classifier->classifyFollowup('in JOD', 'customers.balances', []);
        $this->assertInstanceOf(AssistantReportPlan::class, $planBalances);
        $this->assertSame('customers.balances', $planBalances->reportKey);
        $this->assertSame(['currency_code' => 'JOD'], $planBalances->filters);

        // Override currency on current balance report
        $planVendor = $this->classifier->classifyFollowup('بالدولار', 'vendors.balances', ['currency_code' => 'JOD']);
        $this->assertInstanceOf(AssistantReportPlan::class, $planVendor);
        $this->assertSame('vendors.balances', $planVendor->reportKey);
        $this->assertSame(['currency_code' => 'USD'], $planVendor->filters);
    }

    /**
     * Followup with entity filters, SQL injection, negation, or invalid cached metadata must be refused.
     * (invalidmetadata)
     */
    public function test_classify_followup_refuses_invalid_metadata_and_qualifiers(): void
    {
        $invalidQueries = [
            'for customer Ahmed',
            'without cash',
            'SELECT * FROM users',
            'in USD and ILS',
            'greater than 1000',
        ];

        foreach ($invalidQueries as $text) {
            try {
                $this->classifier->classifyFollowup($text, 'sales.summary', ['preset' => 'this_month']);
                $this->fail("Expected followup '{$text}' with invalid qualifiers to be refused.");
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason);
            }
        }

        // New report question or transaction command in followup returns null (routed back to classify)
        $this->assertNull($this->classifier->classifyFollowup('customer balances', 'sales.summary', ['preset' => 'this_month']));
        $this->assertNull($this->classifier->classifyFollowup('فاتورة بيع', 'sales.summary', ['preset' => 'this_month']));

        // Cached filters containing untrusted / invalid keys are stripped
        $planSanitized = $this->classifier->classifyFollowup('last month', 'sales.summary', [
            'preset' => 'this_month',
            'customer_id' => '999',
            'sql' => 'DROP TABLE users',
        ]);
        $this->assertInstanceOf(AssistantReportPlan::class, $planSanitized);
        $this->assertSame(['preset' => 'last_month'], $planSanitized->filters);
        $this->assertArrayNotHasKey('customer_id', $planSanitized->filters);
        $this->assertArrayNotHasKey('sql', $planSanitized->filters);
    }

    /**
     * Followup on inventory expiry handles bounded context (period allowed, currency stripped per definition).
     * (expiry handledcontextelsewhere)
     */
    public function test_classify_followup_expiry_context_handling(): void
    {
        // Period followup on expiry report succeeds
        $planPeriod = $this->classifier->classifyFollowup('الشهر الماضي', 'inventory.expiry', []);
        $this->assertInstanceOf(AssistantReportPlan::class, $planPeriod);
        $this->assertSame('inventory.expiry', $planPeriod->reportKey);
        $this->assertSame(['preset' => ReportPeriod::PRESET_LAST_MONTH], $planPeriod->filters);

        // Currency followup on expiry report: currency_code is not supported by definition filters
        $this->expectException(AssistantUnavailable::class);
        $this->classifier->classifyFollowup('in USD', 'inventory.expiry', ['preset' => 'this_month']);
    }

    /**
     * hasUnsupportedQualifiers / isUnsupported correctly flags qualifiers that must not get provider broad plans.
     */
    public function test_has_unsupported_qualifiers_guard(): void
    {
        $unsupported = [
            'sales summary this month for customer Ahmed',
            'customer balances للزبون خليل',
            'sales summary this month not including cash',
            'مبيعات هذا الشهر بدون نقدي',
            'sales summary this month compared to last year',
            'مبيعات هذا الشهر اعلى من 500',
            'sales summary this month in USD and ILS',
            'sales summary this month and last month',
            'sales summary and purchases summary',
            'SELECT * FROM invoices',
        ];

        foreach ($unsupported as $text) {
            $this->assertTrue($this->classifier->hasUnsupportedQualifiers($text), "Expected '{$text}' to be flagged as unsupported.");
            $this->assertTrue($this->classifier->isUnsupported($text), 'Expected isUnsupported() to match hasUnsupportedQualifiers().');
        }

        $supported = [
            'sales summary this month',
            'كم مبيعات هذا الشهر',
            'customer balances',
            'أرصدة العملاء',
            'last month',
            'in USD',
            'بالشيكل',
        ];

        foreach ($supported as $text) {
            $this->assertFalse($this->classifier->hasUnsupportedQualifiers($text), "Expected '{$text}' not to be flagged as unsupported.");
            $this->assertFalse($this->classifier->isUnsupported($text));
        }
    }

    /**
     * Helper asserting that an input does not silently produce an unconstrained or altered plan.
     * Must return null, clarification, or throw AssistantUnavailable.
     */
    private function assertSafeRefusalOrClarification(string $query): void
    {
        try {
            $result = $this->classifier->classify($query);
            if ($result === null) {
                $this->assertNull($result);

                return;
            }

            $kind = $result['kind'] ?? '';
            $this->assertNotSame('plan', $kind, "Query '{$query}' must not silently resolve to an unconstrained or altered plan.");
            $this->assertContains($kind, ['clarify_period', 'ambiguous_operation']);
        } catch (AssistantUnavailable $e) {
            $this->assertContains($e->reason, ['question_invalid', 'question_unsupported']);
        }
    }
}
