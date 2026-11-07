# Correction implementation handback — 7 November 2026

Baseline remains `06e4f6d`. Existing dirty work and the designer's UI_REDESIGN_20261107.md were preserved. No commit, push, deploy, production access, external agents, hooks, worktrees or destructive Git operations.

## Implemented scope (partial owner scope)

Purchase detail and desktop/mobile index row actions now expose confirmed-document Edit/Delete through one server boundary (`PurchaseCorrection`). Draft actions remain unchanged. One deliberate final confirmation follows a reason/date input and affected-item review. Edit atomically creates an unposted canonical replacement and canonically voids the original; Delete canonically voids and retains the original. New receipt stores both IDs, actor, reason, economic date and payload digest. Detail shows reciprocal provenance links. No automatic posting, payment reversal, unapplication, return cascade, or physical posted-history deletion is introduced.

Preview is encrypted, actor/company/source/mode/reason/date-bound and expires after ten minutes. Execution freshly authorizes active membership, source void, cost view, and (for Edit) creation/edit permissions under TenantWriteAccess/company serialization. Receipt retry compares economic payload before returning the identical replacement. Final canonical guards and clone errors roll back all writes. Builder receives only explicit editable intent including exact fractional quantities and lots, never source IDs or stock references. Changed unit conversion, line tax, discount or total economics block exact replacement. Sensitive preview rows require cost permission before reads/serialization.

Dependency digest deliberately snapshots **all company_id-scoped tables** plus company and shared units/currencies, sorted deterministically with locks. This includes stock, ledger, allocations, checks, application events, returns, landed costs and tenant replacement configuration. Only correction receipts are excluded so receipt retries are stable. This is conservative: unrelated tenant changes invalidate the preview. Schema enumeration and full tenant reads are a scaling limitation requiring optimization/qualification before release; not a claim of bounded large-company performance.

Receipt migration `2026_11_07_000003_create_purchase_correction_receipts.php` refuses down when provenance exists and uses restrictive source/replacement FKs. Existing migration test's rollback step changed from 2 to 3 to include the new migration. Existing void action/scopes/migrations 000001/000002 were not weakened.

## Exact implementation files

- app/Services/Corrections/PurchaseCorrection.php (new)
- app/Livewire/Pages/Purchasing/PurchaseDetail.php
- resources/views/components/correction-preview.blade.php (new; shared dialog-shell)
- resources/views/livewire/pages/purchasing/purchase-detail.blade.php (additive to designer work)
- resources/views/livewire/partials/purchase-row-actions.blade.php
- resources/lang/{en,ar}/corrections.php (new)
- resources/lang/{en,ar}/purchasing.php (missing purchase blocker translations)
- database/migrations/2026_11_07_000003_create_purchase_correction_receipts.php (new)
- tests/Feature/Purchasing/PurchaseCorrectionTest.php (new)
- tests/Feature/Purchasing/VoidPurchaseReturnTest.php (migration rollback count only)

## Explicit remaining scope / release blockers

This is **not completion of the full owner correction request**.

- Sales Invoice, Sales Return and Purchase Return correction adapters/dialog/index entry points are not implemented.
- Dedicated customer/vendor cash/bank payments are not reversed automatically; existing payment allocations remain canonical blockers. Shared payments, cross-document credit applications and checks remain blocked, never inferred dedicated from one allocation. Whole payment reversal authority/accounting is untouched. No partial unapply implemented.
- Posted and draft child returns remain explicit blockers. No dependency-order cascade or draft deletion is implemented.
- Locked landed costs, stock consumption, valuation and lot insufficiency remain canonical blockers. Linked vendor payments and purchase returns are offered when financial/detail read permits; stock/landed-cost blockers still lack per-record recovery links.
- Payment, expense, payroll and transfer existing reversal dialogs have not been unified. Inspection found existing payment reverse modals and payroll advance/salary wire:confirm flows; these need a separate affected-record adaptation and tests.
- No concurrent process race test, complete cross-company/capability matrix, check/application-event/shared-credit matrix, injected payment/stock/GL failure matrix, or fractional-lot/config parity matrix for the new adapter yet.
- Receipt writes have one private application writer and no public mutable model; no database trigger-level immutability enforcement was introduced.
- Preview source line rows do not yet list stock/GL inverse rows or all independent dependency details. Existing blockers remain truthful, but affected-record presentation needs expansion for owner acceptance.
- Browser visual/focus/touch/RTL qualification and Owner acceptance remain pending. No browser was opened.
- Broad redesign and final designer layout qualification remain parent followup.

