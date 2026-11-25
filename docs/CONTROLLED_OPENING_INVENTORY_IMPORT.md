# Controlled Opening Inventory Import

Candidate CLI (`inventory:opening-import`) for opening stocktake onboarding. Default is read-only (`--dry-run` explicit). **Requires explicit Owner approval before any live `--apply`; never place customer workbooks or credentials in Git.**

## Source & Plan Contract

- **Workbook:** Single `Sheet1` XLSX (1900 date calendar) with exact headers `Item name`, `code`, `Quantity`, `Cost price`, `Sale price`, `EXP date`. Preserves exact decimals and leading-zero codes; rejects formulas, errors, hidden rows, duplicate entries, DTDs, and oversized files.
- **Plan v1:** Binds company public ID, actual actor (+ `effective_actor_id` for `--as-owner`), expected Owner, workbook SHA-256, normalized rows SHA-256, currency, dates, baseline hash, explicit row mapping/exclusions, unit/warehouse, and `OpeningInventoryImport::hash()`.
- **Expiry & catalog rules:** No silent overwrites of existing prices/identities/policies. Missing expiry blocks an included row unless explicitly marked as an **unknown-expiry tracked lot** (dated lots sort first in FEFO, unknown last; authentic dates cannot be erased).

## Atomic Write & Authority

- Composes `ProductCatalogService::createProduct()`, barcode service, and `PostOpeningStockAction::execute()` inside one company-locked `TenantWriteAccess` transaction (`Dr Inventory / Cr Opening Balance Equity` in base currency; sale prices are catalog suggestions only).
- Existing business history or baseline drift blocks import. Exact authorized retries return the recorded audit result without duplication; changed plans conflict. `--dry-run` uses a read-only transaction with zero writes.
- Normal mode authenticates member interactively (honoring enrolled MFA); `--as-owner` requires trusted shell + `company.impersonate` + exact plan confirmation ([Platform Impersonation](PLATFORM_SUPPORT_VIEW.md)).

## Pre-Apply Gates & Command

1. Verify isolated MariaDB QA and clean six-domain reconciliations before and after posting.
2. Confirm customer mapping privately; stage coherent off-host backup + verified restore before `--apply`.
3. Obtain Owner approval naming company, workbook SHA, plan SHA, and live apply.

```bash
php artisan inventory:opening-import <private-source.xlsx> <private-plan.json> \
  --company=<public-id> --source-sha=<sha256> --plan-sha=<sha256> --operator=<member-email> --dry-run
# Replace --dry-run with --apply only under explicit Owner authorization.
```
