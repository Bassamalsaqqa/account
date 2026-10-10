# ACCOUNTING — PHASE 10 PRODUCTION HARDENING
## Autonomous Implementation Package Specifications & Delegation Briefs

**Document Identity:** `docs/PHASE_10_AUTONOMOUS_IMPLEMENTATION_PACKAGES.md`\
**Package Status:** **PREPARED, NOT DISPATCHED FOR IMPLEMENTATION**\
**Execution Gate:** Implementation is blocked pending independent architect P10-0 acceptance and explicit Product Owner authorization.\
**Discovery Baseline Anchor:** Planning was prepared on branch `docs/phase9-production-acceptance` (`47bd392ff4e2adb268805bfd7339805243c677c1`). Upon implementation authorization, fetch and verify `origin/main` anew, record its exact SHA/tree, and rebind every package to that baseline in an isolated linked worktree. Current accepted main is `3e9ebf812a463db9d3f18aaf07b10431c63e84f5` after PR #19; its tree equals the discovery parent. The runtime/source anchor remains historical `8d8428261cd2a10690ab77a5c7271e46b5ff5217`; any later advance requires explicit reconciliation before dispatch.\
**Branch Notice:** Branch `phase/10-production-hardening` has **not** been created during this planning run.

Codex discovery/internal review: **COMPLETE**. Independent architect acceptance: **PENDING final correction review**. Runtime packages: **PREPARED, NOT DISPATCHED**. Phase 10 tests: **NOT RUN**. Production hardening: **INCOMPLETE**. Customer readiness: **NOT YET ESTABLISHED**.

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
3. **Checkpoint budgets, never acceptance substitutes:** Every 15–30 minute budget below bounds one dispatch/checkpoint, not completion of an entire route, nine-document, five-barcode, viewport/WCAG or J01–J20 matrix. Break work into finite owned lanes. On expiry stop and return exact partial evidence; unfinished requirements remain **NOT RUN**, **NOT VERIFIED** or **FAIL**. A later explicitly scoped checkpoint may continue; no automatic worker loop, weakened assertions, fabricated PASS or acceptance by elapsed time.
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

- Explicit Owner implementation authorization and independent architect P10-0 contract acceptance are prerequisites. No package is authorization by itself.
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
| DEPENDENCY:   Architect P10-0 acceptance + Owner kickoff                    |
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
| DEPENDENCY:   Architect P10-0 acceptance + Owner kickoff                    |
| BUDGET:       ≤ 25 minutes; route-family bounded lanes                      |
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
  2. Default Viewer in Company A requests Vendor Payment via AR/EN print/PDF -> **403/404 with no document bytes**, not a redacted successful response. Do not broaden Viewer grants.
  3. An explicitly authorized Purchase/Purchase Return reader with `purchasing.document.pdf` and `purchasing.purchase.view`, lacking `purchasing.cost.view`, may receive the permitted server-redacted DTO/HTML/PDF. This case cannot authorize Vendor Payment or Vendor Statement. Verify absence of restricted fields before serialization, not CSS hiding.
  4. Mid-session downgrade or revoked membership, including between document preparation and delivery: fresh authority denies AR/EN print/PDF and writes with no document bytes or partial effects. Use controlled rendering hooks/independent fixture connections, not production permission edits.
  5. Tampering with Livewire component public properties to inject Company B ID -> rejected by `CompanyScope`; cross-company output returns 403/404 without bytes.
  6. Protected role grant/assignment cannot self-escalate; test existing/new Company defaults, valid Owner delegation and revoked delegation without altering default Viewer grants.
- **Deliverables:**
  - `tests/Feature/Phase10/TenantIsolationSecurityTest.php` *(NEW PROPOSED)*
  - Route audit matrix verifying server denial across all 8 roles.
