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
