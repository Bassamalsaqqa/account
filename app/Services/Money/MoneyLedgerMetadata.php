<?php

declare(strict_types=1);

namespace App\Services\Money;

/** A canonical same-account base residual does not erase native-currency metadata. */
final class MoneyLedgerMetadata
{
    public static function unknownExpression(): string
    {
        return "CASE WHEN l.transaction_currency_code IS NOT NULL THEN CASE WHEN l.transaction_currency_code = ? THEN 0 ELSE 1 END
            WHEN b.source_type IN ('customer_payment','vendor_payment','money_transfer','check_event','reversal')
                AND EXISTS (SELECT 1 FROM posting_lines currency_line WHERE currency_line.posting_batch_id = l.posting_batch_id
                    AND currency_line.company_id = l.company_id AND currency_line.ledger_account_id = l.ledger_account_id
                    AND currency_line.transaction_currency_code = ?) THEN 0 ELSE 1 END";
    }
}
