# Six-Domain Reconciliation Operational Guide

Bounded, strictly **read-only** six-domain integrity auditing and alert integration for Hostinger shared hosting (no Redis, Docker, WebSockets, or persistent background workers).

## 1. Six Canonical Domains & CLI Commands

| # | Domain | Service | CLI Command | Primary Invariants |
|---|---|---|---|---|
| 1 | **Accounting** | `AccountingReconciliationService` | `php artisan accounting:reconcile [id]` | Double-entry balance, system accounts, FX rates, non-negativity |
| 2 | **Inventory** | `InventoryReconciliationService` | `php artisan inventory:reconcile --all` | Moving-average cost, movement sequence, FEFO lots, transfers |
| 3 | **Sales** | `SalesReconciliationService` | `php artisan sales:reconcile [id]` | Header/line sums, tax, customer isolation, batches, allocations, returns |
| 4 | **Payables** | `PayablesReconciliationService` | `php artisan payables:reconcile [id]` (`purchasing:reconcile`) | Vendor isolation, purchase totals, tax snapshots, batches, payments |
| 5 | **Money** | `MoneyReconciliationService` | `php artisan money:reconcile [id]` | Account currency parity, routing, transfer symmetry, immutable history |
| 6 | **Phase 7** | `Phase7ReconciliationService` | `php artisan phase7:reconcile [id]` (`accounting:reconcile-phase7`) | Expenses, payroll, advances, landed cost clearing |

## 2. Guardrails & Configuration (`config/reconciliation.php`)

- **Read-only:** Never mutates posting batches, stock movements, or balances.
- **Default OFF:** `RECONCILIATION_SCHEDULE_ENABLED=false` (CLI refuses without `--force`; scheduler skips).
- **Bounded child execution:** `ReconciliationProcessRunner` enforces `per_task_timeout_seconds` (`30s`) and `max_execution_seconds` (`180s`) via `SIGTERM`/`SIGKILL`; timeouts abort (`TIMEOUT_ABORTED`), alert, and never emit a healthy heartbeat.
- **Overlap mutex & memory:** Lock `reconciliation:orchestration:mutex` TTL (`max(lock_ttl_seconds, max_execution_seconds + 60)`, default `300s`) outlives execution; keyset chunking (`company_chunk_size=50`) bounds memory; ambient `CompanyContext` restores in `finally`.
- **Sanitized alerts:** `ReconciliationAlertSanitizer` emits allowlisted category, count, and status only (zero party names, raw SQL, or amounts). Email requires `RECONCILIATION_ALERT_RECIPIENT`; `RECONCILIATION_ALERTS_ENABLED=false`, channel defaults `'log'`, `record_platform_health=true`, `heartbeat_enabled=true`.

## 3. CLI & Scheduler (`routes/console.php`)

```bash
# Run all six domains manually while schedule toggle is OFF
php artisan reconciliation:run --force
php artisan reconciliation:run <companyPublicId> --force
# Aliases: reconciliation:orchestrate, platform:reconcile-all
```

`routes/console.php` schedules `reconciliation:run` daily at `02:00` with `withoutOverlapping()` gated by `config('reconciliation.enabled')` (preserving `ai-assistant:prune` hourly and `subscriptions.enforcement_enabled=false`).

## 4. Verification State

Isolated unit/process tests and multi-company disposable MariaDB six-domain reconciliations pass across Phase 10, opening-trade, and paired 12-vector suites. Production six-domain checks passed for both companies. Scheduled checks and alerts remain **OFF** (`RECONCILIATION_SCHEDULE_ENABLED=false`) until separately enabled by the Owner.
