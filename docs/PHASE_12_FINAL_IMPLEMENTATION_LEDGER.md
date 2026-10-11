# Phase 12 final-spec implementation ledger

Governing protected Owner specification: ACCOUNTING_PHASE12_FINAL_SPEC_ARCHITECT_REVIEWED_2026-10-11.md.
SHA-256: B080FC50222DC3F8E0AE6E8A3701BD4151E0CC020AD9FEEF14DE205521A5FB3F.
The original remains private and unchanged.

Starting PR #25 head: fb37565cd6a85d9baa2d7fdebc59d359c750f032.
Foundation checkpoint: 9903e5196539a4c916c16d863aed47367c53c2e7.
Accepted main/deployed baseline: c2e39a32271b14de99e6ffda55ec5b0ea400cb7e.
This contract supersedes the earlier Sales-only gateway design. Earlier tests are carried evidence, not proof of the expanded contract.
This is an incremental implementation checkpoint, not final Phase 12 acceptance.

| Package | Source work | Checkpoint state | Remaining acceptance |
| --- | --- | --- | --- |
| P12-M1 | Curated direct DeepSeek/Qwen/OpenAI text; Qwen/Groq/OpenAI STT; bounded transport and shared foreground deadline | IMPLEMENTED; protocol mocks PASS | Actual provider/key/region/billing proof NOT VERIFIED |
| P12-M2 | Protected company/personal write-only BYOK, policy CAS, consent, private metadata, exact budget reservations and usage summaries | IMPLEMENTED; governance/settings/budget tests PASS | Company intent/global-button controls tested; final review pending |
| P12-M3 | Deterministic zero-text-call path, strict proposals, bounded reference clarification, named outage-only fallback, versioned current-form patches | IMPLEMENTED; focused resolver/orchestration/correction tests PASS | Additional Purchase/patch/ambiguity negative cases pending |
| P12-M4 | Existing unsaved Sales/Purchase forms, final human-field provenance, canonical Purchase draft facade, revision guards and Undo | IMPLEMENTED; focused form/draft tests PASS | Independent-process Purchase concurrency tested; additional affected regressions pending |
| P12-M5 | PCM/WAV foreground capture, cancellation/generation guards, genuine installed local recognition, Seamless/Guided flow | IMPLEMENTED; nine frontend tests PASS | Correction-specific browser races, full responsive flow audit, physical phones and dialect evidence pending |
| P12-M6 | Focused MariaDB/security/migration evidence; synthetic Sales browser inspection | IN PROGRESS | Consolidated final source acceptance, economic regressions, full UI evidence and architect handoff NOT RUN |

## Current source boundaries

Typed deterministic input and provider proposals resolve to session/company/user/form-bound encrypted temporary memory. Opening a form creates no invoice, purchase, posting or stock movement. Current-form corrections apply locally validated operations to the latest unsaved fields with compare-and-swap revision checks; Undo refuses intervening edits.

Explicit Sales Save delegates to the accepted Phase 11 command facade. Explicit AI Purchase Save delegates to CreatePurchaseDraftAction through a company-first locked idempotent draft facade; receipt and encrypted final human-field provenance commit together. No posting or stock writer is added or replaced. No AI Purchase posting is implemented.

Provider dispatch uses curated routes, approved credentials and current consent, independent text/speech selection, combined primary/fallback worst-case reservations and one optional named outage-only fallback. Usage estimates/reservations and unknown costs are distinct from independently reported charges. A request-scoped deadline is shared across attempts; Hostinger timing/resource behavior remains unverified.

Company policy and key custody have separate protected permissions. Keys and password fields use normal CSRF-protected HTTP submission and encrypted, hidden model attributes; they are not public Livewire properties. Configuring a key does not verify it. Default runtime activation flags remain disabled.

Reload/language navigation preserves the latest server-synchronized unsaved fields and invalidates obsolete tab revisions. Save and explicit discard clear temporary memory. The correction checkpoint adds navigation cleanup; network delivery and real-browser lifecycle certification retain the limits recorded below.

## Executed checkpoint evidence

Distinct focused PHP coverage: 98 tests / 601 assertions, zero failures/errors/skips in the final successful runs:

- Unit provider/parser/proposal/patch coverage: 45 tests / 271 assertions. This includes AGY's new ProviderCapabilityMatrixTest: 29 tests / 130 assertions independently inspected, copied and rerun by Codex; no worker runtime changes or commits.
- Governance, request budgets, Purchase drafts, reference resolution, encrypted form state, orchestration and migration preservation: 35 tests / 205 assertions.
- Unsaved forms, current-form corrections and Settings security: 18 tests / 125 assertions.

