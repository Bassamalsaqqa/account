# Company Data Export V1 — Contract & Architecture Specification

## Overview

**A.2 Company Data Export V1** provides an authoritative, point-in-time business archive of the explicitly supported company configuration, master data, transactional records, Trial Balance and stock positions for Small Trader Accounting.

The export is delivered as a single password-guarded, disk-backed ZIP archive containing UTF-8 CSV datasets and a comprehensive `manifest.json`.

---

## 1. Security & Tenancy Boundary

### 1.1 Strict Owner-Only Access
- **Guarded Route**: `settings.company-data.download` (served via `App\Http\Controllers\CompanyDataExportController`).
- **Authorization**:
  - The requesting actor must be an authenticated, active persisted user.
  - The request must carry an active company context.
  - The user's pivot record in `company_user` must have `status = 'active'` and `is_owner = true`.
  - **Admin Exclusion**: Users with administrative roles or permissions who do not possess `is_owner = true` on the active company are strictly rejected with HTTP 403.
- **Continuous Authority Re-validation**:
  - A pre-export authority fingerprint (including user ID, company ID, membership status, owner flag, and password hash) is captured prior to entering the transaction.
  - Authority is freshly re-verified against persistent storage immediately following snapshot completion.
  - Authority is re-asserted throughout byte streaming during HTTP delivery; if membership is revoked or password changed concurrently, delivery halts immediately.

### 1.2 Re-authentication & Password Confirmation
- Access requires recent password confirmation within the configured window (`config('auth.password_timeout', 10800)` seconds).
- Uses Fortify's existing password confirmation and `auth.password_confirmed_at`; no password payload alternative exists.
- Unconfirmed or stale requests fail closed with HTTP 423 for JSON clients (existing Fortify confirmation middleware).

### 1.3 Concurrency Control & Rate Limiting
- **Filesystem Locking (`flock`)**:
  - A non-blocking exclusive file lock (`LOCK_EX | LOCK_NB`) is acquired per company in `storage_path('app/private/company_exports/locks/company_export_{id}.lock')`.
  - Concurrent requests for the same company fail immediately with HTTP 429 (`company_data_export.export_in_progress`).
- **Rate Limiting**:
  - Configurable rate limit (default 5 attempts per 3,600 seconds) enforced per company and user key.
  - Exceeding the rate limit responds with HTTP 429.

### 1.4 Immutable Audit Logging
- Every successful export generation creates an immutable `AuditEvent` via `AuditService::log`:
  - `event_key`: `company.data_export.prepared`
  - `actor_user_id`: ID of the downloading Owner
  - `company_id`: Active Company ID
  - `meta_json`: Records archive file name, total record count, dataset count, byte size, data-as-of timestamp and known source baseline.
  - **Secrets Redaction**: All credentials, hashes, tokens, and sensitive keys are omitted.

---

## 2. Database Consistency & Resource Bounds

### 2.1 MariaDB Consistent Read Snapshot
- **Isolation Level**: `REPEATABLE READ, READ ONLY` established before executing any dataset query:
  ```sql
  SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY;
  START TRANSACTION READ ONLY;
  ```
- **Session Preservation**:
  - Initial connection settings (`@@session.tx_isolation` / `@@session.transaction_isolation` and `@@session.max_statement_time`) are captured prior to modification.
  - Transaction state and the previous session statement timeout are restored on completion or failure. The isolation choice is transaction-local.
- **Statement Timeout**:
  - Statement execution is bounded using MariaDB `max_statement_time` to prevent database longlocks.

### 2.2 Storage & Memory Management
- **Disk-Backed Streaming**: CSV datasets are spooled directly to private disk storage in bounded row chunks (disk-backed CSV; PHP memory and total dataset limits also apply).
- **Private Temporary Directory**:
  - Stored under `storage_path('app/private/company_exports/export_...')` with `0700` directory permissions.
  - No web server or public URL access.
- **Fail-Closed Cleanup**:
  - If any dataset fails, times out, or exceeds row limits, the entire temporary directory and all partial files are unlinked immediately. No partial ZIP is ever returned.
  - After delivery attempt termination, the ZIP file and temporary directory are deleted in the response termination callback.

---

## 3. CSV Formatting & Precision Invariants

### 3.1 Encoding & Formula Protection
- All CSV files begin with a UTF-8 Byte Order Mark (`\xEF\xBB\xBF`) to ensure clean rendering across spreadsheet applications (Excel, LibreOffice).
- Formatted using `App\Application\Reporting\Support\CsvCellFormatter`:
  - **Text Cells**: Any text starting with formula triggers (`=`, `+`, `-`, `@`), including preceding Unicode whitespace or control characters, is safely prefixed with an apostrophe (`'`).
  - **Decimal Cells**: Exact decimal values are validated against `/^-?\d+(?:\.\d+)?$/D`. Negative financial values (e.g., `-123.450000`) are preserved as pure numeric strings without apostrophe distortion.
  - **Leading Zeroes**: Numeric identifiers with leading zeroes (e.g., `0012345`, `0599123456`) are formatted as text to prevent loss of leading digits.

