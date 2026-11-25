# Six-Domain Reconciliation Operational Orchestration & Alerting Guide

## Overview

This guide documents the bounded, read-only six-domain reconciliation operational orchestration, health snapshot synchronization, and alert integration for the Small Trader Accounting platform.

The system verifies double-entry accounting integrity, stock valuation, and tenant isolation across all active businesses on standard shared-hosting infrastructure (Hostinger / Apache) without requiring persistent background daemons, Docker, or Redis.

---

## 1. The Six Canonical Domains

The platform enforces economic invariants across six distinct domains. Each domain provides a canonical read-only reconciliation service and a dedicated Artisan command:

| # | Domain | Canonical Service | Standalone Command | Primary Invariants Audited |
|---|---|---|---|---|
| **1** | **Accounting** | `AccountingReconciliationService` | `php artisan accounting:reconcile` | Double-entry balance equality, system accounts chart integrity, batch currency rates, debit/credit non-negativity. |
| **2** | **Inventory** | `InventoryReconciliationService` | `php artisan inventory:reconcile --all` | Moving-average valuation recomputation, stock movement sequence continuity, lot expiry allocations, transfer symmetry. |
| **3** | **Sales** | `SalesReconciliationService` | `php artisan sales:reconcile` | Invoice header vs line sums, tax allocations, customer isolation, posting batch linkage, payment allocations, returns. |
| **4** | **Payables** | `PayablesReconciliationService` | `php artisan payables:reconcile` (alias `purchasing:reconcile`) | Vendor isolation, purchase header vs line totals, tax snapshots, posting batch linkage, payment allocations. |
| **5** | **Money** | `MoneyReconciliationService` | `php artisan money:reconcile` | Money account currency parity, payment routing, transfer symmetry, immutable transaction histories. |
| **6** | **Phase 7** | `Phase7ReconciliationService` | `php artisan phase7:reconcile` (alias `accounting:reconcile-phase7`) | Expenses, payroll entries, employee advance allocations, landed cost clearing accounts. |

---

## 2. Invariants & Operational Guardrails

### A. Strict Read-Only Execution (Never Auto-Correct Ledger)
- The orchestration service and CLI commands are strictly read-only auditors.
- Under no circumstances does the orchestrator mutate posting batches, reverse transactions, rewrite stock movements, or rebuild inventory caches.
- Any discrepancy is reported as an integrity violation for operator review.

### B. Default OFF Until Approved Configuration
- Scheduled execution is disabled by default:
  ```env
  RECONCILIATION_SCHEDULE_ENABLED=false
  ```
- If invoked while disabled, the scheduler skips execution, and the CLI command refuses to run unless the `--force` flag is explicitly provided.

### C. Shared-Hosting Runtime Bounds via Bounded Child Processes
- PHP `set_time_limit()` on Unix does not bound blocking database calls or network/socket I/O.
- The orchestrator executes each domain audit in a bounded child process via `ReconciliationProcessRunner` (`Symfony\Component\Process\Process`) using existing console commands.
- If a child process reaches its allocated time budget (`per_task_timeout_seconds` or remaining overall budget), it is immediately and actually terminated via `SIGTERM` followed by `SIGKILL`.
- Total run budget (`max_execution_seconds`, default: 180s) bounds overall execution time.
- If the budget is exhausted, the orchestrator aborts (`TIMEOUT_ABORTED`), sends a failure/timeout alert, and never sends a false healthy heartbeat.

### D. Mutex Safe TTL Exceeding Enforced Lifetime
- Standard cache mutex locks (`reconciliation:orchestration:mutex`) prevent concurrent runs even if external cron triggers overlap.
- The mutex lock TTL strictly exceeds the enforced lifetime budget (`max($lockTtl, $maxSeconds + 60)`), guaranteeing that the lock cannot expire while a bounded execution is actively running.
- No long-running background workers, Supervisor processes, or WebSockets are required.

### E. Keyset Company Retrieval & Actual Completed Counts
- To protect shared hosting memory, the orchestrator uses keyset chunking (`company_chunk_size`, default: 50), never loading all companies into memory simultaneously.
- `companiesAudited` reflects only actual completed companies where all six domains finished execution. If an execution aborts mid-company, the incomplete company is excluded from the count.
- Unknown company queries (`public_id`) fail immediately (`FAILURE`, 0 audited) without reporting false zero-healthy status.

### F. Strict Allowlisted Alert Sanitization
- All alert messages undergo sanitization via `ReconciliationAlertSanitizer`.
- Regex string stripping cannot guarantee removal of unformatted names or integer amounts. Therefore, alerts emit **allowlisted category, count, and status only**.
- Zero raw finding text, customer names, vendor names, or monetary amounts are ever present in alert payloads or mailings.
- Database exceptions (`QueryException`, `PDOException`) are automatically redacted in error results, CLI output, and logs to prevent raw SQL queries, table names, or credentials from leaking.