- **Verified authorization sequence and future focused coverage:**
  - `PurchasingDocumentBuilder::build` requires `purchasing.document.pdf` plus `VendorFinancialRead::allows` for Vendor Payment. The latter freshly requires active membership, `purchasing.cost.view` AND at least one of `money.vendor_payment.create`, `money.vendor_payment.allocate`, `money.vendor_payment.reverse`, `vendors.statement.view`.
  - Vendor Statement builder first checks `purchasing.document.pdf`; `VendorStatementQuery::execute` then checks membership, `vendors.statement.view` and `purchasing.cost.view` **before its financial history read**. The controller performs final locked statement/cost checks **after rendering**. Existing protection is present; no exploit is claimed.
  - Focused fixture cases cover pdf-only, missing statement/cost authority, cached permission relations, revocation/downgrade during preparation and expensive rendering, and valid combined grants. Instrument builder/query/renderer calls to prove whether unauthorized requests reach expensive rendering; assert no bytes on denial and fresh authority before delivery. Any demonstrated ordering/caching defect needs isolated reproduction and architect-reviewed correction after runtime authorization, never a policy relaxation here.
- **Focused Gate Command (Existing Test):**
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase2/Phase2CompanyIsolationTest.php`

---

```
+-----------------------------------------------------------------------------+
| PACKAGE P10-A3: Public Shares, Catalogs & Web Perimeter Security            |
+-----------------------------------------------------------------------------+
| STATUS:       PREPARED, NOT DISPATCHED FOR IMPLEMENTATION                   |
| OWNER/ROLE:   Worker (AGY) under Lead (Codex) Supervision                   |
| DEPENDENCY:   Architect P10-0 acceptance + Owner kickoff                    |
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
| DEPENDENCY:   Architect P10-0 acceptance + Owner kickoff                    |
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
| DEPENDENCY:   Architect P10-0 acceptance + Owner kickoff                    |
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
| DEPENDENCY:   Architect acceptance + Owner kickoff; A2 policy locked          |
| BUDGET:       ≤ 30 minutes per finite journey-group checkpoint               |
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
| DEPENDENCY:   Architect P10-0 acceptance + Owner kickoff                    |
| BUDGET:       ≤ 20 minutes; visual checks at 390px, 768px, 1440px           |
+-----------------------------------------------------------------------------+
```
- **Scope & Objectives:**
  - Audit all primary navigation screens across Arabic (RTL) and English (LTR).
  - Verify viewport adaptability at 390px (mobile), 768px (tablet), and 1440px (desktop).
  - Enforce zero horizontal page overflow on body/document; enforce localized scroll on wide tables.
  - Audit **both Print/PDF and Sharing/Links settings cards** against working Phase 9 Document Settings, private document actions, financial-share manager and catalog routes. Require truthful labels/navigation in AR/EN and permitted/restricted roles. Correct misleading future-phase copy only where it describes shipped capabilities; retain genuinely unimplemented advanced configuration as future work rather than unconditionally removing either card.
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
  - Evidence-led AR/EN copy/navigation corrections for both settings cards, with genuine future capabilities still labelled future; no blanket tile deletion.
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
  - Authenticated AR/EN **print and actual raster-inspected PDF** for all nine shipped financial outputs: **Quotation, Sales Invoice, Sales Return, Customer Receipt, Customer Statement, Purchase, Purchase Return, Vendor Payment, Vendor Statement**. Compare screen/print/PDF facts with canonical queries and historical snapshots; do not mix current decorative branding with immutable economic identity.
  - Customer Statement specifically checks historical/as-of and per-currency truth for ILS/USD/JOD, opening + activity = closing, partial/later allocations, returns/reversals, from/to boundaries and Company-local midnight. Require `sales.document.pdf`, `sales.statement.view`, `customers.statement.view`, fresh membership and same-company identity. Source refusal is >1,000 total commercial entries before hydration, even when a narrow date filter would show fewer rows; test the boundary and safe refusal without document bytes.
  - Password-protected **issued public Statements** remain the A3 fixed-issuance/grant-security lane. Reuse `FinancialSharesTest.php` and relevant Phase 9 security regressions; C2 tests authenticated statement truth rather than duplicating the broad public-share suite. Catalog print/PDF is a separate nonfinancial projection gate, with prices-OFF/redaction policy retained.
  - Supported barcode gate: **Code 128, EAN-13, EAN-8, UPC-A, UPC-E**. Apply the explicit carried/new matrix below; actual scanner decoding is required, never inferred from SVG/HTML text or carried aggregate counts.
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
  2. Unauthorized AR/EN print/PDF requests receive 403/404 without document bytes; authorized cost-limited Purchase/Purchase Return readers receive only the permitted server-redacted projection. Default Viewer Vendor Payment/Statement remains denied.
  3. Customer/Vendor Statements with >1,000 source entries safely refuse before hydration/render; test opening/activity/closing and date boundaries at/below the supported limit.
  4. EAN/UPC invalid digits or check digits, unsupported symbology, invalid UPC-E number system/expansion, foreign/inactive Product/Unit and overwide symbols fail safely; label limits remain 100 distinct symbols / 500 labels.
- **Deliverables:**
  - Accessibility audit report covering keyboard traps and contrast ratios.
  - Raster-inspected PDF sample evidence across AR and EN.
- **Focused Gate Commands (Existing Tests):**
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase9/PurchasingDocumentsTest.php`
  `php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase9/DocumentPresentationTest.php`

