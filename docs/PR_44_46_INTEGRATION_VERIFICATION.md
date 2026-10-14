# PR #44 / #46 integration verification

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
