# Pure QA Guard Suite Execution Report (Phase 3)

- **Date / Time**: 2026-11-15T00:14:20+02:00
- **Execution Target Commit**: `379e466e0b0a8eab335f7a655af6dfd25f97f02e`
- **Branch**: `t3/recover-cash-writer`
- **Runner**: PHPUnit 12.5.36 (PHP 8.4.27)
- **Lease Scope**: Short exclusive pure QA lease (guard suite) on exact `379e466` commit only; Android idle/released.
- **Grant Constraints**: Zero DB, zero listeners, zero TLS, zero JVM, unconnectable DB port 1 (`127.0.0.1:1`), stop on first failure, zero autofix, zero retry.
- **Prior Run Artifacts**: Phase 1 and Phase 2 failure logs and artifacts preserved unmodified.

---

### PRE-Execution Integrity Verification

- Commit HEAD: `379e466e0b0a8eab335f7a655af6dfd25f97f02e`
- Working Tree: Clean (`0` uncommitted changes)
- Source Hashes (PRE):
  - `tests/Support/Harness/DisposableFinancialPostRunInspector.php`: `4f419bef5aed7352230b1d54934a40f6cabebb912eb8ed771604473869954550`
  - `tests/Support/Harness/financial-inspector-cli.php`: `6d19eff2c1ef978ad045465e67a8e7b05cf0bc76994332df53aeb305ea40c38b`
  - `tests/Support/Harness/DisposableMobileV2BackendHarness.php`: `271eb8c30e1d86a636fb8eaab46b7426fbcf84f8d009f8ad7b18d5d6bf43f12c`
  - `tests/Support/Harness/mobile-v2-harness-cli.php`: `70bcf0edc24cbba3d896ad7d742085bfed616b370dbb0919a1ace55f1a2a5075`
  - `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`: `d970dab29661c01b89700e652d7028734dc3e615d187fb789851bb21ba467b37`
  - `docs/integration/mobile-v2-client-handoff-schema.json`: `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275`
  - `docs/integration/mobile-v2-integration-manifest.json`: `a8d0d9cc106798b305155f65bd0c208fde0e3eb40883986a6d1081ab3cfcfbe2`
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

---

### Raw Execution Command & Environment

```bash
DB_HOST=127.0.0.1 DB_PORT=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=1 \
./vendor/bin/phpunit --bootstrap vendor/autoload.php --stop-on-failure \
--log-junit /tmp/pure-guard-run-phase3/pure-guard-phase3-junit.xml \
tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php
```

- **Exit Code**: `1`

---

### Execution Results

- **Stop Policy Enforced**: Stopped on first failure (`--stop-on-failure`); zero autofix, zero retries, zero additional tests executed.
- **Tests Executed**: 76
- **Assertions Completed**: 363
- **Tests Passed**: 75
- **Failures**: 1 (stopped at Test #76)
- **Later Tests Not Claimed**: Tests #77 through #92 were not reached due to stop-on-failure and are NOT claimed as executed.

#### Major Milestones Verified Passed:
- **Phase 1 Precedence Failures**: PASSED (Tests #66 and #67).
- **Phase 2 Validator Digest Failure**: PASSED (Test #68 passed with 4 assertions).
- **Owner Publication SHA Validation**: PASSED (Test #69 passed with 4 assertions).
- **Pin Overclaim & Mismatch Gates**: PASSED (Test #70 passed with 12 assertions).
- **CLI No-Execute Guard**: PASSED (Test #71 passed with 3 assertions).
- **Cross-Repo Kotlin Golden Parity & Mutation Gates**: PASSED (Test #72 passed with 11 assertions).
- **Report Digest Determinism**: PASSED (Test #73 passed with 12 assertions).
- **Bootstrap Credentials Security Loader**: PASSED (Test #74 passed with 11 assertions).
- **Report Digest Recomputation & Run ID Gate**: PASSED (Test #75 passed with 7 assertions).

#### Failure Detail (Test #76):
- **Test Class**: `Tests\Unit\Mobile\Harness\DisposableMobileV2BackendHarnessGuardTest`
- **Test Method**: `test_outer_validator_rejects_missing_or_invalid_or_mismatched_expected_event_id`
- **Location**: `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php:3082`
- **Failure Message**:
  ```text
  Failed asserting that false is true.
  ```

#### Root Cause Analysis:
In `test_outer_validator_rejects_missing_or_invalid_or_mismatched_expected_event_id`:
- Cases 1 through 3 (missing `expectedEventId`, malformed `expectedEventId` format, and mismatched `expectedEventId` vs report `event_id`) all passed validation assertions (10 assertions passed).
- In Case 4 (lines 3073–3082), `$ambiguousPins['expectedRunId'] = $ambiguousPins['expectedEventId']` was set to test ambiguous identity rejection.
- `$resAmbiguous['is_valid']` evaluated to `false` as expected (`$this->assertFalse($resAmbiguous['is_valid'])` passed).
- However, on line 3082:
  `$this->assertTrue(collect($resAmbiguous['errors'])->contains(fn ($e) => str_contains($e, 'Ambiguous identity')));`
  The error string emitted was `'Trusted DB report run_id mismatch: expected ...'` because `$fixture['report']['run_id']` differed from the mutated `$ambiguousPins['expectedRunId']`, rather than the specific string `'Ambiguous identity'`.

---

### POST-Execution Source Integrity

- All 10 source file hashes re-computed immediately following run and are **100% byte-for-byte identical** to PRE hashes. Zero source modifications during run.
- Working tree non-doc diff against commit `379e466e0b0a8eab335f7a655af6dfd25f97f02e`: **0 bytes**.

---

### Phase-Specific Execution Artifacts

- **Phase 3 JUnit XML**: `docs/integration/pure-guard-phase3-junit.xml` (SHA-256: `0b187e47a1f680a065a27e0c90fe3ffb6fb2a172a863396957918ac13f016956`)
- **Phase 3 Raw Run Output**: `docs/integration/pure-guard-phase3-run-output.txt` (SHA-256: `4479c5e33f9e522aa1e9255058545884de68f864eb699bcf69e832add121109c`)

---

### Lease Release & Process State

- **QA Lease**: Explicitly RELEASED immediately upon run conclusion.
- **Background Processes**: Zero lingering background processes, jobs, listening sockets, or DB connections.
- **Scope Disclaimer**: This execution grants pure unit guard suite evaluation only; it does not grant or imply database qualification or financial execution admission.
