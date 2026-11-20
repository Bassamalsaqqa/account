# Opening trade takeover checkpoint — incomplete

Baseline: dc60da85ac528ff921134779b021ed605cf696bc, branch t3/opening-trade-balances.
This preserves the inherited implementation and takeover corrections for review. It is not release acceptance.

Takeover corrections include missing imports/API helper, vendor status lookup, v3 request serialization, rollback refusal before DDL, exactly-one allocation target checks, action-owned opening posting/reversal scopes, financial-date outstanding replay, cutover financial authorization and exact residual book totals, and atomic web multi-item submissions with stable additional-line retry keys.

## Verified evidence

- MariaDB 11.8 disposable service at 127.0.0.1:3342 only, through tests/Support/run-phase8-disposable.php with owned random schemas. Each completed runner reported cleanup.
- First accounting run: 24 tests / 45 assertions, 11 errors / 5 failures.
- Second accounting run: 24 tests / 78 assertions, 4 errors / 8 failures.
- Third accounting run: 26 tests / 111 assertions, 9 errors / 1 failure. Several test-field mistakes have subsequently been corrected; this does not constitute a passing full rerun.
- PHPStan final source scan: 803 files, no errors.
- Production frontend build passed. Pint applied; final verification is recorded below.
- No production migrations, tenant postings, Hostinger access, merge, push or deployment occurred. All economic execution used disposable fixtures.

## Blocking work remaining

1. Both payment application-event models reconstruct only invoice/purchase IDs and reject opening allocations. Update their canonical aggregation, reconstruction, consume validation and historical application replay together. Verify partial/final relief, reversal, and check lifecycle on both sides.
2. Customer and vendor statement opening queries still filter current posted status and use reversed_at instead of dated reversal posting history. Correct all statement/history paths and add before/after effective-date tests.
3. Generic opening AR/AP overlap checks need concurrent lock-bound verification, including opening inventory first and preserved historical replay. No cutover inference or real tenant migration was attempted.
4. UI remains hard-coded Arabic in substantial places, with missing real EN/AR/RTL keyboard/responsive/browser qualification. The supplied Stitch references and refined UX spec are task references, not a completed visual acceptance result.
5. Full regression, idempotency races, rollback fault injection, migration empty roundtrip and legacy-row preservation, six reconciliations, and API/mobile authoritative unsaved-draft contract qualification are outstanding.
6. Full test failures must be resolved against actual domain behavior, without weakening accounting/authorization invariants. The remaining lookup test expects currency_code while implementation returns default_currency_code; establish the intended contract explicitly in code/docs/tests.

No external agents were launched. No Android files were edited. This checkpoint must remain on HOLD for review and completion.

Final focused guard/history run completed: **5 tests / 18 assertions passed**, covering populated downgrade refusal, forged activation rejection, opening-only customer/vendor statements and unspecified due-date aging. Owned schema cleaned. These tests do not cover dated statement reversals or application-event target failures described above.

Final repository Pint check completed successfully (exit 0); staged diff whitespace check passed. Source checkpoint spans 46 files including one new forward migration. No deliberate untracked repository artifacts are retained. Private takeover command logs remain under /tmp/opening-takeover-*.log for inspection; they contain fixture test/tool output only.
