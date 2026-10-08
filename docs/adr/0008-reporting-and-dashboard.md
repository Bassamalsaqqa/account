# ADR 0008 — Reporting, Operational Analytics & Dashboard

Status: IMPLEMENTED / AWAITING INDEPENDENT REVIEW.
Baseline: `d201a385a16e64eadb849b0b7d252e1365ae6a31` (Phase 7 accepted baseline).
Branch: `phase/8-reporting-dashboard`.
Target Phase: Phase 8 (Reporting & Dashboard).
Deployment: NOT YET DEPLOYED. Subject to independent review and owner authorization.
Phase Status: Phase 7 awaits deployment; Phase 8 is candidate under independent review; Phase 9 is unstarted.

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
