# Pure QA Guard Suite Execution Report (Phase 5)

- **Date / Time**: 2026-11-15T11:53:00+02:00
- **Execution Target Commit**: `4a7c1efee6e1cfb8fcc422a1f3e87756f666494d`
- **Branch**: `t3/recover-cash-writer`
- **Runner**: PHPUnit 12.5.36 (PHP 8.4.27)
- **Lease Scope**: Short exclusive pure QA lease (guard suite) on exact `4a7c1ef` commit only; Android idle/released.
- **Grant Constraints**: Zero DB, zero listeners, zero TLS, zero JVM, unconnectable DB port 1 (`127.0.0.1:1`), stop on first failure / first error, zero autofix, zero retry.
- **Prior Run Artifacts**: Phase 1, Phase 2, Phase 3, and Phase 4 failure logs and artifacts preserved unmodified.

---

### PRE-Execution Integrity Verification

- Commit HEAD: `4a7c1efee6e1cfb8fcc422a1f3e87756f666494d`
- Working Tree: Clean (`0` uncommitted changes)
- Source Hashes (PRE):
  - `tests/Support/Harness/supervised-financial-drill.php`: `e25fba43e3e69dcd3a06b107b6b1ba17a88779a81cf6e0a45312bab796e1370b`
  - `tests/Support/Harness/SupervisedPairedFinancialDrill.php`: `3be65a23bc30793e12851817265610e49aef583fef634c095406cdbc7b65ae2d`
  - `tests/Support/Harness/DisposableFinancialPostRunInspector.php`: `c8caaf80ffe51adccc90e19e48fa5064c659577b6ef61e3834d218a59595b638`
  - `tests/Support/Harness/financial-inspector-cli.php`: `46d4e38151d8d06f7e79aa13b3c654825b486f5b60a583420eecbcb673d42811`
  - `tests/Support/Harness/mobile-v2-harness-cli.php`: `70bcf0edc24cbba3d896ad7d742085bfed616b370dbb0919a1ace55f1a2a5075`
  - `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`: `b28d0f118e54f3fab0c58800383e7c446d30cf2a025a298b9d39c3c087bdf618`
  - `tests/Unit/Mobile/Harness/SupervisedFinancialDrillTest.php`: `2a3dd1fdda9f89fcfe7010d3f9c52e665a61dcfbb03d072f55b257036405e565`
  - `tests/Support/DisposableMariaDbSchema.php`: `ac69d1e6f0875eff250ce1fd688f47fbb95162dcd081f33f889ebf1fc184c352`
  - `tests/Support/Harness/fixtures/golden-provenance-manifest.json`: `2135861348655859da9ef4f232cbb1b3ba87debce0b9806340df62bce0476f08`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wire.json`: `25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wrapper.json`: `7458f05b57fe149beec9e26ebec77015b45f2acfcc428687f10c216f80f1592d`
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

---

### Raw Execution Command & Environment

```bash
DB_HOST=127.0.0.1 DB_PORT=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=1 PHASE8_ALLOW_DISPOSABLE_DB=0 \
timeout 60 ./vendor/bin/phpunit \
  --bootstrap vendor/autoload.php \
  --no-configuration \
  --stop-on-failure \
  --stop-on-error \
  --log-junit docs/integration/pure-guard-phase5-junit.xml \
  tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php \
  tests/Unit/Mobile/Harness/SupervisedFinancialDrillTest.php
```

- **Execution Start**: `2026-11-15T09:53:00Z`
- **Execution End**: `2026-11-15T09:53:00Z`
- **Execution Duration**: `0.134s`
- **Exit Code**: `2`

---

### Execution Results

- **Stop Policy Enforced**: `--stop-on-failure` and `--stop-on-error` enabled; stopped immediately on first error; zero autofix, zero retries.
- **Tests Executed**: 47
- **Assertions Completed**: 165
- **Tests Passed**: 46
- **Failures**: 0
- **Errors**: 1 (Test #47)
- **Unreached Test Inventory Preserved**: 75 tests
  - Remaining in `DisposableMobileV2BackendHarnessGuardTest.php`: 45 tests
  - All tests in `SupervisedFinancialDrillTest.php`: 30 tests

#### Failure / Error Detail:
- **Test Class**: `Tests\Unit\Mobile\Harness\DisposableMobileV2BackendHarnessGuardTest`
- **Test Method**: `test_validate_client_handoff_admits_g1_tree_and_rejects_unadmitted_tree`
- **Location**: `tests/Support/Harness/DisposableMobileV2BackendHarness.php:1363` (invoked via `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php:1612`)
- **Error Exception**: `RuntimeException`
- **Error Message**:
  ```text
  RuntimeException: Malformed client handoff artifact: raw_wire_payload_json must be strictly serialized in authoritative RFC 8785 JCS canonical JSON.
  ```

---

### POST-Execution Integrity & Release

- Working Tree State: Clean (DOCS ONLY publication, zero source edits).
- Source Hashes (POST - 100% Identical to PRE):
  - `tests/Support/Harness/supervised-financial-drill.php`: `e25fba43e3e69dcd3a06b107b6b1ba17a88779a81cf6e0a45312bab796e1370b`
  - `tests/Support/Harness/SupervisedPairedFinancialDrill.php`: `3be65a23bc30793e12851817265610e49aef583fef634c095406cdbc7b65ae2d`
  - `tests/Support/Harness/DisposableFinancialPostRunInspector.php`: `c8caaf80ffe51adccc90e19e48fa5064c659577b6ef61e3834d218a59595b638`
  - `tests/Support/Harness/financial-inspector-cli.php`: `46d4e38151d8d06f7e79aa13b3c654825b486f5b60a583420eecbcb673d42811`
  - `tests/Support/Harness/mobile-v2-harness-cli.php`: `70bcf0edc24cbba3d896ad7d742085bfed616b370dbb0919a1ace55f1a2a5075`
  - `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`: `b28d0f118e54f3fab0c58800383e7c446d30cf2a025a298b9d39c3c087bdf618`
  - `tests/Unit/Mobile/Harness/SupervisedFinancialDrillTest.php`: `2a3dd1fdda9f89fcfe7010d3f9c52e665a61dcfbb03d072f55b257036405e565`
  - `tests/Support/DisposableMariaDbSchema.php`: `ac69d1e6f0875eff250ce1fd688f47fbb95162dcd081f33f889ebf1fc184c352`
  - `tests/Support/Harness/fixtures/golden-provenance-manifest.json`: `2135861348655859da9ef4f232cbb1b3ba87debce0b9806340df62bce0476f08`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wire.json`: `25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wrapper.json`: `7458f05b57fe149beec9e26ebec77015b45f2acfcc428687f10c216f80f1592d`

### Artifact Manifest (Phase 5)

| Artifact Path | SHA-256 Digest |
|---|---|
| `docs/integration/pure-guard-phase5-junit.xml` | `78ad32b5b60adcfff1ba8b5dc621c06992193e62944f63ef01b1db478a977e58` |
| `docs/integration/pure-guard-phase5-run-output.txt` | `4a1b8718cad17ad79453c3e9a56d69bb5caf06249871180f45af233be916b696` |

---

### QA Lease Status & Job Absence

- **Exclusive QA Lease**: **EXPLICITLY RELEASED IMMEDIATELY**.
- **Job Absence**: Zero background processes running (no PHPUnit, no Gradle, no MariaDB, no listening sockets).
