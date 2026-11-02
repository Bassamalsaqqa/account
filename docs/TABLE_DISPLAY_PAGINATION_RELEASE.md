# Table display and pagination correction

Baseline: `1241f829e2be97265507882819a9a8f0078c756f`.

Human-facing amounts use two decimal places; quantities remove trailing zeros without rounding meaningful fractions. Stored decimals, editable fields, accounting calculations, historical snapshots, API data and CSV exports keep their original precision. Currency-specific canonical formatting remains available independently of the display policy.

Record lists default to 50 rows and offer 50, 100 or 200. Both HTTP and Livewire requests enforce these bounds. Reports retain their existing safe paging window; internal queries and exports can still request smaller batches. Authorized derived payroll rows are paginated only after complete balances have been calculated. Product purchase history uses the existing authorization and posted-provenance checks. Changing page size or relevant filters resets pagination. Document line editors, complete printable statements and small summary/configuration tables remain complete rather than hiding financial lines behind pagination.

Tables use compact single-line cells, stable action columns and existing mobile cards. Product cards also cover tablet widths. Arabic and English labels, numeric direction, accessible page-size labels and 44px row actions are preserved. The design follows the restrained table/row-action/pagination approach in the [shadcn data-table guide](https://ui.shadcn.com/docs/components/data-table), implemented in the existing Blade/Livewire stack without new dependencies.

Owner impersonation retains full normal tenant workspaces and existing canonical write permissions. The regression matrix checks products, customers, vendors, quotations, invoices, returns, receipts, purchasing, payments, money, checks, transfers, catalogs and settings in both languages, while preserving the real administrator login and clean exit. Personal credential changes and platform administration remain separate security boundaries.

## Verification checkpoint

- Focused isolated MariaDB gate: 77 tests, 2,286 assertions, exit 0.
- Final display/paging/precision/isolation suite: 8 tests, 58 assertions, exit 0.
- PHPStan: no errors. JavaScript: 44 passed. Composer validation/platform/audit and npm clean install/audit: exit 0, no known advisories.
- Production build: exit 0. No source maps; only manifest-referenced build files are packaged.
- Synthetic Edge checks: AR/EN desktop, tablet and mobile; 50/100/200, search, compact numeric output and responsive actions. Physical-device acceptance remains Owner testing.
- Complete frozen-source QA: pending final receipt. No release acceptance is inferred from focused passes.

## Controlled release boundaries

No migrations, dependency changes, financial writers, company policy changes, new grants or stock import are part of this correction. Subscription enforcement stays globally and per company OFF. Existing production data and posted inventory must remain identical.

Deployment requires an exact source identity, fresh coherent recovery snapshot, verified off-host hashes, isolated restore/boot, six reconciliations for every applicable company, financial/security preservation checks and authenticated AR/EN checks before reopening. Rollback is application/assets only; never overwrite newer transactions with an old database.

Keep the existing configured Hostinger application and public roots. Replace the complete manifest-verified build under maintenance, remove staging after verification and retain recoverable rollback assets outside the public root. Do not migrate the subdomain or overlay old hashed assets.
