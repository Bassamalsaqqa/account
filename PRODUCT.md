# Small Trader Accounting — Product Specification

<!-- impeccable:product-schema 1 -->

## Platform & Technical Foundation

- **Platform**: Web (Desktop, Tablet, and Mobile Responsive Web Application).
- **Architecture**: Laravel 13, Livewire 4, Tailwind CSS 4, Alpine.js, MariaDB 10.4+.
- **Internationalization**: Full Arabic RTL as primary locale; English LTR supported throughout. Tabular numbers, currency codes, SKU/barcodes, and document identifiers strictly isolated LTR.
- **Shared Hosting**: Zero daemon dependencies; fully compatible with standard Apache/cPanel/Hostinger environments (no Redis, Node.js server, Docker, or Supervisor required).

## Users & Core Value Proposition

Arabic-speaking small merchants, retail/wholesale traders, and their authorized accounting staff manage daily commercial operations:
- **Sales & AP**: Quotations, Sales Invoices, Sales Returns, Customer Payments, Customer Statements, and Price History.
- **Purchasing & AR**: Purchase Invoices, Purchase Returns, Vendor Payments, Vendor Statements, and Landed Cost Allocations.
- **Inventory & Valuation**: Multi-warehouse stock tracking, batch/lot tracking with FEFO expiry management, stock movements, adjustments, and transfers.
- **Money & Banking**: Cash registers, bank accounts, transfers, customer/vendor check management, and daily cash summaries.
- **Payroll & Expenses**: Categorized operating expenses with secure receipt attachments, employee records, salary slips, and advances.
- **Reporting & Business Hub**: Real-time position financial reports, trade activity summaries, ledger reconciliations, and streamed CSV exports.
- **Control Plane**: Independent multi-company tenant provisioning, company lifecycle, and immutable platform audit ledger.

## Operating Principles & Safety Invariants

1. **Exact Precision**: All monetary values, costs, exchange rates, taxes, and quantities use exact string/decimal representations (Brick\Math\BigDecimal). Zero binary float arithmetic for accounting truth.
2. **Canonical Business Events**: All legs of every financial transaction commit atomically through canonical services (`AccountingPostingService`, `PostPurchaseAction`, `PostInvoiceAction`, etc.).
3. **Immutable History**: Posted financial transactions and stock movements are write-once; corrections require explicit reversal/return workflows.
4. **Server-Side Authorization**: Company boundary isolation, role permissions, and sensitive cost/profit data are strictly enforced server-side.
5. **AI Assistant Scope**: Built-in assistant answers permitted operational questions and drafts editable proposals; it never commits financial or inventory transactions directly.
