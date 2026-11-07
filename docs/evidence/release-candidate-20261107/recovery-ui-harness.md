# Release UI Test Harness Recovery & Hardening Evidence

**Date**: 2026-11-07
**Sub-Agent**: Implementation Sub-Agent (AGY)
**Baseline HEAD SHA**: `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`
**Current Result SHA**: `06e4f6d71b8dc3202f0708dff91a21b32a5afd33` (dirty working tree preserved, no git staging/commits/push performed)
**Exclusive Edit Scope**:
- `tests/Support/ReleaseUi/ReleaseUiSession.php`
- `tests/Support/ReleaseUi/serve.php`
- `tests/Support/ReleaseUi/cleanup.php`
- `tests/Support/ReleaseUi/ReleaseUiFixtures.php`
- `tests/Unit/ReleaseUi/ReleaseUiHarnessTest.php`
- `docs/evidence/release-candidate-20261107/recovery-ui-harness.md`

---

## 1. Executive Summary

In accordance with release review instructions, an independent evaluation of the six defects documented in `agy-final-harness-git-review.md` was conducted against `tests/Support/ReleaseUi/*` and `tests/Unit/ReleaseUi/*`. Concrete defects were identified and resolved across process identity resolution, setup resource lifecycle management, safe pointer recovery with PID reuse prevention, idempotent signaling, canonical path resolution, and failure containment during database drops.

All test suites and static analysis tools passed cleanly:
- **PHPUnit**: 24 tests, 69 assertions, 0 failures, 0 errors (including end-to-end start/stop smoke on disposable MariaDB schema `accounting_p8_tmp_*`).
- **PHPStan**: 0 errors found across all `ReleaseUi` test support and unit test files.
- **Pint**: Code formatting verified and passed.

---

## 2. Independent Defect Assessment & Fixes Applied

### Defect 1: Versioned PHP Process Identity
- **Assessment**: In environments where the PHP CLI binary is versioned (e.g., `/usr/bin/php8.4`), `basename(PHP_BINARY)` resolves to `php8.4`. Hardcoding `'php'` causes process identity verification via `/proc/[pid]/cmdline` to fail.
- **Fix Applied**:
  - `serve.php` verifies the started web server process against `[basename(PHP_BINARY), '-S', "127.0.0.1:{$port}"]`.
  - `ReleaseUiSession::verifyProcessIdentity()` extracts the executable via `/proc/[pid]/exe` and builds a list of candidate representations (`basename($executable)`, `basename(PHP_BINARY)`, `PHP_BINARY`, etc.), ensuring versioned binaries match cleanly without false rejections.
  - Added unit test `test_process_identity_matches_versioned_php_binary`.

### Defect 2: Early Setup Resource Leaks
- **Assessment**: Procedural setup prior to entering the supervisor loop risked orphaning MariaDB schemas (`accounting_p8_tmp_*`), background server processes, and temporary state files if an unhandled exception occurred during schema migration, fixture seeding, port binding, or pointer creation.
- **Fix Applied**:
  - Pre-allocated resource tracker handles (`$schema`, `$serverProcess`, `$stateDir`, `$pointerFile`) before entering `try`.
  - Installed early `pcntl_signal` handlers for `SIGTERM` and `SIGINT` with `pcntl_async_signals(true)` so premature signals trigger immediate `finally` unwinding.
  - In `finally`, each teardown action (`$serverProcess->stop()`, `$schema->drop()`, `cleanupStateDir()`) is isolated in its own independent `try ... catch` block, guaranteeing that failures in earlier cleanup steps do not prevent subsequent teardown actions from executing.

### Defect 3: Safe Stale Pointer Recovery Without PID-Reuse / Schema-Name-Only Ownership Assumptions
- **Assessment**: When a previous `serve.php` supervisor process crashed or was killed with SIGKILL, the pointer file `/tmp/release_ui_pointer_<uid>.json` remained on disk. Two major hazards existed:
  1. If the operating system reused the dead supervisor's PID for an unrelated process, simple PID existence checks would misidentify the process as the active supervisor.
  2. If the pointer was stale, attempting to drop database schemas named in the pointer would violate schema ownership rules (adopting/dropping by name from an untrusted file).
