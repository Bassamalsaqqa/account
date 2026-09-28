# CODEX_PHASE0_START_PROMPT.md
## Small Trader Accounting — Start the Greenfield Repository
### Phase 0 only

You are starting a new greenfield application in an otherwise empty repository.

Before changing anything, read these files in full:

1. `AGENTS.md`
2. `docs/SMALL_TRADER_ACCOUNTING_MASTER_SPEC_v1.0.md`
3. `docs/ENGINEERING_BLUEPRINT_v1.0.md`

Then inspect all **approved** visual references under:

```text
references/ui/
```

These may contain PNG/JPG screenshots, HTML prototypes, and optional notes.

Do not use rejected Stitch experiments as authority.

---

# Authority order

When sources disagree:

1. Product behavior/scope:
   `SMALL_TRADER_ACCOUNTING_MASTER_SPEC_v1.0.md`
2. Architecture/data/security/accounting/inventory:
   `ENGINEERING_BLUEPRINT_v1.0.md`
3. Agent guardrails:
   `AGENTS.md`
4. Approved screenshots:
   visual authority for layout, hierarchy, density, and appearance.
5. Approved HTML prototypes:
   structural and styling reference only.

Rules:

- Screenshots are the visual target.
- HTML prototypes are NOT production architecture.
- Do not copy standalone prototype HTML/CSS/JS wholesale into Laravel.
- Extract reusable visual rules and implement them using Laravel + Livewire + Tailwind.
- Product and engineering specifications override a prototype if behavior conflicts.

---

# Task

Implement **Phase 0 only** from the Engineering Blueprint.

Do not begin Phase 1.

Do not implement real Customers, Vendors, Products, Inventory, Sales, Purchases, Payments, Checks, Expenses, Ledger, or Reports.

Placeholder/demo content may be used only to prove the shell and reusable UI system.

---

# Required production stack

Use:

- Laravel 13
- PHP 8.4 baseline
- Laravel Livewire 4
- Blade
- Tailwind CSS 4
- Vite
- MySQL
- official Laravel Livewire starter/auth foundation
- PHPUnit
- Laravel Pint
- Larastan/PHPStan

Flux components supplied by the starter kit may be used when useful, but their default appearance must not dictate the product identity.

Do NOT switch to React, Vue, Inertia, Supabase, static-only HTML, microservices, or a permanent Node runtime.

The application must remain deployable on Hostinger shared hosting.

---

# Approved visual direction

The approved UI references define the actual product direction.

Translate their common visual language into one reusable design system.

Current intended character:

- Arabic-first;
- excellent RTL;
- English LTR-safe;
- serious production business software;
- compact but not cramped;
- strong numeric hierarchy;
- restrained cobalt/slate/neutral palette where supported by references;
- near-flat bordered surfaces;
- subtle shadows;
- restrained radii;
- controls around 40–44px where appropriate;
- strong dense tables;
- clean forms;
- readable financial values;
- minimal decorative clutter;
- no generic AI-dashboard look;
- no glassmorphism;
- no huge gradients;
- no card soup;
- no excessive whitespace.

If an approved screenshot establishes a more specific visual choice, follow it.

---

# Step 1 — Bootstrap correctly

Create the Laravel 13 project directly in this repository without nesting it inside another unnecessary directory.

Preserve supplied documentation/reference files.

Do not overwrite/delete:
- `AGENTS.md`
- `docs/*`
- `references/*`
- this prompt file.

Configure the official Livewire starter authentication foundation.

Public self-registration must be configurable and OFF by default for initial private deployment.

Keep framework-supported 2FA capability available for later use.

---

# Step 2 — Establish design tokens before individual pages

Inspect approved screenshots/HTML references first.

Define shared semantic tokens for:

## Colors
- app canvas;
- primary surface;
- secondary/subtle surface;
- border;
- stronger border;
- primary text;
- secondary text;
- muted text;
- primary interactive;
- hover/active;
- success;
- warning;
- danger;
- information;
- focus ring.

Do not create page-specific variables such as `--dashboard-blue`.

## Typography
Define:
- Arabic font;
- Latin/system font;
- page title;
- section title;
- body;
- small/meta;
- table text;
- numeric/money treatment.

Use tabular numerals where useful.

If approved references consistently use IBM Plex Sans Arabic, prefer it through a deployment-safe web-font strategy. Do not redistribute font files.

## Spacing
Create a small coherent spacing scale.

## Radius
Use restrained semantic radius values.

## Layout
Define:
- desktop sidebar width;
- collapsed sidebar behavior if used;
- top bar dimensions;
- mobile header/navigation;
- page gutters;
- standard section spacing;
- content widths.

Do not hard-code unrelated arbitrary spacing independently on each page.

---

# Step 3 — Build reusable components/patterns

Build only what Phase 0 needs, but establish correct primitives.

Likely patterns include:

