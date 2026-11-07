# AGY Final Harness & Git Release Review Evidence — 2026-11-07

**Review Sub-Agent**: AGY Review Agent
**Baseline HEAD**: `06e4f6d71b8dc3202f0708dff91a21b32a5afd33` (dirty working tree strictly preserved)
**Target Repository**: `/home/t3dev/.t3/projects/small-trader-platform/account`
**Execution Context**: Read-only review under authorized AGY release prep. No git staging/commits/branch/push performed. Zero source modifications. No schema/server stopped or dropped.

---

## 1. Executive Summary & Assessment

This audit evaluates the current dirty state across the repository, the hardened `ReleaseUi` test support harness (`tests/Support/ReleaseUi` and `tests/Unit/ReleaseUi`), the AGY test suite runtime/isolation fixes, unproven concurrency fallbacks, and establishes a clean, explicit-path Git commit inventory.

### Key Verdicts:
1. **ReleaseUi Harness Bugs Found**: Concrete failure modes identified in `serve.php` and `cleanup.php`, including a fatal process identity verification failure under versioned PHP binaries (`php8.4`), an orphaned schema/process leak on early setup failure, pointer deadlock upon crashed supervisors, and `stop.signal` exclusive creation collision.
2. **AGY Runtime Contract & Isolation Fixes Qualified**: Process isolation attributes (`RunTestsInSeparateProcesses`, `PreserveGlobalState(false)`) resolve parent memory accumulation without weakening 128 MiB production safety caps; MariaDB sentinel test refactored to use `DisposableMariaDbSchema` without raw PDO drops; awk binary resolution in synthetic deployment fixtures provides robust portability.
3. **PlatformConcurrency Fallback Assessment**: **UNPROVEN (NOT PASSED)**. The unprivileged `PROCESSLIST` fallback is subject to timing races and does not guarantee lock-wait observation.
4. **Durable Purchase Retries**: Confirmed separately in progress under Huda.
5. **Artifact Hygiene**: Identified redundant root `agy-runtime-contract-fixes.md` (duplicate of canonical file in `docs/evidence/release-candidate-20261107/`) and large transient log files that should not be committed to Git.

---

## 2. ReleaseUi Support Harness Review (`tests/Support/ReleaseUi` & `tests/Unit/ReleaseUi`)

A thorough, line-by-line inspection of `cleanup.php`, `serve.php`, `ReleaseUiSession.php`, `ReleaseUiFixtures.php`, and `ReleaseUiHarnessTest.php` reveals the following concrete bugs and ownership flaws:

### Bug 1: Fatal Process Identity Mismatch Under Versioned PHP CLI (`serve.php`)
- **Location**: `tests/Support/ReleaseUi/serve.php:112-115` vs `ReleaseUiSession.php:124-133`
- **Mechanism**:
  - `serve.php` starts the web server subprocess and verifies it via:
    ```php
    $serverPid = $serverProcess->getPid();
    if ($serverPid === null || ! ReleaseUiSession::verifyProcessIdentity($serverPid, ['php', '-S', "127.0.0.1:{$port}"])) {
        $serverProcess->stop(1);
        throw new RuntimeException('Failed to verify started web server process identity.');
    }
    ```
  - `ReleaseUiSession::verifyProcessIdentity()` normalizes argument 0 using `PHP_BINARY`:
    ```php
    $args[0] = $executable === realpath(PHP_BINARY) ? basename(PHP_BINARY) : basename($args[0]);
    foreach ($expectedTokens as $token) {
        if (! in_array($token, $args, true)) {
            return false;
        }
    }
    ```
  - In environments where PHP runs as `/usr/bin/php8.4` (the active environment), `basename(PHP_BINARY)` resolves to `'php8.4'`.
  - `$args[0]` is overwritten with `'php8.4'`.
  - The expected token array hardcodes `'php'`. `in_array('php', $args, true)` returns `false`.
- **Empirical Validation**: Executing `verifyProcessIdentity()` on a Symfony Process running `php8.4 -S 127.0.0.1:9876` fails with `false`.
- **Impact**: `serve.php` crashes immediately on server startup with `RuntimeException: Failed to verify started web server process identity.` whenever run on a host with a versioned PHP executable.

