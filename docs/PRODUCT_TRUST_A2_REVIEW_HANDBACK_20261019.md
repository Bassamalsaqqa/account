# Product Trust & Settings A.2 — review handback

Baseline and parent: `d27adbad93fce8bd7aaf8afa6cdfaa0e33b91bde`. Branch: `feature/product-trust-a2-20261019`. The PR metadata records the exact resulting head. GitHub main was reverified at the baseline. Production is the Owner-reported baseline; this source assignment did not access production, Hostinger, merge or deploy.

## Delivered behavior

- Existing Profile now exposes Fortify enrollment, QR/manual setup, TOTP confirmation, recovery-code viewing/regeneration and disablement. Existing profile/password/account protections remain. Secret responses stay outside Livewire state and are cleared on backgrounding, navigation, teardown and late-response cancellation. Uncertain security mutations require status reload instead of blind retry. TOTP/recovery values are excluded from validation flash data.
- Company preferences, personal confirmed enrollment and the absence of mandatory enforcement are stated separately. No mandatory enrollment or login-contract change was introduced.
- Settings links to existing authorized configuration screens and Profile. The existing Audit viewer has 20-event pagination, bounded date/event-prefix filters, fresh authorization, no private before/after payloads and readable mobile cards. Invalid date ranges no longer produce a render failure.
- Verified active Owners can deliberately download the fixed V1 ZIP through a separate CSRF-protected POST, existing Fortify password confirmation and rate limits. Fresh persisted membership, company status, password and stored-session checks precede generation and delivery. Non-Owner Admins are denied. Client-only cookie sessions fail closed.
- Forty explicit CSV datasets plus a manifest cover the supported business reference inventory. Balances use canonical currency-separated projections; stock uses immutable movement totals; GL/Trial Balance retain exact signed numbers. Payroll datasets/attachments/secrets are excluded; payroll and reversal descriptions are conservatively redacted. The ZIP is not a restorable backup.

See [export contract and full inventory](COMPANY_DATA_EXPORT_V1_CONTRACT.md), [sanitized manifest example](examples/company-data-manifest.example.json), and [synthetic verification evidence](evidence/A2_SYNTHETIC_VERIFICATION_20261019.json).

## Independent corrections to worker drafts

AGY implemented bounded security/export work; its initial claims were not treated as acceptance. Codex inspected and corrected late secret responses, reauthentication cancellation, invalid Alpine/Blade bindings, stale-company Settings access, invalid Audit date rendering, explicit export fields/archived references, session-revocation fail-open paths, the recursive SQL-deadline hook, conservative payroll-reversal redaction, resource/error/cleanup behavior and mobile Audit readability. A separate source reviewer inspected frontend authorization/secret lifetime; its two small usability findings were corrected.

One AGY default-configuration test attempt was interrupted and excluded from accepted evidence. All accepted database runs below used newly created, explicitly owned disposable schemas on local MariaDB port 33312. No production credentials or records were supplied to AGY. Old abandoned browser-fixture cleanup required checking its exact recorded synthetic identity and absence of business events before deletion. Active verification schemas and browser sessions were cleaned.

## Actual verification

Final combined A.2-only MariaDB acceptance: **53 tests, 1,934 assertions passed**, PHP 8.4.25 / MariaDB 10.4.32, owned schema `accounting_p8_tmp_36ead71bd369`, cleaned. This is not a full-suite result.

Coverage includes actual Fortify enable/confirm/recovery/regenerate/disable/login routes and single-use recovery protection; no-store/secret-flash boundaries; Owner/Admin/revoked membership and session checks; cross-company exclusion; manifest/CSV row agreement; Arabic, quotes, multiline/hostile formulas, leading-zero identifiers and exact signed decimals; payroll posting plus canonical reversal; all generation caps; retained global/company locks; cleanup and sanitized failures. A separate real InnoDB snapshot test verifies consistent reads despite concurrent commits, rejects writes, interrupts slow SQL and restores connection state across repeated exports.

- JavaScript: **12 tests passed** for late responses, lifecycle cleanup, Fortify reauthentication/cancellation, uncertain mutation outcomes, duplicate download clicks, truncation/revocation, byte bounds and teardown.
- Scoped Pint: passed. Scoped PHPStan for all new export application classes/controller and Settings: **no errors**.
- Repository-wide PHPStan was attempted after aligning the isolated vendor directory to the unchanged lockfile. It reports one warning in **unchanged** `app/Livewire/Concerns/AdjustsDocumentQuantity.php:221` (`is_string` is always true). No suppression or unrelated refactor was added. Therefore the full `composer qa` aggregate is **not** represented as passed; its broad test leg was deliberately not run.
- Vite production build: **310 modules passed**. Assets: `app-YMISaSSE.css`, `app-D9zciqzJ.js`, lazy ZXing `index-B2ZkNDM-.js`, valid manifest. No dependencies or migrations changed; local PHP ext-zip/pdo_mysql verified.
- Canonically posted synthetic invoice: **Accounting, Inventory, Sales, Payables, Money and Phase7 all healthy before/after export**. Six economic-table fingerprints identical. Separate browser-fixture fingerprints and all six reconciliations also remained healthy; that second fixture was an empty foundation, not a trading-load claim.

