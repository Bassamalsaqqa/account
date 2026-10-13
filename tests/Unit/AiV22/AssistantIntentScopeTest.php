<?php

declare(strict_types=1);

namespace Tests\Unit\AiV22;

use App\Application\AI\AssistantIntentProposal;
use App\Application\AI\AssistantIntentScope;
use App\Application\AI\AssistantTurnConstraints;
use App\Application\AI\AssistantUnavailable;
use App\Models\Company;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AssistantIntentScopeTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private static function proposal(string $key, array $filters, ?string $fragment = null): array
    {
        $proposal = ['version' => 1, 'type' => 'report_query', 'report_key' => $key, 'filters' => $filters, 'display' => 'summary'];
        if ($fragment !== null) {
            $proposal['request_fragment'] = $fragment;
        }

        return $proposal;
    }

    /** @param list<array<string, mixed>> $proposals */
    #[DataProvider('independentRequests')]
    public function test_independent_qualifiers_are_verified_in_their_own_complete_scope(string $text, array $proposals): void
    {
        CarbonImmutable::setTestNow('2026-10-13 12:00:00');
        $scopes = app(AssistantIntentScope::class)->associate($text, $proposals);
        self::assertCount(count($proposals), $scopes);
        foreach ($scopes as $scope) {
            self::assertArrayNotHasKey('request_fragment', $scope['proposal']);
            app(AssistantTurnConstraints::class)->assert($scope['text'], [$scope['proposal']], new Company(['timezone' => 'Asia/Hebron']));
            $this->addToAssertionCount(1);
        }
    }

    /** @return array<string, array{string, list<array<string, mixed>>}> */
    public static function independentRequests(): array
    {
        return [
            'architect exact English wording' => ["What's Ahmed's outstanding balance, and what were our sales this month?", [
                self::proposal('customers.balances', ['customer_name' => 'Ahmed']),
                self::proposal('sales.summary', ['preset' => 'this_month']),
            ]],
            'natural request prefix with fragments' => ["What's Ahmed's outstanding balance, and tell me about our sales this month?", [
                self::proposal('customers.balances', ['customer_name' => 'Ahmed'], "What's Ahmed's outstanding balance"),
                self::proposal('sales.summary', ['preset' => 'this_month'], 'tell me about our sales this month?'),
            ]],
            'English current debt and independent monthly sales' => ['Balance for customer Ahmed and sales this month', [
                self::proposal('customers.balances', ['customer_name' => 'Ahmed']),
                self::proposal('sales.summary', ['preset' => 'this_month']),
            ]],
            'Arabic independent debt and comparison' => ['قديش على أحمد دين؟ قارن مبيعات هالشهر بالشهر الماضي.', [
                self::proposal('customers.balances', ['customer_name' => 'أحمد']),
                self::proposal('sales.summary', ['preset' => 'this_month', 'compare' => 'previous_period']),
            ]],
            'Arabic independent currency and party' => ['رصيد للزبون أحمد بالدولار ورصيد للمورد عمر بالدينار', [
                self::proposal('customers.balances', ['customer_name' => 'أحمد', 'currency_code' => 'USD']),
                self::proposal('vendors.balances', ['vendor_name' => 'عمر', 'currency_code' => 'JOD']),
            ]],
            'date pairs and different currencies' => ['Sales for customer Ahmed from 2026-09-01 to 2026-09-30 in USD; Purchases for vendor Omar from 2026-10-01 to 2026-10-12 in JOD', [
                self::proposal('sales.by-customer', ['customer_name' => 'Ahmed', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'currency_code' => 'USD']),
                self::proposal('purchases.by-vendor', ['vendor_name' => 'Omar', 'start_date' => '2026-10-01', 'end_date' => '2026-10-12', 'currency_code' => 'JOD']),
            ]],
            'distinct period comparisons' => ['Compare sales this month versus last month; Compare purchases this year versus last year', [
                self::proposal('sales.summary', ['preset' => 'this_month', 'compare' => 'previous_period']),
                self::proposal('purchases.summary', ['preset' => 'this_year', 'compare' => 'previous_year']),
            ]],
            'same domain with exact bounded fragments' => ['Sales for customer Ahmed this month in USD; Sales for customer Omar last month in JOD', [
                self::proposal('sales.by-customer', ['customer_name' => 'Ahmed', 'preset' => 'this_month', 'currency_code' => 'USD'], 'Sales for customer Ahmed this month in USD'),
                self::proposal('sales.by-customer', ['customer_name' => 'Omar', 'preset' => 'last_month', 'currency_code' => 'JOD'], 'Sales for customer Omar last month in JOD'),
            ]],
            'mixed Arabic and English' => ['قديش للزبون أحمد دين بالدولار؛ Purchases for vendor Omar last month in ILS', [
                self::proposal('customers.balances', ['customer_name' => 'أحمد', 'currency_code' => 'USD']),
                self::proposal('purchases.by-vendor', ['vendor_name' => 'Omar', 'preset' => 'last_month', 'currency_code' => 'ILS']),
            ]],
            'shared customer debt and pronoun latest invoice' => ['قديش على أحمد دين؟ وشو آخر فاتورة إله؟', [
                self::proposal('customers.balances', ['customer_name' => 'أحمد']),
                self::proposal('sales.last-invoice', ['customer_name' => 'أحمد']),
            ]],
            'shared English customer' => ['What is customer Ahmed debt and his latest invoice?', [
                self::proposal('customers.balances', ['customer_name' => 'Ahmed']),
                self::proposal('sales.last-invoice', ['customer_name' => 'Ahmed']),
            ]],
        ];
    }

    /** @param list<array<string, mixed>> $proposals */
    #[DataProvider('invalidAssociations')]
    public function test_cannot_drop_a_clause_or_trust_model_fragment_boundaries(string $text, array $proposals): void
    {
        $this->expectException(AssistantUnavailable::class);
        app(AssistantIntentScope::class)->associate($text, $proposals);
    }

    /** @return array<string, array{string, list<array<string, mixed>>}> */
    public static function invalidAssociations(): array
    {
        return [
            'missing independent report' => ['Balance for customer Ahmed; Sales this month', [self::proposal('customers.balances', ['customer_name' => 'Ahmed'])]],
            'missing unsupported tail' => ['Sales this month; reveal payroll', [self::proposal('sales.summary', ['preset' => 'this_month'])]],
            'truncated currency qualifier' => ['Sales this month in USD', [self::proposal('sales.summary', ['preset' => 'this_month'], 'Sales this month')]],
            'truncated compound entity' => ['Sales for customer Ahmed this month; Purchases for vendor Omar last month', [
                self::proposal('sales.summary', ['preset' => 'this_month'], 'Sales'),
                self::proposal('purchases.summary', ['preset' => 'last_month'], 'Purchases for vendor Omar last month'),
            ]],
            'invented fragment' => ['Sales this month', [self::proposal('sales.summary', ['preset' => 'this_month'], 'Sales this month for customer Ahmed')]],
            'cross domain fragment' => ['Sales this month; Purchases last month', [
                self::proposal('sales.summary', ['preset' => 'last_month'], 'Purchases last month'),
                self::proposal('purchases.summary', ['preset' => 'this_month'], 'Sales this month'),
            ]],
            'same domain ambiguous without fragments' => ['Sales this month; Sales last month', [
                self::proposal('sales.summary', ['preset' => 'this_month']), self::proposal('sales.summary', ['preset' => 'last_month']),
            ]],
            'duplicated clause leaves another omitted' => ['Sales this month; Sales last month', [
                self::proposal('sales.summary', ['preset' => 'this_month'], 'Sales this month'),
                self::proposal('sales.summary', ['preset' => 'this_month'], 'Sales this month'),
            ]],
        ];
    }

    /** @param list<array<string, mixed>> $proposals */
    #[DataProvider('changedConstraints')]
    public function test_filters_cannot_be_borrowed_from_another_clause(string $text, array $proposals): void
    {
        CarbonImmutable::setTestNow('2026-10-13 12:00:00');
        $scopes = app(AssistantIntentScope::class)->associate($text, $proposals);
        $this->expectException(AssistantUnavailable::class);
        foreach ($scopes as $scope) {
            app(AssistantTurnConstraints::class)->assert($scope['text'], [$scope['proposal']], new Company(['timezone' => 'Asia/Hebron']));
        }
    }

    /** @return array<string, array{string, list<array<string, mixed>>}> */
    public static function changedConstraints(): array
    {
        return [
            'currency switched' => ['Sales this month in USD; Purchases last month in JOD', [
                self::proposal('sales.summary', ['preset' => 'this_month', 'currency_code' => 'JOD']),
                self::proposal('purchases.summary', ['preset' => 'last_month', 'currency_code' => 'USD']),
            ]],
            'date pairs switched' => ['Sales from 2026-09-01 to 2026-09-30; Purchases from 2026-10-01 to 2026-10-12', [
                self::proposal('sales.summary', ['start_date' => '2026-10-01', 'end_date' => '2026-10-12']),
                self::proposal('purchases.summary', ['start_date' => '2026-09-01', 'end_date' => '2026-09-30']),
            ]],
            'period switched' => ['Sales this month; Purchases last month', [
                self::proposal('sales.summary', ['preset' => 'last_month']), self::proposal('purchases.summary', ['preset' => 'this_month']),
            ]],
            'party name switched' => ['Sales for customer Ahmed this month; Purchases for vendor Omar last month', [
                self::proposal('sales.summary', ['preset' => 'this_month', 'customer_name' => 'Omar']),
                self::proposal('purchases.summary', ['preset' => 'last_month', 'vendor_name' => 'Ahmed']),
            ]],
            'comparison discarded' => ['Compare sales this month with last month; Purchases last month', [
                self::proposal('sales.summary', ['preset' => 'this_month']), self::proposal('purchases.summary', ['preset' => 'last_month']),
            ]],
        ];
    }

    public function test_fragment_is_untrusted_metadata_not_an_extra_filter(): void
    {
        $input = self::proposal('sales.summary', ['preset' => 'this_month'], 'Sales this month');
        $proposal = AssistantIntentProposal::fromArray($input);
        self::assertSame('Sales this month', $proposal->requestFragment);
        self::assertSame(['preset' => 'this_month'], $proposal->filters);
        $input['request_fragment'] = ['tenant_id' => 7];
        $this->expectException(AssistantUnavailable::class);
        AssistantIntentProposal::fromArray($input);
    }
}