The affected six unsaved-form tests were also rerun after the company-first Save lock-order correction: 6 tests / 48 assertions, PASS (a repeat, not additional distinct coverage).

Frontend capture/WAV checks: 9 tests, zero failures/skips. Pint and PHPStan passed. Production frontend build passed. These are focused source checks, not paid-provider or production evidence.

Migration preservation: new 140000 extension forward to 89 tables / 67 migrations, rollback to 85 / 65 and forward again; unchanged business fingerprint and all six reconciliations passed on an owned disposable MariaDB schema. Published 120000 and 130000 migrations remain unchanged. Historical foundation evidence was 89 / 66, before 140000.

Synthetic loopback Sales browser evidence covers unsaved handoff, quantity correction, visible changed-field highlighting, Undo and AR/EN reload at 390, 768 and 1440 pixels. Captured DOM overflow checks passed. This limited evidence does not certify the complete Purchase/Settings/microphone flow or physical devices. Artifacts and disposable fixture cleanup records are retained privately outside Git.

An initial correction Undo null-field mismatch and a reload/language-switch refusal were reproduced, corrected and tested. An earlier MariaDB DDL stall remains INCOMPLETE, not PASS; interrupted schemas remain quarantined. Successful guarded runs clean up only their owned schemas. No entire application suite was repeated.

## Ownership and pending gates

Codex owns architecture, integration, financial-boundary review and acceptance of worker output. AGY manual delegation is available; the returned provider test was independently accepted for integration. A bounded Purchase concurrency test task follows this checkpoint. Worker completion is evidence, not architect acceptance.

Pending source work includes navigation-memory lifecycle, remaining resolver collision and correction cases, migration of three legacy UI tests still targeting the superseded Sales-only methods, audio/controller and usage/deadline boundaries, affected canonical regressions, full responsive/accessibility review and final Owner Decision Ledger traceability. Unfinished requirements remain NOT RUN / NOT VERIFIED.

Actual providers, real keys, paid calls, real-phone/dialect accuracy, provider privacy/cost approval and Hostinger resource proof remain NOT VERIFIED / BLOCKED EXTERNAL AUTHORIZATION. Deferred Phase 10 backup/recovery/key custody/email requirements are untouched.

No merge, deployment, production access or mutation, real email, paid provider calls, protected Owner-file publication or GitHub CI occurred for this checkpoint. PR #25 remains draft.

## Correction checkpoint after 04bdb403f4b23590428e3e64a30eadbec05b7b07

Implemented and independently verified:

- SKU/name/barcode matches now share one bounded candidate decision. A reproduced SKU-versus-barcode collision no longer silently chooses one product.
- Authenticated CSRF-protected cleanup deletes only the caller's session-owned temporary capability. Cleanup remains available after AI/membership revocation; foreign actor/session tokens are indistinguishable and untouched. Revision mutation now takes company locks before cache locks.
- Tab navigation, company switch and logout request memory deletion; reload/language changes preserve the same form. Offline/crashed/closed tabs cannot guarantee network delivery: encrypted state has a fixed server TTL and never becomes a reusable saved transaction.
- Speech corrections carry the recording-start revision. Manual financial input aborts pending speech; stale cloud options cannot override a newly selected local-only/text-only method.
- Company text-only policy and permitted per-user genuinely local-only selection are supported. The selected method cannot override company workflow mode or broaden allowed methods; cloud dispatch is refused for local-only/text-only.
- The maintenance prune command honors each company's retention for encrypted final human-field content while preserving content-free usage and provenance identifiers. It does not modify canonical financial documents. No recurring production job was activated.
- Exact UTC company/self usage aggregates distinguish estimates, reservations, reported amounts and unknown costs; company-wide figures require separate fresh permission.
- The new audio route cleans temporary uploads on validation, authorization and processing exits. Mocked upload-to-unsaved-form, missing consent, invalid audio/identity and mid-call key revocation are covered. Revocation is rechecked before publishing temporary text/speech handoff state; failure retains unknown reservations.
- Three superseded UI regressions now use the current workflow, retaining explicit canonical Preview/Confirm/Post and cancellation guarantees. Disabling AI refuses AI provenance Save while the separate manual form still works. The legacy audio endpoint is an explicit 404 tombstone.
- Direct providers require a reviewed server-configuration public destination pin; runtime synchronous DNS was removed because its PHP wait is not bounded. No real destination was configured. Hostname TLS verification and fixed curated routes remain mandatory. Activation requires operator resolution/TLS/address-refresh verification; address rotation may cause a fail-closed outage.

### Actual correction evidence

