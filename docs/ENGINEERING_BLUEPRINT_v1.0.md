# Small Trader Accounting
## Engineering Blueprint & Implementation Source of Truth
### Version 1.0 — September 2026

> **Status:** Approved engineering blueprint for greenfield implementation  
> **Parent specification:** `SMALL_TRADER_ACCOUNTING_MASTER_SPEC_v1.0.md` (the product/master specification agreed in this conversation)  
> **Purpose:** Convert the product specification into an implementation architecture that an AI coding agent can follow without inventing core financial, inventory, tenancy, security, or deployment rules.

---

# 0. Authority and precedence

This file is the engineering source of truth for the new application.

When implementation choices conflict, use this precedence:

1. Financial and inventory correctness invariants in this file.
2. Security and tenant-isolation rules in this file.
3. The approved product/master specification.
4. Tests that encode the approved behavior.
5. Module architecture and conventions in this file.
6. Framework conventions.
7. Agent preference.

An AI agent must not “simplify” an invariant because another implementation appears easier.

If the specification is ambiguous, the agent should prefer:

- preserving financial history;
- explicit data over inferred mutable state;
- database constraints over comments;
- derived status over duplicated truth;
- simple UI over simple accounting internals;
- reversible changes over destructive changes;
- shared-hosting-safe solutions over infrastructure-heavy solutions.

---

# 1. Product boundary

The application is a **small-trader business, inventory and accounting system**.

It is intended for businesses that:

- buy products from vendors;
- keep stock;
- sell products to customers;
- often edit prices per transaction;
- buy the same product from different vendors at different prices;
- may track expiry dates for selected products;
- work in ILS, USD and JOD;
- use Cash, Bank and Checks;
- receive and make partial payments;
- incur business expenses;
- may have employees and salary obligations;
- need quotations, invoices, receipts, statements, catalogs, PDFs and printable reports;
- primarily use ordinary business language rather than accounting terminology.

The application is **not** intended to be:

- an ERP;
- an Odoo clone;
- a full HR/payroll suite;
- a manufacturing/MRP system;
- a tax-compliance engine for every jurisdiction;
- a bank-feed platform in V1;
- an e-commerce platform;
- a generic CRM;
- an AI-first product;
- a microservice system.

The UI is simple. The financial and stock engines are strict.

---

# 2. Locked architectural decisions

These decisions are considered resolved unless a future written architecture decision record explicitly replaces them.

## 2.1 Greenfield application

Build a new Laravel application.

The previous accounting repository may be consulted for:

- feature ideas;
- terminology;
- edge cases;
- historical lessons.

Do not copy its accounting architecture, giant controllers, utility classes, duplicated balances or legacy payment integrations.

## 2.2 Modular monolith

Use one Laravel application and one primary MySQL database.

No microservices.

Module boundaries exist in code and tests, not in separate servers.

## 2.3 Laravel + Livewire

Primary application stack:

- Laravel 13;
- PHP 8.4 baseline;
- MySQL 8-compatible database;
- Laravel Livewire 4;
- Blade;
- Tailwind CSS 4;
- Flux UI components included with the Laravel Livewire starter kit where appropriate;
- Vite for static frontend build;
- Alpine only through/with Livewire where needed.

React is not used for V1.

The architecture may expose APIs later for mobile apps or third parties.

## 2.4 Shared-hosting compatible

Production must run on Hostinger shared hosting without requiring:

- Docker;
- Redis;
- a permanent Node process;
- Supervisor;
- WebSockets;
- Elasticsearch;
- RabbitMQ;
- a permanently running queue worker.

Allowed:

- PHP;
- MySQL;
- static Vite build output;
- Laravel scheduler via cron;
- short-lived database queue workers via cron;
- local filesystem storage;
- later S3-compatible storage.

## 2.5 One company now, SaaS-compatible later

Initial UX assumes one company.

The data model is tenant-aware from the first migration.

Every tenant-owned table contains `company_id`.

A user may eventually belong to multiple companies and sign in through the same login URL.

## 2.6 Company-scoped RBAC

Use `spatie/laravel-permission` with Teams enabled and `company_id` as the team foreign key.

Roles are company-scoped.

A user may be:

- Owner in Company A;
- Viewer in Company B.

Permissions from one company must never leak to another.

## 2.7 Hidden double-entry ledger

The user sees ordinary concepts such as:

- Sale;
- Purchase;
- Cash;
- Bank;
- Checks;
- Expense;
- Customer owes you;
- You owe vendor.

Internally, every financial event posts to a balanced double-entry general ledger.

The canonical financial truth is the ledger.

## 2.8 Moving weighted-average inventory costing

Inventory uses moving weighted-average cost in company base currency.

Inventory valuation and COGS use this cost.

Selling price is independent.

## 2.9 Optional expiry/lot tracking

Expiry tracking is enabled per product.

Products without expiry tracking do not incur lot-selection complexity.

Products with expiry tracking use lots and FEFO allocation by default.

## 2.10 Transaction history is price history

No negotiation engine.

No vendor-pricing AI.

No separate historical-price subsystem is required.

Actual purchase and sales lines are authoritative historical prices.

The UI may surface that history conveniently.

## 2.11 Barcode-ready, scanner workflow later

Store one or more barcodes from the start.

Product and invoice/purchase search must accept barcode text.

Advanced camera/scanner workflows can be implemented later.

## 2.12 Immutable posted history

Draft documents may be edited.

Posted financial/stock documents are not silently rewritten.

Corrections use:

- return;
- reversal;
- void;
- cancellation;
- replacement.

## 2.13 MySQL is the integration-test authority

Unit tests may run without a database.

Financial, tenancy, locking, migration, document numbering, concurrency and inventory integration tests must run against MySQL.

SQLite passing is not sufficient evidence for release.

---

# 3. Technology and dependency policy

## 3.1 Core runtime

```text
PHP:              ^8.4
Laravel:          ^13.0
Livewire:         ^4.0
Database:         MySQL 8 compatible
Node/npm:         build-time only
Frontend runtime: compiled static assets + Livewire
```

Laravel 13 requires PHP 8.3 or newer. PHP 8.4 is selected as the baseline because it is current, supported and compatible with the target hosting model.

## 3.2 Approved first-party Laravel facilities

Prefer framework facilities before adding packages:

- authentication/session;
- Fortify through starter kit;
- policies/gates;
- validation/form requests;
- queues;
- scheduler;
- notifications;
- filesystem/Flysystem;
- cache;
- rate limiting;
- database transactions;
- pessimistic locking;
- encrypted casts;
- ULIDs where useful;
- localization;
- mail;
- HTTP client.

## 3.3 Approved dependencies

Install dependencies only when their responsibility is clear.

### Required early

```text
livewire/livewire:^4.0
spatie/laravel-permission:^8.0
brick/math
larastan/larastan
```

The Laravel Livewire starter kit supplies the first-party starter scaffolding and Flux components used by the starter.

### Required when corresponding module begins

```text
intervention/image
endroid/qr-code
mpdf/mpdf
```

### Optional later

```text
league/flysystem-aws-s3-v3
```

only when S3-compatible storage is actually configured.

Do not install a package merely because an agent prefers it.

## 3.4 Money arithmetic dependency

Use `brick/math` `BigDecimal` for deterministic calculations.

Application code must not use binary floating-point values for:

- money;
- exchange rates;
- quantities used in valuation;
- tax;
- discounts;
- payment allocation;
- COGS.

Decimal database values are read/written as strings or value objects.

## 3.5 PDF

Use mPDF for V1 because:

- PHP-only runtime;
- no Chromium service required;
- suitable for shared hosting;
- established RTL/Arabic support.

PDF generation must use a dedicated render service and document view models.

Controllers and Livewire components do not call mPDF directly.

## 3.6 QR

Use a server-side QR library.

A QR normally contains a secure public URL, not raw financial data.

## 3.7 Images

Use Intervention Image or equivalent abstraction.

Default driver should be GD unless production explicitly confirms Imagick.

All public product photos are re-encoded.

Never trust client filename extensions.

---

# 4. Repository structure

Target structure:

```text
app/
├── Application/
│   ├── Accounting/
│   ├── Catalog/
│   ├── Customers/
│   ├── Employees/
│   ├── Expenses/
│   ├── Inventory/
│   ├── Money/
│   ├── Purchasing/
│   ├── Reporting/
│   ├── Sales/
│   ├── Sharing/
│   ├── Tenancy/
│   └── Vendors/
│
├── Domain/
│   ├── Accounting/
│   │   ├── Actions/
│   │   ├── Enums/
│   │   ├── Exceptions/
│   │   ├── Services/
│   │   ├── ValueObjects/
│   │   └── Contracts/
│   ├── Inventory/
│   ├── Money/
│   ├── Sales/
│   ├── Purchasing/
│   └── ...
│
├── Http/
│   ├── Controllers/
│   ├── Middleware/
│   ├── Requests/
│   └── Resources/
│
├── Livewire/
│   ├── Pages/
│   ├── Forms/
│   └── Components/
│
├── Models/
├── Policies/
├── Providers/
├── Rules/
└── Support/
    ├── Audit/
    ├── Documents/
    ├── Files/
    ├── Localization/
    └── Tenancy/

database/
├── factories/
├── migrations/
└── seeders/

resources/
├── css/
├── js/
├── lang/
│   ├── ar/
│   └── en/
└── views/
    ├── components/
    ├── documents/
    ├── livewire/
    └── reports/

tests/
├── Unit/
├── Feature/
├── Integration/
├── Architecture/
└── Scenarios/
```

The exact directory tree may evolve, but responsibilities may not collapse back into large generic classes.

---

# 5. Module dependency rules

Recommended dependency direction:

```text
UI / HTTP / Livewire
        ↓
Application actions / queries
        ↓
Domain services / value objects
        ↓
Models / persistence abstractions
        ↓
Database
```

Cross-module calls should occur through named application/domain services.

Examples:

Sales posting may call:

```text
InventoryPostingService
AccountingPostingService
```

It should not manually edit warehouse balances or ledger rows.

Forbidden examples:

```php
// Forbidden inside a Livewire component
PostingLine::create(...);

// Forbidden inside SalesInvoiceController
InventoryBalance::where(...)->decrement(...);

// Forbidden inside Blade
$total = $qty * $price * $exchangeRate;
```

---

# 6. Core request context

Every authenticated business request must resolve:

```text
Authenticated User
Active Company
Locale
Permissions for Active Company
Company Settings
```

Implement an explicit `CompanyContext` service.

Conceptual API:

```php
final class CompanyContext
{
    public function company(): Company;
    public function companyId(): int;
    public function user(): User;
}
```

Do not repeatedly derive the active tenant from arbitrary request parameters.

---

# 7. Active-company rules

## Initial single-company behavior

If a user belongs to exactly one active company:

- select it automatically after login;
- store active company in session.

## Future multi-company behavior

If a user belongs to multiple companies:

- use stored last active company when valid;
- otherwise ask user to choose;
- provide a company switch action.

Switching company must:

1. verify membership;
2. update session active company;
3. set Spatie permission team ID;
4. unset cached user `roles` / `permissions` relationships;
5. flush relevant company-scoped cached settings;
6. redirect to safe company landing page.

---

# 8. Tenant-aware model rules

Create a reusable trait such as:

```text
BelongsToCompany
```

Responsibilities:

- apply company scope when `CompanyContext` is available;
- populate `company_id` on creation;
- prevent changing `company_id` after persistence.

However:

> Global scoping is defense-in-depth, not the sole authorization mechanism.

Policies and domain services must still verify company ownership.

Queued jobs must receive `company_id` explicitly and initialize company context before company-owned queries.

CLI/report commands must require explicit company selection.

---

# 9. Authentication

Use the Laravel 13 Livewire starter kit and Laravel Fortify-backed authentication.

Enable:

- login;
- logout;
- password reset;
- email verification where email delivery is configured;
- password confirmation for sensitive actions;
- 2FA for privileged users.

Disable public registration for the initial private deployment.

Users are created/invited by the Owner/Admin.

Future SaaS signup can enable an onboarding workflow without replacing authentication.

Login rate limiting must remain enabled.

Session must regenerate on authentication.

Sensitive actions should require recent password confirmation where appropriate:

- changing owner;
- changing security settings;
- exporting all data;
- destructive account deactivation;
- disabling 2FA;
- rotating share-related secrets.

---

# 10. RBAC implementation

Use `spatie/laravel-permission` v8 teams support.

Before package migrations:

```php
'teams' => true,
'team_foreign_key' => 'company_id',
```

must be configured.

A middleware must call:

```php
setPermissionsTeamId($companyId);
```

before authorization checks and route model binding where required.

With Livewire, company/team middleware must be configured as persistent middleware where necessary.

## Default roles

Seed:

```text
Owner
Administrator
Manager
Sales
Purchasing
Warehouse
Cashier
Viewer
```

Roles may be customized.

## Permission naming convention

Use:

```text
domain.resource.action
```

Examples:

```text
sales.invoice.view
sales.invoice.create
sales.invoice.edit_draft
sales.invoice.post
sales.invoice.void
sales.invoice.change_price
sales.invoice.change_discount

purchasing.purchase.view
purchasing.purchase.create
purchasing.purchase.post

inventory.stock.view
inventory.stock.adjust
inventory.stock.transfer
inventory.cost.view

customers.view
customers.manage
vendors.view
vendors.manage

money.cash.view
money.bank.view
money.receipt.create
money.vendor_payment.create
money.check.manage

reports.sales.view
reports.profit.view
reports.cost.view

settings.company.manage
settings.users.manage
settings.roles.manage
settings.accounting.manage
```

