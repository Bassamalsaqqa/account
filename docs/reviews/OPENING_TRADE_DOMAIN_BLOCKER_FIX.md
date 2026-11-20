# Opening trade domain blocker correction

Branch: t3/opening-trade-balances. Original feature baseline: dc60da85ac528ff921134779b021ed605cf696bc.
Correction starts at incomplete checkpoint 6fd758e491c65465b6343762686b7a635b593805.

Both application event writers reconstruct typed allocation targets and bind one-use append
authorization to the target kind and ID. Legacy version 1/2 reconstruction retains its original
request shape. Customer application replay and correction auditing now reconstruct opening
principal and saved book relief rather than looking for an invoice with the opening item's ID.
Check history reconstructs opening targets and sorts version 3 intent canonically.

Customer and vendor statements include previously posted openings even after reversal.
Reversal rows use the canonical reversal batch posting date. Aging delegates the as-of
decision to the opening item's financial-history calculation rather than filtering by today's
status or wall-clock reversal timestamp.

The existing vendor lookup contract is `default_currency_code`, consistently with customer
lookup and the party master field. Transaction/opening currency remains `currency_code`.
The lookup test now asserts the established field; no API response rename was introduced.
The cutover service test supplies its authorized actor, retaining the service's financial-read
requirements.

No schema, stock, UI, deployment or production changes are part of this correction.
The broader incomplete checkpoint's UI qualification, concurrency/fault injection, migration
qualification, six reconciliations and API/mobile qualification remain separate work.

## Verification

Disposable MariaDB 11.8.6, 127.0.0.1:3342, guarded runner with independent owned schemas:

- Full tests/Feature/Accounting: 29 tests / 166 assertions, passed.
- LaterCustomerCreditTest, CheckLifecycleTest, VendorPaymentResidualAndPreservationTest:
  28 tests / 134 assertions, passed.
- Opening-specific check return/cancel focused run: 1 test / 6 assertions, passed
  (also included in the full 29-test gate).
- PHPStan: no errors. Pint dirty check and diff whitespace check passed.
- No new migration or frontend assets; migration roundtrip and frontend build were not rerun.
- No six-domain reconciliation command gate was run during this correction.
- The added integration tests cover both sides' partial/final saved FX relief, retries,
  payment reversal and financial as-of behavior, dated opening statement reversal,
  and opening check return/cancel. They do not provide a forced numeric ID 42 collision
  matrix, new concurrent lock races, or the full negative authorization/party/date matrix.

Fixture logs and JUnit XML are retained outside the repository at
/tmp/opening-domain{,-final,-complete,-regression,-check}.*. The initial 26-test run had
one audit actor fixture error and one lookup-contract assertion failure; both were
corrected, and the subsequent full runs passed. Every runner reported owned schema cleanup.
No merge, push, deployment, Hostinger access, production migration or production posting occurred.