## Verification

See final appended run evidence below. Prior parent evidence of 86 tests / 348 assertions is preserved as prior evidence, not relabelled as this worker's run. Initial focused run: 3 tests / 8 assertions passed; expanded run: 7 tests / 15 assertions passed. Initial combined regression: 89 tests / 349 assertions, one migration rollback-count failure (subsequently corrected). Production build, changed production PHP PHPStan, Pint and Blade compilation passed during implementation. Later reruns supersede these intermediate results where stated below.

### Focused final evidence before combined rerun

- New correction suite: **12 tests / 28 assertions PASS** on owned disposable MariaDB at 127.0.0.1:3342; schema cleaned.
- Includes Livewire detail dialog through canonical execution, retained delete, replacement retry, stale dependency, rollback after clone creation, unauthenticated and revoked-cost permission, token tampering, economic retry mismatch, active-payment blocker/link, changed conversion, and downgrade refusal.
- Changed production PHP: PHPStan **PASS**, Pint **PASS**; Blade compilation **PASS**; production Vite build **PASS**; mobile-shell Node tests **9 PASS**; `git diff --check` **PASS**.
- Intermediate Livewire test caught missing component props and mixed inline/block Blade PHP parsing; both fixed and the real component test now passes.
- Intermediate concurrent disposable runs caught schema enumeration including other schemas. Fixed with explicit `DB::connection()->getDatabaseName()` and unqualified listing restricted to that database; no cross-schema enumeration remains in the implementation. Final tests use the exact owned disposable runner only.
- Intermediate 98-test run had one new-test failure because invalid current unit configuration threw a domain exception instead of a localized validation error. Canonical configuration errors are now converted into a concise refresh/configuration message, and the 12-test focused rerun passes.
- Current tax configuration is rebuilt canonically and line totals/tax/discount/base quantity/conversion compared. Full parity of zero-value tax percentages, inactive master records and every configuration failure message remains part of the parent snapshot-policy review.
- No browser visual verification or Owner acceptance is claimed.

### Final combined result

**PASS: 98 tests / 376 assertions** (1m38s), comprising Purchasing (including all 12 new correction tests), Phase5D/PurchaseReturnReconciliationTest and Phase8/TradeIntegrityAndHistoryTest. Owned disposable MariaDB schema was cleaned by the runner. All prior 86 tests are included in this run. Final `git diff --check` passed. HEAD remains `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`; no commit was created. Parent can resume sole-writer review; full owner workflow scope remains incomplete as listed above.

## Multi-family implementation follow-up (sole writer, 7 November)

This section supersedes the earlier purchase-only scope and tenant-scan descriptions. It does **not** declare the full owner redesign/correction release accepted. Baseline and resulting HEAD remain `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`; every pre-existing dirty accounting, UI and correction change was preserved. No agents, worktrees, Git cleanup/reset/discard, commits, pushes, deployment, production access, external hooks or sandbox bypass were used. The primary UI router was `ui-ux-design`; repository AGENTS, DESIGN, UI workflow rules and the relevant specification/blueprint transaction, idempotency, return and void sections guided the native implementation. No separate frontend playbook/UI-profile file was found; DESIGN.md and UI_WORKFLOW_RULES.md supplied the project guidance.

### Implemented behavior

