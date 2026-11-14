# PR #102 Harness Readiness-Only Provenance & Evidence Report

**PR**: [#102](https://github.com/Bassamalsaqqa/account/pull/102) (`t3/recover-cash-writer`)  
**Repository**: `Bassamalsaqqa/account`  
**Review Context**: OWNER Review `5905683371` personally fetched PR #102 at commit `6e8c01cbfed9d11fd79e50699ff1a51ae8f4e72e` (2026-11-14 16:06:33Z).  
**Operational Scope**: Sole writer lane, documentation and evidence publication only. Zero database connections, zero listening sockets, zero background jobs, zero child subagents.  
**Accepted Core Foundation**: Prior core qualification (187 tests, 1,440 assertions, route cache DAG, and company-root serialization invariants at `6981511405d7bcd04cd8f8a09e64443ce0aaf47e`) is **accepted** and not redone.

---

## 1. Provenance & Cryptographic Pins Matrix

All digests below are deterministic SHA-256 hashes computed directly from disk:

| Component / File Path | SHA-256 Digest | Status / Purpose |
|---|---|---|
| `docs/integration/mobile-v2-client-handoff-schema.json` | `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275` | **Frozen**: JSON Schema enforcing full cash restriction, ULID format, startup TLS structure, and strict TTL |
| `tests/Support/Harness/DisposableMobileV2BackendHarness.php` | `c84162a7d20e231f4cf41e13de665b5ee8c220caf379225d62f2cfcc01bfce17` | **Source Fixture**: Autoload-only require sequenced before child readiness route handler, extracted template generator |
| `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php` | `bc2f388eb4c889e2088728bceb5d4d1fb1acb07c2828fc84ed7d980a5833ce08` | **Pure Guard Suite**: 44 tests, 163 assertions, zero DB sockets, zero listeners, includes fresh subprocess router test |
| `tests/Support/Harness/MobileV2HarnessCommand.php` | `bb951b183ba2249cf76b43a18638d78fb27829fa3dfa948ea515c4ad8d1e8d92` | **CLI Command**: Bounded `--readiness-only` and paired `--run` dispatching |
| `tests/Support/Harness/mobile-v2-harness-cli.php` | `3de792b8b584e69a202248976885e4c5cc819e53707f0462d3e4c6dc1330400e` | **CLI Runner**: Pre-framework lifecycle scope validation and isolated environment configuration |
| `runtime-pairing-manifest.json` *(private runtime)* | `5d829519aae663812f9b73604fa270b810c34b3e6157ae5bd0c9e06c628669a9` | **Runtime Pairing Manifest**: Authorized scope `ROOT_AUTHORIZED_READINESS_ONLY`, publication `019ea`, executable `ddfc` |

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

All changes on branch `t3/recover-cash-writer` since `6981511405d7bcd04cd8f8a09e64443ce0aaf47e` reside strictly within `tests/Support/Harness/`, `tests/Unit/Mobile/Harness/`, `docs/integration/`, and `docs/evidence/`. Zero production flags or configuration policies were altered.

---

## 3. Current Harness Execution Provenance (`6e8c01cbfed9d11fd79e50699ff1a51ae8f4e72e`)

### 3.1 Pure Guard Test Suite
- **Command**: `php vendor/bin/phpunit -c phpunit.xml tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`
- **Result**: `OK (44 tests, 163 assertions)`
- **Duration**: `0.277s - 0.345s`
- **Resources**: 0 database connections, 0 listening ports, 0 external network calls.
- **Coverage Highlights**:
  - Pre-socket invariant refusals (missing listener gate, host/port deviations, unapproved Android candidates).
  - Lifecycle scope matrix enforcement (`ROOT_AUTHORIZED_LOCAL_PROVISION_ONLY`, `ROOT_AUTHORIZED_READINESS_ONLY`, `ROOT_AUTHORIZED_PAIRED_EXECUTION`).
  - Strict JCS canonical serialization and wire byte SHA-256 integrity.
  - Recomputed `OnlineSaleIntentProfile` digest mismatch detection.
  - Fresh unprimed PHP subprocess router test verifying classloader isolation pre-framework (GET 200, POST 405 Method Not Allowed, non-readiness 403 Forbidden).

### 3.2 Historical vs Current Execution Context
- **Prior Standalone Provision Run**: Performed at commit `4a2e0b28f8b4a4bced4a4aed3e7f69abaf7c3ac1` (recorded in prior audit notes).
- **Current Head Execution (`6e8c01cbfed9d11fd79e50699ff1a51ae8f4e72e`)**: Executed via foreground bounded `--run --readiness-only` under isolated environment `env -i`.
  - **Duration**: `8.236` seconds (well within the finite <= 60s constraint).
  - **Exit Code**: `0`.
  - **Transient Schema**: `accounting_p8_tmp_ad42c06ee92a` (created on MariaDB port 3342, migrated, seeded, and verified dropped).
  - **Retained Log Source**: Extracted from `/tmp/private_harness_readiness/sanitized_readiness_report.json`.

---

## 4. Captured Artifact Qualification & Security Boundaries

Live artifacts were inspected and qualified prior to automatic cleanup:

| Property | Value / Hash | Security & Integrity Note |
|---|---|---|
| **Lifecycle Phase** | `PROVISIONED_AWAITING_TLS_START` | Captured at provision phase before TLS listener start |
| **Captured Handoff SHA-256** | `964e7bcfe6b8a140f043e921e99da6020258434b6649a47dcf9225c7ddcad6d3` | **Pre-start only**: strictly the provisioned handoff artifact, NOT an `ACTIVE_TLS_READY` or Root-approved ready file hash |
| **Startup TLS Status** | `UNAVAILABLE_AWAITING_TLS_START` | Nonce and URLs are null at provision; proxy starts downstream |
| **Generated Test CA Cert SHA-256** | `478a81b5dc2b3e7ac34abf365d82c1ada3d8cfe53c63289d40daec58ac7cd9e2` | Generated in private bootstrap directory; attached separately |
| **Raw Wire Payload SHA-256** | `17439ef479eef0328b178c72e0b5214bcd8937eac1b20de95fed132ed3488822` | RFC 8785 canonical JCS wire byte integrity hash |
| **Canonical Intent Profile Digest** | `sha256:e63c977f609de1794833d2dca34978ddfbf7126af3a30a6a202bf14b1689a86f` | Recomputed authoritative profile digest from synthesized context |
| **Synthesized Context IDs** | Company: `01M7DFH8JRTYG1CE3TXX8Z5ZT8`<br>Actor: `01M7DFH8JJTDRRZHTB7K4JJV6S` | Public ULID identifiers only |
| **Confidential Data** | **ZERO EXPOSURE** | Raw bearer tokens, private keys, database passwords, and schema ownership proofs remain strictly in 0600 private files; zero published to stdout/repo |

---

## 5. Cleanup Scope & Verification

Following execution completion, cleanup was verified independently:
- **MariaDB Schemas**: Exactly `0` test schemas remaining (`SHOW DATABASES LIKE 'accounting_p8_tmp_%'` returned empty).
- **Temporary Directories**: All `/tmp/v2_harness_*` directories fully deleted.
- **Network Sockets**: Ports `127.0.0.1:18080` (HTTP loopback) and `127.0.0.1:18443` (TLS proxy) verified closed and free via `ss -tulpn`.
- **Processes**: Zero orphan `php -S` or `caddy` child processes remaining.
- **Working Tree**: `git status` clean.
- **Lease Status**: Explicitly **RELEASED**.

---

## 6. Record of Unexecuted Gaps & Review Requests

In strict adherence to reporting integrity, the following items from ongoing reviews were **not executed** in this pass and remain open gaps:

1. **Readiness TLS Negative Test Cases**:
   - Negative CA certificate validation (connecting with an untrusted CA cert to verify TLS handshake rejection).
   - Corrupted or mismatched readiness nonce validation over the live TLS proxy.
   - HTTP method/path negative cases (e.g. `POST /_harness/readiness` or invalid probe paths) were verified in the isolated pure PHP subprocess router test, but were **not** executed against the live Caddy TLS proxy.
2. **Intermediate Process Failure Recovery**:
   - Simulated ungraceful child termination (e.g. killing the `php -S` backend process or crashing Caddy while the proxy is actively serving) was **not** exercised live on actual transport.
3. **Standalone Mode Logs on Current HEAD**:
   - Exact execution logs for standalone `--prepare` and standalone `--provision` on commit `6e8c01cbfed9d11fd79e50699ff1a51ae8f4e72e` were not contemporaneously retained (the only retained live log on `6e8c` is `--run --readiness-only`). Missing historical logs are not retro-constructed.
4. **Live Financial Route 403 Network Proof**:
   - Live HTTP requests to financial routes (`/api/mobile/v2/.../sales/online-cash`) were **not** issued over the network during the readiness run, as readiness-only lifecycle scope strictly forbids network calls to financial routes. Financial refusal was proven solely at the source/router boundary.

---

## 7. Review 5905683371 Gate Checklist

| Item | Status | Verification & Evidence |
|---|---|---|
| Core 187/1440 qualification & route cache DAG invariance | **ACCEPTED** | Verified 100% byte-identical against `6981511405d7bcd04cd8f8a09e64443ce0aaf47e` |
| Child process autoload sequencing before readiness route | **ACCEPTED** | Verified in `DisposableMobileV2BackendHarness.php` lines 749-756 |
| Pure guard test suite passing | **ACCEPTED** | 44 tests, 163 assertions, 0 errors, 0 failures in `0.277s` |
| Subprocess router execution test | **ACCEPTED** | `test_child_bootstrap_template_execution_in_fresh_subprocess` passes in fresh `php` process |
| Lifecycle scope assertions | **ACCEPTED** | `assertLifecycleScope` enforces `ROOT_AUTHORIZED_READINESS_ONLY` fail-closed |
| Worker TLS readiness probe | **ACCEPTED** | Worker-side bounded attestation: HTTP 200 with matching nonce verified via Caddy TLS |
| Bounded cleanup on shutdown | **ACCEPTED** | Root-verified: schemas=0, temp dirs absent, ports free, worktree clean |
| Live TLS negative cases on live proxy | **PENDING** | Negative CA cert and live proxy nonce mismatch drills not yet executed |
| Intermediate process failure recovery | **PENDING** | Crash/kill transport recovery drill not yet executed |
| Android candidate qualification | **PENDING** | Android candidate `ad10` P1 fix underway; no approved new runner; no TLS start grant |
| Paired execution authorization | **PENDING** | Requires new reviewed Android runner and fresh Root-verified `ACTIVE_TLS_READY` handoff hash |

---

## 8. Guide to Financial Acceptance Criteria

Any future live paired execution must strictly require and verify the following 5-point transaction graph:

1. **Canonical Invoice**: An `Invoice` record persisted with matching tenant company ID, customer ID, currency, and line items.
2. **Payment**: A `Payment` record persisted strictly with `payment_method = 'cash'`, status `completed`, and non-null cash account ID.
3. **Allocation**: An `InvoicePaymentAllocation` record binding the payment to the invoice, ensuring net balance consistency.
4. **Stock Ledger Entry**: A `StockMovement` recorded in base inventory units matching line item quantities.
5. **Receipt**: A `MobileEventReceipt` record with an authoritative ULID public ID, idempotently deduplicating on `client_event_id`.

### Financial Parity & Edge-Case Rules
- **Status & Replay Parity**: Fresh commit (HTTP 201), exact replay (HTTP 200), and status inquiry (`/offline-events/{clientEventId}/status`) must return identical canonical receipt IDs and totals.
- **Rejection of Unsettled States**: A receipt marked `PostedWithoutPaymentReference` or lacking allocation is **never** accepted as a successful full cash settlement.
- **Overtender Change Calculation**: Expected paid and net total amounts must strictly reflect overtender change (`change_amount = tendered_amount - net_total`). Naive client-side math assumptions that equate tendered cash directly to invoice net without change reconciliation are forbidden.
- **Client Semantics**: The backend remains the sole authority on tax computation, line item extension, currency minor units, and inventory valuation; client-submitted figures are validated, never blindly trusted.

---

## 9. Android Track Coordination Status

- **Peer Candidates**:
  - Publication HEAD: `019ea39af09558c2622356cb22235572747e3ddc`
  - Executable HEAD: `ddfc75536e647c65a6a7e8868c9336cee93769ec`
- **Candidate `ad10` Status**: Android candidate `ad10` review identified P1 changes required; fixes are currently underway source-first in the Android repository.
- **Authorization Boundary**: There is **no approved new Android runner**, **no TLS start grant**, and **no paired execution grant**. Historical qualification of `019ea` does not constitute acceptance of new Android mainline changes.
- **Next Paired Run Requirement**: Live paired execution requires an approved Android runner commit and a fresh Root-verified `ACTIVE_TLS_READY` handoff hash.
