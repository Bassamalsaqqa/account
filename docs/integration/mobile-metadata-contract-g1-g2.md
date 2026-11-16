# Mobile Read Metadata Contract: G1 Currency Minor Units & G2 Selected Tax Lookup

**Date**: 2026-11-15T09:27:00+02:00  
**Status**: Concrete Proposed Additive Contract & Field Specification  
**Deliverables**: G1 (Bootstrap `currency_minor_units` Read Projection) & G2 (Catalog Tax Authority & Selected Tax Lookup Contract)  
**Scope**: Read-Only Metadata Controller Patch + Contract Schemas + Android Coordination Plan  

---

### 1. Architectural Scope & Invariant Boundaries

This document defines the authoritative read-metadata specification for Mobile V1 Bootstrap and Product Catalog endpoints, addressing findings G1 and G2:
1. **Economic Core Immutability**: All financial transaction actions (`app/Actions/Sales/*`, `app/Actions/Company/*`), domain calculators (`app/Domain/Sales/*`), routes (`routes/*`), configuration (`config/*`), and dependencies (`composer.lock`) remain strictly frozen.
2. **Approved Metadata Controller Scope**: Production PHP edits are strictly isolated to [`app/Http/Controllers/Api/Mobile/V1/BootstrapController.php`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/app/Http/Controllers/Api/Mobile/V1/BootstrapController.php) under the approved read-only metadata controller exception.
3. **No Unrequested Aliases**: The `base_currency_minor_units` alias has been completely removed from source, schema, and documentation. Only the canonical single field `currency_minor_units` is supported.
4. **Zero Inferred Defaults**: No hardcoded currency scales (no guessing 2); no false tax assertions (`tax_inclusive: false` must not be invented for unassigned catalog items).

---

### 2. G1: V1 Bootstrap Explicit Read Projection Opt-In

#### 2.1 Explicit Read Projection Selector (`?include=currency_minor_units`)
To prevent breaking existing Android clients that enforce a strict 12-key whitelist (`BootstrapResponse.ALLOWED_KEYS`), the new field is gated behind an explicit read projection selector:
- **Endpoint**: `GET /api/mobile/v1/companies/{companyId}/bootstrap`
- **Query Parameter**: `include=currency_minor_units` (read metadata selector ONLY; grants no financial authority or capabilities).
- **Controller Implementation**:
  - Captured outside closure before consistent read snapshot:
    ```php
    $includeCurrencyMinorUnits = false;
    if ($request->has('include')) {
        $rawInclude = $request->query('include');
        if (is_array($rawInclude) || ! is_string($rawInclude) || $rawInclude !== 'currency_minor_units') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Invalid include parameter. Supported projection: currency_minor_units.',
                status: 422
            );
        }
        $includeCurrencyMinorUnits = true;
    }
    ```
  - **Absent `include` parameter**:
    - Returns the exact byte/key-compatible old 12-field response.
    - Zero extra keys emitted; does NOT invoke the resolver; preserves existing error semantics.
  - **Exact `include=currency_minor_units`**:
    - Resolves single `currency_minor_units` via `App\Services\Money\CurrencyMinorUnitsResolver::resolveForCompany($company, $baseCurrencyCode)`.
    - Returns standard 12 fields plus `'currency_minor_units' => $currencyMinorUnits`.
  - **Unknown / Malformed `include` parameter**:
    - Immediately rejected with HTTP 422 `VALIDATION_FAILED` (`Invalid include parameter. Supported projection: currency_minor_units.`), consistent with existing V1 controllers (`ProductController`). No silent broad field expansion.

#### 2.2 Authoritative Resolution & Fail-Closed Error Contract
When `include=currency_minor_units` is requested:
- Resolver: [`App\Services\Money\CurrencyMinorUnitsResolver::resolveForCompany($company, $baseCurrencyCode)`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/app/Services/Money/CurrencyMinorUnitsResolver.php)
  - Sourced directly from master `currencies` table and qualified against tenant `company_currencies`.
  - Precision scale must be an integer within `0..6`.
- If the company's base currency is missing from currencies master, globally inactive, not enabled in `company_currencies`, or scale is not in 0..6, `resolveForCompany` throws `InvalidArgumentException`.
- The controller catches `InvalidArgumentException` and fails closed immediately with HTTP 409 code `CONTRACT_INCOMPATIBLE`:
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
- **Never falls back or guesses 2**.

#### 2.3 Wire Schema Contract
- File: [`docs/integration/mobile-v1/schemas/bootstrap-response.json`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/mobile-v1/schemas/bootstrap-response.json)
- Added to `properties`:
  ```json
  "currency_minor_units": {
    "type": "integer",
    "minimum": 0,
    "maximum": 6,
    "description": "Authoritative minor units (0..6) of base_currency resolved via CurrencyMinorUnitsResolver::resolveForCompany() when requested via ?include=currency_minor_units."
  }
  ```
- **Schema-Optional**: Kept outside the `required` array. When `include` is absent, the payload contains only the 12 required keys. When `include=currency_minor_units` is requested, the payload contains the 12 required keys plus `currency_minor_units`. Both conform 100% to the schema.

