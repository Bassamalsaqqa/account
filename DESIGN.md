# Small Trader Accounting — Design Constitution & UI Architecture

Native UI constitution for Laravel 13, Livewire 4, Tailwind CSS 4, and Alpine.js (`Blade` + CSS custom properties). No `.impeccable.md`, `components.json`, or React runtime exists in this repository; this document, [`docs/UI_WORKFLOW_RULES.md`](docs/UI_WORKFLOW_RULES.md), [`docs/UX_ROUTE_WORKFLOW_MATRIX.md`](docs/UX_ROUTE_WORKFLOW_MATRIX.md), [`PRODUCT.md`](PRODUCT.md), and [`references/ui/`](references/ui/) are the repository authorities.

## 1. Visual Constitution & CSS Token Authority

Built for high-frequency bilingual trade, treasury, and inventory workflows. **Clarity beats decoration.**

1. **Single Token Source of Truth:** Exact colors, typography, radii, and spacing live in [`resources/css/app.css`](resources/css/app.css) (`@theme`), [`resources/css/business-dashboard.css`](resources/css/business-dashboard.css), and [`resources/css/data-tables.css`](resources/css/data-tables.css). Do not duplicate hex ramps in docs.
2. **Semantic Grounds & Accents:**
   - Neutral grounds (`--color-canvas`, `--color-surface`) hold pages, cards, tables, dialogs, and KPI tiles; `--color-surface-soft` is reserved for passive secondary trays/headers (never nest cards just to hold metrics or tabs).
   - Structural hairlines use `--color-border`; interactive inputs/controls use `--color-border-control` (`>= 3.0:1` WCAG 2.2 AA non-text contrast against surface/canvas).
   - Saturated accents (`--color-primary` / `--color-primary-hover`, `--color-success`, `--color-warning`, `--color-danger`, `--color-info`, `--color-checks`) are earned for primary actions, active navigation/tabs, focus rings, financial direction, and alert/lifecycle states — always paired with localized text labels, never color alone.
3. **Banned Visual Patterns:**
   - No pale pastel washes across KPI/wallet/shortcut tiles, rainbow metric stacks, decorative top/side stripes on every card, ornamental icon bubbles beside page titles/KPI headlines, or disabled `"Coming soon"` placeholders.
   - Light-mode tokens ship currently; keep semantic custom properties layered cleanly when extending themes and verify contrast against real browser renders.
4. **Contextual Help & Disclosures:**
   - Keep concise localized notes where decisions carry consequence (irreversible post/void/reversal, missing FX, server-redacted permissions, negative-stock/expiry rules, tax limits, or sharing expiry/scope). Omit decorative taglines.

## 2. Typography, RTL/LTR Direction & Touch Ergonomics

- **Typefaces & Scale:** Self-hosted `IBM Plex Sans Arabic` (`--font-arabic`, `--font-sans`) with `tabular-nums` monospace for ledger numerals. Baseline: page title (`20–24px`), section heading (`16–18px`), body/table/control (`14px`), inputs (`>= 16px` `< 768px` to prevent iOS auto-zoom), metadata (`12–13px`). Zero micro-type (`text-[9px]`/`text-[10px]`/`text-[11px]`).
- **Bilingual Logical Layout:** Use CSS logical utilities (`ps-`/`pe-`, `ms-`/`me-`, `start-`/`end-`, `text-start`/`text-end`) across Arabic RTL and English LTR. Isolate amounts, quantities, barcodes, SKUs, document numbers, dates, and URLs in `<bdi dir="ltr">` with `tabular-nums`. Display and enter money with two decimal places and trim trailing zeros on quantities without truncating exact `BigDecimal` values.
- **Responsive Targets & Controls:** Compact `36–40px` desktop density (`>= 1024px` fine pointer) and `>= 44×44px` touch targets on mobile/coarse pointers (`< 1024px`). Native `<select>`, `<input>`, and accessible toggles come first; reserve `x-searchable-combobox` for high-cardinality pickers (products, customers, vendors, warehouses) with top-layer viewport-aware positioning.
- **Single Interactive Tree:** Editable transaction rows use one responsive DOM tree per line (`line_uid` keys, `.document-entry-row`), never duplicated hidden desktop/mobile form subtrees. Dialogs reuse `x-dialog-shell` with safe Escape/backdrop dismissal and focus return.

## 3. Current Architecture & Hold Gates

- **App, Guest & Platform Shells:** Stationary `264px` / `76px` sidebar (`layouts/app.blade.php`), categorized `layouts/platform.blade.php`, guest shell (`layouts/guest.blade.php`) with `<x-release-version />`, and shared token-aligned error views (`resources/views/errors/{layout,403,404,419,429,500,503}.blade.php`).
- **Diagnostics Reporter:** Authenticated lazy-loaded problem reporter (`<x-problem-reporter />` in `app` and `platform` shells only; **no guest reporter**). Never auto-captures form inputs or business data; user notes stay untrusted and omitted from agent briefs (`docs/UX_ROUTE_WORKFLOW_MATRIX.md`).
- **Core Workflows:**
  - Transaction forms enforce `Totals -> Discount/Notes -> Grand Total` before `Immediate Settlement` in DOM and visual grids.
  - Document details show one cohesive summary band (`Total`, `Paid/Applied`, `Remaining`, `Due Date`) plus inline permitted actions and `FinancialShareManager` (clearing displayed URL/QR/selection on date or issuance-setting changes while keeping issued links recoverable in history).
  - Catalogs and Barcode Labels live under **Products** (retaining `/settings?section=catalogs` redirect compatibility); Settings uses categorized navigation groups (`SettingsNavigation`).
- **Explicit Hold Gates:** Demand-mounted AI, client bundle splitting, and cold-route latency qualification remain **ON HOLD** until a separate measured phase decision.
