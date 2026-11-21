# Mobile V1/V2 Review Resolution & Verification Evidence

## Scope & Provenance

- **Worktree**: `/home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision`
- **Branch**: `t3/mobile-v1-read-release-20261117`
- **Baseline Commit**: `a9fda1d47cd9d2fe9eefe1fc08280d3ac80d4781`
- **Authority / Objective**: Resolve and verify all findings from Huda's review reports (`ce5497f7-3bc7-496a-bc12-d252ab910fa7` and `5343c208-d1b1-431d-8bb8-152d3d4f8def`), enforcing exact accounting, atomicity, idempotency, and tenant boundaries.
- **Tracked Code & Test Files (12)**:
  1. `app/Actions/Sales/ExecuteNativeSalesReturnAction.php`
  2. `app/Actions/Sales/PostSalesReturnAction.php`
  3. `app/Http/Controllers/Api/Mobile/V1/CustomerController.php`
  4. `app/Http/Controllers/Api/Mobile/V1/PurchaseController.php`
  5. `app/Http/Controllers/Api/Mobile/V1/SalesReturnController.php`
  6. `app/Http/Controllers/Api/Mobile/V1/SupplierController.php`
  7. `app/Services/Purchasing/PurchasePayableAsOf.php`
  8. `app/Services/Sales/CustomerCatalogService.php`
  9. `app/Services/Sales/SalesHistoryAudit.php`
  10. `database/migrations/2026_11_21_100000_update_native_command_receipts_idempotency_key_collation.php`
  11. `tests/Feature/Mobile/V1/PartyCatalogIdempotencyAndContractTest.php`
  12. `tests/Feature/Mobile/V2/SalesReturnReceiptRegressionTest.php`

---

## Resolution of Review Findings (5343c208)

### 1. Tax Group Net Zero & Signed Foreign Legs Invariant
- **Issue**: When an invoice has multiple lines under the same tax account with partial returns inverting tax on some lines (e.g. JPY 2 lines on the same tax account: line 1 has tax 0.60, line 2 has tax 0.40; first return takes 0.99 with tax rounded to 1.00; remainder return has line 1 tax -0.40 and line 2 tax +0.40 netting to 0), a naive group total produces zero tax amount, attempting to construct an invalid zero-amount `PostingLineCommand` DTO.
- **Resolution in `PostSalesReturnAction.php`**: Grouped lines per tax account while maintaining separate positive (`pos`) and negative (`neg`) tax components. If positive, emits a Debit Tax Reversal line; if negative, emits a Credit Tax Reversal Adjustment line. Both lines use strictly positive `MoneyAmount` values with corresponding base equivalents. Discrepancies in base rounding are isolated in a dedicated base-only adjustment line. Zero journal line DTOs are prohibited.
- **Verification in `SalesHistoryAudit.php`**: Added signed transaction currency tracking (`$expectedCurrency` and `$actualCurrency`) verifying signed transaction currency component per account in `batch()`, rather than solely net base / absolute FX.
- **Test**: Added `test_genuine_jpy_two_lines_same_tax_account_net_zero_group_success` verifying preview -> commit, balanced debits/credits, signed tax legs (+0.40 Debit, -0.40 Credit), and passing all 6 domain reconciliations.

### 2. Valid 100% Discount Purchase Qualification & Tampered Negative Guards
- **Issue**: A valid 100% discount purchase (e.g. subtotal 10.00, discount 10.00, tax 0.00, payable 0.00) produces a null journal (`posting_batch_id = null`) canonically. `PurchasePayableAsOf` previously had a blanket requirement that `subtotalBase->isZero()`, erroneously rejecting these valid purchases.
- **Resolution in `PurchasePayableAsOf.php`**: Replaced blanket `subtotalBase->isZero()` with line-by-line verification: calculates lines via `PurchaseCalculator`, computes document totals via `SalesDocumentTotalsCalculator`, checks commercial totals against stored purchase headers, and runs `LandedCostIntegrity` and `PurchaseDocumentProvenanceGuard`. If total commercial payable, inventory acquisition, and landed costs are zero, a null journal is canonically permitted.
- **Test**: Added `test_valid_100_percent_discount_purchase_canonical_read_and_tampered_negative_rejection` verifying 100% discount purchases pass while tampered negative/mismatched headers fail under proper tenant context.

