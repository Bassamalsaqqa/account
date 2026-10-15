# Mobile-First Release A: independent review handback

Release A is a source-review candidate. It has not been merged or deployed. Production/main remains `f9ef4bd5d179ae524c697df7cb839aa94857b5f1`.

Implementation source: **`511f17b4dc7f229d5fb19877a6095c4799e085d6`**. Its direct parent is the production baseline above. The following evidence/documentation commit contains no application changes; the PR head is the delivery SHA, available in the PR and Git history. Review the full baseline-to-head diff.

## Delivered behavior

- **A1, camera:** one reusable Alpine component on Invoice, Quotation, Purchase and Product Catalog. Explicit camera activation, local native `BarcodeDetector` when suitable and locally bundled lazy ZXing fallback. Continuous lookup through the existing barcode/unit/document paths; adjacent-frame suppression, absence re-arming and deliberate Scan Again. Bounded per-document FIFO also includes hardware input, quantity operations and save, preventing an accepted scan from being overtaken by Save. Feedback is opt-in and follows server success. Permission denial, unsupported/missing camera, offline, slow server and failed lookup have localized states. Close, backgrounding, pagehide, navigation, teardown and late permission/decode completion stop/release tracks. Camera video is never uploaded or persisted; arbitrary QR text is only a bounded lookup string.
- **A2, Omnibox:** mobile/header entry and Ctrl/Cmd+K; focus-trapped modal, Escape, arrow/Enter navigation, announcements, 250 ms debounce and current-query result selection. Dedicated read service searches Customers, Vendors, Products (name/SKU/exact barcode), Invoices, Quotations, Purchases and Checks, at most five each. Fresh authenticated actor, active company/membership and category permissions; outgoing financial restrictions reuse `Phase7FinancialRead`. Only public ID, safe identity/reference, status and existing authorized URL leave the server. No costs, balances or amounts are selected. Failed searches are sanitized without breaking the shell; no arbitrary redirect action is exposed.
- **A3, mobile interaction:** five-slot Home / Sales / Scan / Money / Menu navigation, with permission-aware alternatives and a Scan destination sheet; desktop sidebar retained. RTL/LTR, safe-area bottom spacing, drawer focus and visual-viewport keyboard handling. Exact server-side unit-aware quantity buttons, numeric keypads, sticky total/Scan/Add/Save draft controls, serialized form operations and duplicate-save guards. Existing customer/vendor/invoice cards gain permission-gated transaction/view links using supported prefill contracts. Posting review/confirmation remains canonical.
- **A4, sharing:** eligible published catalogs can share their existing issued publication URL through WhatsApp/native/copy controls. The message is generic and contains no editable draft title, passwords or amounts. No grant is issued or renewed by the new read service. Inactive, expired, revoked, unpublished, foreign-company and unauthorized publications do not expose a share URL. Existing financial sharing remains under `FinancialShareManager` / `PublicShareService`; opening WhatsApp is explicitly a handoff, not evidence of delivery.

## Boundaries and implementation decisions

No migrations, new tables, posting-engine changes, stock-valuation changes, check-lifecycle changes or financial-history updates. The existing `party_creation_requests` and `product_creation_requests` migrations remain unchanged; this release does not undo prior accepted migrations. Product creation/selection atomicity remains under the previously accepted boundary and its regression tests pass.

`Permissions-Policy` changes only camera on authenticated catalog/document create/edit routes to `camera=(self)`. Other routes retain `camera=()`; existing stricter policies and all other security headers remain intact. Authenticated business links use ordinary full-document navigation, so the destination camera policy is applied. Do not later convert these links to partial navigation without reviewing response-header behavior.

Only `@zxing/browser` 0.2.1 (MIT) is added as a direct runtime dependency; its lockfile brings `@zxing/library` 0.23.0 (Apache-2.0) and `ts-custom-error` 3.3.1 (MIT). ZXing is dynamically imported when a fallback is needed. No CDN, Redis, search server, WebSocket, permanent Node process or hosting architecture change. Infix name/reference search is bounded to five rows per category with selected columns and existing indexes/relations; very large tenant datasets may warrant separate measured query tuning, rather than speculative schema changes.

