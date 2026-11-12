# Currency Minor-Unit Qualification Report

## 1. Overview & Baseline SHA
- **Baseline SHA**: `f8a0cf7012c4875bec7320d42e0f8dde58593b52` (clean git checkout from `origin/main`)
- **Previous Head**: `d5c1940d9a4c24b0143cdd3b67134ba92d2a78d2`
- **Branch**: `t3/currency-minor-unit-qualification`
- **PR**: https://github.com/Bassamalsaqqa/account/pull/93
- **Goal**: Independent canonical currency minor-unit qualification fix before mobile financial implementation. Bounded to online sale price, draft integrity, preview, posting, returns, and customer payment paths needed for first release.
- **Out of Scope**: Mobile posting routes, offline sync policy, receipt store implementation, purchasing domain writers, GL core base currency policies (`CreateCompanyAction` base currency restrictions).

---

## 2. Supported Qualification Matrix

| Currency Layer / Scope | Supported Scales | Details / Verification Matrix |
| :--- | :--- | :--- |
| **Base Currency (Company Creation / GL Core)** | 2 & 3 decimals (`ILS`, `USD`, `JOD`) | Restrained by existing `CreateCompanyAction` and `AccountingPostingService` foundation invariants. Standard base currencies remain ILS, USD, JOD. |
| **Foreign Currencies (Sales & Payments End-to-End)** | 0, 2, 3 decimals (`JPY`, `USD`, `EUR`, `KWD`, `BHD`, `OMR`) | Fully qualified end-to-end under ILS base: Quotations, Invoices, FEFO allocation, Post, Customer Payments (full & excess unallocated), and Partial/Full Sales Returns. |
| **MoneyValues / Scale Resolver Layer** | 0..6 decimals | Supported and tested up to 6 decimals (matching `decimal(20, 6)` database schema) for parsing, arithmetic formatting, and scale validation. |

---

## 3. Identified Inconsistencies & Resolutions

### 3.1 Baseline Defects Addressed
1. **`app/Services/Sales/SalesDocumentRules::price`**:
   - Previously hardcoded `currency === 'JOD' ? 3 : 2` for price permission comparison.
   - Did not handle non-JOD 3-minor currencies (e.g. KWD, BHD, OMR), 0-minor currencies (e.g. JPY), or higher-precision currencies.
   - Now accepts pre-resolved `$minorUnits` to avoid per-line query amplification.
2. **`app/Actions/Sales/PostCustomerPaymentAction`**:
   - Previously hardcoded `in_array(currency, ['JOD', 'KWD', 'BHD', 'OMR']) ? 3 : 2`.
   - Used `RoundingMode::UNNECESSARY` inside `amount` calculation without catching `RoundingNecessaryException`, resulting in unhandled exceptions.
   - Did not verify that customer payment receipt currency was enabled for the company.
   - Previously locked currencies incrementally inside allocation loops, introducing potential cross-transaction ordering inconsistencies.
3. **`app/Services/Sales/DraftInvoiceIntegrity`**:
   - Hardcoded `currencyMinorUnits: $invoice->currency_code === 'JOD' ? 3 : 2`. Non-JOD 3-minor currency drafts (e.g. KWD) failed draft validation.
4. **`app/Services/Sales/ReceiptPreview`**:
   - Hardcoded `scale = $currency === 'JOD' ? 3 : 2`.
5. **Draft & Quotation Actions / Forms**:
   - `CreateSalesInvoiceDraftAction`, `UpdateSalesInvoiceDraftAction`, `CreateQuotationAction`, `UpdateQuotationAction`, `CreateSalesReturnDraftAction`, `SalesReturnAmounts`, `SalesProductSelection`, `SalesCorrectionAudit`, `InvoiceForm`, `QuotationForm` all contained hardcoded `currency === 'JOD' ? 3 : 2` or `['JOD', 'KWD', 'BHD', 'OMR']` lists.
6. **`app/Services/Money/MoneyValues::amount`**:
   - Queried `Currency.minor_units` with an ad-hoc inline query instead of using a unified authoritative resolver.

