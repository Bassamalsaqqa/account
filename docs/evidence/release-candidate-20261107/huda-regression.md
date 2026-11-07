# Huda database regression review — 2026-11-07

Status: **BLOCKED; release database qualification incomplete.** Baseline/resulting HEAD: `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`. Extensive intentional dirty changes preserved. This is an independent source/coverage review and one failed suite setup attempt, not a runtime pass.

Read: repository AGENTS.md, CONTROLLED_RELEASE_CHECKLIST, SETUP_AND_OPERATIONS_GUIDE section 4, CORRECTION_IMPLEMENTATION_20261107 (including later superseding follow-ups), UI_REDESIGN_20261107. Only this Huda evidence file and Huda-prefixed sanitized logs were written. No application/config/test source or common evidence document was edited.

## Actual execution and environmental blocker

Executed once from the account repository:

```bash
APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 PHASE8_TEST_TIMEOUT=14400 php tests/Support/run-phase8-disposable.php -- tests
```

Runner exit **1**. Exact error: `SQLSTATE[HY000] [2002] Connection refused`. **0 tests / 0 assertions executed**. PHPUnit did not start, so PHPUnit failure/error/skip totals and suite duration are unavailable; this is one setup connection error, not a failing domain assertion. PHP CLI is 8.4.27. No test listener on 3342 or MariaDB server process was found; server/provisioning binaries are absent from PATH and their usual system locations. No schema was created (failure occurs in DisposableMariaDbSchema::create before CREATE DATABASE can execute). Therefore there are zero owned schemas to clean. No cleanup of other agents' schemas was attempted.

Minimal environment fix for parent: provision/enable the dedicated disposable MariaDB endpoint at 127.0.0.1:3342, with the documented test_runner account and intentionally empty password, schema privileges restricted to accounting_p8_tmp_%. Then repeat the exact full-suite command above with the documented 14400-second timeout. Do not substitute persistent port 3353 or application credentials. No passing tests were repeated. No alternative database, package installation, sandbox bypass, hooks or live fallback was used.

Logs: `huda-full-php-suite.log` (actual runner output), `huda-review-environment.log` (result metadata and SHA256 fingerprints of reviewed dirty source/tests). Logs contain no application records, application secrets or ownership tokens.

## Actionable source blocker: Payables rejects legitimate purchase void history (P1)

**Source:** app/Services/Purchasing/PayablesReconciliationService.php:178–179, 217–219. The original purchase and purchase_return posting namespaces require owner status exactly `posted`. Every original source batch is scanned regardless of its reversed status (186–200). A canonically voided nonzero purchase/return retains its original batch but has status `void`, so the condition necessarily appends `Canonical source provenance failure: batch [...], purchase[...] references an uncompleted source event.` (or purchase_return). This makes a valid correction fail the required Payables reconciliation release gate.

**Supporting source:** app/Actions/Purchasing/VoidPurchaseAction.php:440–457 and VoidPurchaseReturnAction.php:273–290 reverse the original and complete the void. app/Models/Purchase.php:171 changes status to VOID; original posting_batch_id is retained. PurchaseVoidIntegrity.php:104–108 requires the retained original batch to be reversed and reciprocally linked to the posted reversal. The reconciler's posted-only lifecycle predicate does not recognize this valid terminal state. This is a deterministic source-level finding; database reproduction remains blocked by the environment above.

**Minimal parent fix:** recognize posted and canonically validated void purchases/returns as completed original source events. Retain original batch ownership, duplicate/orphan and tenant checks. For void sources validate PurchaseVoidIntegrity::assertComplete (and applicable original-history validator), rather than merely allowing every `void` row or excluding reversed batches wholesale. Add regressions for nonzero purchase void, purchase-return void, and correction with dedicated payment plus child return; assert Payables healthy with no findings. Then corrupt/remove a reciprocal inverse and assert unhealthy. Keep draft/uncompleted original sources unhealthy.

**Coverage hole explaining escape:** tests/Feature/Purchasing/VoidPurchaseTest.php:228–232 and VoidPurchaseReturnTest.php:100–104 assert Inventory and Accounting health only. Neither correction test class calls a reconciliation service. The existing Phase5E/VendorPaymentReconciliationAndAuditTest.php:114 cross-domain fixture predates these correction flows and does not exercise them.

## Correction/void invariant assessment (source only, not executed)

