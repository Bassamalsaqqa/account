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

## 2. Pure PHP Revision 3 Qualification Helpers & Defect Corrections

Implemented in `App\Support\Mobile\Contract\Revision3`:
- `OnlineSaleIntentProfile`:
  * **Absolute Regex Anchors (`\A...\z`)**: Replaced all `^$` regex anchors with absolute string anchors (`\A...\z`) throughout Revision 3 code to strictly reject terminal newlines across Crockford ULIDs, UUIDs, ISO currencies, calendar dates, and exact decimal money lexemes across context, raw JSON, native arrays, and profile verification.
  * **AST Shape Validation Before Projection**: When parsing raw JSON strings, AST structure is validated before calling `toNative()`, asserting that `lines` is an instance of `StrictJsonArray` and that line items and payment are instances of `StrictJsonObject`. Prevents JSON associative objects (including numeric-keyed objects like `{"0": ...}`) from silently projecting into list arrays. On native PHP array inputs, strictly asserts `array_is_list($lines)` and associative arrays for line items and payment, with recursive validation rejecting arbitrary PHP objects, floats, malformed UTF-8, and Unicode surrogates.
  * **Strict Request Schema Enforcement**: Unpaid settlement mode strictly requires `amount` to represent zero (`BigDecimal::isZero()`), rejecting positive amounts (e.g. `'50'`). Non-null payment method or cash account instructions on unpaid requests are rejected. Full and partial modes require strictly positive amounts, `payment_method: 'cash'`, and valid Crockford ULID `cash_account_id`.
  * **Deep Normative Profile Verification**: In `OnlineSaleIntentProfile::verify()`, the entire nested normative profile is exhaustively validated against the full schema rules and explicit required nullable payment keys before digest derivation. Eliminates false-positive verification where a malformed profile (such as `lines => 'invalid'`) matches its own computed digest.
  * **Lexeme and Line Invariants**: Preserves original decimal lexemes, line order, and explicit `tax_rate_id: null` verbatim across line ordering, UTF-16 restricted JCS sorting, and SHA-256 payload digest derivation.
- `PaymentSettlementValidator`:
  * Side-effect free predicates using `Brick\Math\BigDecimal`.
  * Explicit currency minor units context integer 0..6; rejects booleans, negative, or > 6 across all public calls.
  * Validates decimal string lexemes: finite, non-exponent, DEC(20,6), zero IEEE-754 floats.
  * Allows trailing zeros representing integer currency minor units (e.g. `50.000000` at minor units = 2).
  * Rejects overprecision beyond minor units without rounding (e.g. `0.000001` at minor units = 2).
  * **First-Release Policy Scope**: Positive financial grand total only (`grand_total > 0`). Rejects `grand_total <= 0` across all paid, unpaid, and offline modes. This constraint is an explicit mobile contract qualification requirement for the first release that is deliberately stricter than canonical backend unpaid invoices (which may permit zero-total orders in certain canonical workflows); it does not constitute a global canonical accounting writer claim.
  * Online settlement predicates:
    - `unpaid`: `amount == 0` with `grand_total > 0`.
    - `partial`: `0 < amount < grand_total`.
    - `full`: `amount == grand_total` (`amount > 0`). Rejects both overpayment and underpayment under the first-release exact policy.
  * Offline cash intake predicate:
    - Full mode tender conservation: `amount_tendered == amount_settled + change_returned`.
    - `amount_settled == grand_total > 0`.
  * Pure line calculator parity (`calculateCanonicalLine`):
    - Matches exact decimal arithmetic of `SalesLineCalculator` and Python verifier.
    - Strictly validates minor units (0..6), positive quantity lexeme, decimal money unit price lexeme, and tax rate decimal lexeme, preventing uncaught exponents or negative values.
  * **Adaptation Illustrations (Qualification Only, Not Server Runtime)**:
    - `adaptOfflineCashToCanonicalReceipt`: maps net retained settled amount (e.g. tender 60, total 50, change 10 -> receipt 50), preserving audit fields separately. Distinct offline audit-only mode is supported when command illustration arguments are omitted; if command mapping is requested, all parameters (payment date, cash account resolver, company ID, customer ID) are required and fail closed on incomplete context.
    - `adaptOnlinePaymentToCanonicalChoice`: maps validated payment intent to `ImmediateSaleCommands` `$choice` array. Unpaid maps exactly to `['mode' => 'unpaid']` only after profile digest derivation, stripping wire payment fields without mutating wire profile, digest, or line `tax_rate_id: null`. Full and partial require reviewed payment date (valid `Y-m-d`) and company-scoped cash account resolver.
    - **Resolver Reflection & Error Redaction**: Cash account resolvers are inspected via Reflection to ensure they explicitly accept at least 2 arguments (`company_id`, `wire_account`) without single-argument fallback. Resolved money account ID must be a positive integer (rejects boolean, zero, negative). Resolver exceptions are caught and redacted to prevent leaking internal database errors or credentials. Arbitrary callables are not claimed to verify tenant ownership (pure illustration only).

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
  * `tests/Unit/Mobile/Contract/Revision3/PaymentSettlementValidatorTest.php` (14 tests, 112 assertions)
  * `tests/Unit/Mobile/Contract/Revision3/OnlineSaleIntentProfileTest.php` (46 tests, 85 assertions)
  * `tests/Unit/Mobile/Contract/Revision3/CrossLanguageParityTest.php` (10 tests, 49 assertions)
  * Revision 3 total: 70 tests, 246 assertions passed.
- Combined pure mobile contract suite:
  * 100 tests, 474 assertions, 0 failures, 0 errors.

### Code Style & Static Analysis
- Pint: `vendor/bin/pint --test app/Support/Mobile/Contract/Revision3 tests/Unit/Mobile/Contract/Revision3` passed.
- PHPStan: `vendor/bin/phpstan analyse --memory-limit=1G app/Support/Mobile/Contract/Revision3 tests/Unit/Mobile/Contract/Revision3` passed with 0 errors.

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

## 5. Supervisor follow-up to independent review (2026-11-12)

The independent review at `5d8f18c` confirmed the five original corrections. A small follow-up bounds native traversal at the shared maximum nesting depth (including circular references), supports static-method string resolvers using PHP 8.4-compatible reflection, and requires a fully validated normative profile with matching minor-unit context before online payment projection. Resolver ownership remains the caller's obligation; these pure helpers provide no runtime authorization or posting proof.

Supervisor execution after these changes: `vendor/bin/phpunit tests/Unit/Mobile/Contract` passed **105 tests / 481 assertions**, with no warnings or deprecations. Scoped Pint and PHPStan on the Revision3 source and tests passed. Earlier Python 303/210 normal and optimized results remain applicable: neither contract bundle nor validator changed. No database, migration, financial route, deployment, or production action occurred. A new independent review of this follow-up remains pending.
