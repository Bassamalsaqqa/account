# ADR-0001: Mobile V1 API Contract & Security Integration Baseline

## Status
**PROPOSED NOT LIVE** (Baseline: commit `f8a0cf7012c4875bec7320d42e0f8dde58593b52` in `account`, commit `79c9bb348f7ad4840842aa077f1820f75745d2a0` in `small-trader-android`).  
Default offline policy is strictly disabled (`enabled: false`). All real offline policies remain pending explicit business owner approval.

---

## 1. Context & Motivation
Small Trader requires a reliable, native Android client application (`small-trader-android`) integrated with the central Laravel platform (`account`). The solution must support fast retail checkout, resilient network-interrupted retries, strict multi-tenant isolation, exact financial decimal accounting, and tamper-evident event intake.

Prior iterations suffered from informal contract specifications, toy validators, unverified rounding assumptions, imprecise ID representations, and ambiguities surrounding product packaging units and offline policy authorization. This Architecture Decision Record (ADR-0001), together with its formal JSON Schemas (Draft 2020-12), OpenAPI 3.1 specification, golden exact-decimal fixtures, and automated validation suite, establishes the authoritative contract baseline.

---

## 2. Architectural Decisions & Contract Invariants

### 2.1 Public ID Strategy: Strict Crockford ULID vs Explicit Client UUID
1. **Server Entity Public IDs**: All server-side domain entities (`company_id`, `actor_id` / `user_id`, `product_id`, `customer_id`, `invoice_id`, `payment_id`, `warehouse_id`, `cash_account_id`, `tax_rate_id`, `unit_id`) are 26-character Crockford Base32 Universally Unique Lexicographically Sortable Identifiers (ULIDs), matching the strict canonical uppercase pattern:
   ```regex
   ^[0-7][0-9A-HJKMNP-TV-Z]{25}$
   ```
   - First character is strictly `[0-7]` (128-bit timestamp/random space).
   - Valid characters exclude `I`, `L`, `O`, `U` to eliminate transcription ambiguity.
   - Lowercase characters are forbidden.
   - Permissive unions (e.g. UUID/ULID regex) or erroneous reviewer suggestions are rejected.
2. **Client & Ephemeral Identifiers**: Generated client-side artifacts retain explicit RFC 4122 UUID types (`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`):
   - `client_event_id` (client transaction identity)
   - `device_id` (hardware installation UUID)
   - `challenge_id` / `nonce` (login challenge lifecycle)
   - `lease_id` / `device_lease_id` (offline authorization lease)
   - `mfa_challenge_id` (two-factor challenge reference)

### 2.2 Product Packaging Unit (`product_unit_id`) vs Unit of Measure (`unit_id`)
1. **Database Schema Parity**: Direct schema inspection confirms that `units` possesses an authoritative public ID (`char(26)` ULID), while `product_units` is an internal join entity (`product_id`, `unit_id`, `conversion_to_base`, `is_base`, `is_default_sale`) with a surrogate big-integer primary key and no standalone public ID column.
2. **Stable External Packaging Unit Mapping**: In client-facing contracts and sales drafts (`SalesDraftIntent`), packaging units are uniquely and deterministically identified via stable external mapping bound to the packaging unit's unit of measure (`unit_id` ULID).
3. **Mandatory Routing & Conversion Snapshots**:
   - Every product in the catalog exposes its base unit, default sales unit, and available packaging units with explicit `conversion_to_base` decimal multipliers.
   - Online sales draft requests (`POST /sales/preview`) require explicit `product_unit_id`, `warehouse_id`, ISO base `currency`, and approved `cash_account_id` (when paid in cash).
   - Implicit stock deductions or unverified unit assumptions are strictly forbidden; warehouse and unit conversions are verified before stock is reserved.

