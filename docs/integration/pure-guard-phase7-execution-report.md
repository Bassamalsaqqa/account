# Pure QA Guard Suite Execution Report (Phase 7)

- **Date / Time**: 2026-11-15T11:56:40Z
- **Execution Target Commit**: `490bf19244e2c1397ab424819b3881996fc0a874`
- **Branch**: `t3/recover-cash-writer`
- **Runner**: PHPUnit 12.5.36 (PHP 8.4.27)
- **Lease Scope**: Exclusive short pure QA lease on exact `490bf19` runtime; 97 guard + 31 supervisor expected 128 tests.
- **Grant Constraints**: Zero DB, zero listeners, zero TLS, zero JVM, unconnectable DB port 1 (`127.0.0.1:1`), stop on first failure / first error, zero autofix, zero retry.
- **Prior Run Artifacts**: Phase 1 through Phase 6 logs and artifacts preserved unmodified.

---

### PRE-Execution Integrity Verification

- Commit HEAD: `490bf19244e2c1397ab424819b3881996fc0a874`
- Working Tree: Clean (`0` uncommitted changes)
- Source Hashes (PRE):
  - `tests/Support/Harness/supervised-financial-drill.php`: `9b82f3df912b63c7dab8f32cd837c5ca4518051689bf66244161de555024361c`
  - `tests/Support/Harness/SupervisedPairedFinancialDrill.php`: `1d797fcd5c44b581b2704ffac3ca931bcdbdfd52074d5eefe70d5feb6494dc72`
  - `tests/Support/Harness/DisposableFinancialPostRunInspector.php`: `c8caaf80ffe51adccc90e19e48fa5064c659577b6ef61e3834d218a59595b638`
  - `tests/Support/Harness/financial-inspector-cli.php`: `46d4e38151d8d06f7e79aa13b3c654825b486f5b60a583420eecbcb673d42811`
  - `tests/Support/Harness/mobile-v2-harness-cli.php`: `70bcf0edc24cbba3d896ad7d742085bfed616b370dbb0919a1ace55f1a2a5075`
  - `tests/Support/Harness/DisposableMobileV2BackendHarness.php`: `7fcf73a3c55940aa484464a56078506cb9faa15b22656750d88923dc3d90b3cc`
  - `tests/Support/Harness/MobileV2HarnessCommand.php`: `0b6914ffc299cd399db6ab2311764b4bd18212238fbaeb46d32518da356a4aa7`
  - `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`: `a55c5f7b1cc698ec0ca6f64646192a49e290466751ca0eccccb2a5b12090ffda`
  - `tests/Unit/Mobile/Harness/SupervisedFinancialDrillTest.php`: `da054156306206bf934f4c28d5d5e41fdc54eba774a423a26a69d03535f56326`
  - `tests/Support/DisposableMariaDbSchema.php`: `ac69d1e6f0875eff250ce1fd688f47fbb95162dcd081f33f889ebf1fc184c352`
  - `tests/Support/Harness/fixtures/golden-provenance-manifest.json`: `2135861348655859da9ef4f232cbb1b3ba87debce0b9806340df62bce0476f08`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wire.json`: `25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wrapper.json`: `7458f05b57fe149beec9e26ebec77015b45f2acfcc428687f10c216f80f1592d`
  - `docs/integration/mobile-v2-integration-manifest.json`: `d386b0bf7bcd31ec693b8cc5ee99d13eb84fac03f1f2543ef0c77cf492e7fcc1`
  - `docs/integration/mobile-v2-client-handoff-schema.json`: `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275`
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
  - `docs/integration/pure-guard-phase4-execution-report.md`: `49cea0d5accbfd106997f069dca333730e0850d3a98a58fbbb07b8bf4519c633`
  - `docs/integration/pure-guard-phase4-junit.xml`: `621b1e4beb98827affbca054f792bee5a6acf7f70e3ef7ffca62a0007d400f1a`
  - `docs/integration/pure-guard-phase4-run-output.txt`: `4de0ed41547aa8c00e5fd101ae314b12028513c836c7265f28cd4a76e86d9486`
  - `docs/integration/pure-guard-phase5-execution-report.md`: `6a9a48b76982cbe825e81ed5ff4f77126410c2582b596e9e6a6ab2e4c1863c00`
  - `docs/integration/pure-guard-phase5-junit.xml`: `78ad32b5b60adcfff1ba8b5dc621c06992193e62944f63ef01b1db478a977e58`
  - `docs/integration/pure-guard-phase5-run-output.txt`: `4a1b8718cad17ad79453c3e9a56d69bb5caf06249871180f45af233be916b696`
  - `docs/integration/pure-guard-phase6-execution-report.md`: `c7278f651503002f2ffc89f14cf1b38e492d92a0841dabc5a60f8560c329aa75`
  - `docs/integration/pure-guard-phase6-junit.xml`: `4b055a4e12c89761a096392541ddaeea8cd746dab59387657f6df77adc511260`
  - `docs/integration/pure-guard-phase6-run-output.txt`: `d152beedfbf613c122447b65627689f3e1e4ea6e131ee466fbbfed6868f7876c`

---

### Raw Execution Command & Environment

```bash
DB_HOST=127.0.0.1 DB_PORT=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=1 PHASE8_ALLOW_DISPOSABLE_DB=0 MOBILE_V2_HARNESS_ENABLE=0 \
timeout 60 ./vendor/bin/phpunit \
  --configuration /tmp/pure_guard_qa_run_phase7_lixHFfDG/phpunit_pure_guard.xml \
  --bootstrap vendor/autoload.php \
  --stop-on-failure \
  --stop-on-error \
  --log-junit docs/integration/pure-guard-phase7-junit.xml