- **Fix Applied**:
  - Added monotonic process start-time tracking: `ReleaseUiSession::getProcessStartTime(int $pid)` reads field 22 (starttime) from `/proc/[pid]/stat`.
  - Supervisor records `owner_start_time` in `state.json` and active pointer.
  - In `ReleaseUiSession::safeWritePointer()`, if a pointer file already exists, it verifies whether the recorded PID and recorded start time match. If the start time mismatches, PID reuse is recognized: the owner is dead, and the pointer is safely reclaimed.
  - Strict preservation of removed unsafe fallback: `safeWritePointer` **never** attempts to connect or drop schemas named in stale files. Only in-memory `DisposableMariaDbSchema` instances hold the capability to drop schemas. Stale state directories are preserved for post-mortem inspection, failing closed.
  - Added unit tests: `test_safe_write_pointer_detects_pid_reuse_when_start_time_mismatches` and `test_safe_write_pointer_refuses_live_owner_with_matching_start_time`.

### Defect 4: Idempotent Stop Signal
- **Assessment**: `cleanup.php` wrote `stop.signal` via exclusive file creation (`fopen('x')`). If `cleanup.php` timed out, was interrupted, or was run multiple times concurrently, subsequent executions failed with `RuntimeException: Refusing to replace existing file`.
- **Fix Applied**:
  - `ReleaseUiSession::writeStopSignal()` checks if `stop.signal` already exists with 0600 permissions and matching cryptographic session token. If matching, it succeeds idempotently without re-creating.
  - Race condition protection: `createExclusiveFile()` is wrapped in a collision catch block that re-verifies matching token before throwing.
  - Added unit tests: `test_stop_signal_is_idempotent_on_correct_token` and `test_stop_signal_refuses_mismatched_token`.

### Defect 5: Canonical Paths and Token Matching
- **Assessment**: On systems where project paths traverse symlinks, `/proc/[pid]/cwd` resolves to the canonical path, whereas relative paths or symlinked script names (`tests/Support/ReleaseUi/serve.php`) failed strict string matching. Furthermore, relative command-line arguments in `/proc/[pid]/cmdline` could fail to resolve if the verifying process was executed from a different working directory.
- **Fix Applied**:
  - `ReleaseUiSession::verifyProcessIdentity()` reads the target process's `/proc/[pid]/cwd`. Path-like tokens in `$args` that are relative are resolved against `/proc/[pid]/cwd` before canonicalization via `realpath()`.
  - Both caller tokens and target process arguments are normalized through canonical paths.
  - `cleanup.php` canonicalizes both `/proc/[pid]/cwd` and `dirname(__DIR__, 3)` via `ReleaseUiSession::canonicalPath()`.
  - Added unit test: `test_canonical_path_resolves_symlinks`.

### Defect 6: Cleanup Behavior on Failed Database Drop
- **Assessment**: If `$schema->drop()` threw an exception (e.g., temporary MariaDB disconnection or query timeout), execution in `serve.php` aborted before `cleanupStateDir()` could unlink credentials, state files, and the session pointer, leaving the harness in a deadlocked state.
- **Fix Applied**:
  - In `serve.php` `finally`: `$schema->drop()` is wrapped in a dedicated `try ... catch (\Throwable $dropError)`.
  - If schema dropping fails, a warning is emitted to `STDERR` stating that the schema may be orphaned for manual or runner sweep (`accounting_p8_tmp_*`), and execution continues unconditionally to `ReleaseUiSession::cleanupStateDir()`.
  - Pointer files and state files are cleanly removed, avoiding deadlock while adhering to the rule: never adopt or drop database schemas merely by name.

---

## 3. Scope Compliance & Files Modified

