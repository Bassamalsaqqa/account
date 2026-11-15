# Mobile V2 / G1 Production Deployment Compatibility Plan

**Authoritative Baseline Diff**: `8cd4322` &rarr; `HEAD` (`f120e8a662321ddb527afb90a3ba5575a8be904a`)  
**Deployment Target**: Hostinger Shared Hosting (`account.palsync.net`, PHP 8.4, MariaDB 10.4)  
**Security Posture**: Default-Deny, Kill-Switch Protected, Read-Opt-in Guarded, Zero Production Keys Rotated

---

## 1. Executive Summary & Diff Scope

The delta between the deployed baseline commit (`8cd4322`) and current `HEAD` encompasses:
- **84 runtime application files** across `app/`, `config/`, and `routes/`.
- **3 forward database migrations** introducing Mobile V1 and V2 tables without mutating existing accounting tables.
- **Strict Default-Deny Isolation**: In production, the immediate online cash sale slice is hard-disabled via `MOBILE_V2_CASH_SLICE_ENABLED=false` and `DenyFinancialExecutionAdapter`, ensuring that zero financial transactions can be executed over the mobile API until explicit business cutover.
- **Backward-Compatible Metadata (G1)**: The mobile bootstrap endpoint preserves the exact historical 12-key metadata contract by default, with opt-in support for `currency_minor_units` (G1 opt-in modes 0, 2, 3).

---

## 2. Runtime File & Migration Inventory (84 Files + 3 Migrations)

### 2.1 Database Migrations (3 Forward Migrations)
1. `database/migrations/2026_11_11_000001_create_mobile_v1_tables.php`:
   - Creates `mobile_devices` (device tracking, binding status, hardware fingerprints).
   - Creates `mobile_access_tokens` (hashed bearer tokens, expiration, multi-tenant scoping).
   - Creates `mobile_auth_challenges` (time-limited cryptographic authentication challenges).
   - Creates `company_mobile_settings` (company-level mobile defaults: walk-in customer, approved cash account, default tax rate).
2. `database/migrations/2026_11_11_000002_harden_mobile_ownership_and_remove_unapproved_catalog_fields.php`:
   - Hardens foreign keys and tenant isolation constraints on mobile device tables.
   - Prunes unapproved catalog fields to prevent leaking internal cost data.
3. `database/migrations/2026_11_12_000001_create_mobile_event_receipts_table.php`:
   - Creates `mobile_event_receipts` table supporting idempotency and auditability:
     - `client_event_id` (UUID unique index per company).
     - `payload_digest` (64-hex SHA-256 canonical JCS digest).
     - `sales_invoice_id`, `customer_payment_id` (cross-linked entity foreign keys).
     - `status` (`pending`, `completed`, `failed`).

### 2.2 Eloquent Models (7 Files)
- `app/Models/Company.php` (augmented with mobile settings relation).
- `app/Models/CompanyMobileSetting.php` (manages mobile-specific configuration).
- `app/Models/MobileAccessToken.php` (bearer token management with SHA-256 hash storage).
- `app/Models/MobileAuthChallenge.php` (challenge lifecycle management).
- `app/Models/MobileDevice.php` (device registry and hardware authorization).
- `app/Models/MobileEventReceipt.php` (event receipt identity and replay store).
- `app/Models/Unit.php` (measurement units with fractional and display logic).

### 2.3 HTTP Controllers & Routes (8 Files)
- `routes/mobile-v1.php`: Routes for `/api/mobile/v1/*` (auth, bootstrap, products, customers, devices).
- `routes/mobile-v2.php`: Routes for `/api/mobile/v2/*` (online cash sale commit, replay, status).
- `app/Http/Controllers/Api/Mobile/V1/AuthController.php`: Challenge-response login and logout.
- `app/Http/Controllers/Api/Mobile/V1/BootstrapController.php`: Workspace bootstrap metadata (12 keys default, 13 keys on opt-in).
- `app/Http/Controllers/Api/Mobile/V1/CustomerController.php`: Read-only customer directory.
- `app/Http/Controllers/Api/Mobile/V1/DeviceController.php`: Device registration and status.
- `app/Http/Controllers/Api/Mobile/V1/ProductController.php`: Read-only product catalog snapshot.
- `app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php`: V2 online cash sale endpoint with dual-gate validation.

