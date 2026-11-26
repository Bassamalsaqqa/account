# Small Trader Accounting — Design Constitution & UI Architecture

Native UI constitution for Laravel 13, Livewire 4, Tailwind CSS 4, and Alpine.js (`Blade` + CSS tokens). There is no standalone `.impeccable.md`, `components.json`, or external frontend playbook file; this document, [`docs/UI_WORKFLOW_RULES.md`](docs/UI_WORKFLOW_RULES.md), [`docs/UX_ROUTE_WORKFLOW_MATRIX.md`](docs/UX_ROUTE_WORKFLOW_MATRIX.md), [`PRODUCT.md`](PRODUCT.md), and [`references/ui/`](references/ui/) are the repository authorities.

## 1. Visual Constitution: Neutral Grounds + Purposeful Saturated Accents

Built for high-frequency bilingual trade, treasury, and inventory workflows. **Clarity beats decoration.**

1. **Neutral Primary Grounds**:
   - Canvas (`--color-canvas`): `#f7f9fc` (verified in [`resources/css/app.css`](resources/css/app.css)).
   - Primary Surface (`--color-surface`): `#ffffff` for all cards, tables, dialogs, and KPI/summary tiles.
   - Inset Tray (`--color-surface-soft`): `#f5f7fb` strictly for passive secondary wells/table headers — **never** nest cards inside cards just to hold metrics or tabs.
   - Structural Hairlines (`--color-border`): `#e4e9f1`.
   - Interactive Control Outline (`--color-border-control` — Phase C upgrade from current `--color-border-strong: #d6deea` at `1.36:1`): calibrate to `>= 3.0:1` WCAG 2.2 AA non-text contrast against both `--color-surface` and `--color-canvas` (candidate ramp `~#7e8ca1`, `3.18:1`–`3.41:1`, verified on real renders before locking).
2. **Saturated Semantic Accents (Earned Focus Only)**:
   - **Primary Action / Active State**: `--color-primary: #255fd6` (`5.70:1` vs `#ffffff`), hover `#1f52bd` (`7.00:1`). Reserved for primary submit/confirm buttons, active navigation states, active tabs, and focus rings.
   - **Semantic Status & Financial Direction** (currently verified `>= 4.6:1` against `#ffffff`, `#f7f9fc`, `#f5f7fb`):
     - Success / Posted / Paid / Inflow: `--color-success: #14804a` (`4.98:1` on white).
     - Warning / Pending / Attention / Expiring Soon: `--color-warning: #a15c00` (`5.19:1` on white).
     - Danger / Void / Overdue / Alert / Outflow: `--color-danger: #b42318` (`6.57:1` on white).
     - Info / Selected / Informational Note: `--color-info: #1d4ed8` (`6.70:1` on white).
   - **Dark/Light Mode Discipline**: The application currently ships light-mode CSS tokens only (`resources/css/app.css`). When adjusting shades or extending tokens in Phase C+, keep semantic CSS custom properties cleanly layered and verify focus rings, disabled controls, and text contrast directly against actual browser renders.
3. **Explicitly Banned Patterns** (Superseding prior pastel-surface guidance):
   - No pale pastel full-card backgrounds (`#f0fdf4`, `#eff6ff`, `#fffbeb`, `#f0f9ff`, `#faf5ff`) on KPI, wallet, or shortcut tiles.
   - No rainbow metric stacks, arbitrary background gradients, or colored top/side border stripes repeated across every metric card (`.document-summary-card` stripe rules).
   - No ornamental icon circles/squares beside page titles (`page-heading.blade.php`), KPI headlines, or shortcut cards; keep icons strictly functional (actions, field triggers, status disambiguation).
   - Prefer shared semantic tokens over page-specific palettes. Retain purposeful standalone print/error styling when needed; do not churn working controls merely to satisfy a grep rule.
   - No disabled `"Coming soon"` placeholder links (`layouts/platform.blade.php`) or synthetic demo telemetry.