### C2 document and barcode evidence policy

Carried Phase 9 evidence: [source handoff](PHASE_9_SOURCE_ACCEPTANCE_HANDOFF.md) reports nine-document browser coverage and actual barcode decodes of **24/24 AR, 14/14 EN and 49/49 UPC-E** at 300 dpi. Existing source tests cover all five Sales outputs, four Purchasing/Vendor outputs, EAN-13/EAN-8/UPC-A invalid check digits, Code 128 ASCII/width bounds, UPC-E exact stored symbols and piece/carton identity. These are carried reports/source coverage, **not fresh P10 PASS**. Aggregate AR/EN counts do not independently identify every decoded symbology; unclassified per-type evidence remains unavailable here.

| Symbol/case | Carried/source evidence | Newly required P10 verification (all NOT RUN) |
|---|---|---|
| Code 128 | Printable ASCII, stored code, piece/carton, width tests; AR/EN decode aggregates not classified per type | Decode actual AR/EN PDF sample; preserve spaces/code identity and safe width/ASCII refusal |
| EAN-13 | Existing valid fixtures and invalid check-digit source tests | Decode actual AR/EN samples; compare exact 13-digit code/check digit and invalid-code refusal |
| EAN-8 | Existing valid fixtures and invalid check-digit source tests | Decode actual AR/EN samples; compare exact 8-digit code/check digit and invalid-code refusal |
| UPC-A | Existing valid fixtures and invalid check-digit source tests | Decode actual AR/EN samples; compare exact 12-digit code/check digit and invalid-code refusal |
| UPC-E | Exact stored-symbol/multi-sheet source tests; reported 49/49 actual UPC-E decodes | Decode actual AR/EN samples and compare 8-digit stored caption with canonical expansion when decoder returns UPC-A; test suffix branches 0/1/2, 3, 4, 5–9 and number systems 0/1, reject bad check digit/system |
| Product/Unit identity | Existing base/null Unit and carton conversion tests | Verify separate stored piece/carton symbols, exact conversion 1 vs fixture carton 12, actual decoded-to-Product/Unit lookup, null Unit → stored base Unit, foreign/inactive Unit denial and no silent substitution |

Minimum fresh decoder set is one actual label of each of the five types in AR and EN, plus distinct base/carton labels and bounded pagination/width cases; reuse a label for overlapping checks rather than rerun whole suites. Record decoder normalization, stored/encoded/decoded values, fixture Product/Unit/conversion and PDF/page evidence. Reuse focused existing check-digit/UPC-E/Unit tests where unchanged; explicitly report any cases carried rather than rerun. Missing decoder/tool access remains NOT VERIFIED and is returned for disposition, never replaced by fabricated scanner proof. All fixtures are isolated; production has no label/barcode fixtures to create for this matrix.

---

```
+-----------------------------------------------------------------------------+
| PACKAGE P10-D1: Isolated Recovery, Current DR & Release Hardening             |
| STATUS:       PREPARED, NOT DISPATCHED FOR IMPLEMENTATION                   |
| OWNER/ROLE:   Codex custody/acceptance; AGY bounded runbook automation      |
| DEPENDENCY:   Architect P10-0 acceptance + Owner implementation kickoff     |
| SPECIAL GATE: Separate backup/key access and isolated restore authorization |
| BUDGET:       ≤ 25 minutes per bounded checkpoint; not full DR acceptance   |
+-----------------------------------------------------------------------------+
```

This template authorizes no backup retrieval, extraction, key access, restore, schedule/provider change or production operation. The three P1 readiness gates remain verified isolated restoration, working approved account recovery and independent off-host backup/key recovery. Decrypting an archive, checking gzip/tar or inspecting hashes is not an actual restore rehearsal.

