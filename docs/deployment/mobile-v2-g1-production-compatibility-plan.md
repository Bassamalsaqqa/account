# Mobile V2 / G1 Production Deployment Compatibility Plan

**Authoritative Baseline Diff**: `8cd4322` &rarr; `HEAD` (`7b8ac0167866d6b6bb089dd697d37517f5fb8fa3`)  
**Deployment Target Origin**: `https://account.palsync.net` (Hostinger CloudLinux Alt-PHP 8.4, MariaDB 10.4)  
**Authoritative Operational Standard**: [`docs/CONTROLLED_RELEASE_CHECKLIST.md`](../CONTROLLED_RELEASE_CHECKLIST.md) and [`docs/HOSTINGER_DEPLOYMENT_GUIDE.md`](../HOSTINGER_DEPLOYMENT_GUIDE.md)  
**Security Posture**: Default-Deny, Kill-Switch Protected, Read-Opt-in Guarded, Zero Production Keys Rotated

---

## 1. Executive Summary & Diff Scope

The delta between the deployed baseline commit (`8cd4322`) and current `HEAD` encompasses:
- **81 application files in `app/`, `config/`, and `routes/`** (48 added, 29 modified, 1 deleted in `app/`; 1 added in `config/`; 2 added in `routes/`).
- **9 template/localization files in `resources/`** (6 modified, 3 deleted).
- **3 database migrations** in `database/migrations/`:
  - `2026_11_11_000001_create_mobile_v1_tables.php` (additive: device, token, challenge, and mobile setting tables).
  - `2026_11_11_000002_harden_mobile_ownership_and_remove_unapproved_catalog_fields.php` (**DESTRUCTIVE SCHEMA ALTERATION**: drops `products.tax_rate_id`, `products.barcode`, foreign key and index; clears `company_mobile_settings` offline policy defaults).
  - `2026_11_12_000001_create_mobile_event_receipts_table.php` (additive: idempotent receipts store).
- **Strict Default-Deny Financial Isolation**: In production, the immediate online cash sale slice is hard-disabled via `MOBILE_V2_CASH_SLICE_ENABLED=false` and `FinancialExecutionAdapterInterface` bound to `DenyFinancialExecutionAdapter` in [`AppServiceProvider`](../../app/Providers/AppServiceProvider.php).
- **Exact Routing Boundary**: When `MOBILE_V2_CASH_SLICE_ENABLED=false`, V2 routes are omitted from routing registration, resulting in strict **HTTP 404 NOT_FOUND** fallback responses at the boundary—never HTTP 503 or 403.
- **Backward-Compatible Metadata (G1)**: The mobile bootstrap endpoint preserves the exact historical 12-key metadata contract by default, with opt-in support for `currency_minor_units` (G1 currency scales: 0 for zero-decimal currencies like JPY, 2 for standard currencies like ILS/USD, 3 for 3-decimal currencies like JOD/KWD/BHD/OMR).
- **Zero On-Host Builds**: Pre-built static assets are deployed through the reviewed `bin/deploy.sh` allowlist. No on-host `npm run build` or blind overlays.

---

## 2. Complete Git Pathlist & Classification (`8cd4322..HEAD`)

### 2.1 Database Migrations (`database/migrations/`)
| Status | File Path | Scope & Impact |
| :--- | :--- | :--- |
| **Added** | `database/migrations/2026_11_11_000001_create_mobile_v1_tables.php` | Additive: creates `mobile_devices`, `mobile_access_tokens`, `mobile_auth_challenges`, `company_mobile_settings`. |
| **Added** | `database/migrations/2026_11_11_000002_harden_mobile_ownership_and_remove_unapproved_catalog_fields.php` | **DESTRUCTIVE**: drops `products.tax_rate_id`, `products.barcode`, FK to `tax_rates`, and composite index `['company_id', 'barcode']`. Resets `company_mobile_settings` offline policy defaults to false/null. Requires pre-migration data export. |
| **Added** | `database/migrations/2026_11_12_000001_create_mobile_event_receipts_table.php` | Additive: creates `mobile_event_receipts` table for idempotent sale receipts and audit trails. |

### 2.2 Core Application (`app/`)
#### Added Files (48)
- **Canonical Action**:
  - `app/Actions/Sales/ExecuteImmediateOnlineCashSaleAction.php` (Atomic 3-leg cash sale action: draft, posting + stock + GL, payment).
