# Product Refinement A.1 — review candidate

Baseline: `e5129d50aa7e9abbdd04a21b006a87afd59b2ca1`.
Application changes: `001272d801b3c3479795de9da0cb83e8fad9f766`.
Branch: `feature/product-refinement-a1-20261018`. The published PR records the final documentation/evidence head.

This is a source review candidate. No merge, deployment, Hostinger access, production database access or production financial writes occurred. Main was rechecked against the named baseline. No migrations, Composer/npm dependencies, canonical posting engines or historical records were changed.

## Confirmed findings and corrections

| Category | Finding | Delivered correction |
| --- | --- | --- |
| 1 — reliability | Rapid quantity taps were discarded while an earlier operation was busy; unrelated requests could overtake a pending batch. | A bounded FIFO reserves the first tap's position, coalesces ordered directions, recalculates once on PHP, and flushes before Save, Scan and Add Line. No optimistic quantity or financial total is presented as confirmed. |
| 1 — reliability | An interrupted secondary helper could allow a queued Save to continue. | Transport failures quarantine dependent operations. Explicit review discards pending actions; nothing is automatically replayed. An ambiguous timed-out quantity request remains active until it actually settles. |
| 1 — reliability | Quantity buttons could accept a tap before product selection completed. Real-browser reproduction safely failed the server's row-identity check. | Disable taps during selection/unit/scanner/line operations, while retaining coalescing during quantity requests. Product/unit and economic input edits are disabled while their document queue is active. |
| 1 — reliability | New payment shortcuts needed fresh party/currency/document validation; whitespace-only vendor FX could reach a decimal parser; a stale returned/paid document could have a nonpositive balance. | Fresh authorization and persisted source checks, exact-string validation, no mutation before whitespace validation, and refusal of nonpositive fresh balances. Existing amount/allocation edits remain visible. |
| 2 — touch usability | Products and other indexes exposed large filter panels before records, with small or cramped actions. | Shared collapsible mobile filters, active counts, status buttons where applicable, mobile cards and explicit action buttons. Desktop filters/tables remain available. |
| 2 — touch usability | Secondary stock/product/return/payment entry required precise typing. | Product barcode capture fills only the input; inventory lookup selects product/barcode unit only; exact quantity controls and availability/warehouse/unit/lot information; source-bound return cards and Return Remaining; payment allocation cards, Pay Remaining and Full Balance. |
| 2 — clarity | Small detail/settings/report/share actions, inconsistent loading/keypad feedback, unreadable glyphs and six existing Arabic report labels. | Shared touch surfaces, scoped disabled/loading targets, numeric input modes, mobile sticky actions, corrected glyphs/labels and existing tax mode labels. No second VAT engine. |
| 3 — performance | Twenty serial quantity requests repeat authorization, queries and full component responses. | One bounded batch replaces those twenty requests for the measured burst. See the measured evidence below. |
| 3 — measured finding retained | Dashboard has 337 queries in this synthetic fixture. | Profiling attributes most repetitions to fresh authorization across canonical reports. No permission cache, speculative session fix or accounting/report rewrite was introduced. |
| 4 — optional expansion | New modules, infrastructure, native packaging, broad SPA conversion and speculative indexes. | Outside this refinement; not implemented. |

## Surface coverage

- Lists: Customers, Vendors, Products, Brands, Categories, Units, Warehouses, Invoices, Quotations, Purchases, both Returns and Payments, Checks, Money Transfers, Expenses/Categories, Employees, Stock Movements, Expiry and Inventory Overview. Existing permission checks and unpaid/document-state conditions govern shortcuts. Vendor New Purchase and Product Barcode Labels open their existing destinations without inventing prefill contracts.
- Forms/details/settings: shared touch action treatment across existing Sales, Purchasing, Money, Expenses, Payroll, party/product/catalog/barcode, reporting and sharing screens. The existing mobile bottom navigation and desktop sidebar are preserved; inventory-only authorized pages can invoke their own scanner.
- Camera: the existing Release A decoder/lifecycle is reused. Only Product create/edit and the three inventory form routes join the self-camera header allowlist. Other security headers and denied routes retain their policies. Product capture never creates a record. Inventory scans do not post, infer a lot or choose a warehouse.
- Returns: source transaction identity and fresh returnable caps remain server-owned. Purchase return helpers also respect original-warehouse stock and original-lot capacity; operators still review allocations. No barcode-based return-source inference.
- Payments: shortcuts only use eligible open documents in the selected account's currency and the selected authorized party. Full Balance fills the amount, preserving deliberate allocation edits. It is not an account balance and does not automatically post a payment.

The canonical accounting/stock actions remain the final authority for all saves. Restricted costs and payroll values remain server-redacted. UI controls and draft helpers do not become posting APIs.

## Verification actually performed

PHP 8.4.25 / MariaDB 10.4, runner-created disposable schemas; each completed test runner cleaned its owned schema:

| Focused gate | Result |
| --- | --- |
| QuantityBatch + ListTouchUsability + existing DocumentTouchControls | 15 tests / 238 assertions passed |
| Integrated secondary draft controls + camera security headers | 18 tests / 134 assertions passed |
| Independent final whitespace/stale-payment corrections | 2 tests / 26 assertions passed |
| JavaScript document operations + existing camera lifecycle | 29 tests passed |
| Scoped PHPStan | 12 changed application files clean; final 3-file payment correction clean separately |
| Scoped Pint | Passed |
| Blade compilation and generated-PHP parsing | Passed; 172 compiled PHP views parsed without syntax errors |
| Static design detector on changed shared/secondary surfaces | No findings |
| `npm run build` | Passed, 308 modules; local lazy ZXing chunk and manifest linkage present |

