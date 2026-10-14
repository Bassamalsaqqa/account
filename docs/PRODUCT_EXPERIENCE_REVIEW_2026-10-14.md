# Product experience improvement — consolidated source handoff

Baseline: `7fdb22b271266efd965737777f4cb08ba5bf0b2a`.
Branch: `feature/product-experience-20261014`.
Implementation checkpoint: `6dd31060df43f3c50bd0730f0b6683748883b0d0`.
The PR's final head includes this report, screenshot evidence and final accessibility polish.
Status: source prepared for independent review; **not merged or deployed**.

## What improved

- **Dashboard:** a compact business overview replaces the repeated totals wall. Canonical net-sales comparisons show actual periods, exact differences and percentages only when the previous positive value supports them. Profit/expense/purchase indicators remain independently permission-scoped. Cash/bank, customer and vendor positions retain currency identity. Positive stock, expiry, unpaid and check alerts link to their canonical reports. One-click periods, deliberate custom-range submission, restricted and empty states are supported.
- **Daily workflows:** login/root/company selection lead to Dashboard while retaining intended destinations. The shared shell removes disabled search, notification/help and obsolete preview controls. Quick Add now links to authorized Sales/Purchase creation. Navigation, forms and tables have more readable sizing, touch targets, logical RTL spacing and a keyboard skip link. Hidden mobile navigation is inert. Reports can be filtered instantly among already authorized titles without another server request.
- **Product selection:** Sales supports bounded Arabic/English name, SKU and exact-barcode search. Existing selected Products/Units stay available. Purchase selection batches repeated Product/Unit reads. Search Enter cannot accidentally submit a Purchase draft; barcode Enter retains its canonical action. Search does not change quantities, prices or save/post authority.
- **Customer clarity/security:** native-currency balances show their actual fallback code and precision. Credit limits use company-base currency, matching canonical policy. Customer detail refreshes membership/permission and same-company record identity before reading data; deleted, foreign and stale-company records are refused.
- **Assistant:** server-derived customer/vendor/product/Sales-invoice context permits scoped questions about the current record. Exact shortcuts can use zero model calls; richer interpretation uses `@current` placeholders, keeping saved record names/identities and canonical results out of model context. Compound clauses retain their own constraints. Temporary context remains bounded and user/company/session-scoped. No persistent chat history was added.
- **Briefing:** zero-model canonical monthly sales comparison, unpaid receivables/payables by separate currency, low-stock and expiry insights provide useful report links. Supporting report detail is collapsed. A changed role, session, company or policy clears stale insights. Existing allowlists must explicitly permit each capability.
- **Voice:** existing native-browser recording, editable text, cancellable automatic sending and explicit cancellation are retained. General privacy text now accurately describes browser processing and enabled automatic sending, without extra microphone dialogs. Opening the assistant or microphone does not itself dispatch DeepSeek.

No new reporting engine, financial writer, provider, model, database table, migration or dependency was introduced. DeepSeek model traffic remains on Laravel AI SDK; free balance diagnostics remain separate.

## Audit disposition