- **Purchase, Sales Invoice, Sales Return and Purchase Return** have a canonical correction service path. Posted Edit retains and voids the source and creates an unposted replacement; posted Delete retains and voids it. Explicit editable input goes through the original draft actions. No replacement is automatically posted. Failure at any later stage rolls back the outer transaction.
- Existing PurchaseCorrection remains the purchase adapter. TradeCorrection supplies the shared boundary for the other three families. Both bind encrypted expiring previews to fresh actor/company/source/mode/reason/date and reviewed dependency state. Receipt payload identity now includes the dependency digest. Malformed tokens produce safe validation; receipt retries reauthorize before returning the original result. Company serialization and unique company/request keys prevent duplicate replacements.
- **P1 tenant scan removed.** PurchaseDependencySnapshot and SalesDependencySnapshot use explicit, company/ID-scoped graph queries with selected columns and stable ID order. They read source lines/lots, immutable movements/posting metadata, associated returns/allocations/payments/application history, and relevant stock balances/cost states. Replacement reads include the actual product/unit/tax/party/warehouse/company language/currency configuration. No runtime schema enumeration, tenant-wide table discovery or `SELECT *` snapshot remains. Each graph query fails closed above 2,000 related rows; it never truncates the reviewed plan. Preview snapshots use read queries; execution locks the selected graph under the canonical company write transaction. Existing authorization/builders may still take bounded company, membership and relevant product/unit locks.
- Dedicated cash/bank customer/vendor payments are disclosed with number, exact amount/currency, account, signed money inverse and link, then reversed by ReverseCustomerPaymentAction/ReverseVendorPaymentAction in the outer correction transaction. Eligibility examines **all** allocations and application events of each linked payment. Every allocation must refer to the source, and original allocations must account for the entire original payment base amount. Initial excess credit, cross-document history, incomplete applications and check-backed payments remain intact and block correction. Checks link to their detail only when check-view permission permits; no bare check payment reversal or fabricated check transition is used.
- Eligible posted child returns are disclosed and canonically reversed newest-first before the parent. Purchase preview projects the exact stock/value restored by selected returns when assessing root shortages; root canonical guards still run after the children. Draft children have a localized open/finish/discard blocker. Sales-return quantity diagnostics aggregate selected children and identify consumed warehouse/lot stock without exposing restricted current balances. Original canonical valuation/inventory/GL verifiers remain final authority.
- The shared review shows each source item, named product/warehouse/lot and exact inverse base quantity, document balance cancellation, each selected payment and child return (including child stock inverses). Purchase shortages use item/lot names instead of raw product IDs. Permitted stock-product and locked-landed-cost expense links offer recovery. New detail and desktop/mobile index shortcuts use the same service boundary and shared row actions; the dialog uses x-dialog-shell, isolated numeric runs, loading guards and one final confirmation. No browser confirmation is stacked onto posted correction confirmation.
- New-family detail pages show reciprocal source/replacement provenance with fresh source-view checks. Purchasing pages clear cached review payloads when cost permission is revoked; dependent review permissions are rechecked before redisplaying cached new-family previews. Canonical mutation authorization remains fresh and independent.
- Sales-return replacement drafts now have a native edit route/form. CreateSalesReturnDraftAction reuses its existing canonical preparation/setters for a freshly authorized same-invoice draft update, preserving identity and language. DeleteReturnDraftAction discards only ordinary unposted return drafts; posted activity and correction-linked replacements are retained with a clear localized blocker. Purchase-return draft editing retains its existing action.
- Protected migrations `000001` and `000002` and their sealed purchase/return inverse boundaries were not changed. New forward migration `000004_create_trade_correction_receipts` creates separately typed source/replacement FKs for sales invoices, sales returns and purchase returns. It refuses down before dropping anything if any receipt exists. Existing `000003` remains purchase-only. The previous migration rollback test now includes all four new migrations.

### Exact files owned by this follow-up

New services under `app/Services/Corrections/`: `PurchaseDependencySnapshot.php`, `SalesDependencySnapshot.php`, `DedicatedPaymentPlan.php`, `PurchaseChildReturnPlan.php`, `CorrectionStockEffects.php`, `SalesReturnStockRead.php`, `TradeCorrection.php`; updated `PurchaseCorrection.php`.

