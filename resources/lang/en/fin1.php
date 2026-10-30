<?php

return [
    'title' => 'Payment with this invoice',
    'help' => 'Leave unpaid to record a receipt later. A payment is recorded only after you review and confirm posting.',
    'mode' => 'Payment choice', 'mode_unpaid' => 'Unpaid', 'mode_full' => 'Pay in full', 'mode_partial' => 'Pay part',
    'payment_method' => 'Payment method', 'cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'check' => 'Incoming check',
    'money_account_id' => 'Payment account', 'currency_code' => 'Check currency', 'choose' => 'Choose…',
    'amount' => 'Amount received (payment currency)', 'exchange_rate' => 'Payment exchange rate to base currency',
    'payment_date' => 'Receipt date', 'invoice_amount' => 'Applied amount (invoice currency)',
    'payment_currency_amount' => 'Applied amount (payment currency, for different currencies)',
    'check_number' => 'Check number', 'bank_name' => 'Bank name', 'drawer' => 'Drawer (optional)', 'due_date' => 'Check due date',
    'no_accounts' => 'No active matching account. Choose another method or leave unpaid and use the existing receipt workflow.',
    'date_help' => 'A blank receipt date uses the invoice date.',
    'fx_help' => 'Use rate 1 for base currency. For different invoice and payment currencies, enter both applied amounts explicitly. Any excess remains unapplied receipt credit.',
    'correct_fields' => 'Review the payment fields below. Your saved invoice remains a draft.',
    'review' => 'Review invoice payment', 'received_amount' => 'Total received', 'outstanding_invoice_amount' => 'Invoice balance after posting',
    'unallocated_payment_amount' => 'Unapplied receipt credit', 'check_control' => 'Checks in hand',
    'atomic_help' => 'Final confirmation posts the invoice, receipt and allocation together. A check remains received, not cleared.',
    'posted_with_payment' => 'Invoice and receipt posted together. Receipt details and the remaining balance are available on this invoice.',
];
