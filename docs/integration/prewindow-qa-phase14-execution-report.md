# Pre-Window QA & Prewarm Execution Report (Phase 14)

- **Date / Time**: 2026-11-15T15:17:49Z – 2026-11-15T15:19:18Z
- **Execution Target Commit**: `a06797faa8a7c274a2fc11304fe71cf0daa06111`
- **Branch**: `t3/recover-cash-writer`
- **Runner**: PHPUnit 12.5.36 (PHP 8.4.27) & Gradle 9.3.1 (JDK 21.0.13+9)
- **Android Peer Publication HEAD**: `b0d5965e7dc5d0e483880afa0bf3ca6a025ee3e6`
- **Android Peer Executable Pin**: `360ce78408f09ecff451381e595a4ffe2f116969`
- **Android Worktree**: `/home/t3dev/.t3/worktrees/small-trader-android/t3-7ac8b73d-abb5d6e52b3b3cf9`
- **Peer Packet**: `/tmp/android-peer-b0-9bsv39hj/peer-packet.json` (`9840f47ba9a67f3995b00a218fdeea88e229963f2db649b429456cfbfc89b1e9`)
- **Outcome**: **SUCCESS (PHASE A ALL 45 PASS + PHASE B COMPILE-ONLY PREWARM SUCCESS)**

---

### 1. Phase A: Focused Pure QA Execution

- **Command**:
  ```bash
  DB_HOST=127.0.0.1 DB_PORT=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=1 PHASE8_ALLOW_DISPOSABLE_DB=0 MOBILE_V2_HARNESS_ENABLE=0 APP_ENV=testing \
  vendor/bin/phpunit \
    --bootstrap vendor/autoload.php \
    --no-configuration \
    --stop-on-failure \
    --stop-on-error \
    --log-junit=/home/t3dev/.t3/userdata/attachments/bounded_qa_phase14_92573c56/phaseA_junit.xml \
    tests/Unit/Mobile/Harness/SupervisedFinancialDrillTest.php
  ```
- **Exit Code**: `0`
- **Duration**: `0.214s` (`00:00.047` PHPUnit internal runtime)
- **Total Tests Executed**: 45
- **Assertions Completed**: 371
- **Passed**: 45
- **Failures**: 0
- **Errors**: 0
- **Skipped**: 0
- **PHPUnit Notices**: 15 (runtime socket probe operations against closed loopback ports during exact cleanup verification tests)
- **Source Invariance**:
  - `head_unchanged`: `true`
  - `status_unchanged`: `true`
  - `source_hashes_identical`: `true` (301,301 bytes tracked file hash map identical before and after run)

---

### 2. Phase B: Android 201 Source Verification & Prewarm Compile

- **Peer Packet Verification**:
  - Peer packet digest: `9840f47ba9a67f3995b00a218fdeea88e229963f2db649b429456cfbfc89b1e9`
  - All 201 source files in `/home/t3dev/.t3/worktrees/small-trader-android/t3-7ac8b73d-abb5d6e52b3b3cf9` verified 100% match with `sourceSha256` map.
  - Diff vs `360ce78408f09ecff451381e595a4ffe2f116969` strictly verified to touch only the 2 allowed docs files (`QA-EVIDENCE.md` and `SOURCE_RELEASE_MANIFEST.json`).
- **Command**:
  ```bash
  JAVA_HOME="/home/t3dev/.local/share/android-toolchain/jdk-21.0.13+9" \
  ANDROID_HOME="/home/t3dev/.local/share/android-toolchain/sdk" \
  PATH="/home/t3dev/.local/share/android-toolchain/jdk-21.0.13+9/bin:/usr/local/bin:/usr/bin:/bin" \
  bash tests/Support/Harness/paired-drill-prewarm.sh \
    /home/t3dev/.t3/worktrees/small-trader-android/t3-7ac8b73d-abb5d6e52b3b3cf9 \
    --offline
  ```
- **Exit Code**: `0`
- **Duration**: `86.298s` (within 180s bound; Gradle build: 1m 25s)
- **Build Result**:
  - 31 actionable tasks: 2 executed (`:app:kspDebugKotlin`, `:app:compileDebugKotlin`), 29 up-to-date.
  - `:app:compileDebugUnitTestSources UP-TO-DATE`
  - Configuration cache entry stored.
