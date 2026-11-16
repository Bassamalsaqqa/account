# Pure QA Guard Suite Execution Report

- **Date / Time**: 2026-11-14T23:01:05+02:00
- **Execution Target Commit**: `3e4dea7c5441847ec7f303380eab0286ab5dda7f`
- **Branch**: `t3/recover-cash-writer`
- **Runner**: PHPUnit 12.5.36 (PHP 8.4.27)
- **Lease Scope**: Short exclusive pure QA lease (guard + golden parity suite) on exact `3e4dea7` commit.
- **Grant Constraints**: Zero DB, zero listeners, zero TLS, zero JVM, unconnectable DB port 1 (`127.0.0.1:1`).

---

### PRE-Execution Integrity Verification

All file hashes verified prior to execution:
- Commit HEAD: `3e4dea7c5441847ec7f303380eab0286ab5dda7f`
- Git Working Tree: Clean (0 uncommitted changes)
- Source Hashes:
  - `tests/Support/Harness/DisposableFinancialPostRunInspector.php`: `c139ef833b0b1d80f7ccbb05c6295c6605c65367b65c3b02d78ccaecfdd59fb0`
  - `tests/Support/Harness/financial-inspector-cli.php`: `1e6a783d492aa33d19fde960a54897b94f9eac78b2742a5257ed11e62260c158`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wire.json`: `25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6`
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wrapper.json`: `7458f05b57fe149beec9e26ebec77015b45f2acfcc428687f10c216f80f1592d`
  - `tests/Support/Harness/fixtures/golden-provenance-manifest.json`: `2135861348655859da9ef4f232cbb1b3ba87debce0b9806340df62bce0476f08`
  - `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`: `847c4063fd24c19af48330ecbc909f5ac81e2dec0057bc8527c3dc809d298fcd`
  - `docs/integration/mobile-v2-client-handoff-schema.json`: `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275`
  - `docs/integration/mobile-v2-integration-manifest.json`: `09c8deeadb2c6f2e82fb5b5a43ad93c4e79055433e88f94f283727f7be2a9121`

---

### Raw Execution Command & Flags

```bash
DB_HOST=127.0.0.1 DB_PORT=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=1 \
./vendor/bin/phpunit --stop-on-failure --log-junit /tmp/pure-guard-junit.xml tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php
```

---

### Execution Results

- **Instruction Gate Enforced**: Stopped on first failure (`--stop-on-failure`); zero autofix, zero retries, zero additional QA executed.
- **Tests Executed**: 66
- **Assertions Completed**: 288
- **Tests Passed**: 65
- **Failures**: 1 (Test #66)

#### Failure Detail:
- **Test Class**: `Tests\Unit\Mobile\Harness\DisposableMobileV2BackendHarnessGuardTest`
- **Test Method**: `test_financial_post_run_inspector_refuses_malformed_or_unproven_disposable_schema`
- **Location**: `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php:2326`
- **Failure Message**:
  ```text
  Failed asserting that 'Explicit disposable test administrative connection is required.' [ASCII](length: 63) contains "Disposable schema ownership proof is required" [ASCII](length: 45).
  ```

#### Root Cause Analysis:
In `DisposableFinancialPostRunInspector::assertExecutionGates($expectedSchemaName)`:
- Line 193 calls `DisposableMariaDbSchema::assertPrimarySchema($expectedSchemaName)`.
- Line 100 of `DisposableMariaDbSchema::assertPrimarySchema` invokes `self::testSettings()`.
- `DisposableMariaDbSchema::testSettings()` checks `getenv('PHASE8_TEST_DB_USERNAME')` and `getenv('PHASE8_TEST_DB_PASSWORD')` (line 85) before `assertPrimarySchema()` reaches the proof path and nonce check (line 104).
- In the pure unconnectable test harness run where DB credentials are intentionally absent, `testSettings()` throws `'Explicit disposable test administrative connection is required.'`, triggering the expectation failure on line 2326 before the proof guard string is reached.

---

### POST-Execution Source Integrity

All file hashes re-computed immediately following execution:
- Commit HEAD: `3e4dea7c5441847ec7f303380eab0286ab5dda7f`
- Source Hashes:
  - `tests/Support/Harness/DisposableFinancialPostRunInspector.php`: `c139ef833b0b1d80f7ccbb05c6295c6605c65367b65c3b02d78ccaecfdd59fb0` (Identical)
  - `tests/Support/Harness/financial-inspector-cli.php`: `1e6a783d492aa33d19fde960a54897b94f9eac78b2742a5257ed11e62260c158` (Identical)
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wire.json`: `25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6` (Identical)
  - `tests/Support/Harness/fixtures/synthetic-financial-golden-wrapper.json`: `7458f05b57fe149beec9e26ebec77015b45f2acfcc428687f10c216f80f1592d` (Identical)
  - `tests/Support/Harness/fixtures/golden-provenance-manifest.json`: `2135861348655859da9ef4f232cbb1b3ba87debce0b9806340df62bce0476f08` (Identical)
  - `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`: `847c4063fd24c19af48330ecbc909f5ac81e2dec0057bc8527c3dc809d298fcd` (Identical)
  - `docs/integration/mobile-v2-client-handoff-schema.json`: `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275` (Identical)
  - `docs/integration/mobile-v2-integration-manifest.json`: `09c8deeadb2c6f2e82fb5b5a43ad93c4e79055433e88f94f283727f7be2a9121` (Identical)
- Working Tree: Zero source modifications during run.

---

### Recorded TODOs & Next Actions for Root Review

1. **TODO (Inspector / Test Guard Precedence)**:
   - In `test_financial_post_run_inspector_refuses_malformed_or_unproven_disposable_schema`, provide dummy `PHASE8_TEST_DB_USERNAME=disposable_user` and `PHASE8_TEST_DB_PASSWORD=disposable_pass` or adapt `DisposableFinancialPostRunInspector::assertExecutionGates` so the intended proof-check branch is reached under pure execution without relying on live DB credentials.
2. **TODO (CLI Mandatory External Pins)**:
   - Address whether CLI `--expected-baseline-hash` should fall back to trusted report vs requiring mandatory external parameter, matching the existing mandatory external `--run-id` model.

---

### Execution Artifacts

- **JUnit XML**: `docs/integration/pure-guard-junit.xml` (SHA-256: `f9062e08bbbfd9072fcf03be9a617adcb9e7643ad6dd54268375da40490536c5`)
- **Raw Run Output**: `docs/integration/pure-guard-run-output.txt` (SHA-256: `04544334317a52abbd01e98ccab4ea2409902e4dbd692ed13964ce94b4379f76`)
