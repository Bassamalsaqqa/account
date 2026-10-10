# ACCOUNTING — PHASE 10 PRODUCTION HARDENING
## Phase 10 Acceptance Matrix & Golden Scenario Scoreboard

**Document Identity:** `docs/PHASE_10_ACCEPTANCE_MATRIX.md`\
**Phase State:** **P10-0 PLANNING BASELINE — ALL NEW PHASE 10 CHECKS MARKED AS NOT RUN**\
**Date:** 2026-10-10\
**Discovery Baseline Branch:** `docs/phase9-production-acceptance` (`47bd392ff4e2adb268805bfd7339805243c677c1`)\
**Test Runner Contract:** Automated tests must execute via `tests/Support/run-phase8-disposable.php -- <target>` with explicit `APP_ENV=testing`, `PHASE8_ALLOW_DISPOSABLE_DB=1`, and local `PHASE8_TEST_DB_{HOST,PORT,USERNAME,PASSWORD}` against a disposable MariaDB schema. Bare `artisan test` against default persistent databases is strictly prohibited.\
**Carried vs Phase 10 Proof:** Carried Phase 9 production evidence is distinct from Phase 10 verification. Historical source QA does not equal a Phase 10 PASS or blanket WCAG certification. No Phase 10 implementation tests have been run during this planning phase.

---

## 1. Reproducible Source Discovery Inventory

The current codebase at HEAD `47bd392ff4e2adb268805bfd7339805243c677c1` contains the following verified inventory:

| Metric | Exact Count | Verification Method / Command |
|---|---:|---|
| **Database Migrations** | 63 | `rg --files database/migrations \| rg '\.php$'` |
| **Database Tables (Target Schema)** | 83 | 80 pre-Phase 9 tables + 3 Phase 9 catalog tables |
| **Registered Permissions** | 97 | `app/Services/Tenancy/CompanyRoleService.php` (`PERMISSIONS` catalog) |
| **Protected Permissions** | 5 | `app/Services/Tenancy/CompanyRoleService.php` (`PROTECTED_PERMISSIONS`) |
| **Total Role Grants Across Roles** | 218 | Carried production fact (`Owner` 97 + 7 non-owner roles 121) |
| **Registered Report Variants** | 69 | Carried production fact (`app/Application/Reporting/Presentation/ReportRegistry.php`) |
| **Document Sequence Counters** | 12 | Carried production fact (`document_sequences` table) |
| **Artisan Console Classes** | 16 | `rg --files app/Console/Commands \| rg '\.php$'` (8 bootstrap, 5 reconcile, 1 rebuild, 2 app) |
| **Reconcile Console Commands** | 5 | `accounting:reconcile`, `inventory:reconcile`, `money:reconcile`, `phase7:reconcile`, `sales:reconcile` *(Payables is a service `PayablesReconciliationService`, not a command)* |
| **Total PHP Files in Tests** | 206 | `rg --files tests \| rg '\.php$'` |
| **PHP Files in `tests/Feature/`** | 194 | `rg --files tests/Feature \| rg '\.php$'` (192 in 18 subdirectories + 2 root: `ExampleTest.php`, `ProfileTest.php`) |
| **PHP Files in `tests/Unit/`** | 2 | `rg --files tests/Unit \| rg '\.php$'` (`ExampleTest.php`, `Phase4/SalesCalculatorsTest.php`) |
| **PHP Files in `tests/Support/`** | 9 | `rg --files tests/Support \| rg '\.php$'` |
| **Test Classes Ending `Test.php`** | 190 | `rg --files tests \| rg 'Test\.php$'` |
| **Non-PHP Files in Tests** | 2 | `tests/Deployment/purchasing-provisioning.sh`, `tests/Support/Phase6/run-races.py` |
| **Reconciliation Domains** | 6 | Accounting, Inventory, Sales, Payables, Money, Phase 7 |

