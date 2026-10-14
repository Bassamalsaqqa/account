# PR #44 / #46 integration verification

**Latest correction:** the partial-allocation finding raised against publication head `e1b3b95` is reproduced and source-corrected in `7861536b8571daf1605a443e792f89bb3bfc1735`. See the final section below. Independent exact-head re-review is pending; no merge or deployment is authorized by this record. The preceding integration evidence remains historical evidence of its named source.

## Disposition

Integration candidate for independent exact-head re-review; **not cleared for Owner merge or production release**. Both original PR heads remain unchanged, draft and unmerged. They were first combined without edits, then two narrowly verified defects were corrected on the separate integration branch. A later independent PR #46 review pauses acceptance; this record supplies source and test evidence without overriding that review.

## Exact source and merge order

| Identity | SHA |
| --- | --- |
| Common main/base | `7fdb22b271266efd965737777f4cb08ba5bf0b2a` |
| PR #46 | `3985fdb4ba0340fa942fb26723ca05d0b9017d87` |
| PR #44 | `7214dfe1b8eadda7c217d97e9cc10706ac07b130` |
| Local ordinary merge of #46 into base | `f1a22bc6467301b90279fbd6cd6a324388ece6d2` |
| Local ordinary merge of #44 after #46; initial tested union | `448a7a7ea14530fe5fac15140bafac909d3a2a22` |
| Initial union tree | `daf83783b09e293c158496a12e7be49b26647ec4` |
| Sales currency rendering correction | `35adae4a2f712756282d9a6cad01c94b8eef05d8` |
| Final corrected implementation and tests, actually executed | `62bf7231468630542a50a00838b801b12a4056b0` |
| Final implementation/test tree before this evidence document | `fed7d2741669461d894cd1234e4f1bc6957edb50` |

Branch: `integration/product-stock-20261014`. Both merges used `--no-ff`, preserved the published parents and completed without conflicts or manual runtime edits. These are isolated verification merges, not GitHub merges into main.

The candidate preserves **#46, then #44** ancestry. After independent acceptance of the corrected integration head, the Owner can merge this integration candidate normally. Neither old PR head alone contains the integration corrections. If the Owner instead merges the original PRs sequentially, use #46 then #44 and include the correction commits before any release. GitHub will generate different merge commit IDs; compare the resulting implementation tree with the reviewed integration tree, not these local merge IDs alone.

## Feature preservation

At the initial merge `448a7a7`, all PR #44-exclusive paths are byte-identical to its reviewed head; all PR #46-exclusive paths are byte-identical to its reviewed head. The only three overlapping paths are:

- `app/Actions/Inventory/AdjustStockAction.php`
- `app/Actions/Inventory/DisposeExpiredStockAction.php`
- `app/Actions/Inventory/PostOpeningStockAction.php`

Their integrated bytes exactly match #46, which already incorporated #44's accepted company-timezone defaults. Canonical action-bound stock/posting safeguards and explicit historical dates are retained together. Dashboard, month-to-date comparisons, invoice-specific AI answers, RTL/LTR presentation, maintenance-held deployment and Hostinger web-configuration preservation are unchanged from accepted #44.

The unchanged implementation union changes 141 paths against the base. No migration, dependency manifest or lockfile changes are present. No production, credentials, financial data, budgets, usage reservations or protected Owner artifacts were accessed or modified.

## Review chronology and merge hold