### D1-A. Historical upgrade compatibility (separate proof)

The retained pre-Phase 9 snapshot is **80 tables / 61 migrations** at `96c310f30a07e97ab8e04d5afbf0b2bb805f4317`. On an authorized isolated target, apply D1-C before any bootstrap; restore that matching code/build/database/files, verify the prior counts/content, then apply only reviewed forward migrations to **83 tables / 63 migrations** for the accepted Phase 9 runtime. Record both stages and all six reconciliations. This compatibility test cannot prove current backup freshness or today's RPO.

### D1-B. Current disaster recovery capability (independent proof)

Require independent restore of a **current coherent recovery point**, with matching source SHA/build, SQL, private attachments, public media, encrypted issuance/grant/publication/audit records, relevant configuration and required Laravel key history. Record the actual manifest/snapshot timestamp, compatibility matrix and hashes, consistency method for DB/files/key-version changes and required recovery components. Restore only existing history; never invent missing issuance from current master/ledger state or reactivate expired/revoked grants.

- **Generation/cadence proposal:** completed coherent encrypted backup at least daily and before authorized releases/schema/key changes; use infrastructure-native facilities or approved restricted cron + locks, no permanent worker. This is a proposed Phase 10 policy, not an existing schedule.
- **Retention proposal:** 7 daily, 4 weekly, 3 monthly encrypted recovery points, preserving required application keys while any retained encrypted history/backup needs them. Validate storage/cost/privacy feasibility; record the adopted Owner-approved policy and any explicit deviation before acceptance. No assumed deployed retention.
- **Age/failure monitoring:** monitor last successfully completed **off-host recoverable** point separately from job start/on-host file creation; warn on >24h age, alert on failed/incomplete generation/transfer/verification or unavailable custody. Lead documents alert recipient, check cadence and controlled delivery under separate authority; no unexpected messages.
- **Independent custody:** encrypted copies survive loss of the hosting account; named custodian/access controls, access logs, trusted digests, retention and a tested recovery path. Backup decryption secret custody is separate from ciphertext copies. Independently recover applicable `APP_KEY`/`APP_PREVIOUS_KEYS` with version/cipher references, without publishing values or treating same-host escrow as host-loss proof.
- **Secret-bearing archive model:** the historical application-state archive includes production `.env`, potentially including application keys and provider/DB credentials. It is restricted **secret-bearing ciphertext**. Separate backup-key escrow does not make application keys absent from that archive. Restrict every copy and plaintext scratch artifact accordingly; independent application-key recovery must be demonstrated separately, not claimed from co-contained bytes. Future formats must document whether configuration/key values are included; preserve historical bytes. No production key rotation or retrieval is permitted here.
- **Verification:** end-to-end isolated startup/login with approved safe auth, Company/role isolation, exact schema/content/file hashes, key/cipher/version compatibility, and **Accounting, Inventory, Sales, Payables, Money, Phase 7** reconciliations. Verify original encrypted-content hashes, retained grants/revisions and revoked/expired denial. The recorded production baseline has no issued financial grants; record that limitation and separately backup/restore owned synthetic encrypted issuance fixtures to exercise correct/wrong/missing/previous keys. Fixture proof cannot be labelled production-record recovery.
- **Observed RPO/RTO:** measure data recovery-point age/gap and elapsed retrieval/custody/sanitization/import/application verification steps; record assumptions, missing prerequisites and cleanup separately. ≤24h RPO and ≤4h RTO remain provisional targets, not observed promises. Historical checkpoint age cannot satisfy current recovery. Missing restore, off-host or key proof remains P1; release cannot claim readiness with open P0/P1 under Owner spec §11 F5. Any material infeasibility/target change requires an explicit Owner/architect decision.

### D1-C. Fail-closed pre-bootstrap isolation (both proof lanes)