### 1.1 `tests/Feature/` Directory Breakdown (194 PHP Files Total)
- Feature Root: 2 files (`ExampleTest.php`, `ProfileTest.php`)
- `tests/Feature/Auth`: 6 files
- `tests/Feature/Console`: 1 file
- `tests/Feature/Hostinger`: 1 file
- `tests/Feature/Phase0`: 1 file
- `tests/Feature/Phase1`: 6 files
- `tests/Feature/Phase2`: 18 files
- `tests/Feature/Phase3`: 14 files
- `tests/Feature/Phase4`: 15 files
- `tests/Feature/Phase5A`: 1 file
- `tests/Feature/Phase5B`: 2 files
- `tests/Feature/Phase5C`: 2 files
- `tests/Feature/Phase5D`: 13 files
- `tests/Feature/Phase5E`: 27 files
- `tests/Feature/Phase5F`: 2 files
- `tests/Feature/Phase6`: 20 files
- `tests/Feature/Phase7`: 16 files
- `tests/Feature/Phase8`: 35 files
- `tests/Feature/Phase9`: 12 files
*(Subdirectories sum: 192 files + 2 root files = 194 PHP files)*

---

## 2. Requirement Acceptance Scoreboard (All 20 Requirements in Spec §13)

Status Definitions:
- `CARRIED HISTORICAL EVIDENCE`: Previously validated in Phase 9 release; carried forward as historical record, not newly rerun.
- `NOT RUN (P10 Planning)`: Proposed Phase 10 verification; execution blocked pending implementation authorization.

