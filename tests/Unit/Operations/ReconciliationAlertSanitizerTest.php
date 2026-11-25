<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use App\Services\Operations\ReconciliationAlertSanitizer;
use Exception;
use PDOException;
use PHPUnit\Framework\TestCase;

class ReconciliationAlertSanitizerTest extends TestCase
{
    private ReconciliationAlertSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new ReconciliationAlertSanitizer;
    }

    public function test_it_emits_allowlisted_category_count_and_status_only(): void
    {
        $raw = 'Invoice [ID 45] base_grand_total [150.00 ILS] does not match grand_total * exchange_rate [152.50 USD].';
        $result = $this->sanitizer->sanitize('sales', $raw);

        $this->assertSame(['category', 'count', 'status'], array_keys($result));
        $this->assertContains($result['category'], ReconciliationAlertSanitizer::ALLOWED_CATEGORIES);
        $this->assertSame(1, $result['count']);
        $this->assertSame('UNHEALTHY', $result['status']);
    }

    public function test_it_discards_malicious_raw_findings_with_plain_names_and_integer_amounts(): void
    {
        $malicious = 'Secret customer Alice Bob at company Acme Corp owes 1000000 USD on private account 42.';
        $result = $this->sanitizer->sanitize('sales', $malicious);

        // Guarantees zero sensitive data or raw strings are emitted
        $this->assertSame(ReconciliationAlertSanitizer::CATEGORY_SALES_RECORD, $result['category']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('UNHEALTHY', $result['status']);

        // Inspect all emitted values
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Alice', $serialized);
        $this->assertStringNotContainsString('Bob', $serialized);
        $this->assertStringNotContainsString('Acme', $serialized);
        $this->assertStringNotContainsString('1000000', $serialized);
        $this->assertStringNotContainsString('USD', $serialized);
        $this->assertStringNotContainsString('42', $serialized);
        $this->assertStringNotContainsString('private account', $serialized);
    }

    public function test_it_discards_sql_injections_and_credentials_in_raw_findings(): void
    {
        $malicious = "DROP TABLE users; SELECT * FROM credentials WHERE password='super_secret'; tenant_leak cross-company";
        $result = $this->sanitizer->sanitize('accounting', $malicious);

        $this->assertSame(ReconciliationAlertSanitizer::CATEGORY_TENANT_LEAK, $result['category']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('UNHEALTHY', $result['status']);

        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('DROP TABLE', $serialized);
        $this->assertStringNotContainsString('super_secret', $serialized);
        $this->assertStringNotContainsString('credentials', $serialized);
    }

    public function test_it_classifies_domain_categories_accurately(): void
    {
        $tests = [
            ['payables', 'Vendor from another company referenced in purchase', ReconciliationAlertSanitizer::CATEGORY_TENANT_LEAK],
            ['accounting', 'Batch currency exchange rate is negative', ReconciliationAlertSanitizer::CATEGORY_CURRENCY_MISMATCH],
            ['accounting', 'Missing required system chart account 1010', ReconciliationAlertSanitizer::CATEGORY_SYSTEM_ACCOUNT],
            ['accounting', 'Posting batch status is corrupted', ReconciliationAlertSanitizer::CATEGORY_POSTING_BATCH],
            ['accounting', 'Batch is out of balance: debit sum does not match credit sum', ReconciliationAlertSanitizer::CATEGORY_BALANCE_IMBALANCE],
            ['inventory', 'Moving average cost valuation gap', ReconciliationAlertSanitizer::CATEGORY_INVENTORY_VALUATION],
            ['inventory', 'Stock movement sequence gap detected', ReconciliationAlertSanitizer::CATEGORY_INVENTORY_MOVEMENT],
            ['sales', 'Invoice tax lines sum does not match header tax', ReconciliationAlertSanitizer::CATEGORY_SALES_RECORD],
            ['payables', 'Purchase line totals do not match invoice header', ReconciliationAlertSanitizer::CATEGORY_PAYABLES_RECORD],
            ['money', 'Money routing integrity mismatch between payment and batch', ReconciliationAlertSanitizer::CATEGORY_MONEY_ROUTING],
            ['phase7', 'Phase 7 landed cost clearing account mismatch', ReconciliationAlertSanitizer::CATEGORY_PHASE7_CLEARING],
            ['accounting', 'Domain execution timed out after 30s', ReconciliationAlertSanitizer::CATEGORY_TIMEOUT],
            ['sales', '', ReconciliationAlertSanitizer::CATEGORY_SALES_RECORD],
            ['unknown', '', ReconciliationAlertSanitizer::CATEGORY_GENERAL],
        ];

        foreach ($tests as [$domain, $message, $expectedCategory]) {
            $result = $this->sanitizer->sanitize($domain, $message);
            $this->assertSame($expectedCategory, $result['category'], "Failed classifying [{$domain}]: {$message}");
            $this->assertContains($result['category'], ReconciliationAlertSanitizer::ALLOWED_CATEGORIES);
        }
    }

    public function test_it_redacts_database_exceptions_and_queries(): void
    {
        $pdoException = new PDOException("SQLSTATE[42S02]: Base table not found: 1146 Table 'account.users' doesn't exist (SQL: select * from users where password = 'plain_password')", 2002);
        $redacted = ReconciliationAlertSanitizer::redactExceptionMessage($pdoException);

        $this->assertStringNotContainsString('plain_password', $redacted);
        $this->assertStringNotContainsString('select * from users', $redacted);
        $this->assertStringNotContainsString('account.users', $redacted);
        $this->assertStringContainsString('DatabaseException', $redacted);
        $this->assertStringContainsString('[REDACTED]', $redacted);
    }

    public function test_it_redacts_exception_messages_with_paths_and_tokens(): void
    {
        $genericException = new Exception('Failed loading config at /home/user/app/storage/keys/private.key with token=secret_token_123');
        $redacted = ReconciliationAlertSanitizer::redactExceptionMessage($genericException);

        $this->assertStringNotContainsString('secret_token_123', $redacted);
        $this->assertStringNotContainsString('/home/user/app/storage/keys/private.key', $redacted);
        $this->assertStringContainsString('token=[REDACTED]', $redacted);
    }

    public function test_it_redacts_output_with_sql_errors(): void
    {
        $output = "Fatal error: Uncaught SQLSTATE[HY000]: General error: 2006 MySQL server has gone away in /var/www/vendor/laravel/framework/src/Illuminate/Database/Connection.php:571\nStack trace: ...";
        $redacted = ReconciliationAlertSanitizer::redactOutput($output);

        $this->assertStringNotContainsString('/var/www/vendor', $redacted);
        $this->assertSame('Database query exception occurred [REDACTED]', $redacted);
    }
}
