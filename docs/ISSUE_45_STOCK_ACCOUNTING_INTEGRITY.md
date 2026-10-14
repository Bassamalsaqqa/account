# Issue #45 ? Stock and accounting integrity correction

Source baseline: `7fdb22b271266efd965737777f4cb08ba5bf0b2a`.
Branch: `fix/stock-accounting-integrity-45`. Independent architect acceptance pending.

## Confirmed defect

[Issue #45](https://github.com/Bassamalsaqqa/account/issues/45) demonstrated an internal business-event boundary gap: a permitted caller could invoke the generic stock primitive, independently commit valued opening stock or a sale referencing a nonexistent invoice, and receive a healthy inventory audit without corresponding GL recognition. This predates PR #44. No external HTTP exploit or actual production imbalance has been established; production was not inspected for this correction.

## Correction and preserved behavior

- [InventoryMovementService](../app/Services/Inventory/InventoryMovementService.php) rejects direct economic `record()` calls. Canonical opening, adjustments, expiry disposal and Sales post/return/void actions use [OwnsStockEvent](../app/Services/Inventory/OwnsStockEvent.php) and [StockEventScope](../app/Services/Inventory/StockEventScope.php). Only an executing allowlisted concrete action can prepare a one-use capability for its exact movement command. An idle action or arbitrary open transaction grants no authority.
- Authority binds authenticated actor, active company, source identity, live connection/PDO and the original Laravel transaction record. Fresh permissions and membership are checked under company-first locks. Rolled-back/replaced transaction records and stale/forged capabilities are refused.
- Before commit, the boundary verifies actual persisted stock, document lifecycle, prepared accounting payload and inventory GL effect. Numbering, stock, allocations, accounting and lifecycle remain in the existing atomic canonical transaction. Mocked/unpersisted GL results cannot bless stock-only completion.
- [AccountingPostingService](../app/Services/Posting/AccountingPostingService.php) requires the action-prepared command for Inventory/Sales sources and the existing strengthened [PurchasePostingScope](../app/Services/Purchasing/PurchasePostingScope.php)/[PurchaseReturnPostingScope](../app/Services/Purchasing/PurchaseReturnPostingScope.php) for Purchasing. Persisted source identity protects reversal checks from a dirty caller model. Raw inventory/Purchasing GL reversals are denied; Sales voids and canonical Purchase Returns retain their accepted semantics.
- Paired warehouse transfers remain canonical stock-only events and preserve company quantity/value. Their permission is rechecked after obtaining the company lock. Valid zero-cost inventory events and all-economic-zero Purchase Returns retain their existing no-empty-GL policy. Paid Sales still require GL even if stock cost is zero.
- [InventoryReconciliationService](../app/Services/Inventory/InventoryReconciliationService.php) always compares valuation with the inventory control account. [StockAccountingAudit](../app/Services/Inventory/StockAccountingAudit.php) checks both stock-to-document/batch and batch-to-source links, same-company lines, immutable Sales allocations and per-event inventory GL values. Unknown sources, missing GL/control accounts, duplicate inventory-event recognition and offsetting orphan batches cannot evade the audit through an aggregate zero balance. It recognizes the actual `expiry_disposal` source. Existing Purchasing provenance and valuation replay checks remain in use.
- Audit data is prefetched and indexed by document/product, source/movement and batch; no new per-movement SQL queries or repeated full-collection joins are introduced. This remains an administrative full-history audit, not a new online accounting engine.

No economic formula, tax/FX policy, costing/FEFO algorithm, posted history, database migration, UI, production credential or business record is rewritten. AccountingPostingService remains the only GL writer; stock effects retain the existing inventory engine. No new dependency or hosting service is required.

## Verification record

Codex independently inspected AGY's bounded Purchasing, fixture and test handbacks and corrected observed fixture defects. AGY did not commit or publish. Its last documentation task hit quota; Codex completed the handback.

Original submission verification at `201bc65840d1d2c727760a7e2d471abdb22517c7` (carried evidence, not a rerun at the correction head):

| Gate | Result |
| --- | --- |
| Canonical stock/GL boundary, rollback, tenant and concurrency subset | PASS: 46 tests / 914 assertions (included in the combined run below) |
| Final combined Inventory/Sales/Purchasing regressions | PASS: 590 tests / 3053 assertions; 0 failures, errors, skips or notices; 9m44s, 130 MB |
| Shared posting/reversal, payment, reconciliation, historical reporting compatibility | PASS: 253 tests / 1223 assertions; 0 failures, errors or skips; includes all six reconciliations on synthetic fixtures |
| Full-repository Pint / PHPStan | PASS; no formatting or static-analysis errors |
| Local production frontend build | PASS (62 modules); assets remain ignored |
| Whitespace / source scope / private artifact preservation | PASS: 45 intended source/test/document files; no migrations, dependencies, protected originals or private evidence in publication scope |

Earlier development runs exposed a temporary test-key setup error, fixture mismatches and missing local Vite assets; these were corrected before the clean combined result above. QA uses full-repository Pint/PHPStan plus the guarded relevant suites, rather than invoking the unguarded `composer qa` full-test command. Unrelated suites and GitHub CI were not run. Tests use `tests/Support/run-phase8-disposable.php`, fresh owned MariaDB schemas and synthetic keys. They do not use persistent production data. The negative historical reproductions deliberately inject corrupted synthetic rows; reconciliation is asserted read-only. Primitive costing/lot/hash tests use [InventoryPrimitiveFixture](../tests/Support/InventoryPrimitiveFixture.php), restricted to testing and an owned disposable primary schema. This white-box test helper is never an application binding or production bypass, and never proves healthy canonical financial acceptance.

New coverage is in [StockAccountingIntegrity](../tests/Feature/StockAccountingIntegrity/): direct/arbitrary-transaction denial, idle action/forged/consumed/stale capability refusal, rollback after stock/numbering/GL failure, exact retries versus conflicting cost/quantity/date, canonical sale / return / void lifecycle, Purchasing GL-only/reversal denial, independent-company preservation, concurrent convergence and fresh role/membership enforcement at the lock boundary. Existing mature fixtures now create balanced canonical openings/adjustments/Sales rather than inventing accounting-orphan stock.

## Release and historical-data boundaries

- No merge, deployment, Hostinger access, live provider call or production mutation occurred.
- No automatic historical repair is included. Newly detected historical event corruption makes reconciliation fail and blocks rebuild; a GL/cache discrepancy remains failed evidence until its cause is reconciled. Any actual historical correction needs a separately authorized, auditable plan; never rewrite posted stock/financial history to make an audit green.
- The code is reviewable source pending independent exact-head acceptance. Issue #45 remains open until the normal accepted merge/release process.
- Protected Owner specifications/PDFs and unrelated worktrees remain preserved. Worker briefs/results and detailed synthetic logs are deliberately retained only under ignored `.ai/issue45`; they are not public PR evidence.

## Final review correction ? unsupported Inventory GL sources

Review [4274155474](https://github.com/Bassamalsaqqa/account/pull/46#discussion_r4274155474) identified a remaining inverse-audit gap: individually balanced `manual_test` batches could debit/credit Inventory by +2 and -2 without stock movements, leaving the aggregate control balance at zero. Their original source types must remain unchanged in the regression.

The correction checks every nonzero per-batch Inventory GL effect against the same canonical stock-to-batch index already validated by the audit. Recognized Sales void reversals are supported through their linked compensating stock; unrelated/non-inventory manual accounting and a batch with exactly zero net Inventory effect require no economic stock counterpart. No historical-source exemption is invented. Generic financial opening posting behavior remains unchanged; the Blueprint's stock-opening contract still requires stock plus Inventory GL. Unsupported Inventory effects are reported for review, never rewritten or silently relabeled.

PR #44 overlap: its accepted head `7214dfe1b8eadda7c217d97e9cc10706ac07b130` changes only the default-date line in `PostOpeningStockAction`, `AdjustStockAction` and `DisposeExpiredStockAction`. Those exact three lines are carried here alongside the PR #46 action-owned transaction/capability wrappers. Default dates use company timezone; explicit dates and posted historical dates remain unchanged. Future integration must retain both behaviors. Neither branch was merged.

Focused correction verification: PASS ? 60 tests / 934 assertions, 0 failures/errors/skips/notices, on newly owned MariaDB; targeted Pint and PHPStan passed. Before the fix, the four new GL cases produced two failures and two passing controls (38 assertions); the three timezone cases reproduced two wrong-date failures and one expiry-boundary error. Tests cover the unchanged unsupported-source pair and lone batch, double-entry accounting validity, read-only snapshot preservation, legitimate manual/zero-effect controls, canonical stock/Sales/Purchasing/reversal history and company-midnight/expiry boundaries. No broad-suite repeat, GitHub CI, production access, merge or deployment.
