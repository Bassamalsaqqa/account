# ADR 0008 — Reporting, Operational Analytics & Dashboard

Status: COMPLETE / ACCEPTED / MERGED / DEPLOYED / PRODUCTION VERIFIED.
Baseline: `d201a385a16e64eadb849b0b7d252e1365ae6a31` (Phase 7 accepted baseline).
Branch: `phase/8-reporting-dashboard`.
Target Phase: Phase 8 (Reporting & Dashboard).
Deployment: Owner-authorized rollout on 2026-10-09 at `96c310f30a07e97ab8e04d5afbf0b2bb805f4317`; live tree `a71f567ba54b16b30d4d1d4643a14a960e0ff98f` exactly matches accepted Phase 8 source. Codex independently verified production on 2026-10-09; see [production acceptance](../PHASE_7_8_PRODUCTION_ACCEPTANCE.md).
Phase Status: Phases 7 and 8 are complete, accepted, merged, deployed and production verified. Phase 9 implementation remains unstarted; its engineering proposal awaits architect review.

---

## 1. Context & Business Requirements

Small Trader Accounting requires operational, financial, and management reporting across all previously accepted domains (Phases 0–7: General Ledger, Inventory, Sales, Purchasing/AP, Money/Checks, Expenses, and Payroll-Lite).

Key requirements:
1. **Zero Economic Writes**: Reporting queries and dashboard surfaces must be strictly read-only. No accounting batches, stock movements, or numbering sequences may be mutated.
2. **Exact Arithmetic Invariants**: Zero PHP floating-point arithmetic. All monetary and quantity figures use exact `BigDecimal` strings (scale 2 for ILS/USD, 3 for JOD, 6 for base currency conversions and unit costs).
3. **Dual-Layer Authorization**: Enforce both report-level permissions (e.g., `reports.profit.view`, `reports.sales.view`, `reports.money.view`, `reports.expenses.view`, `reports.payroll.view`) and granular underlying domain authorities (e.g., `reports.cost.view`, `sales.invoice.view`, `purchasing.cost.view`, `money.receipt.view`, `money.check.view`, `money.expense.view`, `employees.view`, `payroll.salary.view`).
4. **Tenant Isolation & Security**: Every query is strictly bounded by active `CompanyContext`. No cross-company data leakage or fallback.
5. **Shared Hosting & Performance**: Fully operational without Redis, Docker, permanent Node servers, or background worker daemons. Queries must be bounded and paginate cleanly in SQL without full-table memory hydration.

---

## 2. Architecture & Design Decisions

### 2.1 The Reporting Guard & Security Pipeline
All reporting queries enter through `App\Application\Reporting\Security\ReportingGuard`:
- Validates active company membership and tenancy via `CompanyContext`.
- Verifies required capabilities using Spatie permissions with company team scoping (`setPermissionsTeamId`).
- Sensitive financial reports (e.g., Profit, Gross Margin, Inventory Valuation, Cost History) require cost visibility (`reports.cost.view` and domain-specific `inventory.cost.view` or `purchasing.cost.view`). In the absence of cost privileges, cost/profit metrics are omitted or access is rejected with HTTP 403.
- Underlying guards (`VendorFinancialRead`, `Phase7FinancialRead`, `OperationalReportRead`) enforce domain-specific read rules and ensure tenant boundaries.
- Strict input validation via `ReportFilters`: Disallows unknown filters, invalid date formats, out-of-bounds pagination, and unsupported sort orders, returning HTTP 422 prior to query execution.

### 2.2 Timezones, Dates & Period Representation
- Dates are evaluated in the company's configured timezone (`company->timezone`, with company table schema default `'Asia/Hebron'`).
- `ReportPeriod` validates the non-empty timezone string against PHP `DateTimeZone` without arbitrary hard-coded fallbacks.
- Supported presets: `today`, `yesterday`, `this_week`, `last_week`, `this_month`, `last_month`, `this_quarter`, `this_year`, `last_year`, and `custom`.
- For `custom` periods, start and end dates are validated strictly (`YYYY-MM-DD`). Explicit date boundaries are treated as inclusive business dates without unintended timezone date shifting.
- Livewire filter normalization (`ReportView::normalizePeriodState`): Incoming nested period structures are validated strictly using `ReportFilters::fromArray()` before modification. Explicit bookmarked date boundaries are frozen and preserved as visible custom controls (`from`/`to`) whenever they differ from the active clock's preset, preventing silent date shifts. Malformed nested dates or invalid keys produce a controlled validation error on `filters` without uncaught 500 exceptions, suppressing query execution.