1. Verify the trusted **encrypted archive checksum** before any processing; refuse corruption, unknown manifest or mismatch. Protect the trusted digest source independently.
2. Decrypt/extract only into a restricted, nonpublic, network-isolated scratch environment. Supply the archive decryption secret only through separately authorized protected offline custody for this step; never load it as application configuration. Verify archive paths/symlinks cannot escape it; no live app/worktree extraction and no automatic startup. Keep sensitive directories/files restricted (0700/0600).
3. Quarantine archived production `.env` and any stale Laravel configuration caches, especially `bootstrap/cache/config.php`, without sourcing/executing them. **Never activate archived production credentials**, even temporarily. Extracted production configuration is inert secret-bearing material, never the restore target's active environment.
4. Create fresh isolated environment settings and an independently verified owned disposable DB identity: target host/resolved address/schema/user, credentials unique to this target, ownership proof and allowed local connection. No persistent-dev or production schema, server/credential fallback or production DSN aliases.
5. Block production DB access and outbound network/integrations at the environment/network layer; disable SMTP/messaging, scheduled/background jobs, queue dispatch and public ingress. Configuration flags alone are insufficient isolation. Restored jobs/credentials cannot activate providers.
6. **Before any Artisan, migration, jobs or application startup**, verify effective host/schema/user against that owned target, sanitized active environment, quarantined caches and network/ingress/egress denial (including production host aliases/resolved addresses). If effective configuration or isolation cannot be proven without unsafe bootstrap, stop. Keep a redacted pre-bootstrap checklist/proof.
7. Only after that gate, inject separately authorized temporary Laravel application decryption keys/previous-key history via protected custody into the isolated application. The archive secret used for offline extraction in step 2 remains separate and is never an active application setting. No logs/CLI history/Git/screenshots containing values; no use of the entire archived `.env` as a shortcut. Key-history material stays restricted and matches the selected recovery point.
8. Perform authorized recovery/application verification; then securely remove plaintext SQL, extracted secrets, transient keys/credentials/caches, fixture/restore schemas and temporary files, or quarantine retained evidence under the approved access/retention policy. Record cleanup and key retention without values; leave no servers/tunnels/jobs/public endpoints running.

### D1-D. Negative cases and deliverables

Finite isolated cases: corrupted/untrusted ciphertext; absent/incoherent current snapshot; missing/wrong/previous-key incompatibility; archived production `.env`; stale cached production DSN; production host alias/resolved address or wrong schema/user/ownership; attempted SMTP/outbound integration/job execution; inadvertent public ingress; archive traversal/symlink escape; missing attachments/assets; unsupported encrypted version/hash mismatch; revoked/expired grant resurrection. Refuse **before the unsafe step**, with zero production connectivity/data mutation and no document leakage.

Lead owns backup/key policy, target gate and independently verified restore results. AGY may prepare only bounded local automation/runbooks after dispatch authorization. Future candidate paths: `bin/deploy.sh`, `tests/Deployment/`, proposed `docs/PHASE_10_BACKUP_RESTORE_RUNBOOK.md` and `docs/PHASE_10_OPERATIONAL_MONITORING_RUNBOOK.md`. No permission to edit these during this Markdown-only correction. Prohibit live/persistent restores, unencrypted transmission, secret publication and unapproved production config/key/scheduler changes.

Deliver redacted separate historical/current proof logs, approved cadence/retention/age-monitoring and off-host/key-custody records, observed RPO/RTO, all six reconciliations, application-content verification, negative-case results, cleanup and explicit missing evidence. Rehearse pinned code/build rollback on an isolated target with forward-compatible schema; production data restore remains a separately authorized incident operation. Audit document bootstrap wrapper/lock/manifest failure before maintenance under the existing release architecture.

Focused existing regressions: `tests/Feature/Phase9/DocumentSettingsTest.php` and `tests/Feature/Hostinger/SeparatedPublicRootTest.php` via the owned-schema runner. Direct restore/bootstrap/lock/negative-isolation coverage is **NEW PROPOSED**, not existing test execution. These regressions alone cannot satisfy D1 recovery proof.

---

```
+-----------------------------------------------------------------------------+
| PACKAGE P10-E1: Workload Profiling, EXPLAIN Analysis & Resource Tuning     |
+-----------------------------------------------------------------------------+
| STATUS:       PREPARED, NOT DISPATCHED FOR IMPLEMENTATION                   |
| OWNER/ROLE:   Lead (Codex) Direction + Worker (AGY) Benchmark Harness       |
| DEPENDENCY:   Architect P10-0 + Owner kickoff; Stable P10-A/B Query Paths   |
| BUDGET:       ≤ 20 minutes benchmark runs across synthetic profiles S, M, L |
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
Runtime Branch:       DEFERRED (Await architect acceptance + Owner kickoff)
Hard Gate:            Architect acceptance + explicit Owner kickoff required.
================================================================================
```