### G. Ambient CompanyContext Restoration
- `ReconciliationOrchestratorService` captures ambient `CompanyContext` prior to execution, clears it for cross-company isolation, and unconditionally restores the previous company and user in a `finally` block.

### H. Explicit Configurable Recipient
- External email alerts require an explicit recipient:
  ```env
  RECONCILIATION_ALERT_RECIPIENT=ops@example.com
  ```
- If null or empty, external alert delivery is skipped, and logs are written locally without failing.

---

## 3. Configuration Reference (`config/reconciliation.php`)

```php
return [
    // Master toggle for scheduled reconciliation runs (default: false)
    'enabled' => (bool) env('RECONCILIATION_SCHEDULE_ENABLED', false),

    // Execution time budget in seconds (default: 180s)
    'max_execution_seconds' => (int) env('RECONCILIATION_MAX_EXECUTION_SECONDS', 180),

    // Lock TTL to prevent concurrent runs (default: 300s, enforced > max_execution_seconds)
    'lock_ttl_seconds' => (int) env('RECONCILIATION_LOCK_TTL_SECONDS', 300),

    // Bounded child process per-task timeout in seconds (default: 30s)
    'per_task_timeout_seconds' => (int) env('RECONCILIATION_PER_TASK_TIMEOUT_SECONDS', 30),

    // Keyset pagination chunk size (default: 50)
    'company_chunk_size' => (int) env('RECONCILIATION_COMPANY_CHUNK_SIZE', 50),

    // Sync latest results into platform_company_health table (default: true)
    'record_platform_health' => (bool) env('RECONCILIATION_RECORD_PLATFORM_HEALTH', true),

    // Alert settings
    'alerts' => [
        'enabled' => (bool) env('RECONCILIATION_ALERTS_ENABLED', false),
        'channel' => env('RECONCILIATION_ALERT_CHANNEL', 'log'), // 'log', 'mail', or 'null'
        'recipient' => env('RECONCILIATION_ALERT_RECIPIENT', null),
        'heartbeat_enabled' => (bool) env('RECONCILIATION_HEARTBEAT_ENABLED', true),
    ],
];
```

---

## 4. CLI Usage

### Orchestrated Six-Domain Audit
```bash
# Refuses if RECONCILIATION_SCHEDULE_ENABLED=false
php artisan reconciliation:run

# Force manual audit regardless of schedule toggle
php artisan reconciliation:run --force

# Audit a specific company by public ULID
php artisan reconciliation:run 01JM0000000000000000000001 --force

# Available command aliases
php artisan reconciliation:orchestrate --force
php artisan platform:reconcile-all --force
```

### Individual Domain Audits
```bash
# Accounting
php artisan accounting:reconcile [companyPublicId]

# Inventory
php artisan inventory:reconcile --all
php artisan inventory:reconcile [companyPublicId]

# Sales
php artisan sales:reconcile [companyPublicId]

# Payables (Purchasing)
php artisan payables:reconcile [companyPublicId]
php artisan purchasing:reconcile [companyPublicId]

# Money
php artisan money:reconcile [companyPublicId]

# Phase 7
php artisan phase7:reconcile [companyPublicId]
php artisan accounting:reconcile-phase7 [companyPublicId]
```

---

## 5. Scheduler Integration (`routes/console.php`)

The scheduled audit runs daily at 02:00 UTC, guarded by `withoutOverlapping()` and `config('reconciliation.enabled')`:

```php
// routes/console.php
Schedule::command('ai-assistant:prune')->hourly()->withoutOverlapping();

// Bounded read-only six-domain reconciliation orchestration (Default OFF until approved configuration)
Schedule::command('reconciliation:run')
    ->dailyAt('02:00')
    ->withoutOverlapping((int) ceil(config('reconciliation.lock_ttl_seconds', 300) / 60))
    ->when(fn () => (bool) config('reconciliation.enabled', false));
```

Both `ai-assistant:prune` and `subscriptions.enforcement_enabled = false` remain preserved and protected.

---

## 6. Database Integration & Verification Note

> **Note on Environment Constraints:**
> No database daemon slot is assigned in the current test environment. All reconciliation orchestration, bounded child process execution, mutex locking, allowlisted alert sanitization, unknown company failure, and timeout aborts are fully verified through isolated unit and process test suites. Full multi-tenant MariaDB integration verification remains to be run when a persistent database daemon slot is assigned.
