# ACCOUNTING — PHASE 10 PRODUCTION HARDENING
## Phase 10 Acceptance Matrix & Golden Scenario Scoreboard

**Document Identity:** `docs/PHASE_10_ACCEPTANCE_MATRIX.md`\
**Phase State:** **P10-0 PLANNING BASELINE — ALL NEW PHASE 10 CHECKS MARKED AS NOT RUN**\
**Date:** 2026-10-10\

Codex discovery/internal review: **COMPLETE**. Independent architect acceptance: **PENDING final correction review**. Runtime packages: **PREPARED, NOT DISPATCHED**. Phase 10 tests: **NOT RUN**. Production hardening: **INCOMPLETE**. Customer readiness: **NOT YET ESTABLISHED**.

**Discovery Baseline Branch:** `docs/phase9-production-acceptance` (`47bd392ff4e2adb268805bfd7339805243c677c1`)\
**Test Runner Contract:** Automated tests must execute via `tests/Support/run-phase8-disposable.php -- <target>` with explicit `APP_ENV=testing`, `PHASE8_ALLOW_DISPOSABLE_DB=1`, and local `PHASE8_TEST_DB_{HOST,PORT,USERNAME,PASSWORD}` against a disposable MariaDB schema. Bare `artisan test` against default persistent databases is strictly prohibited.\
**Carried vs Phase 10 Proof:** Carried Phase 9 production evidence is distinct from Phase 10 verification. Historical source QA does not equal a Phase 10 PASS or blanket WCAG certification. No Phase 10 implementation tests have been run during this planning phase.

---

## 1. Reproducible Source Discovery Inventory

Discovery inventoried source commit `47bd392ff4e2adb268805bfd7339805243c677c1`. Current main `3e9ebf812a463db9d3f18aaf07b10431c63e84f5` has the same tree; the following inventory is retained source evidence, not new Phase 10 test execution:

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

## 2. Requirement Acceptance Scoreboard (20 Owner IDs; self-contained traceability in §5)

Status Definitions:
- `CARRIED HISTORICAL EVIDENCE`: Previously validated in Phase 9 release; carried forward as historical record, not newly rerun.
- `NOT RUN (P10 Planning)`: Proposed Phase 10 verification; execution blocked pending independent architect acceptance and explicit implementation authorization.

