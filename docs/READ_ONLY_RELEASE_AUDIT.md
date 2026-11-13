# Release snapshot audit — draft, disposable qualification only

This source tool is **not approved for production**. Independent root review and further qualification are required. Production invocation is currently refused, rather than exposing an unqualified live path. Existing application/resources/tests behavior is unchanged.

Run with PHP CLI from a clean, pinned checkout:

```
php tools/release-audit/audit.php --disposable-test CONTROL CONTROL_SHA256 CODE_SHA OUTPUT
```

No defaults are adopted from application configuration. CONTROL is an operator-created JSON file with mode `disposable-test`, host `127.0.0.1`, integer port `3342`, user `test_runner`, password `""`, schema owned by the Phase8 runner, server_hostname and integer server_id independently obtained for that disposable server. The runner's protected cryptographic ownership capability is additionally required. A control digest is an explicit invocation pin, not independent server ownership proof. It must never be used to adopt an existing database.

Control and output directories must already exist as real absolute directories owned by the invoking UID with mode0700. Control files use0600. No links, traversal, public/public_html components or overwrites. Output basename must be `audit-<24 lowercase hex>.json`. The tool creates only that new0600 file. Failures emit only `AUDIT_REFUSED`, exit1; unhealthy findings exit2; healthy completed audit exit0. Interrupted output is not acceptance evidence.

The PDO starts a database-engine repeatable-read READ ONLY consistent snapshot before metadata/business queries. The minimal container uses that exact PDO, without application providers, environment files, sessions, authentication, event listeners or Artisan bootstrap. No canonical history is written. Six actual service reports are inspected per company in explicit system mode; Inventory uses auditCompany. Findings are reduced to health/count/SHA256; raw findings, balances and records are not emitted. Payables retains its accepted policy and is not a whole AP-ledger proof. Existing known integrity defects remain release considerations.

Every table must be a real InnoDB table with a primary key. Full rows are hashed in primary-key order with length-framed bytes and a distinct null tag; no decimal conversion occurs. SHOW CREATE TABLE is hashed separately, including auto-increment state. Unsupported tables fail closed. All tables are covered: sessions/cache/cache_locks/jobs/job_batches/failed_jobs/password_reset_tokens are explicitly marked operational_mutable, but still compared. Remaining tables are preservation scope; this classification needs root review for deployment-specific additions. This is one database snapshot, not a coherent filesystem snapshot or backup. Binary/null/exact-decimal values are retained in hashing. No raw records are written.

`php tools/release-audit/compare.php BEFORE AFTER TRUSTED_IMPLEMENTATION_SHA256` requires protected reports and the identical implementation digest; exit0 means identical full table fingerprints, exit2 differences, exit1 refusal. It does not certify overall release acceptance. Reports record code, executable and implementation SHA256 and optional build-manifest hash. A manifest hash alone does not verify deployed asset bytes. Reports currently do not fingerprint business files/configuration or independently verify grants, DB server transport identity, full source/dependency integrity, or a coherent build. No universal data-preservation claim is made.

## Executed qualification and evidence

Qualification executed on disposable runner with fresh nonce-owned MariaDB 3342 schema (`accounting_p8_tmp_%`):

1. **Refusal suite (`tests/Unit/ReleaseAudit/RefusalTest.php`)**: 5 tests, 57 assertions passed without live database interaction. Proved zero mutation, no socket connection, and immediate `AUDIT_REFUSED` exit 1 on invalid CLI modes (`--production`), missing/invalid paths, non-0600/0700 permissions, directory traversal, symlinks, public folder paths, tampered control pins, mismatched code pins, non-canonical output names, and corrupted compare inputs.
2. **Database lifecycle suite (`tests/Unit/ReleaseAudit/ReleaseAuditDatabaseTest.php`)**: 3 tests, 52 assertions passed on fresh nonce-owned disposable schema (`tests/Support/run-phase8-disposable.php`). Proved:
   - Database engine READ ONLY snapshot enforces no DML (negative test throwing PDOException SQLSTATE 25006).
   - Deterministic table and schema fingerprinting across InnoDB tables with primary keys, capturing exact decimals and classifying operational mutable tables (`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`) vs preservation scope.
   - Canonical execution of all six services (`AccountingReconciliationService`, `InventoryReconciliationService::auditCompany`, `SalesReconciliationService`, `PayablesReconciliationService`, `MoneyReconciliationService`, `Phase7ReconciliationService`) in context-free system mode on representative fixtures with exit 0 and sanitized digest reports.
   - Discrepancy detection exiting with code 2, setting `healthy: false`, reporting domain discrepancy count and SHA-256 digest with zero raw business records or exception traces leaked.
   - Deterministic fingerprint comparisons via `tools/release-audit/compare.php` (`FINGERPRINTS_EQUAL` exit 0 vs `FINGERPRINTS_DIFFER` exit 2 vs refusal exit 1).
   - Server identity mismatch (`server_hostname`, `server_id`) refusing before running audit queries.
   - Complete schema drop and temporary file cleanup verified by the isolated runner.
3. **Static analysis & formatting**:
   - `vendor/bin/phpstan analyse tools/ tests/Unit/ReleaseAudit/`: 0 errors.
   - `vendor/bin/pint --test tools/ tests/Unit/ReleaseAudit/`: passed.

Off-host coherent backup, encryption-key recovery, actual authenticated EN/AR browser smoke, HTTPS assets/MIME, maintenance custody and release/reopening acceptance remain separate root-only gates under CONTROLLED_RELEASE_CHECKLIST and PHASE_10_BACKUP_RESTORE_RUNBOOK. No production connection or recovery rehearsal was performed to prepare this tooling.

Supervisor source correction (QA pending): implementation identity now hashes a named manifest of individual SHA-256s including compare.php, rather than unframed concatenation. Compare requires an external trusted pin matching local implementation and both reports. The operator must obtain that pin independently of untrusted reports. An empty company scope is unhealthy (exit2, scope_empty=true), not a successful reconciliation. These corrections are not yet runtime-qualified. On interrupted output, preserve the incomplete file as failed evidence and choose a new nonce filename; no automatic pathname unlink is added. Production mode remains refused.

## Supervisor follow-up qualification

Executable source2ac072b302e680fe7ac45b32a7027f53d78622dc passed 18 tests / 144 assertions through a fresh nonce-owned MariaDB runner, primary553b0dcbe895 runner-cleaned. BEFORE that database execution, nine actual-lifecycle refusal cases / 27 assertions passed with zero recorded queries, exercising invalid opt-in/configuration/proof and partial teardown. The earlier refusal-harness failure (9/23, oneerror/onefailure) is retained; it was a test observer resolving malformed connection configuration before the guard, plus missing explicit administrative test settings.

The database test class now invokes the previously reviewed fc6422 ownership trait at the start of setup before fixtures; partial-setup teardown handles an unset temporary directory. This repairs a gap missed by the first source review, not a claim that direct legacy test invocation was safe. No existing PR94 lifecycle was changed. Nonparallel scoped PHPStan and Pint passed. See docs/evidence/pr101-supervisor-followup for exact source, commands and raw/published log hashes.

The earlier statement that supervisor corrections were QA-pending is superseded only for these disposable guards/pins/empty-scope tests. Production mode remains refused, and this is NOT coherent off-host recovery, production audit qualification or deployment acceptance.