Fractional steppers use the selected unit's smallest configured quantum (up to six decimal places); whole units step by one. Exact decimal strings and authoritative PHP recalculation remain the financial source. Empty/malformed line input produces validation feedback instead of crashing the sales preview calculator. Desktop fields retain their existing interaction.

## Verification actually run

See [machine-readable verification](review/mobile-release-a/verification.json) and [preservation receipt](review/mobile-release-a/preservation.json).

| Check | Result |
| --- | --- |
| Disposable MariaDB: MobileRelease, accepted Quick Product atomicity, Product Selection, security headers | **37 tests / 253 assertions passed** |
| Final GlobalSearchTest after error-state correction; overlaps the preceding run | **10 tests / 36 assertions passed** |
| Selected existing financial-sharing compatibility across all five subjects, grant lifecycle, statements/receipts/quotations | **7 tests / 274 assertions passed** |
| Node: camera lifecycle/races/repeat suppression, mixed document queue/hardware input and existing sharing controls | **20 tests passed** |
| Changed PHP Pint; changed application PHP PHPStan with repository configuration | **Passed** |
| Vite production build | **Passed** |
| Runtime npm audit (`--omit=dev`) | **0 reported vulnerabilities** |
| Changed-UI Impeccable detector | **0 findings** |
| Accounting, Inventory, Sales, Payables, Money and Phase7 synthetic reconciliations | **All six healthy before and after browser verification** |
| Posting, stock movements, balances/cost states, lots/lot balances, numbering fingerprints | **Unchanged in final synthetic preview** |

Commands: owned `tests/Support/run-phase8-disposable.php` runner; `node --test tests/Frontend/camera-scanner.test.mjs tests/Frontend/document-operations.test.mjs tests/Frontend/financial-share-controls.test.mjs`; scoped `vendor/bin/pint --test`; scoped `vendor/bin/phpstan analyse --memory-limit=512M`; `npm run build`; `npm audit --omit=dev`. Broader `composer qa`/full suite and GitHub Actions were deliberately not run. Formatting-only changes followed the functional runs; final browser verification covered the delivered UI.

The focused fixtures cover quantity precision, inactive/mismatched units, barcode unit selection, refused scans, fresh membership/company/permission changes, tenant isolation, bounded categories, restricted outgoing checks, no sensitive search fields, no search financial effects, catalog publication boundaries, and accepted Quick Product selection failure/rollback compatibility. A synthetic query exception verifies safe search error rendering. The tests use MariaDB behavior rather than SQLite approximations.

### Browser evidence and honest provenance

- [Initial browser receipt](review/mobile-release-a/receipt.json): **62 checks / 32 screenshots**, AR/EN, widths **320 / 390 / 768 / 1440**, all three entry forms and Product/Customer/Vendor/Invoice indexes, search keyboard, synthetic native camera lookup, permission denial and hardware scan after camera/quantity change. Desktop Edge, simulated viewports, fake video device and stubbed native decoder.
- [Transaction/focus receipt](review/mobile-release-a/interactions.json): **6 checks**; each form sends exactly one Save Draft for two immediate save shortcuts, drawer focus/Escape, Scan destination modal and offline camera behavior. Real local Livewire requests; synthetic draft data only.
- [Fallback/PWA receipt](review/mobile-release-a/fallback-pwa.json): **6 checks**, actual locally bundled ZXing decoding a generated QR on synthetic canvas video in all four workflows, repeated-code suppression, close/navigation track release, service-worker registration and no private page cache while offline.
- [Online console smoke receipt](review/mobile-release-a/console-smoke.json): **5 checks** on the final source covering all four entry/catalog pages and a live Omnibox request; **zero console errors and zero page runtime errors**.
- [Final UI receipt](review/mobile-release-a/final-visual.json): **10 checks / 8 retained screenshots**, including toolbar camera without text-field keyboard activation and translated search Clear behavior. No collected page runtime errors in these successful browser runs. Screenshots below use invented disposable fixture data, not production records.