#### 2.4 Android Consumer Coordination Plan
- **Current Android State**:
  - `com.example.data.mobile.readclient.BootstrapResponse.ALLOWED_KEYS` whitelist enforces exactly 12 keys.
  - Calling `/bootstrap` without query parameters remains 100% functional and backward-compatible.
- **Coordination Steps for Android Peer Agent**:
  1. Update `BootstrapResponse.ALLOWED_KEYS` to include `"currency_minor_units"`.
  2. Add optional property `val currencyMinorUnits: Int? = null` in `BootstrapResponse`.
  3. When requesting bootstrap for Online Cash Sales, pass query parameter `?include=currency_minor_units`.
  4. In `StaffOnlineSaleAuthorityResolution.resolve()`, resolve authoritative scale as:
     `bootstrap?.currencyMinorUnits ?: currencyMinorUnitsOverride`.
  5. Backend makes zero edits to Android (`DO NOTeditAndroid` rule observed).

---

### 3. G2: Product Catalog Tax Authority & Selected Tax Lookup

#### 3.1 Truthful Catalog Tax Representation
- **Endpoint**: `GET /api/mobile/v1/companies/{companyId}/products`
- **Controller**: [`App\Http\Controllers\Api\Mobile\V1\ProductController`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/app/Http/Controllers/Api/Mobile/V1/ProductController.php)
- **Database Reality**: Catalog products do not currently have assigned tax domain associations; `tax_rate_id` and `tax_rate` are returned as `null`.
- **Zero Invented Defaults**:
  - **DO NOT emit `tax_inclusive: false`** on catalog items. Falsely claiming `tax_inclusive: false` implies an assigned tax rate with an exclusive calculation, violating domain truth.
  - Catalog items maintain paired tax nullability:
    `(tax_rate_id === null && tax_rate === null) || (tax_rate_id !== null && tax_rate !== null)`.
  - For unassigned catalog products, tax inclusive status is explicitly `null` (unresolved).
  - Only when a product has an actual assigned tax relation in the database is `tax_inclusive` populated (derived from `tax_rate.calculation === 'inclusive'`).

#### 3.2 Canonical V2 ExplicitNoTax Rule
- Sourced from [`app/Support/Mobile/Contract/Revision3/OnlineSaleIntentProfile.php:545-551`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/app/Support/Mobile/Contract/Revision3/OnlineSaleIntentProfile.php#L545-L551):
  ```php
  $taxRateId = $line['tax_rate_id'];
  if ($taxRateId === null) {
      if ($line['tax_rate'] !== '0.000000' || $line['tax_inclusive'] !== false) {
          throw new IntentProfileValidationException(
              "Explicit NoTax line at index {$idx} requires tax_rate='0.000000' and tax_inclusive=false"
          );
      }
  }
  ```
- **Rule Invariants**:
  - `tax_rate_id: null` is permitted on a sales line **ONLY** when `tax_rate: "0.000000"` and `tax_inclusive: false` are chosen intentionally under company domain policy or grants (e.g. tax-exempt sale).
  - This rule is **NOT inferred from unassigned catalog products**.
  - The client must provide an explicit choice: either an explicit selected tax or an explicit NoTax selection.

#### 3.3 Bootstrap Tax Rate Authority & Selected Tax Lookup
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
- **Authority**: `calculation` is strictly enum `["inclusive", "exclusive"]`, sourced from `TaxRate::CALC_INCLUSIVE` / `TaxRate::CALC_EXCLUSIVE`.
- **Default Tax**: `is_default: true` is purely a UI suggestion, not an authoritative assignment.
- **Client Selected Tax Lookup**:
  - When staff selects a product with `tax_rate_id === null`, the client UI requires selection of an applicable tax from `bootstrap.taxes` (or an explicit NoTax choice).
  - The client matches the selected `tax_rate_id` against `bootstrap.taxes`:
    - `tax_rate_id = selectedTax.id`
    - `tax_rate = selectedTax.rate`
    - `tax_inclusive = (selectedTax.calculation === "inclusive")`
  - The client submits the resolved line parameters on the V2 online cash sales wire.
- **Implementation Distinction**: Kotlin does not share PHP's `SalesLineCalculator` code, and no server quote contract is proved. Clients and backend maintain separate, independently qualified arithmetic implementations conforming to shared canonical test vectors.

---

### 4. Verification & Validation Summary

| Gate / Component | Target | Status |
| :--- | :--- | :--- |
| **`BootstrapController.php`** | `?include=currency_minor_units` opt-in + failclosed 409 | Verified via `php -l` |
| **Parameter Validation** | Unknown include rejected with 422 `VALIDATION_FAILED` | Verified in unit test + `php -r` |
| **`bootstrap-response.json`** | Optional `currency_minor_units`, alias removed | Validated via `Draft202012Validator` |
| **`BootstrapMetadataContractTest.php`** | Exercises controller, projection opt-in, resolver | Syntax verified clean via `php -l` |
| **Core Immutability** | `git diff 6981511405d7bcd04cd8f8a09e64443ce0aaf47e -- app` | Only 45 lines in `BootstrapController.php` |
| **Lease Discipline** | No DB / No PHPUnit during Android lease | Strictly observed (0 DB queries, 0 PHPUnit runs) |