- AppShell
- Sidebar
- MobileNavigation
- TopBar
- PageHeader
- Breadcrumb
- Button variants
- IconButton
- TextInput
- SearchInput
- Select/combobox presentation
- Badge
- StatusBadge
- Surface/Card
- Metric
- Toolbar
- FilterBar
- DataTable presentation
- mobile list/card pattern
- Tabs
- EmptyState
- Loading/Skeleton
- InlineAlert
- ValidationSummary
- Modal
- Drawer/Sheet
- Dropdown
- Pagination
- Toast/feedback

Do not create components merely to satisfy this list.

Prefer reusable patterns actually evidenced by the approved references.

---

# Step 4 — Build the real responsive shell

Implement the approved shell as production code.

Requirements:

- Arabic RTL primary;
- English LTR;
- desktop navigation;
- tablet adaptation;
- mobile navigation;
- company/product identity area;
- user/account menu;
- global-search entry point placeholder;
- quick-create entry point placeholder;
- accessible focus states;
- active navigation treatment;
- no horizontal overflow;
- touch-safe tablet/mobile controls.

Future-module navigation may be shown only as clearly nonfunctional development placeholders. Do not pretend unfinished routes exist.

---

# Step 5 — Localization foundation

Create:

```text
resources/lang/ar/
resources/lang/en/
```

Arabic is default.

Root direction:

```text
ar => rtl
en => ltr
```

Do not hard-code visible shell strings.

Mixed-direction content must remain readable:
- SKU;
- barcode;
- phone;
- email;
- currency codes;
- money/numbers.

---

# Step 6 — Responsive proof

Validate intentionally at approximately:

```text
390px   phone
768px   tablet portrait
1024px  tablet / compact laptop
1440px  desktop
```

Responsive does not mean only "nothing overflows".

Ensure:
- mobile navigation is deliberate;
- dense desktop structures have deliberate mobile alternatives;
- primary actions remain reachable;
- touch controls are usable;
- no workflow depends on hover.

---

# Step 7 — Visual proof screen

Implement one approved reference as a visual-system proof, preferably the approved shell/settings direction because it does not require real financial-domain logic.

It may use static demonstration data.

Requirements:
- strong screenshot fidelity;
- reusable tokens/components;
- Arabic RTL;
- English LTR;
- desktop/tablet/mobile behavior.

Do not implement real settings persistence beyond Phase 0 needs.

Do not implement real financial dashboard logic.

---

# Step 8 — Optional development UI specimen

If useful, add a development-only route such as:

```text
/dev/ui
```

showing typography, buttons, fields, badges, surfaces, table patterns, mobile cards, alerts, and empty/loading/error states.

It must not be publicly enabled in production.

If this adds more complexity than value, omit it and document components another way.

---

# Step 9 — Authentication foundation

Keep the official starter authentication architecture.

Verify:
- sign in;
- sign out;
- password reset foundation;
- rate limiting remains intact;
- registration configuration works;
- protected routes require auth.

Do not build company tenancy yet. That is Phase 1.

---

# Step 10 — Engineering quality foundation

Configure:

- Laravel Pint;
- Larastan/PHPStan;
- PHPUnit;
- MySQL testing;
- deterministic Composer QA script;
- production frontend build.

Use MySQL for DB-backed tests.

Do not treat SQLite as authoritative for DB integration behavior.

Provide a command such as:

```text
composer qa
```

and verify:

```text
npm run build
```

---

# Step 11 — Shared-hosting compatibility

Do not introduce requirements for:
- Redis;
- Supervisor;
- WebSockets;
- Docker;
- permanent Node service;
- server-side React.

Vite is build-time.

---

# How to use prototype HTML

For each approved HTML prototype:

DO:
- inspect hierarchy;
- extract spacing/radius/color/type patterns;
- identify reusable components;
- understand responsive intent;
- recreate the visual system idiomatically in Tailwind/Livewire.

DO NOT:
- paste the whole page unchanged into Blade;
- retain giant page-local CSS;
- preserve prototype fake JS architecture;
- copy fake financial calculations;
- introduce another frontend framework;
- duplicate CSS screen by screen.

Goal:

> visual fidelity + reusable production architecture

not literal source-code fidelity.

---

# Tests required in Phase 0

At minimum verify:

1. application boots;
2. sign-in page loads;
3. protected app route requires authentication;
4. public registration follows configuration;
5. Arabic locale renders RTL;
6. English locale renders LTR;
7. production shell works in both directions.

Add more tests where they protect the foundation.

---

# Completion gate

Before declaring Phase 0 complete:

Run:

```text
composer qa
npm run build
```

Run migrations/tests against MySQL.

Review shell/proof screen at:

```text
Arabic RTL:
- phone
- tablet
- desktop

English LTR:
- phone
- desktop
```

Correct meaningful discrepancies from approved references.

Do not begin Phase 1.

---

# Final report

Report:

1. exact Laravel/PHP/Livewire/Tailwind/package versions selected;
2. files/directories created;
3. design tokens/components established;
4. authentication/configuration choices;
5. localization/RTL behavior;
6. responsive behavior;
7. MySQL test setup;
8. tests added and results;
9. `composer qa` result;
10. `npm run build` result;
11. deviations from specifications;
12. unresolved Hostinger assumptions;
13. confirmation that no business-domain modules were implemented.

Stop after Phase 0.
