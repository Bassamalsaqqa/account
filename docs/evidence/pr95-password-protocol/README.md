# Password/protocol step — full PR95 HOLD

Executable source and file hashes are in manifest.json; this documentation is committed separately after QA. Executor: Codex, no delegated writers. Review 5890864759 and both preserved partials were evaluated read-only. No code was copied from them.

Commands used at the executable source SHA:

```
vendor/bin/phpunit tests/Unit/StrictBarrierTest.php
# Prefix each DB command with:
APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1
php tests/Support/run-phase8-disposable.php -- --filter 'test_real_fortify_update|test_reset_wins_first' tests/Feature/Mobile/MobileReceiptLockOrderQualificationTest.php
php tests/Support/run-phase8-disposable.php -- --filter 'PasswordResetTest|PasswordUpdateTest|PasswordConfirmationTest' tests/Feature/Auth
vendor/bin/phpstan analyse --memory-limit=512M tests/Support/StrictBarrier.php tests/Support/DisposableMariaDbSchema.php tests/Support/mobile-identity-worker.php tests/Unit/StrictBarrierTest.php tests/Feature/Mobile/MobileReceiptLockOrderQualificationTest.php
vendor/bin/pint --test tests/Support/StrictBarrier.php tests/Support/DisposableMariaDbSchema.php tests/Support/mobile-identity-worker.php tests/Unit/StrictBarrierTest.php tests/Feature/Mobile/MobileReceiptLockOrderQualificationTest.php
env -u PHASE8_ALLOW_DISPOSABLE_DB php tests/Support/run-phase8-disposable.php -- tests/Unit/StrictBarrierTest.php
npm ci
npm run build
```

The first password case holds User FOR UPDATE in an explicitly labeled test transaction. The real worker loads User and calls production Fortify ResetUserPassword unchanged, in autocommit. Before that call it writes its connection ID. The coordinator requires that exact connection to appear in SHOW FULL PROCESSLIST executing UPDATE users in an updating state, while the target User row remains exclusively held and the process remains unfinished. Only then does it release the row and await completion. Fresh lookup rejects the old epoch. This is server-observed pending SQL plus an exclusive row-holder, not an after-UPDATE query event or a delay asserted as contention. No financial executor runs in these password cases. It proves the controlled User-row contention, not every receipt interleaving or absence of deadlocks throughout the application.

The reset-first case completes reset without a holder, confirms the contention ACK cannot succeed with an exited peer, and verifies a later lookup rejects the old epoch. It is the separate freshness order/negative control, not a second claimed blocked UPDATE.

Strict protocol tests cover missing hold, wrong table, missing peer deadline and crashed peer without database or fabricated ownership proof. Worker hold/start timeouts now throw; timed holds fail closed. Canonical financial execution requires its configured held hook before entering any financial action. Thus old delay-based five-suite tests are intentionally unqualified and must be converted to explicit-release orchestration before reuse. HTTP login/revoke code and Fortify production code are unchanged.

The helper validates live SELECT DATABASE and ownership nonce before fixture switching, and validates the child's live schema/proof before migration. Final password log records primary and both child create/drop pairs; auth log records its primary create/drop pair. Only freshly owned schemas were dropped. No existing databases, daemons or preserved worktrees were cleaned. No schema migration files changed.

Initial auth rendering attempts failed before the reproducible asset build (3 failures); the final run passes 9/26. Final password 2/12 and protocol 5/6 are new evidence, separate from historical 40/202 or 5-suite claims. Logs are sanitized; raw and sanitized hashes are recorded. The historical ancestor fc6422 report, 17444 artifact and prior manifest digest ba75352349e552f53b02ad7159570171f88943dc26e5bdf2a4b013386b0d0659 are not rewritten or relabeled.

Full PR95 remains HOLD: actual two-worker membership candidate race, both winner orders for other cases, cross-entrypoint AI/support caller-held-lock cycles, wider receipt regressions and independent exact-head review remain next. No global DAG/no-deadlock claim. No production financial adapter, grants, routes, flags or defaults changed. No merge, deployment, production/Hostinger access, Android work or network pilot occurred.