### Bug 2: Resource and Process Leak on Early Failure (`serve.php`)
- **Location**: `tests/Support/ReleaseUi/serve.php:23-145`
- **Mechanism**:
  - `serve.php` executes initial setup procedurally outside of any `try ... finally` block:
    1. Line 27: Creates schema `$schema = DisposableMariaDbSchema::create();`
    2. Line 33: Migrates schema `$schema->migrate();`
    3. Line 66: Seeds fixtures `$seedProcess->mustRun();`
    4. Line 106: Starts background server `$serverProcess->start();`
    5. Line 129: Writes state file and session pointer `writePointer()`.
    6. Line 146: Enters `try { while (...) } finally { $serverProcess->stop(); $schema->drop(); ... }`.
  - If any failure occurs during Steps 2 through 8 (such as Bug 1 at line 114, fixture seeding errors, port binding timeouts, or pointer file collisions in Bug 3), execution aborts before line 146.
- **Impact**:
  - The created MariaDB database `accounting_p8_tmp_*` is never dropped and is permanently orphaned in the database server.
  - If failure occurs after line 106, the spawned PHP built-in server process is never stopped and remains running in the background as an orphaned daemon holding the port.

### Bug 3: Pointer File Deadlock Upon Previous Supervisor Crash
- **Location**: `ReleaseUiSession.php:61-75` vs `cleanup.php:23-28`
- **Mechanism**:
  - `writePointer()` invokes `createExclusiveFile()`, opening the pointer file with `fopen($path, 'x')` (O_EXCL).
  - If a previous `serve.php` process was killed ungracefully (SIGKILL, host reboot, out-of-memory killer, or unhandled exception), the pointer file `/tmp/release_ui_pointer_<uid>.json` remains on disk.
  - Subsequent invocations of `serve.php` fail with `RuntimeException("Refusing to replace existing file: ...")`.
  - When an operator runs `php tests/Support/ReleaseUi/cleanup.php` to clean up, `cleanup.php` verifies the owner PID. Because the owner process is dead, `verifyProcessIdentity()` returns `false`, causing `cleanup.php` to throw:
    `RuntimeException('Session ownership could not be verified; state preserved for inspection.')`
- **Impact**: Complete deadlock: `cleanup.php` refuses to remove the pointer file because the owner PID is dead, while `serve.php` refuses to overwrite it because the file exists. Manual file removal (`rm /tmp/release_ui_pointer_*.json`) is required.

### Bug 4: Non-Idempotent Stop Signal Writing in `cleanup.php`
- **Location**: `tests/Support/ReleaseUi/cleanup.php:30` vs `ReleaseUiSession.php:33-40`
- **Mechanism**:
  - `cleanup.php` writes the stop signal via `ReleaseUiSession::writeSecureFile($stateDir, 'stop.signal', $token)`.
  - `writeSecureFile` uses `createExclusiveFile` (`fopen('x')`).
  - If `cleanup.php` times out waiting for supervisor shutdown (e.g., if database dropping takes >10 seconds) or is interrupted and immediately re-executed, `writeSecureFile` throws:
    `RuntimeException: Refusing to replace existing file: .../stop.signal`
- **Impact**: Repeated runs of `cleanup.php` fail immediately with an unhandled exception rather than re-checking or updating the existing signal.

### Bug 5: Path Canonicalization and Token Fragility in `cleanup.php`
- **Location**: `tests/Support/ReleaseUi/cleanup.php:19-25`
- **Mechanism**:
  - `cleanup.php` validates process working directory via:
    `$cwd = @readlink("/proc/{$ownerPid}/cwd"); if ($cwd !== dirname(__DIR__, 3))`
  - On systems with symlinked project directories, `/proc/{pid}/cwd` evaluates to the resolved canonical path, whereas `dirname(__DIR__, 3)` may contain unresolved directory symlinks. Both sides must be wrapped in `realpath()`.
  - Furthermore, `verifyProcessIdentity` requires exact match for `[basename(PHP_BINARY), 'tests/Support/ReleaseUi/serve.php']`. If the server was launched via `./tests/Support/ReleaseUi/serve.php` or a relative path from another directory, strict string matching fails.