- PurchaseCorrection::execute serializes company before source read, freshly authorizes, validates encrypted actor-bound economic identity and receipt replay, locks dependency graph, prepares unposted replacement and reverses dedicated payments/children/source inside one TenantWriteAccess transaction; receipt insert remains in that boundary (PurchaseCorrection.php:81 onward). TradeCorrection mirrors this boundary (TradeCorrection.php:101–150), releases canonical return caps before cloning, and rechecks company-local sales business date before new effects (126–128).
- PurchaseVoidIntegrity.php:19–70 checks full movement-set cardinality, source line ownership and quantity, one inverse per original, historical unit metadata, exact negated quantity/value and business date. Lines 73–108 separately enforce legitimate zero-journal policy and reciprocal journal/currency/amount metadata. This is substantially stronger than checking status alone.
- DedicatedPaymentPlan.php:73 onward compares all linked allocations, full initial base amount and application events, blocks check-backed/shared/excess-credit payments, and delegates reversal to canonical actions. These guards should remain intact. Unsupported compound shared/check/landed-cost corrections remain documented scope limitations, not a new pass claim.
- Existing targeted source tests cover stale configuration, unauthorized/tampered preview, economic retry mismatch, initial excess credit refusal, payment/child ordering, late receipt failure rollback, cached read-permission revocation, company-before-source locks and one two-process race. These tests were inspected, not rerun or accepted based on prior counts.

## Six reconciliation coverage

All six are **NOT EXECUTED in this run**; production reconciliation was not attempted.

| Service | Existing source coverage inspected | New-flow gap |
| --- | --- | --- |
| Accounting | Purchasing/VoidPurchaseTest.php:231; VoidPurchaseReturnTest.php:103; Phase2/ReconciliationCorruptionsTest.php | No six-service check after compound correction |
| Inventory | Purchasing/VoidPurchaseTest.php:228; VoidPurchaseReturnTest.php:100; Phase5D/PurchaseReturnReconciliationTest.php:18 | No combined compound-correction qualification |
| Sales | Phase4/SalesReconciliationTest.php; Phase5D/PurchaseReturnReconciliationTest.php:64 | New TradeCorrection family matrix checks statuses/receipts, not Sales findings |
| Payables | Phase5E/VendorPaymentReconciliationAndAuditTest.php:20,114 | Posted-only lifecycle defect above; no new purchase void assertion |
| Money | Phase6/MoneyControlPlaneTest.php:20,60; Phase6/CrossCurrencyConvergenceTest.php | No Money findings assertion after correction-driven dedicated reversal |
| Phase7 | Phase7/Phase7ReconciliationTest.php:28,156 | No combined fixture proving unrelated expense/payroll history remains healthy after correction |

Parent should add a disposable canonical compound fixture and inspect all six report finding collections, not only process exits, with before/after fingerprints to establish read-only behavior. Payables uses its existing service, not a fabricated CLI. No expanded whole-AP-ledger/tax policy is proposed.

## Migration roundtrip/history assessment and additional gaps

- Forward migrations 000001/000002 refuse destructive down when void history exists; 000002 preflights historical date ambiguity before DDL (000002:14–31). Receipt migration 000003 refuses down with receipts and restrictive FKs preserve source/replacement provenance. Migration 000004 checks all three receipt tables before dropping any (000004:36 onward). No migration roundtrip actually ran here.
- Existing coverage: VoidPurchaseReturnTest.php:224 and :423 exercise empty rollback/up; :397 checks actual return void history retention for 000001/000002. PurchaseCorrectionTest.php:97 tests purchase receipt down refusal. TradeCorrectionTest.php:208 tests invoice receipt refusal; :248 tests 000004 roundtrip in its own disposable schema. These are source observations, not passing results.
- **Missing populated upgrade coverage:** no test found for 000002 up against pre-existing void purchases/returns, including journal-backed and zero-journal stock-only dates, disagreement between journal/stock dates, or ambiguity in the second table proving neither table receives DDL. Add a secondary owned schema representing pre-000002 history; compare full row/schema fingerprints after rejected up. Current empty roundtrip and post-up down-refusal tests do not establish upgrade preservation.
- **Missing all-table receipt protection matrix:** 000004 actual-history refusal test populates only sales_invoice receipts. Add isolated sales_return-only and purchase_return-only fixtures; verify all three receipt tables and rows survive refusal. Add restrictive source/replacement FK tests and explicit purchase receipt empty down/up coverage. Raw SQL immutability is not claimed: receipts have no DB update/delete trigger, as documented.
- **Concurrency scope:** TradeCorrectionTest.php:265–315 races a service-only sales invoice with one token. It does not prove purchase stock/payment/child-return convergence, two independent preview keys for the same source, or cross-family receipt races. Add canonical concurrent stock/payment fixtures; compare unique replacement, numbering, receipt and all economic effects.
- **Consent/dependency matrix:** PurchaseCorrectionTest.php:239 covers initial excess vendor credit, but no new adapter matrix was found for customer/vendor cross-document allocations plus reversed/reapplied application events or check lifecycle changes between preview and execute. Add refusal/rollback assertions for both sides; do not infer coverage from standalone payment tests.
- **Clock boundary:** sales execute has explicit today recheck, but adapter expiry and company-midnight new-execution/replay scenarios are not covered by the new correction tests. VoidPurchaseTest.php:105 covers the separate zero-journal void retry, not TradeCorrection token semantics. Add frozen-clock preview-before-midnight/new-execute-after-midnight refusal and durable same-receipt retry cases.

No additional source defect is claimed from these unexecuted coverage gaps. No release acceptance is implied. No children, account switching, build/browser, commits, push, merge, deployment, Hostinger, production or persistent application database access occurred.
