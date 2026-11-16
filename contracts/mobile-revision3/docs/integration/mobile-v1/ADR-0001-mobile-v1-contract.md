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
1. **Outer Mobile Envelope**: The mobile intake structure (`client_event_id`, `device_id`, `captured_at`, `payload_digest`, and local sync status) acts as a durable delivery and idempotency tracking envelope. Mobile sales require durable receipt replay uniqueness scoped by `UNIQUE(company_id, client_event_id)` bound to `(actor_id, device_id)` and spanning API versions.
2. **Inner Canonical Domain Writers & Durable DB Receipts**: Backend source verification confirms that `CommandReceipt` rows in the accounting backend (`ImmediateSaleCommands.php`, `command_receipts` table) ARE durable database receipts stored with full transactional guarantees, NOT merely ephemeral or session-bound records. However, backend `CommandReceipt` idempotency is scoped by `UNIQUE(company_id, user_id, action, idempotency_key)` with `request_hash = hash('sha256', target_invoice_id . '|' . token)` (an invoice/token-hash scoped identity). This differs fundamentally from the mobile delivery requirement of `UNIQUE(company_id, client_event_id)` event-scoped replay identity.

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

### 3. OpenAPI 3.1 Endpoint Architecture

The contract defines 16 REST endpoints across explicit API versions:
- **Preserved API v1 Endpoints (11 endpoints: Read/Auth & Historical Compatibility)**:
  - *7 POST Endpoints with Request & Response Payloads*:
    1. `POST /api/mobile/v1/auth/challenge`
    2. `POST /api/mobile/v1/auth/login`
    3. `POST /api/mobile/v1/auth/logout`
    4. `POST /api/mobile/v1/devices/{deviceId}/revoke`
    5. `POST /api/mobile/v1/companies/{companyId}/sales/preview` (mapped to `archived-v1-sale-preview-request.json` & response)
    6. `POST /api/mobile/v1/companies/{companyId}/sales/commit`
    7. `POST /api/mobile/v1/companies/{companyId}/offline-events` (mapped to `archived-v1-offline-sale-intake-request.json`)
  - *4 GET Endpoints (Query & Path Parameters Only)*:
    8. `GET /api/mobile/v1/companies/{companyId}/bootstrap` (mapped to `archived-v1-bootstrap-response.json`)
    9. `GET /api/mobile/v1/companies/{companyId}/products`
    10. `GET /api/mobile/v1/companies/{companyId}/customers`
    11. `GET /api/mobile/v1/companies/{companyId}/offline-events/{clientEventId}/status`
- **Agreed Breaking API v2 Endpoints (5 endpoints: Line Tax Identity, Intake v2 & Minor Units)**:
  - *4 POST Endpoints with Request & Response Payloads*:
    12. `POST /api/mobile/v2/companies/{companyId}/sales/preview` (mapped to `sale-preview-request.json` & response)
    13. `POST /api/mobile/v2/companies/{companyId}/sales/commit` (mapped to `sale-commit-request.json` & response)
    14. `POST /api/mobile/v2/companies/{companyId}/offline-events` (mapped to `offline-sale-intake-request.json` & response)
  - *2 GET Endpoints*:
    15. `GET /api/mobile/v2/companies/{companyId}/bootstrap` (mapped to `bootstrap-response.json` requiring `base_currency_minor_units`)
    16. `GET /api/mobile/v2/companies/{companyId}/offline-events/{clientEventId}/status`

All error responses across all endpoints standardize on `schemas/error-response.json` (RFC 7807/9457 Problem Details shape).

---

## 4. Idempotency, Concurrency & Database Constraints

- **Intake Table Unique Constraint**:
  ```sql
  UNIQUE KEY uq_mobile_event (company_id, client_event_id)
  ```
  Concurrency is resolved via atomic database constraint plus row locking (`lockForUpdate`) inside a database transaction.
  The receipt uniqueness domain `(company_id, client_event_id)` spans API versions (must not duplicate across v1 and v2).
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

## Coordinated DRAFT revision 3 (financial contract, line tax amendment, event v2, currency minor units, explicit API v2)

