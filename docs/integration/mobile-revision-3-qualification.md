# Mobile Contract Revision 3 — Qualification & Provenance Record

## 1. Upstream Contract Mirror Provenance & Manifest Integrity

### Upstream Source
- **Origin Repository**: `small-trader-android`
- **Upstream Commit**: `6bb11d9863dabc95143907c0268b0f7d7ab8df8d` (PR 11, branch `origin/t3/financial-contract-revision3-draft`)
- **Bundle Directory**: `contracts/mobile-revision3/`
- **Total Bundle File Count**: Exactly 53 files (51 files under `docs/integration/mobile-v1/**` + `tools/validate-mobile-contract.py` + `tools/requirements-validator.txt`).
- **Manifest Integrity**:
  - Manifest file: `contracts/mobile-revision3/docs/integration/mobile-v1/manifest.sha256`
  - Manifest SHA-256: `e8e3fe672a8f7aceb3152c3dd990b4fb137fa517b24038e468659e0fdf5a87a7`
  - Manifest covers all 50 schema, fixture, OpenAPI, and markdown contract documents.
  - Zero mismatches, zero extra files, zero missing files when compared against upstream git archive of commit `6bb11d9863dabc95143907c0268b0f7d7ab8df8d`.

### Preservation of Existing Backend Contract (Revision 2)
- The primary backend tree files at `docs/integration/mobile-v1/**`, `tools/validate-mobile-contract.py`, and `tools/requirements-validator.txt` remain strictly intact at Revision 2.
- **Revision 2 Manifest SHA-256**: `ba75352349e552f53b02ad7159570171f88943dc26e5bdf2a4b013386b0d0659` (39 contract files, 210 checks pass under Python 3 and Python 3 -O).
- No repinning or alteration to PR 88 or PR 89 base trees.

### Upstream Validator Execution
- The upstream bundle validator runs directly inside the isolated bundle:
  * `python3 contracts/mobile-revision3/tools/validate-mobile-contract.py` (303/303 checks pass)
  * `python3 -O contracts/mobile-revision3/tools/validate-mobile-contract.py` (303/303 checks pass)
- Manifest fail-closed verification includes live filesystem mutation test (Check 27a) which creates and cleanly deletes an owned probe file without altering historical files.

### Schema Versioning & Directory Layout
- No mandatory physical schema-directory rename was performed. API v2 schemas, endpoints, and archived v1 references are already version-separated within the contract specification (`archived-v1-*`, `*-v2.json`, OpenAPI v1/v2 paths).

---

## 2. Pure PHP Revision 3 Qualification Helpers

Implemented in `App\Support\Mobile\Contract\Revision3`:
- `OnlineSaleIntentProfile`:
  * Validates context keys strictly (`['actor_id', 'company_id', 'currency_minor_units']`).
  * Enforces Crockford ULID regex (`^[0-7][0-9A-HJKMNP-TV-Z]{25}$`) on actor and company identifiers.
  * Rejects boolean as integer for `currency_minor_units` (scale 0..6).
  * Strict raw request schema validation before projection: rejects unknown root, line, or payment properties.
  * Line item validation: requires between 1 and 100 items. Requires `tax_rate_id` on every line item; missing `tax_rate_id` throws `IntentProfileValidationException` (never defaults to implicit NoTax).
  * Explicit NoTax (`tax_rate_id: null`) requires `tax_rate: "0.000000"` and `tax_inclusive: false`.
  * Real zero-rate master (`tax_rate_id` Crockford ULID with `tax_rate: "0.000000"`) is recognized as distinct.
  * Enforces positive conversion to base and quantity (`DEC(20,6)` scale <= 6, strictly positive) and non-negative unit price.
  * Preserves exact original string decimal lexemes verbatim across line ordering, UTF-16 restricted JCS sorting, and SHA-256 payload digest derivation.
  * Unpaid settlement mode allows omission or explicit `null` for `payment_method` and `cash_account_id`, normalizing to explicit `null` in the intent profile; for `full` and `partial`, requires `payment_method: "cash"` and a valid `cash_account_id` ULID.
