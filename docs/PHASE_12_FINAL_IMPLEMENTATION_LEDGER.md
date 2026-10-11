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
| P12-M1 | Curated direct DeepSeek/Qwen/OpenAI text; Qwen/Groq/OpenAI STT; bounded transport and shared foreground deadline | IMPLEMENTED; protocol mocks PASS | Actual provider/key/region/billing proof NOT VERIFIED; deadline-specific negatives pending |
| P12-M2 | Protected company/personal write-only BYOK, policy CAS, consent, private metadata, exact budget reservations and usage summaries | IMPLEMENTED; governance/settings/budget tests PASS | Extended audio/usage boundary tests and retention review pending |
| P12-M3 | Deterministic zero-text-call path, strict proposals, bounded reference clarification, named outage-only fallback, versioned current-form patches | IMPLEMENTED; focused resolver/orchestration/correction tests PASS | Additional Purchase/patch/ambiguity negative cases pending |
| P12-M4 | Existing unsaved Sales/Purchase forms, final human-field provenance, canonical Purchase draft facade, revision guards and Undo | IMPLEMENTED; focused form/draft tests PASS | Independent-process Purchase concurrency, navigation-memory cleanup and additional affected regressions pending |
| P12-M5 | PCM/WAV foreground capture, cancellation/generation guards, genuine installed local recognition, Seamless/Guided flow | IMPLEMENTED; nine frontend tests PASS | Correction-specific browser races, full responsive flow audit, physical phones and dialect evidence pending |
| P12-M6 | Focused MariaDB/security/migration evidence; synthetic Sales browser inspection | IN PROGRESS | Consolidated final source acceptance, economic regressions, full UI evidence and architect handoff NOT RUN |

## Current source boundaries

Typed deterministic input and provider proposals resolve to session/company/user/form-bound encrypted temporary memory. Opening a form creates no invoice, purchase, posting or stock movement. Current-form corrections apply locally validated operations to the latest unsaved fields with compare-and-swap revision checks; Undo refuses intervening edits.

Explicit Sales Save delegates to the accepted Phase 11 command facade. Explicit AI Purchase Save delegates to CreatePurchaseDraftAction through a company-first locked idempotent draft facade; receipt and encrypted final human-field provenance commit together. No posting or stock writer is added or replaced. No AI Purchase posting is implemented.

Provider dispatch uses curated routes, approved credentials and current consent, independent text/speech selection, combined primary/fallback worst-case reservations and one optional named outage-only fallback. Usage estimates/reservations and unknown costs are distinct from independently reported charges. A request-scoped deadline is shared across attempts; Hostinger timing/resource behavior remains unverified.

Company policy and key custody have separate protected permissions. Keys and password fields use normal CSRF-protected HTTP submission and encrypted, hidden model attributes; they are not public Livewire properties. Configuring a key does not verify it. Default runtime activation flags remain disabled.

Reload/language navigation preserves the latest server-synchronized unsaved fields and invalidates obsolete tab revisions. Save and explicit discard clear temporary memory. Navigation cleanup beyond these paths is unfinished; TTL remains enforced and is not claimed as complete navigation acceptance.

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
