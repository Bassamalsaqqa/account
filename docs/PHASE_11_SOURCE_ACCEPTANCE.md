# Phase 11 source acceptance packet

Status: Codex implementation and independent worker review complete; independent architect acceptance **PENDING**. API exposure **DISABLED**. Phase 11 merge/deployment **NOT PERFORMED**. Phase 12 **NOT STARTED**.

## Source and scope

Base/main/deployed identity: `3a77afef5c79dc947d306845a725241989acd33a`. Branch: `phase/11-api-command-foundation`. The submitted commit and PR head identify this packet's candidate; no published history is rewritten. PRs #21 and #22 were already merged into this base. The production deployment record is separate from this source candidate.

The controlling protected roadmap is [section 9.3](ACCOUNTING_PHASE_9_FULL_ENGINEERING_ROADMAP_AND_FUTURE_AI_MOBILE_V1.md), read alongside the Master Specification, Engineering Blueprint and accepted ADRs. This candidate implements the Sales-first command foundation. Existing Purchasing and other business workflows remain on their canonical services. It introduces neither a second accounting backend nor a new financial/stock writer. Native mobile clients, bearer-token issuance and AI functionality are outside this candidate. [ADR 0011](adr/0011-versioned-command-api-foundation.md) records the reviewed cookie-session choice and its limits.

AGY supplied bounded initial implementation in isolated worktrees. Codex independently inspected and corrected source, added real HTTP parity coverage, verified browser behavior and completed integration after AGY quota exhaustion. Worker reports are not treated as acceptance evidence.

## Requirement-to-evidence mapping

| Roadmap 9.3 requirement | Implementation boundary | Executed evidence / disposition |
| --- | --- | --- |
| Shared Livewire / API business commands | SalesDraftIntent and SalesInvoiceCommands dispatch existing create/update/post actions | SalesCommandWorkflowTest, ApiCommandParityTest, WebCommandParityTest; PASS locally |
| Strict exact-value intent | Declared headers/lines only; decimal strings with canonical six-place quantity/money and ten-place FX normalization; existing value-object bounds | Float/integer/exponent, unknown field, excessive scale/size/lines and invalid canonical-value refusals; PASS |
| Draft, preview, explicit confirm, execute | Read-only preview; encrypted five-minute company/actor/target/revision token; API confirmed=true; separate visible web confirmation | Stale/edited/tampered/expired token and changed identity/pricing/unit/tax/stock context refusal; no pre-confirmation economic writes; PASS |
| One canonical financial/stock event | Existing PostSalesInvoiceAction, canonical posting/inventory services; receipt in same outer transaction | Late receipt failure after witnessed posting rolls back ledger, movements, stock caches, lot allocations, numbering and lifecycle; six reconciliation checks; PASS |
| Tenant and RBAC parity | Active company context, company-first locks, fresh SalesActorGuard checks before both receipt replay and new operations | Foreign customer/document, default role permissions, mid-session downgrade and revoked membership; PASS |
| Current revocable authentication | Existing Fortify login/MFA, database cookie sessions, CSRF, idle timeout and hard maximum age | Actual database-cookie/CSRF requests; deleted session/password change after middleware proof refused before command effects; PASS |
| Safe device management | Fresh nonfuture password confirmation; owned session rows; actor-bound opaque handles | Own/foreign handle, two-device revocation, current logout, stale/future confirmation refusal; PASS |
| TLS, strict JSON, trace IDs, limits | Global API boundary before session bootstrap, API-scoped fixed problem responses | Disabled 404 with zero DB queries, malformed/oversized JSON, body field injection, TLS/rate limit and exception leakage cases; PASS |
| Idempotency and concurrent requests | Unique company/user/action/key receipts; normalized economic hashes; target binding; deleted-draft tombstone | Seven independently forked MariaDB concurrency/rollback cases, including same/conflicting payloads, execute retries and fresh permission checks; PASS |
| Currency, discount, stock and expiry | Canonical Sales rules, exact FX/tax/discount arithmetic, advisory aggregated stock/FEFO preview | ILS plus USD/JOD parity, permission-sensitive price/discount checks, insufficient/expired stock and changed context refusal; PASS |
| Cost/profit suppression | Explicit API projections and stock preview without costs | Projection/redaction and tenant tests; no raw model serialization; PASS |
| Browser and simulated client compatibility | Versioned v1 endpoints and the same commands in InvoiceForm/InvoiceDetail | Actual HTTP cookie client tests and AR/EN browser preview/edit/stale-confirm/post journey; PASS; native app NOT IMPLEMENTED |
| Future provider independence | Commands have no AI dependency; API default off | Source inspection; Phase 12 and real provider activation remain separate gates |

