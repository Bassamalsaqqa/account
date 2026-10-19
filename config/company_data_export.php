<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Company Data Export Configuration
    |--------------------------------------------------------------------------
    |
    | Bounded point-in-time business archive export configuration for Small
    | Trader Accounting. Strict Owner-only boundary with bounded execution.
    |
    */

    'enabled' => env('COMPANY_DATA_EXPORT_ENABLED', true),

    // Maximum total execution time in seconds (hard ceiling 30)
    'max_execution_seconds' => min(30, (int) env('COMPANY_DATA_EXPORT_MAX_SECONDS', 30)),

    // Maximum number of rows allowed per dataset (hard ceiling 50,000)
    'max_rows_per_dataset' => min(50_000, (int) env('COMPANY_DATA_EXPORT_MAX_ROWS_PER_DATASET', 50_000)),

    // Maximum total rows across all datasets in a single export (hard ceiling 50,000)
    'max_total_rows' => min(50_000, (int) env('COMPANY_DATA_EXPORT_MAX_TOTAL_ROWS', 50_000)),

    // Maximum total compressed ZIP archive size in bytes (hard ceiling 50 MB: 52,428,800 bytes)
    'max_bytes' => min(52_428_800, (int) env('COMPANY_DATA_EXPORT_MAX_BYTES', 52_428_800)),

    // Maximum total uncompressed CSV bytes streamed across all datasets (hard ceiling 50 MB: 52,428,800 bytes)
    'max_uncompressed_bytes' => min(52_428_800, (int) env('COMPANY_DATA_EXPORT_MAX_UNCOMPRESSED_BYTES', 52_428_800)),

    // Maximum memory usage ceiling in bytes (hard ceiling 128 MB: 134,217,728 bytes)
    'max_memory_bytes' => min(134_217_728, (int) env('COMPANY_DATA_EXPORT_MAX_MEMORY_BYTES', 134_217_728)),

    // Database chunk size when writing CSV datasets (bounded between 100 and 250)
    'chunk_size' => max(100, min(250, (int) env('COMPANY_DATA_EXPORT_CHUNK_SIZE', 250))),

    // Rate limiting for the export endpoint
    'rate_limit' => [
        'max_attempts' => (int) env('COMPANY_DATA_EXPORT_RATE_LIMIT_ATTEMPTS', 5),
        'decay_seconds' => (int) env('COMPANY_DATA_EXPORT_RATE_LIMIT_DECAY', 3600), // 1 hour
    ],

    // Private storage location for temporary export files
    'temp_directory' => storage_path('app/private/company_exports'),

    // Private directory for file locks (concurrency control)
    'lock_directory' => storage_path('app/private/company_exports/locks'),

    // Excluded modules and metadata documentation
    'exclusions' => [
        [
            'category' => 'payroll_and_salaries',
            'reason' => 'Employee/payroll datasets are excluded. Payroll and all reversal journal descriptions are redacted conservatively; full numeric GL remains included.',
        ],
        [
            'category' => 'binary_attachments',
            'reason' => 'Receipt images, expense file attachments, uploaded logos, and signature blobs are excluded from tabular CSV archives.',
        ],
        [
            'category' => 'security_and_credentials',
            'reason' => 'User passwords, remember tokens, two-factor authentication secrets, recovery codes, sessions, API keys, AI provider secrets, and webhook secrets are strictly redacted and excluded.',
        ],
        [
            'category' => 'additional_presentations_and_inventory_provenance',
            'reason' => 'Dedicated Profit and Loss, Balance Sheet and Money movement presentation CSVs, brands, lot allocations, inventory-operation history and financial share issuance are outside this fixed V1 inventory. Trial Balance and numeric GL are included; existing individual report exports remain available.',
        ],
        [
            'category' => 'ephemeral_and_audit',
            'reason' => 'Internal cache entries, background queue jobs, command receipts, and raw internal audit log events are excluded from business data reference exports.',
        ],
    ],
];
