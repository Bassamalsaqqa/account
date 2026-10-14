# A1-A6 integrated source and A1-A4 release preparation

Date: 2026-10-13. Source-development checkpoint, not production activation or architect acceptance of new A5/A6 source.

## Exact accepted stack and merge preparation

| Gate | Source SHA | PR |
|---|---|---|
| A1 | `53282ff37d09d1a0860b2962af317dd061e678b0` | #38, previously merged |
| A2 | `12812ff3e8f364ffbaba9d2bd33505ea9128dc53` | #39 |
| A3 | `103fff459e7469753671796ad0021671ef72f419` | #40 |
| A4 | `3846f6366e82122b5460ca485533c10457e0e830` | #41 |

Verified main is `45f7a1a61e2af8312bda97734def291cffaa8427`. All three draft heads remain exact and untouched. A2 is an ancestor of A3; A3 is an ancestor of A4. A read-only Git merge-tree simulation of main + A2 is conflict-free and produces A2's exact tree `a1b2589df0d0512d349fb3600396b1a8ab279c08`.

After explicit Owner merge authorization: verify the accepted heads/current base again, use a normal merge commit for #39, retarget #40 to the resulting main without changing its head, verify its effective tree/acceptance and merge normally, then do the same for #41. Preserve the stacked branches and published history. No squash, rebase, force push, automatic merge or deployment. Any changed implementation head requires renewed exact-head review.

A5, A6 and the older UI/API/HTTP-boundary findings are integrated on a continuation branch through normal local development merges. Their new source requires independent acceptance; those local integrations are not PR/main merges. See [A5](AI_V22_A5_SOURCE_CHECKPOINT.md), [A6](AI_V22_A6_SOURCE_CHECKPOINT.md) and [boundary/parity](AI_V22_UI_API_BOUNDARY_CHECKPOINT.md).

## Clean combined focused verification

PHP 8.4.25, owned disposable MariaDB, explicit relevant filter excluding inherited duplicate acceptance tests: **148 tests, 775 assertions, zero failures/errors/skips**, 3m00.565s. Includes briefing, real UNSAVED Livewire forms, integrated full-row economic fingerprints, session/CSRF/tenant API guards, UI/API parity, consent compatibility, bounded report profiles, independent concurrent readers and balance/SDK/HTTP-boundary regressions. Synthetic posted histories use canonical actions in disposable schemas; the assistant never saves/posts transactions.

Node worker/client/voice: **45 tests PASS**, zero skipped/cancelled. Pint and targeted PHPStan across the changed PHP boundaries and new tests: PASS. Integrated Vite build: **62 modules**, CSS **87.77 kB / 15.60 kB gzip**, JavaScript **62.69 kB / 23.20 kB gzip**. No new dependency, schema migration or canonical economic writer. Protected specification and original/private artifacts preserved.

Final Arabic/English label rendering check after the combined run: **2 tests, 26 assertions, PASS**; final Pint and asset build passed with identical asset sizes. Cash/Bank, expiry status and currency-balance availability use existing report translations rather than internal identifiers. No economic DTO or authorization contract changed.

| Synthetic local scenario | SQL queries | Observed time |
|---|---:|---:|
| S monthly comparison | 301 | 250.17 ms |
| S zero-model follow-up | 163 | 123.14 ms |
| M monthly comparison | 301 | 234.80 ms |
| M zero-model follow-up | 163 | 129.99 ms |
| Five nonempty briefing cards | 254 | 167.15 ms |
| Four simultaneous canonical readers | 76 each | 126.72-131.53 ms |

Compared with the previously recorded 754/504 queries, comparison/follow-up query counts remain 301/163. Four readers return identical exact values without writes. Two warmed downgraded-role readers and two revoked-membership readers each refuse authorization. Parameterized traces without bindings and full fingerprints are retained privately. These are measured local fixtures, not production SLOs or Hostinger capacity certification.

