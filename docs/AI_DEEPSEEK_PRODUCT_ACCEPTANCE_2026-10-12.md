# DeepSeek product implementation — source review packet

Date: 2026-10-12. Status: **implemented locally; independent source acceptance pending; not deployed**.

Source baseline: `b0a4b15354aad76b2b81eda2a01a935c05dd6de6` (`main`, PR #31 merge). Implementation branch: `feature/ai-deepseek-product`. The review head is the commit containing this packet and the draft PR head, not the baseline. PR #32's separate documentation branch is preserved.

## Governing scope and preservation

The Owner's latest DeepSeek-only product decisions control this implementation. The protected original `ACCOUNTING_AI_SIMPLIFIED_IMPLEMENTATION_HANDOFF_2026-10-12.md` was read at its actual local Downloads path. Its SHA-256 remains `afdcfb06d10baba74911e41ac77a001fe606ef7aad44be7312ebfd730583cf65`. It was not relocated, rewritten or published.

The existing company boundary remains authoritative. Keys and policies are never shared across companies. No accounting, inventory, posting, report-source or transaction-save service was changed. No migration, deployment script, CI workflow, runtime environment or production data was changed. Protected Owner files, private evidence and unrelated worktrees remain preserved.

AGY's interrupted worker snapshots were inspected and retained. Codex took over implementation, reviewed the recovered scaffolding and verified the integration. Exhausted AGY workers were not repeatedly dispatched.

## Finished owner journey

1. An authorized protected company Owner sees **Set up AI**, including before a company policy exists.
2. AI Settings presents **DeepSeek AI**, a masked write-only API key field, password confirmation, a compact pricing/privacy disclosure and explicit consent.
3. **Test connection** provisions through existing encrypted credential governance and makes one genuine authenticated request when dispatch and existing budgets permit it. Saving alone never marks a connection verified. A replay or an in-flight duplicate cannot dispatch again.
4. Only a valid response with bounded provider-reported usage, followed by fresh authorization/configuration checks, establishes verification. **Start using AI** opens the existing assistant with the approved primary route.
5. Another potentially paid test requires an explicit separate action. Failed or unknown-cost attempts retain their reservations. No historical counters, limits or reservations are reset.

New simple configurations use only `deepseek` / `deepseek-flash` / `global` / `text_structured`, company-managed credentials, no fallback, no personal BYOK and no cloud speech connection. Fresh safe limits are constrained by positive platform ceilings; a disabled/zero platform ceiling still prevents dispatch. The proposed maxima are USD 0.01/request, USD 0.05/company/day, USD 0.50/company/month, USD 0.02/user/day and USD 0.20/user/month, with 10 company and 5 user requests/day. Existing stricter settings remain authoritative.

Existing advanced policies are preserved, not silently normalized or overwritten by the simple flow. Their original governance forms remain in collapsed **Advanced settings**. Existing legacy transports remain available for preserved configurations; this change adds no new non-DeepSeek integration.

## SDK decision and outbound safety

Use the official [`laravel/ai` SDK](https://laravel.com/docs/13.x/ai-sdk), locked at **v1.2.0**, behind `DeepSeekSdkAdapter` and `BoundedDeepSeekGateway`. The lockfile adds only that package and `symfony/yaml` v8.1.8; existing dependencies are unchanged. The installed versions support PHP 8.4 and Laravel 13. Composer validation, audit and actual PHP 8.4.25 execution passed.

The SDK constructs/parses the DeepSeek request. The application's gateway supplies the existing HTTP boundary: fixed approved HTTPS endpoint, native DNS, TLS verification, no redirects/proxy/retries/rewind, shared deadline, connection/read limits and a 131072-byte response bound. Host-level egress restrictions remain an operational prerequisite; hostname approval does not itself establish public-IP isolation.

There is one production DeepSeek transport path: `DirectProviderTransport` delegates DeepSeek text to the SDK adapter. It creates a request-local provider and isolated event dispatcher. No mutable global credential configuration, SDK agent persistence, conversation database, tools, fallback chain or alternate financial engine is introduced. No SDK migrations are published.

Requests use `max_tokens`, JSON-object mode, `stream=false` and `thinking: disabled`; they do not send `max_completion_tokens`. Existing canonical proposal validation remains authoritative. HTTP 400, 401, 402, 422, 429 and upstream failures have distinct sanitized reasons. Raw response/error bodies, credentials and private prompts are not included in diagnostic messages or chained SDK exceptions.

Reviewed defaults use published peak/cache-miss USD **0.30 per million input** and **1.20 per million output** tokens, checked 2026-10-12 against [DeepSeek pricing](https://api-docs.deepseek.com/quick_start/pricing/). These are estimates, not invoiced cost. See also [official request documentation](https://api-docs.deepseek.com/), [error codes](https://api-docs.deepseek.com/quick_start/error_codes) and [privacy policy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html). The disclosure identifies processing in China and variable retention. **API-specific retention remains NOT VERIFIED; no zero-retention promise is made.** Existing policies display their own configured terms/prices rather than silently receiving new ones.

## Assistant, reports and voice

The authenticated shared layout contains one contextual persistent sparkle/microphone launcher. The drawer is bounded on desktop and becomes a sheet on mobile. It retains the app's IBM Plex Sans Arabic, palette and shared components, supports AR RTL / EN LTR, keyboard focus, Escape dismissal, reduced motion, status/error states, explicit Send and an editable transcript.

Report questions still execute only through `AssistantReportCatalog`, `ReportRegistry`, `ReportingGuard` and the bounded result projection. Numbers, currencies, periods and source links remain local canonical results. The seven-tool allowlist does not include payroll/salary tools; sensitive cost/profit fields are excluded server-side and Purchasing requires its independent cost permissions. Unsupported qualifiers and ambiguous operations preserve the accepted clarification/refusal behavior. The LLM has no database tool or financial writer.

Sales and Purchase instructions still hand off to the existing editable **UNSAVED** forms. Human Save/Post authority, canonical draft commands, fresh permissions, company/session binding and revision checks remain intact. Opening the launcher grants no consent, records no audio and sends no paid request.

Browser speech recognition is optional, not a DeepSeek transcription service. An installed language pack must be positively detected before `processLocally=true` is used. Otherwise the user must explicitly accept browser-managed speech processing before recognition starts. Recognition is capped at 60 seconds/4000 characters; Stop waits for final results, Cancel discards dictation, navigation clears recognition, and permission errors cannot deliver stale callbacks. Dictation never submits automatically. Unsupported browsers retain text entry. No additional commercial speech provider was added.

**Real microphone recognition, device accuracy and Palestinian Arabic quality remain NOT VERIFIED.** The browser walkthrough verified the truthful disclosure and initially unchecked remote consent; automated tests verified lifecycle behavior with synthetic events. They do not establish real audio or on-device language-pack support. Optional speech-synthesis playback was not added.

## Executed focused verification

| Check | Actual result |
| --- | --- |
| Combined MariaDB regression: setup, consent scopes, unsaved forms, settings security, launcher aliases, report authorization, semantic routing | PASS — 63 tests, 527 assertions |
| Final expanded setup security test file | PASS — 10 tests, 66 assertions; includes company/session change, revoked selected key and membership revoked during provider latency |
| SDK + provider contract + native transport + capability matrix | PASS — 61 tests, 311 assertions |
| Canonical report truth/redaction + provider-planned question boundaries | PASS — 17 tests, 185 assertions |
| Browser speech lifecycle / duplicate Send | PASS — 11 Node tests, no failures/skips |
| Targeted Pint | PASS |
| Targeted PHPStan: new SDK/setup services and launcher | PASS — no errors |
| Composer strict validation and locked dependency audit | PASS; no known advisories reported |
| Production frontend build | PASS — 62 modules; no permanent Node runtime required |
| Blade cache compilation | PASS; actual Livewire render also exercised |
| Design detector, changed assistant surfaces | PASS — empty findings |
| Fresh finite UI fix review | All three listed fixes scored resolved; this is not whole-system architectural acceptance |
| Ordinary-extension design/documentation check | PASS; existing global tokens/components preserved |

Counts above describe separate executions and overlap; they must not be added as unique coverage. Earlier fixture/expectation failures were corrected, then the named subsets passed. No broad suite, GitHub CI, production reconciliation or load test was run.

Database tests used the guarded disposable MariaDB runner. The local browser used its own owned schema, loopback-only PHP server, synthetic company/master records and `Http::preventStrayRequests()` with fake SDK responses. The owned browser schema and all schemas created by this run were cleaned through their ownership capabilities. Earlier quarantined schemas were not adopted or dropped.

## Actual browser evidence

Authenticated local checks covered 1440px desktop, 768px tablet and 390px mobile; desktop/mobile AR and EN, actual Livewire aliases, launcher discovery, settings, report source navigation, modal focus/close, browser speech disclosure, and both unsaved form handoffs. No real credentials or production records appear in the screenshots.

- [English setup](evidence/ai-deepseek-product/setup-en-desktop.png) and [Arabic mobile setup](evidence/ai-deepseek-product/setup-ar-mobile.png).
- [English desktop assistant](evidence/ai-deepseek-product/assistant-en-desktop.png) and [English mobile](evidence/ai-deepseek-product/assistant-en-mobile.png).
- [Arabic desktop assistant](evidence/ai-deepseek-product/assistant-ar-desktop.png), [tablet](evidence/ai-deepseek-product/assistant-ar-tablet.png) and [mobile](evidence/ai-deepseek-product/assistant-ar-mobile.png).
- [Arabic canonical report](evidence/ai-deepseek-product/report-ar-mobile.png).
- [Unsaved Sales form](evidence/ai-deepseek-product/unsaved-sales-en.png) and [unsaved Purchase form](evidence/ai-deepseek-product/unsaved-purchase-en.png).
- [Sanitized synthetic browser audit](evidence/ai-deepseek-product/synthetic-browser-audit.json).

The SDK-faked connection returned 20 input/5 output tokens; the successful SDK-faked Sales extraction returned 20/80. These are **synthetic**, not DeepSeek measurements. An initial invalid synthetic proposal was safely rejected, then its fixture was corrected; its reservation remained. Both quoted transaction routes also opened unsaved forms without provider calls. A subsequent SDK-request attempt hit the existing synthetic user budget and was refused before dispatch; the limits were not increased.

After all browser journeys, counts were **zero** for `sales_invoices`, `purchases`, `posting_batches`, `posting_lines`, `stock_movements`, `customer_payments` and `vendor_payments`. No Save/Post was clicked in the browser walkthrough. Test suites separately exercise explicit human draft saving on disposable data.

## Remaining release gates

| Gate | Status |
| --- | --- |
| Independent architectural acceptance of this source head | PENDING |
| Owner authorization to merge/deploy this UX/SDK release | REQUIRED; no merge/deployment performed |
| Genuine DeepSeek connection and end-to-end provider response for this candidate | NOT VERIFIED; zero real provider calls made in this iteration |
| Live test budget/counter and credential readiness | Must be freshly checked before the bounded approved smoke; historical production reservations/counters remain untouched |
| Actual billed provider cost | NOT VERIFIED; fake usage and reserved estimates are not invoices |
| Real Arabic/English microphone/device testing | NOT VERIFIED; browser/service dependent |
| Phase 10 restore, independent backup/key custody and real recovery email proofs | Deferred operational gates preserved; not reclassified as PASS |

After source acceptance and an explicitly authorized release, perform at most the permitted remaining live smoke calls, with no automatic retry and no resetting counters/reservations. Stop on configuration, authentication, budget or upstream failure. This packet makes no customer-readiness or production-connectivity claim.

Local QA helpers, worker snapshots, private logs and discarded captures remain ignored under `.ai/`. Browser-tool metadata in the root worktree's `.playwright-mcp/` remains untracked and unstaged alongside the preserved Owner artifacts; only the listed sanitized synthetic artifacts are published.