### Bug 6: Exception in Database Drop Skips Pointer and State Cleanup
- **Location**: `tests/Support/ReleaseUi/serve.php:165-171`
- **Mechanism**:
  - In `serve.php` `finally`:
    ```php
    if ($serverProcess->isRunning()) { $serverProcess->stop(2, SIGTERM); }
    $schema->drop();
    ReleaseUiSession::cleanupStateDir($stateDir, $pointerFile);
    ```
  - If `$schema->drop()` throws an exception (e.g. MariaDB socket closes or database connection drops during shutdown), execution halts before `ReleaseUiSession::cleanupStateDir()` is called.
- **Impact**: The state directory and pointer file remain permanently stranded on disk.

---

## 3. Review of AGY Export Isolation, Sentinel, and Deployment Fixes

### A. CompanyDataExport Process Isolation
- **Files**:
  - `tests/Feature/CompanyDataExport/CompanyDataExportLimitsTest.php`
  - `tests/Feature/CompanyDataExport/CompanyDataExportTest.php`
  - `tests/Feature/CompanyDataExport/ExportReauthenticationTest.php`
- **Changes Applied**: Added PHPUnit attributes `#[RunTestsInSeparateProcesses]` and `#[PreserveGlobalState(false)]`.
- **Weakening Analysis**: **NO WEAKENING**.
  - Production logic in `CompanyDataExportService` was completely untouched; the 128 MiB memory safety cap (`HARD_MAX_MEMORY_BYTES = 134_217_728`) remains strictly enforced.
  - The root cause in the full test suite was process-level heap accumulation: running ~4000 prior tests pushed PHP CLI resident memory to ~314 MiB, causing export tests to hit the memory ceiling prematurely before asserting row and compressed/uncompressed byte limits.
  - Running each test in an isolated process starts with ~24.5 MB heap, allowing row limits (`row_limit_exceeded`), byte limits (`byte_limit_exceeded`), memory limit (`memory_limit_exceeded`), and timeout limit (`execution_timeout`) to be genuinely and independently verified.
- **Portability Analysis**: **HIGHLY PORTABLE**.
  - Standard native PHPUnit 10+ / 11 / 12 attributes. Compatible across all PHP 8.4 CLI platforms without requiring `pcntl` extensions or vendor modifications.

### B. Disposable MariaDB Sentinel Test
- **File**: `tests/Support/DisposableMariaDbSchemaTest.php:187-211`
- **Changes Applied**: Replaced raw SQL execution `CREATE DATABASE accounting_p8_sentinel_*` and `DROP DATABASE IF EXISTS` with `$sentinel = DisposableMariaDbSchema::create()` and `$sentinel->drop()`.
- **Weakening Analysis**: **NO WEAKENING**.
  - The test verifies that creating and dropping a disposable schema does not drop or affect any independent test schema (`sentinel_test` table record `MUST_PRESERVE` remains intact).
  - The change eliminates raw unmanaged database creation and respects the security boundary: `test_runner` account privileges are intentionally restricted to `accounting_p8_tmp_%` schemas.
- **Portability Analysis**: **PORTABLE**.
  - Directly matches the documented testing environment on loopback port 3342.

### C. Deployment Script Contract Test Portability
- **File**: `tests/Unit/ProductExperience/DeploymentScriptContractTest.php`
- **Changes Applied**: Added `resolveAwkBinary()` searching candidates `['gawk', 'mawk', 'awk']` with `"BEGIN { print 1 }"`, symlinking/wrapping in `$fixtureDir/bin/awk`, and prepending `$unixFixtureDir/bin` to `PATH`.
- **Weakening Analysis**: **NO WEAKENING**.
  - `bin/deploy.sh` was completely untouched; it strictly enforces all SHA256 checksums, CloudLinux PHP 8.4 handler verification, and PWA manifest validations via awk.
- **Portability Analysis**: **HIGHLY PORTABLE**.
  - Resolves dangling `/usr/bin/awk` symlinks on Debian/Ubuntu systems where only `mawk` or `gawk` is installed without default alternative configuration. Fallback wrapper script handles systems that restrict symlinks.

---

## 4. Status of Concurrency and Purchasing Fixes