Do not create vague permissions such as `manage everything`.

## Owner

Owner is a company-scoped role.

Owner is not an application-global bypass.

If a future platform super-admin is added, it must be architecturally separate and should not casually impersonate company users without audit.

---

# 11. Localization and RTL

Supported interface locales initially:

```text
ar
en
```

Arabic is default.

All user-visible strings must come from translations except:

- customer-entered text;
- vendor-entered text;
- product data;
- document free text.

Store internal statuses as stable English machine keys.

Example:

```text
draft
posted
void
```

Translate at presentation.

Do not store translated statuses in database.

## RTL details

When locale is Arabic:

- root HTML `dir="rtl"`;
- layout direction RTL;
- navigation mirrors appropriately;
- numeric identifiers, money fields, phone numbers, barcodes and SKUs remain readable and usually LTR at field/content level;
- mixed Arabic/English product text must be tested.

PDF templates must explicitly control direction rather than assuming browser CSS behavior maps perfectly to mPDF.

---

# 12. Responsive design system

The application is designed for mobile, tablet and desktop from the first UI component.

## Mobile-first rules

- base layout targets small screens;
- enhancements occur at larger breakpoints;
- primary actions must remain visible/reachable;
- forms use one column unless a paired field clearly benefits from two;
- money and quantity inputs use appropriate numeric keyboards;
- touch targets target roughly 44×44 CSS px minimum where practical;
- no critical workflow requires hover.

## Tables

Never solve mobile tables by shrinking text until unreadable.

For operational lists:

- desktop/tablet: table;
- narrow mobile: card/list representation or carefully selected columns with expandable details.

Dense analytical tables may allow controlled horizontal scroll, but primary actions remain fixed/visible.

## Desktop

Use available space for:

- side-by-side summaries;
- persistent filters;
- dense reporting;
- sortable tables.

## Accessibility

Target WCAG 2.2 AA where practical:

- visible focus states;
- semantic labels;
- keyboard usability;
- sufficient contrast;
- status not communicated only through color;
- logical heading structure.

---

# 13. Data-type standards

## Primary keys

Use:

```text
BIGINT UNSIGNED AUTO_INCREMENT
```

for internal primary keys.

For business records exposed in URLs, also use:

```text
public_id CHAR(26)
```

containing a ULID.

Unique index:

```text
UNIQUE(public_id)
```

Do not use sequential IDs in public share links.

## Timestamps

Use Laravel timestamps:

```text
created_at
updated_at
```

stored as UTC datetimes.

Use `deleted_at` only for safely archivable master data.

Financial records do not use soft-delete as a substitute for reversal.

## Dates

Business dates use `DATE`:

- invoice_date;
- due_date;
- purchase_date;
- expiry_date;
- salary period boundaries.

Actual event timestamps use UTC datetime.

## Money

Storage:

```text
DECIMAL(20,6)
```

unless a narrower field is clearly appropriate.

Application arithmetic:

```text
BigDecimal / value objects
```

No PHP float.

## Exchange rates

Define one universal convention:

> `exchange_rate` = number of **company base currency units** per 1 transaction-currency unit.

Example:

```text
Company base = ILS
1 USD = 3.30 ILS
exchange_rate = 3.30
```

Then:

```text
base_amount = foreign_amount × exchange_rate
```

If transaction currency equals company base currency:

```text
exchange_rate = 1
```

Storage:

```text
DECIMAL(20,10)
```

## Quantities

Use:

```text
DECIMAL(20,6)
```

Do not assume every product is integer-counted.

This supports:

- kg;
- liter;
- meter;
- fractional packs where allowed.

## Percentage/rates

Use explicit decimal scale.

Tax rate example:

```text
16.000000
```

Discount percentages should never be represented as float.

---

# 14. PHP value objects

Implement early.

## MoneyAmount

Contains:

```text
amount: BigDecimal
currencyCode: CurrencyCode
```

Responsibilities:

- scale/rounding;
- addition/subtraction only when currencies match;
- comparison;
- serialization.

## DecimalAmount

For:

- quantity;
- conversion factor;
- percentages where a specific type is not required.

## ExchangeRate

Stores:

- base currency;
- quote/transaction currency;
- rate;
- effective date/source if needed.

## CurrencyCode

Validated against enabled/known currencies.

## Quantity

Stores base decimal quantity and provides explicit conversion.

Avoid generic arrays for critical financial primitives.

---

# 15. Rounding rules

Rounding policy must be centralized.

## Currency minor units

Initial:

```text
ILS = 2
USD = 2
JOD = 3
```

## Unit prices

May retain up to 6 decimal places internally.

## Line calculations

Recommended default:

1. calculate line quantity × unit price using high precision;
2. apply line discount;
3. apply line tax rule;
4. round document currency line money amounts to the document currency minor-unit scale;
5. aggregate rounded line amounts;
6. calculate document totals deterministically.

Do not round with binary float.

## Base-currency conversion

Convert posted transaction amounts using stored exchange rate and round base posting amounts to base currency minor-unit scale.

Any required residual from deterministic allocation is assigned by an explicit residual rule, normally to the final allocation line, never silently lost.

---

# 16. Database schema overview

The following is the target logical schema.

Exact migration filenames are agent-generated chronologically, but table responsibilities and critical columns are locked.

---

# 17. Identity and tenancy tables

## 17.1 `users`

```text
id BIGINT PK
public_id CHAR(26) UNIQUE
name VARCHAR(255)
email VARCHAR(255) UNIQUE
email_verified_at DATETIME NULL
password VARCHAR(255)
locale VARCHAR(5) DEFAULT 'ar'
last_active_company_id BIGINT NULL
remember_token
created_at
updated_at
```

Fortify/starter-kit 2FA columns may be added according to the starter kit's current implementation.

`last_active_company_id` is convenience only and must be validated against membership before use.

## 17.2 `companies`

```text
id BIGINT PK
public_id CHAR(26) UNIQUE
name_ar VARCHAR(255)
name_en VARCHAR(255) NULL
legal_name_ar VARCHAR(255) NULL
legal_name_en VARCHAR(255) NULL
base_currency_code CHAR(3)
default_locale VARCHAR(5) DEFAULT 'ar'
timezone VARCHAR(64) DEFAULT 'Asia/Hebron'
phone VARCHAR(64) NULL
whatsapp VARCHAR(64) NULL
email VARCHAR(255) NULL
website VARCHAR(255) NULL
address_ar TEXT NULL
address_en TEXT NULL
registration_number VARCHAR(128) NULL
tax_number VARCHAR(128) NULL
logo_path VARCHAR(512) NULL
stamp_path VARCHAR(512) NULL
status VARCHAR(32) DEFAULT 'active'
created_at
updated_at
```

Do not store arbitrary secrets on this table.

## 17.3 `company_user`

```text
company_id BIGINT FK companies
user_id BIGINT FK users
status VARCHAR(32) DEFAULT 'active'
is_owner BOOLEAN DEFAULT false
joined_at DATETIME
last_accessed_at DATETIME NULL
created_at
updated_at

PRIMARY/UNIQUE(company_id, user_id)
INDEX(user_id, status)
```

`is_owner` is membership metadata; authorization still uses company-scoped Owner role.

## 17.4 `company_languages`

```text
company_id BIGINT FK
locale VARCHAR(5)
enabled BOOLEAN
is_default BOOLEAN
created_at
updated_at

UNIQUE(company_id, locale)
```

At least Arabic remains enabled initially unless product requirements later permit English-only companies.

---

# 18. Currencies

## 18.1 `currencies`

Seed reference table:

```text
code CHAR(3) PK
name VARCHAR(64)
symbol VARCHAR(16)
minor_units TINYINT
active BOOLEAN
```

Seed:

```text
ILS / 2
USD / 2
JOD / 3
```

## 18.2 `company_currencies`

```text
company_id BIGINT FK
currency_code CHAR(3) FK
enabled BOOLEAN
is_base BOOLEAN
display_order SMALLINT
created_at
updated_at

UNIQUE(company_id, currency_code)
```

Exactly one enabled currency is base.

Enforce in service + test.

## 18.3 `exchange_rates`

```text
id BIGINT PK
company_id BIGINT FK
base_currency_code CHAR(3)
currency_code CHAR(3)
rate DECIMAL(20,10)
effective_at DATETIME
source VARCHAR(32) DEFAULT 'manual'
created_by BIGINT FK users
created_at
updated_at

INDEX(company_id, currency_code, effective_at)
```

Base currency rate may be implied as 1 and does not require rows.

Historical documents always copy their own rate.

Editing/deleting a suggested exchange-rate row never changes posted documents.

---

# 19. Settings

Avoid one untyped `settings` dump for critical behavior.

Use explicit settings tables by concern.

## 19.1 `company_inventory_settings`

```text
company_id BIGINT PK/FK
allow_negative_stock BOOLEAN DEFAULT false
default_warehouse_id BIGINT NULL
default_cost_method VARCHAR(32) DEFAULT 'moving_average'
default_expiry_warning_days INT DEFAULT 30
created_at
updated_at
```

Only `moving_average` is implemented in V1 even if field anticipates extension.

## 19.2 `company_document_settings`

```text
company_id BIGINT PK/FK
default_document_locale VARCHAR(5)
show_logo BOOLEAN DEFAULT true
show_qr_by_default BOOLEAN DEFAULT false
show_product_images_on_quotes BOOLEAN DEFAULT false
invoice_footer_ar TEXT NULL
invoice_footer_en TEXT NULL
quotation_terms_ar TEXT NULL
quotation_terms_en TEXT NULL
created_at
updated_at
```

## 19.3 `company_security_settings`

Keep non-secret switches only:

```text
company_id BIGINT PK/FK
require_2fa_for_owner BOOLEAN DEFAULT true
require_2fa_for_admin BOOLEAN DEFAULT false
public_share_default_expiry_days INT NULL
created_at
updated_at
```

Secrets remain environment/encrypted storage.

## 19.4 Generic preferences

If later needed for harmless UI preferences, a typed key/value table may exist, but financial behavior must not be hidden in arbitrary JSON without validation.

---

# 20. Document numbering

## `document_sequences`

```text
id BIGINT PK
company_id BIGINT FK
document_type VARCHAR(64)
prefix VARCHAR(32)
year INT NULL
next_number BIGINT
padding TINYINT DEFAULT 5
reset_policy VARCHAR(32) DEFAULT 'yearly'
created_at
updated_at

UNIQUE(company_id, document_type, year)
```

Assignment algorithm:

```text
DB transaction
→ select sequence row FOR UPDATE
→ capture next number
→ increment next number
→ build final document number
→ persist document
→ commit
```

Final sales invoice and purchase numbers are assigned at posting, not on first blank draft creation.

Once assigned, a number is never reused.

Voiding a posted document preserves its number.

---

# 21. Customers

## `customers`

```text
id BIGINT PK
public_id CHAR(26) UNIQUE
company_id BIGINT FK
code VARCHAR(64) NULL
name_ar VARCHAR(255)
name_en VARCHAR(255) NULL
business_name_ar VARCHAR(255) NULL
business_name_en VARCHAR(255) NULL
phone VARCHAR(64) NULL
whatsapp VARCHAR(64) NULL
email VARCHAR(255) NULL
address_ar TEXT NULL
address_en TEXT NULL
preferred_locale VARCHAR(5) NULL
default_currency_code CHAR(3) NULL
credit_limit DECIMAL(20,6) NULL
notes TEXT NULL
status VARCHAR(32) DEFAULT 'active'
created_by BIGINT FK users
updated_by BIGINT FK users NULL
created_at
updated_at
deleted_at NULL

INDEX(company_id, status)
INDEX(company_id, name_ar)
INDEX(company_id, phone)
UNIQUE(company_id, code) when code not null where supported by application semantics
```

Opening receivable is not stored as a mutable customer balance.

Initial opening balances are posted through an opening-balance transaction.

---

# 22. Vendors

## `vendors`

Mirrors customer concepts:

```text
id
public_id
company_id
code
name_ar
name_en
business_name_ar
business_name_en
phone
whatsapp
email
address_ar
address_en
preferred_locale
default_currency_code
notes
status
created_by
updated_by
timestamps
deleted_at
```

Vendor payable balance is derived from posted purchase/payable activity.

---

# 23. Product taxonomy

## 23.1 `product_categories`

```text
id BIGINT PK
company_id BIGINT FK
parent_id BIGINT NULL
name_ar VARCHAR(255)
name_en VARCHAR(255) NULL
active BOOLEAN
sort_order INT
timestamps
deleted_at
```

## 23.2 `brands`

Optional simple master:

```text
id
company_id
name_ar
name_en
active
timestamps
deleted_at
```

Brand is not required for every product.

---

# 24. Units

## 24.1 `units`

Company-defined master:

```text
id BIGINT PK
company_id BIGINT FK
code VARCHAR(32)
name_ar VARCHAR(128)
name_en VARCHAR(128) NULL
allows_fraction BOOLEAN DEFAULT false
active BOOLEAN DEFAULT true
timestamps

UNIQUE(company_id, code)
```

Seed common units per company:

- piece;
- carton;
- box;
- pack;
- bottle;
- kg;
- g;
- liter;
- meter.

