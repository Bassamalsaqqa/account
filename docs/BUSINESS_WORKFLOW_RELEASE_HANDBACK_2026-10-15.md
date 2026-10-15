# Daily business workflow release: independent review handback

Status: **development candidate for independent review; not deployed**.

- Accepted production baseline: `68b4ec0fdaf98c8cb528181da0d128653f69e937` (Owner-reported deployed V2.3).
- Baseline tree: `b23e37889cf94ccb477713d93158bf1b15fd72e6`.
- Branch: `feature/business-workflow-improvements-20261014`.
- Implementation commit: `9b087f964277adcd3a9ba801b5946c9fba068dbc` (64 files).
- This handback is a subsequent documentation-only commit. The draft PR head identifies the complete candidate; there is no circular claim that the implementation SHA contains this document.
- Final pre-publication fetch confirmed `origin/main` still at the accepted baseline. The original main checkout's tracked files were left unchanged.

## Delivered behavior

| Daily task | Improvement |
| --- | --- |
| Sales and quotations | Create a customer without abandoning an unsaved document; preserve its currency, FX, language and edited lines. Customer and quotation-product discovery is bounded, while selected records remain available. An authorized read-only cue shows the customer's latest posted recorded unit price, original currency/unit and document date. Conversion opens the resulting editable invoice draft when permitted, with provenance links. |
| Purchasing | Create and select a vendor inside the unsaved purchase. Exact barcode/SKU entry resolves the configured active product unit and suggested cost. It preserves edited lines, lot data and different prices rather than combining incompatible rows. |
| Repeated entry | Alt+N adds a line; Ctrl/Cmd+S saves a draft only. Visible dialogs suppress these shortcuts. Repeated scans increment only compatible rows using exact decimal strings. Oversized codes and invalid conversion configurations produce recoverable errors. |
| Customers and vendors | Quick creation uses existing catalog services, fresh form/manage permissions and same-company ownership. Master creation, audit and a durable payload-checked retry receipt commit together. Two simultaneous same-key requests converged to one party in a real two-process check. |
| Products and brands | Bilingual brand search, pagination, creation, editing and activation/deactivation; brand filtering; inline brand creation preserves the unsaved product. Brand deletion was deliberately excluded. Revoked cost permission clears previously hydrated suggested cost. |
| Inventory | Exact whole-package plus base-remainder stock presentation on product/warehouse and low-stock views. The low-stock view eager-loads quantity state instead of looking it up once per product; unit details load only for displayed rows. Quantities remain authoritative in the existing inventory services. |
| Money | Daily opening, inflow, outflow and closing by account/currency, with Today/Yesterday/date controls. Historical daily closing is distinct from current balance. Missing native metadata is disclosed; restricted source history suppresses all summary amounts. Mobile shows all four figures. |
| Checks | Today, overdue, next-seven-days and all-open attention filters with counts, accessible selected states and a full reset. Invalid date ranges show recoverable errors. Existing source visibility and check transitions remain authoritative. |
| Tenant validation | Company predicates added to the touched raw UI existence rules across trade, expenses and payroll. Canonical domain authorization remains in place. This is a scoped defense-in-depth audit, not a claim of exhaustive application security certification. |

Arabic RTL and English LTR remain supported. New sales dates use the company's local day. No alternative posting engine, client-side financial calculator, background infrastructure, mutable balance authority or dependency version change was introduced.

## Proposal decisions and separate operations

[Proposal review](BUSINESS_WORKFLOW_PROPOSAL_REVIEW_2026-10-15.md) classifies each recommendation against repository source. Existing capabilities include sequence locking, report guards, CSV protection, unit prices/barcodes, quotation-to-draft conversion, check reversals, explicit financial sharing and AI unsaved-form correction.

Period locks, counterparty opening positions, endorsement/netting, settlement discounts/refunds, combined immediate payment, stocktake, manual lot policy and retrospective landed-cost accounting need complete domain policies. They were not approximated in a convenience UI. Speculative cache/session speed percentages, mass PhaseX renaming and new SaaS/POS/API programs were excluded from this release.

