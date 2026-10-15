# Daily workflow improvements after V2.4

Baseline: `360f7bcef3bb14041c3cfd52731f39f71a0419be`.

## Proposal assessed against the repository

The repository already has canonical product/base-unit creation, Purchasing draft/posting, FEFO, traceable inventory lots, exact unit-aware barcode entry for invoices and purchases, suggested prices, customer/vendor quick creation and deliberately issued document links. These systems remain the implementation boundaries. Rebuilding them would add risk without improving the daily task.

The missing pieces addressed here are inline Quick Product creation in trade forms, a Receive stock entry into canonical Purchasing, quotation scanning, a usable existing expiry-warning preference, clearer existing physical-lot choices and reliable browser sharing feedback.

## Resulting behavior

- Quick Product creates an active stock product and mandatory base unit, then selects it in the unsaved invoice, quotation or purchase. Name, SKU, base unit and expiry tracking are explicit; no opening stock or hidden prices are accepted. Existing edited rows/currency remain intact. A small durable request receipt compares the normalized complete payload and serializes concurrent retries behind the company lock. Both form and product-management permissions are required again on replay.
- Receive stock on an eligible product opens a prefilled, unsaved purchase. Vendor, quantity, cost, warehouse and canonical save/post remain deliberate. Existing drafts are not overwritten by a receive query parameter. No separate inventory or financial writer is introduced.
- Combining explicit Receive and assistant-adoption URL inputs is refused instead of silently replacing the selected lines.
- The Expiry Center's existing company warning-days preference is editable by settings managers, audited and used by its labelled warning filter/status. Expired remains strictly before the company-local date. The historical report's fixed 30-day Soon policy is separate; its reference-date correction is PR #51.
- Transfer/adjustment lot pickers show expiry order, unknown dates, base-unit quantity/unit, current availability and a dated status. Product/source/direction changes clear stale selections. Canonical stock validation, costing and retry handling remain authoritative. Purchase returns explain original-lot limits and purchase units.
- Invoice, purchase and quotation scanners provide an Add item button, loading/success feedback and refocus. Exact barcode/SKU, active unit, tenant, exact-decimal and safe line-merge rules are preserved. Quotation previews reject raw numeric payloads without float coercion.
- WhatsApp/email links contain localized neutral text and the already-issued link only. Clipboard/native sharing handles failures, cancellation, duplicate clicks and stale completion. Expired/revoked links clear on server render. No message is sent automatically; no document password or amount is included in the message.

## Deliberate limits

Manual lot overrides on sales/returns remain deferred: changing those allocation contracts needs a separate canonical review. This release improves existing selectors without inventing a new stock-event path. Native browser sharing is device-dependent; successful handoff is not proof of delivery. WhatsApp sending still requires the user's action in WhatsApp. No client float totals or parallel stock receipt endpoint were added.

## Migration and rollback

`2026_10_16_000001_create_product_creation_requests_table.php` is additive and contains retry receipts only. Existing applied migrations, including `party_creation_requests`, are unchanged. Apply the new migration before serving the new code. The disposable MariaDB round trip checks posted purchase/stock preservation; independent connections check identical/conflicting request races.

For a future separately authorized production rollback, reverting application/assets to V2.4 can leave the additive receipt table intact. Do not drop populated receipts as a routine code rollback: that loses retry evidence. A migration down removes receipts, not products or financial history, and requires an explicit data-retention decision. No rollout or production migration is performed by this PR.

## Verification and operational scope

The following are separate actual executions; overlapping coverage is not added into a grand total:

| Focused check | Passed |
| --- | --- |
| Quick Product/Receive, independent-connection races, migration round trip and relevant existing trade/inventory compatibility | 36 tests / 285 assertions |
| Immutable-document, invalid FX and six-reconciliation Receive boundaries | 4 tests / 18 assertions |
| Expiry/lot plus relevant date/purchase-return compatibility checkpoint | 23 tests / 230 assertions |
| Expanded expiry/lot matrix | 9 tests / 181 assertions |
| Disposal entry permission after revocation | 1 test / 31 assertions |
| Scanning and financial sharing suites | 28 tests / 392 assertions |
| Combined-form intersections after integration | 5 tests / 61 assertions |
| Conflicting Receive/assistant entry inputs | 1 test / 2 assertions |
| Final expiry warning/action presentation boundary | 1 test / 36 assertions |
| Browser sharing API interactions | 6 Node tests |

All completed test schemas are owned disposable MariaDB schemas and were cleaned. A legacy-share check initially failed because the isolated worktree lacked its ignored Vite build; the unchanged fixture passed after restoring baseline assets, and the final sharing suite passed. An initial supplementary stale-editor fixture failed during an extra state-tamper setup; the intended unchanged-editor action then passed with an explicit denial and unchanged product/receipt counts. No runtime policy was loosened to satisfy these fixtures.

Scoped PHPStan passed on every changed production PHP file and the new feature tests. Explicit changed-PHP Pint passed; no suppression or baseline change was added. An optional wider analysis of existing historical tests found existing helper/timestamp typing findings; those files were not refactored for this release. The production frontend build passed. The UI detector's sole combined finding was a false positive across mutually exclusive active/inactive badge classes; rendered combinations were inspected.

Final headless Edge confirmation covered 24 page states plus four modal captures across Arabic RTL / English LTR and desktop / mobile. It checked exact quotation scanning, actual Quick Product creation/selection, modal focus trapping/Escape/focus restoration, the product Receive link, a saved warning preference, neutral WhatsApp text and clipboard feedback. No browser errors, unresolved translations or document-width overflow were found. The first inspection's missing summary translation, date-time expiry display and premature disposal controls were corrected together. Expiry warnings use the warning badge and only expired rows offer disposal. All six reconciliation services were healthy before and after this local browser session, with financial/stock/numbering fingerprints unchanged; its owned schema was cleaned. This is synthetic evidence, not a production availability claim.

AGY supplied bounded implementation snippets for product creation and browser sharing, plus independent Money/expiry source reviews. Incorrect contract calls, validation casts and sharing feedback were corrected during independent review. Stalled write dispatches were not treated as completed implementation; the sharing review's concurrent-edit fingerprint was inconclusive. No worker self-report was used as sole acceptance evidence.

Focused evidence, browser receipts and the final candidate SHA are recorded in the consolidated Owner handback. No broad test cycle or GitHub CI was run; the full `composer qa` command includes the broad test suite, so focused tests, scoped Pint/PHPStan and the production frontend build were used instead. This release changes no dependencies or canonical posting/allocation services.

Off-host recovery, isolated restoration and real password-reset email verification remain separately tracked, outstanding operational work. This assignment does not certify those items or live provider availability. No merge, deployment, Hostinger access or production financial mutation is included.