The company may add more.

---

# 25. Products

## 25.1 `products`

```text
id BIGINT PK
public_id CHAR(26) UNIQUE
company_id BIGINT FK
sku VARCHAR(128) NULL
name_ar VARCHAR(255)
name_en VARCHAR(255) NULL
description_ar TEXT NULL
description_en TEXT NULL
product_type VARCHAR(32)   // stock, non_stock, service
category_id BIGINT NULL
brand_id BIGINT NULL
base_unit_id BIGINT FK
track_stock BOOLEAN
track_expiry BOOLEAN
minimum_stock_base DECIMAL(20,6) NULL
active BOOLEAN DEFAULT true
created_by BIGINT
updated_by BIGINT NULL
timestamps
deleted_at

UNIQUE(company_id, sku) when non-null by application + database-compatible index strategy
INDEX(company_id, active)
INDEX(company_id, category_id)
```

Rules:

- service => `track_stock=false`;
- non-stock => `track_stock=false`;
- only stock products may track expiry;
- `track_expiry=true` implies `track_stock=true`.

Do not toggle expiry tracking off if active lot-tracked quantity exists unless a controlled migration action handles it.

## 25.2 `product_units`

```text
id BIGINT PK
company_id BIGINT FK
product_id BIGINT FK
unit_id BIGINT FK
conversion_to_base DECIMAL(20,6)
is_default_purchase BOOLEAN
is_default_sale BOOLEAN
default_purchase_price_base DECIMAL(20,6) NULL
default_sale_price_base DECIMAL(20,6) NULL
active BOOLEAN
timestamps

UNIQUE(product_id, unit_id)
INDEX(company_id, product_id)
```

`conversion_to_base`:

```text
1 carton = 12 pieces
conversion_to_base = 12
```

The base unit has conversion 1.

Default prices are suggestions in company base currency, not contractual price lists.

When a foreign-currency document is created, the UI may convert the suggested base price using the selected document exchange rate; user remains free to edit it.

## 25.3 `product_barcodes`

```text
id BIGINT PK
company_id BIGINT FK
product_id BIGINT FK
unit_id BIGINT NULL FK
barcode VARCHAR(128)
is_primary BOOLEAN DEFAULT false
timestamps

UNIQUE(company_id, barcode)
INDEX(company_id, product_id)
```

A carton may therefore have a barcode distinct from the piece barcode.

## 25.4 `product_images`

```text
id BIGINT PK
company_id BIGINT FK
product_id BIGINT FK
disk VARCHAR(32)
path VARCHAR(512)
thumbnail_path VARCHAR(512) NULL
mime_type VARCHAR(128)
width INT NULL
height INT NULL
file_size BIGINT
is_primary BOOLEAN
sort_order INT
created_by BIGINT
timestamps

INDEX(company_id, product_id)
```

No original user filename is used as the storage filename.

---

# 26. Warehouses

## `warehouses`

```text
id BIGINT PK
public_id CHAR(26) UNIQUE
company_id BIGINT FK
code VARCHAR(64)
name_ar VARCHAR(255)
name_en VARCHAR(255) NULL
address_ar TEXT NULL
address_en TEXT NULL
active BOOLEAN
created_by BIGINT
timestamps
deleted_at

UNIQUE(company_id, code)
INDEX(company_id, active)
```

At least one default warehouse is created during company bootstrap.

---

# 27. Inventory lots

Only relevant to expiry/lot-tracked stock.

## `inventory_lots`

```text
id BIGINT PK
public_id CHAR(26) UNIQUE
company_id BIGINT FK
product_id BIGINT FK
lot_number VARCHAR(128) NULL
expiry_date DATE NULL
received_date DATE
source_type VARCHAR(64)
source_id BIGINT
source_line_id BIGINT NULL
created_at
updated_at

INDEX(company_id, product_id, expiry_date)
INDEX(company_id, expiry_date)
INDEX(company_id, lot_number)
```

A receipt/purchase line may create a new lot even if the external lot number matches another receipt. This preserves source traceability and avoids relying on vendor lot-number uniqueness.

---

# 28. Inventory balances

These are rebuildable caches, not authoritative history.

## 28.1 `inventory_balances`

Per product + warehouse:

```text
id BIGINT PK
company_id BIGINT FK
product_id BIGINT FK
warehouse_id BIGINT FK
quantity_base DECIMAL(20,6)
updated_at

UNIQUE(company_id, product_id, warehouse_id)
```

## 28.2 `inventory_lot_balances`

```text
id BIGINT PK
company_id BIGINT FK
lot_id BIGINT FK
warehouse_id BIGINT FK
quantity_base DECIMAL(20,6)
updated_at

UNIQUE(company_id, lot_id, warehouse_id)
INDEX(company_id, warehouse_id)
```

## 28.3 `inventory_cost_states`

Company-wide moving average per product:

```text
id BIGINT PK
company_id BIGINT FK
product_id BIGINT FK
quantity_base DECIMAL(20,6)
average_cost_base DECIMAL(20,6)
inventory_value_base DECIMAL(20,6)
updated_at

UNIQUE(company_id, product_id)
```

This is a performance state/cached current valuation.

Historical movements remain the audit source.

A rebuild command must be possible.

---

# 29. Stock movement ledger

## `stock_movements`

Every physical stock change produces one or more immutable movement rows.

```text
id BIGINT PK
public_id CHAR(26) UNIQUE
company_id BIGINT FK
product_id BIGINT FK
warehouse_id BIGINT FK
lot_id BIGINT NULL FK
movement_type VARCHAR(64)
movement_date DATE
quantity_delta_base DECIMAL(20,6)
unit_cost_base DECIMAL(20,6)
value_delta_base DECIMAL(20,6)
average_cost_after DECIMAL(20,6)
quantity_after_product_company DECIMAL(20,6)
source_type VARCHAR(64)
source_id BIGINT
source_line_id BIGINT NULL
reversal_of_id BIGINT NULL
created_by BIGINT FK
created_at

INDEX(company_id, product_id, movement_date)
INDEX(company_id, warehouse_id, movement_date)
INDEX(company_id, lot_id, movement_date)
INDEX(company_id, source_type, source_id)
```

Rules:

- no update/delete through normal application code;
- correction creates reversal movement(s);
- `quantity_delta_base` positive for inbound, negative for outbound;
- outbound sale uses moving average cost at posting;
- sales return linked to original sale should restore original COGS unit cost;
- purchase return linked to original purchase uses traceable original cost rule;
- warehouse transfers do not create profit or change company-wide average cost;
- lot balance and warehouse balance update in same DB transaction.

---

# 30. Moving-average algorithm

Costing scope is company-wide per product.

Warehouses track quantity location, not separate cost methods.

## Purchase inbound

Before:

```text
old_qty
old_value
```

Inbound:

```text
in_qty
in_value_base
```

After:

```text
new_qty = old_qty + in_qty
new_value = old_value + in_value_base
new_average = new_value / new_qty
```

when `new_qty != 0`.

## Sale outbound

Use current average cost:

```text
cogs = sold_qty × current_average
```

Reduce quantity and inventory value by COGS.

Average cost ordinarily remains unchanged after ordinary outbound sale.

## Sales return

If linked to original sale line/allocation:

- restore quantity;
- restore at the original COGS cost assigned to that sold quantity.

Do not use today's average blindly.

## Purchase return

Use the cost attributable to the returned purchase receipt when traceable.

The costing service recalculates state deterministically.

## Negative stock

Default prohibits it.

If company explicitly permits negative stock:

- negative-stock valuation rules must be separately specified and tested before enabling;
- MVP should keep negative stock disabled.

---

# 31. FEFO allocation

For expiry-tracked product outbound sale:

eligible lot balances:

```text
quantity > 0
expiry_date ASC NULLS LAST
received_date ASC
id ASC
```

The default suggested allocation consumes earliest expiry first.

If expiry date is NULL, those lots sort after known dates by default.

User may override lot selection only with appropriate stock permission.

Allocation must be locked in the sales posting transaction to avoid two simultaneous sales consuming the same lot quantity.

Use row-level `FOR UPDATE` locks on relevant lot balances.

---

# 32. Tax tables

Tax is optional but structurally supported.

## `tax_rates`

```text
id BIGINT PK
company_id BIGINT FK
code VARCHAR(32)
name_ar VARCHAR(128)
name_en VARCHAR(128) NULL
rate DECIMAL(12,6)
calculation VARCHAR(16) // inclusive | exclusive
active BOOLEAN
sales_tax_account_id BIGINT NULL
purchase_tax_account_id BIGINT NULL
timestamps

UNIQUE(company_id, code)
```

When tax is disabled, tax selectors disappear, but schema remains valid.

Posted document lines snapshot their tax rate.

---

# 33. Quotations

## 33.1 `quotations`

```text
id BIGINT PK
public_id CHAR(26) UNIQUE
company_id BIGINT FK
quotation_number VARCHAR(64)
customer_id BIGINT FK
status VARCHAR(32)
quotation_date DATE
expires_on DATE NULL
currency_code CHAR(3)
base_currency_code CHAR(3)
exchange_rate DECIMAL(20,10)
locale VARCHAR(5)
subtotal DECIMAL(20,6)
discount_total DECIMAL(20,6)
tax_total DECIMAL(20,6)
grand_total DECIMAL(20,6)
notes TEXT NULL
terms TEXT NULL
include_product_images BOOLEAN
created_by BIGINT
updated_by BIGINT NULL
sent_at DATETIME NULL
accepted_at DATETIME NULL
converted_invoice_id BIGINT NULL
timestamps

UNIQUE(company_id, quotation_number)
INDEX(company_id, customer_id, quotation_date)
INDEX(company_id, status)
```

Quotation is non-financial and may be edited while draft/sent according to workflow rules.

## 33.2 `quotation_lines`

```text
id BIGINT PK
company_id BIGINT FK
quotation_id BIGINT FK
line_number INT
product_id BIGINT NULL
description TEXT
sku_snapshot VARCHAR(128) NULL
product_name_snapshot VARCHAR(255)
unit_id BIGINT NULL
unit_name_snapshot VARCHAR(128)
unit_conversion_snapshot DECIMAL(20,6)
quantity DECIMAL(20,6)
unit_price DECIMAL(20,6)
discount_type VARCHAR(16) NULL
discount_value DECIMAL(20,6) NULL
discount_amount DECIMAL(20,6)
tax_rate_id BIGINT NULL
tax_rate_snapshot DECIMAL(12,6) NULL
tax_calculation_snapshot VARCHAR(16) NULL
tax_amount DECIMAL(20,6)
line_total DECIMAL(20,6)
sort_order INT
timestamps

INDEX(company_id, quotation_id)
```

Actual unit price is editable.

No pricing engine decides it.

---

# 34. Sales invoices

## 34.1 `sales_invoices`

```text
id BIGINT PK
public_id CHAR(26) UNIQUE
company_id BIGINT FK
invoice_number VARCHAR(64) NULL until posting
customer_id BIGINT FK
warehouse_id BIGINT FK
status VARCHAR(32) // draft, posted, void
invoice_date DATE
due_date DATE NULL
currency_code CHAR(3)
base_currency_code CHAR(3)
exchange_rate DECIMAL(20,10)
locale VARCHAR(5)

subtotal DECIMAL(20,6)
discount_total DECIMAL(20,6)
tax_total DECIMAL(20,6)
grand_total DECIMAL(20,6)
base_grand_total DECIMAL(20,6)

notes TEXT NULL
terms TEXT NULL

customer_snapshot JSON NULL
company_snapshot JSON NULL
document_options JSON NULL
template_version VARCHAR(32) NULL

quotation_id BIGINT NULL
posted_at DATETIME NULL
posted_by BIGINT NULL
voided_at DATETIME NULL
voided_by BIGINT NULL
void_reason TEXT NULL

created_by BIGINT
updated_by BIGINT NULL
timestamps

UNIQUE(company_id, invoice_number)
INDEX(company_id, customer_id, invoice_date)
INDEX(company_id, status, invoice_date)
INDEX(company_id, due_date)
```

`invoice_number` null while draft; assigned atomically at posting.

Payment status is derived from allocations:

```text
unpaid
partially_paid
paid
overpaid/credit if supported later
```

Do not store an independent `paid=true` truth.

## 34.2 `sales_invoice_lines`

```text
id BIGINT PK
company_id BIGINT FK
sales_invoice_id BIGINT FK
line_number INT
product_id BIGINT NULL
description TEXT

sku_snapshot VARCHAR(128) NULL
product_name_snapshot VARCHAR(255)
unit_id BIGINT NULL
unit_name_snapshot VARCHAR(128)
unit_conversion_snapshot DECIMAL(20,6)

quantity DECIMAL(20,6)
quantity_base DECIMAL(20,6)

unit_price DECIMAL(20,6)
discount_type VARCHAR(16) NULL
discount_value DECIMAL(20,6) NULL
discount_amount DECIMAL(20,6)

tax_rate_id BIGINT NULL
tax_rate_snapshot DECIMAL(12,6) NULL
tax_calculation_snapshot VARCHAR(16) NULL
tax_amount DECIMAL(20,6)

line_total DECIMAL(20,6)
base_line_total DECIMAL(20,6)

cogs_unit_base DECIMAL(20,6) NULL
cogs_total_base DECIMAL(20,6) NULL

sort_order INT
timestamps

INDEX(company_id, sales_invoice_id)
INDEX(company_id, product_id)
```

