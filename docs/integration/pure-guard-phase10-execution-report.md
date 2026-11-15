# Pure Focused QA Suite Execution Report (Phase 10)

- **Date / Time**: 2026-11-15T13:36:44Z
- **Execution Target Commit**: `8c00888a8dc8d070631ac56adbc551b98422e203`
- **Branch**: `t3/recover-cash-writer`
- **Runner**: PHPUnit 12.5.36 (PHP 8.4.27)
- **Lease Scope**: Exclusive short focused pure QA lease on `tests/Unit/Mobile/Harness/SupervisedFinancialDrillTest.php` (37 methods expected).
- **Grant Constraints**: Zero DB, zero listeners, zero TLS, zero JVM, unconnectable ambient DB port 1 (`127.0.0.1:1`), `PHASE8_ALLOW_DISPOSABLE_DB=0`, `MOBILE_V2_HARNESS_ENABLE=0`, stop on first failure / first error, zero autofix, zero retry, mocked PDO only.
- **Prior Run Artifacts**: Phase 1 through Phase 9 logs, XMLs, and private attachments preserved unmodified.

---

### PRE-Execution Integrity Verification

- **Commit HEAD**: `8c00888a8dc8d070631ac56adbc551b98422e203`
- **Working Tree**: Clean (`0` uncommitted changes)
- **Tracked Source Hashes (PRE)**:
  - `tests/Support/Harness/SupervisedPairedFinancialDrill.php`: `13da872fc92226dd04803d3550e68cb5a3cb5b9c0a6b7d19565e381048b1114d`
  - `tests/Unit/Mobile/Harness/SupervisedFinancialDrillTest.php`: `aa3742416b270ff5021e065bc83424d5ba186000c01a2f02604616238b97d7ee`
  - `tests/Support/DisposableMariaDbSchema.php`: `86e24177dd3f124c65e8d893fa18536d54da8c755ec58254881fa6e927c39050`
  - `tests/Support/Harness/supervised-financial-drill.php`: `58448eaebda9fe266fc03fc32a688846c879d6796eb0c242ef93ca9cba7dbe22`
  - `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`: `a55c5f7b1cc698ec0ca6f64646192a49e290466751ca0eccccb2a5b12090ffda`
- **Contemporaneous Pre-State Record**:
  - Saved to `/home/t3dev/.t3/userdata/attachments/pure_focused_qa_phase10_361cc622/pre.json` (SHA256: `63f079a5f0c4df250e72c95a6682512b84925a48d86eff28a78124e294b07b65`, 298,397 bytes, mode `0600`).

---

### Raw Execution Command & Environment

```bash
DB_HOST=127.0.0.1 DB_PORT=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=1 PHASE8_ALLOW_DISPOSABLE_DB=0 MOBILE_V2_HARNESS_ENABLE=0 APP_ENV=testing \
vendor/bin/phpunit \
  --bootstrap vendor/autoload.php \
  --no-configuration \
  --stop-on-failure \
  --stop-on-error \
  --log-junit=/home/t3dev/.t3/userdata/attachments/pure_focused_qa_phase10_361cc622/junit.xml \
  tests/Unit/Mobile/Harness/SupervisedFinancialDrillTest.php
```

- **Execution Start UTC**: `2026-11-15T13:36:44.812618+00:00`
- **Execution End UTC**: `2026-11-15T13:36:45.016270+00:00`
- **Execution Duration**: `0.203652s`
- **Exit Code**: `0`

---

### Execution Results

- **Stop Policy Enforced**: `--stop-on-failure` and `--stop-on-error` active; single invocation; zero autofix, zero retries.
- **Total Tests Expected**: 37
- **Tests Executed**: 37
- **Assertions Completed**: 241
- **Tests Passed**: 37
- **Failures**: 0
- **Errors**: 0
- **Skipped**: 0
- **PHPUnit Notices**: 10 (all expected cleanup log notices from simulated failure drills)
- **Suite Outcome**: ALL 37 TESTS PASSED (100%)
- **Unreached Test Inventory Preserved**: 0 tests