New `app/Livewire/Concerns/CorrectsTradeDocument.php`; updated `app/Livewire/Pages/Sales/{InvoiceDetail,ReturnDetail,ReturnForm}.php`, `app/Livewire/Pages/Purchasing/{PurchaseDetail,PurchaseReturnDetail}.php`; updated `app/Actions/Sales/CreateSalesReturnDraftAction.php`; new `app/Actions/Sales/DeleteReturnDraftAction.php`; added `returns.edit` in `routes/web.php`.

New `database/migrations/2026_11_07_000004_create_trade_correction_receipts.php`; new shared `resources/views/components/correction-shortcuts.blade.php`; updated shared `correction-preview.blade.php`, sales invoice row actions, sales invoice/return detail, sales return form/index and purchase-return detail/index; expanded `resources/lang/{en,ar}/corrections.php`.

New `tests/Feature/Purchasing/TradeCorrectionTest.php` and `tests/Support/Corrections/execute-worker.php`; expanded `PurchaseCorrectionTest.php`; `VoidPurchaseReturnTest.php` rollback-step adjustment. Durable pass logs are in `docs/evidence/corrections-20261107/`. Other dirty files belong to the preserved parent work.

### Verification and limits

All database tests used the tracked `tests/Support/run-phase8-disposable.php` with `APP_ENV=testing`, `PHASE8_ALLOW_DISPOSABLE_DB=1`, and test settings host `127.0.0.1`, port `3342`, username `test_runner`, empty password. Secondary migration/race schemas used the same ownership-proof helper and were restored/dropped. No test was run against 3353 or the persistent app database.

- Broad regression **156 tests / 1,422 assertions PASS**: Purchasing, Phase5D/PurchaseReturnReconciliationTest, Phase8/TradeIntegrityAndHistoryTest, Phase4/SalesReturnTest, Phase5E/VendorPaymentReversalTest, Phase5E/VendorPaymentIntegrityAndBoundaryTest, and both SalesVoidAllocationCompleteness/IntegrationDiagnostics suites. This includes the previous 98-test scope. This run preceded the final additive stock diagnostic/cached-review redaction hardening; the final focused run below covers that final source.
- A real two-PHP-process race on an owned secondary MariaDB schema passed: both executions returned the identical replacement; exactly one receipt and one draft remained. The shared TradeCorrection sales-invoice receipt boundary was exercised concurrently; there is no claim that every family/payment combination has a separate process-race test.
- Bounded-read regression seeds 250 unrelated same-company product rows, changes another unrelated product and creates activity/configuration for another company. Snapshot contents, selected row count and query count remain identical, read queries contain no `SELECT *` or `FOR UPDATE`, and the original preview still executes. Relevant changed product configuration, added payments/returns and stale previews remain guarded.
- Focused cases cover all six new-family Edit/Delete combinations; provenance/retry; dedicated customer/vendor payments; shared initial credit protection; posted child return ordering; named consumed-stock blockers; real Livewire details/indexes/form editing; original-note/quantity preservation; fresh permission revocation; migration roundtrip/history refusal; and rollback after payment/stock/GL/draft creation when receipt insertion fails.
- Production build PASS (no source maps), mobile-shell Node tests **9 PASS**, Blade compilation PASS. Exact final focused/Pint/PHPStan evidence is appended below. No full 3,800-test run or browser/server use occurred.

### Explicit remaining scope before owner release acceptance

