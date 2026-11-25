# Android PR #24 (`d43d2c2`) vs PR #25 (`ba8c60a`) Semantic Source Coverage Matrix

**Date:** 2026-11-25  
**Review Agent:** Sub-Agent Reviewer (AGY / Codex)  
**Target Repository:** `small-trader-android` (inspected via read-only git objects)  
**Common Ancestor:** `0ccce244cd9424b5aacfb67fae3e1f20dd88bae2` (`t3/legacy-upgrade-recovery-gate`)  
**PR #24 Head:** `d43d2c200b4ccce1d7075aed759f51529375d307` (`origin/t3/android-bootstrap-tax-status-recovery`) — 63 commits ahead of base  
**PR #25 Head:** `ba8c60a3ba277edaa84107eec3dcfd0524c94a2a` (`origin/t3/android-searchable-selectors-pr22`) — 46 commits ahead of base  
**Sole Active Writer on Android Worktree:** Luong  

---

## 1. Executive Summary & Divergence Analysis

PR #24 and PR #25 represent two parallel, divergent evolutionary lines from common ancestor `0ccce244`:
1. **PR #24 (`d43d2c2`)**: Focused on hardening the **staff online cash prototype** (`StaffWorkspaceApp` / `StaffOnlineSaleScreen`), addressing 5 core root holes: bootstrap currency minor units opt-in (12 vs 13 key schema), line tax selection & explicit `ExplicitNoTax` control, `PaymentUnverifiedHold` read-only status recovery (GET-only polling across network drop / ACK loss), durable recovery persistence with CAS reservation, and scope generation/lifecycle guards.
2. **PR #25 (`ba8c60a`)**: Focused on building the **production merchant application shell** (`FULL_SHELL` / `AuthenticatedTraderApp`), introducing reusable searchable selectors with debounce, full catalog/customer/supplier/purchase/expense workflows, Room v2 outbox integration, shift close / cash count observation with lost-ACK qualification, exact checkout discount validation guards, and default release signing configuration.

### Divergence Risk
PR #25 evolved the broader merchant shell but omitted or reverted several critical financial safeguards established in PR #24:
- `BootstrapModels.kt` reverted to a rigid 12-key set, breaking `currency_minor_units` opt-in.
- `ProductionTraderRepository.kt` hardcodes `taxInclusive = false` and lacks line tax selection / `ExplicitNoTax` handling in the merchant UI.
- `TraderViewModel.completeCashSale()` treats unconfirmed `PENDING_SYNC` sales as immediate successful receipts, clearing the basket and advancing the event UUID instead of holding unverified state.
- `ProductionTraderRepository.forTesting` remains compiled in `src/main`.

---

## 2. Test Discovery (916 Prior vs 919 Discovery) and 6 Skips Explained

