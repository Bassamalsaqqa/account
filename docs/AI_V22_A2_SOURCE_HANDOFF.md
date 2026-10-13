# AI V2.2 A2 — canonical accounting turn source handoff

## Identity and disposition

Baseline/main: `45f7a1a61e2af8312bda97734def291cffaa8427`; branch: `feature/ai-v22-accounting-turn`.
Gate 0/A1 PR #38 accepted source `53282ff37d09d1a0860b2962af317dd061e678b0` was normally merged with Owner authorization. Its source-only Hostinger package is prepared privately; no deployment occurred. A2 independent architectural acceptance is pending.

Controlling protected local Owner specification remains version V2.2, SHA-256 `d2bb378de74235a8329114f394dee0b3b1aeb8107bd2f84f8154f46972637aa8`. It is neither modified nor published.

## Implemented behavior

[AssistantTurnService](../app/Application/AI/AssistantTurnService.php) accepts company/user/session-bound text and an idempotent request UUID. Confident deterministic questions and safe monthly follow-ups execute locally. Other questions use one bounded official Laravel AI SDK planning response, followed by up to three validated read proposals. Canonical results, resolved IDs and financial records are never sent back to DeepSeek. There is no agent loop, new transport, SQL tool or alternate accounting engine.

[AssistantReportPlan](../app/Application/AI/AssistantReportPlan.php) validates raw name labels, supported filters, real paired calendar dates, a maximum 366-day inclusive range, finite comparisons/grouping and configured currencies. [Entity resolution](../app/Application/AI/AssistantReportEntityResolver.php) selects exact or bounded normalized same-company matches; missing/ambiguous names ask for clarification. Explicit currency/date/period/comparison constraints cannot disappear. Provider-supplied names must come from the current user text or authorized prior labels. A bounded local mention check also refuses a known named entity being dropped into a company-wide answer. Unsupported mixed scopes clarify rather than broaden results.

The [catalog](../app/Application/AI/AssistantReportCatalog.php) contains 23 canonical ReportRegistry capabilities and one narrow existing issued-invoice source read:

| Area | Capabilities | Authorization and source |
|---|---|---|
| Sales | summary, by-period, by-customer, by-product, unpaid | Existing report/source permissions; sensitive cost/profit projections remain server-redacted |
| Customers | balances, top, aging | Existing Customer/report permissions; balances remain current, not invented historical figures |
| Purchasing | summary, by-period, by-vendor, by-product, unpaid | Existing Purchasing/report permissions and purchasing.cost.view |
| Vendors | balances, aging | Existing vendor financial/report permissions and purchasing.cost.view |
| Inventory | stock, movements, low-stock, expiry | Existing stock/report permissions; valuation/cost fields require their existing permissions |
| Expenses | summary, by-period, by-category | Existing canonical Expense/report permissions |
| Money | balances | Existing report permission plus Cash/Bank-specific access; only authorized account types |
| Latest Sales invoice | sales.last-invoice | Explicit company allowlist, sales.invoice.view and customers.view; posted/void issued source documents, never drafts |

Every key requires explicit persisted company activation plus current domain authority. Registering the expanded catalog does not migrate an existing allowlist, grant roles, revive credentials or activate an intentionally disabled company. Payroll and protected salary reports are excluded. Filter support is determined by the canonical definition; a category/name/date/currency unsupported by that source is refused. Results retain exact decimal strings, real periods, currency distinctions and source links. Primary rows are bounded to five; the aggregate turn response is bounded to 64 KiB.

[Short-lived context](../app/Application/AI/AssistantTurnContext.php) retains encrypted request labels only for 20 minutes, scoped to company/user/session/policy and fresh report/cost/profit/Cash/Bank authority. No cached financial answer is replayed: identical requests recompute authorized canonical results with no second model dispatch. Conflicting payloads fail closed. Follow-up policy disabling is respected. Delivery carries an internal permission scope that is rechecked and removed by the API; the launcher uses that same scope to clear stale answers.

The authenticated `POST /api/v1/companies/{companyId}/assistant/turn` route preserves TLS, JSON bounds, CSRF, database-session revocation, active company and fresh permissions. Locks do not span provider I/O. Only this exact read-only route has its independent AI rollout gate; financial command endpoints remain controlled by COMMAND_API_ENABLED. `AI_TURN_ENABLED` defaults false. The existing manual and explicitly recognized Sales/Purchase workflows remain available. A2 never saves or posts a transaction.

## Required synthetic demonstration

Date fixed to 2026-10-15, Asia/Hebron; disposable company and canonical posted synthetic fixtures only.