For stock items, COGS is assigned at posting.

Historical invoices retain product/unit snapshots even if master data changes.

## 34.3 `sales_invoice_lot_allocations`

For expiry-tracked sale quantities:

```text
id BIGINT PK
company_id BIGINT FK
sales_invoice_line_id BIGINT FK
inventory_lot_id BIGINT FK
warehouse_id BIGINT FK
quantity_base DECIMAL(20,6)
cogs_unit_base DECIMAL(20,6)
cogs_total_base DECIMAL(20,6)
timestamps

INDEX(company_id, sales_invoice_line_id)
INDEX(company_id, inventory_lot_id)
```

Allocation becomes immutable after invoice posting.

---

# 35. Sales returns

Use explicit documents.

## `sales_returns`

Header similar to invoice:

```text
id
public_id
company_id
return_number
customer_id
original_invoice_id NULL
warehouse_id
status
return_date
currency_code
exchange_rate
totals
reason
snapshots
posted_at/by
created_by
timestamps
```

## `sales_return_lines`

Each may reference:

```text
original_sales_invoice_line_id
```

When linked, restore inventory at original COGS rather than today's average.

Return can:

- reduce receivable;
- create customer credit;
- support refund as a separate money transaction.

Do not mutate original invoice totals.

---

# 36. Purchases

## 36.1 `purchases`

```text
id BIGINT PK
public_id CHAR(26) UNIQUE
company_id BIGINT FK
purchase_number VARCHAR(64) NULL until posting
vendor_id BIGINT FK
vendor_invoice_number VARCHAR(128) NULL
warehouse_id BIGINT FK
status VARCHAR(32)
purchase_date DATE
due_date DATE NULL
currency_code CHAR(3)
base_currency_code CHAR(3)
exchange_rate DECIMAL(20,10)
locale VARCHAR(5)

subtotal DECIMAL(20,6)
discount_total DECIMAL(20,6)
tax_total DECIMAL(20,6)
grand_total DECIMAL(20,6)
base_grand_total DECIMAL(20,6)

notes TEXT NULL

vendor_snapshot JSON NULL
company_snapshot JSON NULL
document_options JSON NULL
template_version VARCHAR(32) NULL

posted_at DATETIME NULL
posted_by BIGINT NULL
voided_at DATETIME NULL
voided_by BIGINT NULL
void_reason TEXT NULL

created_by BIGINT
updated_by BIGINT NULL
timestamps

UNIQUE(company_id, purchase_number)
INDEX(company_id, vendor_id, purchase_date)
INDEX(company_id, status, purchase_date)
INDEX(company_id, due_date)
INDEX(company_id, vendor_invoice_number)
```

## 36.2 `purchase_lines`

```text
id BIGINT PK
company_id BIGINT FK
purchase_id BIGINT FK
line_number INT
product_id BIGINT NULL
description TEXT

sku_snapshot VARCHAR(128) NULL
product_name_snapshot VARCHAR(255)
unit_id BIGINT NULL
unit_name_snapshot VARCHAR(128)
unit_conversion_snapshot DECIMAL(20,6)

quantity DECIMAL(20,6)
quantity_base DECIMAL(20,6)

unit_cost DECIMAL(20,6)
discount_type VARCHAR(16) NULL
discount_value DECIMAL(20,6) NULL
discount_amount DECIMAL(20,6)

tax_rate_id BIGINT NULL
tax_rate_snapshot DECIMAL(12,6) NULL
tax_calculation_snapshot VARCHAR(16) NULL
tax_amount DECIMAL(20,6)

line_total DECIMAL(20,6)
base_line_total DECIMAL(20,6)

landed_cost_allocated_base DECIMAL(20,6) DEFAULT 0
inventory_unit_cost_base DECIMAL(20,6) NULL

sort_order INT
timestamps

INDEX(company_id, purchase_id)
INDEX(company_id, product_id)
```

Actual purchase cost is editable.

Vendor price history comes directly from these lines.

## 36.3 Purchase expiry entries

For an expiry-tracked line, the received quantity may be split into multiple expiry lots.

Use:

### `purchase_line_lots`

```text
id BIGINT PK
company_id BIGINT FK
purchase_line_id BIGINT FK
lot_number VARCHAR(128) NULL
expiry_date DATE NULL
quantity DECIMAL(20,6)
quantity_base DECIMAL(20,6)
created_inventory_lot_id BIGINT NULL
timestamps

INDEX(company_id, purchase_line_id)
INDEX(company_id, expiry_date)
```

The sum of lot quantities must equal line quantity for products requiring lot/expiry tracking before posting.

---

# 37. Purchase returns

Explicit `purchase_returns` and `purchase_return_lines`.

Lines may reference original `purchase_line_id` and inventory lot(s).

A purchase return:

- reduces inventory;
- reduces vendor payable or creates vendor credit;
- uses traceable historical cost;
- does not edit original purchase.

---

# 38. Accounts receivable payments

Use explicit customer receipt tables rather than one ambiguous polymorphic payment table.

## 38.1 `customer_payments`

```text
id BIGINT PK
public_id CHAR(26) UNIQUE
company_id BIGINT FK
payment_number VARCHAR(64)
customer_id BIGINT FK
payment_date DATE

method VARCHAR(16) // cash, bank, check
money_account_id BIGINT NULL
check_id BIGINT NULL

currency_code CHAR(3)
base_currency_code CHAR(3)
amount DECIMAL(20,6)
exchange_rate DECIMAL(20,10)
base_amount DECIMAL(20,6)

reference VARCHAR(128) NULL
notes TEXT NULL

status VARCHAR(32) // posted, reversed
posted_at DATETIME
posted_by BIGINT
reversed_at DATETIME NULL
reversed_by BIGINT NULL
reversal_reason TEXT NULL

created_at

UNIQUE(company_id, payment_number)
INDEX(company_id, customer_id, payment_date)
```

Cash/bank payment requires a money account with matching currency.

Check payment creates/links an incoming check.

## 38.2 `customer_payment_allocations`

```text
id BIGINT PK
company_id BIGINT FK
customer_payment_id BIGINT FK
sales_invoice_id BIGINT FK

payment_currency_amount DECIMAL(20,6)
invoice_currency_amount DECIMAL(20,6)
base_amount_applied_to_receivable DECIMAL(20,6)
settlement_base_value DECIMAL(20,6)
realized_fx_gain_loss_base DECIMAL(20,6)

created_at

UNIQUE as appropriate to prevent accidental duplicate allocation rows
INDEX(company_id, sales_invoice_id)
```

Why both base values?

A foreign-currency invoice may have established AR at one historical base value while payment settlement occurs at another. The difference belongs in realized FX gain/loss.

Allocation service calculates this; UI does not.

A payment may be partly unallocated.

---

# 39. Accounts payable payments

Mirror customer payments explicitly.

## `vendor_payments`

Contains:

- vendor;
- date;
- method;
- cash/bank/check;
- currency;
- amount;
- exchange rate;
- base amount;
- status;
- immutable posted/reversal metadata.

## `vendor_payment_allocations`

Links payment to purchase(s) and calculates realized FX where applicable.

---

# 40. Money accounts

## `money_accounts`

Represents user-visible cash/bank holdings.

```text
id BIGINT PK
public_id CHAR(26) UNIQUE
company_id BIGINT FK
type VARCHAR(16) // cash | bank
name_ar VARCHAR(255)
name_en VARCHAR(255) NULL
currency_code CHAR(3)
ledger_account_id BIGINT FK
bank_name VARCHAR(255) NULL
account_reference VARCHAR(128) NULL
iban VARCHAR(128) NULL
active BOOLEAN
sort_order INT
created_by BIGINT
timestamps
deleted_at

INDEX(company_id, type, active)
INDEX(company_id, currency_code)
```

Do not store mutable `current_balance`.

Balance derives from ledger postings.

An optional cached balance may be added later but must be rebuildable.

Opening amount is posted through an Opening Balance operation.

---

# 41. Checks

Checks are a separate operational lifecycle because they are neither ordinary immediate cash nor bank settlement.

## 41.1 `checks`

```text
id BIGINT PK
public_id CHAR(26) UNIQUE
company_id BIGINT FK
direction VARCHAR(16) // incoming | outgoing

check_number VARCHAR(128)
currency_code CHAR(3)
amount DECIMAL(20,6)
exchange_rate DECIMAL(20,10)
base_amount DECIMAL(20,6)

customer_id BIGINT NULL
vendor_id BIGINT NULL

bank_name VARCHAR(255) NULL
drawer_or_payee VARCHAR(255) NULL

received_or_issued_date DATE
due_date DATE
status VARCHAR(32)

customer_payment_id BIGINT NULL
vendor_payment_id BIGINT NULL
settlement_money_account_id BIGINT NULL

notes TEXT NULL
image_path VARCHAR(512) NULL

created_by BIGINT
timestamps

INDEX(company_id, direction, status)
INDEX(company_id, due_date, status)
INDEX(company_id, check_number)
```

## 41.2 `check_events`

Append-only state history:

```text
id BIGINT PK
company_id BIGINT FK
check_id BIGINT FK
event_type VARCHAR(32)
from_status VARCHAR(32) NULL
to_status VARCHAR(32)
event_date DATE
money_account_id BIGINT NULL
notes TEXT NULL
created_by BIGINT
created_at

INDEX(company_id, check_id, created_at)
```

## Incoming lifecycle

Typical:

```text
received/in_hand
→ deposited
→ cleared
```

Alternatives:

```text
returned
cancelled
```

Accounting behavior is implemented as explicit transitions.

Receiving a check from customer may settle AR into `Checks in Hand`.

Depositing/clearing transfers its financial representation according to defined accounting rules.

A returned check must restore customer receivable where appropriate rather than merely changing a badge.

## Outgoing lifecycle

Typical:

```text
issued
→ cleared
```

Alternatives:

```text
returned
cancelled
```

Issuing may settle AP into `Checks Issued/Payable`; clearance moves liability to bank outflow.

Every transition is idempotent and audited.

---

# 42. Money transfers

## `money_transfers`

```text
id BIGINT PK
public_id CHAR(26)
company_id BIGINT

transfer_number VARCHAR(64)
transfer_date DATE
from_money_account_id BIGINT
to_money_account_id BIGINT

from_currency_code CHAR(3)
to_currency_code CHAR(3)

from_amount DECIMAL(20,6)
to_amount DECIMAL(20,6)

exchange_rate DECIMAL(20,10) NULL
base_value_from DECIMAL(20,6)
base_value_to DECIMAL(20,6)
fx_gain_loss_base DECIMAL(20,6)

status VARCHAR(32)
notes TEXT NULL

posted_at/by
reversed_at/by
timestamps
```

V1 may initially support same-currency transfers first.

Cross-currency exchange must not be implemented until its accounting tests are complete.

---

# 43. Expenses

Keep ordinary operating expenses simple.

## `expense_categories`

```text
id
company_id
code
name_ar
name_en
ledger_account_id
active
timestamps
```

## `expenses`

MVP assumption:

> The quick Expense workflow records an expense that is paid immediately through Cash, Bank or Check.

If the business owes a supplier and will pay later, that belongs in Purchases/Vendor Bills rather than pretending the expense is already paid.

```text
id BIGINT PK
public_id CHAR(26)
company_id BIGINT
expense_number VARCHAR(64)
expense_date DATE
category_id BIGINT
vendor_id BIGINT NULL
description TEXT

currency_code CHAR(3)
amount DECIMAL(20,6)
exchange_rate DECIMAL(20,10)
base_amount DECIMAL(20,6)

method VARCHAR(16)
money_account_id BIGINT NULL
check_id BIGINT NULL

attachment_path VARCHAR(512) NULL
notes TEXT NULL

status VARCHAR(32)
posted_at/by
reversed_at/by
timestamps

INDEX(company_id, expense_date)
INDEX(company_id, category_id, expense_date)
```

This separation keeps the quick workflow understandable.

---

# 44. Employees and payroll-lite

Phase after core MVP verticals.

## `employees`

```text
id
public_id
company_id
code
name
phone
job_title
hire_date
default_salary DECIMAL(20,6)
salary_currency_code CHAR(3)
active
notes
timestamps
deleted_at
```

## `salary_entries`

```text
id
public_id
company_id
employee_id
period_start DATE
period_end DATE
currency_code
exchange_rate
base_salary
bonus
deduction
advance_applied
net_salary
base_net_salary
status
posted_at/by
timestamps
```

Salary expense is recognized when salary entry is posted, even if unpaid.

## `salary_payments`

Separate payment records allocate toward salary entries.

This permits unpaid/partially-paid salary.

Do not build attendance, leave, tax withholding or full statutory payroll in V1.

---

# 45. Accounting chart

## `ledger_accounts`

System-managed chart is mostly hidden from ordinary users.

```text
id BIGINT PK
public_id CHAR(26)
company_id BIGINT
code VARCHAR(32)
system_key VARCHAR(64) NULL
name_ar VARCHAR(255)
name_en VARCHAR(255) NULL
account_type VARCHAR(32)
normal_balance VARCHAR(8)
parent_id BIGINT NULL
is_control BOOLEAN
is_system BOOLEAN
active BOOLEAN
timestamps

UNIQUE(company_id, code)
UNIQUE(company_id, system_key) where non-null by supported strategy
```

Required initial system keys:

