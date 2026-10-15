# Expiry report reference-date correction

Baseline: `360f7bcef3bb14041c3cfd52731f39f71a0419be`.

`ExpiryReportQuery` classified an expiry equal to its report end/reference date as Valid. `ExpiryCenter` and canonical inventory expiry checks treat only dates strictly before the effective date as expired; the reference date belongs inside the warning window. The narrow correction changes the Soon lower bound from `>` to `>=`, preserving the report's existing inclusive 30-day upper bound and strict expired comparison.

An owned disposable MariaDB test creates six lots through canonical opening stock: reference minus one, equal, plus one, exact 30-day upper bound, first day outside the window and unknown expiry. It checks each status, counts, status filters and zero economic writes during the query. The older report fixture was updated so its Valid control is actually outside the warning window.

- Baseline boundary replay: 1 test, 33 assertions, one failure (Soon count 2 rather than 3).
- Corrected boundary test and existing position-inventory report tests: **8 tests, 175 assertions passed**, PHP 8.4.25, owned disposable MariaDB schema cleaned.
- Dirty-file Pint and targeted PHPStan on the changed query and both test files passed.
- No schema, writer, expiry allocation policy or historical stock changes.
- No production access, merge or deployment. Operational recovery/restoration/password-reset email checks remain separately outstanding.
