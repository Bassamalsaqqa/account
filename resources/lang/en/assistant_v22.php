<?php

return [
    'clarify_intent' => 'Do you want to view accounting information or prepare an unsaved invoice? Please specify.',
    'clarify_entity' => 'Which customer, vendor or product do you mean? Enter its full name or code.',
    'clarify_entity_missing' => 'I could not uniquely find that name in this company. Enter its full name or code.',
    'clarify_entity_ambiguous' => 'More than one record matches that name. Enter the full name or unique code.',
    'clarify_period' => 'Which dates or period should I use?',
    'clarify_currency' => 'Which of this company’s currencies should I use?',
    'clarify_unsupported' => 'That request needs an operation this assistant cannot safely perform. Try a supported report or use the manual screen.',
    'clarify_question_unsupported' => 'This report does not support one of your requested filters. Please choose another report or clarify the scope.',
    'clarify_question_invalid' => 'Please clarify the period, currency or requested scope so I can use the correct accounting source.',
    'comparison' => 'Comparison period',
    'followup' => 'Ask a follow-up',
    'fields' => [
        'subtotal_currency' => 'Subtotal', 'discount_total_currency' => 'Discounts', 'tax_total_currency' => 'Tax',
        'gross_sales_base' => 'Gross sales', 'net_sales_base' => 'Net sales', 'returns_base' => 'Returns',
        'tax_base' => 'Tax', 'discounts_base' => 'Discounts', 'original_invoice_count' => 'Invoices',
        'customer_name' => 'Customer', 'customer_code' => 'Customer code', 'currency_code' => 'Currency',
        'outstanding_balance' => 'Outstanding balance', 'outstanding_by_currency' => 'Outstanding by currency',
        'invoiced_amount' => 'Invoiced', 'document_number' => 'Document', 'invoice_number' => 'Invoice',
        'issue_date' => 'Issue date', 'grand_total_currency' => 'Invoice total', 'status' => 'Status',
        'product_name' => 'Product', 'sku' => 'SKU', 'unit_code' => 'Unit', 'quantity_on_hand' => 'On hand',
        'minimum_stock' => 'Minimum stock', 'shortage_quantity' => 'Shortage', 'stock_status' => 'Stock status',
        'low_stock_count' => 'Low stock', 'out_of_stock_count' => 'Out of stock', 'total_alert_count' => 'Alerts',
        'currencies' => 'By currency', 'total_customers' => 'Customers', 'gross_sales' => 'Gross sales', 'net_sales' => 'Net sales',
    ],
];
