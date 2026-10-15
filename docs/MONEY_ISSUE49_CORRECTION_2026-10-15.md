# Money native-metadata correction (#49)

Baseline: `360f7bcef3bb14041c3cfd52731f39f71a0419be`.

## Reproduction and correction

Owned disposable MariaDB fixtures used balanced synthetic historical batches with a USD bank line carrying a matching currency code and NULL transaction amount, alone and mixed with a valid USD line. On the baseline, four independent query cases produced three failures and one `NumberFormatException`: daily summaries exposed zero/partial native totals and current balances either threw on an all-NULL aggregate or exposed a partial native total as available.

`MoneyLedgerMetadata` now requires both matching currency and a non-NULL native amount, including the same-account sibling used to recognize legitimate ancillary residual legs. `MoneyBalanceQuery` avoids parsing a NULL aggregate and uses the shared completeness count to decide native availability. The shared expression also corrects daily and movement completeness without duplicating their policy.

Correct base-ledger totals and base-currency fallback remain intact. Explicit native zero is known; missing metadata is unavailable. No canonical writer, migration, posting behavior or historical record changes are included.

## Independent verification

- Initial isolated red reproducer: 4 tests, 6 assertions; three failures and one error.
- Same reproducer after correction: 4 tests, 12 assertions passed.
- New durable MariaDB regression matrix: **14 tests, 154 assertions passed**.
- The matrix covers debit/credit, all-NULL and mixed ordering, valid/empty/explicit-zero histories, base fallback, historical cutoffs and paginated running windows, supported residual shape, canonical cross-currency transfer, restricted history, tenant context denial and AR/EN Money page rendering. Read checks fingerprint posting, stock, quantity/value caches, lots and numbering for preservation.
- All six reconciliation services passed on the matrix's synthetic canonical FX control. This is fixture evidence, not a new production reconciliation.
- A checkpoint running the new matrix plus existing daily-summary, control-plane, movement-visibility, Money UI and transfer suites ran 65 tests / 680 assertions with one error in the new tenant fixture setup; no mature regression failed. The setup was corrected by clearing company context before creating the second company, then the complete new matrix passed as above. The checkpoint is not reported as an entirely passing run.
- Dirty-file Pint and targeted PHPStan on both changed production files and the new test passed. No broad suite or GitHub CI was run.
- AGY completed a bounded source-only review of the two-file correction with no blocking finding; its read-only receipt reported no working-tree change. Its suggestions were checked against the independent matrix; this review does not replace acceptance tests or independent PR review.

## Limits and release notes

This proves the conditional source defect with synthetic data; it does not assert that production has incomplete native metadata. No attempt was made to repair history or infer unknown foreign amounts from current FX rates. No production access, merge or deployment occurred. Release requires independent review and separate Owner authorization.

Off-host recovery, isolated restoration and real password-reset email verification remain separately tracked, outstanding operational work.