The latest successful per-class reports cover 142 distinct focused PHP tests / 900 assertions across this checkpoint and the preceding checkpoint, with no skipped cases. Counts deduplicate repeated classes; they are not the output of a single full-suite run.

- All Phase 12 unit classes: 49 tests / 283 assertions, PASS, including AGY matrix 29 / 130 and foreground deadline/destination checks 4 / 12.
- Resolver with collision: 7 / 48, PASS.
- Memory/revision/correction/unsaved-form group: 19 / 121, PASS.
- Speech-choice/governance/Settings group: 15 / 84, PASS.
- Retention/usage group: 4 / 58, PASS.
- Audio/handoff/state group: 15 / 91, PASS.
- Migrated workflow class: 10 / 71, PASS; retained legacy security class: 14 / 73, PASS. These classes passed inside mixed batches whose newly added neighboring tests initially failed; those failed neighbors were corrected and rerun separately. Earlier legacy-service coverage remains identified as such and does not prove the new API/orchestrator.
- Budget, Purchase draft and migration classes retain their previously reported successful source checks. No schema or canonical writer changed in this correction batch.

Frontend lifecycle/WAV/capture checks: 16 tests, zero failures/skips. Pint and PHPStan passed; production frontend build passed. A stale multi-address condition left after removing DNS was detected by PHPStan and removed without suppression.

Initial fixture errors in session/control-company setup were corrected. The mid-speech-revocation status failure was corrected before the successful 15-test rerun. Failed aggregate runs remain retained privately and are not relabeled PASS.

### Still unfinished

Independent-process Purchase concurrency is prepared as a manual AGY task at the committed 04bdb40 baseline; it owns two new test files and no runtime code. Resolver speech-name matching, Purchase/patch negative scenarios, foreground text-correction/manual-edit browser races, new-budget process concurrency, full responsive/accessibility flows, affected canonical integration regressions and final Owner Decision Ledger traceability remain in progress / NOT RUN. This is not final M6 acceptance.

The protected Owner original and existing production/runtime configuration remain unchanged. No merge, deployment, production access/mutation, real provider/SMTP call, paid dispatch or GitHub CI occurred.

## Provider-mode safeguard checkpoint

Direct dispatch now requires `AI_ASSISTANT_PROVIDER=direct` in addition to enabled/approved switches. `none` and the superseded gateway mode cannot reach a direct provider; the audio boundary refuses before database access. Governance checks the mode before credential decryption and reservation. All defaults remain disabled.

Focused unit verification: 50 tests / 286 assertions, PASS, including AGY matrix 29 / 130. The affected five-class feature batch ran 35 tests / 222 assertions with one fixture failure: governance still selected the legacy gateway. The direct-provider fixture was corrected and its six-test class rerun separately; the failed aggregate remains recorded privately rather than relabeled PASS. The corrected governance class passed: 6 tests / 29 assertions. The other four affected classes passed: 29 tests / 196 assertions.

Latest successful per-class reports now cover **144 distinct focused PHP tests / 910 assertions**, with zero failures/errors/skips in the selected results (deduplicated evidence, not one full-suite execution). Pint, PHPStan and whitespace checks passed. Both owned disposable schemas from this safeguard checkpoint were cleaned. No migration, frontend asset or canonical financial writer changed in the provider-mode safeguard. Final M6 acceptance and the remaining source tasks above are still incomplete.

## Company control checkpoint

Company AI Settings now expose bilingual global-button and Sales/Purchase intent controls. Allowed intents intersect fresh canonical RBAC for automatic classification and contextual usage; even the Owner cannot use a disabled intent. Hiding the global launcher preserves permitted contextual workflows. Open launchers and handoff capabilities recheck the revised policy. Unsupported, duplicate and non-list intent values are refused. Missing fields retain the previous two-intent/global-button behavior until explicitly configured. No migration is needed for these versioned JSON settings.

Focused affected group: 23 tests / 140 assertions, PASS. The completed company-controls class was then rerun with an additional same-user Administrator-A/Viewer-B role-cache isolation case: **7 tests / 47 assertions**, PASS. These seven tests increase the latest deduplicated focused inventory to **151 tests / 957 assertions**, not a single broad-suite run. Owned primary schemas were cleaned. Pint, PHPStan, the frontend build and whitespace checks passed; the one mechanical settings UI scan reported no findings. Rendered AR/EN labels are tested; this is not full browser/accessibility certification.

AGY Purchase concurrency is reported running in its own 04bdb40 worktree. Its two files are being inspected read-only; source presence and a running test report do not establish completion or acceptance. No worker files or metadata were changed by Codex. No merge/deployment/production access, real credentials, paid calls or CI occurred. Final expanded Phase 12 acceptance remains incomplete.