### 2.4 Middleware & Service Providers (8 Files)
- `app/Http/Middleware/MobileApiBoundary.php`: V1 error formatting and contract envelope enforcement.
- `app/Http/Middleware/MobileAuthenticate.php`: V1 bearer token verification and tenant context binding.
- `app/Http/Middleware/MobileV2ApiBoundary.php`: V2 RFC-7807/envelope boundary and contract formatting.
- `app/Http/Middleware/MobileV2Authenticate.php`: V2 bearer token authentication and permission check.
- `app/Providers/MobileApiServiceProvider.php`: Binds `FinancialExecutionAdapterInterface` (defaults to `DenyFinancialExecutionAdapter`).
- `app/Providers/AppServiceProvider.php`: Core application bindings.
- `app/Providers/FortifyServiceProvider.php`: Web auth configuration.
- `config/mobile.php`: Configuration schema for mobile slices and defaults.

### 2.5 Canonical Actions & Services (15 Files)
- `app/Actions/Sales/ExecuteImmediateOnlineCashSaleAction.php`: Atomic 3-leg cash sale action (draft, posting + stock + GL, payment).
- `app/Actions/Sales/ApplyCustomerPaymentCreditAction.php`: 100% invoice-payment allocation.
- `app/Actions/Sales/CreateSalesInvoiceDraftAction.php`: Sales invoice draft creation.
- `app/Actions/Sales/PostSalesInvoiceAction.php`: Sales invoice posting with lot allocation and GL batch creation.
- `app/Actions/Sales/PostCustomerPaymentAction.php`: Cash payment posting and GL batch creation.
- `app/Actions/Sales/CreateQuotationAction.php`, `UpdateQuotationAction.php`
- `app/Actions/Sales/CreateSalesReturnDraftAction.php`
- `app/Actions/Purchasing/DeletePurchaseDraftAction.php`
- `app/Services/Money/CurrencyMinorUnitsResolver.php`: Authoritative minor unit resolution (ILS: 2, JOD: 3, zero-decimal: 0).
- `app/Services/Money/MoneyValues.php`: Fixed-point decimal formatting.
- `app/Services/Sales/DraftInvoiceIntegrity.php`, `ReceiptPreview.php`, `SalesCorrectionAudit.php`, `SalesDocumentRules.php`, `SalesProductSelection.php`, `SalesReturnAmounts.php`.

### 2.6 Receipt & Execution Capability Architecture (11 Files)
- `app/Support/Mobile/Receipt/MobileEventReceiptManager.php`: Inseparable atomic event recording, replay detection, and execution gatekeeper.
- `app/Support/Mobile/Receipt/DenyFinancialExecutionAdapter.php`: Production default adapter; strictly denies financial mutations.
- `app/Support/Mobile/Receipt/FinancialExecutionAdapterInterface.php`: Contract for execution adapters.
- `app/Support/Mobile/Receipt/NativeFinancialExecutionCapability.php`: Single-use execution token passed to canonical actions.
- `app/Support/Mobile/Receipt/CompositeFinancialExecutionScope.php`: Inseparable execution permit scope.
- `app/Support/Mobile/Receipt/Exceptions/FinancialExecutionDeniedException.php`
- `app/Support/Mobile/Receipt/Exceptions/IllegalStateException.php`
- `app/Support/Mobile/Receipt/Exceptions/MobileEventAuthenticationException.php`
- `app/Support/Mobile/Receipt/Exceptions/MobileEventDuplicatePayloadException.php`
- `app/Support/Mobile/Receipt/Exceptions/MobileEventForeignScopeException.php`
- `app/Support/Mobile/Receipt/Exceptions/SecurityException.php`

### 2.7 Contract Serialization & Intent Validation (16 Files)
- `app/Support/Mobile/Contract/CanonicalJsonSerializer.php`: RFC-8785 (JCS) JSON canonicalization serializer.
- `app/Support/Mobile/Contract/Revision3/OnlineSaleIntentProfile.php`: Strict schema and invariant validator for V2 sales.
- `app/Support/Mobile/Contract/Revision3/OnlineSaleWireResponseProjection.php`: Projects canonical wire response.
- `app/Support/Mobile/Contract/Revision3/PaymentSettlementValidator.php`: Validates cash payment settlement.
- `app/Support/Mobile/Contract/Revision3/Exceptions/IntentProfileValidationException.php`
- `app/Support/Mobile/Contract/StrictJsonParser.php`: Strict JSON parser rejecting duplicate keys, trailing commas, and non-conforming floats.
- `app/Support/Mobile/Contract/StrictJsonElement.php`, `StrictJsonObject.php`, `StrictJsonArray.php`, `StrictJsonString.php`, `StrictJsonNumber.php`, `StrictJsonBoolean.php`, `StrictJsonNull.php`
- `app/Support/Mobile/Contract/DecimalLexeme.php`, `ContractConstants.php`, `WireResponse.php`
- `app/Support/Mobile/CatalogSnapshot.php`

