# Pure QA Guard Suite Execution Report (Phase 2)

- **Date / Time**: 2026-11-14T23:07:53+02:00
- **Execution Target Commit**: `9a4c9205d935eb9258ec8073235382e20c67d6f4`
- **Branch**: `t3/recover-cash-writer`
- **Runner**: PHPUnit 12.5.36 (PHP 8.4.27)
- **Lease Scope**: Short exclusive pure QA lease (guard suite) on exact `9a4c920` commit.
- **Grant Constraints**: Zero DB, zero listeners, zero TLS, zero JVM, unconnectable DB port 1 (`127.0.0.1:1`), stop on first failure, zero autofix, zero retry.
- **Prior Run Artifacts**: Unmodified and preserved.

---

### PRE-Execution Integrity Verification

- Commit HEAD: `9a4c9205d935eb9258ec8073235382e20c67d6f4`
- Working Tree: Clean (`0` uncommitted changes)
- Source Hashes:
  - `tests/Support/Harness/DisposableFinancialPostRunInspector.php`: `c139ef833b0b1d80f7ccbb05c6295c6605c65367b65c3b02d78ccaecfdd59fb0`
  - `tests/Support/Harness/financial-inspector-cli.php`: `cf60f426f64926bf9d37347f33952f214f8eff5a9f47b84a39752280691ebe92`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wire.json`: `25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wrapper.json`: `7458f05b57fe149beec9e26ebec77015b45f2acfcc428687f10c216f80f1592d`
  - `tests/Support/Harness/fixtures/golden-provenance-manifest.json`: `2135861348655859da9ef4f232cbb1b3ba87debce0b9806340df62bce0476f08`
  - `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`: `d04bd0985c02bf6c733fdc835553d6118626d52242490e1ec2f63fe021b8a400`
  - `docs/integration/mobile-v2-client-handoff-schema.json`: `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275`
  - `docs/integration/mobile-v2-integration-manifest.json`: `e05570d8c0a4665c88c1423e8997c2037bedd8d013082b547cca0e007fc10a16`
- Prior Run 1 Artifact Hashes (Preserved & Unmodified):
  - `docs/integration/pure-guard-execution-report.md`: `5dffb77e6c6398807dafeddff5475088ffcf9caa6d80032e41989626be9ba233`
  - `docs/integration/pure-guard-junit.xml`: `f9062e08bbbfd9072fcf03be9a617adcb9e7643ad6dd54268375da40490536c5`
  - `docs/integration/pure-guard-run-output.txt`: `04544334317a52abbd01e98ccab4ea2409902e4dbd692ed13964ce94b4379f76`

---

### Raw Execution Command & Flags

```bash
DB_HOST=127.0.0.1 DB_PORT=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=1 \
./vendor/bin/phpunit --stop-on-failure --log-junit /tmp/pure-guard-run-phase2/pure-guard-phase2-junit.xml tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php
```

---

### Execution Results

- **Stop Policy Enforced**: Stopped on first failure (`--stop-on-failure`); zero autofix, zero retries, zero additional tests executed.
- **Tests Executed**: 68
- **Assertions Completed**: 290
- **Tests Passed**: 67 (tests #66 and #67 passed successfully)
- **Failures**: 1 (stopped at Test #68)
- **Later Tests Not Claimed**: Cross-repo Kotlin golden parity tests (tests 71+) were not reached due to stop-on-failure and are NOT claimed as executed.

#### Passed Precedence Tests:
- **Test #66** (`test_financial_post_run_inspector_refuses_malformed_or_unproven_disposable_schema`): PASSED. Reached ownership proof branch with dummy local credentials; verified fail-closed before PDO connection; restored environment.
- **Test #67** (`test_financial_post_run_inspector_fails_closed_when_test_admin_credentials_missing`): PASSED. Verified fail-closed when admin credentials are unset; restored environment.

#### Failure Detail (Test #68):
- **Test Class**: `Tests\Unit\Mobile\Harness\DisposableMobileV2BackendHarnessGuardTest`
- **Test Method**: `test_financial_outer_validator_accepts_valid_golden_android_evidence_and_trusted_db_report`
- **Location**: `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php:2499`
- **Failure Message**:
  ```text
  Failed asserting that false is true.
  ```

#### Root Cause Analysis:
In `test_financial_outer_validator_accepts_valid_golden_android_evidence_and_trusted_db_report`:
- The mock `$trustedDbReport` fixture does not include a `report_digest` field.
- `DisposableFinancialPostRunInspector::validateOuterEvidenceJson` mandates a non-empty `report_digest` in `$trustedDbReport` and recalculates/validates the self-digest via `hash_equals()`.
- Missing `report_digest` causes `$validation['is_valid']` to evaluate to `false` with error `'Trusted DB report missing mandatory report_digest'`, causing `$this->assertTrue($result['is_valid'])` to fail on line 2499.

---

### POST-Execution Source Integrity

- All 8 source file hashes re-computed immediately following run and are **100% byte-for-byte identical** to PRE hashes. Zero source modifications during run.
- Working tree non-doc diff: **0 bytes**.

---

### Phase-Specific Execution Artifacts

- **Phase 2 JUnit XML**: `docs/integration/pure-guard-phase2-junit.xml` (SHA-256: `e0d72a4956c62d2d9393becd74da81b0c2747eda9269b1d2d3e5c5bdd234f813`)
- **Phase 2 Raw Run Output**: `docs/integration/pure-guard-phase2-run-output.txt` (SHA-256: `233ee0232a4d2658dd07867579110f185e3c65577d7fe26e15db4df7028a6475`)