### A. Managed SaaS PlatformConcurrency Fallback
- **Status**: **UNPROVEN (NOT ASSESSED AS PASSED)**
- **Findings**:
  - `tests/Feature/ManagedSaas/PlatformConcurrencyTest.php` lines 66-85 add `getIntrospectionPdo()`, and line 176 adds a fallback:
    ```php
    $waiting = (int) DB::selectOne(
        "SELECT COUNT(*) AS waiting FROM information_schema.PROCESSLIST WHERE ID = ? AND COMMAND = 'Query' AND INFO LIKE '%platform_operator_grants%'",
        [$connection]
    )->waiting > 0;
    ```
  - When running under unprivileged test accounts (`test_runner` lacking `PROCESS` privilege), querying `PROCESSLIST` only shows queries from the current user.
  - Furthermore, `COMMAND = 'Query'` does not prove that the transaction is blocked on an InnoDB lock wait; it only shows that a query is in progress. This introduces a race condition where the query may start but not yet hold or wait on the row lock, leading to flaky assertions under varying load.
  - This fallback remains unproven and should not be assessed as passed.

### B. Durable Purchase Original-Snapshot Retry
- **Status**: **IN PROGRESS (Separately handled by Huda)**
- **Findings**: Work is ongoing in purchasing domain actions and `app/Livewire/Pages/Purchasing/PurchaseForm.php`. Excluded from this review scope as instructed.

---

## 5. Artifact Hygiene & Sensitive Data Audit

### A. Redundant Root Artifact
- **File**: `/home/t3dev/.t3/projects/small-trader-platform/account/agy-runtime-contract-fixes.md`
- **Status**: **REDUNDANT DUPLICATE**
- **Evidence**: A byte-for-byte diff between root `agy-runtime-contract-fixes.md` and `docs/evidence/release-candidate-20261107/agy-runtime-contract-fixes.md` shows zero differences. The root copy was created mistakenly at the workspace root and should not be committed to Git. The canonical document resides under `docs/evidence/release-candidate-20261107/`.

### B. Transient Log Files (Exclude from Commits)
The following files in `docs/evidence/release-candidate-20261107/` are raw execution transcripts and should be kept untracked:
- `huda-runtime-full-php-suite.log` (45.7 KB) — Raw verbose PHPUnit terminal transcript.
- `huda-runtime-failure-index.log` (28.5 KB) — Raw stack trace dump.
- `agy-build-audit.md` / `agy-security-package-diff.log` / `huda-migration-qualification-tests.log` — Transient build logs.
- Note: Zero live credentials or secrets are present. All referenced credentials in documentation and logs are synthetic test configurations (`test_runner` on port 3342 with empty password).

---

## 6. Coherent Git Commit Inventory & Grouping

The 236 dirty and untracked entries across the repository are categorized into 7 coherent, logically decoupled commits, each paired with corresponding tests and documentation:

### Group 1: Core Trade Document Corrections, Reversals, and Void Actions
- **Scope**: Void actions for purchases and returns, draft deletions, domain exceptions, migrations, correction preview components, and translation keys.
- **Source Paths**:
  - `app/Actions/Purchasing/VoidPurchaseAction.php`
  - `app/Actions/Purchasing/VoidPurchaseReturnAction.php`
  - `app/Actions/Sales/DeleteReturnDraftAction.php`
  - `app/Domain/Purchasing/Exceptions/*`
  - `app/Services/Corrections/*`
  - `app/Services/Purchasing/PurchaseVoidCapability.php`
  - `app/Services/Purchasing/PurchaseVoidIntegrity.php`
  - `app/Services/Purchasing/PurchaseVoidScope.php`
  - `app/Services/Purchasing/PurchaseReturnVoidCapability.php`
  - `app/Services/Purchasing/PurchaseReturnVoidScope.php`
  - `app/Services/Reviews/*`
  - `app/Livewire/Concerns/CorrectsTradeDocument.php`
  - `app/Livewire/Concerns/ReviewsStandaloneReversal.php`
  - `database/migrations/2026_11_07_000001_add_void_columns_to_purchase_returns_and_permissions.php`
  - `database/migrations/2026_11_07_000002_add_purchase_void_business_dates.php`
  - `database/migrations/2026_11_07_000003_create_purchase_correction_receipts.php`
  - `database/migrations/2026_11_07_000004_create_trade_correction_receipts.php`
  - `database/migrations/2026_11_07_000005_create_purchase_confirmation_receipts.php`
  - `resources/lang/ar/corrections.php`, `resources/lang/en/corrections.php`
  - `resources/lang/ar/standalone.php`, `resources/lang/en/standalone.php`
  - `resources/views/components/correction-preview.blade.php`
  - `resources/views/components/correction-shortcuts.blade.php`
  - `resources/views/components/standalone-reversal-review.blade.php`
  - `docs/CORRECTION_IMPLEMENTATION_20261107.md`
