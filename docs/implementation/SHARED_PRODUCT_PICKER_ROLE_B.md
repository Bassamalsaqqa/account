# Shared product picker — Role B handoff

Base: PR87 `912aa71f557b314837e70138df22615902fc9d7b`, branch `t3/web-invoice-purchase-workflow`.
Prepared checkout started at `f8a0cf7012c4875bec7320d42e0f8dde58593b52`; a normal independent branch was created directly at the required base.

PRODUCT.md, DESIGN.md, AGENTS.md, UI workflow rules, barcode/unit master-spec sections, blueprint quantity rules and purchasing provenance ADR were consulted. Native Blade/Livewire/Alpine implementation; no new framework, financial core change, migration or permission grant.

## Actual rollout and remaining adapters

- Purchase create and sales invoice create already used the same partial/controller at PR87. Both inherit flushed hardware Enter, stale-response prevention, pending-operation suppression, retry feedback and compact desktop/44px mobile picker sizing.
- Quotations now use that partial/controller, with a thin quote-authorized bounded lookup/add adapter. Existing unit, price and quantity controls remain; row keys are UUIDs. The 50-product per-render menu is removed; render loads selected product IDs only. Suggestions are re-resolved server-side. Quotes no longer render separate barcode/search/product dropdowns.
- Sales returns and purchase returns intentionally select original invoice/purchase lines. A catalog product ID must never substitute for their historical line provenance. Their source-line selectors remain.
- Inventory stock adjustment and stock transfer still use their existing product select/barcode adapters. Catalog composer also retains its existing bounded search. Full analogous-form rollout is NOT complete; these require separately qualified host adapters, not a blind shared-partial replacement.
- Purchase create header already exposes warehouse/currency/due date on the required base. No PurchaseForm PHP, purchase list/detail, party/report, authentication, receipt or financial mobile files changed. No generic input/button component changed.

## Stitch reference

One private synthetic-data project: https://stitch.withgoogle.com/projects/4180170178157455428
Desktop generation includes a mobile 390px reference; standalone mobile generation requested in the same project. API list_screens returned no screen IDs, so no verified deep screen links are claimed. Project authority remains local DESIGN.md: generated colors, sidebar dimensions and expandable economic cards are not imported. Applied patterns are the single camera-inside-field picker, adjacent quick create, compact desktop controls and 44px mobile targets. No generated React or application code imported. These references do not establish running-app acceptance.

## Merge gates and overlaps

PR87 remains the sole dependency. PR93 currency compatibility `1b8a696feda350bbc2df86a26d05b69fd615ba57` was not merged or reimplemented; quotation template and product suggestion callsites need combined currency regression after composition. PR95 remains a separate writer; no auth/receipt/financial-mobile edits. Agent A's PurchaseForm PHP and purchase list/detail, and Agent C's party/report components remain untouched. Shared picker and quotation read/search additions require independent exact-head review. No self-approval.

## Verification limits

Browser preview_status returned available=false: EN/AR desktop/mobile visual workflow, camera permissions/device behavior and paired browser latency NOT VERIFIED. Existing camera scanner implementation retained (permission/unavailable/cancel states); no physical hardware scan claim. No production access, financial activation, deploy, merge, Hostinger access, history rewrite, external agents or cleanup of historical artifacts.

## Completed checks

- Node shipped-controller/document FIFO/camera suites: 45 tests passed (Node does not emit assertion totals). Includes keyboard, stale candidate, Enter/debounce, repeated submission, failed request retry and existing camera permission/error/cancel lifecycle coverage.
- Guarded MariaDB bounded-search suite: 9 tests / 110 assertions, owned schema `accounting_p8_tmp_6e6fa0b23366`, cleaned. Existing 10,000-product benchmark ran. One measured SKU-prefix query used 14 statements, median 91.32ms, p95 950.51ms under concurrent verification load: this is service/SQL timing, not paired browser latency or a performance improvement claim.
- Final product-experience regression plus new quotation adapter test: 13 tests / 102 assertions, owned schema `accounting_p8_tmp_6fce9a32e5bf`, cleaned. Quote test checks bounded results, null cost, canonical product selection, row identity, search clear and nonexistent-ID rejection. Existing regression covers foreign/inactive products, cost revocation and independent duplicate rows.
- Earlier regression attempts: one disposable migration timed out and cleaned its schema; another had 12 missing-APP_KEY errors and was superseded by the successful run with an explicit synthetic test key. No fallback database used.
- Pint passed; PHPStan on all `app` plus explicitly changed PHP test passed with no errors. Final production build passed (7.01s); no `.map` files found. npm ci reported zero vulnerabilities. No economic reconciliation executed because financial posting/inventory behavior was not changed.
- Local reproducible dependencies installed from lockfiles into ignored `vendor`/`node_modules`; build and compiled test views remain ignored. Owned diagnostic logs are `/tmp/account-picker-{composer,npm,regression,phpstan,build,js}.log`; no private data transfer. No deliberate untracked source artifact remains after this handoff document is committed.
