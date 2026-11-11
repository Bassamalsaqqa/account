# Mobile V1 Contract Provenance & Backend Integration Notes

## 1. Mirrored Contract Provenance & Manifest Verification

- **Authoritative Source**: Mirrored from frozen Android repository at PR4 head `088f1f9d5fdb231b31d7bc1525092b500411cb5f`.
- **Mirrored Paths**:
  - `docs/integration/mobile-v1/` (OpenAPI 3.1 specification, ADR-0001, JSON Schemas Draft 2020-12, golden fixtures, regression fixtures, `manifest.sha256`)
  - `tools/validate-mobile-contract.py`
  - `tools/requirements-validator.txt`
- **Manifest Integrity**:
  - All 38 contract files verified against `docs/integration/mobile-v1/manifest.sha256`.
  - Manifest file SHA-256: `a8d04a130bdb0bd909a7cfabdde93fb6f90c96cc07dbedfbd01971bdb07c6733`.
  - Contract validation suite passes with zero warnings: `python3 tools/validate-mobile-contract.py` and `python3 -O tools/validate-mobile-contract.py` (120/120 checks pass).

## 2. Cross-Platform Interoperability (Android PR5 & PR6 Parity)

- **Android PR5 (`t3/android-contract-foundation` @ `b79ed6a`)**:
  - Implements pure Kotlin `CanonicalJsonSerializer.kt` and `StrictJsonParser.kt` adhering to Small Trader Restricted Canonical JSON Profile v1 (RFC 8785 subset).
  - Confirmed bit-for-bit canonical serialization and SHA-256 digest parity across all 5 golden canonical vectors (`primitive_sorting`, `currency_total`, `arabic_fractions`, `utf16_surrogate_key_ordering`, `control_char_escaping`) and golden offline sale intake (`sha256:d442ce49a7609a9a42bb5165990d481d1c7c563cb8030403487099a9919728d9`).
- **Android PR6 (`t3/room-outbox-foundation` @ `5c86ee9`)**:
  - Implements bounded Room offline sales outbox with terminal event immutability, scoped queue locking on HTTP 401, and deterministic payload replay.
- **Backend Pure PHP Implementation**:
  - `App\Support\Mobile\Contract\CanonicalJsonSerializer`
  - `App\Support\Mobile\Contract\StrictJsonParser`
  - `App\Support\Mobile\Contract\DecimalLexeme`
  - Full PHPUnit unit test suite in `tests/Unit/Mobile/Contract/CanonicalJsonSerializerTest.php` running without application database dependency.

## 3. Identifier Strategy & Schema Authority

1. **Server Domain Entity Public IDs**:
   - Entities: `company_id`, `actor_id` / `user_id`, `product_id`, `customer_id`, `invoice_id`, `payment_id`, `warehouse_id`, `cash_account_id`, `unit_id`.
   - Format: Strict 26-character uppercase Crockford Base32 Universally Unique Lexicographically Sortable Identifiers (ULIDs) matching `^[0-7][0-9A-HJKMNP-TV-Z]{25}$`.
   - Permissive regexes, lowercase characters, or UUID format for server domain entities are strictly rejected.
2. **Client & Ephemeral Identifiers**:
   - Entities: `client_event_id`, `device_id`, `challenge_id`, `device_lease_id`.
   - Format: Standard RFC 4122 UUIDs (`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`).
3. **Packaging Unit Identifier Mapping (`product_unit_id`)**:
   - Backend Schema Fact: `units` table contains authoritative public ULID column (`char(26)`). The `product_units` table is an internal join entity with an auto-increment integer surrogate primary key and no public ID.
   - Contract Convention: External client requests reference `product_unit_id`, which maps deterministically to `units.public_id` scoped to the corresponding product.

## 4. Decimal Lexeme Preservation & Deferred Normalization Invariant

- **Strict Wire Preserved Lexeme**: Incoming JSON numbers for money, tax, and quantity must be strings. The raw string lexeme as submitted (e.g. `'1'` vs `'1.000000'`) must be preserved verbatim in `DecimalLexeme` during intake digest computation.
- **Why Deferred Normalization is Mandatory**: If the backend normalizes `'1'` to `'1.000000'` before verifying the client's `payload_digest`, the resulting canonical JSON string and SHA-256 hash will diverge, causing legitimate requests to fail digest validation.
- **Economic Invariant**: Normalization to `Brick\Math\BigDecimal` and domain scale normalization occur *strictly after* payload digest verification succeeds.

## 5. Scope Boundaries for Slice 1

- **Slice 1 Inclusions**:
  1. Mirrored contract files and validation tools.
  2. Minimal `AGENTS.md` policy amendment for scoped AGY isolated worktrees with single writer and independent Codex review.
  3. Pure PHP canonical JSON serializer, strict parser, and decimal lexeme value object.
  4. Comprehensive parity and negative unit tests with zero database dependency.
  5. Provenance and backend limitations documentation outside mirrored directory.
- **Slice 1 Explicit Exclusions**:
  - NO route activation.
  - NO mobile authentication endpoints or handlers.
  - NO financial service or ledger posting mutations.
  - NO database migrations.

## 6. Owner-agreed boundary and exactness clarification

Decoded JSON strings, including keys, are limited to 65,536 UTF-16 code units:
BMP characters (including Arabic and euro) count as one, supplementary characters
as two, whether raw UTF-8 or escaped surrogate pairs. Raw input is limited to
1,048,576 UTF-8 bytes. The parser counts decoded units incrementally without
rescanning accumulated strings. This clarification is coordinated with the Android
counterpart; frozen mirrored contract files and validation tools remain unchanged.