| Requirement ID | Specification Clause | Attack / Negative Scenario | Expected Response / Assertion | Actual Route / Component | Roles & Viewports / Locales | Existing or Proposed Test File | Phase 10 Status |
|---|---|---|---|---|---|---|---|
| **SEC-01** | No cross-Company IDOR in protected routes, Livewire methods, exports, public paths | User in Company A requests invoice/payment/statement of Company B via URL parameter or Livewire method payload. | HTTP 404 (model not found within company scope) or HTTP 403; zero data returned. | `invoices/{publicId}`, `pdf/invoice/{publicId}`, Livewire actions | Foreign Tenant, Viewer | `tests/Feature/Phase2/Phase2CompanyIsolationTest.php` *(Existing)*<br>`tests/Feature/Phase10/TenantIsolationSecurityTest.php` *(NEW PROPOSED)* | **NOT RUN (P10 Planning)** |
| **SEC-02** | Owner-only protected Phase 9 defaults/delegation; output authority unchanged | Non-Owner self-grant/cloned protected role; default Viewer Vendor Payment; missing document/vendor-financial intersection; valid cost-limited Purchase reader | No self-escalation. Default Viewer and unauthorized readers: 403/404 with no document bytes. Protected delegation needs current same-company Owner. Explicitly authorized Purchase/Return reader without cost permission gets only the allowed server-redacted projection, never Vendor Payment | `CompanyRoleService`, `PurchasingDocumentController`, `PurchasingDocumentBuilder`, `VendorFinancialRead` | Existing/new Companies; default/custom roles; AR/EN print/PDF | `tests/Feature/Phase9/DocumentSettingsTest.php`, `tests/Feature/Phase9/DocumentPresentationTest.php`, `tests/Feature/Phase9/PurchasingDocumentsTest.php` (existing); A2 focused downgrade/revocation/render checks proposed | **NOT RUN (P10 Planning)** |
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
| **UX-03** | Truthful nine financial outputs and supported barcode labels | AR/EN authenticated print/PDF parity; unauthorized versus authorized cost-limited requests; Customer Statement date/currency/refusal boundaries; all five symbologies | Nine outputs: Quotation, Sales Invoice, Sales Return, Customer Receipt, Customer Statement, Purchase, Purchase Return, Vendor Payment, Vendor Statement. Denied roles get 403/404 without bytes; only authorized Purchase/Return readers get permitted cost-redacted projection. Historical/per-currency opening + activity = closing; >1,000 source-entry refusal; truthful Cash/Bank labels. Code 128/EAN-13/EAN-8/UPC-A/UPC-E evidence per C2, no carried count → P10 PASS | `PdfDocumentController`, `PurchasingDocumentController`, `PdfRendererService`, `BarcodeLabelService` | AR/EN; default/custom authorized roles, foreign Company, downgraded/revoked actor | Existing `tests/Feature/Phase9/DocumentPresentationTest.php`, `tests/Feature/Phase9/PurchasingDocumentsTest.php`, `tests/Feature/Phase9/BarcodeLabelsTest.php`; C2 actual PDF/decoder artifacts proposed | **NOT RUN (P10 Planning)** |
| **OPS-01** | Separate historical upgrade and current isolated disaster recovery | A: restore old 80/61 snapshot then forward-upgrade; B: independently restore current coherent off-host recovery point. Negative `.env`/cached DSN/host-alias/egress/ingress/key cases | D1-C trusted checksum → restricted network-isolated scratch → quarantine archived production `.env`/config caches → verified disposable identity/settings → disable production DB, mail/integrations/jobs/public ingress → verify effective connection/isolation before Artisan/startup → authorized temporary keys → application recovery and secure cleanup. A reaches 83/63; B verifies current manifest/code/files/keys, grants/content, six reconciliations and observed RPO/RTO, cadence/retention/age alerts. Old checkpoint/decryption alone cannot pass B | D1-A/B/C/D; isolated target + independent off-host/key custody | Lead custody/acceptance; separately authorized operator | `tests/Deployment/RestoreRehearsalTest.php` (NEW PROPOSED); separate historical/current application/content/negative/cleanup evidence | **NOT RUN (P10 Planning)**; **P1 readiness blocker** |
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
| **J11** | **Operating Expense vs Landed Cost** | Proper expense routing/classification, frozen category/employee identity; distinct from inventory capitalization; private attachment. | `tests/Feature/Phase7/ExpenseTest.php` | **NOT RUN (P10 Planning)** |
| **J12** | **Employee Advance, Salary & Pay** | Restricted payroll visibility; advance payment and salary entry lifecycle per payroll-lite contract. | `tests/Feature/Phase7/PayrollTest.php`<br>`tests/Feature/Phase7/EmployeeAdvanceTest.php` | **NOT RUN (P10 Planning)** |
| **J13** | **Reports Dashboard & Exports** | Totals match ledger exactly; filters/date ranges respected; zero cross-company data in CSV export. | `tests/Feature/Phase8/IntegratedReportTruthTest.php` | **NOT RUN (P10 Planning)** |
| **J14** | **Nine financial print/PDF outputs + Customer Statement truth** | All nine named in UX-03/C2, AR/EN authenticated print/PDF; rendered snapshots/currency truth, deny unauthorized with zero bytes, separately redact permitted Purchase/Return. Customer Statement historical opening/activity/closing, per-currency totals, from/to/timezone boundaries and >1,000-source refusal. Reuse A3 issued passworded Statement regressions rather than duplicate them | `tests/Feature/Phase9/PurchasingDocumentsTest.php`, `tests/Feature/Phase9/DocumentPresentationTest.php`; A3 `tests/Feature/Phase9/FinancialSharesTest.php` reused | **NOT RUN (P10 Planning)** |
| **J15** | **Public Share / Catalog / QR** | Password/first-view gating; prices OFF; terminal Revoke retires token; new link issues 40-char token. | `tests/Feature/Phase9/PublicCatalogTest.php`<br>`tests/Feature/Phase9/CatalogLifecycleExpiryTest.php` | **NOT RUN (P10 Planning)** |
| **J16** | **Foreign Company & Role Downgrade** | Cross-company request rejected with 403/404; mid-session role downgrade denies write without partial post. | `tests/Feature/Phase2/Phase2CompanyIsolationTest.php`<br>`tests/Feature/Phase10/TenantRoleDowngradeTest.php` *(NEW PROPOSED)* | **NOT RUN (P10 Planning)** |
| **J17** | **Identical Double-Submit & Replay** | Identical payload converges to single record; altered payload with same key fails cleanly per transport contract. | `tests/Feature/Phase2/PostingIdempotencyPayloadTest.php` | **NOT RUN (P10 Planning)** |
| **J18** | **Injected Failure Mid-Post / Mid-Issuance** | Any failure rolls back document, stock, ledger, numbers, allocations/audit and issuance token/content together; no orphan grant or number | `tests/Feature/Phase2/PostingArchitectureBoundaryTest.php`, `tests/Feature/Phase5D/PurchaseReturnAtomicityTest.php`, `tests/Feature/Phase5E/VendorPaymentAtomicBoundaryTest.php`, `tests/Feature/Phase9/FinancialSharesTest.php` | **NOT RUN (P10 Planning)** |
| **J19** | **Concurrent sale/purchase against low stock** | Independent sessions/DB connections preserve explicit negative-stock policy, exact stock/value, locks and FEFO lots; identical and competing events have one canonical outcome without partial stock/ledger effects | `tests/Feature/Phase3/NegativeStockPreventionTest.php`; `tests/Feature/Phase10/ConcurrentStockRaceTest.php` (NEW PROPOSED) | **NOT RUN (P10 Planning)** |
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

