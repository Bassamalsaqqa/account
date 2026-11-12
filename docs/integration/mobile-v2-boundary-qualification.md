# Mobile V2 Boundary Qualification

Baseline: Stacked on PR88 `origin/t3/mobile-api-auth-slice2` HEAD `9f73ca8952da6bbfc0eabadbb01e51892ace8bc6` (supervisor verified). Prepared checkout started from supervisor baseline `f8a0cf7012c4875bec7320d42e0f8dde58593b52`. Branch `t3/mobile-v2-boundary-qualification`.

## 1. Architectural Scope & Default-OFF Boundary

Mobile V2 introduces an explicit, default-OFF boundary qualification for upcoming V2 endpoints before routing, session initialization, or CSRF verification:

1. **Dual-Gate Activation**: Mobile V2 requires both `config('mobile.enabled')` AND `config('mobile.v2_enabled')` to be `true`. Both default to `false`. When either gate is disabled, all `/api/mobile/v2/*` requests return HTTP 404 `MOBILE_API_DISABLED` in schema-compliant JSON with `Cache-Control: no-store, no-cache, must-revalidate, private` and `Pragma: no-cache`.
2. **Registration Before Routing**: Registered globally via `bootstrap/app.php` alongside existing boundary middlewares. Intercepts `/api/mobile/v2` and `/api/mobile/v2/*` early. All other routes pass untouched. Route-level double mounting in `MobileApiServiceProvider` is prevented.
3. **Fail-Closed in Production**: No financial or business routes are registered under V2 in production. Any recognized root path or unknown V2 paths fail-closed with HTTP 404 `NOT_FOUND` in schema-compliant JSON.
4. **Test-Only Harness**: Authorization, boundary defenses, and identity qualification are verified using test-only routes registered exclusively in the testing environment (`api/mobile/v2/test-harness/*`), never present in production.

## 2. Independent Audit Findings & Technical Resolution

### Finding 1: Ambient Guard & Session Isolation Without API Logout or Lazy Browser Auth (P1)
- **Defect**: Both `MobileV2Authenticate` and `MobileV2ApiBoundary` previously called `auth()->logout()` in their `finally` blocks, causing remember token cycling, user table writes, session key deletion, cookie queue mutations, and `Logout` events. Furthermore, `CompanyContext::clear()` invoked `user() -> auth()->user()`, triggering lazy browser session authentication and remember-me token resolution on session guards when `hasUser()` was false. Finally, `MobileV2ApiBoundary` previously omitted `CompanyContext` clearing on boundary exits.
- **Resolution**:
  - Added explicit, sessionless `CompanyContext::clearSessionless()` using stored explicit user and already-resolved guard user only (`hasUser() === true`), resetting company, Spatie team ID, and unsetting relations without calling `auth()->user()`.
  - Enforced `CompanyContext::clearSessionless()` both BEFORE entry and in FINALLY across all boundary paths (disabled 404, TLS, parser, rate limit, unknown route, and exception exits).
  - Captured guard state (`hasUser()`, prior user, and user resolver) BEFORE clearing context in `MobileV2Authenticate`.
  - Completely eliminated `auth()->logout()` across all API cleanup paths.
  - Verified with real remember-cookie headers and `hasUser()` initially false that 0 calls to `retrieveByToken` occur, 0 session mutations occur, 0 user DB writes occur, 0 `Logout` events fire, 0 cookies are queued, and `$guard->hasUser()` remains false across disabled, unknown, invalid, and success requests.

### Finding 2: Strict Canonical Error Redaction & Complete Removal of Internal Safe Header (P1)
- **Defect**: An internal header trust bypass (`INTERNAL_SAFE_HEADER = 'X-Mobile-V2-Internal-Safe'`) was used to bypass `standardizeErrorResponse`, allowing downstream responses to spoof the header or leak unreviewed messages, raw credentials, or unexpected extra fields.
- **Resolution**:
  - Completely DELETED the `INTERNAL_SAFE_HEADER` constant and header. `standardizeErrorResponse` trusts no HTTP header and no arbitrary public build message.
  - `buildErrorResponse` itself canonicalizes code, message, and details directly, guaranteeing that early boundary returns and downstream errors are safe and contain no internal headers.
  - Enforced fixed, reviewed canonical messages (`defaultMessageForCode`) uniformly across all status codes and error paths.
  - Preserved strictly nonsecret machine fields required by contract when explicitly validated (`reason` known enum, `revoked_at` ISO 8601 string, `requested_company_id`).
  - Validation error field keys are restricted to `ALLOWED_VALIDATION_FIELD_KEYS` (unknown keys mapped to generic `'field'`).
  - Downstream responses spoofing `X-Mobile-V2-Internal-Safe` (any value) are sanitized, secret messages redacted to canonical text, extra fields stripped, and the header removed from the response.
  - Preserved status codes, bounded `Retry-After` headers (1..86400), and `Cache-Control: no-store, no-cache, must-revalidate, private`.

