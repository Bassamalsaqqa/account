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

A measured synthetic monthly comparison after capability-enumeration optimization took 3052 ms and 754 SQL queries for the complete local Livewire request; a zero-model follow-up took 846 ms and 504 queries. These are development observations, not Hostinger guarantees. Repeated catalog checks now use a fresh per-enumeration permission snapshot; actual execution and delivery authorize independently. A4 thin-client integration and further proportional rendering/query improvements remain future work.

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
