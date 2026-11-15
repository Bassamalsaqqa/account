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