This explicit revision introduces proposed contract revision 3 for financial line tax identity, mode, and conversion snapshots, offline event version 2, currency minor units metadata, and explicit `/api/mobile/v2` breaking endpoint representations. Historical baseline is Git `ec8220934839ade0c4fe5408398ab1a313feb09c`, revision 2 manifest SHA-256 `ba75352349e552f53b02ad7159570171f88943dc26e5bdf2a4b013386b0d0659` (preserved unchanged as parent reference). Status: **PROPOSED NOT LIVE**.

### Distinction Between Endpoint Version (API v2), Contract Revision (Revision 3), and Event Version (v2)
Three distinct version concepts are strictly separated:
1. **Endpoint Version (`/api/mobile/v2`)**: The URL route prefix for breaking financial write endpoints. Existing `/api/mobile/v1` routes preserve parent revision-2 read/auth behavior and historical archived schemas (`archived-v1-*.json`) unchanged.
2. **Contract Revision (Revision 3)**: The coordinated specification version covering schemas, OpenAPI 3.1, ADR-0001, test fixtures, and validation suites.
3. **Offline Event Version (`event_version: 2`)**: The wire payload version for offline sales events. Offline intake on `/api/mobile/v2` strictly rejects `event_version: 1` with HTTP 409 Conflict without mutating stored client bytes.

### Normative Online Canonical Intent Profile
Online sale preview (`POST /api/mobile/v2/companies/{companyId}/sales/preview`) normalizes the complete economic intent into one normative canonical intent profile object before computing its canonical SHA-256 digest (`payload_digest`):
- **Exact Field Selection**:
  - `actor_id`: Authoritative staff ULID bound from authenticated Bearer session token.
  - `company_id`: Authoritative company ULID bound from URL route parameter `{companyId}`.
  - `currency`: ISO-4217 3-character uppercase currency code from request.
  - `currency_minor_units`: Server-qualified currency minor units (integer `0..6`) from currency master context.
  - `device_id`: Device UUID from request.
  - `client_event_id`: Client transaction UUID from request.
  - `customer_id`: Customer ULID from request.
  - `warehouse_id`: Warehouse ULID from request.
  - `lines`: Ordered array of line items preserving client submission order, each containing:
    - `product_id`: Catalog product ULID.
    - `product_unit_id`: Packaging unit ULID.
    - `conversion_to_base`: Positive exact decimal string snapshot.
    - `quantity`: Positive exact decimal string lexeme.
    - `unit_price`: Nonnegative exact decimal string lexeme.
    - `tax_rate_id`: Public nullable Crockford ULID.
    - `tax_rate`: Exact percentage string snapshot (`0..100`, up to 6 decimals).
    - `tax_inclusive`: Boolean flag.
  - `payment`: Cash payment intent containing:
    - `settlement_mode`: `"full"` | `"unpaid"` | `"partial"`.
    - `payment_method`: `"cash"` for full/partial; `null` for unpaid (omitted fields in valid request canonically normalize to `null`).
    - `cash_account_id`: Approved cash register Crockford ULID for full/partial; `null` for unpaid (omitted fields in valid request canonically normalize to `null`).
    - `amount`: Exact monetary decimal string lexeme (`Decimal(amount) == 0` for unpaid; `0 < Decimal(amount) < grand_total` for partial; `Decimal(amount) == grand_total` for full, enforcing the orchestrator-selected first-release exact policy). Preserves exact string representation (e.g. `'0.000'`) without rounding or lexeme mutation.
- **Lexeme Preservation & No Floats**: All quantity and monetary values are strictly validated string decimal lexemes; IEEE-754 floats are forbidden. No implicit rounding, truncating, or repricing occurs prior to canonical hashing.
- **Restricted JCS Serialization (RFC 8785 Subset)**: Object keys sorted by UTF-16 code units; characters < 0x20 escaped; no whitespace between tokens.
- **Bound Preview Token Placeholder**: The computed SHA-256 digest is embedded in an illustrative opaque sample token (`preview_token`) consisting of base64-encoded JSON claims `{"version": 2, "company_id": "...", "digest": "sha256:..."}` and an illustrative signature suffix. The token is an illustrative opaque placeholder for contract binding, not fake cryptographic proof.