## 5. Protected Owner specification traceability

### 5.1 Exact local source and review boundary

The protected original was located and read completely (784 lines) at `C:\Users\bassa\Documents\accounting\docs\PHASE_10_PRODUCTION_HARDENING_FULL_ENGINEERING_SPEC_V1.md` (repo-relative `docs/PHASE_10_PRODUCTION_HARDENING_FULL_ENGINEERING_SPEC_V1.md`). Its header says **Version 1.0**, prepared **2026-10-10**, **PROPOSED PHASE 10 CONTRACT — NOT AN IMPLEMENTATION OR DEPLOYMENT AUTHORIZATION**. Exact UTF-8 bytes: **77,779**; SHA-256: `285f41301b41d7895e1624268289676aab63d75120e67bf7dba74a2640d6e347`. The same hash is verified before/after this correction. It is an Owner-supplied local/untracked original, **not present at the referenced GitHub path**; it is neither published, relocated, replaced nor rewritten. Do not mistake its unavailable GitHub link for a missing local contract or for independent architect access to its bytes.

This sanitized self-contained annex records all 20 Owner requirement IDs from original §13, all 20 journey IDs from §7 B2 and the execution mapping from §§4/15. Controlling clauses below summarize the protected scope; acceptance evidence is **required future proof**, not present compliance. Reviewers can assess the published mapping without accessing secrets or changing original custody. Whole-original independent compliance certification remains unavailable until controlled original access or acceptance of this annex; this correction claims neither.

Priority remains original §3.1: P0 unsafe exposure/corruption stops work; P1 blocks release, P2 needs bounded architect-approved disposition, P3 may be ticketed. Original §11 F5 requires zero open P0/P1 for release. The prominent P1 readiness items are isolated restoration, approved account recovery and independent off-host backup/key recovery. §0.2/§19 exclude APIs/AI/mobile and architecture replacement; §§7/14 preserve exact canonical/isolated economic tests and focused QA. Original §9 D2 requires an **actual** isolated application restore, not decryption alone.

### 5.2 Requirement-to-clause/package/evidence mapping

All rows are **NOT RUN for Phase 10 acceptance**; Codex's completed discovery/source reading is distinct. AGY may implement only an authorized bounded brief; Codex owns acceptance. No substantive scope deviation is approved by this packet.

