# Small Trader Accounting
## Product, MVP & Application Master Specification
### Version 1.0 — September 2026

> **Status:** Approved product source of truth  
> **Purpose:** Define what the application is, who it serves, what V1/MVP must contain, and which product behaviors must remain simple for non-accountant small traders.  
> **Companion engineering document:** `ENGINEERING_BLUEPRINT_v1.0.md`

---

# 1. Product vision

Build a modern, simple, bilingual business-management and accounting application for small traders who generally do not have a dedicated accountant.

The typical business:

- buys products from multiple vendors;
- stores and tracks products;
- sells products to customers;
- may sell the same product at different prices in different transactions;
- may buy the same product from different vendors at different prices;
- needs historical purchase and selling information;
- may receive and make partial payments;
- works with ILS, USD and JOD;
- receives and pays through Cash, Bank and Checks;
- has operating expenses;
- may employ workers and pay salaries;
- needs inventory tracking;
- may need expiry-date tracking for selected products;
- needs quotations, invoices, receipts, statements, reports, PDFs and printable documents;
- may share selected products as a catalog;
- may later use barcode scanning;
- primarily needs to understand the business rather than accounting terminology.

The central philosophy is:

> **Simple outside. Strict inside.**

The software should feel like a trading application.

Professional accounting runs underneath it.

---

# 2. Product success questions

The owner should be able to answer quickly:

- What did I sell?
- What did I buy?
- How much profit did I make?
- Who owes me money?
- Whom do I owe?
- What do I currently have in stock?
- Where is that stock?
- Which products expire soon?
- How much Cash do I have?
- How much is in Bank?
- Which Checks are pending?
- What were my expenses?
- What salaries remain unpaid?
- What did I previously buy this product for?
- Which vendors previously supplied it?
- What did each vendor charge me?
- What have I previously sold this product for?
- What did this specific customer buy from me?
- What did I buy from this specific vendor?
- What is the current value of my inventory?
- Which products are selling?
- Which products are barely moving?

The owner should not need to understand journal entries to answer these questions.

---

# 3. Target devices

The same application must work well on:

- mobile phones;
- tablets;
- laptops;
- desktop PCs.

Responsive behavior is a core requirement, not a later enhancement.

## Mobile

Prioritize:

- quick sale;
- quick purchase;
- product lookup;
- customer/vendor lookup;
- payment receipt;
- vendor payment;
- expense recording;
- stock lookup;
- quotation;
- invoice;
- catalog sharing;
- checks.

## Tablet

Optimize for:

- shop-counter use;
- warehouse work;
- sales representatives;
- receiving stock;
- invoicing;
- quotations.

## Desktop

Optimize for:

- reports;
- large lists;
- statements;
- stock analysis;
- administration;
- settings;
- bulk review.

No separate mobile application is required for V1.

---

# 4. Product positioning

This application is not intended to be:

- Odoo;
- QuickBooks;
- a giant ERP;
- an accountant-only workstation;
- a full HR system;
- a manufacturing system;
- an e-commerce platform.

It is:

> **A simple trading, inventory and accounting system for small businesses, with professional accounting hidden behind familiar everyday business actions.**

The essential product flow is:

```text
BUY
 ↓
STORE
 ↓
SELL
 ↓
COLLECT
 ↓
PAY
 ↓
UNDERSTAND THE BUSINESS
```

---

# 5. SaaS direction

The initial application serves a single company.

However, it must be SaaS-compatible from day one.

Today:

```text
Sign in
→ automatically enter company
```

Later:

```text
Sign in
→ choose authorized company
```

The same login URL and architecture remain valid.

The initial release does not require:

- subscription plans;
- platform billing;
- tenant signup;
- plan enforcement.

---

# 6. Languages

Initial languages:

- Arabic;
- English.

Arabic is primary.

Arabic must receive complete RTL support.

English is LTR.

Each company can enable or disable supported languages.

Each user can have a preferred interface language.

Documents can use a language independently of the user's interface language.

Example:

- user operates app in Arabic;
- creates an English quotation.

---

# 7. Company identity and settings

Company settings must support:

- Arabic company name;
- English company name;
- logo;
- address;
- telephone;
- WhatsApp;
- email;
- website;
- registration information;
- tax number if applicable;
- invoice footer;
- quotation terms;
- signature/stamp;
- default currency;
- enabled currencies;
- enabled languages;
- date/number preferences;
- document numbering;
- default warehouse;
- document/PDF options;
- QR options;
- inventory options;
- security options.

Settings should be grouped by domain rather than placed on one giant page.

---

# 8. Users and RBAC

Support:

- multiple users;
- custom roles;
- granular permissions.