### 2.3 Currencies & Exact Formatting
- Financial reports preserve native transaction currencies. Native amounts in mixed currencies are never silently aggregated into a single scalar.
- Scalar currency maps and nested per-currency metric trees (e.g. `['currencies' => ['USD' => ['net_sales' => '100.000000']]]`) preserve their explicit ISO currency context (`USD`) via `ReportPresenter::totals()`. Root GL metric names do not override nested foreign currency context with the base currency (`ILS`).
- Standardized `_base` metrics provide company base currency totals (converted at canonical posting-time exchange rates).
- Money and non-money separation: Presentation layers (`ReportPresenter`) distinguish currency amounts from quantities, unit counts, and percentages. Quantities and counts are never labeled with currency symbols.

### 2.4 Bounded Aggregations & Query Performance
- **Batched AR/AP Calculations**: `ReceivablePositionAsOf::forInvoices()` and `PurchasePayableAsOf::forHistory()` process up to 500 documents per batch, replacing per-invoice N+1 queries while maintaining full historical provenance.
- **SQL Window Functions**: Ledger reports (such as `MoneyMovementReportQuery`) compute running balances across full prior history using SQL window functions (`SUM(...) OVER (ORDER BY b.posting_date, b.id, l.line_number, l.id)`), with date-range filtering and SQL `LIMIT` / `OFFSET` applied in the outer query.
- **Historical Snapshots**: Party names and product descriptions use immutable historical snapshots saved at posting time, ensuring reports remain historically true even if master records are renamed or deleted.

### 2.5 Export & CSV Streaming
- `ReportCsvController` executes validation and permission checks prior to opening the HTTP stream.
- Malformed filters and invalid date ranges return an HTTP 422 JSON/HTML response immediately, avoiding malformed stream headers.
- Authorized streams use `CsvReportWriter`:
  - Output encoded in UTF-8 with BOM (`\xEF\xBB\xBF`) for Excel compatibility.
  - RFC-4180 quoting and formatting.
  - Formula injection mitigation: Fields beginning with `=`, `+`, `-`, or `@` are sanitized with leading single quotes.
  - Chunked pagination (100 rows per chunk) with re-authorization check on every page.
  - Stable export columns derived from report definitions, ensuring fields that are null on page 1 are not dropped from subsequent pages.

### 2.6 Dashboard Synthesis
- `DashboardReports` aggregates high-level metrics across Activity, Positions, and Alerts directly through the canonical reporting queries.
- Distinguishes period activity (Sales, Purchases, Expenses) from point-in-time balances (Cash, Receivables, Payables).
- Alerts provide actionable items: Overdue Sales Invoices, Unpaid Purchases, Due/Returned Checks, and Unpaid Payroll.

---

## 3. Query Families & Complete Traceability Matrix (69 Reports)

The reporting catalog registers exactly 69 distinct reports across 9 operational and management groups. Route keys use family.operation with hyphenated compound operation names and map directly to registered query classes and permission contracts:

| Route Key | Group | Query Class | Required Permissions |
|---|---|---|---|
| `sales.summary` | sales | `SalesSummaryReportQuery` | `reports.sales.view`, `sales.invoice.view` |
| `sales.by-period` | sales | `SalesSummaryReportQuery` | `reports.sales.view`, `sales.invoice.view` |
| `sales.by-customer` | sales | `SalesByCustomerReportQuery` | `reports.sales.view`, `sales.invoice.view` |
| `sales.by-product` | sales | `SalesByProductReportQuery` | `reports.sales.view`, `sales.invoice.view` |
| `sales.by-category` | sales | `SalesByCategoryReportQuery` | `reports.sales.view`, `sales.invoice.view` |
| `sales.gross-profit` | sales | `SalesGrossProfitReportQuery` | `reports.sales.view`, `sales.invoice.view`, `reports.profit.view`, `reports.cost.view`, `inventory.cost.view` |
| `sales.discounts` | sales | `SalesDiscountsReportQuery` | `reports.sales.view`, `sales.invoice.view` |
| `sales.returns` | sales | `SalesReturnsReportQuery` | `reports.sales.view`, `sales.invoice.view` |
| `sales.unpaid` | sales | `SalesUnpaidInvoicesReportQuery` | `reports.sales.view`, `sales.invoice.view` |
| `sales.price-history` | sales | `SalesPriceHistoryReportQuery` | `reports.sales.view`, `sales.invoice.view` |
| `customers.balances` | customers | `CustomerBalancesReportQuery` | `reports.sales.view`, `customers.view` |
| `customers.statement` | customers | `CustomerStatementReportQuery` | `reports.sales.view`, `customers.statement.view` |
| `customers.aging` | customers | `CustomerReceivablesAgingReportQuery` | `reports.sales.view`, `customers.statement.view` |
| `customers.overdue` | customers | `CustomerOverdueInvoicesReportQuery` | `reports.sales.view`, `customers.statement.view` |
| `customers.top` | customers | `CustomerTopReportQuery` | `reports.sales.view`, `sales.invoice.view` |
| `customers.buying-history` | customers | `CustomerBuyingHistoryReportQuery` | `reports.sales.view`, `sales.invoice.view` |
| `customers.product-history` | customers | `CustomerProductHistoryReportQuery` | `reports.sales.view`, `sales.invoice.view` |
| `purchases.summary` | purchases | `PurchaseSummaryReportQuery` | `reports.purchases.view`, `purchasing.purchase.view`, `purchasing.cost.view` |
| `purchases.by-period` | purchases | `PurchaseSummaryReportQuery` | `reports.purchases.view`, `purchasing.purchase.view`, `purchasing.cost.view` |
| `purchases.by-vendor` | purchases | `PurchasesByVendorReportQuery` | `reports.purchases.view`, `purchasing.purchase.view`, `purchasing.cost.view` |
| `purchases.by-product` | purchases | `PurchasesByProductReportQuery` | `reports.purchases.view`, `purchasing.purchase.view`, `purchasing.cost.view` |
| `purchases.returns` | purchases | `PurchaseReturnsReportQuery` | `reports.purchases.view`, `purchasing.purchase.view`, `purchasing.cost.view` |
| `purchases.unpaid` | purchases | `PurchaseUnpaidReportQuery` | `reports.purchases.view`, `purchasing.purchase.view`, `purchasing.cost.view` |
| `purchases.price-history` | purchases | `PurchasePriceHistoryReportQuery` | `reports.purchases.view`, `purchasing.purchase.view`, `purchasing.cost.view` |
| `vendors.balances` | vendors | `VendorBalancesReportQuery` | `reports.purchases.view`, `vendors.statement.view`, `purchasing.cost.view` |
| `vendors.statement` | vendors | `VendorStatementReportQuery` | `reports.purchases.view`, `vendors.statement.view`, `purchasing.cost.view` |
| `vendors.aging` | vendors | `VendorPayablesAgingReportQuery` | `reports.purchases.view`, `vendors.statement.view`, `purchasing.cost.view` |
| `vendors.purchase-history` | vendors | `VendorPurchaseHistoryReportQuery` | `reports.purchases.view`, `purchasing.purchase.view`, `purchasing.cost.view` |
| `vendors.product-history` | vendors | `VendorProductHistoryReportQuery` | `reports.purchases.view`, `purchasing.purchase.view`, `purchasing.cost.view` |
| `vendors.price-history` | vendors | `VendorProductPriceHistoryReportQuery` | `reports.purchases.view`, `purchasing.purchase.view`, `purchasing.cost.view` |
| `inventory.stock` | inventory | `InventoryStockOnHandReportQuery` | `reports.inventory.view`, `inventory.stock.view` |
| `inventory.by-warehouse` | inventory | `InventoryStockOnHandReportQuery` | `reports.inventory.view`, `inventory.stock.view` |
| `inventory.movements` | inventory | `InventoryMovementReportQuery` | `reports.inventory.view`, `inventory.stock.view` |
| `inventory.valuation` | inventory | `InventoryValuationReportQuery` | `reports.inventory.view`, `inventory.stock.view`, `inventory.cost.view`, `reports.cost.view` |
| `inventory.low-stock` | inventory | `LowStockReportQuery` | `reports.inventory.view`, `inventory.stock.view` |
| `inventory.adjustments` | inventory | `InventoryMovementReportQuery` | `reports.inventory.view`, `inventory.stock.view` |
| `inventory.cost-history` | inventory | `InventoryCostHistoryReportQuery` | `reports.inventory.view`, `inventory.stock.view`, `inventory.cost.view`, `reports.cost.view` |
| `inventory.vendor-products` | inventory | `InventoryVendorProductsReportQuery` | `reports.inventory.view`, `inventory.stock.view` |
| `inventory.transfers` | inventory | `TransferHistoryReportQuery` | `reports.inventory.view`, `inventory.stock.view` |
| `inventory.expiry` | inventory | `ExpiryReportQuery` | `reports.inventory.view`, `inventory.stock.view` |
| `money.balances` | money | `MoneyBalanceReportQuery` | `reports.money.view` |
| `money.movements` | money | `MoneyMovementReportQuery` | `reports.money.view` |
| `money.receipts` | money | `ReceiptRegisterReportQuery` | `reports.money.view`, `money.receipt.view` |
| `money.vendor-payments` | money | `MoneyVendorPaymentReportQuery` | `reports.money.view` |
| `money.transfers` | money | `TransferRegisterReportQuery` | `reports.money.view`, `money.transfer.view` |
| `money.checks` | money | `CheckRegisterReportQuery` | `reports.money.view`, `money.check.view` |
| `money.cash` | money | `MoneyBalanceReportQuery` | `reports.money.view` |
| `money.bank` | money | `MoneyBalanceReportQuery` | `reports.money.view` |
| `money.incoming-checks` | money | `CheckRegisterReportQuery` | `reports.money.view`, `money.check.view` |
| `money.outgoing-checks` | money | `CheckRegisterReportQuery` | `reports.money.view`, `money.check.view` |
| `money.due-checks` | money | `CheckRegisterReportQuery` | `reports.money.view`, `money.check.view` |
| `money.returned-checks` | money | `CheckRegisterReportQuery` | `reports.money.view`, `money.check.view` |
| `expenses.summary` | expenses | `ExpenseReportQuery` | `reports.expenses.view`, `money.expense.view` |
| `expenses.detail` | expenses | `ExpenseDetailReportQuery` | `reports.expenses.view`, `money.expense.view` |
| `expenses.by-period` | expenses | `ExpenseReportQuery` | `reports.expenses.view`, `money.expense.view` |
| `expenses.by-category` | expenses | `ExpenseReportQuery` | `reports.expenses.view`, `money.expense.view` |
| `expenses.by-currency` | expenses | `ExpenseReportQuery` | `reports.expenses.view`, `money.expense.view` |
| `expenses.trend` | expenses | `ExpenseReportQuery` | `reports.expenses.view`, `money.expense.view` |
| `expenses.fuel` | expenses | `ExpenseReportQuery` | `reports.expenses.view`, `money.expense.view` |
| `expenses.delivery` | expenses | `ExpenseReportQuery` | `reports.expenses.view`, `money.expense.view` |
| `expenses.transport` | expenses | `ExpenseReportQuery` | `reports.expenses.view`, `money.expense.view` |
| `payroll.summary` | payroll | `PayrollSummaryReportQuery` | `reports.payroll.view`, `employees.view`, `payroll.salary.view` |
| `payroll.payments` | payroll | `PayrollPaymentReportQuery` | `reports.payroll.view`, `employees.view`, `payroll.salary.view` |
| `payroll.advances` | payroll | `PayrollAdvanceReportQuery` | `reports.payroll.view`, `employees.view`, `payroll.salary.view` |
| `payroll.statement` | payroll | `PayrollEmployeeStatementReportQuery` | `reports.payroll.view`, `employees.view`, `payroll.salary.view` |
| `payroll.unpaid` | payroll | `PayrollSummaryReportQuery` | `reports.payroll.view`, `employees.view`, `payroll.salary.view` |
| `payroll.outstanding-advances` | payroll | `PayrollAdvanceReportQuery` | `reports.payroll.view`, `employees.view`, `payroll.salary.view` |
| `payroll.salary-history` | payroll | `PayrollEmployeeStatementReportQuery` | `reports.payroll.view`, `employees.view`, `payroll.salary.view` |
| `profit` | profit | `ProfitReportQuery` | `reports.profit.view`, `reports.cost.view` |

