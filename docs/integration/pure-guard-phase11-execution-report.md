# Pure Focused QA Suite Execution Report (Phase 11)

- **Date / Time**: 2026-11-15T14:20:36Z
- **Execution Target Commit**: `2b3335a218e77b012aa90737d80aba2edb12e440`
- **Branch**: `t3/recover-cash-writer`
- **Runner**: PHPUnit 12.5.36 (PHP 8.4.27)
- **Lease Scope**: Exclusive short focused pure QA lease on `tests/Unit/Mobile/Harness/SupervisedFinancialDrillTest.php` (44 methods expected).
- **Grant Constraints**: Zero DB, zero listeners, zero TLS, zero JVM, unconnectable ambient DB port 1 (`127.0.0.1:1`), `PHASE8_ALLOW_DISPOSABLE_DB=0`, `MOBILE_V2_HARNESS_ENABLE=0`, stop on first failure / first error, zero autofix, zero retry, mocked PDO only.
- **Prior Run Artifacts**: Phase 1 through Phase 10 logs, XMLs, and private attachments preserved unmodified.

---

### PRE-Execution Integrity Verification

- **Commit HEAD**: `2b3335a218e77b012aa90737d80aba2edb12e440`
- **Working Tree**: Clean (`0` uncommitted changes)
- **Tracked Source Hashes (PRE)**:
  - `tests/Support/Harness/SupervisedPairedFinancialDrill.php`: `62077f6b3b4fc1a5f7853d8b05bc3214ebc54b48f65cf2857ac9c905d62507cb`
  - `tests/Unit/Mobile/Harness/SupervisedFinancialDrillTest.php`: `f4ac7fed58e3569c2f68e2c8092a2181462cd3bb3ce37cf33b91a0ffa67acee7`
  - `tests/Support/DisposableMariaDbSchema.php`: `cead1316742b16dd763d9de7c8a760e9b5f0c5f7e102021d8e7e1d749ad058aa`
  - `tests/Support/Harness/supervised-financial-drill.php`: `26a3debf0d96776c864a82c6ca0012b821f750542720b8cdafa0a9096bed8abd`
  - `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`: `a55c5f7b1cc698ec0ca6f64646192a49e290466751ca0eccccb2a5b12090ffda`
- **Contemporaneous Pre-State Record**:
  - Saved to `/home/t3dev/.t3/userdata/attachments/pure_focused_qa_phase11_4945a81b/pre.json` (SHA256: `9ecaecd5b56b4c11de5c51912cca8a2f9f1f1baccd2f994bdcd19988e922af5f`, 298,916 bytes, mode `0600`).

---

### Raw Execution Command & Environment

```bash
DB_HOST=127.0.0.1 DB_PORT=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=1 PHASE8_ALLOW_DISPOSABLE_DB=0 MOBILE_V2_HARNESS_ENABLE=0 APP_ENV=testing \
vendor/bin/phpunit \
  --bootstrap vendor/autoload.php \
  --no-configuration \
  --stop-on-failure \
  --stop-on-error \
  --log-junit=/home/t3dev/.t3/userdata/attachments/pure_focused_qa_phase11_4945a81b/junit.xml \
  tests/Unit/Mobile/Harness/SupervisedFinancialDrillTest.php
```

- **Execution Start UTC**: `2026-11-15T14:20:36.297561+00:00`
- **Execution End UTC**: `2026-11-15T14:20:36.527945+00:00`
- **Execution Duration**: `0.230384s`
- **Exit Code**: `0`

---

### Execution Results