Suggested roles:

- Owner;
- Administrator;
- Manager;
- Sales;
- Purchasing;
- Warehouse;
- Cashier;
- Viewer.

Examples of permission distinctions:

- salesperson can create invoice;
- salesperson may or may not change price;
- warehouse employee can see quantity but not cost;
- salesperson can see selling price but not purchase cost;
- only permitted users can view company profit;
- only permitted users can adjust stock;
- only permitted users can manage checks;
- only permitted users can change settings.

Access must be enforced server-side.

---

# 9. Customers

Customer record should support:

- name;
- business name;
- phone;
- WhatsApp;
- email;
- address;
- notes;
- preferred language;
- default currency;
- credit limit;
- status;
- custom fields later if needed.

Customer page should show:

- current outstanding balance;
- unpaid invoices;
- payments;
- quotations;
- sales returns;
- purchase history from the customer's perspective;
- statement;
- aging;
- notes;
- attachments.

Customer balance is derived from transactions rather than independently typed and maintained.

---

# 10. Vendors

Vendor record should support:

- name;
- business name;
- phone;
- WhatsApp;
- email;
- address;
- notes;
- preferred currency;
- status.

Vendor page should show:

- amount owed;
- purchases;
- payments;
- returns;
- vendor statement;
- aging;
- products supplied;
- historical purchase prices;
- notes;
- attachments.

---

# 11. Products

Product data may include:

- SKU;
- one or more barcodes;
- Arabic name;
- English name;
- Arabic description;
- English description;
- category;
- brand;
- product type;
- primary photo;
- additional photos;
- track stock;
- track expiry;
- base unit;
- default purchase unit;
- default sale unit;
- default purchase price;
- default sale price;
- minimum stock;
- active/inactive status.

Product types:

```text
Stock Item
Non-stock Item
Service
```

---

# 12. Pricing philosophy

Do not build a negotiation engine.

A product may have:

```text
default_sale_price
default_purchase_price
```

These are simply default/suggested values.

When creating a sale:

```text
line price = default selling price
```

The authorized user can edit the line price.

When creating a purchase:

```text
line cost = default purchase price
```

The user can edit the actual cost.

The actual line value is stored permanently with the transaction.

---

# 13. Historical selling-price information

Every sales line records:

- date;
- customer;
- product;
- quantity;
- unit;
- currency;
- actual unit price.

Therefore the application automatically knows historical selling prices.

Useful product/customer views should surface:

- recent selling prices;
- prices charged to this customer;
- transaction links;
- quantity/unit used.

No duplicate “price intelligence” subsystem is required.

---

# 14. Vendor purchase-price information

The same product may be purchased from several vendors.

Every purchase line records:

- vendor;
- product;
- date;
- quantity;
- unit;
- currency;
- actual cost;
- discount.

This automatically answers:

- Which vendors supplied this product?
- What did Vendor A charge before?
- Did the vendor raise the price?
- Which vendor previously sold it cheaper?
- What was my last purchase price?

The transaction history is authoritative.

---

# 15. Product photographs

Each product can have:

- primary photo;
- optional additional photos.

Images can be used in:

- product screens;
- catalogs;
- quotations;
- price offers;
- invoices where enabled.

Uploads should be resized/optimized automatically to control shared-hosting disk use.

---

# 16. Barcode readiness

Barcodes should exist in the data model from the beginning.

A product may later have:

- piece barcode;
- carton barcode;
- additional vendor/manufacturer barcodes.

V1 does not need an advanced scanner UX.

The architecture must later support:

- USB barcode scanner;
- Bluetooth scanner;
- phone camera;
- tablet camera;
- stock counting by scan;
- scan-to-add in sales;
- scan-to-add in purchases.

A physical barcode scanner commonly behaves like keyboard input, so product search should naturally accept barcode entry.

---

# 17. Units of measure

Products may be purchased/sold in different units.

Example:

```text
Product: Hand Soap

Base unit:
Piece

Other unit:
Carton

1 Carton = 12 Pieces
```

Purchase:

```text
20 cartons
```

Stock receives:

```text
240 pieces
```

Sale:

```text
3 cartons + 4 pieces
```

Stock removes:

```text
40 pieces
```

Units must be configurable.

Examples:

- piece;
- carton;
- box;
- pack;
- bottle;
- kilogram;
- gram;
- liter;
- meter.

---

# 18. Warehouses/storage

The data model must support multiple storage locations.

Possible examples:

- Main Warehouse;
- Shop;
- Secondary Warehouse;
- Vehicle.

The first client may use only one.

Stock must be traceable by warehouse.

---

# 19. Stock movement ledger

Every stock quantity change must be traceable.