- `PaymentSettlementValidator`:
  * Side-effect free predicates using `Brick\Math\BigDecimal`.
  * Explicit currency minor units context integer 0..6; rejects booleans, negative, or > 6.
  * Validates decimal string lexemes: finite, non-exponent, DEC(20,6), zero IEEE-754 floats.
  * Allows trailing zeros representing integer currency minor units (e.g. `50.000000` at minor units = 2).
  * Rejects overprecision beyond minor units without rounding (e.g. `0.000001` at minor units = 2).
  * First-Release Policy: positive financial grand total only (`grand_total > 0`). Rejects `grand_total <= 0` across all paid, unpaid, and offline modes.
  * Online settlement predicates:
    - `unpaid`: `amount == 0` with `grand_total > 0`.
    - `partial`: `0 < amount < grand_total`.
    - `full`: `amount == grand_total` (`amount > 0`). Rejects both overpayment and underpayment under the first-release exact policy.
  * Offline cash intake predicate:
    - Full mode tender conservation: `amount_tendered == amount_settled + change_returned`.
    - `amount_settled == grand_total > 0`.
  * Pure line calculator parity (`calculateCanonicalLine`):
    - Matches exact decimal arithmetic of `SalesLineCalculator` and Python verifier.
  * Adaptation illustrations (contract qualification only, not server runtime):
    - `adaptOfflineCashToCanonicalReceipt`: maps net retained settled amount (e.g. tender 60, total 50, change 10 -> receipt 50), preserving audit fields separately.
    - `adaptOnlinePaymentToCanonicalChoice`: maps validated payment intent to `ImmediateSaleCommands` `$choice` array. Unpaid maps exactly to `['mode' => 'unpaid']` only after profile digest derivation, stripping wire payment fields without mutating wire profile, digest, or line `tax_rate_id: null`. Full and partial require reviewed payment date (valid `Y-m-d`) and company-scoped cash account resolver returning positive internal integer ID, failing closed without leaking credentials.

---

## 3. Test Coverage & Verification Evidence

### Python Contract Validation
- Bundle suite: `contracts/mobile-revision3/tools/validate-mobile-contract.py`
  * Normal execution: 303 checks passed.
  * Optimized execution (`-O`): 303 checks passed.
- Existing Revision 2 suite: `tools/validate-mobile-contract.py`
  * Normal execution: 210 checks passed.
  * Optimized execution (`-O`): 210 checks passed.

### PHP Test Execution
- Existing pure contract suite:
  * `tests/Unit/Mobile/Contract/CanonicalJsonSerializerTest.php` (25 tests, 114 assertions)
  * `tests/Unit/Mobile/Contract/ContractBoundaryTest.php` (5 tests, 114 assertions)
  * Baseline total: 30 tests, 228 assertions passed.
- New Revision 3 pure qualification suite:
  * `tests/Unit/Mobile/Contract/Revision3/PaymentSettlementValidatorTest.php` (6 tests, 51 assertions)
  * `tests/Unit/Mobile/Contract/Revision3/OnlineSaleIntentProfileTest.php` (17 tests, 57 assertions)
  * `tests/Unit/Mobile/Contract/Revision3/CrossLanguageParityTest.php` (7 tests, 47 assertions)
  * Revision 3 total: 30 tests, 155 assertions passed.
- Combined pure mobile contract suite:
  * 60 tests, 383 assertions, 0 failures, 0 errors.

### Code Style & Static Analysis
- Pint: `vendor/bin/pint --test app/Support/Mobile/Contract tests/Unit/Mobile/Contract` passed.
- PHPStan: `vendor/bin/phpstan analyse --memory-limit=1G app/Support/Mobile/Contract tests/Unit/Mobile/Contract` passed with 0 errors.

---

## 4. Architectural Boundaries & Future Financial Holds

In accordance with `AGENTS.md` and repository architectural invariants, the following roadmap items remain strictly on **HOLD** and are not activated or modified in this slice:

1. **Currency Minor Units Writer Unification**:
   - Source inspection reveals divergence in existing backend services: `SalesDocumentRules` hardcodes `$currency === 'JOD' ? 3 : 2`, `PostCustomerPaymentAction` hardcodes `in_array($currency, ['JOD', 'KWD', 'BHD', 'OMR']) ? 3 : 2`, while `MoneyValues` supports dynamic scale. Unifying currency scale rules across all ledger writers remains on hold for a dedicated financial unification slice.
2. **Explicit API v2 Default OFF Boundary**:
   - Existing v1 routing and authentication middleware passes non-v1 prefixes (`/api/mobile/v2/**`). No v2 routes, endpoints, or controllers are registered or exposed.
3. **Cross-Version Event & Tenant Deduplication**:
   - Client event IDs and company public IDs must be uniquely enforced across contract versions within the same MariaDB transaction, verifying absence-row and rollback atomicity. No database migrations or schema alterations are introduced in this qualification slice.
4. **Offline Business Policy & Device Leases**:
   - Offline multi-device sequence locking, server-issued device leases, and outbox reconciliation remain OFF.
5. **No Financial Runtime or Production Mutations**:
   - Zero modifications to Eloquent models, financial posting services (`AccountingPostingService`), stock movement services, database migrations, or production deployment scripts.