4. **Status, Category & Direction Rules**:
   - **Never rely on color alone**: pair saturated indicators (compact badge, crisp status dot, directional glyph, or emphasized numeric band) with a clear localized text label.
   - **Separate category from status**: module/ledger tags use restrained neutral/indigo framing; `success`/`warning`/`danger` are reserved for document lifecycle, cashflow direction, stock/expiry alerts, and validation errors.
   - **Document Totals & Summary Bands**: Present Subtotal, optional Discount, Tax, Paid/Applied, Remaining, and Grand Total in **one** cohesive neutral band/strip (emphasizing Grand Total / Remaining Balance), rather than tinting every figure or duplicating summary grids at both top and bottom of detail screens (`purchase-detail.blade.php`).
5. **Contextual Help & Honest Disclosures**:
   - Keep concise, plain-language localized helper notes where a business choice carries consequence: irreversible post/void/reversal actions, missing FX rates, permission-redacted figures (server-omitted, never CSS-hidden), negative-stock/expiry-lot rules, tax limitations, and multi-option settings. Remove hollow taglines/empty subtitle noise.

## 2. Typography, RTL/LTR Direction & Touch Ergonomics

- **Typefaces**: Self-hosted `IBM Plex Sans Arabic` (Western digits) with system Arabic/Latin fallbacks (`Tahoma, Arial, sans-serif`); monospace `tabular-nums` for ledger numerals.
- **Hierarchy Scale (Minimum Legibility Baseline)**:
  - Page Title: `text-xl md:text-2xl font-bold text-text-primary tracking-tight` (`20–24px`).
  - Section Heading: `text-base md:text-lg font-semibold text-text-primary` (`16–18px`).
  - Table / Nav / Button / Dense Control: `14px` (`text-sm`, `--color-text-primary` `#101828` or `--color-text-secondary` `#475467`).
  - Interactive Form Inputs: `15–16px` (`text-sm sm:text-base` desktop; `>= 16px` `< 768px` to prevent iOS viewport auto-zoom).
  - Secondary Meta / Timestamps / Captions: `12–13px` (`text-xs`, `--color-text-muted` `#526174` / contrast `>= 5.9:1` on grounds).
  - **Zero micro-type**: eliminate remaining `text-[9px]`, `text-[10px]`, and `text-[11px]` utilities (`purchase-form`, `invoice-form`, `quotation-form`).
- **Bilingual RTL/LTR Mechanics**:
  - Use CSS logical utilities exclusively (`ps-`/`pe-`, `ms-`/`me-`, `start-`/`end-`, `text-start`/`text-end`). Audit and remove physical `ml-`/`mr-`/`pl-`/`pr-`/`text-left`/`text-right` outside isolated numeric islands.
  - Money amounts, quantities, barcodes, SKUs, document numbers, dates, and URLs render with `dir="ltr"`, `unicode-bidi: isolate` (`<bdi>`), `tabular-nums`, 2-decimal display precision for currency, and trailing-zero trimming without silent truncation of fractional inventory/conversion quantities (`BigDecimal` authority on server).
- **Density, Rhythm & Controls**:
  - 8px base spacing rhythm with 4px fine increments; align desktop grids (`max-w-[1440px]`, clean gutters) without unnecessary card nesting.
  - **Desktop**: compact `36–40px` controls and aligned rows; inspect cramped controls in context rather than banning a height utility.
  - **Mobile (`< 768px`)**: minimum `44×44px` touch targets (`.touch-action`, `min-h-[44px]`), responsive stacked/grid card layouts that **reuse** a single interactive component DOM tree rather than duplicating desktop+mobile Livewire/combobox subtrees.
  - **Native Controls First**: Use native `<select>`, `<input type="checkbox">`, `<input type="radio">`, and accessible switch primitives styled consistently with tokens; reserve custom searchable combobox (`x-searchable-combobox`) for high-cardinality datasets (products, customers, vendors, warehouses).

## 3. Core Component & Screen Archetypes

1. **Application Shell (`layouts/app.blade.php`, `layouts/platform.blade.php`, `mobile-shell.js`)**:
   - Stationary 264px expanded / 76px collapsed desktop sidebar (`localStorage['accounting.sidebar.collapsed']`, `sessionStorage['accounting.sidebar.open'|'accounting.sidebar.scroll']`), sticky topbar with global search, company switcher, language toggle (`AR`/`EN`), and visible active state for every reachable destination (`catalogs.*`, `labels.*`, `money.*`, etc.).
