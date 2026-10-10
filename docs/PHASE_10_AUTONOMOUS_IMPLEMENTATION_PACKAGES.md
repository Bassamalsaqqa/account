# ACCOUNTING — PHASE 10 PRODUCTION HARDENING
## Autonomous Implementation Package Specifications & Delegation Briefs

**Document Identity:** `docs/PHASE_10_AUTONOMOUS_IMPLEMENTATION_PACKAGES.md`\
**Package Status:** **PREPARED, NOT DISPATCHED FOR IMPLEMENTATION**\
**Execution Gate:** Implementation is strictly blocked pending explicit Product Owner authorization.\
**Discovery Baseline Anchor:** Planning was prepared on branch `docs/phase9-production-acceptance` (`47bd392ff4e2adb268805bfd7339805243c677c1`). Upon implementation authorization, fetch and verify `origin/main` anew, record its exact SHA/tree, and rebind every package to that baseline in an isolated linked worktree. `8d8428261cd2a10690ab77a5c7271e46b5ff5217` is the observed planning anchor; any advance requires explicit reconciliation before dispatch.\
**Branch Notice:** Branch `phase/10-production-hardening` has **not** been created during this planning run.

---

## 1. Governance, Authority & Autonomous Execution Architecture

### 1.1 Multi-Agent Role Model
- **Product Owner:** Authorizes implementation kickoff, merges to `main`, and authorizes production deployment. Owns business risk acceptance.
- **ChatGPT (Independent Architect):** Reviews scope, security/accounting architecture, significant blockers, integrated pull requests, and source acceptance.
- **Codex (Lead Engineer / Integration):** Owns baseline reconciliation, package dispatch, worker code review, lead-owned core files, final QA, and PR preparation.
- **AGY (Autonomous Worker):** Executes assigned bounded work packages in isolated linked worktrees. Delivers exact evidence and test passes. Has **zero** authority to merge, push, deploy, or modify production.

### 1.2 Anti-Looping & Process Discipline Rules
1. **No Automated Worker Loops:** Workers must not spawn recursive agents, trigger cascading subagent cycles, or rerun broad test suites (>50 tests) for localized changes.
2. **Serial Integration:** Packages are integrated serially by Codex. Overlapping workers on shared central files are strictly prohibited.
3. **Execution Budgets (Time & Scenarios, Never Artificial Assertion Caps):** Each package has a strict budget expressed in wall-clock time (e.g. ≤ 15 minutes) and finite scenario test runs. Assertions must be complete and meaningful (never capped artificially to force a green status). If a test budget is exhausted, the worker must halt and report findings rather than disable assertions or weaken invariants.
4. **No Unapproved Test Tools:** No external testing libraries or unconfigured browser harnesses (e.g. Laravel Dusk) may be installed or fabricated. Tests run via PHPUnit using `tests/Support/run-phase8-disposable.php`.
5. **Lead-Owned Central Invariants:** Workers are strictly prohibited from modifying:
   - `app/Services/Posting/AccountingPostingService.php` (canonical ledger writer)
   - Canonical stock writers under `app/Services/Inventory/InventoryMovementService.php` and `InventoryRebuildService.php`
   - `app/Services/Tenancy/CompanyRoleService.php` (RBAC catalog)
   - `database/migrations/` (forward-only migrations owned by Lead)
   - `app/Services/Sales/PublicShareService.php` and public token generation

---

### 1.3 Mandatory envelope for every future dispatch

These are prepared package templates. Before dispatch, Codex writes the exact parent SHA/tree, worktree branch/path, selected route family, literal allowed files, fixture ownership, output paths, checkpoint budget and commands into the worker brief. The worker verifies branch/HEAD and stops on unexpected drift. Generic allowed directories below are candidate pools; they do not permit concurrent repository-wide edits.

- Implementation authorization and P10-0 acceptance are prerequisites. No package is authorization by itself.
- No worker staging, commit, push, merge, history rewrite, reset, deployment, production access, real messaging, persistent database reset, Git metadata edits, other-worktree changes or recursive agent spawning.
- Common fixture contract: two independent synthetic Companies with factory-assigned IDs; Owner, Administrator, Manager, Sales, Purchasing, Warehouse, Cashier, Viewer, one approved custom role and a foreign-company actor. Choose permitted/restricted roles per package; preserve protected grants and keep all fixture rows owned by the isolated test. Public guests hold no authority except a deliberately issued fixture grant.
- All database tests and browser write workflows use owned local disposable MariaDB and normal auth/CSRF/Livewire. Never weaken a test to fit a budget. Stop the checkpoint and return evidence if incomplete.
- Lead owns business-event Actions, canonical ledger/stock writers and reversal scopes, tenant/RBAC policy, issued-financial content/grant policy, `CatalogService` publication/token mutation and every migration. Worker fixes to these paths require a newly bounded correction reviewed by Codex; material canonical or data-policy changes require architect review.
- Source read access does not authorize production operations. Host/SMTP/provider inspection and actual backup/key access or restore must use a separately scoped approved workflow; no stress or fake records on production.
- Package gates use the owned-schema runner and relevant existing regressions plus new behavioral checks. UI/asset changes additionally run `npm run build`; substantial PHP changes run `composer pint -- --test` and `composer phpstan`. P10-F runs one justified guarded broad pass, with the test component through the owned runner (`php tests/Support/run-phase8-disposable.php -- tests`). Bare `composer qa` uses default database settings and is never run against persistent data; use equivalent components under the owned environment.
- Report exact SHA/tree, paths changed, commands and real counts, failures/NOT RUN, screenshots/actual PDFs when applicable, cleanup and deviations. Codex independently reviews and reruns relevant critical checks before acceptance.