## Integrated evidence and support outputs

P10-F consolidates the 20 requirement dispositions and J01–J20 proofs, all primary route families with list/detail/form/loading/empty/error/restricted states where available, AR/EN at 390/768/1440px, keyboard/focus and actual raster-inspected document/decoded barcode samples. C1/C2 prepare concise AR-first/EN-second help for statuses and irreversible actions; lead verifies that guidance matches current routes and policy. Security disclosures remain server-enforced. D1 supplies separate historical upgrade and current coherent-backup restore proofs, pre-bootstrap isolation, independent off-host/secret/key custody, observed RPO/RTO, negative cases, cleanup and rollback evidence; E1 supplies exact fixture sizes, SQL/memory/time and EXPLAIN measurements with actual host-limit evidence. F returns the integrated PR/source handoff for architect review. G remains separately authorized by the Owner; no implementation PR or release is created by this planning packet.

## Acceptance and release gates

1. **P10-0 publication:** independent architect reviews this proposed contract against exact Phase 9 runtime/source anchors. Publication does not authorize implementation.
2. **Implementation kickoff:** independent architect P10-0 acceptance plus explicit Owner authorization; reconcile current main and instantiate exact bounded package/worktree envelopes. Start D1 recovery feasibility and A1 recovery delivery ahead of polish.
3. **Package acceptance:** lead independently verifies source, surrounding canonical boundaries, focused tests and evidence; observed defects get reproduction/regression, hypotheses get measured disposition. No worker report alone earns PASS.
4. **P10-F source acceptance:** complete the 20 requirement dispositions and J01–J20 isolated proofs, auth/tenant/concurrency/economic gates, successful restore/off-host/key recovery, delivery, measured performance and AR/EN interaction/PDF/accessibility evidence. Owner spec §11 F5 requires **zero open P0/P1** at release acceptance; no missing proof or checkpoint expiry can waive that gate. An infeasible target needs an explicit Owner/architect contract decision before any altered gate; this packet grants no waiver and documents no approved substantive scope deviation. Run focused regressions during packages and one justified guarded broad pass at integration. Repeat only for changes, failures or unresolved concerns; report actual tests/assertions.
5. **P10-G release:** independent architect source acceptance followed by separate Owner merge and deployment authority. Verify exact SHA/tree, backup/rollback checkpoint, forward migrations and pinned assets; perform bounded read-only live smoke/reconciliations after an authorized release. Never overwrite live data in a rehearsal.
6. **Customer-readiness claim:** requires observed source and production acceptance plus recovery/support evidence and explicit residual-risk disposition. Any missing proof stays NOT RUN/NOT VERIFIED/FAIL, not a fabricated PASS.

## Documentation publication scope

PR #19 is merged at current main `3e9ebf812a463db9d3f18aaf07b10431c63e84f5` (tree `6e4f98739ace0c0890847f33f6bad0de1a4e84e6`). Its tree equals preserved Phase 9 documentation commit `47bd392ff4e2adb268805bfd7339805243c677c1`. Phase 9 production runtime remains the accepted historical release `8d8428261cd2a10690ab77a5c7271e46b5ff5217`; this correction performs no production refresh. PR #20 branch `docs/phase10-p0-contract` preserves `47bd392… → e20c1ea… → documentation correction` by a normal new commit, with no branch reconstruction or history rewrite. A main-to-branch merge is unnecessary because both the effective and merge-base differences already contain exactly the four intended P10-0 documents and the Phase 9 documentation trees are equal.

Only these four P10-0 planning documents are publication files. The protected Owner original, roadmaps, supplied PDFs, existing browser artifacts and ignored evidence remain unchanged/excluded. The accepted Phase 9 production record is untouched. No runtime, test, migration, dependency, lockfile or deployment-script edits; no CI, application tests, browser runs, SMTP probes, backup/key retrieval/extraction, schema operations, production access, merge or deployment.

Codex discovery/internal review: **COMPLETE**. Independent architect acceptance: **PENDING final correction review**. Runtime packages: **PREPARED, NOT DISPATCHED**. Phase 10 tests: **NOT RUN**. Production hardening: **INCOMPLETE**. Customer readiness: **NOT YET ESTABLISHED**.