| Requirement ID | Specification Clause | Attack / Negative Scenario | Expected Response / Assertion | Actual Route / Component | Roles & Viewports / Locales | Existing or Proposed Test File | Phase 10 Status |
|---|---|---|---|---|---|---|---|
| **SEC-01** | No cross-Company IDOR in protected routes, Livewire methods, exports, public paths | User in Company A requests invoice/payment/statement of Company B via URL parameter or Livewire method payload. | HTTP 404 (model not found within company scope) or HTTP 403; zero data returned. | `invoices/{publicId}`, `pdf/invoice/{publicId}`, Livewire actions | Foreign Tenant, Viewer | `tests/Feature/Phase2/Phase2CompanyIsolationTest.php` *(Existing)*<br>`tests/Feature/Phase10/TenantIsolationSecurityTest.php` *(NEW PROPOSED)* | **NOT RUN (P10 Planning)** |
| **SEC-02** | Owner-only delegated protected Phase 9 publication/price/settings defaults | Administrator or Manager attempts to publish catalog, enable public prices, or edit document settings. | HTTP 403 Forbidden; no unauthorized grant or publication mutation; no role self-escalation via `settings.roles.manage`. | `settings/documents`, `catalogs/{publicId}`, `CompanyRoleService` | Administrator, Manager, Owner | `tests/Feature/Phase9/DocumentSettingsTest.php` *(Existing)* | **NOT RUN (P10 Planning)** |
| **SEC-03** | Auth/session/logout/recovery/2FA safety | User accesses protected page after logout; submits invalid 2FA code; brute forces password. | Session invalidated on logout; 2FA invalid code fails; >5 login failures throttles HTTP 429. | `/login`, `/logout`, `/two-factor-challenge`, `/forgot-password`, `/reset-password/{token}` | All Roles; Desktop / Mobile | `tests/Feature/Auth/AuthenticationTest.php` *(Existing)*<br>`tests/Feature/Auth/PasswordResetTest.php` *(Existing)* | **NOT RUN (P10 Planning)** |
| **SEC-04** | Public financial links neutral and bound to issued grant and password | Caller fetches `/share/{token}` with GET; guesses password; requests expired/revoked token. | GET/HEAD returns neutral landing; bad password rejects; expired/revoked returns HTTP 404. | `share/{token}` (GET/POST) | Public Guest; AR / EN | `tests/Feature/Phase9/FinancialSharesTest.php` *(Existing)* | **NOT RUN (P10 Planning)** |
| **SEC-05** | Catalog prices OFF and Revoke terminal | Public requests `/catalog/{token}`; attempts to view prices when disabled; accesses revoked link. | HTML/JSON/PDF contains zero price fields; terminal Revoke permanently retires token (HTTP 404). | `catalog/{token}` (GET/POST) | Public Guest; AR / EN | `tests/Feature/Phase9/PublicCatalogTest.php` *(Existing)*<br>`tests/Feature/Phase9/CatalogLifecycleExpiryTest.php` *(Existing)* | **NOT RUN (P10 Planning)** |
| **SEC-06** | Upload, asset and private-data isolation | Attacker uploads SVG with embedded `<script>`; path traversal filename `../../etc/passwd`; oversized image. | ValidationException: invalid MIME type or extension; path sanitized; private uploads outside web root. | `attachments/expenses/{publicId}`, `ProductImageService` | Manager, Purchasing | `tests/Feature/Phase7/ExpenseTest.php` *(Existing)*<br>`tests/Feature/Phase10/UploadSecurityTest.php` *(NEW PROPOSED)* | **NOT RUN (P10 Planning)** |
| **SEC-07** | Dependency, config, secret and log hygiene | Direct HTTP probe of `.env`, `.git`, or backup directories; check logs for token or plaintext statements. | HTTP 403/404 on web probes; logs contain zero bearer tokens, passwords, or encrypted statement payloads. | Apache/LiteSpeed webroot, `storage/logs/` | Public Web Probes | `tests/Deployment/purchasing-provisioning.sh` *(Existing)*<br>`tests/Feature/Phase10/EnvironmentSecurityTest.php` *(NEW PROPOSED)* | **NOT RUN (P10 Planning)** |
| **ECO-01** | Canonical atomic postings and reversals | Exception injected during inventory write of posted invoice; verify database state after rollback. | Database transaction rolls back 100%; zero ledger lines, zero stock movements, sequence unconsumed. | `AccountingPostingService`, `InventoryMovementService` | Cashier, Sales | `tests/Feature/Phase2/PostingArchitectureBoundaryTest.php` *(Existing)*<br>`tests/Feature/Phase4/SalesReturnTest.php` *(Existing)* | **NOT RUN (P10 Planning)** |
| **ECO-02** | Exact amounts, FX, Units, historical snapshots | Cross-currency invoice in USD settled in ILS; product master price edited after posting. | Posted invoice lines retain historical price/FX snapshot; ledger debits = credits with zero float drift. | `AccountingPostingService`, `HistoricalSaleCost` | Sales, Purchasing | `tests/Feature/Phase2/NumericPrecisionAndBoundsTest.php` *(Existing)*<br>`tests/Feature/Phase6/CrossCurrencyVendorPaymentsTest.php` *(Existing)* | **NOT RUN (P10 Planning)** |
| **ECO-03** | No duplicates on retry/concurrency | Two parallel requests submit identical transaction key and payload; second altered payload submitted. | Identical retry converges to single posted transaction; altered payload with same key fails cleanly. | `PostingIdempotencyPayloadTest`, `PublicationConcurrencyTest` | Sales, Purchasing | `tests/Feature/Phase2/PostingIdempotencyPayloadTest.php` *(Existing)*<br>`tests/Feature/Phase9/PublicationConcurrencyTest.php` *(Existing)* | **NOT RUN (P10 Planning)** |
| **ECO-04** | 69 reports remain correct/permission-scoped | Viewer attempts to access Gross Profit report; generate Customer Aging report with filtered date. | Profit query aborts 403; aging report matches canonical ledger balance as of specified date. | `reports/{reportKey}`, `reports/{reportKey}/csv` | Viewer, Owner; AR / EN | `tests/Feature/Phase8/IntegratedReportTruthTest.php` *(Existing)* | **NOT RUN (P10 Planning)** |
| **UX-01** | AR/EN responsive screens and workflows | Render core screens at 390px, 768px, 1440px in AR RTL and EN LTR; check for body horizontal overflow. | Document body has zero horizontal scroll; tables scroll locally within container; clean RTL/LTR layout. | Dashboard, Invoices, Purchases, Catalogs, Barcode Labels | All Roles; 390×844, 768×1024, 1440×900 | `tests/Feature/Phase10/ResponsiveLayoutAuditTest.php` *(NEW PROPOSED)* | **NOT RUN (P10 Planning)** |
| **UX-02** | Accessible controls, focus, errors, keyboard | Form submitted with validation errors; keyboard tab navigation through modal dialogs. | Validation preserves user input; errors linked with accessible labels; focus trapped in open modal. | Invoice Form, Purchase Form, Transfer Form | All Roles; Keyboard / Screen reader | `tests/Feature/Phase10/AccessibilityAuditTest.php` *(NEW PROPOSED)* | **NOT RUN (P10 Planning)** |
| **UX-03** | Truthful documents and printed financial content | Unauthorized role attempts to download PDF with acquisition cost; inspect cash payment receipt labels. | Cost redacted from PDF bytes; cash receipt renders "Cash" label (not "Cheque"); RTL Arabic text shaped properly. | `pdf/invoice/{publicId}`, `pdf/payment/{publicId}`, `PdfRendererService` | Viewer, Sales, Purchasing; AR / EN | `tests/Feature/Phase9/PurchasingDocumentsTest.php` *(Existing)*<br>`tests/Feature/Phase9/DocumentPresentationTest.php` *(Existing)* | **NOT RUN (P10 Planning)** |
| **OPS-01** | Isolated complete restore rehearsal | Restore prior-source backup into standalone test schema, apply forward migrations, verify row counts. | Verify isolated destination first; restore prior 80 tables/61 migrations, apply forward upgrade to 83/63, recover files and encrypted content with separately held keys, all six reconciliations pass; record off-host availability, observed RPO/RTO and cleanup. | Isolated Test Target, AES-256 backup archive | Lead / DevOps | `tests/Deployment/RestoreRehearsalTest.php` *(NEW PROPOSED)* | **NOT RUN (P10 Planning)**<br>*(Open Readiness Gap)* |
| **OPS-02** | Safe pinned deploy/rollback and backup protection | Deploy script executed with missing `public/build/manifest.json`; backup creation interrupted. | Script fails closed before maintenance mode; application remains online; backup permissions 0700/0600. | `bin/deploy.sh` | Lead / Deployment | `tests/Deployment/DeployScriptIntegrityTest.php` *(NEW PROPOSED)* | **NOT RUN (P10 Planning)** |
| **OPS-03** | Supportable logs/monitoring/cron/password recovery | Daily application log inspected after test runs; verify scheduled tasks execution and error traps. | Zero new unhandled errors in the measured window; no secrets/PII/ciphertext leaks; actual scheduled-job requirement/cron disposition recorded; approved password-reset delivery/recovery demonstrated or explicit P1 blocker. | `storage/logs/`, `routes/console.php` | Lead / DevOps | `tests/Deployment/OperationalMonitoringTest.php` *(NEW PROPOSED)* | **NOT RUN (P10 Planning)** |
| **PERF-01** | Measured bounded resource use on shared hosting | Large report query (10,000 rows) or statement generated under synthetic M/L fixture profiles. | Query memory remains bounded; EXPLAIN plans show index utilization; measured resource growth and safe refusal; host limits and targets recorded separately. | `ReportRegistry`, `CsvReportWriter`, MariaDB | Owner, Manager | `tests/Feature/Phase10/WorkloadBenchmarkTest.php` *(NEW PROPOSED)* | **NOT RUN (P10 Planning)** |
| **QA-01** | Final integrated test policy | Verify test execution discipline: package tests run via disposable runner; single guarded final broad pass. | Zero persistent DB mutations; test budget respected; Pint and PHPStan clean with zero errors. | `tests/Support/run-phase8-disposable.php` | Lead (Codex) | Integrated QA Suite Run *(Proposed)* | **NOT RUN (P10 Planning)** |
| **REL-01** | Owner-authorized merge/deploy and verified live result | Verify release pipeline: exact SHA pinned; pre-merge clean tree; read-only post-deploy verification. | Pull request merged with exact tree; deployment exits 0; read-only smoke passes without data mutation. | Hostinger Deployment Pipeline | Product Owner, Lead (Codex) | Post-Deploy Verification Suite *(Proposed)* | **NOT RUN (P10 Planning)** |

