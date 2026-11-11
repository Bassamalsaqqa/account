# Mobile V1 Contract Provenance & Backend Integration Notes

## 1. Mirrored Contract Provenance & Manifest Verification

### Authoritative Current Mirror: Coordinated DRAFT Revision 2
- **Authoritative Source**: Mirrored from Android git repository at commit `ec8220934839ade0c4fe5408398ab1a313feb09c` (branch `origin/t3/android-mobile-contract`).
- **Mirrored Paths**:
  - `docs/integration/mobile-v1/` (OpenAPI 3.1 specification, ADR-0001, JSON Schemas Draft 2020-12, golden fixtures, regression fixtures, revision 2 validation cases, `manifest.sha256`)
  - `tools/validate-mobile-contract.py`
  - `tools/requirements-validator.txt`
- **Revision 2 Manifest Integrity**:
  - All 39 mirrored contract files verified against `docs/integration/mobile-v1/manifest.sha256`.
  - Manifest file SHA-256: `ba75352349e552f53b02ad7159570171f88943dc26e5bdf2a4b013386b0d0659`.
  - Contract validation suite passes with zero warnings under both standard and optimized Python execution:
    * `python3 tools/validate-mobile-contract.py` (210/210 checks pass)
    * `python3 -O tools/validate-mobile-contract.py` (210/210 checks pass)
    * Fails closed if `manifest.sha256` is missing or tampered.
    * Includes 89 revision 2 positive and negative fixture validations.

### Historical Provenance: Revision 1
- **Historical Source**: Mirrored from frozen Android repository at PR4 head `088f1f9d5fdb231b31d7bc1525092b500411cb5f`.
- **Historical Manifest SHA-256**: `a8d04a130bdb0bd909a7cfabdde93fb6f90c96cc07dbedfbd01971bdb07c6733` (38 contract files, 120 validation checks).
- **API Prefix**: Remains `/api/mobile/v1`. Revision 2 is an explicit coordinated draft revision, not a silent mirror rewrite.

## 2. Cross-Platform Interoperability (Android PR5 & PR6 Parity)

- **Android PR5 (`t3/android-contract-foundation` @ `b79ed6a`)**:
  - Implements pure Kotlin `CanonicalJsonSerializer.kt` and `StrictJsonParser.kt` adhering to Small Trader Restricted Canonical JSON Profile v1 (RFC 8785 subset).
  - Confirmed bit-for-bit canonical serialization and SHA-256 digest parity across all 5 golden canonical vectors (`primitive_sorting`, `currency_total`, `arabic_fractions`, `utf16_surrogate_key_ordering`, `control_char_escaping`) and golden offline sale intake (`sha256:d442ce49a7609a9a42bb5165990d481d1c7c563cb8030403487099a9919728d9`).
- **Android PR6 (`t3/room-outbox-foundation` @ `5c86ee9`)**:
  - Implements bounded Room offline sales outbox with terminal event immutability, scoped queue locking on HTTP 401, and deterministic payload replay.
- **Backend Pure PHP Implementation (PR86 baseline @ `614b1da44ef8715d64a2477e635246f760a3f84c`)**:
  - `App\Support\Mobile\Contract\CanonicalJsonSerializer`
  - `App\Support\Mobile\Contract\StrictJsonParser`
  - `App\Support\Mobile\Contract\DecimalLexeme`
  - `App\Support\Mobile\Contract\ContractConstants`
  - Full PHPUnit contract test suite:
    * `tests/Unit/Mobile/Contract/CanonicalJsonSerializerTest.php` (25 tests, 114 assertions)
    * `tests/Unit/Mobile/Contract/ContractBoundaryTest.php` (5 tests, 114 assertions)
    * Total pure contract suite: 30 tests, 228 assertions passing with zero application database dependency.

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

## 5. Scope Boundaries for this Slice

- **This Slice Inclusions**:
  1. Exact mirror of `docs/integration/mobile-v1/` tree at Android commit `ec8220934839ade0c4fe5408398ab1a313feb09c` (manifest SHA-256 `ba75352349e552f53b02ad7159570171f88943dc26e5bdf2a4b013386b0d0659`).
  2. Authoritative `tools/validate-mobile-contract.py` and `tools/requirements-validator.txt` from same commit (210 checks passing under python3 and python3 -O).
  3. Preserved historical manifest provenance outside mirrored directory.
  4. Integration and decision record notes outside mirrored directory.
  5. Verification that all pure PHP contract foundation tests pass (30 tests, 228 assertions).
- **Explicit Exclusions**:
  - NO route activation.
  - NO mobile authentication endpoint or handler expansion (deferred to next coordinated AGY backend auth/read DTO qualification slice).
  - Existing PR 88 head `353ae4b252008d0fc1f0a532e11666a845757216` uses old mirror; separate adaptation later.
  - Preserve local unpublished P2 commit `b2465878384721973dc1937e451c1dc0939b8ff5` in other worktree.
  - No web picker changes.
  - No consumer pin changes (Android parent owns PR 5/6).
  - NO financial service or ledger posting mutations; financial slice remains on HOLD.
  - NO database migrations.
  - NO production merge, deployment, or Hostinger access.