### 2.8 Tenancy, Reporting & UI Components (19 Files)
- `app/Support/Tenancy/CompanyContext.php`: Multi-tenant session and company binding manager.
- `app/Application/Commands/ImmediateSaleCommands.php`, `SalesInvoiceCommands.php`
- `app/Application/Reporting/Presentation/ReportFilterOptions.php`
- `app/Http/Controllers/Auth/LockedTwoFactorSessionController.php`
- `app/Livewire/Pages/Purchasing/PurchaseDetail.php`, `PurchaseIndex.php`
- `app/Livewire/Pages/Reporting/ReportView.php`
- `app/Livewire/Pages/Sales/CustomerStatementView.php`, `InvoiceForm.php`, `QuotationForm.php`
- Web view templates: `customer-statement-view.blade.php`, `report.blade.php`, etc.

---

## 3. Production Environment Isolation & Default-Deny Safeguards

### 3.1 Kill Switch & Default Adapter
To guarantee that deploying these 84 files and 3 migrations does not activate any live online cash transaction processing prematurely:
1. **Environment Configuration (`.env`)**:
   ```ini
   # Mobile API Master Switch (V1 read routes active)
   MOBILE_API_ENABLED=true

   # Mobile V2 API Slices (Strictly Default-Deny in Production)
   MOBILE_V2_API_ENABLED=false
   MOBILE_V2_CASH_SLICE_ENABLED=false
   ```
2. **Adapter Binding**:
   In [`app/Providers/MobileApiServiceProvider.php`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/app/Providers/MobileApiServiceProvider.php), the container binding for `FinancialExecutionAdapterInterface` defaults to `DenyFinancialExecutionAdapter`.
   - If an unauthorized request reaches the V2 online cash endpoint while `MOBILE_V2_CASH_SLICE_ENABLED=false`, the controller and adapter immediately throw `FinancialExecutionDeniedException`.
   - The boundary returns HTTP 503 / 403 with `SERVICE_UNAVAILABLE` / `FEATURE_DISABLED`.
   - Zero database mutations occur (no invoices, payments, lot allocations, or posting batches).

---

## 4. G1 Read Metadata Opt-in Verification (Modes 0, 2, 3)

The mobile bootstrap endpoint (`GET /api/mobile/v1/companies/{companyId}/bootstrap`) is engineered to guarantee backward compatibility with existing mobile clients while supporting the G1 currency minor units extension:

### 4.1 Mode 0: Standard Request (No Query Parameters)
- **Request**: `GET /api/mobile/v1/companies/{companyId}/bootstrap`
- **Output**: Exact historical **12-key JSON payload**:
  1. `company_id`
  2. `company_name`
  3. `base_currency`
  4. `walk_in_customer_id`
  5. `staff`
  6. `device_lease`
  7. `default_warehouse`
  8. `approved_cash_account`
  9. `units`
  10. `taxes`
  11. `offline_policy`
  12. `catalog_snapshot_version`
- **Verification**: `assertCount(12, array_keys($payload))` passes; `currency_minor_units` is strictly omitted.

### 4.2 Mode 2: Explicit Opt-In for Scale-2 Currencies (ILS, USD)
- **Request**: `GET /api/mobile/v1/companies/{companyId}/bootstrap?include=currency_minor_units`
- **Output**: Exactly **13 keys**: the standard 12 keys plus `currency_minor_units: 2`.
- **Validation**: Wire schema validator confirms valid projection; client accurately formats 2-decimal fractional amounts.

### 4.3 Mode 3: Explicit Opt-In for Scale-3 Currencies (JOD)
- **Request**: `GET /api/mobile/v1/companies/{companyId}/bootstrap?include=currency_minor_units`
- **Output**: Exactly **13 keys**: the standard 12 keys plus `currency_minor_units: 3`.
- **Validation**: Allows Jordan Trading Company or 3-decimal currencies to communicate minor unit scale without truncation.

### 4.4 Rejection of Invalid Parameters
- Any unsupported `?include=` value (e.g. `?include=invalid`, array syntax `?include[]=...`) fails closed immediately with HTTP 422:
  `{"code": "VALIDATION_FAILED", "message": "Invalid include parameter. Supported projection: currency_minor_units."}`.

---

## 5. Hostinger Production Architecture & Document Root Wiring

Per [`docs/HOSTINGER_DEPLOYMENT_GUIDE.md`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/HOSTINGER_DEPLOYMENT_GUIDE.md):