- **HTTP Controllers & Middleware**:
  - `app/Http/Controllers/Api/Mobile/V1/AuthController.php`
  - `app/Http/Controllers/Api/Mobile/V1/BootstrapController.php`
  - `app/Http/Controllers/Api/Mobile/V1/CustomerController.php`
  - `app/Http/Controllers/Api/Mobile/V1/DeviceController.php`
  - `app/Http/Controllers/Api/Mobile/V1/ProductController.php`
  - `app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php`
  - `app/Http/Controllers/Auth/LockedTwoFactorSessionController.php`
  - `app/Http/Middleware/MobileApiBoundary.php`
  - `app/Http/Middleware/MobileAuthenticate.php`
  - `app/Http/Middleware/MobileV2ApiBoundary.php`
  - `app/Http/Middleware/MobileV2Authenticate.php`
- **Models**:
  - `app/Models/CompanyMobileSetting.php`
  - `app/Models/MobileAccessToken.php`
  - `app/Models/MobileAuthChallenge.php`
  - `app/Models/MobileDevice.php`
  - `app/Models/MobileEventReceipt.php`
- **Providers & Services**:
  - `app/Providers/MobileApiServiceProvider.php` (registers mobile config and routes).
  - `app/Services/Money/CurrencyMinorUnitsResolver.php` (authoritative currency scale resolver: 0..6 decimals).
- **Mobile Support & RFC-8785 Contract Parsing**:
  - `app/Support/Mobile/CatalogSnapshot.php`
  - `app/Support/Mobile/Contract/CanonicalJsonSerializer.php`
  - `app/Support/Mobile/Contract/ContractConstants.php`
  - `app/Support/Mobile/Contract/DecimalLexeme.php`
  - `app/Support/Mobile/Contract/Exceptions/CanonicalSerializationException.php`
  - `app/Support/Mobile/Contract/Revision3/Exceptions/IntentProfileValidationException.php`
  - `app/Support/Mobile/Contract/Revision3/OnlineSaleIntentProfile.php`
  - `app/Support/Mobile/Contract/Revision3/OnlineSaleWireResponseProjection.php`
  - `app/Support/Mobile/Contract/Revision3/PaymentSettlementValidator.php`
  - `app/Support/Mobile/Contract/StrictJsonArray.php`
  - `app/Support/Mobile/Contract/StrictJsonBoolean.php`
  - `app/Support/Mobile/Contract/StrictJsonElement.php`
  - `app/Support/Mobile/Contract/StrictJsonNull.php`
  - `app/Support/Mobile/Contract/StrictJsonNumber.php`
  - `app/Support/Mobile/Contract/StrictJsonObject.php`
  - `app/Support/Mobile/Contract/StrictJsonParser.php`
  - `app/Support/Mobile/Contract/StrictJsonString.php`
  - `app/Support/Mobile/WireResponse.php`
- **Receipt & Execution Capability Architecture**:
  - `app/Support/Mobile/Receipt/CompositeFinancialExecutionScope.php`
  - `app/Support/Mobile/Receipt/DenyFinancialExecutionAdapter.php`
  - `app/Support/Mobile/Receipt/Exceptions/FinancialExecutionDeniedException.php`
  - `app/Support/Mobile/Receipt/Exceptions/IllegalStateException.php`
  - `app/Support/Mobile/Receipt/Exceptions/MobileEventAuthenticationException.php`
  - `app/Support/Mobile/Receipt/Exceptions/MobileEventDuplicatePayloadException.php`
  - `app/Support/Mobile/Receipt/Exceptions/MobileEventForeignScopeException.php`
  - `app/Support/Mobile/Receipt/Exceptions/SecurityException.php`
  - `app/Support/Mobile/Receipt/FinancialExecutionAdapterInterface.php`
  - `app/Support/Mobile/Receipt/MobileEventReceiptManager.php`
  - `app/Support/Mobile/Receipt/NativeFinancialExecutionCapability.php`