### Normative Online Canonical Intent Profile Projection & Payment Semantics
> [!IMPORTANT]
> All helper functions and adapters in `tools/validate-mobile-contract.py` (`construct_online_sale_intent_profile`, `verify_online_sale_intent_profile`, `verify_payment_decimal_consistency`, `adapt_offline_cash_to_canonical_receipt`, `adapt_online_payment_to_canonical_choice`, `calculate_canonical_line`) are proposal demonstrations and contract qualification test verifiers, NOT server implementations. The authoritative server domain logic and ledger posting reside in backend Laravel services (`ImmediateSaleCommands.php`, `SalesInvoiceCommands.php`, `SalesLineCalculator.php`, `PostCustomerPaymentAction.php`).

1. **Mandatory Pre-Projection Schema Validation**:
   - The raw incoming request is validated against `sale-preview-request.json` before any projection occurs. Any unknown property at the root, line, or payment level is rejected immediately with `IntentProfileValidationError`.
   - Line items must explicitly specify `tax_rate_id`. Missing `tax_rate_id` throws a clear fixed typed `IntentProfileValidationError`; it never defaults or falls back to implicit NoTax. Explicit No-Tax requires an explicit `null` value with `tax_rate: "0.000000"` and `tax_inclusive: false`.
   - The route and session context must contain strictly known keys `{"actor_id", "company_id", "currency_minor_units"}`. ULID formats are strictly enforced, and `currency_minor_units` is mandatory explicit context (integer in `0..6`), explicitly rejecting booleans as integers (`isinstance(b, int)` trap), invalid scales, or missing values with no guessing of 2.

2. **First-Release Policy: Positive Financial Grand Total ONLY**:
   - In accordance with orchestrator policy and backend receipt invariants (`PostCustomerPaymentAction::execute` line 209: receipt amount must be strictly positive), the first-release contract policy permits **strictly positive financial grand totals ONLY** (`grand_total > 0`).
   - All paid, unpaid, and offline sales reject non-positive totals (`grand_total <= 0`); no zero-invoice or zero-receipt workarounds are supported. Zero-total NoPayment sales may be qualified in future canonical revisions but are NOT claimed now.

3. **Exact Currency Minor Units Representation**:
   - Received, settled, tender, and change amounts must represent integer currency minor units in `0..6` using exact `Decimal` arithmetic without rounding (`RoundingMode::UNNECESSARY` parity with `MoneyValues::amount` and `ReceiptRequestValues::decimal`).
   - Trailing zeros (e.g. `50.000000` at minor units = 2) are accepted because they represent exact integer minor units.
   - Fractional amounts beyond currency minor units (e.g. `0.000001` at minor units = 2 for ILS) are strictly rejected without rounding.
   - Quantities and packaging conversion to base preserve 6-decimal precision, and tax percentages preserve up to 6-decimal percent, independent of currency minor units.
   - Numeric finite/domain bounds are validated before helper execution; helpers fail closed on malformed lexemes, NaN, Infinity, floats, or scientific exponent notations with explicit `return False` invariants without crashing.

