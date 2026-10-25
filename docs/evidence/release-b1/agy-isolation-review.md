# AGY final isolation review

The initial bounded inspection timed out during its report. A resumed conclusion used the same inspected source without further tools. Codex inspected the actual diff and independently ran the ordered MariaDB regression; the worker did not run tests or grant acceptance.

No actionable finding in inspected subset. 

The isolation correction is structurally sound:
1. `PublicationConcurrencyTest` creates a dedicated disposable schema via `DisposableMariaDbSchema::createFromSource()`, switches the parent connection, and drops/restores cleanly in `tearDown()`.
2. Both child worker execution paths (`race` and the render test) receive `$fixtureSchema->environment()`, properly binding child processes to the disposable database with valid ownership proofs verified by `assertPrimarySchema()`.
3. Pre-test primary counts for all relevant tables (`companies`, `users`, `sales_invoices`, `posting_batches`, `stock_movements`) are asserted unchanged in `tearDown()`, preventing fixture pollution into subsequent suites.

Tests were not run.