### 3.2 Canonical Resolution Implemented
- **`App\Services\Money\CurrencyMinorUnitsResolver`**:
   - Registered as `scoped` in `AppServiceProvider` (request-scoped singleton with flushable in-memory caches).
   - Queries master `currencies` table and validates currency existence, `active` status, and ensures minor units fall within `0..6`.
   - **Separation of Concerns**:
     - `resolveForCompany(Company $company, string $currencyCode)`: In-memory cached lookup for fast reads, draft calculation, and interactive price rule validation.
     - `resolveCurrenciesForCompanyWrite(Company $company, array $currencyCodes, bool $lockForUpdate = false)`: **Authoritative transactional write validation**. Directly executed inside transaction boundaries before any business checks or state mutations.
       - Enforces active database transaction when `$lockForUpdate` is true (`DB::transactionLevel() > 0`), throwing a `LogicException` outside transactions to prevent autocommit lock drops.
       - Master `Currency` records are acquired with `sharedLock()` in deterministic alphabetical order, protecting master currency metadata from concurrent writes/updates without serializing independent companies sharing the same global currency (e.g., ILS/USD).
       - Tenant `CompanyCurrency` records are acquired with `lockForUpdate()` in deterministic alphabetical order, preserving company-scoped mutual exclusion.
       - Revalidates master active status and company enablement from fresh database state, and refreshes the scoped resolver cache. No caller `flush()` is required.
     - `resolve(string $currencyCode)` / `resolveHistorical(string $currencyCode)`: Used for historical transaction inspection, audit (`SalesCorrectionAudit`), reversals, and returns (`CreateSalesReturnDraftAction`, `SalesReturnAmounts`). Does not require master currency to be active or company enabled, ensuring retired/disabled currencies do not corrupt historical reads.
   - Raw original minor units validation ensures non-integer inputs (e.g. `2.5`) are rejected strictly.
- **Write Authority Protection Across Action Callsites**:
   - `CreateSalesInvoiceDraftAction`: Resolves and locks document + base currency using `resolveCurrenciesForCompanyWrite` before number generation and line processing.
   - `UpdateSalesInvoiceDraftAction`: Resolves and locks document + base currency using `resolveCurrenciesForCompanyWrite`.
   - `PostSalesInvoiceAction`: Resolves and locks document + base currency deterministically before customer/company snapshots, FEFO allocation, and posting.
   - `PostCustomerPaymentAction`: Resolves and locks the full union of receipt, base, and all allocation invoice currencies ONCE upfront in deterministic order before amount processing. Allocation invoices are locked deterministically by ID within tenant company. Repetitive per-allocation locking inside the allocations loop is completely eliminated.
   - `ImmediateSaleCommands`: Resolves and locks payment, invoice, and base currencies deterministically from strict validated signed raw intent and owned accounts/checks upfront BEFORE calling `intent()` and `paymentTerms()`. Returns 409 HttpException on stale confirmation or deactivation with atomic transaction rollback.
   - `CreateQuotationAction` & `UpdateQuotationAction`: Pre-resolve and lock currencies before sequence generation and line processing.
- **Query Amplification Elimination (N+1)**:
   - Pre-resolves minor units once per canonical document operation and refreshes cache.
   - Line validation loops in `SalesDocumentRules::price(...)` perform 0 additional currency queries.
   - Benchmark confirmed: 1 line draft creation executes **3 currency metadata queries**; 100 lines draft creation executes **3 currency metadata queries** (delta = 0).
- **Historical Scale Preservation vs Master Mutation**:
   - Schema stores currency amounts as `decimal(20, 6)` across all document and ledger lines, but does NOT store an independent snapshot column for `minor_units`.
   - Auditing of the codebase confirmed there are **no runtime editors or actions** permitting master currency `minor_units` modification.
   - Master currency scale mutation at runtime is **unsupported** and unbackfilled. The PR boundary provides **retirement compatibility** (deactivated master currencies or disabled company currencies remain readable and auditable for historical transactions and returns). It does NOT protect against out-of-band raw SQL updates to master scale. A documented hold test (`test_documented_hold_historical_reads_source_current_master_scale_without_snapshot`) explicitly documents this architecture boundary.

---

## 4. Decimal Arithmetic & Rounding Policy
- **Exact Decimal Arithmetic**: Uses `Brick\Math\BigDecimal` across all calculations.
- **No Float Math**: Zero floating-point arithmetic introduced.
- **Receipt Input Scale Enforcement**: Receipt inputs use `RoundingMode::UNNECESSARY` to enforce that receipt amounts match canonical currency minor units exactly. Trailing zeros are accepted (e.g. `100.500000` for ILS), while overprecision (e.g. `100.501` for ILS) is rejected with an informative `InvalidArgumentException`.
- **Rounding Rule Preservation**: `HALF_UP` rounding is preserved strictly where existing explicit business calculations call for it (e.g. `SalesLineCalculator`, price suggestion comparisons, return cumulative entitlement allocations).
- **Legitimate Zero-Value Items**: Policy for legitimate zero-value lines (free samples, warranty replacements) remains intact and verified.