### 1.4 Architecture and roadmap boundaries

Keep Laravel/Livewire/Tailwind and the single accounting backend. All business-event effects remain inseparable through canonical Actions, `AccountingPostingService` and inventory services; transaction wrappers alone are not proof against partial-commit bypasses. No floats for economic values, direct financial/stock history writes, observer/template side effects, alternate ledger, mutable derived authority or new frontend framework. Preserve tenant authorization, server-side redaction, payload-aware idempotency, posting-time identity snapshots, economic snapshot policy and immutable history.

**Phase 11:** authenticated, tenant-scoped, versioned command/API work is deferred; no production API during Phase 10. **Phase 12:** AI text/voice proposals, provider integration and confirmation flows are deferred; no model SQL or automatic posting. **Phase 13:** PWA/native/offline/mobile functionality is deferred; future work must reuse the authoritative backend. Phase 10 may document compatibility decisions without implementing those capabilities.

All Product Owner root documents, both roadmap copies, the original Phase 10 proposal, supplied PDFs and existing `.playwright-mcp/` artifacts are protected. Workers cannot delete, rename, overwrite or stage these, nor ignored `.ai/` evidence. Lead verifies hashes before and after each work package and stages only literal reviewed paths. New public documents must contain sanitized summaries rather than credentials, keys, tokens, sessions or complete business records.

## 2. Autonomous Package Specifications

```
+-----------------------------------------------------------------------------+
| PACKAGE P10-A1: Identity, Sessions, and Account Recovery                    |
+-----------------------------------------------------------------------------+
| STATUS:       PREPARED, NOT DISPATCHED FOR IMPLEMENTATION                   |
| OWNER/ROLE:   Worker (AGY) under Lead (Codex) Supervision                   |
| DEPENDENCY:   P10-0 Baseline Accepted                                       |
| BUDGET:       ≤ 15 minutes; ≤ 10 test suite runs                            |
+-----------------------------------------------------------------------------+
```
- **Scope & Objectives:**
  - Audit Fortify login, logout, password reset, 2FA enabling/recovery, and throttling.
  - Verify session rotation on login and privilege change; verify session invalidation on logout.
  - Verify CSRF protection on sensitive state switches (`company/switch`, `locale`).
  - Propose safe SMTP/provider configuration; exercise password-reset flows with a local fake transport. Separately authorized delivery proof uses only an Owner-approved recipient/environment, records delivery and single-use/expiry behavior, and never creates a production Customer or sends unexpected mail. Missing proof remains a P1 readiness blocker; a proposed alternative requires demonstrated recovery and explicit Owner risk disposition.
- **Allowed Paths:**
  - `app/Providers/FortifyServiceProvider.php`
  - `app/Actions/Fortify/ResetUserPassword.php`, `app/Actions/Fortify/UpdateUserPassword.php`
  - `resources/views/livewire/pages/auth/`, `resources/views/auth/two-factor-challenge.blade.php`
  - `tests/Feature/Auth/`
- **Prohibited Paths:**
  - `app/Services/Posting/*`
  - `app/Services/Inventory/*`
  - `app/Services/Tenancy/CompanyRoleService.php`
  - `database/migrations/*`
  - Production `.env` or live Hostinger mailers
- **Accepted Interfaces & Contracts:**
  - Fortify standard contract; `CompanyContext` tenancy switcher; Laravel session store.
- **Two-Company Test Fixtures:**
  - Company A (factory-assigned ID, Owner User A, Cashier User B)
  - Company B (distinct factory-assigned ID, Owner User C, Viewer User D)
- **Critical Negative Cases:**
  1. User B logs out; back button / cached session cannot execute authenticated requests.
  2. User A switches from Company A to Company B without membership; request aborts 403.
  3. Brute force password attempts exceed 5 failures; account throttling engages (HTTP 429).
  4. Password reset token used twice or expired token rejected.
- **Deliverables:**
  - `tests/Feature/Auth/SessionHardeningTest.php` *(NEW PROPOSED)*
  - Bounded report documenting session lifecycle and cookie security flags (`Secure`, `HttpOnly`, `SameSite=Lax`).