## Version 1 client contract

All routes start with /api/v1, require same-origin authenticated cookie sessions, and retain existing Fortify login/2FA/password-confirmation endpoints. Cookie-bound mutations require CSRF. No bearer tokens are issued here. API access requires a fresh credential login marker, database session storage on the canonical connection, TLS and accepted rollout configuration. A legitimate fresh login may use the remember checkbox; later automatic remember-cookie recall does not establish an API session.

| Method and suffix | Result / authority |
| --- | --- |
| GET /session | Current authenticated actor/company and session lifetime metadata |
| GET /sessions | Owned device session handles, current flag, last activity and user agent; fresh password confirmation |
| DELETE /sessions/{opaqueHandle} | Revoke owned device; fresh password confirmation; current-device revocation logs out |
| POST /companies/{companyId}/sales/invoices/draft | Canonical draft; Idempotency-Key required; 201 on authorized creation/replay |
| PUT /companies/{companyId}/sales/invoices/{publicId} | Canonical draft update; Idempotency-Key required |
| GET /companies/{companyId}/sales/invoices/{publicId} | Authorized explicit cost-redacted resource |
| POST /companies/{companyId}/sales/invoices/{publicId}/preview | Read-only exact preview and bounded confirmation token; view and post authority |
| POST /companies/{companyId}/sales/invoices/{publicId}/execute | confirmed=true and confirmation_token; Idempotency-Key; current view/post authority |
| GET /companies/{companyId}/lookups/{customers,products,warehouses,currencies,taxes} | Scoped permitted lookup; maximum 50 rows; product prices labeled suggestions |

The route companyId must match the explicitly session-selected company numeric ID or public ID; invoice publicId is the existing opaque document identity. Intent IDs are positive integers resolved within the same company. Intent fields are customer_id, warehouse_id, currency_code, exchange_rate, issue_date, due_date, notes, terms, document_locale and lines. Line fields are product_id, product_unit_id, item_description, quantity, unit_price, discount_type, discount_value and tax_rate_id. The existing canonical percent/fixed discount policy remains authoritative.

Body maximum is 65,536 bytes; at most 100 lines; notes/terms at most 2,000 characters; item descriptions at most 255. Economic values are JSON strings. Request keys are 1-128 ASCII letters/digits/underscore/hyphen/dot/colon, scoped by company/actor/action. Equivalent normalized draft payloads converge; changed economics or target conflict. Execute binds the supplied reviewed token; an exact already-committed retry may replay after token expiry, but never bypasses current authorization. Deleted drafts retain receipt tombstones rather than being recreated. Replay returns the current authorized canonical resource, not an immutable response cache.

Success returns an explicit JSON resource or preview. Errors use application/problem+json with type, title, status, detail and trace_id, plus X-Trace-Id and no-store headers. Expected refusal statuses include 401 authentication, 403 authorization, 404 disabled/foreign/missing resource, 405 unsupported method, 406 unacceptable response type, 409 conflicting key/stale or blocked execution, 413 oversized body, 415 unsupported media type, 419 CSRF, 422 validation, 423 password confirmation, 429 throttle and 503 unsupported session capability. Fixed error text and exception-class-only API logging avoid SQL, paths, passwords and tokens. The disabled gate returns 404 before any session/database work.

## Executed verification

Environment: PHP 8.4.25, PHPUnit 12.5.36, local loopback MariaDB **10.4.32**, existing locked dependencies. Production's previously recorded MariaDB **11.8.9** was not queried or load-tested in this work. Version parity is a limitation; MariaDB, rather than SQLite, was used for integration, locking, decimals, foreign keys and migrations. Every database run used the existing owned-schema guards, nonce proof and finally cleanup. Fake/local mail only. The composer qa formatter/static/test gates were executed separately, with PHPUnit routed through the owned-schema runner.