### 3.2 Currency Invariants
- Amounts are never summed or merged across unlike currencies.
- Multi-currency balances and transaction lines explicitly report both foreign currency amounts (`*_currency`) and base currency values (`*_base`).

---

## 4. Fixed V1 Inventory Datasets (40 Files)

| # | Filename | Description | Source / Authority |
|---|---|---|---|
| 1 | `company.csv` | Company profile, registration, and tax details | `companies` table (secrets redacted) |
| 2 | `company_currencies.csv` | Enabled currencies and base currency flag | `company_currencies` table |
| 3 | `exchange_rates.csv` | Historical currency exchange rates | `exchange_rates` table |
| 4 | `document_settings.csv` | Invoicing templates and document terms | `company_document_settings` table |
| 5 | `inventory_settings.csv` | Cost method, negative stock, and expiry warning rules | `company_inventory_settings` table |
| 6 | `purchase_settings.csv` | Purchasing defaults and payment terms | `company_purchase_settings` table |
| 7 | `customers.csv` | Customer directory and contact details | `customers` table |
| 8 | `customer_balances.csv` | Authoritative customer AR balances per currency | `App\Domain\Sales\Queries\CustomerBalanceQuery` |
| 9 | `vendors.csv` | Vendor directory and payment terms | `vendors` table |
| 10 | `vendor_balances.csv` | Authoritative vendor AP balances per currency | `App\Domain\Purchasing\Queries\VendorBalanceQuery` |
| 11 | `warehouses.csv` | Storage facilities and branches | `warehouses` table |
| 12 | `units.csv` | Units of measure and precision rules | `units` table |
| 13 | `product_categories.csv` | Category hierarchy | `product_categories` table |
| 14 | `products.csv` | Master catalog items and default pricing | `products` table |
| 15 | `product_units.csv` | Packaging unit conversions and prices | `product_units` table |
| 16 | `product_barcodes.csv` | Packaging unit barcodes and identifiers | `product_barcodes` table |
| 17 | `inventory_stock_on_hand.csv` | Stock positions and weighted average costs | `stock_movements` canonical aggregation |
| 18 | `quotations.csv` | Sales price quotation headers | `quotations` table |
| 19 | `quotation_lines.csv` | Sales quotation detailed line items | `quotation_lines` table |
| 20 | `sales_invoices.csv` | Sales invoice headers and financial totals | `sales_invoices` table |
| 21 | `sales_invoice_lines.csv` | Sales invoice line items, tax, and COGS | `sales_invoice_lines` table |
| 22 | `sales_returns.csv` | Customer credit returns and refunds | `sales_returns` table |
| 23 | `sales_return_lines.csv` | Sales return items and inventory reversals | `sales_return_lines` table |
| 24 | `purchases.csv` | Vendor purchase invoices and receipts | `purchases` table |
| 25 | `purchase_lines.csv` | Purchase line items and landed cost | `purchase_lines` table |
| 26 | `purchase_returns.csv` | Vendor return credits and debit notes | `purchase_returns` table |
| 27 | `purchase_return_lines.csv` | Vendor return items and inventory removal | `purchase_return_lines` table |
| 28 | `customer_payments.csv` | Customer payment receipts | `customer_payments` table |
| 29 | `customer_payment_allocations.csv` | Receipt-to-invoice settlements and FX | `customer_payment_allocations` table |
| 30 | `vendor_payments.csv` | Vendor disbursements | `vendor_payments` table |
| 31 | `vendor_payment_allocations.csv` | Payment-to-bill settlements and FX | `vendor_payment_allocations` table |
| 32 | `money_accounts.csv` | Cash registers and bank accounts | `money_accounts` table |
| 33 | `money_transfers.csv` | Treasury transfers and FX gain/loss | `money_transfers` table |
| 34 | `checks.csv` | Commercial bank check register | `checks` table |
| 35 | `expense_categories.csv` | Expense classifications | `expense_categories` table |
| 36 | `expenses.csv` | Operating expense vouchers | `expenses` table |
| 37 | `ledger_accounts.csv` | Chart of accounts structure | `ledger_accounts` table |
| 38 | `posting_batches.csv` | General ledger journal batches | `posting_batches` table |
| 39 | `posting_lines.csv` | General ledger double-entry lines | `posting_lines` table |
| 40 | `trial_balance.csv` | Authoritative cumulative trial balance | `App\Services\Accounting\DerivedLedgerBalanceService` |

---

## 5. Explicit Exclusions

