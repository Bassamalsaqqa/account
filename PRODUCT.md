# Small Trader Accounting — Product Specification

<!-- impeccable:product-schema 1 -->

## Platform & Technical Foundation

- **Platform**: Web (Desktop, Tablet, and Mobile Responsive Web Application).
- **Architecture**: Laravel 13, Livewire 4, Tailwind CSS 4, Alpine.js, MariaDB 10.4+.
- **Internationalization**: Full Arabic RTL as primary locale; English LTR supported throughout. Tabular numbers, currency codes, SKU/barcodes, and document identifiers strictly isolated LTR.
- **Shared Hosting**: Zero daemon dependencies; fully compatible with standard Apache/cPanel/Hostinger environments (no Redis, Node.js server, Docker, or Supervisor required).
- **Mobile & PWA**: Responsive web application with network-only progressive web app capabilities; strictly network-connected (no offline financial writes or unverified client mutations).

## Users & Core Value Proposition

Arabic-speaking small merchants, retail/wholesale traders, and their authorized accounting staff manage daily commercial operations:
- **Sales & AR**: Quotations, Sales Invoices, Sales Returns, Customer Payments, Customer Statements, and Price History.
- **Purchasing & AP**: Purchase Invoices, Purchase Returns, Vendor Payments, Vendor Statements, and Landed Cost Allocations (including ADR 0013 zero-cost inventory receipts).
- **Inventory & Valuation**: Multi-warehouse stock tracking, batch/lot tracking with FEFO expiry management, stock movements, adjustments, and transfers.
- **Money & Banking**: Cash registers, bank accounts, transfers, customer/vendor check management, and daily cash summaries.
- **Payroll & Expenses**: Categorized operating expenses with secure receipt attachments, employee records, salary slips, and advances.
- **Reporting & Business Hub**: Real-time position financial reports, trade activity summaries, ledger reconciliations, and streamed CSV exports.
- **Control Plane**: Independent multi-company tenant provisioning, company lifecycle, and immutable platform audit ledger.

## Operating Principles & Safety Invariants

1. **Exact Precision**: All monetary values, costs, exchange rates, taxes, and quantities use exact string/decimal representations (`Brick\Math\BigDecimal`). Zero binary float arithmetic for accounting truth.
2. **Canonical Business Events**: All legs of every financial transaction commit atomically through canonical services (`AccountingPostingService`, `PostPurchaseAction`, `PostInvoiceAction`, etc.).
3. **Immutable History**: Posted financial transactions and stock movements are write-once; corrections require explicit reversal/return workflows.
4. **Server-Side Authorization**: Company boundary isolation, role permissions, and sensitive cost/profit data are strictly enforced server-side.
5. **Subscription Enforcement**: Global and per-company subscription enforcement remains OFF until explicitly authorized by the owner.
6. **AI Assistant Scope**: Built-in assistant answers permitted operational questions and drafts editable proposals; it never commits financial or inventory transactions directly.

## Owner Workflow Standards & UI Patterns

These durable workflow decisions govern both Arabic and English interfaces:

1. **Language & Copy**:
   - Write for a Palestinian small business owner, not a theoretical accountant.
   - Arabic is natural, concise, and locally familiar; English is equally clear.
   - Action names clearly state the result. Avoid internal accounting jargon (such as posting batches, FEFO allocation formulas, or token validations) in routine screens.
   - New documents automatically inherit the current interface language; printing defaults to that language. Editing existing drafts preserves stored language intent.

2. **Creation & Editing Flow**:
   - **Minimal Create**: Create forms start with essential required fields to enable rapid data entry.
   - **Full Edit**: Edit forms expose all details (notes, secondary contacts, tax registrations, default terms) by default.
   - **Accessible Toggles**: Optional fields in create dialogs/forms use an accessible toggle rather than cluttering initial entry.

3. **Numeric & Money Presentation**:
   - **Money**: Displayed and entered with exactly two decimal places for human readability. Economic storage, FX rates, unit conversions, and calculations use exact arbitrary-precision decimal strings throughout; never silently rounded or truncated.
   - **Quantities**: Trailing display zeros are trimmed for clean reading (e.g. `5` instead of `5.000`), but fractional units (e.g. `2.75` kg) are never truncated or rounded.
   - **Isolated LTR Numbers**: All currency amounts, quantities, SKUs, barcodes, and document codes use `font-mono tabular-nums` with explicit `dir="ltr"` and `unicode-bidi: isolate`.

4. **Table Density & Ordering**:
   - **Page Sizes**: Standard pagination provides 50 items by default, with 100 and 200 options.
   - **Ordering**: Document lists default to newest-first (descending created date) with a stable descending ID tie-breaker, ensuring newly entered backdated drafts remain easily discoverable.
   - **Single-Line Desktop Rows**: Ordinary table rows stay on a single line with reserved action space; text truncates cleanly with accessible tooltips. Mobile wraps into touch-friendly cards.
   - **Row Navigation**: Clicking non-interactive row area opens the detail page; buttons, links, checkboxes, and inline controls retain independent actions.

5. **Controls & Form Interaction**:
   - **Inline Actions**: Related actions live inside or adjacent to their input field: camera scanner icon inside product search, full balance shortcut inside amount fields, quick-create buttons next to party selectors.
   - **Short Actionable Errors**: Validation errors appear adjacent to the failing field with clear instructions for recovery, and focus shifts to the first invalid field.
   - **Single Confirmation**: Routine confirm actions use exactly one deliberate confirmation step without stacked browser prompts or redundant review screens.
   - **Shared Modals**: Non-destructive dialogs dismiss on backdrop click or Escape; in-flight submissions gate dismissal to prevent ambiguous states.

6. **Document Previews & Output**:
   - **Real PDF Parity**: Browser print and PDF export render identical document layouts derived from the canonical document number.
   - **Clean Summary Cards**: Restrained cards prominently show Total, Paid, and Remaining balances using exact read-model calculations.

7. **Assistant State & Qualifications**:
   - Bounded read optimization is released.
   - Demand-mounted AI, client bundle separation, enabled-state paired browser latency, and 100-line virtualized draft qualification remain deferred on HOLD.