```text
cash_control
bank_control
checks_in_hand
checks_issued
accounts_receivable
accounts_payable
inventory
cogs
sales_revenue
sales_returns
operating_expense_parent
salary_expense
salary_payable
inventory_loss
expiry_loss
fx_gain
fx_loss
opening_balance_equity
tax_output (when tax enabled)
tax_input (when tax enabled)
```

Money accounts may each have their own child ledger account under Cash/Bank controls.

No cross-company fallback lookup is ever allowed.

Missing required account configuration raises a domain exception and prevents posting.

---

# 46. Posting batches

## `posting_batches`

Canonical immutable accounting event header.

```text
id BIGINT PK
public_id CHAR(26) UNIQUE
company_id BIGINT FK

batch_number VARCHAR(64) NULL
posting_date DATE
status VARCHAR(32) // posted, reversed

source_type VARCHAR(64)
source_id BIGINT

currency_code CHAR(3)
base_currency_code CHAR(3)
exchange_rate DECIMAL(20,10)

description VARCHAR(512) NULL
idempotency_key VARCHAR(191)

posted_by BIGINT FK
posted_at DATETIME

reversal_of_id BIGINT NULL
reversed_by_batch_id BIGINT NULL

created_at

UNIQUE(company_id, idempotency_key)
INDEX(company_id, posting_date)
INDEX(company_id, source_type, source_id)
```

Once posted:

- no normal updates;
- no normal deletes.

A reversal creates a second balanced batch with opposite lines and links both.

---

# 47. Posting lines

## `posting_lines`

```text
id BIGINT PK
company_id BIGINT FK
posting_batch_id BIGINT FK
ledger_account_id BIGINT FK

line_number INT
description VARCHAR(512) NULL

debit_base DECIMAL(20,6) DEFAULT 0
credit_base DECIMAL(20,6) DEFAULT 0

transaction_currency_code CHAR(3) NULL
transaction_amount DECIMAL(20,6) NULL
exchange_rate DECIMAL(20,10) NULL

customer_id BIGINT NULL
vendor_id BIGINT NULL
product_id BIGINT NULL
warehouse_id BIGINT NULL
tax_rate_id BIGINT NULL

created_at

INDEX(company_id, ledger_account_id, posting_batch_id)
INDEX(company_id, customer_id)
INDEX(company_id, vendor_id)
```

Invariant per line:

```text
exactly one of debit_base / credit_base is positive
```

Invariant per batch:

```text
SUM(debit_base) == SUM(credit_base)
```

This is checked before insert and again after line construction before commit.

---

# 48. Accounting posting service

Only one domain boundary may create ledger postings:

```text
AccountingPostingService
```

Specialized services build posting instructions:

```text
InvoicePostingBuilder
PurchasePostingBuilder
CustomerPaymentPostingBuilder
VendorPaymentPostingBuilder
ExpensePostingBuilder
CheckTransitionPostingBuilder
SalaryPostingBuilder
StockLossPostingBuilder
```

Builders do not persist lines directly.

They return a validated posting command to the canonical posting service.

No controller, model observer or Blade/Livewire class may call `PostingLine::create()`.

Architecture tests should enforce this convention.

---

# 49. Source posting examples

## Sale

```text
Dr Accounts Receivable            sale total
    Cr Sales Revenue                    net revenue
    Cr Output Tax                       tax, if any

Dr COGS                            cost
    Cr Inventory                       cost
```

## Customer payment — Cash

```text
Dr Cash
    Cr Accounts Receivable
```

Plus realized FX gain/loss when required.

## Purchase

```text
Dr Inventory                      inventory net cost
Dr Input Tax                      recoverable tax if configured
    Cr Accounts Payable               total
```

Non-stock vendor bill lines may post to expense accounts when that capability is added.

## Vendor payment

```text
Dr Accounts Payable
    Cr Cash/Bank
```

Plus realized FX where applicable.

## Expense

```text
Dr Expense Account
    Cr Cash/Bank/Checks
```

## Expiry disposal

```text
Dr Expired Inventory Expense
    Cr Inventory
```

## Opening cash balance

```text
Dr Cash
    Cr Opening Balance Equity
```

Positive asset opening balance is never represented as a one-sided line.

---

# 50. Idempotency

Every posting source gets a deterministic idempotency key.

Example:

```text
invoice:123:post:v1
customer-payment:55:post:v1
check:88:clear:v1
```

If the same action is retried due to:

- browser retry;
- Livewire duplicate submit;
- network timeout;
- queue retry;

the financial event must not post twice.

Database uniqueness enforces this.

---

# 51. Domain transaction boundaries

Financial operations generally use:

```php
DB::transaction(function () {
    // lock relevant source/state rows
    // validate
    // update operational document
    // create stock movements
    // update rebuildable stock caches
    // create ledger posting batch/lines
    // create audit event
}, attempts: 3);
```

Do not:

1. commit invoice;
2. then separately try inventory;
3. then separately try ledger.

Posted business state, inventory and accounting must commit atomically when part of the same business event.

---

# 52. Locks and concurrency

Use pessimistic locks where concurrency can corrupt state.

Examples:

- document sequence row;
- inventory balance row;
- inventory lot balance rows;
- product cost state;
- payment allocation/open invoice balance calculation;
- check state transition.

Locks belong inside DB transactions.

Avoid broad table locks.

---

# 53. Derived balances

Never persist these as independent authority:

- customer current balance;
- vendor current balance;
- money-account current balance;
- invoice paid flag;
- purchase paid flag.

Derive them from canonical transaction/allocation/ledger data.

Caches are allowed later if:

- clearly identified as caches;
- rebuild command exists;
- reconciliation test exists.

---

# 54. AR/AP open-item logic

Invoice outstanding amount derives from:

```text
invoice grand total
- valid payment allocations
- returns/credits applied
```

Purchase outstanding amount mirrors this.

Customer total receivable is aggregate open receivable documents and credit positions.

Ledger AR must reconcile with AR subledger in company base currency.

Vendor AP must reconcile likewise.

Provide reconciliation service/command before production:

```text
php artisan accounting:reconcile {company}
```

It should detect discrepancies rather than “fix” them silently.

---

# 55. Foreign-currency accounting

For every posted foreign-currency document:

store:

```text
document amount in transaction currency
document exchange rate
document base amount
```

For open receivables/payables:

- original transaction currency remains visible;
- base ledger value is established at posting rate.

When settled at a different rate, calculate realized FX difference.

Example:

Invoice:

```text
100 USD
posting rate 3.50 ILS/USD
AR = 350 ILS
```

Payment later:

```text
100 USD
settlement value 330 ILS
```

Settlement:

```text
Dr Cash 330
Dr FX Loss 20
    Cr AR 350
```

Do not rewrite the invoice rate.

Unrealized FX revaluation is not required for initial MVP unless later explicitly specified.

---

# 56. Document snapshots

Master-data edits must not rewrite history.

When a financial document posts, snapshot:

## Party snapshot

Examples:

```json
{
  "name": "...",
  "business_name": "...",
  "address": "...",
  "phone": "...",
  "tax_number": "..."
}
```

## Company snapshot

Examples:

```json
{
  "name": "...",
  "address": "...",
  "phone": "...",
  "tax_number": "...",
  "logo_path": "...",
  "registration_number": "..."
}
```

## Line snapshots

Store:

- product name;
- SKU;
- unit name;
- conversion factor;
- tax rate;
- actual unit price.

PDF regeneration should use posted snapshots where historical fidelity matters.

Do not rely only on live customer/product master records.

---

# 57. Catalogs

## `catalogs`

```text
id
public_id
company_id
name_ar
name_en
description_ar
description_en
active
show_photos
show_descriptions
show_sku
show_prices
currency_code NULL
created_by
timestamps
```

## `catalog_items`

```text
catalog_id
product_id
sort_order
custom_display_name_ar NULL
custom_display_name_en NULL
custom_price NULL
timestamps
```

Default requirement is price-less catalog.

Price display is optional.

Catalog content is live until share is revoked/expired.

---

# 58. Public shares

Use one sharing infrastructure.

## `public_shares`

```text
id BIGINT PK
public_id CHAR(26) UNIQUE
company_id BIGINT

subject_type VARCHAR(64)
subject_id BIGINT

token_lookup_hash CHAR(64) UNIQUE
token_encrypted TEXT

active BOOLEAN
expires_at DATETIME NULL
password_hash VARCHAR(255) NULL

view_count BIGINT DEFAULT 0
last_viewed_at DATETIME NULL

created_by BIGINT
created_at
revoked_at DATETIME NULL
revoked_by BIGINT NULL
```

The public URL contains a cryptographically random token.

Store:

- SHA-256 lookup hash for efficient matching;
- encrypted token so the same live link/QR can be shown again.

Do not store the public token as plaintext.

Viewing a share:

1. hash presented token;
2. lookup hash;
3. confirm active;
4. confirm not expired;
5. verify optional password;
6. authorize subject as shareable;
7. render only share DTO fields.

Never call a generic model serializer for public documents.

---

# 59. Audit events

## `audit_events`

Append-only application audit history.

```text
id BIGINT PK
public_id CHAR(26)
company_id BIGINT
actor_user_id BIGINT NULL
event_key VARCHAR(128)
subject_type VARCHAR(64)
subject_id BIGINT NULL
summary VARCHAR(512)
before_json JSON NULL
after_json JSON NULL
meta_json JSON NULL
ip_address VARCHAR(45) NULL
user_agent VARCHAR(512) NULL
created_at

INDEX(company_id, created_at)
INDEX(company_id, subject_type, subject_id)
INDEX(company_id, event_key, created_at)
```

No update/delete UI.

Do not audit sensitive plaintext secrets/passwords/tokens.

Financial posting history itself remains the primary accounting audit trail; `audit_events` supplements it with actor/configuration context.

---

# 60. Attachments and file security

Private business attachments go to Laravel private storage.

Examples:

- expense receipt;
- vendor document;
- check image;
- private statement export.

Public product media uses public storage.

Never store private financial attachments under a directly guessable public path.

Private downloads require:

```text
authenticated user
+ active company ownership
+ permission
```

or an explicitly created public share that authorizes that exact asset.

---

# 61. Image pipeline

On product image upload:

1. inspect actual MIME/type;
2. enforce max bytes/dimensions;
3. decode image;
4. discard unsafe metadata where practical;
5. re-encode to approved format;
6. create optimized display image;
7. create thumbnail;
8. use random filename;
9. persist metadata.

Suggested initial display target:

```text
max dimension ~1600–2000px
```

Thumbnail:

```text
~300–500px
```

Exact values can be tuned.

Original upload retention is optional and should be disabled by default on shared hosting unless required.

---

# 62. PDF architecture

Do not generate PDFs directly from arbitrary Livewire page HTML.

Implement:

```text
DocumentDataBuilder
DocumentRenderer
PdfRenderer
PrintRenderer
```

For each document/report:

```text
source data
→ immutable/authorized view model
→ HTML document template
→ print/PDF
```

The same data DTO should power screen print view and PDF where possible.

PDF templates require dedicated RTL testing.

Do not assume Tailwind browser CSS fully maps to mPDF CSS.

Use PDF-safe CSS.

---

# 63. Report architecture

Reports are read models/query services, not controller SQL blobs.

Structure:

```text
Application/Reporting/Queries/
    SalesSummaryQuery
    SalesByProductQuery
    CustomerStatementQuery
    VendorStatementQuery
    InventoryValuationQuery
    ExpiryReportQuery
    ProfitReportQuery
```

Input is a typed filter DTO:

```text
company
from
to
currency display mode
customer/vendor/product/warehouse filters
```

Output is a report DTO.

Views do not calculate financial totals.

---

# 64. Report currency rules

Operational reports may display original transaction currency.

Financial profit/valuation reports use company base currency as authoritative comparison unit.

For mixed-currency reports:

- never add 100 USD + 100 ILS and label result “200”;
- group by currency or convert to base using stored historical transaction values.

Customer/vendor statements can show:

- original amount/currency;
- applied amount;
- outstanding amount;
- optional base equivalent.

---

# 65. Report period object

Create shared `DateRange` / `ReportPeriod` value object.

Presets:

```text
today
yesterday
this_week
last_week
this_month
last_month
this_quarter
this_year
last_year
custom
```

Resolve in company timezone.

Routes/querystrings should preserve filter state.

---

# 66. Search

Global search should use indexed SQL queries initially.

No Elasticsearch.

Search targets:

- product names;
- SKU;
- barcode;
- customer;
- vendor;
- invoice number;
- quotation number;
- purchase number;
- payment number;
- check number.

Normalize Arabic search carefully but do not destructively transform stored names.

Barcode exact match should be prioritized.

---

# 67. Barcode readiness

Scanner hardware often behaves like keyboard input.

Therefore all product selectors should support:

```text
focus search
→ scanner types barcode
→ Enter
→ exact barcode match
→ product/unit resolved
```

`product_barcodes.unit_id` allows carton barcode to select carton unit automatically.

Camera scanning is a future enhancement.

No schema rewrite should be necessary.

---

# 68. UI page architecture

Prefer Livewire full-page components for interactive business screens.

Examples:

```text
Pages/Sales/Invoices/Index
Pages/Sales/Invoices/Create
Pages/Sales/Invoices/Show
Pages/Purchasing/Purchases/Create
Pages/Inventory/Products/Show
```

Complex reusable forms may use Livewire Form objects.

