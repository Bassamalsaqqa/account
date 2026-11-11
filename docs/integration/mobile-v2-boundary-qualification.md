# Mobile V2 Boundary Qualification

Baseline: Stacked on PR88 `origin/t3/mobile-api-auth-slice2` HEAD `9f73ca8952da6bbfc0eabadbb01e51892ace8bc6` (supervisor verified). Prepared checkout started from supervisor baseline `f8a0cf7012c4875bec7320d42e0f8dde58593b52`. Branch `t3/mobile-v2-boundary-qualification`.

## 1. Architectural Scope & Default-OFF Boundary

Mobile V2 introduces an explicit, default-OFF boundary qualification for upcoming V2 endpoints before routing, session initialization, or CSRF verification:

1. **Dual-Gate Activation**: Mobile V2 requires both `config('mobile.enabled')` AND `config('mobile.v2_enabled')` to be `true`. Both default to `false`. When either gate is disabled, all `/api/mobile/v2/*` requests return HTTP 404 `MOBILE_API_DISABLED` in schema-compliant JSON with `Cache-Control: no-store, no-cache, must-revalidate, private` and `Pragma: no-cache`.
2. **Registration Before Routing**: Registered globally via `bootstrap/app.php` alongside existing boundary middlewares. Intercepts `/api/mobile/v2` and `/api/mobile/v2/*` early. All other routes pass untouched. Route-level double mounting in `MobileApiServiceProvider` is prevented.
3. **Fail-Closed in Production**: No financial or business routes are registered under V2 in production. Any recognized root path or unknown V2 paths fail-closed with HTTP 404 `NOT_FOUND` in schema-compliant JSON.
4. **Test-Only Harness**: Authorization, boundary defenses, and identity qualification are verified using test-only routes registered exclusively in the testing environment (`api/mobile/v2/test-harness/*`), never present in production.

## 2. Supervisor Audit Findings & Technical Resolution

### Finding 1: Compatibility `config/mobile.php` Syntax Defect Restored (P1)
- **Defect**: The closing docblock comment `*/` before `'enabled'` was missing in `config/mobile.php`, causing the `'enabled'` key to be commented out when parsed by direct PHP `require`.
- **Resolution**: Restored the closing `*/` before `'enabled'`. Confirmed via standalone subprocess PHP require that both `'enabled'` and `'v2_enabled'` keys exist by default with value `false`. Tested clean controlled environments without config overrides for `MOBILE_API_ENABLED=false` and `true`, ensuring V1 behavior is completely unchanged.

### Finding 2: Comprehensive Error Redaction & Standard Status Mapping (P2)
- **Defect**: Downstream exceptions (e.g. `AuthorizationException`, `ConflictHttpException`, `HttpExceptionInterface`) could forward raw messages or arbitrary objects. Validation error sanitization only matched top-level exact keys, leaking nested credentials or raw SQL in error messages. Duplicate keys in `StrictJsonParser` reflected arbitrary input keys.
- **Resolution**:
  - Implemented `ALLOWED_INTERNAL_ERROR_CODES` allowlist (`UNAUTHENTICATED`, `FORBIDDEN`, `FORBIDDEN_TENANT_MISMATCH`, `NOT_FOUND`, `METHOD_NOT_ALLOWED`, `NOT_ACCEPTABLE`, `CONFLICT`, `PAYLOAD_TOO_LARGE`, `UNSUPPORTED_MEDIA_TYPE`, `VALIDATION_FAILED`, `TOO_MANY_REQUESTS`, `BAD_REQUEST`, `INVALID_JSON`, `FINANCIAL_AUTHORITY_UNRESOLVED`, `MOBILE_API_DISABLED`, `INTERNAL_SERVER_ERROR`).
  - Untrusted exceptions and unreviewed error objects are mapped to standard status codes and bounded generic messages without leaking client text or exception details.
  - Safe reviewed messages for allowlisted internal codes are preserved if under 256 bytes and free of SQL/stack traces; all unreviewed downstream errors and arbitrary extra properties (`leak_extra`, `arbitrary_extra`) are stripped.
  - `StrictJsonParser` duplicate key exceptions are caught and masked with generic message `'The request body must be valid, well-formed canonical JSON.'`.
  - Nested dotted credential keys (`auth.password`, `credentials.secret`) are redacted to `'The provided credential or secret field failed validation.'`.
  - Validation messages containing SQL fragments or stack lines are replaced with generic field validation messages.
  - Preserved valid bounded `Retry-After` header (0–86400s) on HTTP 429 and ensured `Cache-Control: no-store` on all error paths without silently converting 403 into 500.