[Operational follow-up](OPERATIONS_RECOVERY_AND_MAIL_FOLLOWUP.md) separately tracks OPS-RECOVERY-01, OPS-RESTORE-02 and OPS-MAIL-03. Off-host custody, isolated restoration and real password-reset delivery remain **not verified**. No real reset email was sent.

## Schema and preservation

One forward migration: `database/migrations/2026_10_15_000001_create_party_creation_requests_table.php`.

It adds an ancillary retry-receipt table with company/user/key uniqueness, payload hash and restrictive master-record foreign keys. Financial, stock, historical snapshot and balance tables are unchanged. Existing applied migrations were not edited. The new code requires this table; removing it would also remove durable quick-create replay receipts. Production migration or rollback is not authorized by this handback.

A newly owned disposable MariaDB fixture exercised canonical purchase, invoice, receipt and check events before UI verification. Rolling back and reapplying the new migration preserved fingerprints of posting batches/lines, stock movements, inventory balances/cost states/lots/lot balances and numbering sequences. The same fingerprints remained unchanged after real UI draft/master interactions.

All six canonical reconciliations were healthy both before and after: Accounting, Inventory, Sales, Payables, Money and Phase7. No synthetic dataset was imported from production.

## Verification actually completed

Counts below overlap and must not be added together. No V2.3 release counts are reused as candidate evidence.

| Check | Observed result |
| --- | --- |
| Broad relevant MariaDB checkpoint: new workflows/unit breakdown, ProductSelectionSearch, quotation lifecycle/provenance, all Phase5B, MoneyUi, MoneyMovementVisibility and Phase7LivewireUi | **221 tests, 1,518 assertions passed**. This preceded the final small UI/date/redaction/scanner corrections. |
| Final complete `tests/Feature/BusinessWorkflow` plus `tests/Unit/Inventory/StockQuantityBreakdownTest.php` | **58 tests, 378 assertions passed**, after those corrections and the PC restart. Includes exact decimal rejection, invalid conversion recovery, retry rollback/conflict, tenant/permission changes, historical price redaction, daily summary visibility and date behavior. Owned primary schema cleaned. |
| Full-project Pint `--test` | Passed on final source. |
| Full-project PHPStan `analyse --memory-limit=512M --no-progress` | Zero errors on final source. |
| `npm run build` | Passed, 62 modules; final CSS `app-Cobfho7i.css`, JS `app-C3t-FFih.js`. Generated assets remain ignored build outputs. |
| Composer locked audit / npm audit | No advisories or abandoned packages / zero vulnerabilities at verification time. Lockfiles unchanged. |
| AR/EN browser layout checks | 28 successful pages: seven screens × two languages × desktop/mobile. No page errors, page overflow or unresolved translation keys. Screenshots inspected, including Arabic mobile Money and English mobile quotation entry. |
| Real browser interactions | Six actions passed, zero browser errors: dialog focus/Escape/shortcut suppression; barcode/Alt+N/draft-only Ctrl+S; vendor creation preserving cost; inline brand preserving product; Money Yesterday; Checks Today. Repeated after restart on the fresh owned fixture. |
| Migration and synthetic economic preservation | Rollback/reapply passed; all economic fingerprints unchanged; six reconciliations healthy before and after UI actions. |
| Actual parallel quick-create requests | Two processes, same resulting party, one durable request receipt. |
| Impeccable mechanical detector | No findings in the touched interface pass. |
| `git diff --check` / staged whitespace check | Passed. |

PHP 8.4.25 and local MariaDB were used. Focused tests ran through `tests/Support/run-phase8-disposable.php` with explicit testing-only loopback configuration, an ownership capability and freshly created schemas. No persistent-data reset command was used. Mail was array-only and application AI was disabled.

