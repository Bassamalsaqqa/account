# Phase 12 expanded source review packet

Status: source implementation and the bounded verification below are complete for independent exact-head architect review; acceptance pending. PR #25 remains draft. This packet governs evidence for the expanded implementation; the older Sales-only acceptance record is historical evidence.

## Identities and authority

- Accepted main/deployed Phase 11 source: `c2e39a32271b14de99e6ffda55ec5b0ea400cb7e`.
- Implementation branch: `phase/12-ai-sales-assistant`; PR: https://github.com/Bassamalsaqqa/account/pull/25.
- Starting checkpoint for final verification: `f254cf7497a0f81c831921bfbd900db289f7d652`; P0 correction parent is exact HEAD `760a1531716c0165a1e0dc5b801178aaceed0811` (baseline 760a).
- Protected authority: `ACCOUNTING_PHASE12_FINAL_SPEC_ARCHITECT_REVIEWED_2026-10-11.md`, dated 2026-10-11, SHA-256 `B080FC50222DC3F8E0AE6E8A3701BD4151E0CC020AD9FEEF14DE205521A5FB3F`, as amended by the Owner-authorized P0 clauses of protected `SMALL_TRADER_UNIFIED_AI_ASSISTANT_DESIGN_HANDOFF_2026-10-11.md`, version 1.0 dated 2026-10-11, SHA-256 `DB6D598B24E36205D6118A94C4488CF8DCC9ED3969FFC2585380A014024EB115` (both remain local, private, and unpublished; see [P0 DNS and consent amendment](PHASE_12_P0_DNS_CONSENT_AMENDMENT.md)). The protected inventory of 14 artifact identities was checked against all 15 existing local copies with no hash drift or missing file.
- [Implementation ledger](PHASE_12_FINAL_IMPLEMENTATION_LEDGER.md), [provider contract](PHASE_12_PROVIDER_CONTRACT.md), [verified direct protocols](PHASE_12_DIRECT_PROVIDER_PROTOCOLS.md), [ADR 0012](adr/0012-ai-sales-proposals-and-confirmation.md), [P0 DNS and consent amendment](PHASE_12_P0_DNS_CONSENT_AMENDMENT.md).

## Package boundaries

| Package | Implemented source | Source proof / remaining boundary |
| --- | --- | --- |
| P12-M1 | Curated direct text and speech adapters; strict local validation; normal-DNS cURL transport; bounded foreground hops | Provider contract mocks and deadline tests. Native DNS supersedes prior IP pinning per Owner P0 amendment. No real key/provider verification. |
| P12-M2 | Protected Owner delegation; company/private BYOK; company policy, per-capability consent, budgets and usage | Governance, Settings, company controls, reservation, scoped consent and two-company tests. No report data consent; no platform billing. |
| P12-M3 | Deterministic zero-text-call path; Sales/Purchase classification; bounded candidates; constrained fallback; patch preparation/acknowledgement | Resolver, orchestration, speech-candidate, transcript, correction and process-concurrency tests. |
| P12-M4 | Existing unsaved canonical forms; explicit human Save; idempotent Purchase draft facade; final-field provenance; highlight/Undo | Both forms, Purchase corrections, independent-process draft races and integrated canonical journey. No new financial/stock writer. |
| P12-M5 | PCM16 WAV capture; intentional Stop; cancellation; genuine installed local recognition only | Recorder/WAV tests and synthetic loopback audio-to-form proof. Physical phones and actual recognition remain activation gates. |
| P12-M6 | Guarded local integration, affected canonical regressions, migration preservation, bilingual responsive evidence, exact-head packet | Verification results below; independent exact-head architect acceptance is still required. |

## Owner decision traceability

The following sanitized mapping records all 25 decisions from the protected ledger. It does not publish or rewrite the Owner original. “Source tested” means local source/synthetic evidence, never operational provider certification.

