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

### C. Shared-Hosting Runtime Bounds
- To prevent timeouts or resource exhaustion on shared hosting:
  - `max_execution_seconds` (default: 180s) caps the run time.
  - The orchestrator measures elapsed time per company and gracefully aborts (`TIMEOUT_ABORTED`) if the time budget is exhausted, alerting operators.

### D. Mutex Non-Overlap Without Daemons
- Standard cache mutex locks (`reconciliation:orchestration:mutex`, TTL: 300s) prevent concurrent runs even if external cron triggers overlap.
- No long-running background workers, Supervisor processes, or WebSockets are required.

### E. Zero Financial Values and Zero PII in Alerts
- All alert messages undergo sanitization via `ReconciliationAlertSanitizer`.
- **Absolute Redaction**:
  - Currency symbols (`$`, `€`, `£`, `ILS`, `USD`, `JOD`, `BHD`, etc.) and monetary amounts are redacted to `[REDACTED_AMOUNT]`.
  - Floating-point numbers, rates, and decimals are redacted to `[REDACTED_DECIMAL]`.
  - Customer, vendor, and user emails are redacted to `[REDACTED_EMAIL]`.
  - Phone numbers are redacted to `[REDACTED_PHONE]`.
  - Personal business names are never included; companies are identified solely by their opaque public ULID.

### F. Explicit Configurable Recipient
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

    // Lock TTL to prevent concurrent runs on shared hosting (default: 300s)
    'lock_ttl_seconds' => (int) env('RECONCILIATION_LOCK_TTL_SECONDS', 300),

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
