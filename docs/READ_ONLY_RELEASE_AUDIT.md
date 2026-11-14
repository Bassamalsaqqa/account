# Release snapshot audit — draft, disposable qualification only

This source tool is **not approved for production**. Independent root review and further qualification are required. Production invocation is currently refused, rather than exposing an unqualified live path. Existing application/resources/tests behavior is unchanged.

Run with PHP CLI from a clean, pinned checkout:

```
php tools/release-audit/audit.php --disposable-test CONTROL CONTROL_SHA256 CODE_SHA OUTPUT
```

No defaults are adopted from application configuration. CONTROL is an operator-created JSON file with mode `disposable-test`, host `127.0.0.1`, integer port `3342`, user `test_runner`, password `""`, schema owned by the Phase8 runner, server_hostname and integer server_id independently obtained for that disposable server. The runner's protected cryptographic ownership capability is additionally required. A control digest is an explicit invocation pin, not independent server ownership proof. It must never be used to adopt an existing database.

Control and output directories must already exist as real absolute directories owned by the invoking UID with mode0700. Control files use0600. No links, traversal, public/public_html components or overwrites. Output basename must be `audit-<24 lowercase hex>.json`. The tool creates only that new0600 file. Failures emit only `AUDIT_REFUSED`, exit1; unhealthy findings exit2; healthy completed audit exit0. Interrupted output is not acceptance evidence.

The PDO starts a database-engine repeatable-read READ ONLY consistent snapshot before metadata/business queries. The minimal container uses that exact PDO, without application providers, environment files, sessions, authentication, event listeners or Artisan bootstrap. No canonical history is written. Six actual service reports are inspected per company in explicit system mode; Inventory uses auditCompany. Findings are reduced to health/count/SHA256; raw findings, balances and records are not emitted. Payables retains its accepted policy and is not a whole AP-ledger proof. Existing known integrity defects remain release considerations.

Every table must be a real InnoDB table with a primary key. Row scanning demonstrably uses a two-phase bounding strategy:
- **Phase A (Engine-Only Numeric Aggregates)**: Queries `SELECT COUNT(*), MAX(COALESCE(OCTET_LENGTH(c), 0)), SUM(COALESCE(OCTET_LENGTH(c), 0)), MAX(OCTET_LENGTH(c))` returning only bounded numeric totals. Validates row counts, maximum single-row memory, cumulative byte sum, and column cell byte limits using overflow-safe exact arithmetic (using declared `brick/math` dependency from `composer.json` with pure PHP string arithmetic fallback, requiring no undeclared extension) *before* any payload query is issued.
- **Phase B (Value Streaming)**: Streams values with unbuffered query (`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false`) in primary-key order. Values are fed directly into the incremental streaming SHA-256 hash context without in-memory duplicate representations (`frame()`) or decimal conversions.

On normal completion, `closeCursor()` is invoked and the caller's previous buffering attribute is restored. On early abort (budget exceedance, error, or cancellation), `closeCursor()` is intentionally skipped to avoid `mysqlnd` stalling by draining unread network packets over the socket, Laravel container/connection/resolver references are explicitly purged, and the PDO database connection reference is cleared. *Note: Driver-level PDO destructor behavior does not guarantee immediate socket termination across all client environments, so actual socket cancellation and network abort semantics require live integration qualification under the database lease.* Hard SQL statement timeouts (`max_statement_time` on MariaDB, `max_execution_time` on MySQL) are enforced before every query via a registered `DB::beforeExecuting` hook and raw PDO calls, tracking the exact remaining deadline across multi-query services; if statement timeout is unsupported or already expired, the workload is explicitly refused with `AUDIT_REFUSED`. Uninstrumented Eloquent result rows inside reconciliation services are bounded by elapsed execution time and memory assertions rather than global byte/row counters.

SHOW CREATE TABLE is hashed separately. `AUTO_INCREMENT` stripping parses table options using quote-aware parenthesis nesting depth, strictly targeting the table definition closing parenthesis so that default expressions, column comments, or table options containing nested parentheses and quotes are preserved intact. Counter values above `PHP_INT_MAX` are retained as exact numeric strings without floating-point degradation. Substantive DDL alterations (`normalized_schema_sha256`) are separated from counter volatility, ensuring schema changes on any table always trigger preservation differences.

A single shared cumulative budget (`Budget`) spans the complete audit lifecycle: metadata inspection, initial table fingerprinting, all six canonical reconciliation services across all companies, and final table fingerprinting. Execution time and memory bounds cover all phases; row and byte bounds track audit metadata and fingerprint streaming. Individual cell sizes exceeding bounded thresholds are explicitly refused before unbounded allocations occur. Observed initial scan, final scan, and cumulative resource metrics (`peak_memory_bytes`, `total_rows`, `total_bytes`, `duration_seconds`) are recorded in the audit report.

`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens` are classified as `operational_mutable`. All remaining tables are preservation scope. Table classification is derived exclusively from the canonical table name allowlist.

`php tools/release-audit/compare.php BEFORE AFTER TRUSTED_IMPLEMENTATION_SHA256 [--accept-operational-volatility]` requires protected reports and the identical implementation digest (`Guard::implementationDigest()`). Semantics:
- Identical reports: exit 0 (`FINGERPRINTS_EQUAL`).
- Substantive DDL differences or preservation table mutations (financial, inventory, receipt, money records): exit 2 (`FINGERPRINTS_DIFFER`). These can NEVER be accepted or bypassed by operational flags.
- Operational-only churn (row count/hash changes or counter volatility on `operational_mutable` tables only):
  - Without disposition flag: distinct non-success exit 3 (`OPERATIONAL_VOLATILITY_ONLY`), listing volatile tables.
  - With `--accept-operational-volatility`: exit 0 (`OPERATIONAL_VOLATILITY_ACCEPTED`), emitting an explicit operator notice that operational churn acceptance is a comparison disposition only and never a release approval.
