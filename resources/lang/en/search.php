<?php

declare(strict_types=1);

return [
    'title' => 'Global Search',
    'open_search' => 'Open quick search',
    'close_search' => 'Close search',
    'search_placeholder' => 'Search customers, vendors, products, invoices, checks...',
    'clear_search' => 'Clear search',
    'quick_search' => 'Quick search',
    'shortcut' => 'Ctrl + K',
    'type_to_search' => 'Type at least 2 characters to search...',
    'no_results' => 'No matching results found',
    'no_results_desc' => 'Try searching by a different name, SKU, or document reference number.',
    'loading' => 'Searching...',
    'slow_loading' => 'Search is taking longer than expected...',
    'offline_notice' => 'You are currently offline.',
    'error_occurred' => 'An error occurred while searching. Please try again.',
    'results_count' => '{0} No results found|{1} 1 result found|[2,*] :count results found',

    // Categories
    'categories' => [
        'customer' => 'Customers',
        'vendor' => 'Vendors',
        'product' => 'Products',
        'invoice' => 'Sales Invoices',
        'quotation' => 'Quotations',
        'purchase' => 'Purchases',
        'check' => 'Checks',
    ],

    // Check Directions
    'direction_incoming' => 'Incoming check',
    'direction_outgoing' => 'Outgoing check',

    // Statuses
    'statuses' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'draft' => 'Draft',
        'sent' => 'Sent',
        'posted' => 'Posted',
        'void' => 'Void',
        'cancelled' => 'Cancelled',
        'received' => 'Received',
        'deposited' => 'Deposited',
        'issued' => 'Issued',
        'cleared' => 'Cleared',
        'returned' => 'Returned',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
    ],

    // Hints
    'hints' => [
        'navigate' => 'Navigate',
        'select' => 'Select',
        'close' => 'Close',
    ],
];