Movement types include:

- Opening Balance;
- Purchase;
- Purchase Return;
- Sale;
- Sale Return;
- Transfer In;
- Transfer Out;
- Adjustment Increase;
- Adjustment Decrease;
- Damage;
- Loss;
- Expiry Disposal.

Every movement links back to its source.

Example:

```text
SALE
INV-2026-0041
Product X
Main Warehouse
-12 pieces
```

Users should be able to navigate from movement to source transaction.

---

# 20. Inventory costing

Use moving weighted-average cost initially.

Example:

```text
100 pieces @ 10 ILS
100 pieces @ 12 ILS
```

Average:

```text
11 ILS
```

Sale of 20 pieces:

```text
COGS = 220 ILS
```

Selling price is independent.

This allows actual profit calculation without forcing fixed sale prices.

---

# 21. Expiry tracking

Expiry tracking is optional per product.

Product setting:

```text
Track expiry dates: Yes / No
```

If disabled:

- stock behaves normally;
- no lot/expiry UI is shown.

If enabled:

- received stock is tracked in lots/batches;
- each received group may have an expiry date;
- optional lot number may be stored;
- the system can distinguish quantities by expiry.

Example:

```text
50 pieces
Expiry: 2027-03-31

30 pieces
Expiry: 2027-08-31
```

Total stock:

```text
80
```

but expiry quantities remain distinct.

---

# 22. FEFO

For products with expiry tracking enabled, the default outbound allocation should use:

> **First Expire, First Out (FEFO)**

Example:

```text
Lot A — expiry March — 20 remaining
Lot B — expiry September — 50 remaining
```

Sale of 10 defaults to:

```text
Lot A → -10
```

An authorized user may choose another lot when necessary.

---

# 23. Expiry reports

Support:

- expired products;
- expiring in 7 days;
- expiring in 30 days;
- expiring in 60 days;
- expiring in 90 days;
- custom expiry window;
- expiry by product;
- expiry by warehouse;
- quantity/value at risk.

Dashboard may show expiry alerts.

---

# 24. Expiry disposal

Expired stock must not disappear through a direct quantity edit.

Use an explicit:

```text
Expiry Disposal
```

recording:

- product;
- lot;
- warehouse;
- quantity;
- date;
- reason;
- user;
- cost impact.

Accounting should record inventory loss appropriately.

---

# 25. Negative stock

Default:

```text
Do not permit negative stock.
```

A future optional setting may allow it for authorized companies/users.

The MVP should keep it disabled.

Expiry-lot quantities must never become negative.

---

# 26. Sales documents

Core sales documents:

- Quotation / Price Offer;
- Sales Invoice;
- Sales Return / Credit Note;
- Receipt;
- Customer Statement.

Potential later additions:

- Sales Order;
- Delivery Note.

---

# 27. Quotations

Quotation should support:

- customer;
- date;
- expiry date;
- currency;
- exchange rate;
- products;
- quantity;
- units;
- editable unit price;
- discounts;
- optional tax;
- notes;
- terms.

Optional document settings:

- product photos;
- product descriptions;
- SKU;
- QR.

Workflow:

```text
Draft
→ Sent
→ Accepted
→ Rejected
→ Expired
→ Converted to Invoice
```

Quotation does not move inventory or post accounting.

---

# 28. Sales invoices

Invoice supports:

- invoice number;
- customer;
- date;
- due date;
- currency;
- exchange rate;
- warehouse;
- products/services;
- quantity;
- units;
- default editable unit price;
- discount;
- tax if enabled;
- notes;
- payment terms.

Status:

```text
Draft
Posted
Partially Paid
Paid
Void
```

`Partially Paid` and `Paid` are driven by payment allocations.

Posting affects:

- customer receivable;
- sales;
- inventory;
- COGS;
- ledger.

---

# 29. Purchases

Purchase/vendor bill supports:

- vendor;
- vendor invoice number;
- internal purchase number;
- date;
- due date;
- currency;
- exchange rate;
- warehouse;
- items;
- units;
- quantities;
- default editable purchase cost;
- discounts;
- tax if enabled;
- expiry/lot information where needed;
- notes.

Status:

```text
Draft
Posted
Partially Paid
Paid
Void
```

Posting affects:

- vendor payable;
- inventory;
- inventory cost;
- ledger.

---

# 30. Sales and purchase returns

Support explicit:

- Sales Return;
- Purchase Return.

Returns must correctly affect:

- customer/vendor balances;
- stock;
- expiry lots;
- COGS/inventory cost;
- accounting;
- reports.

Do not edit an old posted document to simulate a return.

---

# 31. Partial payments

Invoices and purchases do not have to be paid immediately or in full.

