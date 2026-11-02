# Table display and pagination correction

Baseline: `1241f829e2be97265507882819a9a8f0078c756f`.

Human-facing amounts use two decimal places; quantities remove trailing zeros without rounding meaningful fractions. Stored decimals, editable fields, accounting calculations, historical snapshots, API data and CSV exports keep their original precision. Currency-specific canonical formatting remains available independently of the display policy.

Record lists default to 50 rows and offer 50, 100 or 200. Both HTTP and Livewire requests enforce these bounds. Reports retain their existing safe paging window; internal queries and exports can still request smaller batches. Authorized derived payroll rows are paginated only after complete balances have been calculated. Product purchase history uses the existing authorization and posted-provenance checks. Changing page size or relevant filters resets pagination. Document line editors, complete printable statements and small summary/configuration tables remain complete rather than hiding financial lines behind pagination.

Tables use compact single-line cells, stable action columns and existing mobile cards. Product cards also cover tablet widths. Arabic and English labels, numeric direction, accessible page-size labels and 44px row actions are preserved. The design follows the restrained table/row-action/pagination approach in the [shadcn data-table guide](https://ui.shadcn.com/docs/components/data-table), implemented in the existing Blade/Livewire stack without new dependencies.

The platform shell shares the tenant font, semantic colours, typography and control sizing while retaining its separate administration permissions and navigation. Billing review text uses exact string/BigInt rounding for two-decimal display; the submitted decimal strings remain unchanged. Report and assistant presentation distinguish money, quantities and numeric identifiers, preserving leading-zero barcodes and raw report/export values.

Owner impersonation retains full normal tenant workspaces and existing canonical write permissions. The regression matrix checks products, customers, vendors, quotations, invoices, returns, receipts, purchasing, payments, money, checks, transfers, catalogs and settings in both languages, while preserving the real administrator login and clean exit. Personal credential changes and platform administration remain separate security boundaries.

## Verification checkpoint

- Focused isolated MariaDB gate: 77 tests, 2,286 assertions, exit 0.
- Final display/paging/precision/isolation suite: 8 tests, 58 assertions, exit 0.
- PHPStan: no errors. JavaScript: 44 passed. Composer validation/platform/audit and npm clean install/audit: exit 0, no known advisories.
- Production build: exit 0. No source maps; only manifest-referenced build files are packaged.
- Synthetic Edge checks: AR/EN desktop, tablet and mobile; 50/100/200, search, compact numeric output and responsive actions. Physical-device acceptance remains Owner testing.
- Corrected-source regression package: 198 tests, 2,002 assertions, exit 0, including statement rendering, unchanged historical JOD values, payroll redaction, inventory valuation, report/CSV precision, mocked assistant output and platform billing.
- Final supporting gates: Composer validation/platform/audit, npm clean install/audit and production build exit 0; 46 JavaScript tests passed. No known dependency advisories.
- Complete QA on `193e645efc2efb544eaf63a69cc57a4b8c118993`: exit 1, 3,769 passed, seven failed, 44,390 assertions. Six failures retained older JOD display expectations; one required an empty array after the view adopted an empty paginator. Their corrected tests retain exact historical-value and redaction assertions and pass in the focused package. Four Windows symlink warnings do not certify downstream Linux assertions.
- The full retry on `d248753e512d125005ca4f6398320546aba685a4` was intentionally interrupted after source review found that aging buckets (`days_1_30`, etc.) must be money while minimum stock and elapsed days must be quantities. Pint and PHPStan passed before interruption; this is not a complete QA pass. A focused regression reproduced the defect. The corrected shared classifier passes the related regression package: **199 tests, 2,033 assertions, exit 0**, including all seven earlier full-run failures and preservation of raw historical values.
- Final synthetic Edge confirmation passed for AR/EN billing previews (two-decimal review text with unchanged six-place form input), aligned sticky action headers, tablet product cards and English mobile reflow. Selecting 100 rows displayed all 53 synthetic products. Temporary viewport overrides were reset. Physical-device acceptance remains Owner testing.
- Release acceptance remains HOLD pending the final complete frozen-source QA receipt and controlled production gates. No merge or deployment has occurred for this correction.
- Authentication verification uses normal browser login/password confirmation. A proposed stored-session proof inspection was rejected and was not executed; those legacy deployment inspectors are retired. The replacement procedure requires fresh, source-bound AR/EN browser receipts and checks financial/security preservation after the browser reads. Normal language switching must restore the original locale; only the verifying Owner's resulting `updated_at` metadata may differ. Credentials, other user fields and all other users remain exact. Nineteen synthetic deployment-gate cases passed, including rejection of broader user, credential, financial and stale-receipt changes.

## Controlled release boundaries

No migrations, dependency changes, financial writers, company policy changes, new grants or stock import are part of this correction. Subscription enforcement stays globally and per company OFF. Existing production data and posted inventory must remain identical.

Deployment requires an exact source identity, fresh coherent recovery snapshot, verified off-host hashes, isolated restore/boot, six reconciliations for every applicable company, financial/security preservation checks and authenticated AR/EN checks before reopening. Rollback is application/assets only; never overwrite newer transactions with an old database.

Keep the existing configured Hostinger application and public roots. Replace the complete manifest-verified build under maintenance, remove staging after verification and retain recoverable rollback assets outside the public root. Do not migrate the subdomain or overlay old hashed assets.