---

## 3. Golden Business Journeys Matrix (J01 – J20)

All 20 golden journeys must be executed in **disposable MariaDB schemas** across ILS, USD, and JOD, tax/discount, Unit conversion ratios (piece/carton), and at least two distinct user roles. Use an owned synthetic trading Company and a foreign control Company. Include a connected story: purchase 10 cartons, allocate transport before posting per the landed-cost policy, sell 3 cartons, return 1 carton, settle balances, then verify stock/value, AR/AP, ledger and as-of reports. Capture expected exact decimal amounts, snapshots, sequence deltas and non-target Company fingerprints at each step; never create fixture Customers, transactions, roles, shares or inventory on production. Existing tests listed below are reuse candidates, not proof that the complete journey has already passed:

| Journey ID | Business Scenario Description | Expected Canonical Invariants | Existing Test Files Available for Reuse | Status |
|---|---|---|---|---|
| **J01** | **Customer / Product / Warehouse Setup** | Same Company; approved defaults; valid Units; zero ledger writes during master setup. | `tests/Feature/Phase3/UnitConversionTest.php`<br>`tests/Feature/Phase4/CustomerManagementTest.php` | **NOT RUN (P10 Planning)** |
| **J02** | **Opening Stock with Lot & Expiry** | Canonical movement and valuation; warehouse/lot tracking; accurate book vs available quantity. | `tests/Feature/Phase3/MovingAverageCostingTest.php`<br>`tests/Feature/Phase3/FefoAllocationTest.php` | **NOT RUN (P10 Planning)** |
| **J03** | **Quotation → Invoice (Edited Price)** | Draft vs posted immutability; tax/discount snapshots; document sequence incremented once. | `tests/Feature/Phase4/QuotationLifecycleTest.php`<br>`tests/Feature/Phase4/QuotationProvenanceCorrectionTest.php` | **NOT RUN (P10 Planning)** |
| **J04** | **Partial Payment → Later Allocation** | Outstanding receivable tracked; original vs subsequent receipt allocation separated; no double posting. | `tests/Feature/Phase4/CustomerPaymentAndFxTest.php`<br>`tests/Feature/Phase4/LaterCustomerCreditTest.php` | **NOT RUN (P10 Planning)** |
| **J05** | **Sales Return / Void** | Links to original invoice; exact stock/cost reversal; historical FX preserved; refund/credit correct. | `tests/Feature/Phase4/SalesReturnTest.php` | **NOT RUN (P10 Planning)** |
| **J06** | **Purchase → Inventory Receipt** | AP/stock/COGS basis; vendor foreign currency; taxes/discounts recorded; FEFO expiry lot created. | `tests/Feature/Phase5C/PurchasePostingTest.php` | **NOT RUN (P10 Planning)** |
| **J07** | **Draft Purchase + Landed Cost → Post** | Allocate a posted, unreversed freight expense to Draft Purchase before posting; freeze exact line allocations/capitalization; preserve commercial Vendor AP; no retroactive posted-Purchase revaluation. | `tests/Feature/Phase7/LandedCostTest.php` | **NOT RUN (P10 Planning)** |
| **J08** | **Purchase Return & Vendor Payment** | Vendor balance updated; correct debit note / refund; original allocation preserved; check settlement base. | `tests/Feature/Phase5D/PurchaseReturnAtomicityTest.php`<br>`tests/Feature/Phase5E/VendorPaymentAtomicBoundaryTest.php`<br>`tests/Feature/Phase5E/VendorPaymentReversalTest.php` | **NOT RUN (P10 Planning)** |
| **J09** | **Cash ↔ Bank Transfer & FX** | Dual-currency legs; realized gain/loss posting; debits equal credits in base currency; no float drift. | `tests/Feature/Phase6/MoneyTransfersTest.php` | **NOT RUN (P10 Planning)** |
| **J10** | **Check Lifecycle (In & Out)** | Issue/receive → deposit → clear / return / void; accounting timing correct; zero duplicate clearing. | `tests/Feature/Phase6/CheckLifecycleTest.php` | **NOT RUN (P10 Planning)** |
| **J11** | **Operating Expense vs Landed Cost** | Proper expense account routing; distinct from inventory capitalization; private receipt attachment. | `tests/Feature/Phase7/ExpenseTest.php` | **NOT RUN (P10 Planning)** |
| **J12** | **Employee Advance, Salary & Pay** | Restricted payroll visibility; advance payment and salary entry lifecycle per payroll-lite contract. | `tests/Feature/Phase7/PayrollTest.php`<br>`tests/Feature/Phase7/EmployeeAdvanceTest.php` | **NOT RUN (P10 Planning)** |
| **J13** | **Reports Dashboard & Exports** | Totals match ledger exactly; filters/date ranges respected; zero cross-company data in CSV export. | `tests/Feature/Phase8/IntegratedReportTruthTest.php` | **NOT RUN (P10 Planning)** |
| **J14** | **Sales/Purchasing PDF & Statements** | Rendered amounts match source snapshots; sensitive costs redacted for unauthorized roles; RTL/LTR. | `tests/Feature/Phase9/PurchasingDocumentsTest.php`<br>`tests/Feature/Phase9/DocumentPresentationTest.php` | **NOT RUN (P10 Planning)** |
| **J15** | **Public Share / Catalog / QR** | Password/first-view gating; prices OFF; terminal Revoke retires token; new link issues 40-char token. | `tests/Feature/Phase9/PublicCatalogTest.php`<br>`tests/Feature/Phase9/CatalogLifecycleExpiryTest.php` | **NOT RUN (P10 Planning)** |
| **J16** | **Foreign Company & Role Downgrade** | Cross-company request rejected with 403/404; mid-session role downgrade denies write without partial post. | `tests/Feature/Phase2/Phase2CompanyIsolationTest.php`<br>`tests/Feature/Phase10/TenantRoleDowngradeTest.php` *(NEW PROPOSED)* | **NOT RUN (P10 Planning)** |
| **J17** | **Identical Double-Submit & Replay** | Identical payload converges to single record; altered payload with same key fails cleanly per transport contract. | `tests/Feature/Phase2/PostingIdempotencyPayloadTest.php` | **NOT RUN (P10 Planning)** |
| **J18** | **Injected Failure Mid-Post** | Exception injected before final commit causes atomic rollback of document, stock, ledger, and numbers. | `tests/Feature/Phase2/PostingArchitectureBoundaryTest.php`<br>`tests/Feature/Phase5D/PurchaseReturnAtomicityTest.php`<br>`tests/Feature/Phase5E/VendorPaymentAtomicBoundaryTest.php` | **NOT RUN (P10 Planning)** |
| **J19** | **Concurrent Sale of Low Stock** | Two concurrent sessions attempt to sell remaining stock; one succeeds, second fails cleanly; stock ≥ 0. | `tests/Feature/Phase3/NegativeStockPreventionTest.php`<br>`tests/Feature/Phase10/ConcurrentStockRaceTest.php` *(NEW PROPOSED)* | **NOT RUN (P10 Planning)** |
| **J20** | **As-Of Date Boundaries & Timezone** | End-of-month and cross-midnight postings reconcile accurately in company-local timezone without off-by-one. | `tests/Feature/Phase8/ReportPeriodTest.php`<br>`tests/Feature/Phase8/HistoricalPositionBatchTest.php`<br>`tests/Feature/Phase9/ShareExpiryTest.php` | **NOT RUN (P10 Planning)** |

