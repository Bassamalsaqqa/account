# UX Route, Role & Verification Matrix

Current route-family, role-redaction, and verification reference for candidate `b1.3` (not deployed). Design rules and CSS token authority live in [`../DESIGN.md`](../DESIGN.md), [`UI_WORKFLOW_RULES.md`](UI_WORKFLOW_RULES.md), and [`../resources/css/app.css`](../resources/css/app.css).

## 1. Roles, Access Boundaries & Server Redaction Map

Company roles are seeded idempotently in [`app/Services/Tenancy/CompanyRoleService.php`](../app/Services/Tenancy/CompanyRoleService.php); control-plane grants live in [`app/Application/Platform/PlatformAccess.php`](../app/Application/Platform/PlatformAccess.php):

| Role / Actor | Primary Accessible Modules | Restricted / Server-Redacted Boundaries |
|---|---|---|
| `Owner` | Full company workspace, Settings, AI & Phase 9 governance | Company-scoped (no cross-tenant or `/platform` routes without explicit platform grant). |
| `Administrator` | Company ops, Settings (`company`, `users`, `roles`, `sequences`, `taxes`, `money_accounts`, `purchases`), Audit, Payroll | Excluded by default from `PHASE9_PERMISSIONS` and `AI_PERMISSIONS` unless granted. |
| `Manager` | Ops + read-only company/user/audit settings + sequences/taxes/accounts/purchase settings + all `reports.*` + HR/Payroll | No `settings.company.manage`, `settings.roles.*`, `settings.users.manage`, `PHASE9_PERMISSIONS`, or `AI_PERMISSIONS`. |
| `Sales` | Invoices (draft/post), Quotations, Returns, Customers, `inventory.stock.view`, Receipts, Incoming checks, `reports.sales.view` | No `sales.invoice.change_price`, `change_discount`, `inventory.cost.view`, `purchasing.*`, or `reports.{profit,cost}.view`. |
| `Purchasing` | Purchases (draft/post), Landed cost, Purchase returns, Vendors, `inventory.stock.view`, Vendor payments, Outgoing checks, `reports.purchases.view`, `money.expense.view` | No `sales.*`, `customers.*`, cash/bank/receipts, or `inventory.cost.view` (unless granted). |
| `Warehouse` | Products (`inventory.product.manage`), Stock Overview, Adjustments, Transfers, Expiry, `reports.inventory.view` | No `inventory.cost.view`, `purchasing.cost.view`, sales, purchasing docs, or money accounts. |
| `Cashier` | Invoice create/view, Customers/statements/PDF, `inventory.stock.view`, `money.cash.view`, Receipts & Incoming checks | No `sales.invoice.post`, `edit_draft`, `void`, `change_price`, `change_discount`, bank balances, or reports. |
| `Viewer` | Read-only views (`sales.*`, `purchasing.purchase.view`, `inventory.stock.view`, parties, `money.*`, basic reports) | No writes; no `inventory.cost.view`, `purchasing.cost.view`, `reports.{profit,cost,financial}.view`, or `vendors.statement.view`. |
| `Platform Operator` | Control plane (`company.read`, `company.provision`, `audit.read`, `subscription.manage`, `company.support`, `company.impersonate`) | Session-bounded impersonation; provisioning/billing/triage require recent password proof. Diagnostic bug-report access (`support.bug_reports.view`, `support.bug_reports.manage`) requires explicit `--permission=` grants. |

## 2. Route-Family & Archetype Matrix