### A. 916 Prior vs 919 Discovery
- **Prior Baseline (Commit `74819d7` / early PR #25)**: Reported 916 discovered tests (910 passing, 6 skipped).
- **Latest Discovery (Commit `ba8c60a` / Head of PR #25)**: Discovered **919 total tests** (913 passing, 6 skipped).
- **Exact Delta (+3 tests)**: Commit `dba32b8` ("Preserve exact checkout discounts and block unsupported production intent [skip ci]") added exactly 3 new test methods:
  1. `app/src/test/java/com/example/MerchantCheckoutTest.kt`:  
     `invoiceReviewScreen_productionSession_restoredDraftWithFractionalCentDiscount_showsActionableRecoveryAndBlocksPayment()`
  2. `app/src/test/java/com/example/MerchantCheckoutTest.kt`:  
     `demoSession_fractionalDiscount_isPermittedAndCompletesCashSale()`
  3. `app/src/test/java/com/example/data/ShiftCloseRealOkHttpSecurityTest.kt`:  
     `testRecordSale_productionRejectsFractionalNonzeroDiscount_beforeOutboxCapture()`

### B. The 6 Skips from Actual Source
All 6 skipped test cases are explicitly gated in test source code and accounted for:
1. `OutboxScaleBenchmarkHarnessTest.kt:177`: `benchmarkOutbox100WritesThroughput()`  
   *Reason*: Gated by `Assume.assumeTrue(System.getProperty("test.outbox.benchmark") == "true")`. Opt-in benchmark harness.
2. `OutboxScaleBenchmarkHarnessTest.kt:277`: `benchmarkOutbox1kBatchLatencyAndThroughput()`  
   *Reason*: Gated by `Assume.assumeTrue(System.getProperty("test.outbox.benchmark") == "true")`.
3. `OutboxScaleBenchmarkHarnessTest.kt:359`: `benchmarkOutboxConcurrentIngestionUnderLock()`  
   *Reason*: Gated by `Assume.assumeTrue(System.getProperty("test.outbox.benchmark") == "true")`.
4. `OutboxScaleBenchmarkHarnessTest.kt:452`: `benchmarkOutboxDeliveryThroughput()`  
   *Reason*: Gated by `Assume.assumeTrue(System.getProperty("test.outbox.benchmark") == "true")`.
5. `SmallTraderReadPilotSmokeTest.kt:41`: `readPilotSmokeLiveHttp()`  
   *Reason*: Gated by `Assume.assumeTrue(System.getProperty("test.pilot.live") == "true")`. Requires running live loopback server.
6. `ExampleRobolectricTest.kt:15`: `read string from context`  
   *Reason*: Annotated with `@org.junit.Ignore("Requires online Maven shadow jar download; tested via JVM ExampleUnitTest")`.

**Total Test Qualification:** 919 discovered - 6 skipped = **913 passing tests**.

---

## 3. Detailed Semantic Comparison Matrix

| Area | PR #24 (`d43d2c2`) Implementation | PR #25 (`ba8c60a`) Implementation | Semantic Assessment & Resolution |
|---|---|---|---|
| **1. Bootstrap Precision Opt-in** | `SmallTraderReadClient.getBootstrap(..., includeCurrencyMinorUnits = true)` transmits `include=currency_minor_units`. `BootstrapModels.kt` supports dual schema: `ALLOWED_KEYS_DEFAULT` (12) and `ALLOWED_KEYS_WITH_CURRENCY` (13). Rejects unrequested currency key; validates integer `0..6` when requested. | Reverted to strict static 12 keys (`ALLOWED_KEYS`). `SmallTraderReadClient.getBootstrap()` does not send query param. Throws schema exception if server returns 13 keys. Defaults client UI to hardcoded 2dp. | **PR #24 is authoritative.** Must restore 12 vs 13 key schema in `BootstrapModels.kt` and wire `currency_minor_units` to `ProductionTraderRepository` and `TraderViewModel`. |
| **2. Line Tax & ExplicitNoTax Choice** | Active bootstrap taxes selection and `ExplicitNoTax` control in `StaffOnlineSaleScreen`. Strict line validation: null tax rate ID requires `"0.000000"` rate and `taxInclusive = false`. Unassigned tax blocks checkout. | `ProductionTraderRepository.kt:1360–1375` hardcodes `val taxInclusive = false`. UI lacks tax selection dropdown or explicit NoTax button. Silently applies product catalog tax rate or falls back to 0 without tax-inclusive awareness. | **Defect in PR #25.** Must port explicit line tax selection and `ExplicitNoTax` into `InvoiceReviewScreen` / `NewSaleScreen`, preserving `isTaxInclusive` snapshot instead of hardcoding `false`. |
| **3. Hold / Status Recovery** | On dropped network ACK, sets `PaymentUnverifiedHold`. Preserves basket and intent. Blocks new sale. Exposes GET `/offline-events/{clientEventId}/status`. Unlocks receipt only upon server confirmation. Durably persisted via `DurableOnlineSaleRecoveryStore` across reboots. | `SaleSyncGateway` and `SaleSyncCoordinator` have `checkStatus()`. However, `TraderViewModel.completeCashSale()` treats `PENDING_SYNC` as immediate success, clearing basket, creating new UUID, and navigating to `RECEIPT`. | **High Risk in PR #25.** If background sync fails or conflicts, basket is already lost and cashier is on receipt screen. Must add hold state in `TraderViewModel` when status is `PENDING_SYNC`. |
| **4. Generation & Lifecycle Guards** | `StaffWorkspaceViewModel` tracks monotonic `generation`, cancels `saleJob` on `onCleared` and across all auth/scope transitions (`restoreSession`, `signIn`, `logout`, `clearListsImmediately`). Discards stale 401 callbacks without locking queue. | Adds query generation counters (`supplierSearchGeneration`, `customerSearchGeneration`, etc.) and re-keys Compose tree via `key(...)`. But `TraderViewModel.onCleared()` only calls `repository.close()` without cancelling in-flight sale jobs. | **Partial Parity.** PR #25's Compose re-keying is clean, but `TraderViewModel` must track and cancel `saleJob` on `onCleared()` and during auth resets. |
| **5. Release Entrypoint** | Launches `AppLaunchTarget.STAFF_WORKSPACE` (`StaffWorkspaceApp`) containing only staff login and prototype sale screen. | Introduces `AppLaunchTarget.FULL_SHELL` in `AppLaunchTargetResolver.kt` and wires `MainActivity` to `AuthenticatedTraderApp`, running complete merchant shell with all tabs and dialogs. | **PR #25 is superior.** `FULL_SHELL` represents the real merchant product. Release entrypoint must be `FULL_SHELL`, incorporating the financial fixes from PR #24. |
| **6. `forTesting` Reachability** | `forTesting` helpers isolated or bounded in test files. | `ProductionTraderRepository.forTesting(...)` is declared in `src/main` (lines 3851–3915) with hardcoded test tokens, mock origins, and default warehouse ID. Zero callers in `src/main`. | **Architectural Defect in PR #25.** While R8 minify can tree-shake unused methods in release, having test factory in `src/main` risks shipping simulation code. Must extract to `app/src/test`. |

---

## 4. Classification: Superseded vs Preserved Functionality

### A. Superseded Functionality (Do Not Port from PR #24)
1. `StaffOnlineSaleScreen.kt` & `StaffOnlineSaleViewModel.kt`: Fully superseded by `NewSaleScreen.kt`, `InvoiceReviewScreen.kt`, and `TraderViewModel.kt` in the full merchant shell.
2. `AndroidV2OnlineCashClient.kt` & `OnlineCashSaleOrchestrator.kt`: Superseded by `HttpSaleSyncGateway.kt` and `SaleSyncCoordinator.kt` integrated with Room Outbox v2 (`MerchantCommandStore.kt`).
3. `AppLaunchTarget.STAFF_WORKSPACE`: Superseded by `AppLaunchTarget.FULL_SHELL` in `AuthenticatedTraderApp.kt`.

### B. Functionality That Must Be Preserved & Ported into PR #25
1. **Dual Bootstrap Schema & Opt-In (`BootstrapModels.kt`, `SmallTraderReadClient.kt`)**: Strict 12 default vs 13 requested projection.
2. **Line Tax & ExplicitNoTax Selection (`InvoiceReviewScreen.kt`, `ProductionTraderRepository.kt`)**: Active tax picker, `taxInclusive` snapshot support, and `ExplicitNoTax` rule.
3. **Unverified Payment Hold Protection (`TraderViewModel.kt`, `ReceiptScreen.kt`)**: Basket preservation and hold screen when outbox status is `PENDING_SYNC`.
4. **Lifecycle & Coroutine Cancellation (`TraderViewModel.kt`)**: Explicit `saleJob` cancellation on `onCleared()`.
5. **Clean Separation of Test Code (`ProductionTraderRepositoryTestFixtures.kt`)**: Relocate `forTesting` to `app/src/test`.

---

## 5. Implementation Guidance: File Ownership & Exact Patches

### Patch 1: Bootstrap Currency Minor Units Opt-In
- **File Ownership:** Luong
- **Files Affected:**
  - `app/src/main/java/com/example/data/mobile/readclient/BootstrapModels.kt`
  - `app/src/main/java/com/example/data/mobile/readclient/SmallTraderReadClient.kt`
  - `app/src/main/java/com/example/data/ProductionTraderRepository.kt`
- **Target Changes:**
  1. In `BootstrapModels.kt`, restore `val currencyMinorUnits: Int? = null` on `BootstrapResponse`.
  2. Define `ALLOWED_KEYS_DEFAULT` (12 keys) and `ALLOWED_KEYS_WITH_CURRENCY` (13 keys, adding `"currency_minor_units"`).
  3. In `fromStrictJson(obj: StrictJsonObject, includeCurrencyMinorUnits: Boolean = false)`:
     - When `includeCurrencyMinorUnits == false`, validate against `ALLOWED_KEYS_DEFAULT`.
     - When `includeCurrencyMinorUnits == true`, validate against `ALLOWED_KEYS_WITH_CURRENCY` and require `currency_minor_units` to be integer in `0..6`.
  4. In `SmallTraderReadClient.getBootstrap`, add parameter `includeCurrencyMinorUnits: Boolean = false` and append query parameter `mapOf("include" to "currency_minor_units")` when true.
  5. In `ProductionTraderRepository.fromReadReady`, store and expose `bootstrap.currencyMinorUnits ?: 2`.
- **Target Test:**
  `app/src/test/java/com/example/data/mobile/readclient/BootstrapModelsTest.kt` verifying both default (12) and requested (13) parsing.

### Patch 2: Line Tax Snapshot & ExplicitNoTax Control
- **File Ownership:** Luong
- **Files Affected:**
  - `app/src/main/java/com/example/data/ProductionTraderRepository.kt`
  - `app/src/main/java/com/example/viewmodel/TraderUiState.kt`
  - `app/src/main/java/com/example/ui/screens/InvoiceReviewScreen.kt`
- **Target Changes:**
  1. In `ProductionTraderRepository.kt:1360–1375`, remove hardcoded `val taxInclusive = false`.
  2. Read `taxInclusive` from basket line item or product tax snapshot (`basketItem.product.isTaxInclusive ?: false`).
  3. Validate `ExplicitNoTax`: if `taxRateId == null`, require `taxRate = "0.000000"` and `taxInclusive = false`.
  4. In `InvoiceReviewScreen.kt`, provide tax selection chips/sheet for lines with active bootstrap taxes and an explicit `ExplicitNoTax` action.
- **Target Test:**
  `app/src/test/java/com/example/MerchantCheckoutTest.kt` verifying tax-inclusive sale line intent generation and `ExplicitNoTax` compliance.

### Patch 3: Unverified Payment Hold in TraderViewModel
- **File Ownership:** Luong
- **Files Affected:**
  - `app/src/main/java/com/example/viewmodel/TraderViewModel.kt`
  - `app/src/main/java/com/example/viewmodel/TraderUiState.kt`
  - `app/src/main/java/com/example/ui/screens/ReceiptScreen.kt`
- **Target Changes:**
  1. In `TraderViewModel.kt:completeCashSale`, inspect `result.getOrNull()?.outboxStatus`.
  2. If status is `"PENDING_SYNC"`, do **not** clear basket, do **not** generate new `currentSaleClientEventId`. Set `isPaymentUnverifiedHold = true` and display Hold warning banner with "Check Status" button.
  3. Implement `checkSaleStatus()` in `TraderViewModel` calling `gateway.checkStatus(...)`. Clear basket and advance only when status becomes `CONFIRMED`.
- **Target Test:**
  `app/src/test/java/com/example/MerchantCheckoutTest.kt` verifying that transport ACK drop does not clear basket or discard the client event ID.

### Patch 4: Extract `forTesting` from Production Source
- **File Ownership:** Luong
- **Files Affected:**
  - `app/src/main/java/com/example/data/ProductionTraderRepository.kt` (remove lines 3851–3915)
  - `app/src/test/java/com/example/data/ProductionTraderRepositoryTestFixtures.kt` (create new file with `forTesting` extension)
- **Target Changes:**
  Move `ProductionTraderRepository.Companion.forTesting(...)` out of `src/main` into `src/test`. All existing unit tests continue to compile and run against the fixture without shipping test token logic in the APK.
- **Target Test:**
  Verify clean compilation of `app/src/test/java/com/example/data/NativeMerchantWritesQualificationTest.kt`.
