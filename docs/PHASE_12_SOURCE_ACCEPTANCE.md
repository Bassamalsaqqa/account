# Phase 12 source candidate — architect handoff

Date: 2026-10-11. Status: IMPLEMENTED SOURCE CANDIDATE; INDEPENDENT ARCHITECT ACCEPTANCE PENDING. Real-provider/voice operational acceptance is incomplete. Customer readiness is not established by this milestone.

## Exact baseline and release separation

Implementation branch: phase/12-ai-sales-assistant. Source baseline/current main and deployed Phase 11: c2e39a32271b14de99e6ffda55ec5b0ea400cb7e, tree c4b50af0531b37be204898cbff860a3361d38d2c. [PR #24](https://github.com/Bassamalsaqqa/account/pull/24) accepted head c669c093c5ee61ffc71bc7af2fed74f9b41aec3a was merged normally and deployed under Owner authorization. [Deployment record](PHASE_11_PRODUCTION_DEPLOYMENT.md) documents six healthy reconciliations, unchanged business-data fingerprints and disabled COMMAND_API_ENABLED. The exact Phase 12 candidate is the implementation PR head; no Phase 12 merge/deployment is authorized by this review packet.

Scope authority: [approved roadmap §9.4](ACCOUNTING_PHASE_9_FULL_ENGINEERING_ROADMAP_AND_FUTURE_AI_MOBILE_V1.md), [ADR 0011](adr/0011-versioned-command-api-foundation.md), proposed [ADR 0012](adr/0012-ai-sales-proposals-and-confirmation.md). Protected Owner specifications, original roadmaps and private PDFs are preserved; 14 protected local hashes match. No AGY work was used: Owner requested Codex implementation while AGY was unavailable. Codex inspected source and evidence directly; that is internal QA, not independent architect acceptance.

## Implementation ledger

| Package | Source/evidence | Source status |
| --- | --- | --- |
| P12-1 — feature/access/provider boundary | ai_assistant config; AssistantAccess; Phase12Boundary; gateway adapters and [contract](PHASE_12_PROVIDER_CONTRACT.md) | Implemented; all defaults off/none/zero; actual upstream dependency NOT VERIFIED |
| P12-2 — structured interpretation/private resolution | SalesProposalSchema/Resolver/Assistant; same-company authoritative references; ProductUnit/barcode binding; exact strings; company-local dates; encrypted apply tokens | Implemented; fake-provider security/economic tests PASS |
| P12-3 — canonical human review | InvoiceForm and contextual assistant partial; editable manual form; Phase 11 create/preview/confirm/execute; atomic approved-command audit | Implemented; affected web/domain regressions PASS; no new financial/stock writer |
| P12-4 — bounded text/voice entry | PCM worklet/encoder/recorder, authenticated CSRF audio route, opt-in consent, generation-bound cleanup, editable transcript | Implemented; synthetic audio/lifecycle tests PASS; physical device and real ASR NOT RUN |
| P12-5 — usage/privacy/lifecycle | company/user request and cost reservation caps; own/admin UTC usage; server disable switches; encrypted structured replay TTL/pruning; content-free metadata | Implemented; concurrent reservations and administrative permission tests PASS; actual billing/retention NOT VERIFIED |
| P12-6 — verification/publication | 32 Phase 12 + 43 affected Phase 11 tests; six frontend tests; build/static/format; six local viewports; additive migration round trip | Local source checks PASS; architect, real provider and release gates remain pending |

## Roadmap acceptance mapping

PASS below means the identified local source behavior was actually tested. It does not imply a real model/provider was evaluated. Carried Phase 11 evidence is labeled separately.

| ID / controlling roadmap acceptance | Actual evidence and outcome | Remaining scope |
| --- | --- | --- |
| AI-01 — Arabic/English/mixed prompts and editable transcript | AssistantSecurityTest normalizes Arabic digits, currency and unit references; fake transcription returns editable text; AR/EN browser text/apply verified | Real extraction/Levantine ASR corpus, confidence calibration and noisy audio BLOCKED EXTERNAL AUTHORIZATION; fake output is not prompt-quality proof |
| AI-02 — missing/duplicate names | AssistantWorkflowTest: missing/duplicate Customer and Product/Unit require questions; exact matches only | Provider interpretation of ambiguous speech NOT VERIFIED |
| AI-03 — tenant and authorization truth | AssistantSecurityTest: foreign Customer never disclosed; wrong company, revoked membership, price/discount restrictions; own versus administrator usage | Existing Phase 11 fresh permissions/tenant command coverage carried and affected command regressions rerun |
| AI-04 — units/economic truth | AssistantEconomicTest: 2 cartons = 24 pieces, FEFO lot selection, 16-piece remaining balance, six healthy domain reconciliations; exact JOD tax/discount/FX 3.523 × 5 = 17.615; stock-change refusal; company-local today/yesterday boundary. Barcode Product/Unit conflict and Arabic digits tested | Other expired-lot/period/credit policy regressions carried from accepted Phase 11/canonical Sales; not claimed as new exhaustive Phase 12 runs |
| AI-05 — bound expiring confirmation | AssistantWorkflowTest: expired/apply token and changed default price refused; membership rechecked. Economic stock change blocks old confirmation, draft and economic history unchanged | Posting uses the unchanged Phase 11 expiring preview token; its accepted concurrency/security evidence is carried where not rerun |
| AI-06 — repeat/concurrent submissions | Ten interpretation retries produce one provider request, zero draft/history; three independent-connection budget races: identical request convergence, changed-input conflict, aggregate cap enforcement. Rerun Phase 11 web/workflow tests prove canonical repeated drafts/posts converge | Real network retry UX/provider billing recovery NOT VERIFIED; no automatic provider retries |
| AI-07 — no implicit financial effects | Workflow + economic tests: interpret/apply/cancel/failed proposals create no economic effects; draft/preview do not post; explicit canonical confirmation required; approved-command audit encrypted and atomic with draft | No LLM/database writer, SQL tool, autonomous financial intent or alternate ledger introduced |
| AI-08 — outage/injection/budget safety | Security/schema tests reject unknown IDs/tools/SQL/float economics; fail provider approval, consent, private IP pins, redirects, oversized responses, malformed audio and budget exhaustion; reservations remain conservative after failures | Real upstream caps, TLS/network behavior and invoice/billing equivalence NOT VERIFIED (HTTP fakes used) |
| AI-09 — audit and minimized retention | Initiator/tenant, HMAC source identity, resolved hash, encrypted human-approved canonical command, P11 receipt key, invoice and reserved/reported usage retained; encrypted replay expires; prune/cancel remove proposal cache; no raw input/audio/transcript columns/cache | Upstream deletion/privacy approval and production key custody remain external gates; financial-command audit is retained business history |
| AI-10 — mobile/permission/accessibility fallback | AR RTL / EN LTR at 390/768/1440: assistant fits and no document overflow. Labels/consent/loading/review/apply/clear and keyboard focus inspected. Synthetic media tests: denied permission, stale permission after restart, explicit upload only, discard abort/late transcript refusal | Real phone/physical microphone permission paths, device ASR and formal WCAG AA certification NOT RUN; manual input remains available |

## Exact checks and evidence

Final deduplicated coverage: **75 PHP tests, 460 assertions, zero remaining failures/skips** — 32 Phase 12 and 43 affected Phase 11 tests. [Case inventory](evidence/phase12/focused-test-inventory.json) records each exact test and assertion count. This is the union of latest passing results, not one falsely described full-suite execution.

- Initial final-integration run: 74 tests / 457 assertions, one multipart test-observation failure (73 passed). Corrected the assertion to inspect multipart name/contents fields; reran only AssistantSecurityTest: 14 tests / 74 assertions, PASS. No production or external provider request was made.
- Added the company-timezone day-boundary regression after source review; reran only AssistantEconomicTest: 4 tests / 25 assertions, PASS. Earlier economic fixture failures (default purchase unit and exact base amount) were corrected; accounting policy/code was preserved.
- Earlier completed focused suite: 26 tests / 210 assertions, PASS, including all three concurrent budget races and the Phase 12 migration-preservation proof.
- Final frontend checks: node --test tests/Frontend/*.test.js — 6 tests PASS, zero skips. Four mock-device lifecycle regressions plus two actual sample/WAV encoder tests; not physical device evidence.
- Pint --dirty PASS; PHPStan analyse --no-progress PASS (zero errors); npm run build PASS (60 modules). Final JS app-1BTtk_MZ.js, CSS app-BjI511Oz.css, worklet ai-audio-worklet-BEsL_ZVi.js. Build outputs remain ignored and are not source commits.
- Additive assistant_requests migration round trip on an independently owned MariaDB schema: 84/64 → 85/65 → 84/64 → 85/65, existing table fingerprints preserved, six reconciliations healthy. The existing Phase 11 migration test now targets its own migration explicitly, preserving the newer assistant table.
- [Browser observations](evidence/phase12/browser-observations.json): six actual loopback viewports, fake provider/mail, synthetic fixture only. Applying fields shows 400.00 ILS, moves focus to Customer, and saves/posts nothing. Clear removes description/consent/proposal; already applied manual fields remain editable. Synthetic [Arabic mobile](evidence/phase12/ar-390.png) and [English desktop](evidence/phase12/en-1440.png) captures are published. Other captures/private JUnit remain ignored evidence.
- Targeted Impeccable detector on the assistant partial returned no findings. UI finish review was performed inline because the Owner requested Codex-only work; it is not an independent architect or full accessibility certification.

No broad full-suite repetition or GitHub CI occurred. All DB tests used tests/Support/run-phase8-disposable.php and owned-schema guards; the browser fixture/server and each test schema were cleaned. The owned local MariaDB data directory/evidence remain private; earlier quarantined Phase 10 schemas and unrelated worktrees were not adopted or dropped.

## Review findings and policy boundaries

Resolved internal findings: real multipart fields required scalar limits JSON; discard/restart must not revive pending capture or late transcript callbacks; relative dates must use Company timezone. These corrections affect new Phase 12 code only. Canonical accounting/stock writers, tenant permissions, published migrations and historical records are unchanged.

Review the new gateway dependency decision explicitly. The gateway client/interfaces are implemented; an actual compatible gateway service is **NOT IMPLEMENTED by this repository and NOT VERIFIED**. Provider selection may instead justify a direct vendor adapter under the same contracts. No model endpoint/pricing/retention claims are fabricated and no live vendor call establishes quality in this packet.

## Required gates and next work

1. Independent ChatGPT architect acceptance of this source, including ADR 0012/provider transport choice, safety, migration and evidence.
2. Owner approval of a specific provider/gateway operator, endpoint, credential custody, cost caps and privacy terms. Then evaluate real Arabic/English/mixed prompts, adversarial/noisy transcripts, real billing and physical-device permission paths using separately approved synthetic recipients/data. Until then provider activation remains BLOCKED EXTERNAL AUTHORIZATION.
3. Separate accepted Phase 12 merge/release decision; keep all AI flags off and COMMAND_API_ENABLED=false until explicit rollout acceptance. Any deployment must apply only the additive migration with data-preservation and code/assets rollback safeguards, retaining audit records/table on rollback. No Phase 12 deployment or schema change has occurred on Hostinger.
4. Deferred Phase 10 P1 gates stay NOT VERIFIED: independent off-host backups/keys, historical and current isolated restores, approved real password recovery. No new operational proof or customer-readiness claim is made.

No Phase 13 implementation, live AI activation, real mail delivery, production fixtures, Phase 12 PR merge/deployment or unauthorized production mutation occurred. Only the accepted Phase 11 merge/deployment and its verification used production access.
