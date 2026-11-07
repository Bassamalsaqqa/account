# Native UI redesign batch — 7 November 2026

Baseline: `06e4f6d`. No commit, push, release, server startup or production access.
Existing dirty accounting, purchase, menu and AI changes were retained. This receipt describes only this writer's presentation changes; repository-wide diff statistics include earlier work.

## Implemented

- Shared semantic surface/control foundation; additive `tone` on existing card, invalid-input state on existing text-input, button spacing and icon sizing, restrained summary-card hierarchy, dropdown overlay border/shadow. Existing props and attribute bags retained.
- Shared `page-heading` composed into product, invoice and expense indexes; authorized actions retained. Removed duplicate expense subtitle and redundant product-search instruction; invoice count remains visible.
- Stationary shell geometry and storage code retained; shell surfaces and navigation type unified. No new requests, caches, runtime, dependencies or artificial delays.
- Dashboard keeps its original permission-scoped read model, exact display strings and separate currency balances. Semantic tinted activity/treasury cards and quieter horizontal shortcuts replace the multi-palette shortcut tiles. Date controls no longer force a 40px maximum on mobile.
- Native template propagation across sales/purchases/returns/payments, products/inventory/catalog, money/checks/transfers, expenses/payroll, settings/profile/AI, auth, public share and platform: surfaces, semantic feedback, readable control labels and removal of 9/10/11px utility typography. This is template-level styling, not a claim of redesigned workflow logic.
- Settings feedback dismiss controls now expose accessible names. Pending queues, permission conditions, redaction, iframe print behavior and exact decimal contracts were not rewritten.

## Official reference mapping

Sources inspected through official MCP tools on this date, free tier only:

