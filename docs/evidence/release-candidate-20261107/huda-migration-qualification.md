# Huda correction migration qualification — 2026-11-07

Status: **PASS for the assigned focused migration-preservation coverage.** This is not full-suite or release acceptance.

Baseline and resulting HEAD: `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`. Existing dirty work was preserved. Only `tests/Feature/Purchasing/CorrectionMigrationPreservationTest.php` and this `huda-migration-qualification*` evidence family were written by this task. No migration, core source, dependency, other test, or other owner's evidence was changed.

## Runtime and ownership

- PHP 8.4.27, PHPUnit 12.5.36, MariaDB 11.8.8 on loopback port 3342.
- Installed Symfony http-foundation, http-kernel and routing are **v8.1.11**. Symfony process remains **v8.1.7**; this qualification does not claim that every Symfony package is 8.1.11. No dependency changes were made.
- The intentionally empty-password `test_runner@127.0.0.1` account has only global USAGE and schema-prefix grants for `accounting_p8_tmp_%`; no PROCESS grant was requested or used.
- The existing isolated runner created its own primary schema and supplied ownership proof. The new class overrides the inherited RefreshDatabase entry point: it verifies that proof, creates/migrates a new helper-owned canonical fixture schema, and switches only to that named connection. It does not refresh, reset or populate the runner's primary schema.
- Canonical purchasing/sales/void/correction actions generate business history in that new fixture schema. Historical cases then create a second NEW helper-owned schema, run the actual repository migration prefix before 000001, and replay only those synthetic canonical rows, projected onto that historical schema's columns. Pre-000002 cases apply 000001 before replay. The replay skips the migrations ledger, preserves the historical prefix's own migration entries, and disables FK checks only within that newly created fixture schema during fixture import.
- No populated canonical schema is rolled backward to manufacture history. No persistent DB, sibling schema, pre-existing history or other worker's schema is adopted, reset or dropped. The helper instances clean only schemas they created; teardown restores named connections in reverse order. Both test runs completed and the isolated runner reported its primary schema cleaned.

## New coverage

Eight test cases, including data-provider cases:

1. **000001, populated forward:** posted purchase and posted return survive introduction of return void columns. Newly introduced columns remain null; existing history/configuration fingerprints remain equal. The five migration permissions are deliberately absent in the historical fixture before up, and up grants them to existing Owner and Manager roles without leaking permission-team state.
2. **000002, journal-backed inference:** canonical void purchase and void return already populate the pre-000002 fixture. Up infers distinct historical business dates, November 6 and November 5 respectively, and preserves existing row contents.
3. **000002, legitimate zero-journal inference:** zero-cost canonical purchase/return voids explicitly have null reversal batch IDs. Up infers their dates from stock history rather than inventing journal rows or using the execution timestamp.
4. **000002, first-table disagreement:** journal-backed purchase void stock date disagrees with its reversal posting date. Up refuses with the precise ambiguity exception, no business-date column on either table, identical complete schema DDL and unchanged rows.
5. **000002, second-table disagreement:** purchase history is valid but return journal/stock dates disagree. Preflight refuses before either table receives DDL; complete DDL and row fingerprints remain unchanged.
6. **000002, second-table ambiguity:** valid zero-journal purchase history precedes a zero-journal return with two distinct stock dates in the historical fixture. Up refuses before altering purchases or returns; complete DDL and row fingerprints remain unchanged. Deliberate corruption is confined to the new historical fixture, never the canonical source fixture.
7. **000004, sales-return-only receipt:** canonical edit correction creates a sales return source/replacement pair and exactly one sales-return receipt, with the other two trade receipt tables empty. Down refuses before any table is removed, and all three trade receipt tables, complete schema DDL and row contents remain unchanged.
8. **000004, purchase-return-only receipt:** the equivalent canonical purchase return correction verifies the third-table preflight case, also preserving all three tables and every row.

The two successful historical 000002 cases continue forward through **000003 and 000004**, verifying all four receipt tables start empty, historical rows remain unchanged, and each source/replacement FK points at its intended document table with RESTRICT deletion semantics. Both return-receipt refusal cases also verify all four receipt families' source/replacement FK metadata before and after refusal, and real SQL deletion probes for their populated source and replacement documents fail with SQLSTATE 23000 / MariaDB 1451, preserving all rows. Other document-child FKs can also block those deletion probes; the exact receipt FK's deletion policy is independently checked through schema metadata.

Existing empty receipt round trips, purchase receipt down refusal and invoice-focused receipt behavior were not rerun or duplicated. Existing six-service reconciliation and broad runtime/export/UI/security coverage remain with their assigned owners.

## Actual commands and results

Focused command, from the account repository:

```bash
APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 \
PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 \
PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 \
php tests/Support/run-phase8-disposable.php -- \
  tests/Feature/Purchasing/CorrectionMigrationPreservationTest.php
```

- Initial run: **8 tests, 150 assertions, 1 error**, exit 2, 00:36.361. The sales invoice fixture omitted required `item_description`; this was a new-test fixture error, not an application defect. Its primary schema `accounting_p8_tmp_0471c3b67ea4` was cleaned. Log: `huda-migration-qualification-tests.log`.
- The fixture was corrected, restrictive-FK metadata checks were added to both populated forward cases, and SQL deletion rejection probes were added to both populated return-receipt cases.
- Final run: **8 tests, 268 assertions, no errors/failures**, exit 0, 00:37.041, 81 MB peak. Primary schema `accounting_p8_tmp_1f47a980b0c9` was cleaned. Log: `huda-migration-qualification-final-tests.log`.
- `vendor/bin/pint tests/Feature/Purchasing/CorrectionMigrationPreservationTest.php`: exit 0; formatted only the assigned file (imports/fully-qualified strict types). Log: `huda-migration-qualification-pint.log`.
- `vendor/bin/pint --test tests/Feature/Purchasing/CorrectionMigrationPreservationTest.php`: **passed**, exit 0. Log: `huda-migration-qualification-pint-check.log`.
- Installed component versions, server version, current runner grants, HEAD and SHA256 values for the assigned test and migrations 000001–000004: `huda-migration-qualification-environment.log`.

## Limits and handoff

The historical fixtures reproduce relevant schema versions using the repository's real migration prefix, but their rows are synthetic canonical fixtures, not restored production data. Migration bodies are invoked directly for the targeted up/down assertions; a deployment command's migration batching/ledger integration is not independently qualified. Fingerprints normalize the expected introduced void-date/nullable void columns and exclude newly created receipt tables during forward-preservation comparisons; refusal tests additionally compare complete DDL, and populated receipt refusal comparisons include receipt rows.

No unexpected migration/core defect was observed; no proposed source fix is needed. No full suite, additional focused class, PHPStan, frontend build, audit, browser, production reconciliation or deployment check was run. No children, commit, push, branch change, production access, privilege expansion, server cleanup or sibling-schema cleanup occurred. ReleaseUi, runtime/export contracts, PlatformAccess/FinalSecurity and source review remain outside this task's ownership.
