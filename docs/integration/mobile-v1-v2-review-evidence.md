# Mobile V1/V2 Review Resolution & Verification Evidence

## Scope & Provenance

- **Worktree**: `/home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision`
- **Branch**: `t3/mobile-v1-read-release-20261117`
- **Baseline Commit**: `82c4a23aaae3133a0359acad36638556ba292c73`
- **Authority / Objective**: Resolve and verify all 7 review blockers identified in Huda's independent review report (`ce5497f7-3bc7-496a-bc12-d252ab910fa7`) and implement boundary-correct invariants.
- **Tracked Code & Test Files (11)**:
  1. `app/Actions/Sales/ExecuteNativeSalesReturnAction.php`
  2. `app/Actions/Sales/PostSalesReturnAction.php`
  3. `app/Http/Controllers/Api/Mobile/V1/CustomerController.php`
  4. `app/Http/Controllers/Api/Mobile/V1/PurchaseController.php`
  5. `app/Http/Controllers/Api/Mobile/V1/SalesReturnController.php`
  6. `app/Http/Controllers/Api/Mobile/V1/SupplierController.php`
  7. `app/Services/Purchasing/PurchasePayableAsOf.php`
  8. `app/Services/Sales/CustomerCatalogService.php`
  9. `database/migrations/2026_11_21_100000_update_native_command_receipts_idempotency_key_collation.php`
  10. `tests/Feature/Mobile/V1/PartyCatalogIdempotencyAndContractTest.php`
  11. `tests/Feature/Mobile/V2/SalesReturnReceiptRegressionTest.php`

---

## Resolution of Review Blockers (ce5497f7)

### 1. Party Cutover & Legacy Adoption Invariant
- Removed fragile actor/name/time adoption heuristics from `CustomerController.php` and `SupplierController.php`.
- Established explicit catalog cutover rule: unproven command identities without authoritative `NativeCommandReceipt` records or explicit cutover mapping are rejected. Legitimate new party intents with duplicate or overlapping names are never suppressed or mutated.
- Handled English-only party creation without customer code cleanly, avoiding undefined `name_ar` errors.
- Prevented receiptless baseline updates from overwriting newer edits with unknown legacy patches.

### 2. Payment FX, Midnight Retries & Frozen Command Identity
- In `CustomerController.php` and `SupplierController.php`, explicit FX 1 vs 2 on foreign payments conflict (409) and never equate.
- Fixed receipt lookup property: reads `$existingReceipt->payload` (not `$request_payload`).
- Retries across midnight recover the frozen business date stored in the receipt rather than generating a new date and payload hash.
- Fresh foreign currency writes without an explicit `exchange_rate` strictly reject with 422 ("no new foreign write implicit 1").
- Bidirectional historical payload/hash compatibility reconstructed from pinned historical handlers (commit `078f426`), covering both customer and supplier controllers and both base hash directions (with and without explicit `1.0000000000` exchange rate).

### 3. Signed Tax Reversal & Genuine Partial JPY Fixture
- In `PostSalesReturnAction.php`, derived transaction currency posting direction strictly from the original signed tax (`isPositive` => Debit, `isNegative` => Credit), never from rounded base residuals.
- Base residual differences between group base and computed base are represented separately as a canonical base-only adjustment line (`"Sales Return {$returnNumber} Tax Reversal Base Adjustment"`).
- Verified full native `preview` -> `post` API flow with genuine partial JPY fixtures and 4 positive tax groups, ensuring balanced debits/credits, retention of original currency components, and passing all 6 domain reconciliations.

### 4. Catalog 598c Hash Compatibility, Supplier Fields & Credit Limit Validation
- In `CustomerController.php`, recovered 598c unscaled alias-expanded sorted payload hash for exact-body replay compatibility.
- In `SupplierController.php`, historical supplier receipts carrying legacy `company_name` proceed to receipt replay before fresh allowlist rejection, without introducing unsupported new writes. Excluded unapplied `address_line_1_ar`/`address_line_1_en` keys from allowlist and hashing; retained `postal_code`.
- In `CustomerCatalogService.php` and `CustomerController.php`, enforce strict validation before numeric coercion: rejects booleans and floats, enforces scale <= 6 (e.g. `0.0000001` returns 422 `ValidationException`, preventing 500 `MoneyAmount` errors), and preserves exact 6-decimal storage.

### 5. Fresh Return History Permission Check & Redaction
- In `ExecuteNativeSalesReturnAction.php`, moved the fresh-only return history check (`sales.return.view`) inside the locked company transaction in the executor, preventing concurrent commands from racing past un-locked prechecks.
- In `SalesReturnController.php`, corrected the `preview` permission gate so denial is reachable (returns 403 on invoices with prior returns when the actor lacks `sales.return.view`).
- Removed disclosure of prior return public IDs from unrecorded legacy return conflict exceptions.

### 6. Purchase Allocatable Date Cutoff & Zero-Cost Qualification
- In `PurchaseController.php`, changed the `allocatable=true` filter from application server date to company timezone business date (`$companyToday`).
- In `PurchasePayableAsOf.php`, added zero-checks for header totals and executed `LandedCostIntegrity::validate` and `PurchaseDocumentProvenanceGuard::validatePosted`.
- In `PartyCatalogIdempotencyAndContractTest.php`, re-bound `CompanyContext` before calling `components()`, ensuring failures reflect authentic provenance violations, and verified the settled cash future/reversal status matrix.