- **Stop Policy Enforced**: `--stop-on-failure` and `--stop-on-error` active; single invocation; zero autofix, zero retries.
- **Total Tests Expected**: 44
- **Tests Executed**: 44
- **Assertions Completed**: 320
- **Tests Passed**: 44
- **Failures**: 0
- **Errors**: 0
- **Skipped**: 0
- **PHPUnit Notices**: 15 (emitted by PHPUnit error handler during test execution for runtime socket probe operations against closed loopback ports during exact cleanup tests; distinct from supervisor stdout/stderr runtime console output)
- **Suite Outcome**: ALL 44 TESTS PASSED (100%)
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
  - `test_actual_drill_ordering_tls_failure_triggers_cleanup_with_early_captured_snapshot`: PASSED (15 assertions)
  - `test_prelive_failure_cleanup_with_captured_snapshot`: PASSED (11 assertions)
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
  - `test_scoped_cleanup_and_three_store_env_restoration_lifecycle`: PASSED (66 assertions)
  - `test_scoped_cleanup_restores_all_stores_even_when_cleanup_throws`: PASSED (32 assertions)
  - `test_exact_cleanup_removes_separate_owned_proof_parent_directory`: PASSED (14 assertions)
  - `test_exact_cleanup_handles_gracefully_already_removed_proof_dir_post_kill`: PASSED (7 assertions)
  - `test_exact_cleanup_refuses_unknown_or_insecure_proof_parent_directory`: PASSED (9 assertions)
  - `test_post_cleanup_final_witness_records_actual_partial_failure_nonzero`: PASSED (20 assertions)
  - `test_exact_cleanup_refuses_tampered_proof_file_content_preserving_evidence`: PASSED (11 assertions)
  - `test_exact_cleanup_refuses_regenerated_proof_parent_directory_with_inode_mismatch`: PASSED (11 assertions)
  - `test_persist_post_cleanup_final_witness_throws_on_output_path_collision`: PASSED (2 assertions)

---

### POST-Execution Invariance & Process Absence Attestation

- **POST Commit HEAD**: `2b3335a218e77b012aa90737d80aba2edb12e440` (Identical to PRE)
- **POST Working Tree**: Clean (`0` uncommitted changes prior to docs publication)
- **Source Hashes (POST)**: All tracked repository files verified byte-for-byte identical to PRE state.
- **Process Absence Check**: Verified 0 active PHPUnit, artisan, or harness child processes.
- **Contemporaneous Post-State Record**:
  - Saved to `/home/t3dev/.t3/userdata/attachments/pure_focused_qa_phase11_4945a81b/post.json` (SHA256: `d615a9e5ce79f9ffbe6831f1eb414252690f983fd31b7dcd64b5364809acafc7`, 298,916 bytes, mode `0600`).

---

### Retained Contemporaneous Machine Artifact Index

The execution environment automatically persisted contemporaneous raw evidence in private directory `/home/t3dev/.t3/userdata/attachments/pure_focused_qa_phase11_4945a81b/` (mode `0700`, files `0600`):

| File Name | SHA-256 Digest | Size (Bytes) | Mode | Purpose |
|---|---|---|---|---|
| `pre.json` | `9ecaecd5b56b4c11de5c51912cca8a2f9f1f1baccd2f994bdcd19988e922af5f` | 298,916 | `0600` | Contemporaneous pre-execution HEAD, status, and full source hash map |
| `post.json` | `d615a9e5ce79f9ffbe6831f1eb414252690f983fd31b7dcd64b5364809acafc7` | 298,916 | `0600` | Contemporaneous post-execution HEAD, status, and source invariance verification |
| `junit.xml` | `c703b9d3c849d81b463d0ab24e407f441084d3d4eae2869a69eb69bff9fe7e15` | 17,842 | `0600` | Complete JUnit XML report with all 44 test cases and timings |
| `raw_stdout_stderr.txt` | `783ebb43eeb59138d6cc18b3b699ee2fb68b48e80e7819b912bcae29a0a773b8` | 1,024 | `0600` | Raw combined standard output and error stream |
| `run_metadata.json` | `eb1600f252deba7b13a99087847286c45a64b995742c92cfb4a814efdea0d1a7` | 948 | `0600` | Execution timing, exact argv, non-secret env, and exit code |
| `machine_artifact_index.json` | `3ff1b23c2805ef96e8349f7ba308d533a1e263435198947f1542f70f803cbeee` | 775 | `0600` | Byte-level manifest index of all retained run files |

---

### Published Sanitized Documents

- [`docs/integration/pure-guard-phase11-execution-report.md`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/pure-guard-phase11-execution-report.md)
- [`docs/integration/pure-guard-phase11-junit.xml`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/pure-guard-phase11-junit.xml)
- [`docs/integration/pure-guard-phase11-run-output.txt`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/pure-guard-phase11-run-output.txt)
- [`docs/integration/pure-guard-phase11-machine-manifest.json`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/pure-guard-phase11-machine-manifest.json)

---

### Exclusive QA Lease Release

The short exclusive pure QA lease is immediately released. All execution artifacts remain securely retained.