### 2.3 TaxPercentage & Deterministic Line Rounding Sequence
1. **Tax Rates as Percentages**: Tax rates are formatted as percentage strings from 0 to 100 with up to 6 decimal places (`TaxPercentage`), matching `TaxPercentage.php` (e.g. `"16.000000"` for 16%, `"0.000000"` for zero-rated), not fractional rates (`0.1600`).
2. **Arithmetic Sequence (Laravel SalesLineCalculator.php)**:
   Financial line calculations adhere strictly to the sequence in `SalesLineCalculator.php` (commit `f8a0cf7012c4875bec7320d42e0f8dde58593b52`):
   - **Step 1: Line Subtotal**:
     `rawSubtotal = quantity * unitPrice`  
     `subtotal = rawSubtotal.toScale(minorUnits, RoundingMode::HALF_UP)`
   - **Step 2: Line Discount**:
     `discount = (subtotal * (discountValue / 100)).toScale(minorUnits, RoundingMode::HALF_UP)` (percent) or fixed discount; capped at subtotal.  
     `netBeforeTax = subtotal - discount`
   - **Step 3: Tax Calculation**:
     `rate = taxRate / 100`  
     - **Inclusive Tax (`tax_inclusive: true`)**:  
       `actualNet = netBeforeTax / (1 + rate).toScale(minorUnits, RoundingMode::HALF_UP)`  
       `tax = netBeforeTax - actualNet`  
       `total = netBeforeTax` (line total charged to customer is the discounted gross amount)
     - **Exclusive Tax (`tax_inclusive: false`)**:  
       `tax = (netBeforeTax * rate).toScale(minorUnits, RoundingMode::HALF_UP)`  
       `total = netBeforeTax + tax`
   - **Step 4: Scale Normalization**: All persisted monetary and quantity figures are normalized to 6 decimal places (`DECIMAL(20,6)`).
3. **Positive Quantities with Trailing Zeros**: Positive quantity fields accept trailing zeros (e.g. `"0.100000"`), while strictly rejecting zero (`"0.000000"`), negative amounts, over-scale values (>6 decimals), and values exceeding `DECIMAL(20,6)` magnitude (`99999999999999.999999`). Stock `available_quantity` is nonnegative with up to six decimal places.

### 2.4 Settlement Mode vs Payment Method
1. **Field Separation**: Settlement status (`settlement_mode: "unpaid" | "full" | "partial"`) is distinctly separated from payment tender (`payment_method: "cash"`).
2. **Scope Boundaries**:
   - Initial production rollout is constrained to base currency transactions: online cash sales and unpaid credit sales. Partial cash payment is accepted only when explicitly specified with positive tendered amount and approved cash account routing.
   - Offline transactions are restricted to immutable full cash payments (`mode: "full"`, `payment_method: "cash"`, `amount_settled == grand_total`).

### 2.5 Online Preview Digest Binding & Durable Commit Retry
1. **Preview Token Binding**: `POST /sales/preview` normalizes the economic intent (actor, company, device, client event ID, customer, warehouse, currency, lines, payment), computes a canonical SHA-256 digest (`payload_digest`), and embeds this digest inside a signed `preview_token`.
2. **Durable Replay & Commit Verification**:
   - `POST /sales/commit` receives `client_event_id`, `preview_token`, `payload_digest`, and `idempotency_key`.
   - The server first checks event receipt replay: if `(company_id, client_event_id)` has already posted with an identical digest, the stored canonical outcome is returned immediately without regenerating invoices or journal batches.
   - The server verifies that the supplied `payload_digest` matches the digest bound inside the `preview_token` before inspecting token expiry or generating a server invoice.
   - The server invoice ULID (`invoice_id`) is strictly distinct from the client event UUID (`client_event_id`).

### 2.6 Transport Delivery Envelope vs Canonical Accounting Domain Receipt
1. **Outer Mobile Envelope**: The mobile intake structure (`client_event_id`, `device_id`, `captured_at`, `payload_digest`, and local sync status) acts as a durable delivery and idempotency tracking envelope.
2. **Inner Canonical Domain Writers**: The backend accounting domain processes the inner economic payload through transactional writers (`CreateSalesInvoiceDraftAction`, `PostSalesInvoiceAction`, `SalesLineCalculator`, `CustomerPayment`), atomically committing the invoice, inventory lot movements, and general ledger journal entries into an immutable central `CommandReceipt`.