| Decision | Controlling intent | Implementation and evidence | State |
| --- | --- | --- | --- |
| R1.1 | Global and contextual entry | AssistantLauncher, app layout, InvoiceForm/PurchaseForm; company-control and unsaved-form tests | SOURCE TESTED |
| R1.2 | Classify global intent; constrain context | TransactionAssistant/TransactionProposalSchema; unsupported-payment and mismatched-intent tests | SOURCE TESTED |
| R1.3 | Seamless processing; optional editable transcript | Launcher, recorder, AiTranscriptRevisions; transcript and browser handoff evidence | SOURCE TESTED |
| R1.4 | Labeled permitted defaults; no invented economics | TransactionProposalResolver; field origins, missing-FX/lot/invoice and permission tests | SOURCE TESTED |
| R1.5 | One transaction, multiple lines; bounded capture | Proposal line bounds, PcmWave/AiAudioLimits; recorder/WAV tests | SOURCE TESTED; 60-second Hostinger support NOT VERIFIED |
| R1.6 | Company BYOK; Owner opt-in personal BYOK | AiGovernance/AiProviderConnection; encrypted/scoped credential tests | SOURCE TESTED |
| R2.1 | Genuinely unsaved form before Save | AiUnsavedForm; both canonical-form tests and zero-row browser check | SOURCE TESTED |
| R2.2 | Compact blocking clarification; other missing fields inline | Resolver/launcher; bounded offered candidates and missing supplier facts | SOURCE TESTED |
| R2.3 | Follow-up voice plus authoritative manual edits | AiFormCorrections, recorder acknowledgement/generation; Purchase regression and browser correction/Undo | SOURCE TESTED |
| R2.4 | Sales/Purchase only | Strict versioned schemas and global unsupported-intent refusal | SOURCE TESTED; other intents DEFERRED |
| R2.5 | Existing full forms | InvoiceForm and PurchaseForm handoff; no alternate transaction editor/writer | SOURCE TESTED |
| R3.1 | Company-wide Seamless/Guided | AiCompanyPolicy, Settings, fresh launcher authorization; speech-policy tests | SOURCE TESTED |
| R3.2 | Intentional Stop after informed consent | Versioned route consent; recorder and audio boundary tests; synthetic upload | SOURCE TESTED |
| R3.3 | Human Save always required | Unsaved forms, canonical commands and no-write assertions | SOURCE TESTED |
| R3.4 | Revision-bound highlighted correction and Undo | Prepared patches, browser acknowledgement, current-field CAS and Undo tests | SOURCE TESTED |
| R3.5 | Configurable constrained recovery | AiProviderRoutes and combined budget reservation; outage-only fallback tests | SOURCE TESTED |
| R4.1 | Manual recovery default | Fallback disabled by default; no automatic retry on invalid/rejected output | SOURCE TESTED |
| R4.2 | Company default; explicit allowed private selection | AiProviderRoutes preferences and launcher selections; personal scope and key-source tests | SOURCE TESTED |
| R4.3 | AI capabilities intersect canonical RBAC | AiGovernance plus fresh SalesActorGuard checks; company/role/cost refusal tests | SOURCE TESTED |
| R4.4 | Separate push-to-talk; no passive microphone | Capture teardown/start/Stop and local-recognition lifecycle tests | SOURCE TESTED; physical interruption proof NOT VERIFIED |
| R4.5 | One current unsaved form lifetime | AiFormState encrypted fixed TTL; navigation, Save, discard, session/tab and expiry tests | SOURCE TESTED; offline cleanup delivery cannot be guaranteed |
| R5.1 | Future platform credit/billing boundary only | Reserved credential-source/policy interfaces; no credit ledger/subscription/payment integration | DEFERRED AS APPROVED |
| R5.2 | Separate Owner-delegated settings/key authority | CompanyRoleService protected grants and governance; admin self-grant/custodian tests | SOURCE TESTED |
| R5.3 | Only approved provider/model connections | Capability registry, current consent, company/private allowlists, route preferences | SOURCE TESTED |
| R5.4 | Permitted genuinely local-only recognition | AiSpeechMethods and processLocally availability check; no installed pack => no capture/cloud | SOURCE TESTED; real device/locale support NOT VERIFIED |

No new accounting-policy deviation is approved by this mapping. Section 15 expressly permits a safe shorter recording cap when actual host limits prevent 60 seconds. The local PHP upload limit yielded 21 seconds; no blanket 60-second hosting claim is made.

## Section 15 technical clarifications

| Clause | Source correction and targeted proof | Limit |
| --- | --- | --- |
| 15.1 | PurchaseDraftCommands delegates to CreatePurchaseDraftAction; company/actor/form receipt and canonical digest; same-key/conflict/process races, authorization-at-lock-boundary, rollback and human-final provenance | PostPurchaseAction unchanged; no AI posting |
| 15.2 | Separate authenticated STT and interpretation hops; session/company/user/form/policy-bound state; upload/runtime caps; one monotonic deadline across primary/fallback | Per-hop allowance does not prove total latency, proxy capacity or Hostinger support |
| 15.3 | Independent strict proposal/patch schemas; Qwen region-specific data URI; Groq ten-second billing floor; separate estimates/reservations/reported/unknown cost | Mock HTTP is not accuracy, pricing-invoice or retention proof |
| 15.4 | Password-confirmed write-only encrypted provisioning; Configured — not yet verified labels; local configuration validation makes no provider call | Actual connection testing stays separately gated |
| 15.5 | Unsupported intent refusal; correct existing unsaved form; no manufactured vendor invoice/lots/FX; latest-revision corrections/Undo; AI-free manual Purchase Save | Bilingual synthetic responsive/audio evidence is source proof only |