## 6. String and Container Acceptance Boundaries

Decoded JSON strings, including keys, are limited to 65,536 UTF-16 code units:
BMP characters (including Arabic and euro) count as one, supplementary characters
as two, whether raw UTF-8 or escaped surrogate pairs. Raw input is limited to
1,048,576 UTF-8 bytes. The parser counts decoded units incrementally without
rescanning accumulated strings. This clarification is coordinated with the Android
counterpart.

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

## 7. Coordinated DRAFT Revision 2 Decisions (from `mobile-v1-revision-2-decisions.md` & ADR-0001)

### MFA Retry Semantics
- When MFA is required, the login endpoint responds with `status: "MFA_REQUIRED"`, `retry: "fresh_challenge"`, an HTTP 403 or 401 response per schema, and a transient challenge token.
- Ordinary challenge burn rules apply: each attempt consumes the challenge; the client must obtain a fresh challenge nonce and ID before retrying with credentials and MFA code or recovery code.

### Immutable Staff/Device Token & Fresh Tenant Identity
- Auth tokens bind immutably to staff (actor) and device identity.
- Multi-company authorization, active membership, roles, and permission grants are evaluated fresh per explicit tenant route (`/api/mobile/v1/companies/{companyId}/...`), eliminating stale permissions on token reuse.

### Field Width Alignment with Account Migrations
Field widths reflect actual Account database column definitions (no truncation):
- Companies (`2026_09_29_000001`): `name` varchar(255)
- Users (`0001_01_01_000000`): default Laravel string varchar(255)
- Warehouses (`2026_09_30_000019`): `name` varchar(255), `code` varchar(64)
- Money accounts (`2026_10_02_000036`) and Ledger accounts (`2026_09_29_000013`): `name` varchar(255), ledger `code` varchar(32)
- Tax rates (`2026_10_02_000032`): `name` varchar(255), `code` varchar(32)
- Units (`2026_09_30_000018`): `name` varchar(128)
- Products (`2026_09_30_000021`): `name` varchar(255), `sku` varchar(128)
- Product barcodes (`2026_09_30_000023`): `barcode` varchar(128)
- Customers (`2026_10_02_000031`): `name` varchar(255), `code` varchar(64), `phone` varchar(64)
- Default-unit DTO has no name field; base-unit and all-unit names use 128 chars.

### Offline Lease Policy
- Offline status is disabled/unavailable (`status: "unavailable"`).
- NO offline transaction caps are granted.
- Schema enforces null lease fields (`device_lease_id: null`, `offline_cap_cents: null`, `lease_expires_at: null`, `sync_interval_seconds: null`).

### Master List Invariants
- `unit_masters: []` and `tax_masters: []` are permitted in bootstrap responses.
- Empty master lists indicate absent configuration, NOT sale readiness.

### Nullable Product Tax Pair
- `tax_rate` and `tax_rate_id` in product catalog DTO form a nullable pair (`{"tax_rate": null, "tax_rate_id": null}` or both non-null).
- A null pair represents an unknown or unspecified product tax association, NOT a zero-rated tax rate.
- Line explicit taxes are resolved against canonical company tax masters; no new product tax foreign key, no guessed tax percentages, and no arbitrary caps.

## 8. Financial Readiness Hold & Immutable Economic Invariants

- **Financial Schemas & Fixtures Bit-for-Bit Unchanged**:
  All financial schemas and golden fixtures remain identical between Revision 1 (`088f1f9d5fdb231b31d7bc1525092b500411cb5f`) and Revision 2 (`ec8220934839ade0c4fe5408398ab1a313feb09c`):
  * `schemas/sale-preview-request.json`
  * `schemas/sale-preview-response.json`
  * `schemas/sale-commit-request.json`
  * `schemas/sale-commit-response.json`
  * `schemas/offline-sale-intake-request.json`
  * `schemas/offline-sale-intake-response.json`
  * `schemas/sale-event-status-response.json`
  * `fixtures/golden-canonical-vectors.json`
  * `fixtures/golden-offline-sale-intake.json`
  * `fixtures/golden-sale-commit.json`
  * `fixtures/golden-sale-event-status.json`
  * `fixtures/golden-sale-preview.json`
  * `fixtures/regression-*`
- **Known Gaps Blocking Financial Activation**:
  * Public sale preview lacks explicit line `tax_rate_id` and server-side resolution of tax percentage and inclusive snapshot.
  * Offline intake event structure lacks explicit line tax identification and likely requires a subsequent event version.
  * Financial protocol is NOT READY; the financial slice remains held.
