# Mobile Read Metadata Contract: G1 Currency Minor Units & G2 Selected Tax Lookup

**Date**: 2026-11-15T09:22:00+02:00  
**Status**: Concrete Source Specification & Field Contract  
**Deliverables**: G1 (Bootstrap `currency_minor_units`) & G2 (Catalog Tax Authority & Selected Tax Lookup)  
**Scope**: Read-Only Metadata Controller Patch + Contract Schemas + Android Coordination  

---

### 1. Architectural Scope & Invariant Boundaries

This document defines the authoritative read-metadata specification for Mobile V1 Bootstrap and Product Catalog endpoints, addressing findings G1 and G2:
1. **Economic Core Immutability**: All financial transaction actions (`app/Actions/Sales/*`, `app/Actions/Company/*`), domain calculators (`app/Domain/Sales/*`), routes (`routes/*`), configuration (`config/*`), and dependencies (`composer.lock`) remain strictly frozen.
2. **Approved Metadata Controller Scope**: Changes to production PHP source are strictly isolated to `app/Http/Controllers/Api/Mobile/V1/BootstrapController.php` under the approved read-only metadata controller exception.
3. **Zero Inferred Defaults**: No hardcoded currency scales (no guessing 2); no false tax assertions (`tax_inclusive: false` must not be invented for unassigned catalog items).
4. **No Server Quote Endpoints**: Tax calculation rules ride strictly on `bootstrap.taxes[].calculation` authority; no new preview or quote endpoints are invented.

---

### 2. G1: V1 Bootstrap `currency_minor_units` Resolution

#### 2.1 Resolution Authority
- **Endpoint**: `GET /api/mobile/v1/companies/{companyId}/bootstrap`
- **Controller**: [`App\Http\Controllers\Api\Mobile\V1\BootstrapController`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/app/Http/Controllers/Api/Mobile/V1/BootstrapController.php)
- **Authoritative Resolver**: [`App\Services\Money\CurrencyMinorUnitsResolver::resolveForCompany($company, $baseCurrencyCode)`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/app/Services/Money/CurrencyMinorUnitsResolver.php)
  - This is the exact same authority utilized by V2 Online Cash Sales and financial document posting actions.
  - Sourced directly from master `currencies` table and qualified against tenant `company_currencies`.
  - Precision scale must be an integer within `0..6`.

#### 2.2 Error Handling & Fail-Closed Behavior
If `resolveForCompany` throws `InvalidArgumentException` (e.g. currency unknown, master inactive, disabled for tenant, or invalid scale):
```php
try {
    $currencyMinorUnits = app(CurrencyMinorUnitsResolver::class)
        ->resolveForCompany($company, $baseCurrencyCode);
} catch (InvalidArgumentException $e) {
    return MobileApiBoundary::buildErrorResponse(
        code: 'CONTRACT_INCOMPATIBLE',
        message: 'Company base currency minor units cannot be resolved: '.$e->getMessage(),
        status: 409
    );
}
```
- Returns standard HTTP 409 with error code `CONTRACT_INCOMPATIBLE`.
- **Never falls back or guesses 2**.

#### 2.3 Wire Schema Contract
- File: [`docs/integration/mobile-v1/schemas/bootstrap-response.json`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/mobile-v1/schemas/bootstrap-response.json)
- Added to `properties`:
  ```json
  "currency_minor_units": {
    "type": "integer",
    "minimum": 0,
    "maximum": 6,
    "description": "Authoritative minor units (0..6) of base_currency resolved via CurrencyMinorUnitsResolver::resolveForCompany()."
  },
  "base_currency_minor_units": {
    "type": "integer",
    "minimum": 0,
    "maximum": 6,
    "description": "Authoritative minor units (0..6) of base_currency resolved via CurrencyMinorUnitsResolver::resolveForCompany()."
  }
  ```
- **Optional in Schema**: Kept out of the `required` array. This preserves 100% backward compatibility with existing test fixtures (e.g. `golden-bootstrap.json`) and legacy client parsers.

#### 2.4 Android Consumer Gap Audit (Read-Only)
- **Observed Gap**: In Android's `com.example.data.mobile.readclient.BootstrapResponse`, `ALLOWED_KEYS` is a strict whitelist:
  ```kotlin
  private val ALLOWED_KEYS = setOf(
      "company_id", "company_name", "base_currency", "walk_in_customer_id",
      "staff", "device_lease", "default_warehouse", "approved_cash_account",
      "units", "taxes", "offline_policy", "catalog_snapshot_version"
  )
  ```
  Any unrecognized key triggers `checkAllowedKeys()`, throwing `CanonicalSerializationException`.
- **Current Workaround**: In `com.example.ui.staff.sale.StaffOnlineSaleAuthorityResolution.resolve()`, Android currently reports missing contract:
  `"V1 Bootstrap lacks currency_minor_units (required for OnlineSaleContext scale validation)"` unless an explicit `currencyMinorUnitsOverride` is passed.
