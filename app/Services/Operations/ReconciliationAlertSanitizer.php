<?php

declare(strict_types=1);

namespace App\Services\Operations;

use ReflectionClass;
use Throwable;

final class ReconciliationAlertSanitizer
{
    /**
     * Strict allowlisted violation and error categories.
     * Guaranteed zero PII, customer names, financial values, or raw messages.
     */
    public const CATEGORY_TENANT_LEAK = 'TENANT_ISOLATION_LEAK';

    public const CATEGORY_CURRENCY_MISMATCH = 'CURRENCY_CONFIGURATION_MISMATCH';

    public const CATEGORY_SYSTEM_ACCOUNT = 'SYSTEM_ACCOUNT_INTEGRITY_MISMATCH';

    public const CATEGORY_POSTING_BATCH = 'POSTING_BATCH_INTEGRITY_MISMATCH';

    public const CATEGORY_BALANCE_IMBALANCE = 'DOUBLE_ENTRY_BALANCE_IMBALANCE';

    public const CATEGORY_INVENTORY_VALUATION = 'INVENTORY_VALUATION_DISCREPANCY';

    public const CATEGORY_INVENTORY_MOVEMENT = 'INVENTORY_MOVEMENT_INTEGRITY_GAP';

    public const CATEGORY_SALES_RECORD = 'SALES_RECORD_INTEGRITY_MISMATCH';

    public const CATEGORY_PAYABLES_RECORD = 'PAYABLES_RECORD_INTEGRITY_MISMATCH';

    public const CATEGORY_MONEY_ROUTING = 'MONEY_ROUTING_INTEGRITY_MISMATCH';

    public const CATEGORY_PHASE7_CLEARING = 'PHASE7_CLEARING_INTEGRITY_MISMATCH';

    public const CATEGORY_TIMEOUT = 'TIMEOUT_ABORTED';

    public const CATEGORY_EXECUTION_FAILURE = 'EXECUTION_FAILURE';

    public const CATEGORY_GENERAL = 'DOMAIN_INTEGRITY_VIOLATION';

    public const ALLOWED_CATEGORIES = [
        self::CATEGORY_TENANT_LEAK,
        self::CATEGORY_CURRENCY_MISMATCH,
        self::CATEGORY_SYSTEM_ACCOUNT,
        self::CATEGORY_POSTING_BATCH,
        self::CATEGORY_BALANCE_IMBALANCE,
        self::CATEGORY_INVENTORY_VALUATION,
        self::CATEGORY_INVENTORY_MOVEMENT,
        self::CATEGORY_SALES_RECORD,
        self::CATEGORY_PAYABLES_RECORD,
        self::CATEGORY_MONEY_ROUTING,
        self::CATEGORY_PHASE7_CLEARING,
        self::CATEGORY_TIMEOUT,
        self::CATEGORY_EXECUTION_FAILURE,
        self::CATEGORY_GENERAL,
    ];

    /**
     * Emits allowlisted category, count, and status only.
     * Never emits raw finding, exception text, customer names, or numeric amounts.
     *
     * @return array{category: string, count: int, status: string}
     */
    public function sanitize(string $domain, string $rawMessage = '', int $count = 1, string $status = 'UNHEALTHY'): array
    {
        $category = $this->classifyCategory($domain, $rawMessage);

        return [
            'category' => $category,
            'count' => max(1, $count),
            'status' => in_array($status, ['HEALTHY', 'UNHEALTHY', 'TIMEOUT', 'FAILURE'], true) ? $status : 'UNHEALTHY',
        ];
    }

    /**
     * Classifies a message or domain into a safe allowlisted category.
     */
    public function classifyCategory(string $domain, string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return self::CATEGORY_TIMEOUT;
        }

        if (str_contains($lower, 'another company') || str_contains($lower, 'cross-company') || str_contains($lower, 'tenant')) {
            return self::CATEGORY_TENANT_LEAK;
        }

