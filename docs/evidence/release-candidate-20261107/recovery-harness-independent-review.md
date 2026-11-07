# Release UI Test Harness Recovery & Hardening Independent Review

**Date**: 2026-11-07
**Review Agent**: Independent Acceptance Review Sub-Agent
**Model Tier**: Gemini 3.8 Flash (High)
**Execution Mode**: Single-agent evaluation (zero nested child delegates)
**Baseline HEAD SHA**: `06e4f6d71b8dc3202f0708dff91a21b32a5afd33` (extensively dirty working tree preserved)
**Target Project**: `/home/t3dev/.t3/projects/small-trader-platform/account`
**Write Policy**: Strictly READ-ONLY on all application source, migrations, and test files; exclusive permitted write target: `docs/evidence/release-candidate-20261107/recovery-harness-independent-review.md`.

---

## 1. Executive Summary & Verification Context

An independent acceptance review was conducted on the Release UI test support harness (`tests/Support/ReleaseUi/*`) and its corresponding unit test suite (`tests/Unit/ReleaseUi/ReleaseUiHarnessTest.php`), evaluating the implementation against:
- `docs/evidence/release-candidate-20261107/agy-final-harness-git-review.md`
- `docs/evidence/release-candidate-20261107/recovery-ui-harness.md`
- Six specific technical observations raised by the parent coordinator.

### Test Execution Verification
The designated disposable test runner was executed against the local testing MariaDB instance on loopback port 3342:
```bash
APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- tests/Unit/ReleaseUi/ReleaseUiHarnessTest.php
```
**Result**:
- **Tests**: 24 executed, 69 assertions, 0 errors, 0 failures.
- **Duration**: 5.51 seconds.
- **Disposable Schema**: Owned primary test schema (`accounting_p8_tmp_527b060ee81e`) created and cleanly dropped. Zero residual schemas, zero background processes leaked.

### High-Level Review Findings
1. **Financial Correctness**: **UNCOMPROMISED**. The Release UI test harness resides entirely within test support infrastructure (`tests/Support/ReleaseUi`), operates solely on isolated loopback port 3342 targeting ephemeral `accounting_p8_tmp_*` schemas, and has zero execution path in production or runtime accounting ledger transactions.
2. **Schema-Name-Only Adoption Invariant**: **STRICTLY SATISFIED**. No code in `ReleaseUiSession`, `serve.php`, or `cleanup.php` ever connects to, adopts, or drops a database schema solely by reading its name from an untrusted file or pointer. Drop authority is restricted exclusively to the in-memory `DisposableMariaDbSchema` object owned by the active supervisor process.
3. **Concrete Material Bugs vs. Dev-Only Limitations**:
   - **Concrete Material Bug (High)**: Asynchronous signal handlers throwing exceptions during `finally` execution in `serve.php` can interrupt teardown, orphaning background web servers, database schemas, and state directories.
   - **Concrete Operational Bug (Medium)**: Swallowing `$schema->drop()` exceptions in `serve.php` while cleaning up state files and printing `Release UI session cleanly terminated` masks orphaned schemas from calling CI runners and scripts.
   - **Concurrency Edge-Case (Medium)**: TOCTOU race in `safeWritePointer` where an uncoordinated second startup can unlink a live pointer file without locking or inode verification.
   - **Acceptable Dev-Only Trade-offs (Low)**: Treating unverifiable/dead owners as safe to reclaim (essential for recovering from crashed supervisor sessions without manual operator intervention); overly broad `ownerProcessTokens` which fails closed by refusing replacement.
   - **Test Gap (Moderate)**: Integration smoke test verifies TCP socket connectivity but performs zero HTTP GET/POST requests and does not exercise the seeded business fixtures against trade document recovery actions.

---

## 2. Detailed Technical Assessment of the 6 Observations