Example:

```text
Invoice:
10,000 ILS

Payment 1:
2,000 ILS Cash

Payment 2:
3,000 ILS Bank

Payment 3:
5,000 ILS later
```

Payments are separate records.

The system should support payment allocations.

A single payment may later be allocated to multiple invoices.

---

# 32. Cross-currency payments

Example:

```text
Invoice:
10,000 ILS
```

Customer pays:

```text
1,000 USD
```

Payment stores:

- payment currency;
- payment amount;
- exchange rate actually used;
- equivalent base amount;
- invoice amount allocated.

Historical rates never change because today's exchange rate changes.

---

# 33. Supported currencies

Initial:

- ILS;
- USD;
- JOD.

Currency precision:

- ILS: 2 decimal places;
- USD: 2 decimal places;
- JOD: 3 decimal places.

Company selects one base/reporting currency.

Every foreign-currency transaction preserves its own exchange rate.

---

# 34. Exchange rates

Initial system should remain simple.

Rates can be entered manually.

A configured rate is a suggestion/default for a new transaction.

The transaction stores its own rate.

Changing today's rate does not alter historical transactions.

Automatic online exchange rates may be added later.

---

# 35. User-facing money accounts

Keep the navigation conceptually simple:

- Cash;
- Bank;
- Checks.

Multiple actual money accounts can exist.

Examples:

```text
Cash ILS
Cash USD
Bank of Palestine ILS
Arab Bank USD
```

The user still understands them under the categories Cash/Bank.

Internally the accounting engine has additional accounts such as AR, AP, Inventory and COGS.

---

# 36. Incoming checks

Track:

- check number;
- customer;
- bank;
- amount;
- currency;
- received date;
- due date;
- allocated invoice(s);
- image;
- notes;
- status.

Typical lifecycle:

```text
Received / In Hand
→ Deposited
→ Cleared
```

Other outcomes:

```text
Returned
Cancelled
```

A returned check must correctly restore the financial obligation where appropriate.

---

# 37. Outgoing checks

Track:

- vendor;
- check number;
- bank;
- amount;
- currency;
- issue date;
- due date;
- status.

Typical:

```text
Issued
→ Cleared
```

Other:

```text
Returned
Cancelled
```

---

# 38. Expenses

Expense should support:

- date;
- category;
- description;
- optional vendor;
- amount;
- currency;
- exchange rate;
- payment method;
- Cash/Bank/Check;
- attachment;
- notes.

Suggested default categories:

- Fuel;
- Transport;
- Delivery;
- Rent;
- Electricity;
- Water;
- Telephone;
- Internet;
- Maintenance;
- Office;
- Marketing;
- Salary;
- Other.

Categories are customizable.

---

# 39. Landed costs

Certain purchasing-related costs may need to increase inventory cost rather than act purely as operating expense.

Example:

```text
Goods        10,000
Transport       500
Handling        200
```

User may explicitly classify the 700 as landed cost.

Future allocation options:

- by value;
- by quantity;
- manual.

Ordinary fuel/transport expenses remain operating expenses unless explicitly linked as landed cost.

---

# 40. Employees and salaries

Keep employee support simple.

Employee record:

- name;
- phone;
- job title;
- hire date;
- default salary;
- salary currency;
- active/inactive;
- notes.

Salary entry:

- employee;
- period;
- base salary;
- bonus;
- deduction;
- advance;
- net salary;
- paid amount;
- remaining;
- currency;
- payment method;
- payment date.

Status:

```text
Unpaid
Partially Paid
Paid
```

Do not build full HR/payroll in initial release.

---

# 41. Hidden accounting engine

The application must internally use professional double-entry accounting.

Every financial event creates balanced postings.

Core rule:

```text
SUM(debits) = SUM(credits)
```

The user normally does not see this machinery.

Internal accounts include:

- Cash;
- Bank;
- Checks;
- Accounts Receivable;
- Accounts Payable;
- Inventory;
- Cost of Goods Sold;
- Sales Revenue;
- Sales Returns;
- Operating Expenses;
- Salary Expense;
- Salary Payable;
- Inventory Loss;
- Expiry Loss;
- FX Gain;
- FX Loss;
- Opening Balance Equity;
- tax accounts if enabled.

---

# 42. Accounting examples

## Sale

Selling price:

```text
150
```

Inventory cost:

```text
100
```

Posting:

```text
Dr Accounts Receivable       150
    Cr Sales Revenue              150

Dr Cost of Goods Sold        100
    Cr Inventory                  100
```

## Customer payment

```text
Dr Cash                      150
    Cr Accounts Receivable       150
```

## Purchase