These focused runs total **35 PHP tests / 398 assertions**, without counting worker reruns twice. `composer qa` includes a full test suite and was intentionally replaced by its scoped constituent checks under the Owner's focused-testing instruction. No GitHub Actions CI or paid application-provider requests were run.

All six canonical **synthetic, read-only** reconciliations passed: Accounting, Inventory, Sales, Payables, Money and Phase7. Evidence is local disposable data, never independent production evidence. Preservation results distinguish permitted synthetic draft creation from immutable posted accounting/stock history.

### Browser evidence and provenance

Real desktop Edge running the isolated synthetic application was exercised at requested viewport overrides of 390, 768 and 1440 pixels. Scrollbars reduce some content/capture widths to 375/753 pixels. These are desktop responsive checks, **not physical phone or tablet evidence**.

- AR/EN Products: compact filters, expansion/collapse, status controls, card actions and desktop tables. Measured visible mobile action targets were 44px high; no page overflow on the checked Product and Adjustment phone views.
- Inventory Adjustment: product selection, fresh available stock/unit breakdown, authoritative increment and numeric input mode. No stock save was performed in the browser.
- Sales Return: original 2 / returned 0 / remaining 2; Return Remaining filled 2 without persistence.
- Customer Payment: Pay Remaining filled the draft allocation with 49.000000 without confirming a payment.
- Invoice: product selection and authoritative quantity changes; immediately tapping Save after a quantity tap retained exact quantity 3 and its 75.000000 line subtotal when missing-customer validation refused the save.
- Product scanner: explicit opening, focus and Escape closure checked. Actual camera decoding, permission-denial and lifecycle branches are covered by focused JavaScript/server tests; this PC browser session did not establish real-device camera behavior.
- A fresh final Invoice browser tab produced no captured application console warnings/errors on load. An earlier browser-extension message-channel error and temporary diagnostic errors are excluded from final application acceptance; temporary diagnostic code was removed.

Representative screenshots (synthetic data):

| Phone | Tablet | Desktop |
| --- | --- | --- |
| [AR Products](images/refinement-a1/after-ar-products-390.png) / [EN Products](images/refinement-a1/after-en-products-390.png) | [AR Invoices](images/refinement-a1/after-ar-invoices-768.png) | [AR Products](images/refinement-a1/after-ar-products-1440.png) |
| [EN Adjustment](images/refinement-a1/after-en-adjustment-390.png) | [AR Adjustment](images/refinement-a1/after-ar-adjustment-768.png) | [EN Products](images/refinement-a1/after-en-products-1440.png) |
| [AR Invoice](images/refinement-a1/after-ar-invoice-form-390.png) / [validation preservation](images/refinement-a1/after-ar-invoice-validation-390.png) | [EN Payment](images/refinement-a1/after-en-payment-768.png) | |

## Performance evidence

See [raw measurements and analysis](evidence/refinement-a1/PERFORMANCE.md). Measurements use the same local synthetic fixture and protocol harness; they are not Hostinger response-time promises. Initial loads, Product/Customer/Omnibox search, document item selection/camera handler, a draft save and Sales Summary were measured.

The exact 1→21 quantity comparison was **20 requests / 800 queries / 1,390,614 response bytes** versus **1 request / 40 queries / 72,236 response bytes**. Local wall time was 9,914.49ms versus 358.71ms. Request/query/payload reduction is the meaningful result; local load and compilation caused substantial timing variance elsewhere. No speculative cache/index or authorization weakening was used to manufacture a latency improvement.

## Delegation and independent review

AGY implemented bounded nonvisual draft/quantity work. Its first output was corrected independently for row identity, exact-string handling, source ownership, active units, party/currency restrictions, ordered minimum clamping, FIFO reservation and ambiguous failure recovery. Codex integrated and reviewed the resulting diff and reran relevant gates.

A later AGY read-only quantity review returned partial source analysis with no demonstrated blocker; its truncated output is not full independent source acceptance. The relay's live-worktree fingerprint changed during concurrent Codex integration, so that flag is not evidence that AGY authored those changes. A separate sanitized Dashboard review could not authenticate and is not counted as completed. Final independent architectural/source acceptance remains with the Owner's reviewer.

## Review and release limitations

- Physical iPhone/Android checks of these new Product/Inventory/Return/Payment controls remain outstanding. Prior Release A iPhone evidence is not relabelled as A.1 evidence. Onscreen keyboards, camera permission/rear focus, rotation, background/resume and installed PWA should be verified on actual devices before release acceptance.
- Only representative responsive browser workflows were exercised; the focused render/authorization tests cover additional lists. This is not a claim of exhaustive manual testing of every role on every screen.
- Existing language switching reloads a document; no global unsaved-navigation or SPA architecture was added. Operators should save drafts before switching languages.
- An interrupted return-draft save may have an unknown server outcome. The queue stops automatic retries and requires review; this UI release does not add a new server-side return-draft idempotency protocol. Canonical posted payment/stock idempotency remains unchanged.
- No large-volume tenant benchmark or production Hostinger performance measurement occurred.
- No new financial module, recovery/DevOps workstream or production release operation is part of this PR.

Review the final combined source at the published PR head. Merge and deployment require separate independent acceptance and explicit Owner authorization.