4. **Source-Backed Payment Semantics Parity (`ImmediateSaleCommands.php` & `PostCustomerPaymentAction.php`)**:
   - **Unpaid Settlement (`settlement_mode: "unpaid"`)**:
     - An unpaid sale carries zero payment tender (`Decimal(amount) == 0`) with strictly positive grand total (`grand_total > 0`). String decimal lexemes (e.g. `'0.000'`) are preserved verbatim without monetary rounding or format conversion.
     - Per canonical rules (`ImmediateSaleCommands::intent`), an unpaid sale cannot carry cash payment instructions. `payment_method` and `cash_account_id` may be omitted from client requests or supplied as explicit `null`.
     - In the canonical intent profile, omitted payment method and cash account canonically normalize to `null`. If non-null payment instructions are supplied with `unpaid` mode, validation fails closed.
     - **Documented Command Choice Adapter**: ONLY AFTER strict wire and profile validation and digest derivation, the adapter maps canonical choice EXACTLY to `{"mode": "unpaid"}`, stripping wire payment fields (`amount`, `payment_method`, `cash_account_id`) from the command input so that `ImmediateSaleCommands::intent` (which strictly checks `array_diff(array_keys($choice), ['mode']) === []`) accepts it. No cash account resolver or payment date is required (`unpaidnoresolverneeded`). Stripping applies ONLY to validated payment snapshot fields; no general null filter is applied across the payload (line `tax_rate_id: null` after 0/false validation MUST remain explicit canonical NoTax).
   - **Partial Settlement (`settlement_mode: "partial"`)**:
     - `payment_method: "cash"` and an approved company cash register `cash_account_id` are required economic fields and must not be omitted or null.
     - The payment amount must be positive and strictly below the qualified grand total (`0 < Decimal(amount) < grand_total`), matching `ImmediateSaleCommands` allocation checks (`! $allocated->isLessThan($total)`).
     - **Documented Partial Adapter (Proposed Qualification)**: Base currency cash without change maps wire `amount` to canonical `received_amount` AND `invoice_amount` allocation (`allocated_amount`), with FX 1.0000000000, explicit reviewed payment date (`payment_date`), same company active cash account (`money_account_id`), and `payment_currency_amount` matching allocation.
     - **Company-Scoped Cash Account Resolver & Reviewed Date**: The illustrative adapter requires an explicit company-scoped active cash account ULID -> internal positive integer resolver (`money_account_id: int`) and an explicit reviewed payment date with NO default (no invented dates like `2026-11-11`). The resolver is called only after strict wire validation and scoped to company; missing/foreign/inactive accounts fail closed and never return wire ULIDs under `money_account_id`.
   - **Full Settlement (`settlement_mode: "full"`)**:
     - `payment_method: "cash"` and `cash_account_id` are required economic fields and must not be omitted or null.
     - **Backend Canonical Excess vs Change Semantics**: Backend source verification of `ImmediateSaleCommands::intent` (lines 185-242) and `ImmediateSaleCommands::paymentTerms` (lines 248-295) baseline `f8a0cf7` identifies that the prior claim that "full wire amount >= total means change" was incorrect. In backend `paymentTerms`, when `amount > total`, the excess (`amount - consumed`) is recorded as `unallocated_payment_amount` (unallocated customer credit posted to the customer's account), NOT returned change.
     - **First-Release Online Policy**: To avoid unintended credit accumulation and because the mobile client has no customer credit allocation UX in first release, the orchestrator selected the smallest exact first-release online policy: **FULL wire amount MUST EQUAL grand total (`Decimal(amount) == grand_total`, both strictly positive)**. Amounts greater than or less than grand total are strictly rejected by contract verifiers and the first-release adapter. Backend canonical credit semantics remain unchanged (no new credit or change feature introduced).
     - **Resolver & Reviewed Date**: Like partial settlement, full settlement requires an explicit company-scoped cash account resolver mapping wire ULID to internal positive integer ID and an explicit reviewed payment date with no invented defaults.
   - **Offline Cash Tender & Change Conservation**:
     - Offline transactions retain distinct `amount_tendered`, `amount_settled`, and `change_returned` fields (`mode: "full"`).
     - Conservation invariant `amount_tendered == amount_settled + change_returned` and `amount_settled == grand_total` (`settled > 0`) is strictly verified without falling back to online `amount` or tautological self-comparisons.
     - **Proposed Canonical Receipt Adapter & Audit Separation**: The helper result is an illustrative audit mapping, NOT a `PostCustomerPaymentActionDTO`. Offline audit bytes and digest retain `amount_tendered`, `amount_settled`, and `change_returned` separately in `audit`. The authoritative canonical receipt amount to post against the customer invoice is the **NET RETAINED** settled amount (`amount_settled`), NOT the tendered amount (`amount_tendered`) when change is returned (e.g. tender 60, total 50, change 10 -> canonical receipt amount 50). Wire audit fields are never passed unchecked into `PostCustomerPaymentAction`.

### Financial Line Schema Amendment
Both online sale preview (`POST /api/mobile/v2/companies/{companyId}/sales/preview`) and offline event intake (`POST /api/mobile/v2/companies/{companyId}/offline-events`) require:
1. `tax_rate_id`: Public nullable 26-character Crockford ULID (`^[0-7][0-9A-HJKMNP-TV-Z]{25}$`).
2. `tax_rate`: Exact percentage snapshot string (`0..100`, up to 6 decimal places: `^(?:100(?:\\.0{1,6})?|(?:0|[1-9][0-9]?)(?:\\.[0-9]{1,6})?)$`).
3. `tax_inclusive`: Boolean flag (`true` for gross inclusive tax calculation, `false` for net exclusive tax calculation).
4. `conversion_to_base`: Positive exact decimal string (`^(?:0\\.(?=[0-9]{1,6}$)[0-9]*[1-9][0-9]*|[1-9][0-9]{0,13}(?:\\.[0-9]{1,6})?)$`) matching the catalog packaging unit conversion multiplier.

### Explicit No-Tax vs. Catalog Null Tax Pair
- **Catalog Null Pair**: In `GET /products`, `tax_rate: null, tax_rate_id: null` denotes the *absence of a product default tax association*, NEVER zero tax or tax exemption. Mature products have no tax foreign keys.
- **Explicit No-Tax Selection**: When an operator explicitly selects "No Tax" in the client UI, the line transmits `tax_rate_id: null`, `tax_rate: "0.000000"`, `tax_inclusive: false`. Any combination of `tax_rate_id: null` with non-zero rate or inclusive mode is strictly rejected by the schema and server validation.
- **Server Master Resolution**: When `tax_rate_id` is non-null, the server resolves the public ULID to an active tax rate master within the authenticated company, revalidating exact percentage and tax mode under the database transaction. Fails closed on mismatch without repricing; never infers or guesses a master record by percentage alone.

### Source Currency Qualification & Financial Defaults
- **Source Currency Codebase Inconsistency**: Backend `SalesDocumentRules::price` (`app/Services/Sales/SalesDocumentRules.php`) currently hardcodes `$currency === 'JOD' ? 3 : 2`. This is a codebase quirk/inconsistency, not a universal rule or universal rejection of other currencies. Dynamic currency minor unit parity requires qualifying all server financial writers to read `currencies.minor_units`. No client guessing of currency metadata.
- **Zero Financial Defaults**: No financial defaults exist. Missing tax identity, tax mode, conversion snapshot, or currency scale fails closed; no fallback may invent economic terms.
- **Offline Policy Null Lease**: When `offline_policy.enabled` is `false` (`availability: "unavailable"`), `device_lease` is strictly `null`. No offline lease is ever granted or retained when offline capture is disabled.

### Arithmetic Qualification & Decimal Parity
- **JSON Schema Syntax Limitation**: JSON Schema Draft 2020-12 validates string lexeme syntax and regex boundaries only. Multi-field arithmetic consistency is enforced by transactional server domain logic.
- **Server Decimal Consistency Required**: Backend writers enforce exact arithmetic: line subtotals and taxes, header grand total balance, and cash tender balance (`amount_tendered == amount_settled + change_returned`, `amount_settled == grand_total`).
- **Fixture Arithmetic Verification**: Fixture arithmetic tests in validation suites invoke genuine exact calculator helpers implementing `SalesLineCalculator.php` / `RoundingMode::HALF_UP` parity. They verify fixture correctness and do not claim universal server runtime validation.
- **Reference Calculation (ILS gross 50.000000, 16% inclusive, 2 minor units)**:
  - `subtotal = 50.000000`
  - `net_before_tax = round(50.00 / 1.16, 2) = 43.100000`
  - `tax_amount = 50.00 - 43.10 = 6.900000` (NOT unrounded floating `6.896552`)
  - `line_total = 43.10 + 6.90 = 50.000000`

### Canonical Stale-Preview Behavior
`SalesInvoiceCommands::computePreviewRevision` fingerprints current product and unit default prices. Any subsequent price edit invalidates the preview token, even if the acting user holds the `sales.invoice.change_price` override permission. The mobile client must preserve this canonical behavior: stale previews fail closed, requiring a fresh preview. No relaxed stale token rules, no silent refresh, and no stale-preview exemption for price overrides.

### Proposed Mobile Authority Boundary & PR88 Middleware Requirements
- **PR88 Boundary Scope**: PR88's draft `MobileApiBoundary`, provider registration, and exception hook only recognize the `/api/mobile/v1` prefix. Mounting middleware on `/api/mobile/v2` routes would bypass prefix-specific checks if unadapted. A separate reviewed v2 boundary must explicitly cover: default-OFF feature switch, authentication, company context setup/reset, input/schema validation, rate limits, redacted errors, and exception handling.
- **Token Authority Isolation**: Existing v1 staff and device tokens confer no implicit financial authority on `/api/mobile/v2`; financial token/grant boundaries remain proposed qualifications with no runtime endpoints created here.
- **Cross-Version Receipt Identity**: The durable receipt uniqueness domain `(company_id, client_event_id)` spans API versions. Original actor/device scope and current authorization must be verified before status or replay disclosure, including legacy unsupported version responses.

