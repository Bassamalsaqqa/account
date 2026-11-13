# PR #95 remains on HOLD

Review 5890864759 was read from GitHub. This follow-up corrects evidence attribution only. It does not finish the requested source/lock qualification and must not be used as financial implementation admission.

Executable source remains `75228efb5bfbb56a7e5497dbae52ea5ab3a674f2`. The separate JSON records actual no-DB refusal and Python results with source/log digests. No new MariaDB schema was created or dropped. Dependencies were installed from the tracked Composer lock without scripts. No production access, Android changes, merge, activation or deployment occurred.

## Outstanding source and proof work

- Password reset uses production Fortify UPDATE in autocommit; the existing FOR UPDATE listener cannot hold it. Add a clearly labeled test row-holder and prove blocked UPDATE before explicit release; prove opposite winner freshness separately.
- Existing barrier timeout paths break and proceed. Missing-hook checks in the preserved partial run after effects and cannot establish rollback. Replace with strict protocol enforcement before financial effects, nonzero errors and real negative cases. The partial's fabricated ownership capability is unsuitable for no-DB tests.
- Replace delay-based races with coordinator-observed held-lock ACK, contender attempt and database wait, then explicit release, in both winner orders. Existing membership-filter test is sequential, not a candidate-pluck race. Add actual two-worker candidate/locking-read race.
- Remove harness-only membership retries or report every 1213/1205 and attempts without treating those retries as production behavior.
- Bound claims to participating explicit row locks. Login token insertion follows Company/Membership locks and FK implicit parent locks remain relevant.
- Concrete reachable edges needing contextual analysis: AssistantReportPolicySettings::authorizeOwner locks CompanyUser, save locks AiCompanyPolicy then User for password confirmation; SupportViewContext::authorizeWrite locks Company then operator User. Caller-held locks and principal overlap must be analyzed before adopting the preserved User-first reorder. No whole-application acyclicity is claimed.
- Source callgraph and relevant MFA/recovery/role/security regressions, fresh nonce-owned MariaDB reverse/random receipt runs, scoped PHPStan and Pint remain required. Existing helper create/migrate/switch/restore/drop must be inspected with live ownership admission before new DB tests.

The preserved partial checkout and original supervisor artifacts were read only. Historical p0-pr94-refusal-proof.json is unchanged. Production DenyFinancialExecutionAdapter and V2 OFF remain unchanged. Independent exact-head review remains required; no financial writer may start from this evidence correction alone.