1. **Sales correction business dates are company-local today only.** Existing sealed sales void actions use today for stock/GL/lifecycle. The adapter validates that limitation instead of recording a false historical date. Historical sales correction dates require a forward lifecycle/read-model change and independent qualification. Purchasing keeps its existing explicit business-date support.
2. Shared/initial-credit/cross-document payment unapplication, check transitions and landed-cost reversal are deliberately unsupported compound corrections. They remain localized blockers with permitted recovery links; no new financial event boundary was invented. Dedicated ordinary cash/bank payment flows are implemented.
3. Draft child returns require their own finish/discard operation before parent correction. Replacement drafts cannot be physically discarded because durable correction FKs preserve their provenance. Ordinary unlinked return drafts can be discarded.
4. Canonical stock consumption, lot deficits, carrying/valuation mismatches and locked landed costs can still block. Quantity shortages and purchase stock/landed-cost recovery are presented; the full sales-return moving-average/value verifier remains an execution-time canonical guard. Some exact replacement configuration failures in the new sales path are discovered during atomic draft construction, after confirmation, and roll back everything; no read-only clone simulator was introduced.
5. The final suite is meaningful targeted evidence, **not the entire requested combinatorial matrix**. Separate process races for each adapter, every cross-currency/fractional-lot/tax permutation, every check/application-event authorization permutation, and injected intermediate failures per new adapter remain parent qualification work. Existing foundation fractional/historical integrity suites were retained and the broad selected regression passed. No separate complete six-domain reconciliation command run is claimed beyond the named reconciliation/integrity suites.
6. Receipt tables have restrictive typed FKs and application-private writers, but no new trigger-level receipt immutability enforcement.
7. Analogous standalone reversal UIs were inspected, not unified: sales/vendor payment detail reverse modals still need full affected-allocation review/shared-shell conversion; payroll advance/salary-entry browser confirmations and salary-payment controls need affected-record review; expense and money-transfer reversals retain their existing subsystem UI. Those views were not recast into a different economic subsystem here.
8. Browser visual/focus/touch/EN/AR/RTL qualification, broad redesign acceptance, complete release QA and parent source review are outstanding. Sidebar aria/state fixes, dashboard permission gates, login errors and parent UI documentation were preserved. No release/deployment approval is implied by these tests.

### Final follow-up gate evidence

- **37 tests / 200 assertions PASS** on the final focused correction suites (45.682s), including the process race, disposable migration roundtrip/history refusal, real shared-dialog/detail/index paths, native replacement-draft editing, quantity diagnostics and revoked-cost cached-payload redaction. Owned primary and secondary schemas were cleaned.
- After the final permitted payment/check recovery-link merge/deduplication, the dedicated-payment cases were rerun: **2 tests / 10 assertions PASS** (4.166s). These overlap the focused suite; they are not added to its test total.
- Changed production PHP PHPStan exited **0**; changed PHP Pint `--test` **PASS**. Final Blade compilation **PASS**. Build **PASS**, mobile-shell **9 tests PASS**, no built source maps found, and `git diff --check` **PASS**.
- Evidence files: `focused-37.txt`, `payment-links.txt`, `race.txt`, `regression-156.txt`, `phpstan.txt`, `pint.txt`, `blade.txt`, `build.txt`, `mobile-shell.txt` under `docs/evidence/corrections-20261107/`. Source-only tests and local compilation are evidence; no visual qualification or owner acceptance is claimed.
- HEAD remains `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`. No commit, push, merge, deploy or production access occurred. Parent may resume sole-writer source review with the limitations above.

## Independent implementation follow-up — four findings and standalone review

Baseline and final HEAD verified as `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`. Existing extensive dirty checkout retained. This section supersedes the earlier statement that standalone dialogs have not been unified; those changes were already present when this follow-up began. Source and tests were reviewed directly; no separate standalone handoff document was found in this correction handback.

### Findings resolved