| Source | Native mapping / decision |
| --- | --- |
| [BoardUI button](https://www.boardui.com/components/button) | Shared primary/secondary buttons: compact semantic variants, icon spacing, focus and disabled behavior. Project's 44px mobile minimum supersedes upstream small sizes. |
| [BoardUI sidebar](https://www.boardui.com/components/sidebar) | Shell surface hierarchy; existing 264/76px geometry and native storage retained. |
| [BoardUI stat cards](https://www.boardui.com/components/stat-cards) | Dashboard label/value hierarchy and restrained tints; no demo deltas or animation. |
| [BoardUI switch](https://www.boardui.com/components/switch) | Reviewed against existing native details-toggle; binary semantics, peer focus and RTL thumb retained. |
| [BoardUI modal](https://www.boardui.com/components/modal) | Reviewed against existing dialog-shell; native focus trap and in-flight dismissal protection retained. |
| [BoardUI dropdown](https://www.boardui.com/components/dropdown) | Bordered elevated panel; existing native outside/Escape/navigation dismissal retained. |
| [BoardUI table](https://www.boardui.com/components/table) | Quiet surfaces and legible cell hierarchy; existing native pagination and mobile table workflows retained. |
| [BoardUI input](https://www.boardui.com/components/input) | Existing text-input invalid/disabled state and readable labels. |
| [BoardUI public dashboard](https://www.boardui.com/templates/dashboard) | Public visual reference for shell/card/table hierarchy only; no template source fetched. |
| [shadcn button](https://ui.shadcn.com/docs/components/button), [card](https://ui.shadcn.com/docs/components/card), [switch](https://ui.shadcn.com/docs/components/switch) | Registry discovery plus official button-demo/button-group-demo source inspected. Native composition, clear action labels and icon-only accessible names; no React/Radix installation. |

These are original Blade/CSS adaptations of design patterns, not installed official npm components or copied React implementation files. No Pro activation, paid source, branding, demo data or third-party icon source added. Existing third-party notices and licenses remain authoritative.

## Validation actually executed

- `npm run build`: PASS (production Vite build, no source maps requested).
- `php artisan view:cache`: PASS.
- `node --test tests/JavaScript/*.test.mjs`: PASS, 56 tests, 0 failures.
- `php vendor/bin/phpunit tests/Unit/ProductExperience/ShellMenuAndInlineComponentTest.php --filter inline_panel`: PASS, 3 tests / 15 assertions; no database migration or domain suite run.
- Explicit Blade runtime render in English and Arabic of page-heading, secondary-button, card tone and exact document summary amount: PASS.
- `git diff --check`: PASS.
- UI view search for `text-[9px]`, `text-[10px]`, `text-[11px]`: no matches. Physical print/barcode paths were excluded from automated restyling.

## Remaining work and acceptance boundary

### Parent refinements after designer review

- Sidebar links now carry localized accessible names and current-page state; group controls expose expanded state and their controlled region. An explicitly collapsed active group stays collapsed (the rail still keeps destinations reachable).
- Dashboard shortcuts use destination permissions and the report registry's visibility rule.
- Details toggles accept a stable section-specific label without breaking existing callers. Settlement appearance uses an explicit component class instead of its accessibility description selector.
- Login inputs associate error IDs and invalid state. Guest controls and tablet/coarse-pointer controls share touch sizing; desktop input minimums are consolidated.
- Parent ran the nine mobile-shell JavaScript checks and Blade compilation successfully after these changes. Visual qualification and final build remain pending.

NOT visually checked. No browser/server tools were used as instructed. Parent should verify EN/AR at desktop expanded/collapsed, tablet and narrow mobile sizes, keyboard focus, menu clipping and focus return, long names, large/fractional numbers, document queues and real error/loading states before acceptance. Build/Blade rendering do not prove geometry or contrast.

This batch is substantial shared styling and selected index/dashboard composition. Individual form field regrouping, mobile card restructuring, family-specific optional-section switches, every settings/AI/public/platform layout, and contextual payment workflow redesign are NOT claimed complete. Existing workflow behavior is preserved. Other raw palette utilities remain in older templates; future conversion should be contextual rather than assumed fully complete. No correction/void domain UI or new translation keys were fabricated. No purchasing `void_*` template call sites were found in the bounded scan.

Owner acceptance and release remain pending parent review. No performance timings or universal speed claim are asserted.

## Exact presentation files touched by this writer

- `resources/css/app.css`
- `resources/css/business-dashboard.css`
- `resources/views/auth/two-factor-challenge.blade.php`
- `resources/views/components/auth-session-status.blade.php`
- `resources/views/components/badge.blade.php`
- `resources/views/components/camera-scanner.blade.php`
- `resources/views/components/card.blade.php`
- `resources/views/components/document-actions.blade.php`
- `resources/views/components/document-summary-cards.blade.php`
- `resources/views/components/dropdown.blade.php`
- `resources/views/components/modal.blade.php`
- `resources/views/components/money-daily-summary.blade.php`
- `resources/views/components/page-heading.blade.php`
- `resources/views/components/primary-button.blade.php`
- `resources/views/components/purchasing/field.blade.php`
- `resources/views/components/refinement-filters.blade.php`
- `resources/views/components/responsive-nav-link.blade.php`
- `resources/views/components/sales-command-preview.blade.php`
- `resources/views/components/secondary-button.blade.php`
- `resources/views/components/settings-shell.blade.php`
- `resources/views/components/text-input.blade.php`
- `resources/views/components/touch-quantity-stepper.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/dev/ui.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/layouts/guest.blade.php`
- `resources/views/layouts/platform.blade.php`
- `resources/views/livewire/ai/assistant-launcher.blade.php`
- `resources/views/livewire/ai/deep-seek-setup.blade.php`
- `resources/views/livewire/financial-share-manager.blade.php`
- `resources/views/livewire/global-search.blade.php`
- `resources/views/livewire/pages/auth/verify-email.blade.php`
- `resources/views/livewire/pages/catalog/brand-index.blade.php`
- `resources/views/livewire/pages/catalog/category-index.blade.php`
- `resources/views/livewire/pages/catalog/unit-index.blade.php`
- `resources/views/livewire/pages/catalog/warehouse-index.blade.php`
- `resources/views/livewire/pages/catalogs/catalog-composer.blade.php`
- `resources/views/livewire/pages/customers/customer-detail.blade.php`
- `resources/views/livewire/pages/customers/customer-form.blade.php`
- `resources/views/livewire/pages/customers/customer-index.blade.php`
- `resources/views/livewire/pages/expenses/expense-category-index.blade.php`
- `resources/views/livewire/pages/expenses/expense-detail.blade.php`
- `resources/views/livewire/pages/expenses/expense-form.blade.php`
- `resources/views/livewire/pages/expenses/expense-index.blade.php`
- `resources/views/livewire/pages/inventory/expiry-center.blade.php`
- `resources/views/livewire/pages/inventory/inventory-overview.blade.php`
- `resources/views/livewire/pages/inventory/opening-stock-form.blade.php`
- `resources/views/livewire/pages/inventory/partials/lot-picker.blade.php`
- `resources/views/livewire/pages/inventory/stock-adjustment-form.blade.php`
- `resources/views/livewire/pages/inventory/stock-movement-index.blade.php`
- `resources/views/livewire/pages/inventory/stock-transfer-form.blade.php`
- `resources/views/livewire/pages/money/account-detail.blade.php`
- `resources/views/livewire/pages/money/check-detail.blade.php`
- `resources/views/livewire/pages/money/check-form.blade.php`
- `resources/views/livewire/pages/money/check-index.blade.php`
- `resources/views/livewire/pages/money/overview.blade.php`
- `resources/views/livewire/pages/money/transfer-detail.blade.php`
- `resources/views/livewire/pages/money/transfer-form.blade.php`
- `resources/views/livewire/pages/money/transfer-index.blade.php`
- `resources/views/livewire/pages/payroll/employee-advance-form.blade.php`
- `resources/views/livewire/pages/payroll/employee-detail.blade.php`
- `resources/views/livewire/pages/payroll/employee-form.blade.php`
- `resources/views/livewire/pages/payroll/employee-index.blade.php`
- `resources/views/livewire/pages/payroll/salary-entry-form.blade.php`
- `resources/views/livewire/pages/payroll/salary-payment-form.blade.php`
- `resources/views/livewire/pages/products/product-detail.blade.php`
- `resources/views/livewire/pages/products/product-form.blade.php`
- `resources/views/livewire/pages/products/product-index.blade.php`
- `resources/views/livewire/pages/purchasing/payment-detail.blade.php`
- `resources/views/livewire/pages/purchasing/payment-form.blade.php`
- `resources/views/livewire/pages/purchasing/payment-index.blade.php`
- `resources/views/livewire/pages/purchasing/purchase-detail.blade.php`
- `resources/views/livewire/pages/purchasing/purchase-form.blade.php`
- `resources/views/livewire/pages/purchasing/purchase-index.blade.php`
- `resources/views/livewire/pages/purchasing/purchase-return-detail.blade.php`
- `resources/views/livewire/pages/purchasing/purchase-return-form.blade.php`
- `resources/views/livewire/pages/purchasing/purchase-return-index.blade.php`
- `resources/views/livewire/pages/purchasing/settings/purchase-settings-form.blade.php`
- `resources/views/livewire/pages/purchasing/vendor-detail.blade.php`
- `resources/views/livewire/pages/purchasing/vendor-form.blade.php`
- `resources/views/livewire/pages/purchasing/vendor-index.blade.php`
- `resources/views/livewire/pages/reporting/dashboard.blade.php`
- `resources/views/livewire/pages/reporting/hub.blade.php`
- `resources/views/livewire/pages/reporting/report.blade.php`
- `resources/views/livewire/pages/sales/customer-statement-view.blade.php`
- `resources/views/livewire/pages/sales/invoice-detail.blade.php`
- `resources/views/livewire/pages/sales/invoice-form.blade.php`
- `resources/views/livewire/pages/sales/invoice-index.blade.php`
- `resources/views/livewire/pages/sales/payment-detail.blade.php`
- `resources/views/livewire/pages/sales/payment-form.blade.php`
- `resources/views/livewire/pages/sales/payment-index.blade.php`
- `resources/views/livewire/pages/sales/quotation-detail.blade.php`
- `resources/views/livewire/pages/sales/quotation-form.blade.php`
- `resources/views/livewire/pages/sales/quotation-index.blade.php`
- `resources/views/livewire/pages/sales/return-detail.blade.php`
- `resources/views/livewire/pages/sales/return-form.blade.php`
- `resources/views/livewire/pages/sales/return-index.blade.php`
- `resources/views/livewire/pages/sales/settings/document-sequence-settings.blade.php`
- `resources/views/livewire/pages/sales/settings/document-settings-form.blade.php`
- `resources/views/livewire/pages/sales/settings/money-account-settings.blade.php`
- `resources/views/livewire/pages/sales/settings/tax-rate-settings.blade.php`
- `resources/views/livewire/pages/settings-index.blade.php`
- `resources/views/livewire/partials/quick-customer-modal.blade.php`
- `resources/views/livewire/partials/quick-vendor-modal.blade.php`
- `resources/views/livewire/profile/delete-user-form.blade.php`
- `resources/views/livewire/profile/update-profile-information-form.blade.php`
- `resources/views/platform/audit.blade.php`
- `resources/views/platform/create.blade.php`
- `resources/views/platform/denied.blade.php`
- `resources/views/platform/health.blade.php`
- `resources/views/platform/index.blade.php`
- `resources/views/platform/pagination.blade.php`
- `resources/views/platform/show.blade.php`
- `resources/views/platform/subscriptions/show.blade.php`
- `resources/views/platform/support-audit.blade.php`
- `resources/views/platform/support-banner.blade.php`
- `resources/views/platform/support-denied.blade.php`
- `resources/views/profile.blade.php`
- `resources/views/profile/two-factor-authentication.blade.php`
- `resources/views/sales/public-share.blade.php`
- `resources/views/tenancy/select.blade.php`
- `resources/views/tenancy/setup.blade.php`
- `resources/views/welcome.blade.php`