### 7. Migration Bounded SQL & Forward-Schema Rollback Policy
- In `2026_11_21_100000_update_native_command_receipts_idempotency_key_collation.php`, replaced in-memory row iteration with bounded SQL (`WHERE LENGTH(idempotency_key) != CHAR_LENGTH(idempotency_key)`).
- Under the forward-schema rollback policy (`docs/CONTROLLED_RELEASE_CHECKLIST.md:36`), down migrations refuse downgrade with a `RuntimeException` when non-ASCII keys exist.

---

## Actual Test Commands & Verified Counts

| Category | Command | Result |
|---|---|---|
| **Code Style** | `vendor/bin/pint --test` | **Passed (0 errors)** across all 11 files |
| **Static Analysis** | `vendor/bin/phpstan analyse --no-progress` | **Passed (0 errors)** across all 11 files |
| **Unit Test Suite** | `vendor/bin/phpunit tests/Unit/Mobile` | **134 tests / 715 assertions passed** |
| **Disposable MariaDB Feature Suite** | `APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- tests/Feature/Mobile/V1/PartyCatalogIdempotencyAndContractTest.php tests/Feature/Mobile/V2/SalesReturnReceiptRegressionTest.php` | **42 tests / 491 assertions passed** (Owned schema `accounting_p8_tmp_28979d46cf86` cleaned) |
| **Total Verified** | Unit + Disposable MariaDB Suites | **176 tests / 1,206 assertions passed** |

Breakdown of MariaDB suite:
- `PartyCatalogIdempotencyAndContractTest`: 18 tests, 222 assertions passed.
- `SalesReturnReceiptRegressionTest`: 24 tests, 269 assertions passed.

---

## Six Reconciliation Status: Actual vs Inherited

1. **Accounting Reconciliation** (`AccountingReconciliationService`):
   - **Actual**: Explicitly evaluated and asserted 10 times in `SalesReturnReceiptRegressionTest` (lines 363, 502, 542, 698, 723, 842, 924, 1532, 1588) on disposable MariaDB. Healthy (`$acct->isHealthy === true`, 0 violations).
2. **Inventory Reconciliation** (`InventoryReconciliationService`):
   - **Actual**: Explicitly evaluated and asserted 10 times in `SalesReturnReceiptRegressionTest`. Healthy (`$inv->isHealthy === true`, 0 discrepancies).
3. **Sales Reconciliation** (`SalesReconciliationService`):
   - **Actual**: Explicitly evaluated and asserted 10 times in `SalesReturnReceiptRegressionTest`. Healthy (`$sales->isHealthy === true`, 0 violations).
4. **Payables Reconciliation** (`PayablesReconciliationService`):
   - **Actual**: Explicitly evaluated and asserted 10 times in `SalesReturnReceiptRegressionTest`. Healthy (`$pay->isHealthy === true`, 0 violations).
5. **Money Reconciliation** (`MoneyReconciliationService`):
   - **Actual**: Explicitly evaluated and asserted 10 times in `SalesReturnReceiptRegressionTest`. Healthy (`$money->isHealthy === true`, 0 violations).
6. **Phase 7 Reconciliation** (`Phase7ReconciliationService`):
   - **Actual**: Explicitly evaluated and asserted 10 times in `SalesReturnReceiptRegressionTest`. Healthy (`$p7->isHealthy === true`, 0 violations).

**Inherited Reconciliations**:
- Broad platform-level reconciliations across unrelated web workflows (such as legacy drafts, opening balance conversions, physical inventory adjustments) were not re-run in this targeted mobile regression slice; their healthy status is inherited from baseline `82c4a23` / prior verification runs.

---

## Migration Preservation & DDL Rehearsal Limits

- **Collation Migration**: `2026_11_21_100000_update_native_command_receipts_idempotency_key_collation.php` updates `idempotency_key` to `utf8mb4_bin`.
- **Downgrade Refusal**: Verified via `test_migration_down_refuses_non_ascii_unicode_downgrade`. When non-ASCII keys exist, `down()` raises `RuntimeException` to preserve Unicode idempotency keys under the forward-schema rollback policy.
- **DDL Rehearsal Limits**:
  - Rehearsals ran exclusively against disposable MariaDB schemas on numeric loopback port 3342.
  - MariaDB table collation changes require metadata locks (`ALTER TABLE`); safe application quiescence is mandatory during production deployment. Zero-lock live table alteration under active concurrent production writes is not claimed.
  - Pure ASCII down-migration (when no Unicode keys exist) was not separately exercised in this pass.

---

## Catalog Cutover Rule

- Fragile actor/name/timestamp heuristics eliminated.
- **Rule**: Without an authoritative command identity (an existing `NativeCommandReceipt` record with matching request hash or explicit cutover record), an incoming party creation request is treated strictly as a fresh command intent.
- Legitimate new customer/supplier creations with identical or overlapping names are never suppressed or mutated; they generate genuine distinct entities.
- Receiptless baseline updates do not re-apply unknown old patches over newer edits.
- Replay compatibility is strictly bound to cryptographic request hash recovery of frozen historical receipts (including the 598c sorted unscaled JSON payload hash), avoiding ambiguous fallback heuristics.

---

## Untested Gates & Unresolved Items Disclosure

- **Production Deployment & Applying Migrations**: No migrations were applied to production or persistent databases (`3353`).
- **Live Multi-Node Concurrency Lock Rehearsal**: No multi-node concurrent DDL rehearsal was executed against live staging/production MariaDB.
- **Opening Review Coordination**: MariaDB port 3342 was used cleanly within ephemeral schemas; the active opening review runner (9295db2a) maintains its own separate feature scope.
- **Source-Control Discipline**: Zero commits pushed to remote; zero pull requests created or merged; no Hostinger/production deployments. All changes remain local to worktree `/home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision`.