        if (str_contains($lower, 'currency') || str_contains($lower, 'exchange rate') || str_contains($lower, 'base currency')) {
            return self::CATEGORY_CURRENCY_MISMATCH;
        }

        if (str_contains($lower, 'system account') || str_contains($lower, 'account missing') || str_contains($lower, 'missing required system')) {
            return self::CATEGORY_SYSTEM_ACCOUNT;
        }

        if (str_contains($lower, 'posting batch') || str_contains($lower, 'posting_batch_id') || str_contains($lower, 'batch status')) {
            return self::CATEGORY_POSTING_BATCH;
        }

        if (str_contains($lower, 'out of balance') || str_contains($lower, 'debit sum') || str_contains($lower, 'credit sum') || str_contains($lower, 'imbalance') || str_contains($lower, 'does not match calculated')) {
            return self::CATEGORY_BALANCE_IMBALANCE;
        }

        if (str_contains($lower, 'phase 7') || str_contains($lower, 'phase7') || str_contains($lower, 'landed cost') || str_contains($lower, 'payroll') || str_contains($lower, 'clearing account')) {
            return self::CATEGORY_PHASE7_CLEARING;
        }

        if (str_contains($lower, 'valuation') || str_contains($lower, 'moving average') || str_contains($lower, 'cost')) {
            return self::CATEGORY_INVENTORY_VALUATION;
        }

        if (str_contains($lower, 'movement') || str_contains($lower, 'stock level') || str_contains($lower, 'lot allocation')) {
            return self::CATEGORY_INVENTORY_MOVEMENT;
        }

        return match ($domain) {
            'sales' => self::CATEGORY_SALES_RECORD,
            'payables' => self::CATEGORY_PAYABLES_RECORD,
            'money' => self::CATEGORY_MONEY_ROUTING,
            'phase7' => self::CATEGORY_PHASE7_CLEARING,
            'inventory' => self::CATEGORY_INVENTORY_VALUATION,
            'accounting' => self::CATEGORY_BALANCE_IMBALANCE,
            default => self::CATEGORY_GENERAL,
        };
    }

    /**
     * Redacts database exceptions, SQL queries, bindings, and sensitive tokens for logs, CLI, and error results.
     */
    public static function redactExceptionMessage(Throwable $e): string
    {
        $shortClass = (new ReflectionClass($e))->getShortName();

        $className = get_class($e);
        if (str_contains($className, 'Database') || str_contains($className, 'PDO') || str_contains($className, 'QueryException')) {
            $code = $e->getCode();

            return "DatabaseException [{$shortClass}]: Database operation failed (code: {$code}) [REDACTED]";
        }

        $msg = $e->getMessage();
        if (str_contains($msg, 'SQLSTATE') || str_contains($msg, 'Connection refused') || str_contains($msg, 'Access denied') || str_contains($msg, 'QueryException')) {
            return "DatabaseException [{$shortClass}]: Database operation failed [REDACTED]";
        }

        $sanitized = (string) preg_replace('/(password|secret|key|token)[=:][^,\s]+/i', '$1=[REDACTED]', $msg);
        $sanitized = (string) preg_replace('/(\/[a-zA-Z0-9_\-\.]+)+/', '[PATH]', $sanitized);

        return "{$shortClass}: {$sanitized}";
    }

    /**
     * Redacts console output that may contain database errors or credentials.
     */
    public static function redactOutput(string $output): string
    {
        if (str_contains($output, 'SQLSTATE') || str_contains($output, 'QueryException') || str_contains($output, 'PDOException')) {
            return 'Database query exception occurred [REDACTED]';
        }

        $sanitized = (string) preg_replace('/(password|secret|key|token)[=:][^,\s]+/i', '$1=[REDACTED]', $output);
        $sanitized = (string) preg_replace('/(\/[a-zA-Z0-9_\-\.]+)+/', '[PATH]', $sanitized);

        return $sanitized;
    }
}
