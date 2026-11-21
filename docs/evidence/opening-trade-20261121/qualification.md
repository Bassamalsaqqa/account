# Opening trade migration correction qualification

Baseline: `7684905388952849fd65f3b53074b0b979b0dd70`, branch `t3/opening-trade-balances`.

The unpublished feature migration's rollback left the named composite allocation indexes behind after removing their opening-item column. MariaDB then rejected reapplication with duplicate index error 1061. Rollback now explicitly drops each named composite index before removing its foreign-key column. The populated-history refusal still executes before all DDL.

Publication evidence: the migration was introduced in local commit `6fd758e`; `git branch -r --contains 6fd758e` returned no branches; live `git ls-remote --heads origin 't3/opening*'` returned no branches. The supplied task attests that applies were confined to disposable fixtures. No production state was inspected or independently attested by this work.

Qualification uses `tests/Support/run-phase8-disposable.php`, a fresh randomly named owned schema on loopback MariaDB port 3342, cryptographic ownership proof and automatic cleanup. Connection credentials stay in the existing private attachment and are not copied into the repository. Source its `connection.env`, export a random testing APP_KEY, LOG_CHANNEL=stderr and its private run directory as VIEW_COMPILED_PATH, then run:

```sh
php tests/Support/run-phase8-disposable.php -- --do-not-cache-result tests/Feature/Accounting
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```

The migration test creates both legacy invoice and purchase allocations through canonical actions, compares all allocation/payment/journal rows exactly before and after down/up, and compares complete table definitions (including indexes, foreign keys and target constraints). The populated opening-history test compares complete financial rows and schema definitions around downgrade refusal. The typed collision test now persists actual document and opening IDs of 42 in separate customer/vendor fixtures. A deterministic failure after the persisted opening audit verifies rollback of opening items, posting batches, posting lines, audit rows and document sequences. All six reconciliation services run on new customer/vendor opening fixtures. The two-process AR/AP generic-versus-trade race remains included.

Two preliminary runs failed in the expanded legacy fixture before migration DDL because a default stock invoice had no inventory. The fixture was corrected to use a service invoice; these preliminary runs are not passing qualification evidence.

Final full Accounting: **50 tests, 294 assertions, zero failures/errors**, 2m41.594s; the owned schema was cleaned. Full Pint and PHPStan passed. No frontend assets changed, so no build was rerun. Mature payment checks (28 tests/134 assertions) and golden journey (31/389) are inherited evidence from the task and were not rerun here. Expanded negative actor/party/tenant/date coverage remains a separate qualification gap; existing permission/tenant and dated-statement cases remain in Accounting. Browser qualification remains a later phase. No UI design work occurred.

No production/Hostinger access, GitHub Actions access, push, merge or deployment occurred. Production migration/posting release remains HOLD pending explicit owner review approval.