---

## 4. Honest Limitations & Operational Boundaries

1. **No Real-Time Sockets**: Dashboard updates require page refresh or Livewire action triggers; WebSocket servers are excluded to preserve shared-hosting compatibility.
2. **Statement Memory Hydration vs SQL Pagination**:
   - Operational queries (`SalesSummary`, `PurchaseSummary`, `MoneyMovement`) paginate directly in SQL via `LIMIT / OFFSET`.
   - Party statement queries (`CustomerStatementReportQuery`, `VendorStatementReportQuery`) wrap the accepted canonical subledger engines (`CustomerStatementQuery`, `VendorStatementQuery`), which evaluate complete customer/vendor history from inception up to the period end date to establish exact opening balance and running debit/credit totals in PHP memory before slicing rows via `array_slice()`.
   - CSV export streaming fetches 100 rows per chunk. For party statements, the subledger calculation runs per chunk. This ensures strict adherence to the accepted subledger arithmetic without mutating financial calculation engines.
3. **Synchronous Batch Caps**: As-of position queries cap chunk sizes at 500 records to prevent memory exhaustion on large datasets.
4. **No Retroactive Cost Mutations**: FIFO/moving-average inventory costing reflects point-in-time stock movements. Valuation reflects actual recorded posting batches.
5. **Historical Entity Links**: When underlying masters (customers, vendors, products) are deleted, drill-down links in reports are safely suppressed while historical text snapshots remain visible.
6. **Reconciliation Evidence**: Exactly 6 domain reconciliation services are verified and healthy in `IntegratedReportTruthTest` (`AccountingReconciliationService`, `Phase7ReconciliationService`, `InventoryReconciliationService`, `SalesReconciliationService`, `PayablesReconciliationService`, `MoneyReconciliationService`), confirming zero economic writes across all database tables.