### Observation 1: `safeWritePointer` Unlinks After Read Without Locking/Inode Check
- **Source Location**: [`ReleaseUiSession.php:73-124`](file:///home/t3dev/.t3/projects/small-trader-platform/account/tests/Support/ReleaseUi/ReleaseUiSession.php#L73-L124)
- **Mechanism**:
  1. `safeWritePointer()` checks `is_file($pointer)`.
  2. It reads and parses the JSON via `readPointer($pointer)` and inspects the owner PID.
  3. If `$isAlive` evaluates to `false`, it executes `stat($pointer)`, verifies `uid === posix_getuid()`, and calls `@unlink($pointer)`.
  4. It then delegates to `self::writePointer($data)`, which calls `createExclusiveFile()` using `fopen($pointer, 'x')`.
- **Failure Mode & Race Condition**:
  If two invocations of `serve.php` (e.g., parallel automated test runners or concurrent developer commands under the same UID) execute `safeWritePointer()` at the same time:
  - Process A checks stale pointer $\rightarrow$ deems owner dead $\rightarrow$ unlinks $\rightarrow$ calls `createExclusiveFile('x')` $\rightarrow$ succeeds and writes Process A's live pointer.
  - Process B, having previously read the stale pointer before Process A wrote its new pointer, now proceeds to `@unlink($pointer)`. It unlinks Process A's newly written **live** pointer file without checking if the inode or mtime changed.
  - Process B then calls `createExclusiveFile('x')` $\rightarrow$ succeeds and writes Process B's pointer.
  - Process A is now running as an active supervisor without a valid pointer, and `cleanup.php` will only manage Process B, leaving Process A orphaned.
- **Classification**: **Concrete Concurrency Flaw (Medium Severity in Test Harness / Dev-Only)**.
- **Actionable Parent Fix**:
  Surround the read-verify-unlink-write sequence with an advisory lock (`flock`) on a dedicated lockfile (e.g., `sys_get_temp_dir() . '/release_ui_pointer_' . posix_getuid() . '.lock'`), or verify that the file inode (`$stat['ino']`) has not changed before issuing `unlink`.

---

### Observation 2: Treats Malformed or Unverifiable Owner as Dead
- **Source Location**: [`ReleaseUiSession.php:79-114`](file:///home/t3dev/.t3/projects/small-trader-platform/account/tests/Support/ReleaseUi/ReleaseUiSession.php#L79-L114)
- **Mechanism**:
  In `safeWritePointer()`:
  - If `$existing === null` (corrupt JSON, empty file, or interrupted write), or `$existingPid <= 1`, or PID start-time mismatches (PID reuse), or `verifyProcessIdentity()` returns `false`:
  - `$isAlive` remains `false`.
  - The function does not throw; it unlinks the pointer file and proceeds to write the new pointer.
- **Analysis vs. `cleanup.php`**:
  - `cleanup.php` explicitly **fails closed** (`throw new RuntimeException('Session ownership could not be verified; state preserved for inspection.')`) because `cleanup.php` must never send kill signals to arbitrary processes.
  - In contrast, `safeWritePointer()` is designed specifically to recover from abandoned or crashed supervisor sessions (SIGKILL, power failure, OOM killer). In those crash scenarios, `/proc/[pid]` no longer exists, so `verifyProcessIdentity()` will **always** return `false`.
  - If `safeWritePointer()` failed closed on unverifiable owners, any crashed session would permanently block subsequent runs of `serve.php`, necessitating manual `rm /tmp/release_ui_pointer_*.json`.
  - **Blast Radius**: Reclaiming the pointer file **only** removes the file in `/tmp`. It never executes `DROP DATABASE` and never deletes the directory in `/tmp/release_ui_*`.
- **Classification**: **Acceptable Dev-Only Limitation (Intentional Crash Recovery Design)**.
- **Recommendation**: Retain current recovery semantics; add a descriptive warning log if unlinking a pointer with malformed JSON or dead PID.

---

### Observation 3: `ownerProcessTokens` Only Checking PHP Binary Name
- **Source Location**: [`ReleaseUiSession.php:306-312`](file:///home/t3dev/.t3/projects/small-trader-platform/account/tests/Support/ReleaseUi/ReleaseUiSession.php#L306-L312) vs. [`cleanup.php:23-28`](file:///home/t3dev/.t3/projects/small-trader-platform/account/tests/Support/ReleaseUi/cleanup.php#L23-L28)
- **Mechanism**:
  ```php
  public static function ownerProcessTokens(): array
  {
      return [basename(PHP_BINARY)];
  }
  ```
  `safeWritePointer()` passes `ownerProcessTokens()` to `verifyProcessIdentity()`.
  When tokens are limited to `[basename(PHP_BINARY)]` (e.g. `['php8.4']`):
  - Any PHP process running under the same UID (e.g., `php artisan ...`, `composer install`, `phpstan`) matches argument 0.
  - If a supervisor crashed and its PID was reused by another PHP command, and start-time checking was unavailable (`/proc` unmounted or start-time unrecorded), `safeWritePointer()` treats the unrelated PHP process as the active supervisor and throws `Refusing to replace active session pointer: owner PID ... is still alive`.
- **Analysis**:
  - This behavior fails **closed** (refuses to clobber).
  - However, it is inconsistent with `cleanup.php`, which rigorously requires matching both `basename(PHP_BINARY)` AND `tests/Support/ReleaseUi/serve.php`, along with process working directory matching `canonicalPath(dirname(__DIR__, 3))`.
- **Classification**: **Low-Severity Precision Limitation (Dev-Only)**.
- **Actionable Parent Fix**:
  Update `ownerProcessTokens()` to include the script identifier:
  ```php
  public static function ownerProcessTokens(): array
  {
      return [basename(PHP_BINARY), 'tests/Support/ReleaseUi/serve.php'];
  }
  ```
  Because `verifyProcessIdentity()` already resolves path tokens against `/proc/[pid]/cwd` and canonicalizes symlinks, this ensures exact matching without false positives.

---

### Observation 4: Database Drop Exceptions Swallow Error, Delete State, and Report Clean Termination
- **Source Location**: [`serve.php:191-212`](file:///home/t3dev/.t3/projects/small-trader-platform/account/tests/Support/ReleaseUi/serve.php#L191-L212) and [`cleanup.php:59-67`](file:///home/t3dev/.t3/projects/small-trader-platform/account/tests/Support/ReleaseUi/cleanup.php#L59-L67)
- **Mechanism**:
  In `serve.php` `finally`:
  ```php
  if ($schema !== null) {
      try {
          $schema->drop();
      } catch (\Throwable $dropError) {
          fwrite(STDERR, "WARNING: Schema drop failed: {$dropError->getMessage()}\n");
          fwrite(STDERR, "Schema {$schemaName} may be orphaned — manual cleanup required.\n");
      }
  }

  if ($stateDir !== null) {
      try {
          ReleaseUiSession::cleanupStateDir($stateDir, $pointerFile);
      } catch (\Throwable $cleanupError) { ... }
  }

  fwrite(STDOUT, "Release UI session cleanly terminated.\n");
  ```
- **Consequences**:
  1. If `$schema->drop()` fails (e.g., MariaDB connection timeout, deadlock, or daemon hiccup), the error is caught and printed only to `STDERR`.
  2. `cleanupStateDir()` immediately deletes the state directory, erasing `state.json` and diagnostic records of which schema, port, and credentials were in use.
  3. `serve.php` prints `Release UI session cleanly terminated.` to `STDOUT` and exits with exit code `0`.
  4. In `cleanup.php`, the polling loop checks if `$stateDir` and `$pointerFile` have been deleted. Seeing they are gone, `cleanup.php` prints `Supervisor completed owned session cleanup.` and exits with code `0`.
  5. As a result, both supervisor and cleanup script report 100% clean success while an orphaned database remains allocated in MariaDB.
- **Classification**: **Concrete Operational Defect (Medium Severity in Test Harness)**.
- **Mitigating Factors**:
  - The schema name strictly adheres to `accounting_p8_tmp_*`, so runner sweeps (`run-phase8-disposable.php`) will eventually garbage-collect it.
  - Production databases are unaffected (restricted to port 3342 `test_runner`).
- **Actionable Parent Fix**:
  - If `$schema->drop()` throws an exception, record a `drop_failure.log` in `$stateDir` and remove ONLY the `$pointerFile` (so subsequent runs are not deadlocked), while preserving `$stateDir` for inspection.
  - Do NOT print `cleanly terminated` to STDOUT; emit `TERMINATED_WITH_ERRORS` and terminate with a non-zero exit code (e.g., `exit(1)` or rethrow in `finally`).

---

### Observation 5: Asynchronous Signal Exceptions During `finally` Could Interrupt Cleanup
- **Source Location**: [`serve.php:33-47`](file:///home/t3dev/.t3/projects/small-trader-platform/account/tests/Support/ReleaseUi/serve.php#L33-L47) and [`serve.php:173-213`](file:///home/t3dev/.t3/projects/small-trader-platform/account/tests/Support/ReleaseUi/serve.php#L173-L213)
- **Mechanism**:
  - `serve.php` enables asynchronous signal handling early:
    ```php
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$stopRequested) {
        $stopRequested = true;
        throw new RuntimeException('Supervisor received SIGTERM during execution.');
    });
    pcntl_signal(SIGINT, function () use (&$stopRequested) {
        $stopRequested = true;
        throw new RuntimeException('Supervisor received SIGINT during execution.');
    });
    ```
  - When a termination signal arrives, the closure throws a `RuntimeException`.
  - PHP unwinds the execution stack into the `finally` block.
  - Within `finally`:
    - `$serverProcess->stop(2, SIGTERM)` can block for up to 2 seconds.
    - `$schema->drop()` issues network I/O across TCP socket to MariaDB.
    - `ReleaseUiSession::cleanupStateDir()` iterates and unlinks filesystem paths.
  - **The Defect**: Because `pcntl_async_signals(true)` remains active and the signal handlers are never reset or masked, if an operator presses Ctrl+C twice, or if a process monitor issues a second SIGTERM/SIGINT during `finally`, the signal handler executes immediately and throws a second `RuntimeException`.
  - In PHP, throwing an unhandled exception inside a `finally` block **aborts execution of the finally block immediately**.
  - Any subsequent teardown steps (stopping the server process, dropping the MariaDB schema, or unlinking state files) are completely skipped.
- **Classification**: **Concrete Material Bug (High Severity within Test Support Lifecycle)**.
- **Actionable Parent Fix**:
  1. Never throw exceptions from asynchronous signal handlers in PHP. Instead, mutate a flag (`$stopRequested = true;`).
  2. At the entry of the `finally` block, explicitly mask or ignore termination signals so teardown is non-interruptible:
     ```php
     if (function_exists('pcntl_signal')) {
         pcntl_signal(SIGTERM, SIG_IGN);
         pcntl_signal(SIGINT, SIG_IGN);
     }
     ```

---

### Observation 6: Start/Stop Smoke Test Lacks HTTP Readiness and Fixture Recovery Flow Verification
- **Source Location**: [`tests/Unit/ReleaseUi/ReleaseUiHarnessTest.php:336-419`](file:///home/t3dev/.t3/projects/small-trader-platform/account/tests/Unit/ReleaseUi/ReleaseUiHarnessTest.php#L336-L419) and [`tests/Support/ReleaseUi/ReleaseUiFixtures.php`](file:///home/t3dev/.t3/projects/small-trader-platform/account/tests/Support/ReleaseUi/ReleaseUiFixtures.php)
- **Gaps Identified**:
  1. **Zero HTTP Protocol Verification**:
     In `test_integration_serve_starts_and_cleanup_stops_on_disposable_fixture`:
     ```php
     preg_match('/URL: http:\/\/127\.0\.0\.1:(\d+)/', $output, $portMatch);
     $port = (int) $portMatch[1];
     $sock = @fsockopen('127.0.0.1', $port);
     $this->assertIsResource($sock, "Web server not listening on port {$port}");
     fclose($sock);
     ```
     This only verifies that PHP's built-in web server opened a listening TCP socket. It never performs an HTTP request (e.g. `GET /login`). If the web application boots with a fatal error (e.g., missing Laravel encryption key, unreadable storage directory, broken database credentials in the child process, or missing session table), `fsockopen()` still succeeds, giving false confidence in readiness.
  2. **Zero Fixture Recovery Flow Exercised**:
     `ReleaseUiFixtures.php` meticulously provisions:
     - User, Company, Product, Vendor, Customer, Bank Account.
     - Purchase, VendorPayment, PurchaseReturn.
     - SalesInvoice, CustomerPayment, SalesReturn.
     - MoneyTransfer, Operating Expense.
     - Employee, EmployeeAdvance, SalaryEntry, SalaryPayment.
     Annotations in `ReleaseUiFixtures.php` explicitly note:
     `// 12. Vendor Payment against purchase (for standalone reversal test)`
     `// 17. Money Transfer between Cash and Bank (for standalone reversal test)`
     `// 18. Operating Expense (Electricity) (for standalone reversal test)`
     `// 19. Employee & Payroll (Advance, Salary Entry, Salary Payment for standalone reversal test)`
     However, not a single test in `ReleaseUiHarnessTest.php` passes these fixtures into any recovery or reversal action (e.g., `ReviewsStandaloneReversal`, `VoidPurchaseAction`, or `PostExpenseAction`).
- **Classification**: **Concrete Test Verification Gap (Moderate Severity)**.
- **Actionable Parent Fix**:
  1. In `ReleaseUiHarnessTest.php`: After verifying the TCP socket, issue an HTTP GET to `http://127.0.0.1:{$port}/login` using `file_get_contents` or `curl`, asserting an HTTP 200/302 response and HTML content.
  2. Add a focused unit or feature test verifying that `ReleaseUiFixtures::seed()` produces data structures compatible with the standalone reversal review components.

---

## 3. Summary Assessment Matrix for Parent Agent

| Observation | Area | Real Defect? | Severity | Risk Type | Parent Action Required |
| :--- | :--- | :---: | :---: | :--- | :--- |
| **1. `safeWritePointer` unlink race** | `ReleaseUiSession.php` | Yes | Medium | Concurrency / TOCTOU | Add file locking (`flock`) on dedicated lockfile or verify inode before unlinking. |
| **2. Treats unverifiable owner as dead** | `ReleaseUiSession.php` | No | Low | Dev-only design | Acceptable as designed; prevents unrecoverable pointer deadlock after crashes. |
| **3. `ownerProcessTokens` only PHP** | `ReleaseUiSession.php` | Yes (minor) | Low | Precision / Over-broad | Include `serve.php` in `ownerProcessTokens()`: `[basename(PHP_BINARY), 'tests/Support/ReleaseUi/serve.php']`. |
| **4. DB drop exceptions swallowed** | `serve.php` | Yes | Medium | Diagnostic / Logging | Preserve `state.json` on drop failure; emit failure status and exit non-zero. |
| **5. Async signal exceptions in `finally`** | `serve.php` | Yes | High | Process Lifecycle | Mask signals (`SIG_IGN`) inside `finally`; do not throw from async signal handlers. |
| **6. Smoke test lacks HTTP & recovery flows** | `ReleaseUiHarnessTest.php` | Yes | Moderate | Test Coverage Gap | Add real HTTP GET check on `$port` and test fixture compatibility with reversal actions. |

---

## 4. Compliance and Invariant Audit

1. **Read-Only Enforcement**: Verified that zero application source files, migrations, or test files were modified during this review. Working directory dirty state from baseline `06e4f6d71b8dc3202f0708dff91a21b32a5afd33` is preserved intact.
2. **Schema Safety**: The test execution operated exclusively on `127.0.0.1:3342` using the authorized `test_runner` account targeting disposable schema `accounting_p8_tmp_527b060ee81e`. No production databases, no port 3353 instances, and no sibling schemas were contacted or dropped.
3. **No Schema Adoption**: Verified that nowhere in the harness is a schema adopted merely by name from a file. Only the in-memory `DisposableMariaDbSchema` object retains drop privileges.
4. **No Child Delegates**: Work performed completely by the designated review sub-agent.