2. **Searchable Picker & Line Entry (`x-searchable-combobox`, `x-touch-quantity-stepper`)**:
   - **Single interactive DOM tree** per line using stable UI-only `line_uid` (`wire:key`), never index+mutable-product keys or mirrored hidden desktop/mobile trees.
   - Popup listbox escapes `overflow-x-auto` table clipping (viewport-aware top-layer positioning/flip/clamp), aligns option IDs cleanly (`-opt-` in both `searchable-combobox.blade.php` and `searchable-combobox.js` for working `aria-activedescendant`), and supports Arrow/Enter/Tab/Escape + immediate optimistic quantity coalescing.
3. **Create / Edit Transaction Forms (`purchase-form`, `invoice-form`, `quotation-form`, return/adjustment/transfer forms)**:
   - Essential header first (party/quick-create beside selector, date, compact warehouse/currency/due-date row; FX rate hidden when matching base currency).
   - Product search + inline camera trigger inside field; clean responsive line items; **Financial progression order**: Summary & Totals (Subtotal -> optional collapsed Discount/Tax/Notes -> Grand Total) **must precede** Settlement / Immediate Payment in both visual grid and mobile/keyboard DOM order (`purchase-form` currently renders settlement before totals — Phase B fix).
4. **Detail, Print & Public Share (`invoice-detail`, `purchase-detail`, `payment-detail`, `quotation-detail`, `financial-share-manager`, `public-share`)**:
   - Single prominent document header summary band (`Total`, `Paid/Applied`, `Remaining`, `Due Date`) + inline permitted actions (`Edit`, `Confirm`, `Pay/Receive`, `Return`, `Void`, `PDF`, `Share` via shared `x-dialog-shell` — retire ad-hoc `fixed inset-0` modals). No repeated bottom summary grids.
5. **Auth, Build Identity & Problem Reporting (`layouts/guest.blade.php`, `auth/login.blade.php`, `errors/*`)**:
   - Crisp calm SaaS auth layout with real `44px` touch wrappers, tokenized shared inputs/checkboxes, localized errors, and cached build/release identity replacing hardcoded `'v1.0'` (`lang/*/app.php`).
   - Accessible "Report a problem" entry (authenticated shell + platform + safe guest/error fallback) capturing route, trace/correlation ID, build SHA, sanitized context, and operator-configured delivery without exposing tenant secrets.
6. **Dashboard, Parties & Products (`reporting/dashboard`, `reporting/hub`, `customer-detail`, `vendor-detail`, `product-detail`)**:
   - Concise permission-scoped KPIs + today's cash/bank/checks on neutral `bg-surface` with crisp saturated semantic tags (no pastel dashboard tiles); single header card + ARIA `tablist` (`ArrowLeft`/`ArrowRight`, zero duplicate attributes) + clean Ledger/Docs/History panes without nested currency cards.
7. **Catalogs & Settings Information Architecture (`SettingsNavigation`, `SettingsIndex`, `catalog-composer`, `app.blade.php`)**:
   - Move Catalogs & Barcode Labels canonically under **Products** (`Products` -> `Catalogs`, `Labels`) while keeping `/settings?section=catalogs` redirect compatibility and stripping `<x-settings-shell>` wrapper from `catalog-composer`.
   - Organize Settings cleanly into discoverable categories (`Business`, `Sales & Purchasing`, `Documents & Printing`, `Inventory & Product Setup`, `Money`, `Team & Access`, `Integrations & Data`, `Personal Account`, plus separate `Platform`), deduping repeated controls (e.g., AI master toggle currently duplicated in `security` & `ai`) and translating remaining hardcoded Arabic labels.

## 4. Phased Token/Component Upgrade & Exact File Ownership (No Code Edits in Phase A)

Detailed route/permission/pain/test mappings live in [`docs/UX_ROUTE_WORKFLOW_MATRIX.md`](docs/UX_ROUTE_WORKFLOW_MATRIX.md). Implementation sequence after root signs off on first form slice (`Phase B`):

