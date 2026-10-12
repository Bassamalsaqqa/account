# Unified Assistant V1 — completed source submission

Branch: `phase/unified-ai-assistant-v1`; [draft PR #28](https://github.com/Bassamalsaqqa/account/pull/28). **Source implementation complete; independent final architect acceptance pending. Not merged or deployed.** Accepted deployed Phase 12 baseline remains `db17512798af5ba6d9d5e7e90fb326ca44718f34`, tree `a45fee80490af0c44ac7a6ed89ffd6dc87c84ba7`. PR #26 was accepted at `e799dc3cf4ef25b12584745ba9786e00d8ffeec5` and deployed in the previously reviewed controlled recovery. This submission performs no new production verification. [The accepted deployment record](PHASE_12_PRODUCTION_DEPLOYMENT.md) remains unchanged.

Publication base: `main` at `c44a432afe496e6def0cffb75be2724a2adada67`, following Owner-authorized documentation-only [PR #27](https://github.com/Bassamalsaqqa/account/pull/27) merge at reviewed head `b4b06ccb9b57f829e21b2426d8f1f2e3516066c4`. Foundation `599d6e56c0ac057fe0a607c4e5c8dbd4ff958bb1`, checkpoint `14c9815` and normal main integration `84a7377f5211115e384704fc94342f8f81468114` retain their history. Updated main is integrated by a normal merge for publication; no rebase, squash, force push or amend.

Protected authority: `SMALL_TRADER_UNIFIED_AI_ASSISTANT_DESIGN_HANDOFF_2026-10-11.md`, version 1.0, SHA-256 `DB6D598B24E36205D6118A94C4488CF8DCC9ED3969FFC2585380A014024EB115`. The Owner original remains local and unpublished. Phase 12 final specification remains unchanged at SHA-256 `B080FC50222DC3F8E0AE6E8A3701BD4151E0CC020AD9FEEF14DE205521A5FB3F`.

## Architect findings and implemented evidence

| Required capability | Implementation and acceptance evidence |
| --- | --- |
| Normal company governance and AR/EN Settings | `AiGovernance` validates strict booleans and unique known report keys; unknown keys/types/associative arrays fail closed. Existing revision, Owner enablement and settings-management authority remain in force. Existing `AiSettings` exposes seven choices in both languages. Settings authority does not grant report reading. Tests exercise normal governance updates and actual settings saves. |
| Canonical execution and separate authorization | `AssistantReportCatalog`/`AssistantReportExecutor` use `ReportRegistry::execute`, typed filters and fresh `ReportingGuard` before query, projection and delivery. Report-only staff need no create rights. Purchase/Vendor tools require existing purchasing cost authority; no new Viewer grant or alternative accounting policy. Missing permissions, role downgrade, revoked membership and foreign-company access fail closed. |
| Bounded truthful result | `AssistantReportDto` whitelists safe totals/columns, exact decimals, native/base currencies, actual company-timezone periods, generation/as-of metadata, five sample rows and canonical source URLs with nested filters. Totals cover the full query, not only the sample. Cash/Bank scope and unavailable native balances preserve truth. Current customer/vendor positions refuse historical labels. `ReportTruthBoundaryTest` covers all seven canonical contracts, localization, redaction, source filters, persisted company configuration and 64 KiB refusal. |
| Cheapest interpretation first | `AssistantDeterministicClassifier` handles AR/EN/mixed language, explicit periods/currencies, bounded follow-ups and ambiguity. Unsupported comparisons/entity qualifiers/contradictions fail closed. Missing period requires clarification. Language boundaries: 18 tests / 552 assertions. No model narrative, model arithmetic or report-data transmission is required. |
| Optional BYOK question planning | `AssistantQuestionPlanner`/`DirectTextProvider::planQuestion` reuse selected primary/approved fallback, encrypted credentials, HTTPS transport, deadlines and reservations. Strict plans contain no SQL, actor/tenant/entity IDs, arbitrary URLs or filters. Only the user's question and permitted tool schema reach a provider. Stored records/results do not. Transaction consent does not authorize questions. One primary call plus at most one approved outage-only fallback; invalid output never triggers fallback. Policy, membership and connection revisions are rechecked after latency; unknown billing retains reservations. |
| One launcher and preserved forms | Existing `AssistantLauncher` supports report-only staff and independently authorized transaction users. Explicit Sales/Purchase contexts preserve unsaved editable-form handoff. Ambiguous operation requires user choice. Existing recorder/audio controller support explicit question transcription without transaction state/token creation. Typed and transcribed questions match canonical result fields; question audio does not silently dispatch a transaction. Disabled features retain manual workflows. |
| Private bounded context | `AssistantQuestionContext` stores encrypted metadata only: report key, validated filters, company/actor/session, policy revision and expiry. TTL 20 minutes; plaintext 4 KiB/ciphertext 8 KiB limits. Reads recheck authority and purge invalid data. No raw-row replay or reuse across session/company/actor/revision mismatch. Close/reset clears current context; old session-bound entries cannot be reused. |
| Runtime/UI compatibility | Explicit `ai.assistant-launcher` registration is preserved and exercised by existing alias regression and new Livewire tests. Authenticated local browser QA covers AR/EN answers and Settings, 390/768/1440 layouts, Escape, source filters and existing Sales/Purchase form aliases. No framework, dependency, migration or financial/stock writer is introduced. Actual new Hostinger/Linux execution remains a release gate. |

Exactly seven tools: `sales.summary`, `customers.balances`, `purchases.summary`, `vendors.balances`, `inventory.low-stock`, `inventory.expiry`, `money.balances`. Profit tools, charts/comparisons, arbitrary entity filters, permanent chat, narrative disclosure, TTS/OCR, extra drafts, mobile/API expansion and autonomous posting remain outside V1.

## Executed verification

Original foundation: 13 tests / 63 assertions. Final verification uses focused Unified tests and existing Phase 12 launcher-alias, consent-scope, audio-boundary and governance regressions. **128 distinct tests / 1,244 assertions; zero remaining failures, errors or skips**, composed from the latest result for each test across recorded runs; this is not claimed as one monolithic execution.

| Execution | Actual result |
| --- | --- |
| Initial integrated focused set | 111 tests / 1,135 assertions; three fixture errors, not treated as PASS. |
| Corrected context/UI set | 32 tests / 104 assertions; context passed, one old UI title assertion failed. |
| Final governance/UI rerun | 17 tests / 40 assertions; PASS. |
| Additional/context boundary run | 30 tests / 126 assertions; context passed, four worker fixture/expectation failures. |
| Corrected additional boundary file | 15 tests / 82 assertions; PASS. |
| Added transcript-routing checks | 2 tests / 23 assertions; PASS. |

Corrections use normal Owner authentication for policy updates, correct active-company permission-team setup before reader role assignment, existing stock-product Purchase fixture requirements and exact 403 expectations for mid-request revocation. Conditional worker assertions were removed. No runtime authorization bypass or accounting change made fixtures pass. Earlier narrow checks informed integration and are not double-counted.

- Database tests used `tests/Support/run-phase8-disposable.php` and owned disposable MariaDB schemas; cleanup confirmed. Earlier quarantined schemas were untouched.
- Targeted PHPStan passed on all 14 changed/new application classes. Pint and whitespace checks passed. Production frontend build passed: Vite 7.3.6, 61 modules; generated assets remain ignored.
- Browser QA used a loopback-only server and owned synthetic company/users with provider dispatch and mail disabled. AR/EN answers showed exact periods/currencies/source filters. Viewports stayed bounded at 390/768/1440. Settings rendered in both languages. Mobile Sales/Purchase forms resolved `pages.sales.invoice-form`, `pages.purchasing.purchase-form` and `ai.assistant-launcher` in both languages. Escape worked and no browser console errors were observed. Screenshots were inspected in-session; this is targeted local evidence, not complete accessibility/device certification. Server/schema cleaned and viewport restored.
- Fourteen protected artifacts match their prior SHA-256 inventory; both newer protected specifications match the identities above. Private evidence, SQL, keys, tokens, protected originals and scratch files are not staged.

## Delegation and review

AGY headless availability was proven with Hello. Isolated workers implemented bounded governance/UI, classifier/context and report-projection/test packages. Codex inspected actual source/diffs, rejected weak tests, corrected integration and independently executed the gates above. Final AGY read-only inspection reported no reproducible defect; this is supporting evidence, not architect acceptance or operational proof. Only explicitly selected final worker paths were accepted; stale scratch tests were excluded. Time limits were checkpoints, never acceptance substitutes. Ignored worker/evidence directories are deliberately retained privately.

## Release state and remaining gates

**READY FOR INDEPENDENT SOURCE REVIEW.** No Unified merge/deployment, production access or production mutation occurred. The separately authorized PR #27 merge changes only the deployment record. No migration, canonical writer, permission catalog or immutable history changed.

`AI_QUESTIONS_ENABLED=false` defaults off; existing global AI, provider dispatch, speech and command API production controls and zero ceilings remain untouched. Provider tests use HTTP fakes and refuse stray requests. No real mail, paid calls, live microphone recording, backup/key retrieval or external customer fixture was used. No broad suite or GitHub CI ran.

Independent source acceptance and a separate Owner release decision are required before Unified merge/deployment. New Hostinger/Linux authenticated execution, approved provider/region/privacy/cost activation, billing/retention proof, physical Arabic microphone/device checks and full accessibility certification remain NOT VERIFIED. Deferred Phase 10 off-host backup, isolated recovery/key custody and real recovery email gates remain unchanged and open. No customer readiness is declared.