## Historical expanded verification at 760a153 (carried evidence)

- Current Purchase correction class: **9 tests / 119 assertions**, PASS, no errors/skips. Exact quantity/cost/unit, tax/discount/FX, invoice/lot refusal, fresh cost permission, prepared acknowledgement, manual edit/Undo, explicit Save provenance and AI-disabled manual Save are covered. AGY produced one uncommitted file before a quota failure; Codex inspected and corrected fixture field names and the invalid base-currency FX scenario. Earlier failed reports remain failed evidence.
- Current final-contract economic journey: **1 test / 54 assertions**, PASS. Both existing forms remain unsaved until human Save. Human-entered supplier invoice/lots, 3 cartons of 12 pieces, 72 ILS acquisition, 2-carton sale with 10% discount and 16% tax (167.040000 ILS), FEFO, 12 remaining pieces, explicit canonical posting/replay and immutable historical snapshots are verified. Accounting, Inventory, Sales, Money, Phase 7 and Payables reconciliations all pass. No provider HTTP was sent.
- Affected mature regressions: **144 tests / 478 assertions**, PASS, no errors/skips: PurchasePostingTest, SalesInvoicePostingAndFefoTest and Phase11/SalesCommandWorkflowTest. This is one focused batch, not the entire application suite.
- Recorder/navigation/WAV: **27 frontend tests**, PASS, no failures/skips; production build PASS (61 modules). A real browser failure reproduced serialization of a missing Livewire speech-connection property as a callable, causing UUID refusal. The corrected recorder sends only a nonempty string connection; initial explicit selection is preserved. The failing reproduction passed after correction; browser voice correction and Undo passed with rebuilt assets.
- Whole-repository Pint and PHPStan: PASS, no suppressions added. The single guarded Phase 12 unit/feature integration pass completed **202 tests / 1,832 assertions**, PASS, zero failures/errors/skips (9:03.851; 104 MB). This includes the Purchase/economic classes above, so those repetitions are not added to the aggregate. The 144 mature canonical tests are a separate batch. Superseded gateway-class tests inside the 202-case batch remain legacy compatibility evidence; the final-contract classes and mocked direct-provider tests prove the new route. The runner cleaned its owned primary schema; auxiliary schemas were owned and cleaned by their harnesses.
- Migration preservation evidence resides in AssistantMigrationPreservationTest: forward-only 130000/140000, 89 tables/67 migrations, rollback to 85/65 and forward, business fingerprint preserved and six reconciliations. This rollback occurs only inside a newly owned disposable schema. Existing published 120000/130000 migration files were not rewritten during these corrections.

Reproduction commands (with explicit local disposable-schema opt-in/configuration, never persistent production credentials):

```text
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --memory-limit=512M --no-progress
php tests/Support/run-phase8-disposable.php -- tests/Unit/Phase12 tests/Feature/Phase12
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase5C/PurchasePostingTest.php tests/Feature/Phase4/SalesInvoicePostingAndFefoTest.php tests/Feature/Phase11/SalesCommandWorkflowTest.php
node --test tests/Frontend/transaction-recorder.test.js tests/Frontend/assistant-memory.test.js tests/Frontend/ai-wave.test.js
npm run build
git diff --check
```

The full application PHPUnit suite and composite composer qa were not repeated; the style/static constituent gates and the guarded relevant suites above were used. Reports and synthetic screenshots are retained in ignored private evidence, with no SQL, audio, reset token or real key published.

## Owner P0 amendment verification section (baseline 760a1531716c0165a1e0dc5b801178aaceed0811)

Following the Owner's authorization of normal-DNS direct transport and per-capability scoped consent on 2026-10-11 ([P0 DNS and consent amendment](PHASE_12_P0_DNS_CONSENT_AMENDMENT.md)), the following QA status applies to the amendment:

| Verification target | Status | Invariants and scope |
| --- | --- | --- |
| P0 normal-DNS direct transport | PASS ? LOCAL SOURCE QA | Direct transport dispatches to curated HTTPS registry hosts (`ProviderCapabilityRegistry`) with server-owned paths (`/chat/completions`, `/audio/transcriptions`) using native cURL / Guzzle DNS resolution via `CurlHandler`. Outbound IPv4 pinning (`config('ai_assistant.provider_ipv4...')`) and `CURLOPT_RESOLVE` are removed from active transport. TLS peer and hostname verification strictly enabled (`verify => true`). 5s connect timeout and native low-speed safeguard, shared <= 25s deadline (`AiProviderDeadline`), 128 KiB streamed cap, no retries. |
| P0b per-capability scoped consent | PASS ? LOCAL SOURCE QA | Consent is scoped strictly to selected primary provider/model and approved fallback route per capability (`text_structured`, `audio_transcription`), not all visible choices. Speech method choices: cloud or `local_if_supported` when enabled. Explicit acceptance of updated disclosure required; policy/connection revisions invalidate fingerprints; route selection changes require refreshed disclosure. No report data consent is implicitly approved; reporting/conversation assistant deferred to future Unified P1 separate PR after Phase 12 source acceptance and Owner release decision. |
| Legacy gateway isolation | PASS ? SOURCE INSPECTION | `GatewayTransport` and `AI_GATEWAY_PINNED_IPV4` remain unchanged, optional, disabled by default, and are NOT active final operational mode. |
| Exact HEAD PR #25 architect re-review | PENDING INDEPENDENT ACCEPTANCE | Full exact-head review by independent architect across transport, consent, and regression suites. |