---

## 5. Verification & Test Evidence

### 5.1 Dedicated Qualification Suite
- **Test File**: `tests/Feature/Money/CurrencyMinorUnitQualificationTest.php` (32 tests, 191 assertions)
- **Execution Command**:
  ```bash
  APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- tests/Feature/Money/CurrencyMinorUnitQualificationTest.php
  ```
- **Results**:
  ```
  OK (32 tests, 191 assertions)
  Owned primary schema cleaned.
  ```

### 5.2 Full Sales Regression Suite
- **Test Files**:
  - `tests/Unit/Phase4/SalesCalculatorsTest.php`
  - `tests/Feature/Phase4/CustomerPaymentAndFxTest.php`
  - `tests/Feature/Phase4/SalesInvoicePostingAndFefoTest.php`
  - `tests/Feature/Fin1/ImmediateSaleCommandsTest.php`
  - `tests/Feature/Phase6/CrossCurrencyCustomerPaymentsTest.php`
  - `tests/Feature/Money/CurrencyMinorUnitQualificationTest.php`
- **Execution Command**:
  ```bash
  APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- tests/Unit/Phase4/SalesCalculatorsTest.php tests/Feature/Phase4/CustomerPaymentAndFxTest.php tests/Feature/Phase4/SalesInvoicePostingAndFefoTest.php tests/Feature/Fin1/ImmediateSaleCommandsTest.php tests/Feature/Phase6/CrossCurrencyCustomerPaymentsTest.php tests/Feature/Money/CurrencyMinorUnitQualificationTest.php
  ```
- **Results**:
  ```
  OK (63 tests, 382 assertions)
  Owned primary schema cleaned.
  ```

### 5.3 History Reconciliation Suite (All 6 Canonical Reconciliations Verified)
- Dedicated integration tests in `CurrencyMinorUnitQualificationTest` verify all six reconciliation services against multi-currency transactions and returns, confirming zero discrepancies and zero violations:
1. `AccountingReconciliationService::reconcile($company)` -> `isHealthy === true`, `violations === []`
2. `InventoryReconciliationService::auditCompany($company)` -> `isHealthy === true`, `discrepancies === []`
3. `SalesReconciliationService::reconcile($company)` -> `isHealthy === true`, `violations === []`
4. `PayablesReconciliationService::reconcile($company)` -> `isHealthy === true`, `violations === []`
5. `MoneyReconciliationService::reconcile($company)` -> `isHealthy === true`, `violations === []`
6. `Phase7ReconciliationService::reconcile($company)` -> `isHealthy === true`, `violations === []`

Database fingerprinting before and after running all six reconciliations confirmed zero mutation of persisted database state.
In addition, `Phase7ReconciliationTest` and `StockAccountingReconciliationTest` were executed on MariaDB:
```
OK (18 tests, 143 assertions)
Owned primary schema cleaned.
```

### 5.4 Static Analysis & Formatting
- **PHPStan (Strict level across all configured files)**:
  ```bash
  ./vendor/bin/phpstan analyse --no-progress
  # [OK] No errors
  ```
- **Laravel Pint**:
  ```bash
  ./vendor/bin/pint --test
  # {"tool":"pint","result":"passed"}
  ```

---

## 6. Scope Boundaries & Remaining Writer Risks

> [!WARNING]
> While online sales price calculation, draft integrity, document rules, preview, posting, returns, and customer payment paths are now canonically qualified for currencies with scales 0..3 end-to-end and up to 6 at resolver level, the following areas remain outside the bounded scope of this PR and should NOT be assumed to be fully qualified:
>
> 1. **Purchasing Domain**:
>    - `PostVendorPaymentAction`, `PurchaseCalculator`, `PurchaseReturnAmounts`, `PurchaseReturnPostedIntegrityValidator`, `VendorPaymentHistoryCommands`, and `VendorPaymentPreview` contain hardcoded scale logic or JOD branches. They must be addressed in a subsequent purchasing qualification PR.
> 2. **General Ledger & Company Creation**:
>    - `CreateCompanyAction` limits company base currency to `ILS`, `USD`, `JOD`. Core GL posting assumes 2 or 3 minor units for base currency journal balances.
> 3. **Mobile Posting Routes & Receipt Store**:
>    - Not modified in this PR. Bounded strictly to backend qualification.