---

## 4. Runnable Existing Commands via Disposable Runner

### 4.1 Invocation Contract
All tests must execute against isolated disposable MariaDB schemas via `tests/Support/run-phase8-disposable.php`. Bare `php artisan test` or global PHPUnit calls against persistent databases are strictly prohibited.

```bash
# Required environment variables:
export APP_ENV=testing
export PHASE8_ALLOW_DISPOSABLE_DB=1
export PHASE8_TEST_DB_HOST=127.0.0.1
export PHASE8_TEST_DB_PORT=3306
export PHASE8_TEST_DB_USERNAME=root
export PHASE8_TEST_DB_PASSWORD=""
```

### 4.2 Verified Runnable Commands (Existing Files Only)
Each command targets exactly one existing, verified test file in the repository:

```bash
# Auth & Sessions (Package P10-A1)
php tests/Support/run-phase8-disposable.php -- tests/Feature/Auth/AuthenticationTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Auth/PasswordResetTest.php

# Tenancy & Role Isolation (Package P10-A2)
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase2/Phase2CompanyIsolationTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase9/DocumentSettingsTest.php

# Public Shares & Catalogs (Package P10-A3)
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase9/FinancialSharesTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase9/PublicCatalogTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase9/CatalogLifecycleExpiryTest.php

# Canonical Postings & Scenarios (Package P10-B1)
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase2/PostingArchitectureBoundaryTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase2/PostingIdempotencyPayloadTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase4/QuotationLifecycleTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase4/SalesReturnTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase5C/PurchasePostingTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase5D/PurchaseReturnAtomicityTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase5E/VendorPaymentAtomicBoundaryTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase6/MoneyTransfersTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase6/CheckLifecycleTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase7/ExpenseTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase7/PayrollTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase8/IntegratedReportTruthTest.php

# Print & PDF Verification (Package P10-C2)
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase9/PurchasingDocumentsTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase9/DocumentPresentationTest.php
```

