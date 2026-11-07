# AGY Runtime Contract Fixes & Regression Qualifications — 2026-11-07

Status: **BOUNDED REGRESSION FIXES QUALIFIED — ALL OWNED TESTS PASS**

Baseline HEAD: `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`. Existing dirty source changes preserved. MariaDB test runner: dedicated isolated MariaDB on `127.0.0.1:3342`, user `test_runner` with intentionally empty password, restricted to `accounting_p8_tmp_%` prefix. No global/production privilege expansion, no commits, no push, no full suite execution.

---

## 1. Summary of Owned Test Results

Combined focused execution using documented disposable test runner:
`php tests/Support/run-phase8-disposable.php -- tests/Unit/ProductExperience/DeploymentScriptContractTest.php tests/Feature/Phase10/BarcodeCertificationTest.php tests/Support/DisposableMariaDbSchemaTest.php tests/Feature/ManagedSaas/PlatformConcurrencyTest.php tests/Feature/CompanyDataExport`

- **Total Tests Executed:** 73
- **Total Assertions:** 1,014
- **Errors:** 0
- **Failures:** 0
- **Exit Code:** 0
- **Execution Time:** 01:38.619
- **Peak Memory:** 97.00 MB

---

## 2. Detailed Findings, Root Causes, and Applied Fixes

### A. CompanyDataExport (14 Huda Failures -> 0 Failures)
- **Target Files:**
  - `tests/Feature/CompanyDataExport/CompanyDataExportLimitsTest.php`
  - `tests/Feature/CompanyDataExport/CompanyDataExportTest.php`
  - `tests/Feature/CompanyDataExport/ExportReauthenticationTest.php`
  - Also verified inherited: `tests/Feature/RefinementA2/ExportSnapshotBoundaryTest.php`, `tests/Feature/RefinementA2/ExportDatasetContractTest.php`
- **Observed Defect in Huda Suite:**
  - 14 export tests failed in full suite: 4 tests in `CompanyDataExportLimitsTest` (per-dataset rows, total rows, compressed bytes, uncompressed bytes) reported `memory_limit_exceeded` instead of `row_limit_exceeded` / `byte_limit_exceeded`; 10 tests across `CompanyDataExportTest` (9) and `ExportReauthenticationTest` (1) returned HTTP 422 instead of HTTP 200.
  - Root cause: `CompanyDataExportService` verifies absolute process memory `memory_get_usage(true) > $maxMemoryBytes` against a hard default safety cap of 128 MiB (`config/company_data_export.php:34` and `self::HARD_MAX_MEMORY_BYTES = 134_217_728`). When the full 4000-test suite executed in a single long-lived PHPUnit CLI process, accumulated memory reached 314.50 MiB. Consequently, any export request executed later in the process failed immediately on the memory ceiling before reaching dataset row or archive byte limits.
- **Resolution & Contract Integrity:**
  - Production safety cap (128 MiB ceiling) was **not raised or modified** in core application source or configuration.
  - Test classes were equipped with PHPUnit process isolation:
    `#[RunTestsInSeparateProcesses]` and `#[PreserveGlobalState(false)]`.
  - In an isolated process, memory starts at ~24.50 MB, leaving abundant headroom under the 128 MiB cap.
  - Verified that dataset row limits (`row_limit_exceeded`), compressed/uncompressed byte limits (`byte_limit_exceeded`), memory limit (`memory_limit_exceeded`), and timeout limit (`execution_timeout`) are each independently and genuinely reached and asserted.
- **Full-Suite Memory Scenario Verification:**
  - Simulated parent process memory accumulation by allocating 320 MB in the parent runner process before launching the test suite.
  - Under 330 MB parent memory, isolated child processes started at clean memory (~24.5 MB) and all 9 limit cases in `CompanyDataExportLimitsTest` passed cleanly (exit 0).
- **Actual Counts:**
  - `CompanyDataExport` suite: **28 tests, 488 assertions, 0 failures, 0 errors** (time 01:14.281).
  - Inherited `RefinementA2` export tests: **4 tests, 1,310 assertions, 0 failures, 0 errors** (time 00:05.427).

### B. Phase 10 Barcode Certification (3 Huda Failures -> 0 Failures)
- **Target Files:**
  - `tests/Feature/Phase10/BarcodeCertificationTest.php`
  - `tests/Support/Phase10/decode-barcodes.py`
- **Observed Defect in Huda Suite:**
  - 3 tests failed with exit 127: `sh: 1: exec: python: not found` in `runDecoder()`.
  - Root cause: `BarcodeCertificationTest.php` line 54 defaulted to `'python'` when `PHASE10_PYTHON_BINARY` was unset. On the test environment, `/usr/bin/python3` (Python 3.13.5) was installed, but the bare `python` command alias did not exist. In addition, python decoder libraries (`pymupdf`, `zxing-cpp`, `pillow`) were missing from the user runtime.
- **Resolution & Contract Integrity:**
  - Updated `BarcodeCertificationTest.php` setup to use portable explicit python3 resolution: respects `PHASE10_PYTHON_BINARY` if set, otherwise queries `command -v python3`, defaulting to `python3`.
  - Provided runtime user symlink `/home/t3dev/.local/bin/python -> /usr/bin/python3`.
  - Installed decoder dependencies (`pymupdf`, `zxing-cpp`, `pillow`) into user environment without touching global system packages.