1. `CorrectsTradeDocument::correctionHistory()` no longer enters TenantWriteAccess or locks company/membership rows. It uses the existing fresh, matching-company MoneyActorGuard read authorization, including cached-preview dependent permissions. TradeCorrection authorization now supports this explicit read path; canonical preview/execution retain their default locking authorization and write transactions. A SQL-listener regression checks the history method itself. Full invoice Livewire mounting still produced eight company/membership locking queries elsewhere in its rendering path in the exploratory test; this follow-up does **not** claim all page rendering is lock-free.
2. TradeCorrection hashes the explicit economic graph, raw payment snapshot and selected payment/return IDs rather than translated blockers, links, stock names and account display rows. Locale changes no longer invalidate the reviewed economic state. Cross-locale execution with a child return passes. PurchaseCorrection already hashes its raw dependency graph and required no equivalent change.
3. CorrectionStockEffects keeps source movement references in company-scoped SQL subqueries instead of hydrating unbounded ID lists. Its output query fetches at most 2,001 rows and rejects above 2,000 without truncating a successful review. A disposable DB fixture with 2,001 source movements proves rejection. This is a fetched-row bound per stock-effect review, not a universal query-time or whole-cascade capacity guarantee.
4. EN/AR correction copy now says “Reverse the document total” / “عكس إجمالي المستند”. The displayed value remains the gross document total; it is no longer described as the outstanding balance. Existing `balance` payload/key retained for compatibility.

### Standalone reversal completion

Reviewed ReviewsStandaloneReversal, Services/Reviews/StandaloneReversalReview, the shared Blade dialog, and customer/vendor payment, expense, payroll and transfer call sites. Private reviewed executors still call the original canonical actions under the review transaction; no financial writer, decimal arithmetic, history mutation, permission bypass or new schema was added.

- Added a trait render hook that rebuilds authorized display rows only while a review is open, using bounded reads without row locks. Revoked related-document read permission removes its cached row before serialization; revoked review authority closes the review. Reviewed economic digest remains unchanged until explicit stale-review handling.
- Standalone confirmation compares actor/kind/id/method/digest/blocked state independently of translated presentation. Locale changes do not demand another economic review; changed economic dependencies still do.
- Fixed the vendor stale-permission regression: it now opens the review before revoking permission, then asserts confirmation is forbidden. Previously it chained a second request after an already-forbidden open request, causing an invalid Livewire snapshot error. Pint also removed one pre-existing whitespace-only blank line in that test.

### Files deliberately changed in this follow-up

- `app/Livewire/Concerns/CorrectsTradeDocument.php`
- `app/Services/Corrections/TradeCorrection.php`
- `app/Services/Corrections/CorrectionStockEffects.php`
- `resources/lang/en/corrections.php`, `resources/lang/ar/corrections.php`
- `app/Livewire/Concerns/ReviewsStandaloneReversal.php`
- `app/Services/Reviews/StandaloneReversalReview.php`
- `tests/Feature/Purchasing/TradeCorrectionTest.php`
- `tests/Feature/Phase4/StandaloneReceiptReviewTest.php`
- `tests/Feature/Phase5E/VendorPaymentUiTest.php`
- This handback and additive `followup-*` evidence files under `docs/evidence/corrections-20261107/`.

No migration changed. Several files above were already untracked from prior work; they remain untracked. Existing unrelated dirty files were preserved. Build output is ignored generated local output.

### Actual verification

All DB tests used the documented isolated runner with `APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1`. No test used persistent preview port 3353. Owned test schemas were cleaned by the runner.

- `followup-focused-final.txt`: **49 tests / 284 assertions PASS** (56.123s): TradeCorrectionTest, PurchaseCorrectionTest, StandaloneReceiptReviewTest (before its two new follow-up tests), StandaloneReversalReviewTest and TransferReversalValidationTest. Includes the existing process-race, migration/history, retry and rollback regressions. The stock-bound fixture was added afterward and tested below.
- `followup-additional-final.txt`: **37 tests / 158 assertions PASS** (36.716s): all three StandaloneReceiptReviewTest cases, VendorPaymentUiTest, MoneyUiTest and the new 2,001-movement stock-bound case. Suites overlap; these totals are not additive unique-test counts.
- Final changed-production PHPStan: **PASS**, five files, no errors (`followup-phpstan-final.txt`).
- Final selected-file Pint `--test`: **PASS**. Formatting run evidence in `followup-pint-final.txt`.
- Blade compilation: **PASS** (`followup-blade.txt`). Production Vite build: **PASS**, no built `.map` files (`followup-build.txt`). Mobile shell Node tests: **9 PASS** (`followup-mobile-shell.txt`). Final `git diff --check`: **PASS**.
- Intermediate evidence is retained honestly: first 42-test run had an overbroad full-page lock assertion; first broader standalone run had the invalid forbidden-request snapshot sequence; first stock fixture duplicated its idempotency key. The narrowed reader regression, corrected permission sequence and unique-key fixture passed in the final runs.

