# Independent current-source release review — 2026-11-07

## Scope and disposition

Baseline and resulting HEAD: `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`. Reviewed actual extensive dirty source, not HEAD alone, and read `AGENTS.md`, `huda-app-regression-fixes.md`, and `parent-final-followups.md`. Consulted the primary `ui-ux-design` router and existing `DESIGN.md` / `UI_WORKFLOW_RULES.md` for the form recovery contract. No supporting UI generator or delegated agent was needed.

**One P1 defect remains in the ordinary new-purchase confirmation retry boundary.** The completed-confirmation change correctly handles retries using the successfully returned component state, but does not make a replay of the original request idempotent. The focused checks below pass; they do not cover this missing request-snapshot case. No additional concrete blocker was confirmed in the bounded correction execution/authorization/lock and Payables void-integrity review.

## Finding

### P1 — Replaying the original new-purchase confirmation can post a second purchase and payment

- **Locations:** `app/Livewire/Pages/Purchasing/PurchaseForm.php:345`, `app/Livewire/Pages/Purchasing/PurchaseForm.php:749`, `app/Livewire/Pages/Purchasing/PurchaseForm.php:794`, `app/Livewire/Pages/Purchasing/PurchaseForm.php:846`; surrounding canonical writer `app/Actions/Purchasing/CreatePurchaseDraftAction.php:26`.
- **Trigger:** Start an ordinary, non-AI new purchase with no previously saved draft. Submit `saveAndConfirm`, let the server commit, but lose its successful response. Resubmit the original valid Livewire request/snapshot under the same authorized actor/company, rather than submitting the newly returned success snapshot. This can also be tested by sending the same captured pre-confirm request twice sequentially. Ordinary in-flight double-click suppression is not the trigger.
- **Source trace:** The incoming snapshot still has `completedConfirmation = null`, `savedDraftPublicId = null`, and `publicId = null`. The completed-record branch therefore does not run. `save(true)` enters `CreatePurchaseDraftAction`, which unconditionally creates another purchase rather than resolving a stable form/request identity. A new encrypted preview and a new UUID execution key are generated for that new purchase. `ImmediatePurchaseCommands` correctly scopes its receipt to this new purchase/token/key, so it cannot associate that execution with the first request. `completedConfirmation` is assigned only after canonical execution and only becomes available to a later browser request if its response is received.
- **Impact:** The second execution can commit another numbered purchase, inventory receipt and liability; full-cash settlement can also commit a second vendor payment. Company serialization prevents simultaneous inconsistent writes but does not deduplicate these two serialized business events. `vendor_invoice_number` is optional and indexed, not a unique request identity.
- **Recovery context:** `resources/js/document-operations.js:43` quarantines failed operations, which mitigates accidental immediate client retries. It is not durable server-side idempotency. Its explicit recovery at `resources/js/document-operations.js:208` refreshes/recalculates using the still-available component state and clears quarantine; it does not look up a completed purchase by a stable original operation identity. Purchase quantity controls expose that recovery through `resources/views/components/touch-quantity-stepper.blade.php:80`. No browser failure/recovery exercise was run in this review.
- **Related existing-draft behavior:** If the lost response was from an existing-draft form, the old snapshot still lacks the completed record. `authorizeForm()` at `app/Livewire/Pages/Purchasing/PurchaseForm.php:203` then rejects the now-posted purchase as an immutable draft before a receipt replay can converge. This does not duplicate that existing draft, but illustrates the same success-snapshot-only recovery limitation.
- **Why the passing regression does not disprove this:** `tests/Feature/Refinement/HumanWorkflowRefinementTest.php:91` calls confirmation again on the same Livewire test object after applying the successful response. That tests the new completed record, not reuse of the original signed request/snapshot after an ambiguous response.
- **Required fix / acceptance test:** Bind ordinary form creation and its confirmation to a stable, server-validated operation identity present before the first write, with durable request/payload matching and atomic receipt/result resolution. Preserve current actor/company/payment permissions and posted-integrity validation on replay. Add a regression that replays the identical pre-confirm Livewire snapshot after the first request commits, for new/existing drafts and unpaid/paid modes; assert one purchase, one original purchase posting/stock effect, one command result, and at most one payment. Also exercise lost-response recovery rather than only same-object repeat calls.
- **Evidence strength:** Concrete source-derived control-flow defect. The exact captured-request/lost-response reproduction was not executed; no new test/source file was permitted by this read-only assignment. This is not a claim that ordinary enabled-browser double-clicks bypass the operation queue.

