<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use App\Services\Operations\ReconciliationAlertSanitizer;
use PHPUnit\Framework\TestCase;

class ReconciliationAlertSanitizerTest extends TestCase
{
    private ReconciliationAlertSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new ReconciliationAlertSanitizer;
    }

    public function test_it_redacts_currency_symbols_and_monetary_amounts(): void
    {
        $raw = 'Invoice [ID 45] base_grand_total [150.00 ILS] does not match grand_total * exchange_rate [152.50 USD].';
        $result = $this->sanitizer->sanitize('sales', $raw);

        $this->assertStringNotContainsString('150.00', $result['detail']);
        $this->assertStringNotContainsString('152.50', $result['detail']);
        $this->assertStringNotContainsString('ILS', $result['detail']);
        $this->assertStringNotContainsString('USD', $result['detail']);
        $this->assertStringContainsString('[REDACTED_AMOUNT]', $result['detail']);
    }

    public function test_it_redacts_decimal_numbers_and_rates(): void
    {
        $raw = 'Non-positive exchange rate [0.000000] on batch [REF 123], expected rate 3.673219.';
        $result = $this->sanitizer->sanitize('accounting', $raw);

        $this->assertStringNotContainsString('0.000000', $result['detail']);
        $this->assertStringNotContainsString('3.673219', $result['detail']);
        $this->assertStringContainsString('[REDACTED_DECIMAL]', $result['detail']);
    }

    public function test_it_redacts_percentages(): void
    {
        $raw = 'Header discount 15% exceeds line cap 10.5%.';
        $result = $this->sanitizer->sanitize('sales', $raw);

        $this->assertStringNotContainsString('15%', $result['detail']);
        $this->assertStringNotContainsString('10.5%', $result['detail']);
        $this->assertStringContainsString('[REDACTED_PCT]', $result['detail']);
    }

    public function test_it_redacts_emails_and_phone_numbers(): void
    {
        $raw = 'Customer contact merchant@example.com with phone +970599123456 has invalid opening balance.';
        $result = $this->sanitizer->sanitize('sales', $raw);

        $this->assertStringNotContainsString('merchant@example.com', $result['detail']);
        $this->assertStringNotContainsString('970599123456', $result['detail']);
        $this->assertStringContainsString('[REDACTED_EMAIL]', $result['detail']);
        $this->assertStringContainsString('[REDACTED_PHONE]', $result['detail']);
    }

    public function test_it_classifies_tenant_isolation_leaks(): void
    {
        $raw = 'Detected 3 purchases referencing vendors from another company.';
        $result = $this->sanitizer->sanitize('payables', $raw);

        $this->assertSame(ReconciliationAlertSanitizer::CATEGORY_TENANT_LEAK, $result['category']);
    }

    public function test_it_classifies_currency_configuration_mismatches(): void
    {
        $raw = 'Currency configuration error: base currency [JOD] is disabled for company [12].';
        $result = $this->sanitizer->sanitize('accounting', $raw);

        $this->assertSame(ReconciliationAlertSanitizer::CATEGORY_CURRENCY_MISMATCH, $result['category']);
        $this->assertStringNotContainsString('JOD', $result['detail']);
    }

    public function test_it_classifies_double_entry_balance_imbalance(): void
    {
        $raw = 'Batch is out of balance: debit sum [1000.00] does not match credit sum [950.00].';
        $result = $this->sanitizer->sanitize('accounting', $raw);

        $this->assertSame(ReconciliationAlertSanitizer::CATEGORY_BALANCE_IMBALANCE, $result['category']);
        $this->assertStringNotContainsString('1000.00', $result['detail']);
        $this->assertStringNotContainsString('950.00', $result['detail']);
    }

    public function test_it_classifies_inventory_movement_and_valuation(): void
    {
        $rawMovement = 'Movement sequence gap detected for product between seq 10 and 15.';
        $resultMovement = $this->sanitizer->sanitize('inventory', $rawMovement);
        $this->assertSame(ReconciliationAlertSanitizer::CATEGORY_INVENTORY_MOVEMENT, $resultMovement['category']);

        $rawValuation = 'Moving average cost discrepancy between cached and replayed valuation.';
        $resultValuation = $this->sanitizer->sanitize('inventory', $rawValuation);
        $this->assertSame(ReconciliationAlertSanitizer::CATEGORY_INVENTORY_VALUATION, $resultValuation['category']);
    }
}