#### Modified Files (29)
- `app/Actions/Sales/ApplyCustomerPaymentCreditAction.php`
- `app/Actions/Sales/CreateQuotationAction.php`
- `app/Actions/Sales/CreateSalesInvoiceDraftAction.php`
- `app/Actions/Sales/CreateSalesReturnDraftAction.php`
- `app/Actions/Sales/PostCustomerPaymentAction.php`
- `app/Actions/Sales/PostSalesInvoiceAction.php`
- `app/Actions/Sales/UpdateQuotationAction.php`
- `app/Actions/Sales/UpdateSalesInvoiceDraftAction.php`
- `app/Application/Commands/ImmediateSaleCommands.php`
- `app/Application/Commands/SalesInvoiceCommands.php`
- `app/Application/Reporting/Presentation/ReportFilterOptions.php`
- `app/Livewire/Pages/Purchasing/PurchaseDetail.php`
- `app/Livewire/Pages/Purchasing/PurchaseIndex.php`
- `app/Livewire/Pages/Reporting/ReportView.php`
- `app/Livewire/Pages/Sales/CustomerStatementView.php`
- `app/Livewire/Pages/Sales/InvoiceForm.php`
- `app/Livewire/Pages/Sales/QuotationForm.php`
- `app/Models/Company.php`
- `app/Models/Unit.php`
- `app/Providers/AppServiceProvider.php` (binds `FinancialExecutionAdapterInterface` &rarr; `DenyFinancialExecutionAdapter`).
- `app/Providers/FortifyServiceProvider.php`
- `app/Services/Money/MoneyValues.php`
- `app/Services/Sales/DraftInvoiceIntegrity.php`
- `app/Services/Sales/ReceiptPreview.php`
- `app/Services/Sales/SalesCorrectionAudit.php`
- `app/Services/Sales/SalesDocumentRules.php`
- `app/Services/Sales/SalesProductSelection.php`
- `app/Services/Sales/SalesReturnAmounts.php`
- `app/Support/Tenancy/CompanyContext.php`

#### Deleted File (1)
- `app/Actions/Purchasing/DeletePurchaseDraftAction.php` (**DELETED**: removed unapproved purchasing draft deletion action).

### 2.3 Configuration & Routes (`config/`, `routes/`)
| Status | File Path | Description |
| :--- | :--- | :--- |
| **Added** | `config/mobile.php` | Configuration schema for Mobile V1 and V2 feature flags (`v2_cash_slice_enabled`). |
| **Added** | `routes/mobile-v1.php` | Routes under `/api/mobile/v1/*` (auth, bootstrap, products, customers, devices). |
| **Added** | `routes/mobile-v2.php` | Routes under `/api/mobile/v2/companies/{companyId}/*` (gated by `v2_cash_slice_enabled`). |

### 2.4 Resources & Presentation (`resources/`)
| Status | File Path | Scope |
| :--- | :--- | :--- |
| **Deleted** | `resources/lang/ar/purchase_draft_delete.php` | Removed deleted purchase action translations. |
| **Deleted** | `resources/lang/en/purchase_draft_delete.php` | Removed deleted purchase action translations. |
| **Deleted** | `resources/views/components/report-party-selector.blade.php` | Removed deprecated report component. |
| **Modified** | `resources/lang/ar/reports.php`, `resources/lang/en/reports.php` | Translation updates for reporting. |
| **Modified** | `resources/views/livewire/pages/purchasing/purchase-detail.blade.php` | Purchasing UI adjustments. |
| **Modified** | `resources/views/livewire/pages/purchasing/purchase-index.blade.php` | Removed delete draft button. |
| **Modified** | `resources/views/livewire/pages/reporting/report.blade.php` | Report view adjustments. |
| **Modified** | `resources/views/livewire/pages/sales/customer-statement-view.blade.php` | Customer statement presentation. |
| **Modified** | `resources/views/livewire/partials/purchase-row-actions.blade.php` | Purchase row actions update. |

---

## 3. Pre-Migration Destructive Column Preservation Plan

Migration `2026_11_11_000002_harden_mobile_ownership_and_remove_unapproved_catalog_fields.php` executes:
```php
Schema::table('products', function (Blueprint $table): void {
    $table->dropIndex(['company_id', 'barcode']);
    $table->dropForeign(['tax_rate_id']);
    $table->dropColumn(['tax_rate_id', 'barcode']);
});
```

### 3.1 Destructive Risk Analysis
1. **Irreversible Data Loss**: Running `migrate:rollback` restores the empty columns `tax_rate_id` and `barcode`, but **permanently loses the cell contents**.
2. **Mandatory Pre-Migration Export**: Before executing `php artisan migrate`, the operator MUST export all existing `products` rows containing non-null `tax_rate_id` or `barcode` to a secure, timestamped, off-host CSV/SQL artifact:
   ```bash
   # Export products table columns prior to drop
   mysql -h 127.0.0.1 -u <actual_db_user> -p <actual_db_name> -e \
     "SELECT id, company_id, code, name_en, name_ar, tax_rate_id, barcode FROM products WHERE tax_rate_id IS NOT NULL OR barcode IS NOT NULL;" \
     > /path/to/protected/backups/products_destructive_columns_backup_$(date +%Y%m%d%H%M%S).tsv
   ```