Do not put financial domain logic in them.

They collect/validate user input then invoke application actions.

---

# 69. Form behavior

## Sale line

When product selected:

1. select default sale unit;
2. calculate suggested unit price from `product_units.default_sale_price_base`;
3. if invoice currency != base, convert suggestion using invoice exchange rate;
4. populate line;
5. allow authorized user to edit price.

Changing the line price requires no “negotiation” subsystem.

Optional UX hint:

```text
Last sold to this customer: X
Recent selling range: ...
```

may be added later as a query-only feature.

## Purchase line

Same concept:

- choose default purchase unit;
- populate suggested default;
- user edits actual cost.

Optional contextual history:

```text
Last bought from Vendor A: ...
Last 5 purchases: ...
```

comes from purchase-line query.

---

# 70. Historical price queries

Implement query services rather than stored duplicated price history.

Examples:

```text
ProductPurchaseHistoryQuery
ProductSalesHistoryQuery
VendorProductHistoryQuery
CustomerProductSalesHistoryQuery
```

Recommended result fields:

```text
date
document number
party
unit
quantity
currency
actual unit price
base equivalent
```

Indexes on document lines + header party/date are required.

Do not create “last_vendor_price” as financial authority.

A cache may be introduced later only for performance.

---

# 71. Product/warehouse availability

Product detail should be able to show:

```text
Total stock
Stock by warehouse
Available expiry lots
Average cost (permission-controlled)
Recent purchases
Recent sales
Vendors that supplied product
```

Stock totals derive from inventory balances backed by movement ledger.

---

# 72. Expiry UX

If `track_expiry=false`:

- no lot UI;
- no expiry fields.

If `track_expiry=true`:

Purchase line requires lot distribution before posting.

The UI may provide:

```text
Quantity 50
Expiry 2027-03-31
Lot ABC
+ Add another expiry batch
```

Sale line allocation defaults automatically through FEFO.

Ordinary seller does not need to manually choose lot unless:

- system has insufficient eligible stock;
- user explicitly opens lot selection;
- company workflow requires lot selection.

Dashboard warnings should be configurable.

---

# 73. Inventory disposal

Create explicit stock adjustment/disposal action.

Reasons:

```text
damage
loss
expiry
count_adjustment
other
```

An expiry disposal references lot.

Disposal creates:

- stock movement;
- accounting posting;
- audit event.

No user directly edits quantity.

---

# 74. Stock count future-readiness

V1 does not require a sophisticated cycle-count module, but schema/services should allow future:

```text
stock_count
stock_count_lines
```

that generate adjustment movements after approval.

Do not modify stock balance directly for future stock-count implementation.

---

# 75. Landed costs

Landed cost is not implemented until purchasing/inventory core is stable.

Future design:

```text
landed_cost_documents
landed_cost_lines
landed_cost_allocations
```

Allocation methods:

- value;
- quantity;
- manual.

Posting modifies inventory cost/value appropriately.

Never silently classify every transport expense as landed cost.

User explicitly chooses.

---

# 76. Opening balances

Initial migration/onboarding may need:

- customer receivables;
- vendor payables;
- cash;
- bank;
- checks;
- stock quantities/value.

Create an explicit opening-balance workflow.

Never set:

```text
customer.balance = ...
bank.current_balance = ...
```

Opening financial balances post against `Opening Balance Equity`.

Opening stock creates stock movement + inventory debit/opening equity credit using supplied initial cost.

Opening customer receivable:

```text
Dr AR
    Cr Opening Balance Equity
```

Opening vendor payable:

```text
Dr Opening Balance Equity
    Cr AP
```

Opening process should produce a reconciliation summary.

---

# 77. Status modeling

Use PHP backed enums for domain statuses.

Database stores stable strings.

Examples:

```text
InvoiceStatus
PurchaseStatus
QuotationStatus
CheckStatus
PostingStatus
```

Do not use MySQL ENUM for frequently evolving workflow states.

This keeps migrations safer.

Derived status remains derived.

Example:

`Overdue` is not a persisted invoice workflow state. It means:

```text
posted
AND outstanding > 0
AND due_date < company today
```

---

# 78. Soft-delete policy

May soft-delete/archive:

- customer;
- vendor;
- product;
- warehouse if no unsafe usage;
- employee;
- categories.

Posted transaction headers/lines are not soft-deleted as normal business behavior.

A voided invoice still exists.

A reversed payment still exists.

A canceled check still exists.

---

# 79. Model observers

Avoid placing core financial side effects in Eloquent observers.

Why:

- hidden execution;
- hard-to-control transaction boundaries;
- difficult testing;
- accidental triggering from maintenance scripts.

Observers may be used for safe metadata tasks, but not:

- ledger posting;
- stock movement;
- payment allocation.

Domain/application actions perform those explicitly.

---

# 80. Events

Domain events may be emitted **after successful commit** for secondary effects:

- send email;
- generate notification;
- queue optional PDF;
- analytics;
- webhook later.

Do not use async events for core accounting or stock consistency.

Core financial/stock effects occur synchronously in the DB transaction.

---

# 81. Queues

Use database queue driver initially.

Appropriate queued work:

- email;
- large PDF/report generation;
- export;
- non-critical image derivatives;
- future notifications.

Do not queue:

- invoice ledger posting;
- core stock deduction;
- payment allocation.

On Hostinger shared hosting, run bounded worker via cron, e.g. conceptually:

```text
php artisan queue:work database --stop-when-empty --max-time=50 --tries=3
```

Exact cron command/path is deployment-specific.

Scheduler:

```text
php artisan schedule:run
```

once per minute where hosting permits.

---

# 82. Cache

Initial safe stores:

- database cache or file cache based on deployment benchmark;
- database sessions preferred for multi-device/restart resilience if performance is acceptable.

Do not design correctness around cache availability.

Cache may store:

- settings;
- translation metadata;
- non-critical lookups.

Never treat cache as financial truth.

---

# 83. Security headers and production configuration

Production:

```text
APP_ENV=production
APP_DEBUG=false
HTTPS only
secure session cookies
same-site cookie policy
```

Apply sensible headers:

- frame restrictions / CSP as compatible with app;
- `X-Content-Type-Options`;
- referrer policy;
- HSTS after HTTPS is confirmed stable.

Do not create a CSP so strict that it breaks Livewire without testing.

---

# 84. Secrets

Secrets belong in environment configuration or encrypted application storage.

Never store plaintext:

- SMTP password;
- future payment provider secrets;
- external API keys.

Never display a saved secret back in full in settings UI.

Use replace/rotate semantics.

---

# 85. Mass assignment and validation

Use explicit request/form DTOs.

Do not pass:

```php
Model::create($request->all());
```

for sensitive models.

Validate:

- company ownership;
- related entity status;
- currency enabled;
- warehouse active;
- unit belongs to product;
- permission to see/change cost;
- document remains editable;
- quantity constraints;
- exchange rate positive.

---

# 86. Authorization

Use Laravel policies for resource-specific authorization.

Spatie permissions answer capability.

Policies answer:

```text
Does this user have the capability in this company for this specific record?
```

Example:

```php
$user->can('sales.invoice.view')
&& $invoice->company_id === $companyContext->companyId()
```

Do not authorize solely by hiding buttons.

Every Livewire action and controller endpoint enforces permission server-side.

---

# 87. Route model binding

Public/tenant business routes should prefer `public_id` rather than raw numeric IDs.

Example:

```text
/sales/invoices/{invoice:public_id}
```

Custom binding must remain tenant-aware.

Do not allow Laravel to resolve a record from another company before policy/context is applied.

---

# 88. Database indexes

Index according to real access paths.

Mandatory high-value examples:

```text
sales_invoices(company_id, customer_id, invoice_date)
sales_invoices(company_id, status, invoice_date)
sales_invoices(company_id, due_date)

purchases(company_id, vendor_id, purchase_date)
purchases(company_id, due_date)

sales_invoice_lines(company_id, product_id)
purchase_lines(company_id, product_id)

stock_movements(company_id, product_id, movement_date)
stock_movements(company_id, warehouse_id, movement_date)

inventory_lots(company_id, product_id, expiry_date)
inventory_lot_balances(company_id, warehouse_id)

checks(company_id, due_date, status)

posting_batches(company_id, posting_date)
posting_lines(company_id, ledger_account_id, posting_batch_id)
```

Do not add indexes blindly to every column.

Use query plans when reports grow.

---

# 89. Foreign keys

Use actual foreign keys for ordinary relational integrity.

Financial/master deletion policy should normally be `RESTRICT` rather than allowing important history to cascade away.

Draft-only child lines may cascade with parent only if the application never hard-deletes posted parent documents.

When unsure, prefer restrictive deletion and explicit cleanup.

---

# 90. Tenant relation integrity

Every child row carries `company_id` even when it could technically be inferred from parent.

Benefits:

- fast scoped queries;
- explicit audit;
- safer indexes;
- easier reporting;
- defense-in-depth.

Application service validation must guarantee all related IDs belong to the same company.

Add automated tenant-invariant tests across all company-owned relationships.

Composite tenant foreign keys may be added selectively if a relation proves high-risk, but are not mandatory for every relationship in V1 because they add substantial schema complexity. Service/policy/global-scope/test defenses remain mandatory.

---

# 91. Factories and seeders

Every core model needs factories suitable for:

- tenant isolation tests;
- scenario tests;
- realistic report datasets.

Seeders:

```text
CurrencySeeder
PermissionSeeder
DefaultRoleSeeder
DefaultUnitSeeder
DefaultLedgerAccountSeeder
DefaultExpenseCategorySeeder
DemoDataSeeder (development only)
```

Production bootstrapping must not run demo data.

---

# 92. Company bootstrap service

Implement:

```text
CreateCompanyAction
```

Atomically creates:

- company;
- owner membership;
- enabled Arabic/English languages;
- ILS/USD/JOD currencies;
- base currency selection;
- default warehouse;
- default units;
- internal ledger chart;
- default expense categories;
- document sequences;
- Owner role assignment;
- default settings.

This same action is used now for the first company and later for SaaS onboarding.

Do not maintain a separate “single company install” architecture.

---

# 93. Initial application onboarding

For initial private deployment:

- public registration disabled;
- owner user created safely via CLI/bootstrap process;
- company created through `CreateCompanyAction`.

Possible Artisan command:

```text
php artisan app:bootstrap-company
```

Command prompts:

- owner name/email;
- company Arabic name;
- optional English name;
- base currency;
- timezone.

Password handling should not echo or log plaintext.

---

# 94. Logging

Application logs:

- use daily rotation;
- never log passwords/tokens/secrets;
- never log full uploaded sensitive documents;
- log exceptions with correlation/request context;
- financial posting failures should include source IDs/company but not sensitive credentials.

Production log retention should suit shared-hosting disk limits.

---

# 95. Error handling

Domain failures should use named exceptions.

Examples:

```text
InsufficientStockException
MissingLedgerAccountException
DocumentAlreadyPostedException
ClosedDocumentException
InvalidCurrencyException
AllocationExceedsOutstandingException
CheckInvalidTransitionException
TenantMismatchException
```

UI converts these to translated, useful user messages.

Do not show raw SQL/stack traces in production.

---

# 96. Document posting lifecycle

## Sales invoice

Draft:

- fully editable;
- no ledger;
- no stock;
- no invoice number if final number assigned on posting.

Post:

1. authorize;
2. validate customer/warehouse/currency;
3. validate all lines and unit conversions;
4. validate stock availability;
5. lock sequence;
6. assign invoice number;
7. lock inventory/cost/lot rows;
8. calculate deterministic totals;
9. snapshot master data;
10. create lot allocations;
11. create stock movements;
12. update inventory caches;
13. construct balanced posting;
14. persist posting through canonical service;
15. mark invoice posted;
16. audit;
17. commit.

After commit:

- queue optional email/notification.

## Purchase

Equivalent pattern, but inventory moves inbound and average cost changes.

---

# 97. Void rules

A posted document with financial/stock effects cannot be converted back to Draft.

Void creates reversing effects.

If an invoice has payments allocated:

- simple void may be prohibited until allocations are reversed/reapplied;
- or a controlled compound reversal action must explicitly handle them.

Do not silently detach payments.

Same principle for returns and checks.

---

# 98. Payment lifecycle

Customer payment:

1. create draft input DTO;
2. validate customer/company/currency;
3. validate money account or check details;
4. validate allocations do not exceed valid outstanding amounts;
5. lock invoice/open-item rows;
6. calculate FX;
7. create payment;
8. create allocations;
9. create check if method check;
10. post accounting batch;
11. audit;
12. commit.

Reversal:

- create reversing batch;
- invalidate/reverse allocations;
- preserve original payment record;
- update check state only through valid check transition rules.

---

# 99. Permission-sensitive data

Cost/profit is sensitive.

UI and queries must honor permissions.

A user without `inventory.cost.view` or relevant report permission must not receive hidden values in:

- rendered HTML;
- Livewire serialized payload;
- API JSON;
- exported CSV/PDF;
- public share pages.

Do not merely hide a column with CSS.

---

# 100. Public document safety

Public share DTOs explicitly whitelist fields.

A public catalog must never accidentally expose:

- cost;
- average cost;
- vendor history;
- internal stock quantity unless deliberately enabled later;
- customer data;
- margin;
- ledger IDs;
- internal notes.

Public invoice/quotation links expose only that document and approved company/contact fields.

---