---

## 5. Architectural Correction 02 Policies and Boundaries

### 5.1 Capability Alignment and Filter Authority
- **Authority Consistency**: All 69 report variants declare exact minimal capability requirements in `ReportRegistry` aligned with `ReportingGuard` and domain query guards. Hub discovery, report view authorization, and CSV export agree identically.
- **Account-Type Gating**: Money account queries enforce account-type authority (`money.cash.view` for cash, `money.bank.view` for banks). Users with only cash authority cannot enumerate or query bank accounts.
- **Filter Cleanup**: Unsupported filter declarations (`currency_code` on `money.movements`, `status` on `inventory.stock`, `inventory.by-warehouse`, and `inventory.valuation`) are removed from registry to preserve truthful contracts.

### 5.2 Master Selector Privacy and Bounded Search
- **Bounded Selection**: Master selectors (`customer_id`, `vendor_id`, `product_id`, `warehouse_id`, `employee_id`, `money_account_id`, `category_id`) return at most 25 records per query, preventing unbounded DOM hydration on large tables (>100 records).
- **In-Memory and Debounced Search**: Livewire components maintain local, non-URL-bound `$selectorSearch` state enabling debounced name and code/SKU search without polluting filter URLs or mutating financial filters.
- **Bookmark Retention**: When an entity is already selected or bookmarked in report filters, it is deterministically retained/prepended in the option list even if beyond the current 25-item search page.
- **Master Privacy**: When an actor lacks permission to view master lists (`customers.view`, `vendors.view`, `employees.view`, `inventory.stock.view`/`inventory.product.manage`), the corresponding master selector is completely omitted from response options and Livewire templates.

