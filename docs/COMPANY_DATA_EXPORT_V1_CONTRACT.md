# Company Data Export V1 Contract

Owner-only, point-in-time archive (`routes` name `settings.company-data.download`, `CompanyDataExportController`) delivered as a password-guarded ZIP of 40 UTF-8 CSVs + `manifest.json`. **Not a SQL backup or restore media.**

## 1. Security & Tenancy

- **Owner-only access:** Requires active persisted user, active company context, `company_user.status = 'active'`, and `is_owner = true` (other admins return `403`).
- **Password confirmation:** CSRF-protected `POST` guarded by Fortify `password.confirm` (`config('auth.password_timeout', 10800)` seconds; stale JSON clients return `423`). No GET download or password payload parameter exists.
- **Continuous revalidation:** Captures authority fingerprint (user, company, status, owner flag, password hash) pre-transaction, re-verifies post-snapshot, and rechecks on every 64 KiB stream chunk (revocation/password change aborts immediately).
- **Concurrency & Rate Limit:** Per-company `LOCK_EX | LOCK_NB` flock in `storage/app/private/company_exports/locks/` + instance-wide global lock (`429` on overlap) and 5/3600s rate limit.
- **Audit:** Records `company.data_export.prepared` via `AuditService` with counts, size, timestamp, and baseline; zero secrets logged.

## 2. Database Snapshot & Resource Caps

- **Consistent Read:** Runs under `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY`, preserving/restoring session isolation and MariaDB `max_statement_time` (fails closed if statement timeouts unsupported).
- **Hard Bounds (operators may lower, never raise):** `30s` generation, `50,000` total rows, `50,000` rows/dataset, `50 MiB` CSV+manifest, `50 MiB` ZIP, `128 MiB` PHP memory, `100–250` chunk size. Requires `ext-zip`, `pdo_mysql`, and file/db sessions (cookie-only sessions refused).
- **Fail-Closed Cleanup:** Any error/cap/timeout deletes temp `0700` storage (`storage/app/private/company_exports/export_...`); no partial ZIP or reusable download URL is kept.

## 3. CSV Formatting & Currency Rules

- UTF-8 BOM (`\xEF\xBB\xBF`) via `App\Application\Reporting\Support\CsvCellFormatter`.
- Formula triggers (`=`, `+`, `-`, `@` even after Unicode whitespace/control chars) and digit-only leading-zero identifiers prefix `'`. Validated numeric decimals (`/^-?\d+(?:\.\d+)?$/D`, e.g. `-123.450000`) remain unprefixed numeric text. Import into Excel with identifier columns marked Text.
- Currencies are never summed across unlike codes; multi-currency tables report both `*_currency` and `*_base`.

## 4. Fixed V1 Datasets (40 CSVs) & Exclusions

- **Included (40):** `company`, `company_currencies`, `exchange_rates`, `document_settings`, `inventory_settings`, `purchase_settings`, `customers`, `customer_balances` (`CustomerBalanceQuery`), `vendors`, `vendor_balances` (`VendorBalanceQuery`), `warehouses`, `units`, `product_categories`, `products`, `product_units`, `product_barcodes`, `inventory_stock_on_hand` (`stock_movements` + `InventoryReportHelper`), `quotations`, `quotation_lines`, `sales_invoices`, `sales_invoice_lines`, `sales_returns`, `sales_return_lines`, `purchases`, `purchase_lines`, `purchase_returns`, `purchase_return_lines`, `customer_payments`, `customer_payment_allocations`, `vendor_payments`, `vendor_payment_allocations`, `money_accounts`, `money_transfers`, `checks`, `expense_categories`, `expenses`, `ledger_accounts`, `posting_batches`, `posting_lines`, `trial_balance` (`DerivedLedgerBalanceService`). Archived masters retain `deleted_at` for historical lookup.
- **Excluded:** Employee profiles/advances/payroll tables (GL totals remain in `posting_*`/`trial_balance` with redacted payroll/reversal descriptions), binary attachments/logos/signatures, credentials/tokens/2FA/AI keys, queue/lock/command-receipt/audit tables, brands, lot allocations, shares, and operational configs.

## 5. Manifest (`manifest.json`)

Includes `manifest_version` (`1.0`), `generated_at`, `company`, `exported_by`, `source_version`, `datasets`, `total_datasets`, `total_records`, `completeness`, `exclusions`, `limits`, and `reconstruction_limitations`. Reference fixture: [manifest example](examples/company-data-manifest.example.json).