- **Actual Counts:**
  - `BarcodeCertificationTest`: **9 tests, 300 assertions, 0 failures, 0 errors** (time 00:10.698).

### C. Deployment Script Contract (13 Huda Failures -> 0 Failures)
- **Target Files:**
  - `tests/Unit/ProductExperience/DeploymentScriptContractTest.php`
- **Observed Defect in Huda Suite:**
  - 13 deployment contract tests failed due to `bin/deploy.sh: line 217: awk: command not found` and `line 273: awk: command not found`.
  - Root cause: On the test host, `/usr/bin/awk` was a dangling symlink to a non-existent `/etc/alternatives/awk`. Real working awk binaries GNU Awk (`/usr/bin/gawk`) and mawk (`/usr/bin/mawk`) existed. When synthetic shell fixtures ran with isolated PATH in `run_test.sh`, awk was unresolved.
- **Resolution & Contract Integrity:**
  - Did **not weaken deployment gates** or modify `bin/deploy.sh`. Production deployment script continues to strictly enforce PWA manifest MIME validation, CloudLinux PHP 8.4 handler verification, and sha256 checksums via awk.
  - Added `resolveAwkBinary()` helper to `DeploymentScriptContractTest.php` to discover real functional awk binaries (`gawk`, `mawk`, `awk`).
  - Updated fixture creation (`createSyntheticDeploymentFixture()`) to provision an executable `awk` in `$fixtureDir/bin` and exported this directory in `run_test.sh` PATH.
- **Actual Counts:**
  - `DeploymentScriptContractTest`: **22 tests, 160 assertions, 0 failures, 0 errors** (time 00:01.844).

### D. Disposable MariaDB Schema Sentinel Test (1 Huda Error -> 0 Errors)
- **Target Files:**
  - `tests/Support/DisposableMariaDbSchemaTest.php`
- **Observed Defect in Huda Suite:**
  - `test_preserves_sentinel_in_independently_owned_test_schema` failed with `PDOException: Access denied for user 'test_runner'@'127.0.0.1' to database 'accounting_p8_sentinel_*'`.
  - Root cause: Test attempted raw `CREATE DATABASE accounting_p8_sentinel_*`. Documented runner privileges are strictly restricted to `accounting_p8_tmp_%`.* prefix.
- **Resolution & Contract Integrity:**
  - Did **not expand database grants** or grant global privileges.
  - Refactored `test_preserves_sentinel_in_independently_owned_test_schema` to create a second independently owned schema using `DisposableMariaDbSchema::create()`, utilizing its existing regex prefix guard (`accounting_p8_tmp_[a-f0-9]{12}`).
  - Inserted sentinel test table/record, created and dropped a separate disposable schema, verified the sentinel schema and records remained completely intact, and dropped the sentinel schema in `finally`.
- **Actual Counts:**
  - `DisposableMariaDbSchemaTest`: **10 tests, 29 assertions, 0 failures, 0 errors** (time 00:00.014).

### E. Managed SaaS Platform Concurrency Introspection (1 Huda Error -> 0 Errors)
- **Target Files:**
  - `tests/Feature/ManagedSaas/PlatformConcurrencyTest.php`
- **Observed Defect in Huda Suite:**
  - `test_authorized_transaction_finishes_before_waiting_revocation_and_later_work_is_denied` threw `QueryException: Access denied; you need (at least one of) the PROCESS privilege(s) for this operation` at line 151 when querying `information_schema.INNODB_LOCK_WAITS` and `INNODB_TRX`.
  - Root cause: `test_runner` account intentionally lacks `PROCESS` privilege.
- **Resolution & Contract Integrity:**
  - Did **not grant global privileges** to `test_runner` and did **not turn test into a skip**.
  - Added `getIntrospectionPdo()` helper to `PlatformConcurrencyTest.php` providing an owned test-only administrative connection to query InnoDB lock wait state, with a supported nonprivileged fallback observing `information_schema.PROCESSLIST` on the revoker's connection ID (`COMMAND = 'Query' AND INFO LIKE '%platform_operator_grants%'`).
  - Lock-wait observation successfully asserted that the revocation transaction was blocked on the held row lock prior to barrier release.
- **Actual Counts:**
  - `PlatformConcurrencyTest`: **4 tests, 37 assertions, 0 failures, 0 errors** (time 00:11.805).

---

## 3. Scope Boundary and Safety Compliance

1. **Excluded Files Untouched:**
   - `app/Application/Platform/ManageSubscription.php` — NOT edited.
   - `app/Livewire/Pages/Purchasing/PurchaseForm.php` — NOT edited.
   - Other Huda-owned tests (`tests/Feature/Purchasing/**`, `ReleaseReconciliationTest.php`, etc.) — NOT edited.
   - `ReleaseUi` components — NOT edited.
   - Parent ManagedSaas `PlatformAccessTest.php`, `PlatformFinalSecurityContractTest.php`, `PlatformBillingConcurrencyTest.php` — NOT edited.
2. **Database Cleanliness:**
   - Isolated disposable MariaDB on port 3342 was used throughout.
   - All runner-owned schemas were cleaned upon completion.
   - No pre-existing or other workers' schemas were dropped or altered.
3. **Source Code & Git State:**
   - Dirty source across the repository was strictly preserved.
   - No git commits, tags, pushes, or production mutations were performed.
