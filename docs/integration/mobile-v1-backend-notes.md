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