| Finding | Disposition and evidence |
| --- | --- |
| Login/navigation incorrectly land in Settings | **Confirmed/fixed:** Fortify home, root and company-selection redirects; intended routes preserved. |
| Maintenance command can continue after failure | **Confirmed/fixed:** removed swallowed `artisan down` failure. Isolated shell tests verify no Composer/migration/publication after failure and preserve existing maintenance ownership. No deployment was executed. |
| Incomplete Arabic validation | **Confirmed/fixed:** active `resources/lang/ar/validation.php` and English counterpart cover installed Laravel validation keys. |
| Old preview/interface controls | **Confirmed/fixed:** shared shell no longer presents dead controls or claims the functioning system is a preview; real authorized Quick Add replaces the disabled button. |
| Voice privacy copy describes manual-only sending | **Confirmed/fixed:** general copy describes browser processing, editable transcript and enabled cancellable automatic sending. |
| Customer money labels/precision | **Confirmed/fixed:** absent default currency displayed blank; balance fallback and company-base credit limits now have correct labels and JOD precision. |
| Inventory business dates | **Confirmed/fixed:** omitted opening/adjustment/disposal dates and relevant form/expiry dates use company timezone. Explicit historical dates are unchanged. Synthetic UTC/Hebron midnight tests reproduce the difference. |
| Inventory fractional valuation display | **Confirmed/fixed:** `toScale(2)` without a rounding policy could throw for six-decimal values; existing exact-string formatter now presents ILS/JOD correctly without changing canonical valuation. |
| Missing general security headers | **Confirmed/fixed within scope:** application baseline adds nosniff, same-origin framing, restricted base/object policy, referrer and browser-permission headers. Existing stricter public-document CSP and API cache directives are preserved. Authenticated responses are private/no-store. No claim of a complete security certification or new HSTS/domain policy. |
| Repeated Dashboard/Purchase queries | **Confirmed/improved:** measured below; hidden secondary Dashboard reports become authorized links rather than eager queries. Fresh source and final authorization checks remain. |
| Whole AP account must equal vendor liability subledger | **Dismissed as stated:** accepted ADR 0005 explicitly reconciles canonical Purchasing/payment batches because legitimate AP opening balances exist outside vendor sources. Existing Payables reconciliation checks source/batch ownership and applications. No mutable Vendor.balance authority was invented. |
| Every Inventory GL reconciliation bypass is resolved | **Not established:** see the confirmed internal boundary concern below. Normal canonical action integration and corruption checks must not be confused with proof of every possible internal service caller. |
| Shared cURL code duplicated / web UI API parity absent | **Already addressed in merged source:** `DeepSeekHttpBoundary` is shared. Six focused AR/EN UI/API parity cases now pass with equal canonical output/model-call and economic fingerprints; the web UI continues to use the shared application service. No redundant UI-to-HTTP loop was introduced. |
| Generic currency/FX precision or DB integrity failures | **Not confirmed generally:** exact decimal primitives and existing safeguards remain. Only reproduced date/presentation defects were corrected; no speculative schema or economic-policy changes. |
| Reconciliation cron absent | **Confirmed operational gap, not activated:** repository schedules AI pruning, not recurring six-domain reconciliation. Frequency, resource allowance, protected evidence and alert destination need an operations decision. No production cron was changed. |
| Outdated documentation | **Confirmed/fixed:** README no longer states accounting primitives are Phase 2 in progress. Historical acceptance records and Owner specifications remain intact. |
| Real DeepSeek understanding / Palestinian microphone accuracy | **Still unverified in this round:** no live paid calls or acoustic/device accuracy experiment. Synthetic callbacks and mocked SDK responses prove boundaries, not real recognition quality. |
| Independent off-host recovery and isolated restore | **Outstanding operational gates:** this source task does not retrieve backups/keys, perform restoration or establish customer readiness. |

### Confirmed internal stock-service boundary concern — P2, requires architectural follow-up

The public `InventoryMovementService::record()` primitive rejects direct Purchase/Purchase Return writes but accepts certain Sales/opening/adjustment/disposal movements. A bounded synthetic diagnostic showed an active authorized internal caller could commit a stock-only opening and then a Sale referring to a nonexistent invoice, with no GL batch. The Inventory GL audit condition can report healthy when there are no GL lines and the source is outside its three recognized opening/adjustment/disposal source categories.

This is **not a demonstrated remote/unprivileged exploit or production incident**. The seven actual runtime call sites found are canonical actions: PostOpeningStock, AdjustStock, DisposeExpiredStock, PostSalesInvoice, PostSalesReturn, VoidSalesInvoice and VoidSalesReturn. They already wrap stock/financial effects in an outer transaction. Older Phase 3 tests intentionally exercise stock-only primitives; accepting them is not proof of canonical business-event authority.

A proper correction needs action-owned, company/source/actor/transaction-bound capabilities analogous to existing Purchasing scopes, with standalone-negative, rollback, idempotency and concurrency coverage. Merely checking an open transaction or scanning callers would not fix authority. That architectural change was not mixed into this UI release. The diagnostic is retained in ignored engineering evidence, not as a test that blesses unsafe behavior. Independent review should decide its release significance before merge.

## Measured responsiveness

Same local MariaDB, PHP 8.4, owned synthetic fixtures; these are not Hostinger guarantees.

| Measurement | Accepted baseline | Feature | Limits |
| --- | --- | --- | --- |
| Initial Dashboard, 44 canonical posted synthetic invoices | 393 queries; 436/393/308 ms | 306 queries; 262/316/267 ms | Three in-process reads each; feature additionally computes previous-month sales; hidden secondary reports are links. Economic fingerprint unchanged; 50 MiB measured peak process memory. |
| PurchaseForm render, 12 repeated product lines | 50 queries; 37 Product/Unit reads; 23.38 ms | 17 queries; 4 Product/Unit reads; 10.97 ms | Component render only, one local observation; one-line rendering remains 17 queries. |

Timing varied under concurrent local QA. Query counts are the stronger repeatable evidence. No arbitrary performance target, production load test or removal of fresh RBAC checks was used. Browser navigation tools sometimes timed out after the page action completed; settled URL/DOM were checked before proceeding.

## Executed verification

Commands used PHP 8.4 and `tests/Support/run-phase8-disposable.php` with explicit owned MariaDB protections on local loopback. No persistent data reseeding/wipe occurred. Counts below are **actual runs**, with overlapping focused rechecks identified rather than summed as unique coverage.