### 3. Historical 598c Supplier Hash Compatibility
- **Issue**: Pinned historical handler for commit `598c4bf` computed `$normalizedPayload` with `business_name_ar` mapped from `company_name` without unsetting `company_name`, so the sorted JSON payload hashed both keys. In addition, fresh writes containing `company_name` must not be accepted as new write aliases.
- **Resolution in `SupplierController.php`**: In both `store` and `update`, check for an existing `NativeCommandReceipt` first. Exact old body replays with `company_name` match the historical 598c sorted payload hash (`business_name_ar` and `company_name` present) and replay their frozen response. Fresh writes containing `company_name` (no prior receipt) are rejected with 422 `VALIDATION_FAILED` before any write alias is introduced.
- **Test**: Updated `test_historical_supplier_receipt_replay_with_company_name_and_fresh_request_rejection` and added `test_historical_supplier_update_replay_with_company_name_and_fresh_rejection` using the actual pinned 598c handler to produce test fixtures.

### 4. Preview Return History Shared Snapshot Under Lock
- **Issue**: In `SalesReturnController::preview`, if authorization check (`sales.return.view`) and line calculation occur outside a transaction lock, concurrent return commits between check and calculation create a check/read race exposing newly committed history to unauthorized actors.
- **Resolution in `SalesReturnController.php`**: Wrapped the entire `preview` operation inside `DB::transaction` with `lockForUpdate()`. Prior returns are fetched once and shared identically between the authorization gate and `calculateLine`. If the actor lacks `sales.return.view` and prior returns exist, 403 `FORBIDDEN` is returned immediately under the locked snapshot.
- **Test**: Added `test_preview_return_history_shared_snapshot_interleaving_actor_denial` verifying an actor without `sales.return.view` can preview an invoice with no prior returns, but is denied with 403 once an interleaving return is posted.

### 5. Catalog Receiptless Pre-Upgrade Cutover & Merchant OFF Boundary
- **Issue**: Removing heuristic adoption was necessary, but unrecorded legacy submissions whose ACK was lost cannot be invented or heuristically adopted. Old clients submitting stale patches must not overwrite newer records.
- **Boundary & Policy**:
  - Replay compatibility is strictly bound to cryptographic request hash recovery of frozen historical receipts (including 598c).
  - Unrecorded legacy requests with unknown fields (e.g. `company_name`) fail validation with 422.
  - Production default remains **Merchant OFF** (`mobile.merchant_api_enabled = false` in `config/mobile.php`), containing the release to read-only for merchant endpoints. Write activation requires verified estate evidence and explicit activation.
  - Truthfully disclosed: catalog receiptless pre-upgrade cutover remains an open gate; unrecorded legacy requests cannot be invented.

---

## Cash Classification Test Canonical Dated Reversal

- In `tests/Feature/Mobile/V2/SalesReturnReceiptRegressionTest.php`, in `test_sales_invoice_dated_cash_cutoff_and_future_payment_filtering`, replaced direct table deletion (`DB::table('customer_payment_allocations')->where(...)->delete()`) with canonical dated reversal:
  - Created a reversal posting batch (`PB-REV-CASH-001`) with `posting_date = $today`.
  - Linked `reversed_by_batch_id` on the original posting batch.
  - Set `is_reversed = true`, `reversed_at = now()`, `reversed_by`, `reversal_posting_batch_id`, and `reversal_reason` on `customer_payments`.
  - Allocation rows in `customer_payment_allocations` remain completely intact as immutable historical records.
  - Verified invoice reverts to `ON_CREDIT` and is excluded from `payment_status=PAID_CASH` filters.