- **Phase B — First Form Slice (`Document Picker, Single-Tree Rows & Form Order`)** *(Adam / coordinated slice)*:
  - `resources/views/components/searchable-combobox.blade.php`, `resources/js/searchable-combobox.js` (`-opt-` ID sync, top-layer/viewport-safe popup, `compact` height >=36px/44px touch).
  - `resources/views/livewire/pages/purchasing/purchase-form.blade.php`, `app/Livewire/Pages/Purchasing/PurchaseForm.php`, `resources/views/livewire/partials/immediate-purchase-settlement.blade.php` (Totals before Settlement in DOM + grid, stable `line_uid`, eliminate `text-[11px]`/`h-8`).
  - Analogous alignment: `resources/views/livewire/pages/sales/{invoice-form,quotation-form}.blade.php`, `app/Livewire/Pages/Sales/{InvoiceForm,QuotationForm}.php`, `resources/views/livewire/partials/immediate-sale-settlement.blade.php`, `resources/js/document-operations.js`.
- **Phase C — Design Tokens, Core Primitives, Auth & Diagnostics Foundation**:
  - `resources/css/{app.css,business-dashboard.css,data-tables.css}` (`--color-border-control`, remove pastel/stripe overrides).
  - `resources/views/components/{page-heading,document-summary-cards,card,badge,text-input,dialog-shell,primary-button,secondary-button,danger-button}.blade.php` + shared checkbox/select/toggle styling.
  - `resources/views/layouts/{app,guest,platform}.blade.php`, `resources/views/livewire/pages/auth/login.blade.php`, `resources/views/errors/{layout,403,503}.blade.php` (+ missing `404`/`419`/`429`/`500` error views), `config/app.php` / `app/Support/BuildInfo*` (cached release version metadata), and bounded "Report a problem" endpoint/dialog (`app/Http/Controllers/`, `config/logging.php` — awaiting owner approval on any log/report retention policy).
- **Phase D — Dashboard, Detail, Payment & Party Surfaces**:
  - `resources/views/livewire/pages/reporting/{dashboard,hub,report}.blade.php`.
  - `resources/views/livewire/pages/{sales/invoice-detail,sales/quotation-detail,sales/return-detail,purchasing/purchase-detail,purchasing/purchase-return-detail,expenses/expense-detail}.blade.php`, `resources/views/livewire/financial-share-manager.blade.php`.
  - `resources/views/livewire/pages/{sales,purchasing}/{payment-form,payment-detail}.blade.php`.
  - `resources/views/livewire/pages/{customers/customer-detail,purchasing/vendor-detail,payroll/employee-detail}.blade.php` (+ index/form logical-direction/height/table cleanup).
- **Phase E — Products, Inventory, Catalogs & Settings IA**:
  - `resources/views/livewire/pages/products/{product-detail,product-form,product-index,barcode-labels}.blade.php`, `resources/views/livewire/pages/inventory/*.blade.php`, `resources/views/livewire/pages/catalog/*.blade.php`.
  - `resources/views/livewire/pages/catalogs/catalog-composer.blade.php`, `resources/views/catalogs/public.blade.php`, `resources/views/sales/public-share.blade.php`.
  - `app/Support/Settings/SettingsNavigation.php`, `app/Livewire/Pages/SettingsIndex.php`, `resources/views/components/settings-shell.blade.php`, `resources/views/livewire/pages/settings-index.blade.php`, `resources/views/livewire/pages/sales/settings/*.blade.php`, `resources/views/livewire/pages/purchasing/settings/purchase-settings-form.blade.php`, `resources/views/livewire/pages/expenses/expense-category-index.blade.php`, `resources/lang/{ar,en}/*.php`.
- **Phase F — Platform Control Plane & Explicit Deferred Items**:
  - `resources/views/platform/{index,show,create,audit,support-banner}.blade.php`, `resources/views/platform/subscriptions/show.blade.php`, `resources/views/layouts/platform.blade.php`.
  - **Hold Gate (Explicitly Deferred)**: AI demand-mounting and client bundle splitting remain **ON HOLD** pending an explicit measured phase decision; do not activate incidentally.