- **Network / Financial Isolation**:
  - Offline mode enforced (`--offline`).
  - Zero financial HTTP endpoints contacted.
  - Zero DB queries executed.
  - Zero test suites run during compile prewarm.
- **Invariance**:
  - Backend HEAD, status, and source hashes identical.
  - Android HEAD, status, and source hashes identical.

---

### 3. Process Absence Attestation

- Direct inspection verified 0 active PHPUnit, artisan, Gradle, or supervisor jobs.
- Loopback ports 18080 and 18443 remain closed.

---

### 4. Retained Evidence Directory (`/home/t3dev/.t3/userdata/attachments/bounded_qa_phase14_92573c56/`)

| File Name | SHA-256 Digest | Size (Bytes) | Mode | Description |
|---|---|---|---|---|
| `phaseA_pre_backend.json` | `c949ef5e85f87ec25d16038a22a57de17a1d34403520caa356840a265d6e502e` | 301,301 | `0600` | Machine PRE snapshot: backend git HEAD, status, and full source hash map |
| `phaseA_post_backend.json` | `5dc711751b7dacdfe2c4d6cf5372ab1cf58cc24423b6e4e9c668b4ef895d8919` | 301,301 | `0600` | Machine POST snapshot: backend source invariance attestation |
| `phaseA_junit.xml` | `215a67b4f74c0a2b70638e6bc2afeb1774ba566c60ca6d8f5c25a68663875e90` | 18,227 | `0600` | JUnit XML report across all 45 passing test methods |
| `phaseA_raw.txt` | `933b2e1e4179478723aa864a19d105bd0d8e599b69d6385daead889bd931a94b` | 1,024 | `0600` | Raw stdout/stderr stream from Phase A PHPUnit run |
| `phaseA_meta.json` | `28698d62703e127b04d04ef3544e8b0c14fe6dd6d7de0ed2bcdbeeabb2d5ce86` | 654 | `0600` | Phase A argv, timing, and source invariance verification |
| `phaseB_pre_both.json` | `8c6c628513539fd3ad6a86f58832892bf50cb738baa516db839100c3e324d772` | 389,712 | `0600` | Machine PRE snapshot: both repos git HEAD, status, 201 source verification |
| `phaseB_post_both.json` | `e1c5446ef67ca6de9c1c7beaa67024dcc27d68ebdad1070a45e1fed9b912b687` | 389,408 | `0600` | Machine POST snapshot: both repos source invariance attestation |
| `phaseB_raw.txt` | `0b0fa75441551b8e21c74dcf80e51c0607ae4cee09f9dcb503ad72a6ab44fc5c` | 3,298 | `0600` | Raw stdout/stderr stream from Phase B offline prewarm compile |
| `phaseB_meta.json` | `25b770dba0bfaea00e1f71e2215dc4114607fa0350f25667b61c9c4ac295c041` | 711 | `0600` | Phase B argv, timing, and source invariance verification |
| `machine_artifact_index.json` | `d601b07ec32007f354f02fae9ae418e974e645fe14299446d6b5e022f18167f6` | 1,442 | `0600` | Byte-level manifest index of all retained run files |
| `run_summary.json` | `27c3d1b9be38d17f3583561a3f6562479e0bf0114002ea8fae6396f7c9b837f4` | 263 | `0600` | Execution summary: both phases exit code 0 |

---

### 5. Published Sanitized Documents

- [`docs/integration/prewindow-qa-phase14-execution-report.md`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/prewindow-qa-phase14-execution-report.md)
- [`docs/integration/prewindow-qa-phase14-machine-manifest.json`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/prewindow-qa-phase14-machine-manifest.json)
- [`docs/integration/prewindow-qa-phase14-phaseA-junit.xml`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/prewindow-qa-phase14-phaseA-junit.xml)
- [`docs/integration/prewindow-qa-phase14-phaseA-run-output.txt`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/prewindow-qa-phase14-phaseA-run-output.txt)
- [`docs/integration/prewindow-qa-phase14-phaseB-run-output.txt`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/prewindow-qa-phase14-phaseB-run-output.txt)

---

### 6. Lease Status

- **Status**: RELEASED immediately.
- **Active Processes**: 0.
- **Next Step**: Awaiting Root review and separate Root grant for paired financial attempt 4.