The [18:13 UTC source acceptance](https://github.com/Bassamalsaqqa/account/pull/46#pullrequestreview-5522503323) was followed by a [18:28 UTC follow-up review](https://github.com/Bassamalsaqqa/account/pull/46#pullrequestreview-5522725224) at the same #46 SHA. The latter pauses unconditional acceptance for:

1. Missing immutable Sales allocation history potentially allowing a stock-bearing invoice/return void to reverse GL without compensating stock, including zero-cost stock. This is a corrupt-history fault-injection scenario, not a claimed normal-user exploit or observed production incident.
2. Sales-void movement and accounting reversal dates not consistently using one captured company-local day at a UTC/company-date boundary.

The previously fixed offsetting unsupported Inventory-GL reconciliation defect is not reopened. No historical data repair or speculative engine redesign is proposed here.

## Bounded integration corrections

1. **Confirmed Sales form rendering defect:** PR #44 introduced a customer-currency fallback referencing `$company`, which `InvoiceForm::render()` does not supply to the view. Customers without a default currency cause `Undefined variable $company`; six product-selection tests reproduced this. The correction passes only the freshly authorized company's base-currency string to the view. The existing seven product-selection checks plus a new explicit Arabic/English null-currency check pass: **8 tests / 40 assertions**, no economic writes. No customer currency or invoice amount is changed.
2. **Confirmed Sales-void calendar mismatch:** the new timezone diagnostics reproduce October 14 movement/GL dates instead of the October 15 Hebron business day at October 14 23:30 UTC. Capture one company-local day under the canonical locks and pass it to both compensating stock and the existing accounting reversal service. The private helper forwards an optional date; general reversal defaults and historical dates remain unchanged.
3. **Missing-allocation hypothesis not reproduced as a committing bypass:** the diagnostic reached existing `SalesInvoice`/`SalesReturn` model guards, which throw `ImmutableRecordException` when a stock-bearing void lacks compensating movements. No new protection or engine rewrite was added. Final regressions require that known domain exception and unchanged post-corruption economic/provenance snapshots, for invoice and return at both nonzero and zero costs. Legitimate service-only voids remain positive controls. This disposition is limited to the review's precise synthetic scenarios, not a certification of all damaged historical data.

## Verification evidence

The initial, unchanged merged source was tested under PHP 8.4.25 / isolated MariaDB 10.4.32 using `tests/Support/run-phase8-disposable.php`. Result: **346 tests / 2,325 assertions / 6 errors / 0 assertion failures / 4 skips**, 556.507 seconds. All six errors are the same Sales-view variable defect above. The four Windows symlink-contract skips are explicit; Linux coverage is not claimed. The owned primary and concurrency fixture schemas were cleaned by their guards; old quarantined schemas were not adopted or dropped.

The initial run includes stock authority, canonical opening/adjustment/expiry/transfer, payload idempotency, fresh RBAC/company isolation, rollback, Stock event concurrency, Sales/return/void, Purchase/return, Accounting reversals, contextual AI, dashboard/MTD and the six-domain synthetic reconciliation/fingerprint check. It is one bounded compatibility run, not a full-suite certification. Do not relabel its errors PASS because a later subset passes.

Pint and full PHPStan pass on the unchanged merge. The production Vite build succeeds (62 modules), and **50 Node voice/memory/PWA tests pass**, zero failures/skips. No GitHub CI was run. Build and Node assets are unchanged by the two subsequent server-side corrections.

**Final corrected-source run at `62bf7231468630542a50a00838b801b12a4056b0`: 174 tests / 1,652 assertions, zero errors, failures or skips**, 188.618 seconds (PHPUnit display 03:08.657), 100 MB peak. The owned primary schema was cleaned. This is a new, narrower combined rerun justified by the two reproduced defects, not a repeated whole application suite. Its 22 explicit targets include canonical stock/accounting integrity, both Sales void date cases, missing-allocation fault injection, service-only controls, exact Accounting and Inventory recognition, Sales/Purchasing returns and rollback, company dates, product search/role downgrade/company switch, current-invoice context, dashboard, briefing, record context, MTD boundaries and integrated report truth.

- The **8 void regressions / 174 assertions** pass. All four corrupt-allocation cases reject and preserve the complete post-corruption snapshots, including zero-cost stock. The two no-stock service controls pass. Invoice and return stock/reversal dates both use October 15 at the UTC/Hebron boundary, all three relevant reconciliations remain healthy, and retries leave the economic/provenance snapshots unchanged.
- The **8 product-selection/rendering checks / 40 assertions** pass, including the new explicit AR/EN missing-default-currency scenario. Product/unit selection, editable amounts, company isolation and downgraded-role denial remain covered.
- The **integrated report truth check / 30 assertions** passes all six synthetic domain reconciliations (Accounting, Inventory, Sales, Payables, Money, Phase 7) and verifies exact report values and unchanged economic fingerprints. This is not a read of production books.
- Targeted final Pint and PHPStan pass for the changed PHP source. The initial full static gates, 62-module frontend build and 50 Node checks are carried from `448a7a7`; their unrelated source/assets did not change. The final subset does not rerun the original Stock opening races or Windows deployment contracts; those passed/explicitly skipped as recorded in the initial run.

The first diagnostic attempt produced two genuine timezone failures and four uncaught `ImmutableRecordException` errors, plus two passing service controls. Codex corrected the test harness to capture the specific known domain refusal and assert unconditional unchanged-state snapshots; it did not remove a financial guard or reinterpret an unexpected exception as success. The new final regressions supply the missing rollback proof.

AGY implemented the bounded diagnostics and date forwarding in an isolated worktree. Codex independently inspected its diff, corrected assertions, copied only the four owned files, and executed the gates. The accepted source branches remain at their original SHAs. Private command logs/JUnit reports and relay results remain ignored under `.ai/integration-44-46/`; the worker worktree intentionally retains its three modified source files and one untracked diagnostic test for auditability. The same diagnostic test is tracked in the integration candidate. No protected Owner originals or private evidence were staged.

The final publication commit adds only this evidence document to tested implementation `62bf723`; it does not change implementation, tests or assets. Automated checks use synthetic data and mock provider responses; they do not establish live DeepSeek intelligence, physical microphone accuracy, Hostinger behavior or real recovery readiness.

## Correction inventory requiring independent re-review

Compared with the unchanged merged source `448a7a7`, the correction changes five runtime paths and two test paths:

- `app/Actions/Sales/VoidSalesInvoiceAction.php`
- `app/Actions/Sales/VoidSalesReturnAction.php`
- `app/Services/Inventory/OwnsStockEvent.php`
- `app/Livewire/Pages/Sales/InvoiceForm.php`
- `resources/views/livewire/pages/sales/invoice-form.blade.php`
- `tests/Feature/ProductExperience/ProductSelectionSearchTest.php`
- `tests/Feature/StockAccountingIntegrity/SalesVoidIntegrationDiagnosticsTest.php` (new)

This record is the eighth correction/evidence path. The final union against main has 143 changed paths, including the original accepted work. No formula, ledger writer, schema, dependency, permission, AI transport, budget, provider dispatch or financial history change is introduced by the integration corrections. No new unresolved failure exists in the executed subset; independent exact-head acceptance is still pending.

## Remaining controlled production-release requirements

- Resolve the newer #46 findings, obtain independent exact-head acceptance, repeat the relevant changed-source integration checks and obtain explicit Owner GitHub merge/release authorization.
- Follow the [controlled release checklist](CONTROLLED_RELEASE_CHECKLIST.md): coherent protected recovery checkpoint and rollback source/build, maintenance using current deployed code before checkout, preserved Hostinger `.htaccess`, secrets, assets, uploads and financial-row/schema fingerprints. No migration/bootstrap opt-in is needed by this union.
- Execute all six strengthened reconciliations read-only on actual existing books under authorized maintenance. A new discrepancy requires private evidence and an Owner-reviewed resolution; never rewrite historical records to obtain a green result.
- Verify authenticated AR/EN dashboard, Settings, reports, Sales/Purchase forms, API/Livewire and fresh logs on actual Linux; check HTTPS assets/web-manifest MIME, file preservation and intended feature flags. Reopen only after explicit authorization and successful gates. Preparation scripts never reopen.
- Independent off-host backup/key recovery, isolated restore and real account-recovery mail remain separate operational requirements. Synthetic tests do not close them. No live model call or financial posting is required by this verification assignment.

## PR #47 final partial-allocation correction

### Review and red reproduction

Baseline: `e1b3b95bf1a497d850c2a0c3f30884447153efb8`. Controlling finding: [independent PR #47 review](https://github.com/Bassamalsaqqa/account/pull/47#pullrequestreview-5523478020). This is a conditional corrupt-history integrity gap, not evidence of production corruption or a normal-UI exploit.

Two initial disposable probes canonically sold/returned three zero-cost units across two expiry lots, then deleted exactly one allocation while retaining the original immutable movement and another valid allocation. Both canonical voids **committed**: only one unit was compensated while the entire document and GL became void/reversed. The probes failed their expected no-commit assertions: **2 tests / 8 assertions / 2 failures**. Their sanitized metadata distinguishes actual commit from a merely source-reasoned hypothesis.

The full baseline matrix then executed **12 tests / 266 assertions**, with **6 complete-document controls passing**, **5 failed assertions** and **1 error**. Four partial-loss cases (zero-cost lots and mixed-cost product lines, Invoice and Return) did not reject; nonzero-cost partial loss already refused through existing valuation checks, but not the intended provenance category. No fixtures, allocations, movements or cost snapshots were rewritten to make a test green. All injections use explicit synthetic SQL, never production data.

### Canonical correction

Only `app/Services/Inventory/StockEventScope.php` changes runtime behavior. No new writer, transport, migration, observer side effect or domain engine is introduced.

- Before recording compensation, reconstruct **all** same-company original Sales movements and require a bijection to the document's immutable allocation rows. Validate document/line/product identity, movement type/sign, lot, exact base quantity, historical unit cost and value. An existing non-null movement source-line reference must agree; canonical Sales uses nullable document-level movement references and retains its allocation-based line authority.
- Before accounting reversal, independently query the persisted compensations. Require full quantity cancellation by product/warehouse/lot, invoice compensation by each original movement ID, and return compensation by the exact multiset of original returned quantities. Return voids keep their existing outbound costing and no-`reversal_of_id` semantics; no historical pointer is fabricated. Zero value cannot waive physical quantity completeness.
- Recheck at scope completion and use the actual persisted compensation set for the Inventory GL comparison, including ordinary idempotent retries and no-stock documents. Existing company-first locks, fresh permissions, transaction ownership, reciprocal GL linkage and rollback protections remain.
- Refuse missing/inconsistent provenance using **`InvalidInventoryMovementException` with exact diagnostic `sales_void_stock_provenance_incomplete`**. The four older all-missing tests now require that class/category, not a broad exception union. Unrelated authorization, precondition or test-assertion errors cannot satisfy their expected refusal.

Valid complete multi-lot, mixed-cost, zero-cost and service-only voids remain supported. Posted history is immutable; damaged history is rejected and still detected read-only, without automatic repair. This proves the review's bounded scenarios, not every conceivable historical corruption.

### Verification actually executed

All database runs used the existing owned-schema runner, PHP 8.4.25 and local isolated MariaDB 10.4.32. No full application suite or GitHub CI was run.

| Run | Actual result | Scope and limits |
| --- | --- | --- |
| First correction compatibility iteration | 124 tests / 1,758 assertions / 6 errors; 188.329 s | Detected an overly strict nullable source-line check on valid Invoice voids. Corrected before publication. No hidden PASS claim. |
| Final corrected compatibility | **120 tests / 1,845 assertions, zero errors/failures/skips**; 101.237 s, 92 MB | Canonical stock authority, corruption/reconciliation, rollback/idempotency, fresh tenant/RBAC, Sales Invoice/Return/void, Accounting reversals, Purchasing boundaries/returns, company dates and integrated report truth. Includes all six synthetic reconciliations and unchanged economic fingerprints. |
| Final expanded void verification | **28 tests / 832 assertions, zero errors/failures/skips**; 33.831 s (display 33.839), 80 MB | Final 20-case completeness suite plus 8 earlier service/full-loss/timezone diagnostics. Six complete controls, six partial-loss refusals, six wrong-quantity/lot/movement-ID refusals, two deliberately incomplete persisted-compensation rollbacks. |
| Scoped concurrency evidence | **4 tests / 137 assertions PASS**, included in the first 124-test iteration | Identical/conflicting opening-stock races, role downgrade and membership revocation under company lock. These are existing Stock scope races, not new Sales-void races. Their code/path is unaffected by the subsequent nullable Sales-line condition correction; not redundantly rerun or counted as final-subset tests. |
| Final formatting/static | **Pint PASS; targeted PHPStan PASS** | Changed PHP runtime and test formatting. No frontend assets or schema changes, so no repeated build or migration round-trip is claimed. |

The 120-test and 28-test runs overlap; do **not** add their totals as unique coverage. The final 28-test run follows two added post-stock fault-injection tests and leaves the passing runtime source unchanged. Those tests run the real canonical stock engine, delete one newly created compensation inside the test transaction, require the precise pre-reversal refusal, and prove rollback of stock, balances, lots, GL, lifecycle, sequences and operation/provenance rows. Accounting, Inventory and Sales reconciliations remain healthy after rollback. Partial-input-loss tests assert reconciliation still reports the injected corruption and that the audit itself changes no rows.

AGY implemented the initial bounded regression matrix and independently reviewed the guard. Codex corrected PHPUnit data-provider metadata, tightened/expanded cases, implemented the canonical scope change and independently ran all checks. AGY independently identified the same nullable Sales source-line issue found by compatibility testing; it was corrected before the final green runs. Source implementation/tests are committed at `7861536b8571daf1605a443e792f89bb3bfc1735` (tree `2ee9fcf314d2250208f8955fd164e11b76dd800f`); the subsequent publication commit changes only this evidence document.

### Scope, custody and remaining gates

This correction changes **four paths** against the reviewed head: one runtime file, the new `SalesVoidAllocationCompletenessTest.php`, tightened `SalesVoidIntegrationDiagnosticsTest.php`, and this evidence record. Existing Dashboard/UI/AI, company-timezone changes and the resolved unsupported-source GL audit are preserved. No migration, dependency, formula, pricing, budget, credential, permission catalog or historical business record changes are introduced. Original #44/#46 branches remain unchanged.

Private logs/JUnit/AGY artifacts remain ignored under `.ai/pr47-allocation/`. The worker worktree deliberately retains one modified scope file and one untracked test file; the independently reviewed final versions are tracked in the integration branch. Owner originals and unrelated worktrees remain untouched.

The initial two-case probe's PowerShell wrapper stopped on native stderr before completing runner cleanup. One newly created synthetic schema remains **quarantined locally**, isolated from application/production, without an available private cleanup capability. Automatic approval review rejected a proposed cleanup fallback because logs and row counts do not establish ownership. No fallback was applied or schema deleted. Its disposition requires explicit scoped cleanup authorization; older quarantined schemas were neither adopted nor dropped. All later baseline/compatibility/final runs reported owned-schema cleanup normally. This custody item does not excuse or replace source acceptance.

**Ready for independent exact-head source review, not merge or deployment.** No production access, paid AI calls, GitHub merge, deployment, financial-history repair or usage reset occurred. Independent off-host recovery, actual Linux/Hostinger checks, six read-only live reconciliations, preservation fingerprints, rollback and separately approved reopening remain the controlled release requirements above.