3. **Audit Verification**: Verify the TSV file size, line count, and checksum. File permissions must be `0600` inside a `0700` directory.
4. **Offline Policy Reset**: Migration 000002 explicitly updates `company_mobile_settings` offline policy columns to `false` and `NULL`. This is intentional (unapproved policies cannot be presumed valid), but current settings must be recorded in the pre-deployment database dump.

---

## 4. Production Environment Isolation & Default-Deny Architecture

### 4.1 Strict Default-Deny Environment Flags
In production (`.env` in private application root `/home/u556956644/domains/palsync.net/accounting/.env`):
```ini
# Mobile V1 Master API Switch (read-only catalog & bootstrap enabled)
MOBILE_API_ENABLED=true

# Mobile V2 Master API Switch (defaults to false)
MOBILE_V2_API_ENABLED=false

# Immediate Online Cash Sale Slice Switch (CRITICAL: MUST REMAIN FALSE)
MOBILE_V2_CASH_SLICE_ENABLED=false
```

### 4.2 Exact Routing Boundary (HTTP 404)
In [`routes/mobile-v2.php`](../../routes/mobile-v2.php):
```php
if ((bool) config('mobile.v2_cash_slice_enabled', false)) {
    Route::prefix('companies/{companyId}')
        ->group(function (): void {
            Route::post('/sales/online-cash', [OnlineCashSaleController::class, 'store'])
                ->middleware('mobile.v2.auth')
                ->name('mobile.v2.sales.online-cash');

            Route::get('/offline-events/{clientEventId}/status', [OnlineCashSaleController::class, 'status'])
                ->middleware('mobile.v2.auth')
                ->name('mobile.v2.offline-events.status');
        });
}

Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], '{fallbackPlaceholder?}', function () {
    return MobileV2ApiBoundary::buildErrorResponse(
        code: 'NOT_FOUND',
        message: 'The requested resource was not found.',
        status: 404
    );
})->where('fallbackPlaceholder', '.*')->fallback();
```
When `MOBILE_V2_CASH_SLICE_ENABLED=false`, the routes are not registered. All requests to:
- `POST https://account.palsync.net/api/mobile/v2/companies/{companyId}/sales/online-cash`
- `GET https://account.palsync.net/api/mobile/v2/companies/{companyId}/offline-events/{clientEventId}/status`

fall through to the boundary fallback, returning strictly **HTTP 404 NOT_FOUND** (`code: NOT_FOUND`), NOT HTTP 503 or 403.

