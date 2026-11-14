# PR #102 Harness Readiness-Only Provenance & Evidence Report

**PR**: [#102](https://github.com/Bassamalsaqqa/account/pull/102) (`t3/recover-cash-writer`)  
**Repository**: `Bassamalsaqqa/account`  
**Review Context**: OWNER Review `5906317848` submitted at `2026-11-14T18:21:45Z` on commit `a8540ff83513b49988d90c77cf284831cfd5b2de` and subsequent execution commits through `d7c7324535b1e6586140ab1e3561966342d625d6`.  
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
| `runtime-pairing-manifest.json` *(Run `ooug5prl`)* | `88c8c2e2389f7486e73c74f4f660aa0621bf3c8486a85590b6c4bbb44bb83f5f` | **Pairing Manifest**: Authorized scope `ROOT_AUTHORIZED_READINESS_ONLY`, pub `6533272`, exec `78ac443` |

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
- **Commit `a8540ff83513b49988d90c77cf284831cfd5b2de` & Current HEAD**:
  - **Root Guard Run**: `1.180s` (`OK (57 tests, 241 assertions)`).
  - **Worker Guard Run**: `1.116s` (`OK (57 tests, 241 assertions)`).
  - Enforced pre-socket `pcntl` extension availability, early signal handler registration before DB/sockets, `0600` permissions on CA/server certs and private keys, and `0700` parent directory permissions.

---

## 4. Historical vs Root-Coordinated Execution Runs

Five distinct execution runs are documented with exact provenance:

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

### 4.4 Run 4: Root-Coordinated Paired Run Pin Parser Mismatch (Commit `a8540ff8`, Dir `/tmp/root-readiness-bar7pq1o`)
- **Execution Cause**: Android client parser compared executable HEAD against publication pin (`expected 824..., got 753...`) in `CashPilotHandoffConfig.kt:603` before client HTTP.
- **JVM Invocations**: 1 (duration `27.16s`, exit code `1`).
- **Test Results**: 2 tests (1 PASS, 1 FAIL, 0 SKIP, 0 error).
- **Outcome**: Zero fresh readiness JSON produced. Zero financial requests sent.
- **SIGTERM Abort Drill**: PID 1185016 aborted by SIGTERM, exited 1, schema `accounting_p8_tmp_3a598a57cc05` dropped.

### 4.5 Run 5: Root-Coordinated Paired Run Readiness SUCCESS (Commit `d7c7324`, Dir `/tmp/root-readiness-ooug5prl`)
- **Backend Execution HEAD**: `d7c7324535b1e6586140ab1e3561966342d625d6`
- **Peer Android Publication HEAD**: `653327243b2f5a4541c5fcf8cbe9520769e6be50`
- **Peer Android Executable HEAD**: `78ac4437e4010b68408278788dc82eb826fe8014`
- **Runtime Pairing Manifest SHA-256**: `88c8c2e2389f7486e73c74f4f660aa0621bf3c8486a85590b6c4bbb44bb83f5f`
- **Runtime Pairing Scope**: `ROOT_AUTHORIZED_READINESS_ONLY`
- **Live Handoff Snapshot SHA-256**: `8e48f3692a88f5ac3f333f81536d70eb3541228959968697fda03f68df44befc` (transient runtime file, now deleted; never a reusable endpoint)
- **Live CA Certificate SHA-256**: `9dde86450a9ccef026bb1ff4c2d6d75cb46ff61729cc008994c5a0d0ac725f72`
- **Root Outcome SHA-256**: `dd267bc4f3a505d1558020758545ac1e40159114eca0b9538fb9f37839aeffe7`

#### Root TLS Probe Verification (All Passed)
1. **Strict CA/SAN GET 200**: Verified HTTP 200 and matching nonce via CA-trusted TLS on `https://127.0.0.1:18443/_harness/readiness`.
2. **Readiness Probe POST 405**: Verified HTTP 405 Method Not Allowed enforced on readiness route `/_harness/readiness`.
3. **Safe Non-Readiness Route GET 403**: Verified HTTP 403 Forbidden on safe probe path `/_readiness_forbidden_probe`. Readiness-only boundary strictly blocks non-readiness routes; zero financial network routes called.
4. **Untrusted / Wrong CA Rejection**: **ACTUAL LIVE TLS NEGATIVE PASS**. Verified strict TLS handshake rejection when connecting with an untrusted CA.

#### Android Client JVM Execution & Full Readiness PASS
- **Invocations**: Exactly **1 JVM invocation** (`./gradlew cleanTestDebugUnitTest testDebugUnitTest ...`, duration `13.3375s`, exit code `0`).
- **JUnit Test XML** (`0-TEST-...SmallTraderOnlineCashPilotSmokeTest.xml`, SHA `51e834a7f5d358e0e6544eb03b355e40130e0455d0d2c8de9b7099390b07c6eb`):
  - Total tests: 2 | Passed: 2 | Failed: 0 | Skipped: 0 | Errors: 0
  - `executePairedOnlineCashPilotSmoke`: **PASSED** (`0.643s`).
  - `testCashPilotFinancialAcceptanceEnforcement`: **PASSED** (`0.002s`).
- **Fresh Evidence JSON** (`1-paired-online-cash-pilot-evidence.json`, SHA `0bfaba51e8ab55ae49ab5bf05f374fe4091d48c2a694dd4b810e120049246c5d`):
  - Overall status: **`PASS`**.
  - `readiness_probe`: **`PASS`** (HTTP 200, latency 306ms, Host loopback HTTPS GET probe verified HTTP 200 with matching nonce).
  - All 6 Financial Scenarios: **`NOT_RUN`** (`financial_cash_fresh_commit_201`, `financial_cash_idempotent_replay_200`, `financial_cash_event_status_posted`, `financial_cash_tamper_409`, `transport_lost_ack_recovery`, `stock_permission_rollback_probes`).
  - **No Full Pilot Claim**: Readiness-only pass is strictly bounded; zero financial execution claimed.
- **Serialization Omission Disclosure**:
  - The original Android fresh JSON (`0bfaba...`) `toJson` method serialized only `clientHead: 78ac4437e4010b68408278788dc82eb826fe8014` and omitted a separate `clientPublicationHead` property.
  - The original JSON content and hash are preserved unaltered (no rewriting).
  - This report and the wrapper manifest independently record the Root-verified publication HEAD `653327243b2f5a4541c5fcf8cbe9520769e6be50` alongside execution HEAD `78ac4437e4010b68408278788dc82eb826fe8014` without requiring a rerun.
- **Android Repository Integrity**:
  - `android_pre.json`: `3f948f6cfcc23bef892c96430326447a094f3a272b6ee4c605406b726415a1aa` (HEAD `6533272...`, status clean, 197 files).
  - `android_post.json`: `1fee9e7b4278b0904013d8f7eba38d7097b72bcaa3d93740ecf1261530e4d657` (HEAD `6533272...`, status `?? app/docs/`, 197 files identical).
  - Both pre and post contain 197 identical source file hashes (zero source mutations). The untracked `app/docs/` contains only the generated evidence JSON.

#### Backend Hold Interruption & Intentional SIGTERM Drill
- **Process PID**: `1204264`
- **Interrupt Signal**: SIGTERM issued intentionally by Root following evidence capture.
- **Signal Handler Response**: Caught SIGTERM, logged `Readiness hold ABORTED by interrupt signal SIGTERM.`, and exited with code **1** (`FAILURE`, non-zero).
- **Cleanup Guarantee**:
  - Exact owned MariaDB schema `accounting_p8_tmp_84fa14fb4cb2` dropped (verified absent).
  - Exact owned directory `/tmp/v2_harness_c8253786b061d82a` absent (verified deleted).
  - Loopback port `18080` and TLS proxy port `18443` verified closed via Python `socket.connect_ex` non-zero (connection refused).

---

## 5. Retained Evidence Artifacts Matrix (`/tmp/root-readiness-ooug5prl`)

All 13 artifacts verified against private files:

| File Name | SHA-256 Digest | Bytes | Description |
|---|---|---|---|
| `0-TEST-...SmallTraderOnlineCashPilotSmokeTest.xml` | `51e834a7f5d358e0e6544eb03b355e40130e0455d0d2c8de9b7099390b07c6eb` | 691 | JUnit test report: 2 tests, 2 PASS, 0 FAIL, 0 SKIP, 0 error |
| `1-paired-online-cash-pilot-evidence.json` | `0bfaba51e8ab55ae49ab5bf05f374fe4091d48c2a694dd4b810e120049246c5d` | 2,184 | Fresh client evidence JSON: readiness PASS, all 6 financial NOT_RUN |
| `android.log` | `be9aa06a8259901b324f0e7d90a3999ef0e6f52272fbcbc4b0f44e326068c283` | 2,379 | Raw Gradle test task execution output (BUILD SUCCESSFUL in 13s) |
| `android_command.json` | `72219dce6bd6be895b6c45c8c8211cef0881b2ebfa108f43726c9d0d4dbe7f93` | 1,206 | Gradle command-line arguments and runtime environment pins |
| `android_post.json` | `1fee9e7b4278b0904013d8f7eba38d7097b72bcaa3d93740ecf1261530e4d657` | 27,155 | Post-execution state JSON (197 files identical to pre; untracked app/docs/) |
| `android_pre.json` | `3f948f6cfcc23bef892c96430326447a094f3a272b6ee4c605406b726415a1aa` | 27,141 | Pre-execution state JSON (HEAD 6533272..., 197 source file hashes) |
| `android_result.json` | `8f9da64aa1645666d9ecba248a216e1774879017bfcac0d1b6b3333cccebf623` | 1,041 | Android execution result metadata (exit 0, duration 13.3375s) |
| `backend.log` | `fd7eedd59cd0b334a4d9305b8c65ec0697d8cff0c9d792602466f462202c5ebc` | 756 | Backend harness execution log with SIGTERM abort and cleanup |
| `backend_command.json` | `a47fac07e5deeabbac9b810abab17047c81004a3f55f3307de0ba214b359120b` | 428 | Backend harness command invocation arguments |
| `live_handoff_snapshot.json` | `8e48f3692a88f5ac3f333f81536d70eb3541228959968697fda03f68df44befc` | 5,457 | Transient runtime handoff artifact snapshot (now deleted) |
| `live_verified.json` | `42528b2ead0e60c3bb1e35b727044e6e0ca9b7ad19dab180012527057ab76704` | 728 | Root TLS probe outcomes (GET 200, POST 405, safe GET 403, wrong CA rejection) |
| `manifest.json` | `88c8c2e2389f7486e73c74f4f660aa0621bf3c8486a85590b6c4bbb44bb83f5f` | 5,118 | Pairing manifest defining authorized scope `ROOT_AUTHORIZED_READINESS_ONLY` |
| `root_outcome.json` | `dd267bc4f3a505d1558020758545ac1e40159114eca0b9538fb9f37839aeffe7` | 3,525 | Root structured run verdict: `READINESS_JVM_TESTS_PASS` |

---

## 6. Cleanup Scope & Verification

Cleanup was independently verified for exact owned resources:
- **Owned MariaDB Schema**: Exact owned schema `accounting_p8_tmp_84fa14fb4cb2` dropped (verified absent via MariaDB information schema; no wildcard drops).
- **Owned Temporary Directory**: Exact owned directory `/tmp/v2_harness_c8253786b061d82a` absent (verified deleted).
- **Network Sockets**: Ports `127.0.0.1:18080` (HTTP loopback) and `127.0.0.1:18443` (TLS proxy) verified closed via Python `socket.connect_ex` non-zero (connection refused).
- **Working Tree**: `git status` clean (zero uncommitted edits).
- **Lease Status**: Explicitly **FREE / RELEASED**.

---

## 7. Review 5906317848 Gate Checklist Status

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
| Android paired client execution (Run 5) | **VERIFIED** | 1 JVM invocation, exit 0, 2 tests PASS in 13.3375s; readiness PASS |
| Root SIGTERM abort drill & schema drop | **VERIFIED** | PID 1204264 aborted by SIGTERM, exited 1, dropped schema `accounting_p8_tmp_84fa14fb4cb2`, closed ports |
| Failure first stop honored across all runs | **VERIFIED** | Honored across all 5 runs; zero automatic retries |
| Outer financial pilot qualification | **PENDING** | All 6 financial scenarios are `NOT_RUN`; requires reviewable disposable financial plan and individual pass of critical scenarios |
| Live SIGINT drill on active hold | **PENDING** | SIGTERM verified; SIGINT drill pending future pass |
| Intermediate transport crash drill during active traffic | **PENDING** | Pending future coordinated drill |

---

## 8. Guide to Financial Acceptance Criteria

Any future live paired execution must strictly require and verify the canonical financial models, public DTOs, and settlement rules:

### 8.1 Canonical Domain Entities & Public Identity
1. **SalesInvoice**: Model `SalesInvoice` persisted with status `posted` (tenant company, customer, currency, line items). Public ID in wire DTOs is a ULID (`invoice_id`).
2. **CustomerPayment**: Model `CustomerPayment` persisted with status `posted` (method `cash`, non-null `cash_account_id`). Public ID in wire DTOs is a ULID (`payment_id`).
3. **CustomerPaymentAllocation**: Model `CustomerPaymentAllocation` binding `CustomerPayment` to `SalesInvoice`, reconciling invoice balance.
4. **StockMovement**: Model `StockMovement` recording physical ledger deduction in base inventory units.
5. **MobileEventReceipt**: Model `MobileEventReceipt` recording execution receipt. In the public wire contract, receipt identity is strictly the client UUID (`client_event_id`). There is **no public ULID receipt contract**.

### 8.2 DTO Structure & Parity Rules
- **Commit Wire DTO (10 keys)**: Returns `client_event_id` (UUID), `status` (`'posted'`), `invoice_id` (ULID), `invoice_number`, `payment_id` (ULID), `payment_receipt_number`, `grand_total`, `amount_paid`, `change_returned`, `posted_at`.
- **Status Wire DTO**: Returns `client_event_id`, `company_id`, `status` (`'posted'` or `'unknown'`), `payload_digest`, `official_invoice`, `conflict`, `queried_at`.
  - The `official_invoice` field is a **6-field subset** (`invoice_id`, `invoice_number`, `payment_id`, `payment_receipt_number`, `grand_total`, `posted_at`).
  - Parity assertions must compare matching invoice/payment public ULIDs, invoice numbers, and totals across the shared fields; do **not** claim identical 10-key receipt structures between commit and status.
- **Rejection of Unsettled States**: A receipt marked `PostedWithoutPaymentReference` or lacking allocation is **never** accepted as a full cash settlement success.

### 8.3 Outer Financial Pilot Acceptance Criteria
- Full pilot acceptance **must require each required critical scenario to PASS separately**:
  1. Fresh online cash sale commit and status query verification.
  2. Idempotent exact replay verification.
  3. Lost ACK recovery / status replay verification.
  4. Conflict rejection on client event ID reuse with altered payload.
- A basic smoke PASS with lost ACK / rollback NOT RUN is **never** a full pilot PASS.

### 8.4 Overtender & Wire Settlement Semantics
- **Backend Full Cash Wire Contract**: At the backend financial slice, the request `payment.amount` is the exact settlement amount applied to the invoice (`change_returned` = `0.000000` on the wire).
- **Client Tender Calculation**: Overtender and customer change calculation is managed durably on the Android client (`OfflineSaleV2` local tender/change state where `tender_amount >= net_total` and `local_change = tender_amount - net_total`).
- **No Unsupported Backend Fields**: Do not impose non-existent or unsupported tender/change fields onto the backend cash slice request payload.