### 2.7 Bounded Pagination & Search Semantics
1. Catalog (`GET /products`) and customer (`GET /customers`) endpoints enforce bounded pagination:
   - Query parameters: `limit` (integer 1..100, default 50), `cursor` (opaque string token), and `search` (query string, max 100 characters).
   - Response structures provide `next_cursor` (string or null) and `has_more` (boolean).

### 2.8 Bootstrap Authoritative Tax Definitions & No Offline Lease
1. `GET /bootstrap` returns the authoritative company context, walk-in customer ULID, staff profile, default warehouse, approved cash account, units catalog, authoritative `taxes` definitions, and offline policy status.
2. **No Offline Lease When Disabled**: When `offline_policy.enabled` is `false`, `device_lease` is strictly `null`. Offline leases are never granted while offline capture is disabled.
3. **No Negative Stock or Unenforced Offline Claims**: Negative inventory is strictly prohibited. All offline policies (provisional cash capture, oversell allowances, disconnected limits, reconciliation queues) remain pending explicit business owner sign-off and default to OFF (`enabled: false`).

### 2.9 Authentication Lifecycle, MFA_REQUIRED & Hardware Custody
1. **Challenge Lifecycle**: `POST /auth/challenge` issues single-use nonces and challenge UUIDs with a 5-minute TTL.
2. **Fortify Parity & Mutual Exclusion**: `POST /auth/login` supports standard credentials, optional TOTP `mfa_code`, and optional single-use Fortify `recovery_code`. The request schema enforces strict mutual exclusion between `mfa_code` and `recovery_code` (submitting both simultaneously is rejected).
3. **Explicit MFA_REQUIRED Status**: If credentials are valid but two-factor authentication is required, the server responds with `status: MFA_REQUIRED` and `retry: fresh_challenge`; obtain a fresh ordinary challenge before retrying credentials with MFA/recovery.
4. **Auth Revocation Fails Closed**: Revoked tokens fail at the gateway with **HTTP 401 Unauthorized**. No 401 response ever issues parked receipts or sync outcomes. The Android client locks the local outbox (`QUEUE_LOCKED_AUTH_DENIED`) pending authenticated manager resolution.
5. **Keystore Key Custody**: Hardware-backed AES-256-GCM keys reside in `AndroidKeyStore`. Master keys encrypt token ciphertext stored in private app storage; bearer tokens are never stored directly in plaintext or in Android Keystore.

---

## 3. OpenAPI 3.1 Endpoint Architecture

The contract defines 11 REST endpoints:
- **7 POST Endpoints with Request & Response Payloads**:
  1. `POST /api/mobile/v1/auth/challenge`
  2. `POST /api/mobile/v1/auth/login`
  3. `POST /api/mobile/v1/auth/logout`
  4. `POST /api/mobile/v1/devices/{deviceId}/revoke`
  5. `POST /api/mobile/v1/companies/{companyId}/sales/preview`
  6. `POST /api/mobile/v1/companies/{companyId}/sales/commit`
  7. `POST /api/mobile/v1/companies/{companyId}/offline-events`
- **4 GET Endpoints (Query & Path Parameters Only, No Request Body)**:
  8. `GET /api/mobile/v1/companies/{companyId}/bootstrap`
  9. `GET /api/mobile/v1/companies/{companyId}/products`
  10. `GET /api/mobile/v1/companies/{companyId}/customers`
  11. `GET /api/mobile/v1/companies/{companyId}/offline-events/{clientEventId}/status`

All error responses across all endpoints standardize on `schemas/error-response.json` (RFC 7807/9457 Problem Details shape).

---

## 4. Idempotency, Concurrency & Database Constraints

- **Intake Table Unique Constraint**:
  ```sql
  UNIQUE KEY uq_mobile_event (company_id, client_event_id)
  ```
  Concurrency is resolved via atomic database constraint plus row locking (`lockForUpdate`) inside a database transaction.
- **Idempotent Replay Handling**:
  - If `(company_id, client_event_id)` matches an existing record and `request.payload_digest == existing.payload_digest`: return stored canonical outcome immediately. Do NOT regenerate invoices, numbers, or accounting lines.
  - If `request.payload_digest != existing.payload_digest`: reject immediately with **HTTP 409 Conflict** and structured conflict evidence.