## Actual browser evidence and truthful limits

Accepted A4 local authenticated Edge: English comparison ILS 800 versus 450; Palestinian Arabic debt/latest-invoice question returns ILS 1,000 debt and the latest ILS 300 issued invoice. Arabic mobile viewport 390 x 844 has document and scroll widths 390 px; report tables scroll within the drawer. Model responses are mocked; numbers are actual canonical synthetic accounting results.

Integrated A5 renders the explicit daily action and zero-token canonical cards in the actual Livewire drawer. Neutral A6 offline shell rendered in Arabic desktop and Arabic/English mobile with no page overflow. Screenshots and ignored local-only harnesses are retained privately. Browser cancellation uses synthetic recognition callbacks and a test-only 10-second grace for inspection; the source default remains 1.25 seconds and is exercised by Node timers. Actual microphone hardware, Palestinian Arabic acoustic accuracy, paid DeepSeek intelligence, physical installation/offline lifecycle and production resource behavior remain NOT VERIFIED. No such result is inferred from mocks.

The new versioned API remains report-only. UI/API report parity does not establish native token/device onboarding or Sales/Purchase API handoffs. Follow-ups re-execute canonical reports under fresh authorization; no stored financial answer is replayed. Daily briefing never grants report access or expands company policy.

## Preservation-first A1-A4 candidate

Ignored immutable package: accepted source SHA `3846f6366e82122b5460ca485533c10457e0e830`, tree `87f0b02e9fc9fd6cea9ed6bc88acb4258c6aef3b`. Source ZIP SHA-256 `5fabc432cb12f0c941fcaf1df317dfc96ed83353ad9651d94b3a320a38381840`; Vite build ZIP SHA-256 `2d4810faa0051f9795ccce6882840a7ad79760b521f8cd0efb3ed6d8287dc6c0`. Manifest records source/build files and lock hashes. No environment, key, SQL, upload, private evidence, protected Owner original or vendor directory. This package excludes unaccepted A5/A6/boundary corrections and is not a production backup. Linux Composer/caches and operational preservation proofs still need the separately authorized release environment.

Before any release: independently confirm deployed SHA/configuration; retain encrypted coherent database/files/config backup and separately protected key custody; capture row/file preservation fingerprints and six domain reconciliation baselines; verify rollback source/build compatibility; enter and hold maintenance before source replacement. Preserve production `.env`, uploads, private assets and all historical usage/reservations. Rebuild Linux autoload/config/routes/views and verify Livewire aliases. Keep experimental flags and external dispatch disabled unless explicitly authorized. Test authenticated AR/EN dashboard, settings, reports and existing-party UNSAVED forms, API disabled/enabled contract as authorized, new logs and six reconciliations before reopening. Critical mismatch/500 requires rollback before reopening.

Do not blindly execute the deployment script's `down || true` / automatic `up` sequence: release operation must fail closed on maintenance failure and independently hold reopening until acceptance checks pass. No script change or deployment is made here. The six domains are accounting, inventory, Sales, money, Purchasing/AP and Phase 7; use existing canonical read-only reconciliation services/commands, never alternate writers or synthetic live transactions.

## Remaining gates

- Independent acceptance of continuation source; explicit Owner sequential-merge and deployment authorization.
- Representative Hostinger data/resource measurements and actual Linux/authenticated preservation/reconciliation verification before A2 activation.
- Deferred Phase 10 real isolated restore, independent off-host backup/key custody and actual approved email recovery remain unproven and unchanged.
- Real provider/microphone/installed-device proof requires its own authorized environment. No paid request or production financial mutation occurred.
- Current owned test and browser schemas are cleaned normally. An interrupted older browser fixture remains in private quarantine until ownership/cleanup can be re-established; it was not adopted or silently dropped. Other quarantined schemas remain untouched.

No GitHub CI, broad full-suite runs, PR/main merge, production access or deployment. Only finite local development integration merges and focused synthetic verification.