| Check | Actual result |
| --- | --- |
| Final tests/Feature/Phase11 | **PASS: 75 tests, 733 assertions, 0 errors/failures/skips**, 03:18.177, 88 MiB |
| ApiCommandParityTest | 8 tests / 121 assertions |
| ApiSecurityTest | 17 / 110 |
| CommandConcurrencyTest | 7 / 283 |
| CommandMigrationPreservationTest | 1 / 20 |
| SalesCommandWorkflowTest | 29 / 109 |
| WebCommandParityTest | 13 / 90 |
| Focused final accessibility + HTTP + web regression | **PASS: 25 tests / 1,407 assertions**, 29.713s, 86 MiB; overlaps Phase 11 coverage, not added to its total |
| Full Pint --test | **PASS** repository-wide |
| PHPStan | **PASS: no errors** |
| Cached route compatibility | **PASS: 13 API routes** after route:cache, private local cache paths only |
| Production frontend build | **PASS: 58 modules**, 1.38s; CSS 81.12 kB / JS 51.52 kB; generated assets kept ignored |
| Broad guarded regression continuation | **PASS: 1,454 tests / 23,419 assertions, 0 errors/failures/skips**, 29:52.563, 204 MiB |

The first guarded broad run reached the runner's 900-second timeout after 732 summarized cases and 58 additional progress marks. Two failures were the AR/EN combined-validation presentation cases: zero quantity stopped later accessible field errors. Codex added the positive exact-decimal quantity rule to the existing form validation, then reran those cases with adjacent HTTP/web coverage successfully (25 / 1,407 above). The interrupted run is not reported as a passing full suite. To avoid repeating completed suites, a private exact copy of the guarded runner changed only autoload/root resolution and its timeout to 5,400 seconds, resuming at the beginning of the last unfinished class. Its ownership/isolation/cleanup protections were unchanged. Overlap is deliberate; no artificial aggregate assertion count is claimed. Private logs/JUnit remain outside Git.

Actual parity tests verify six domains using the five accounting:reconcile, inventory:reconcile, sales:reconcile, money:reconcile and phase7:reconcile commands, plus PayablesReconciliationService. Exact USD total 104.400000 / base 365.400000 and JOD total 104.400000 / base 521.916480 match their previews and posted sources. Integration fixtures are owned synthetic local records, not production customers or transactions.

## Browser evidence

On a private loopback server and a newly owned disposable schema, real Fortify login and actual Livewire requests verified EN/LTR and AR/RTL at **390, 768 and 1440 px**. All six preview checks showed correct direction, visible exact totals, focus on the review panel and no horizontal page overflow. Cancel returned focus to the initiating detail button. Editing the draft invalidated its earlier confirmation; a fresh review and explicit confirmation posted synthetic invoice INV-2026-0001 for **140.00 ILS**.

Private artifacts: browser-checks.json, preview-en-390.png, preview-en-768.png, preview-en-1440.png, preview-ar-390.png, preview-ar-768.png, preview-ar-1440.png and browser-posted-ar.png. The log contains **two asynchronous browser message-channel diagnostics**, without an attributable application source; they are retained as an unclassified browser limitation rather than claimed absent or a confirmed application defect. No Alpine preview-lifecycle errors occurred. These focused checks do not establish a new whole-product WCAG certification.

The browser tab/server were closed and its owned schema cleaned. Database tests cleaned their owned schemas. A final read-only information_schema check found zero remaining disposable schemas on the owned loopback server; the identity-verified server was then stopped gracefully, with its synthetic datadir retained privately. Older quarantined Phase 10 schemas/worktrees were preserved, not silently adopted or dropped.

## Architecture, data preservation and rollback

One new forward migration adds command_receipts with tenant/actor identity, unique action/key and nullable document reference. No existing migration is edited; no business row is rewritten. Migration round-trip tests preserve populated existing business data and verify receipt foreign-key behavior. Canonical accounting/stock writers, dependencies, lockfiles and deployment scripts are unchanged.

For a separately accepted release, preserve current production configuration/data and the pre-release checkpoint. Keep COMMAND_API_ENABLED=false until its explicit exposure gate passes. A code rollback to the accepted predecessor can retain the additive receipt table. **Dropping receipt metadata is not the production rollback plan**: it would erase retry identities. No migration rollback, deployment or production mutation was performed in this source task.