```text
Dr Inventory               1,000
    Cr Accounts Payable        1,000
```

## Vendor payment

```text
Dr Accounts Payable        1,000
    Cr Bank                    1,000
```

---

# 43. Financial history

Draft documents may be edited.

Posted financial records should not silently change.

Corrections use:

- void;
- reversal;
- return;
- cancellation;
- replacement.

History must remain auditable.

---

# 44. Public product catalogs

User can select products and generate a shareable catalog.

Example:

```text
☑ Product A
☑ Product B
☑ Product C
```

Options:

```text
Show photos          Yes
Show descriptions    Yes
Show SKU             Yes
Show prices           No
```

Default use case includes catalogs without prices.

System generates:

- secure public URL;
- QR code.

Catalog may later be:

- updated;
- disabled;
- expired;
- revoked;
- password protected.

The same live link/QR can show updated catalog content.

---

# 45. Quotations with photos and QR

Quotation output can optionally include:

- product photos;
- descriptions;
- SKU;
- units;
- prices;
- QR.

The QR can open a secure web version later.

---

# 46. Public document sharing

The same sharing infrastructure may serve:

- catalogs;
- quotations;
- invoices;
- receipts;
- selected statements;
- future delivery notes.

Sharing is opt-in.

Sensitive financial documents remain private by default.

---

# 47. PDF and printing

Every meaningful document/report should provide appropriate:

- View;
- Print;
- PDF.

Reports may additionally support:

- CSV;
- XLSX where useful.

Documents should include configurable:

- company logo;
- company details;
- customer/vendor;
- document number;
- date;
- item lines;
- totals;
- payment information;
- notes;
- terms;
- footer;
- QR.

Arabic RTL and English LTR must both be supported.

---

# 48. Document numbering

Separate sequences for:

- Quotation;
- Invoice;
- Sales Return;
- Purchase;
- Purchase Return;
- Receipt;
- Payment;
- Expense;
- Stock Adjustment;
- Transfer.

Example:

```text
INV-2026-00001
Q-2026-00001
PUR-2026-00001
```

Allow:

- prefix;
- padding;
- next number;
- annual reset.

Numbering must remain safe when multiple employees create documents simultaneously.

---

# 49. Sales reports

Expected reports:

- sales summary;
- sales by period;
- sales by customer;
- sales by product;
- sales by category;
- sales by employee;
- gross profit;
- profit by invoice;
- profit by product;
- discounts;
- returns;
- unpaid invoices;
- selling-price history.

---

# 50. Customer reports

Expected:

- customer balances;
- customer statements;
- receivables aging;
- overdue invoices;
- top customers;
- customer buying history;
- product history by customer.

---

# 51. Purchase reports

Expected:

- purchase summary;
- purchases by period;
- purchases by vendor;
- purchases by product;
- purchase returns;
- unpaid purchases;
- purchase-price history.

---

# 52. Vendor reports

Expected:

- vendor balances;
- vendor statements;
- payables aging;
- vendor purchase history;
- products purchased from vendor;
- price history by vendor/product.

---

# 53. Inventory reports

Expected:

- stock on hand;
- stock by warehouse;
- stock movement;
- inventory valuation;
- low stock;
- out of stock;
- adjustments;
- product cost history;
- products by vendor;
- transfer history;
- expiry reports;
- expired stock;
- soon-to-expire stock.

Later:

- slow-moving products;
- fast-moving products;
- dead stock.

---

# 54. Money reports

Expected:

- Cash movement;
- Bank movement;
- check register;
- incoming checks;
- outgoing checks;
- checks due;
- returned checks;
- receipts;
- payments;
- transfers;
- balances by currency.

---

# 55. Expense reports

Expected:

- expenses by period;
- expenses by category;
- expenses by currency;
- expense trend;
- fuel totals;
- delivery totals;
- transport totals;
- detailed expense ledger.

---

# 56. Employee reports

Expected after payroll-lite implementation:

- salary summary;
- salary payments;
- unpaid salaries;
- employee salary history;
- employee statement.

---

# 57. Profit reports

Expected structure:

```text
Sales Revenue
- Cost of Goods Sold
= Gross Profit

- Operating Expenses
- Salary Expense
± FX Gain/Loss
- Inventory Loss/Expiry
= Net Profit
```

All calculations must support selected periods.

---

# 58. Report periods

Common selector:

- Today;
- Yesterday;
- This Week;
- Last Week;
- This Month;
- Last Month;
- This Quarter;
- This Year;
- Last Year;
- Custom.

Custom provides:

- From;
- To.

---

# 59. Dashboard

Dashboard should answer business questions rather than merely display decoration.

Suggested metrics:

- Sales;
- Gross Profit;
- Expenses;
- Net Profit;
- Receivables;
- Payables;
- Cash;
- Bank;
- Checks;
- Inventory Value.

Operational alerts:

- overdue customers;
- purchases due;
- checks due;
- returned checks;
- low stock;
- expiring products;
- expired stock;
- unpaid salaries;
- recent activity.

Mobile dashboard should prioritize important information rather than simply shrink desktop layout.

---

# 60. Global search

Search should find:

- customer;
- vendor;
- product;
- SKU;
- barcode;
- invoice;
- quotation;
- purchase;
- receipt;
- check.

Barcode entry naturally fits this search infrastructure.

---

# 61. Quick actions

Useful quick actions:

```text
+ Sale
+ Purchase
+ Customer
+ Vendor
+ Product
+ Payment Received
+ Vendor Payment
+ Expense
```

Mobile may use a compact quick-action menu.

---

# 62. Taxes

Tax is optional but supported structurally.

Company setting:

```text
Taxes Enabled: Yes / No
```

Tax rate supports:

- name;
- percentage;
- inclusive/exclusive;
- active/inactive.

When tax is disabled, tax UI largely disappears.

When enabled, proper tax control accounting is required.

---

# 63. Audit trail

Track important activity such as:

- invoice created;
- invoice posted;
- invoice voided;
- purchase posted;
- payment received;
- vendor paid;
- product changed;
- default price changed;
- actual transaction price changed;
- stock adjusted;
- expiry disposed;
- exchange rate changed;
- user/role changed;
- company setting changed;
- catalog created;
- share revoked.

Audit history is not editable through normal UI.

---

# 64. Deletion philosophy

Master data can generally be archived/soft-deleted when safe:

- customers;
- vendors;
- products;
- employees.

Posted financial records are not casually deleted.

Use:

- Void;
- Reverse;
- Cancel;
- Return.

---

# 65. Security expectations

Required baseline:

- CSRF protection;
- secure password hashing;
- login rate limiting;
- secure session handling;
- role/permission enforcement;
- strict company isolation;
- HTTPS;
- secure cookies;
- secure upload validation;
- MIME checking;
- image re-encoding;
- escaped output;
- server-side validation;
- no committed secrets;
- audit trail;
- dependency scanning;
- tenant-isolation tests;
- permission tests;
- optional/required 2FA for privileged users.

---

# 66. File privacy

Separate:

```text
public product media
```

from:

```text
private financial attachments
```

Private files must require authorization or an explicit secure share link.

---

# 67. Responsive UX principles

Priorities:

1. clarity;
2. speed;
3. confidence;
4. readable numbers;
5. good tables;
6. excellent filters;
7. touch-friendly controls;
8. useful mobile workflows;
9. printable documents;
10. visual polish.

No critical function should require hover.

No mobile screen should depend on unreadably compressed desktop tables.

---

# 68. User language

Prefer familiar business wording.

Use:

- Customer owes you;
- You owe vendor;
- Received payment;
- Paid vendor;
- Cash;
- Bank;
- Checks;
- Stock;
- Expense;
- Profit.

Avoid exposing jargon such as:

- debit account;
- credit account;
- contra account;
- posting batch;

unless an advanced accounting view is intentionally added later.

---

# 69. Performance expectations

The app should be efficient on shared hosting.

Use:

- pagination;
- indexed database queries;
- optimized images;
- lazy image loading;
- efficient aggregates;
- appropriate caching;
- background exports when large;
- no N+1 query patterns.

Do not prematurely add distributed infrastructure.

---

# 70. Shared-hosting target

Production must remain deployable on Hostinger shared hosting.

A permanent Node server is not required.

Frontend assets may be built statically.

The application should run primarily on:

- PHP;
- MySQL;
- static built assets;
- cron;
- filesystem storage.

---

# 71. Testing expectations

Core business behavior must be protected by tests.

Important invariants:

- every accounting posting balances;
- business events are atomic;
- each company is isolated;
- duplicate financial posting cannot occur;
- sale reduces stock exactly once;
- purchase increases stock exactly once;
- returns reverse correctly;
- unit conversion remains correct;
- partial payments calculate correctly;
- cross-currency payments calculate correctly;
- expiry lots remain traceable;
- FEFO allocation works correctly;
- posted history cannot silently change.

---

# 72. Core unit-conversion test

Example:

```text
1 carton = 12 pieces
```

Purchase:

```text
10 cartons
```

Expected stock:

```text
120 pieces
```

Sell:

```text
2 cartons + 3 pieces
```

Expected:

```text
93 pieces
```

Return one carton:

```text
105 pieces
```

---

# 73. Core vendor-history test