These are **headless desktop Edge tests**, not real iPhone Safari or Android Chrome tests. No external physical-device evidence was available. Native Android detection was exercised through a stub; real bundled ZXing was exercised on desktop synthetic video. Before release acceptance, verify actual device permission prompts, rear-camera focus/decoding, rotation/background/resume, interrupted network, repeat scans, keyboard/safe areas and installed PWA behavior on iOS Safari and Android Chrome. iOS vibration may be unsupported and browser audio autoplay policies may suppress an optional tone; visual success remains authoritative. An offline first visit cannot download an uncached lazy decoder; the application remains online-only for financial work.

### Retained screenshots

| AR / RTL | EN / LTR |
| --- | --- |
| [Phone Purchase](review/mobile-release-a/ar-phone-purchase.png) | [Phone Purchase](review/mobile-release-a/en-phone-purchase.png) |
| [Phone Product Catalog](review/mobile-release-a/ar-phone-products.png) | [Phone Product Catalog](review/mobile-release-a/en-phone-products.png) |
| [Phone Omnibox](review/mobile-release-a/ar-phone-search.png) | [Phone Omnibox](review/mobile-release-a/en-phone-search.png) |
| [Desktop Invoice](review/mobile-release-a/ar-desktop-invoice.png) | [Desktop Invoice](review/mobile-release-a/en-desktop-invoice.png) |

## Review and release readiness

AGY implemented isolated camera/search work and their correction rounds. The forms worker produced partial changes before timing out; Codex finished and integrated them. Codex inspected the actual source, corrected integration defects (quotation schema field, stale authorization/redirect surface, duplicate event bindings, mixed scan/save ordering, malformed preview input and overlapping mobile controls) and ran the independent checks above. Two final source-only AGY review attempts timed out without a verdict; **no independent AGY acceptance is claimed**. The Product Owner's external ChatGPT architectural/source review remains required.

Build output: app CSS 111.82 kB / 19.16 kB gzip, initial app JS 75.30 kB / 27.07 kB gzip, separate lazy ZXing chunk 454.37 kB / 117.45 kB gzip. Deploy with the normal supported PHP/Composer environment and locally built `public/build` assets/manifest. Retain old hashed assets during transition for open tabs; do not introduce a Node hosting requirement. Files remain 0644 and directories 0755 under the existing release procedure.

No migration round-trip is required for this diff. After independent source acceptance, real-device evidence and separate explicit merge/deployment authorization, use the existing Hostinger controlled release guide: verify reviewed SHA and production drift, take the normal recoverable release backup, publish matching source/assets atomically, clear/rebuild supported Laravel caches, check application availability and authenticated AR/EN/API routes, compare preservation evidence and run all six production reconciliations. Rollback is the accepted baseline source/assets and cache refresh; no Release A schema/data rollback is needed. Never reverse prior production migrations or financial records to roll back this UI release. This is readiness guidance, **not an executed production release**.

Outstanding release gates: independent source acceptance, physical iOS/Android validation and separate Owner merge/deployment authorization. No known failing focused source check remains.

## Separate future and operational work

[Check endorsement and Owner equity consultation](MOBILE_RELEASE_A_FUTURE_FINANCIAL_CONSULTATION.md) is architecture/policy preparation only. It implements neither feature and records the decisions needed for a separately accepted canonical phase.

**Off-host backup recovery, isolated restoration and real password-reset email verification remain outstanding.** Disposable fixture tests do not complete any of them.

## Local artifact accounting

The integration worktree is `.ai/worktrees/mobile-release-a`; camera/search/forms worker worktrees and `.ai/mobile-release-a` briefs/results are deliberately retained as ignored local review artifacts. Integration `.ai/` contains synthetic browser scripts, local fixture helpers, raw logs and receipts; `vendor`, `node_modules` and `public/build` are ignored dependency/build artifacts. No `.env`, credentials, backups, database dump or real business records are committed or sent in the source-only review payloads.

Successful final test/preview schemas were removed by their owning runners. Two earlier interrupted local disposable schemas, `accounting_p8_tmp_7eb4c374346c` and `accounting_p8_tmp_6b99151d14e9`, are quarantined rather than adopted/reset by the strict fixture helper; they are not production. Their old local receipts are excluded from accepted verification evidence. Root main's pre-existing untracked Owner documents and `.playwright-mcp` directory remain untouched. No merge, deployment or production access occurred for Release A.
