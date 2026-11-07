# AGY Independent Release-Ready Review & Publication Audit

**Date:** 2026-11-07
**Repository:** `/home/t3dev/.t3/projects/small-trader-platform/account`
**Review Agent:** AGY (Independent Review Sub-Agent)
**Baseline HEAD:** `06e4f6d71b8dc3202f0708dff91a21b32a5afd33` (`origin/main`)
**Verdict:** **RELEASE BLOCKED — PENDING GATES (NO ACCEPTANCE WITHOUT DB/BROWSER VERIFICATION)**

---

## 1. Executive Summary & Verification Gates

The small trader platform codebase has undergone substantial enhancements across document corrections (purchase and trade reversals, dedicated payment plans, reciprocal receipt tracking), design system modernization (BoardUI/shadcn alignment, 44px touch targets, LTR/RTL bidi isolation), and accessibility hardening.

However, per strict engineering invariants and task instructions, this release candidate **CANNOT BE ACCEPTED OR RELEASED** at this stage due to:
1. **P1 Blocker in Payables Reconciliation Source:** `PayablesReconciliationService.php:111` fails to query void purchase returns (`PurchaseReturn::STATUS_VOID`), leaving void purchase return integrity completely unvalidated in liability audits and causing `VoidPurchaseReturnTest::test_successful_purchase_return_void_restores_stock_and_accounting` to fail when reciprocal history is corrupted.
2. **Pending DB & Runtime Test Completion:** Huda's full test suite run and final patched verification remain in progress on the preserved disposable MariaDB runtime (`127.0.0.1:3342`).
3. **Pending Security Dependency Patch:** Upstream Symfony packages have 10 known advisories. The planned 5-package patch to `v8.1.11` (`agy-security-plan.md`) is queued for Parent execution after Huda finishes.
4. **Browser Visual / Interaction Qualification:** **NOT VERIFIED**. Local services are not running; browser navigation to `http://127.0.0.1:8000` failed with `ERR_CONNECTION_REFUSED`. Live UI, focus management, touch targets, and RTL visual flows require verification once a test server is running.

---

## 2. In-Depth Source Code Review Findings

### 2.1 Payables Reconciliation & Void Integrity (`PayablesReconciliationService.php`)
- **Evaluated Changes:**
  - `PayablesReconciliationService::canonicalSourceBatchViolations()` now maps both `purchase` and `purchase_return` source namespaces to allow completed lifecycles `[STATUS_POSTED, STATUS_VOID]`.
  - In `reconcile()`, line 101 audits liability sources:
    ```php
    foreach (Purchase::where('company_id', $cid)->whereIn('status', [Purchase::STATUS_POSTED, Purchase::STATUS_VOID])->get() as $purchase) {
        app(PurchasePostingCommandBuilder::class)->validatePosted($purchase);
        if ($purchase->isVoid()) {
            app(PurchaseVoidIntegrity::class)->assertComplete($purchase);
        }
    }
    ```
  - `PurchaseVoidIntegrity::assertComplete()` comprehensively verifies the full stock movement inverse cardinality, negated deltas, historical unit conversions, business date equivalence, zero-journal constraints, and GL reciprocal batch pairing (`reversed_by_batch_id` and `reversal_of_id`).