```

- **Execution Start**: `2026-11-15T11:56:37Z`
- **Execution End**: `2026-11-15T11:56:40Z`
- **Execution Duration**: `3.126s`
- **Exit Code**: `1`

---

### Execution Results

- **Stop Policy Enforced**: `--stop-on-failure` and `--stop-on-error` enabled; stopped immediately on first failure; zero autofix, zero retries.
- **Total Tests Expected**: 128
- **Tests Executed**: 107
- **Assertions Completed**: 499
- **Tests Passed**: 106
- **Failures**: 1
- **Errors**: 0
- **Suite Outcome**: STOPPED WITH EXIT CODE 1
- **Suite Breakdown**:
  - `DisposableMobileV2BackendHarnessGuardTest.php`: 97 executed, 97 passed, 0 failures, 0 errors, 477 assertions.
  - `SupervisedFinancialDrillTest.php`: 10 executed, 9 passed, 1 failure, 0 errors, 22 assertions (stopped on 10th test: `test_verify_evidence_freshness_accepts_golden_25ae_fixture`).
- **Unreached Test Inventory Preserved**: 21 tests
  - Remaining in `SupervisedFinancialDrillTest.php`: 21 tests (tests 11 through 31)

#### Failure Detail:
- **Test Class**: `Tests\Unit\Mobile\Harness\SupervisedFinancialDrillTest`
- **Test Method**: `test_verify_evidence_freshness_accepts_golden_25ae_fixture`
- **Location**: `tests/Unit/Mobile/Harness/SupervisedFinancialDrillTest.php:321`
- **Assertion**:
  ```text
  Failed asserting that file "/home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/tests/Unit/Support/Harness/fixtures/synthetic-financial-golden-wire.json" exists.
  ```

---

### Published Phase 7 Artifacts

- `docs/integration/pure-guard-phase7-run-output.txt`: `2b60a694db3ab2eb849197639920001e199f685a4b17c524269035d476df35c7`
- `docs/integration/pure-guard-phase7-junit.xml`: `285699651af2eedc8612590aa456fc4eebbbde18f6b31357e4bb3cf8aa0effb7`

---

### POST-Execution Integrity & Release

- Working Tree State: Clean (DOCS ONLY publication, zero source edits).
- Source Hashes (POST - 100% Identical to PRE):
  - `tests/Support/Harness/supervised-financial-drill.php`: `9b82f3df912b63c7dab8f32cd837c5ca4518051689bf66244161de555024361c`
  - `tests/Support/Harness/SupervisedPairedFinancialDrill.php`: `1d797fcd5c44b581b2704ffac3ca931bcdbdfd52074d5eefe70d5feb6494dc72`
  - `tests/Support/Harness/DisposableFinancialPostRunInspector.php`: `c8caaf80ffe51adccc90e19e48fa5064c659577b6ef61e3834d218a59595b638`
  - `tests/Support/Harness/financial-inspector-cli.php`: `46d4e38151d8d06f7e79aa13b3c654825b486f5b60a583420eecbcb673d42811`
  - `tests/Support/Harness/mobile-v2-harness-cli.php`: `70bcf0edc24cbba3d896ad7d742085bfed616b370dbb0919a1ace55f1a2a5075`
  - `tests/Support/Harness/DisposableMobileV2BackendHarness.php`: `7fcf73a3c55940aa484464a56078506cb9faa15b22656750d88923dc3d90b3cc`
  - `tests/Support/Harness/MobileV2HarnessCommand.php`: `0b6914ffc299cd399db6ab2311764b4bd18212238fbaeb46d32518da356a4aa7`
  - `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`: `a55c5f7b1cc698ec0ca6f64646192a49e290466751ca0eccccb2a5b12090ffda`
  - `tests/Unit/Mobile/Harness/SupervisedFinancialDrillTest.php`: `da054156306206bf934f4c28d5d5e41fdc54eba774a423a26a69d03535f56326`
  - `tests/Support/DisposableMariaDbSchema.php`: `ac69d1e6f0875eff250ce1fd688f47fbb95162dcd081f33f889ebf1fc184c352`
  - `tests/Support/Harness/fixtures/golden-provenance-manifest.json`: `2135861348655859da9ef4f232cbb1b3ba87debce0b9806340df62bce0476f08`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wire.json`: `25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wrapper.json`: `7458f05b57fe149beec9e26ebec77015b45f2acfcc428687f10c216f80f1592d`
  - `docs/integration/mobile-v2-integration-manifest.json`: `d386b0bf7bcd31ec693b8cc5ee99d13eb84fac03f1f2543ef0c77cf492e7fcc1`
  - `docs/integration/mobile-v2-client-handoff-schema.json`: `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275`
- QA Lease Status: RELEASED immediately upon command completion.
- Running Processes: Zero PHPUnit / Gradle / supervisor / background jobs.