Product X:

```text
Vendor A
10 cartons
100 ILS each

Vendor B
8 cartons
95 ILS each

Vendor A
12 cartons
110 ILS each
```

The system must display this exact history.

No special pricing engine is required.

---

# 74. Core expiry test

Purchase:

```text
Product X

50 pieces
Expiry 2027-03-31

30 pieces
Expiry 2027-08-31
```

Sale:

```text
20 pieces
```

Expected FEFO suggestion:

```text
20 from March lot
```

Remaining:

```text
March: 30
August: 30
Total: 60
```

---

# 75. Core currency test

Company base:

```text
ILS
```

Invoice:

```text
10,000 ILS
```

Payment:

```text
1,000 USD
Rate 3.30
```

Applied:

```text
3,300 ILS
```

Remaining:

```text
6,700 ILS
```

The original 3.30 rate remains historically fixed.

---

# 76. Canonical full business scenario

The permanent end-to-end test should eventually simulate:

1. create company;
2. create users/roles;
3. create customer;
4. create two vendors;
5. create stock product;
6. piece base unit;
7. carton conversion = 12;
8. barcode data;
9. expiry tracking;
10. buy from Vendor A with expiry batch;
11. buy same product from Vendor B at another price;
12. verify purchase history;
13. make quotation;
14. create invoice with edited sale price;
15. FEFO stock allocation;
16. post invoice;
17. customer pays partly in USD Cash;
18. customer pays remainder through Bank;
19. vendor is partially paid by Check;
20. record operating expense;
21. record salary when module exists;
22. customer returns product;
23. dispose expired stock;
24. generate customer statement;
25. generate vendor statement;
26. generate inventory report;
27. generate expiry report;
28. generate profit report.

Final assertions include:

- stock quantity;
- stock lots;
- inventory value;
- customer balance;
- vendor balances;
- Cash;
- Bank;
- Checks;
- COGS;
- sales;
- expenses;
- gross profit;
- net profit;
- balanced ledger.

---

# 77. MVP definition

A serious operational MVP includes:

## Foundation

- authentication;
- company;
- Arabic/English;
- mobile/tablet/desktop responsive shell;
- users;
- RBAC;
- settings;
- audit infrastructure.

## Customers/vendors

- customer records;
- vendor records;
- balances;
- transaction history;
- statements.

## Products/inventory

- products;
- categories;
- photos;
- barcodes stored;
- units;
- unit conversion;
- warehouses;
- stock movement;
- weighted-average cost;
- optional expiry tracking;
- lots;
- FEFO;
- expiry reporting.

## Sales

- quotations;
- invoices;
- returns;
- editable line prices;
- partial payments.

## Purchasing

- purchases;
- returns;
- editable line costs;
- purchase/vendor price history;
- partial payments.

## Money

- Cash;
- Bank;
- Checks;
- ILS;
- USD;
- JOD;
- cross-currency payments.

## Expenses

- expense categories;
- expenses;
- attachments.

## Accounting

- hidden double-entry ledger;
- AR;
- AP;
- inventory;
- COGS;
- sales;
- expenses;
- FX;
- balanced postings.

## Reports

- sales;
- purchases;
- customers;
- vendors;
- stock;
- expiry;
- profit;
- expenses;
- Cash/Bank/Checks.

## Documents

- PDF;
- print;
- QR;
- secure public catalog;
- quotation/document sharing.

---

# 78. V1 completion after MVP

After the core MVP is stable, complete V1 with:

- employees;
- salary tracking;
- landed costs;
- stock transfers;
- enhanced aging;
- richer catalog management;
- advanced dashboard;
- XLSX exports;
- import tools;
- richer document templates;
- enhanced expiry warnings;
- additional inventory analysis.

Some of these may be promoted earlier if the first real client requires them.

---

# 79. Future capabilities

Design for but do not prematurely implement:

- multiple companies per user;
- SaaS subscriptions;
- barcode-scanner workflows;
- phone-camera barcode scanning;
- barcode label printing;
- serial-number tracking;
- advanced lot tracking;
- purchase orders;
- sales orders;
- delivery notes;
- automatic exchange rates;
- full payroll;
- POS;
- mobile-native apps;
- customer/vendor portals;
- bank feeds;
- OCR;
- AI assistant;
- advanced tax localization;
- e-commerce.

---

# 80. Implementation sequence

## Phase 0 — Foundation

- new Laravel project;
- quality tooling;
- responsive UI shell;
- Arabic/English infrastructure;
- authentication foundation.

## Phase 1 — Identity/administration

- companies;
- memberships;
- users;
- RBAC;
- company settings;
- audit infrastructure.

## Phase 2 — Financial foundation