### 5.1 Filesystem Hierarchy (Private Application Root Outside Webroot)
```text
/home/u556956644/domains/palsync.net/
├── accounting/                         <-- PRIVATE APPLICATION ROOT (NOT WEB-ACCESSIBLE)
│   ├── .env                            <-- 0600 mode, root custody
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── routes/
│   ├── storage/
│   ├── vendor/
│   └── artisan
│
└── public_html/
    └── account/                        <-- PUBLIC DOCUMENT ROOT (WEB-ACCESSIBLE)
        ├── index.php                   <-- Bridges to ../../accounting
        ├── .htaccess                   <-- URL rewriting & CloudLinux PHP 8.4 handler
        └── build/                      <-- Vite assets (manifest.json, CSS, JS)
```

### 5.2 Document Root Protection via `.htaccess`
- `public_html/account/.htaccess` enforces:
  - HTTPS redirection.
  - Front controller routing (`RewriteRule ^ index.php [L]`).
  - Block access to hidden files (`.env`, `.git`).
  - CloudLinux Alt-PHP 8.4 handler isolation:
    ```apache
    <IfModule mime_module>
      AddHandler application/x-httpd-alt-php84 .php .php8 .phtml
    </IfModule>
    ```

---

## 6. Step-by-Step Rollout & Verification Runbook

> [!CAUTION]
> The worker is strictly prohibited from executing live Hostinger deployments, SSH connections, production database commands, or signing key rotations. The runbook below is the authoritative procedure for Root / Owner execution.

### Phase 1: Pre-Deployment Off-Host Backup
1. Capture complete off-host MySQL dump:
   ```bash
   mysqldump -h 127.0.0.1 -u u556956644_account -p --single-transaction --quick --routines --triggers u556956644_accounting > backup_pre_mobile_$(date +%Y%m%d%H%M%S).sql
   ```
2. Verify backup integrity: size non-zero, contains table schema and insert statements.
3. Snapshot existing private application directory:
   ```bash
   tar -czf /home/u556956644/backups/accounting_app_backup_$(date +%Y%m%d%H%M%S).tar.gz -C /home/u556956644/domains/palsync.net accounting/
   ```

### Phase 2: Maintenance Window Engagement
1. Engage application maintenance mode:
   ```bash
   php84 artisan down --secret="deployment-window-override" --render="errors::503"
   ```
2. Confirm web requests receive standard 503 maintenance page.

### Phase 3: Code Deployment & Fingerprint Verification
1. Sync files to `/home/u556956644/domains/palsync.net/accounting/`.
2. Verify repository commit SHA matches `HEAD`:
   ```bash
   git rev-parse HEAD
   # Must equal f120e8a662321ddb527afb90a3ba5575a8be904a
   ```
3. Install production dependencies:
   ```bash
   composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
   ```
4. Build public assets:
   ```bash
   npm ci
   npm run build
   ```
5. Sync public build assets to `public_html/account/build/`.

### Phase 4: Forward Database Migrations
1. Run database migrations:
   ```bash
   php84 artisan migrate --force
   ```
2. Verify migration log reports exactly 3 migrations executed:
   - `2026_11_11_000001_create_mobile_v1_tables`
   - `2026_11_11_000002_harden_mobile_ownership_and_remove_unapproved_catalog_fields`
   - `2026_11_12_000001_create_mobile_event_receipts_table`
3. Verify existing accounting tables (`sales_invoices`, `customer_payments`, `stock_movements`, `posting_batches`) are untouched.

### Phase 5: Cache Warming & Verification
1. Re-optimize Laravel caches:
   ```bash
   php84 artisan config:cache
   php84 artisan route:cache
   php84 artisan view:cache
   ```
2. Verify web endpoints:
   - Web application login and dashboard load successfully.
   - Mobile bootstrap returns HTTP 200 with 12 keys.
   - Mobile bootstrap with `?include=currency_minor_units` returns HTTP 200 with 13 keys.
   - Mobile V2 online cash endpoint returns HTTP 503 / 403 (feature disabled).

### Phase 6: Exit Maintenance Mode
1. Bring application live:
   ```bash
   php84 artisan up
   ```

---

## 7. Rollback & Contingency Protocol

If any migration or dependency failure occurs during the deployment window:
1. Keep maintenance mode engaged (`artisan down`).
2. Rollback the 3 migrations:
   ```bash
   php84 artisan migrate:rollback --step=3
   ```
3. If database state is corrupted, restore the pre-deployment MySQL dump:
   ```bash
   mysql -h 127.0.0.1 -u u556956644_account -p u556956644_accounting < backup_pre_mobile_*.sql
   ```
4. Restore application directory from tar archive if necessary.
5. Clear route and config caches (`artisan optimize:clear`).
6. Bring application up (`artisan up`).
7. Verify web accounting system operates normally under baseline `8cd4322`.