All 14 inventoried protected Owner/private artifacts retained their original SHA-256. The protected Phase 10 Owner specification retained SHA-256 285f41301b41d7895e1624268289676aab63d75120e67bf7dba74a2640d6e347. Originals, private PDFs, ignored evidence and unrelated worktrees were not staged, moved or rewritten. Only intended Phase 11 source/tests/docs are published. Deliberate ignored artifacts retained locally are .ai/delegations/phase11-20261011 (synthetic database datadir, fake test configuration, private guarded-runner copy, logs/JUnit, browser images, checksum/cleanup metadata and PR-body preparation), the isolated Phase 11 worktrees, and integration vendor/, node_modules/, public/build/ and generated local Laravel caches. None is part of the PR. No credentials, backup archives, SQL dumps, real reset tokens or private test configuration are included.

## Outstanding gates

| Gate | Truthful state / minimum next action |
| --- | --- |
| Independent Phase 11 source acceptance | **PENDING** architect review of this commit, ADR and executed evidence |
| Phase 11 merge/deployment | **NOT PERFORMED**; accepted source and authorized release required |
| API production exposure | **DISABLED**; explicit accepted authentication/security/rollout gate required |
| Production database-version verification | **NOT RUN** in this source work; separately controlled acceptance/release verification |
| Phase 12 implementation | **NOT STARTED** until Phase 11 source acceptance |
| Real AI provider activation | **NOT AUTHORIZED**; provider/cost/privacy approval required |
| P10 isolated real-data restoration | **NOT VERIFIED / BLOCKED EXTERNAL AUTHORIZATION** |
| P10 independent off-host backup and key recovery | **NOT VERIFIED / BLOCKED EXTERNAL AUTHORIZATION** |
| P10 actual approved password-reset email delivery | **NOT VERIFIED / BLOCKED EXTERNAL AUTHORIZATION** |

Production remains at the accepted 3a77afe baseline for Owner testing. Phase 11 source evidence does not establish customer readiness or resolve the three P1 operational gates. No GitHub Actions/CI, merge, new deployment, production access or production fixtures were used for this candidate.

## Exact changed-file inventory

The candidate changes exactly these 36 files (seven existing files and 29 additions):

```text
.env.example
app/Application/Commands/SalesDraftIntent.php
app/Application/Commands/SalesInvoiceCommands.php
app/Application/Commands/StockAvailabilityQuery.php
app/Http/Controllers/Api/V1/ApiController.php
app/Http/Controllers/Api/V1/LookupController.php
app/Http/Controllers/Api/V1/SalesInvoiceApiController.php
app/Http/Controllers/Api/V1/SessionApiController.php
app/Http/Middleware/Phase11ApiBoundary.php
app/Http/Middleware/Phase11ApiGuard.php
app/Livewire/Pages/Sales/InvoiceDetail.php
app/Livewire/Pages/Sales/InvoiceForm.php
app/Models/CommandReceipt.php
app/Providers/Phase11ServiceProvider.php
app/Services/Security/CommandSessionGuard.php
bootstrap/app.php
bootstrap/providers.php
config/commands.php
database/migrations/2026_10_11_100000_create_command_receipts_table.php
docs/PHASE_11_IMPLEMENTATION_LEDGER.md
docs/PHASE_11_SOURCE_ACCEPTANCE.md
docs/adr/0011-versioned-command-api-foundation.md
resources/lang/ar/commands.php
resources/lang/en/commands.php
resources/views/components/sales-command-preview.blade.php
resources/views/livewire/pages/sales/invoice-detail.blade.php
resources/views/livewire/pages/sales/invoice-form.blade.php
routes/api-v1.php
tests/Feature/Phase11/ApiCommandParityTest.php
tests/Feature/Phase11/ApiSecurityTest.php
tests/Feature/Phase11/CommandConcurrencyTest.php
tests/Feature/Phase11/CommandMigrationPreservationTest.php
tests/Feature/Phase11/SalesCommandWorkflowTest.php
tests/Feature/Phase11/WebCommandParityTest.php
tests/Support/Phase11/command-race-worker.php
tests/Support/Phase11CsrfTestMiddleware.php
```
