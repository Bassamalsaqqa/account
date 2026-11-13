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

`php tools/release-audit/compare.php BEFORE AFTER` requires protected reports and the identical implementation digest; exit0 means identical full table fingerprints, exit2 differences, exit1 refusal. It does not certify overall release acceptance. Reports record code, executable and implementation SHA256 and optional build-manifest hash. A manifest hash alone does not verify deployed asset bytes. Reports currently do not fingerprint business files/configuration or independently verify grants, DB server transport identity, full source/dependency integrity, or a coherent build. No universal data-preservation claim is made.

Outstanding qualification: comprehensive refusal/partial-setup tests, representative canonical fixtures across all six domains, READ ONLY negative DML test, before/after and corruption/redaction tests on the nonce-owned MariaDB runner, and application+test+script static analysis. No production enablement until those are independently reviewed. This draft deliberately has no restore/import/decrypt, backup reader, maintenance, migration, deployment or reopening executor.

Off-host coherent backup, encryption-key recovery, actual authenticated EN/AR browser smoke, HTTPS assets/MIME, maintenance custody and release/reopening acceptance remain separate root-only gates under CONTROLLED_RELEASE_CHECKLIST and PHASE_10_BACKUP_RESTORE_RUNBOOK. No production connection or recovery rehearsal was performed to prepare this tooling.
