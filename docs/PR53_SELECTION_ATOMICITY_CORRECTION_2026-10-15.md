# PR #53: Quick Product selection atomicity correction

Reviewed baseline: `98c84b5ee5bc8de2c017fb75389dfe86dbdcc7f6`, based on deployed/main `360f7bcef3bb14041c3cfd52731f39f71a0419be`.

Both published independent reviews were read:

- https://github.com/Bassamalsaqqa/account/pull/53#pullrequestreview-5530698126
- https://github.com/Bassamalsaqqa/account/pull/53#pullrequestreview-5530764250

## Reproduced failure

An owned disposable MariaDB test disabled company USD, retained a valid positive exchange-rate string `3.5000000000`, preserved an edited purchase row, opened Quick Product and submitted a new SKU. At the reviewed baseline, Purchasing's selector caught the disabled-currency rejection and returned normally. The outer transaction committed Product, base ProductUnit, receipt and audit. The red test failed its full-state comparison: all four master/receipt/audit fingerprints changed; document/posting/stock fingerprints did not. Result: **1 test, 2 assertions, 1 failure**. Its owned schema was cleaned.

## Focused correction

The existing three form selectors now return an explicit success boolean. Ordinary Purchasing selection still renders its recoverable error, but reports `false` on refusal. Quick Product requires success and the expected product/base-unit identity; refusal throws inside the company-locked transaction. Product, base unit, receipt and audit roll back together. Invoice/Quotation exceptions retain the same transaction rollback boundary.

After rollback the original unsaved lines and pre-attempt error bag are restored, and a bounded AR/EN modal error explains what to check. The modal, entered fields, request token and search stay intact. The failed selector's transient draft error does not leak into a successful retry. Modal closing and success dispatch occur only after successful transaction completion. Authorization/unexpected exceptions propagate after line restoration. No canonical posting, historical records, schema or receipt-idempotency policy was changed.

## Focused evidence

- Corrected Quick Product selection, existing Quick Product/Receive, stale-document/FX boundaries, independent-connection identical/conflicting races and receipt migration preservation: **33 tests / 202 assertions passed** on disposable MariaDB, schema cleaned.
- New coverage includes the disabled-currency/valid-rate counterexample, retry using the same retained token after re-enabling currency, disabled default company language, and downstream inactive ProductUnit refusal in Purchasing, Invoice and Quotation. Full database fingerprints verify rollback of master, receipt, audit, documents, accounting, stock and numbering. Existing tests cover revoked permissions, stale immutable editors and company switching. Six synthetic reconciliations remain covered after canonical Receive posting.
- Targeted PHPStan on the changed trait, three forms and new regression test passed; changed-PHP Pint and diff checks passed.
- Final retry/error-bag refinement: **1 test / 19 assertions passed**, then targeted PHPStan and Pint passed again. This is a separate overlapping execution, not an added unique-test total.

This is a forward correction for final independent review. The previous PR #53 head is not represented as accepted. The subsequent isolated combined-tree evidence is recorded separately, including accepted PRs #50–#52. No main merge, production access, paid provider request or deployment occurred. Off-host recovery, isolated restoration and real password-reset email verification remain outstanding.
