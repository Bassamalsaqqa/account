# Release UI Test Harness Hardening Report

**Date**: 2026-11-07
**Agent**: Implementation Sub-Agent (AGY)
**Target Subsystem**: `tests/Support/ReleaseUi/*`
**Compliance Standards**: Tracked `DisposableMariaDbSchema` ownership, private 0700 directory isolation, exclusive 0600 credentials custody, strict process identity verification, zero `fuser`/`pkill` broad commands, zero reflection against private ownership.

---

## 1. Executive Summary & Problem Resolution

The prior inspection identified severe architectural and security defects in the initial `serve.php` and `cleanup.php` implementations:
1. **Reflection Hack Bypassing Schema Ownership**: `serve.php` used `ReflectionProperty` to forcibly set `$schema->owned = false`, allowing the process to terminate without dropping the schema and delegating drop responsibility to a mutable `/tmp` state file.
2. **Untracked Capability & Broad Schema Drop**: `cleanup.php` dropped any regex-matching database name parsed from a public `/tmp` file without verifying capability proof or ownership custody.
3. **Broad Kill Fallbacks**: `cleanup.php` invoked `fuser -k {$port}/tcp` and `pkill -f 'php -S ...'`, risking collateral termination of other local services (such as Huda's MariaDB or sibling test runners).
4. **Arbitrary Path Unlink**: Files were removed based on unvalidated paths in mutable temp files without verifying file ownership or directory confinement.

All four defects have been completely eliminated in `tests/Support/ReleaseUi/*`.

---

## 2. Hardened Architecture & Design Decisions

### A. Strict Foreground Owner Process Supervision
- **DisposableMariaDbSchema Lifecycle**: The owner process (`serve.php`) instantiates `$schema = DisposableMariaDbSchema::create()`, keeping the object alive in memory.
- **No Reflection**: Zero reflection is used. When `serve.php` receives a shutdown signal or exits via its `finally` block, `$schema->drop()` is executed naturally and directly by the owner.
- **Web Server Child Supervision**: The PHP built-in web server is managed as a supervised child process using `Symfony\Component\Process\Process`. On termination, the supervisor calls `$serverProcess->stop(2, SIGTERM)` before releasing the database.

### B. Private Unique State Directory (`0700`) & Exclusive Files (`0600`)
- **Directory Isolation**: State is written exclusively to a unique randomized directory:
  `sys_get_temp_dir() . '/release_ui_' . bin2hex(random_bytes(8))`
  Permissions are explicitly enforced to `0700` and verified with `stat()`.
- **Credential & State Custody**: `credentials.json` and `state.json` are created with `0600` permissions inside the private directory.
- **Session Pointer**: An active session pointer file `sys_get_temp_dir() . '/release_ui_pointer_' . posix_getuid() . '.json'` (mode `0600`) securely links `cleanup.php` to the active directory without requiring predictable public file paths.
- **Capability Secret**: A 64-character cryptographic token (`session_token`) is stored in `state.json`. Cleanup signals must present this token via `stop.signal` to authorize process termination.

### C. Strict Process Identity Verification (No `fuser` / `pkill`)
- Before sending any signal or terminating any process:
  1. The PID is checked to ensure `pid > 1`.
  2. `/proc/{pid}` owner UID is verified against `posix_getuid()`.
  3. `/proc/{pid}/cmdline` is parsed from null-delimited arguments to ensure it matches the exact expected command (`php`, `-S`, and `127.0.0.1:<port>`).
- If process identity fails verification, termination is refused immediately.
- `posix_kill($pid, SIGTERM)` is sent directly to the verified PID. If the process does not terminate within the timeout, `/proc/{pid}/cmdline` is re-verified before sending `SIGKILL`.
- Broad system commands (`fuser -k`, `pkill`) are strictly prohibited.

### D. Confinement of Unlink Operations
- Unlinking is restricted strictly to regular files (never symlinks) situated within the verified private state directory and owned by current `posix_getuid()`.
- Arbitrary path deletion from user input or state files is impossible.

---

## 3. Test Coverage & Verification

### Unit Tests (`tests/Unit/ReleaseUi/ReleaseUiHarnessTest.php`)
A dedicated unit test suite verifies all security invariants:
1. `test_state_directory_creation_enforces_0700_permissions`: Confirms 0700 mode and UID ownership.
2. `test_secure_file_writing_enforces_0600_permissions`: Confirms 0600 mode and UID ownership on files.
3. `test_state_directory_verification_refuses_outside_paths`: Asserts refusal when given paths outside authorized prefix (e.g., `/etc`).
4. `test_schema_name_validation_enforces_strict_naming_regex`: Validates that only `/^accounting_p8_tmp_[a-f0-9]{12}$/D` names are accepted, rejecting injection attempts and production databases.
5. `test_process_identity_verification_refuses_unmatched_or_foreign_processes`: Tests that foreign PIDs (e.g. init PID 1) or mismatched commands fail verification.

**Test Run Result**:
```text
PHPUnit 12.5.36 by Sebastian Bergmann and contributors.
.....                                                               5 / 5 (100%)
Time: 00:00.090, Memory: 24.50 MB
OK (5 tests, 19 assertions)
```

### Style & Lint Compliance
Executed `./vendor/bin/pint --test tests/Support/ReleaseUi tests/Unit/ReleaseUi`:
```text
{"tool":"pint","result":"passed"}
```
All code strictly formatted according to project conventions.
