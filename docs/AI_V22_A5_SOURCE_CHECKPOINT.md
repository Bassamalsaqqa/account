# A5: deterministic daily accounting briefing

Baseline: accepted A4 `3846f6366e82122b5460ca485533c10457e0e830`. Source complete; independent acceptance pending. Feature flag `AI_DAILY_BRIEFING_ENABLED` defaults to false. No migration, dependency, economic writer or transport change.

## Working behavior

An explicit Daily Briefing action (Arabic: الملخص اليومي) in the existing assistant retrieves up to five canonical report cards: unpaid Sales, unpaid Purchases, permitted Cash/Bank balances, low stock and inventory expiry. Exact briefing phrases take the same deterministic route. Opening the assistant alone never fetches the briefing or dispatches a model request. Choosing it cancels pending voice submission and discards an outstanding UNSAVED assistant clarification safely.

Each card requires both the company report-policy allowlist and fresh existing domain permissions. Unauthorized cards are omitted, never represented as zero. Canonical executors independently authorize retrieval/delivery and redact costs/account types; the service compares fresh actor/company/session/policy/permission scopes before and after the snapshot. Revocation, company/session switch or query failure cannot return stale cards. Combined output is capped at the existing 64 KiB boundary. There are no provider calls, usage reservations, stored financial snapshots, scheduler jobs or background tasks.

Unpaid means open balances as of today, not overdue. Money is the current canonical Cash/Bank position. Stock and expiry use the company's local date; expiry includes expired, soon, valid and unknown tracked active lots. Cards preserve currency-separated canonical values, dates and source links. This does not add due-date semantics or invent zero results for restricted data.

## Executed verification

- Corrected focused DailyBriefingTest: **17 tests, 146 assertions, PASS** on owned disposable MariaDB. Nonzero canonical Sales/Purchase/cash/stock/lot fixtures, exact DTO equality, full financial/master-data fingerprints, zero HTTP/request records, restricted role/policy, mid-read/post-read revocation, company/session invalidation, locked server clarification-to-briefing transition, AR/EN labels and explicit-only execution.
- Related UNSAVED transaction conversation regressions were run with the briefing suite: **33 tests, 233 assertions, PASS** before the final measured-fixture additions. No automatic master-data creation or financial writes.
- Five nonempty cards: **254 SQL queries, 172.83 ms**, zero model calls in the synthetic local fixture. Parameterized query trace retained privately without bindings. This is a measured local sample, not a Hostinger capacity guarantee.
- Pint and targeted PHPStan: PASS. Later combined source/build/browser evidence is recorded in the integration checkpoint.

Worker checkpoint output was independently inspected and corrected: invalid fixture fields/units, client attempts to set locked state, premature revocation hooks, incomplete fingerprint checks and unnecessary repeated report-permission enumerations. Timed checkpoints were not treated as acceptance.

No production access, paid provider call, financial mutation outside owned synthetic fixtures, main/PR merge, deployment or CI. Existing A2-A4 published ancestry remains intact.
