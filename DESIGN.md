# Small Trader Accounting — Design System & Workflow Architecture

Inspired by BoardUI and shadcn principles, adapted to Laravel 13, Livewire 4, Tailwind CSS 4, and Alpine.js.

## Design Philosophy

The application is built for everyday high-frequency business operations. It prioritizes clarity, informational density, rapid keyboard entry, and touch-first usability without superfluous decorative ornamentation.

1. **Semantic Color & Token System**:
   - Canvas / Ground: `bg-canvas` (`#f8fafc` / light calm slate).
   - Surfaces: `bg-surface` (`#ffffff`), `bg-surface-soft` (`#f1f5f9` for subtle trays/wells).
   - Borders: `border-border` (`#e2e8f0` hairlines), `border-border-strong` (`#cbd5e1` controls).
   - Accent & Primary: `--color-primary: #255fd6`, hover `#1f52bd`, light tints `#eef4ff`.
   - **Rich Subtle Colored Surfaces (BoardUI-Inspired)**:
     - Sales Metric Card: Subtle emerald surface (`#f0fdf4`, border `#bbf7d0`, text `#166534`).
     - Purchases Metric Card: Subtle blue surface (`#eff6ff`, border `#bfdbfe`, text `#1e40af`).
     - Cash Register Card: Subtle amber surface (`#fffbeb`, border `#fde68a`, text `#92400e`).
     - Bank Account Card: Subtle sky surface (`#f0f9ff`, border `#bae6fd`, text `#0369a1`).
     - Checks Held Card: Subtle purple surface (`#faf5ff`, border `#e9d5ff`, text `#6b21a8`).
   - Semantic Status Tokens:
     - Success / Posted / Paid: `--color-success: #14804a`, background `#eaf8f0`.
     - Warning / Pending: `--color-warning: #a15c00`, background `#fff5df`.
     - Danger / Void / Alert: `--color-danger: #b42318`, background `#fff0ee`.
     - Draft / Neutral: slate soft surface with subtle border.

2. **Bilingual Arabic RTL & English LTR Typography**:
   - Primary Arabic Font: `IBM Plex Sans Arabic` with system fallback (`Tahoma, Arial, sans-serif`).
   - Monospace & Numbers: All money amounts, quantities, SKUs, barcode values, document numbers, and timestamps use `font-mono tabular-nums` with explicit `dir="ltr"` and `unicode-bidi: isolate`.
   - Clear Visual Hierarchy:
     - Page Title: `text-xl md:text-2xl font-bold text-text-primary tracking-tight`
     - Section Heading: `text-base md:text-lg font-semibold text-text-primary`
     - Table Column Headers: `text-xs font-semibold uppercase tracking-wider text-text-secondary`
     - Body / Table Cell: `text-sm text-text-primary`
     - Caption / Helper Text: `text-xs text-text-muted`

3. **Desktop Working Density & 44px Mobile Targets**:
   - Desktop: Compact 36px–38px buttons and inputs, tight one-line table rows with sticky action column.
   - Mobile: 44px minimum touch targets on all interactive controls (`.touch-action`, `min-h-[44px]`).
   - iOS Zoom Prevention: Form inputs use `16px` font size on screens `< 768px` to prevent browser auto-zoom.

4. **Shared Component Architecture**:
   - **App Shell**: 264px fixed sidebar with persistent state in `sessionStorage` (`accounting.sidebar.open` and `accounting.sidebar.scroll`), sticky topbar with search, tenant switcher, and language toggle.
   - **Settings Hub**: Two-column layout with persistent discoverable Section Index sidebar on desktop, horizontal pill scroller on mobile, and URL deep-linking (`#[Url(as: 'section')]`). Compact 2-column form grids with isolated saves.
   - **Document Sharing**: One-click Quick Share action card (30-day default expiry, interface locale auto-inherited, instant copy & feedback), with deferred history/QR computation.
   - **Data Tables**: Striped hover rows, server-side pagination with 50/100/200 choices, sticky header, and end-anchored row actions.
   - **Dialogs & Overlays**: Accessible `dialog-shell` with focus trap, backdrop click, Escape key dismiss (gated during in-flight submission), and Alpine v3 `$cleanup` hook.
   - **Stat Cards**: Clean KPI cards with subtle colored surfaces, distinct icon circles, and bold tabular amounts.
   - **Feedback**: Inline actionable alert banners for errors, non-intrusive action toasts for temporary confirmations.

5. **Motion & Transitions**:
   - Targeted, finite micro-interactions (color, opacity, border-color) avoiding generic `transition: all`.
   - Full support for `prefers-reduced-motion: reduce` (`transition: none !important`).
   - Zero artificial waits, spinners, or simulated delays. Loading states preserve preceding context (`opacity-70`).