| Focused run | Result |
| --- | --- |
| Navigation, localization, deployment shell and affected authentication regressions | 25 tests / 178 assertions PASS |
| Product search, selected units, revocation/company switch and query bounds | 7 tests / 35 assertions PASS |
| New inventory company-date/precision regressions | 6 tests / 33 assertions PASS |
| Existing canonical inventory/accounting integration | 5 tests / 45 assertions PASS; included again in final integration check below |
| Customer detail currency/security, including existing regressions | 10 tests / 26 assertions PASS |
| Clean combined context, briefing, existing briefing security and AR/EN UI/API parity | 46 tests / 363 assertions PASS; zero failures/errors/skips |
| Dashboard truth/restriction/period plus application headers | 8 tests / 78 assertions PASS; subsequent cache-header correction checked below |
| Final headers/cache preservation + canonical stock/GL integration + cross-domain Purchasing/Accounting/Sales/Inventory reconciliation | 10 tests / 62 assertions PASS |
| Existing UNSAVED Sales/Purchase handoffs/form adoption + comprehensive Phase 7 payroll/expense reconciliation | 5 tests / 39 assertions PASS |
| Browser voice state/cancellation/repetition/offline/duplicate-dispatch tests | 34 Node tests PASS |
| Negative internal service-boundary diagnostic | 1 test / 9 assertions reproduced the concern; **not counted as safety acceptance** |

Changed runtime PHP passed targeted PHPStan with zero errors. Changed/new PHP passed Pint. Production frontend build passed (62 modules, CSS 102.93 kB / gzip 17.75 kB; JS 62.69 kB / gzip 23.20 kB). Blade compilation, shell syntax, whitespace and added-source sensitive-pattern review passed. No dependencies/migrations changed. Full `composer qa`/full PHPUnit and GitHub CI were intentionally not repeated under the Owner's focused-testing direction.

### Practical browser evidence

All screenshots and values are synthetic local fixtures, not production records.

- Dashboard inspected in English LTR and Arabic RTL at 1440px desktop and 390px mobile, including actual canonical ILS 800 versus ILS 450 sales. Mobile measurements showed no page overflow beyond the viewport on inspected Dashboard, Sales, Products, Inventory, Money and Purchasing states.
- Report search filters authorized Arabic titles in the browser without server calls. Shared navigation, rendered list/empty states, currency labels, Sales/Purchase form controls and unsaved search were inspected.
- On Ahmed's actual synthetic Customer screen, `قديش عليه دين؟` returned one company-scoped canonical ILS 1,000 balance; request metadata showed `attempt_count=0`, `cost_status=no_text_call`. Fixture economic counts remained four invoices, five batches, ten posting lines, zero stock movements and zero purchases. No browser Save/Post was performed.
- Daily briefing rendered canonical AR comparisons, per-currency receivables, stock/expiry observations and report links with zero provider requests. The actual browser supported microphone affordances were inspected; no acoustic accuracy claim is made. Mocked callback tests remain separate from real speech evidence.
- Restricted and stale-context behaviors were verified with server-side synthetic tests, not by granting access to production employees.

| Dashboard | Desktop | Mobile |
| --- | --- | --- |
| Arabic | [Screenshot](evidence/product-experience-20261014/dashboard-ar-desktop.jpg) | [Screenshot](evidence/product-experience-20261014/dashboard-ar-mobile.jpg) |
| English | [Screenshot](evidence/product-experience-20261014/dashboard-en-desktop.jpg) | [Screenshot](evidence/product-experience-20261014/dashboard-en-mobile.jpg) |

[Current-customer answer](evidence/product-experience-20261014/context-ar-desktop.jpg) · [Arabic report search](evidence/product-experience-20261014/reports-ar-desktop.jpg) · [English mobile Sales form](evidence/product-experience-20261014/sales-en-mobile.jpg)

## Delegation, preservation and release boundaries

AGY headless Hello succeeded. Bounded Dashboard UI/navigation/briefing work and source auditing were delegated in isolated worktrees, then independently inspected, corrected and tested. In particular, the briefing worker's first-currency/base-currency assumption was rejected and replaced with separate exact currency maps. The final additional read-only AGY review timed out without a report; it supplied no acceptance evidence and made no changes. Codex's own source review and actual checks govern this handoff.

Owner originals/private PDFs, accepted historical production records, ignored credentials/evidence and unrelated worktrees were not staged or rewritten. Local `.ai` artifacts deliberately retain worker briefs/results, baseline query traces, test output, synthetic preview ownership manifests and the negative boundary probe. Interrupted preview inventories were preserved rather than silently adopting/dropping schemas; previously quarantined schemas remain untouched. Successfully completed test harnesses clean their own schemas. The live preview is stopped through its owned cleanup path at handoff.

No production access, financial mutation, key retrieval, real mail, paid DeepSeek call, merge, deployment, budget/counter reset, history rewrite or GitHub CI occurred for this task. Source review is pending. Remaining release risks are the internal stock-boundary finding, actual Hostinger capacity, live language/device evaluation, reconciliation scheduling and existing off-host/restore/email operational proofs. Exact invoice-record questions beyond the curated tools still refuse unsupported requests instead of substituting another invoice; invoice-context customer questions are supported.