- exact money handling;
- currencies;
- exchange rates;
- internal accounts;
- double-entry posting engine;
- accounting invariants.

## Phase 3 — Products/inventory

- categories;
- products;
- photos;
- barcodes;
- units;
- warehouses;
- stock movements;
- costing;
- expiry lots;
- FEFO.

## Phase 4 — Sales vertical

```text
Customer
→ Quotation
→ Invoice
→ Inventory
→ Accounting
→ Payment
→ Statement
→ Reports
→ PDF/share
```

## Phase 5 — Purchasing vertical

```text
Vendor
→ Purchase
→ Inventory
→ Accounting
→ Payment
→ Statement
→ Purchase/price history
```

## Phase 6 — Money/checks

- Cash;
- Bank;
- Checks;
- transfers;
- payment allocations;
- cross-currency behavior.

## Phase 7 — Expenses/employees/landed cost

COMPLETE / ACCEPTED / MERGED / AWAITING DEPLOYMENT. Accepted source `e8a5dee856bc06da48b1313f8776d723af309b5e` merged through PR #15 at `c07559c92816f838532e4b9d1c1537065f50ad5c` on 2026-10-08. Corrections 01–03 are independently accepted; provenance and outcomes are recorded in ADR 0007. Production deployment is pending separate authorization.

## Phase 8 — Reports

COMPLETE / ACCEPTED / MERGED / AWAITING DEPLOYMENT. Accepted source `e8e3e28fb040875d3b55ad403bea54a037e71437` merged through PR #16 at `96c310f30a07e97ab8e04d5afbf0b2bb805f4317` on 2026-10-09, with exact accepted-tree preservation. Independent acceptance review: `5471206252`. Corrections 01–03 are accepted; see ADR 0008 and `docs/PHASE_8_SOURCE_ACCEPTANCE_HANDOFF.md` for provenance, verification and operational limits. Phase 8 is not deployed. Phase 7 production deployment and healthy-baseline verification remain a separate outstanding gate.

## Phase 9 — Documents/catalog/QR/sharing

UNSTARTED.

## Phase 10 — Production hardening

- security;
- tenant testing;
- accounting tests;
- inventory tests;
- RTL;
- responsive QA;
- performance;
- backup/restore;
- Hostinger deployment.

---

# 81. UI/UX direction

The application should look and behave like a modern operational business tool, not an accountant-only ERP.

The visual system should emphasize:

- information hierarchy;
- readable figures;
- clear status;
- strong forms;
- usable tables;
- obvious actions;
- fast navigation;
- responsive adaptation;
- RTL quality.

The design system must cover:

- mobile;
- tablet;
- desktop;
- Arabic RTL;
- English LTR;
- empty states;
- loading states;
- validation;
- error states;
- disabled states;
- permission-restricted states.

Static screenshots alone are not sufficient to define all interactions.

---

# 82. Design source-of-truth philosophy

Visual references and mockups are useful for establishing direction.

The durable source of truth should eventually consist of:

```text
PROJECT_UI.md / DESIGN_SYSTEM.md
design tokens
responsive rules
RTL rules
component behavior rules
approved implemented component specimens
representative screen references
```

The implemented design system and representative screens become more authoritative than arbitrary new AI-generated mockups after the system is established.

---

# 83. What not to inherit from the old application

Do not carry over:

- giant controllers;
- giant models;
- generic Utility classes;
- duplicated accounting sources;
- mutable bank opening balances;
- independently stored financial balances;
- silent financial fallbacks;
- excessive menus;
- dozens of unused integrations;
- unnecessary pricing intelligence;
- generic ERP modules;
- premature complexity.

Use the old application only as a feature/lesson reference.

---

# 84. Final product rule

Every important business fact should be recorded once at its natural source.

Examples:

- actual sale price lives on the sales line;
- actual purchase price lives on the purchase line;
- vendor is known from the purchase;
- customer is known from the sale;
- exchange rate lives on the transaction;
- expiry date belongs to its stock lot;
- stock movement identifies its source document;
- accounting posting identifies its source transaction.

Reports derive knowledge from these facts.

Do not build duplicate intelligence systems when historical transactions already contain the answer.

---

# 85. Definition of success

The product succeeds when a non-accountant can comfortably operate it from a phone, tablet or PC and understand the business immediately.

Underneath the interface:

```text
every amount reconciles
every stock unit is traceable
every expiry-sensitive quantity is traceable
every historical purchase price is recoverable
every historical selling price is recoverable
every vendor relationship can be reconstructed
every currency conversion is reproducible
every posted financial event is auditable
every company is isolated
every report derives from authoritative data
```

That is the product standard.