| Route Family (`web.php` / `auth.php`) | Archetype | Server Boundary / Key Invariants |
|---|---|---|
| **Auth & Guest** (`login`, `password.*`, `verification.*`, `locale.switch`) | Minimal Guest Shell (`layouts/guest.blade.php`) | `guest`/`auth`, `throttle`, `signed`; `<x-release-version />` shows `ReleaseIdentity` (`b1.3 · Build unverified` when unstamped); **no guest bug reporter**. |
| **Errors & Diagnostics** (`errors/{403,404,419,429,500,503}`, `diagnostics.bug-reports.store`) | Shared Error Pages + Lazy Authenticated Reporter | Authenticated-only intake (`auth`); no form/business auto-capture; untrusted user notes omitted from sanitized agent briefs; no retention/pruning active. |
| **Tenancy & Account** (`companies.{setup,select,switch}`, `profile`, `assistant.memory.discard`) | Selector Cards + Account Profile | `auth`; `x-dialog-shell` two-factor/export flows; server-derived company context. |
| **Dashboard & Reports** (`dashboard`, `reports.{index,show,export}`) | Neutral KPI Home + Hub/Report Tables | `ReportingGuard`, `DashboardReports`, `ReportCsvController`; zero pastel card washes; currency separation preserved. |
| **Sales & Purchasing Forms** (`invoices.*`, `purchases.*`, `quotations.*`) | Searchable Line Composer + Totals + Settlement | `SalesActorGuard` / purchasing guards; single DOM editor per line (`line_uid`), viewport-safe `x-searchable-combobox`, `Totals -> Settlement` flow. |
| **Documents, Shares & PDFs** (`*.{index,show}`, `returns.*`, `purchase-returns.*`, `pdf.*`, `public.share*`) | Ledger Index + Summary Detail + `FinancialShareManager` | Single header summary band; deliberate encrypted `PublicShare` snapshots; clearing displayed URL/QR/selection on date/setting changes while keeping issued links active in history; HTTPS required for QR data URIs. |
| **Payments & Statements** (`payments.*`, `vendor-payments.*`, `customers.statement`) | Allocation Form + Detail + Statement | `VendorFinancialRead`, `reports.financial.view` gating on realized FX presentation; `CustomerStatementView` syncs `FinancialShareManager` via scoped events. |
| **Parties** (`customers.*`, `vendors.*`, `employees.*`, `payroll.*`) | Directory Index + ARIA Tabbed Profile | Logical RTL/LTR ARIA `tablist`; server-redacted vendor/cost metrics; single-line desktop tables + mobile cards. |
| **Products, Inventory & Catalogs** (`products.*`, `inventory.*`, `labels.index`, `catalogs.*`, `public.catalog`) | Tabbed Profile + Stock Ops + Composer | Catalogs & Labels live under **Products** (with `/settings?section=catalogs` redirect); FEFO lot/expiry rules; `x-dialog-shell` modals. |
| **Money & Expenses** (`money.*`, `expenses.*`) | Treasury Register + Check/Transfer/Expense | `AuthorizesMoneyPages`, `reports.financial.view` FX display boundary, private attachment downloads. |
| **Settings Hub** (`settings.*`, `expenses.categories`) | Categorized Settings Shell (`SettingsNavigation`) | `CompanyPolicy::view`, `password.confirm` preserving active section on return, single permitted-section derivation. |
| **Platform Control Plane** (`platform.*`, `platform.bug-reports.*`) | Operator Console + Support Banner | `PlatformAccess` core vs explicit diagnostic grants; sanitized brief viewer (`bugs:brief` parity). |
| **Headless & Mobile APIs** (`api/v1/*`, `api/mobile/v1/*`, `api/mobile/v2/*`) | Token/Lease JSON Boundaries | Live Mobile V1 + AI; Mobile V2, offline capture, and subscription enforcement remain OFF by default; mobile document discounts remain unimplemented. |

## 3. Verification Scope & Open / Hold Gates

- **Testing & Build Gates:** Focused MariaDB PHPUnit suites, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, Node JS unit suites (`tests/Frontend/*`, `tests/JavaScript/*`), `npm run build` (no source maps), and `tools/release/qualify` govern acceptance for each slice.
- **Open Rendered & Device Gates:** Full `320px`/`390px`/`820px`/`1024px`/`1440px` rendered sweeps across `ar` RTL / `en` LTR, native `200%` zoom, full restricted-role rendered matrix, remaining uncompleted route families, and physical Android device acceptance remain unverified until explicitly run (there is **no iOS app gate**).
- **Explicit Hold Items:** Cold/warm navigation and selector performance qualification is active. Demand-mounted AI, client bundle splitting, and automated bug-report retention/pruning remain **ON HOLD**.