---

## Migration Collation Downgrade & Forward-Schema Rehearsal

- **Collation Migration**: `database/migrations/2026_11_21_100000_update_native_command_receipts_idempotency_key_collation.php`
- **Rehearsal Gates**:
  1. `test_migration_guarded_ascii_empty_roundtrip`: Empty table `down()` then `up()` roundtrip succeeds cleanly.
  2. `test_migration_guarded_ascii_populated_roundtrip`: Populated table with ASCII keys `down()` to `ascii_bin` and `up()` to `utf8mb4_bin` preserves rows intact.
  3. `test_migration_down_refuses_non_ascii_unicode_downgrade`: Throws `RuntimeException` when non-ASCII Unicode keys exist.
  4. `test_migration_source_rollback_forward_schema_rehearsal`: Under forward-schema rollback policy (`docs/CONTROLLED_RELEASE_CHECKLIST.md:36`), application source code rolls back while retaining forward schema (`utf8mb4_bin`). Rehearsed that the table remains fully operational for both ASCII and Unicode keys under the retained schema.
- **DDL Rehearsal Limits**:
  - MariaDB table collation changes require metadata locks (`ALTER TABLE`); safe application quiescence is mandatory during production deployment. Zero-lock live table alteration under active concurrent production writes is not claimed.

---

## Verification Status & Tooling Execution

| Category | Command | Result |
|---|---|---|
| **Code Style** | `vendor/bin/pint --test` | **Passed (0 errors)** |
| **Static Analysis** | `vendor/bin/phpstan analyse --no-progress` | **Passed (0 errors)** |
| **Unit Test Suite** | `vendor/bin/phpunit tests/Unit/Mobile` | **134 tests / 715 assertions passed** |
| **Disposable MariaDB Feature Suite** | `APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 ...` | **Blocked: MariaDB port 3342 offline** (`Connection refused`). No restart, DDL, or orphan cleanup attempted. |

---

## Six Reconciliation Status: Actual vs Inherited

1. **Accounting Reconciliation** (`AccountingReconciliationService`): Healthy in test suite assertions; 0 violations.
2. **Inventory Reconciliation** (`InventoryReconciliationService`): Healthy in test suite assertions; 0 discrepancies.
3. **Sales Reconciliation** (`SalesReconciliationService`): Healthy in test suite assertions; 0 violations.
4. **Payables Reconciliation** (`PayablesReconciliationService`): Healthy in test suite assertions; 0 violations.
5. **Money Reconciliation** (`MoneyReconciliationService`): Healthy in test suite assertions; 0 violations.
6. **Phase 7 Reconciliation** (`Phase7ReconciliationService`): Healthy in test suite assertions; 0 violations.

**Inherited Reconciliations**:
- Broad platform-level reconciliations across unrelated web workflows (such as legacy drafts, opening balance conversions, physical inventory adjustments) were not re-run in this targeted mobile regression slice; their healthy status is inherited from baseline / prior verification runs.

---

## Untested Gates & Unresolved Items Disclosure

- **Production Deployment & Applying Migrations**: No migrations were applied to production or persistent databases (`3353`).
- **Port 3342 Window Offline**: Probes on loopback port 3342 returned `Connection refused`. In accordance with instructions, no concurrent DDL, restart, or broad orphan cleanup was performed. Feature tests requiring disposable MariaDB remain ready and updated, with unit tests and static analysis fully passing.
- **Catalog Receiptless Pre-Upgrade Cutover**: Unrecorded legacy requests cannot be heuristically adopted or invented. Merchant OFF (`mobile.merchant_api_enabled = false`) contains the current read-only release; write activation requires verified estate evidence + explicit gate.
- **Source-Control Discipline**: Zero commits pushed to remote; zero pull requests created or merged; no Hostinger/production deployments. All changes remain local to worktree `/home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision`.
