# Pure QA Guard Suite Execution Report (Phase 6)

- **Date / Time**: 2026-11-15T13:30:23+02:00
- **Execution Target Commit**: `dc20025c991414bdd8ba95f3cfd67124a9947eca`
- **Branch**: `t3/recover-cash-writer`
- **Runner**: PHPUnit 12.5.36 (PHP 8.4.27)
- **Lease Scope**: Short exclusive pure QA lease (guard suite) on exact `dc20025` commit only; Android released after 113 focused PASS.
- **Grant Constraints**: Zero DB, zero listeners, zero TLS, zero JVM, unconnectable DB port 1 (`127.0.0.1:1`), stop on first failure / first error, zero autofix, zero retry.
- **Prior Run Artifacts**: Phase 1, Phase 2, Phase 3, Phase 4, and Phase 5 logs and artifacts preserved unmodified.

---

### PRE-Execution Integrity Verification

- Commit HEAD: `dc20025c991414bdd8ba95f3cfd67124a9947eca`
- Working Tree: Clean (`0` uncommitted changes)
- Source Hashes (PRE):
  - `tests/Support/Harness/supervised-financial-drill.php`: `9b82f3df912b63c7dab8f32cd837c5ca4518051689bf66244161de555024361c`
  - `tests/Support/Harness/SupervisedPairedFinancialDrill.php`: `1d797fcd5c44b581b2704ffac3ca931bcdbdfd52074d5eefe70d5feb6494dc72`
  - `tests/Support/Harness/DisposableFinancialPostRunInspector.php`: `c8caaf80ffe51adccc90e19e48fa5064c659577b6ef61e3834d218a59595b638`
  - `tests/Support/Harness/financial-inspector-cli.php`: `46d4e38151d8d06f7e79aa13b3c654825b486f5b60a583420eecbcb673d42811`
  - `tests/Support/Harness/mobile-v2-harness-cli.php`: `70bcf0edc24cbba3d896ad7d742085bfed616b370dbb0919a1ace55f1a2a5075`
  - `tests/Support/Harness/DisposableMobileV2BackendHarness.php`: `7fcf73a3c55940aa484464a56078506cb9faa15b22656750d88923dc3d90b3cc`
  - `tests/Support/Harness/MobileV2HarnessCommand.php`: `0b6914ffc299cd399db6ab2311764b4bd18212238fbaeb46d32518da356a4aa7`
  - `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`: `30a1d571dba1b3af72ac200661224afb4a1f48d43c091aa27b696af50ffcea20`
  - `tests/Unit/Mobile/Harness/SupervisedFinancialDrillTest.php`: `a8dabd0875415f99e26c555843e19c1a1e95ca7aa9c06c52c1398cd19cde425a`
  - `tests/Support/DisposableMariaDbSchema.php`: `ac69d1e6f0875eff250ce1fd688f47fbb95162dcd081f33f889ebf1fc184c352`
  - `tests/Support/Harness/fixtures/golden-provenance-manifest.json`: `2135861348655859da9ef4f232cbb1b3ba87debce0b9806340df62bce0476f08`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wire.json`: `25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wrapper.json`: `7458f05b57fe149beec9e26ebec77015b45f2acfcc428687f10c216f80f1592d`
  - `docs/integration/mobile-v2-integration-manifest.json`: `db8cbf2be7e289b46e63aaaf2c0e110511a7f6ee846a2ce111410b5e19269783`
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

---

### Raw Execution Command & Environment

```bash
DB_HOST=127.0.0.1 DB_PORT=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=1 PHASE8_ALLOW_DISPOSABLE_DB=0 \
timeout 60 ./vendor/bin/phpunit \
  --configuration /tmp/pure_guard_qa_run_phase6/phpunit_pure_guard.xml \
  --bootstrap vendor/autoload.php \
  --stop-on-failure \
  --stop-on-error \
  --log-junit docs/integration/pure-guard-phase6-junit.xml
```

- **Execution Start**: `2026-11-15T11:30:23Z`
- **Execution End**: `2026-11-15T11:30:26Z`
- **Execution Duration**: `2.853s`
- **Exit Code**: `1`

---

### Execution Results

- **Stop Policy Enforced**: `--stop-on-failure` and `--stop-on-error` enabled; stopped immediately on first failure; zero autofix, zero retries.
- **Tests Executed**: 70
- **Assertions Completed**: 305
- **Tests Passed**: 69
- **Failures**: 1 (Test #70)
- **Errors**: 0
- **Unreached Test Inventory Preserved**: 57 tests
  - Remaining in `DisposableMobileV2BackendHarnessGuardTest.php`: 26 tests (tests 71 through 96)
  - All tests in `SupervisedFinancialDrillTest.php`: 31 tests

#### Failure Detail:
- **Test Class**: `Tests\Unit\Mobile\Harness\DisposableMobileV2BackendHarnessGuardTest`
- **Test Method**: `test_financial_post_run_inspector_refuses_malformed_or_unproven_disposable_schema`
- **Location**: `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php:2493`
- **Assertion**:
  ```text
  Failed asserting that 'DisposableMariaDbSchema refused: APP_ENV is not testing.' [ASCII](length: 56) contains "Disposable schema ownership proof is required" [ASCII](length: 45).
  ```

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
  - `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`: `30a1d571dba1b3af72ac200661224afb4a1f48d43c091aa27b696af50ffcea20`
  - `tests/Unit/Mobile/Harness/SupervisedFinancialDrillTest.php`: `a8dabd0875415f99e26c555843e19c1a1e95ca7aa9c06c52c1398cd19cde425a`
  - `tests/Support/DisposableMariaDbSchema.php`: `ac69d1e6f0875eff250ce1fd688f47fbb95162dcd081f33f889ebf1fc184c352`
  - `tests/Support/Harness/fixtures/golden-provenance-manifest.json`: `2135861348655859da9ef4f232cbb1b3ba87debce0b9806340df62bce0476f08`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wire.json`: `25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wrapper.json`: `7458f05b57fe149beec9e26ebec77015b45f2acfcc428687f10c216f80f1592d`
  - `docs/integration/mobile-v2-integration-manifest.json`: `db8cbf2be7e289b46e63aaaf2c0e110511a7f6ee846a2ce111410b5e19269783`
  - `docs/integration/mobile-v2-client-handoff-schema.json`: `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275`

### Artifact Manifest (Phase 6)

| Artifact Path | SHA-256 Digest |
|---|---|
| `docs/integration/pure-guard-phase6-junit.xml` | `4b055a4e12c89761a096392541ddaeea8cd746dab59387657f6df77adc511260` |
| `docs/integration/pure-guard-phase6-run-output.txt` | `d152beedfbf613c122447b65627689f3e1e4ea6e131ee466fbbfed6868f7876c` |

---

### QA Lease Status & Job Absence

- **Exclusive QA Lease**: **EXPLICITLY RELEASED IMMEDIATELY**.
- **Job Absence**: Zero background processes running (no PHPUnit, no Gradle, no MariaDB, no listening sockets).
