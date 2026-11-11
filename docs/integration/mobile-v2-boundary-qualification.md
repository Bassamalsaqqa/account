# Mobile V2 Boundary Qualification

Baseline: Stacked on PR88 `origin/t3/mobile-api-auth-slice2` HEAD `9f73ca8952da6bbfc0eabadbb01e51892ace8bc6` (supervisor verified). Prepared checkout started from supervisor baseline `f8a0cf7012c4875bec7320d42e0f8dde58593b52`. Branch `t3/mobile-v2-boundary-qualification`.

## 1. Architectural Scope & Default-OFF Boundary

Mobile V2 introduces an explicit, default-OFF boundary qualification for upcoming V2 endpoints before routing, session initialization, or CSRF verification:

1. **Dual-Gate Activation**: Mobile V2 requires both `config('mobile.enabled')` AND `config('mobile.v2_enabled')` to be `true`. Both default to `false`. When either gate is disabled, all `/api/mobile/v2/*` requests return HTTP 404 `MOBILE_API_DISABLED` in schema-compliant JSON with `Cache-Control: no-store, no-cache, must-revalidate, private` and `Pragma: no-cache`.
2. **Registration Before Routing**: Registered globally via `bootstrap/app.php` alongside existing boundary middlewares. Intercepts `/api/mobile/v2` and `/api/mobile/v2/*` early. All other routes pass untouched.
3. **Fail-Closed in Production**: No financial or business routes are registered under V2 in production. Any recognized or unknown V2 paths fail-closed with HTTP 404 `NOT_FOUND` in schema-compliant JSON.
4. **Test-Only Harness**: Authorization, boundary defenses, and identity qualification are verified using test-only routes registered exclusively in the testing environment (`api/mobile/v2/test-harness/*`), never present in production.

## 2. Boundary Defenses

- **TLS Enforcement**: Non-secure requests outside local and testing environments return HTTP 403 `FORBIDDEN`.
- **Accept Header**: Requests specifying an Accept header that excludes `application/json` or `*/*` return HTTP 406 `NOT_ACCEPTABLE`.
- **GET Body Rejection**: GET requests with a non-empty payload body return HTTP 400 `BAD_REQUEST`.
- **Safe Query Bounds**: Query strings exceeding 2,048 bytes or query parameters containing null bytes or unprintable control characters return HTTP 400 `BAD_REQUEST`.
- **Payload Bound (1 MiB Contract)**: Payloads and Content-Length exceeding 1,048,576 bytes (`ContractConstants::MAX_PAYLOAD_BYTES`) return HTTP 413 `PAYLOAD_TOO_LARGE`.
- **Content-Type**: Mutating requests (`POST`, `PUT`, `PATCH`, `DELETE`) without `application/json` Content-Type return HTTP 415 `UNSUPPORTED_MEDIA_TYPE`.
- **Strict JSON Parsing**: Mutating payloads are validated using `StrictJsonParser`. Duplicate keys, IEEE 754 floats (decimals must be string-encoded), unpaired Unicode surrogates, leading zeros, negative zero (`-0`), numbers outside safe integer bounds, or nesting depths exceeding 32 return HTTP 422 `INVALID_JSON`.
- **Rate Limiting**: Overall IP rate limiting (`mobile_v2:ip_overall:{ip}`, 120/min) and authenticated actor+company rate limiting (`mobile_v2:actor:{user}:company:{company}`, 120/min) return HTTP 429 `TOO_MANY_REQUESTS` with preserved `Retry-After` header. No global cache flushing occurs.
- **Exception Sanitization**: Uncaught internal exceptions return sanitized HTTP 500 `INTERNAL_SERVER_ERROR` without leaking SQL, table names, stack traces, or credentials. Validation error details mask credential fields (`password`, `token`, etc.).

## 3. Identity Qualification & Tenant Scoping

- **Sessionless Authentication**: Web session cookies, synthetic browser proofs, and support impersonation headers are ignored; requests require an explicit `Authorization: Bearer <token>` header, returning HTTP 401 `UNAUTHENTICATED` if absent, malformed, expired, revoked, or bound to a revoked device or mismatched user.
- **Password Revision Epoch**: When a user's password changes, token password epoch validation fails, revokes the token in the database, and returns HTTP 401 `PASSWORD_REVISED`.
- **Route vs Payload Disagreement**: If a route declares `{companyId}` and the request payload specifies `company_id` / `companyId`, any mismatch is rejected immediately with HTTP 422 `VALIDATION_FAILED` before performing any database lookups.
- **Tenant Membership without Existence Oracle**: Routes requiring company context resolve the company and verify active membership in `company_user` (`User` has no active column; membership is authoritative). Missing companies, inactive companies, and companies where the actor lacks active membership all return identical HTTP 403 `FORBIDDEN_TENANT_MISMATCH` errors, preventing existence oracles.
- **Actual Permission Checking**: Permission checks verify real Spatie permissions (e.g. `hasPermissionTo`) or policy methods (`ProductPolicy::viewAny`). Role names (`Administrator`, `Cashier`) never grant an automatic bypass or exemption.
- **Context Isolation**: `CompanyContext` and Spatie permission teams are cleared prior to request handling and restored in `finally` blocks, even upon exception. Non-company endpoints never fall back to an ambient default company. No background jobs are dispatched or left in queue state.

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
