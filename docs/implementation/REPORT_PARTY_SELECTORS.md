# Role C report selectors — implementation handoff

Base: PR87 `912aa71f557b314837e70138df22615902fc9d7b`, branch `t3/report-party-selector-canonical`.

Uses the existing ReportFilterOptions read lookup and canonical report queries, with one shared Blade search/select control (`<x-report-party-selector>`). Search is at most 100 characters; result pages remain 25 records plus one explicitly selected same-company identity. Only party lookups change ordering to localized name with ID tie-break. Existing report permission plus master-list permissions remain required; missing permissions never become raw-ID entry controls. CSV query validation, same-company ID validation, chunk reauthorization and PDF delivery remain in their existing canonical paths.

Custom report dates now show adjacent localized errors for missing/inverted ranges and recover after correction. The standalone customer detail statement defaults dates in company timezone and rechecks fresh membership, permission and customer ownership on render. It remains customer-bound; there is no standalone SupplierStatement class in this baseline (supplier statements use vendors.statement).

No financial calculation, storage, aggregate, export controller or writer changes. Exact balances and per-currency totals remain canonical. No schema migrations. Summary currency runs are explicitly LTR. Existing opening/closing/charges/credits translations retained.

The shared control also replaces existing search/select markup for non-party report filters (product, warehouse, category and money account), without changing their lookup permissions or queries. Report hub date/control targets are 44px on mobile; desktop remains compact. Standalone detail statement date fields get 44px targets and inline errors. No claim that all report-specific layouts or every standalone family has been redesigned.

Integration gates: ReportFilterOptions and reporting/report.blade.php are Role C shared touchpoints; coordinate other picker/filter changes there. No purchase form/actions, product-create templates, auth/receipt or financial mobile files touched. PR93 currency compatibility commit `1b8a696feda350bbc2df86a26d05b69fd615ba57` was not imported/reimplemented; separately review currency presentation overlap before composition. PR95 remains independent. No old PR94/e111 features imported or run.

## Quality and Verification Evidence (13 November 2026)

- **Disposable MariaDB QA**: `APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE= APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase8/ReportPartySelectorTest.php`
  - Result: 10 tests, 44 assertions passed (0 failures, 0 errors) on disposable schema `accounting_p8_tmp_%` (cleaned up immediately after run).
  - Scope tested:
    1. `test_custom_statement_dates_show_inline_errors_and_recover`: date validation error and recovery.
    2. `test_statement_selectors_use_shared_bounded_search_in_both_languages`: EN and AR search bounds with max 100 char limit.
    3. `test_party_lookup_bounds_results_and_retains_selected_label`: query budget, limit 25, 2 queries maximum.
    4. `test_customer_detail_statement_rechecks_membership_on_refresh`: revoked/inactive membership recheck on live render/refresh.
    5. `test_party_lookup_and_statement_reject_foreign_party`: cross-tenant boundary rejection and 404 on foreign party.
    6. `test_report_view_denies_revoked_membership`: ReportView 403 on revoked membership.
    7. `test_limited_roles_cannot_view_unauthorized_statement`: ReportView 403 when role lacks report permissions.
    8. `test_report_export_with_party_filter_and_revalidates_permissions`: CSV export route enforces party filters and permissions.
    9. `test_dates_default_in_company_timezone`: `fromDate` and `toDate` respect company timezone.
    10. `test_customer_statement_maintains_exact_balances_and_currency_separation`: exact canonical string balances and per-currency separation.
- **Code Style (Pint)**: `vendor/bin/pint --dirty --test` passed clean.
- **Static Analysis (PHPStan)**: `vendor/bin/phpstan analyse app/Application/Reporting/Presentation/ReportFilterOptions.php app/Livewire/Pages/Reporting/ReportView.php app/Livewire/Pages/Sales/CustomerStatementView.php tests/Feature/Phase8/ReportPartySelectorTest.php --debug --no-progress` passed with 0 errors (`[OK] No errors`).
- **Production Asset Build**: `GOMAXPROCS=1 RAYON_NUM_THREADS=1 UV_THREADPOOL_SIZE=1 npm run build` completed in 5.91s with 0 errors and no sourcemaps.
- **Browser Sandbox Verification**: T3 `preview_status` returned `{"available":false}`. Browser sandbox intact; external automated or live browser verification was unavailable. EN/AR mobile/desktop visual geometry, keyboard interaction, and paired browser latency are NOT VERIFIED. No sandbox bypass, hooks, daemon restarts, or unsandboxed fallback was performed.

## Registered party selector call sites

Generated from ReportRegistry definitions; each uses the shared ReportView/report template and ReportFilterOptions path. Product-only/inventory/GL reports without party filters retain their existing report behavior.
- `sales.summary`: customer_id
- `sales.by-period`: customer_id
- `sales.by-customer`: customer_id
- `sales.gross-profit`: customer_id
- `sales.discounts`: customer_id
- `sales.returns`: customer_id
- `sales.unpaid`: customer_id
- `sales.price-history`: customer_id
- `customers.balances`: customer_id
- `customers.statement`: customer_id
- `customers.aging`: customer_id
- `customers.overdue`: customer_id
- `customers.buying-history`: customer_id
- `customers.product-history`: customer_id
- `purchases.summary`: vendor_id
- `purchases.by-period`: vendor_id
- `purchases.by-vendor`: vendor_id
- `purchases.by-product`: vendor_id
- `purchases.returns`: vendor_id
- `purchases.unpaid`: vendor_id
- `purchases.price-history`: vendor_id
- `vendors.balances`: vendor_id
- `vendors.statement`: vendor_id
- `vendors.aging`: vendor_id
- `vendors.purchase-history`: vendor_id
- `vendors.product-history`: vendor_id
- `vendors.price-history`: vendor_id
- `inventory.vendor-products`: vendor_id
- `money.receipts`: customer_id
- `money.vendor-payments`: vendor_id
- `money.checks`: customer_id, vendor_id
- `expenses.summary`: vendor_id
- `expenses.detail`: vendor_id
- `payroll.summary`: employee_id
- `payroll.payments`: employee_id
- `payroll.advances`: employee_id
- `payroll.statement`: employee_id
- `money.incoming-checks`: customer_id, vendor_id
- `money.outgoing-checks`: customer_id, vendor_id
- `money.due-checks`: customer_id, vendor_id
- `money.returned-checks`: customer_id, vendor_id
- `expenses.by-period`: vendor_id
- `expenses.by-category`: vendor_id
- `expenses.by-currency`: vendor_id
- `expenses.trend`: vendor_id
- `expenses.fuel`: vendor_id
- `expenses.delivery`: vendor_id
- `expenses.transport`: vendor_id
- `payroll.unpaid`: employee_id
- `payroll.outstanding-advances`: employee_id
- `payroll.salary-history`: employee_id