## Independently reviewed AGY Purchase concurrency

Collected exactly two untracked files from the unchanged 04bdb40 manual worker. AGY reported 5 tests / 143 assertions, zero failures/errors/skips. Codex inspected both files, copied them into the integration worktree and strengthened exact canonical payload-hash and winning draft/line/quantity/cost/unit-conversion assertions, explicit 409 classification, no-bytes authorization results, foreign-unit-only refusal and complete inventory-effect fingerprints. The child now verifies the disposable DB destination and rejects cached configuration before bootstrap; unrelated InvalidArgumentException failures cannot masquerade as conflicts.

Final current-source evidence: four independent-process cases **4 tests / 151 assertions**, PASS; foreign vendor/product/ProductUnit/warehouse refusal **1 test / 28 assertions**, PASS. These are five successful scenarios across focused runs, not a green final aggregate: the five-case run initially reported an error because the stricter foreign-product expectation omitted the existing NotFoundHttpException. Only that case was corrected to accept the established model-not-found/404 boundaries and rerun. The prior five-case run (before the last assertion changes) also passed 5 / 160. Failed and superseded reports remain private and are not substituted for current proof.

The two real PHP processes are held behind the same company row lock, both report an attempting barrier and remain running without a result before release. Identical creates converge to one complete draft/receipt; differing costs yield one exact winning draft and one 409. Mid-wait Viewer downgrade and membership revocation deny both results with no writes. Control-tenant histories, stock/valuation caches, lots, numbering and primary schema counts remain unchanged. Targeted Accounting, Inventory, Money and Payables reconciliations pass on these synthetic fixtures; this does not claim a newly executed six-domain integration pass.

Codex-owned primary and auxiliary schemas and child/barrier artifacts were cleaned by the harness. Earlier quarantined schemas remain untouched. Worker originals are retained untracked; no worker commit or runtime modification was accepted. Pint, PHPStan and whitespace checks passed. Latest deduplicated focused evidence is **156 PHP tests / 1,136 assertions**; final M6 integration and the remaining source/UI requirements are still incomplete. No merge, deployment, production access, provider call or CI occurred.

## Foreground correction acknowledgement checkpoint

Codex corrected the unsynchronized browser-edit race without changing canonical financial writers. Provider inference now prepares a protected, session/user/company/tab-bound single-use patch without changing the editable form. The current browser generation must acknowledge it in a separate no-provider local commit; the server rechecks permissions, policy/keys, source form revision and exact latest payload. Human input or selection changes discard late patches. Only the short local commit disables previously enabled form controls; inference leaves ordinary fields editable. Text-only correction requests also expose cancellation.

A second reproduced defect involved deferred wire:model fields delivered with the preparation call: synchronization advanced server memory while Livewire retained its old revision. The trait now retains the synchronized revision before preparation/dehydration. The one-case failing reproduction is retained privately; the corrected aggregate passed.

Actual current checks:
- Correction, form-state and unsaved canonical-form group: **20 PHP tests / 127 assertions**, zero failures/errors/skips. Correction class: 11 / 68; form state: 3 / 11; unsaved forms: 6 / 48.
- Prepared patches remain unapplied until acknowledgement; wrong session/tab, replay, unsynchronized latest payload, changed company policy and original-form expiry refuse. Patch lifetime is capped by the original fixed expiry. No invoice or posting is created by correction.
- Frontend checks: **25 tests**, zero failures/skips, including five new recorder preparation/commit/manual-edit cases. Four retained legacy recorder checks are not direct-provider operational evidence.
- Pint, PHPStan, production frontend build (61 modules) and whitespace checks PASS. The final JavaScript interruption update was followed by a fresh relevant frontend/build run.
- Protected governing Owner specification SHA-256 remains B080FC50222DC3F8E0AE6E8A3701BD4151E0CC020AD9FEEF14DE205521A5FB3F. Owned test schemas were cleaned.

The earlier 156 / 1,136 deduplicated focused evidence figure is historical checkpoint evidence, not a new aggregate for this source. Current exact per-class results above supersede earlier correction/form-state/unsaved-form reports.

AGY headless hello completed successfully. Bounded budget process-concurrency, tenant-local speech candidates and provider/ADR documentation assignments are running in separate linked worktrees at 782dee5111f6cf693afdf9def198e7a2fb8b6d61. Their outputs remain NOT ACCEPTED pending independent inspection and targeted verification. Final Phase 12 M6 acceptance, full responsive/device certification and remaining source requirements are incomplete. No merge, deployment, production access/mutation, real credential/provider call or GitHub CI occurred.

