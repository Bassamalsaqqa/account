# Candidate window: reproduced production contention, qualification still HOLD

Implementation worker only; no independent acceptance. Baseline b8943252f014be0785c38660765677fd88c4695c. Final executable source 0f4e504 (see manifest for full SHA). No application source, migrations, financial adapters, routes, grants, Android or web UI changed.

The new two-process test uses a distinct company Owner and a staff login principal. The login executes through the production HTTP kernel. A beforeExecuting callback pauses the first Company FOR UPDATE before execution, after the candidate pluck returns and after production User/Device locks. The coordinator requires the candidate ACK and observes the production membership worker's connection executing its company_user UPDATE while both processes remain alive. It explicitly aborts the login transaction before awaiting the membership worker. Login's 500 is intentional harness fault injection, not a qualified successful response. The production action then completes without harness retries. Missing ACKs/timeouts throw.

This reproduces a technical obstruction to the requested admin-commits-between-candidate-and-locking-read sequence under current production User locking. The initial attempt awaited admin completion while holding User; it failed with login 500 after the strict deadline. That failed experiment is not a passing membership race. The corrected test explicitly releases via rollback. The test observes pending UPDATE, not InnoDB lock graph metadata, so the specific FK explanation is source-backed inference, not a measured lock-edge identity. company_user.user_id references users; only the login holds the staff User in this pair. No action authority was fabricated, and no membership mutation was substituted for the production toggle.

The existing direct-deactivation test is retained and accurately renamed as a sequential regression. It does not qualify the candidate window or either winner order. The membership worker's former five-attempt loop is removed; production action executes once. No 1213/1205 retry masking is introduced. This narrow run does not establish zero deadlocks across other operations.

## Remaining technical work

AI update calls TenantWriteAccess, then authorizeOwner -> AiGovernance -> SalesActorGuard, acquiring Company/Membership before Policy and password User. Actor may overlap mobile User. The preserved read-only partial moves password User after TenantWriteAccess, which can already lock Company for active support/subscription admission. Its unconditional sessionActor call also broadens host-delegation rejection beyond expansion. It is not adopted blindly.

SupportViewContext's trusted-host branch locks Company -> grant -> operator User -> owner memberships. The browser path delegates to SupportViewSession::resolve(true), which independently takes Company then PlatformAccess grant/User. Moving only the trusted-host lines would leave that browser path and caller-held Company locks unresolved. Operator User can overlap a mobile principal when actually assigned membership; distinct effective Owner/operator identity must be preserved. These paths remain unqualified; no whole-application DAG assertion is made.

Both winner orders for receipt/revocation/membership, all-memberships-revoked candidate filtering, relevant role-definition/cache concurrency, and independent exact-head Codex review remain required. No financial milestone admission follows from this reproduction. Production financial deny/default OFF remain unchanged.

Historical artifacts were preserved byte-for-byte. In this repository p0-pr94-refusal-proof.json is 6710 bytes at fc6422 and baseline b894325 (not the requested 17444); no replacement or relabeling was performed. Prior 36/194 and 41/206 ancestor repeats are not new worker results.

No production/staging/Hostinger access, merge, deployment, activation, daemon restart, persistent DB reset or other-worktree cleanup occurred. Dependencies were installed from lock without scripts. Tests use only explicit test_runner loopback 3342 and newly nonce-owned schemas through the tracked disposable runner; logs record created/dropped schemas.

## Actual gates

Final-source scoped PHPStan and Pint passed; protocol 40/202 and Python 210 normal / 210 optimized passed. Earlier-source candidate+sequential passed 2/17. Two final-source migration attempts timed out at 120 seconds and cleaned owned schemas; no final-source candidate PASS is claimed. Broader regressions completed 75 tests / 443 assertions with 2 errors and 12 failures (migration timeout and missing Vite manifest). npm ci was stopped on this checkout after host resource exhaustion (os error 11); build was not reached. No dependency lock changes. Separate raw private artifacts are named in manifest; published logs are sanitized, with regressions a diagnostic excerpt. No 1213/1205 total is asserted from this failed qualification.