### Finding 3: Strict Rejection of GET/HEAD Bodies & Truthful Test Requests (P2)
- **Defect**: Middleware checked `trim($rawBody) !== '[]'`, allowing whitespace/empty-array bodies on GET/HEAD. Test helper `getJson()` in Laravel automatically sent `json_encode([])` (`"[]"`) as request body, masking true GET behavior.
- **Resolution**:
  - Middleware rejects any non-empty body on `GET` and `HEAD` requests (`$rawBody !== ''`), returning HTTP 400 `BAD_REQUEST`.
  - Overrode test-harness `getJson()` to execute clean body-less `GET` requests with `Accept: application/json`.
  - Added recursive validation of query parameters (`validateQueryParams`) bounding nesting depth (<= 16) and rejecting control characters/null bytes in keys and values.

### Finding 4: Support Impersonation Denial & Ambient Identity Isolation (P2)
- **Defect**: Identity middleware only tested sessions without Bearer tokens and failed to detect active `SupportViewContext` or `SupportViewSession::KEY` when a valid Bearer token was supplied. Ambient browser actors could conflate with native tokens.
- **Resolution**:
  - Explicitly denied requests where `SupportViewContext::active()` or `SupportViewSession::KEY` is detected in session or cookies with HTTP 403 `FORBIDDEN` (`"Support impersonation is unauthorized on Mobile V2."`).
  - Active tenant membership is verified authoritative via `company_user` (`User` has no `active` column).
  - Ambient browser session mismatch (`auth()->check() && auth()->id() !== $user->id`) is rejected with HTTP 401 `UNAUTHENTICATED`.
  - Bound verified native actor into `auth()->setUser($user)` inside the pipeline so `CompanyContext::user()` matches the verified actor, preventing identity conflation.

### Finding 5: Financial Authority Denial & Fresh Grants Verification (P1)
- **Defect**: V2 Bearer tokens must not possess financial mutation or posting capabilities. Role bypasses (`Administrator`, `SuperAdmin`) must not grant financial authority.
- **Resolution**:
  - Bearer tokens qualify staff/device identity ONLY, never financial posting permission.
  - All financial capabilities matching financial patterns (`sales.`, `purchasing.`, `money.`, `payroll.`, `customer.payment.`, `vendor.payment.`, `inventory.stock.`) are explicitly rejected with HTTP 403 `FINANCIAL_AUTHORITY_UNRESOLVED`.
  - Only explicitly reviewed non-financial capabilities on `ALLOWED_NON_FINANCIAL_CAPABILITIES` allowlist (`product.viewAny`, `identity.verify`, `identity.probe`) are permitted.
  - Role names grant zero bypass; `userHasPermission` evicts Spatie permission cache (`forgetCachedPermissions`) and checks fresh permissions without role exemptions.

### Finding 6: Route Mounting & Global Boundary Architecture
- **Defect**: `MobileApiServiceProvider` mounted `MobileV2ApiBoundary` onto the route group when it was already prepended globally in `bootstrap/app.php`, causing double middleware execution and double IP rate limit increments.
- **Resolution**: Removed redundant route-level middleware mounting. Mapped root path `/api/mobile/v2` and fallback route directly in provider. Each request increments the IP rate limiter by exactly 1 attempt.