### Finding 3: Removal of Invented Permissions & Global Forget Cache Thrash (P2)
- **Defect**: `ALLOWED_NON_FINANCIAL_CAPABILITIES` contained invented permissions `'identity.verify'` and `'identity.probe'`, which do not exist in the database and threw `PermissionDoesNotExist` 500 errors. Additionally, `app(PermissionRegistrar::class)->forgetCachedPermissions()` was called per request, causing global cache thrashing across all tenants.
- **Resolution**:
  - Removed invented `'identity.verify'` and `'identity.probe'` from allowlists; retained only the reviewed `product.viewAny` policy capability for read probes. Unknown capabilities return HTTP 403 `FORBIDDEN`.
  - Removed per-request `forgetCachedPermissions()` global cache eviction. Fresh permissions are verified using explicit Spatie team ID scoping (`setPermissionsTeamId($company->id)`) and unsetting model relations (`$user->unsetRelation('roles')->unsetRelation('permissions')`).
  - Tested that removing a role or permission takes effect immediately without global cache flushes, and that custom roles or `Administrator` assignees cannot bypass financial authority denials. Null identity qualification grants zero financial authority.

### Finding 4: All-Verbs Scoped Fallback Catchall Route (P2)
- **Defect**: `Route::fallback()` in Laravel defaults to `GET` only. As a result, enabled unknown requests with `POST`, `PUT`, `PATCH`, `DELETE`, or `OPTIONS` returned HTTP 405 `METHOD_NOT_ALLOWED` instead of the promised HTTP 404 `NOT_FOUND`.
- **Resolution**:
  - Implemented an all-verbs fallback route matching `['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD']` scoped strictly under `api/mobile/v2` with `->fallback()`.
  - Scoped to `api/mobile/v2` with `{fallbackPlaceholder?}` so it catches both root `/api/mobile/v2`, `/api/mobile/v2/`, and all nested unknown paths without shadowing real test harness routes or unrelated v1/web routes.
  - Added double-mount guard in `MobileV2ApiBoundary` to ensure each request consumes exactly 1 IP rate limit attempt.

### Finding 5: Stable Actor Rate Limiter Before Company Queries (P3)
- **Defect**: The authenticated actor rate limiter was evaluated after company database queries and membership checks, and included untrusted route strings in the key (`mobile_v2:actor:{$user->id}:company:{$routeCompanyId}`). Attackers could hammer the database with invalid company lookups (returning 403) while bypassing the actor rate limiter and causing cache key fanout.
- **Resolution**:
  - Moved the actor rate limiter immediately after identity verification (token, device, user, and password epoch verified), BEFORE company DB queries or permission checks.
  - Bounded to a stable key based strictly on verified actor ID: `mobile_v2:actor:{$user->id}`. Untrusted company route strings are completely excluded from cache keys.
  - Repeated requests with foreign or non-existent company IDs now increment the stable actor counter and hit HTTP 429 `TOO_MANY_REQUESTS` once the limit is reached.
  - Configured invalid/negative rate limit caps to fail closed to safe, reasonable bounds (120 attempts/min).

## 3. Test Custody, Command Logs & Verification

The suite was executed on an isolated, disposable MariaDB test schema using `tests/Support/run-phase8-disposable.php`:

```bash
# Disposable Qualification Test Run (V2 Boundary & Regressions)
APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 \
  PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 \
  php tests/Support/run-phase8-disposable.php -- tests/Feature/Mobile/V2/MobileV2BoundaryQualificationTest.php

# Result:
# Owned primary test schema: accounting_p8_tmp_d7a2b7cf5f85
# PHPUnit 12.5.36 by Sebastian Bergmann and contributors.
# .....................................                             37 / 37 (100%)
# Time: 00:22.475, Memory: 79.00 MB
# OK (37 tests, 488 assertions)
# Owned primary schema cleaned.

# Full Feature Mobile Regression Suite Run (All 12 feature suites)
APP_ENV=testing APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=" \
  PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 \
  PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 \
  php tests/Support/run-phase8-disposable.php -- tests/Feature/Mobile
# OK (99 tests, 921 assertions)
# Owned primary schema cleaned.

# Phase 11 API Security Suite Run
APP_ENV=testing APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=" \
  PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 \
  PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 \
  php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase11/ApiSecurityTest.php
# OK (17 tests, 110 assertions)
# Owned primary schema cleaned.

# Unit Mobile Test Suite Run
APP_ENV=testing APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=" \
  PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 \
  PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 \
  php tests/Support/run-phase8-disposable.php -- tests/Unit/Mobile
# OK (40 tests, 260 assertions)
# Owned primary schema cleaned.

# Python Contract & Schema Suite (Verified tracked at HEAD: tools/validate-mobile-contract.py)
python3 ./tools/validate-mobile-contract.py && python3 -O ./tools/validate-mobile-contract.py
# SUCCESS: All 210 contract, schema, fixture, regression, and negative tests passed!

# Code Formatting & Static Analysis
./vendor/bin/pint --test
# {"tool":"pint","result":"passed"}
./vendor/bin/phpstan analyse --no-progress
# [OK] No errors

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