---

## 5. Explicit Owner Decisions Pending Before Live Cash Pilot

All offline policies remain strictly disabled by default (`offline_policy.enabled: false`) pending written business sign-off:
1. **Cash Only While Disconnected**: Default: `cash_only: true`.
2. **Offline Stock Policy**: Bounded provisional cash sales with transparent exception queue (no negative inventory allowed).
3. **Disconnected Limits & Thresholds**: Maximum duration (12 hours), maximum transaction count (50 sales), maximum monetary volume (5,000 base currency).
4. **Provisional Customer Document**: Local slip clearly labeled **"Pending synchronization / غير مرحّلة بعد"** with local serial, no official fiscal invoice number.
5. **Revocation Resolution Policy**: If an operator is revoked while disconnected, the device locks the outbox queue on HTTP 401 until an authorized manager resolves it.
6. **Cross-Platform Parity Testing**: Complete automated test suites verifying exact digest and rounding parity across Kotlin (`small-trader-android`) and PHP (`account`) must pass prior to production rollout.

## Coordinated DRAFT revision 2 (read/MFA/bootstrap only)

This explicit revision supersedes conflicting read/auth examples above. Historical baseline is Git `088f1f9d5fdb231b31d7bc1525092b500411cb5f`, manifest SHA-256 `a8d04a130bdb0bd909a7cfabdde93fb6f90c96cc07dbedfbd01971bdb07c6733`. API prefix remains `/api/mobile/v1`. Financial schemas and canonical digest fixtures remain unchanged and are not financial release approval.

MFA_REQUIRED returns only `status: MFA_REQUIRED, retry: fresh_challenge`. The ordinary challenge is consumed; fetch a fresh ordinary challenge and resend credentials with its new nonce/id plus MFA or recovery code, mutually exclusive. No continuation proof and no compulsory MFA. Token identity is immutable STAFF + DEVICE. Each company route requires explicit selection, fresh active company/membership, canonical grants and tenant ownership; never named-role exemptions or session fallback. Roles are bounded presentation strings only.

Offline unavailable is `{enabled:false,availability:"unavailable"}` with null device_lease enforced by schema. No approved numeric defaults exist. Enabled policy requires all explicitly reviewed limits; shape validation cannot approve business policy. Online bootstrap can succeed while offline is OFF. Canonical walk-in customer, warehouse and approved cash mapping requirements remain: missing configuration is a truthful configuration error, never first-record guessing. Empty unit/tax masters are valid reads, not sale-readiness claims.

Product tax_rate and tax_rate_id form a nullable pair. Null means NO PRODUCT DEFAULT ASSOCIATION, not zero tax/exemption. Mature Product has no tax FK and returns null pair. Bootstrap taxes are actual company tax-master definitions. UI must explicitly select a permitted line TaxRate under canonical SalesDraftIntent; null ID plus zero is only an explicit no-tax choice. Never manufacture a product tax column, use product ULID as tax ULID, or infer tax identity from a percentage. Before financial implementation, amend online preview to carry explicit line tax public ID, resolved/validated server-side against percentage/inclusive snapshots. Current preview's percentage-only request has this unresolved gap. Offline tax identity probably needs a new event version; do not post current offline payload by guessing a tax record. Existing economic payloads and golden digests are frozen.

### Restricted parser boundary clarification

Raw JSON payload limit is 1 MiB of UTF-8 bytes. Each decoded JSON string is bounded at 65536 UTF-16 code units, checked after appending a full escaped surrogate pair. Unicode hex escapes accept unsigned ASCII hex digits only; unescaped controls are rejected. Integers must be within the exact safe integer domain [-9007199254740991,9007199254740991], consistently for parsed and raw values. Economic decimal input lexemes are preserved without trimming or rounding; floats are forbidden. Economic bounded-string validation must require a full match (a trailing newline must not pass a dollar-anchor check). These clarify parser requirements, not a change to immutable financial fields or digest vectors.