- **Tests**:
  - `tests/Support/Corrections/*`
  - `tests/Feature/Purchasing/*`
  - `tests/Feature/Phase4/StandaloneReceiptReviewTest.php`
  - `tests/Feature/Phase7/StandaloneReversalReviewTest.php`
  - `tests/Feature/Phase7/Phase7Correction01Test.php`

### Group 2: Purchasing Immediate Settlement & Confirmation Receipts
- **Scope**: Immediate purchase settlement commands, confirmation receipts, draft snapshots, payables reconciliation, and stock provenance.
- **Source Paths**:
  - `app/Application/Commands/ImmediatePurchaseCommands.php`
  - `app/Services/Purchasing/PurchaseDraftSnapshot.php`
  - `app/Services/Purchasing/PurchaseFormConfirmation.php`
  - `app/Services/Purchasing/PayablesReconciliationService.php`
  - `app/Services/Purchasing/PurchaseDraftBuilder.php`
  - `app/Services/Purchasing/PurchasePayableAsOf.php`
  - `app/Services/Purchasing/PurchasePostingCommandBuilder.php`
  - `app/Services/Purchasing/PurchaseReadModel.php`
  - `app/Services/Purchasing/PurchaseReturnPostedIntegrityValidator.php`
  - `app/Services/Purchasing/PurchaseReturnPostingCommandBuilder.php`
  - `app/Services/Purchasing/PurchaseReturnReadModel.php`
  - `app/Services/Purchasing/PurchaseReturnStockProvenance.php`
  - `app/Services/Purchasing/PurchaseStockProvenance.php`
  - `app/Models/Purchase.php`, `PurchaseLineLot.php`, `PurchaseReturn.php`
  - `resources/lang/ar/purchase_settlement.php`, `resources/lang/en/purchase_settlement.php`
  - `resources/views/livewire/partials/immediate-purchase-review.blade.php`
  - `resources/views/livewire/partials/immediate-purchase-settlement.blade.php`
- **Tests**:
  - `tests/Feature/BusinessWorkflow/QuickProductReceiveTest.php`
  - `tests/Feature/Phase5E/VendorPaymentAllocationStatusRegressionTest.php`
  - `tests/Feature/Phase5E/VendorPaymentFinalInputChronologyRegressionTest.php`
  - `tests/Feature/Phase5E/VendorPaymentUiTest.php`
  - `tests/Feature/Phase7/Phase7PaymentWorkflowTest.php`

### Group 3: Mobile Shell, Responsive UI Redesign & Component System
- **Scope**: Mobile responsive layout, touch targets, accessibility headings, inline panels, Livewire views redesign across accounting modules.
- **Source Paths**:
  - `resources/css/app.css`
  - `resources/css/business-dashboard.css`
  - `resources/js/mobile-shell.js`
  - `resources/views/components/inline-panel.blade.php`
  - `resources/views/components/page-heading.blade.php`
  - `resources/views/livewire/pages/*` (140+ views in money, payroll, products, purchasing, reporting, sales, settings)
  - `resources/views/platform/*`
  - `app/Livewire/Pages/*`
  - `routes/web.php`
  - `docs/UI_REDESIGN_20261107.md`
- **Tests**:
  - `tests/JavaScript/mobile-shell.test.mjs`
  - `tests/Unit/ProductExperience/ShellMenuAndInlineComponentTest.php`
  - `tests/Feature/Refinement/HumanWorkflowRefinementTest.php`
  - `tests/Feature/Phase6/MoneyUiTest.php`