#### Suite Breakdown
- `SupervisedFinancialDrillTest.php`:
  - `test_prestart_approval_fails_closed_when_ttl_is_missing`: PASSED (2 assertions)
  - `test_prestart_approval_fails_closed_when_ttl_is_expired`: PASSED (2 assertions)
  - `test_prestart_approval_allows_identical_publication_and_executable_heads`: PASSED (3 assertions)
  - `test_live_root_approval_fails_closed_when_ttl_is_missing`: PASSED (2 assertions)
  - `test_live_root_approval_fails_closed_when_scope_or_schema_mismatches`: PASSED (2 assertions)
  - `test_await_live_root_approval_fails_when_child_terminates`: PASSED (2 assertions)
  - `test_readiness_probe_fails_on_untrusted_external_url`: PASSED (2 assertions)
  - `test_handoff_triple_match_fails_when_wire_json_tampered`: PASSED (2 assertions)
  - `test_canonical_profile_digest_normalization_and_raw_wire_inequality`: PASSED (4 assertions)
  - `test_verify_evidence_freshness_accepts_golden_25ae_fixture`: PASSED (5 assertions)
  - `test_verify_evidence_freshness_rejects_alias_mutations`: PASSED (2 assertions)
  - `test_verify_evidence_freshness_rejects_scenario_mutations`: PASSED (2 assertions)
  - `test_exact_cleanup_skips_destructive_actions_when_snapshot_is_null`: PASSED (3 assertions)
  - `test_exact_cleanup_rejects_foreign_db_port`: PASSED (5 assertions)
  - `test_exact_cleanup_rejects_malformed_schema_via_factory`: PASSED (2 assertions)
  - `test_exact_cleanup_reports_failure_when_schema_lingers`: PASSED (4 assertions)
  - `test_exact_cleanup_succeeds_when_clean`: PASSED (4 assertions)
  - `test_capture_snapshot_rejects_invalid_schema_name`: PASSED (2 assertions)
  - `test_capture_snapshot_rejects_zero_child_pid`: PASSED (2 assertions)
  - `test_capture_snapshot_rejects_invalid_proof_nonce_format`: PASSED (2 assertions)
  - `test_capture_snapshot_env_is_restored_after_proof_assertion_failure`: PASSED (5 assertions)
  - `test_capture_snapshot_rejects_proof_parent_insecure_perms`: PASSED (2 assertions)
  - `test_disposable_mariadb_schema_creates_private_0700_proof_parent_directory`: PASSED (10 assertions)
  - `test_actual_drill_ordering_tls_failure_triggers_cleanup_with_early_captured_snapshot`: PASSED (13 assertions)
  - `test_prelive_failure_cleanup_with_captured_snapshot`: PASSED (9 assertions)
  - `test_exact_cleanup_removes_nested_subdirectories_and_unlinks_symlink_without_touching_outside_target`: PASSED (8 assertions)
  - `test_exact_cleanup_refuses_unsafe_harness_dir_pointing_to_repo_or_system_root`: PASSED (7 assertions)
  - `test_golden_fixture_has_distinct_provenance_between_handoff_sha_and_profile_digest`: PASSED (18 assertions)
  - `test_exact_cleanup_pid_mismatch_refuses_signals`: PASSED (4 assertions)
  - `test_exact_cleanup_null_snapshot_skips_child_signals`: PASSED (4 assertions)
  - `test_from_live_ownership_rejects_negative_child_pid`: PASSED (2 assertions)
  - `test_from_live_ownership_rejects_schema_with_nul_byte`: PASSED (2 assertions)
  - `test_from_live_ownership_rejects_schema_with_backtick`: PASSED (2 assertions)
  - `test_from_live_ownership_rejects_harness_dir_inside_repo`: PASSED (2 assertions)
  - `test_from_live_ownership_rejects_invalid_harness_dir_name`: PASSED (2 assertions)
  - `test_scoped_cleanup_and_three_store_env_restoration_lifecycle`: PASSED (65 assertions)
  - `test_scoped_cleanup_restores_all_stores_even_when_cleanup_throws`: PASSED (32 assertions)

---

### POST-Execution Invariance & Process Absence Attestation

- **POST Commit HEAD**: `8c00888a8dc8d070631ac56adbc551b98422e203` (Identical to PRE)
- **POST Working Tree**: Clean (`0` uncommitted changes prior to docs publication)
- **Source Hashes (POST)**: All tracked repository files verified byte-for-byte identical to PRE state.
- **Process Absence Check**: Verified 0 active PHPUnit, artisan, or harness child processes.
- **Contemporaneous Post-State Record**:
  - Saved to `/home/t3dev/.t3/userdata/attachments/pure_focused_qa_phase10_361cc622/post.json` (SHA256: `67346a0b8688952127f33d42b9783213cfe679ac82443a24877e1941e0876605`, 298,397 bytes, mode `0600`).

---

### Retained Contemporaneous Machine Artifact Index

The execution environment automatically persisted contemporaneous raw evidence in private directory `/home/t3dev/.t3/userdata/attachments/pure_focused_qa_phase10_361cc622/` (mode `0700`, files `0600`):

| File Name | SHA-256 Digest | Size (Bytes) | Mode | Purpose |
|---|---|---|---|---|
| `pre.json` | `63f079a5f0c4df250e72c95a6682512b84925a48d86eff28a78124e294b07b65` | 298,397 | `0600` | Contemporaneous pre-execution HEAD, status, and full source hash map |
| `post.json` | `67346a0b8688952127f33d42b9783213cfe679ac82443a24877e1941e0876605` | 298,397 | `0600` | Contemporaneous post-execution HEAD, status, and source invariance verification |
| `junit.xml` | `e09daf77645a0c64be70e530b93625c2502602d7843221196610acf7cd74a578` | 14,982 | `0600` | Complete JUnit XML report with all 37 test cases and timings |
| `raw_stdout_stderr.txt` | `b2607f32c53859334d56c9a17ef74be664945bec5f2836ab0e63fbb3c7b3d03d` | 818 | `0600` | Raw combined standard output and error stream |
| `run_metadata.json` | `177cdf3180d0b2f9fea660496dcb33adfacdb619dc70374ba6cf6375324a7cc8` | 948 | `0600` | Execution timing, exact argv, non-secret env, and exit code |
| `machine_artifact_index.json` | `fce87c98c8c8ec1cd41f620aed1233824a31bd5a9ea321bb0425335518ef4029` | 774 | `0600` | Byte-level manifest index of all retained run files |

---

### Published Sanitized Documents

- [`docs/integration/pure-guard-phase10-execution-report.md`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/pure-guard-phase10-execution-report.md)
- [`docs/integration/pure-guard-phase10-junit.xml`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/pure-guard-phase10-junit.xml)
- [`docs/integration/pure-guard-phase10-run-output.txt`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/pure-guard-phase10-run-output.txt)
- [`docs/integration/pure-guard-phase10-machine-manifest.json`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/pure-guard-phase10-machine-manifest.json)

---

### Exclusive QA Lease Release

The short exclusive pure QA lease is immediately released. All execution artifacts remain securely retained.