### 5.3 Expense Classification Segregation
- **Operating Default**: Default expense reports operate strictly on ordinary operating expenses (`status = operating`), computing P&L operating totals and excluding landed cost clearing activity.
- **Landed Cost Gating**: Selecting `status = landed_cost` explicitly requires `purchasing.cost.view`, authorizes through `Phase7FinancialRead`, and aggregates landed cost clearing base amounts into segregated totals (`landed_cost_clearing_base`).
- **P&L Integrity**: Net Profit in `ProfitReportQuery` excludes landed cost clearing lines, reflecting inventory capitalization rules.

### 5.4 Stable Product Aggregation and Representative Snapshot
- **Grouping Key**: `ProductAggregationHelper` groups catalog products by `product_id`. Null-product lines are grouped by a stable commercial identity tuple (`description`, `sku`, `name_ar`, `name_en`, `unit_ar`, `unit_en`) to prevent distinct free-text lines from collapsing under NULL.
- **Deterministic Snapshot**: Representative metadata (name, description, SKU) is derived via `ROW_NUMBER() OVER (...)` prioritizing latest original lines (`invoice_line`, `purchase_line`), tie-broken by `business_date DESC`, `document_id DESC`, `line_id DESC`.
- **Original-Only Date**: `last_purchased_date` is conditional MAX over original document lines only; return lines never advance or fabricate purchase dates. If a period contains only return activity, `last_purchased_date` remains null.

### 5.5 Aging KPI and Master Codes
- **Distinct Aging Counts**: `TradeOpenPositions` calculates `customer_count` and `vendor_count` across the entire filtered dataset using an ordered distinct accumulator, reporting truthful party counts independently of pagination.
- **Current Codes vs Immutable Names**: Reports join same-company current master codes (`customer_code`, `vendor_code`) while keeping historical document party names frozen from posted transaction snapshots.

### 5.6 Bounded CSV Snapshot Spool
- **Spool Before Delivery**: Complete export payload is written to a private temporary spool (`tmpfile()`) under MariaDB repeatable read consistent snapshot before HTTP response headers are sent.
- **Snapshot Isolation**: Database transaction is closed immediately after spool completion. Streaming to client occurs with no open database transaction or locks held.
- **Authority Fingerprint**: Pre-export authority fingerprint is re-validated outside the snapshot prior to response generation, and live checks verify authority immediately before every 64 KiB delivery chunk (see Correction 03 below).
- **Operational Bounds**: Capped at `MAX_ROWS = 50_000`, `MAX_BYTES = 50MB`, `MAX_SECONDS = 30`. Breaching limits deletes spool and aborts with explicit error before header transmission.

### 5.7 Final Integrity Verification
- Every advertised enum option is executed against its registered query in a permanent all-variant audit. Inventory increase/decrease options use canonical adjustment movement types; category-profit and invoice-margin ordering use exact aggregate SQL expressions supported by MariaDB. Sorting is omitted only from totals subqueries.
- Export transactions explicitly request transaction-local REPEATABLE READ and READ ONLY through the tracked Laravel connection, independent of session defaults. Period boundaries are frozen for every page.
- One preparation deadline covers setup, report reads and spool writes. Each SQL statement receives the remaining budget. The prior session timeout, including its fractional value, is restored exactly; failed rollback or restoration discards the connection.
- Reporting authorization reloads actor role/direct-permission relationships without evicting the global permission catalogue. A database-backed permission cache is supported without writing inside the snapshot. Live membership and full permission fingerprints are checked again after rollback and before delivery; changed authority discards the prepared export. Delivery checkpoints stop further output after revocation but cannot recall bytes already delivered.


## 6. Final Correction 03 release contracts

### 6.1 Disposable MariaDB verification