### Group 4: Runtime Test Suite Hardening, Isolation, and Portability
- **Scope**: Company data export process isolation attributes, MariaDB disposable schema sentinel test refactor, deployment script contract test awk discovery.
- **Source Paths**:
  - `tests/Feature/CompanyDataExport/CompanyDataExportLimitsTest.php`
  - `tests/Feature/CompanyDataExport/CompanyDataExportTest.php`
  - `tests/Feature/CompanyDataExport/ExportReauthenticationTest.php`
  - `tests/Feature/Phase10/BarcodeCertificationTest.php`
  - `tests/Support/DisposableMariaDbSchemaTest.php`
  - `tests/Unit/ProductExperience/DeploymentScriptContractTest.php`
  - `docs/evidence/release-candidate-20261107/agy-runtime-contract-fixes.md`

### Group 5: Release UI Testing Support Harness & IPC Supervisor
- **Scope**: Foreground supervised web server and schema lifecycle harness with private 0700 state directory and 0600 token authorization.
- **Source Paths**:
  - `tests/Support/ReleaseUi/ReleaseUiSession.php`
  - `tests/Support/ReleaseUi/serve.php`
  - `tests/Support/ReleaseUi/cleanup.php`
  - `tests/Support/ReleaseUi/ReleaseUiFixtures.php`
  - `docs/evidence/release-candidate-20261107/agy-ui-harness-hardening.md`
- **Tests**:
  - `tests/Unit/ReleaseUi/ReleaseUiHarnessTest.php`

### Group 6: Dependency Security Updates & Licensing Documentation
- **Scope**: Symfony component security patches (HTTP foundation, kernel, routing, serializer, YAML) and third-party notices.
- **Source Paths**:
  - `composer.lock`
  - `THIRD_PARTY_NOTICES.md`

### Group 7: Release Candidate Verification & Audit Evidence
- **Scope**: Verification records, audit summaries, and final review documentation.
- **Source Paths**:
  - `docs/evidence/release-candidate-20261107/agy-build-audit.md`
  - `docs/evidence/release-candidate-20261107/agy-independent-final.md`
  - `docs/evidence/release-candidate-20261107/agy-security-plan.md`
  - `docs/evidence/release-candidate-20261107/agy-visual-final.md`
  - `docs/evidence/release-candidate-20261107/parent-final-followups.md`
  - `docs/evidence/release-candidate-20261107/agy-final-harness-git-review.md`

---

## 7. Fresh-Clone Execution Requirements

To clone and run the test suite and release build cleanly on a fresh workstation:

1. **System Binaries & Tools**:
   - PHP 8.4 CLI (`php`) with extensions: `pdo`, `pdo_mysql`, `zip`, `bcmath`, `mbstring`, `pcntl`, `posix`.
   - Node.js (v20+ / v22+) & npm.
   - Composer (v2.8+).
   - `awk` (either GNU Awk `gawk` or `mawk`).
   - Python 3 (`python3`).

2. **Python Barcode Certification Dependencies**:
   - Required by `tests/Support/Phase10/decode-barcodes.py` and `tests/Feature/Phase10/BarcodeCertificationTest.php`:
     ```bash
     pip3 install --user pymupdf zxing-cpp pillow
     ```
   - If not installed, Phase 10 barcode certification tests will fail with `ImportError`.

3. **Isolated Test Database Configuration**:
   - Disposable test runner requires a dedicated local MariaDB instance:
     ```bash
     # Listening on 127.0.0.1:3342
     # User: test_runner (password: empty)
     # Grants: ALL PRIVILEGES ON `accounting_p8_tmp_%`.* TO 'test_runner'@'127.0.0.1';
     export PHASE8_ALLOW_DISPOSABLE_DB=1
     export PHASE8_TEST_DB_HOST=127.0.0.1
     export PHASE8_TEST_DB_PORT=3342
     export PHASE8_TEST_DB_USERNAME=test_runner
     export PHASE8_TEST_DB_EMPTY_PASSWORD=1
     ```

4. **Asset Build**:
   ```bash
   composer install --no-interaction
   npm ci
   npm run build
   ```
