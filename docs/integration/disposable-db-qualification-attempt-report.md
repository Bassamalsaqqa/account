# Disposable MariaDB Qualification Attempt Report

**Date**: 2026-11-15T07:27:49+02:00  
**Branch**: `t3/recover-cash-writer`  
**Execution HEAD**: `b8996447b3b3fb81b21a659107a0c71e6b66f43f`  
**PR**: [https://github.com/Bassamalsaqqa/account/pull/102](https://github.com/Bassamalsaqqa/account/pull/102)  
**Execution Lease**: `ONE SHORT EXCLUSIVE OWNED 3342 DB qualification lease` (NOW RELEASED)  
**Outcome**: `FAILED` (Exit code: 1, Stop on FIRST failure, NO autofix/retry)  
**Teardown**: Owned schema dropped, independent absence verified (`ABSENCE_CONFIRMED`)

---

### 1. Invariant & Source Integrity Verification (PRE & POST)

All pre- and post-execution source hashes match strictly and remain unaltered:

| Source Component | File Path | SHA-256 Digest | State |
| :--- | :--- | :--- | :--- |
| **Qualification Runner** | `tests/Support/Harness/disposable-db-qualification-runner.php` | `9b3f46f34383c31bb9962a4f00998a12fa1eeecb6ff7b28d6fbb6b69e5d4cb07` | Preserved |
| **Financial Post-Run Inspector** | `tests/Support/Harness/DisposableFinancialPostRunInspector.php` | `1e71e7c5b6ff9aa1787c9f80a498020a16b9b39fcb9be1cf91b29a6e60bfa434` | Preserved |
| **Mobile V2 Backend Harness** | `tests/Support/Harness/DisposableMobileV2BackendHarness.php` | `c51239c9027878368508544a4eb92f447743ea416973ba0778c187f5d4750c82` | Preserved |
| **Disposable MariaDB Schema** | `tests/Support/DisposableMariaDbSchema.php` | `05b1fae7f2aa2f5670aa5a485fa56dbcf99ef3ecbf510166a50616b3f9fc3cba` | Preserved |
| **Client Handoff Schema** | `docs/integration/mobile-v2-client-handoff-schema.json` | `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275` | Preserved |
| **Golden Manifest** | `tests/Support/Harness/fixtures/golden-provenance-manifest.json` | `2135861348655859da9ef4f232cbb1b3ba87debce0b9806340df62bce0476f08` | Preserved |
| **Golden Wire Fixture** | `tests/Support/Harness/fixtures/synthetic-financial-golden-wire.json` | `25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6` | Preserved |
| **Golden Wrapper Fixture** | `tests/Support/Harness/fixtures/synthetic-financial-golden-wrapper.json` | `7458f05b57fe149beec9e26ebec77015b45f2acfcc428687f10c216f80f1592d` | Preserved |
| **Integration Manifest** | `docs/integration/mobile-v2-integration-manifest.json` | `5c3eb851aa8a9d0426bbab4c75908b1a37c0410766f6ecdbffbb0eb58e7c10b7` | Preserved |
| **CreateCompanyAction** | `app/Actions/Company/CreateCompanyAction.php` | `26db5b1156637da98cf5a1334057dd215b248a3136d81ea1994fb59b1585aa68` | Preserved |

- **Production Diff**: `git diff 6981511405d7bcd04cd8f8a09e64443ce0aaf47e -- app routes config composer.lock` => **0 bytes** (strictly clean).
- **Working Tree State**: `git status --porcelain` => clean.

---

### 2. Execution Command & Environment

The run was executed under a dedicated external operator watchdog with a hard 200s timeout bound and emergency reap:

```bash
DB_URL="" \
DB_CONNECTION=mysql \
DB_HOST=127.0.0.1 \
DB_PORT=1 \
DB_DATABASE=poison_sentinel_unconnectable \
CACHE_STORE=array \
SESSION_DRIVER=array \
QUEUE_CONNECTION=sync \
APP_ENV=testing \
PHASE8_ALLOW_DISPOSABLE_DB=1 \
MOBILE_V2_HARNESS_ENABLE=1 \
php tests/Support/Harness/disposable-db-qualification-runner.php \
  --execute \
  --expected-source-head=b8996447b3b3fb81b21a659107a0c71e6b66f43f \
  --credentials-file=/home/t3dev/.t3/userdata/attachments/p8_db_qualification_run/admin_credentials.json \
  --timeout=180 \
  --output-file=/home/t3dev/.t3/userdata/attachments/p8_db_qualification_run/runner_output.json
```

---

### 3. Execution Trajectory & Observed Results

1. **Pre-checks**:
   - `proof_refusal`: **PASSED** (`Disposable schema ownership proof is required. Use the isolated runner.`)
   - `invalid_schema_refusal`: **PASSED** (`Inspector refused: invalid disposable schema name 'invalid_schema_name'.`)
2. **Ambient Sentinel**:
   - Initialized and poisoned ambient connection to `127.0.0.1:1` / `poison_sentinel_unconnectable`.
   - Zero ambient dials attempted (`ambient_connection_attempts: 0`).
3. **Owned Schema Creation**:
   - Created owned disposable schema `accounting_p8_tmp_a80e1544e7d3` on `127.0.0.1:3342`.
4. **First Failure Encountered (`DisposableMariaDbSchema::migrate`)**:
   - `DisposableMariaDbSchema::migrate()` invokes `$this->runApplicationCode(...)` to verify schema ownership before running `artisan migrate`.
   - `runApplicationCode` spawns a Symfony Process using `$this->environment()`.
   - `$this->environment()` provides `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, but does NOT export `PHASE8_TEST_DB_HOST`, `PHASE8_TEST_DB_PORT`, `PHASE8_TEST_DB_USERNAME`, `PHASE8_TEST_DB_PASSWORD`.
   - The child script invokes `Tests\Support\DisposableMariaDbSchema::testSettings()`, which threw:
     ```
     PHP Fatal error: Uncaught RuntimeException: Explicit disposable test administrative connection is required.
     in tests/Support/DisposableMariaDbSchema.php:85
     ```
   - Process exited with code 255.
5. **Enforcement of STOP ON FIRST FAILURE**:
   - The runner caught the exception immediately.
   - Refused to claim success; set `qualification_status = "FAILED"` and `exit_code = 1`.
   - No retry, no autofix, and no secondary tests attempted.

---

### 4. Teardown & Independent Absence Verification

1. **Synchronous Internal Teardown**:
   - The runner's `finally` block dropped the owned schema:
     `Owned schema dropped: accounting_p8_tmp_a80e1544e7d3`
   - Temporary cache and script files were cleaned up.
2. **External Watchdog Independent Absence Query**:
   - Connected directly to MariaDB on `127.0.0.1:3342` using administrative credentials.
   - Ran exact query without wildcard expansion:
     ```sql
     SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'accounting_p8_tmp_a80e1544e7d3';
     ```
   - Result: `0` rows returned (**`ABSENCE_CONFIRMED`**). Schema was completely deleted and did not leak.
3. **Process Children Absence**:
   - Subprocess `PID 1486492` and all child processes exited cleanly in `0.6655s`. Zero dangling processes.

---

### 5. Retained Artifacts & Hashes

All raw logs and audit artifacts are preserved under `/home/t3dev/.t3/userdata/attachments/p8_db_qualification_run/`:

- `admin_credentials.json` (0600): `bab71f831ab1d27c91376f79e221a1f118355b21f9d61f39e26709e3bf9219e8`
- `raw_stderr.log` (0600): `2624ff002ab1876e8a53be89897e333db02e9351a91c4218a3783c33cefe2dc3`
- `raw_stdout.log` (0600): `e0b2297d7ff0bb835a801477851b20542c030f42d8fbd596db764ad5e492c452`
- `runner_output.json` (0600): `e0b2297d7ff0bb835a801477851b20542c030f42d8fbd596db764ad5e492c452`
- `watchdog_audit.json` (0600): `b00f53fd6280ab5d42eac63184f524e696dbe27ecf08d3545381064230274c04`
- `watchdog_runner.py` (0700): `7604184aeb232bf5fdf0d011df19195c1696921852f015769566ace1285aa61a`

---

### 6. Lease Release

The single short exclusive owned DB qualification lease granted for `b8996447b3b3fb81b21a659107a0c71e6b66f43f` is **RELEASED IMMEDIATELY**.
Zero background listeners, servers, or database connections remain active.
Working tree non-doc diff against `b8996447b3b3fb81b21a659107a0c71e6b66f43f` is strictly empty.

---

### 7. Errata & Source Consolidation (Post-Attempt Review)

Following Root inspection of Phase 1 failure artifacts, the following source corrections were implemented in commit `6e3378a38b4af1c2cd8a19b268134a12ac8e8143`:

1. **Child Process Environment Propagation Fix**:
   - `DisposableMariaDbSchema::environment()` explicitly includes `PHASE8_TEST_DB_HOST`, `PHASE8_TEST_DB_PORT`, `PHASE8_TEST_DB_USERNAME`, `PHASE8_TEST_DB_PASSWORD`, and `PHASE8_TEST_DB_EMPTY_PASSWORD` in the returned array, ensuring Symfony `Process` child processes receive administrative credentials without relying on ambient inheritance.
   - `loadSecureAdministrativeCredentials` in `disposable-db-qualification-runner.php` populates `$_ENV` and `$_SERVER` alongside `putenv`.
   - `$isolatedEnv` explicitly passes administrative test credentials into the isolated Laravel pre-boot environment.

2. **Synthetic Child Process Pre-check Regression**:
   - Added Pre-check 3 in `disposable-db-qualification-runner.php` validating that a spawned Symfony `Process` child receives explicit dummy credentials and a fresh synthetic ownership proof, validating `testSettings()` and `assertPrimarySchema()` without dialing any database socket.

3. **Correction of False Observations in Runner Output**:
   - Replaced assumed literals (`owned_pdo_verified = true`, `3342`) with observed nullable state.
   - Sentinel report and dedicated PDO fields are marked `NOT_RUN` and `null` if failure occurs prior to reaching Step 8/9.
   - Baseline and six-reconciler results are marked `NOT_RUN` / `null` if unreached.

4. **Exact MariaDB Absence Query**:
   - Schema drop verification uses `SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?` to prevent underscore single-character wildcard ambiguity.

5. **Watchdog Security Hardening**:
   - Configured nonsecret environment logging to use a strict allowlist of known safe keys rather than serializing all ambient environment variables.
   - Future qualification attempts will target a fresh unique directory with independent nonce (`p8_db_qualification_phase2_<nonce>`), preserving Phase 1 artifacts intact.

---

### 8. Phase 2 Bounded Qualification Attempt & Outcome (`fcefc8f7bcaede3c0ed532b76c5c9d90e2939b41`)

**Execution Date**: 2026-11-15T07:37:37+02:00  
**Branch**: `t3/recover-cash-writer`  
**Execution HEAD**: `fcefc8f7bcaede3c0ed532b76c5c9d90e2939b41`  
**Lease**: `ONE exclusive OWNED 3342 DB phase2 attempt` (RELEASED IMMEDIATELY)  
**Outcome**: `FAILED` (Exit code: 1, Stop on FIRST failure, NO autofix/retry)  
**Elapsed Runtime**: `8.1347s` (Subprocess PID: `1492025`)  
**Teardown**: Owned schema dropped, independent absence verified (`ABSENCE_CONFIRMED`)

#### 8.1 Invariant & Source Integrity Verification (PRE & POST)
Pre- and post-execution source hashes match strictly across all components:
- `tests/Support/Harness/disposable-db-qualification-runner.php`: `aa29347ece0eee06683a0ae449379bcfd7705e8a3d7404d4066f3a0312aa2677`
- `tests/Support/Harness/DisposableFinancialPostRunInspector.php`: `4f419bef5aed7352230b1d54934a40f6cabebb912eb8ed771604473869954550`
- `tests/Support/Harness/DisposableMobileV2BackendHarness.php`: `271eb8c30e1d86a636fb8eaab46b7426fbcf84f8d009f8ad7b18d5d6bf43f12c`
- `tests/Support/DisposableMariaDbSchema.php`: `ac69d1e6f0875eff250ce1fd688f47fbb95162dcd081f33f889ebf1fc184c352`
- `docs/integration/mobile-v2-client-handoff-schema.json`: `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275`
- `tests/Support/Harness/fixtures/golden-provenance-manifest.json`: `2135861348655859da9ef4f232cbb1b3ba87debce0b9806340df62bce0476f08`
- `tests/Support/Harness/fixtures/synthetic-financial-golden-wire.json`: `25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6`
- `tests/Support/Harness/fixtures/synthetic-financial-golden-wrapper.json`: `7458f05b57fe149beec9e26ebec77015b45f2acfcc428687f10c216f80f1592d`
- `docs/integration/mobile-v2-integration-manifest.json`: `c53ccd90bca5dbe34b7ab6191f332a8e8b84de0869e8b060623d980e2759f78a`
- `app/Actions/Company/CreateCompanyAction.php`: `fa2f38cb99780e4c635c7a3f72d77c8832265c9aaacf366a8ee88853ceff26b5`
- **Working Tree State**: Clean (`git status --porcelain` empty).
- **Production Diff**: `git diff 6981511405d7bcd04cd8f8a09e64443ce0aaf47e -- app routes config composer.lock` => 0 bytes.

#### 8.2 Execution Trajectory & Steps Passed
1. **Pre-checks**:
   - `proof_refusal`: **PASS** (`Disposable schema ownership proof is required. Use the isolated runner.`)
   - `invalid_schema_refusal`: **PASS** (`Inspector refused: invalid disposable schema name 'invalid_schema_name'.`)
   - `child_env_propagation`: **PASS** (`Synthetic child process validated testSettings and assertPrimarySchema under explicit environment without DB socket.`)
2. **Schema Creation & Migration Lifecycle**:
   - Schema created: `accounting_p8_tmp_8087ec7c7426` on `127.0.0.1:3342`.
   - Explicit environment propagation: child process received administrative credentials via `DisposableMariaDbSchema::environment()`.
   - Schema migrations (`artisan migrate --force`): **PASS** (completed cleanly in ~7 seconds).
3. **Canonical Foundation Fixture**:
   - `CreateCompanyAction` executed under owned connection: created `company_id = 1` cleanly.
   - Permissions and roles established.
4. **Ambient Connection Sentinel**:
   - Ambient connections poisoned to `127.0.0.1:1` / `poison_sentinel_unconnectable`.
   - Interceptor installed; **0** ambient dials recorded (`ambient_connection_attempts: 0`).
5. **Dedicated Read-Only Connection**:
   - Dedicated PDO connected to `accounting_p8_tmp_8087ec7c7426` on port `3342`.
   - Disposable probe table `_disposable_ro_probe` created and populated.
   - Read-only transaction initiated: `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY`.

#### 8.3 First Failure Encountered (`innodb_trx` Column Name)
- The query `SELECT trx_read_only FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID()` failed with:
  ```
  SQLSTATE[42S22]: Column not found: 1054 Unknown column 'trx_read_only' in 'SELECT'
  ```
- **Root Cause**: In MariaDB 10.x/10.11, the read-only flag in `information_schema.innodb_trx` is named `trx_is_read_only` (MySQL uses `trx_read_only`).
- **Enforcement**: STOP ON FIRST FAILURE enforced immediately. `qualification_status = "FAILED"`, `exit_code = 1`. No retry, no autofix, no secondary queries.

#### 8.4 Teardown & Absence Verification
1. **Internal Synchronous Teardown**:
   - The runner's `finally` block dropped `accounting_p8_tmp_8087ec7c7426`.
2. **External Watchdog Independent Absence Query**:
   - Executed `SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'accounting_p8_tmp_8087ec7c7426'` against MariaDB port 3342.
   - Returned `0` rows (**`ABSENCE_CONFIRMED`**). Zero schema leakage.
3. **Process Hierarchy**:
   - All subprocesses and migration workers cleanly exited and were reaped. Zero dangling processes.

#### 8.5 Phase 2 Retained Artifacts & Hashes
All artifacts are preserved under `/home/t3dev/.t3/userdata/attachments/p8_db_qualification_phase2_8c2e91da/` (dir `0700`, files `0600`):
- `admin_credentials.json`: `bab71f831ab1d27c91376f79e221a1f118355b21f9d61f39e26709e3bf9219e8`
- `pre_source_hashes.json`: `6bd6628bca4cc8021a5edeff093bd60b4852c6711d6667fead011a65dc93bb97`
- `post_source_hashes.json`: `6bd6628bca4cc8021a5edeff093bd60b4852c6711d6667fead011a65dc93bb97`
- `raw_stderr.log`: `5a0f5e662dfb984e01e3ab0d80c79480bb86a2924fd35f3480e7a4c02f71e5fc`
- `raw_stdout.log`: `c43cd09ecdb763a19df773bc919d916d605466f3ba59e14b4902a418a010bb51`
- `runner_output.json`: `c43cd09ecdb763a19df773bc919d916d605466f3ba59e14b4902a418a010bb51`
- `watchdog_audit.json`: `0a24ebf01b07c7cc0cbdbb40829e55ae01b4ced91e35fa04bc976d082bb7965f`
- `watchdog_runner_phase2.py`: `82fa0266eb7499ee91f6c734a716a1a28dee05908f0855aa028a06c12a409054`

#### 8.6 Source Consolidation for Phase 3 Readiness (No DB Run until Root Grant)
1. **MariaDB Transaction Column Compatibility**:
   - `disposable-db-qualification-runner.php` queries `trx_is_read_only` (MariaDB standard) with fallback to `trx_read_only` (MySQL) and `SELECT *`.
2. **Honest Observation Reporting**:
   - `read_only_proof.status` is set to `VERIFIED` strictly when `$innodbTrxReadOnlyObserved === 1 && $mutationDeniedObserved === true`. Otherwise `FAILED` if attempted, or `NOT_RUN` if unreached.
   - `baseline_comparison.status` is set to `VERIFIED` strictly when `$postBaseline === $preBaseline && $executionSuccess === true`.
   - `six_reconcilers_status` is set to `VERIFIED` strictly when `$reconciliationReport !== null && ($reconciliationReport['all_healthy'] ?? false) === true`.
3. **Lease Status**:
   - **RELEASED IMMEDIATELY**. Zero active connections, zero background listeners.

---

### 9. Phase 3 Bounded Qualification Attempt & Outcome (`8f28e4c8297512462a7eea16f4044959828c7d24`)

**Execution Date**: 2026-11-15T07:45:23+02:00  
**Branch**: `t3/recover-cash-writer`  
**Execution HEAD**: `8f28e4c8297512462a7eea16f4044959828c7d24`  
**Lease**: `ONE exclusive owned 3342 phase3 attempt` (RELEASED IMMEDIATELY)  
**Outcome**: `FAILED` (Exit code: 1, Stop on FIRST failure, NO autofix/retry)  
**Elapsed Runtime**: `6.0263s` (Subprocess PID: `1495557`)  
**Teardown**: Owned schema dropped, independent absence verified (`ABSENCE_CONFIRMED`)

#### 9.1 Invariant & Source Integrity Verification (PRE & POST)
Pre- and post-execution source hashes match strictly across all components:
- `tests/Support/Harness/disposable-db-qualification-runner.php`: `2d78f831ffd92bdb08a390f7e07e0283d116af03f0af45f94a6bce2355273e73`
- `tests/Support/Harness/DisposableFinancialPostRunInspector.php`: `4f419bef5aed7352230b1d54934a40f6cabebb912eb8ed771604473869954550`
- `tests/Support/Harness/DisposableMobileV2BackendHarness.php`: `271eb8c30e1d86a636fb8eaab46b7426fbcf84f8d009f8ad7b18d5d6bf43f12c`
- `tests/Support/DisposableMariaDbSchema.php`: `ac69d1e6f0875eff250ce1fd688f47fbb95162dcd081f33f889ebf1fc184c352`
- `docs/integration/mobile-v2-client-handoff-schema.json`: `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275`
- `tests/Support/Harness/fixtures/golden-provenance-manifest.json`: `2135861348655859da9ef4f232cbb1b3ba87debce0b9806340df62bce0476f08`
- `tests/Support/Harness/fixtures/synthetic-financial-golden-wire.json`: `25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6`
- `tests/Support/Harness/fixtures/synthetic-financial-golden-wrapper.json`: `7458f05b57fe149beec9e26ebec77015b45f2acfcc428687f10c216f80f1592d`
- `docs/integration/mobile-v2-integration-manifest.json`: `c53ccd90bca5dbe34b7ab6191f332a8e8b84de0869e8b060623d980e2759f78a`
- `app/Actions/Company/CreateCompanyAction.php`: `fa2f38cb99780e4c635c7a3f72d77c8832265c9aaacf366a8ee88853ceff26b5`
- **Working Tree State**: Clean (`git status --porcelain` empty).
- **Production Diff**: `git diff 6981511405d7bcd04cd8f8a09e64443ce0aaf47e -- app routes config composer.lock` => 0 bytes.

#### 9.2 Execution Trajectory & Steps Passed
1. **Pre-checks**:
   - `proof_refusal`: **PASS** (`Disposable schema ownership proof is required. Use the isolated runner.`)
   - `invalid_schema_refusal`: **PASS** (`Inspector refused: invalid disposable schema name 'invalid_schema_name'.`)
   - `child_env_propagation`: **PASS** (`Synthetic child process validated testSettings and assertPrimarySchema under explicit environment without DB socket.`)
2. **Schema Creation & Migration Lifecycle**:
   - Schema created: `accounting_p8_tmp_d8d550da625b` on `127.0.0.1:3342`.
   - Explicit environment propagation: child process received administrative credentials via `DisposableMariaDbSchema::environment()`.
   - Schema migrations (`artisan migrate --force`): **PASS** (completed cleanly in ~5 seconds).
3. **Canonical Foundation Fixture**:
   - `CreateCompanyAction` executed under owned connection: created `company_id = 1` cleanly.
   - Permissions and roles established.
4. **Ambient Connection Sentinel**:
   - Ambient connections poisoned to `127.0.0.1:1` / `poison_sentinel_unconnectable`.
   - Interceptor installed; **0** ambient dials recorded (`ambient_connection_attempts: 0`).
5. **Dedicated Read-Only Connection**:
   - Dedicated PDO connected to `accounting_p8_tmp_d8d550da625b` on port `3342`.
   - Disposable probe table `_disposable_ro_probe` created and populated.
   - Read-only transaction initiated: `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY`.

#### 9.3 First Failure Encountered (`PROCESS` Privilege Required for `innodb_trx`)
- The query `SELECT trx_is_read_only FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID()` failed with:
  ```
  SQLSTATE[42000]: Syntax error or access violation: 1227 Access denied; you need (at least one of) the PROCESS privilege(s) for this operation
  ```
- **Root Cause**: In MariaDB, accessing `information_schema.innodb_trx` requires the global `PROCESS` privilege. The user `test_runner` has database-level privileges on `accounting_p8_tmp_%` and schema administrative rights, but does not have the global `PROCESS` privilege granted on the server.
- **Truthful Status Reporting**:
  - `read_only_proof.status` evaluated strictly to **`FAILED`** (addressing prior false-positive `VERIFIED` label).
  - `baseline_comparison.status` evaluated to **`NOT_RUN`**.
  - `six_reconcilers_status` evaluated to **`NOT_RUN`**.
- **Enforcement**: STOP ON FIRST FAILURE enforced immediately. `qualification_status = "FAILED"`, `exit_code = 1`. No retry, no autofix, no secondary queries during attempt.

#### 9.4 Teardown & Absence Verification
1. **Internal Synchronous Teardown**:
   - The runner's `finally` block dropped `accounting_p8_tmp_d8d550da625b`.
2. **External Watchdog Independent Absence Query**:
   - Executed `SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'accounting_p8_tmp_d8d550da625b'` against MariaDB port 3342.
   - Returned `0` rows (**`ABSENCE_CONFIRMED`**). Zero schema leakage.
3. **Process Hierarchy**:
   - Subprocess PID 1495557 and all migration workers cleanly exited and were reaped. Zero dangling processes.

#### 9.5 Phase 3 Retained Artifacts & Hashes
All artifacts are preserved under `/home/t3dev/.t3/userdata/attachments/p8_db_qualification_phase3_e1ac90f7/` (dir `0700`, files `0600`):
- `admin_credentials.json`: `bab71f831ab1d27c91376f79e221a1f118355b21f9d61f39e26709e3bf9219e8`
- `pre_source_hashes.json`: `e85820504f2970ce51cb109fcf3bd3a8da3f4036c0e2750298165c37bb3653d8`
- `post_source_hashes.json`: `e85820504f2970ce51cb109fcf3bd3a8da3f4036c0e2750298165c37bb3653d8`
- `raw_stderr.log`: `a6706a16e8465d12db88de03416f9793e9e61bcdcb72da0b8778168b10228928`
- `raw_stdout.log`: `0e13d1c0e1b9b05db292575c553cdf3929e636242524fda4e56ee6e8e1fb4d15`
- `runner_output.json`: `0e13d1c0e1b9b05db292575c553cdf3929e636242524fda4e56ee6e8e1fb4d15`
- `watchdog_audit.json`: `5f3b5cf13e1c3e0b87901783db06a9df8267bad479bcc975c1ccc7ded66e1b45`
- `watchdog_runner_phase3.py`: `57505f86cc18d7cda812d60da81d95d6ce489a6a546f24c194d24fd1cde0979d`

#### 9.6 Stand Down & Lease Release
- **Lease Status**: **RELEASED IMMEDIATELY**. Zero active connections, zero background listeners.
- **DOCS ONLY Publish**: No unrequested source fixes bundled into docs handback. Root next source fix separately.

