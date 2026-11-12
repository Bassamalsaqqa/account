# Currency Minor-Unit Qualification Report

## 1. Overview & Baseline SHA
- **Baseline SHA**: `f8a0cf7012c4875bec7320d42e0f8dde58593b52` (clean git checkout from `origin/main`)
- **Branch**: `t3/currency-minor-unit-qualification`
- **Goal**: Independent canonical currency minor-unit qualification fix before mobile financial implementation. Bounded to online sale price, draft integrity, preview, posting, and customer payment paths needed for first release.
- **Out of Scope**: Mobile posting routes, offline sync policy, receipt store implementation, purchasing domain writers, GL core posting currency hardcodings.

---

## 2. Identified Inconsistencies & Resolutions

### 2.1 Baseline Defects Identified
1. **`app/Services/Sales/SalesDocumentRules::price`**:
   - Hardcoded `currency === 'JOD' ? 3 : 2` for price permission comparison.
   - Did not handle non-JOD 3-minor currencies (e.g. KWD, BHD, OMR), 0-minor currencies (e.g. JPY), or higher-precision currencies.
2. **`app/Actions/Sales/PostCustomerPaymentAction`**:
   - Hardcoded `in_array(currency, ['JOD', 'KWD', 'BHD', 'OMR']) ? 3 : 2`.
   - Used `RoundingMode::UNNECESSARY` inside `amount` calculation but did not catch `RoundingNecessaryException`, which resulted in unhandled exceptions instead of user-friendly validation errors.
   - Did not verify that the customer payment receipt currency was enabled for the company.
3. **`app/Services/Sales/DraftInvoiceIntegrity`**:
   - Hardcoded `currencyMinorUnits: $invoice->currency_code === 'JOD' ? 3 : 2`. Non-JOD 3-minor currency drafts (e.g., KWD) failed draft validation.
4. **`app/Services/Sales/ReceiptPreview`**:
   - Hardcoded `scale = $currency === 'JOD' ? 3 : 2`.
5. **Draft & Quotation Actions / Forms**:
   - `CreateSalesInvoiceDraftAction`, `UpdateSalesInvoiceDraftAction`, `CreateQuotationAction`, `UpdateQuotationAction`, `CreateSalesReturnDraftAction`, `SalesReturnAmounts`, `SalesProductSelection`, `SalesCorrectionAudit`, `InvoiceForm`, `QuotationForm` all contained hardcoded `currency === 'JOD' ? 3 : 2` or `['JOD', 'KWD', 'BHD', 'OMR']` lists.
6. **`app/Services/Money/MoneyValues::amount`**:
   - Queried `Currency.minor_units` with an ad-hoc inline query instead of using a unified cached/authoritative resolver.

### 2.2 Canonical Resolution Implemented
- **`App\Services\Money\CurrencyMinorUnitsResolver`**:
  - Authoritative resolver for currency minor units.
  - Queries the master `currencies` table and validates currency existence, `active` status, and ensures minor units fall within the supported domain `0..6` (matching database schema `decimal(20, 6)`).
  - Provides `resolveForCompany(Company $company, string $currencyCode)` to enforce company currency enablement (`company_currencies.enabled == true`).
  - Reused across all sales document price, draft integrity, preview, posting, and customer payment paths.

---

## 3. Decimal Arithmetic & Rounding Policy
- **Exact Decimal Arithmetic**: Uses `Brick\Math\BigDecimal` across all calculations.
- **No Float Math**: Zero floating-point arithmetic introduced.
- **Receipt Input Scale Enforcement**: Receipt inputs use `RoundingMode::UNNECESSARY` to enforce that receipt amounts match the canonical currency minor units exactly. Trailing zeros are accepted (e.g. `100.500000` for ILS), while overprecision (e.g. `100.501` for ILS) is rejected with an informative `InvalidArgumentException`.
- **Rounding Rule Preservation**: `HALF_UP` rounding is preserved strictly where existing explicit business calculations call for it (e.g. `SalesLineCalculator`, price suggestion comparisons).
- **Legitimate Zero-Value Items**: Policy for legitimate zero-value lines (free samples, warranty replacements) remains intact and verified.

---

## 4. Verification & Test Evidence

### 4.1 Dedicated Qualification Suite
- **Test File**: `tests/Feature/Money/CurrencyMinorUnitQualificationTest.php`
- **Execution Command**:
  ```bash
  APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- tests/Feature/Money/CurrencyMinorUnitQualificationTest.php
  ```
- **Results**:
  ```
  OK (13 tests, 81 assertions)
  Owned primary schema cleaned.
  ```

### 4.2 Full Sales Regression Suite
- **Execution Command**:
  ```bash
  APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- tests/Unit/Phase4/SalesCalculatorsTest.php tests/Feature/Phase4/CustomerPaymentAndFxTest.php tests/Feature/Phase4/SalesInvoicePostingAndFefoTest.php tests/Feature/Fin1/ImmediateSaleCommandsTest.php tests/Feature/Phase6/CrossCurrencyCustomerPaymentsTest.php tests/Feature/Money/CurrencyMinorUnitQualificationTest.php
  ```
- **Results**:
  ```
  OK (44 tests, 272 assertions)
  Owned primary schema cleaned.
  ```

### 4.3 Static Analysis & Formatting
- **PHPStan (Level 6)**:
  ```bash
  ./vendor/bin/phpstan analyse
  # [OK] No errors (710 files inspected)
  ```
- **Laravel Pint**:
  ```bash
  ./vendor/bin/pint --dirty --test
  # {"tool":"pint","result":"passed"}
  ```

---

## 5. Scope Boundaries & Remaining Writer Risks

> [!WARNING]
> While online sales price calculation, draft integrity, document rules, preview, posting, and customer payment paths are now canonically qualified for currencies with scales 0..6, the following areas remain outside the bounded scope of this PR and should NOT be assumed to be fully qualified:
>
> 1. **Purchasing Domain**:
>    - `PostVendorPaymentAction`, `PurchaseCalculator`, `PurchaseReturnAmounts`, `PurchaseReturnPostedIntegrityValidator`, `VendorPaymentHistoryCommands`, and `VendorPaymentPreview` contain hardcoded scale logic or JOD branches. They must be addressed in a subsequent purchasing qualification PR.
> 2. **General Ledger & Accounting Service**:
>    - `AccountingPostingService` and `AccountingReconciliationService` contain static lists of standard currencies (`ILS`, `USD`, `JOD`) and company base currency provisioning constraints.
> 3. **Mobile Posting Routes & Receipt Store**:
>    - Not modified in this PR. Bounded strictly to backend qualification.