| Owner ID | Controlling clauses in protected v1.0 | Package/accountable ownership | Required acceptance evidence (not compliance assertion) |
|---|---|---|---|
| SEC-01 | §6 A2/A4, §11 F2 | A2/A4; Codex policy, AGY bounded negatives | Two owned Companies; route/Livewire/export/attachment source map; fresh-member and foreign-ID denial with no response leakage |
| SEC-02 | §6 A2/A3 (C3), §11 F2 | A2/A3; Codex protected RBAC | New/existing Company defaults and protected role/grant/assignment regressions; Owner delegation; denied Vendor Payment versus authorized cost-limited Purchase projection |
| SEC-03 | §6 A1, §9 D4 | A1/D1; Codex account/recovery policy | Real login/logout/2FA/reset/session/CSRF/revocation flows, cookies/config; approved actual delivery or explicit P1 blocker |
| SEC-04 | §6 A3 (C1/C2/C5) | A3; Codex issuance policy | Neutral GET/HEAD, explicit CSRF POST, exact 15-minute grant binding, password/finite expiry, fixed original allocations/ciphertext/hash, missing/wrong-key/retry/revocation refusal |
| SEC-05 | §6 A3 (C4), §11 F1/F2 | A3; Codex publication/token policy | Prices OFF absent from DTO/HTML/JSON/print/PDF; approved fixed media; terminal Revoke/New Link, no resurrection, Company-local catalog expiry |
| SEC-06 | §6 A4 | A4/A3; Codex surface acceptance | Spoofed MIME/path/symlink/asset/header and contextual encoding/SSRF tests; private attachment/webroot isolation |
| SEC-07 | §6 A5/A6, §9 D5 | A5/D1; Codex secrecy/custody | Exact dependency inventory/audit reachability, debug/key/config checks without values, safe log/error/HTTP-private-path evidence |
| ECO-01 | §7 B1–B4, §11 F1 | B1; Codex canonical Actions/writers | J01–J12/J16–J19 source/stock/GL/number/allocation/audit atomicity, reversals and injected failures; six reconciliations; no alternate writer |
| ECO-02 | §7 B1/B2/B4 | B1; Codex exact economic policy | ILS/USD/JOD, exact scales and rounding, FX/tax/discount/Unit snapshots, mutable-master edits and unchanged historical truth |
| ECO-03 | §7 B1/B2 (J17/J19), §14.2 | B1/A3; Codex idempotency | Independent MariaDB connections/processes; same payload converges, altered key payload conflicts, locks/uniqueness and coherent audit |
| ECO-04 | §7 B3, §11 F1/F2 | B1/E1; Codex read-model truth | Representative families across 69 registered reports, period/filter/currency and server-denied sensitive data; canonical/CSV parity |
| UX-01 | §8 C1/C2/C6 | C1; Codex UI acceptance | Actual route/state/task matrix, AR RTL/EN LTR at 390/768/1440px; bbox/local-table-scroll/interaction/error-preservation screenshots |
| UX-02 | §8 C2/C5/C6 | C2/C1; Codex scoped accessibility | Keyboard/Escape/focus restoration, labels/errors/announcements, contrast/zoom/reduced motion; WCAG 2.2 AA practical target, no blanket certificate |
| UX-03 | §8 C3/C4, §11 F2 | C2/A2; Codex disclosure/document truth | All nine financial print/PDF outputs plus separate Catalog projection, actual AR/EN raster/content parity, Customer Statement balances/ranges/limits, denied-vs-redacted roles; five-barcode/Unit actual decoder evidence |
| OPS-01 | §9 D1/D2/D6 | D1; Codex custody/independent proof | Historical 80/61 → 83/63 AND current coherent off-host restore; quarantined secret-bearing .env/cache; isolated application/key/content/six-domain proof, negative cases and cleanup |
| OPS-02 | §9 D3, §12 G1/G2 | D1/G; Codex release, Owner authority | Exact pinned code/build, verified private backup/manifest/bootstrap/locks, forward-only schema and isolated code/build rollback; production restore separately authorized |
| OPS-03 | §9 D1/D4/D5/D6 | D1/A1; Codex operational ownership | Daily/retention/age/failure policy adopted under authority, delivery proof, actual recurring-job need, privacy-safe rotation/logs/monitoring/runbooks |
| PERF-01 | §10 E1–E5 | E1; Codex measurement/optimization | Fixed S/M/L fixtures, actual SQL/EXPLAIN/memory/time/host constraints, PDF/CSV/labels/concurrency refusal/cleanup; local timings cannot claim Hostinger SLA |
| QA-01 | §14.1–14.3, §15 | F with A–E; Codex guarded QA | Focused changed-path gates/checkpoints, one justified guarded final broad pass, exact source/unique vs rerun counts, owned MariaDB and real PDF/browser evidence; no CI without approval |
| REL-01 | §11 F5, §12 G1–G4, §16 | F/G; architect acceptance + Owner merge/deploy | Zero open P0/P1 release acceptance, exact accepted PR/main/live tree and rollback/backup checkpoint; separately authorized release with independent read-only live handoff |

### 5.3 All 20 golden journeys and execution mapping

The detailed §3 table supplies scenario, exact invariants, existing/proposed tests and **NOT RUN** status for each of **J01, J02, J03, J04, J05, J06, J07, J08, J09, J10, J11, J12, J13, J14, J15, J16, J17, J18, J19, J20**. Each maps to original **§7 B2's matching ID** and §7 B1/B3/B4 invariants, under **B1 / Codex canonical acceptance + bounded AGY fixture evidence**. J14 additionally maps to C2/A2 document/role truth, J15 to A3 publication/issued-grant security, J16 to A2, J17/J18/J19 to independent-connection/injected-failure guards, J20 to Company-local/as-of read truth. All use two owned synthetic Companies, multiple roles, exact ILS/USD/JOD values, tax/discount, Unit ratios/lots and before/after source/ledger/stock/sequence/control-Company fingerprints. No production fixtures.

