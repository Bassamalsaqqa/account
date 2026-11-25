<?php

declare(strict_types=1);

namespace App\Services\Operations;

final class ReconciliationAlertSanitizer
{
    /**
     * Common violation categories for structured reporting without PII or financial amounts.
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

    public const CATEGORY_GENERAL = 'DOMAIN_INTEGRITY_VIOLATION';

    /**
     * Sanitize a raw violation or discrepancy string:
     * - Strips all currency symbols ($, €, £, ¥, ILS, USD, JOD, BHD, EUR, GBP, etc.)
     * - Redacts all decimal numbers, prices, monetary amounts, and floating values.
     * - Redacts email addresses and phone numbers.
     * - Redacts bracketed values that contain digits or monetary content.
     *
     * @return array{category: string, detail: string}
     */
    public function sanitize(string $domain, string $rawMessage): array
    {
        $category = $this->classifyCategory($domain, $rawMessage);
        $sanitizedDetail = $this->redactSensitiveData($rawMessage);

        return [
            'category' => $category,
            'detail' => $sanitizedDetail,
        ];
    }

    /**
     * Classifies a message into a safe domain category.
     */
    public function classifyCategory(string $domain, string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'another company') || str_contains($lower, 'foreign') || str_contains($lower, 'cross-company') || str_contains($lower, 'tenant')) {
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
     * Redacts financial amounts, currencies, decimal values, and personal identifiers.
     */
    public function redactSensitiveData(string $text): string
    {
        // 1. Redact email addresses
        $text = (string) preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED_EMAIL]', $text);

        // 2. Redact phone numbers
        $text = (string) preg_replace('/(?:\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', '[REDACTED_PHONE]', $text);

        // 3. Redact currency labels and symbols with amounts (e.g. ILS 100.00, $50, 9.833 JOD)
        $text = (string) preg_replace('/\b(ILS|USD|JOD|BHD|EUR|GBP|SAR|AED|EGP|QAR|KWD|OMR|\$|€|£|¥|₪)\s*[-+]?[0-9]+(\.[0-9]+)?\b/i', '[REDACTED_AMOUNT]', $text);
        $text = (string) preg_replace('/[-+]?[0-9]+(\.[0-9]+)?\s*(ILS|USD|JOD|BHD|EUR|GBP|SAR|AED|EGP|QAR|KWD|OMR|\$|€|£|¥|₪)\b/i', '[REDACTED_AMOUNT]', $text);

        // 4. Redact percentages
        $text = (string) preg_replace('/\b\d+(\.\d+)?%/', '[REDACTED_PCT]', $text);

        // 5. Redact decimal numbers (which represent financial quantities, rates, prices, or balances)
        $text = (string) preg_replace('/(?<![a-zA-Z0-9_])[-+]?[0-9]+\.[0-9]+(?![a-zA-Z0-9_])/', '[REDACTED_DECIMAL]', $text);

        // 6. Redact standalone bracketed values that contain digits or IDs: e.g. [123.45], [ID 45], [0.000000]
        $text = (string) preg_replace('/\[(?:ID\s*)?\d+(?:\.\d+)?\]/', '[REF]', $text);

        // 7. Strip out raw currency codes even without numbers
        $text = (string) preg_replace('/\b(ILS|USD|JOD|BHD|EUR|GBP|SAR|AED|EGP|QAR|KWD|OMR)\b/', '[CURRENCY]', $text);

        // 8. Normalize spacing
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
