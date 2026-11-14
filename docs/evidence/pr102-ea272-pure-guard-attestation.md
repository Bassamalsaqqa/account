# PR #102 Pure Guard Attestation Report (Commit `ea272d3`)

**PR**: [#102](https://github.com/Bassamalsaqqa/account/pull/102) (`t3/recover-cash-writer`)  
**Repository**: `Bassamalsaqqa/account`  
**Execution HEAD**: `ea272d3370600b52ad1da4f6824e4a2cc3be12b8`  
**Attestation Type**: Verified Single Pure Guard QA Execution (Docs-Only Publication)  
**Execution Timestamp**: `2026-11-14T19:53:41Z` to `2026-11-14T19:53:45Z` (3,286 ms elapsed)  
**Core Invariant Base**: `6981511405d7bcd04cd8f8a09e64443ce0aaf47e` (0-byte diff on `app/`, `routes/`, `config/`, `composer.lock`)  
**Client Handoff Schema SHA-256**: `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275`  

---

## 1. Executive Summary & Verification Outcome

Under the short exclusive pure QA lease granted following Android's verified 44-test pass, the full 64-test pure guard suite was executed exactly once against commit [`ea272d3370600b52ad1da4f6824e4a2cc3be12b8`](https://github.com/Bassamalsaqqa/account/commit/ea272d3370600b52ad1da4f6824e4a2cc3be12b8).

- **Execution Command**:
  ```bash
  PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=1 DB_HOST=127.0.0.1 DB_PORT=1 \
    ./vendor/bin/phpunit tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php \
    --log-junit /tmp/pure-guard-run-ea272/junit.xml
  ```
- **Execution Results**:
  - **Tests**: 64 total, 64 PASS, 0 FAIL, 0 ERROR, 0 SKIP
  - **Assertions**: 284 total
  - **Exit Code**: `0`
  - **PHPUnit Elapsed Time**: `00:02.821` (Wall-clock: 3,286 ms)
  - **Memory Usage**: `30.50 MB`
- **Network & Process Boundaries**:
  - Unconnectable database environment enforced (`PHASE8_TEST_DB_PORT=1`, `DB_PORT=1`).
  - **0 DB connections / sockets opened** (MariaDB port 3342 unused).
  - **0 background network listeners started**.
  - **0 TLS proxy sockets opened**.
  - **0 JVM executions**.
  - **0 background processes remaining**.

---

## 2. Pre- and Post-Execution Invariant & Hash Attestation

Both before and immediately following test execution, snapshots of git HEAD, porcelain working tree status, and target source file SHA-256 hashes were recorded to verify strict immobility and zero drift during the run.

### 2.1 Invariant State Matches
| Check | Pre-Execution Value | Post-Execution Value | Result |
|---|---|---|---|
| **Git Commit HEAD** | `ea272d3370600b52ad1da4f6824e4a2cc3be12b8` | `ea272d3370600b52ad1da4f6824e4a2cc3be12b8` | **IDENTICAL** |
| **Git Working Tree Status** | Clean (`git status --porcelain` empty) | Clean (`git status --porcelain` empty) | **CLEAN** |
| **Core Invariance vs `6981511`** | 0 bytes (`app/`, `routes/`, `config/`, `composer.lock`) | 0 bytes (`app/`, `routes/`, `config/`, `composer.lock`) | **0 BYTES** |
| **Handoff Schema SHA-256** | `edaef030...ca275` | `edaef030...ca275` | **IDENTICAL** |

### 2.2 Target Source File Hashes (Verified 100% Identical Pre- and Post-Run)
| File Path | SHA-256 Digest |
|---|---|
| `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php` | `8138145a5a82697607b67612f728e6ccfc5a0e2ac20db7f4877a49e6e36c74eb` |
| `tests/Support/Harness/DisposableMobileV2BackendHarness.php` | `271eb8c30e1d86a636fb8eaab46b7426fbcf84f8d009f8ad7b18d5d6bf43f12c` |
| `tests/Support/Harness/MobileV2HarnessCommand.php` | `0b6914ffc299cd399db6ab2311764b4bd18212238fbaeb46d32518da356a4aa7` |
| `tests/Support/Harness/mobile-v2-harness-cli.php` | `70bcf0edc24cbba3d896ad7d742085bfed616b370dbb0919a1ace55f1a2a5075` |
| `docs/integration/mobile-v2-client-handoff-schema.json` | `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275` |
| `docs/integration/mobile-v2-integration-manifest.json` | `eb7d8999bcb57b8f65c2899b886c66820d34f35d89f7b6c2fd02df97a2d29c70` |
| `docs/evidence/pr102-disposable-financial-test-plan.md` | `6470b5ed76af40584827f9dc38132c7be757a436b5d5bc3aa59be9e0401a74a4` |

---

## 3. Raw Execution Artifacts & Digests

Execution outputs were captured privately outside the repository checkout at `/tmp/pure-guard-run-ea272` (permissions `0700` dir / `0600` files).

| Artifact File | Description | SHA-256 Digest |
|---|---|---|
| `junit.xml` | Full JUnit test execution report (64 test cases, 284 assertions) | `073395536bc15580e39f0479e7e75dfe3ab648abe192405fe548532a7ca8079d` |
| `raw_stdout.txt` | Complete unedited stdout stream from PHPUnit | `dd8f78f1c157deb633ad2469b15b4b72dcfa007e2264e4f6c1c56e9897519ef3` |
| `raw_stderr.txt` | Complete unedited stderr stream from PHPUnit (empty) | `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855` |
| `run_meta.json` | Execution metadata (timestamps, command, exit code, invariant bools) | `1d9802c703dad4de9150821abdd2352c9dcbbebf6e7d416d51294183b9d09e49` |

### 3.1 Raw Output Excerpt
```text
PHPUnit 12.5.36 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.27
Configuration: /home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/phpunit.xml

................................................................  64 / 64 (100%)

Time: 00:02.821, Memory: 30.50 MB

OK (64 tests, 284 assertions)
```

---

## 4. Scope & Test Cleanup Boundary Qualification

1. **Pure Unit Guard Scope**:
   - The test `test_actual_used_signal_guard_path_aborts_non_zero_with_truthful_log_and_invokes_real_cleanup` exercised real `DisposableMobileV2BackendHarness::cleanup()` execution on an **unprovisioned** harness instance (verifying that interrupted setup cleanly triggers cleanup without throwing).
   - This test strictly does **not** claim live database schema drop or child process termination, which only applies during actual provisioned / paired transport runs.
2. **Prior Runs Distinction**:
   - This single attested pure run (64 tests / 284 assertions) strictly documents commit `ea272d3`.
   - Prior runs (such as Run 5 readiness `ooug5prl` 2/2 tests PASS and Android 44/44 PASS) remain separate, immutable historical artifacts. No fabricated prior 64-test logs exist or were manufactured.
3. **Future Android Approved Pairing Target Pins**:
   - **Publication Pin**: `c00aedb70ea06f28ae42282149c030df76dc300d`
   - **Executable Pin**: `b7e61eb4ed0aed2f5040d9fc44f9764b310be530`
   - Replaces `6bc`/`78ac` for future pairing only following the Root-verified 44-test focused pass.
   - The future outer financial validator must independently verify that both fresh JSON pins in `CashPilotEvidenceJSON` match approved publication `c00` and executable `b7`, alongside live handoff SHA and the actual backend HEAD commit at execution time.