# 101. Performance budgets

Initial practical goals on ordinary shared hosting with production-like data:

- ordinary list/navigation interactions should feel responsive;
- avoid queries that scale linearly across all historical rows for every dashboard load;
- paginate lists;
- report exports may be asynchronous when large;
- product thumbnails should be small;
- global search should cap results.

Do not claim a specific concurrent-user capacity until benchmarked on actual Hostinger plan.

---

# 102. Test layers

## Unit

Pure domain/value logic:

- Money;
- rounding;
- exchange-rate conversion;
- unit conversion;
- document math;
- weighted-average math;
- FX calculation;
- state transition rules.

## Feature

HTTP/Livewire/auth/permission behavior.

## Integration

MySQL-backed:

- posting;
- stock;
- foreign keys;
- sequences;
- locking-related behavior where testable;
- migrations;
- tenant scope.

## Architecture

Rules such as:

- controllers must not import PostingLine for writes;
- UI layer must not mutate InventoryBalance;
- Domain does not depend on Livewire;
- no float financial helpers.

## Scenarios

Full realistic business flows.

---

# 103. Mandatory financial tests before first production transaction

At minimum:

1. cash sale;
2. credit sale;
3. partial customer payment;
4. full customer payment;
5. cross-currency customer payment;
6. purchase on credit;
7. partial vendor payment;
8. cross-currency vendor payment;
9. sale return;
10. purchase return;
11. operating expense;
12. incoming check receive/clear/return;
13. outgoing check issue/clear/cancel;
14. opening balance;
15. stock expiry disposal;
16. document reversal;
17. duplicate submit/idempotency.

Every resulting posting must balance.

---

# 104. Mandatory inventory tests

1. piece/carton conversion;
2. multiple warehouses;
3. weighted-average purchase;
4. sale using average cost;
5. sale return at original COGS;
6. purchase return;
7. negative stock rejection;
8. expiry-lot receipt;
9. FEFO suggestion/allocation;
10. lot override permission;
11. expiry disposal;
12. warehouse transfer preserving company cost;
13. simultaneous stock deduction protection.

---

# 105. Mandatory tenant tests

Create:

```text
Company A
Company B
User A
User B
Shared User with different roles
```

Verify across every critical module:

- A cannot read B;
- A cannot update B;
- A cannot delete/void B;
- foreign customer ID cannot be attached to A invoice;
- foreign product cannot be attached;
- foreign warehouse cannot be used;
- foreign ledger account cannot be posted;
- role in A does not grant capability in B;
- queued job for A cannot leak context to B.

Tenant isolation test failure blocks release.

---

# 106. Mandatory document snapshot tests

Post invoice.

Then change:

- customer name/address;
- company address;
- product name;
- unit display name.

Re-render historical posted invoice.

Verify stored historical snapshot remains unchanged according to snapshot policy.

---

# 107. Mandatory decimal tests

Include values that expose float errors.

Examples:

```text
0.1 + 0.2
JOD three-decimal values
large quantities
fractional kg
percentage discount
multi-line rounding residual
FX conversion
partial allocation
```

Expected values are exact decimal strings.

---

# 108. Canonical end-to-end scenario

Create a permanent test fixture/scenario named conceptually:

```text
TraderBusinessFlowTest
```

Flow:

1. create company;
2. create owner and restricted salesperson;
3. create customer;
4. create Vendor A and Vendor B;
5. create product with Piece base unit;
6. create Carton conversion = 12;
7. add piece and carton barcodes;
8. enable expiry tracking;
9. buy cartons from Vendor A at price A with expiry lot A;
10. buy cartons from Vendor B at lower/higher price with expiry lot B;
11. verify purchase history;
12. verify moving average;
13. quote customer;
14. convert/create invoice with edited sale price;
15. FEFO allocation;
16. post invoice;
17. verify stock + COGS + AR;
18. customer pays partly USD cash;
19. customer pays remainder through Bank;
20. pay Vendor A partly by check;
21. clear/return test as separate scenario;
22. record fuel/delivery expense;
23. record salary after payroll-lite module exists;
24. return one sold carton;
25. dispose expired lot quantity;
26. generate customer statement;
27. vendor statement;
28. stock report;
29. expiry report;
30. profit report;
31. reconciliation.

At end assert:

- quantity;
- lot balances;
- average cost;
- inventory value;
- AR;
- AP;
- cash;
- bank;
- checks;
- sales;
- COGS;
- expenses;
- gross profit;
- net profit;
- posting balance;
- zero unexplained reconciliation difference.

---

# 109. Static analysis

Use Larastan.

Initial target:

```text
level 6
```

Ratchet upward after foundation stabilizes.

Do not suppress large classes of warnings globally.

Any baseline file should be temporary and reviewed.

Use strict types in new PHP source where practical:

```php
declare(strict_types=1);
```

---

# 110. Formatting

Laravel Pint is canonical formatter.

CI/local QA checks formatting.

No debate per-file.

---

# 111. QA command

Create Composer script:

```text
composer qa
```

that executes a deterministic set such as:

```text
pint --test
phpstan
phpunit
```

and frontend separately:

```text
npm run build
```

Provide:

```text
composer qa:fix
```

only for safe formatting operations, not behavioral auto-fixes.

---

# 112. CI

When GitHub Actions credits are available, CI should use MySQL service and run:

1. Composer install;
2. npm ci;
3. production frontend build;
4. migrations on clean MySQL database;
5. Pint check;
6. Larastan;
7. tests.

Local `composer qa` remains mandatory even when CI is unavailable.

No architecture should depend on paid CI.

---

# 113. Database migrations

Migration rules:

- one logical change per migration;
- no destructive shortcut on production;
- add/backfill/switch/remove-later strategy for significant changes;
- migrations must be tested against production-like MySQL;
- no business logic that depends on authenticated user inside migration;
- no remote network requests in migrations.

Before real customers exist, schema may evolve freely through normal migrations.

After production data exists:

- never reset database;
- never edit old applied migration to alter history;
- add new migration.

---

# 114. Deployment blueprint — Hostinger

Expected deployment shape:

```text
Hostinger Web/Cloud shared PHP hosting
PHP 8.4
MySQL
Laravel project
public/ as web root where configuration permits
compiled public/build assets
storage writable
cron
```

Laravel must be served from its `public` directory or equivalent safe Hostinger document-root arrangement.

Never expose project root containing `.env`.

## Deployment steps conceptually

1. backup database/files;
2. deploy code;
3. `composer install --no-dev --optimize-autoloader`;
4. upload/use previously compiled Vite production assets or build in deployment environment if supported;
5. `php artisan migrate --force`;
6. `php artisan optimize`;
7. ensure storage link/public media arrangement;
8. verify queue/scheduler cron;
9. health check;
10. smoke test login, sale draft, report read.

Exact Hostinger commands depend on selected plan and directory layout.

---

# 115. Static frontend build

Development:

```text
npm install
npm run dev
```

Release:

```text
npm ci
npm run build
```

Production requires output, not Vite dev server.

Commit policy for `public/build` depends on deployment pipeline:

- if production cannot run Node build reliably, build artifacts may be included in release package;
- source control does not need to commit build artifacts if deployment reliably builds them.

Choose once deployment process is known.

---

# 116. Scheduler and queue cron

Scheduler cron runs Laravel schedule.

A second bounded cron may process database queue.

Jobs must be safe if one cron invocation overlaps another; use Laravel scheduler overlap protection where appropriate.

Long-running report jobs should chunk data and respect shared-hosting execution limits.

---

# 117. Backup and restore

Before V1 production launch define:

- database backup method;
- product media backup;
- private attachment backup;
- restore procedure;
- recovery ownership.

At least one restore test must be performed.

A successful backup job without a tested restore is insufficient.

---

# 118. Data export

Provide future/early administrator export capability for company-owned data.

At minimum planning should include:

- customers;
- vendors;
- products;
- invoices;
- purchases;
- payments;
- stock movements;
- ledger;
- reports.

This improves portability and disaster recovery.

---

# 119. Architectural anti-pattern blacklist

Agents must not introduce:

- `Utility.php` catch-all;
- `Helpers.php` containing business logic;
- 1,000-line controllers;
- financial calculations in Blade;
- mutable `customer.balance` authority;
- mutable `bank.current_balance` authority;
- global chart-account fallback;
- float arithmetic for money;
- raw cross-tenant `Model::find($id)` without context/policy;
- posted-document hard delete;
- inventory quantity edited directly;
- ledger posting from model observer;
- external HTTP calls in login path;
- plaintext credentials in DB;
- public attachment paths for private documents;
- state-changing GET routes;
- package proliferation;
- premature microservices;
- permanent Node requirement.

---

# 120. Coding-agent workflow

For every phase, the agent should:

1. read:
   - product/master specification;
   - this engineering blueprint;
   - `AGENTS.md`;
2. inspect current repository state;
3. state which phase/task it is implementing;
4. identify invariants affected;
5. implement minimal coherent slice;
6. add/update tests;
7. run QA;
8. report:
   - files changed;
   - migrations;
   - tests added;
   - commands run;
   - risks;
   - what remains;
9. stop at phase boundary unless explicitly asked to continue.

Agent must not opportunistically implement future features during foundational phases.

---

# 121. `AGENTS.md` minimum content

The repository should include a concise agent-facing rules file derived from this blueprint.

Non-negotiables:

```text
1. Read ENGINEERING_BLUEPRINT.md before architecture/domain work.
2. No float money calculations.
3. No financial writes outside accounting services.
4. No stock mutation outside inventory services.
5. No company-owned query without tenant context.
6. No posted financial hard delete.
7. Posted corrections use reversal/return/void.
8. No hidden financial side effects in observers.
9. Database transactions wrap financial business events.
10. Use MySQL integration tests for financial/concurrency behavior.
11. Arabic and English must remain functional.
12. Every page must remain mobile/tablet/desktop responsive.
13. Shared-hosting compatibility is mandatory.
14. New dependencies require justification.
15. New domain behavior requires tests.
16. Do not implement future phases unless requested.
```

---

# 122. Architecture decision records

Create:

```text
docs/adr/
```

for decisions that would be expensive to reverse.

Initial ADRs can include:

```text
0001-modular-monolith.md
0002-livewire-not-react.md
0003-moving-average-cost.md
0004-canonical-double-entry-ledger.md
0005-company-scoped-rbac.md
0006-expiry-lots-fefo.md
0007-mysql-integration-tests.md
```

ADRs explain why, not just what.

---

# 123. Phase roadmap

## Phase 0 — Foundation

Goal:

A clean Laravel 13 repository with secure auth starter, Livewire responsive shell, quality tooling, architecture documents and test environment.

No business accounting features.

Deliverables:

- Laravel 13 app;
- Livewire starter kit;
- Arabic/English shell;
- RTL/LTR layout switching;
- responsive navigation shell;
- PHP 8.4 baseline;
- MySQL dev/test setup;
- Pint;
- Larastan;
- PHPUnit;
- `composer qa`;
- environment templates;
- `AGENTS.md`;
- this blueprint stored in repo;
- ADR directory;
- CI workflow if available;
- safe app config;
- health route verification.

Acceptance:

- app boots;
- login/register starter behavior understood;
- public registration can be disabled;
- Arabic/English switch works;
- mobile/tablet/desktop shell works;
- tests pass on MySQL;
- production Vite build passes;
- no business tables yet beyond starter/framework needs.

## Phase 1 — Tenancy, users, RBAC and company settings

Goal:

Create the future-SaaS-safe identity/tenant foundation.

Deliverables:

- companies;
- company_user;
- company languages;
- currency reference/company currencies;
- CompanyContext;
- tenant scope trait;
- company-scoped Spatie teams permissions;
- default roles/permissions;
- CreateCompanyAction;
- initial company bootstrap command;
- owner/admin user management;
- company settings;
- company switch infrastructure, hidden/simplified when user has one company;
- security policies;
- tenant tests;
- audit-event infrastructure.

Acceptance:

- one login URL;
- one-company user enters directly;
- different company role sets remain isolated;
- cross-company URL/request tampering is denied;
- company creation creates defaults;
- Owner can manage users/roles;
- ordinary user cannot access settings without permission;
- no public registration for private deployment.

## Phase 2 — Money/accounting primitives

- BigDecimal wrappers;
- currencies/rates;
- ledger accounts;
- posting batches/lines;
- posting/reversal engine;
- opening balance primitives;
- accounting invariants and reconciliation skeleton.

No invoices yet.

## Phase 3 — Products/inventory

- categories;
- units;
- products;
- barcodes;
- images;
- warehouses;
- stock movement ledger;
- balances;
- moving average;
- expiry lots;
- FEFO;
- stock adjustments.

## Phase 4 — Sales vertical

Complete:

```text
Customer
→ Quote
→ Invoice
→ Stock
→ Accounting
→ Customer payment
→ Statement
→ PDF/share
```

## Phase 5 — Purchasing vertical