Use `tests/Support/run-phase8-disposable.php -- <PHPUnit targets>` with explicit `APP_ENV=testing`, `PHASE8_ALLOW_DISPOSABLE_DB=1`, and `PHASE8_TEST_DB_HOST`, `PORT`, `USERNAME`, `PASSWORD` variables. On platforms that remove empty environment variables, `PHASE8_TEST_DB_EMPTY_PASSWORD=1` explicitly opts into a blank test password. No credentials are inferred from application configuration. Only a loopback database server is accepted.

The runner creates a cryptographically named `accounting_p8_tmp_<12 hex>` schema, refuses adoption of pre-existing schemas, runs canonical forward migrations/catalogue provisioning, forces isolated PHPUnit configuration and removes only the schema it created. Auxiliary snapshot fixtures use separately owned schemas, preserve actual foreign keys, and restore the original PDO, transaction, actor and Company context. They do not truncate or clone persistent databases. Snapshot tests require an exact private runner ownership proof (schema/server identity and random nonce), and refuse unknown primary databases before RefreshDatabase runs.

### 6.2 Resource limits

Report requests must satisfy `page * per_page <= 50,000`, validated using division before multiplication. Unsafe or overflowing pages fail with controlled filter errors. The subledger page accumulator retains a bounded top-K heap, preserving stable sorting, currency-specific comparisons, full-dataset counts and financial totals. Safe pages beyond the dataset return empty rows; they do not invent balances. CSV retains its 50,000-row capacity and rejects oversized exports before delivery, alongside the existing 50 MiB and 30-second preparation bounds. Full-history statement hydration remains the limitation documented above.

### 6.3 Units and product aggregation

Base-quantity report rows use the same-company Product's canonical base Unit, including soft-deleted Products. A carton of 12 pieces plus five pieces therefore reports 17 pieces. Missing/free-text identities return an unavailable unit instead of borrowing a transaction-unit label. Historical representative names/SKUs and immutable financial source values are unchanged.

The five product aggregates now separate unranked SUM/group queries from ranked representative-identity selection. Totals/count queries use only the unranked branch. Representative selection remains deterministic; returns, reversals, grouping, sorting and decimal arithmetic are unchanged.

Independent local MariaDB measurement used 10,000 synthetic rows and 500 Products in a private activity table, without inserting fake financial documents. Five warm repetitions measured old/new main pipelines at 165.99/85.78 ms and old/new totals at 145.78/12.64 ms, with byte-identical sorted rows and exact totals (quantity 6,000; revenue 600,000; COGS 300,000). EXPLAIN and all samples are retained in `codex-d2-benchmark.json`. This isolates aggregation/ranking overhead; it is not an end-to-end production latency guarantee. Canonical posted-fixture regressions separately verify economic equivalence. No performance deferral is required for this bounded change.

### 6.4 Authorization and presentation

ReportingGuard checks current Company status, membership and freshly queried actor role/direct-permission assignments under the exact Company team. It does not clear or rebuild Spatie's global catalogue per authorization. Spatie mutation APIs retain normal invalidation. Database-backed caching, multiple actors/teams and independent-connection revocation are covered by regressions.

ReportPresentationPolicy centrally controls serialized options and sensitive column schemas. Sales COGS/profit columns and profit sorting require `reports.cost.view`, `reports.profit.view` and `inventory.cost.view`; inventory cost columns require `inventory.cost.view` plus `reports.cost.view`. Purchasing acquisition cost uses `purchasing.cost.view`; Inventory products-by-Vendor spend follows its source-specific `purchasing.cost.view` plus `reports.cost.view` conjunction. Expense Landed Cost selection requires the existing Expense-read and acquisition-cost authority. Forged, bookmarked or revoked privileged options remain rejected by query guards. UI and CSV column authorization is independent of nonempty rows, so restricted empty/out-of-range reports cannot reveal financial column names and authorized empty reports retain their valid schema.

### 6.5 CSV delivery revocation

Pre-preparation and post-snapshot/pre-response authorization remain mandatory. Delivery revalidates the live full authority fingerprint immediately before each 64 KiB chunk. Revocation stops subsequent chunks; private spool resources close in `finally`, including read failures, authority failures and client disconnects. Unexpected errors are not silently suppressed.