### 4.3 Container Service Provider Binding
In [`app/Providers/AppServiceProvider.php`](../../app/Providers/AppServiceProvider.php#L62-L65):
```php
$this->app->bind(
    FinancialExecutionAdapterInterface::class,
    DenyFinancialExecutionAdapter::class
);
```
`DenyFinancialExecutionAdapter` is permanently bound in `AppServiceProvider`. Even if routing were accidentally enabled, any attempt to acquire a financial execution capability unconditionally throws `FinancialExecutionDeniedException`. Zero financial rows can be created.

---

## 5. G1 Metadata Opt-in & Currency Scale Verification (0, 2, 3)

The mobile bootstrap endpoint ([`BootstrapController.php`](../../app/Http/Controllers/Api/Mobile/V1/BootstrapController.php)) serves the baseline workspace metadata.

### 5.1 Default Historical Payload (Exact 12 Keys)
When called without query parameters:
`GET https://account.palsync.net/api/mobile/v1/companies/{companyId}/bootstrap`

The response payload contains **strictly 12 keys**:
1. `company_id` (ULID string)
2. `company_name` (string)
3. `base_currency` (ISO 4217 code)
4. `walk_in_customer_id` (ULID string)
5. `staff` (object: `id`, `name`, `role`, `permissions`)
6. `device_lease` (literal `null`)
7. `default_warehouse` (object: `id`, `code`, `name`)
8. `approved_cash_account` (object: `id`, `code`, `name`, `currency`)
9. `units` (array of active measurement units)
10. `taxes` (array of active tax rates with `is_default`)
11. `offline_policy` (object: `{"enabled": false, "availability": "unavailable"}`)
12. `catalog_snapshot_version` (string hash)

`currency_minor_units` is strictly omitted from the default payload to maintain 100% backward compatibility with legacy clients.

### 5.2 Opt-In Projection: Currency Minor Units (`?include=currency_minor_units`)
When the client explicitly provides `?include=currency_minor_units`:
`GET https://account.palsync.net/api/mobile/v1/companies/{companyId}/bootstrap?include=currency_minor_units`

The response payload contains **exactly 13 keys**: the 12 base keys plus `currency_minor_units` resolved by [`CurrencyMinorUnitsResolver`](../../app/Services/Money/CurrencyMinorUnitsResolver.php).

#### Currency Scale Modes:
- **Mode 0 (Zero-Decimal Currencies, e.g. JPY)**:
  `currency_minor_units: 0`
- **Mode 2 (Standard Currencies, e.g. ILS, USD, EUR)**:
  `currency_minor_units: 2`
- **Mode 3 (Three-Decimal Currencies, e.g. JOD, KWD, BHD, OMR)**:
  `currency_minor_units: 3`

### 5.3 Rejection of Invalid Parameters
Any other `?include=` parameter value (e.g. `?include=unknown`, array syntax `?include[]=...`) is rejected immediately at the boundary with **HTTP 422 Unprocessable Entity**:
```json
{
  "code": "VALIDATION_FAILED",
  "message": "Invalid include parameter. Supported projection: currency_minor_units."
}
```

---

## 6. Hostinger CloudLinux Alt-PHP 8.4 & `.htaccess` Custody

Per [`docs/HOSTINGER_DEPLOYMENT_GUIDE.md`](../HOSTINGER_DEPLOYMENT_GUIDE.md):
- **Hosting Environment**: Hostinger Shared/CloudLinux.
- **Alt-PHP 8.4 Binary**: `/opt/alt/php84/usr/bin/php`.
- **Private Application Root**: `/home/u556956644/domains/palsync.net/accounting` (mode `0755`, `.env` mode `0600`).
- **Public Web Root**: `/home/u556956644/domains/palsync.net/public_html/account`.
- **Live `.htaccess` Custody**: The live `.htaccess` in `public_html/account/.htaccess` contains CloudLinux Alt-PHP 8.4 handler directives:
  ```apache
  <IfModule mime_module>
    AddHandler application/x-httpd-alt-php84 .php .php8 .phtml
  </IfModule>
  ```
  `bin/deploy.sh` verifies live `.htaccess` into restricted private custody **before Composer install, migrations, caches, or asset replacement**. Existing configuration remains byte-for-byte unchanged. Symlinked public roots and web configuration are strictly rejected.

---

## 7. Authoritative Rollout Runbook (Per `CONTROLLED_RELEASE_CHECKLIST.md`)

> [!CAUTION]
> The worker is strictly prohibited from executing live Hostinger deployments, SSH connections, production database commands, or signing key rotations. The runbook below is the authoritative procedure for Root / Owner execution.

### Phase 1: Pre-Deployment Coherent Recovery & Destructive Column Export
1. Establish a coherent off-host recovery point:
   - Capture full MySQL dump with routines and triggers:
     ```bash
     mysqldump -h 127.0.0.1 -u <actual_user> -p --single-transaction --quick --routines --triggers <actual_db> > /path/to/backups/accounting_coherent_$(date +%Y%m%d%H%M%S).sql
     ```
   - Snapshot code, build, public media, private files, configuration dependencies, and encryption-key history into a timestamped archive with completeness manifest and ciphertext checksums.
2. Export destructive columns (`products.tax_rate_id` and `products.barcode`) to off-host storage (TSV export per Section 3).
3. Verify backup completeness and restore qualification before initiating mutations.

### Phase 2: Enter Maintenance Under CURRENTLY DEPLOYED Code
1. Enter maintenance using the **currently deployed code** before any checkout or file sync:
   ```bash
   /opt/alt/php84/usr/bin/php artisan down --render="errors::503"
   ```
2. Verify actual unauthenticated HTTP 503 response and maintenance marker on disk.

### Phase 3: Synchronize Code & Execute Reviewed `bin/deploy.sh`
1. Privately stage the exact approved replacement revision (`7b8ac0167866d6b6bb089dd697d37517f5fb8fa3`).
2. Run the deployment script under verified PHP 8.4:
   ```bash
   PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh --run-migrations
   ```
   `bin/deploy.sh`:
   - Preserves pre-existing maintenance mode.
   - Takes custody of live `.htaccess` into restricted private custody.
   - Runs `composer install --no-dev --prefer-dist --optimize-autoloader`.
   - Executes migrations (`2026_11_11_000001`, `2026_11_11_000002`, `2026_11_12_000001`).
   - Replaces public assets from the reviewed release bundle using its fixed allowlist (`index.php`, `manifest.webmanifest`, `sw.js`, `offline.html`, `build/`, icons).
   - Optimizes caches (`config:cache`, `route:cache`, `view:cache`).
   - **LEAVES MAINTENANCE HELD**.

### Phase 4: Verification While Maintenance Remains HELD
1. **Source & Fingerprint Integrity**:
   - Verify `git rev-parse HEAD` matches `7b8ac0167866d6b6bb089dd697d37517f5fb8fa3`.
   - Verify `.htaccess` checksum is identical to pre-deploy custody.
   - Verify file permissions (0755 directories, 0644 files, 0600 `.env`).
2. **Reconciliation Suite Execution**:
   - Execute the 6 authoritative reconciliation services for each active tenant company:
     ```bash
     /opt/alt/php84/usr/bin/php artisan accounting:reconcile
     /opt/alt/php84/usr/bin/php artisan inventory:reconcile
     /opt/alt/php84/usr/bin/php artisan sales:reconcile
     /opt/alt/php84/usr/bin/php artisan money:reconcile
     /opt/alt/php84/usr/bin/php artisan phase7:reconcile
     # PayablesReconciliationService verified via read-only harness
     ```
   - Assert all 6 report healthy with 0 issues.
3. **Smoke Verification (Via Maintenance Bypass)**:
   - Authenticated Arabic and English web workflows (Dashboard, Settings, Invoices).
   - Verify `GET /manifest.webmanifest` returns `Content-Type: application/manifest+json`.
   - Test Mobile V1 Bootstrap:
     - `GET /api/mobile/v1/companies/{companyId}/bootstrap` &rarr; 200 OK, exact 12 keys.
     - `GET /api/mobile/v1/companies/{companyId}/bootstrap?include=currency_minor_units` &rarr; 200 OK, exact 13 keys with correct scale.
   - Test Mobile V2 Online Cash Boundary:
     - `POST /api/mobile/v2/companies/{companyId}/sales/online-cash` &rarr; **404 NOT_FOUND**.
     - `GET /api/mobile/v2/companies/{companyId}/offline-events/{clientEventId}/status` &rarr; **404 NOT_FOUND**.

### Phase 5: Explicit Reopening Gate
1. Only after all verification gates pass and explicit Owner authorization is granted:
   ```bash
   /opt/alt/php84/usr/bin/php artisan up
   ```
2. Verify live unauthenticated access, active login, and normal web accounting operation.

---

## 8. Rollback & Forward-Data Preservation Protocol

Per `CONTROLLED_RELEASE_CHECKLIST.md` Section 36:
> *"Verify safe rollback to the known-good source/build with retained forward schema and business data. Do not restore an old SQL snapshot over subsequent production activity or reverse applied migrations for convenience."*

### 8.1 Prohibited Rollback Operations
- **NEVER** run `php artisan migrate:rollback --step=3`. Reversing migration 000002 cannot restore dropped product tax rates and barcodes, and creates schema conflicts with forward-accumulated transactions.
- **NEVER** blindly restore an old SQL dump over live production activity, which destroys subsequent financial entries, journal entries, and customer balances.
- **NEVER** blindly extract an old application tarball over existing files without verifying permissions and custody.

### 8.2 Safe Contingency Procedure
If deployment fails during the maintenance window:
1. **Hold Maintenance**: Application remains in maintenance mode (`artisan down`).
2. **Preserve Forward Schema**: The newly added columns and tables (`mobile_devices`, `mobile_event_receipts`, etc.) do not disrupt baseline code when Mobile V2 routing remains disabled.
3. **Code Reversion**: If a code regression requires returning to baseline commit `8cd4322`, check out `8cd4322` in the application directory while retaining the database schema intact:
   ```bash
   git checkout 8cd4322
   PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh --skip-migrate
   ```
4. **Re-verify Reconciliations**: Re-run the 6 domain reconcilers.
5. **Re-open Only Under Owner Authorization**: Re-open via `artisan up` only once stability is certified.