Complete / Accepted Source / Merged / Deployed (Phases 5A–5F; Phase 5F merged through PR #13 and deployed on 2026-10-07):

```text
Vendor
→ Purchase
→ Inventory
→ Accounting
→ Purchase Returns
→ Vendor Payment / AP
→ Statement / Aging
→ Purchase / Vendor Price History
```

## Phase 6 — Money/checks

Complete / Accepted Source / Merged / Deployed (Phase 6 accepted source `3d32c80a2b437c8e7245e7c5b4f6ee16298ca172` merged through PR #14 at `51adf805a85de72c3f15c764bc2eac7786f4efd1` and deployed on 2026-10-07; see ADR 0006. Phase 7 remains unstarted).

- Cash/Bank;
- transfers;
- incoming checks;
- outgoing checks;
- cross-currency allocations and FX;
- money reports.

## Phase 7 — Expenses, employees, landed cost

## Phase 8 — Reporting

## Phase 9 — Documents, QR, catalogs and sharing hardening

## Phase 10 — Production hardening

- security review;
- performance/load;
- backup restore;
- Hostinger deployment;
- regression suite;
- responsive QA;
- RTL/PDF QA.

---

# 124. Phase 0 implementation prompt for Codex

Use the following as the authoritative Phase 0 agent prompt.

---

## CODEX PROMPT — PHASE 0

You are starting a new greenfield Laravel application for a small-trader accounting, inventory and business-management product.

Before changing code, read:

1. `SMALL_TRADER_ACCOUNTING_MASTER_SPEC_v1.0.md`
2. `ENGINEERING_BLUEPRINT_v1.0.md`
3. `AGENTS.md` if already present.

Your task is **Phase 0 only: repository and engineering foundation**.

Do not implement Customers, Vendors, Products, Inventory, Sales, Purchases, Ledger, Payments, Checks, Expenses or Reports yet.

### Required stack

- Laravel 13
- PHP 8.4 baseline
- Laravel Livewire 4 official starter kit
- Blade
- Tailwind CSS 4
- Flux UI only as supplied/allowed by the starter kit; do not add Flux Pro dependency
- Vite
- MySQL
- PHPUnit
- Laravel Pint
- Larastan/PHPStan

Do not use React, Vue, Inertia, microservices, Redis, Docker requirements, a permanent Node server or infrastructure incompatible with Hostinger shared hosting.

### Goals

Create a clean repository that:

1. boots successfully;
2. uses Laravel 13 conventions;
3. includes the Livewire starter authentication stack;
4. supports Arabic and English from day one;
5. switches root `dir` between RTL/LTR correctly;
6. has a responsive application shell suitable for mobile, tablet and desktop;
7. has an initial neutral dashboard placeholder only;
8. uses MySQL for integration tests;
9. has deterministic QA commands;
10. includes architecture documentation and agent guardrails;
11. builds production frontend assets successfully.

### Authentication

Keep starter authentication functionality but make public self-registration configurable and default it OFF for the initial private deployment.

Do not invent company/tenant implementation in Phase 0; Phase 1 will do that.

2FA support from the Laravel starter/Fortify stack should remain available for Phase 1.

### Localization

Implement translation infrastructure:

```text
resources/lang/ar
resources/lang/en
```

Default locale Arabic.

Create at least enough translated shell/auth/navigation labels to prove architecture.

Do not hard-code user-visible shell strings.

Arabic layout must render RTL.

English layout must render LTR.

Numbers/money/SKU/barcode presentation must be able to remain LTR inside Arabic screens when appropriate.

### Responsive shell

Implement a restrained business application shell, not final feature design.

Requirements:

- mobile-first;
- touch-friendly;
- responsive sidebar/header;
- no horizontal overflow at ordinary phone widths;
- accessible focus states;
- Arabic/English compatible;
- desktop can use denser navigation;
- mobile navigation collapses cleanly.

Do not over-design or add animation libraries.

### Quality tools

Configure:

- Pint;
- Larastan;
- PHPUnit;
- `composer qa`;
- production `npm run build`.

Use strict typing in new PHP code where reasonable.

Larastan target can begin at level 6.

### MySQL testing

Do not treat SQLite as release-authoritative.

Provide `.env.testing.example` or documentation for MySQL test DB.

Integration/feature tests that rely on DB should be runnable against MySQL.

Do not require production credentials.

### Documentation

Create/retain:

```text
README.md
AGENTS.md
docs/adr/
```

Add initial ADRs covering:

- modular monolith;
- Livewire choice;
- MySQL integration testing.

Store the approved master specification and engineering blueprint in `/docs` or root with stable names.

### Security baseline

Ensure:

- `APP_DEBUG` production guidance is safe;
- `.env` excluded;
- no secrets committed;
- CSRF/auth starter behavior remains intact;
- login throttling from Laravel/Fortify is not removed;
- storage/public directories follow Laravel conventions.

### Shared hosting

Do not require:

- Supervisor;
- Redis;
- WebSockets;
- Docker;
- long-running Node process.

Production frontend must be a static Vite build.

### Tests

Add foundation tests proving:

- app loads;
- login page loads;
- registration availability follows configuration;
- Arabic locale applies RTL;
- English locale applies LTR;
- unauthenticated app page redirects appropriately.

### Completion gate

Run and report:

```text
composer qa
npm run build
```

Also run migrations/tests against MySQL.

Do not begin Phase 1.

At completion report:

- exact framework/package versions selected by lock files;
- files added/changed;
- commands run;
- test results;
- build results;
- unresolved Hostinger assumptions;
- confirmation that no business-domain modules were implemented.

---

# 125. Phase 1 implementation prompt for Codex

Only use after Phase 0 is accepted.

---

## CODEX PROMPT — PHASE 1

Read the approved master specification, engineering blueprint, AGENTS.md and Phase 0 ADRs first.

Implement **Phase 1 only: company tenancy, company-scoped users/RBAC, company settings and audit foundation**.

Do not implement financial ledger, products, inventory, invoices, purchases, payments, checks, expenses or reports.

### Required models/tables

Implement:

```text
companies
company_user
company_languages
currencies
company_currencies
company_inventory_settings
company_document_settings
company_security_settings
audit_events
```

plus Spatie permission tables configured for teams using:

```text
company_id
```

as the team foreign key.

### Company model

Include core fields defined in engineering blueprint.

Use:

- internal bigint ID;
- public ULID;
- Arabic name required;
- English name optional;
- base currency;
- Arabic default locale;
- timezone.

### CompanyContext

Implement a single request-scoped company context service.

Authenticated company-owned screens/services must resolve tenant through this context.

Do not scatter `session('company_id')` throughout the application.

### Membership

A user can belong to one or more companies.

For now, if exactly one active company membership exists, activate it automatically.

Build company-switch capability in services/middleware, but UI may remain minimal when only one company exists.

Never trust `last_active_company_id` without verifying active membership.

### RBAC

Install/configure Spatie Laravel Permission v8 Teams mode BEFORE its migration is finalized.

Use `company_id` as team key.

Default roles:

```text
Owner
Administrator
Manager
Sales
Purchasing
Warehouse
Cashier
Viewer
```

Seed granular permissions from blueprint.

Create middleware that:

- resolves active company;
- sets Spatie permission team ID;
- correctly refreshes cached role/permission relationships.

Ensure this works with Livewire persistent middleware requirements.

### Policies

Add policies for Company and User management.

All server actions must authorize.

Do not rely on hidden buttons.

### Company creation

Implement `CreateCompanyAction` atomically.

It must create:

- company;
- owner membership;
- enabled Arabic + English;
- ILS/USD/JOD;
- selected base currency;
- default warehouse placeholder only if warehouse table is intentionally deferred then defer warehouse creation until Phase 3; do not create an incomplete fake warehouse schema in Phase 1;
- default settings;
- roles/permissions;
- Owner assignment.

If a dependency belongs to a later phase, record it as a future hook rather than inventing half of that module.

### Initial bootstrap

Implement a safe CLI workflow for the first owner/company.

Do not enable public self-signup.

### Audit foundation

Create append-only `audit_events` structure and service.

Audit at least:

- company created;
- company identity setting changed;
- user invited/added;
- membership status changed;
- role assigned/removed;
- critical security setting changed.

Do not log password/token/secret values.

### Language/company settings UI

Implement responsive settings pages for:

- company identity;
- localization;
- currencies enable/disable;
- security switches where relevant;
- users;
- roles.

Do not add accounting settings yet.

### Tenant isolation

Add broad tests:

Company A / Company B.

Verify:

- user with A access cannot read B company settings;
- forged B public ID does not leak data;
- a user with role in A does not receive same role in B;
- company switch rejects non-members;
- Livewire actions remain scoped;
- role cache does not survive company switch incorrectly.

### Currency constraints

Seed reference currencies:

```text
ILS minor units 2
USD minor units 2
JOD minor units 3
```

Exactly one base currency per company.

Do not implement exchange-rate business logic until Phase 2.

### Completion gate

Run:

```text
composer qa
npm run build
```

Run migration/test suite on MySQL.

Do not begin Phase 2.

Report:

- migrations;
- company/RBAC behavior;
- tenant tests;
- security assumptions;
- any deviations from blueprint;
- complete QA result.

---

# 126. Phase acceptance discipline

Do not hand an agent the entire product and say “build it.”

Each phase must be accepted before the next.

Reasons:

- limits architecture drift;
- makes code review practical;
- catches incorrect foundational assumptions early;
- lets the user test real UI incrementally;
- prevents AI agents from creating broad but shallow features.

---

# 127. Definition of done for every business feature

A feature is not done because its screen exists.

Done means:

- authorization implemented;
- tenant scope enforced;
- validation implemented;
- domain action implemented;
- database transaction defined if required;
- audit behavior defined;
- Arabic translations;
- English translations;
- mobile/tablet/desktop tested;
- tests added;
- static analysis passes;
- production build passes;
- print/PDF considered if document/report;
- no forbidden architecture bypass.

---

# 128. V1 non-goals to protect scope

Do not implement during MVP unless explicitly promoted:

- automatic vendor ranking;
- negotiation engine;
- dynamic pricing AI;
- customer-specific contracted price lists;
- bank feeds;
- automated OCR;
- automatic exchange-rate feed;
- full payroll;
- attendance;
- manufacturing;
- serial-number inventory;
- advanced batch recall;
- e-commerce;
- POS;
- mobile-native application;
- external API marketplace;
- dozens of payment gateways;
- platform subscription billing.

Barcode fields/readiness are included; advanced scanning UI is future.

Expiry lots are included because they affect the core stock schema and cannot safely be bolted on as an afterthought.

---

# 129. Data migration/import readiness

Even though this is a new app, design import actions for future customer onboarding.

Potential imports:

- customers;
- vendors;
- products;
- opening stock;
- opening AR/AP;
- money opening balances.

Imports must:

- validate before commit;
- produce row-level errors;
- avoid partial financial corruption;
- use the same domain services as manual onboarding where financial state is affected.

Do not write a “fast importer” that bypasses ledger/stock rules.

---

# 130. Rebuild and reconciliation commands

Design current-state caches to be rebuildable.

Planned commands:

```text
inventory:rebuild-balances {company}
inventory:rebuild-cost-state {company}
accounting:reconcile {company}
permissions:verify-company-scope
```

Rebuild commands should support dry-run where practical.

They diagnose and reconstruct derived/cache state; they do not rewrite historical posted facts silently.

---

# 131. Operational observability

Before production:

Provide a lightweight administrator/system status page or commands showing:

- queue pending/failed;
- scheduler last heartbeat;
- storage write health;
- DB connectivity;
- app version/commit;
- backup status if available.

Do not expose these publicly.

---

# 132. Versioning

Use semantic application release tags:

```text
v0.x during development
v1.0.0 first stable release
```

Store release/commit metadata in deployment environment.

Audit/report exports may include application version/template version where useful.

---

# 133. Final engineering promise

The implementation is considered architecturally healthy when the following statement is true:

> Every important business event is recorded once at its natural source, every financial effect is posted atomically to one canonical ledger, every physical stock change is represented by a traceable inventory movement, every expiry-sensitive quantity can be traced to a lot, every historical transaction preserves the price/currency/rate actually used, every company is isolated, and every public/shared view exposes only explicitly authorized data.

The application should remain simple for the trader because complexity is absorbed by the domain architecture—not because the underlying rules are ignored.

---

# 134. External references validated for this blueprint

The following official/current references informed the stack decisions as of September 2026:

- Laravel 13 deployment/server requirements:
  https://laravel.com/framework/docs/13.x/deployment

- Laravel 13 release/support information:
  https://laravel.com/framework/docs/13.x/releases

- Laravel starter kits / Livewire starter:
  https://laravel.com/framework/docs/13.x/starter-kits
  https://laravel.com/starter-kits

- Livewire 4:
  https://livewire.laravel.com/docs/4.x

- Laravel authentication:
  https://laravel.com/framework/docs/13.x/authentication

- Laravel database transactions / locking:
  https://laravel.com/framework/docs/13.x/database
  https://laravel.com/framework/docs/13.x/queries

- Laravel filesystem:
  https://laravel.com/framework/docs/13.x/filesystem

- Spatie Laravel Permission v8:
  https://spatie.be/docs/laravel-permission/v8

- Spatie teams permissions:
  https://spatie.be/docs/laravel-permission/v8/basic-usage/teams-permissions

- mPDF RTL/Arabic:
  https://mpdf.github.io/fonts-languages/arabic-rtl-text-v5-x.html

These URLs are references, not runtime dependencies.

---

# 135. Immediate next action

Do not start the whole application from this document in one run.

Next action:

1. create the new repository;
2. place the approved master specification in it;
3. place this engineering blueprint in it;
4. create `AGENTS.md`;
5. hand Codex **Phase 0 only**;
6. review Phase 0 output;
7. only then hand it Phase 1.

That sequence is intentional.