### Limits / independent review handback

Browser, visual layout, focus, touch and EN/AR RTL geometry remain **NOT VERIFIED**; no browser was opened. Owner acceptance remains pending. No full-suite run, new full reconciliation batch, audit or deployment qualification is claimed; economic writers were unchanged. Existing unsupported shared/check/credit and landed-cost compound corrections remain blockers. Per-query graph bounds do not establish aggregate performance for thousands of child documents. Full invoice rendering has additional locking queries outside the history reader, as noted above.

No account switching, children/external agents, hooks, live fallback, sandbox bypass, persistent financial data access, production access, commit, push, merge or deployment occurred. Parent independent source review remains required.


## Parent fixes after independent acceptance review — 7 November

The Huda independent review found two P2 objections, with no P0/P1 finding, and independently passed 68 tests / 373 assertions. Parent verified and fixed both objections directly without launching another writer.

- Added `CorrectionPresentationAccess`: purchase and trade previews capture the relevant granted recovery/read capabilities. Purchase rendering and trade history rendering freshly authorize those capabilities and close/clear the entire cached preview after revocation. This conservatively refreshes presentation rather than leaving cached expense, check or stock recovery identifiers/URLs serialized. Older cached previews lacking the permission metadata also fail closed. Permission metadata is presentation-only and excluded from the encrypted economic digest; canonical execution authorization remains independent.
- Both TradeCorrection and PurchaseCorrection execution now acquire the company lock before reading the source, then freshly authorize and lock the source. This preserves canonical company-before-source ordering and avoids reading a source snapshot before waiting for company serialization.
- Added real Livewire refresh regressions for expense/check/stock read revocation in both purchase and sales-invoice correction dialogs, and query-order execution regressions for both correction paths.
- An intermediate 43-test run exposed MariaDB error 1020 in the existing two-process race when source reading still preceded company serialization. Moving company locking ahead of the initial source read fixed it; the next run passed 43 tests / 235 assertions, including the race. Purchase received the same safeguard afterward; final verification follows below.

Changed in this parent follow-up: `app/Services/Corrections/{CorrectionPresentationAccess,TradeCorrection,PurchaseCorrection}.php`, `app/Livewire/Concerns/CorrectsTradeDocument.php`, `app/Livewire/Pages/Purchasing/PurchaseDetail.php`, `tests/Feature/Purchasing/{PurchaseCorrectionTest,TradeCorrectionTest}.php`, and this handback. No migrations or assets changed in this follow-up. All existing dirty work and untracked artifacts were preserved. Baseline/resulting HEAD remains `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`. No commit, push, merge, deployment or production access occurred. Browser/visual/focus/RTL geometry and full release qualification remain pending; earlier unsupported compound flows remain unchanged.

Final parent verification: **44 tests / 239 assertions PASS** (56.155s), full PurchaseCorrectionTest and TradeCorrectionTest on final source, including both lock-order regressions, six permission-revocation refresh scenarios and the two-process race. Used the documented owned disposable MariaDB runner at `127.0.0.1:3342`, `test_runner`, intentionally empty password; owned schema cleanup confirmed. Final PHPStan for all five changed production PHP files **PASS**, selected-file Pint `--test` **PASS**, and `git diff --check` **PASS**. No additional frontend build was needed for this PHP-only follow-up; earlier build evidence remains separately reported. These results overlap prior suites and are not additive unique-test counts.