All edits were strictly confined to the assigned exclusive scope:
1. `tests/Support/ReleaseUi/ReleaseUiSession.php`: Added `getProcessStartTime()`, PID-reuse detection in `safeWritePointer()`, `/proc/[pid]/cwd` path canonicalization in `verifyProcessIdentity()`, and collision-resilient idempotent `writeStopSignal()`.
2. `tests/Support/ReleaseUi/serve.php`: Added early signal handling, recorded `owner_start_time`, and protected individual cleanup steps in `finally`.
3. `tests/Support/ReleaseUi/cleanup.php`: Added `owner_start_time` check to detect PID reuse, canonical path comparisons for working directory.
4. `tests/Support/ReleaseUi/ReleaseUiFixtures.php`: Added missing `CustomerPayment` model import, updated `SalaryEntry::$salary_number` property, and formatted `email_verified_at` as string.
5. `tests/Unit/ReleaseUi/ReleaseUiHarnessTest.php`: Fixed umask mask issue in `test_state_dir_verification_refuses_wrong_permissions` by applying explicit `chmod(0755)`; added 4 new unit tests covering PID reuse detection, matching owner refusal, permission enforcement, and cleanup refusal on reused PID.
6. `docs/evidence/release-candidate-20261107/recovery-ui-harness.md`: This evidence report.

Zero files outside this scope were edited or staged.

---

## 4. Verification Evidence & Test Execution

### A. Focused PHPUnit Harness Suite
**Command**:
```bash
APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- tests/Unit/ReleaseUi/ReleaseUiHarnessTest.php
```
**Output**:
```text
Owned primary test schema: accounting_p8_tmp_c6bc333fdc82
PHPUnit 12.5.36 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.27
Configuration: /home/t3dev/.t3/userdata/antigravity-tmp/c45eb5b51b31/run-blYZFG/p8_phpunit_d15nhip9n3s78vlSblV

........................                                          24 / 24 (100%)

Time: 00:05.614, Memory: 24.50 MB

OK (24 tests, 69 assertions)
Owned primary schema cleaned.
```

### B. Static Analysis (PHPStan)
**Command**:
```bash
./vendor/bin/phpstan analyse tests/Support/ReleaseUi tests/Unit/ReleaseUi --no-progress
```
**Output**:
```text
Note: Using configuration file /home/t3dev/.t3/projects/small-trader-platform/account/phpstan.neon.

 [OK] No errors
```

### C. Code Formatting (Pint)
**Command**:
```bash
./vendor/bin/pint --test tests/Support/ReleaseUi tests/Unit/ReleaseUi
```
**Output**:
```json
{"tool":"pint","result":"passed"}
```

---

## 5. Security & Isolation Invariants Maintained

1. **Schema Ownership**: Only the live in-memory instance of `DisposableMariaDbSchema` retains drop authority. Zero reflection is used on private properties. Neither `serve.php` nor `cleanup.php` ever drops a database by parsing a name from an untrusted file.
2. **File & Directory Custody**: State directories are enforced strictly to mode `0700` and UID match. Pointer and credential files are enforced to mode `0600` and UID match. Path traversal outside authorized temp directories is rejected.
3. **Process Supervision**: Zero broad process killing (`fuser -k`, `pkill`). Signals are only sent after verifying UID ownership, process commandline, canonical executable paths, and PID start time.
4. **Disposable Testing Compliance**: Operates exclusively against loopback port 3342 using the authorized `test_runner` account targeting temporary schemas (`accounting_p8_tmp_*`). Server port 3353 and non-disposable environments remain strictly untouched.

---

## 6. Risk Assessment & Residual Notes

- **Integration Dependency**: `test_integration_serve_starts_and_cleanup_stops_on_disposable_fixture` requires the test MariaDB instance on port 3342. If the database daemon is unavailable, the test safely skips with a descriptive notice.
- **Browser Automation Readiness**: The background web server supervisor, ephemeral tenant fixture seeder, and signal cleanup harness are verified and ready for subsequent browser qualification phases.