The aggregate historical `composer qa` command was **not run**. Its complete Pint and PHPStan legs were run separately; its unbounded historical test leg was replaced with the guarded relevant MariaDB suites above, avoiding default PHPUnit database configuration and repetitive full-history testing. Do not describe this as a passing full `composer qa` or full application test suite.

The PC restart interrupted the first preview process before its final audit/cleanup receipt. Its synthetic schema was left quarantined rather than adopted or reset. A fresh owned fixture completed the interaction, migration, preservation and cleanup checks after restart. Previously completed browser screenshots and test receipts survived.

## Delegation and independent acceptance

Three isolated headless AGY implementation packages covered brands, trade entry and Money/Checks. Codex inspected source and surrounding canonical services, requested worker corrections, made additional integration fixes and independently ran the gates above. Final corrected package snapshots were collected without policy anomalies and accepted:

| Package | Accepted tree |
| --- | --- |
| Brands | `0bfbe308920434423f7fc0e050ecd69ccae57d29` |
| Trade entry | `cf15139bfe882f7da442ce73157ef7dd9d44cc49` |
| Money/Checks | `12f7fb2ad00c9b247b999b8dfc7f301be144fdae` |

These are package trees, not the combined release tree. Corrected files were integrated explicitly into the dedicated release worktree, with Codex-owned inventory, price-cue, durable receipt and tenant-validation work. Publication uses ordinary commits on that consolidated branch rather than separate worker commit/push transactions. The Owner explicitly authorized commit and push. Workers did not commit, push or deploy.

An additional optional read-only AGY review timed out without completing a review; it reported no read-only violation. It is **not** approval or acceptance evidence. Independent human review of the draft PR remains outstanding.

## Review limits and release boundary

- No measured Hostinger latency claim, physical scanner-hardware check, real microphone/provider evaluation or production browser certification.
- Historical price cues are optional and bounded to at most 100 selected products. They do not apply prices or reinterpret historical tax/unit policy.
- Purchase shortcut retry protection covers sequential responses for the same component state. It does not establish durable idempotency for all concurrent stale manual-purchase submissions; no new purchase posting path was introduced.
- Shared-hosting deployment configuration and existing public financial-disclosure policy remain unchanged. Larger low-stock catalog profiling and full-history summary scaling remain future measured work.
- No merge, deployment, Hostinger access, production-data access, production migration, GitHub CI dispatch or paid application-provider call occurred. Any release requires separate authorization.

## Changed-file inventory

The implementation commit contains the following groups; `git diff --name-only 68b4ec0..9b087f9` is the exact 64-file manifest:

- New domains/services: `StockQuantityBreakdown`, `MoneyDailySummaryQuery`, `CustomerLastPriceQuery`, `QuickPartyCreation`.
- New Livewire pieces: `QuickPartyCreate` concern and `Catalog/BrandIndex`.
- Existing Livewire pages: Expenses category/form; Inventory overview; Money overview/account/checks; Payroll advance/salary entry/payment; Products detail/form/index; Purchasing payment/purchase; Sales invoice detail/form, payment, quotation detail/form and return.
- One new migration named above; no altered historical migrations.
- AR/EN inventory, money, workflow and trade-cue language files.
- Shared customer-last-price, money-daily-summary and stock-breakdown components; app layout, brand management and corresponding Inventory/Money/Products/Purchasing/Sales views; brands route in `routes/web.php`.
- Eight `tests/Feature/BusinessWorkflow/*Test.php` files and the stock-breakdown unit test.
- Proposal matrix and operational follow-up documents. This documentation-only follow-up adds the present handback.

Private ignored artifacts deliberately retained: isolated integration/worker/review worktrees; controller/relay records and briefs; local dependency/build caches; guarded test/preview/browser scripts; synthetic receipts, screenshots and restart diagnostics. They are not PR contents. The original main checkout's 16 pre-existing untracked Owner documents/PDFs and `.playwright-mcp` directory were preserved. No unrelated cleanup was performed.