- **Focused Gate Command (Existing Test):**
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Auth/AuthenticationTest.php`

---

```
+-----------------------------------------------------------------------------+
| PACKAGE P10-A2: Tenant Isolation, Permissions & Authorization Hardening    |
+-----------------------------------------------------------------------------+
| STATUS:       PREPARED, NOT DISPATCHED FOR IMPLEMENTATION                   |
| OWNER/ROLE:   Lead (Codex) Architecture + Worker (AGY) Route Scans          |
| DEPENDENCY:   P10-0 Baseline Accepted                                       |
| BUDGET:       ≤ 25 minutes; route-family bounded lanes                       |
+-----------------------------------------------------------------------------+
```
- **Scope & Objectives:**
  - Audit every `publicId` route and Livewire action across specific route families (Lane 1: Sales/Quotes/Invoices; Lane 2: Purchasing/Vendors/Payments; Lane 3: Money/Checks; Lane 4: Expenses/Payroll) for fresh server-side Company scoping and permission checks.
  - Verify server-side redaction of sensitive financial data (costs, profit, payroll) at serialized DTO/HTML/JSON boundary.
  - Verify that private Purchasing/Vendor documents (`pdf/purchase/{publicId}`, `pdf/purchase-return/{publicId}`, `pdf/vendor-payment/{publicId}`, `pdf/vendor-statement/{publicId}`) cannot be accessed via public share tokens or by unauthorized roles.
- **Allowed Paths (Bounded per Lane):**
  - Lane 1: `app/Livewire/Pages/Sales/*`, `app/Http/Controllers/PdfDocumentController.php`
  - Lane 2: `app/Livewire/Pages/Purchasing/*`, `app/Http/Controllers/PurchasingDocumentController.php`
  - Lane 3: `app/Livewire/Pages/Money/*`
  - Lane 4: `app/Livewire/Pages/Expenses/*`, `app/Livewire/Pages/Payroll/*`
- **Prohibited Paths:**
  - `app/Services/Posting/AccountingPostingService.php`
  - `app/Services/Inventory/InventoryMovementService.php`
  - `app/Services/Tenancy/CompanyRoleService.php` (Lead-owned)
  - `database/migrations/*`
- **Accepted Interfaces & Contracts:**
  - `SalesActorGuard::lockAndAuthorize()`, `CompanyScope`, `VendorFinancialRead`.
- **Two-Company Test Fixtures:**
  - Company A: Owner, Manager, Cashier, Viewer
  - Company B: Owner, Warehouse, Foreign Member
- **Critical Negative Cases:**
  1. Cashier in Company A requests `pdf/purchase/{publicId}` of Company B -> HTTP 404/403.
  2. Viewer in Company A requests Vendor Payment with hidden acquisition cost -> cost redacted from response JSON/HTML.
  3. Mid-session role downgrade: Manager downgraded to Viewer mid-session -> subsequent POST action fails 403 without partial execution.
  4. Tampering with Livewire component public properties to inject Company B ID -> rejected by `CompanyScope`.
- **Deliverables:**
  - `tests/Feature/Phase10/TenantIsolationSecurityTest.php` *(NEW PROPOSED)*
  - Route audit matrix verifying server denial across all 8 roles.
- **Focused Gate Command (Existing Test):**
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase2/Phase2CompanyIsolationTest.php`

---

```
+-----------------------------------------------------------------------------+
| PACKAGE P10-A3: Public Shares, Catalogs & Web Perimeter Security            |
+-----------------------------------------------------------------------------+
| STATUS:       PREPARED, NOT DISPATCHED FOR IMPLEMENTATION                   |
| OWNER/ROLE:   Worker (AGY) under Lead (Codex) Supervision                   |
| DEPENDENCY:   P10-0 Baseline Accepted                                       |
| BUDGET:       ≤ 15 minutes; ≤ 10 test suite runs                            |
+-----------------------------------------------------------------------------+
```
- **Scope & Objectives:**
  - Validate neutral first-view GET/HEAD and CSRF-protected POST on `share/{token}`.
  - Verify 15-minute exact grant-bound session unlock, password validation, and expiry handling.
  - Confirm Catalog prices default OFF across all public projections (HTML, JSON, print, PDF).
  - Verify terminal Revoke permanently retires bearer tokens; Pause is reversible; New Link issues a fresh 40-character token.
  - Preserve and verify the existing public-marketing-media retention disclosure: published image URLs and cached/downloaded copies may remain accessible after managed-link revocation. Improve wording only if measured usability evidence warrants it.
- **Allowed Paths:**
  - `app/Http/Controllers/PublicShareController.php`
  - `app/Http/Controllers/PublicCatalogController.php`
  - `app/Services/Catalogs/CatalogAccess.php`
  - `app/Services/Catalogs/ApprovedCatalogMedia.php`
  - `resources/views/sales/public-share.blade.php`, `resources/views/catalogs/public.blade.php`
- **Prohibited Paths:**
  - `app/Services/Posting/*`
  - `app/Services/Sales/PublicShareService.php` (Lead-owned issuance writer)
  - `database/migrations/*`
- **Accepted Interfaces & Contracts:**
  - `FinancialSharePolicy`, `CatalogRenderer`, `ApprovedCatalogMedia`.
- **Critical Negative Cases:**
  1. Public request to `share/{token}` without password verification returns neutral prompt without statement content.
  2. Expired or revoked share/catalog token returns HTTP 404.
  3. Catalog published with prices disabled returns zero price data even if requested via JSON/print/PDF formats.
- **Deliverables:**
  - Regression report on share/catalog security controls.
- **Focused Gate Commands (Existing Tests):**
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase9/FinancialSharesTest.php`
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase9/PublicCatalogTest.php`

---

```
+-----------------------------------------------------------------------------+
| PACKAGE P10-A4: Uploads, CSV Exports & Data Surface Security                |
+-----------------------------------------------------------------------------+
| STATUS:       PREPARED, NOT DISPATCHED FOR IMPLEMENTATION                   |
| OWNER/ROLE:   Worker (AGY) under Lead (Codex) Supervision                   |
| DEPENDENCY:   P10-0 Baseline Accepted                                       |
| BUDGET:       ≤ 15 minutes; ≤ 8 test suite runs                             |
+-----------------------------------------------------------------------------+
```
- **Scope & Objectives:**
  - Verify private expense attachment upload security: MIME validation, file size limits, safe random filename generation, storage outside public web root.
  - Verify `app/Application/Reporting/Support/CsvCellFormatter.php` and `CsvReportWriter.php` formula prefixing (`=+\-@`) and numeric string preservation.
  - Ensure CSV download headers include safe `Content-Disposition`, UTF-8 BOM, and `X-Content-Type-Options: nosniff`.
- **Allowed Paths:**
  - `app/Http/Controllers/ExpenseAttachmentController.php`
  - `app/Application/Reporting/Support/CsvReportWriter.php`
  - `app/Application/Reporting/Support/CsvCellFormatter.php`
  - `tests/Feature/Phase10/UploadSecurityTest.php` *(NEW PROPOSED)*
- **Prohibited Paths:**
  - Modifying canonical reporting queries or ledger data models.
- **Accepted Interfaces & Contracts:**
  - `CsvCellFormatter::text()`, `CsvCellFormatter::decimal()`, `CsvReportWriter::write()`.
- **Critical Negative Cases:**
  1. Uploading file with disguised `.php` extension or SVG containing JavaScript fails with validation error.
  2. Downloading report with cell text `=SUM(A1:A10)` produces CSV line with leading apostrophe `"'=SUM(A1:A10)"`.
  3. Negative decimal numbers (e.g. `-150.000000`) exported as exact numeric strings without formula corruption.
- **Deliverables:**
  - `tests/Feature/Phase10/UploadSecurityTest.php` *(NEW PROPOSED)*
- **Focused Gate Command (Existing Test):**
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase7/ExpenseTest.php`

---

```
+-----------------------------------------------------------------------------+
| PACKAGE P10-A5: Dependencies, Configuration & Secrecy Audit                 |
+-----------------------------------------------------------------------------+
| STATUS:       PREPARED, NOT DISPATCHED FOR IMPLEMENTATION                   |
| OWNER/ROLE:   Lead (Codex) Owned Lane                                       |
| DEPENDENCY:   P10-0 Baseline Accepted                                       |
| BUDGET:       ≤ 15 minutes static audit                                     |
+-----------------------------------------------------------------------------+
```
- **Scope & Objectives:**
  - Audit Composer and npm dependencies (`composer audit`, `npm audit`).
  - Verify production configuration hardening: `APP_DEBUG=false`, `APP_KEY` retention, session cookie flags.
  - Verify that no application logs or error pages leak database credentials, escrow keys, or statement ciphertext.
- **Allowed Paths:**
  - Read-only inspection of `composer.lock`, `package-lock.json`, `config/`.
- **Prohibited Paths:**
  - Arbitrary dependency upgrades or modifying production secrets.
- **Critical Negative Cases:**
  1. In an isolated local fixture, a controlled exception returns a generic error view without stack trace, database queries or secrets. Production error handling is inspected read-only; do not induce production failures.
- **Deliverables:**
  - Dependency audit and configuration review summary.

---

```
+-----------------------------------------------------------------------------+
| PACKAGE P10-B1: Canonical Financial, Inventory & Scenario Hardening        |
+-----------------------------------------------------------------------------+
| STATUS:       PREPARED, NOT DISPATCHED FOR IMPLEMENTATION                   |
| OWNER/ROLE:   Lead (Codex) Direction + Worker (AGY) Scenario Fixtures       |
| DEPENDENCY:   P10-0 Baseline; P10-A2 Authorization Rules Locked             |
| BUDGET:       ≤ 30 minutes per journey-group checkpoint; finite J01–J20 matrix     |
+-----------------------------------------------------------------------------+
```
- **Scope & Objectives:**
  - Validate the 20 Golden Business Journeys (J01–J20) under multi-currency (ILS, USD, JOD), tax/discount, Unit conversion ratios (piece/carton), and dual-role workflows.
  - Verify that posted documents are strictly immutable; edits to Product or Vendor master data do not alter historical transaction lines.
  - Inject mid-post failures and verify 100% atomic rollback across document, stock, ledger, and sequence numbers.
  - Verify payload-aware idempotency: identical retry converges; altered same-key payload fails cleanly per transport contract.
- **Allowed Paths:**
  - `tests/Feature/Phase10/GoldenJourneysTest.php` *(NEW PROPOSED)*
  - `tests/Feature/Phase10/IdempotencyConcurrencyTest.php` *(NEW PROPOSED)*
  - `tests/Support/` scenario helper fixtures
- **Prohibited Paths:**
  - Directly modifying `AccountingPostingService.php` or `app/Services/Inventory/` without prior Lead review and explicit test reproduction.
  - Modifying historical migrations.
- **Accepted Interfaces & Contracts:**
  - `AccountingPostingService::post()`, Brick Math exact decimal primitives, `ReconciliationService` suites.
- **Two-Company Test Fixtures:**
  - Company A (Trading entity with ILS base, USD/JOD foreign accounts)
  - Company B (Control entity to ensure zero cross-contamination)
- **Critical Negative Cases:**
  1. Mid-post exception thrown during inventory movement -> ledger batch, invoice status, and sequence numbers all roll back cleanly.
  2. Double-click submit: 2 concurrent requests with identical key and payload -> exactly one posted record created.
  3. Altered replay: 2 concurrent requests with identical key but altered price -> fails cleanly with conflict/validation exception.
  4. Concurrent sale of last remaining item -> exactly one sale succeeds; second receives InsufficientStockException; stock never negative.
- **Deliverables:**
  - `tests/Feature/Phase10/GoldenJourneysTest.php` *(NEW PROPOSED)*
  - Full execution evidence for J01–J20 in disposable MariaDB.
- **Focused Gate Commands (Existing Tests):**
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase2/PostingArchitectureBoundaryTest.php`
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase4/QuotationLifecycleTest.php`
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase5C/PurchasePostingTest.php`
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase6/MoneyTransfersTest.php`

---

```
+-----------------------------------------------------------------------------+
| PACKAGE P10-C1: Full Bilingual Responsive UI & Screen Interaction Audit     |
+-----------------------------------------------------------------------------+
| STATUS:       PREPARED, NOT DISPATCHED FOR IMPLEMENTATION                   |
| OWNER/ROLE:   Worker (AGY) under Lead (Codex) Supervision                   |
| DEPENDENCY:   P10-0 Baseline Accepted                                       |
| BUDGET:       ≤ 20 minutes; visual checks at 390px, 768px, 1440px           |
+-----------------------------------------------------------------------------+
```
- **Scope & Objectives:**
  - Audit all primary navigation screens across Arabic (RTL) and English (LTR).
  - Verify viewport adaptability at 390px (mobile), 768px (tablet), and 1440px (desktop).
  - Enforce zero horizontal page overflow on body/document; enforce localized scroll on wide tables.
  - Remove obsolete "Coming soon" placeholder card in Settings hub; point to Document Settings.
  - Verify form validation error retention (user input not wiped on validation failure).
  - Note on Inventory access: UI workers may read inventory services and DTOs for barcode/media presentation, but must not edit canonical inventory writers.
- **Allowed Paths:**
  - `resources/views/`
  - `resources/css/`
  - `app/Livewire/` (UI state, error messaging, layout bindings)
  - `lang/ar/` and `lang/en/`
- **Prohibited Paths:**
  - Any backend posting, accounting, inventory mutation, or security services.
  - Introducing heavy new JavaScript or CSS UI frameworks.
- **Accepted Interfaces & Contracts:**
  - Tailwind CSS v4, Livewire v4 Volt/Components, Blade templates.
- **Device & Locale Matrix:**
  - Viewports: 390×844 (Mobile), 768×1024 (Tablet), 1440×900 (Desktop)
  - Locales: `ar` (dir=rtl), `en` (dir=ltr)
- **Critical Negative Cases:**
  1. 390px viewport on complex invoice table: table scrolls horizontally within container without expanding page body beyond 390px.
  2. Mixed script text: Arabic customer name with English SKU and USD currency symbol rendered in correct reading order without visual overlap.
  3. Form submission with invalid input preserves entered draft lines and highlights errors with accessible labels.
- **Deliverables:**
  - Cleaned up `settings-index.blade.php`.
  - Machine-readable bounding box and viewport audit log.
- **Focused Gate Command (Existing Test):**
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase9/DocumentPresentationTest.php`

---

```
+-----------------------------------------------------------------------------+
| PACKAGE P10-C2: Accessibility, Localization & Print/PDF Document Truth      |
+-----------------------------------------------------------------------------+
| STATUS:       PREPARED, NOT DISPATCHED FOR IMPLEMENTATION                   |
| OWNER/ROLE:   Worker (AGY) under Lead (Codex) Supervision                   |
| DEPENDENCY:   P10-C1 UI Layout Stabilized                                   |
| BUDGET:       ≤ 20 minutes; accessibility & raster PDF checks               |
+-----------------------------------------------------------------------------+
```
- **Scope & Objectives:**
  - Audit accessibility targeting WCAG 2.2 AA (color contrast ≥ 4.5:1, form field labels, keyboard navigation, modal focus trapping).
  - Audit truthfulness of printed and PDF documents: rendered values must exactly match stored database snapshots.
  - Verify mPDF raster rendering across AR RTL shaping and EN LTR layout for Sales Invoices, Quotations, Returns, Receipts, Purchases, Purchase Returns, Vendor Payments, and Vendor Statements.
  - Validate barcode generation and scanner decodability (EAN-13, Code 128).
  - Enforce safe refusal on documents exceeding `DocumentRenderLimits` (e.g. >1,000 statement entries).
- **Allowed Paths:**
  - `resources/views/pdf/`
  - `app/Services/Sales/PdfRendererService.php`
  - `app/Services/Purchasing/PurchasingDocumentRenderer.php`
  - `lang/ar/` and `lang/en/`
- **Prohibited Paths:**
  - Altering underlying financial snapshots or calculation engines.
- **Accepted Interfaces & Contracts:**
  - mPDF v8.3, Endroid QR Code v6.1, Intervention Image v4.3.
- **Critical Negative Cases:**
  1. Cash payment receipt does not render misleading "Cheque" labels.
  2. Unauthorized user attempting PDF download receives 403 rather than empty or unredacted PDF.
  3. Statement query with 10,000 entries safely refuses generation per `DocumentRenderLimits`.
- **Deliverables:**
  - Accessibility audit report covering keyboard traps and contrast ratios.
  - Raster-inspected PDF sample evidence across AR and EN.
- **Focused Gate Commands (Existing Tests):**
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase9/PurchasingDocumentsTest.php`
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase9/DocumentPresentationTest.php`

---

```
+-----------------------------------------------------------------------------+
| PACKAGE P10-D1: Backup/Restore Rehearsal & Release Hardening               |
+-----------------------------------------------------------------------------+
| STATUS:       PREPARED, NOT DISPATCHED FOR IMPLEMENTATION                   |
| OWNER/ROLE:   Lead (Codex) Direction + Worker (AGY) Automation Runbooks     |
| DEPENDENCY:   P10-0 Baseline Accepted                                       |
| SPECIAL GATE: Isolated Restore Target Authorized (No Production Data Leak)  |
| BUDGET:       ≤ 25 minutes isolated target rehearsal                        |
+-----------------------------------------------------------------------------+
```
- **Scope & Objectives:**
  - Highest-priority operational lane: rehearse isolated restore and independent host-loss recovery before claiming launch readiness. No backup retrieval, custody change or restore is authorized by this template.
  - Execute a complete, isolated database restoration rehearsal in an access-controlled standalone test schema from a verified encrypted backup archive. Record exact target identity/owned-schema proof and fail closed if it resolves to production or any persistent application database.
  - Recover database, encrypted issuance/grant/revision records, private attachments, public media, compatible code/build, environment configuration and application-key history together. Verify expected hashes/counts and all six reconciliation domains; disable public ingress, outbound mail and production network/DB connections.
  - Demonstrate encrypted off-host retention independent of the hosting account, access logging/retention and authorized recovery access. Keep backup decryption secrets and Laravel `APP_KEY`/applicable previous keys in separately controlled custody, never beside off-host archives or in Git/logs. Test decryption and application-level encrypted-content recovery on isolated data; do not rotate production keys. Missing/wrong keys or unavailable off-host copies remain explicit P1 blockers, not an assumed PASS.
  - **Restore Baseline Rule:** The pre-Phase 9 backup contains **80 tables and 61 migrations** at prior accepted commit `96c310f30a07e97ab8e04d5afbf0b2bb805f4317`. Restore procedure must import this baseline, then apply forward reviewed migrations to verify current compatibility.
  - Target environment safety: restored target MUST NOT have public ingress, outbound email, or production database credentials. Key access for decryption is reviewed by custody reference.
  - Audit `bin/deploy.sh`: ensure `documents:bootstrap --all` is either integrated into `bin/deploy.sh` or encapsulated in a verified canonical release wrapper.
  - Establish Hostinger operational runbooks for log rotation, cron jobs, and rollback procedures.
- **Allowed Paths:**
  - `bin/deploy.sh`
  - `docs/PHASE_10_BACKUP_RESTORE_RUNBOOK.md` *(NEW PROPOSED)*
  - `docs/PHASE_10_OPERATIONAL_MONITORING_RUNBOOK.md` *(NEW PROPOSED)*
  - `tests/Deployment/`
- **Prohibited Paths:**
  - Running restore commands against production database or persistent dev database.
  - Exporting or transmitting unencrypted backup files or escrow keys.
- **Accepted Interfaces & Contracts:**
  - OpenSSL AES-256-CBC PBKDF2; Hostinger CLI `/opt/alt/php84/usr/bin/php`.
- **Critical Negative Cases:**
  1. Corrupted ciphertext backup fails hash verification; no database import attempted.
  2. Missing/wrong backup or application key aborts recovery; application decryption must succeed before the restored target is accepted. Destination identity is verified before any import, and no fallback to production is allowed.
  3. Deployment script executed with missing Vite manifest aborts immediately *before* entering maintenance mode.
- **Deliverables:**
  - `docs/PHASE_10_BACKUP_RESTORE_RUNBOOK.md` *(NEW PROPOSED)*
  - `docs/PHASE_10_OPERATIONAL_MONITORING_RUNBOOK.md` *(NEW PROPOSED)*
  - Isolated restore rehearsal execution log, off-host/key-custody proof with values redacted, cleanup/retention record and observed RPO/RTO metrics. Provisional goals are RPO ≤24h and RTO ≤4h; these are targets, not measured promises. Record differences and obtain Owner risk disposition before customer-readiness acceptance.
  - Pinned code/build rollback rehearsal on an isolated target with forward-compatible schema; a production data restore is a separately authorized incident operation, never a routine reverse migration.
- **Focused Gate Command (Existing Test):**
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase9/DocumentSettingsTest.php`
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Hostinger/SeparatedPublicRootTest.php`
  Direct document-bootstrap command idempotence and deploy-lock tests are NEW PROPOSED coverage; no existing `DocumentsBootstrapTest.php` is claimed.

---

```
+-----------------------------------------------------------------------------+
| PACKAGE P10-E1: Workload Profiling, EXPLAIN Analysis & Resource Tuning     |
+-----------------------------------------------------------------------------+
| STATUS:       PREPARED, NOT DISPATCHED FOR IMPLEMENTATION                   |
| OWNER/ROLE:   Lead (Codex) Direction + Worker (AGY) Benchmark Harness       |
| DEPENDENCY:   P10-0 Baseline; Stable P10-A/B Query Paths                   |
| BUDGET:       ≤ 20 minutes benchmark runs across synthetic profiles S, M, L|
+-----------------------------------------------------------------------------+
```
- **Scope & Objectives:**
  - Define three synthetic workload profiles in disposable MariaDB (Profile S: 10 products / 50 txs; Profile M: 500 products / 5,000 txs; Profile L: 2,500 products / 25,000 txs).
  - Measure query counts, N+1 regressions, memory consumption, and wall time across Dashboard, Reports, and Statements.
  - Record PHP CLI/web handler, extensions, effective memory/time configuration, MariaDB version/mode/packet settings and provider CPU/process/IO/concurrent-request quotas as distinct evidence. Source/CLI settings do not establish effective web capacity.
  - Measure reports/CSV, AR/EN PDFs and 1/2/4 concurrent local requests with finite S/M/L fixtures: elapsed time, peak memory, CPU where measurable, queries, locks/deadlocks, refusal status and cleanup. Use an approved isolated host-equivalent target for web measurements; record differences from Hostinger and mark unavailable metrics NOT VERIFIED. No production load/stress, PDF bombardment or synthetic production records.
  - Run MariaDB `EXPLAIN` on heavy queries; add forward-only composite indexes only where representative before/after evidence justifies them, under Lead ownership. Index use alone is not a performance acceptance criterion.
  - Verify CSV export caps: output exceeding 50,000 rows or 50 MiB file byte cap MUST fail closed before emitting partial output. (Memory 128M is a provisional goal, not an existing contract).
- **Allowed Paths:**
  - `database/migrations/` (additive, forward-only index migrations owned by Lead)
  - `app/Application/Reporting/`
  - `tests/Feature/Phase10/WorkloadBenchmarkTest.php` *(NEW PROPOSED)*
- **Prohibited Paths:**
  - Modifying canonical accounting posting logic or altering immutable historical lines.
  - Running load or stress tests against Hostinger production.
- **Accepted Interfaces & Contracts:**
  - MariaDB query optimizer, Laravel cursor streaming, Brick Math, `CsvReportWriter`.
- **Critical Negative Cases:**
  1. CSV export exceeding 50,000 rows terminates safely before partial stream delivery.
  2. A representative filtered report meets the agreed measured budget without changing economic results; inspect EXPLAIN/cardinality rather than demanding an index where a small-table scan is cheaper.
- **Deliverables:**
  - `docs/PHASE_10_PERFORMANCE_BENCHMARKS.md` *(NEW PROPOSED)* with before/after query timings and EXPLAIN traces.
- **Focused Gate Command (Existing Test):**
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase8/HistoricalPositionBatchTest.php`

---

## 3. Lead Integration Packages (P10-A through P10-G)

| Package | Lead Integration Scope | Final Gate Authority |
|---|---|---|
| **P10-A Integration** | Review worker security PRs across A1–A5, verify tenant isolation negatives, lock RBAC catalog. | Lead (Codex) |
| **P10-B Integration** | Review golden journey proofs (J01–J20), verify debit=credit and stock reconciliations. | Lead (Codex) |
| **P10-C Integration** | Review visual responsive audit, inspect raster PDF outputs, verify WCAG targets. | Lead (Codex) |
| **P10-D Integration** | Verify isolated restore rehearsal results, validate deployment script updates. | Lead (Codex) |
| **P10-E Integration** | Review EXPLAIN plans and benchmark metrics; approve any additive indexes. | Lead (Codex) |
| **P10-F Integrated Gate** | Execute one guarded broad QA pass, assemble integrated PR, prepare handoff. | ChatGPT (Independent Architect) |
| **P10-G Production Release** | Pre-merge verification, live backup, pinned deployment, independent read-only smoke. | Product Owner Authorization |

---

## 4. Execution Readiness Summary

Package templates are prepared. Codex must instantiate exact file ownership and the baseline envelope after implementation authorization; no runtime package has been dispatched.

```text
================================================================================
PHASE 10 PACKAGES PREPARED: A1, A2, A3, A4, A5, B1, C1, C2, D1, E1, LEAD
================================================================================
Dispatch Status:      PREPARED, NOT DISPATCHED FOR IMPLEMENTATION
Branch Creation:      DEFERRED (Awaits authorization to branch from main)
Hard Gate:            Explicit Product Owner implementation authorization required.
================================================================================
```

## Integrated evidence and support outputs

P10-F consolidates the 20 requirement dispositions and J01–J20 proofs, all primary route families with list/detail/form/loading/empty/error/restricted states where available, AR/EN at 390/768/1440px, keyboard/focus and actual raster-inspected document/decoded barcode samples. C1/C2 prepare concise AR-first/EN-second help for statuses and irreversible actions; lead verifies that guidance matches current routes and policy. Security disclosures remain server-enforced. D1 supplies isolated restore and rollback evidence with observed RPO/RTO and recovery custody limits; E1 supplies exact fixture sizes, SQL/memory/time and EXPLAIN measurements with actual host-limit evidence. F returns the integrated PR/source handoff for architect review. G remains separately authorized by the Owner; no implementation PR or release is created by this planning packet.

## Acceptance and release gates

1. **P10-0 publication:** independent architect reviews this proposed contract against exact Phase 9 runtime/source anchors. Publication does not authorize implementation.
2. **Implementation kickoff:** explicit Owner authorization; reconcile current main and instantiate exact bounded package/worktree envelopes. Start D1 recovery feasibility and A1 recovery delivery ahead of polish.
3. **Package acceptance:** lead independently verifies source, surrounding canonical boundaries, focused tests and evidence; observed defects get reproduction/regression, hypotheses get measured disposition. No worker report alone earns PASS.
4. **P10-F source acceptance:** complete the 20 requirement dispositions and J01–J20 isolated proofs, auth/tenant/concurrency/economic gates, successful restore/off-host/key recovery, delivery, measured performance and AR/EN interaction/PDF/accessibility evidence. No unresolved P0/P1 may be silently waived. Any infeasible readiness target needs an explicit Owner risk decision and architect disposition; document limitations without claiming certification. Run focused regressions during packages and one justified guarded broad pass at integration. Repeat only for changes, failures or unresolved concerns; report actual tests/assertions.
5. **P10-G release:** independent architect source acceptance followed by separate Owner merge and deployment authority. Verify exact SHA/tree, backup/rollback checkpoint, forward migrations and pinned assets; perform bounded read-only live smoke/reconciliations after an authorized release. Never overwrite live data in a rehearsal.
6. **Customer-readiness claim:** requires observed source and production acceptance plus recovery/support evidence and explicit residual-risk disposition. Any missing proof stays NOT RUN/NOT VERIFIED/FAIL, not a fabricated PASS.

## Documentation publication scope

This publication is documentation only, based on discovery source commit `47bd392ff4e2adb268805bfd7339805243c677c1` and production/main commit `8d8428261cd2a10690ab77a5c7271e46b5ff5217`. The publication branch preserves the Phase 9 documentation commit and ancestry. Only the four P10-0 planning documents are added by the new commit; its PR against main also carries the four unchanged Phase 9 documentation changes from the still-open acceptance PR. The original proposed specification and all Owner artifacts remain untouched. No runtime, migration, test or lockfile changes, production probes, broad QA, merge or deployment are part of publication. Implementation packages remain **PREPARED, NOT DISPATCHED** pending explicit authorization.
