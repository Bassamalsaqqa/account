# Controlled opening inventory import

Status: implementation candidate, not production APPLY acceptance. No customer data or real workbook belongs in this document, tests, public PRs or Git history.

The CLI `inventory:opening-import` reads a narrow, checksum-pinned XLSX stocktake and a separate private reviewed plan. Default mode is read-only; `--dry-run` makes that choice explicit. The normal member mode authenticates the actual active tenant member interactively. Its enrolled second factor is not bypassed. The explicit `--as-owner` mode instead uses trusted server-shell custody and an active administrator's `company.impersonate` grant, resolves the active tenant Owner server-side and requires interactive exact-plan confirmation. Both modes use the same canonical stock/accounting services and unchanged tenant permissions. No membership, customer login/credential, MFA setting or company policy is created or modified to obtain authority. No email or AI call is made. See [administrative delegation](PLATFORM_SUPPORT_VIEW.md).

## Source and plan

The six headers are `Item name`, `code`, `Quantity`, `Cost price`, `Sale price`, `EXP date`, in one `Sheet1` using the 1900 date calendar. The reader preserves source row numbers and source text, exact decimal strings and text identifiers including leading zeroes. It rejects formulas, errors, nontext identifiers, unsupported dates, misaligned/extra populated columns, missing quantity/prices, hidden rows, duplicate archive entries, DTD/entity declarations and oversized input. Supported serial-date formats include the locale-specific built-in format 58; see [Microsoft Open XML numbering formats](https://learn.microsoft.com/en-us/dotnet/api/documentformat.openxml.spreadsheet.numberingformat). Unsupported workbooks require explicit normalization and renewed source review, never guessing.

Plan version 1 binds the company public ID, actual actor (and `effective_actor_id` for delegated execution), expected active Owner identity, original workbook hash, normalized rows hash, currency, stock-count/posting dates and a tenant baseline hash. It records ownership, completeness, nonduplication, no-payable and per-base-unit price confirmations. Every original row is mapped in order, including explicit exclusions with a reason. Included rows bind an explicit product identity group, optional exact existing product public ID, active same-company unit and warehouse, expiry decision and zero-cost confirmation where needed. The SHA is `OpeningInventoryImport::hash()` of the decoded ordered plan; preserve the exact structured plan rather than regenerating it differently after approval.

Product grouping is a business decision: a shared barcode cannot map to different products; a same-name/different-code pair is not silently classified. Codes can be explicitly classified as barcodes or SKUs. Blank identifiers remain null. English-only names remain English-only. Existing catalog matches, including soft-deleted rows, require review; no existing prices, identity or expiry policy are automatically overwritten. Unrelated existing catalog records remain untouched.

Missing expiry normally blocks an included row. A separately recorded Owner decision can explicitly permit that individual row as an **unknown-expiry tracked lot**. It is never fabricated or marked non-expiring. Existing canonical FEFO places eligible dated lots first and unknown dates last; no date exists to establish expiry disposal. An authentic source date cannot be erased using the unknown-expiry confirmation. Manufacturer lot numbers remain null when unknown.

## Write boundary and replay

The importer composes `ProductCatalogService::createProduct()`, its normal barcode service and one `PostOpeningStockAction::execute()` per included original row. One company-first `TenantWriteAccess` transaction encloses the entire import, including the completion audit. Products, mandatory base ProductUnits, barcodes, lots, movements, valuation, GL and provenance either commit together or roll back together. The existing financial/stock writers and exact decimal policy are unchanged. There is no migration, new infrastructure, queue or permanent import UI.

Opening recognition is **Dr Inventory / Cr Opening Balance Equity**, in confirmed base currency; supplied sale prices are catalog suggestions, not sales/revenue. No purchase, invoice, payable or payment is invented. Existing business history or baseline drift blocks a new import pending a separately reviewed cutover treatment. All six canonical reconciliations must be healthy before and after posting.

An existing tenant audit event holds source/plan hashes, real actor, source-provider attribution and row-level canonical movement/batch results. Company serialization prevents concurrent double-creation, and canonical per-row idempotency keys are source-bound. Exact authorized retries return the original result before comparing the now-changed baseline; changed plans/actors conflict. Membership, permissions, company status and commercial admission are rechecked. Audit or GL failure rolls back the whole import. An interrupted uncommitted run can retry the same plan; do not replace its key or silently begin a new import.

Dry-run uses a server-enforced read-only transaction and performs no product, audit, session or password-rehash writes. Normal member execution requires actual operator credentials entered interactively, never a password passed on the command line or recorded in a plan. Explicit trusted-host delegation requires the existing platform grant and exact interactive plan confirmation instead of the customer password. A dry-run is not Owner approval to APPLY.

## Separate release and APPLY gates

1. Review the frozen implementation and negative/concurrency evidence independently. Run the appropriate isolated MariaDB QA; no persistent test or production database is used for synthetic writes.
2. Confirm all customer decisions and exact row mapping privately. Re-read current production identity, catalog/history and six reconciliations; pin the approved source/plan and actual actor.
3. Obtain separate source-release authorization if deployment is needed. Do not infer it from an inventory preparation task.
4. Before APPLY, create a coherent fresh backup with off-host hash custody and an isolated restore/compatible boot proof. Retain source, configuration/key custody and uploads privately. Use the existing controlled release/recovery procedure.
5. Obtain explicit Owner approval naming the company, workbook SHA, reviewed plan SHA and production APPLY. Revalidate drift and hold on any change; use an appropriate reviewed write freeze.
6. Run once, capture canonical IDs, exact quantities/value, lot/FEFO and AR/EN inventory views, all six reconciliations and unrelated-tenant preservation. Verify an exact retry adds no economic effects. Delegated host retries may add truthful access/write audit events for that new administrator execution. Never restore a stale whole database over later business activity; subsequent correction requires a reviewed canonical compensating event.

Command template (not an authorization and not executed in production):

```text
php artisan inventory:opening-import <private-source.xlsx> <private-plan.json> --company=<public-id> --source-sha=<sha256> --plan-sha=<sha256> --operator=<actual-member-email> --dry-run
```

The separately approved invocation replaces `--dry-run` with `--apply`. Never put credentials or customer inventory in public command output/evidence. Keep the private pre-apply decision packet authoritative for actual hashes, customer decisions, verification status and blockers.
