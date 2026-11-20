# Opening trade sole-writer correction

Baseline: 6689de41b2a4f022d5ab05a754bbdb5f43078896.
Checkout: t3-50d1c3c0-c79b8f6ba88d6936. This report supersedes the inherited unverified worker claims only for the checks explicitly recorded below.

## Changes

- As-of opening outstanding uses immutable application business dates when an application has no FX journal. Reversed zero-FX applications use the owning payment's dated reversal journal, never the wall-clock reversal timestamp.
- Generic opening actions hold the same company lock as trade openings throughout validation and posting. Direct `opening_balance` posting requires executing action-owned request scope; an idle action cannot activate it. The scope is container-scoped and the owning action is final.
- Typed allocation normalization preserves omission until the authoritative target/payment currencies are known. Missing cross-currency amounts fail; same-currency fallback canonicalizes before hashing so saved request history remains reconstructible. Versions 1/2 are unchanged. Check source-history comparison follows the same currency-aware canonicalization.
- Opening entry and normal payment forms use actual opening model columns, localized EN/AR labels, a conditional foreign-currency rate, optional historical details, one confirm action and a secondary historical-line action. Existing shared Blade controls are retained; no new framework, font, daemon or runtime dependency.
- Inherited tests claiming a barrier race were sequential and renamed. A separate test now runs independent PHP processes against committed fixtures while the parent holds the company lock, covering both AR and AP. An empty opening migration round-trip and idle-action capability negative are included.

## Qualification limits

Stitch project 15641450350510420845: reviewed all four supplied screen records and their HTML through the Stitch download tool. Direct download URLs returned 401; the authenticated download tool succeeded. No real browser, viewport, keyboard or screenshot qualification was performed. Visual acceptance remains outstanding. No new migrations were introduced. Empty round-trip and populated downgrade refusal do not establish preservation of a fully populated pre-feature allocation dataset. The ID-42 test covers typed normalization and distinct actual economic targets; it does not force both persisted target IDs to 42. No broad new authorization-negative matrix or deterministic post-numbering fault-injection matrix was added.

No merge, push, deployment, Hostinger access, production migration or production posting occurred. All economic test execution used guarded owned disposable MariaDB 11.8.6 schemas on port 3342; no persistent port 3353 access. No subagents were launched. Private fixture logs remain outside the repository under /tmp/opening-sole-*.log. Build output is ignored generated output; no deliberate untracked source artifacts remain after commit.

## Completed checks

- Final full Accounting run completed: **47 tests / 277 assertions / 1 error**. All 46 other tests passed, including all three P1 correction scenarios and the two-process AR/AP race. Every owned schema was cleaned.
- Blocking error: empty down/up leaves the composite `cpa_opening_item_idx` index name behind after dropping its target column; re-up fails with MariaDB 1061 duplicate index. The analogous vendor index needs the same review. The failing regression is deliberately retained. The already-applied migration was not edited, consistent with the repository contract. Release remains HOLD pending an approved rollback correction strategy.
- Separate race run: **1 test / 10 assertions passed**, both AR/AP.
- Payment/check regression rerun: **28 tests / 134 assertions passed** (LaterCustomerCreditTest, CheckLifecycleTest, VendorPaymentResidualAndPreservationTest).
- Earlier combined regression + GoldenTradingJourneyTest: **31 tests / 389 assertions passed**, including six-domain reconciliations on the mature golden fixtures. These reconciliations were not independently rerun on every new opening fixture.
- PHPStan: **804 files, no errors** on final source. Pint dirty test passed; diff whitespace passed.
- Final production frontend build passed, no source maps introduced. No full repository PHPUnit, dependency audit or browser qualification was run.
- Earlier failed Accounting runs: 44 tests / 162 assertions / 13 errors; 45 tests / 253 assertions / 2 errors. Both runners finished and cleaned their schemas; subsequent corrections are represented by the final 47-test run above.

Files: 25 source/test/report/localization files changed; no migration file changed. The resulting local commit is available from Git HEAD; this report intentionally does not embed its own commit hash.