### Finding 7: Token Bounds & Payload Company Alias Validation
- **Defect**: Raw tokens were silently trimmed. Company aliases (`company_id`, `companyId`) accepted non-string types or disagreed with route parameters.
- **Resolution**:
  - Extracted raw token using `substr($authHeader, 7)` without `trim()`. Tokens with whitespace, control characters, or length > 256 bytes return HTTP 401 `UNAUTHENTICATED`.
  - Validated payload company alias types strictly: non-string types (array, int, null, bool), alias disagreement (`company_id` != `companyId`), and route `{companyId}` mismatches return HTTP 422 `VALIDATION_FAILED` before database lookups.

## 3. Test Custody, Command Logs & Verification

The suite was executed on an isolated, disposable MariaDB test schema using `tests/Support/run-phase8-disposable.php`:

```bash
# Disposable Qualification Test Run
APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 \
  PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 \
  php tests/Support/run-phase8-disposable.php -- tests/Feature/Mobile/V2/MobileV2BoundaryQualificationTest.php

# Result:
# Owned primary test schema: accounting_p8_tmp_561618f2f59a
# PHPUnit 12.5.36 by Sebastian Bergmann and contributors.
# ............................                                      28 / 28 (100%)
# Time: 00:11.104, Memory: 79.00 MB
# OK (28 tests, 219 assertions)
# Owned primary schema cleaned.

# Unit Mobile Test Suite Run
php tests/Support/run-phase8-disposable.php -- tests/Unit/Mobile
# OK (40 tests, 260 assertions)
# Owned primary schema cleaned.

# Python Contract & Schema Suite
python3 ./tools/validate-mobile-contract.py && python3 -O ./tools/validate-mobile-contract.py
# SUCCESS: All 210 contract, schema, fixture, regression, and negative tests passed!

# Code Formatting & Static Analysis
./vendor/bin/pint --test
# PASS: 0 issues
./vendor/bin/phpstan analyse
# [OK] No errors across 738 files
```

## 4. Policy Holds and Unresolved Authority Gates

### Actual Authority Denial (`actualauthoritydenial`)
Bearer tokens qualify staff and device identity ONLY. They NEVER confer financial posting or mutation authority. Financial authority on Mobile V2 is completely unresolved. Any request attempting financial mutations or requiring financial permissions (e.g., `sales.invoice.post`, `sales.invoice.create`, `money.receipt.post`) is explicitly rejected with HTTP 403 `FINANCIAL_AUTHORITY_UNRESOLVED`. No fake passes, temporary allowances, or invented sales permissions are introduced.

### Write Transaction Revalidation (`writetxn`)
Middleware identity and permission checks operate solely as early boundary gates. They do NOT provide linearization or race-proof guarantees against concurrent token revocation, password changes, membership updates, or grant revocation. Future financial writers MUST revalidate actor identity, device status, password revision epoch, active company membership, and specific financial capabilities INSIDE the canonical write transaction under database locks.

### Currency Writer Holding (`currencywriter`)
Multi-currency arithmetic, exchange rate conversions, and money account ledger postings must use existing canonical financial writers. Mobile V2 establishes no financial writers, currency calculators, or ledger bypasses.

### Receipt Uniqueness and Sequence Race Holding (`receiptuniquerace`)
Receipt and document sequence uniqueness and idempotency cannot rely on client-side timestamps or uncoordinated event IDs. Unique sequence allocation and conflict handling must be canonically enforced and serialized inside database write transactions.

### Offline Policy Holds (`offlinepolicyholds`)
Offline intake, offline sale queuing, and offline lease grants remain strictly held and disabled. No offline policies or default numeric limits are invented without an approved financial authority model and business review.

## 5. Migrations & Operational Summary

- **Migrations**: 0 migrations added. Existing schemas and migrations remain untouched.
- **Canonicity**: Canonical financial writers, browser guards, and session CSRF protections remain untouched.
- **Contract Parity**: Frozen Mobile V1 schemas and `manifest.sha256` remain completely unmodified; 210/210 Python contract tests continue to pass in normal and optimized modes.