## Transcript, candidate and reservation checkpoint after 417aef25b9d1dc58a1e8712bba7a1c7ac6148b0e

Codex collected AGY's exact two budget-test files, three speech-candidate files and two documentation files from the preserved 782dee5 worktrees. Worker completion was not treated as acceptance. Independent correction removed an unoffered-record-ID fallback, bounded choices to fresh offered candidates, corrected fixture tenant context and reconciled documentation against actual forms, consent records, key handling and per-hop deadlines. The historical source-acceptance record is now explicitly identified as superseded.

The optional original-description editor is implemented in both existing unsaved forms. A confirmed reprocess creates a separate, session/actor/company/form-bound proposal, with an additional-usage warning. Latest human fields and original revision are checked before navigation; ambiguous replacements leave the old form intact until local clarification and another acknowledgement. Prepared replacement URLs cannot bypass acknowledgement. Exact FX clarifications use indexed inputs, preserve decimal strings and make no additional interpretation call. No canonical financial writer, migration or saved financial document changed.

The local browser reproduced a locale-POST navigation defect: the Navigation API discarded same-form memory before redirecting back, producing 409. The same-origin locale POST now retains the form; ordinary/foreign navigation, company switch and logout still discard memory. The regression models the actual POST destination and submitted locale, using the [documented NavigateEvent formData behavior](https://developer.mozilla.org/en-US/docs/Web/API/NavigateEvent/formData). A rebuilt-browser English-to-Arabic-to-English Purchase switch passed without losing the unsaved quantities/costs.

Actual focused evidence, retained privately:

- Budget concurrency: **7 tests / 270 assertions**, zero failures/errors/skips. Two independent processes wait behind the company lock. Same-key requests converge; differing payloads conflict; company/user/platform caps reserve only one result; deterministic requests honor count limits; role downgrade, membership revocation and company disable refuse pending work; attempts advance once and unknown reservations remain conservative. Company-disable fixture now asserts one policy row actually changes. Earlier failing fixture reports remain failed evidence, not runtime incidents.
- Speech candidates plus existing assistant orchestration: **19 tests / 119 assertions**, zero failures/errors/skips. Exact SKU/barcode, normalized collisions, explicit fuzzy choice, five-candidate/100-record refusal, foreign/inactive exclusions, cross-company role differences, piece/carton identity and unchanged financial rows are covered.
- Optional transcript class: **6 tests / 73 assertions**, zero failures/errors/skips. Explicit replacement, original intent, current policy, wrong session, latest manual edits, single-use handoff, ambiguity and exact FX clarification are covered.
- Relevant recorder/navigation checks: **23 frontend tests**, zero failures/skips. Other historical frontend classes are not counted as newly executed in this batch. Production frontend build passed: 61 modules; generated assets remain ignored build outputs.
- Focused PHPStan passed without suppressions after correcting generic/model contracts and testing API access. Pint and whitespace checks passed. An affected workflow confirmation batch is recorded below once complete; these figures are separate executions, not a broad-suite aggregate.

The final affected workflow batch passed **42 tests / 308 assertions**, zero failures/errors/skips, covering speech candidates, assistant orchestration, transcript revisions, existing correction patches and both unsaved forms. This supersedes the earlier separate results for those five classes; do not add the repeated tests to an aggregate. Two earlier commands named nonexistent test files and ran no tests; their runners still cleaned the newly owned schemas. The source/UI scan emitted no findings. The observed notice text/background contrast was 5.16:1; that single color pair does not certify all accessibility requirements. Four current documents' relative Markdown links resolved locally. The protected 14-artifact inventory matched all 15 existing local copies, plus the governing final-spec hash above.

Synthetic loopback browser evidence now includes Purchase optional-transcript layouts in AR/EN at 390/768/1440, missing supplier invoice truth, and Sales explicit reprocessing from 5 cartons at 80 to 7 at 81 (567.00 ILS), without Save. Captured document widths were bounded by the requested viewport. Evidence uses disposable fixtures and mocked providers; actual microphone/audio-to-form browser certification, physical devices, provider/dialect accuracy and complete accessibility certification remain NOT RUN / NOT VERIFIED. Only the local synthetic fixture was cleaned; unrelated quarantines were preserved.

A new bounded AGY Purchase correction-regression assignment runs independently at 417aef2, owning one new test file only. Remaining affected economic regressions, complete source/spec traceability, final responsive/audio evidence and P12-M6 integration handoff remain IN PROGRESS. All external activation gates and deferred Phase 10 operational requirements remain unchanged. No merge, deployment, production access/mutation, real secrets/mail/provider call, paid dispatch or GitHub CI occurred.