## Contracts independently checked without another confirmed finding

- `completedConfirmation` is Livewire-locked. Successful-state `saveAndConfirm` and direct `confirmPost` enter the canonical command again rather than returning a cached redirect. The reviewed form/settlement digest remains enforced.
- `ImmediatePurchaseCommands::execute` binds encrypted consent to actor, company and purchase; locks company before purchase; refreshes post/cost/payment/check permissions before receipt lookup; rejects differing token/request identity; checks canonical purchase/payment integrity on replay; and encloses posting, payment and command receipt in one tenant-write transaction.
- `PurchaseCorrection` and `TradeCorrection` execution acquire the company lock before reading their source, refresh permission/membership authority through `SalesActorGuard`, bind token identity to actor/company/document, compare durable receipt payload digests, and re-evaluate dependency graphs before inverses. Clone, dependency reversals, source void and correction receipt share the enclosing transaction. Dedicated/shared/check payment restrictions and child-return permissions are not replaced by presentation-only checks.
- `VoidPurchaseAction` / `VoidPurchaseReturnAction` refresh authorization inside the locked canonical transaction. Their capabilities/scopes require canonical completion; void business dates and reason/date replay conflicts are checked. `PurchaseVoidIntegrity` verifies complete reciprocal stock quantities/values/provenance and accounting lines/pointers/date, including zero-journal handling.
- Payables audits both posted and void purchase/return sources, calls their posted-history builders and the void validator, and iterates return rows independently. Existing healthy-void and deliberately corrupted reciprocal-pointer tests pass. The six-reconciliation compound correction/replay test also passes. These are bounded checks, not an exhaustive corruption-field or historical-data qualification.

## Focused execution evidence

Both runs used only `tests/Support/run-phase8-disposable.php`, loopback MariaDB **3342**, `test_runner`, explicitly empty password, and helper-owned disposable schemas:

```sh
APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 \
PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 \
PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_PASSWORD='' \
php tests/Support/run-phase8-disposable.php -- \
  tests/Feature/Refinement/HumanWorkflowRefinementTest.php \
  tests/Feature/Purchasing/PurchaseCorrectionTest.php \
  tests/Feature/Purchasing/TradeCorrectionTest.php \
  tests/Feature/Purchasing/VoidPurchaseTest.php \
  tests/Feature/Purchasing/VoidPurchaseReturnTest.php \
  tests/Feature/Purchasing/ReleaseReconciliationTest.php \
  --filter 'purchase_confirm_creates|PurchaseCorrectionTest|TradeCorrectionTest|VoidPurchaseTest|VoidPurchaseReturnTest|ReleaseReconciliationTest'
```

**PASS: 88 tests, 536 assertions; 01:43.644; 93 MB; exit 0.** Runner-owned schema `accounting_p8_tmp_9646488e3b6d`; runner reported `Owned primary schema cleaned.` This includes the four completed-confirmation modes and the correction race/lock regressions in the selected classes.

```sh
APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 \
PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 \
PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_PASSWORD='' \
php tests/Support/run-phase8-disposable.php -- \
  tests/Feature/Purchasing/ImmediatePurchaseSettlementTest.php \
  --filter 'full_cash_settlement|atomic_rollback|reused_key|permission_revocation|stale_consent|replay_validates|zero_cost_purchase'
```

**PASS: 9 tests, 58 assertions; 00:09.564; 81 MB; exit 0.** Runner-owned schema `accounting_p8_tmp_bfbbcce808a3`; runner reported `Owned primary schema cleaned.`

These two invocations total **97 tests / 594 assertions**, not an additive release-wide coverage claim. Raw output was observed in the review terminal; no extra log artifact was written.

## Limitations and safety boundary

- This is a bounded independent source review, not release acceptance. No captured-HTTP replay, network-loss browser test, exhaustive permission race, complete corruption matrix, populated-history migration qualification, full suite, full static analysis, build, dependency audit or production qualification was performed here.
- Parent's ongoing ReleaseUi support changes and the other worker's export/runtime contract changes were not modified or independently accepted. A passing focused run does not settle those separate release gates. Dirty source remains under concurrent ownership; the finding refers to the source locations recorded here.
- Only this Markdown report was deliberately written. No application/test source edits, children, branches, commits, pushes, merge, deployment, production or Hostinger access occurred. No port 3353 access, manual schema enumeration/adoption/drop, sibling-schema cleanup, MariaDB stop, hooks or sandbox bypass occurred. Disposable creation/migration/cleanup stayed inside the authorized runner/helper lifecycle.