### 4.3 Proposed Test Suites (Not Yet Created — To Be Authored During Implementation)
- `tests/Feature/Phase10/TenantIsolationSecurityTest.php` (SEC-01)
- `tests/Feature/Phase10/UploadSecurityTest.php` (SEC-06)
- `tests/Feature/Phase10/EnvironmentSecurityTest.php` (SEC-07)
- `tests/Feature/Phase10/ResponsiveLayoutAuditTest.php` (UX-01)
- `tests/Feature/Phase10/AccessibilityAuditTest.php` (UX-02)
- `tests/Feature/Phase10/WorkloadBenchmarkTest.php` (PERF-01)
- `tests/Deployment/RestoreRehearsalTest.php` (OPS-01)
- `tests/Deployment/DeployScriptIntegrityTest.php` (OPS-02)
- `tests/Deployment/OperationalMonitoringTest.php` (OPS-03)

## P10-0 checks actually run by Codex

The planning baseline was independently refreshed at 2026-10-10 08:24:31 UTC: exact release/tree and clean state, 28 deployed AR/EN kernel renders, 32 HTTPS probes, eight file-hash comparisons and six read-only reconciliations in before/after snapshots. Only cache/session fingerprints changed; no economic/grant/sequence mutation or new daily-log errors. See [the production baseline](PHASE_10_PRODUCTION_BASELINE.md) for limits.