1. **Payroll & Salaries**: Employee profiles (`employees`), salary advances (`employee_advances`), and payroll disbursements (`salary_entries`, `salary_payments`) are excluded per bounded V1 specification.
2. **Binary Attachments**: Receipt images, file attachments, logo images, and signature blobs are omitted from CSV exports.
3. **Security & Credentials**: Passwords, remember tokens, two-factor authentication secrets, recovery codes, personal access tokens, sessions, AI provider keys, and webhook secrets are strictly redacted and excluded.
4. **Internal Metadata**: Queue jobs, failed jobs, cache locks, command receipts, and raw system audit trails are excluded.

---

## 6. Manifest Specification (`manifest.json`)

The archive root contains `manifest.json` detailing:
- `manifest_version`: Specification version (`1.0`).
- `generated_at`: ISO 8601 UTC timestamp of export generation.
- `company`: Legal and trade identity, base currency, and timezone.
- `exported_by`: Authenticated Owner identity.
- `source_version`: Codebase schema and application version.
- `datasets`: Complete list of exported files with exact row counts.
- `total_datasets` & `total_records`: Global record tally.
- `completeness`: Explicit confirmation of non-truncated, complete execution.
- `exclusions`: Structured documentation of omitted modules.
- `limits`: Enforcement limits applied during execution.
- `reconstruction_limitations`: Legal and accounting notices regarding point-in-time reference usage.

---

## 7. Actual integration and interpretation

The route is a CSRF-protected **POST** inside the authenticated active-company group, with Fortify `password.confirm` and request throttling. Settings offers a persisted Owner-only card. Unconfirmed users return to Settings after existing password confirmation and deliberately retry. There is no password parameter, GET download, raw SQL or JSON snapshot feature.

The client waits for a full ZIP of the declared byte length before initiating a local download; truncated or revoked transfers show failure. "Download started" does not prove the device saved the file. Native POST remains a progressive fallback. No package is retained for a public or reusable URL.

Archived Customers, Vendors, Products, Warehouses and Categories remain in their directories with `deleted_at` markers so historical references can be interpreted. Balances include archived parties. The stock query uses immutable movement totals, scopes every joined company-owned entity, and uses the existing InventoryReportHelper for average cost; undefined ratios are blank/null rather than an authoritative zero.

V1 includes Trial Balance and full numeric General Ledger, not dedicated Profit & Loss, Balance Sheet or account-movement presentation CSVs. Cash/bank activity can be reviewed through money-account/GL references, payments, transfers and Checks. Existing authorized individual report CSV exports remain available. Brands, lot allocations, financial share issuance, employee master/payroll details, binary attachments, audit payloads and operational configuration are not a restoration contract. These omissions must be stated in the manifest, not hidden under a claim of complete application backup.

Payroll numeric journal amounts remain in the full GL and Trial Balance. Recognized payroll-source descriptions and all reversal descriptions are conservatively redacted; payroll source references do not provide employee detail. This Owner-only accounting inclusion is distinct from exporting salary/employee datasets. Free text entered by users can contain personal business information; store the downloaded ZIP privately.

Text cells that begin with formula characters and digit-only identifiers with leading zeros use an apostrophe prefix. This is a deliberate CSV escape, not an Excel formula. CSV consumers must account for that prefix when interpreting original identifiers. Exact signed decimal columns remain numeric text without that escape. Spreadsheet auto-conversion varies: use Excel's CSV import with identifier columns set to Text for reliable interpretation, and never use the ZIP as an import/restore input.

The package's `completeness` refers only to the fixed supported inventory, within its limits. A failed/limited attempt returns an error and no completed ZIP; it does not generate a manifest claiming partial success. Source metadata identifies the known base plus the export contract version; it is not a claim to be a SQL schema backup.


## 8. Hard bounds and runtime prerequisites

PHP 8.4 with ext-zip and pdo_mysql, MariaDB/InnoDB with accessible `max_statement_time`, and protected writable local storage are required. Client-only cookie sessions are refused: they cannot independently prove session revocation during preparation/delivery. Existing file/database session handlers are supported; tests also use the array handler.

Generation is capped at 30 seconds, 50,000 total records, 50,000 per dataset, 50 MiB CSV+manifest data, 50 MiB completed ZIP, 128 MiB PHP process memory, and chunks of 100–250 records. Operators can lower these ceilings, never increase them through environment configuration. Unsupported SQL deadline facilities fail closed. Slow statements are interrupted; a single-query deadline is rounded up to the next whole second. Network delivery is separate from the generation budget, remains bounded by the completed ZIP size and existing HTTP timeouts, and is reauthorized for each 64 KiB chunk.

A global lock limits preparation/delivery to one export across this application instance, in addition to a company lock. Lock files are retained to prevent inode races. ZIP files are not retained; shutdown cleanup also covers an unconsumed response. Hard process termination/host loss can defeat PHP cleanup; no reusable download URL exists.

See [manifest example](examples/company-data-manifest.example.json). It is sanitized synthetic browser-fixture output, not production data, a capacity result, or a restoration promise. Its row counts describe that fixture only. `total_uncompressed_bytes` counts the CSV bytes; the enforcement cap additionally includes manifest bytes.
