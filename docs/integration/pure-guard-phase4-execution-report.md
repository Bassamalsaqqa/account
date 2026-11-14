# Pure QA Guard Suite Execution Report (Phase 4 - Full Green)

- **Date / Time**: 2026-11-15T00:23:23+02:00
- **Execution Target Commit**: `8f83b921f958fc7274a3b7b153fdedb97fd5c807`
- **Branch**: `t3/recover-cash-writer`
- **Runner**: PHPUnit 12.5.36 (PHP 8.4.27)
- **Lease Scope**: Short exclusive pure QA lease (guard suite) on exact `8f83b921` commit only; Android idle/released.
- **Grant Constraints**: Zero DB, zero listeners, zero TLS, zero JVM, unconnectable DB port 1 (`127.0.0.1:1`), stop on first failure, zero autofix, zero retry.
- **Prior Run Artifacts**: Phase 1, Phase 2, and Phase 3 failure logs and artifacts preserved unmodified.

---

### PRE-Execution Integrity Verification

- Commit HEAD: `8f83b921f958fc7274a3b7b153fdedb97fd5c807`
- Working Tree: Clean (`0` uncommitted changes)
- Source Hashes (PRE):
  - `tests/Support/Harness/DisposableFinancialPostRunInspector.php`: `4f419bef5aed7352230b1d54934a40f6cabebb912eb8ed771604473869954550`
  - `tests/Support/Harness/financial-inspector-cli.php`: `6d19eff2c1ef978ad045465e67a8e7b05cf0bc76994332df53aeb305ea40c38b`
  - `tests/Support/Harness/DisposableMobileV2BackendHarness.php`: `271eb8c30e1d86a636fb8eaab46b7426fbcf84f8d009f8ad7b18d5d6bf43f12c`
  - `tests/Support/Harness/mobile-v2-harness-cli.php`: `70bcf0edc24cbba3d896ad7d742085bfed616b370dbb0919a1ace55f1a2a5075`
  - `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`: `282dea6ab828a76bac7ecfd58d8bb2b93e6f9e716d6b16afca04af7898c26078`
  - `docs/integration/mobile-v2-client-handoff-schema.json`: `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275`
  - `docs/integration/mobile-v2-integration-manifest.json`: `c53ccd90bca5dbe34b7ab6191f332a8e8b84de0869e8b060623d980e2759f78a`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wire.json`: `25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wrapper.json`: `7458f05b57fe149beec9e26ebec77015b45f2acfcc428687f10c216f80f1592d`
  - `tests/Support/Harness/fixtures/golden-provenance-manifest.json`: `2135861348655859da9ef4f232cbb1b3ba87debce0b9806340df62bce0476f08`
- Prior Run Artifact Hashes (Preserved & Unmodified):
  - `docs/integration/pure-guard-execution-report.md`: `5dffb77e6c6398807dafeddff5475088ffcf9caa6d80032e41989626be9ba233`
  - `docs/integration/pure-guard-junit.xml`: `f9062e08bbbfd9072fcf03be9a617adcb9e7643ad6dd54268375da40490536c5`
  - `docs/integration/pure-guard-run-output.txt`: `04544334317a52abbd01e98ccab4ea2409902e4dbd692ed13964ce94b4379f76`
  - `docs/integration/pure-guard-phase2-execution-report.md`: `d1a6fd35781773135c71ac4d714c3b55fabd470e83299829d79e11d61157f362`
  - `docs/integration/pure-guard-phase2-junit.xml`: `e0d72a4956c62d2d9393becd74da81b0c2747eda9269b1d2d3e5c5bdd234f813`
  - `docs/integration/pure-guard-phase2-run-output.txt`: `233ee0232a4d2658dd07867579110f185e3c65577d7fe26e15db4df7028a6475`
  - `docs/integration/pure-guard-phase3-execution-report.md`: `f92d861fb53632dd55bf83cf89908ac35f54eb29beddc102f87c42dc4eb2f430`
  - `docs/integration/pure-guard-phase3-junit.xml`: `0b187e47a1f680a065a27e0c90fe3ffb6fb2a172a863396957918ac13f016956`
  - `docs/integration/pure-guard-phase3-run-output.txt`: `4479c5e33f9e522aa1e9255058545884de68f864eb699bcf69e832add121109c`

---

### Raw Execution Command & Environment

```bash
DB_HOST=127.0.0.1 DB_PORT=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=1 \
./vendor/bin/phpunit --bootstrap vendor/autoload.php --stop-on-failure \
--log-junit /tmp/pure-guard-run-phase4/pure-guard-phase4-junit.xml \
tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php
```

- **Exit Code**: `0`

---

### Execution Results

- **Stop Policy Enforced**: `--stop-on-failure` enabled; ran to full completion.
- **Tests Executed**: 92
- **Assertions Completed**: 458
- **Tests Passed**: 92 (100% FULL GREEN)
- **Failures**: 0
- **Errors**: 0
- **Skips**: 0

#### Key Verification Highlights:
- **Test #76** (`test_outer_validator_rejects_missing_or_invalid_or_mismatched_expected_event_id`): PASSED (12 assertions). Verified external runId mismatch failure and consistent equal run/event UUID acceptance.
- **Test #77** (`test_outer_validator_rejects_empty_or_invalid_deltas`): PASSED (8 assertions).
- **Test #78** (`test_outer_validator_accepts_equal_cross_table_primary_key_ids_with_correctly_bound_graph`): PASSED (4 assertions).
- **Test #79** (`test_outer_validator_rejects_identical_public_ulids_between_invoice_and_payment`): PASSED (4 assertions).
- **Test #80** (`test_outer_validator_rejects_swapped_fk_pair_in_allocation`): PASSED (4 assertions).
- **Test #81** (`test_outer_validator_fails_closed_on_absent_allocation_stock_or_gl_entries`): PASSED (8 assertions).
- **Test #82** (`test_outer_validator_rejects_wrong_receipt_payload_digest_or_company`): PASSED (6 assertions).
- **Test #83** (`test_outer_validator_rejects_altered_selected_row_amounts`): PASSED (8 assertions).
- **Test #84** (`test_outer_validator_rejects_mutated_canonical_graph_due_to_report_digest_mismatch`): PASSED (4 assertions).
- **Test #85** (`test_outer_validator_rejects_not_run_scenario_with_non_null_or_missing_status_code`): PASSED (10 assertions).
- **Tests #86 & #87** (`test_inspect_delta_enforces_mandatory_uuid_expected_event_id` & `test_inspect_delta_enforces_mandatory_expected_payload_digest_pure_format`): PASSED (4 assertions).
- **Test #88** (`test_outer_validator_rejects_empty_or_unchanged_document_sequences`): PASSED (9 assertions).
- **Test #89** (`test_outer_validator_rejects_baseline_old_entity_ids`): PASSED (6 assertions).
- **Test #90** (`test_outer_validator_rejects_duplicate_gl_lines_or_wrong_parent_batch_fk`): PASSED (6 assertions).
- **Test #91** (`test_outer_validator_rejects_reversed_payment_or_missing_money_account`): PASSED (8 assertions).
- **Test #92** (`test_outer_validator_rejects_missing_or_null_lot_stock_movement_fk`): PASSED (4 assertions).

---

### POST-Execution Source Integrity

- All 10 source file hashes re-computed immediately following run and are **100% byte-for-byte identical** to PRE hashes. Zero source modifications during run.
- Working tree non-doc diff against commit `8f83b921f958fc7274a3b7b153fdedb97fd5c807`: **0 bytes**.

---

### Phase-Specific Execution Artifacts

- **Phase 4 JUnit XML**: `docs/integration/pure-guard-phase4-junit.xml` (SHA-256: `621b1e4beb98827affbca054f792bee5a6acf7f70e3ef7ffca62a0007d400f1a`)
- **Phase 4 Raw Run Output**: `docs/integration/pure-guard-phase4-run-output.txt` (SHA-256: `4de0ed41547aa8c00e5fd101ae314b12028513c836c7265f28cd4a76e86d9486`)

---

### Lease Release & Process State

- **QA Lease**: Explicitly RELEASED immediately upon run conclusion.
- **Background Processes**: Zero lingering background processes, jobs, listening sockets, or DB connections.
- **Scope Disclaimer**: This execution verifies pure unit guard suite parity (synthetic PHP / Kotlin parity); it does not grant or imply database qualification or financial execution admission.