- Tampered reports, mismatched implementation SHA, invalid paths, missing/invalid required fields, or unknown arguments: exit 1 (`AUDIT_REFUSED`).

## Executed qualification and evidence

Qualification executed on disposable runner with fresh nonce-owned MariaDB 3342 schema (`accounting_p8_tmp_%`):

1. **Refusal suite (`tests/Unit/ReleaseAudit/RefusalTest.php`)**: Tests passed without live database interaction. Proved zero mutation, no socket connection, and immediate `AUDIT_REFUSED` exit 1 on invalid CLI modes (`--production`), missing/invalid paths, non-0600/0700 permissions, directory traversal, symlinks, public folder paths, tampered control pins, mismatched code pins, non-canonical output names, corrupted compare inputs, invalid limit types/ranges, and unknown control keys. Proved compare tool classification of operational churn (exit 3 / exit 0 with flag) vs strict preservation failure (exit 2) vs substantive schema DDL failure (exit 2). Proved quote-aware nested parenthesis DDL parsing, exact counter string retention, cumulative multi-phase budget enforcement without reset, zero-second deadline refusal, oversized numeric cell byte refusal before payload query, and unsupported SQL timeout refusal.
2. **Database lifecycle suite (`tests/Unit/ReleaseAudit/ReleaseAuditDatabaseTest.php`)**: Passed on fresh nonce-owned disposable schema (`tests/Support/run-phase8-disposable.php`). Proved:
   - Database engine READ ONLY snapshot enforces no DML (negative test throwing PDOException SQLSTATE 25006).
   - Deterministic table and schema fingerprinting across InnoDB tables with primary keys, capturing exact decimals, distinguishing substantive DDL from counter volatility, and classifying operational mutable tables (`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`) vs preservation scope.
   - Demonstrable unbuffered streaming with bounded memory, cursor cleanup, and budget-exceeded refusal with zero partial output created.
   - Canonical execution of all six services (`AccountingReconciliationService`, `InventoryReconciliationService::auditCompany`, `SalesReconciliationService`, `PayablesReconciliationService`, `MoneyReconciliationService`, `Phase7ReconciliationService`) in context-free system mode on representative fixtures with exit 0 and sanitized digest reports.
   - Discrepancy detection exiting with code 2, setting `healthy: false`, reporting domain discrepancy count and SHA-256 digest with zero raw business records or exception traces leaked.
   - Empty company scope failing as unhealthy (exit 2, `scope_empty: true`).
   - Deterministic fingerprint comparisons via `tools/release-audit/compare.php` (`FINGERPRINTS_EQUAL` exit 0 vs `FINGERPRINTS_DIFFER` exit 2 vs `OPERATIONAL_VOLATILITY_ONLY` exit 3 vs `OPERATIONAL_VOLATILITY_ACCEPTED` exit 0 vs refusal exit 1).
   - Server identity mismatch (`server_hostname`, `server_id`) refusing before running audit queries.
   - Complete schema drop and temporary file cleanup verified by the isolated runner.
   - Prior buffering attribute preservation and shared cumulative budget enforcement.
   - Phase A engine-side numeric aggregate refusal before payload query execution on oversized cells/rows, verified via an anonymous PDO query observer proving zero Phase B payload queries are ever issued.
   - Empty table (`COUNT(*)=0`), `NULL` column values, `BIGINT UNSIGNED` overflow (`18446744073709551614 > PHP_INT_MAX`), and 50KB representative large data handling without numeric degradation or precision loss.
   - Multi-query deadline expiration and process memory tracking assertions under early abort.
3. **Static analysis & formatting**:
   - `vendor/bin/phpstan analyse tools/ tests/Unit/ReleaseAudit/`: 0 errors (predates e6 lease restriction).
   - `vendor/bin/pint --test tools/ tests/Unit/ReleaseAudit/`: passed (predates e6 lease restriction).

Off-host coherent backup, encryption-key recovery, actual authenticated EN/AR browser smoke, HTTPS assets/MIME, maintenance custody and release/reopening acceptance remain separate root-only gates under CONTROLLED_RELEASE_CHECKLIST and PHASE_10_BACKUP_RESTORE_RUNBOOK. No production connection or recovery rehearsal was performed to prepare this tooling. Production mode remains unconditionally refused.

## Resource lease and execution qualification status

PR #100 lease released; exclusive DB, build, and dependency lease transferred to PR #101.
- All zero-connection refusals executed in `tests/Unit/ReleaseAudit/RefusalTest.php`.
- Disposable test database execution on nonce-owned MariaDB 3342 (`tests/Support/run-phase8-disposable.php`), scoped `phpstan --debug`, and `pint` formatting qualified under the exclusive QA lease.
- 15 pure CLI regressions passed without Laravel bootstrap, database connections, or dependencies: `python3 tests/Unit/ReleaseAudit/compare_classification_test.py`.
- PHP syntax checks passed clean across all tool and test files (`php -l`).
- All workload bounding (Phase A engine-side numeric aggregates before payload queries with PDO query observer verification, Phase B direct streaming hash without duplicate frame strings, DB::beforeExecuting hook updating per-query deadlines across services, connection/resolver purging on abort, and quote-aware nested parenthesis DDL normalization) is fully implemented and qualified.