| Original execution package | Published template/owner and proof | Disposition |
|---|---|---|
| P10-0 (§5) | Risk register/baseline/matrix; Codex exact source and historical production evidence | Discovery/internal review complete; architect acceptance pending |
| P10-A (§6) | A1 identity/recovery; A2 tenant/RBAC; A3 public surfaces; A4 uploads/CSV; A5 deps/config; Codex owns policy | Runtime templates prepared; not dispatched |
| P10-B (§7) | B1 J01–J20, six reconciliations, concurrency/failure/snapshot proof; Codex canonical writers | NOT RUN |
| P10-C (§8) | C1 route/state/device/copy; C2 accessibility, nine financial outputs, Catalog and five barcode types; Codex disclosure/acceptance | NOT RUN |
| P10-D (§9) | D1-A historical upgrade, D1-B current DR, D1-C pre-bootstrap isolation, D1-D negatives/cleanup; Codex custody/restore acceptance | NOT RUN / P1 readiness gaps |
| P10-E (§10) | E1 fixed-profile benchmarks/host evidence; Codex correctness-preserving tuning | NOT RUN |
| P10-F (§11/14) | Lead integration; one justified guarded broad pass, business/UX/support/readiness packet; independent architect source gate | NOT RUN |
| P10-G (§12) | Owner separately authorizes merge/deploy; Codex exact pinned release and independent read-only handoff | NOT AUTHORIZED / NOT RUN |

### 5.4 Reconciliations, deviations and unresolved proof

- Original §1 authoring caveat (Phase 9 rollout unverified) is superseded only by the unchanged timestamped Phase 9 production acceptance and prior Codex discovery record; original prose remains historical. PR #19's merge changes documentation main, not the accepted runtime identity or historical evidence timestamps.
- **Scope clarifications, not accounting-policy changes:** draft-only landed cost follows accepted ADR 0007; Vendor Payment denial/intersection versus limited-visibility Purchase/Return follows accepted source/ADR 0009; nine financial outputs/five barcodes and current-vs-historical recovery reflect original §§1.4/8 C4/9 and the finite architect findings. Existing CSV/limiter/media/Statement guards remain protections, not relabelled vulnerabilities.
- Numeric S/M/L sizes, finite checkpoint budgets and proposed daily/7-4-3 backup retention operationalize original §§9/10/14. They are implementation proposals/targets awaiting measured feasibility and authorized adoption, **not approved deviations or deployed facts**. No expired budget grants PASS. Material retention/cost/RPO/RTO or policy conflict must return to Owner/architect; none is silently resolved here.
- The full original's GitHub availability, actual current recovery/independent key access, mailbox delivery, host workload capacity and new P10 role/UX/economic/PDF/decoder execution evidence are unavailable/not run in this correction. Carried source/production records are attributed evidence; no new production compliance or architect acceptance is claimed. There is **no identified unresolved accounting-policy conflict** in this finite correction; any later discovered genuine conflict requires an explicit decision.

## Documentation publication scope

PR #19 is merged at current main `3e9ebf812a463db9d3f18aaf07b10431c63e84f5` (tree `6e4f98739ace0c0890847f33f6bad0de1a4e84e6`). Its tree equals preserved Phase 9 documentation commit `47bd392ff4e2adb268805bfd7339805243c677c1`. Phase 9 production runtime remains the accepted historical release `8d8428261cd2a10690ab77a5c7271e46b5ff5217`; this correction performs no production refresh. PR #20 branch `docs/phase10-p0-contract` preserves `47bd392… → e20c1ea… → documentation correction` by a normal new commit, with no branch reconstruction or history rewrite. A main-to-branch merge is unnecessary because both the effective and merge-base differences already contain exactly the four intended P10-0 documents and the Phase 9 documentation trees are equal.

Only these four P10-0 planning documents are publication files. The protected Owner original, roadmaps, supplied PDFs, existing browser artifacts and ignored evidence remain unchanged/excluded. The accepted Phase 9 production record is untouched. No runtime, test, migration, dependency, lockfile or deployment-script edits; no CI, application tests, browser runs, SMTP probes, backup/key retrieval/extraction, schema operations, production access, merge or deployment.

Codex discovery/internal review: **COMPLETE**. Independent architect acceptance: **PENDING final correction review**. Runtime packages: **PREPARED, NOT DISPATCHED**. Phase 10 tests: **NOT RUN**. Production hardening: **INCOMPLETE**. Customer readiness: **NOT YET ESTABLISHED**.