Historical test counts (202 Phase 12 tests / 1,832 assertions, 144 mature tests / 478 assertions, 27 frontend tests) are preserved as authentic historical evidence under prior configuration and do not claim execution of the newly amended normal-DNS or per-capability consent code until verified and integrated by Codex.

P0 executed results: **128 tests / 895 assertions**, then a focused final metadata/dispatch check of **35 tests / 189 assertions**, both with zero failures/errors/skips. Distinct latest cases total **129 / 901**, without counting repeated cases twice. Whole-repository Pint, PHPStan and the frontend production build passed. The existing six-domain economic journey was included. The [dated amendment, §5](PHASE_12_P0_DNS_CONSENT_AMENDMENT.md#5-executed-p0-evidence-and-review-handback) records exact targets, resolved failed iterations, source/network-proof limits and protected-file preservation. No new browser/physical-device, paid-provider or Hostinger proof is claimed.

## Browser evidence and practical accessibility

The private loopback fixture substitutes a synthetic AudioContext stream for the physical microphone and mocks every AI HTTP request. The actual application AudioWorklet, PCM/WAV encoding, CSRF-authenticated upload, reservations, transcription handoff, canonical form rendering and follow-up acknowledgement run. Neither real speech accuracy nor real keys/billing are tested.

Sales intentional Stop opened 5 cartons at 80 ILS (400 ILS), without Save. Arabic Purchase intentional Stop opened 5 cartons at 24 ILS (120 ILS), without manufacturing the supplier invoice number. Follow-up recording changed Purchase quantity to 7 (168 ILS), highlighted quantity, and Undo restored 5 (120 ILS). Same-form language navigation retained fields. Bilingual 390/768/1440 captures cover both voice-populated form states and AI Settings; they are viewport layouts of two completed capture journeys, not twelve independent transcription runs. Failed long clips were refused by the synthetic cost ceiling before dispatch. The Settings page accurately displays unknown costs and unverified connections.

Read-only counts after the browser demonstrations were zero for purchases, sales_invoices, posting_batches, stock_movements, ai_purchase_draft_receipts and command_receipts. The owned browser server/schema were cleaned; unrelated quarantines and worker originals were preserved.

Practical checks include readable RTL/LTR layouts, semantic labels, dialog focus trap/Escape (Arabic Tab moved from the description to the in-dialog submit button; Escape closed the dialog), status/alert announcements, 44px primary controls, editable manual inputs and observable change highlighting. The observed notice text/background contrast was 5.16:1. Full WCAG certification, screen-reader testing on actual devices, physical iPhone/Android interruption, real provider dialect quality and acoustic accuracy are **NOT VERIFIED**. An earlier tablet screenshot was not accepted because its capture geometry was inconsistent. A fresh 768px Arabic Settings check showed a 752.8px main area at x=0 and the closed sidebar at x=752.8 with translate=100%; an explicit 753×1024 viewport capture rendered the complete content correctly. The retained failed/superseded screenshot is not substituted for successful proof. This was a browser capture artifact, with no shared-layout source change.

## Activation and operational blockers

All default switches remain disabled: AI_ASSISTANT_ENABLED=false, AI_PROVIDER_APPROVED=false, AI_ASSISTANT_PROVIDER=none, AI_SPEECH_ENABLED=false; COMMAND_API_ENABLED=false remains required. The synthetic fixture overrides only its isolated process/configuration. No production configuration is changed.

Separate Owner approval remains mandatory for provider/operator/region, pricing and spending limits, privacy/retention, real credentials, production DNS resolution and TLS egress verification on target hosting, production-body logging and a consented 30–50-case Arabic/English/mixed evaluation with actual latency/field accuracy/spend. Live ASR paid calls, physical phones, dialect accuracy, and Hostinger resource guarantees remain NOT VERIFIED. Deferred Phase 10 independent off-host backup, isolated recovery/key custody and real password-reset mail proofs remain open.

No merge, deployment, production access/mutation, real mail, paid provider dispatch, real secret handling, protected Owner publication or GitHub CI occurred. Independent exact-head architect acceptance and later explicit Owner release/activation decisions remain required.
