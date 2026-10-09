# Phase 8 source acceptance and merge handoff

Status: **COMPLETE / ACCEPTED / MERGED / AWAITING DEPLOYMENT**.
Date: 2026-10-09. Phase 9: **UNSTARTED**.

## Exact source identities

| Record | SHA |
|---|---|
| Pre-merge main | `d201a385a16e64eadb849b0b7d252e1365ae6a31` |
| Independently accepted source | `e8e3e28fb040875d3b55ad403bea54a037e71437` |
| Accepted source tree | `a71f567ba54b16b30d4d1d4643a14a960e0ff98f` |
| PR #16 merge commit | `96c310f30a07e97ab8e04d5afbf0b2bb805f4317` |
| Merged tree | `a71f567ba54b16b30d4d1d4643a14a960e0ff98f` |

[PR #16](https://github.com/Bassamalsaqqa/account/pull/16) was merged with exact-head protection after verifying its open/mergeable state, unchanged main, branch/origin parity, clean tracked worktrees and resolved review threads. [Independent acceptance review 5471206252](https://github.com/Bassamalsaqqa/account/pull/16#pullrequestreview-5471206252) binds acceptance to the exact source above. The merge parents are the expected pre-merge main and accepted source; the complete trees are identical.

A separate documentation-only finalization commit updates this handoff, the Engineering Blueprint, Master Specification roadmap and ADR 0008. Its SHA is recorded in the execution handback; it does not alter the accepted runtime, tests, migrations, permissions or configuration.

## Accepted Corrections 01–03

- **01:** truthful signed Sales reversal/category metrics, current Money cutoff, identifier types, report column/grouping definitions and all-69 data-backed contracts.
- **02:** permissions and master-option privacy, searchable bounded selectors beyond record 100, coherent filters and Landed Cost grouping, historical original-only dates, distinct party counts, stable product aggregation/current codes and read-consistent private CSV preparation.
- **03:** disposable-test ownership, bounded deep-page pagination, canonical base-unit labels, fresh permissions without global cache eviction, capability-aware options/financial columns, pre-chunk CSV authorization and measured aggregation improvements.

[ADR 0008](adr/0008-reporting-and-dashboard.md) retains detailed economics, security, correction provenance and performance evidence boundaries.

## Preserved verification evidence

The final accepted source passed Phase 8 **253 tests / 12,390 assertions**, plus disposable-schema support **10 / 29**. All-69 registry contracts **16 / 8,617** are part of that Phase 8 gate. Final AR/EN desktop/mobile browser checks passed **16/16**. Pint, Larastan level 6 and Blade compilation passed; the integration production frontend build passed. Integrated report truth verified zero economic writes and six healthy local domain reconciliations.

Merge/finalization did not rerun these suites: exact accepted/merged tree equality makes the existing source QA applicable. Finalization checks cover tree identity, ancestry, documentation-only scope, Git parity, clean tracked tree/index and byte-for-byte preservation of all nine Owner files. Local evidence is retained under `.ai/delegations/20261009-phase8-final-c03/` and `.ai/delegations/20261009-phase8-merge-finalization/`; these ignored artifacts are not runtime dependencies.

## Deployment gates and accepted limitations

- **Phase 7 production deployment and healthy-baseline verification remain outstanding.** This finalization performs no production verification and does not treat earlier worker claims as independent production evidence.
- **Phase 8 is not deployed.** Deployment requires separate Owner authorization after the Phase 7 production gate is verified healthy. This document is a source handoff, not an executable deployment authorization.
- Customer/Vendor statements hydrate complete accepted history before pagination and repeat that calculation per export page. The accepted scalability limitation remains documented.
- Synchronous CSV preparation is bounded to **50,000 rows / 50 MiB / 30 seconds**. Capacity failures occur before delivery; exports use a private repeatable-read spool with cleanup.
- Live authorization is checked before each **64 KiB** delivery chunk. Revocation stops future chunks; HTTP cannot recall bytes already transmitted or retroactively turn a started 200 into a 403.
- The local 10,000-row aggregation benchmark demonstrates pipeline improvement and exact equality; it is not a production latency SLA.

No runtime changes, migrations, canonical accounting/inventory writer changes, production access, Hostinger access, deployment or Phase 9 implementation are included in this finalization.
