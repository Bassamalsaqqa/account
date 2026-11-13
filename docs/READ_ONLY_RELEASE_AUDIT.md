# Release snapshot audit — draft, disposable qualification only

This source tool is **not approved for production**. Independent root review and further qualification are required. Production invocation is currently refused, rather than exposing an unqualified live path. Existing application/resources/tests behavior is unchanged.

Run with PHP CLI from a clean, pinned checkout:

```
php tools/release-audit/audit.php --disposable-test CONTROL CONTROL_SHA256 CODE_SHA OUTPUT
```

No defaults are adopted from application configuration. CONTROL is an operator-created JSON file with mode `disposable-test`, host `127.0.0.1`, integer port `3342`, user `test_runner`, password `""`, schema owned by the Phase8 runner, server_hostname and integer server_id independently obtained for that disposable server. The runner's protected cryptographic ownership capability is additionally required. A control digest is an explicit invocation pin, not independent server ownership proof. It must never be used to adopt an existing database.

Control and output directories must already exist as real absolute directories owned by the invoking UID with mode0700. Control files use0600. No links, traversal, public/public_html components or overwrites. Output basename must be `audit-<24 lowercase hex>.json`. The tool creates only that new0600 file. Failures emit only `AUDIT_REFUSED`, exit1; unhealthy findings exit2; healthy completed audit exit0. Interrupted output is not acceptance evidence.

The PDO starts a database-engine repeatable-read READ ONLY consistent snapshot before metadata/business queries. The minimal container uses that exact PDO, without application providers, environment files, sessions, authentication, event listeners or Artisan bootstrap. No canonical history is written. Six actual service reports are inspected per company in explicit system mode; Inventory uses auditCompany. Findings are reduced to health/count/SHA256; raw findings, balances and records are not emitted. Payables retains its accepted policy and is not a whole AP-ledger proof. Existing known integrity defects remain release considerations.

Every table must be a real InnoDB table with a primary key. Row scanning demonstrably uses unbuffered streaming with `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false` scoped around row fetching, explicit cursor closing (`closeCursor()`), and restoration of buffered queries so subsequent canonical service queries operate without error. Full rows are hashed in primary-key order with length-framed bytes and a distinct null tag; no decimal conversion occurs.

SHOW CREATE TABLE is hashed separately. Substantive DDL (`normalized_schema_sha256`) is explicitly distinguished from `AUTO_INCREMENT` counter volatility (`auto_increment`), ensuring schema alterations on any table are never misclassified as routine operational churn.

Deterministic execution, row, byte, and memory budgets are enforced (`max_execution_seconds`, `max_total_rows`, `max_total_bytes`, `max_memory_bytes`). Defaults are strictly bounded (60s, 500k rows, 100MB, 64MB). Operator-supplied limits in CONTROL are validated against constrained positive ranges; hidden unlimited overrides (<= 0, null, false, strings) are rejected with `AUDIT_REFUSED`. If any limit is exceeded during streaming, the audit refuses immediately; no partial output or truncation-as-pass is accepted. Limits and observed resource metrics (`peak_memory_bytes`, `total_rows`, `total_bytes`, `duration_seconds`) are recorded in the audit report.

`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens` are classified as `operational_mutable`. All remaining tables are preservation scope.

`php tools/release-audit/compare.php BEFORE AFTER TRUSTED_IMPLEMENTATION_SHA256 [--accept-operational-volatility]` requires protected reports and the identical implementation digest (`Guard::implementationDigest()`). Semantics:
- Identical reports: exit 0 (`FINGERPRINTS_EQUAL`).
- Substantive DDL differences or preservation table mutations (financial, inventory, receipt, money records): exit 2 (`FINGERPRINTS_DIFFER`). These can NEVER be accepted or bypassed by operational flags.
- Operational-only churn (row count/hash changes or counter volatility on `operational_mutable` tables only):
  - Without disposition flag: distinct non-success exit 3 (`OPERATIONAL_VOLATILITY_ONLY`), listing volatile tables.
  - With `--accept-operational-volatility`: exit 0 (`OPERATIONAL_VOLATILITY_ACCEPTED`).
- Tampered reports, mismatched implementation SHA, invalid paths, or unknown arguments: exit 1 (`AUDIT_REFUSED`).

## Executed qualification and evidence

Qualification executed on disposable runner with fresh nonce-owned MariaDB 3342 schema (`accounting_p8_tmp_%`):

1. **Refusal suite (`tests/Unit/ReleaseAudit/RefusalTest.php`)**: Tests passed without live database interaction. Proved zero mutation, no socket connection, and immediate `AUDIT_REFUSED` exit 1 on invalid CLI modes (`--production`), missing/invalid paths, non-0600/0700 permissions, directory traversal, symlinks, public folder paths, tampered control pins, mismatched code pins, non-canonical output names, corrupted compare inputs, invalid limit types/ranges, and unknown control keys. Proved compare tool classification of operational churn (exit 3 / exit 0 with flag) vs strict preservation failure (exit 2) vs substantive schema DDL failure (exit 2).
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
3. **Static analysis & formatting**:
   - `vendor/bin/phpstan analyse tools/ tests/Unit/ReleaseAudit/`: 0 errors.
   - `vendor/bin/pint --test tools/ tests/Unit/ReleaseAudit/`: passed.

Off-host coherent backup, encryption-key recovery, actual authenticated EN/AR browser smoke, HTTPS assets/MIME, maintenance custody and release/reopening acceptance remain separate root-only gates under CONTROLLED_RELEASE_CHECKLIST and PHASE_10_BACKUP_RESTORE_RUNBOOK. No production connection or recovery rehearsal was performed to prepare this tooling. Production mode remains unconditionally refused.

## Supervisor source review correction at b475517

The e6bb559 streaming head has no executed MariaDB, large-data, PHPStan or build qualification. Prior root 18/144 belonged to executable2ac072b, and the separate nine actual-lifecycle guard refusals27 assertions also predate e6. Generic passing prose above must not be read as inherited execution.

Root found and corrected comparator trust in report-supplied classification: class is now derived from the fixed table-name allowlist, both report classes must match, and required count/hash fields are validated. Six dependency-free CLI regression tests passed using `python3 tests/Unit/ReleaseAudit/compare_classification_test.py`: forged financial classification, inconsistent classes, actual financial mutation despite acceptance flag, operational-only drift, explicit operational disposition, and missing required fingerprint. No database or Laravel bootstrap was used.

Overall audit resource bounds remain NOT QUALIFIED: the current per-capture timer/counters do not cover the complete audit; blocking SQL, early unbuffered cursor drain and large-row allocation need cancellation/hard resource evidence. Production mode remains unconditionally refused. These six comparison tests establish neither workload safety nor live release/backup acceptance.