This is best-effort live revocation: HTTP cannot retract bytes already sent or replace an already-started 200 response with a 403. There is an unavoidable race between the final authority read and chunk emission. Revocation before response preparation still produces 403 with no CSV; revocation before/within the stream stops further bytes without claiming a retroactive status change. Permanent tests cover both membership and financial-permission changes on independent PDO connections before delivery and between chunks.

CSV preparation warms the normal Spatie permission catalogue once before its read-only transaction, so a cold database-backed cache cannot attempt a write inside that snapshot. This does not evict the catalogue; live actor/team/membership authorization and delivery fingerprints remain independently refreshed.

## 7. Independent acceptance and exact-source merge

Phase 8 received independent architect source acceptance in [review 5471206252](https://github.com/Bassamalsaqqa/account/pull/16#pullrequestreview-5471206252). This records source acceptance, not production validation or deployment.

- Pre-merge main: `d201a385a16e64eadb849b0b7d252e1365ae6a31`.
- Exact accepted source: `e8e3e28fb040875d3b55ad403bea54a037e71437`.
- PR: [#16](https://github.com/Bassamalsaqqa/account/pull/16), branch `phase/8-reporting-dashboard`.
- Merge commit: `96c310f30a07e97ab8e04d5afbf0b2bb805f4317`, created on 2026-10-09 using merge-commit workflow with expected-head protection.
- Accepted and merged tree: `a71f567ba54b16b30d4d1d4643a14a960e0ff98f`. Exact full-tree equality and both parents were verified before documentation changes.

### Accepted correction checkpoints

1. **Correction 01** (`0cef826a90510c7fe0ea1a7aa7d8fdafa3681c5e`): category-scoped Sales reversal metrics; Company-local current Money cutoff; Check/lot text identifiers; Expense and stock-transfer column contracts; warehouse grouping; data-backed all-69 registry/query/UI/CSV contracts.
2. **Correction 02** (`25f8ce2fc9af4f9ff4d28cbbcab2ac2e520bff3c`): consistent source/report permissions; bounded searchable selectors and master-data privacy; supported filter contracts; separate operating/Landed Cost grouping; original-only purchase/sale dates; distinct aging parties; stable product identity; current party codes with frozen names; repeatable-read private CSV spool with frozen periods, capacity limits and live authority checks.
3. **Correction 03** (`e8e3e28fb040875d3b55ad403bea54a037e71437`): guarded disposable schemas; bounded deep-page retention; canonical base units; permission-cache freshness without recurring eviction; capability-aware options; permission-based financial columns including empty results; authorization before each 64 KiB stream chunk; separated unranked aggregation and ranked identity, backed by the measured local benchmark in section 6.

All review threads were resolved before merge. Accepted-source execution evidence: Phase 8 **253 tests / 12,390 assertions**, disposable support **10 / 29**, all-69 registry coverage **16 / 8,617** (a subset of Phase 8), and final AR/EN desktop/mobile browser smoke **16/16**. Pint, Larastan level 6 and Blade compilation passed; the integration asset build passed. Integrated report-truth testing verified six healthy local reconciliations and zero economic writes. These are pre-merge source-test results, not production checks or fresh post-merge suite runs.

Post-merge verification consists of source/tree identity, exact ancestry, documentation-only diffs, Git parity and tracked-worktree/index cleanliness. No runtime changes, migrations, deployment, Hostinger access or Phase 9 work are part of finalization.

The statement-hydration, synchronous CSV capacity, and streaming-revocation limits in sections 4–6 remain accepted V1 boundaries. The previously outstanding Phase 7 prerequisite and Phase 8 deployment gates were completed under Owner authorization and independently verified on 2026-10-09. See [Phase 8 source acceptance handoff](../PHASE_8_SOURCE_ACCEPTANCE_HANDOFF.md) and [production acceptance](../PHASE_7_8_PRODUCTION_ACCEPTANCE.md); production verification is separate from the pre-merge source-test evidence above.