Native serialization enforces the same decoded string and 32-container nesting
limits, and limits its resulting canonical UTF-8 payload to 1,048,576 bytes.
Raw input size and canonical output size are distinct: whitespace/escapes can
make raw input larger. Native serialization checks aggregate output size after
construction, so this is an acceptance bound rather than a streaming memory bound.
Strict objects and stdClass always emit object shape, including empty and numeric
key objects; PHP lists emit arrays. StrictJsonObject::toNative() remains a convenience
array conversion and cannot preserve object/list identity; serialize the strict
element itself when identity matters.

Public strict numbers validate integer grammar, safe range and raw/value agreement.
Decimal lexemes reject whitespace without trimming, preserve original digest bytes,
and use UNNECESSARY scale conversion by default. Precision loss throws; rounding
requires an explicit mode and does not change the original lexeme.

The decoded UTF-16 boundary and exact decimal interpretation are coordinated with
the Android correction. A coordinated formal contract update is pending; these
implementation notes do not modify or supersede the frozen mirror provenance.

## 7. Slice 2 Architecture & Security Model

### Shared-Hosting Native Compatibility
- **Default State**: `MOBILE_API_ENABLED=false` in `config/mobile.php` (`.env` key `MOBILE_API_ENABLED`). When false, all `/api/mobile/v1/*` routes return generic 404 `MOBILE_API_DISABLED` without revealing endpoint existence or software stack.
- **Dependency-Free Architecture**: No Redis, daemon workers, Laravel Horizon, or Supervisor processes are required.
- **Hashed Opaque Tokens**: Native SHA-256 hashed Bearer tokens stored in `mobile_access_tokens.token_hash`. Plaintext tokens are never stored, logged, or exposed in audit trails.
- **Sessionless Operation**: Mobile API runs strictly sessionless. `CompanyContext::setCompany` is activated explicitly during the request lifecycle and does not interact with browser sessions, cookies, or CSRF tokens (`api-v1.php` and `CommandSessionGuard` remain untouched).
- **Transport Security & Anti-Caching**: HTTPS/TLS enforcement on non-local environments. All mobile responses include `Cache-Control: must-revalidate, no-cache, no-store, private` and `Pragma: no-cache` to ensure intermediary caches and browsers never store authenticated or tenant responses.

### Authentication, MFA, and Security Revalidation
- **Cryptographic Challenges (`/auth/challenge`)**: 32-byte cryptographically secure random nonces hashed with SHA-256. Bounded 5-minute TTL. Atomic single-use consumption (`used_at` timestamp).
- **Login (`/auth/login`)**:
  - Validates challenge nonce proof and single-use constraint.
  - Safe generic `INVALID_CREDENTIALS` (401) errors for invalid email, wrong password, expired challenge, or nonce mismatch (no user enumeration).
  - Fortify TOTP MFA integration: Optional and honors existing confirmed Fortify MFA (`two_factor_secret` + `two_factor_confirmed_at`). No blanket compulsory MFA.
  - Recovery Codes: Single-use atomic consumption from encrypted recovery codes array.
  - Device Revocation: Devices in `mobile_devices` table can be revoked remotely (`/devices/{deviceId}/revoke`). Revoked devices immediately fail closed on login and subsequent bearer requests with `DEVICE_REVOKED` / `UNAUTHENTICATED`.
  - Password Revision Epoch: User password hash updates invalidate all active bearer tokens instantly via `password_epoch` check (`epoch-{sha256(password)}`).
  - Tenant Membership: User must be an active member of at least one company. Company selection cannot bypass active membership.

### Scope-Before-Return Boundary (Zero Oracle Policy)
- **Strict Execution Order**:
  1. Verify Bearer token existence, expiration, and token hash match.
  2. Verify device status (must exist and not be revoked).
  3. Verify user active status and password revision epoch.
  4. Verify requested company exists, is active, and the authenticated user holds an active membership.
  5. Verify user holds required module permissions (`inventory.stock.view`, `sales.invoice.view`, `customers.view`).
- **No Existence or Digest Oracle**:
  - All company lookups, product queries, customer queries, and future transaction/receipt lookups execute *strictly within* the authenticated company scope.
  - An unauthorized actor requesting a company they do not belong to receives `403 FORBIDDEN_TENANT_MISMATCH` before any database queries for products, customers, or receipts execute.
  - A valid payload digest, valid event ID, or receipt ID never authorizes return across company or actor scopes.
  - If a token is being revalidated or stale, cached data from another scope is never returned.

## 8. Planned Guard for Slice 3 Receipt & Financial Integration

For the upcoming Slice 3 offline sales intake and receipt retrieval (`/api/mobile/v1/companies/{companyId}/sales/offline` and receipt status endpoints):
1. **Planned Guard Contract (`ReceiptScopeGuard`)**:
   - The receipt guard requires verification of:
     1. Matching company context (`companies.public_id == route.companyId`).
     2. Matching actor / device scope (receipt author or device that created the offline sale, unless caller holds `sales.invoice.view` / `Manager` / `Owner` role).
     3. Active POS / invoicing permissions at receipt return time.
2. **Replay & Idempotency Isolation**:
   - Outbox replay and receipt acknowledgments look up records strictly scoped by `company_id`.
   - Inquiries by `client_event_id` or `payload_digest` across tenants are rejected immediately with 404/403 without acknowledging receipt existence or validity of the digest.
3. **Database Concurrency & Locking**:
   - Atomic idempotency check and journal posting inside database transactions with row-level locks (`SELECT ... FOR UPDATE` on idempotency keys) to prevent duplicate economic ledger entries during mobile network retries.