- **CONCRETE BLOCKER (P1 Bug Identified):**
  - Look at line 111 of [app/Services/Purchasing/PayablesReconciliationService.php](file:///home/t3dev/.t3/projects/small-trader-platform/account/app/Services/Purchasing/PayablesReconciliationService.php#L111):
    ```php
    foreach (PurchaseReturn::where('company_id', $cid)->where('status', Purchase::STATUS_POSTED)->get() as $return) {
        try {
            app(PurchaseReturnPostingCommandBuilder::class)->validatePosted($return);
            if ($return->isVoid()) {
                app(PurchaseVoidIntegrity::class)->assertComplete($return);
            }
        } catch (\Throwable $e) {
            $violations[] = "Purchase Return [ID {$return->id}] integrity failure: {$e->getMessage()}";
        }
    }
    ```
  - **The Defect:** Line 111 queries `where('status', Purchase::STATUS_POSTED)` instead of `whereIn('status', [PurchaseReturn::STATUS_POSTED, PurchaseReturn::STATUS_VOID])`.
  - **The Consequence:** Void purchase returns are never fetched by this query! The check `if ($return->isVoid())` is dead code. When a purchase return is voided, `PurchaseVoidIntegrity::assertComplete($return)` is **never executed**.
  - **Test Impact:** In `tests/Feature/Purchasing/VoidPurchaseReturnTest.php:111-114`:
    ```php
    DB::table('posting_batches')->where('id', $originalBatchId)->update(['reversed_by_batch_id' => null]);
    $corrupted = app(PayablesReconciliationService::class)->reconcile($this->company);
    $this->assertFalse($corrupted->isHealthy);
    ```
    Because line 111 ignored void returns, `$corrupted->isHealthy` returned `true`, causing the test failure captured in `huda-runtime-focused.log:113` (`Failed asserting that true is false`).
  - **Required Parent Action:** Update line 111 to:
    ```php
    foreach (PurchaseReturn::where('company_id', $cid)->whereIn('status', [PurchaseReturn::STATUS_POSTED, PurchaseReturn::STATUS_VOID])->get() as $return) {
    ```

### 2.2 Corrupted Reciprocal History Regressions
- **Evaluated Files:**
  - [tests/Feature/Purchasing/VoidPurchaseTest.php](file:///home/t3dev/.t3/projects/small-trader-platform/account/tests/Feature/Purchasing/VoidPurchaseTest.php#L235-L243)
  - [tests/Feature/Purchasing/VoidPurchaseReturnTest.php](file:///home/t3dev/.t3/projects/small-trader-platform/account/tests/Feature/Purchasing/VoidPurchaseReturnTest.php#L107-L115)
  - [tests/Feature/Purchasing/ReleaseReconciliationTest.php](file:///home/t3dev/.t3/projects/small-trader-platform/account/tests/Feature/Purchasing/ReleaseReconciliationTest.php)
- **Findings:**
  - Both `VoidPurchaseTest` and `VoidPurchaseReturnTest` establish strong regressions ensuring that a terminal `status = 'void'` does not excuse missing reciprocal GL batches.
  - `ReleaseReconciliationTest` provides a canonical 6-service reconciliation suite (`Accounting`, `Sales`, `Payables`, `Money`, `Phase7`, and `Inventory`), asserting healthy state and zero database mutations across compound corrections (purchase, child return, dedicated payment, unrelated expense/invoice/salary) and idempotent receipt replays.

### 2.3 Concurrency & Lock Ordering (`PurchaseCorrection` & `TradeCorrection`)
- **Evaluated Files:**
  - [app/Services/Corrections/PurchaseCorrection.php](file:///home/t3dev/.t3/projects/small-trader-platform/account/app/Services/Corrections/PurchaseCorrection.php#L79-L85)
  - [app/Services/Corrections/TradeCorrection.php](file:///home/t3dev/.t3/projects/small-trader-platform/account/app/Services/Corrections/TradeCorrection.php#L101-L107)
  - [app/Services/Sales/SalesActorGuard.php](file:///home/t3dev/.t3/projects/small-trader-platform/account/app/Services/Sales/SalesActorGuard.php#L17-L33)
- **Findings:**
  - **Company-Before-Source Locking:** Both services now explicitly serialize `Company::whereKey($companyId)->lockForUpdate()->firstOrFail()` BEFORE reading or locking the source model. This enforces canonical hierarchical locking order across processes, preventing MariaDB deadlocks and Error 1020 during concurrent corrections.
  - **Cached Permission Invalidation:** `SalesActorGuard::lockAndAuthorize()` executes `$actor->unsetRelation('roles')->unsetRelation('permissions')` and resets `setPermissionsTeamId($companyId)`. Any role or permission change made to the actor's membership is refreshed immediately from the database within the lock boundary.
  - **Presentation Revocation:** `CorrectionPresentationAccess::capture($actor)` caches read privileges at preview time. Livewire components re-validate these capabilities prior to rendering links/details; if permission was revoked, the preview closes safely (fail-closed).

### 2.4 Accessibility & Interface Hardening
- **Evaluated Files:**
  - [resources/views/components/settings-shell.blade.php](file:///home/t3dev/.t3/projects/small-trader-platform/account/resources/views/components/settings-shell.blade.php#L38-L43)
  - [resources/views/components/correction-preview.blade.php](file:///home/t3dev/.t3/projects/small-trader-platform/account/resources/views/components/correction-preview.blade.php)
  - [resources/css/business-dashboard.css](file:///home/t3dev/.t3/projects/small-trader-platform/account/resources/css/business-dashboard.css)
- **Findings:**
  - **Settings Shell:** Mobile navigation scroller pills have been updated with `touch-action min-h-11 inline-flex items-center`, guaranteeing a 44px minimum touch target height. Container backgrounds are normalized to semantic `bg-surface`.
  - **Correction Preview Error Association:**
    - Input controls have explicit associations: `for="correction-reason"` with `id="correction-reason"`, and `for="correction-date"` with `id="correction-date"`.
    - Both inputs implement `:aria-invalid` and `:aria-describedby` linking to `<p role="alert" id="correction-*-error">`.
  - **Correction Preview Heading Focus:**
    - The plan header utilizes `wire:key="correction-plan-heading"`, `tabindex="-1"`, and autofocuses via Alpine `x-init="$nextTick(() => $el.focus({ preventScroll: true }))"`, directing screen readers and keyboard focus to the plan upon generation.
  - **False-Positive Auditing:**
    - Verified that `.merchant-home .home-action` in `business-dashboard.css:132` enforces `min-height: 44px`, overriding utility-level 40px mobile date inputs.
    - Verified that generic table action headers (`sales.actions`) and omitted `purchasing.actions` are standard across the existing codebase and do not constitute defects.

---

## 3. Read-Only Git & GitHub Publication Preparation

### 3.1 Repository & Branch State
- **Current Branch:** `main` (commit `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`).
- **Remote Tracking:** `origin` -> `https://github.com/Bassamalsaqqa/account.git`. Local `main` is even with `origin/main`.
- **GitHub CLI Status:** Authenticated as `Bassamalsaqqa` with `repo`, `workflow`, `gist`, `read:org` scopes. No credential leak.
- **Existing PRs / Checks:**
  - `gh pr list --head main`: 0 pull requests.
  - Check runs on commit `06e4f6d`: 0 runs reported.
  - No active checks or reviews are pending on the remote branch.

### 3.2 Inventory of Working Tree (Dirty Changes)

#### A. Intentional Untracked Source Files (New Architecture & Actions)
```text
app/Actions/Purchasing/VoidPurchaseAction.php
app/Actions/Purchasing/VoidPurchaseReturnAction.php
app/Actions/Sales/DeleteReturnDraftAction.php
app/Application/Commands/ImmediatePurchaseCommands.php
app/Domain/Purchasing/Exceptions/PurchasingReversalBlockedException.php
app/Livewire/Concerns/CorrectsTradeDocument.php
app/Livewire/Concerns/ReviewsStandaloneReversal.php
app/Services/Corrections/CorrectionPresentationAccess.php
app/Services/Corrections/CorrectionStockEffects.php
app/Services/Corrections/DedicatedPaymentPlan.php
app/Services/Corrections/PurchaseChildReturnPlan.php
app/Services/Corrections/PurchaseCorrection.php
app/Services/Corrections/PurchaseDependencySnapshot.php
app/Services/Corrections/SalesDependencySnapshot.php
app/Services/Corrections/SalesReturnStockRead.php
app/Services/Corrections/TradeCorrection.php
app/Services/Purchasing/PurchaseDraftSnapshot.php
app/Services/Purchasing/PurchaseReturnVoidCapability.php
app/Services/Purchasing/PurchaseReturnVoidScope.php
app/Services/Purchasing/PurchaseVoidCapability.php
app/Services/Purchasing/PurchaseVoidIntegrity.php
app/Services/Purchasing/PurchaseVoidScope.php
app/Services/Reviews/StandaloneReversalReview.php
database/migrations/2026_11_07_000001_add_void_columns_to_purchase_returns_and_permissions.php
database/migrations/2026_11_07_000002_add_purchase_void_business_dates.php
database/migrations/2026_11_07_000003_create_purchase_correction_receipts.php
database/migrations/2026_11_07_000004_create_trade_correction_receipts.php
resources/lang/ar/corrections.php
resources/lang/ar/purchase_settlement.php
resources/lang/ar/standalone.php
resources/lang/en/corrections.php
resources/lang/en/purchase_settlement.php
resources/lang/en/standalone.php
resources/views/components/correction-preview.blade.php
resources/views/components/correction-shortcuts.blade.php
resources/views/components/inline-panel.blade.php
resources/views/components/page-heading.blade.php
resources/views/components/standalone-reversal-review.blade.php
resources/views/livewire/partials/immediate-purchase-review.blade.php
resources/views/livewire/partials/immediate-purchase-settlement.blade.php
```

#### B. Intentional Untracked Test Files & Harness Support
```text
tests/Feature/Phase12/AiSettingsRegressionTest.php
tests/Feature/Phase4/StandaloneReceiptReviewTest.php
tests/Feature/Phase7/StandaloneReversalReviewTest.php
tests/Feature/Purchasing/ImmediatePurchaseSettlementTest.php
tests/Feature/Purchasing/PurchaseCorrectionTest.php
tests/Feature/Purchasing/ReleaseReconciliationTest.php
tests/Feature/Purchasing/TradeCorrectionTest.php
tests/Feature/Purchasing/VoidPurchaseReturnTest.php
tests/Feature/Purchasing/VoidPurchaseTest.php
tests/Support/Corrections/execute-worker.php
tests/Unit/ProductExperience/ShellMenuAndInlineComponentTest.php
```

#### C. Documentation & Evidence Files
```text
docs/CORRECTION_IMPLEMENTATION_20261107.md
docs/UI_REDESIGN_20261107.md
docs/evidence/corrections-20261107/*
docs/evidence/release-candidate-20261107/*
```

---

## 4. Proposed Logical Commit Plan

To avoid catastrophic mega-commits (`git add .`), keep reviewable atomic history, and respect Git discipline, the Parent should execute the following 7-commit sequence once gates are satisfied:

| Step | Commit Scope / Title | Target Paths / Files | Description |
|---|---|---|---|
| **1** | **feat(purchasing): add void schema, permissions and purchase void integrity** | `database/migrations/2026_11_07_000001*`<br>`database/migrations/2026_11_07_000002*`<br>`app/Models/Purchase.php`<br>`app/Models/PurchaseReturn.php`<br>`app/Actions/Purchasing/VoidPurchaseAction.php`<br>`app/Actions/Purchasing/VoidPurchaseReturnAction.php`<br>`app/Services/Purchasing/*Void*`<br>`app/Services/Purchasing/PurchaseVoidIntegrity.php`<br>`app/Services/Tenancy/CompanyRoleService.php`<br>`resources/lang/*/purchasing.php` | Schema migrations for void tracking, void actions, model lifecycle helpers, role permissions, and canonical void integrity validator. |
| **2** | **fix(purchasing): include void liabilities in payables reconciliation** | `app/Services/Purchasing/PayablesReconciliationService.php` | Canonical reconciliation support for posted and void liability sources (`Purchase` and `PurchaseReturn`), enforcing reciprocal compensation history. |
| **3** | **feat(corrections): implement trade and purchase document corrections** | `database/migrations/2026_11_07_000003*`<br>`database/migrations/2026_11_07_000004*`<br>`app/Services/Corrections/*`<br>`app/Services/Reviews/*`<br>`app/Livewire/Concerns/CorrectsTradeDocument.php`<br>`app/Livewire/Concerns/ReviewsStandaloneReversal.php`<br>`resources/lang/*/corrections.php`<br>`resources/lang/*/standalone.php`<br>`resources/views/components/correction-*`<br>`resources/views/components/standalone-reversal-review.blade.php`<br>`app/Livewire/Pages/*Detail.php` | Dual-table correction receipts, atomic compensation plans (dedicated payments and child returns), and company-before-source serialization. |
| **4** | **feat(purchasing): add immediate purchase settlement and commands** | `app/Application/Commands/ImmediatePurchaseCommands.php`<br>`resources/views/livewire/partials/immediate-purchase-*`<br>`resources/lang/*/purchase_settlement.php`<br>`app/Livewire/Pages/Purchasing/PurchaseForm.php`<br>`resources/views/livewire/pages/purchasing/purchase-form.blade.php` | Immediate purchase cash/bank settlement drawer, draft line lot bindings, and rapid voucher execution. |
| **5** | **style(ui): modernize design system and ensure 44px mobile touch targets** | `resources/css/*.css`<br>`resources/js/*`<br>`resources/views/components/*`<br>`resources/views/layouts/*`<br>`resources/views/dashboard.blade.php` | BoardUI and shadcn design alignment, semantic surfaces (`bg-surface`), error accessibility associations, 44px touch targets on mobile, and settings shell. |
| **6** | **style(views): propagate semantic design tokens across application views** | `resources/views/livewire/pages/*`<br>`resources/views/platform/*`<br>`resources/views/profile*`<br>`routes/web.php` | Coherent visual refresh across all sales, purchases, money, inventory, expenses, payroll, and admin views without altering business rules. |
| **7** | **test(qa): add comprehensive correction, reconciliation and regression tests** | `tests/Feature/Purchasing/*`<br>`tests/Feature/Phase4/*`<br>`tests/Feature/Phase5E/*`<br>`tests/Feature/Phase6/*`<br>`tests/Feature/Phase7/*`<br>`tests/Feature/Phase12/*`<br>`tests/Unit/*`<br>`tests/Support/Corrections/*`<br>`docs/*` | All feature tests, 6-service reconciliation suite, corrupted reciprocal history tests, UI component tests, documentation, and release evidence. |

*(Note: If the upstream Symfony security patch is applied, it should precede or accompany Commit 1 as `build(deps): update symfony components to v8.1.11` modifying `composer.lock` and `composer.json` if needed).*

---

## 5. Scope & Documentation Gaps to Flag

1. **`docs/CORRECTION_IMPLEMENTATION_20261107.md` Outdated Scope Notes:**
   - The doc mentions: *"Sales Invoice, Sales Return and Purchase Return correction adapters/dialog/index entry points are not implemented."*
   - In actual source, `TradeCorrection.php` and `CorrectsTradeDocument.php` were implemented subsequently by the Parent. The documentation should be updated to reflect that TradeCorrection is implemented.
2. **Unsupported Edge-Cases in Correction Architecture:**
   - As documented truthfully in `CORRECTION_IMPLEMENTATION_20261107.md`, compound corrections involving **shared multi-invoice payments**, **cross-document customer credits**, and **check-backed settlements** remain deliberate blockers and require manual review. This scope boundary must be explicitly highlighted in the release notes.
3. **Database Trigger-Level Immutability:**
   - Correction receipts rely on single-writer application logic with restrictive foreign keys; raw SQL update/delete triggers were not added in MySQL/MariaDB.

---

## 6. Pending Release Gate Checklist

| Gate | Requirement | Responsible Agent | Current Status | Gate Outcome |
|---|---|---|---|---|
| **Gate 1** | Fix `PayablesReconciliationService.php:111` to `whereIn('status', [PurchaseReturn::STATUS_POSTED, PurchaseReturn::STATUS_VOID])` | Parent | Identified & Documented | **FAIL (Must fix)** |
| **Gate 2** | Await completion of Huda full test suite and clean disposable schema teardown | Huda / Runner | In Progress (`14400s` timeout) | **PENDING** |
| **Gate 3** | Apply minimal 5-package Symfony security patch (`v8.1.11`) and verify `composer audit` returns 0 advisories | Parent | Dry-run verified (`agy-security-plan.md`) | **PENDING** |
| **Gate 4** | Run static analysis (`pint --test`, `phpstan`, `view:cache`) and full PHPUnit suite on patched vendor | Parent / Huda | Blocked by Gates 1–3 | **PENDING** |
| **Gate 5** | Start local web server + database and execute real browser visual & focus qualification via T3 Preview | Parent / Owner | Local server unavailable (`ERR_CONNECTION_REFUSED`) | **BLOCKED** |
| **Gate 6** | Execute 7-step logical commit plan and push branch to `origin/main` | Parent | Blocked by Gates 1–5 | **HOLD** |

---
*Report filed by AGY Review Sub-Agent. No source files mutated, no child agents invoked, no commits/pushes made.*
