# PR #102 Harness Readiness-Only Provenance & Evidence Report

**PR**: [#102](https://github.com/Bassamalsaqqa/account/pull/102) (`t3/recover-cash-writer`)  
**Repository**: `Bassamalsaqqa/account`  
**Review Context**: OWNER Review `5906317848` submitted at `2026-11-14T18:21:45Z` on commit `a8540ff83513b49988d90c77cf284831cfd5b2de` (carrying prior Review `5905683371` and peer Android Review `5906183761` submitted at `2026-11-14T17:53:49Z` on `753fb5133e28acb1284370dd7a84e3929e7b1a7d`).  
**Operational Scope**: Sole writer lane, documentation and evidence publication only. Zero database connections, zero listening sockets, zero background jobs, zero child subagents. Lease FREE.  
**Accepted Core Foundation**: Prior core qualification (187 tests, 1,440 assertions, route cache DAG, and company-root serialization invariants at `6981511405d7bcd04cd8f8a09e64443ce0aaf47e`) is **accepted** and not redone.

---

## 1. Provenance & Cryptographic Pins Matrix

All digests below are deterministic SHA-256 hashes computed directly from disk:

| Component / File Path | SHA-256 Digest | Status & Role |
|---|---|---|
| `docs/integration/mobile-v2-client-handoff-schema.json` | `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275` | **Frozen**: JSON Schema enforcing full cash restriction, ULID format, startup TLS structure, and strict TTL |
| `docs/integration/mobile-v2-integration-manifest.json` | `471240f132901eacef39ff1acea44f4b4802f775edb81e6e8c6a151d2b56ac92` | **Integration Manifest**: Dual-track configuration, authoritative request template, endpoints, and HEAD pins |
| `tests/Support/Harness/DisposableMobileV2BackendHarness.php` | `ccb8c9f3852b4736675253d094659c8629d197df1c35a3caa7c99ba519da8152` | **Source Fixture**: Pre-socket pcntl availability, early signal registration, strict X.509 v3 certs, 0600 file permissions |
| `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php` | `cb601109631bd7c7899d3093fae079e0c8a5b8cb4f31582f016bc5cbf4bdd4fe` | **Pure Guard Suite**: 57 tests, 241 assertions, zero DB sockets, zero listeners, includes pcntl enforcement & strict cert tests |
| `tests/Support/Harness/MobileV2HarnessCommand.php` | `9bf4f389a034c52c62ea6aad796b20c06051f87dbf5a33b683572c4bff9bfd97` | **CLI Command**: Bounded readiness hold (`--readiness-hold-seconds`), CLI signal trap, and nonzero exit on interrupt |
| `tests/Support/Harness/mobile-v2-harness-cli.php` | `5836790dcc58a9956868c1fcc1e6a0cc979a989d0bcf82a2a197b2787c548690` | **CLI Runner**: Pre-framework pcntl check, signal registration, and fail-closed readiness hold loop |
| `runtime-pairing-manifest.json` *(Run `bar7pq1o`)* | `76a7326baa90c2dc43d89d75998518bded17856d5ad2752e0a7a676237456f3b` | **Pairing Manifest**: Authorized scope `ROOT_AUTHORIZED_READINESS_ONLY`, pub `824cb7d`, exec `753fb51` |

---

## 2. Core Tree Invariance Map

The core application code remains **100% byte-identical** to the accepted base commit `6981511405d7bcd04cd8f8a09e64443ce0aaf47e`. Verified via command:

```bash
git diff 6981511405d7bcd04cd8f8a09e64443ce0aaf47e -- app routes config composer.lock
# Result: 0 files changed, 0 insertions, 0 deletions (EMPTY DIFF)
```

| Core Path | Status vs `6981511405d7bcd04cd8f8a09e64443ce0aaf47e` | Verification Output |
|---|---|---|
| `app/` | Byte-Identical | Empty diff, qualified tree SHA `34d1da0ae82b182fa2c0adafd785416a86834a20` verified |
| `routes/` | Byte-Identical | Empty diff |
| `config/` | Byte-Identical | Empty diff |
| `composer.lock` | Byte-Identical | Empty diff |

All changes on branch `t3/recover-cash-writer` since `6981511405d7bcd04cd8f8a09e64443ce0aaf47e` reside strictly within `tests/Support/Harness/`, `tests/Unit/Mobile/Harness/`, `docs/integration/`, and `docs/evidence/`. Zero production routes, controllers, models, flags, or configuration policies were altered.

---

## 3. Harness Execution Provenance & Guard Test History

### 3.1 Pure Guard Test Suite Execution Progression
- **Prior Root Run on `31140e4`**:
  - Result: 43 tests, 145 assertions, **3 errors, 3 failures** (82ms, zero DB).
  - Uncovered missing handoff arguments, stale exception messages, and child autoload sequencing gaps.
- **Commit `6e8c01cbfed9d11fd79e50699ff1a51ae8f4e72e`**:
  - Root: `0.281s`, Worker: `0.277s` (`OK (44 tests, 163 assertions)`).
  - Child process autoload sequenced before readiness route; subprocess router execution test added.
- **Commit `815db269ba5ec1348553b9931f76ae91fb36578d`**:
  - Root: `0.965s` (`OK (53 tests, 227 assertions)`).
  - Configured X.509 v3 CA (`basicConstraints=CA:TRUE`, `keyUsage=critical,keyCertSign,cRLSign`) and server (`basicConstraints=CA:FALSE`, `keyUsage=digitalSignature,keyEncipherment`, `extendedKeyUsage=serverAuth`, `SAN=IP:127.0.0.1,IP:10.0.2.2`) extensions for strict Python 3.13 and OpenSSL verification.
- **Current Head Execution (`a8540ff83513b49988d90c77cf284831cfd5b2de`)**:
  - **Root Guard Run**: `1.180s` (`OK (57 tests, 241 assertions)`).
  - **Worker Guard Run**: `1.116s` (`OK (57 tests, 241 assertions)`).
  - Enforced pre-socket `pcntl` extension availability, early signal handler registration before DB/sockets, `0600` permissions on CA/server certs and private keys, and `0700` parent directory permissions.

---

## 4. Historical vs Root-Coordinated Execution Runs

Four distinct execution runs are documented with exact provenance:

### 4.1 Run 1: Standalone Provision (Commit `4a2e0b28`)
- **Status**: `PROVISIONED_AWAITING_TLS_START_ONLY`.
- **Captured Handoff SHA-256**: `964e7bcfe6b8a140f043e921e99da6020258434b6649a47dcf9225c7ddcad6d3`.
- **Startup TLS Status**: `UNAVAILABLE_AWAITING_TLS_START` (URLs and nonce null; pre-start only).

### 4.2 Run 2: Worker Readiness Probe (Commit `6e8c01cb`)
- **Command**: `mobile-v2-harness-cli.php --run --readiness-only` (duration `8.236s`, exit `0`).
- **Transient Owned Schema**: `accounting_p8_tmp_ad42c06ee92a` (dropped cleanly).
- **Report Digest**: `ad1f7cc1bf82a6a7353f6b1b898065ccc9ce10ec1882ea178f270091200c35cb`.

### 4.3 Run 3: Root Readiness Hold CA Validation Failure (Commit `9589edaa`, Dir `/tmp/root-readiness-eb_1btu5`)
- **Execution Cause**: Root executed live readiness hold with strict Python 3.13 / OpenSSL client.
- **Failure**: `[SSL:CERTIFICATE_VERIFY_FAILED] CA cert does not include key usage extension`.
- **JVM Invocations**: Exactly **0** (first-failure stop honored; client JVM never launched).
- **SIGTERM Abort Drill**: Root sent SIGTERM; schema `accounting_p8_tmp_a5057b7ce693` dropped cleanly, ports closed.

### 4.4 Run 4: Root-Coordinated Paired Run (Commit `a8540ff8`, Dir `/tmp/root-readiness-bar7pq1o`)
- **Backend Execution HEAD**: `a8540ff83513b49988d90c77cf284831cfd5b2de`
- **Peer Android Publication HEAD**: `824cb7dac1f17a3530f178134f9d9168e7e50b94`
- **Peer Android Executable HEAD**: `753fb5133e28acb1284370dd7a84e3929e7b1a7d`
- **Runtime Pairing Manifest SHA-256**: `76a7326baa90c2dc43d89d75998518bded17856d5ad2752e0a7a676237456f3b`
- **Runtime Pairing Scope**: `ROOT_AUTHORIZED_READINESS_ONLY`
- **Live Handoff Snapshot SHA-256**: `3c51b313c8307e85f2e1e4d9d8bc940b8d3ead5bb7999730859520d2c0805253` (transient runtime file, now deleted; never a reusable endpoint)
- **Live CA Certificate SHA-256**: `b5f7f5b100a2d40c8746eba466e569178f988ea61a470a4906152738556c4066`

#### Root TLS Probe Verification
1. **Strict CA/SAN GET 200**: Verified HTTP 200 and matching nonce via CA-trusted TLS on `https://127.0.0.1:18443/_harness/readiness`.
2. **Readiness Probe POST 405**: Verified HTTP 405 Method Not Allowed enforced on readiness route `/_harness/readiness`.
3. **Safe Non-Readiness Route GET 403**: Verified HTTP 403 Forbidden on safe probe path `/_readiness_forbidden_probe`. Readiness-only boundary strictly blocks non-readiness routes; zero financial network routes called.
4. **Untrusted / Wrong CA Rejection**: **ACTUAL LIVE TLS NEGATIVE PASS**. Verified strict TLS handshake rejection when connecting with an untrusted CA.
5. **Nonce Mismatch Check**: **LOCAL COMPARISON CHECK**. In-memory comparison check (`json_nonce != '0'*32`) passed; live corrupted nonce over network / client path negative remains **UNPROVEN**.

#### Android Client JVM Execution & Parser Pin Failure
- **Invocations**: Exactly **1 JVM invocation** (`./gradlew cleanTestDebugUnitTest testDebugUnitTest ...`, duration `27.16s`, exit code `1`).
- **JUnit Test XML** (`0-TEST-...SmallTraderOnlineCashPilotSmokeTest.xml`, SHA `dc2340a...`):
  - Total tests: 2. Passed: 1. Failed: 1. Skipped: 0. Errors: 0.
  - `testCashPilotFinancialAcceptanceEnforcement`: **PASSED** (`0.011s`).
  - `executePairedOnlineCashPilotSmoke`: **FAILED** (`0.069s`).
- **Failure Cause**:
  ```
  java.lang.IllegalArgumentException: Peer Android executable HEAD pin mismatch: expected 824cb7dac1f17a3530f178134f9d9168e7e50b94, got 753fb5133e28acb1284370dd7a84e3929e7b1a7d
      at com.example.data.mobile.readclient.pilot.CashPilotHandoffConfig$Companion.parseBytes(CashPilotHandoffConfig.kt:603)
      at com.example.data.mobile.readclient.pilot.CashPilotHandoffConfig$Companion.fromFile(CashPilotHandoffConfig.kt:488)
      at com.example.data.mobile.readclient.pilot.SmallTraderOnlineCashPilotSmokeTest.executePairedOnlineCashPilotSmoke(SmallTraderOnlineCashPilotSmokeTest.kt:85)
  ```
- **Execution Timing**: Failed during configuration parsing in Kotlin **before any client HTTP request was sent**.
- **Client Output**: Zero fresh client readiness JSON was generated.
- **Financial Boundary**: Zero financial requests dispatched; strictly `NOT_RUN`.
- **Pre/Post State JSON SHA-256**: Both `android_pre.json` and `android_post.json` are verified identical at SHA-256 `7280a75fcd482b311a15ed4f9996537b523423c666f3956a5ee64d5101ac72a8` (this is the digest of the JSON file recording HEAD, git status, and source file hashmap; NOT a git object tree hash). Zero mutations occurred.

#### Backend Hold Interruption & SIGTERM Drill
- **Process PID**: `1185016`
- **Interrupt Signal**: SIGTERM issued by Root during readiness hold.
- **Early Signal Handler Response**: Caught SIGTERM, logged `Readiness hold ABORTED by interrupt signal SIGTERM.`, and exited with code **1** (`FAILURE`, non-zero).
- **Cleanup Guarantee**: Verified via MariaDB and Python `socket.connect_ex`:
  - Exact owned MariaDB schema `accounting_p8_tmp_3a598a57cc05` dropped.
  - Exact owned directory `/tmp/v2_harness_a7c718c045489fbb` absent.
  - Loopback port `18080` and TLS proxy port `18443` verified closed via Python `socket.connect_ex` non-zero (connection refused).

---

## 5. Retained Evidence Artifacts Matrix (`/tmp/root-readiness-bar7pq1o`)

All 14 artifacts verified against private files:

| File Name | SHA-256 Digest | Bytes | Description |
|---|---|---|---|
| `0-TEST-...SmallTraderOnlineCashPilotSmokeTest.xml` | `dc2340af6bfabddcef4fd1793a334de2ca5d47686e26b62d9fed1beaaa702e9d` | 5,392 | JUnit test report: 2 tests, 1 PASS, 1 FAIL (pin mismatch at line 603) |
| `android_command.json` | `be6ca6ec0995080dc155528ee55f08e7aac0f39bdc66ddeb29e4b7afe14317f9` | 1,124 | Gradle command-line arguments and sanitized nonsecret environment |
| `android.log` | `d0cc29dcc956a8c2f80b3ada823e15fe8deccab599dc6983fd6b8b04d3b955b6` | 3,296 | Raw Gradle test task execution output (BUILD FAILED in 26s) |
| `android_pre.json` | `7280a75fcd482b311a15ed4f9996537b523423c666f3956a5ee64d5101ac72a8` | 27,141 | Pre-execution state JSON (HEAD, status, and source file hashmap) |
| `android_post.json` | `7280a75fcd482b311a15ed4f9996537b523423c666f3956a5ee64d5101ac72a8` | 27,141 | Post-execution state JSON (identical to pre; zero mutations) |
| `android_result.json` | `89469c9e6b8652a074e4dcac9b662f0920a2d157f4dc1394551b32b8a0991cec` | 616 | Android test run metadata (duration 27.16s, exit 1, artifacts mapping) |
| `backend_exit.json` | `cb8cd4906b5e40e60c90b877f8767d77fd9217bba6ab7e6ff490d024de788ea5` | 46 | Backend harness process exit status (`{"exit": 1, "finished_at": ...}`) |
| `backend.log` | `65110f2352ec6a2979cf87766a6fa7203147f41cb98635655346fb81ab971304` | 756 | Backend harness execution log with SIGTERM abort and cleanup |
| `live_handoff_snapshot.json` | `3c51b313c8307e85f2e1e4d9d8bc940b8d3ead5bb7999730859520d2c0805253` | 5,457 | Transient runtime handoff artifact snapshot (now deleted) |
| `live_verified.json` | `928b08e700b1ecbd1febc9ca8b947a3467097a1b94aa55df463110ac89907716` | 654 | Root TLS probe outcomes (GET 200, POST 405, safe GET 403, wrong CA rejection) |
| `manifest.json` | `76a7326baa90c2dc43d89d75998518bded17856d5ad2752e0a7a676237456f3b` | 5,118 | Pairing manifest defining authorized scope `ROOT_AUTHORIZED_READINESS_ONLY` |
| `operator.json` | `cf79dea8480ad890380916335efab393420d0db63e85b2c8592c832ee3d9677c` | 515 | Root harness command invocation arguments and manifest SHA pin |
| `pid` | `68eb480291680e36768324c8a4f24a5f3b889e47a558ac7687ac97618ea21f1c` | 7 | Backend harness PID recording file (`1185016`) |
| `root_outcome.json` | `edc41299370fadfd6b633b022f5778e5e5953a8a18c449e9c0bcff6339b950d8` | 700 | Root structured run verdict: `JVM_PIN_VALIDATION_FAILED_BEFORE_CLIENT_HTTP` |

### Sanitized Backend Execution Log Excerpt
```
Mobile V2 Harness — Readiness-Only Probe Test (--readiness-only)
Owned schema created: accounting_p8_tmp_3a598a57cc05
  Provision OK — schema: accounting_p8_tmp_3a598a57cc05
Readiness probe test succeeded.
  TLS proxy port   : https://127.0.0.1:18443
  Readiness probe  : verified HTTP 200 and matching nonce via CA-trusted TLS.
  Client handoff   : /tmp/v2_harness_a7c718c045489fbb/runtime_client_handoff.json
  Financial routes : strictly blocked (HTTP 403) in child router.
Holding readiness state for 120s (PID 1185016). Press Ctrl+C to terminate early.
Readiness hold ABORTED by interrupt signal SIGTERM.

Shutting down and cleaning up owned processes, files, and schemas...
Owned schema dropped: accounting_p8_tmp_3a598a57cc05
Cleanup completed.
```

---

## 6. Cleanup Scope & Verification

Cleanup was independently verified for exact owned resources:
- **Owned MariaDB Schema**: Exact owned schema `accounting_p8_tmp_3a598a57cc05` dropped (verified absent via MariaDB information schema; no wildcard drops).
- **Owned Temporary Directory**: Exact owned directory `/tmp/v2_harness_a7c718c045489fbb` absent (verified deleted).
- **Network Sockets**: Ports `127.0.0.1:18080` (HTTP loopback) and `127.0.0.1:18443` (TLS proxy) verified closed via Python `socket.connect_ex` non-zero (connection refused).
- **Working Tree**: `git status` clean (zero uncommitted edits).
- **Lease Status**: Explicitly **FREE / RELEASED**.

---

## 7. Record of Unexecuted Gaps & Review Requests

In strict adherence to reporting integrity, the following review items were **not executed** in this pass and remain open gaps:

1. **Live Corrupted Nonce over Network / Client Path Negative**:
   - The nonce mismatch check in Run 4 was an in-memory comparison check (`json_nonce != '0'*32`), NOT an actual client validator or failed HTTP request. Corrupted nonce validation over the live TLS proxy stream remains **unproven**.
2. **Live SIGINT Abort Drill**:
   - The readiness hold was interrupted live via **SIGTERM** (exercising signal handler, abortion exit code 1, and schema cleanup). A live **SIGINT** (Ctrl+C) drill on the running hold was not executed.
3. **Intermediate Transport Process Failure Recovery**:
   - Simulated ungraceful transport failure (e.g. killing `php -S` or crashing Caddy while client HTTP traffic is active) was not exercised live on actual transport.
4. **Financial Pilot Execution**:
   - Strictly **NOT RUN**. Readiness-only lifecycle scope strictly forbids network calls to financial routes.

---

## 8. Review 5906317848 Gate Checklist

| Item | Status | Verification & Evidence |
|---|---|---|
| Core 187/1440 qualification & route cache DAG invariance | **ACCEPTED** | Verified 100% byte-identical against `6981511405d7bcd04cd8f8a09e64443ce0aaf47e` |
| Pure guard test suite passing | **ACCEPTED** | 57 tests, 241 assertions, 0 errors, 0 failures (Root: `1.180s`, Worker: `1.116s`) |
| Strict X.509 v3 CA & server extensions | **ACCEPTED** | Verified via pure OpenSSL / Python strict validation tests |
| Pre-socket pcntl availability & early signal registration | **ACCEPTED** | Verified in `DisposableMobileV2BackendHarness.php` and pure CLI test |
| File permission policy (0600 certs/keys, 0700 dir) | **ACCEPTED** | Verified in source fixture and pure guard test |
| Root strict CA/SAN TLS GET 200 with nonce | **VERIFIED** | Live Root probe verified HTTP 200 with matching nonce over CA-trusted TLS |
| Readiness POST 405 & safe GET 403 barriers | **VERIFIED** | Live Root probes verified POST 405 and safe GET 403 on `/_readiness_forbidden_probe` enforced |
| Root wrong CA refusal (live TLS negative) | **VERIFIED** | Live Root probe verified strict TLS handshake failure on untrusted CA cert |
| Nonce mismatch negative check | **LOCAL CHECK** | Local comparison check passed; live network client path negative remains unproven |
| Root SIGTERM abort drill & schema drop | **VERIFIED** | PID 1185016 aborted by SIGTERM, exited 1, dropped schema `accounting_p8_tmp_3a598a57cc05` |
| Failure first stop honored (no autorestart) | **VERIFIED** | First failure stopped immediately; 0 automatic retries |
| Android client pin parser fix | **PENDING** | Bug in `CashPilotHandoffConfig.kt:603` assigned to Android sole writer lane |
| Live corrupted nonce client path negative drill | **PENDING** | Pending live network drill |
| Live SIGINT drill on active hold | **PENDING** | SIGTERM verified; SIGINT drill pending future pass |
| Intermediate transport crash drill during active traffic | **PENDING** | Pending future coordinated drill |
| Paired execution authorization | **PENDING** | Requires reviewed Android runner with parser fix and fresh Root-verified `ACTIVE_TLS_READY` handoff hash |

---

## 9. Guide to Financial Acceptance Criteria

Any future live paired execution must strictly require and verify the canonical financial models, public DTOs, and settlement rules:

### 9.1 Canonical Domain Entities & Public Identity
1. **SalesInvoice**: Model `SalesInvoice` persisted with status `posted` (tenant company, customer, currency, line items). Public ID in wire DTOs is a ULID (`invoice_id`).
2. **CustomerPayment**: Model `CustomerPayment` persisted with status `posted` (method `cash`, non-null `cash_account_id`). Public ID in wire DTOs is a ULID (`payment_id`).
3. **CustomerPaymentAllocation**: Model `CustomerPaymentAllocation` binding `CustomerPayment` to `SalesInvoice`, reconciling invoice balance.
4. **StockMovement**: Model `StockMovement` recording physical ledger deduction in base inventory units.
5. **MobileEventReceipt**: Model `MobileEventReceipt` recording execution receipt. In the public wire contract, receipt identity is strictly the client UUID (`client_event_id`). There is **no public ULID receipt contract**.

### 9.2 DTO Structure & Parity Rules
- **Commit Wire DTO (10 keys)**: Returns `client_event_id` (UUID), `status` (`'posted'`), `invoice_id` (ULID), `invoice_number`, `payment_id` (ULID), `payment_receipt_number`, `grand_total`, `amount_paid`, `change_returned`, `posted_at`.
- **Status Wire DTO**: Returns `client_event_id`, `company_id`, `status` (`'posted'` or `'unknown'`), `payload_digest`, `official_invoice`, `conflict`, `queried_at`.
  - The `official_invoice` field is a **6-field subset** (`invoice_id`, `invoice_number`, `payment_id`, `payment_receipt_number`, `grand_total`, `posted_at`).
  - Parity assertions must compare matching invoice/payment public ULIDs, invoice numbers, and totals across the shared fields; do **not** claim identical 10-key receipt structures between commit and status.
- **Rejection of Unsettled States**: A receipt marked `PostedWithoutPaymentReference` or lacking allocation is **never** accepted as a full cash settlement success.

### 9.3 Outer Financial Pilot Acceptance Criteria
- Full pilot acceptance **must require each required critical scenario to PASS separately**:
  1. Fresh online cash sale commit and status query verification.
  2. Idempotent exact replay verification.
  3. Lost ACK recovery / status replay verification.
  4. Conflict rejection on client event ID reuse with altered payload.
- A basic smoke PASS with lost ACK / rollback NOT RUN is **never** a full pilot PASS.

### 9.4 Overtender & Wire Settlement Semantics
- **Backend Full Cash Wire Contract**: At the backend financial slice, the request `payment.amount` is the exact settlement amount applied to the invoice (`change_returned` = `0.000000` on the wire).
- **Client Tender Calculation**: Overtender and customer change calculation is managed durably on the Android client (`OfflineSaleV2` local tender/change state where `tender_amount >= net_total` and `local_change = tender_amount - net_total`).
- **No Unsupported Backend Fields**: Do not impose non-existent or unsupported tender/change fields onto the backend cash slice request payload.

---

## 10. Android Track Coordination Status

- **Peer Candidates**:
  - Publication HEAD: `824cb7dac1f17a3530f178134f9d9168e7e50b94`
  - Executable HEAD: `753fb5133e28acb1284370dd7a84e3929e7b1a7d`
- **Identified Client Defect**:
  - `CashPilotHandoffConfig.kt:603` in `SmallTraderOnlineCashPilotSmokeTest` compares the worktree executable HEAD against the publication pin (`expected 824..., got 753...`), failing in-process before HTTP dispatch.
- **Sole Android Writer Track**:
  - The Android client fix belongs strictly to the Android sole writer lane. The backend assistant does **not** edit Android client source code.
- **Authorization Boundary**:
  - Lease remains **FREE / RELEASED**.
  - There is **no TLS start grant**, **no DB start grant**, and **no financial execution grant**.
- **Next Paired Run Requirement**:
  - Live paired execution requires an approved Android runner commit with the parser fix, a fresh Root-verified `ACTIVE_TLS_READY` handoff hash, and explicit paired execution authorization.