| Question | Observed canonical result | Planning calls |
|---|---|---|
| "قديش على أحمد دين؟ وشو آخر فاتورة إله؟" | Ahmed debt ILS 1000.000000; latest issued invoice 2026-10-10, ILS 300.000000 | One mocked SDK response, two local reads |
| "قارن مبيعات هالشهر بالشهر الماضي." | October ILS 800.000000 versus September ILS 450.000000 with exact source periods | One mocked SDK response |
| "شو الأصناف اللي قربت تخلص؟" | LOW-STOCK-01 on hand 0.000000, minimum 10.000000, shortage 10.000000 | Zero |
| "طيب وماذا عن الشهر اللي قبله؟" | Following the monthly comparison: August 2026, ILS 200.000000 | Zero, typed scoped context |

Normal local Owner login and the actual registered Livewire drawer were used in Edge. English desktop, Arabic RTL at 390x844 and 768x1024, canonical source links, loading states, Escape cancellation and reopening were checked. Both mobile/tablet document widths matched their viewports. Screenshots and sanitized counts are retained in ignored local evidence, not published with credentials or private material. Financial row counts stayed at the seeded four invoices, five posting batches, ten posting lines, one receipt and zero stock movements; no invoice/payment/stock creation occurred during assistant use. Separate canonical regressions verify full economic fingerprints remain unchanged.

A measured synthetic monthly comparison at the original A2 checkpoint took 3052 ms and 754 SQL queries for the complete local Livewire request; a zero-model follow-up took 846 ms and 504 queries. These historical observations are retained; the correction measurements below supersede them for the corrected source. They are development observations, not Hostinger guarantees.

## Verification actually executed

- Clean combined relevant regression subset: **219 tests / 969 assertions**, zero failures/errors/skips, PHP 8.4.25 and disposable MariaDB; includes canonical report integrity, entity resolution, exact comparisons, permission redaction and existing UNSAVED Sales/Purchase regressions.
- After the final explicit-session, omitted-name and actual API additions: **27 A2 feature tests / 181 assertions**, zero failures/errors/skips. This includes the real HTTP route through the service/canonical report with zero provider traffic; internal delivery scope is not serialized. Do not sum overlapping runs.
- Targeted PHPStan for all changed application classes passed; focused Pint, production Vite build, Blade compilation/clearing and whitespace checks passed.
- Fake SDK and synthetic provider responses only; no paid calls, keys, production prompts or company records were used. Unknown historical cost reservations are not reset or refunded.
- AGY supplied bounded acceptance-fixture and local browser-harness work. Codex independently inspected, corrected and executed the results. Worker syntax claims are not substitutes for the results above.

No schema/dependency/lockfile/canonical financial-writer changes. No GitHub CI, Hostinger access, production mutation, A2 merge or deployment. Owner originals, older worktrees and quarantined schemas are preserved; newly owned test/browser schemas are cleaned separately.

## Remaining boundaries

Independent source acceptance and separate release authorization are required. Real DeepSeek natural-language quality, live billing and actual microphone/dialect accuracy have not been measured in this task. Browser HTTP is local synthetic QA; authenticated API transport/session refusal cases are covered by guarded HTTP feature tests.

A3 natural transaction-intent integration, A4 cancellable automatic voice/thin drawer, A5 zero-token briefing and A6 shell-only PWA remain unaccepted and are not claimed complete. Current monthly local follow-ups require a single compatible monthly prior plan; complex/mixed scopes use planning or clarification. Normalized entity fallback is finite and fails closed beyond its scan cap. No walk-in/master-data creation, automatic financial posting, result egress or unrestricted tools are introduced.

## Focused A2 architect corrections

Correction baseline: `688941d0dc3a2dcbb637c04a8c2fb30ad81ab6de`. PR #39 remains draft, awaiting independent exact-head re-review. The checks in this section are newly executed correction evidence; earlier counts above are historical and overlap.

### Independent intent constraints

[AssistantIntentScope](../app/Application/AI/AssistantIntentScope.php) associates each proposal with a complete server-recognized question clause before existing constraint and same-company entity validation. Optional SDK `request_fragment` is untrusted metadata: it must match the whole clause exactly and is stripped before execution/context persistence. Every independent clause must be covered. Truncated fragments, borrowed names/currencies/dates/comparisons, omitted clauses and ambiguous same-domain associations clarify with no report results. The bounded supported debt/last-invoice pronoun pair retains its shared customer scope. Unrecognized clause boundaries remain a clarification limitation, not permission to ignore qualifiers.