- **Coordination Contract for Android Peer**:
  1. Add `"currency_minor_units"` (and/or `"base_currency_minor_units"`) to `BootstrapResponse.ALLOWED_KEYS`.
  2. Map property `val currencyMinorUnits: Int? = null` in `BootstrapResponse`.
  3. In `StaffOnlineSaleAuthorityResolution.resolve()`, resolve authoritative scale as:
     `bootstrap?.currencyMinorUnits ?: currencyMinorUnitsOverride`.

---

### 3. G2: Product Catalog Tax Authority & Selected Tax Lookup

#### 3.1 Catalog Tax Truthfulness (No Invented Defaults)
- **Endpoint**: `GET /api/mobile/v1/companies/{companyId}/products`
- **Controller**: [`App\Http\Controllers\Api\Mobile\V1\ProductController`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/app/Http/Controllers/Api/Mobile/V1/ProductController.php)
- **Current Database Reality**: Products in catalog do not currently have assigned tax domain associations; `tax_rate_id` and `tax_rate` are returned as `null`.
- **Contract Enforcement**:
  - **DO NOT emit `tax_inclusive: false`** on catalog items. Falsely claiming `tax_inclusive: false` implies an assigned tax rate with an exclusive calculation, violating domain truth.
  - Catalog items maintain paired tax nullability:
    `(tax_rate_id === null && tax_rate === null) || (tax_rate_id !== null && tax_rate !== null)`.
  - If a catalog product is unassigned, `tax_inclusive` is strictly `null` (unresolved).
  - Only when a product has an actual assigned tax relation in the database is `tax_inclusive` populated (derived from `tax_rate.calculation === 'inclusive'`).

#### 3.2 Bootstrap Tax Rate Authority
- **Endpoint**: `GET /api/mobile/v1/companies/{companyId}/bootstrap`
- The `taxes` array provides all active company taxes:
  ```json
  {
    "id": "01JCV8X9MDPTRSVWXYZ0123456",
    "code": "VAT16",
    "name": "Standard VAT (16%)",
    "rate": "16.000000",
    "calculation": "exclusive",
    "is_default": true
  }
  ```
- **Authoritative Calculation Field**: `calculation` is strictly enum `["inclusive", "exclusive"]`, sourced from `TaxRate::CALC_INCLUSIVE` / `TaxRate::CALC_EXCLUSIVE`.

#### 3.3 Selected Tax Lookup Contract (No Server Quote Endpoint)
When a product has `tax_rate_id === null` (unassigned):
1. **Client Selection**:
   The staff UI flags the line as unassigned (`tax_rate_id: null, tax_inclusive: null`) and requires the user to select an applicable tax from `bootstrap.taxes` (or pre-selects the default tax if `is_default: true`).
2. **Deterministic Lookup**:
   The client matches the selected `tax_rate_id` against `bootstrap.taxes`:
   - `tax_rate_id = selectedTax.id`
   - `tax_rate = selectedTax.rate`
   - `tax_inclusive = (selectedTax.calculation === "inclusive")`
3. **Dispatch to V2 Wire**:
   When building the raw wire line item for `POST /api/mobile/v2/companies/{companyId}/sales/online-cash`, the resolved parameters are submitted:
   ```json
   {
     "product_id": "01JCV8X9MBV4W5X6Y7Z8012345",
     "product_unit_id": "01JCV8X9MC6789ABCDEFGHJKMN",
     "conversion_to_base": "1.000000",
     "quantity": "2.000000",
     "unit_price": "25.000000",
     "tax_rate_id": "01JCV8X9MDPTRSVWXYZ0123456",
     "tax_rate": "16.000000",
     "tax_inclusive": false
   }
   ```
4. **No Preview / Quote Endpoint**: Both client local estimates and server validation use the exact same calculation authority via `SalesLineCalculator`, eliminating the need for a `/quote` or `/tax-preview` endpoint.

---

### 4. Verification & Validation Summary

| Gate / Component | Target | Status |
| :--- | :--- | :--- |
| **`BootstrapController.php`** | `resolveForCompany` + fail-closed 409 | Verified via `php -l` |
| **`bootstrap-response.json`** | Optional `currency_minor_units` (0..6) | Validated via `Draft202012Validator` |
| **`WireResponse::accepts`** | Backward-compatible + strict bounds | Unit tested in `BootstrapMetadataContractTest.php` |
| **Core Immutability** | `git diff 6981511405d7bcd04cd8f8a09e64443ce0aaf47e -- app` | 33 lines only in approved `BootstrapController.php` |
| **Lease Discipline** | No DB / No PHPUnit during Android lease | Strictly observed (0 DB queries, 0 PHPUnit runs) |