### Local export measurements

| Fixture | Generation + delivery | SQL including guards | ZIP | Records / datasets | Whole PHP test-process peak |
|---|---:|---:|---:|---:|---:|
| Foundation + canonical ILS 100 service invoice | 112.66 ms | 114 | 21,359 bytes | 124 / 40 | 82 MiB |
| Foundation + 1,000 synthetic customers | 148.07 ms | 128 | 48,877 bytes | 2,117 / 40 | 84 MiB |

These measurements are local synthetic evidence, not Hostinger timing, maximum-capacity proof or representative live commercial load. The generation ceilings are 30 seconds / 50,000 total rows / 50 MiB uncompressed including manifest / 50 MiB ZIP / 128 MiB PHP memory; global preparation/delivery concurrency is one.

## Browser evidence and provenance

Edge desktop simulation on an isolated loopback build, synthetic Owner and Sales accounts; **no A.2 physical-device evidence**. AR/EN were inspected at 390px, 768px and 1440px. Profile remains accessible to Sales without company-settings access; restricted Settings returns 403. Backend tests also exercise Warehouse and Cashier Profile access. Audit keyboard activation, filtering/empty state, RTL/LTR and export reauthentication/download feedback were checked. No claim of a live screen-reader or physical authenticator-app exercise is made.

The actual browser-generated ZIP supplied by the Owner was inspected: **19,859 bytes, 40 CSVs + manifest, 101 synthetic-foundation records, complete/nontruncated, ZIP integrity passed**. Download initiation is not a guarantee of device persistence; this file provides actual local download evidence. Its contents are not committed.

Screenshots (synthetic only):

| Surface | Arabic | English |
|---|---|---|
| Phone Settings / account | [Settings](images/refinement-a2/settings-ar-390.png), [export feedback](images/refinement-a2/export-ar-390.png), [Audit cards](images/refinement-a2/audit-ar-390.png), [Profile](images/refinement-a2/profile-ar-390.png) | [restricted Sales Profile](images/refinement-a2/profile-sales-en-390.png) |
| Tablet | [Settings](images/refinement-a2/settings-ar-768.png) | [Settings](images/refinement-a2/settings-en-768.png), [Profile](images/refinement-a2/profile-en-768.png) |
| Desktop | [Settings](images/refinement-a2/settings-ar-1440.png) | [Settings](images/refinement-a2/settings-en-1440.png), [Profile](images/refinement-a2/profile-en-1440.png) |

Browser automation intermittently timed out during capture/download waiting, while the real download completed. The captured console error concerned an asynchronous browser message channel; no application-bundle runtime error was observed. Do not interpret the automation timeout as a failed export or as physical-device evidence.

## Limits and Owner handback

Await independent source acceptance and explicit Owner merge/release authorization. Runtime release checks must confirm ext-zip, MariaDB statement-timeout support and protected writable storage on the actual host; this assignment did not change hosting. Packages larger than V1 caps fail clearly instead of returning an incomplete ZIP. Dedicated P&L/Balance Sheet presentation CSVs, attachments, payroll details, brands and lot-allocation history remain outside the fixed inventory and are declared in the manifest. Other existing individual report exports remain available. Freely entered business text can contain private information; keep the downloaded ZIP private.

The standalone human UAT sheet is delivered locally at `.ai/a2/deliverables/HUMAN_UAT_A2_PILOT.md`, outside this source PR as requested. Its actual-result fields remain blank; current pilot UAT can continue independently. No client acceptance, commercial-readiness percentage, physical-device pass or operational recovery proof is claimed.

No posting engines, inventory actions, migrations, historical data, company ownership rules or immutable-history policies changed. The candidate worktree is intended to be clean after commit. The separate root checkout's two earlier uncommitted Settings translation drafts and the Owner's untracked documents/screenshots are preserved, outside this PR. Ignored local AGY briefs/results, test measurements, fixture scripts and the separate UAT deliverable remain deliberate local artifacts; no credentials, `.env`, sessions, generated export or database contents are committed.
