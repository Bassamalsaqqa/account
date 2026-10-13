<?php

declare(strict_types=1);

namespace Tests\Unit\AiV22;

use App\Application\AI\AssistantTurnConstraints;
use App\Application\AI\AssistantUnavailable;
use App\Models\Company;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AssistantTurnConstraintsTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, string> $filters */
    private function proposal(array $filters): array
    {
        return ['version' => 1, 'type' => 'report_query', 'report_key' => 'sales.summary', 'filters' => $filters, 'display' => 'summary'];
    }

    private function company(): Company
    {
        return new Company(['timezone' => 'Asia/Hebron']);
    }

    /** @param array<string, string> $filters */
    #[DataProvider('preservedConstraints')]
    public function test_preserves_explicit_constraints_without_a_fixed_language_grammar(string $text, array $filters): void
    {
        CarbonImmutable::setTestNow('2026-10-13 12:00:00');
        app(AssistantTurnConstraints::class)->assert($text, [$this->proposal($filters)], $this->company());
        $this->addToAssertionCount(1);
    }

    public static function preservedConstraints(): array
    {
        return [
            'dialect month comparison' => ['قارن مبيعات هالشهر بالشهر الماضي.', ['preset' => 'this_month', 'compare' => 'previous_period']],
            'English month comparison' => ['Compare sales this month versus last month', ['preset' => 'this_month', 'compare' => 'previous_period']],
            'year comparison' => ['Compare sales this year with last year', ['preset' => 'this_year', 'compare' => 'previous_year']],
            'single period' => ['قديش بعنا هالشهر بالدولار؟', ['preset' => 'this_month', 'currency_code' => 'USD']],
            'canonical date equivalent' => ['sales this month', ['start_date' => '2026-10-01', 'end_date' => '2026-10-31']],
            'explicit ISO interval' => ['Sales from 2026-09-01 to 2026-09-30 in ILS', ['start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'currency_code' => 'ILS']],
            'NIS alias' => ['Sales in NIS today', ['preset' => 'today', 'currency_code' => 'ILS']],
            'nondefault ISO retained for canonical validation' => ['Sales this month in EUR', ['preset' => 'this_month', 'currency_code' => 'EUR']],
            'natural vocabulary and entity labels' => ['يا ريت تورجيني كيف الامور ماشية مع الزبون أحمد', ['preset' => 'this_month', 'customer_name' => 'أحمد']],
            'no fixed vocabulary rejection' => ['Would you kindly tell me how the business is doing?', ['preset' => 'this_month']],
            'quoted customer name' => ['Sales for customer "Ahmed Ziad"', ['preset' => 'this_month', 'customer_name' => 'Ahmed Ziad']],
            'Arabic labelled customer' => ['قديش للزبون أَحمد دين', ['preset' => 'this_month', 'customer_name' => 'احمد']],
        ];
    }

    /** @param array<string, string> $filters */
    #[DataProvider('lostConstraints')]
    public function test_refuses_dropped_or_changed_explicit_constraints(string $text, array $filters): void
    {
        try {
            app(AssistantTurnConstraints::class)->assert($text, [$this->proposal($filters)], $this->company());
            self::fail('An explicit request constraint was silently changed or discarded.');
        } catch (AssistantUnavailable $error) {
            self::assertSame('question_invalid', $error->reason);
        }
    }

    public static function lostConstraints(): array
    {
        return [
            'USD dropped' => ['Sales this month in USD', ['preset' => 'this_month']],
            'USD replaced' => ['قديش بعنا هالشهر بالدولار؟', ['preset' => 'this_month', 'currency_code' => 'ILS']],
            'EUR cannot become company default' => ['Sales this month in EUR', ['preset' => 'this_month']],
            'explicit unknown ISO cannot be ignored' => ['Sales currency XYZ', ['preset' => 'this_month']],
            'contradictory currencies' => ['Sales in USD and ILS', ['preset' => 'this_month', 'currency_code' => 'USD']],
            'period dropped' => ['Show sales last month', ['preset' => 'this_month']],
            'Palestinian month dropped' => ['قديش مبيعات هالشهر؟', ['preset' => 'last_month']],
            'comparison dropped' => ['قارن مبيعات هالشهر بالشهر الماضي', ['preset' => 'this_month']],
            'comparison reversed' => ['Compare this month and last month', ['preset' => 'last_month', 'compare' => 'previous_period']],
            'prior year changed to prior month' => ['Compare sales this month to last year', ['preset' => 'this_month', 'compare' => 'previous_period']],
            'one date is incomplete' => ['sales from 2026-09-01', ['preset' => 'last_month']],
            'explicit dates changed' => ['sales from 2026-09-01 to 2026-09-30', ['start_date' => '2026-09-02', 'end_date' => '2026-09-30']],
            'invalid calendar date' => ['sales from 2026-02-30 to 2026-03-02', ['start_date' => '2026-02-28', 'end_date' => '2026-03-02']],
            'unrepresentable contradictory periods' => ['sales this month and last week', ['preset' => 'this_month', 'compare' => 'previous_period']],
            'substituted entity' => ['Sales for customer Ahmed', ['preset' => 'this_month', 'customer_name' => 'Other']],
            'quoted named qualifier dropped' => ['Sales for customer "Ahmed Ziad"', ['preset' => 'this_month']],
            'Arabic named qualifier dropped' => ['قديش للزبون أحمد دين', ['preset' => 'this_month']],
            'partial name is not provenance' => ['Sales for customer Ahmed', ['preset' => 'this_month', 'customer_name' => 'Ahmed Ziad']],
        ];
    }

    public function test_same_customer_followup_may_reuse_only_prior_request_labels(): void
    {
        app(AssistantTurnConstraints::class)->assert('طيب وبالنسبة لنفس الزبون؟', [
            $this->proposal(['preset' => 'this_month', 'customer_name' => 'Ahmed']),
        ], $this->company(), [
            $this->proposal(['preset' => 'last_month', 'customer_name' => 'Ahmed']),
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_prior_customer_cannot_supply_an_unrequested_vendor(): void
    {
        $this->expectException(AssistantUnavailable::class);
        app(AssistantTurnConstraints::class)->assert('And the same party?', [
            $this->proposal(['preset' => 'this_month', 'vendor_name' => 'Ahmed']),
        ], $this->company(), [
            $this->proposal(['preset' => 'last_month', 'customer_name' => 'Ahmed']),
        ]);
    }

    public function test_every_returned_proposal_must_preserve_the_requested_currency(): void
    {
        $this->expectException(AssistantUnavailable::class);
        app(AssistantTurnConstraints::class)->assert('Sales in USD', [
            $this->proposal(['preset' => 'this_month', 'currency_code' => 'USD']),
            $this->proposal(['preset' => 'last_month']),
        ], $this->company());
    }

    #[DataProvider('invoiceQueries')]
    public function test_invoice_reads_do_not_capture_creation_commands(string $text, bool $expected): void
    {
        self::assertSame($expected, app(AssistantTurnConstraints::class)->isReadInvoiceQuestion($text));
    }

    public static function invoiceQueries(): array
    {
        return [
            ['قديش على أحمد دين؟ وشو آخر فاتورة إله؟', true],
            ['What is Ahmed’s latest invoice?', true],
            ['Show the most recent sales invoice', true],
            ['اعمل فاتورة لأحمد مثل آخر فاتورة', false],
            ['Create a sales invoice like the last invoice', false],
            ['Prepare the latest invoice as a new draft', false],
            ['بدي فاتورة جديدة مثل آخر فاتورة', false],
            ['قديش مبيعات هالشهر', false],
        ];
    }
}
