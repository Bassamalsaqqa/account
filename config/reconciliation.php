<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Scheduled Reconciliation Master Toggle
    |--------------------------------------------------------------------------
    |
    | Master toggle for scheduled six-domain reconciliation orchestration.
    | Default OFF until approved configuration by the owner.
    | When false, scheduled execution is skipped and CLI run refuses without --force.
    |
    */
    'enabled' => (bool) env('RECONCILIATION_SCHEDULE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Shared Hosting Runtime Bounds
    |--------------------------------------------------------------------------
    |
    | Strict bounds to protect shared hosting environments without daemons:
    | - max_execution_seconds: total maximum time budget for reconciliation run.
    | - lock_ttl_seconds: non-overlapping mutex lock duration (must exceed lifetime).
    | - per_task_timeout_seconds: bounded child process timeout per domain/company.
    | - company_chunk_size: keyset pagination chunk size to prevent memory exhaustion.
    |
    */
    'max_execution_seconds' => (int) env('RECONCILIATION_MAX_EXECUTION_SECONDS', 180),
    'lock_ttl_seconds' => (int) env('RECONCILIATION_LOCK_TTL_SECONDS', 300),
    'per_task_timeout_seconds' => (int) env('RECONCILIATION_PER_TASK_TIMEOUT_SECONDS', 30),
    'company_chunk_size' => (int) env('RECONCILIATION_COMPANY_CHUNK_SIZE', 50),

    /*
    |--------------------------------------------------------------------------
    | Platform Health Snapshot Sync
    |--------------------------------------------------------------------------
    |
    | Whether to update the platform_company_health table with the latest
    | read-only domain health status for each company audited.
    |
    */
    'record_platform_health' => (bool) env('RECONCILIATION_RECORD_PLATFORM_HEALTH', true),

    /*
    |--------------------------------------------------------------------------
    | Operational Alerts & Health Reporting
    |--------------------------------------------------------------------------
    |
    | Alerts for:
    | 1. Heartbeat: confirmation of successful zero-violation run.
    | 2. Nonzero findings: integrity violations detected in any domain.
    | 3. Execution failures: unhandled exceptions or timeout.
    |
    | INVARIANTS:
    | - Default OFF until approved configuration.
    | - ZERO financial values, money amounts, or PII in alert payloads.
    | - Emits allowlisted category, count, and status only.
    | - Explicit configurable recipient. If null/empty, external alerts are disabled.
    |
    */
    'alerts' => [
        'enabled' => (bool) env('RECONCILIATION_ALERTS_ENABLED', false),
        'channel' => env('RECONCILIATION_ALERT_CHANNEL', 'log'), // 'log', 'mail', 'null'
        'recipient' => env('RECONCILIATION_ALERT_RECIPIENT', null), // explicit email address or identifier
        'heartbeat_enabled' => (bool) env('RECONCILIATION_HEARTBEAT_ENABLED', true),
    ],
];
