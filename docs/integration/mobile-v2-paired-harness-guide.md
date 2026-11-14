# Paired Controlled HTTPS Disposable Backend Harness Guide

> **Status: SOURCE-ONLY PREPARATION — NO ACTIVE EXECUTION**  
> **Notice**: This document outlines how the upstream Android client connects to the controlled disposable backend harness for paired HTTPS integration testing.  
> **Strict Safety Guardrail**: No network listeners, PHP-S, Caddy, TLS daemons, or database connections are started during this preparation phase. The backend harness defaults strictly to `NO-EXECUTE`. Execution requires explicit Root coordination after Android client test readiness is confirmed.

---

## 1. Provenance, Base Tree & Qualification Pins

The backend harness is built on exact qualified source pins:
- **Backend Base Branch**: `t3/recover-cash-writer`
- **Execution Head SHA**: `6981511405d7bcd04cd8f8a09e64443ce0aaf47e`
- **Evidence Publication SHA**: `717c3da2829792a5ec4f11597905a17dbb8e7cd9`
- **Peer Android PR Base SHA**: `0ccce244`
- **Integration Dual-Manifest**: [mobile-v2-integration-manifest.json](mobile-v2-integration-manifest.json)
- **Harness Bootstrap Class**: [DisposableMobileV2BackendHarness.php](../../tests/Support/Harness/DisposableMobileV2BackendHarness.php)

---

## 2. Authoritative Route Behavior & Code Citations

Both public Mobile V2 endpoints are defined and handled as follows:

### A. Online Cash Sale Commit Endpoint
- **Route Definition**: [routes/mobile-v2.php](../../routes/mobile-v2.php#L22-L26)
  ```php
  Route::post('/companies/{companyId}/sales/online-cash', [OnlineCashSaleController::class, 'store'])
      ->name('mobile.v2.sales.online-cash')
      ->middleware('mobile.v2.auth:sales.invoice.create');
  ```
- **Handler Implementation**: [OnlineCashSaleController.php](../../app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php#L30-L144)
- **HTTP Status Codes & Behavior**:
  - `HTTP 201 Created`: Fresh execution. All financial legs committed atomically under Company-root serialization ([OnlineCashSaleController.php:136](../../app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php#L136)).
  - `HTTP 200 OK`: Idempotent replay of previously committed event. Returns byte-for-byte identical `SaleCommitResponse` ([OnlineCashSaleController.php:136](../../app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php#L136)).
  - `HTTP 409 Conflict`: Duplicate `client_event_id` with altered/tampered payload. Handled via `MobileEventDuplicatePayloadException` returning standard code `CONFLICT` ([OnlineCashSaleController.php:116-121](../../app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php#L116-L121)).
  - `HTTP 422 Unprocessable`: Empty request body, invalid JSON syntax, domain validation errors, or inventory insufficiency ([OnlineCashSaleController.php:63, 79, 98, 122](../../app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php#L63)).
  - `HTTP 401 Unauthorized`: Missing or malformed `Authorization: Bearer` header, revoked or expired token ([MobileV2Authenticate.php:92, 169](../../app/Http/Middleware/MobileV2Authenticate.php#L92)).
  - `HTTP 403 Forbidden`: Actor missing required financial permissions, non-member, or tenant mismatch ([MobileV2Authenticate.php:267, 280, 289, 306, 332](../../app/Http/Middleware/MobileV2Authenticate.php#L267)).
  - `HTTP 404 Not Found`: Gating flag OFF (`mobile.v2_cash_slice_enabled = false`) or master mobile API disabled ([OnlineCashSaleController.php:36-41](../../app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php#L36-L41)).
  - `HTTP 408 Request Timeout`: Bounded transport disconnect or socket read timeout.
  - `HTTP 415 Unsupported Media Type`: Non-JSON `Content-Type` on mutating POST request ([MobileV2ApiBoundary.php:218-222](../../app/Http/Middleware/MobileV2ApiBoundary.php#L218-L222)).
  - `HTTP 429 Too Many Requests`: Actor or IP rate limit exceeded ([MobileV2ApiBoundary.php:159](../../app/Http/Middleware/MobileV2ApiBoundary.php#L159), [MobileV2Authenticate.php:248](../../app/Http/Middleware/MobileV2Authenticate.php#L248)).

### B. Offline Event Status Query Endpoint
- **Route Definition**: [routes/mobile-v2.php](../../routes/mobile-v2.php#L28-L32)
  ```php
  Route::get('/companies/{companyId}/offline-events/{clientEventId}/status', [OnlineCashSaleController::class, 'status'])
      ->name('mobile.v2.offline-events.status')
      ->middleware('mobile.v2.auth:sales.invoice.view');
  ```
- **Handler Implementation**: [OnlineCashSaleController.php](../../app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php#L146-L228)
- **HTTP Status Codes & Behavior**:
  - `HTTP 200 OK (posted)`: Event previously committed. Returns `SaleEventStatusResponse` with `status = "posted"` and `official_invoice` object populated ([OnlineCashSaleController.php:220-227](../../app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php#L220-L227)).
  - `HTTP 200 OK (unknown)`: Event was never processed by the company. Returns `SaleEventStatusResponse` with `status = "unknown"`, `official_invoice = null`, and `conflict = null` ([OnlineSaleWireResponseProjection.php:84-91](../../app/Support/Mobile/Contract/Revision3/OnlineSaleWireResponseProjection.php#L84-L91)).
  - `HTTP 422 Unprocessable`: Malformed `clientEventId` path parameter failing version-agnostic UUID regex layout ([OnlineCashSaleController.php:177-183](../../app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php#L177-L183)).
  - `HTTP 401 Unauthorized`: Missing or invalid bearer token ([MobileV2Authenticate.php:92](../../app/Http/Middleware/MobileV2Authenticate.php#L92)).
  - `HTTP 403 Forbidden`: Actor missing view permissions, non-member, or foreign tenant ([MobileV2Authenticate.php:267, 332](../../app/Http/Middleware/MobileV2Authenticate.php#L267)).
  - `HTTP 404 Not Found`: Cash slice disabled or master mobile API disabled ([OnlineCashSaleController.php:152-157](../../app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php#L152-L157)).
  - `HTTP 429 Too Many Requests`: Actor or IP rate limit exceeded ([MobileV2ApiBoundary.php:159](../../app/Http/Middleware/MobileV2ApiBoundary.php#L159)).

---

## 3. Server-Synthesized Context vs Client Wire Request

The client request wire JSON is strictly decoupled from server-internal context:

```
CLIENT WIRE REQUEST JSON                SERVER-INTERNAL CONTEXT (Synthesized)
------------------------------------    ------------------------------------
client_event_id (UUID 8-4-4-4-12)       company_id (from Route parameter)
device_id (UUID 8-4-4-4-12)             actor_id (from Bearer Token User)
customer_id (ULID 26 chars)             currency_minor_units (from CurrencyMinorUnitsResolver)
warehouse_id (ULID 26 chars)
currency (ISO 4217, e.g. "ILS")
lines [ { product_id, product_unit_id, quantity, unit_price, tax_rate_id, ... } ]
payment { settlement_mode, payment_method, cash_account_id, amount }
```

Client requests **must never** submit `actor_id` or `currency_minor_units` on the wire; these are resolved authoritatively by backend controllers from session and company state.

Authoritative synthetic request example with valid Crockford Base32 ULIDs (26 characters):
```json
{
  "client_event_id": "c0686be5-888f-4890-90bf-1f05f416a19a",
  "device_id": "f81d4fae-7dec-11d0-a765-00a0c91e6bf6",
  "customer_id": "01M7CZPF6TP283F3ZRXF5P8GT3",
  "warehouse_id": "01M7CZPF6TP283F3ZRXF5P8GT4",
  "currency": "ILS",
  "lines": [
    {
      "product_id": "01M7CZPF6TP283F3ZRXF5P8GT5",
      "product_unit_id": "01M7CZPF6TP283F3ZRXF5P8GT6",
      "conversion_to_base": "1.000000",
      "quantity": "1.000000",
      "unit_price": "50.000000",
      "tax_rate_id": "01M7CZPF6TP283F3ZRXF5P8GT7",
      "tax_rate": "16.000000",
      "tax_inclusive": false
    }
  ],
  "payment": {
    "settlement_mode": "full",
    "payment_method": "cash",
    "cash_account_id": "01M7CZPF6TP283F3ZRXF5P8GT8",
    "amount": "58.000000"
  }
}
```

---

## 4. How Android Connects via Trusted Controlled HTTPS

When Root explicitly coordinates execution, the harness establishes a controlled, self-signed local TLS listener:

1. **Certificate Authority & SAN**:
   - The harness generates a dedicated private test CA and server certificate.
   - **Subject Alternative Name (SAN)**: Explicitly bound to `DNS:localhost, IP:127.0.0.1, IP:10.0.2.2` (for Android Emulator access).
2. **Android Trust Configuration**:
   - The test CA certificate will be exported to a dedicated location for Android test builds.
   - The Android integration test harness imports the test CA into its network security config or OkHttpClient trust manager.
   - **No Trust-All or Cleartext Fallback**: The client must explicitly validate the test CA certificate. Cleartext HTTP (`http://`) or wildcard certificate trust is prohibited.
3. **Loopback & Emulator Mapping**:
   - Android Emulator maps host `127.0.0.1` to `10.0.2.2`.
   - Android test client configures base URL: `https://10.0.2.2:<port>/api/mobile/v2/`.

---

## 5. Lost-ACK Simulation & Reconcile Verification

To test network drops and retry behavior without financial backdoors:

1. **Test-Harness-Only Simulation**:
   - The harness or HTTP proxy simulates a network disconnection immediately after commit execution before the response bytes are received by the client.
2. **Client Recovery Sequence**:
   - Upon timeout / lost ACK, the Android client retries `POST /sales/online-cash` with the identical `client_event_id` and payload.
   - The backend receipt manager detects the duplicate event ID and returns `HTTP 200 OK` with the exact original `SaleCommitResponse`.
   - Alternatively, client issues `GET /offline-events/{clientEventId}/status` to confirm the terminal state.
3. **6 Post-Failure Reconciliation Assertions**:
   1. Receipt record in `mobile_event_receipts` remains singular (no duplicate receipt).
   2. Invoice record in `sales_invoices` has exactly one record created.
   3. Customer payment in `customer_payments` has exactly one record created.
   4. Cash account balance in `money_accounts` is credited exactly once.
   5. Stock movement ledger has exactly one lot deduction.
   6. Replay response body matches fresh commit body byte-for-byte.

---

## 6. Cleanup & Lifecycle Guarantees

- **Owned Processes & Sockets**: On test completion or interruption, any spawned listener PID is terminated immediately.
- **Owned Database Schemas**: The disposable schema (`accounting_p8_tmp_*`) on 127.0.0.1:3342 is dropped via `DisposableMariaDbSchema::drop()`. Foreign schemas are never touched.
- **Temporary Files & Nonces**: Temporary directories in `sys_get_temp_dir()` containing proof nonces and runtime credentials (`chmod 0600`) are completely unlinked.

---

## 7. Critical Contract Handoff & Kotlin Fixture Parity Guidance

### A. Raw Online 7 Keys vs Durable Offline Envelope
The upstream Android client must maintain strict separation between the internal durable offline envelope (15 fields) and the raw online wire request (7 keys):

```
RAW ONLINE WIRE REQUEST (7 KEYS ONLY)
-------------------------------------
1. client_event_id   (UUID v4 string, RFC 4122 format)
2. device_id         (UUID v4 string, RFC 4122 format)
3. customer_id       (ULID 26-char string)
4. warehouse_id      (ULID 26-char string)
5. currency          (ISO 4217 3-letter currency code, e.g. "ILS")
6. lines             (Array of line items: product_id, product_unit_id, conversion_to_base, quantity, unit_price, tax_rate_id, tax_rate, tax_inclusive)
7. payment           (Payment object: settlement_mode, payment_method, cash_account_id, amount)
```

**Distinct Server-Synthesized Context (Resolved Server-Side, NEVER on Wire)**:
- `company_id`: resolved from route parameter `/companies/{companyId}/`
- `actor_id`: resolved from Bearer token user
- `currency_minor_units`: resolved from `CurrencyMinorUnitsResolver`

**Distinct Durable Offline Envelope (15 Fields)**:
- Do not blindly serialize the offline envelope to the online cash POST route.
- Lossless explicit semantic mapping and snapshot checks are required on the client.
- Do **not** silently strip offline fields and label mapping valid.

### B. Canonical Intent Profile Digest vs Wire Hash
The canonical payload digest:
```
sha256:0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630
```
is derived from `OnlineSaleIntentProfile(context, RAWrequest)` serialized under Canonical JSON (RFC 8785) rules. It is **NOT** a raw SHA-256 hash of the incoming HTTP POST wire bytes.
Existing validated fixture `ca5a` (`manifest_sha256: 3248b9721c3e2a09e84ae478ddb0171c3ea576fd0cbdd70b3606e4cdda4ff3e7`, digest: `0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630`) is authoritative.

### C. Kotlin Fixture Parity Rules
1. **No Invented Protocol Fields or Versions**: Do not introduce synthetic wrapper keys, schema version headers, or non-spec wire fields.
2. **No Payment ULID Fabrication**: Client must not fabricate ULID identifiers for payments.
3. **Mirror DTO Nullable Values Preserved**: Nullable fields in mirror DTOs must be preserved as nullable even though the full cash fixture has non-null expected payment.
4. **No Retired V1 Harness Fallback**: Use only the new owned V2 admitted private TLS manifest.
5. **Zero Live Web Changes / Financial Deny OFF**: Production financial deny remains active; zero changes to web accounting routes or live production code.

### D. Peer Android Status & Review Hold
- Candidate commit `39fea3eae32522240caa0db8f40d018694064d8f` (Android PR 23) reports unit 76 PASS but is marked **`CANDIDATE_KNOWN_BAD`** (changes required on client gateway).
- The harness refuses start with commit `39fea3eae32522240caa0db8f40d018694064d8f`.
- No HTTPS pairing or listener startup will occur until client corrections are reviewed and an approved head is provided by Root.

---

## 8. Retained Guard QA Evidence & Command Attestation (Commit 11def9bc3c26f9eeb2d261c73835088d8f68d8c1)

> **Notice**: Core harness source files (`tests/Support/Harness/*`, `tests/Unit/Mobile/Harness/*`) are strictly **SOURCE-FROZEN** at commit `11def9bc3c26f9eeb2d261c73835088d8f68d8c1`. The following attestations document the captured execution evidence produced during the short backend guard QA lease.

### A. Execution State & Tree Hash Verification
- **Exact Execution HEAD**: `11def9bc3c26f9eeb2d261c73835088d8f68d8c1`
- **Parent Commit (Before)**: `4babf9420d5711ed5a2d0fa244b3f1219f978738`
- **Base Qualification Commit**: `6981511405d7bcd04cd8f8a09e64443ce0aaf47e`
- **Core Tree Invariance**:
  - `git rev-parse HEAD:app`: `34d1da0ae82b182fa2c0adafd785416a86834a20` (identical to 698)
  - `git diff 6981511405d7bcd04cd8f8a09e64443ce0aaf47e HEAD -- app/ routes/ config/ composer.lock`: **0 bytes / 0 lines diff (100% clean)**
- **Tracked Template Manifest & Pins**:
  - `docs/integration/mobile-v2-integration-manifest.json` SHA-256: `5bfb4b06ae33ddf0084a3d2d99a41e714f0c64d97fa810b4ecdddf6f8e5f16c1`
  - In accordance with architectural rules, runtime execution pins in the tracked manifest are intentionally set to `UNSET_...` templates to prevent circular self-referential hash commits prior to code freeze.
  - Concrete runtime pairing parameters are pinned via the Root-reviewed private runtime pairing manifest:
    - Path: `/home/t3dev/.t3/userdata/attachments/private-runtime-pairing-manifest.json`
    - SHA-256: `c2839437f9a2b538cb919e9d98866565a492de048a4e5d2503a008cf65a31adc`
    - Approved Peer Android HEAD: `019ea39af09558c2622356cb22235572747e3ddc` (Implementation qualified executable: `ddfc75536e647c65a6a7e8868c9336cee93769ec`)
    - Backend Execution HEAD: `11def9bc3c26f9eeb2d261c73835088d8f68d8c1`

### B. Core Harness File SHA-256 Digest Inventory
| File | Path | SHA-256 Digest |
|---|---|---|
| Harness Fixture | `tests/Support/Harness/DisposableMobileV2BackendHarness.php` | `7f78c49df4fc28540d2d01a528183a8db5fa18bae4df3c998191322ba9c89d68` |
| CLI Runner | `tests/Support/Harness/mobile-v2-harness-cli.php` | `34b9176af6c4cb183cce8f1b8b41cd27f9fb934f47917d4b752df0b6694e503d` |
| Guard Test | `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php` | `f5ce3974cd8edfb3aa4bf4fd9826a4c397b21dce611ecf5058026a0d8703d9f0` |
| Harness Command | `tests/Support/Harness/MobileV2HarnessCommand.php` | `0b51e19e94ef9892f82c8f7d471d186f9f75c0fd9a08eb57f4fd7d08e58e8e99` |

### C. Pure Guard PHPUnit Suite (23 Tests, 64 Assertions — 100% PASS)
- **Command**:
  ```bash
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3342 DB_DATABASE=unconnectable_qa_lease_guard \
    php /home/t3dev/.t3/worktrees/account/t3-9723058c-3d0a7ebb15fba29a/vendor/bin/phpunit \
    --configuration /home/t3dev/.t3/worktrees/account/t3-9723058c-3d0a7ebb15fba29a/phpunit.xml \
    /home/t3dev/.t3/worktrees/account/t3-9723058c-3d0a7ebb15fba29a/tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php
  ```
- **Raw Execution Log**:
  ```text
  PHPUnit 12.5.36 by Sebastian Bergmann and contributors.

  Runtime:       PHP 8.4.27
  Configuration: /home/t3dev/.t3/worktrees/account/t3-9723058c-3d0a7ebb15fba29a/phpunit.xml

  .......................                                           23 / 23 (100%)

  Time: 00:00.026, Memory: 24.50 MB

  OK (23 tests, 64 assertions)
  ```
- **Attestation**:
  Executed against unconnectable database configuration (`DB_DATABASE=unconnectable_qa_lease_guard`). Zero database queries executed, zero framework kernels booted, zero socket listeners opened.

### D. Scoped Pint Style Verification (0 Violations)
- **Command**:
  ```bash
  vendor/bin/pint --test \
    tests/Support/Harness/DisposableMobileV2BackendHarness.php \
    tests/Support/Harness/MobileV2HarnessCommand.php \
    tests/Support/Harness/mobile-v2-harness-cli.php \
    tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php
  ```
- **Raw Output**:
  ```json
  {"tool":"pint","result":"passed"}
  ```

### E. PHPStan Static Analysis (Level 5 — 0 Errors)
- **Command**:
  ```bash
  php vendor/bin/phpstan analyse --configuration=phpstan.neon \
    tests/Support/Harness/DisposableMobileV2BackendHarness.php \
    tests/Support/Harness/MobileV2HarnessCommand.php \
    tests/Support/Harness/mobile-v2-harness-cli.php \
    tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php
  ```
- **Raw Output**:
  ```text
   0/4 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0% 4/4 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

   [OK] No errors
  ```

### F. CLI Runner Source-Only `--prepare` Verification
- **Command**:
  ```bash
  php tests/Support/Harness/mobile-v2-harness-cli.php --prepare
  ```
- **Raw Output**:
  ```text
  Mobile V2 Harness — Source-Only Prepare (zero sockets, zero DB, zero framework boot)
    QUALIFIED_EXECUTION_HEAD   : 6981511405d7bcd04cd8f8a09e64443ce0aaf47e
    QUALIFIED_APP_TREE         : 34d1da0ae82b182fa2c0adafd785416a86834a20
    REQUIRED_BASE_BRANCH       : t3/recover-cash-writer
    BACKEND_LOOPBACK_PORT      : 18080
    TLS_PROXY_PORT             : 18443
    ANDROID_CANDIDATE_KNOWN_BAD: 39fea3eae32522240caa0db8f40d018694064d8f
    CA5A_MANIFEST_SHA256       : 3248b9721c3e2a09e84ae478ddb0171c3ea576fd0cbdd70b3606e4cdda4ff3e7
    CA5A_CANONICAL_DIGEST      : 0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630

  TLS certificate covers SAN: localhost, 127.0.0.1, 10.0.2.2 (Android emulator)
  Android base URL (emulator):   https://10.0.2.2:18443/api/mobile/v2/

  To run foreground lifecycle (requires Root coordination & approved Android HEAD):
    MOBILE_V2_HARNESS_ENABLE=1 MOBILE_V2_HARNESS_START_LISTENER=1 PHASE8_ALLOW_DISPOSABLE_DB=1 \
      PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 \
      PHASE8_TEST_DB_USERNAME=<user> PHASE8_TEST_DB_PASSWORD=<pass> \
      php tests/Support/Harness/mobile-v2-harness-cli.php --run --backend-head=<40hex> --android-head=<40hex> \
        --pairing-manifest-sha=<64hex>

  Prepare phase complete. Zero framework booted. Zero listeners started.
  ```

### G. Database Isolation & Nonce Proof Trace Analysis
- **Harness & CLI Boundary Verification**:
  - `mobile-v2-harness-cli.php` isolates the environment before booting Laravel by purging global DB settings and explicitly overriding:
    - `DB_CONNECTION=mysql`
    - `DB_HOST=127.0.0.1`
    - `DB_PORT=3342`
    - `DB_DATABASE=v2_harness_unprovisioned`
    - `DB_URL=''`
  - **Child Process Isolation**:
    When the child backend server process is spawned by `DisposableMobileV2BackendHarness::startBackendServer()`, Symfony Process explicitly unsets parent database and security environment variables via `false` entries in the environment map (`DB_DATABASE => false`, `DB_USERNAME => false`, `DB_PASSWORD => false`, `DATABASE_URL => false`).
  - **Ownership Proof Requirement**:
    Before framework boot or PDO connection, the child process verifies `DisposableMariaDbSchema::assertPrimarySchema()`, ensuring the temporary schema ownership proof nonce (`PHASE8_DISPOSABLE_DB_NONCE`) matches the private file at `PHASE8_DISPOSABLE_DB_PROOF` (mode `0600`).
  - **Disclosed Limitation**:
    In `--provision` and `--run` modes, Laravel's service providers initialize configuration on kernel bootstrap. Therefore, strict dual manifest validation (`assertDualManifestPin`) and pre-socket format/branch invariants (`assertPreSocketInvariants`) run **strictly before** `Kernel::bootstrap()` and before `DisposableMariaDbSchema::create()`, ensuring that any pin mismatch, unapproved peer HEAD, or dirty core tree aborts execution at exit code 1 with zero database interactions.



---

## 9. Runtime Client Handoff JSON Artifact & Schema Specification

### A. Minimal Runtime Artifact Overview
To eliminate prose-only handoffs and provide a machine-verifiable contract for the Android client pairing thread, the harness generates a concrete private JSON artifact (`runtime_client_handoff.json`, mode `0600`) located within the harness private temporary directory (`/tmp/v2_harness_<nonce>/`).

The formal JSON Schema is located at [`docs/integration/mobile-v2-client-handoff-schema.json`](file:///home/t3dev/.t3/worktrees/account/t3-9723058c-3d0a7ebb15fba29a/docs/integration/mobile-v2-client-handoff-schema.json).

### B. Core Artifact Fields & Authoritative Rules
1. **Schema, Scope & Lifecycle Phase**:
   - `schema_version`: Strict `"1.0.0"`.
   - `handoff_scope`: `"ROOT_AUTHORIZED_READINESS_ONLY"` or `"ROOT_AUTHORIZED_PAIRED_EXECUTION"`.
   - `lifecycle_phase`: Explicitly declares harness readiness state:
     - `"PROVISIONED_AWAITING_TLS_START"`: Prepared disposable schema and fixture seeded; TLS proxy not yet launched (not execution success).
     - `"ACTIVE_TLS_READY"`: Supervised TLS proxy running, certificate generated, readiness probe verified.
     - `"READINESS_ONLY_VERIFIED"`: Supervised TLS running in strictly bounded readiness-only mode (financial endpoints blocked).
   - `created_at`: Strict ISO 8601 creation timestamp (e.g. `"2026-11-14T17:00:00+02:00"`).
   - `expires_at`: Strict ISO 8601 expiration timestamp (e.g. `"2026-11-14T18:00:00+02:00"`).

2. **Complete Mandatory Backend Runtime Pins (10 Pins, Zero Optional)**:
   - `qualified_core_head_sha`: Qualified core commit SHA (`6981511405d7bcd04cd8f8a09e64443ce0aaf47e`).
   - `backend_execution_head_sha`: Current worktree HEAD SHA.
   - `qualified_app_tree_sha`: Qualified app tree hash (`34d1da0ae82b182fa2c0adafd785416a86834a20`).
   - `peer_android_approved_head_sha`: Root-approved Android candidate HEAD (`019ea39af09558c2622356cb22235572747e3ddc`).
   - `peer_android_executable_head_sha`: Peer Android implementation qualified executable SHA (`ddfc75536e647c65a6a7e8868c9336cee93769ec`).
   - `source_fixture_sha256`: SHA-256 of `tests/Support/Harness/DisposableMobileV2BackendHarness.php`.
   - `guard_test_sha256`: SHA-256 of `tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`.
   - `cli_command_sha256`: SHA-256 of `tests/Support/Harness/MobileV2HarnessCommand.php`.
   - `cli_runner_sha256`: SHA-256 of `tests/Support/Harness/mobile-v2-harness-cli.php`.
   - `pairing_manifest_sha256`: SHA-256 pin of the pairing manifest.

3. **Endpoints**:
   - `base_url`: `https://10.0.2.2:18443/api/mobile/v2` (Android emulator loopback)
   - `loopback_base_url`: `https://127.0.0.1:18443/api/mobile/v2`
   - `routes`:
     - `online_cash_sale`: `/companies/{companyId}/sales/online-cash`
     - `offline_event_status`: `/companies/{companyId}/offline-events/{clientEventId}/status`
     - `readiness_probe`: `/_harness/readiness`

4. **Authoritative Fixture & Context (No Hardcoded Defaults)**:
   - `fixture`: Schema name, tenant company public ID, staff actor public ID, device ID, customer, warehouse, product, unit, tax rate, and cash money account public IDs. Includes fresh resolved `currency_minor_units`.
   - `server_synthesized_context`:
     - `company_id`: Company public ULID
     - `actor_id`: Staff actor public ULID
     - `currency_minor_units`: Dynamically resolved from company base currency via `App\Services\Money\CurrencyMinorUnitsResolver::resolve($currencyCode)` (integer 0..6, no static fallbacks).

5. **Lossless Raw Wire Sample Bytes (Wire-Byte Integrity ONLY)**:
   - `raw_wire_sample`:
     - `raw_wire_payload_json`: Exact serialized wire string bytes of the raw 7-key request (`client_event_id`, `device_id`, `customer_id`, `warehouse_id`, `currency`, `lines`, `payment`).
     - `raw_wire_payload_sha256`: SHA-256 hash of `raw_wire_payload_json` for wire-byte integrity verification ONLY.
     - `parsed_request`: Normative structured JSON object preserving exact decimal lexemes.

6. **Canonical OnlineSaleIntentProfile Digest**:
   - The digest is computed authoritatively using `App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile::construct($serverContext, $sampleRequest)` and `OnlineSaleIntentProfile::computeDigest($profile)`.
   - Follows RFC 8785 JSON Canonicalization Scheme (JCS) and Revision 3 normalization rules.
   - Distinct from raw wire body SHA-256. Formatted as `sha256:<64hex>`.

7. **Historical CA5A Reference Kept Separate**:
   - `historical_ca5a_reference`:
     - `ca5a_manifest_sha256`: `3248b9721c3e2a09e84ae478ddb0171c3ea576fd0cbdd70b3606e4cdda4ff3e7`
     - `ca5a_canonical_digest`: `0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630`
   - Preserved purely for historical fixture audit; never substituted for the fresh runtime digest.

8. **Private Credentials Path**:
   - `credentials_path`: Points to `runtime_credentials.json` (chmod `0600`), storing `raw_token`, `token_hash`, `device_id`, `staff_email`, and `schema`.
   - Raw tokens are never outputted to stdout, logs, or public command arrays.

9. **Lifecycle-Governed Startup TLS**:
   - During `provision()` (`"PROVISIONED_AWAITING_TLS_START"`):
     - `status`: `"UNAVAILABLE_AWAITING_TLS_START"`
     - `ca_cert_path`: `null`
     - `ca_cert_sha256`: `null`
     - `readiness_url`: `null`
     - `readiness_nonce`: `null`
   - Upon `start()` (`"ACTIVE_TLS_READY"` or `"READINESS_ONLY_VERIFIED"`):
     - `status`: `"ACTIVE_TLS_READY"`
     - `ca_cert_path`: `<harnessTempDir>/bootstrap/test-ca.pem`
     - `ca_cert_sha256`: SHA-256 of generated test CA certificate
     - `readiness_url`: `https://127.0.0.1:18443/_harness/readiness`
     - `readiness_nonce`: Active 128-bit hex harness nonce

10. **Fail-Closed Artifact Validation**:
    - `DisposableMobileV2BackendHarness::validateClientHandoff(array $handoff)` strictly validates all required keys, pin counts, SHA-256 checksums, and non-fabricated TLS fields before artifact emission. Any malformed artifact throws `RuntimeException` and fails closed immediately.

---

## 10. Strictly Bounded Readiness-Only Mode (`--readiness-only`)

### A. Operational Boundary
The `--readiness-only` mode provides a strictly isolated verification step that validates TLS CA trust, loopback routing, and the `/_harness/readiness` nonce probe without opening financial or tenant endpoints to traffic:
- **Financial Endpoints Blocked**: Child router strictly returns `HTTP 403 Forbidden` (`{"error": "forbidden", "message": "Harness is running in READINESS-ONLY mode..."}`) for any non-readiness endpoint, aborting before framework routing.
- **Fail-Closed Scope Gate**: Fails closed before framework boot if the runtime manifest specifies `ROOT_AUTHORIZED_LOCAL_PROVISION_ONLY` or an unapproved scope. Requires explicit `ROOT_AUTHORIZED_READINESS_ONLY` or `ROOT_AUTHORIZED_PAIRED_EXECUTION`.

### B. HTTP 200 Status Code Verification
In `DisposableMobileV2BackendHarness::validateReadinessResponse(?array $httpHeaders, string|false $responseBody, string $expectedNonce): bool`:
- Explicitly tests `$httpHeaders[0]` against `#^HTTP/\S+\s+200\b#`.
- Rejects responses with non-200 HTTP status (e.g. 500, 403, 404, or 302).
- Validates that the body is non-false and parses as a valid JSON object containing `status === "ready"` and `hash_equals($expectedNonce, $data["nonce"])`.