These P10-0 checks are not implementation acceptance of SEC/ECO/UX/OPS/PERF requirements or execution of J01–J20. PHPUnit tests/assertions: **0 / 0**. Pint, PHPStan, dependency audits, frontend build, migration round-trip, browser interaction matrix, real mail delivery and restore rehearsal: **NOT RUN** for this documentation-only preparation.

## Architect-review coverage and evidence boundaries

| Contract topic | Review location and required proof | Current disposition |
|---|---|---|
| Exact source/production release | Risk register header; production baseline sections 1–7 and timestamped appendix | Anchors verified; no fresh publication probes |
| Risk severity, likelihood, accountable owner and classification | Risk register sections 2, 4 and 5 | Evidence-based qualitative assessments; no claimed incident rates |
| Restore and independent off-host disaster recovery | D1, OPS-01 and baseline recovery boundaries | P1 readiness work; not performed |
| Backup/application encryption-key safeguards | D1; separate custody, trusted digests, isolated application decryption | On-host retention carried; independent recovery unverified |
| Authentication, authorization, tenant isolation | A1/A2, SEC-01–03 and J16 | Planned focused negative/role tests |
| Financial, inventory, FX and reporting truth | B1, ECO-01–04 and J01–20 | Canonical/exact/snapshot architecture retained; rich P10 journeys not run |
| Realistic end-to-end business scenarios | Connected carton/purchase/sale/return/settlement story above; B1 owned fixtures | Isolated MariaDB only |
| Mail/password-reset readiness | A1/D1, SEC-03 and OPS-03 | Delivery remains unverified; no live mail authorized |
| Host PHP/DB/memory/PDF/concurrency measurements | Baseline configuration; E1 measured S/M/L profiles | Configuration verified; capacity proof pending |
| Arabic/English accessibility and responsive UX | C1/C2, UX-01–03: all route families/states, 390/768/1440px, keyboard/focus, errors, contrast, screen-reader labels and actual PDFs | WCAG 2.2 AA target; scoped evidence required, no blanket certification |
| Security and performance remediation | A1–A5/E1, hypotheses reproduced before repairs | Bounded packages prepared |
| Focused QA without repetitive full suites | Package envelope; QA-01 and P10-F | Focused gates; one justified guarded broad integration pass |
| Acceptance and release authority | Package acceptance/release gates; REL-01 | Architect contract review first; implementation/merge/deploy separately gated |
| Bounded Codex/AGY delegation | Package sections 1.1–1.3 | Exact SHA/file ownership/budgets required before dispatch |
| All Product Owner files | Package architecture/preservation rules; discovery hash record | Preserved; excluded from staging |
| Phase 11–13 boundaries | Package section 1.4 | APIs, AI and mobile deferred; one backend |

Accessibility acceptance must identify pages/states, locales, devices, assistive checks, results and unresolved failures, with independent sign-off. Automated HTML/PHP checks or carried Phase 9 screenshots alone do not certify Phase 10 accessibility. Test existing contrast, focus, semantic labels, error announcements and keyboard behavior before choosing minimal repairs; preserve server-side data restrictions in every response.

## Documentation publication scope

This publication is documentation only, based on discovery source commit `47bd392ff4e2adb268805bfd7339805243c677c1` and production/main commit `8d8428261cd2a10690ab77a5c7271e46b5ff5217`. The publication branch preserves the Phase 9 documentation commit and ancestry. Only the four P10-0 planning documents are added by the new commit; its PR against main also carries the four unchanged Phase 9 documentation changes from the still-open acceptance PR. The original proposed specification and all Owner artifacts remain untouched. No runtime, migration, test or lockfile changes, production probes, broad QA, merge or deployment are part of publication. Implementation packages remain **PREPARED, NOT DISPATCHED** pending explicit authorization.