English: "What's Ahmed's outstanding balance, and what were our sales this month?" Arabic: "قديش على أحمد دين؟ وشو مبيعات هالشهر؟" Both now return current Ahmed debt **ILS 1000.000000** independently of October sales **ILS 800.000000**, with correct current/period labels and separate canonical source links. Actual local Livewire rendering was verified in English desktop and Arabic RTL mobile using one mocked SDK planning response each; calculations use posted synthetic canonical fixtures. No live model-language accuracy is claimed.

### Query profile and authorization

[Sanitized parameterized traces](AI_V22_A2_QUERY_PROFILE.json) include the ten most frequent statements per workload, excluding all SQL bindings, prompts, credentials and production identifiers. The new profile harness measures only `AssistantTurnService::run`, including governance, reservations, local reports and context; it excludes fixture creation, HTTP middleware and Blade rendering.

| Workload | Queries before / after | Elapsed ms before / after | Exact result |
|---|---:|---:|---|
| S comparison | 515 / 301 | 1118.0 / 503.6 | October 800 / September 450 ILS |
| S zero-model follow-up | 313 / 163 | 706.0 / 201.9 | August 200 ILS |
| M comparison | 515 / 301 | 838.6 / 289.2 | October 860 / September 510 ILS |
| M zero-model follow-up | 313 / 163 | 511.8 / 156.9 | August 200 ILS |

S contains four invoices and one receipt. M contains sixteen invoices, twenty-five additional customers and one receipt. These are single development observations on PHP 8.4.25/disposable MariaDB, not capacity targets or production guarantees. Exact totals and economic-table fingerprints are asserted independently; before/after diagnostic report hashes used different projection hashing and are not presented as byte-equivalence evidence.

The dominant redundant reads were role/direct/role-permission hydration and repeated separate company/membership checks. [ReportingGuard](../app/Application/Reporting/Security/ReportingGuard.php) now reads direct and role permission names with one fresh SQL union using the installed Spatie relationships themselves, preserving team, morph, global-role and web-guard semantics. Active persisted company/membership are checked together. A catalog evaluation reuses only that immediate permission result; canonical execution and delivery independently recheck current authority. No cross-turn permission cache or economic-query shortcut is introduced. Residual repeated checks intentionally remain at independent boundaries; large-data hosting performance remains unmeasured.

Four independent concurrent readers returned the same **800/450 ILS** comparison with **76 queries each** (134.6–136.2 ms). After warming permissions, role downgrade and membership revocation before barrier release each denied both readers. Economic fingerprints remained unchanged. This bounded concurrency proof is not a production load test.

The complete corrected local Livewire request measured **525 queries / 934 ms** for the Arabic comparison and **369 queries / 482 ms** for its zero-model follow-up, versus the earlier **754 / 3052 ms** and **504 / 846 ms** observations above. The owned browser cache is now database-backed, while the earlier harness used file cache; these are observational full-request figures, not a controlled cache-equivalent benchmark. The service-only S/M table is the controlled before/after comparison. The follow-up request has zero provider attempts; four seeded invoices, five posting batches, ten posting lines, one receipt and zero stock movements remain unchanged.

### Explicit company report access

[AssistantReportPolicySettings](../app/Application/AI/AssistantReportPolicySettings.php) and the collapsed AR/EN Owner settings section allow explicit finite catalog selection. Expansion requires protected active Owner authority, current revision and password confirmation. Existing policies are not automatically expanded; AI activation, question enablement, role permissions, encrypted keys, custom limits, historical reservations and unrelated settings remain unchanged. Meaningful report-scope consent is invalidated by the existing fingerprint rules. Invalid legacy configurations fail safely rather than silently normalizing other fields.

### Newly executed verification

- One clean combined relevant subset: **288 tests / 9704 assertions**, zero failures/errors/skips. Includes report truth, canonical guard/registry, SDK/intent contracts, HTTP/session/context isolation, explicit allowlists and concurrent revocation.
- Compound integration cases: **5 tests / 34 assertions**, including actual exact-value Arabic/English answers and omission/currency refusal.
- S/M profiling: **2 tests / 20 assertions**, one mocked SDK call for comparison and zero for follow-up; unchanged economic fingerprints.
- Scoped Pint/PHPStan and production Vite build pass. Test helper annotations and the stale accepted-A1 verification wording expectation were corrected without changing runtime behavior.
- Browser screenshots and full-request observations remain in ignored local evidence. Native voice accuracy, paid DeepSeek quality, Hostinger capacity and production activation are NOT VERIFIED by these tests.

A3 began separately in `feature/ai-v22-unsaved-drafting`; its development checkpoint does not form part of PR #39 or establish A3 completion. No merge, deployment, production access, paid call, budget reset, financial-writer, migration, dependency or protected-specification change occurred.
