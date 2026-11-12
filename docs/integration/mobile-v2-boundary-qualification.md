# Mobile V2 Boundary Qualification

Baseline: Stacked on PR88 `origin/t3/mobile-api-auth-slice2` HEAD `9f73ca8952da6bbfc0eabadbb01e51892ace8bc6` (supervisor verified). Prepared checkout started from supervisor baseline `f8a0cf7012c4875bec7320d42e0f8dde58593b52`. Branch `t3/mobile-v2-boundary-qualification`.

## 1. Architectural Scope & Default-OFF Boundary

Mobile V2 introduces an explicit, default-OFF boundary qualification for upcoming V2 endpoints before routing, session initialization, or CSRF verification:

1. **Dual-Gate Activation**: Mobile V2 requires both `config('mobile.enabled')` AND `config('mobile.v2_enabled')` to be `true`. Both default to `false`. When either gate is disabled, all `/api/mobile/v2/*` requests return HTTP 404 `MOBILE_API_DISABLED` in schema-compliant JSON with `Cache-Control: no-store, no-cache, must-revalidate, private` and `Pragma: no-cache`.
2. **Registration Before Routing**: Registered globally via `bootstrap/app.php` alongside existing boundary middlewares. Intercepts `/api/mobile/v2` and `/api/mobile/v2/*` early. All other routes pass untouched. Route-level double mounting in `MobileApiServiceProvider` is prevented.
3. **Fail-Closed in Production**: No financial or business routes are registered under V2 in production. Any recognized root path or unknown V2 paths fail-closed with HTTP 404 `NOT_FOUND` in schema-compliant JSON.
4. **Test-Only Harness**: Authorization, boundary defenses, and identity qualification are verified using test-only routes registered exclusively in the testing environment (`api/mobile/v2/test-harness/*`), never present in production.

## 2. Independent Audit Findings & Technical Resolution

### Finding 1: Ambient Guard & Session Isolation Without API Logout (P1)
- **Defect**: Both `MobileV2Authenticate` and `MobileV2ApiBoundary` previously called `auth()->logout()` in their `finally` blocks. On Laravel's `SessionGuard`, `logout()` cycles the `remember_token`, performs a database write to the `users` table, deletes the browser session auth key, queues remember cookie deletion, and fires `Logout` events—even for disabled paths, unknown routes, invalid tokens, or mixed ambient actors. Furthermore, `app(SupportViewContext::class)->clear()` cleared unowned ambient support context.
- **Resolution**:
  - Completely eliminated `auth()->logout()` in all API cleanup paths.
  - Implemented in-memory guard state capture and restoration: captures `$guard->hasUser()` and `$guard->user()` along with `$request->getUserResolver()` on entry; restores in-memory user via `$guard->setUser($priorUser)` when a prior user was present or `$guard->forgetUser()` when absent.
  - Eliminated `SupportViewContext::clear()` from `finally` blocks, preserving unowned ambient contexts while maintaining strict 403 rejection if active impersonation is detected on entry.
  - Verified that `remember_token` remains unchanged, session auth key is untouched, no `Logout` events are dispatched, no cookies are queued, and no user DB writes occur across disabled routes, unknown routes, invalid tokens, mixed actors, success requests, and thrown exceptions.

### Finding 2: Strict Canonical Error Redaction & Field Key Allowlist (P1)
- **Defect**: `standardizeErrorResponse` allowed any short downstream message without SQL keywords for allowlisted codes. Downstream exceptions and structured errors could leak raw credentials, arbitrary keys, or internal messages. Sensitive field keys were preserved verbatim, and `sanitizeValidationMessage` allowed arbitrary text.
- **Resolution**:
  - Enforced fixed, reviewed canonical messages (`defaultMessageForCode`) ALWAYS for untrusted downstream errors; eliminated regex-based SQL guesswork for downstream messages.
  - Introduced internal safe response tagging (`INTERNAL_SAFE_HEADER = 'X-Mobile-V2-Internal-Safe'`) to differentiate genuine boundary/auth responses from untrusted downstream responses.
  - Defined strict validation field key allowlist `ALLOWED_VALIDATION_FIELD_KEYS`. Unknown field keys are sanitized to generic `'field'`, never echoing arbitrary secret keys.
  - Sensitive field keys (matching `isSensitiveField`) strictly output generic message `['The provided credential or secret field failed validation.']`. Non-sensitive allowed keys strictly output bounded generic template `["The {$safeFieldName} field failed validation."]`. Arbitrary exception texts and nested credential details never propagate.
  - Verified across every allowed error code (`MOBILE_API_DISABLED`, `UNAUTHENTICATED`, `FORBIDDEN`, `FORBIDDEN_TENANT_MISMATCH`, `NOT_FOUND`, `METHOD_NOT_ALLOWED`, `NOT_ACCEPTABLE`, `CONFLICT`, `PAYLOAD_TOO_LARGE`, `UNSUPPORTED_MEDIA_TYPE`, `VALIDATION_FAILED`, `TOO_MANY_REQUESTS`, `FINANCIAL_AUTHORITY_UNRESOLVED`, `INTERNAL_SERVER_ERROR`, `BAD_REQUEST`, `INVALID_JSON`), ensuring downstream secret messages are redacted, status codes and bounded `Retry-After` headers are preserved, and `no-store` headers are enforced.

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
# Owned primary test schema: accounting_p8_tmp_e7cab1d13468
# PHPUnit 12.5.36 by Sebastian Bergmann and contributors.
# ...................................                               35 / 35 (100%)
# Time: 00:14.404, Memory: 79.00 MB
# OK (35 tests, 403 assertions)
# Owned primary schema cleaned.

# Unit Mobile Test Suite Run
php tests/Support/run-phase8-disposable.php -- tests/Unit/Mobile
# OK (40 tests, 260 assertions)
# Owned primary schema cleaned.

# Phase 11 API Security Suite Run
APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=" \
  php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase11/ApiSecurityTest.php
# OK (17 tests, 110 assertions)
# Owned primary schema cleaned.

# Mobile V1 Feature Tests Run
APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=" \
  php tests/Support/run-phase8-disposable.php -- tests/Feature/Mobile/AuthLoginTest.php tests/Feature/Mobile/AuthChallengeTest.php tests/Feature/Mobile/ProductCatalogTest.php
# OK (26 tests, 195 assertions)
# Owned primary schema cleaned.

# Python Contract & Schema Suite
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
